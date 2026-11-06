<?php
declare(strict_types=1);

namespace D6N\LPM\Core;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Lifecycle\SchemaUpgrader;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\AdminPortalModule;
use D6N\LPM\Modules\Cache\CacheModule;
use D6N\LPM\Modules\DiagnosticLog\DiagnosticLogModule;
use D6N\LPM\Modules\ExchangeRates\ExchangeRatesModule;
use D6N\LPM\Modules\Geolocation\GeolocationModule;
use D6N\LPM\Modules\PricingEngine\PricingEngineModule;
use D6N\LPM\Modules\WooCommerce\WooCommerceModule;

defined( 'ABSPATH' ) || exit;

/**
 * Main application container and runtime orchestrator.
 */
class Run implements IHookable {

	private DiagnosticLogModule $log_module;
	private CacheModule $cache_module;
	private GeolocationModule $geolocation_module;
	private ExchangeRatesModule $exchange_rates_module;
	private PricingEngineModule $pricing_engine_module;
	private WooCommerceModule $woocommerce_module;
	private AdminPortalModule $admin_portal_module;
	private SchemaUpgrader $schema_upgrader;

	/**
	 * Constructor initializing all domain modules.
	 */
	public function __construct() {
		$this->log_module = new DiagnosticLogModule();
		$logger           = $this->log_module->get_logger();
		$log_repository   = $this->log_module->get_repository();

		$this->cache_module = new CacheModule( $logger );
		$cache              = $this->cache_module->get_cache_adapter();

		$this->geolocation_module = new GeolocationModule( $logger, $cache );
		$country_service          = $this->geolocation_module->get_country_service();

		$this->exchange_rates_module = new ExchangeRatesModule( $logger, $cache );
		$exchange_rates_service      = $this->exchange_rates_module->get_service();

		$this->pricing_engine_module = new PricingEngineModule(
			$exchange_rates_service,
			$country_service,
			$cache,
			$logger,
			$this->cache_module->get_price_cache()
		);
		$resolution_service          = $this->pricing_engine_module->get_resolution_service();
		$country_currency_service    = $this->pricing_engine_module->get_country_currency_service();

		$this->woocommerce_module = new WooCommerceModule(
			$resolution_service,
			$country_currency_service,
			$country_service,
			$logger,
			$this->pricing_engine_module->get_listing_prefetcher()
		);

		$this->admin_portal_module = new AdminPortalModule(
			$log_repository,
			$cache,
			$this->cache_module->get_price_cache(),
			$logger,
			$this->pricing_engine_module,
			$this->exchange_rates_module,
			$this->geolocation_module
		);

		$this->schema_upgrader = new SchemaUpgrader( $logger );
	}

	/**
	 * Wire the cache warm-up job to the pricing engine.
	 *
	 * @return void
	 */
	private function enable_cache_warm_up(): void {
		$country_currency_repository = $this->pricing_engine_module->get_country_currency_repository();
		$ruled_countries             = $this->pricing_engine_module->get_ruled_country_service();
		$selector                    = $this->pricing_engine_module->get_warm_up_product_selector();

		$this->cache_module->enable_warm_up(
			$this->pricing_engine_module->make_warm_up_price_resolver( $this->woocommerce_module->get_product_types()->is_supported( ... ) ),
			static function () use ( $country_currency_repository ): array {
				$mappings = $country_currency_repository->get_active_mappings();

				if ( is_wp_error( $mappings ) ) {
					return array();
				}

				return array_values(
					array_filter(
						array_map(
							static fn ( $row ): string => isset( $row->country_code ) ? (string) $row->country_code : '',
							$mappings
						)
					)
				);
			},
			$ruled_countries->get_ruled_countries( ... ),
			$selector->select_ruled_first( ... ),
			$selector->select_in_category( ... )
		);
	}

	/**
	 * Register all module hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( array( 'update_option_', 'add_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . Core::get_domain(), array( Core::class, 'forget_option_row' ), 10, 0 );
		}

		$localizes = Core::is_active() && $this->geolocation_module->get_provider_queue()->has_providers();

		$this->log_module->register();
		$this->schema_upgrader->register();
		$this->cache_module->register();
		$this->geolocation_module->register();
		$this->exchange_rates_module->register();
		$this->pricing_engine_module->register_rule_upkeep();

		if ( $localizes ) {
			$this->pricing_engine_module->register();
			$this->woocommerce_module->register();
		}

		$this->admin_portal_module->register();

		if ( $localizes ) {
			$this->enable_cache_warm_up();
		}
	}
}
