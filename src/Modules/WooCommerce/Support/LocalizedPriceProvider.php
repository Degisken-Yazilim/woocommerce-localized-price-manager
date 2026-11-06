<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Support;

use D6N\LPM\Core\Contracts\ICustomerCountryService;
use D6N\LPM\Core\Money\DecimalText;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\PricingEngine\Services\PriceResolutionService;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use D6N\LPM\Modules\PricingEngine\ValueObjects\ResolvedPrice;
use WC_Product;
use WeakMap;

defined( 'ABSPATH' ) || exit;

/**
 * Request-scoped, idempotent access to resolved prices for the WooCommerce layer.
 */
class LocalizedPriceProvider {

	/**
	 * Captured store base prices, keyed by "{product_id}|{price_type}".
	 *
	 * @var array<string, string|null>
	 */
	private array $base_prices = array();

	/**
	 * Memoised resolutions, keyed by "{product_id}|{price_type}|{country}".
	 *
	 * @var array<string, ResolvedPrice>
	 */
	private array $resolutions = array();

	/**
	 * Which price properties of which product objects are already converted.
	 *
	 * @var WeakMap<WC_Product, array<string, true>>
	 */
	private WeakMap $localized_objects;

	/**
	 * Price actually written onto each cart product object.
	 *
	 * @var WeakMap<WC_Product, ResolvedPrice>
	 */
	private WeakMap $charges;

	/**
	 * Memoised customer country for this request, null until first resolved.
	 *
	 * @var string|null
	 */
	private ?string $country = null;

	/**
	 * Constructor.
	 *
	 * @param PriceResolutionService  $resolution_service Price resolution service.
	 * @param ICustomerCountryService $country_service    Customer country service.
	 * @param ProductTypeSupport      $product_types      Product type policy and suppression scope.
	 */
	public function __construct(
		private readonly PriceResolutionService $resolution_service,
		private readonly ICustomerCountryService $country_service,
		private readonly ProductTypeSupport $product_types
	) {
		$this->localized_objects = new WeakMap();
		$this->charges           = new WeakMap();
	}

	/**
	 * Get the validated customer country of this request.
	 *
	 * @return string Upper-case ISO country code, or an empty string when unknown.
	 */
	public function get_country(): string {
		if ( null !== $this->country ) {
			return $this->country;
		}

		$this->country = CountryCodeValidator::sanitize( (string) $this->country_service->get_current_user_country() );

		return $this->country;
	}

	/**
	 * Get the store base price of a product, captured from the `'edit'` context.
	 *
	 * @param WC_Product $product    Product instance.
	 * @param string     $price_type Price type: 'price', 'regular' or 'sale'.
	 * @return string Base price as decimal text, '0' when the product carries no usable amount.
	 */
	public function get_base_price( WC_Product $product, string $price_type = PriceResolutionService::PRICE_TYPE_ACTIVE ): string {
		return $this->capture_base_price( $product, $price_type ) ?? '0';
	}

	/**
	 * Tell whether a product carries a usable base amount of one price type.
	 *
	 * @param WC_Product $product    Product instance.
	 * @param string     $price_type Price type: 'price', 'regular' or 'sale'.
	 * @return bool
	 */
	public function has_base_price( WC_Product $product, string $price_type ): bool {
		return null !== $this->capture_base_price( $product, $price_type );
	}

	/**
	 * Capture the store base price of a product from the `'edit'` context.
	 *
	 * @param WC_Product $product    Product instance.
	 * @param string     $price_type Price type: 'price', 'regular' or 'sale'.
	 * @return string|null Base price as decimal text, null when the product carries no usable amount.
	 */
	private function capture_base_price( WC_Product $product, string $price_type ): ?string {
		$product_id = $product->get_id();
		$key        = $product_id . '|' . $price_type;

		if ( $product_id > 0 && array_key_exists( $key, $this->base_prices ) ) {
			return $this->base_prices[ $key ];
		}

		$source = $product;
		if ( $product_id > 0 && function_exists( 'wc_get_product' ) ) {
			$clean = wc_get_product( $product_id );
			if ( $clean instanceof WC_Product ) {
				$source = $clean;
			}
		}

		$raw = match ( $price_type ) {
			PriceResolutionService::PRICE_TYPE_REGULAR => $source->get_regular_price( 'edit' ),
			PriceResolutionService::PRICE_TYPE_SALE    => $source->get_sale_price( 'edit' ),
			default                                    => $source->get_price( 'edit' ),
		};

		$base = DecimalText::plain( $raw );

		if ( $product_id > 0 ) {
			$this->base_prices[ $key ] = $base;
		}

		return $base;
	}

