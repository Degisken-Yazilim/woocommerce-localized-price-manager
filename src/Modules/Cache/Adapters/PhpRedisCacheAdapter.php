<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Adapters;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Modules\Cache\Support\PayloadCodec;
use D6N\LPM\Modules\Cache\ValueObjects\CacheMiss;
use InvalidArgumentException;
use Redis;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * PhpRedis cache adapter.
 */
class PhpRedisCacheAdapter extends AbstractCacheAdapter {

	/**
	 * Connected client, or null when the extension or server is unavailable.
	 *
	 * @var Redis|null
	 */
	private ?Redis $redis = null;

	/**
	 * Constructor.
	 *
	 * @param ILogger|null $logger Logger instance.
	 */
	public function __construct( ?ILogger $logger = null ) {
		parent::__construct( $logger );
		$this->connect();
	}

	/**
	 * Check if Redis is available and connected.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return null !== $this->redis;
	}

	/**
	 * Check whether a host constant asks for this driver and its client is installed.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return null !== self::read_string_constant( 'REDIS_HOST', 'WP_REDIS_HOST' ) && class_exists( Redis::class );
	}

	/**
	 * Read one entry from the backend.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return mixed Cached value, or CacheMiss::instance() on a miss.
	 */
	protected function read_entry( string $key, string $group ): mixed {
		if ( null === $this->redis ) {
			return CacheMiss::instance();
		}

		try {
			$raw = $this->redis->get( $this->build_key( $key, $group ) );
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Redis GET failed', $e, $key, $group );
			return CacheMiss::instance();
		}

		if ( false === $raw || null === $raw ) {
			return CacheMiss::instance();
		}

		return $this->decode( (string) $raw, $key, $group );
	}

