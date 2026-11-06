<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine;

use Closure;
use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\ICustomerCountryService;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Money\MoneyAmount;
use D6N\LPM\Modules\Cache\Services\PriceCacheService;
use D6N\LPM\Modules\ExchangeRates\Services\ExchangeRatesService;
use D6N\LPM\Modules\PricingEngine\CategoryPricing\CategoryPricingModel;
use D6N\LPM\Modules\PricingEngine\CategoryPricing\CategoryPricingRepository;
use D6N\LPM\Modules\PricingEngine\CountryCurrency\CountryCurrencyModel;
use D6N\LPM\Modules\PricingEngine\CountryCurrency\CountryCurrencyRepository;
use D6N\LPM\Modules\PricingEngine\CountryCurrency\CountryCurrencyService;
use D6N\LPM\Modules\PricingEngine\CountryGroup\CountryGroupModel;
use D6N\LPM\Modules\PricingEngine\CountryGroup\CountryGroupRepository;
use D6N\LPM\Modules\PricingEngine\CountryPricing\CountryPricingModel;
use D6N\LPM\Modules\PricingEngine\CountryPricing\CountryPricingRepository;
use D6N\LPM\Modules\PricingEngine\CountryPricing\CountryPricingRuleGuard;
use D6N\LPM\Modules\PricingEngine\Services\ListingRulePrefetcher;
use D6N\LPM\Modules\PricingEngine\Services\PriceResolutionService;
use D6N\LPM\Modules\PricingEngine\Services\PricingRuleReader;
use D6N\LPM\Modules\PricingEngine\Services\RuledCountryService;
use D6N\LPM\Modules\PricingEngine\Services\WarmUpProductSelector;
use D6N\LPM\Modules\PricingEngine\Support\PricingRuleMemo;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing engine bounded-context module bootstrapper.
 */
class PricingEngineModule implements IHookable {

	private CountryPricingRepository $country_pricing_repository;
	private CountryPricingRuleGuard $country_pricing_rule_guard;
	private CategoryPricingRepository $category_pricing_repository;
	private CountryCurrencyRepository $country_currency_repository;
	private CountryGroupRepository $country_group_repository;
	private CountryCurrencyService $country_currency_service;
	private PriceResolutionService $resolution_service;
	private ListingRulePrefetcher $listing_prefetcher;
	private RuledCountryService $ruled_country_service;
	private WarmUpProductSelector $warm_up_selector;

	/**
	 * Constructor.
	 *
	 * @param ExchangeRatesService    $exchange_rates  Exchange rates service.
	 * @param ICustomerCountryService $country_service Customer country service.
	 * @param ICache                  $cache           Cache adapter instance.
	 * @param ILogger                 $logger          Logger service.
	 * @param PriceCacheService|null  $price_cache     Resolved price cache; built from $cache when omitted.
	 */
	public function __construct(
		ExchangeRatesService $exchange_rates,
		ICustomerCountryService $country_service,
		ICache $cache,
		ILogger $logger,
		?PriceCacheService $price_cache = null
	) {
		$this->country_pricing_repository  = new CountryPricingRepository( new CountryPricingModel(), $logger );
		$this->category_pricing_repository = new CategoryPricingRepository( new CategoryPricingModel(), $logger );
		$this->country_currency_repository = new CountryCurrencyRepository( new CountryCurrencyModel(), $logger );
		$this->country_group_repository    = new CountryGroupRepository( new CountryGroupModel(), $logger );
		$this->country_pricing_rule_guard  = new CountryPricingRuleGuard( $this->country_pricing_repository, $logger );

		$this->country_currency_service = new CountryCurrencyService(
			$this->country_currency_repository,
			$this->country_group_repository,
			$cache,
			$logger
		);

		$rule_reader = new PricingRuleReader(
			$this->country_pricing_repository,
			$this->category_pricing_repository,
			new PricingRuleMemo()
		);

		$this->resolution_service = new PriceResolutionService(
			$rule_reader,
			$this->country_currency_service,
			$exchange_rates,
			$country_service,
			$price_cache ?? new PriceCacheService( $cache ),
			$logger
		);

		$this->listing_prefetcher = new ListingRulePrefetcher( $rule_reader, $country_service, $logger, $this->resolution_service );

		$this->warm_up_selector = new WarmUpProductSelector( $this->category_pricing_repository, $logger );

		$this->ruled_country_service = new RuledCountryService(
			$this->country_pricing_repository,
			$this->category_pricing_repository,
			$this->country_group_repository,
			$this->country_currency_repository,
			$logger
		);
	}

