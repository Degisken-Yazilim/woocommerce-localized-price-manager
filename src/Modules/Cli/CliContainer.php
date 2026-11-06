<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cli;

use Closure;
use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Database\MigrationRunner;
use D6N\LPM\Modules\Cache\CacheModule;
use D6N\LPM\Modules\Cache\Services\CacheWarmUpService;
use D6N\LPM\Modules\DiagnosticLog\DiagnosticLogModule;
use D6N\LPM\Modules\ExchangeRates\ExchangeRatesModule;
use D6N\LPM\Modules\ExchangeRates\Providers\ExchangeRateProviderRegistry;
use D6N\LPM\Modules\ExchangeRates\Repositories\ExchangeRateRepository;
use D6N\LPM\Modules\ExchangeRates\Services\ExchangeRatesService;
use D6N\LPM\Modules\Geolocation\GeolocationModule;
use D6N\LPM\Modules\PricingEngine\PricingEngineModule;
use D6N\LPM\Modules\WooCommerce\Support\ProductTypeSupport;

defined( 'ABSPATH' ) || exit;

/**
 * Lazy object graph shared by the WP-CLI commands.
 */
class CliContainer {

	/**
	 * Diagnostic log module.
	 *
	 * @var DiagnosticLogModule|null
	 */
	private ?DiagnosticLogModule $log_module = null;

	/**
	 * Cache module.
	 *
	 * @var CacheModule|null
	 */
	private ?CacheModule $cache_module = null;

	/**
	 * Geolocation module.
	 *
	 * @var GeolocationModule|null
	 */
	private ?GeolocationModule $geolocation_module = null;

	/**
	 * Exchange rates module.
	 *
	 * @var ExchangeRatesModule|null
	 */
	private ?ExchangeRatesModule $rates_module = null;

	/**
	 * Pricing engine module.
	 *
	 * @var PricingEngineModule|null
	 */
	private ?PricingEngineModule $pricing_module = null;

	/**
	 * Migration runner.
	 *
	 * @var MigrationRunner|null
	 */
	private ?MigrationRunner $migration_runner = null;

	/**
	 * Whether the warm-up closures have already been injected.
	 *
	 * @var bool
	 */
	private bool $warm_up_wired = false;

	/**
	 * Get the plugin logger.
	 *
	 * @return ILogger Logger instance.
	 */
	public function get_logger(): ILogger {
		return $this->get_log_module()->get_logger();
	}

	/**
	 * Get the active cache adapter.
	 *
	 * @return ICache Cache adapter.
	 */
	public function get_cache(): ICache {
		return $this->get_cache_module()->get_cache_adapter();
	}

	/**
	 * Get the exchange rates service.
	 *
	 * @return ExchangeRatesService Rates service.
	 */
	public function get_rates_service(): ExchangeRatesService {
		return $this->get_rates_module()->get_service();
	}

	/**
	 * Get the exchange rate repository.
	 *
	 * @return ExchangeRateRepository Rate repository.
	 */
	public function get_rates_repository(): ExchangeRateRepository {
		return $this->get_rates_module()->get_repository();
	}

	/**
	 * Get the exchange rate provider registry.
	 *
	 * @return ExchangeRateProviderRegistry Provider registry.
	 */
	public function get_rates_registry(): ExchangeRateProviderRegistry {
		return $this->get_rates_module()->get_registry();
	}

	/**
	 * Get the migration runner.
	 *
	 * @return MigrationRunner Migration runner.
	 */
	public function get_migration_runner(): MigrationRunner {
		if ( null === $this->migration_runner ) {
			$this->migration_runner = new MigrationRunner( $this->get_logger() );
		}

		return $this->migration_runner;
	}

	/**
	 * Get the cache warm-up service with its closures wired in.
	 *
	 * @return CacheWarmUpService Warm-up service.
	 */
	public function get_warm_up_service(): CacheWarmUpService {
		$service = $this->get_cache_module()->get_warm_up_service();

		if ( ! $this->warm_up_wired ) {
			$selector = $this->get_pricing_module()->get_warm_up_product_selector();

			$service->set_price_resolver( $this->get_pricing_module()->make_warm_up_price_resolver( ( new ProductTypeSupport() )->is_supported( ... ) ) );
			$service->set_country_provider( $this->make_country_provider() );
			$service->set_ruled_country_provider( $this->get_pricing_module()->get_ruled_country_service()->get_ruled_countries( ... ) );
			$service->set_product_selector( $selector->select_ruled_first( ... ) );
			$service->set_category_selector( $selector->select_in_category( ... ) );

			$this->warm_up_wired = true;
		}

		return $service;
	}

	/**
	 * Get the diagnostic log module.
	 *
	 * @return DiagnosticLogModule Log module.
	 */
	private function get_log_module(): DiagnosticLogModule {
		if ( null === $this->log_module ) {
			$this->log_module = new DiagnosticLogModule();
		}

		return $this->log_module;
	}

	/**
	 * Get the cache module.
	 *
	 * @return CacheModule Cache module.
	 */
	private function get_cache_module(): CacheModule {
		if ( null === $this->cache_module ) {
			$this->cache_module = new CacheModule( $this->get_logger() );
		}

		return $this->cache_module;
	}

	/**
	 * Get the geolocation module.
	 *
	 * @return GeolocationModule Geolocation module.
	 */
	private function get_geolocation_module(): GeolocationModule {
		if ( null === $this->geolocation_module ) {
			$this->geolocation_module = new GeolocationModule( $this->get_logger(), $this->get_cache() );
		}

		return $this->geolocation_module;
	}

	/**
	 * Get the exchange rates module.
	 *
	 * @return ExchangeRatesModule Rates module.
	 */
	private function get_rates_module(): ExchangeRatesModule {
		if ( null === $this->rates_module ) {
			$this->rates_module = new ExchangeRatesModule( $this->get_logger(), $this->get_cache() );
		}

		return $this->rates_module;
	}

	/**
	 * Get the pricing engine module.
	 *
	 * @return PricingEngineModule Pricing engine module.
	 */
	private function get_pricing_module(): PricingEngineModule {
		if ( null === $this->pricing_module ) {
			$this->pricing_module = new PricingEngineModule(
				$this->get_rates_service(),
				$this->get_geolocation_module()->get_country_service(),
				$this->get_cache(),
				$this->get_logger(),
				$this->get_cache_module()->get_price_cache()
			);
		}

		return $this->pricing_module;
	}

	/**
	 * Build the closure that lists the active country codes.
	 *
	 * @return Closure Provider closure returning ISO country codes.
	 */
	private function make_country_provider(): Closure {
		$repository = $this->get_pricing_module()->get_country_currency_repository();

		return static function () use ( $repository ): array {
			$rows = $repository->get_active_mappings();

			if ( ! is_array( $rows ) ) {
				return array();
			}

			$codes = array();

			foreach ( $rows as $row ) {
				$code = strtoupper( trim( (string) ( $row->country_code ?? '' ) ) );

				if ( '' !== $code ) {
					$codes[ $code ] = $code;
				}
			}

			return array_values( $codes );
		};
	}
}
