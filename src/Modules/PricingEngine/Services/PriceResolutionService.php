<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\Services;

use D6N\LPM\Core\Contracts\ICustomerCountryService;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\FallbackModeEnum;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Enums\RoundingModeEnum;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Money\DecimalMath;
use D6N\LPM\Core\Money\DecimalText;
use D6N\LPM\Core\Money\ExchangeRate;
use D6N\LPM\Core\Money\MoneyAmount;
use D6N\LPM\Core\Reference\CountryCurrencyMap;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\RequestContext;
use D6N\LPM\Core\Shared\Utils;
use D6N\LPM\Modules\Cache\Services\PriceCacheService;
use D6N\LPM\Modules\ExchangeRates\Services\ExchangeRatesService;
use D6N\LPM\Modules\PricingEngine\CountryCurrency\CountryCurrencyService;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use D6N\LPM\Modules\PricingEngine\ValueObjects\ResolvedPrice;
use stdClass;
use Throwable;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Master price resolution engine.
 */
class PriceResolutionService implements IHookable {

	/**
	 * Price type of `woocommerce_product_get_price` — whatever WooCommerce currently charges.
	 */
	public const PRICE_TYPE_ACTIVE = 'price';

	/**
	 * Price type of `woocommerce_product_get_regular_price`.
	 */
	public const PRICE_TYPE_REGULAR = 'regular';

	/**
	 * Price type of `woocommerce_product_get_sale_price`.
	 */
	public const PRICE_TYPE_SALE = 'sale';

	/**
	 * Most delivered prices the request memo holds before it starts over.
	 */
	public const MAX_DELIVERED_ENTRIES = 10000;

	/**
	 * Prices already delivered in this request, keyed by product, price type, country and base price.
	 *
	 * @var array<string, mixed>
	 */
	private array $delivered = array();

	/**
	 * Cache generation the delivered prices belong to.
	 *
	 * @var int
	 */
	private int $delivered_generation = 0;

	/**
	 * Constructor.
	 *
	 * @param PricingRuleReader       $rules            Product and category rule reader.
	 * @param CountryCurrencyService  $country_currency Country mapping and group read model.
	 * @param ExchangeRatesService    $exchange_rates   Exchange rates service.
	 * @param ICustomerCountryService $country_service  Customer country service.
	 * @param PriceCacheService       $price_cache      Resolved price cache.
	 * @param ILogger                 $logger           Logger service.
	 */
	public function __construct(
		private readonly PricingRuleReader $rules,
		private readonly CountryCurrencyService $country_currency,
		private readonly ExchangeRatesService $exchange_rates,
		private readonly ICustomerCountryService $country_service,
		private readonly PriceCacheService $price_cache,
		private readonly ILogger $logger
	) {}

	/**
	 * Register WordPress action & filter hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress action & filter hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$priority = WooHookPriorityEnum::Standard->value;

		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_active_price' ), $priority, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( $this, 'filter_regular_price' ), $priority, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'filter_sale_price' ), $priority, 2 );
	}

	/**
	 * Filter callback for `woocommerce_product_get_price`.
	 *
	 * @param mixed      $price   Original WooCommerce price.
	 * @param WC_Product $product Product instance.
	 * @return mixed Localized price, or the original value when nothing applies.
	 */
	public function filter_active_price( mixed $price, WC_Product $product ): mixed {
		return $this->filter_price( $price, $product, self::PRICE_TYPE_ACTIVE );
	}

	/**
	 * Filter callback for `woocommerce_product_get_regular_price`.
	 *
	 * @param mixed      $price   Original WooCommerce price.
	 * @param WC_Product $product Product instance.
	 * @return mixed Localized price, or the original value when nothing applies.
	 */
	public function filter_regular_price( mixed $price, WC_Product $product ): mixed {
		return $this->filter_price( $price, $product, self::PRICE_TYPE_REGULAR );
	}

	/**
	 * Filter callback for `woocommerce_product_get_sale_price`.
	 *
	 * @param mixed      $price   Original WooCommerce price.
	 * @param WC_Product $product Product instance.
	 * @return mixed Localized price, or the original value when nothing applies.
	 */
	public function filter_sale_price( mixed $price, WC_Product $product ): mixed {
		return $this->filter_price( $price, $product, self::PRICE_TYPE_SALE );
	}

	/**
	 * Run the full resolution hierarchy for one product, price type and country.
	 *
	 * @param WC_Product   $product      Product instance.
	 * @param float|string $base_price   Store base price, read from the CRUD 'edit' context.
	 * @param string       $price_type   'price', 'regular' or 'sale'.
	 * @param string       $country_code Customer ISO country code.
	 * @return ResolvedPrice
	 */
	public function resolve_price( WC_Product $product, float|string $base_price, string $price_type, string $country_code ): ResolvedPrice {
		$country_code = CountryCodeValidator::sanitize( $country_code );
		$base         = DecimalText::plain( $base_price ) ?? '0';

		try {
			return $this->run_chain( $product, $base, $price_type, $country_code );
		} catch ( Throwable $e ) {
			$this->log_failure( 'Price resolution failed', $product, $e );

			return new ResolvedPrice(
				price: $base,
				original_price: $base,
				currency: Core::get_shop_base_currency(),
				country_code: $country_code,
				resolution_mode: 'fallback_direct',
				rate: '1'
			);
		}
	}

