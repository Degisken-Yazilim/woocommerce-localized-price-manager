<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Services;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\CacheGroupEnum;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Shared\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Service responsible for purging and invalidating price & configuration caches upon data updates.
 */
class CacheInvalidationService implements IHookable {

	/**
	 * Constructor.
	 *
	 * @param ICache            $cache       Active cache adapter instance.
	 * @param PriceCacheService $price_cache Resolved-price cache key owner.
	 * @param ILogger           $logger      Logger instance.
	 */
	public function __construct(
		private readonly ICache $cache,
		private readonly PriceCacheService $price_cache,
		private readonly ILogger $logger
	) {}

	/**
	 * Register WordPress action hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress action hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'save_post_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_delete_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( Core::build_hook( HookEnum::CountryCurrencySaved->value ), array( $this, 'invalidate_all_pricing_cache' ) );
		add_action( Core::build_hook( HookEnum::SettingsSaved->value ), array( $this, 'invalidate_all_pricing_cache' ) );
		add_action( Core::build_hook( HookEnum::CurrencyRateUpdated->value ), array( $this, 'invalidate_currency_cache' ), 10, 1 );

		foreach ( Core::WC_MONEY_OPTIONS as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'invalidate_all_pricing_cache' ), 10, 0 );
		}
	}

	/**
	 * Invalidate every cached price of a single product.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function on_product_saved( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		$this->price_cache->flush_product( $product_id );
		$this->logger->cache( sprintf( 'Invalidated pricing cache for product ID: %d', $product_id ), array() );
	}

	/**
	 * Invalidate cache entries specifically for a given currency code.
	 *
	 * @param string $currency_code ISO currency code.
	 * @return void
	 */
	public function invalidate_currency_cache( string $currency_code ): void {
		$currency_code = strtoupper( trim( $currency_code ) );

		if ( '' === $currency_code ) {
			return;
		}

		$this->price_cache->flush();
		$this->bump_pricing_revision();

		$this->logger->cache( sprintf( 'Cache invalidation executed for currency: %s', $currency_code ), array() );
	}

	/**
	 * Invalidate all pricing cache entries across all groups.
	 *
	 * @return void
	 */
	public function invalidate_all_pricing_cache(): void {
		$this->price_cache->flush();
		$this->cache->flush_group( CacheGroupEnum::CountryCurrency->value );
		$this->bump_pricing_revision();
		$this->logger->cache( 'Flushed all pricing and country-currency cache groups.', array() );
	}

	/**
	 * Advance the pricing revision that carts stamp themselves with.
	 *
	 * @return void
	 */
	private function bump_pricing_revision(): void {
		$current = (int) Core::get_option( OptionsEnum::PricingRevision->value, 0 );

		Core::save_option( OptionsEnum::PricingRevision->value, $current + 1 );
	}
}
