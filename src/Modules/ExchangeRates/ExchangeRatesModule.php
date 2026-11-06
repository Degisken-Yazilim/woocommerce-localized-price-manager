<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Shared\TaskScheduler;
use D6N\LPM\Modules\ExchangeRates\Models\ExchangeRateModel;
use D6N\LPM\Modules\ExchangeRates\Providers\ExchangeRateProviderRegistry;
use D6N\LPM\Modules\ExchangeRates\Providers\FrankfurterProvider;
use D6N\LPM\Modules\ExchangeRates\Repositories\ExchangeRateRepository;
use D6N\LPM\Modules\ExchangeRates\Services\ExchangeRatesService;

defined( 'ABSPATH' ) || exit;

/**
 * Exchange rates bounded-context module bootstrapper.
 */
class ExchangeRatesModule implements IHookable {

	/**
	 * Rate repository.
	 *
	 * @var ExchangeRateRepository
	 */
	private ExchangeRateRepository $repository;

	/**
	 * Provider registry.
	 *
	 * @var ExchangeRateProviderRegistry
	 */
	private ExchangeRateProviderRegistry $registry;

	/**
	 * Rates service.
	 *
	 * @var ExchangeRatesService
	 */
	private ExchangeRatesService $service;

	/**
	 * Constructor.
	 *
	 * @param ILogger $logger Logger instance.
	 * @param ICache  $cache  Cache adapter instance.
	 */
	public function __construct( ILogger $logger, ICache $cache ) {
		$this->repository = new ExchangeRateRepository( new ExchangeRateModel(), $logger );
		$this->registry   = new ExchangeRateProviderRegistry( $logger );

		$this->service = new ExchangeRatesService(
			$this->repository,
			new FrankfurterProvider( null, $logger ),
			$cache,
			$logger,
			null,
			$this->registry,
			new TaskScheduler( $logger )
		);
	}

	/**
	 * Get the exchange rates service.
	 *
	 * @return ExchangeRatesService Rates service.
	 */
	public function get_service(): ExchangeRatesService {
		return $this->service;
	}

	/**
	 * Get the exchange rate repository.
	 *
	 * @return ExchangeRateRepository Rate repository.
	 */
	public function get_repository(): ExchangeRateRepository {
		return $this->repository;
	}

	/**
	 * Get the provider registry, for the admin settings screen.
	 *
	 * @return ExchangeRateProviderRegistry Provider registry.
	 */
	public function get_registry(): ExchangeRateProviderRegistry {
		return $this->registry;
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
		$this->service->register_hooks();
	}
}