	/**
	 * Guard every filter callback so no throwable reaches WooCommerce.
	 *
	 * @param mixed      $price      Original WooCommerce price.
	 * @param WC_Product $product    Product instance.
	 * @param string     $price_type Price type being filtered.
	 * @return mixed
	 */
	private function filter_price( mixed $price, WC_Product $product, string $price_type ): mixed {
		try {
			return $this->localize_price( $price, $product, $price_type );
		} catch ( Throwable $e ) {
			$this->log_failure( 'Price filtering failed', $product, $e );

			return $price;
		}
	}

	/**
	 * Resolve, cache and return the localized price for the current request.
	 *
	 * @param mixed      $price      Original WooCommerce price.
	 * @param WC_Product $product    Product instance.
	 * @param string     $price_type Price type being filtered.
	 * @return mixed
	 */
	private function localize_price( mixed $price, WC_Product $product, string $price_type ): mixed {
		if ( ! Core::is_active() ) {
			return $price;
		}

		if ( RequestContext::is_wp_admin() ) {
			return $price;
		}

		if ( RequestContext::is_foreign_rest_request() ) {
			return $price;
		}

		/**
		 * Filters whether price localization runs for a given product.
		 *
		 * @param bool       $enabled    Whether localization is enabled. Default true.
		 * @param WC_Product $product    The WooCommerce product.
		 * @param string     $price_type Price type being filtered: 'price', 'regular' or 'sale'.
		 */
		$enabled = apply_filters( Core::build_hook( HookEnum::PricingIsConversionEnabled->value ), true, $product, $price_type );

		if ( ! $enabled ) {
			return $price;
		}

		$country_code = CountryCodeValidator::sanitize( (string) $this->country_service->get_current_user_country() );

		$raw_price = $this->read_base_price( $product, $price_type );

		if ( '' === $raw_price ) {
			return $price;
		}

		$memo_key = $product->get_id() . '|' . $price_type . '|' . $country_code . '|' . $raw_price;

		$this->follow_cache_generation();

		if ( array_key_exists( $memo_key, $this->delivered ) ) {
			return $this->delivered[ $memo_key ];
		}

		$base_price = $this->normalize_base_price( $raw_price );

		if ( null === $base_price ) {
			return $price;
		}

		$cached = $this->get_cached_resolution( $product->get_id(), $price_type, $country_code );

		if ( null !== $cached ) {
			return $this->remember_delivered( $memo_key, $this->deliver( $cached->price, $product, $price_type, $country_code, false ) );
		}

		$resolved = $this->resolve_price( $product, $base_price, $price_type, $country_code );

		$this->cache_resolution( $product->get_id(), $price_type, $country_code, $resolved );

		return $this->remember_delivered( $memo_key, $this->deliver( $resolved->price, $product, $price_type, $country_code, true ) );
	}

	/**
	 * Empty the delivered prices when the price cache has been flushed since they were stored.
	 *
	 * @return void
	 */
	private function follow_cache_generation(): void {
		$generation = $this->price_cache->get_generation();

		if ( $generation !== $this->delivered_generation ) {
			$this->delivered            = array();
			$this->delivered_generation = $generation;
		}
	}

	/**
	 * Keep one delivered price for the rest of the request.
	 *
	 * @param string $memo_key  Product, price type, country and base price.
	 * @param mixed  $delivered Price as delivered to WooCommerce.
	 * @return mixed The delivered price.
	 */
	private function remember_delivered( string $memo_key, mixed $delivered ): mixed {
		if ( count( $this->delivered ) >= self::MAX_DELIVERED_ENTRIES ) {
			$this->delivered = array();
		}

		$this->delivered[ $memo_key ] = $delivered;

		return $delivered;
	}

	/**
	 * Answer from the resolution cache, resolving and caching on a miss.
	 *
	 * @param WC_Product   $product      Product instance.
	 * @param float|string $base_price   Store base price, read from the CRUD 'edit' context.
	 * @param string       $price_type   'price', 'regular' or 'sale'.
	 * @param string       $country_code Customer ISO country code.
	 * @return ResolvedPrice
	 */
	public function resolve_cached_price( WC_Product $product, float|string $base_price, string $price_type, string $country_code ): ResolvedPrice {
		$country_code = CountryCodeValidator::sanitize( $country_code );
		$cached       = $this->get_cached_resolution( $product->get_id(), $price_type, $country_code );

		if ( null !== $cached ) {
			return $cached;
		}

		$resolved = $this->resolve_price( $product, $base_price, $price_type, $country_code );

		$this->cache_resolution( $product->get_id(), $price_type, $country_code, $resolved );

		return $resolved;
	}

	/**
	 * Read one cached resolution.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $price_type   Price type.
	 * @param string $country_code Customer ISO country code.
	 * @return ResolvedPrice|null The cached resolution, or null on a miss or an entry of any other shape.
	 */
	public function get_cached_resolution( int $product_id, string $price_type, string $country_code ): ?ResolvedPrice {
		return ResolvedPrice::from_cache_payload( $this->price_cache->get( $product_id, $price_type, $country_code ) );
	}

