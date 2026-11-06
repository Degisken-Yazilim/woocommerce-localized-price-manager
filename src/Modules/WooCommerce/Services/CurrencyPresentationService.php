<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\ICustomerCountryService;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\FallbackModeEnum;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\RequestContext;
use D6N\LPM\Modules\PricingEngine\CountryCurrency\CountryCurrencyService;
use D6N\LPM\Modules\PricingEngine\Services\PriceResolutionService;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use D6N\LPM\Modules\WooCommerce\Support\LocalizedPriceProvider;
use D6N\LPM\Modules\WooCommerce\Support\ProductTypeSupport;
use Throwable;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Presentation of the visitor's currency: code, symbol and number format.
 */
class CurrencyPresentationService implements IHookable {

	/**
	 * Memoised presentation format of the current request, null until resolved.
	 *
	 * @var array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int}|null
	 */
	private ?array $format = null;

	/**
	 * Whether the format lookup of this request has already been attempted.
	 *
	 * @var bool
	 */
	private bool $format_resolved = false;

	/**
	 * Request part of the localization verdict, null until the request is parsed.
	 *
	 * @var bool|null
	 */
	private ?bool $request_applies = null;

	/**
	 * Re-entrancy guard for the currency symbol filter.
	 *
	 * @var bool
	 */
	private bool $resolving_symbol = false;

