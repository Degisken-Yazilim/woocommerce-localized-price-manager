<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Adapters;

use Closure;
use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\CacheGroupEnum;
use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\Cache\Support\PayloadCodec;
use D6N\LPM\Modules\Cache\Support\SiteKey;
use D6N\LPM\Modules\Cache\ValueObjects\CacheMiss;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for cache adapters.
 */
abstract class AbstractCacheAdapter implements ICache {

	/**
	 * TTL applied when the group is unknown and no explicit TTL is given.
	 */
	protected const DEFAULT_TTL = 3600;

	/**
	 * Default lock lifetime in seconds.
	 */
	private const DEFAULT_LOCK_TIMEOUT = 30;

	/**
	 * Option name prefix used by the `add_option()` based lock.
	 */
	private const LOCK_OPTION_PREFIX = 'd6n_lpm_cache_lock_';

	/**
	 * Object cache group used for locks when a persistent object cache exists.
	 */
	private const LOCK_GROUP = 'locks';

	/**
	 * Most keys sent to the backend in one bulk read.
	 */
	private const BULK_READ_CHUNK = 500;

	/**
	 * Most entries sent to the backend in one bulk write.
	 */
	private const BULK_WRITE_CHUNK = 500;

	/**
	 * Key namespace shared by every entry this adapter writes.
	 *
	 * @var string
	 */
	protected string $prefix;

	/**
	 * Per-request memo of resolved group version counters.
	 *
	 * @var array<string, int>
	 */
	private array $group_versions = array();

	/**
	 * Per-request memo of entries known to the backend, encoded, keyed by storage key.
	 *
	 * @var array<string, string>
	 */
	private array $entries = array();

	/**
	 * Per-request memo of storage keys a bulk read found absent from the backend.
	 *
	 * @var array<string, true>
	 */
	private array $known_misses = array();

	/**
	 * Encoded entries awaiting the end-of-request bulk write, keyed by frozen storage key.
	 *
	 * @var array<string, array{group: string, payload: string, ttl: int}>
	 */
	private array $deferred = array();

	/**
	 * Reason the driver could not connect, or null when it never tried or succeeded.
	 *
	 * @var string|null
	 */
	protected ?string $connection_error = null;

	/**
	 * Listener told about the first driver failure of the request.
	 *
	 * @var Closure|null
	 */
	private ?Closure $runtime_failure_listener = null;

	/**
	 * Whether the runtime failure listener already ran this request.
	 *
	 * @var bool
	 */
	private bool $runtime_failure_reported = false;

	/**
	 * Constructor.
	 *
	 * @param ILogger|null $logger Logger instance.
	 */
	public function __construct(
		protected ?ILogger $logger = null
	) {
		$this->prefix = Core::get_hook_prefix() . 'cache:' . SiteKey::current() . ':site_' . get_current_blog_id();
	}

	/**
	 * Check if cache adapter is available on this environment.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Whether entries are held in a memory store rather than in the database.
	 *
	 * @return bool
	 */
	public function is_memory_backed(): bool {
		return true;
	}

	/**
	 * Check whether the environment asks for this driver at all.
	 *
	 * @return bool True when the driver should be probed.
	 */
	public static function is_configured(): bool {
		return true;
	}

	/**
	 * Get the reason the driver could not connect.
	 *
	 * @return string|null Failure reason, or null when the driver never tried or connected.
	 */
	public function get_connection_error(): ?string {
		return $this->connection_error;
	}

	/**
	 * Register the listener told about the first driver failure of the request.
	 *
	 * @param Closure $listener Listener taking no arguments.
	 * @return void
	 */
	public function on_runtime_failure( Closure $listener ): void {
		$this->runtime_failure_listener = $listener;
	}

	/**
	 * Notify the runtime failure listener once per request.
	 *
	 * @return void
	 */
	protected function report_runtime_failure(): void {
		if ( $this->runtime_failure_reported || null === $this->runtime_failure_listener ) {
			return;
		}

		$this->runtime_failure_reported = true;
		( $this->runtime_failure_listener )();
	}

