<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * All custom plugin hook suffixes passed to Core::build_hook().
 */
enum HookEnum: string {
	case PricingIsConversionEnabled = 'pricing_is_conversion_enabled';
	case PricingResolvedPrice       = 'pricing_resolved_price';
	case PricingPriceResolved       = 'pricing_price_resolved';
	case PricingPriceCacheKey       = 'pricing_price_cache_key';
	case PricingMode1Price          = 'pricing_mode1_price';
	case PricingMode2Price          = 'pricing_mode2_price';
	case PricingFallbackPrice       = 'pricing_fallback_price';
	case PricingFinalPrice          = 'pricing_final_price';
	case PricingExchangeRate        = 'pricing_exchange_rate';
	case PricingRounding            = 'pricing_rounding';
	case PricingCurrencyCode        = 'pricing_currency_code';
	case PricingCurrencySymbol      = 'pricing_currency_symbol';
	case PricingCurrencyFormatArgs  = 'pricing_currency_format_args';
	case PricingOrderMetaSaved      = 'pricing_order_meta_saved';
	case PricingCartPricesUpdated   = 'pricing_cart_prices_updated';
	case PricingCartIdentityChanged = 'pricing_cart_identity_changed';
	case PricingProductHidden       = 'pricing_product_hidden';
	case ProductIsSupportedType     = 'product_is_supported_type';
	case RequestIsStorefrontAjax    = 'request_is_storefront_ajax';
	case CountryAllowPreview        = 'country_allow_preview';
	case CountryGetUserCountry      = 'country_get_user_country';
	case CountryGetGuestCountry     = 'country_get_guest_country';
	case CountryCustomerDetected    = 'country_customer_detected';
	case CountryGuestDetected       = 'country_guest_detected';

	case IPDetectionStrategy = 'ip_detection_strategy';
	case TrustCDNHeaderIP    = 'trust_cdn_header_ip';

	case CountryCurrencySaved      = 'country_currency_saved';
	case CurrencyRateUpdated       = 'currency_rate_updated';
	case ExchangeRatesRefreshed    = 'exchange_rates_refreshed';
	case SettingsSaved             = 'settings_saved';
	case ProductRulesSaved         = 'product_rules_saved';
	case CategoryRulesSaved        = 'category_rules_saved';
	case ExchangeRateProviders     = 'exchange_rate_providers';
	case GeolocationAdapters       = 'geolocation_adapters';
	case GeolocationChainExhausted = 'geolocation_chain_exhausted';
	case CacheAdapters             = 'cache_adapters';
	case HttpRequestArgs           = 'http_request_args';

	case UpdateExchangeRates = 'update_exchange_rates';
	case CleanOldLogs        = 'clean_old_logs';
}
