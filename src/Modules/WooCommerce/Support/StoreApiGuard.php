<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Availability probe for the WooCommerce Store API.
 */
class StoreApiGuard {

	/**
	 * Memoised probe result, null until the first call.
	 *
	 * @var bool|null
	 */
	private static ?bool $available = null;

	/**
	 * Check whether the Store API is present and hookable.
	 *
	 * @return bool True when Store API filters may be registered.
	 */
	public static function is_available(): bool {
		if ( null !== self::$available ) {
			return self::$available;
		}

		self::$available = self::probe();

		return self::$available;
	}

	/**
	 * Run the actual availability checks.
	 *
	 * @return bool
	 */
	private static function probe(): bool {
		if ( ! defined( 'WC_VERSION' ) ) {
			return false;
		}

		return class_exists( 'Automattic\WooCommerce\StoreApi\StoreApi' );
	}
}
