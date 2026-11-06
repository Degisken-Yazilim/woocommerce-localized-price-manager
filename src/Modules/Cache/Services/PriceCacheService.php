<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Services;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Enums\CacheGroupEnum;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\Cache\Adapters\AbstractCacheAdapter;
use D6N\LPM\Modules\Cache\ValueObjects\CacheMiss;

defined( 'ABSPATH' ) || exit;

/**
 * Single owner of the resolved-price cache key scheme.
 */
class PriceCacheService {

	/**
	 * Whether resolved prices outlive the request, or null until first asked.
	 *
	 * @var bool|null
	 */
	private ?bool $persistent = null;

	/**
	 * Resolved prices kept for this request only, keyed by group, then key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $request_store = array();

	/**
	 * Number of times this request has flushed resolved prices.
	 *
	 * @var int
	 */
	private int $generation = 0;

	/**
	 * Constructor.
	 *
	 * @param ICache $cache Active cache adapter instance.
	 */
	public function __construct(
		private readonly ICache $cache
	) {}

	/**
	 * Get the TTL used for resolved prices.
	 *
	 * @return int TTL in seconds.
	 */
	public function get_ttl(): int {
		return CacheGroupEnum::Pricing->ttl();
	}

	/**
	 * Get the cache group holding a product's resolved prices.
	 *
	 * @param int $product_id Product ID, or 0 for the shared pricing group.
	 * @return string Cache group name.
	 */
	/**
	 * The cache adapter the resolved prices are actually stored in.
	 *
	 * @return ICache Active cache adapter.
	 */
	public function get_adapter(): ICache {
		return $this->cache;
	}

	/**
	 * Whether resolved prices are stored beyond the current request.
	 *
	 * @return bool False when the active adapter keeps its entries in the database.
	 */
	public function is_persistent(): bool {
		$this->persistent ??= ! $this->cache instanceof AbstractCacheAdapter || $this->cache->is_memory_backed();

		return $this->persistent;
	}

	public function get_group( int $product_id = 0 ): string {
		if ( $product_id > 0 ) {
			return CacheGroupEnum::Pricing->value . ':product_' . $product_id;
		}

		return CacheGroupEnum::Pricing->value;
	}

	/**
	 * Build the cache key of one resolved price.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $price_type   Price type, typically 'regular' or 'sale'.
	 * @param string $country_code Customer ISO country code.
	 * @return string Cache key.
	 */
	public function build_key( int $product_id, string $price_type, string $country_code ): string {
		$base_key = sprintf( 'resolution_%d_%s_%s', $product_id, $price_type, strtoupper( $country_code ) );

		/**
		 * Filters the cache key used to store a resolved localized price.
		 *
		 * @param string $base_key     Unversioned key: 'resolution_{id}_{type}_{country}'.
		 * @param int    $product_id   Product ID.
		 * @param string $price_type   Price type.
		 * @param string $country_code Customer ISO country code.
		 */
		return (string) apply_filters(
			Core::build_hook( HookEnum::PricingPriceCacheKey->value ),
			$base_key,
			$product_id,
			$price_type,
			$country_code
		);
	}

	/**
	 * Read a cached resolved price.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $price_type   Price type.
	 * @param string $country_code Customer ISO country code.
	 * @return mixed Cached price, or CacheMiss::instance() on a miss.
	 */
	public function get( int $product_id, string $price_type, string $country_code ): mixed {
		$key   = $this->build_key( $product_id, $price_type, $country_code );
		$group = $this->get_group( $product_id );

		if ( ! $this->is_persistent() ) {
			return array_key_exists( $key, $this->request_store[ $group ] ?? array() )
				? $this->request_store[ $group ][ $key ]
				: CacheMiss::instance();
		}

		return $this->cache->get( $key, $group );
	}

