<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\ValueObjects;

defined( 'ABSPATH' ) || exit;

/**
 * Sentinel value object to distinguish cache miss from cached boolean false value.
 */
class CacheMiss {

	private static ?CacheMiss $instance = null;

	private function __construct() {}

	/**
	 * Get singleton instance of CacheMiss sentinel.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}
