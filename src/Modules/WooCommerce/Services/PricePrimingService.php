<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Modules\PricingEngine\Services\ListingRulePrefetcher;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Primes the price cache for the product lists WooCommerce builds from ids alone.
 */
class PricePrimingService implements IHookable {

	/**
	 * Post type of the products a list is primed for.
	 */
	private const PRODUCT_POST_TYPE = 'product';

	/**
	 * Constructor.
	 *
	 * @param ListingRulePrefetcher $prefetcher Prefetcher that primes a product list.
	 */
	public function __construct(
		private readonly ListingRulePrefetcher $prefetcher
	) {}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_load_cart_from_session', array( $this, 'prime_cart' ), WooHookPriorityEnum::Standard->value, 0 );
		add_filter( 'woocommerce_upsell_display_args', array( $this, 'prime_upsells' ), WooHookPriorityEnum::VeryLate->value, 1 );
		add_filter( 'woocommerce_cart_crosssell_ids', array( $this, 'prime_list' ), WooHookPriorityEnum::VeryLate->value, 1 );
		add_filter( 'update_post_metadata_cache', array( $this, 'prime_cached_products' ), WooHookPriorityEnum::VeryLate->value, 2 );
	}

	/**
	 * Action callback for `woocommerce_load_cart_from_session`: prime the simple products of the session cart.
	 *
	 * @return void
	 */
	public function prime_cart(): void {
		$session = function_exists( 'WC' ) ? WC()->session : null;
		$rows    = null === $session ? null : $session->get( 'cart' );

		if ( ! is_array( $rows ) ) {
			return;
		}

		$product_ids = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && empty( $row['variation_id'] ) && isset( $row['product_id'] ) ) {
				$product_ids[] = $row['product_id'];
			}
		}

		$this->prefetcher->prime( $product_ids );
	}

	/**
	 * Filter callback for `woocommerce_upsell_display_args`: prime the up-sells of the product page.
	 *
	 * @param mixed $args Up-sell display arguments.
	 * @return mixed The arguments, unchanged.
	 */
	public function prime_upsells( mixed $args ): mixed {
		$product = $GLOBALS['product'] ?? null;

		if ( $product instanceof WC_Product ) {
			$this->prefetcher->prime( $product->get_upsell_ids() );
		}

		return $args;
	}

	/**
	 * Filter callback for the cart cross-sell id list: prime the listed products.
	 *
	 * @param mixed $product_ids Product IDs.
	 * @return mixed The list, unchanged.
	 */
	public function prime_list( mixed $product_ids ): mixed {
		if ( is_array( $product_ids ) ) {
			$this->prefetcher->prime( $product_ids );
		}

		return $product_ids;
	}

	/**
	 * Filter callback for `update_post_metadata_cache`: prime the products of an id list whose posts are already cached.
	 *
	 * @param mixed $check      Short-circuit value, handed back unchanged.
	 * @param mixed $object_ids Post IDs about to have their meta cached.
	 * @return mixed The short-circuit value, unchanged.
	 */
	public function prime_cached_products( mixed $check, mixed $object_ids ): mixed {
		if ( ! is_array( $object_ids ) || count( $object_ids ) < 2 ) {
			return $check;
		}

		$product_ids = array();

		foreach ( wp_cache_get_multiple( $object_ids, 'posts' ) as $id => $post ) {
			if ( is_object( $post ) && self::PRODUCT_POST_TYPE === ( $post->post_type ?? null ) ) {
				$product_ids[] = (int) $id;
			}
		}

		if ( count( $product_ids ) > 1 ) {
			$this->prefetcher->prime( $product_ids );
		}

		return $check;
	}
}
