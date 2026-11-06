<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Support;

use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Shared\Core;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Which products this plugin prices, and the scope in which it prices nothing.
 */
class ProductTypeSupport {

	/**
	 * The single WooCommerce product type this plugin prices.
	 */
	private const SUPPORTED_TYPE = 'simple';

	/**
	 * How many suppression scopes are currently open.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Whether this plugin prices products of this type at all.
	 *
	 * @param WC_Product $product Product instance.
	 * @return bool True for a simple product, false for every other type.
	 */
	public function is_supported( WC_Product $product ): bool {
		$supported = self::SUPPORTED_TYPE === (string) $product->get_type();

		/**
		 * Filters whether a product type takes part in price localization.
		 *
		 * @param bool       $supported Whether the product is localized.
		 * @param WC_Product $product   The WooCommerce product.
		 */
		return (bool) apply_filters(
			Core::build_hook( HookEnum::ProductIsSupportedType->value ),
			$supported,
			$product
		);
	}

	/**
	 * Whether a suppression scope is currently open.
	 *
	 * @return bool True while nothing at all may be localized.
	 */
	public function is_suppressed(): bool {
		return $this->depth > 0;
	}

	/**
	 * Whether a product may be localized right now.
	 *
	 * @param WC_Product $product Product instance.
	 * @return bool True when the type is supported and no scope is open.
	 */
	public function applies_to( WC_Product $product ): bool {
		return ! $this->is_suppressed() && $this->is_supported( $product );
	}

	/**
	 * Run a callback with every form of localization switched off.
	 *
	 * @template TReturn
	 * @param callable(): TReturn $callback Callback to run inside the scope.
	 * @return TReturn Whatever the callback returned.
	 */
	public function without_localization( callable $callback ): mixed {
		++$this->depth;

		try {
			return $callback();
		} finally {
			--$this->depth;
		}
	}
}