	/**
	 * Read the cached resolutions of several products, every price type.
	 *
	 * @param array<int, int> $product_ids  Product IDs.
	 * @param string          $country_code Customer ISO country code.
	 * @return array<int, array<string, ResolvedPrice|null>> Resolutions keyed by product ID, then price type; null on a miss or an entry of any other shape.
	 */
	public function get_cached_resolutions( array $product_ids, string $country_code ): array {
		$cached = $this->price_cache->get_many(
			$product_ids,
			array( self::PRICE_TYPE_ACTIVE, self::PRICE_TYPE_REGULAR, self::PRICE_TYPE_SALE ),
			$country_code
		);

		$resolutions = array();

		foreach ( $cached as $product_id => $by_type ) {
			foreach ( $by_type as $price_type => $payload ) {
				$resolutions[ $product_id ][ $price_type ] = ResolvedPrice::from_cache_payload( $payload );
			}
		}

		return $resolutions;
	}

	/**
	 * Store one resolution in the resolution cache.
	 *
	 * @param int           $product_id   Product ID.
	 * @param string        $price_type   Price type.
	 * @param string        $country_code Customer ISO country code.
	 * @param ResolvedPrice $resolved     Resolution to store.
	 * @return void
	 */
	private function cache_resolution( int $product_id, string $price_type, string $country_code, ResolvedPrice $resolved ): void {
		$this->price_cache->set( $product_id, $price_type, $country_code, $resolved->to_cache_payload() );
	}

	/**
	 * Apply the public result filters and announce a hidden product.
	 *
	 * @param mixed      $value        Resolved price value.
	 * @param WC_Product $product      Product instance.
	 * @param string     $price_type   Price type.
	 * @param string     $country_code Customer ISO country code.
	 * @param bool       $announce     Whether to fire the "price resolved" action.
	 * @return mixed
	 */
	private function deliver( mixed $value, WC_Product $product, string $price_type, string $country_code, bool $announce ): mixed {
		if ( '' === (string) $value ) {
			$this->notify_hidden_product( $product->get_id() );
		}

		if ( $announce ) {
			/**
			 * Fires after a localized price has been resolved and cached.
			 *
			 * @param mixed      $value        The resolved price.
			 * @param WC_Product $product      The WooCommerce product.
			 * @param string     $price_type   Price type.
			 * @param string     $country_code Customer ISO country code.
			 */
			do_action( Core::build_hook( HookEnum::PricingPriceResolved->value ), $value, $product, $price_type, $country_code );
		}

		/**
		 * Filters the localized price returned to WooCommerce.
		 *
		 * @param mixed      $value   The resolved price, or an empty string when hidden.
		 * @param WC_Product $product The WooCommerce product.
		 */
		return apply_filters( Core::build_hook( HookEnum::PricingResolvedPrice->value ), $value, $product );
	}

	/**
	 * Read the store base price straight from the CRUD 'edit' context.
	 *
	 * @param WC_Product $product    Product instance.
	 * @param string     $price_type Price type.
	 * @return string Base price as WooCommerce stores it, or an empty string when there is nothing to localize.
	 */
	private function read_base_price( WC_Product $product, string $price_type ): string {
		return (string) match ( $price_type ) {
			self::PRICE_TYPE_REGULAR => $product->get_regular_price( 'edit' ),
			self::PRICE_TYPE_SALE    => $product->get_sale_price( 'edit' ),
			default                  => $product->get_price( 'edit' ),
		};
	}

	/**
	 * Normalize a stored base price into decimal text.
	 *
	 * @param string $raw_price Base price as WooCommerce stores it.
	 * @return string|null Base price as decimal text, or null when it cannot be localized.
	 */
	private function normalize_base_price( string $raw_price ): ?string {
		return MoneyAmount::from( $raw_price )?->value();
	}

