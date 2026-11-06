<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for the background action queue.
 */
interface IActionQueue {

	/**
	 * Whether the queue backend is loaded.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

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
	public function enqueue( string $hook, array $args, string $group ): int;

	/**
	 * Cancel every pending action of one hook, whatever its arguments.
	 *
	 * @param string $hook Action hook name.
	 * @return void
	 */
	public function cancel_all( string $hook ): void;
}
