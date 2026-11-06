<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cli;

use D6N\LPM\Modules\Cli\Commands\CacheCommand;
use D6N\LPM\Modules\Cli\Commands\MigrateCommand;
use D6N\LPM\Modules\Cli\Commands\RatesCommand;
use Throwable;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every `wp d6n-lpm …` command.
 */
class CliModule {

	/**
	 * Top level command namespace.
	 *
	 * @var string
	 */
	public const COMMAND_NAMESPACE = 'd6n-lpm';

	/**
	 * Register the commands when running under WP-CLI.
	 *
	 * @return bool True when the commands were registered.
	 */
	public static function register(): bool {
		if ( ! self::is_wp_cli() ) {
			return false;
		}

		$container = new CliContainer();

		try {
			self::add_migrate_command( $container );
			self::add_rates_command( $container );
			self::add_cache_commands( $container );
		} catch ( Throwable $e ) {
			WP_CLI::warning( sprintf( 'Localized Price Manager could not register its WP-CLI commands: %s', $e->getMessage() ) );

			return false;
		}

		return true;
	}

	/**
	 * Check whether the current request runs under WP-CLI.
	 *
	 * @return bool True when WP-CLI is available.
	 */
	public static function is_wp_cli(): bool {
		return defined( 'WP_CLI' ) && WP_CLI && class_exists( WP_CLI::class );
	}

	/**
	 * Register `wp d6n-lpm migrate`.
	 *
	 * @param CliContainer $container Shared CLI object graph.
	 * @return void
	 */
	private static function add_migrate_command( CliContainer $container ): void {
		WP_CLI::add_command(
			self::COMMAND_NAMESPACE . ' migrate',
			array( new MigrateCommand( $container ), '__invoke' ),
			array(
				'shortdesc' => 'Runs, inspects or rolls back the plugin database migrations.',
				'synopsis'  => array(
					array(
						'type'        => 'positional',
						'name'        => 'action',
						'optional'    => true,
						'default'     => 'run',
						'options'     => array( 'run', 'status', 'rollback' ),
						'description' => 'What to do; defaults to running every pending migration.',
					),
					array(
						'type'        => 'flag',
						'name'        => 'force',
						'optional'    => true,
						'description' => 'Forget every tracking row first, so applied migrations run again.',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'optional'    => true,
						'description' => 'Batch number to roll back; required by the rollback action.',
					),
					array(
						'type'        => 'flag',
						'name'        => 'yes',
						'optional'    => true,
						'description' => 'Skip the confirmation prompt.',
					),
				),
			)
		);
	}

	/**
	 * Register `wp d6n-lpm rates update`.
	 *
	 * @param CliContainer $container Shared CLI object graph.
	 * @return void
	 */
	private static function add_rates_command( CliContainer $container ): void {
		WP_CLI::add_command(
			self::COMMAND_NAMESPACE . ' rates update',
			array( new RatesCommand( $container ), 'update' ),
			array(
				'shortdesc' => 'Refreshes the stored exchange rates.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'base',
						'optional'    => true,
						'description' => 'ISO 4217 base currency to refresh; defaults to every configured base.',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'provider',
						'optional'    => true,
						'description' => 'Provider key to use instead of the configured priority queue.',
					),
				),
			)
		);
	}

	/**
	 * Register `wp d6n-lpm cache flush` and `wp d6n-lpm cache warm`.
	 *
	 * @param CliContainer $container Shared CLI object graph.
	 * @return void
	 */
	private static function add_cache_commands( CliContainer $container ): void {
		$command = new CacheCommand( $container );

		WP_CLI::add_command(
			self::COMMAND_NAMESPACE . ' cache flush',
			array( $command, 'flush' ),
			array(
				'shortdesc' => 'Flushes the plugin cache, entirely or one group at a time.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'group',
						'optional'    => true,
						'description' => 'Cache group to flush; flushes every plugin group when omitted.',
					),
				),
			)
		);

		WP_CLI::add_command(
			self::COMMAND_NAMESPACE . ' cache warm',
			array( $command, 'warm' ),
			array(
				'shortdesc' => 'Pre-computes resolved prices of the most popular products for every ruled country.',
				'synopsis'  => array(),
			)
		);
	}
}
