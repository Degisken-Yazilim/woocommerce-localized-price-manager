<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Shared;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Contracts\ITaskScheduler;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Task scheduler implementation using WordPress cron API.
 */
class TaskScheduler implements ITaskScheduler {

	/**
	 * Constructor.
	 *
	 * @param ILogger|null $logger Optional logger instance.
	 */
	public function __construct(
		private readonly ?ILogger $logger = null
	) {
	}

	/**
	 * Schedule a recurring event.
	 *
	 * @param int               $timestamp  Unix timestamp.
	 * @param string            $recurrence Recurrence interval key.
	 * @param string            $hook       Hook name.
	 * @param array<int, mixed> $args       Arguments, passed positionally to the hook callbacks.
	 * @return bool
	 *
	 * @phpstan-param list<mixed> $args
	 */
	public function schedule_recurring_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool {
		try {
			if ( ! wp_next_scheduled( $hook, $args ) ) {
				return true === wp_schedule_event( $timestamp, $recurrence, $hook, $args, true );
			}
			return true;
		} catch ( Throwable $e ) {
			$this->logger?->error( $e, array( 'hook' => $hook ) );
			return false;
		}
	}

	/**
	 * Clear scheduled hook.
	 *
	 * @param string            $hook Hook name.
	 * @param array<int, mixed> $args Hook arguments, passed positionally to the hook callbacks.
	 * @return bool
	 *
	 * @phpstan-param list<mixed> $args
	 */
	public function clear_scheduled_hook( string $hook, array $args = array() ): bool {
		try {
			return is_int( wp_clear_scheduled_hook( $hook, $args, true ) );
		} catch ( Throwable $e ) {
			$this->logger?->error( $e, array( 'hook' => $hook ) );
			return false;
		}
	}
}
