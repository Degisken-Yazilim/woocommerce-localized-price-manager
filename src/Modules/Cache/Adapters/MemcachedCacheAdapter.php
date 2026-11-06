<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Adapters;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Modules\Cache\Support\PayloadCodec;
use D6N\LPM\Modules\Cache\ValueObjects\CacheMiss;
use InvalidArgumentException;
use Memcached;
use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Memcached cache adapter.
 */
class MemcachedCacheAdapter extends AbstractCacheAdapter {

	/**
	 * Connected client, or null when the extension or server is unavailable.
	 *
	 * @var Memcached|null
	 */
	private ?Memcached $memcached = null;

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
	 * Check if Memcached is available and connected.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return null !== $this->memcached;
	}

	/**
	 * Check whether a host constant asks for this driver and its client is installed.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return null !== self::read_string_constant( 'MEMCACHED_HOST' ) && class_exists( Memcached::class );
	}

	/**
	 * Read one entry from the backend.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return mixed Cached value, or CacheMiss::instance() on a miss.
	 */
	protected function read_entry( string $key, string $group ): mixed {
		if ( null === $this->memcached ) {
			return CacheMiss::instance();
		}

		try {
			$raw    = $this->memcached->get( $this->build_key( $key, $group ) );
			$result = $this->memcached->getResultCode();
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Memcached GET failed', $e, $key, $group );
			return CacheMiss::instance();
		}

		if ( Memcached::RES_SUCCESS !== $result ) {
			if ( Memcached::RES_NOTFOUND !== $result ) {
				$this->report_runtime_failure();
			}

			return CacheMiss::instance();
		}

		if ( ! is_string( $raw ) ) {
			return CacheMiss::instance();
		}

		try {
			return PayloadCodec::decode( $raw );
		} catch ( Throwable $e ) {
			$this->log_failure( 'Memcached payload could not be JSON decoded', $e, $key, $group );
			return CacheMiss::instance();
		}
	}

	/**
	 * Read several entries from the backend with one getMulti.
	 *
	 * @param array<string, array{key: string, group: string}> $requests Entries to read, keyed by storage key.
	 * @return array<string, mixed> Values keyed by storage key; empty when the backend did not answer.
	 */
	protected function read_entries( array $requests ): array {
		if ( null === $this->memcached || array() === $requests ) {
			return array();
		}

		$storage_keys = array_map( 'strval', array_keys( $requests ) );

		try {
			$found  = $this->memcached->getMulti( $storage_keys );
			$result = $this->memcached->getResultCode();
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Memcached GETMULTI failed', $e, '', '' );
			return array();
		}

		if ( ! is_array( $found ) || ( Memcached::RES_SUCCESS !== $result && Memcached::RES_NOTFOUND !== $result ) ) {
			$this->report_runtime_failure();
			return array();
		}

		$results = array();

		foreach ( $storage_keys as $storage_key ) {
			$raw = $found[ $storage_key ] ?? null;

			if ( ! is_string( $raw ) ) {
				$results[ $storage_key ] = CacheMiss::instance();
				continue;
			}

			try {
				$results[ $storage_key ] = PayloadCodec::decode( $raw );
			} catch ( Throwable $e ) {
				$this->log_failure( 'Memcached payload could not be JSON decoded', $e, $requests[ $storage_key ]['key'], $requests[ $storage_key ]['group'] );
				$results[ $storage_key ] = CacheMiss::instance();
			}
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
		if ( null === $this->memcached ) {
			return false;
		}

		try {
			$encoded = PayloadCodec::encode( $value );
		} catch ( InvalidArgumentException $e ) {
			$this->log_failure( 'Memcached payload could not be JSON encoded', $e, $key, $group );
			return false;
		}

		try {
			$stored = (bool) $this->memcached->set( $this->build_key( $key, $group ), $encoded, $this->resolve_ttl( $ttl, $group ) );
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Memcached SET failed', $e, $key, $group );
			return false;
		}

		if ( ! $stored ) {
			$this->report_runtime_failure();
		}

		return $stored;
	}

	/**
	 * Delete one entry from the backend.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Group name.
	 * @return bool
	 */
	protected function delete_entry( string $key, string $group ): bool {
		if ( null === $this->memcached ) {
			return false;
		}

		try {
			return (bool) $this->memcached->delete( $this->build_key( $key, $group ) );
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Memcached DELETE failed', $e, $key, $group );
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
		if ( null === $this->memcached ) {
			return false;
		}

		try {
			return (bool) $this->memcached->add( $this->build_key( $key, $group ), $encoded, $ttl );
		} catch ( Throwable $e ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Memcached ADD failed', $e, $key, $group );
			return false;
		}
	}

	/**
	 * Write several encoded entries to the backend with one setMulti per TTL.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether each entry was stored, keyed by storage key.
	 */
	protected function write_entries( array $items ): array {
		$results   = array_fill_keys( array_keys( $items ), false );
		$memcached = $this->memcached;

		if ( null === $memcached || array() === $items ) {
			return $results;
		}

		$by_ttl = array();

		foreach ( $items as $storage_key => $item ) {
			$by_ttl[ $item['ttl'] ][ (string) $storage_key ] = $item['payload'];
		}

		$refused = 0;

		foreach ( $by_ttl as $ttl => $payloads ) {
			try {
				$stored = (bool) $memcached->setMulti( $payloads, (int) $ttl );
			} catch ( Throwable $e ) {
				$this->report_runtime_failure();
				$this->log_failure( 'Memcached SETMULTI failed', $e, '', '' );
				continue;
			}

			foreach ( array_keys( $payloads ) as $storage_key ) {
				$results[ $storage_key ] = $stored;
			}

			if ( ! $stored ) {
				$refused += count( $payloads );
			}
		}

		if ( $refused > 0 ) {
			$this->report_runtime_failure();
			$this->log_failure( sprintf( 'Memcached SETMULTI refused %d of %d entries', $refused, count( $items ) ), null, '', '' );
		}

		return $results;
	}

	/**
	 * Store several encoded payloads one add at a time, each only when its key is absent.
	 *
	 * @param array<string, array{group: string, payload: string, ttl: int}> $items Entries keyed by storage key.
	 * @return array<string, bool> Whether this call stored each entry, keyed by storage key.
	 */
	protected function add_entries( array $items ): array {
		$results   = array_fill_keys( array_keys( $items ), false );
		$memcached = $this->memcached;

		if ( null === $memcached ) {
			return $results;
		}

		$failure = null;

		foreach ( $items as $storage_key => $item ) {
			try {
				$results[ $storage_key ] = (bool) $memcached->add( (string) $storage_key, $item['payload'], $item['ttl'] );
			} catch ( Throwable $e ) {
				$failure ??= $e;
			}
		}

		if ( null !== $failure ) {
			$this->report_runtime_failure();
			$this->log_failure( 'Memcached ADD failed', $failure, '', '' );
		}

		return $results;
	}

	/**
	 * Open the Memcached connection, degrading to "unavailable" on any failure.
	 *
	 * @return void
	 */
	private function connect(): void {
		$host = self::read_string_constant( 'MEMCACHED_HOST' );

		if ( null === $host || ! class_exists( Memcached::class ) ) {
			return;
		}

		try {
			$memcached = new Memcached();
			$port      = defined( 'MEMCACHED_PORT' ) ? (int) MEMCACHED_PORT : 11211;

			if ( ! $memcached->getServerList() && ! $memcached->addServer( $host, $port ) ) {
				throw new RuntimeException( sprintf( 'Could not add Memcached server %s:%d', $host, $port ) );
			}

			$this->harden_client( $memcached );

			if ( ! $memcached->getStats() ) {
				throw new RuntimeException( sprintf( 'Memcached server %s:%d is not responding', $host, $port ) );
			}

			$this->memcached = $memcached;
		} catch ( Throwable $e ) {
			$this->memcached = null;

			$this->connection_error = 'Memcached connection failed: ' . $e->getMessage();
		}
	}

	/**
	 * Apply the hardening options the extension supports.
	 *
	 * @param Memcached $memcached Connected client.
	 * @return void
	 */
	private function harden_client( Memcached $memcached ): void {
		if (
			defined( 'Memcached::OPT_SERIALIZER' )
			&& defined( 'Memcached::SERIALIZER_JSON' )
			&& defined( 'Memcached::HAVE_JSON' ) && Memcached::HAVE_JSON
		) {
			$memcached->setOption( Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_JSON );
		}

		if (
			defined( 'MEMCACHED_USERNAME' ) && is_string( MEMCACHED_USERNAME ) && '' !== MEMCACHED_USERNAME
			&& defined( 'MEMCACHED_PASSWORD' ) && is_string( MEMCACHED_PASSWORD )
			&& defined( 'Memcached::HAVE_SASL' ) && Memcached::HAVE_SASL
		) {
			$memcached->setOption( Memcached::OPT_BINARY_PROTOCOL, true );
			$memcached->setSaslAuthData( MEMCACHED_USERNAME, MEMCACHED_PASSWORD );
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