	/**
	 * Read the first configuration constant holding a non-empty string.
	 *
	 * @param string ...$names Constant names, in order of precedence.
	 * @return string|null Constant value, or null when none is usable.
	 */
	protected static function read_string_constant( string ...$names ): ?string {
		foreach ( $names as $name ) {
			if ( ! defined( $name ) ) {
				continue;
			}

			$value = constant( $name );

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Read the first configuration constant holding a port number.
	 *
	 * @param string ...$names Constant names, in order of precedence.
	 * @return int|null Port number, or null when none is usable.
	 */
	protected static function read_port_constant( string ...$names ): ?int {
		foreach ( $names as $name ) {
			if ( ! defined( $name ) ) {
				continue;
			}

			$value = constant( $name );

			if ( is_string( $value ) && ctype_digit( $value ) ) {
				$value = (int) $value;
			}

			if ( is_int( $value ) && $value > 0 ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Read the first configuration constant holding a number.
	 *
	 * @param string ...$names Constant names, in order of precedence.
	 * @return int|float|null Constant value, or null when none is numeric.
	 */
	protected static function read_numeric_constant( string ...$names ): int|float|null {
		foreach ( $names as $name ) {
			if ( ! defined( $name ) ) {
				continue;
			}

			$value = constant( $name );

			if ( is_int( $value ) || is_float( $value ) ) {
				return $value;
			}

			if ( is_string( $value ) && is_numeric( $value ) ) {
				return 0 + $value;
			}
		}

		return null;
	}

	/**
	 * Read the Redis credentials from `REDIS_PASSWORD` or `WP_REDIS_PASSWORD`.
	 *
	 * @return array{0: string|null, 1: string}|null Username and password, or null when none is set.
	 */
	protected static function read_redis_credentials(): ?array {
		foreach ( array( 'REDIS_PASSWORD', 'WP_REDIS_PASSWORD' ) as $name ) {
			if ( ! defined( $name ) ) {
				continue;
			}

			$value = constant( $name );

			if ( is_string( $value ) && '' !== $value ) {
				return array( null, $value );
			}

			if ( ! is_array( $value ) || 2 !== count( $value ) ) {
				continue;
			}

			list( $username, $password ) = array_values( $value );

			if ( is_string( $username ) && is_string( $password ) && '' !== $password ) {
				return array( '' === $username ? null : $username, $password );
			}
		}

		return null;
	}

	/**
	 * Get cached item value, answering a repeated read from the request memo.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return mixed Cached value, or CacheMiss::instance() on a miss.
	 */
	public function get( string $key, string $group = 'default' ): mixed {
		return $this->fetch( $key, $group, true );
	}

	/**
	 * Get several cached items of several groups, one backend round trip per chunk.
	 *
	 * @param array<string, array<int, string>> $keys_by_group Cache keys keyed by group name.
	 * @return array<string, array<string, mixed>> Values keyed by group name, then cache key; CacheMiss::instance() on a miss.
	 */
	public function get_multiple( array $keys_by_group ): array {
		$this->resolve_versions( array_map( 'strval', array_keys( $keys_by_group ) ) );

		$results  = array();
		$requests = array();
		$slots    = array();

		foreach ( $keys_by_group as $group => $keys ) {
			$group = (string) $group;

			if ( ! $this->is_resolved( $group ) ) {
				foreach ( $keys as $key ) {
					$results[ $group ][ (string) $key ] = CacheMiss::instance();
				}

				continue;
			}

			foreach ( $keys as $key ) {
				$key         = (string) $key;
				$storage_key = $this->build_key( $key, $group );

				$results[ $group ][ $key ] = CacheMiss::instance();

				if ( isset( $this->entries[ $storage_key ] ) ) {
					$results[ $group ][ $key ] = PayloadCodec::decode( $this->entries[ $storage_key ] );
					continue;
				}

				if ( isset( $this->known_misses[ $storage_key ] ) ) {
					continue;
				}

				$requests[ $storage_key ] = array(
					'key'   => $key,
					'group' => $group,
				);
				$slots[ $storage_key ][]  = array( $group, $key );
			}
		}

		$read = $this->read_chunked( $requests );

		foreach ( $slots as $storage_key => $targets ) {
			if ( ! array_key_exists( $storage_key, $read ) ) {
				continue;
			}

			$value = $read[ $storage_key ];

			if ( CacheMiss::instance() === $value ) {
				$this->known_misses[ $storage_key ] = true;
				continue;
			}

			$this->remember_entry( $storage_key, $value );

			foreach ( $targets as list( $group, $key ) ) {
				$results[ $group ][ $key ] = $value;
			}
		}

		return $results;
	}

	/**
	 * Read one entry, answering from the request memo before the backend.
	 *
	 * @param string $key                Cache key.
	 * @param string $group              Group name.
	 * @param bool   $trust_known_misses Whether a miss a bulk read found answers without the backend.
	 * @return mixed Cached value, or CacheMiss::instance() on a miss.
	 */
	private function fetch( string $key, string $group, bool $trust_known_misses ): mixed {
		$storage_key = $this->build_key( $key, $group );

		if ( isset( $this->entries[ $storage_key ] ) ) {
			return PayloadCodec::decode( $this->entries[ $storage_key ] );
		}

		if ( $trust_known_misses && isset( $this->known_misses[ $storage_key ] ) ) {
			return CacheMiss::instance();
		}

		$value = $this->read_entry( $key, $group );

		if ( CacheMiss::instance() !== $value ) {
			$this->remember_entry( $storage_key, $value );
		}

		return $value;
	}

	/**
	 * Read many entries from the backend in chunks.
	 *
	 * @param array<string, array{key: string, group: string}> $requests Entries to read, keyed by storage key.
	 * @return array<string, mixed> Values keyed by storage key; keys the backend did not answer are absent.
	 */
	private function read_chunked( array $requests ): array {
		$read = array();

		foreach ( array_chunk( $requests, self::BULK_READ_CHUNK, true ) as $chunk ) {
			$read = array_replace( $read, $this->read_entries( $chunk ) );
		}

		return $read;
	}

	/**
	 * Resolve the version counters of several groups with one backend read.
	 *
	 * @param array<int, string> $groups Group names.
	 * @return void
	 */
	private function resolve_versions( array $groups ): void {
		$requests = array();
		$paths    = array();

		foreach ( $groups as $group ) {
			if ( $this->is_versioning_group( $group ) ) {
				continue;
			}

			$path = '';

			foreach ( explode( ':', $group ) as $segment ) {
				$path = ( '' === $path ) ? $segment : $path . ':' . $segment;

				if ( isset( $this->group_versions[ $path ] ) ) {
					continue;
				}

				$version_key = $this->build_version_key( $path );
				$storage_key = $this->build_key( $version_key, CacheGroupEnum::Versioning->value );

				$requests[ $storage_key ] = array(
					'key'   => $version_key,
					'group' => CacheGroupEnum::Versioning->value,
				);
				$paths[ $storage_key ]    = $path;
			}
		}

		if ( array() === $requests ) {
			return;
		}

		$read   = $this->read_chunked( $requests );
		$one    = PayloadCodec::encode( 1 );
		$absent = array();

		foreach ( $requests as $storage_key => $request ) {
			if ( ! array_key_exists( $storage_key, $read ) ) {
				continue;
			}

			$stored = $read[ $storage_key ];

			if ( CacheMiss::instance() === $stored ) {
				unset( $this->known_misses[ $storage_key ] );

				$absent[ $storage_key ] = array(
					'group'   => CacheGroupEnum::Versioning->value,
					'payload' => $one,
					'ttl'     => $this->resolve_ttl( CacheGroupEnum::Versioning->ttl(), CacheGroupEnum::Versioning->value ),
				);
			}

			$this->group_versions[ $paths[ $storage_key ] ] = is_numeric( $stored ) ? max( 1, (int) $stored ) : 1;
		}

		foreach ( $this->add_many( $absent ) as $storage_key => $added ) {
			if ( $added ) {
				$this->entries[ $storage_key ] = $one;
			}
		}
	}

	/**
	 * Whether every version counter on the path of a group is known to this request.
	 *
	 * @param string $group Group name.
	 * @return bool
	 */
	private function is_resolved( string $group ): bool {
		if ( $this->is_versioning_group( $group ) ) {
			return true;
		}

		$path = '';

		foreach ( explode( ':', $group ) as $segment ) {
			$path = ( '' === $path ) ? $segment : $path . ':' . $segment;

			if ( ! isset( $this->group_versions[ $path ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Set cached item value and keep it in the request memo.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL in seconds.
	 * @param string $group Group name.
	 * @return bool
	 */
	public function set( string $key, mixed $value, int $ttl, string $group = 'default' ): bool {
		$storage_key = $this->build_key( $key, $group );

		unset( $this->entries[ $storage_key ], $this->known_misses[ $storage_key ], $this->deferred[ $storage_key ] );

		if ( ! $this->write_entry( $key, $value, $ttl, $group ) ) {
			return false;
		}

		$this->remember_entry( $storage_key, $value );

		return true;
	}

	/**
	 * Queue a value for the end-of-request bulk write and keep it in the request memo.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL in seconds.
	 * @param string $group Group name.
	 * @return void
	 */
	public function set_deferred( string $key, mixed $value, int $ttl, string $group = 'default' ): void {
		$storage_key = $this->build_key( $key, $group );

		unset( $this->entries[ $storage_key ], $this->known_misses[ $storage_key ], $this->deferred[ $storage_key ] );

		try {
			$payload = PayloadCodec::encode( $value );
		} catch ( InvalidArgumentException $e ) {
			$this->logger?->warning(
				'Cache payload could not be JSON encoded: ' . $e->getMessage(),
				array(
					'key'   => $key,
					'group' => $group,
				)
			);

			return;
		}

		$this->deferred[ $storage_key ] = array(
			'group'   => $group,
			'payload' => $payload,
			'ttl'     => $this->resolve_ttl( $ttl, $group ),
		);
		$this->entries[ $storage_key ]  = $payload;
	}

	/**
	 * Write every queued entry in one bulk write.
	 *
	 * @return void
	 */
	public function flush_deferred(): void {
		if ( array() === $this->deferred ) {
			return;
		}

		$items          = $this->deferred;
		$this->deferred = array();

		$this->write_many( $items );
	}

	/**
	 * Delete cached item and drop it from the request memo.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return bool
	 */
	public function delete( string $key, string $group = 'default' ): bool {
		$storage_key = $this->build_key( $key, $group );

		unset( $this->entries[ $storage_key ], $this->known_misses[ $storage_key ], $this->deferred[ $storage_key ] );

		return $this->delete_entry( $key, $group );
	}

	/**
	 * Set the values of several groups without keeping them in the request memo.
	 *
	 * @param array<string, array<string, mixed>> $data_by_group Key-value pairs keyed by group name.
	 * @param int                                 $ttl           TTL in seconds.
	 * @return bool True when every value was stored.
	 */
	public function set_multiple( array $data_by_group, int $ttl ): bool {
		$this->resolve_versions( array_map( 'strval', array_keys( $data_by_group ) ) );

		$items    = array();
		$complete = true;

		foreach ( $data_by_group as $group => $data ) {
			$group = (string) $group;

			if ( ! $this->is_resolved( $group ) ) {
				$complete = false;

				continue;
			}

			foreach ( $data as $key => $value ) {
				$storage_key = $this->build_key( (string) $key, $group );

				unset( $this->entries[ $storage_key ], $this->known_misses[ $storage_key ], $this->deferred[ $storage_key ] );

				try {
					$items[ $storage_key ] = array(
						'group'   => $group,
						'payload' => PayloadCodec::encode( $value ),
						'ttl'     => $this->resolve_ttl( $ttl, $group ),
					);
				} catch ( InvalidArgumentException $e ) {
					$complete = false;

					$this->logger?->warning(
						'Cache payload could not be JSON encoded: ' . $e->getMessage(),
						array(
							'key'   => (string) $key,
							'group' => $group,
						)
					);
				}
			}
		}

		$stored = ! in_array( false, $this->write_many( $items ), true );

		return $complete && $stored;
	}

	/**
	 * Write many encoded entries to the backend in chunks.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether each entry was stored, keyed by storage key.
	 */
	protected function write_many( array $items ): array {
		return $this->in_write_chunks( $items, fn ( array $chunk ): array => $this->write_entries( $chunk ) );
	}

	/**
	 * Add many encoded entries to the backend in chunks, each only when its key is absent.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether this call stored each entry, keyed by storage key.
	 */
	private function add_many( array $items ): array {
		return $this->in_write_chunks( $items, fn ( array $chunk ): array => $this->add_entries( $chunk ) );
	}

	/**
	 * Hand entries to a driver write in chunks and normalise its answers.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @param Closure                                                        $write Driver write taking one chunk.
	 * @return array<string, bool> Whether each entry was stored; an entry the driver did not answer is false.
	 */
	private function in_write_chunks( array $items, Closure $write ): array {
		$results = array();

		foreach ( array_chunk( $items, self::BULK_WRITE_CHUNK, true ) as $chunk ) {
			$answered = $write( $chunk );

			foreach ( array_keys( $chunk ) as $storage_key ) {
				$results[ $storage_key ] = true === ( $answered[ $storage_key ] ?? false );
			}
		}

		return $results;
	}

	/**
	 * Store a value the backend holds in the request memo.
	 *
	 * @param string $storage_key Fully built, version-stamped key.
	 * @param mixed  $value       Value the backend holds.
	 * @return void
	 */
	private function remember_entry( string $storage_key, mixed $value ): void {
		unset( $this->known_misses[ $storage_key ] );

		try {
			$this->entries[ $storage_key ] = PayloadCodec::encode( $value );
		} catch ( InvalidArgumentException ) {
			unset( $this->entries[ $storage_key ] );
		}
	}

	/**
	 * Drop the per-request entry memo.
	 *
	 * @return void
	 */
	protected function reset_entry_memo(): void {
		$this->entries      = array();
		$this->known_misses = array();
		$this->deferred     = array();
	}

	/**
	 * Flush every plugin cache group by bumping its version stamp.
	 *
	 * @return bool True when every group counter was advanced.
	 */
	public function flush_all(): bool {
		$this->reset_version_memo();
		$this->reset_entry_memo();

		$flushed = true;

		foreach ( $this->get_plugin_groups() as $group ) {
			if ( ! $this->flush_group( $group ) ) {
				$flushed = false;
			}
		}

		return $flushed;
	}

	/**
	 * Advance the version counter of every plugin cache group.
	 *
	 * @return bool True when every counter was persisted.
	 */
	protected function bump_all_group_versions(): bool {
		$this->reset_version_memo();
		$this->reset_entry_memo();

		$bumped = true;

		foreach ( $this->get_plugin_groups() as $group ) {
			if ( ! $this->bump_group_version( $group ) ) {
				$bumped = false;
			}
		}

		return $bumped;
	}

	/**
	 * List the top-level groups holding cached entries.
	 *
	 * @return array<int, string> Group names, without the versioning group.
	 */
	protected function get_plugin_groups(): array {
		$groups = array();

		foreach ( CacheGroupEnum::cases() as $case ) {
			if ( ! $this->is_versioning_group( $case->value ) ) {
				$groups[] = $case->value;
			}
		}

		return $groups;
	}

	/**
	 * Flush all items in group by bumping its version stamp.
	 *
	 * @param string $group Group name.
	 * @return bool True when the version counter was advanced.
	 */
	public function flush_group( string $group = 'default' ): bool {
		return $this->bump_group_version( $group );
	}

	/**
	 * Build cache group key.
	 *
	 * @param string $group Group name.
	 * @return string
	 */
	protected function build_group_key( string $group = 'default' ): string {
		$group = $this->normalize_key( $group );
		return "{$this->prefix}:group:{$group}";
	}

	/**
	 * Build full, version-stamped cache key.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return string
	 */
	protected function build_key( string $key, string $group ): string {
		$group_key = $this->build_group_key( $group );

		if ( $this->is_versioning_group( $group ) ) {
			return $group_key . ':' . $this->normalize_key( $key );
		}

		return $group_key . ':v' . $this->get_version_stamp( $group ) . ':' . $this->normalize_key( $key );
	}

	/**
	 * Normalize cache key to safe string format.
	 *
	 * @param string $key Raw key string.
	 * @return string
	 */
	protected function normalize_key( string $key ): string {
		return (string) preg_replace_callback(
			'/[^a-zA-Z0-9\-_.]/',
			static fn ( $matches ) => rawurlencode( $matches[0] ),
			$key
		);
	}

	/**
	 * Check whether a group holds the version counters themselves.
	 *
	 * @param string $group Group name.
	 * @return bool
	 */
	protected function is_versioning_group( string $group ): bool {
		return CacheGroupEnum::Versioning->value === $group;
	}

	/**
	 * Build the composite version stamp for a hierarchical group.
	 *
	 * @param string $group Group name.
	 * @return string Dot separated version stamp.
	 */
	protected function get_version_stamp( string $group ): string {
		$path  = '';
		$parts = array();

		foreach ( explode( ':', $group ) as $segment ) {
			$path    = ( '' === $path ) ? $segment : $path . ':' . $segment;
			$parts[] = (string) $this->get_group_version( $path );
		}

		return implode( '.', $parts );
	}

	/**
	 * Read the current version counter for a single group path.
	 *
	 * @param string $group Group path.
	 * @return int Version number, always at least 1.
	 */
	protected function get_group_version( string $group ): int {
		if ( isset( $this->group_versions[ $group ] ) ) {
			return $this->group_versions[ $group ];
		}

		$version_key = $this->build_version_key( $group );
		$stored      = $this->get( $version_key, CacheGroupEnum::Versioning->value );

		if ( CacheMiss::instance() === $stored ) {
			$this->add( $version_key, 1, CacheGroupEnum::Versioning->ttl(), CacheGroupEnum::Versioning->value );
		}

		$version = is_numeric( $stored ) ? max( 1, (int) $stored ) : 1;

		$this->group_versions[ $group ] = $version;

		return $version;
	}

	/**
	 * Store a value only when the key is absent, keeping it in the request memo.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL in seconds.
	 * @param string $group Group name.
	 * @return bool True when this call stored the value.
	 */
	protected function add( string $key, mixed $value, int $ttl, string $group ): bool {
		try {
			$encoded = PayloadCodec::encode( $value );
		} catch ( InvalidArgumentException ) {
			return false;
		}

		$storage_key = $this->build_key( $key, $group );

		unset( $this->known_misses[ $storage_key ] );

		if ( ! $this->add_entry( $key, $encoded, $this->resolve_ttl( $ttl, $group ), $group ) ) {
			return false;
		}

		$this->entries[ $storage_key ] = $encoded;

		return true;
	}

	/**
	 * Store an encoded payload in the backend only when the key is absent.
	 *
	 * @param string $key     Cache key.
	 * @param string $encoded Encoded payload.
	 * @param int    $ttl     TTL in seconds.
	 * @param string $group   Group name.
	 * @return bool True when the payload was stored; false when the key existed, the write failed or the driver has no atomic add.
	 */
	protected function add_entry( string $key, string $encoded, int $ttl, string $group ): bool {
		unset( $key, $encoded, $ttl, $group );

		return false;
	}

	/**
	 * Store several encoded payloads in the backend, each only when its key is absent.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether this call stored each entry; all false when the driver has no atomic add.
	 */
	protected function add_entries( array $items ): array {
		return array_fill_keys( array_keys( $items ), false );
	}

	/**
	 * Advance the version counter of a group, invalidating every stamped key.
	 *
	 * @param string $group Group path.
	 * @return bool True when the new counter was persisted.
	 */
	protected function bump_group_version( string $group ): bool {
		$next = $this->get_group_version( $group ) + 1;

		$this->reset_entry_memo();

		$stored = $this->set(
			$this->build_version_key( $group ),
			$next,
			CacheGroupEnum::Versioning->ttl(),
			CacheGroupEnum::Versioning->value
		);

		$this->reset_version_memo();
		$this->group_versions[ $group ] = $next;

		return $stored;
	}

	/**
	 * Drop the per-request version memo.
	 *
	 * @return void
	 */
	protected function reset_version_memo(): void {
		$this->group_versions = array();
	}

	/**
	 * Build the storage key of a group version counter.
	 *
	 * @param string $group Group path.
	 * @return string
	 */
	protected function build_version_key( string $group ): string {
		return 'version_' . $this->normalize_key( $group );
	}

	/**
	 * Resolve the effective TTL for a write.
	 *
	 * @param int|null $ttl   Explicit TTL, or null to use the group default.
	 * @param string   $group Group name, possibly a colon separated sub-group.
	 * @return int TTL in seconds.
	 */
	protected function resolve_ttl( ?int $ttl, string $group ): int {
		if ( null !== $ttl && $ttl > 0 ) {
			return $ttl;
		}

		$root = explode( ':', $group )[0];
		$case = CacheGroupEnum::tryFrom( $root );

		return null !== $case ? $case->ttl() : static::DEFAULT_TTL;
	}

	/**
	 * Get value or compute callback if missed with stampede protection.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $callback Fallback callback.
	 * @param int|null $ttl      TTL in seconds, or null for the group default.
	 * @param string   $group    Group name.
	 * @return mixed
	 */
	public function remember( string $key, callable $callback, ?int $ttl = null, string $group = 'default' ): mixed {
		$value = $this->fetch( $key, $group, false );

		if ( CacheMiss::instance() !== $value ) {
			return $value;
		}

		$ttl      = $this->resolve_ttl( $ttl, $group );
		$lock_key = $this->build_lock_key( $key, $group );

		if ( $this->acquire_lock( $lock_key ) ) {
			try {
				$value = $this->fetch( $key, $group, false );

				if ( CacheMiss::instance() !== $value ) {
					return $value;
				}

				$value = $callback();
				$this->set( $key, $value, $ttl, $group );

				return $value;
			} finally {
				$this->release_lock( $lock_key );
			}
		}

		usleep( random_int( 50000, 200000 ) );
		$value = $this->fetch( $key, $group, false );

		if ( CacheMiss::instance() !== $value ) {
			return $value;
		}

		$value = $callback();
		$this->set( $key, $value, $ttl, $group );

		return $value;
	}

	/**
	 * Build the lock key guarding a cache entry.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return string
	 */
	protected function build_lock_key( string $key, string $group ): string {
		return $this->build_key( $key, $group ) . ':lock';
	}

	/**
	 * Resolve the lock lifetime in seconds.
	 *
	 * @return int
	 */
	protected function get_lock_timeout(): int {
		/**
		 * Filters how long a cache stampede lock is held before it is stale.
		 *
		 * @param int $timeout Lock lifetime in seconds.
		 */
		$timeout = (int) apply_filters( Core::build_hook( 'cache_lock_timeout' ), self::DEFAULT_LOCK_TIMEOUT );

		return $timeout > 0 ? $timeout : self::DEFAULT_LOCK_TIMEOUT;
	}

	/**
	 * Acquire an exclusive lock using an atomic "create only if absent" primitive.
	 *
	 * @param string $lock_key Lock key.
	 * @return bool True when this process owns the lock.
	 */
	protected function acquire_lock( string $lock_key ): bool {
		$timeout = $this->get_lock_timeout();

		$acquired = $this->has_external_object_cache()
			? (bool) wp_cache_add( $this->normalize_key( $lock_key ), time() + $timeout, $this->build_group_key( self::LOCK_GROUP ), $timeout )
			: $this->acquire_option_lock( $lock_key, $timeout );

		if ( ! $acquired ) {
			$this->logger?->cache( 'Cache lock contention', array( 'lock_key' => $lock_key ), 'Cache', LogLevelEnum::Debug );
		}

		return $acquired;
	}

	/**
	 * Release a previously acquired lock.
	 *
	 * @param string $lock_key Lock key.
	 * @return void
	 */
	protected function release_lock( string $lock_key ): void {
		if ( $this->has_external_object_cache() ) {
			wp_cache_delete( $this->normalize_key( $lock_key ), $this->build_group_key( self::LOCK_GROUP ) );
			return;
		}

		delete_option( $this->build_lock_option_name( $lock_key ) );
	}

	/**
	 * Acquire a lock through an atomic, non-autoloaded option row insert.
	 *
	 * @param string $lock_key Lock key.
	 * @param int    $timeout  Lock lifetime in seconds.
	 * @return bool True when this process owns the lock.
	 */
	private function acquire_option_lock( string $lock_key, int $timeout ): bool {
		$option_name = $this->build_lock_option_name( $lock_key );
		$expires_at  = time() + $timeout;

		if ( add_option( $option_name, $expires_at, '', false ) ) {
			return true;
		}

		$existing = get_option( $option_name );

		if ( is_numeric( $existing ) && (int) $existing > time() ) {
			return false;
		}

		delete_option( $option_name );

		return (bool) add_option( $option_name, $expires_at, '', false );
	}

	/**
	 * Build the option name backing a lock.
	 *
	 * @param string $lock_key Lock key.
	 * @return string
	 */
	private function build_lock_option_name( string $lock_key ): string {
		return self::LOCK_OPTION_PREFIX . md5( $lock_key );
	}

	/**
	 * Check whether a persistent object cache drop-in is active.
	 *
	 * @return bool
	 */
	protected function has_external_object_cache(): bool {
		return (bool) wp_using_ext_object_cache();
	}

	/**
	 * Read one entry from the backend.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return mixed Cached value, or CacheMiss::instance() on a miss.
	 */
	abstract protected function read_entry( string $key, string $group ): mixed;

	/**
	 * Read several entries from the backend.
	 *
	 * @param array<string, array{key: string, group: string}> $requests Entries to read, keyed by storage key.
	 * @return array<string, mixed> Values keyed by storage key, CacheMiss::instance() for an entry the backend lacks; a key the backend did not answer is absent.
	 */
	protected function read_entries( array $requests ): array {
		$results = array();

		foreach ( $requests as $storage_key => $request ) {
			$results[ $storage_key ] = $this->read_entry( $request['key'], $request['group'] );
		}

		return $results;
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
	abstract protected function write_entry( string $key, mixed $value, int $ttl, string $group ): bool;

	/**
	 * Delete one entry from the backend.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return bool
	 */
	abstract protected function delete_entry( string $key, string $group ): bool;

	/**
	 * Write several encoded entries to the backend.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether each entry was stored, keyed by storage key.
	 */
	abstract protected function write_entries( array $items ): array;
}
