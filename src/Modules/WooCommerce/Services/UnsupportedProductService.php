<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\RequestContext;
use D6N\LPM\Modules\WooCommerce\Support\CartLocator;
use D6N\LPM\Modules\WooCommerce\Support\ProductTypeSupport;
use Throwable;
use WC_Cart;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Renders every product type this plugin does not price in the shop's own currency.
 */
class UnsupportedProductService implements IHookable {

	/**
	 * Constructor.
	 *
	 * @param ProductTypeSupport $product_types Product type policy and suppression scope.
	 * @param ILogger            $logger        Logger service.
	 */
	public function __construct(
		private readonly ProductTypeSupport $product_types,
		private readonly ILogger $logger
	) {}

	/**
	 * Register WordPress filter hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress filter hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$priority = WooHookPriorityEnum::Standard->value;

		add_filter( 'woocommerce_get_price_html', array( $this, 'relabel_price_html' ), $priority, 2 );
		add_filter( 'woocommerce_cart_product_price', array( $this, 'relabel_cart_product_price' ), $priority, 2 );
		add_filter( 'woocommerce_cart_product_subtotal', array( $this, 'relabel_cart_product_subtotal' ), $priority, 4 );

		add_filter(
			Core::build_hook( HookEnum::PricingIsConversionEnabled->value ),
			array( $this, 'skip_unsupported_products' ),
			WooHookPriorityEnum::VeryLate->value,
			2
		);
	}

	/**
	 * Tell the pricing engine to leave unsupported products, and open scopes, alone.
	 *
	 * @param bool       $enabled Whether localization should run. Default true.
	 * @param WC_Product $product Product being priced.
	 * @return bool
	 */
	public function skip_unsupported_products( bool $enabled, WC_Product $product ): bool {
		if ( ! $enabled ) {
			return false;
		}

		return $this->product_types->applies_to( $product );
	}

	/**
	 * Re-render an unsupported product's price HTML in the shop's own currency.
	 *
	 * @param string     $html    Price HTML WooCommerce produced.
	 * @param WC_Product $product Product the HTML belongs to.
	 * @return string
	 */
	public function relabel_price_html( string $html, WC_Product $product ): string {
		return $this->relabel( $html, $product, static fn (): string => (string) $product->get_price_html() );
	}

	/**
	 * Re-render an unsupported product's cart row price in the shop's own currency.
	 *
	 * @param string     $html    Formatted price WooCommerce produced.
	 * @param WC_Product $product Product of the cart row.
	 * @return string
	 */
	public function relabel_cart_product_price( string $html, WC_Product $product ): string {
		$cart = CartLocator::current();

		if ( null === $cart ) {
			return $html;
		}

		return $this->relabel( $html, $product, static fn (): string => (string) $cart->get_product_price( $product ) );
	}

	/**
	 * Re-render an unsupported product's cart row subtotal in the shop's own currency.
	 *
	 * @param string     $html     Formatted subtotal WooCommerce produced.
	 * @param WC_Product $product  Product of the cart row.
	 * @param int        $quantity Quantity of the cart row.
	 * @param mixed      $cart     Cart the row belongs to.
	 * @return string
	 */
	public function relabel_cart_product_subtotal( string $html, WC_Product $product, int $quantity = 1, mixed $cart = null ): string {
		$cart = $cart instanceof WC_Cart ? $cart : CartLocator::current();

		if ( null === $cart ) {
			return $html;
		}

		return $this->relabel( $html, $product, static fn (): string => (string) $cart->get_product_subtotal( $product, $quantity ) );
	}

	/**
	 * Replace a localized string with the same string rendered under suppression.
	 *
	 * @param string             $html    String WooCommerce produced.
	 * @param WC_Product         $product Product the string belongs to.
	 * @param callable(): string $render Callback that renders the string again.
	 * @return string The unlocalized rendering, or the original on any failure.
	 */
	private function relabel( string $html, WC_Product $product, callable $render ): string {
		if ( $this->product_types->is_suppressed() ) {
			return $html;
		}

		if ( ! Core::is_active() || RequestContext::is_wp_admin() ) {
			return $html;
		}

		if ( $this->product_types->is_supported( $product ) ) {
			return $html;
		}

		try {
			return $this->product_types->without_localization( $render );
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Base currency re-render failed for product %d: %s', $product->get_id(), $e->getMessage() ),
				array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				),
				'WooCommerce'
			);

			return $html;
		}
	}
}