	/**
	 * Read several entries from the backend with one MGET.
	 *
	 * @param array<string, array{key: string, group: string}> $requests Entries to read, keyed by storage key.
	 * @return array<string, mixed> Values keyed by storage key; empty when the backend did not answer.
	 */
	protected function read_entries( array $requests ): array {
		if ( null === $this->redis || array() === $requests ) {
			return array();
		}

		$storage_keys = array_map( 'strval', array_keys( $requests ) );

		try {
			$values = $this->redis->mget( $storage_keys );
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Redis MGET failed', $e, '', '' );
			return array();
		}

		if ( ! is_array( $values ) || count( $values ) !== count( $storage_keys ) ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Redis MGET answered an unexpected reply', null, '', '' );
			return array();
		}

		$values  = array_values( $values );
		$results = array();

		foreach ( $storage_keys as $index => $storage_key ) {
			$raw = $values[ $index ];

			$results[ $storage_key ] = ( false === $raw || null === $raw )
				? CacheMiss::instance()
				: $this->decode( (string) $raw, $requests[ $storage_key ]['key'], $requests[ $storage_key ]['group'] );
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
	protected function write_entry( string $key, mixed $value, int $ttl, string $group ): bool {
		if ( null === $this->redis ) {
			return false;
		}

		try {
			$encoded = PayloadCodec::encode( $value );
		} catch ( InvalidArgumentException $e ) {
			$this->log_failure( 'Redis payload could not be JSON encoded', $e, $key, $group );
			return false;
		}

		try {
			return (bool) $this->redis->setex( $this->build_key( $key, $group ), $this->resolve_ttl( $ttl, $group ), $encoded );
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Redis SETEX failed', $e, $key, $group );
			return false;
		}
	}

	/**
	 * Delete one entry from the backend.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return bool
	 */
	protected function delete_entry( string $key, string $group ): bool {
		if ( null === $this->redis ) {
			return false;
		}

		try {
			return $this->redis->del( $this->build_key( $key, $group ) ) > 0;
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Redis DEL failed', $e, $key, $group );
			return false;
		}
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
		if ( null === $this->redis ) {
			return false;
		}

		try {
			return true === $this->redis->set(
				$this->build_key( $key, $group ),
				$encoded,
				array(
					'nx',
					'ex' => $ttl,
				)
			);
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Redis SET NX failed', $e, $key, $group );
			return false;
		}
	}

	/**
	 * Flush all items in group.
	 *
	 * @param string $group Group name.
	 * @return bool
	 */
	public function flush_group( string $group = 'default' ): bool {
		$bumped = parent::flush_group( $group );

		$this->delete_by_pattern( $this->build_group_key( $group ) . '*' );

		return $bumped;
	}

	/**
	 * Write several encoded entries to the backend with one pipeline of SETEX.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether each entry was stored, keyed by storage key.
	 */
	protected function write_entries( array $items ): array {
		return $this->run_pipeline( $items, true );
	}

	/**
	 * Store several encoded payloads with one pipeline of SET NX.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether this call stored each entry, keyed by storage key.
	 */
	protected function add_entries( array $items ): array {
		return $this->run_pipeline( $items, false );
	}

	/**
	 * Send one pipeline of writes and read back a reply per entry.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items   Entries keyed by storage key.
	 * @param bool                                                           $replace True for SETEX, false for SET NX.
	 * @return array<string, bool> Whether each entry was stored, keyed by storage key.
	 */
	private function run_pipeline( array $items, bool $replace ): array {
		$failed  = array_fill_keys( array_keys( $items ), false );
		$command = $replace ? 'SETEX' : 'SET NX';

		if ( null === $this->redis || array() === $items ) {
			return $failed;
		}

		try {
			$pipeline = $this->redis->pipeline();

			foreach ( $items as $storage_key => $item ) {
				if ( $replace ) {
					$pipeline->setex( (string) $storage_key, $item['ttl'], $item['payload'] );
					continue;
				}

				$pipeline->set(
					(string) $storage_key,
					$item['payload'],
					array(
						'nx',
						'ex' => $item['ttl'],
					)
				);
			}

			$replies = $pipeline->exec();
		} catch ( Throwable $e ) {
			$this->discard_pipeline();
			$this->report_runtime_failure();
			$this->log_failure( 'Redis ' . $command . ' pipeline failed', $e, '', '' );
			return $failed;
		}

		if ( ! is_array( $replies ) || count( $replies ) !== count( $items ) ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Redis ' . $command . ' pipeline answered an unexpected reply', null, '', '' );
			return $failed;
		}

		$replies = array_values( $replies );
		$results = array();
		$refused = 0;
		$index   = 0;

		foreach ( array_keys( $items ) as $storage_key ) {
			$results[ $storage_key ] = true === $replies[ $index++ ];

			if ( $replace && ! $results[ $storage_key ] ) {
				++$refused;
			}
		}

		if ( $refused > 0 ) {
			$this->report_runtime_failure();
			$this->log_failure( sprintf( 'Redis %s pipeline refused %d of %d entries', $command, $refused, count( $items ) ), null, '', '' );
		}

		return $results;
	}

	/**
	 * Leave pipeline mode after a failed pipeline, ignoring any failure of the discard itself.
	 *
	 * @return void
	 */
	private function discard_pipeline(): void {
		try {
			$this->redis?->discard();
		} catch ( Throwable ) {
			return;
		}
	}

	/**
	 * Open the Redis connection, degrading to "unavailable" on any failure.
	 *
	 * @return void
	 */
	private function connect(): void {
		$host = self::read_string_constant( 'REDIS_HOST', 'WP_REDIS_HOST' );

		if ( null === $host || ! class_exists( Redis::class ) ) {
			return;
		}

		try {
			$redis = new Redis();

			$connected = $redis->connect(
				$host,
				self::read_port_constant( 'REDIS_PORT', 'WP_REDIS_PORT' ) ?? 6379,
				(float) ( self::read_numeric_constant( 'REDIS_TIMEOUT', 'WP_REDIS_TIMEOUT' ) ?? 2.5 )
			);

			if ( ! $connected ) {
				throw new RuntimeException( 'Redis connection refused' );
			}

			$credentials = self::read_redis_credentials();

			if ( null !== $credentials ) {
				list( $username, $password ) = $credentials;

				if ( ! $redis->auth( null === $username ? $password : array( $username, $password ) ) ) {
					throw new RuntimeException( 'Redis authentication failed' );
				}
			}

			$database = self::read_numeric_constant( 'REDIS_DATABASE', 'WP_REDIS_DATABASE' );

			if ( null !== $database ) {
				$redis->select( (int) $database );
			}

			$this->redis = $redis;
		} catch ( Throwable $e ) {
			$this->redis = null;

			$this->connection_error = 'PhpRedis connection failed: ' . $e->getMessage();
		}
	}

	/**
	 * Delete every key matching a glob pattern using a non-blocking SCAN.
	 *
	 * @param string $pattern Redis glob pattern.
	 * @return bool True when the scan completed without an error.
	 */
	private function delete_by_pattern( string $pattern ): bool {
		$redis = $this->redis;

		if ( null === $redis ) {
			return false;
		}

		$keys = $this->scan( $redis, $pattern );

		if ( empty( $keys ) ) {
			return true;
		}

		try {
			$redis->del( $keys );
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Redis DEL by pattern failed', $e, $pattern, '' );
			return false;
		}

		return true;
	}

	/**
	 * Collect keys matching a glob pattern.
	 *
	 * @param Redis  $redis   Connected client.
	 * @param string $pattern Redis glob pattern.
	 * @return array<string>
	 */
	private function scan( Redis $redis, string $pattern ): array {
		$found    = array();
		$iterator = null;

		try {
			do {
				$batch = $redis->scan( $iterator, $pattern, 1000 );

				if ( is_array( $batch ) ) {
					foreach ( $batch as $raw_key ) {
						$found[] = (string) $raw_key;
					}
				}
			} while ( 0 !== $iterator && null !== $iterator );
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Redis SCAN failed', $e, $pattern, '' );
			return $found;
		}

		return $found;
	}

	/**
	 * Decode a stored payload.
	 *
	 * @param string $raw   Raw JSON payload.
	 * @param string $key   Cache key, for logging.
	 * @param string $group Group name, for logging.
	 * @return mixed Decoded value, or CacheMiss::instance() when undecodable.
	 */
	private function decode( string $raw, string $key, string $group ): mixed {
		try {
			return PayloadCodec::decode( $raw );
		} catch ( Throwable $e ) {
			$this->log_failure( 'Redis payload could not be JSON decoded', $e, $key, $group );
			return CacheMiss::instance();
		}
	}

	/**
	 * Log a driver failure when a logger is available.
	 *
	 * @param string         $message   Human readable message.
	 * @param Throwable|null $exception Originating exception, if any.
	 * @param string         $key       Cache key.
	 * @param string         $group     Group name.
	 * @return void
	 */
	private function log_failure( string $message, ?Throwable $exception, string $key, string $group ): void {
		$this->logger?->warning(
			null !== $exception ? $message . ': ' . $exception->getMessage() : $message,
			array(
				'key'   => $key,
				'group' => $group,
			)
		);
	}
}
