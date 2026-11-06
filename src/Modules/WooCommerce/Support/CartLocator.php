<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Support;

use WC_Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Locator for the cart of the current request.
 */
class CartLocator {

	/**
	 * Get the cart of the current request.
	 *
	 * @return WC_Cart|null Live cart, or null when this request has none.
	 */
	public static function current(): ?WC_Cart {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		// @phpstan-ignore-next-line
		return self::narrow( WC()->cart ?? null );
	}

	/**
	 * Narrow a raw `WC()->cart` value to a cart, or to null.
	 *
	 * @param mixed $cart Raw property value.
	 * @return WC_Cart|null Cart, or null when the value is not one.
	 */
	private static function narrow( mixed $cart ): ?WC_Cart {
		return $cart instanceof WC_Cart ? $cart : null;
	}
}
