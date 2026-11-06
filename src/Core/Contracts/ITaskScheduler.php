<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for cron task scheduler wrapper.
 */
interface ITaskScheduler {
	/**
	 * Schedule a recurring cron event.
	 *
	 * @param int               $timestamp  First execution timestamp.
	 * @param string            $recurrence Recurrence key (e.g. 'every_three_hours').
	 * @param string            $hook       WordPress action hook name.
	 * @param array<int, mixed> $args       Hook arguments, passed positionally to the hook callbacks.
	 * @return bool
	 *
	 * @phpstan-param list<mixed> $args
	 */
	public function schedule_recurring_event( int $timestamp, string $recurrence, string $hook, array $args = array() ): bool;

	/**
	 * Clear scheduled cron event.
	 *
	 * @param string            $hook Hook name.
	 * @param array<int, mixed> $args Hook arguments, passed positionally to the hook callbacks.
	 * @return bool
	 *
	 * @phpstan-param list<mixed> $args
	 */
	public function clear_scheduled_hook( string $hook, array $args = array() ): bool;
}