	/**
	 * Walk the tiers and produce the final result.
	 *
	 * @param WC_Product $product      Product instance.
	 * @param string     $base_price   Store base price, as decimal text.
	 * @param string     $price_type   Price type.
	 * @param string     $country_code Customer ISO country code.
	 * @return ResolvedPrice
	 */
	private function run_chain( WC_Product $product, string $base_price, string $price_type, string $country_code ): ResolvedPrice {
		if ( '' === $country_code ) {
			$resolved = $this->resolve_fallback( $base_price, $country_code );

			$filtered = apply_filters(
				Core::build_hook( HookEnum::PricingFallbackPrice->value ),
				$resolved->price,
				$base_price,
				$price_type,
				$country_code
			);

			return $this->finalize( $this->with_filtered_price( $resolved, $filtered ), $price_type );
		}

		$rules = $this->collect_rules( $product, $country_code );

		$base_has_sale = $product->is_on_sale( 'edit' );

		$resolved = $this->resolve_mode1( $rules, $base_price, $price_type, $country_code, $base_has_sale );

		if ( null !== $resolved ) {
			/**
			 * Filters the Mode 1 price produced by the product or category tier.
			 *
			 * @param float|string|null $price        Resolved price, or an empty string when blocked.
			 * @param int               $product_id   Product ID.
			 * @param string            $price_type   Price type.
			 * @param string            $country_code Customer ISO country code.
			 */
			$filtered = apply_filters(
				Core::build_hook( HookEnum::PricingMode1Price->value ),
				$resolved->price,
				$product->get_id(),
				$price_type,
				$country_code
			);

			return $this->finalize( $this->with_filtered_price( $resolved, $filtered ), $price_type );
		}

		$resolved = $this->resolve_mode2( $base_price, $country_code );

		if ( null !== $resolved ) {
			/**
			 * Filters the Mode 2 price produced by the country or group mapping.
			 *
			 * @param float|string|null $price        Converted price.
			 * @param string            $base_price   Store base price, as decimal text.
			 * @param string            $price_type   Price type.
			 * @param string            $country_code Customer ISO country code.
			 */
			$filtered = apply_filters(
				Core::build_hook( HookEnum::PricingMode2Price->value ),
				$resolved->price,
				$base_price,
				$price_type,
				$country_code
			);

			return $this->finalize( $this->with_filtered_price( $resolved, $filtered ), $price_type );
		}

		$resolved = $this->resolve_fallback( $base_price, $country_code );

		/**
		 * Filters the global fallback price used when no rule matched.
		 *
		 * @param float|string|null $price        Fallback price, or an empty string in hide mode.
		 * @param string            $base_price   Store base price, as decimal text.
		 * @param string            $price_type   Price type.
		 * @param string            $country_code Customer ISO country code.
		 */
		$filtered = apply_filters(
			Core::build_hook( HookEnum::PricingFallbackPrice->value ),
			$resolved->price,
			$base_price,
			$price_type,
			$country_code
		);

		return $this->finalize( $this->with_filtered_price( $resolved, $filtered ), $price_type );
	}

	/**
	 * Apply the final filter to a resolved result.
	 *
	 * @param ResolvedPrice $resolved   Resolved result.
	 * @param string        $price_type Price type.
	 * @return ResolvedPrice
	 */
	private function finalize( ResolvedPrice $resolved, string $price_type ): ResolvedPrice {
		/**
		 * Filters the final price after the whole hierarchy has run.
		 *
		 * @param float|string|null $price        Resolved price.
		 * @param string            $price_type   Price type.
		 * @param string            $country_code Customer ISO country code.
		 */
		$filtered = apply_filters(
			Core::build_hook( HookEnum::PricingFinalPrice->value ),
			$resolved->price,
			$price_type,
			$resolved->country_code
		);

		return $this->with_filtered_price( $resolved, $filtered );
	}

	/**
	 * Collect the product and category rules that govern this country.
	 *
	 * @param WC_Product $product      Product instance.
	 * @param string     $country_code Customer ISO country code.
	 * @return array{product: stdClass|null, category: stdClass|null}
	 */
	private function collect_rules( WC_Product $product, string $country_code ): array {
		$product_rule  = $this->fetch_product_rule( $product->get_id(), $country_code );
		$category_rule = null === $product_rule ? $this->fetch_category_rule( $product, $country_code ) : null;

		return array(
			'product'  => $product_rule,
			'category' => $category_rule,
		);
	}

	/**
	 * Tier 1 and 2 — explicit product and category rules.
	 *
	 * @param array{product: stdClass|null, category: stdClass|null} $rules        Collected rules.
	 * @param string                                                 $base_price   Store base price, as decimal text.
	 * @param string                                                 $price_type   Price type.
	 * @param string                                                 $country_code Customer ISO country code.
	 * @param bool                                                   $base_has_sale Whether the base product is on sale right now.
	 *
	 * @return ResolvedPrice|null Result, or null when the governing tier priced nothing.
	 */
	private function resolve_mode1( array $rules, string $base_price, string $price_type, string $country_code, bool $base_has_sale ): ?ResolvedPrice {
		$product_rule = $rules['product'];

		if ( null !== $product_rule ) {
			return $this->resolve_product_rule( $product_rule, $base_price, $price_type, $country_code, $base_has_sale );
		}

		$category_rule = $rules['category'];

		if ( null !== $category_rule ) {
			return $this->resolve_category_rule( $category_rule, $base_price, $country_code );
		}

		return null;
	}

