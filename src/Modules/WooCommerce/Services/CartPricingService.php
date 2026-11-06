<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\RequestContext;
use D6N\LPM\Modules\PricingEngine\Services\PriceResolutionService;
use D6N\LPM\Modules\PricingEngine\ValueObjects\ResolvedPrice;
use D6N\LPM\Modules\WooCommerce\Support\CartLocator;
use D6N\LPM\Modules\WooCommerce\Support\LocalizedPriceProvider;
use Throwable;
use WC_Cart;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the localized price onto the in-memory cart item products.
 */
class CartPricingService implements IHookable {

	/**
	 * Constructor.
	 *
	 * @param LocalizedPriceProvider $prices Resolved price provider.
	 * @param ILogger                $logger Logger service.
	 */
	public function __construct(
		private readonly LocalizedPriceProvider $prices,
		private readonly ILogger $logger
	) {}

	/**
	 * Register WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$priority = WooHookPriorityEnum::Standard->value;

		add_action( 'woocommerce_before_calculate_totals', array( $this, 'recalculate_cart_item_prices' ), $priority, 1 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), $priority, 4 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), $priority, 6 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'on_cart_item_removed' ), $priority, 0 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'on_cart_emptied' ), $priority, 0 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'on_refreshed_fragments' ), $priority, 1 );
		add_filter(
			Core::build_hook( HookEnum::PricingIsConversionEnabled->value ),
			array( $this, 'skip_already_localized_products' ),
			WooHookPriorityEnum::VeryLate->value,
			3
		);
	}

	/**
	 * Write the localized price onto every cart item product.
	 *
	 * @param WC_Cart $cart Cart instance.
	 * @return void
	 */
	public function recalculate_cart_item_prices( WC_Cart $cart ): void {
		try {
			$this->localize_cart( $cart );
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Cart price localization failed: %s', $e->getMessage() ),
				array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				),
				'WooCommerce'
			);
		}
	}

	/**
	 * Refresh cart fragments for mini-cart drawers.
	 *
	 * @param array<string, mixed> $fragments Mini-cart HTML fragments.
	 * @return array<string, mixed>
	 */
	public function on_refreshed_fragments( array $fragments ): array {
		$cart = CartLocator::current();

		if ( null !== $cart ) {
			$this->recalculate_cart_item_prices( $cart );
		}

		return $fragments;
	}

	/**
	 * Tell the pricing engine to skip the one price it has already written.
	 *
	 * @param bool       $enabled    Whether localization should run. Default true.
	 * @param WC_Product $product    Product being priced.
	 * @param string     $price_type Price type being filtered: 'price', 'regular' or 'sale'.
	 * @return bool
	 */
	public function skip_already_localized_products( bool $enabled, WC_Product $product, string $price_type = PriceResolutionService::PRICE_TYPE_ACTIVE ): bool {
		if ( ! $enabled ) {
			return false;
		}

		return ! $this->prices->is_localized( $product, $price_type );
	}

	/**
	 * Refuse a product whose currency differs from the currency of the cart.
	 *
	 * @param mixed $passed       Whether the product passed validation so far.
	 * @param mixed $product_id   Product ID being added.
	 * @param mixed $quantity     Quantity being added.
	 * @param mixed $variation_id Variation ID being added, 0 for none.
	 * @return bool
	 */
	public function validate_add_to_cart( mixed $passed, mixed $product_id = 0, mixed $quantity = 1, mixed $variation_id = 0 ): bool {
		if ( ! $passed ) {
			return false;
		}

		try {
			if ( ! Core::is_active() || RequestContext::is_wp_admin() ) {
				return true;
			}

			$cart = CartLocator::current();

			if ( null === $cart || $cart->is_empty() ) {
				return true;
			}

			$target_id = (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id;
			$product   = function_exists( 'wc_get_product' ) ? wc_get_product( $target_id ) : null;

			if ( ! ( $product instanceof WC_Product ) ) {
				return true;
			}

			$cart_currency = $this->get_cart_currency( $cart );

			if ( null === $cart_currency || $this->currency_of( $product ) === $cart_currency ) {
				return true;
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Cart currency validation failed: %s', $e->getMessage() ),
				array( 'product_id' => (int) $product_id ),
				'WooCommerce'
			);

			return true;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				__( 'This product is sold in a different currency from the products already in your cart. Complete your purchase or empty your cart before adding it.', 'localized-price-manager' ),
				'error'
			);
		}

		return false;
	}

	/**
	 * Record the currency of the product just added as the cart currency.
	 *
	 * @param string               $cart_item_key Cart item key.
	 * @param int                  $product_id    Product ID added.
	 * @param int                  $quantity      Quantity added.
	 * @param int                  $variation_id  Variation ID if variation.
	 * @param array<string, mixed> $variation     Variation attributes.
	 * @param array<string, mixed> $cart_item_data Extra cart item data.
	 * @return void
	 */
	public function on_add_to_cart( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id > 0 ? $variation_id : $product_id ) : null;

		if ( ! ( $product instanceof WC_Product ) ) {
			return;
		}

		WC()->session->set( 'd6n_lpm_active_cart_currency', $this->currency_of( $product ) );
		WC()->session->set( 'd6n_lpm_last_added_item_key', $cart_item_key );
		WC()->session->set( 'd6n_lpm_cart_country', $this->prices->get_country() );
	}

	/**
	 * Get the currency a product is sold in for the current visitor.
	 *
	 * @param WC_Product $product Product.
	 * @return string Upper-case currency code.
	 */
	private function currency_of( WC_Product $product ): string {
		$resolved = $this->prices->resolve( $product );

		return null !== $resolved && '' !== $resolved->currency
			? strtoupper( trim( $resolved->currency ) )
			: strtoupper( trim( Core::get_shop_base_currency() ) );
	}

	/**
	 * Get the currency the cart is held in.
	 *
	 * @param WC_Cart $cart Cart instance.
	 * @return string|null Upper-case currency code, or null when the cart holds no product.
	 */
	private function get_cart_currency( WC_Cart $cart ): ?string {
		$session_currency = ( function_exists( 'WC' ) && null !== WC()->session )
			? WC()->session->get( 'd6n_lpm_active_cart_currency' )
			: null;

		if ( is_string( $session_currency ) && '' !== $session_currency ) {
			return strtoupper( $session_currency );
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$existing = $cart_item['data'] ?? null;

			if ( $existing instanceof WC_Product ) {
				return $this->currency_of( $existing );
			}
		}

		return null;
	}

	/**
	 * Reset active cart currency when all items are removed one by one.
	 *
	 * @return void
	 */
	public function on_cart_item_removed(): void {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( null !== $cart && $cart->is_empty() ) {
			$this->on_cart_emptied();
		}
	}

	/**
	 * Reset active cart currency when the cart is emptied.
	 *
	 * @return void
	 */
	public function on_cart_emptied(): void {
		if ( function_exists( 'WC' ) && null !== WC()->session ) {
			WC()->session->set( 'd6n_lpm_active_cart_currency', null );
			WC()->session->set( 'd6n_lpm_last_added_item_key', null );
			WC()->session->set( 'd6n_lpm_cart_country', null );
		}
	}

	/**
	 * Resolve and apply the localized price of every cart line.
	 *
	 * @param WC_Cart $cart Cart instance.
	 * @return void
	 */
	private function localize_cart( WC_Cart $cart ): void {
		if ( RequestContext::is_wp_admin() || RequestContext::is_foreign_rest_request() ) {
			return;
		}

		if ( ! Core::is_active() || $cart->is_empty() ) {
			return;
		}

		$country       = $this->prices->get_country();
		$items         = $cart->get_cart();
		$last_item_key = function_exists( 'WC' ) && null !== WC()->session
			? WC()->session->get( 'd6n_lpm_last_added_item_key' )
			: null;

		$target_item = null;
		if ( is_string( $last_item_key ) && isset( $items[ $last_item_key ]['data'] ) && $items[ $last_item_key ]['data'] instanceof WC_Product ) {
			$target_item = $items[ $last_item_key ]['data'];
		} else {
			$last_entry = end( $items );
			if ( is_array( $last_entry ) && isset( $last_entry['data'] ) && $last_entry['data'] instanceof WC_Product ) {
				$target_item = $last_entry['data'];
			}
		}

		$active_cart_currency = null;
		if ( null !== $target_item ) {
			$resolved = $this->prices->resolve( $target_item );
			if ( null !== $resolved && '' !== $resolved->currency ) {
				$active_cart_currency = strtoupper( trim( $resolved->currency ) );
			}
		}

		if ( null === $active_cart_currency ) {
			$active_cart_currency = Core::get_shop_base_currency();
		}

		if ( function_exists( 'WC' ) && null !== WC()->session ) {
			WC()->session->set( 'd6n_lpm_active_cart_currency', $active_cart_currency );
			WC()->session->set( 'd6n_lpm_cart_country', $country );
		}

		$localized = 0;

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;

			if ( ! ( $product instanceof WC_Product ) ) {
				continue;
			}

			$resolved = $this->prices->resolve( $product );

			if ( null === $resolved ) {
				continue;
			}

			$charged = $resolved;

			if ( $resolved->currency !== $active_cart_currency ) {
				$base_price = (string) $this->prices->get_base_price( $product, PriceResolutionService::PRICE_TYPE_ACTIVE );
				$converted  = $this->prices->convert_with_rate( $base_price, $active_cart_currency );

				if ( null !== $converted ) {
					$charged = new ResolvedPrice(
						price: $converted['amount'],
						original_price: $base_price,
						currency: $active_cart_currency,
						country_code: $country,
						resolution_mode: 'cart_currency',
						is_blocked: false,
						rate: $converted['rate']
					);
				}
			}

			$price = (string) $charged->price;

			if ( '' === $price ) {
				continue;
			}

			$product->set_price( $price );
			$this->prices->record_charge( $product, $charged );

			$this->prices->mark_localized( $product, PriceResolutionService::PRICE_TYPE_ACTIVE );
			++$localized;
		}

		if ( 0 === $localized ) {
			return;
		}

		$this->logger->debug(
			sprintf( 'Localized %d cart line(s) for country %s.', $localized, $country ),
			array( 'country_code' => $country ),
			'WooCommerce'
		);

		/**
		 * Fires after every cart line has been priced in the visitor's currency.
		 *
		 * @param WC_Cart $cart         The cart object.
		 * @param string  $country_code Customer ISO country code.
		 */
		do_action( Core::build_hook( HookEnum::PricingCartPricesUpdated->value ), $cart, $country );
	}
}
