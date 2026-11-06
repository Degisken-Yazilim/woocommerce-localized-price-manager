<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Lifecycle;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Database\MigrationRunner;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\DiagnosticLog\Services\LifecycleAuditLogger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Applies pending schema migrations after a plugin update.
 */
class SchemaUpgrader implements IHookable {

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'Lifecycle';

	/**
	 * Transient that throttles retries after a failed upgrade.
	 *
	 * @var string
	 */
	public const RETRY_GUARD = 'd6n_lpm_schema_upgrade_retry';

	/**
	 * Seconds to wait before retrying an upgrade that failed.
	 *
	 * @var int
	 */
	private const RETRY_DELAY = 900;

	/**
	 * Priority on `init` the check runs at.
	 *
	 * @var int
	 */
	private const INIT_PRIORITY = 2;

	/**
	 * Logger the migration subsystem records through.
	 *
	 * @var ILogger|null
	 */
	private ?ILogger $lifecycle_logger = null;

	/**
	 * Constructor.
	 *
	 * @param ILogger              $logger Logger instance.
	 * @param MigrationRunner|null $runner Migration runner; built on first use when omitted.
	 */
	public function __construct(
		private readonly ILogger $logger,
		private ?MigrationRunner $runner = null
	) {}

	/**
	 * Register WordPress action hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress action hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'maybe_upgrade' ), self::INIT_PRIORITY );
	}

	/**
	 * Apply whatever the ledger still reports as pending.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->may_run() ) {
			return;
		}

		try {
			$runner = $this->get_runner();

			if ( array() === $runner->get_pending() ) {
				$this->mark_checked();

				return;
			}

			$result = $runner->run_up();

			if ( $result['locked'] ) {
				return;
			}

			if ( $result['success'] ) {
				$this->mark_checked();

				if ( $result['ran'] > 0 ) {
					$this->logger->audit(
						'Applied the schema migrations a plugin update left pending.',
						array( 'ran' => $result['ran'] ),
						self::LOG_SOURCE
					);
				}

				return;
			}

			$this->announce_failure(
				sprintf(
					/* translators: %d: number of migrations that failed. */
					_n(
						'Localized Price Manager could not apply %d pending database migration. The plugin is running against an older schema; run "wp d6n-lpm migrate run" or check the diagnostic log.',
						'Localized Price Manager could not apply %d pending database migrations. The plugin is running against an older schema; run "wp d6n-lpm migrate run" or check the diagnostic log.',
						$result['failed'],
						'localized-price-manager'
					),
					$result['failed']
				),
				array(
					'ran'    => $result['ran'],
					'failed' => $result['failed'],
				)
			);
		} catch ( Throwable $e ) {
			$this->announce_failure(
				__( 'Localized Price Manager could not apply its pending database migrations. The plugin is running against an older schema; run "wp d6n-lpm migrate run" or check the diagnostic log.', 'localized-price-manager' ),
				array( 'error' => $e->getMessage() )
			);
		}
	}

	/**
	 * Decide whether this request may run schema changes.
	 *
	 * @return bool True when the upgrade may be attempted.
	 */
	private function may_run(): bool {
		if ( Core::get_version() === (string) Core::get_option( OptionsEnum::SchemaCheckedVersion->value, '' ) ) {
			return false;
		}

		if ( wp_doing_ajax() ) {
			return false;
		}

		return false === get_transient( self::RETRY_GUARD );
	}

	/**
	 * Record that this build has seen the ledger with nothing pending.
	 *
	 * @return void
	 */
	private function mark_checked(): void {
		delete_transient( self::RETRY_GUARD );

		Core::save_option( OptionsEnum::SchemaCheckedVersion->value, Core::get_version() );
	}

	/**
	 * Log a failed upgrade, tell the operator, and hold off the next attempt.
	 *
	 * @param string               $message Notice text shown to the operator.
	 * @param array<string, mixed> $context Log context.
	 * @return void
	 */
	private function announce_failure( string $message, array $context = array() ): void {
		set_transient( self::RETRY_GUARD, 1, self::RETRY_DELAY );

		$this->logger->error(
			'A pending schema migration could not be applied after a plugin update.',
			$context,
			self::LOG_SOURCE
		);

		Core::add_admin_notice( $message, 'error' );
	}

	/**
	 * Get the migration runner, recording on the audit channel.
	 *
	 * @return MigrationRunner Migration runner.
	 */
	private function get_runner(): MigrationRunner {
		if ( null === $this->runner ) {
			if ( null === $this->lifecycle_logger ) {
				$this->lifecycle_logger = new LifecycleAuditLogger( $this->logger );
			}

			$this->runner = new MigrationRunner( $this->lifecycle_logger );
		}

		return $this->runner;
	}
}