	/**
	 * Resolve one product level rule.
	 *
	 * @param stdClass $rule          Rule row.
	 * @param string   $base_price    Store base price, as decimal text.
	 * @param string   $price_type    Price type.
	 * @param string   $country_code  Customer ISO country code.
	 * @param bool     $base_has_sale Whether the base product is on sale right now.
	 * @return ResolvedPrice|null Result, or null when the row carries no price at all.
	 */
	private function resolve_product_rule( stdClass $rule, string $base_price, string $price_type, string $country_code, bool $base_has_sale ): ?ResolvedPrice {
		if ( $this->is_flag_set( $rule, 'is_blocked' ) ) {
			return $this->blocked( $base_price, $country_code, 'product_blocked' );
		}

		$currency = $this->rule_currency( (string) ( $rule->local_currency ?? '' ), $country_code );
		$rate     = ExchangeRate::from( $rule->exchange_rate ?? null );

		if ( null !== $rate ) {
			return new ResolvedPrice(
				price: $this->multiply_by_rate( $base_price, $rate->value(), $currency, $country_code ),
				original_price: $base_price,
				currency: $currency,
				country_code: $country_code,
				resolution_mode: 'product_rate',
				is_blocked: false,
				rate: $rate->value()
			);
		}

		$auto_convert = $this->is_flag_set( $rule, 'auto_convert' );
		$manual       = $auto_convert ? null : $this->pick_manual_price( $rule, $price_type, $base_has_sale );

		if ( null !== $manual ) {
			return new ResolvedPrice(
				price: $manual,
				original_price: $base_price,
				currency: $currency,
				country_code: $country_code,
				resolution_mode: 'product_mode1',
				is_blocked: false
			);
		}

		if ( ! $auto_convert && '' === trim( (string) ( $rule->local_currency ?? '' ) ) ) {
			return null;
		}

		$converted = $this->convert_to_currency( $base_price, $currency, $country_code );

		if ( null !== $converted ) {
			return new ResolvedPrice(
				price: $converted['amount'],
				original_price: $base_price,
				currency: $currency,
				country_code: $country_code,
				resolution_mode: 'product_auto_convert',
				is_blocked: false,
				rate: $converted['rate']
			);
		}

		return null;
	}

	/**
	 * Resolve one category level rule.
	 *
	 * @param stdClass $rule         Rule row.
	 * @param string   $base_price   Store base price, as decimal text.
	 * @param string   $country_code Customer ISO country code.
	 * @return ResolvedPrice|null Result, or null when the row prices nothing.
	 */
	private function resolve_category_rule( stdClass $rule, string $base_price, string $country_code ): ?ResolvedPrice {
		if ( $this->is_flag_set( $rule, 'is_blocked' ) ) {
			return $this->blocked( $base_price, $country_code, 'category_blocked' );
		}

		$currency = $this->rule_currency( (string) ( $rule->currency_code ?? '' ), $country_code );
		$rate     = ExchangeRate::from( $rule->exchange_rate ?? null );

		if ( null !== $rate ) {
			return new ResolvedPrice(
				price: $this->multiply_by_rate( $base_price, $rate->value(), $currency, $country_code ),
				original_price: $base_price,
				currency: $currency,
				country_code: $country_code,
				resolution_mode: 'category_rate',
				is_blocked: false,
				rate: $rate->value()
			);
		}

		if ( empty( $rule->currency_code ) ) {
			return null;
		}

		$converted = $this->convert_to_currency( $base_price, $currency, $country_code );

		if ( null === $converted ) {
			return null;
		}

		return new ResolvedPrice(
			price: $converted['amount'],
			original_price: $base_price,
			currency: $currency,
			country_code: $country_code,
			resolution_mode: 'category_mode1',
			is_blocked: false,
			rate: $converted['rate']
		);
	}

	/**
	 * Tier 3 and 4 — the country's active group when it prices, otherwise the country mapping.
	 *
	 * @param string $base_price   Store base price, as decimal text.
	 * @param string $country_code Customer ISO country code.
	 * @return ResolvedPrice|null Result, or null when neither prices the country.
	 */
	private function resolve_mode2( string $base_price, string $country_code ): ?ResolvedPrice {
		$mapping  = $this->country_currency->get_mapping( $country_code );
		$currency = null !== $mapping && ! empty( $mapping->currency_code )
			? strtoupper( (string) $mapping->currency_code )
			: '';

		$grouped = $this->resolve_group( $base_price, $currency, $country_code );

		if ( null !== $grouped ) {
			return $grouped;
		}

		if ( '' === $currency ) {
			return null;
		}

		$converted = $this->convert_to_currency( $base_price, $currency, $country_code );

		if ( null === $converted ) {
			return null;
		}

		return new ResolvedPrice(
			price: $converted['amount'],
			original_price: $base_price,
			currency: $currency,
			country_code: $country_code,
			resolution_mode: 'auto_mode2',
			is_blocked: false,
			rate: $converted['rate']
		);
	}

	/**
	 * Price through the country's active group, when the group carries its own rate or currency.
	 *
	 * @param string $base_price       Store base price, as decimal text.
	 * @param string $country_currency Currency of the country mapping, possibly empty.
	 * @param string $country_code     Customer ISO country code.
	 * @return ResolvedPrice|null Result, or null when the group does not price the country.
	 */
	private function resolve_group( string $base_price, string $country_currency, string $country_code ): ?ResolvedPrice {
		$group = $this->country_currency->get_group_for_country( $country_code );

		if ( null === $group ) {
			return null;
		}

		$group_currency = ! empty( $group->currency_code ) ? strtoupper( (string) $group->currency_code ) : '';
		$group_rate     = ExchangeRate::from( $group->exchange_rate ?? null );

		if ( null !== $group_rate ) {
			$rate_currency = '' !== $group_currency ? $group_currency : ( '' !== $country_currency ? $country_currency : Core::get_global_currency() );

			return new ResolvedPrice(
				price: $this->multiply_by_rate( $base_price, $group_rate->value(), $rate_currency, $country_code ),
				original_price: $base_price,
				currency: $rate_currency,
				country_code: $country_code,
				resolution_mode: 'group_rate',
				is_blocked: false,
				rate: $group_rate->value()
			);
		}

		if ( '' === $group_currency ) {
			return null;
		}

		$converted = $this->convert_to_currency( $base_price, $group_currency, $country_code );

		if ( null === $converted ) {
			return null;
		}

		return new ResolvedPrice(
			price: $converted['amount'],
			original_price: $base_price,
			currency: $group_currency,
			country_code: $country_code,
			resolution_mode: 'group_mode2',
			is_blocked: false,
			rate: $converted['rate']
		);
	}

