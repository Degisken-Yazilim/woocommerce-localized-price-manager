<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Services;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\Cache\Adapters\AbstractCacheAdapter;
use D6N\LPM\Modules\Cache\Adapters\MemcachedCacheAdapter;
use D6N\LPM\Modules\Cache\Adapters\PhpRedisCacheAdapter;
use D6N\LPM\Modules\Cache\Adapters\PredisCacheAdapter;
use D6N\LPM\Modules\Cache\Adapters\TransientCacheAdapter;
use D6N\LPM\Modules\Cache\Adapters\WpObjectCacheAdapter;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Multi-driver cache manager discovering and providing the best active cache adapter.
 */
class CacheManager {

	/**
	 * Suffix of the retired transient that held the probe outcomes, still cleared on uninstall.
	 */
	public const LEGACY_PROBE_TRANSIENT = 'cache_probe';

	/**
	 * Seconds a successful probe is trusted.
	 */
	public const SUCCESS_TTL = 3600;

	/**
	 * Seconds a failed probe is trusted.
	 */
	public const FAILURE_TTL = 600;

	/**
	 * Remembered probe outcomes keyed by adapter class, or null until read.
	 *
	 * @var array<string, array{ok: bool, expires_at: int}>|null
	 */
	private ?array $probes = null;

	/**
	 * Constructor.
	 *
	 * @param ILogger|null $logger Logger service instance.
	 */
	public function __construct(
		private readonly ?ILogger $logger = null
	) {}

	/**
	 * Get the default, unfiltered network driver probe order.
	 *
	 * @return array<int, class-string<ICache>>
	 */
	public function get_default_adapters(): array {
		return array(
			PhpRedisCacheAdapter::class,
			PredisCacheAdapter::class,
			MemcachedCacheAdapter::class,
		);
	}

	/**
	 * Get best available active cache adapter.
	 *
	 * @return ICache
	 */
	public function get_active_adapter(): ICache {
		foreach ( $this->get_candidate_adapters() as $adapter_class ) {
			$adapter = $this->probe( $adapter_class );

			if ( null !== $adapter ) {
				$this->logger?->cache( sprintf( 'Using cache adapter: %s', $adapter_class ), array(), 'Cache', LogLevelEnum::Debug );
				return $adapter;
			}
		}

		if ( wp_using_ext_object_cache() ) {
			$this->logger?->cache( sprintf( 'Using cache adapter: %s', WpObjectCacheAdapter::class ), array(), 'Cache', LogLevelEnum::Debug );
			return new WpObjectCacheAdapter( $this->logger );
		}

		$this->logger?->cache( 'Falling back to TransientCacheAdapter.', array(), 'Cache', LogLevelEnum::Debug );

		return new TransientCacheAdapter( $this->logger );
	}

	/**
	 * Resolve the filtered adapter probe list.
	 *
	 * @return array<int, class-string<ICache>>
	 */
	private function get_candidate_adapters(): array {
		/**
		 * Filters the ordered list of network cache adapter class names to probe.
		 *
		 * @param array<int, class-string<ICache>> $defaults Adapter class names.
		 */
		return apply_filters( Core::build_hook( HookEnum::CacheAdapters->value ), $this->get_default_adapters() );
	}

	/**
	 * Probe one adapter, honouring and updating the remembered outcome.
	 *
	 * @param string $adapter_class Adapter class name.
	 * @return ICache|null Usable adapter, or null when it must be skipped.
	 *
	 * @phpstan-param class-string<ICache> $adapter_class
	 */
	private function probe( string $adapter_class ): ?ICache {
		if ( is_a( $adapter_class, AbstractCacheAdapter::class, true ) && ! $adapter_class::is_configured() ) {
			return null;
		}

		$previous = $this->get_probes()[ $adapter_class ] ?? null;

		if ( null !== $previous && ! $previous['ok'] && $this->is_fresh( $previous ) ) {
			return null;
		}

		try {
			$adapter = new $adapter_class( $this->logger );
		} catch ( Throwable $e ) {
			$this->record_failure( $adapter_class, sprintf( 'Cache adapter %s could not be constructed: %s', $adapter_class, $e->getMessage() ), $previous );
			return null;
		}

		if ( $adapter->is_available() ) {
			$this->record_success( $adapter_class, $previous );
			$this->watch_runtime_failures( $adapter, $adapter_class );
			return $adapter;
		}

		$reason = $adapter instanceof AbstractCacheAdapter
			? $adapter->get_connection_error()
			: sprintf( 'Cache adapter %s reported itself unavailable', $adapter_class );

		if ( null !== $reason ) {
			$this->record_failure( $adapter_class, $reason, $previous );
		}

		return null;
	}

