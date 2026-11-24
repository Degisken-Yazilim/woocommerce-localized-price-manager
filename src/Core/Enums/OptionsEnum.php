<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin option keys stored in WordPress options.
 */
enum OptionsEnum: string {
	case MasterEnabled        = 'd6n_lpm_enabled';
	case ActiveStatus         = 'active_status';
	case GlobalCurrency       = 'global_currency';
	case CustomerGeolocation  = 'customer_geolocation';
	case LocalPriceRounding   = 'local_price_rounding';
	case LogLevel             = 'log_level';
	case FallbackMode         = 'fallback_mode';
	case ExchangeRateProvider = 'exchange_rate_provider';

	/**
	 * Per-provider activation and priority.
	 */
	case ExchangeRateProviders = 'exchange_rate_providers';
	case ExchangeRateFrequency = 'exchange_rate_frequency';

	/**
	 * Monotonic counter of the pricing basis, bumped on every global invalidation.
	 */
	case PricingRevision = 'pricing_revision';

	case GeolocationProviders = 'geolocation_providers';
	case CountryCookie        = 'country_cookie';
	case LogRetentionDays     = 'log_retention_days';
	case DbLogEnabled         = 'db_log_enabled';
	case FileLogEnabled       = 'file_log_enabled';
	case IPDetectionStrategy  = 'ip_detection_strategy';

	case TrustedHeaders        = 'trusted_headers';
	case TrustedCountryHeaders = 'trusted_country_headers';

	/**
	 * Standalone wp_options row used as the schema-migration execution lock.
	 */
	case MigrationLock = 'd6n_lpm_migration_lock';

	/**
	 * Standalone wp_options row naming the current cache warm-up batch chain.
	 */
	case CacheWarmUpRun      = 'd6n_lpm_cache_warmup_run';
	case CacheWarmUpProducts = 'd6n_lpm_cache_warmup_products';

	/**
	 * Standalone, autoloaded wp_options row holding the remembered cache driver probe outcomes.
	 */
	case CacheProbe = 'd6n_lpm_cache_probe';

	/**
	 * Plugin build whose migration ledger has already been found complete.
	 */
	case SchemaCheckedVersion = 'schema_checked_version';

	/**
	 * Newline separated list of approved proxy network ranges.
	 */
	case TrustedProxyRanges = 'trusted_proxy_ranges';

	/**
	 * Generation stamp baked into every country-partitioned widget cache id.
	 */
	case WidgetCacheRevision = 'widget_cache_revision';

	/**
	 * Master switch for the destructive part of `uninstall.php`.
	 */
	case RemoveDataOnUninstall = 'remove_data_on_uninstall';
}