	/**
	 * Tier 5 — the global fallback mode.
	 *
	 * @param string $base_price   Store base price, as decimal text.
	 * @param string $country_code Customer ISO country code.
	 * @return ResolvedPrice
	 */
	private function resolve_fallback( string $base_price, string $country_code ): ResolvedPrice {
		$mode = FallbackModeEnum::from_option( Core::get_option( OptionsEnum::FallbackMode->value, FallbackModeEnum::Direct->value ) );

		if ( FallbackModeEnum::Hide === $mode ) {
			return new ResolvedPrice(
				price: '',
				original_price: $base_price,
				currency: Core::get_global_currency(),
				country_code: $country_code,
				resolution_mode: 'fallback_hide',
				is_blocked: false
			);
		}

		if ( FallbackModeEnum::Convert === $mode ) {
			$global    = Core::get_global_currency();
			$converted = $this->convert_to_currency( $base_price, $global, $country_code );

			if ( null !== $converted ) {
				return new ResolvedPrice(
					price: $converted['amount'],
					original_price: $base_price,
					currency: $global,
					country_code: $country_code,
					resolution_mode: 'fallback_convert',
					is_blocked: false,
					rate: $converted['rate']
				);
			}

			$this->logger->debug(
				sprintf( 'Fallback conversion to %s unavailable; showing the store base price.', $global ),
				array( 'country_code' => $country_code ),
				'PricingEngine'
			);
		}

		return new ResolvedPrice(
			price: $base_price,
			original_price: $base_price,
			currency: Core::get_shop_base_currency(),
			country_code: $country_code,
			resolution_mode: 'fallback_direct',
			is_blocked: false,
			rate: '1'
		);
	}

	/**
	 * Build the result of a blacklisted tier.
	 *
	 * @param string $base_price   Store base price, as decimal text.
	 * @param string $country_code Customer ISO country code.
	 * @param string $mode         Resolution mode label.
	 * @return ResolvedPrice
	 */
	private function blocked( string $base_price, string $country_code, string $mode ): ResolvedPrice {
		return new ResolvedPrice(
			price: '',
			original_price: $base_price,
			currency: Core::get_global_currency(),
			country_code: $country_code,
			resolution_mode: $mode,
			is_blocked: true
		);
	}

	/**
	 * Fetch the active product rule, translating repository errors into "no rule".
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $country_code Customer ISO country code.
	 * @return stdClass|null
	 */
	private function fetch_product_rule( int $product_id, string $country_code ): ?stdClass {
		$row = $this->rules->get_product_rule( $product_id, $country_code );

		if ( is_wp_error( $row ) ) {
			$this->logger->warning(
				sprintf( 'Product pricing rule lookup failed for product %d.', $product_id ),
				array( 'error' => $row->get_error_message() ),
				'PricingEngine'
			);

			return null;
		}

		return $row instanceof stdClass ? $row : null;
	}

