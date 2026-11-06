<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache;

use Closure;
use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Modules\Cache\Services\CacheInvalidationService;
use D6N\LPM\Modules\Cache\Services\CacheManager;
use D6N\LPM\Modules\Cache\Services\CacheWarmUpService;
use D6N\LPM\Modules\Cache\Services\PriceCacheService;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Cache bounded-context module bootstrapper.
 */
class CacheModule implements IHookable {

	private ICache $cache_adapter;
	private PriceCacheService $price_cache;
	private CacheInvalidationService $invalidation_service;
	private CacheWarmUpService $warm_up_service;

	/**
	 * Constructor.
	 *
	 * @param ILogger $logger Logger instance.
	 */
	public function __construct( private readonly ILogger $logger ) {
		$manager             = new CacheManager( $logger );
		$this->cache_adapter = $manager->get_active_adapter();

		$this->price_cache          = new PriceCacheService( $this->cache_adapter );
		$this->invalidation_service = new CacheInvalidationService( $this->cache_adapter, $this->price_cache, $logger );
		$this->warm_up_service      = new CacheWarmUpService(
			$this->price_cache,
			$logger,
			persistent_cache_probe: $this->price_cache->is_persistent( ... )
		);
	}

	/**
	 * Get active cache adapter.
	 *
	 * @return ICache
	 */
	public function get_cache_adapter(): ICache {
		return $this->cache_adapter;
	}

	/**
	 * Get the cache warm-up service.
	 *
	 * @return CacheWarmUpService
	 */
	public function get_warm_up_service(): CacheWarmUpService {
		return $this->warm_up_service;
	}

	/**
	 * Wire the price resolution, country and product selection closures into the warm-up job.
	 *
	 * @param Closure $price_resolver         Resolver closure: ( int, string, string ): mixed.
	 * @param Closure $country_provider       Closure returning active ISO country codes.
	 * @param Closure $ruled_country_provider Closure returning the countries an operator has priced.
	 * @param Closure $product_selector       Closure selecting the products a run covers: ( int $limit ): array<int>.
	 * @param Closure $category_selector      Closure paging a category's products: ( int $term_id, int $after_id, int $limit ): array<int>.
	 * @return void
	 */
	public function enable_warm_up( Closure $price_resolver, Closure $country_provider, Closure $ruled_country_provider, Closure $product_selector, Closure $category_selector ): void {
		$this->warm_up_service->set_price_resolver( $price_resolver );
		$this->warm_up_service->set_country_provider( $country_provider );
		$this->warm_up_service->set_ruled_country_provider( $ruled_country_provider );
		$this->warm_up_service->set_product_selector( $product_selector );
		$this->warm_up_service->set_category_selector( $category_selector );
	}

	/**
	 * Get the resolved-price cache, the single owner of its key scheme.
	 *
	 * @return PriceCacheService Resolved-price cache.
	 */
	public function get_price_cache(): PriceCacheService {
		return $this->price_cache;
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
		$this->invalidation_service->register_hooks();
		$this->warm_up_service->register_hooks();

		add_action( 'shutdown', array( $this, 'flush_deferred_writes' ), WooHookPriorityEnum::VeryLate->value, 0 );
	}

	/**
	 * Write the price entries queued during the request.
	 *
	 * @return void
	 */
	public function flush_deferred_writes(): void {
		try {
			$this->cache_adapter->flush_deferred();
		} catch ( Throwable $e ) {
			$this->logger->error( 'Deferred cache writes failed: ' . $e->getMessage(), array(), 'Cache' );
		}
	}
}
