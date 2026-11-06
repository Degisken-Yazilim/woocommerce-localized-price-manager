<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Lifecycle;

use D6N\LPM\Core\Contracts\IActionQueue;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Contracts\ITaskScheduler;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Shared\ActionQueue;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\TaskScheduler;
use D6N\LPM\Modules\Cache\Services\CacheWarmUpService;
use D6N\LPM\Modules\DiagnosticLog\DiagnosticLogModule;
use D6N\LPM\Modules\DiagnosticLog\Services\NullLogger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin deactivation routine.
 */
class Deactivator {

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'Lifecycle';

	/**
	 * Constructor.
	 *
	 * @param ILogger|null        $logger    Logger instance.
	 * @param ITaskScheduler|null $scheduler Cron scheduler.
	 * @param IActionQueue|null   $queue     Background action queue.
	 */
	public function __construct(
		private ?ILogger $logger = null,
		private ?ITaskScheduler $scheduler = null,
		private ?IActionQueue $queue = null
	) {}

	/**
	 * Static entry point registered with `register_deactivation_hook()`.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		( new self() )->run();
	}

	/**
	 * Get every cron hook this plugin owns.
	 *
	 * @return array<int, string> Fully prefixed cron hook names.
	 */
	public static function get_owned_cron_hooks(): array {
		return array(
			Core::build_hook( HookEnum::UpdateExchangeRates->value ),
			Core::build_hook( HookEnum::CleanOldLogs->value ),
			Core::build_hook( CacheWarmUpService::LEGACY_CRON_HOOK ),
		);
	}

	/**
	 * Clear every scheduled event this plugin owns.
	 *
	 * @return array<string, bool> Cron hook to outcome map.
	 */
	public function run(): array {
		$results = array();

		foreach ( self::get_owned_cron_hooks() as $hook ) {
			$results[ $hook ] = $this->clear_hook( $hook );
		}

		$this->clear_warm_up_continuations();
		$this->cancel_warm_up_batches();

		$this->log_info( 'Deactivation cleared the plugin cron events.', array( 'hooks' => array_keys( $results ) ) );

		return $results;
	}

	/**
	 * Clear one scheduled hook.
	 *
	 * @param string $hook Fully prefixed cron hook name.
	 * @return bool True when the hook was cleared.
	 */
	private function clear_hook( string $hook ): bool {
		try {
			return $this->get_scheduler()->clear_scheduled_hook( $hook );
		} catch ( Throwable $e ) {
			$this->log_error(
				'Deactivation could not clear a cron hook.',
				array(
					'hook'  => $hook,
					'error' => $e->getMessage(),
				)
			);

			return false;
		}
	}

	/**
	 * Remove the cache warm-up continuation events.
	 *
	 * @return void
	 */
	private function clear_warm_up_continuations(): void {
		try {
			wp_unschedule_hook( Core::build_hook( CacheWarmUpService::LEGACY_CRON_HOOK ) );
		} catch ( Throwable $e ) {
			$this->log_error( 'Deactivation could not clear the cache warm-up continuations.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Cancel the pending cache warm-up batches in Action Scheduler.
	 *
	 * @return void
	 */
	private function cancel_warm_up_batches(): void {
		try {
			$queue = $this->get_queue();

			if ( $queue->is_available() ) {
				$queue->cancel_all( Core::build_hook( CacheWarmUpService::BATCH_HOOK ) );
				$queue->cancel_all( Core::build_hook( CacheWarmUpService::CATEGORY_BATCH_HOOK ) );
			}
		} catch ( Throwable $e ) {
			$this->log_error( 'Deactivation could not cancel the cache warm-up batches.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Get the background action queue.
	 *
	 * @return IActionQueue Queue instance.
	 */
	private function get_queue(): IActionQueue {
		if ( null === $this->queue ) {
			$this->queue = new ActionQueue();
		}

		return $this->queue;
	}

	/**
	 * Get the cron scheduler.
	 *
	 * @return ITaskScheduler Scheduler instance.
	 */
	private function get_scheduler(): ITaskScheduler {
		if ( null === $this->scheduler ) {
			$this->scheduler = new TaskScheduler( $this->get_logger() );
		}

		return $this->scheduler;
	}

	/**
	 * Get the logger, falling back to a null logger when it cannot be built.
	 *
	 * @return ILogger Logger instance.
	 */
	private function get_logger(): ILogger {
		if ( null === $this->logger ) {
			try {
				$this->logger = ( new DiagnosticLogModule() )->get_logger();
			} catch ( Throwable $e ) {
				$this->logger = new NullLogger();
			}
		}

		return $this->logger;
	}

	/**
	 * Log an informational deactivation event.
	 *
	 * @param string               $message Message to record.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	private function log_info( string $message, array $context = array() ): void {
		try {
			$this->get_logger()->info( $message, $context, self::LOG_SOURCE );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Log a failed deactivation step.
	 *
	 * @param string               $message Message to record.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	private function log_error( string $message, array $context = array() ): void {
		try {
			$this->get_logger()->error( $message, $context, self::LOG_SOURCE );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}
