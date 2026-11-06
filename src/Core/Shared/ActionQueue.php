<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Shared;

use D6N\LPM\Core\Contracts\IActionQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Background action queue backed by Action Scheduler.
 */
class ActionQueue implements IActionQueue {

	/**
	 * Whether the Action Scheduler API is loaded.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Enqueue an action to run once, as soon as possible.
	 *
	 * @param string            $hook  Action hook name.
	 * @param array<int, mixed> $args  Arguments, passed positionally to the hook callbacks.
	 * @param string            $group Action group.
	 * @return int Action ID, or 0 when nothing was enqueued.
	 *
	 * @phpstan-param list<scalar> $args
	 */
	public function enqueue( string $hook, array $args, string $group ): int {
		if ( ! $this->is_available() ) {
			return 0;
		}

		return (int) as_enqueue_async_action( $hook, $args, $group );
	}

	/**
	 * Cancel every pending action of one hook, whatever its arguments.
	 *
	 * @param string $hook Action hook name.
	 * @return void
	 */
	public function cancel_all( string $hook ): void {
		if ( ! $this->is_available() ) {
			return;
		}

		as_unschedule_all_actions( $hook );
	}
}