	/**
	 * Constructor.
	 *
	 * @param CountryCurrencyService      $country_currency Country to currency read model.
	 * @param ICustomerCountryService     $country_service  Customer country service.
	 * @param ProductTypeSupport          $product_types    Product type policy and suppression scope.
	 * @param ILogger                     $logger           Logger service.
	 * @param LocalizedPriceProvider|null $price_provider   Resolved price provider.
	 */
	public function __construct(
		private readonly CountryCurrencyService $country_currency,
		private readonly ICustomerCountryService $country_service,
		private readonly ProductTypeSupport $product_types,
		private readonly ILogger $logger,
		private readonly ?LocalizedPriceProvider $price_provider = null
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

		add_filter( 'woocommerce_currency', array( $this, 'get_currency_code' ), $priority, 1 );
		add_filter( 'woocommerce_currency_symbol', array( $this, 'get_currency_symbol' ), $priority, 2 );
		add_filter( 'wc_price_args', array( $this, 'apply_number_format' ), $priority, 1 );

		add_filter( 'wc_get_price_decimals', array( $this, 'get_price_decimals' ), $priority, 1 );
		add_filter( 'wc_get_price_decimal_separator', array( $this, 'get_price_decimal_separator' ), $priority, 1 );
		add_filter( 'wc_get_price_thousand_separator', array( $this, 'get_price_thousand_separator' ), $priority, 1 );

		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), $priority, 2 );

		add_action( 'woocommerce_load_cart_from_session', array( $this, 'forget_unresolved_format' ), $priority, 0 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'on_cart_currency_changed' ), WooHookPriorityEnum::Early->value, 0 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'on_cart_currency_changed' ), WooHookPriorityEnum::Late->value, 0 );
		add_action( 'wp', array( $this, 'on_wp_query_ready' ), $priority, 0 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'on_cart_currency_changed' ), WooHookPriorityEnum::Late->value, 0 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'on_cart_currency_changed' ), WooHookPriorityEnum::Late->value, 0 );
	}

	/**
	 * Release a "no country" memo once the cart session has loaded.
	 *
	 * @return void
	 */
	public function forget_unresolved_format(): void {
		if ( null === $this->format ) {
			$this->format_resolved = false;
		}
	}

	/**
	 * Reset presentation format when the WordPress main query is ready.
	 *
	 * @return void
	 */
	public function on_wp_query_ready(): void {
		$this->format          = null;
		$this->format_resolved = false;
	}

	/**
	 * Reset presentation format when cart currency changes.
	 *
	 * @return void
	 */
	public function on_cart_currency_changed(): void {
		$this->format          = null;
		$this->format_resolved = false;
	}

	/**
	 * Filter the WooCommerce active currency code.
	 *
	 * @param string $default_currency Currency WooCommerce would otherwise use.
	 * @return string
	 */
	public function get_currency_code( string $default_currency ): string {
		$code = $default_currency;

		if ( $this->applies() ) {
			$format = $this->get_format();
			$code   = null !== $format && '' !== $format['currency'] ? $format['currency'] : $this->unresolved_country_currency( $default_currency );
		}

		/**
		 * Filters the currency code the storefront presents to this visitor.
		 *
		 * @param string $code Resolved currency code.
		 */
		return (string) apply_filters( Core::build_hook( HookEnum::PricingCurrencyCode->value ), $code );
	}

	/**
	 * Decide the currency label for a visitor whose country cannot be resolved.
	 *
	 * @param string $default_currency Currency WooCommerce would otherwise use.
	 * @return string
	 */
	private function unresolved_country_currency( string $default_currency ): string {
		$mode = FallbackModeEnum::from_option( Core::get_option( OptionsEnum::FallbackMode->value, FallbackModeEnum::Direct->value ) );

		return FallbackModeEnum::Convert === $mode ? Core::get_global_currency() : $default_currency;
	}

	/**
	 * Filter the WooCommerce active currency symbol.
	 *
	 * @param string $default_symbol Symbol WooCommerce would otherwise use.
	 * @param string $currency       Currency code the symbol was requested for.
	 * @return string
	 */
	public function get_currency_symbol( string $default_symbol, string $currency = '' ): string {
		$symbol = $default_symbol;

		if ( ! $this->resolving_symbol && $this->applies() ) {
			$this->resolving_symbol = true;

			try {
				$format = $this->get_format();

				if ( null !== $format && '' !== $format['symbol'] ) {
					$requested = strtoupper( trim( $currency ) );

					if ( '' === $requested || $requested === $format['currency'] ) {
						$symbol = $format['symbol'];
					}
				}
			} finally {
				$this->resolving_symbol = false;
			}
		}

		/**
		 * Filters the currency symbol the storefront presents to this visitor.
		 *
		 * @param string $symbol Resolved currency symbol.
		 */
		return (string) apply_filters( Core::build_hook( HookEnum::PricingCurrencySymbol->value ), $symbol );
	}

	/**
	 * Apply the visitor currency's separators and decimal count to `wc_price()`.
	 *
	 * @param array<string, mixed> $args Arguments `wc_price()` is about to use.
	 * @return array<string, mixed>
	 */
	public function apply_number_format( array $args ): array {
		$format = $this->get_localized_format();

		if ( null === $format ) {
			return $args;
		}

		$requested = strtoupper( trim( (string) ( $args['currency'] ?? '' ) ) );

		if ( '' !== $requested && $requested !== $format['currency'] ) {
			return $args;
		}

		$args['decimal_separator']  = $format['decimal_separator'];
		$args['thousand_separator'] = $format['thousand_separator'];
		$args['decimals']           = max( 0, $format['decimals'] );

		/**
		 * Filters the number format applied to a localized currency amount.
		 *
		 * @param array<string, mixed> $args     Modified `wc_price()` arguments.
		 * @param string               $currency Currency code being formatted.
		 */
		return (array) apply_filters(
			Core::build_hook( HookEnum::PricingCurrencyFormatArgs->value ),
			$args,
			$format['currency']
		);
	}

	/**
	 * Filter the number of decimals WooCommerce prices, rounds and stores money in.
	 *
	 * @param mixed $default_decimals Decimal count WooCommerce would otherwise use.
	 * @return mixed The visitor currency's decimal count, or the store's own value.
	 */
	public function get_price_decimals( mixed $default_decimals ): mixed {
		$format = $this->get_localized_format();

		return null === $format ? $default_decimals : max( 0, $format['decimals'] );
	}

	/**
	 * Filter the decimal separator WooCommerce formats prices with.
	 *
	 * @param mixed $default_separator Separator WooCommerce would otherwise use.
	 * @return mixed The visitor currency's decimal separator, or the store's own value.
	 */
	public function get_price_decimal_separator( mixed $default_separator ): mixed {
		$format = $this->get_localized_format();

		return null === $format ? $default_separator : $format['decimal_separator'];
	}

	/**
	 * Filter the thousand separator WooCommerce formats prices with.
	 *
	 * @param mixed $default_separator Separator WooCommerce would otherwise use.
	 * @return mixed The visitor currency's thousand separator, or the store's own value.
	 */
	public function get_price_thousand_separator( mixed $default_separator ): mixed {
		$format = $this->get_localized_format();

		return null === $format ? $default_separator : $format['thousand_separator'];
	}

	/**
	 * Get the format only when this request really is localized.
	 *
	 * @return array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int}|null
	 */
	private function get_localized_format(): ?array {
		if ( ! $this->applies() ) {
			return null;
		}

		$format = $this->get_format();

		return null === $format || '' === $format['currency'] ? null : $format;
	}

	/**
	 * Get the presentation format of the visitor's country or active context.
	 *
	 * @return array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int}|null
	 */
	public function get_format(): ?array {
		if ( $this->format_resolved ) {
			return $this->format;
		}

		$this->format_resolved = true;

		$country = CountryCodeValidator::sanitize( (string) $this->country_service->get_current_user_country() );

		if ( '' === $country ) {
			return null;
		}

		try {
			$context_currency = $this->resolve_context_currency( $country );

			if ( null !== $context_currency && '' !== $context_currency ) {
				$this->format = $this->country_currency->get_format_for_currency( $context_currency, $country );
			} else {
				$this->format = $this->country_currency->get_format_for_country( $country );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Currency format lookup failed for %s: %s', $country, $e->getMessage() ),
				array( 'country_code' => $country ),
				'WooCommerce'
			);
		}

		return $this->format;
	}

	/**
	 * Get the presentation format for a specific currency code in the visitor's country.
	 *
	 * @param string $currency_code Upper-case ISO currency code.
	 * @return array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int}
	 */
	public function get_format_for_currency( string $currency_code ): array {
		$country = CountryCodeValidator::sanitize( (string) $this->country_service->get_current_user_country() );

		return $this->country_currency->get_format_for_currency( $currency_code, '' !== $country ? $country : null );
	}

	/**
	 * Resolve the currency code appropriate for the current request context.
	 *
	 * @param string $country Sanitized country code.
	 * @return string|null Upper-case currency code, or null to follow country mapping.
	 */
	private function resolve_context_currency( string $country ): ?string {

		if ( function_exists( 'WC' ) && null !== WC()->session ) {
			$session_country = WC()->session->get( 'd6n_lpm_cart_country' );
			$cart_currency   = WC()->session->get( 'd6n_lpm_active_cart_currency' );

			if ( $session_country === $country && is_string( $cart_currency ) && '' !== $cart_currency ) {
				if ( ( function_exists( 'is_cart' ) && is_cart() )
					|| ( function_exists( 'is_checkout' ) && is_checkout() )
					|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
					|| ( null !== WC()->cart && ! WC()->cart->is_empty() )
				) {
					return strtoupper( trim( $cart_currency ) );
				}
			} elseif ( $session_country !== $country && null !== WC()->cart && ! WC()->cart->is_empty() && null !== $this->price_provider ) {
				$items         = WC()->cart->get_cart();
				$last_item_key = WC()->session->get( 'd6n_lpm_last_added_item_key' );
				$target_item   = null;

				if ( is_string( $last_item_key ) && isset( $items[ $last_item_key ]['data'] ) && $items[ $last_item_key ]['data'] instanceof WC_Product ) {
					$target_item = $items[ $last_item_key ]['data'];
				} else {
					$last_entry = end( $items );
					if ( is_array( $last_entry ) && isset( $last_entry['data'] ) && $last_entry['data'] instanceof WC_Product ) {
						$target_item = $last_entry['data'];
					}
				}

				if ( null !== $target_item ) {
					$resolved = $this->price_provider->resolve( $target_item );
					if ( null !== $resolved && ! $resolved->is_hidden() && '' !== $resolved->currency ) {
						$currency = strtoupper( trim( $resolved->currency ) );
						WC()->session->set( 'd6n_lpm_active_cart_currency', $currency );
						WC()->session->set( 'd6n_lpm_cart_country', $country );

						return $currency;
					}
				}
			}
		}

		if ( function_exists( 'is_product' ) && is_product() && null !== $this->price_provider ) {
			$product_id = (int) get_queried_object_id();

			if ( $product_id > 0 && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );

				if ( $product instanceof WC_Product ) {
					$resolved = $this->price_provider->resolve( $product );

					if ( null !== $resolved && ! $resolved->is_hidden() && '' !== $resolved->currency ) {
						return strtoupper( trim( $resolved->currency ) );
					}
				}
			}
		}

		return null;
	}

	/**
	 * Ensure product price HTML displays in the product's resolved currency if different from page currency.
	 *
	 * @param string $price_html Price HTML generated by WooCommerce.
	 * @param mixed  $product    Product instance being formatted.
	 * @return string
	 */
	public function filter_price_html( string $price_html, mixed $product ): string {
		if ( ! ( $product instanceof WC_Product ) || null === $this->price_provider || ! $this->applies() ) {
			return $price_html;
		}

		if ( '' === $price_html ) {
			return '';
		}

		$resolved = $this->price_provider->resolve( $product );

		if ( null === $resolved || '' === $resolved->currency || $resolved->is_blocked || '' === (string) $resolved->price ) {
			return $price_html;
		}

		$format = $this->get_format();

		if ( null !== $format && $resolved->currency === $format['currency'] ) {
			return $price_html;
		}

		$product_format = $this->get_format_for_currency( $resolved->currency );
		$args           = array(
			'currency'           => $resolved->currency,
			'decimal_separator'  => $product_format['decimal_separator'],
			'thousand_separator' => $product_format['thousand_separator'],
			'decimals'           => $product_format['decimals'],
		);

		if ( $product->is_on_sale() ) {
			$regular       = $this->price_provider->resolve( $product, PriceResolutionService::PRICE_TYPE_REGULAR );
			$regular_price = null !== $regular ? (float) $regular->price : (float) $product->get_regular_price();
			$sale_price    = (float) $resolved->price;

			return wc_format_sale_price( wc_price( $regular_price, $args ), wc_price( $sale_price, $args ) ) . $product->get_price_suffix();
		}

		return wc_price( (float) $resolved->price, $args ) . $product->get_price_suffix();
	}

	/**
	 * Whether currency presentation should be localized for this request.
	 *
	 * @return bool
	 */
	private function applies(): bool {
		if ( $this->product_types->is_suppressed() ) {
			return false;
		}

		if ( null !== $this->request_applies ) {
			return $this->request_applies;
		}

		$applies = Core::is_active()
			&& ! RequestContext::is_wp_admin()
			&& ! wp_doing_cron()
			&& ! ( defined( 'WP_CLI' ) && WP_CLI )
			&& ! RequestContext::is_foreign_rest_request();

		if ( did_action( 'parse_request' ) > 0 ) {
			$this->request_applies = $applies;
		}

		return $applies;
	}
}