	/**
	 * Resolve the localized price of a product for the current customer country.
	 *
	 * @param WC_Product $product    Product instance.
	 * @param string     $price_type Price type: 'price', 'regular' or 'sale'.
	 * @return ResolvedPrice|null Resolution result, or null when localization does not apply to this product or the sale price is empty.
	 */
	public function resolve( WC_Product $product, string $price_type = PriceResolutionService::PRICE_TYPE_ACTIVE ): ?ResolvedPrice {
		if ( ! Core::is_active() ) {
			return null;
		}

		if ( ! $this->product_types->applies_to( $product ) ) {
			return null;
		}

		if ( PriceResolutionService::PRICE_TYPE_SALE === $price_type && null === $this->capture_base_price( $product, $price_type ) ) {
			return null;
		}

		$country = $this->get_country();

		$key = $product->get_id() . '|' . $price_type . '|' . $country;

		if ( isset( $this->resolutions[ $key ] ) ) {
			return $this->resolutions[ $key ];
		}

		$resolved = $this->resolution_service->resolve_cached_price(
			$product,
			$this->get_base_price( $product, $price_type ),
			$price_type,
			$country
		);

		$this->resolutions[ $key ] = $resolved;

		return $resolved;
	}

	/**
	 * Record that one price property of a product object now holds a converted amount.
	 *
	 * @param WC_Product $product    Product instance.
	 * @param string     $price_type Price type whose property was written: 'price', 'regular' or 'sale'.
	 * @return void
	 */
	public function mark_localized( WC_Product $product, string $price_type = PriceResolutionService::PRICE_TYPE_ACTIVE ): void {
		$marks = $this->localized_objects[ $product ] ?? array();

		$marks[ $price_type ] = true;

		$this->localized_objects[ $product ] = $marks;
	}

	/**
	 * Check whether one price property of a product object is already converted.
	 *
	 * @param WC_Product $product    Product instance.
	 * @param string     $price_type Price type to ask about: 'price', 'regular' or 'sale'.
	 * @return bool
	 */
	public function is_localized( WC_Product $product, string $price_type = PriceResolutionService::PRICE_TYPE_ACTIVE ): bool {
		$marks = $this->localized_objects[ $product ] ?? array();

		return isset( $marks[ $price_type ] );
	}

	/**
	 * Convert an amount in base currency into a target currency using the resolution service.
	 *
	 * @param string $amount          Base amount as decimal text.
	 * @param string $target_currency Target currency code.
	 * @return string|null Converted amount as decimal text, or null on failure.
	 */
	public function convert_amount( string $amount, string $target_currency ): ?string {
		return $this->convert_with_rate( $amount, $target_currency )['amount'] ?? null;
	}

	/**
	 * Convert an amount in base currency into a target currency, keeping the rate applied.
	 *
	 * @param string $amount          Base amount as decimal text.
	 * @param string $target_currency Target currency code.
	 * @return array{amount: string, rate: string}|null Converted amount and rate, or null on failure.
	 */
	public function convert_with_rate( string $amount, string $target_currency ): ?array {
		return $this->resolution_service->convert_to_currency( $amount, $target_currency, $this->get_country() );
	}

	/**
	 * Record the price that was written onto a cart product object.
	 *
	 * @param WC_Product    $product Cart product object.
	 * @param ResolvedPrice $charged Price written onto it.
	 * @return void
	 */
	public function record_charge( WC_Product $product, ResolvedPrice $charged ): void {
		$this->charges[ $product ] = $charged;
	}

	/**
	 * Get the price that was written onto a cart product object.
	 *
	 * @param WC_Product $product Cart product object.
	 * @return ResolvedPrice|null Price written onto it, or null when none was written.
	 */
	public function get_charge( WC_Product $product ): ?ResolvedPrice {
		return $this->charges[ $product ] ?? null;
	}
}
