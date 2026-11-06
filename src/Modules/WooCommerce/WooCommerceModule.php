<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce;

use D6N\LPM\Core\Contracts\ICustomerCountryService;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Modules\PricingEngine\CountryCurrency\CountryCurrencyService;
use D6N\LPM\Modules\PricingEngine\Services\ListingRulePrefetcher;
use D6N\LPM\Modules\PricingEngine\Services\PriceResolutionService;
use D6N\LPM\Modules\WooCommerce\Services\CartIdentityService;
use D6N\LPM\Modules\WooCommerce\Services\CartPricingService;
use D6N\LPM\Modules\WooCommerce\Services\CurrencyPresentationService;
use D6N\LPM\Modules\WooCommerce\Services\OrderMetaPersistenceService;
use D6N\LPM\Modules\WooCommerce\Services\OrderPricingDisplay;
use D6N\LPM\Modules\WooCommerce\Services\PricePrimingService;
use D6N\LPM\Modules\WooCommerce\Services\PurchasabilityGateService;
use D6N\LPM\Modules\WooCommerce\Services\StoreAPICartHandler;
use D6N\LPM\Modules\WooCommerce\Services\StoreAPIProductHandler;
use D6N\LPM\Modules\WooCommerce\Services\UnsupportedProductService;
use D6N\LPM\Modules\WooCommerce\Services\WidgetCacheService;
use D6N\LPM\Modules\WooCommerce\Support\LocalizedPriceProvider;
use D6N\LPM\Modules\WooCommerce\Support\ProductTypeSupport;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration bounded-context module bootstrapper.
 */
class WooCommerceModule implements IHookable {

	private ProductTypeSupport $product_types;
	private LocalizedPriceProvider $price_provider;
	private OrderMetaPersistenceService $order_meta_service;
	private PurchasabilityGateService $purchasability_gate;
	private CartPricingService $cart_pricing;
	private CartIdentityService $cart_identity;
	private UnsupportedProductService $unsupported_products;
	private CurrencyPresentationService $currency_presentation;
	private StoreAPICartHandler $store_api_cart;
	private StoreAPIProductHandler $store_api_products;
	private OrderPricingDisplay $order_pricing_display;
	private WidgetCacheService $widget_cache;
	private PricePrimingService $price_priming;

	/**
	 * Constructor.
	 *
	 * @param PriceResolutionService  $resolution_service Price resolution service.
	 * @param CountryCurrencyService  $country_currency   Country to currency read model.
	 * @param ICustomerCountryService $country_service    Customer country service.
	 * @param ILogger                 $logger             Logger service.
	 * @param ListingRulePrefetcher   $prefetcher         Prefetcher that primes product lists.
	 */
	public function __construct(
		PriceResolutionService $resolution_service,
		CountryCurrencyService $country_currency,
		ICustomerCountryService $country_service,
		ILogger $logger,
		ListingRulePrefetcher $prefetcher
	) {
		$this->product_types  = new ProductTypeSupport();
		$this->price_provider = new LocalizedPriceProvider( $resolution_service, $country_service, $this->product_types );

		$this->currency_presentation = new CurrencyPresentationService( $country_currency, $country_service, $this->product_types, $logger, $this->price_provider );
		$this->order_meta_service    = new OrderMetaPersistenceService( $country_currency, $this->price_provider, $logger );
		$this->purchasability_gate   = new PurchasabilityGateService( $this->price_provider, $this->product_types, $logger );
		$this->cart_pricing          = new CartPricingService( $this->price_provider, $logger );
		$this->cart_identity         = new CartIdentityService( $this->price_provider, $logger );
		$this->unsupported_products  = new UnsupportedProductService( $this->product_types, $logger );
		$this->store_api_cart        = new StoreAPICartHandler( $this->price_provider, $this->product_types, $logger );
		$this->store_api_products    = new StoreAPIProductHandler( $this->price_provider, $this->currency_presentation, $this->product_types, $logger );
		$this->order_pricing_display = new OrderPricingDisplay( $logger );
		$this->widget_cache          = new WidgetCacheService( $this->price_provider );
		$this->price_priming         = new PricePrimingService( $prefetcher );
	}

	/**
	 * Get the product type policy every service of this module applies.
	 *
	 * @return ProductTypeSupport
	 */
	public function get_product_types(): ProductTypeSupport {
		return $this->product_types;
	}

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$this->currency_presentation->register_hooks();
		$this->order_meta_service->register_hooks();
		$this->purchasability_gate->register_hooks();
		$this->cart_pricing->register_hooks();
		$this->cart_identity->register_hooks();
		$this->unsupported_products->register_hooks();
		$this->store_api_cart->register_hooks();
		$this->store_api_products->register_hooks();
		$this->widget_cache->register_hooks();
		$this->price_priming->register_hooks();

		if ( is_admin() ) {
			$this->order_pricing_display->register_hooks();
		}
	}
}
