<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Adapters;

use D6N\LPM\Modules\Cache\Support\PayloadCodec;
use D6N\LPM\Modules\Cache\ValueObjects\CacheMiss;
use InvalidArgumentException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress Object Cache adapter.
 */
class WpObjectCacheAdapter extends AbstractCacheAdapter {

	/**
	 * Check if WP object cache is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->has_external_object_cache();
	}

	/**
	 * Check whether a persistent object cache drop-in is active.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return (bool) wp_using_ext_object_cache();
	}

	/**
	 * Read one entry from the backend.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return mixed Cached value, or CacheMiss::instance() on a miss.
	 */
	protected function read_entry( string $key, string $group ): mixed {
		return $this->decode_found(
			wp_cache_get( $this->build_key( $key, $group ), $this->build_group_key( $group ) ),
			array(
				'key'   => $key,
				'group' => $group,
			)
		);
	}

	/**
	 * Read several entries from the backend with one call per object cache group.
	 *
	 * @param array<string, array{key: string, group: string}> $requests Entries to read, keyed by storage key.
	 * @return array<string, mixed> Values keyed by storage key.
	 */
	protected function read_entries( array $requests ): array {
		$by_group = array();

		foreach ( $requests as $storage_key => $request ) {
			$by_group[ $this->build_group_key( $request['group'] ) ][] = (string) $storage_key;
		}

		$results = array();

		foreach ( $by_group as $wp_group => $storage_keys ) {
			$found = wp_cache_get_multiple( $storage_keys, (string) $wp_group );

			foreach ( $storage_keys as $storage_key ) {
				$results[ $storage_key ] = $this->decode_found( $found[ $storage_key ] ?? false, $requests[ $storage_key ] );
			}
		}

		return $results;
	}

	/**
	 * Decode one payload the object cache returned.
	 *
	 * @param mixed                             $raw     Payload, or false on a miss.
	 * @param array{key: string, group: string} $request Entry that was read.
	 * @return mixed Decoded value, or CacheMiss::instance() on a miss or an undecodable payload.
	 */
	private function decode_found( mixed $raw, array $request ): mixed {
		if ( ! is_string( $raw ) ) {
			return CacheMiss::instance();
		}

		try {
			return PayloadCodec::decode( $raw );
		} catch ( Throwable $e ) {
			$this->logger?->warning(
				'Object cache payload could not be JSON decoded: ' . $e->getMessage(),
				array(
					'key'   => $request['key'],
					'group' => $request['group'],
				)
			);
			return CacheMiss::instance();
		}
	}

	/**
	 * Write one entry to the backend.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL in seconds.
	 * @param string $group Group name.
	 * @return bool
	 */
	protected function write_entry( string $key, mixed $value, int $ttl, string $group ): bool {
		try {
			$encoded = PayloadCodec::encode( $value );
		} catch ( InvalidArgumentException $e ) {
			$this->logger?->warning(
				'Object cache payload could not be JSON encoded: ' . $e->getMessage(),
				array(
					'key'   => $key,
					'group' => $group,
				)
			);
			return false;
		}

		return (bool) wp_cache_set(
			$this->build_key( $key, $group ),
			$encoded,
			$this->build_group_key( $group ),
			$this->resolve_ttl( $ttl, $group )
		);
	}

	/**
	 * Delete one entry from the backend.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return bool
	 */
	protected function delete_entry( string $key, string $group ): bool {
		return (bool) wp_cache_delete( $this->build_key( $key, $group ), $this->build_group_key( $group ) );
	}

	/**
	 * Store an encoded payload in the backend only when the key is absent.
	 *
	 * @param string $key     Cache key.
	 * @param string $encoded Encoded payload.
	 * @param int    $ttl     TTL in seconds.
	 * @param string $group   Group name.
	 * @return bool True when the payload was stored.
	 */
	protected function add_entry( string $key, string $encoded, int $ttl, string $group ): bool {
		return (bool) wp_cache_add( $this->build_key( $key, $group ), $encoded, $this->build_group_key( $group ), $ttl );
	}

	/**
	 * Flush all items in group.
	 *
	 * @param string $group Group name.
	 * @return bool
	 */
	public function flush_group( string $group = 'default' ): bool {
		$bumped = parent::flush_group( $group );

		if ( $this->supports_native_group_flush() ) {
			$flushed = (bool) wp_cache_flush_group( $this->build_group_key( $group ) );

			if ( ! $flushed ) {
				$this->logger?->debug(
					'wp_cache_flush_group reported failure; relying on the group version stamp',
					array( 'group' => $group )
				);
			}
		}

		return $bumped;
	}

	/**
	 * Write several encoded entries to the backend with one call per object cache group and TTL.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether each entry was stored, keyed by storage key.
	 */
	protected function write_entries( array $items ): array {
		return $this->write_by_group( $items, 'wp_cache_set_multiple' );
	}

	/**
	 * Store several encoded payloads with one call per object cache group and TTL, each only when its key is absent.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether this call stored each entry, keyed by storage key.
	 */
	protected function add_entries( array $items ): array {
		return $this->write_by_group( $items, 'wp_cache_add_multiple' );
	}

	/**
	 * Hand entries to an object cache multi-write, one call per object cache group and TTL.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items    Entries keyed by storage key.
	 * @param callable                                                       $writer   Object cache function taking data, group and expiration.
	 * @return array<string, bool> Whether each entry was stored, keyed by storage key.
	 */
	private function write_by_group( array $items, callable $writer ): array {
		$batches = array();

		foreach ( $items as $storage_key => $item ) {
			$wp_group = $this->build_group_key( $item['group'] );

			$batches[ $wp_group ][ $item['ttl'] ][ (string) $storage_key ] = $item['payload'];
		}

		$results = array();

		foreach ( $batches as $wp_group => $by_ttl ) {
			foreach ( $by_ttl as $ttl => $data ) {
				$answered = $writer( $data, (string) $wp_group, (int) $ttl );

				foreach ( array_keys( $data ) as $storage_key ) {
					$results[ $storage_key ] = is_array( $answered ) && true === ( $answered[ $storage_key ] ?? false );
				}
			}
		}

		return $results;
	}

	/**
	 * Check whether the active drop-in really implements group flushing.
	 *
	 * @return bool
	 */
	private function supports_native_group_flush(): bool {
		return function_exists( 'wp_cache_supports' )
			&& function_exists( 'wp_cache_flush_group' )
			&& (bool) wp_cache_supports( 'flush_group' );
	}
}
