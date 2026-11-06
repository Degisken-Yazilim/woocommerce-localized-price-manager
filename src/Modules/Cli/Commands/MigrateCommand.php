<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cli\Commands;

use D6N\LPM\Core\Database\MigrationRepository;
use D6N\LPM\Core\Database\MigrationRunner;
use D6N\LPM\Core\Database\Migrations\AbstractMigration;
use D6N\LPM\Modules\Cli\CliContainer;
use WP_CLI;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

defined( 'ABSPATH' ) || exit;

/**
 * `wp d6n-lpm migrate` — database migration commands.
 */
class MigrateCommand {

	/**
	 * Table columns rendered by the status action.
	 *
	 * @var array<int, string>
	 */
	private const STATUS_COLUMNS = array( 'migration', 'status', 'priority' );

	/**
	 * Constructor.
	 *
	 * @param CliContainer $container Shared CLI object graph.
	 */
	public function __construct(
		private readonly CliContainer $container
	) {}

	/**
	 * Run, inspect or roll back the plugin database migrations.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : What to do. Defaults to running every pending migration.
	 * ---
	 * default: run
	 * options:
	 *   - run
	 *   - status
	 *   - rollback
	 * ---
	 *
	 * [--force]
	 * : Forget every tracking row first, so already applied migrations run again.
	 *
	 * [--batch=<n>]
	 * : Batch number to roll back. Required by the rollback action.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp d6n-lpm migrate
	 *     wp d6n-lpm migrate --force
	 *     wp d6n-lpm migrate status
	 *     wp d6n-lpm migrate rollback --batch=2
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$action = strtolower( trim( (string) ( $args[0] ?? 'run' ) ) );

		switch ( $action ) {
			case '':
			case 'run':
				$this->run_pending( $assoc_args );
				break;
			case 'status':
				$this->show_status();
				break;
			case 'rollback':
				$this->rollback( $assoc_args );
				break;
			default:
				WP_CLI::error( sprintf( 'Unknown action "%s". Use run, status or rollback.', $action ) );
				break;
		}
	}

	/**
	 * Run every pending migration.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	private function run_pending( array $assoc_args ): void {
		$runner     = $this->container->get_migration_runner();
		$repository = $runner->get_repository();

		if ( ! $repository->create_table() ) {
			WP_CLI::error( 'The migration tracking table could not be created.' );

			return;
		}

		if ( (bool) get_flag_value( $assoc_args, 'force', false ) ) {
			$this->forget_tracking_rows( $runner, $repository, $assoc_args );
		}

		$result = $runner->run_up();

		if ( ! $result['success'] ) {
			WP_CLI::error(
				sprintf(
					'Migration run failed. Ran: %d, failed: %d.',
					$result['ran'],
					$result['failed']
				)
			);

			return;
		}

		WP_CLI::success( sprintf( 'Migrations are up to date. Ran: %d.', $result['ran'] ) );
	}

	/**
	 * Delete the tracking row of every discovered migration.
	 *
	 * @param MigrationRunner      $runner     Migration runner.
	 * @param MigrationRepository  $repository Tracking repository.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	private function forget_tracking_rows(
		MigrationRunner $runner,
		MigrationRepository $repository,
		array $assoc_args
	): void {
		$names = array_keys( $runner->discover_migrations() );

		if ( array() === $names ) {
			WP_CLI::warning( 'No migration classes were discovered; --force has nothing to forget.' );

			return;
		}

		WP_CLI::confirm(
			sprintf( '--force re-runs all %d migrations, including the applied ones. Continue?', count( $names ) ),
			$assoc_args
		);

		$forgotten = 0;

		foreach ( $names as $name ) {
			if ( $repository->delete_migration( $name ) ) {
				++$forgotten;
			}
		}

		WP_CLI::log( sprintf( 'Forgot %d tracking rows; every migration is pending again.', $forgotten ) );
	}

	/**
	 * Print the per migration status table and the recorded summary.
	 *
	 * @return void
	 */
	private function show_status(): void {
		$runner     = $this->container->get_migration_runner();
		$repository = $runner->get_repository();

		if ( ! $repository->table_exists() ) {
			WP_CLI::warning( 'The migration tracking table does not exist yet; run "wp d6n-lpm migrate" first.' );
		}

		$completed = $repository->get_completed();
		$items     = array();

		foreach ( $runner->discover_migrations() as $name => $migration ) {
			$items[] = array(
				'migration' => $name,
				'status'    => in_array( $name, $completed, true ) ? 'completed' : 'pending',
				'priority'  => $migration instanceof AbstractMigration ? $migration->priority() : 0,
			);
		}

		if ( array() === $items ) {
			WP_CLI::warning( 'No migration classes were discovered.' );

			return;
		}

		format_items( 'table', $items, self::STATUS_COLUMNS );

		WP_CLI::log( sprintf( 'Latest batch: %d', $repository->get_latest_batch() ) );

		foreach ( $repository->get_status_summary() as $state => $count ) {
			WP_CLI::log( sprintf( '  %s: %d', $state, $count ) );
		}
	}

	/**
	 * Roll back every completed migration in a batch.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	private function rollback( array $assoc_args ): void {
		$batch = (int) get_flag_value( $assoc_args, 'batch', 0 );

		if ( $batch <= 0 ) {
			WP_CLI::error( 'Rolling back needs a positive batch number: --batch=<n>.' );

			return;
		}

		$runner = $this->container->get_migration_runner();
		$names  = $runner->get_repository()->get_by_batch( $batch );

		if ( array() === $names ) {
			WP_CLI::error( sprintf( 'Batch %d holds no completed migrations.', $batch ) );

			return;
		}

		WP_CLI::confirm(
			sprintf( 'Roll back %d migration(s) in batch %d? This drops the data they created.', count( $names ), $batch ),
			$assoc_args
		);

		if ( ! $runner->rollback_batch( $batch ) ) {
			WP_CLI::error( sprintf( 'Rolling back batch %d failed; see the plugin log for details.', $batch ) );

			return;
		}

		WP_CLI::success( sprintf( 'Rolled back %d migration(s) in batch %d.', count( $names ), $batch ) );
	}
}
