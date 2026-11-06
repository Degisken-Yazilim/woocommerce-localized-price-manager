<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for classes that register WordPress hooks.
 */
interface IHookable {
	/**
	 * Register all WordPress actions and filters for this component.
	 *
	 * @return void
	 */
	public function register(): void;
}
