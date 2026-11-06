<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\DiagnosticLog\Handlers\WooCommerceLogHandler;
use D6N\LPM\Modules\DiagnosticLog\Repositories\LogRepository;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Purges expired log records from every destination.
 */
class LogPurgeService implements IHookable {

	private LogSettings $settings;

	/**
	 * Constructor.
	 *
	 * @param LogRepository              $repository   Log repository instance.
	 * @param DiagnosticLogger           $logger       Logger service instance.
	 * @param WooCommerceLogHandler|null $file_handler File destination to expire, or null to skip it.
	 * @param LogSettings|null           $settings     Settings reader, defaults to a fresh instance.
	 */
	public function __construct(
		private readonly LogRepository $repository,
		private readonly DiagnosticLogger $logger,
		private readonly ?WooCommerceLogHandler $file_handler = null,
		?LogSettings $settings = null
	) {
		$this->settings = $settings ?? new LogSettings();
	}

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
		add_action( Core::build_hook( HookEnum::CleanOldLogs->value ), array( $this, 'run_scheduled_purge' ) );
	}

	/**
	 * Cron entry point: purge and discard the count.
	 *
	 * @return void
	 */
	public function run_scheduled_purge(): void {
		if ( ! Core::is_active() ) {
			$this->logger->debug( 'Scheduled log purge skipped: the plugin master switch is off.', array(), 'LogPurge' );

			return;
		}

		$this->purge_old_logs();
	}

	/**
	 * Purge expired log records from the database and the file destination.
	 *
	 * @return int Number of purged database records.
	 */
	public function purge_old_logs(): int {
		try {
			$retention_days = $this->settings->get_retention_days();
			$result         = $this->repository->purge_old_logs( $retention_days );

			if ( is_wp_error( $result ) ) {
				$this->logger->error( sprintf( 'Log purge failed: %s', $result->get_error_message() ), array(), 'LogPurge' );
				return 0;
			}

			$purged       = (int) $result;
			$files_purged = $this->purge_files();

			$this->logger->info(
				sprintf(
					'Purged %d old log records (retention: %d days, file logs expired: %s).',
					$purged,
					$retention_days,
					$files_purged ? 'yes' : 'no'
				),
				array(),
				'LogPurge'
			);

			return $purged;
		} catch ( Throwable $e ) {
			$this->logger->error( sprintf( 'Log purge failed: %s', $e->getMessage() ), array(), 'LogPurge' );
			return 0;
		}
	}

	/**
	 * Ask the file destination to expire its handles.
	 *
	 * @return bool True when the file destination ran its expiry routine.
	 */
	private function purge_files(): bool {
		if ( null === $this->file_handler ) {
			return false;
		}

		return $this->file_handler->purge_expired();
	}
}