	/**
	 * Remember a successful probe and announce a recovery.
	 *
	 * @param string                                $adapter_class Adapter class name.
	 * @param array{ok: bool, expires_at: int}|null $previous      Previously remembered outcome.
	 * @return void
	 */
	private function record_success( string $adapter_class, ?array $previous ): void {
		if ( null !== $previous && $previous['ok'] && $this->is_fresh( $previous ) ) {
			return;
		}

		if ( null !== $previous && ! $previous['ok'] ) {
			$this->logger?->info( sprintf( 'Cache adapter %s is reachable again.', $adapter_class ), array(), 'Cache' );
		}

		$this->remember( $adapter_class, true, time() + self::SUCCESS_TTL );
	}

	/**
	 * Remember a failed probe and announce it when the state changed.
	 *
	 * @param string                                $adapter_class Adapter class name.
	 * @param string                                $reason        Failure reason.
	 * @param array{ok: bool, expires_at: int}|null $previous      Previously remembered outcome.
	 * @return void
	 */
	private function record_failure( string $adapter_class, string $reason, ?array $previous ): void {
		if ( null === $previous || $previous['ok'] ) {
			$this->logger?->warning( $reason, array( 'adapter' => $adapter_class ), 'Cache' );
		}

		$this->remember( $adapter_class, false, time() + self::FAILURE_TTL );
	}

	/**
	 * Make the next request re-probe an adapter that failed at runtime.
	 *
	 * @param ICache $adapter       Selected adapter.
	 * @param string $adapter_class Adapter class name.
	 * @return void
	 */
	private function watch_runtime_failures( ICache $adapter, string $adapter_class ): void {
		if ( ! $adapter instanceof AbstractCacheAdapter ) {
			return;
		}

		$adapter->on_runtime_failure(
			function () use ( $adapter_class ): void {
				$this->probes = null;
				$previous     = $this->get_probes()[ $adapter_class ] ?? null;

				$this->remember( $adapter_class, $previous['ok'] ?? true, 0 );
			}
		);
	}

	/**
	 * Check whether a remembered outcome is still trusted.
	 *
	 * @param array{ok: bool, expires_at: int} $probe Remembered outcome.
	 * @return bool
	 */
	private function is_fresh( array $probe ): bool {
		return $probe['expires_at'] > time();
	}

	/**
	 * Persist one probe outcome.
	 *
	 * @param string $adapter_class Adapter class name.
	 * @param bool   $ok            Whether the adapter was usable.
	 * @param int    $expires_at    Unix time the outcome stops being trusted, or 0 to force a re-probe.
	 * @return void
	 */
	private function remember( string $adapter_class, bool $ok, int $expires_at ): void {
		$probes                   = $this->get_probes();
		$probes[ $adapter_class ] = array(
			'ok'         => $ok,
			'expires_at' => $expires_at,
		);

		$this->probes = $probes;

		update_option( OptionsEnum::CacheProbe->value, $probes, true );
	}

	/**
	 * Read the remembered probe outcomes once per manager.
	 *
	 * @return array<string, array{ok: bool, expires_at: int}>
	 */
	private function get_probes(): array {
		if ( null !== $this->probes ) {
			return $this->probes;
		}

		$stored = get_option( OptionsEnum::CacheProbe->value, array() );
		$probes = array();

		if ( is_array( $stored ) ) {
			foreach ( $stored as $adapter_class => $probe ) {
				if ( is_string( $adapter_class ) && is_array( $probe ) && is_bool( $probe['ok'] ?? null ) && is_int( $probe['expires_at'] ?? null ) ) {
					$probes[ $adapter_class ] = array(
						'ok'         => $probe['ok'],
						'expires_at' => $probe['expires_at'],
					);
				}
			}
		}

		$this->probes = $probes;

		return $probes;
	}
}