	/**
	 * Read the cached resolved prices of several products in one batch.
	 *
	 * @param array<int, int>    $product_ids  Product IDs.
	 * @param array<int, string> $price_types  Price types.
	 * @param string             $country_code Customer ISO country code.
	 * @return array<int, array<string, mixed>> Cached prices keyed by product ID, then price type; CacheMiss::instance() on a miss.
	 */
	public function get_many( array $product_ids, array $price_types, string $country_code ): array {
		$keys          = array();
		$keys_by_group = array();

		foreach ( array_unique( array_map( 'intval', $product_ids ) ) as $product_id ) {
			$group = $this->get_group( $product_id );

			foreach ( $price_types as $price_type ) {
				$key = $this->build_key( $product_id, $price_type, $country_code );

				$keys[ $product_id ][ $price_type ] = $key;
				$keys_by_group[ $group ][]          = $key;
			}
		}

		$values = $this->is_persistent() ? $this->cache->get_multiple( $keys_by_group ) : $this->request_store;
		$found  = array();

		foreach ( $keys as $product_id => $product_keys ) {
			$group = $this->get_group( $product_id );

			foreach ( $product_keys as $price_type => $key ) {
				$found[ $product_id ][ $price_type ] = array_key_exists( $key, $values[ $group ] ?? array() )
					? $values[ $group ][ $key ]
					: CacheMiss::instance();
			}
		}

		return $found;
	}

	/**
	 * Store a resolved price.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $price_type   Price type.
	 * @param string $country_code Customer ISO country code.
	 * @param mixed  $value        Resolved price.
	 * @return bool True on success.
	 */
	public function set( int $product_id, string $price_type, string $country_code, mixed $value ): bool {
		$key   = $this->build_key( $product_id, $price_type, $country_code );
		$group = $this->get_group( $product_id );

		if ( ! $this->is_persistent() ) {
			$this->request_store[ $group ][ $key ] = $value;

			return true;
		}

		$this->cache->set_deferred( $key, $value, $this->get_ttl(), $group );

		return true;
	}

	/**
	 * Store the resolved prices of several products in a single batch.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $items_by_product Price rows keyed by product ID.
	 * @return array<int, bool> Whether each product's prices were stored, keyed by product ID; false when nothing usable was supplied for it.
	 */
	public function set_multiple( array $items_by_product ): array {
		$data_by_group = array();
		$results       = array();

		foreach ( $items_by_product as $product_id => $items ) {
			$product_id = (int) $product_id;
			$group      = $this->get_group( $product_id );
			$data       = array();

			foreach ( $items as $item ) {
				if ( ! isset( $item['country_code'], $item['price_type'] ) || ! array_key_exists( 'value', $item ) ) {
					continue;
				}

				$key          = $this->build_key( $product_id, (string) $item['price_type'], (string) $item['country_code'] );
				$data[ $key ] = $item['value'];
			}

			$results[ $product_id ] = array() !== $data;

			if ( array() !== $data ) {
				$data_by_group[ $group ] = $data;
			}
		}

		if ( array() === $data_by_group ) {
			return $results;
		}

		if ( ! $this->is_persistent() ) {
			foreach ( $data_by_group as $group => $data ) {
				$this->request_store[ $group ] = array_replace( $this->request_store[ $group ] ?? array(), $data );
			}

			return $results;
		}

		if ( ! $this->cache->set_multiple( $data_by_group, $this->get_ttl() ) ) {
			return array_map( static fn (): bool => false, $results );
		}

		return $results;
	}

	/**
	 * Invalidate every cached price of one product.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True on success.
	 */
	public function flush_product( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		++$this->generation;

		unset( $this->request_store[ $this->get_group( $product_id ) ] );

		return $this->cache->flush_group( $this->get_group( $product_id ) );
	}

	/**
	 * Invalidate every cached price of every product.
	 *
	 * @return bool True on success.
	 */
	public function flush(): bool {
		++$this->generation;

		$this->request_store = array();

		return $this->cache->flush_group( $this->get_group() );
	}

	/**
	 * Get how many times this request has flushed resolved prices.
	 *
	 * @return int
	 */
	public function get_generation(): int {
		return $this->generation;
	}

	/**
	 * Check whether a value returned by this service is a cache miss.
	 *
	 * @param mixed $value Value returned by get().
	 * @return bool True when the value is the miss sentinel.
	 */
	public function is_miss( mixed $value ): bool {
		return CacheMiss::instance() === $value;
	}
}
