<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for cache operations.
 */
interface ICache {
	/**
	 * Get a value from a cache.
	 *
	 * @param string $key   The cache key.
	 * @param string $group The cache group.
	 * @return mixed The cached value, or CacheMiss::instance() when the key is absent.
	 */
	public function get( string $key, string $group = 'default' ): mixed;

	/**
	 * Get several values of several groups.
	 *
	 * @param array<string, array<int, string>> $keys_by_group Cache keys keyed by group name.
	 * @return array<string, array<string, mixed>> Values keyed by group name, then cache key; CacheMiss::instance() when a key is absent.
	 */
	public function get_multiple( array $keys_by_group ): array;

	/**
	 * Set a value in the cache.
	 *
	 * @param string $key   The cache key.
	 * @param mixed  $value The value to cache.
	 * @param int    $ttl   Time-to-live in seconds.
	 * @param string $group The cache group.
	 * @return bool True on success, false on failure.
	 */
	public function set( string $key, mixed $value, int $ttl, string $group = 'default' ): bool;

	/**
	 * Queue a value to be written at the end of the request.
	 *
	 * @param string $key   The cache key.
	 * @param mixed  $value The value to cache.
	 * @param int    $ttl   Time-to-live in seconds.
	 * @param string $group The cache group.
	 * @return void
	 */
	public function set_deferred( string $key, mixed $value, int $ttl, string $group = 'default' ): void;

	/**
	 * Write every queued value to the cache.
	 *
	 * @return void
	 */
	public function flush_deferred(): void;

	/**
	 * Delete a value from the cache.
	 *
	 * @param string $key   The cache key.
	 * @param string $group The cache group.
	 * @return bool True on success, false on failure.
	 */
	public function delete( string $key, string $group = 'default' ): bool;

	/**
	 * Flush all values in a cache group.
	 *
	 * @param string $group The cache group.
	 * @return bool True on success, false on failure.
	 */
	public function flush_group( string $group = 'default' ): bool;

	/**
	 * Flush all cache values.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function flush_all(): bool;

	/**
	 * Set the values of several groups.
	 *
	 * @param array<string, array<string, mixed>> $data_by_group Key-value pairs keyed by group name.
	 * @param int                                 $ttl           Time-to-live in seconds.
	 * @return bool True when every value was stored, false otherwise.
	 */
	public function set_multiple( array $data_by_group, int $ttl ): bool;

	/**
	 * Check if a cache adapter is available.
	 *
	 * @return bool True if available, false otherwise.
	 */
	public function is_available(): bool;

	/**
	 * Get value from cache or execute callback and cache result.
	 *
	 * @param string   $key      The cache key.
	 * @param callable $callback The callback to execute if the cache misses.
	 * @param int|null $ttl      Time-to-live in seconds, or null for the group default.
	 * @param string   $group    The cache group.
	 * @return mixed The cached or computed value.
	 */
	public function remember( string $key, callable $callback, ?int $ttl = null, string $group = 'default' ): mixed;
}