	/**
	 * Fetch the nearest category rule that applies to a product.
	 *
	 * @param WC_Product $product      Product instance.
	 * @param string     $country_code Customer ISO country code.
	 * @return stdClass|null
	 */
	private function fetch_category_rule( WC_Product $product, string $country_code ): ?stdClass {
		$term_ids = $this->get_ordered_term_ids( $product );

		if ( empty( $term_ids ) ) {
			return null;
		}

		$rules = $this->rules->get_category_rules( $term_ids, $country_code );

		if ( is_wp_error( $rules ) ) {
			$this->logger->warning(
				sprintf( 'Category pricing rule lookup failed for product %d.', $product->get_id() ),
				array( 'error' => $rules->get_error_message() ),
				'PricingEngine'
			);

			return null;
		}

		foreach ( $term_ids as $term_id ) {
			$rule = $rules[ $term_id ] ?? null;

			if ( $rule instanceof stdClass ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Get the product's category terms and their ancestors, nearest term first.
	 *
	 * @param WC_Product $product Product instance.
	 * @return array<int, int> Ordered, de-duplicated term IDs.
	 */
	private function get_ordered_term_ids( WC_Product $product ): array {
		$terms = $this->normalize_ids( (array) $product->get_category_ids() );

		if ( empty( $terms ) ) {
			return $terms;
		}

		$chains    = array();
		$max_depth = 0;

		foreach ( $terms as $term_id ) {
			$chain     = $this->normalize_ids( (array) get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );
			$chains[]  = $chain;
			$max_depth = max( $max_depth, count( $chain ) );
		}

		$ordered = $terms;

		for ( $depth = 0; $depth < $max_depth; $depth++ ) {
			foreach ( $chains as $chain ) {
				if ( isset( $chain[ $depth ] ) ) {
					$ordered[] = $chain[ $depth ];
				}
			}
		}

		return array_values( array_unique( $ordered ) );
	}

	/**
	 * Cast a raw list to a list of unique positive integers.
	 *
	 * @param array<mixed> $ids Raw IDs.
	 * @return array<int, int>
	 */
	private function normalize_ids( array $ids ): array {
		$normalized = array_map( 'intval', array_filter( $ids, 'is_scalar' ) );

		return array_values( array_unique( array_filter( $normalized, static fn ( int $id ): bool => $id > 0 ) ) );
	}

	/**
	 * Pick the manually entered amount that matches the requested price type.
	 *
	 * @param stdClass $rule          Rule row.
	 * @param string   $price_type    Price type.
	 * @param bool     $base_has_sale Whether the base product runs a sale.
	 * @return string|null Amount as decimal text, or null when the column is blank.
	 */
	private function pick_manual_price( stdClass $rule, string $price_type, bool $base_has_sale ): ?string {
		$sale    = $this->column_amount( $rule, 'local_sale_price' );
		$regular = $this->column_amount( $rule, 'local_price' );

		return match ( $price_type ) {
			self::PRICE_TYPE_SALE    => $sale,
			self::PRICE_TYPE_REGULAR => $regular,
			default                  => $base_has_sale ? ( $sale ?? $regular ) : $regular,
		};
	}

	/**
	 * Read one money column of a rule row.
	 *
	 * @param stdClass $rule   Rule row.
	 * @param string   $column Column name.
	 * @return string|null Amount as decimal text, or null when blank or unusable.
	 */
	private function column_amount( stdClass $rule, string $column ): ?string {
		$value = $rule->{$column} ?? null;

		if ( null === $value || '' === $value ) {
			return null;
		}

		return MoneyAmount::from( $value )?->value();
	}

	/**
	 * Read a tinyint flag from a rule row.
	 *
	 * @param stdClass $rule   Rule row.
	 * @param string   $column Column name.
	 * @return bool
	 */
	private function is_flag_set( stdClass $rule, string $column ): bool {
		return true === Utils::parse_bool( $rule->{$column} ?? false );
	}

	/**
	 * Choose the currency a rule prices in.
	 *
	 * @param string $explicit     Currency stored on the rule, possibly empty.
	 * @param string $country_code Customer ISO country code.
	 * @return string Upper-case currency code.
	 */
	private function rule_currency( string $explicit, string $country_code ): string {
		$explicit = strtoupper( trim( $explicit ) );

		if ( '' !== $explicit ) {
			return $explicit;
		}

		return $this->country_currency->get_currency_for_country( $country_code ) ?? Core::get_global_currency();
	}

	/**
	 * Convert the store base price into a target currency.
	 *
	 * @param string $amount       Amount in the store base currency, as decimal text.
	 * @param string $currency     Target currency code.
	 * @param string $country_code Customer ISO country code.
	 * @return array{amount: string, rate: string}|null Converted amount and the rate used, both as decimal text, or null when no rate is available.
	 */
	public function convert_to_currency( string $amount, string $currency, string $country_code ): ?array {
		$from = Core::get_shop_base_currency();
		$to   = strtoupper( trim( $currency ) );

		if ( '' === $to ) {
			return null;
		}

		if ( $from === $to ) {
			return array(
				'amount' => DecimalText::plain( $amount ) ?? $amount,
				'rate'   => '1',
			);
		}

		$rate = $this->get_exchange_rate( $from, $to );

		if ( null === $rate ) {
			$this->logger->debug(
				sprintf( 'No exchange rate available for %s to %s.', $from, $to ),
				array(),
				'PricingEngine'
			);

			return null;
		}

		return array(
			'amount' => $this->multiply_by_rate( $amount, $rate, $to, $country_code ),
			'rate'   => $rate,
		);
	}

	/**
	 * Multiply an amount by a rate using drift-safe math, then round it once.
	 *
	 * @param string $amount       Base amount, as decimal text.
	 * @param string $rate         Exchange rate, as decimal text.
	 * @param string $currency     Currency the amount is converted into.
	 * @param string $country_code Customer ISO country code.
	 * @return string Converted and rounded amount, as decimal text.
	 */
	private function multiply_by_rate( string $amount, string $rate, string $currency, string $country_code ): string {
		$decimals = $this->get_decimals( $currency, $country_code );

		return $this->apply_rounding( DecimalMath::multiply( $amount, $rate, $decimals + 12 ), $currency, $country_code );
	}

	/**
	 * Get an exchange rate, exposing it to third parties.
	 *
	 * @param string $from Source currency code.
	 * @param string $to   Target currency code.
	 * @return string|null Rate as decimal text, or null when unavailable.
	 */
	private function get_exchange_rate( string $from, string $to ): ?string {
		$rate = $this->exchange_rates->get_exchange_rate( $from, $to );

		/**
		 * Filters the exchange rate used for a price conversion.
		 *
		 * @param float|null $rate The exchange rate, null when unavailable.
		 * @param string     $from Source currency code.
		 * @param string     $to   Target currency code.
		 */
		$offered  = null === $rate ? null : (float) $rate;
		$filtered = apply_filters(
			Core::build_hook( HookEnum::PricingExchangeRate->value ),
			$offered,
			$from,
			$to
		);

		if ( null !== $rate && $filtered === $offered ) {
			return ExchangeRate::from( $rate )?->value();
		}

		return ExchangeRate::from( $filtered )?->value();
	}

	/**
	 * Apply the configured rounding mode to a converted price.
	 *
	 * @param string $price        Raw converted price, as decimal text.
	 * @param string $currency     Currency the price is in.
	 * @param string $country_code Customer ISO country code.
	 * @return string Rounded price, as decimal text.
	 */
	private function apply_rounding( string $price, string $currency, string $country_code ): string {
		$mode     = RoundingModeEnum::from_option( Core::get_option( OptionsEnum::LocalPriceRounding->value, RoundingModeEnum::None->value ) );
		$decimals = $this->get_decimals( $currency, $country_code );

		$rounded = match ( $mode ) {
			RoundingModeEnum::Up   => $this->step_to_decimals( $price, $decimals, true ),
			RoundingModeEnum::Down => $this->step_to_decimals( $price, $decimals, false ),
			default                => DecimalMath::round( $price, $decimals ),
		};

		/**
		 * Filters a rounded price, for strategies such as .99 endings.
		 *
		 * @param float  $rounded  Price after rounding.
		 * @param float  $price    Price before rounding.
		 * @param string $mode     Rounding mode value.
		 * @param int    $decimals Decimal count applied: the visitor currency context's own, or the store's when none is mapped.
		 */
		$offered  = (float) $rounded;
		$filtered = apply_filters(
			Core::build_hook( HookEnum::PricingRounding->value ),
			$offered,
			(float) $price,
			$mode->value,
			$decimals
		);

		if ( $filtered === $offered ) {
			return $rounded;
		}

		return DecimalText::plain( $filtered ) ?? $rounded;
	}

	/**
	 * Step a value up or down to a whole unit of the currency's last digit.
	 *
	 * @param string $price    Value as decimal text.
	 * @param int    $decimals Decimal places of the target currency.
	 * @param bool   $up       True to step up, false to step down.
	 * @return string Stepped value, as decimal text.
	 */
	private function step_to_decimals( string $price, int $decimals, bool $up ): string {
		$factor    = '1' . str_repeat( '0', max( 0, $decimals ) );
		$exact     = DecimalMath::multiply( $price, $factor, 12 );
		$truncated = DecimalMath::multiply( $price, $factor, 0 );

		$stepped = ( $up && $exact !== $truncated )
			? DecimalMath::add( $truncated, '1', 0 )
			: $truncated;

		return DecimalMath::divide( $stepped, $factor, $decimals );
	}

	/**
	 * Get the decimal count a price in a currency is rounded to.
	 *
	 * @param string $currency     Currency the price is in.
	 * @param string $country_code Customer ISO country code.
	 * @return int
	 */
	private function get_decimals( string $currency, string $country_code ): int {
		$currency = strtoupper( trim( $currency ) );
		$format   = $this->country_currency->get_format_for_country( $country_code );

		if ( null !== $format && '' !== $format['currency'] && ( '' === $currency || $format['currency'] === $currency ) ) {
			return max( 0, (int) $format['decimals'] );
		}

		if ( '' === $currency || Core::get_shop_base_currency() === $currency ) {
			return Core::get_shop_price_decimals();
		}

		return max( 0, CountryCurrencyMap::get_currency_decimals( $currency ) ?? Core::get_shop_price_decimals() );
	}

	/**
	 * Fold a filtered price back into the result that produced it.
	 *
	 * @param ResolvedPrice     $resolved Resolved result.
	 * @param float|string|null $filtered Price the filter answered with.
	 * @return ResolvedPrice
	 */
	private function with_filtered_price( ResolvedPrice $resolved, float|string|null $filtered ): ResolvedPrice {
		if ( $filtered === $resolved->price ) {
			return $resolved;
		}

		if ( is_string( $filtered ) && '' !== $filtered ) {
			return $resolved->with_price( Utils::parse_float( $filtered ) ?? $filtered );
		}

		return $resolved->with_price( $filtered );
	}

	/**
	 * Announce that a product has no purchasable price in this country.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private function notify_hidden_product( int $product_id ): void {
		/**
		 * Fires when a product must be hidden and made unpurchasable.
		 *
		 * @param int $product_id Product ID.
		 */
		do_action( Core::build_hook( HookEnum::PricingProductHidden->value ), $product_id );
	}

	/**
	 * Log a throwable caught at a WooCommerce boundary.
	 *
	 * @param string     $message Context message.
	 * @param WC_Product $product Product instance.
	 * @param Throwable  $error   Caught throwable.
	 * @return void
	 */
	private function log_failure( string $message, WC_Product $product, Throwable $error ): void {
		$this->logger->error(
			sprintf( '%s for product %d: %s', $message, $product->get_id(), $error->getMessage() ),
			array(
				'file' => $error->getFile(),
				'line' => $error->getLine(),
			),
			'PricingEngine'
		);
	}
}
