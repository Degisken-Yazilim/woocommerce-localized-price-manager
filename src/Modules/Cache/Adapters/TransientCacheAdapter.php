<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Adapters;

use D6N\LPM\Modules\Cache\Support\PayloadCodec;
use D6N\LPM\Modules\Cache\ValueObjects\CacheMiss;
use InvalidArgumentException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress Transients cache adapter fallback.
 */
class TransientCacheAdapter extends AbstractCacheAdapter {

	/**
	 * Whether entries are held in a memory store rather than in the database.
	 *
	 * @return bool
	 */
	public function is_memory_backed(): bool {
		return false;
	}

	/**
	 * Read one entry from the backend.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return mixed Cached value, or CacheMiss::instance() on a miss.
	 */
	protected function read_entry( string $key, string $group ): mixed {
		$raw = get_transient( $this->build_key( $key, $group ) );

		if ( ! is_string( $raw ) ) {
			return CacheMiss::instance();
		}

		try {
			return PayloadCodec::decode( $raw );
		} catch ( Throwable $e ) {
			$this->logger?->warning(
				'Transient payload could not be JSON decoded: ' . $e->getMessage(),
				array(
					'key'   => $key,
					'group' => $group,
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
				'Transient payload could not be JSON encoded: ' . $e->getMessage(),
				array(
					'key'   => $key,
					'group' => $group,
				)
			);
			return false;
		}

		$result = set_transient( $this->build_key( $key, $group ), $encoded, $this->resolve_ttl( $ttl, $group ) );
		if ( false === $result ) {
			$this->logger?->warning(
				'set_transient failed',
				array(
					'key'   => $key,
					'group' => $group,
				)
			);
		}
		return (bool) $result;
	}

	/**
	 * Delete one entry from the backend.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return bool
	 */
	protected function delete_entry( string $key, string $group ): bool {
		if ( false !== delete_transient( $this->build_key( $key, $group ) ) ) {
			return true;
		}

		if ( CacheMiss::instance() === $this->read_entry( $key, $group ) ) {
			return true;
		}

		$this->logger?->warning(
			'delete_transient left the entry in place',
			array(
				'key'   => $key,
				'group' => $group,
			)
		);

		return false;
	}

	/**
	 * Flush all items in group.
	 *
	 * @param string $group Group name.
	 * @return bool
	 */
	public function flush_group( string $group = 'default' ): bool {
		global $wpdb;

		$bumped = parent::flush_group( $group );

		$group_key         = $wpdb->esc_like( $this->build_group_key( $group ) );
		$transient_pattern = '_transient_' . $group_key . '%';
		$timeout_pattern   = '_transient_timeout_' . $group_key . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$transient_pattern,
				$timeout_pattern
			)
		);

		if ( false === $result ) {
			$this->logger?->error( 'Transient flush_group query failed', array( 'group' => $group ) );
			return false;
		}

		return $bumped;
	}

	/**
	 * Delete every plugin transient, then advance every group counter.
	 *
	 * @return bool
	 */
	public function flush_all(): bool {
		global $wpdb;

		$prefix            = $wpdb->esc_like( $this->prefix );
		$transient_pattern = '_transient_' . $prefix . '%';
		$timeout_pattern   = '_transient_timeout_' . $prefix . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$transient_pattern,
				$timeout_pattern
			)
		);

		$bumped = $this->bump_all_group_versions();

		if ( false === $result ) {
			$this->logger?->error( 'Transient flush_all query failed' );
			return false;
		}
		return $bumped;
	}

	/**
	 * Write several encoded entries to the backend one transient at a time.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether each entry was stored, keyed by storage key.
	 */
	protected function write_entries( array $items ): array {
		$results = array();
		$refused = 0;

		foreach ( $items as $storage_key => $item ) {
			$results[ $storage_key ] = (bool) set_transient( (string) $storage_key, $item['payload'], $item['ttl'] );

			if ( ! $results[ $storage_key ] ) {
				++$refused;
			}
		}

		if ( $refused > 0 ) {
			$this->logger?->warning( sprintf( 'set_transient failed for %d of %d entries', $refused, count( $items ) ) );
		}

		return $results;
	}
}