	/**
	 * Get price resolution service instance.
	 *
	 * @return PriceResolutionService
	 */
	public function get_resolution_service(): PriceResolutionService {
		return $this->resolution_service;
	}

	/**
	 * Get the prefetcher that primes the price cache and the rules of a product list.
	 *
	 * @return ListingRulePrefetcher
	 */
	public function get_listing_prefetcher(): ListingRulePrefetcher {
		return $this->listing_prefetcher;
	}

	/**
	 * Build the closure the cache warm-up resolves one product price for one country with.
	 *
	 * @param Closure $is_supported Product type policy: ( WC_Product $product ): bool.
	 * @return Closure Resolver: ( int $product_id, string $price_type, string $country_code ): array|null, the resolution cache payload, or null when there is nothing to cache.
	 */
	public function make_warm_up_price_resolver( Closure $is_supported ): Closure {
		$resolution = $this->resolution_service;

		return static function ( int $product_id, string $price_type, string $country_code ) use ( $resolution, $is_supported ): ?array {
			if ( ! function_exists( 'wc_get_product' ) ) {
				return null;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product || true !== $is_supported( $product ) ) {
				return null;
			}

			$raw = (string) match ( $price_type ) {
				PriceResolutionService::PRICE_TYPE_REGULAR => $product->get_regular_price( 'edit' ),
				PriceResolutionService::PRICE_TYPE_SALE    => $product->get_sale_price( 'edit' ),
				default                                    => $product->get_price( 'edit' ),
			};

			$base = '' === $raw ? null : MoneyAmount::from( $raw )?->value();

			if ( null === $base ) {
				return null;
			}

			return $resolution->resolve_price( $product, $base, $price_type, $country_code )->to_cache_payload();
		};
	}

	/**
	 * Get the selector of the products the cache warm-up covers.
	 *
	 * @return WarmUpProductSelector
	 */
	public function get_warm_up_product_selector(): WarmUpProductSelector {
		return $this->warm_up_selector;
	}

	/**
	 * Get the service listing the countries an operator has priced.
	 *
	 * @return RuledCountryService
	 */
	public function get_ruled_country_service(): RuledCountryService {
		return $this->ruled_country_service;
	}

	/**
	 * Get the country to currency read model.
	 *
	 * @return CountryCurrencyService
	 */
	public function get_country_currency_service(): CountryCurrencyService {
		return $this->country_currency_service;
	}

	/**
	 * Get the product level rule repository.
	 *
	 * @return CountryPricingRepository
	 */
	public function get_country_pricing_repository(): CountryPricingRepository {
		return $this->country_pricing_repository;
	}

	/**
	 * Get the rules every product level rule write must satisfy.
	 *
	 * @return CountryPricingRuleGuard
	 */
	public function get_country_pricing_rule_guard(): CountryPricingRuleGuard {
		return $this->country_pricing_rule_guard;
	}

	/**
	 * Get the category level rule repository.
	 *
	 * @return CategoryPricingRepository
	 */
	public function get_category_pricing_repository(): CategoryPricingRepository {
		return $this->category_pricing_repository;
	}

	/**
	 * Get the country to currency mapping repository.
	 *
	 * @return CountryCurrencyRepository
	 */
	public function get_country_currency_repository(): CountryCurrencyRepository {
		return $this->country_currency_repository;
	}

	/**
	 * Get the country group repository.
	 *
	 * @return CountryGroupRepository
	 */
	public function get_country_group_repository(): CountryGroupRepository {
		return $this->country_group_repository;
	}

	/**
	 * Register the rule upkeep that must run whether or not the plugin localizes.
	 *
	 * @return void
	 */
	public function register_rule_upkeep(): void {
		$this->country_pricing_rule_guard->register_hooks();
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
		$this->resolution_service->register_hooks();
		$this->listing_prefetcher->register_hooks();
	}
}
