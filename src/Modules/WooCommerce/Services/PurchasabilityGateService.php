<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\RequestContext;
use D6N\LPM\Modules\WooCommerce\Support\LocalizedPriceProvider;
use D6N\LPM\Modules\WooCommerce\Support\ProductTypeSupport;
use Throwable;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Hides and blocks products that have no purchasable price in the visitor's country.
 */
class PurchasabilityGateService implements IHookable {

	/**
	 * Visibility decisions of this request, keyed by product ID.
	 *
	 * @var array<int, bool>
	 */
	private array $visibility = array();

	/**
	 * Constructor.
	 *
	 * @param LocalizedPriceProvider $prices        Resolved price provider.
	 * @param ProductTypeSupport     $product_types Product type policy and suppression scope.
	 * @param ILogger                $logger        Logger service.
	 */
	public function __construct(
		private readonly LocalizedPriceProvider $prices,
		private readonly ProductTypeSupport $product_types,
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

		add_action( Core::build_hook( HookEnum::PricingProductHidden->value ), array( $this, 'mark_product_as_hidden' ), $priority, 1 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'maybe_hide_add_to_cart_button' ), $priority, 2 );
		add_filter( 'woocommerce_product_is_purchasable', array( $this, 'maybe_make_product_unpurchasable' ), $priority, 2 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'maybe_make_product_unpurchasable' ), $priority, 2 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'maybe_hide_price_html' ), WooHookPriorityEnum::Late->value, 2 );
		add_filter( 'woocommerce_empty_price_html', array( $this, 'maybe_hide_price_html' ), WooHookPriorityEnum::Late->value, 2 );
	}

	/**
	 * Record that the pricing engine hid a product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function mark_product_as_hidden( int $product_id ): void {
		if ( $product_id > 0 ) {
			$this->visibility[ $product_id ] = true;
		}
	}

	/**
	 * Decide whether a product must be hidden in the visitor's country.
	 *
	 * @param WC_Product $product Product instance.
	 * @return bool True when the product has no purchasable price here.
	 */
	public function should_hide_product( WC_Product $product ): bool {
		$product_id = $product->get_id();

		if ( $product_id > 0 && array_key_exists( $product_id, $this->visibility ) ) {
			return $this->visibility[ $product_id ];
		}

		$hidden = false;

		try {
			$hidden = $this->resolve_visibility( $product );
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Purchasability check failed for product %d: %s', $product_id, $e->getMessage() ),
				array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				),
				'WooCommerce'
			);
		}

		if ( $product_id > 0 ) {
			$this->visibility[ $product_id ] = $hidden;
		}

		return $hidden;
	}

	/**
	 * Remove the loop add-to-cart button of a hidden product.
	 *
	 * @param string     $html    Button HTML.
	 * @param WC_Product $product Product instance.
	 * @return string
	 */
	public function maybe_hide_add_to_cart_button( string $html, WC_Product $product ): string {
		if ( ! $this->gate_applies( $product ) ) {
			return $html;
		}

		return $this->should_hide_product( $product ) ? '' : $html;
	}

	/**
	 * Remove the price HTML of a hidden product.
	 *
	 * @param string     $html    Price HTML.
	 * @param WC_Product $product Product instance.
	 * @return string
	 */
	public function maybe_hide_price_html( string $html, WC_Product $product ): string {
		if ( ! $this->gate_applies( $product ) ) {
			return $html;
		}

		return $this->should_hide_product( $product ) ? '' : $html;
	}

	/**
	 * Make a hidden product unpurchasable.
	 *
	 * @param bool       $is_purchasable Current purchasable flag.
	 * @param WC_Product $product        Product instance.
	 * @return bool
	 */
	public function maybe_make_product_unpurchasable( bool $is_purchasable, WC_Product $product ): bool {
		if ( ! $is_purchasable ) {
			return false;
		}

		if ( ! $this->gate_applies( $product ) ) {
			return $is_purchasable;
		}

		return ! $this->should_hide_product( $product );
	}

	/**
	 * Whether the gate has anything to say about this product, in this request.
	 *
	 * @param WC_Product $product Product instance.
	 * @return bool
	 */
	private function gate_applies( WC_Product $product ): bool {
		return ! RequestContext::is_wp_admin() && $this->product_types->applies_to( $product );
	}

	/**
	 * Ask the (cached) pricing engine whether this product still has a price.
	 *
	 * @param WC_Product $product Product instance.
	 * @return bool
	 */
	private function resolve_visibility( WC_Product $product ): bool {
		if ( ! Core::is_active() ) {
			return false;
		}

		if ( '' === $this->prices->get_country() ) {
			return false;
		}

		return '' === trim( (string) $product->get_price() );
	}
}
