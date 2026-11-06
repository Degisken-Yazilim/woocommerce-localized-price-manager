<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Adapters;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Modules\Cache\Support\PayloadCodec;
use D6N\LPM\Modules\Cache\ValueObjects\CacheMiss;
use InvalidArgumentException;
use Predis\Client;
use Predis\Response\ErrorInterface;
use Throwable;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Predis cache adapter.
 */
class PredisCacheAdapter extends AbstractCacheAdapter {

	/**
	 * Connected client, or null when Predis or the server is unavailable.
	 *
	 * @var Client|null
	 */
	private ?Client $redis = null;

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
	 * Check if Predis is available and connected.
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
		return null !== self::read_string_constant( 'REDIS_HOST', 'WP_REDIS_HOST' ) && class_exists( Client::class );
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
			$this->log_failure( 'Predis GET failed', $e, $key, $group );
			return CacheMiss::instance();
		}

		if ( null === $raw ) {
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

			if ( count( $values ) !== count( $storage_keys ) ) {
				throw new UnexpectedValueException( 'MGET answered a different number of values than keys' );
			}
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Predis MGET failed', $e, '', '' );
			return array();
		}

		$values  = array_values( $values );
		$results = array();

		foreach ( $storage_keys as $index => $storage_key ) {
			$raw = $values[ $index ];

			$results[ $storage_key ] = ( null === $raw || false === $raw )
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
			$this->log_failure( 'Predis payload could not be JSON encoded', $e, $key, $group );
			return false;
		}

		try {
			$this->redis->setex( $this->build_key( $key, $group ), $this->resolve_ttl( $ttl, $group ), $encoded );
			return true;
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Predis SETEX failed', $e, $key, $group );
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
			return (int) $this->redis->del( array( $this->build_key( $key, $group ) ) ) > 0;
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Predis DEL failed', $e, $key, $group );
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
			return null !== $this->redis->set( $this->build_key( $key, $group ), $encoded, 'EX', $ttl, 'NX' );
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Predis SET NX failed', $e, $key, $group );
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
			$replies = $this->redis->pipeline(
				static function ( $pipeline ) use ( $items, $replace ): void {
					foreach ( $items as $storage_key => $item ) {
						if ( $replace ) {
							$pipeline->setex( (string) $storage_key, $item['ttl'], $item['payload'] );
							continue;
						}

						$pipeline->set( (string) $storage_key, $item['payload'], 'EX', $item['ttl'], 'NX' );
					}
				}
			);
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Predis ' . $command . ' pipeline failed', $e, '', '' );
			return $failed;
		}

		if ( ! is_array( $replies ) || count( $replies ) !== count( $items ) ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Predis ' . $command . ' pipeline answered an unexpected reply', null, '', '' );
			return $failed;
		}

		$replies = array_values( $replies );
		$results = array();
		$refused = 0;
		$index   = 0;

		foreach ( array_keys( $items ) as $storage_key ) {
			$reply = $replies[ $index++ ];

			$results[ $storage_key ] = null !== $reply && false !== $reply && ! $reply instanceof ErrorInterface;

			if ( $replace && ! $results[ $storage_key ] ) {
				++$refused;
			}
		}

		if ( $refused > 0 ) {
			$this->report_runtime_failure();
			$this->log_failure( sprintf( 'Predis %s pipeline refused %d of %d entries', $command, $refused, count( $items ) ), null, '', '' );
		}

		return $results;
	}

	/**
	 * Open the Predis connection, degrading to "unavailable" on any failure.
	 *
	 * @return void
	 */
	private function connect(): void {
		$host = self::read_string_constant( 'REDIS_HOST', 'WP_REDIS_HOST' );

		if ( null === $host || ! class_exists( Client::class ) ) {
			return;
		}

		try {
			$config = array(
				'scheme'  => 'tcp',
				'host'    => $host,
				'port'    => self::read_port_constant( 'REDIS_PORT', 'WP_REDIS_PORT' ) ?? 6379,
				'timeout' => (float) ( self::read_numeric_constant( 'REDIS_TIMEOUT', 'WP_REDIS_TIMEOUT' ) ?? 2.5 ),
			);

			$credentials = self::read_redis_credentials();

			if ( null !== $credentials ) {
				list( $username, $password ) = $credentials;

				if ( null !== $username ) {
					$config['username'] = $username;
				}

				$config['password'] = $password;
			}

			$database = self::read_numeric_constant( 'REDIS_DATABASE', 'WP_REDIS_DATABASE' );

			if ( null !== $database ) {
				$config['database'] = (int) $database;
			}

			$client = new Client( $config );
			$client->connect();
			$client->ping();

			$this->redis = $client;
		} catch ( Throwable $e ) {
			$this->redis = null;

			$this->connection_error = 'Predis connection failed: ' . $e->getMessage();
		}
	}

	/**
	 * Delete every key matching a glob pattern using a cursor based SCAN.
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
			$this->log_failure( 'Predis DEL by pattern failed', $e, $pattern, '' );
			return false;
		}

		return true;
	}

	/**
	 * Collect keys matching a glob pattern.
	 *
	 * @param Client $redis   Connected client.
	 * @param string $pattern Redis glob pattern.
	 * @return array<string>
	 */
	private function scan( Client $redis, string $pattern ): array {
		$found  = array();
		$cursor = 0;

		try {
			do {
				$result = $redis->scan(
					$cursor,
					array(
						'MATCH' => $pattern,
						'COUNT' => 1000,
					)
				);

				$cursor = isset( $result[0] ) ? (int) $result[0] : 0;

				if ( isset( $result[1] ) && is_array( $result[1] ) ) {
					foreach ( $result[1] as $raw_key ) {
						$found[] = (string) $raw_key;
					}
				}
			} while ( 0 !== $cursor );
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Predis SCAN failed', $e, $pattern, '' );
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
			$this->log_failure( 'Predis payload could not be JSON decoded', $e, $key, $group );
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
