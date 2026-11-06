<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Services;

use Closure;
use D6N\LPM\Core\Contracts\IGeolocation;
use D6N\LPM\Core\Contracts\IGeolocationResolver;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\Utils;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Geolocation chain runner.
 */
class GeolocationResolver implements IGeolocationResolver {

	/**
	 * Log source for this service.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'Geolocation';

	/**
	 * Resolved adapter chain, built on first use.
	 *
	 * @var array<int, IGeolocation>|null
	 */
	private ?array $resolved_adapters = null;

	/**
	 * Constructor.
	 *
	 * @param array<int, IGeolocation>|Closure $adapters Ordered adapters, or a factory returning them.
	 * @param ILogger                          $logger   Logger service instance.
	 */
	public function __construct(
		private readonly array|Closure $adapters,
		private readonly ILogger $logger
	) {}

	/**
	 * Get the adapter chain, building it once on first use.
	 *
	 * @return array<int, IGeolocation> Ordered adapters, possibly empty.
	 */
	public function get_adapters(): array {
		if ( null !== $this->resolved_adapters ) {
			return $this->resolved_adapters;
		}

		if ( is_array( $this->adapters ) ) {
			$this->resolved_adapters = $this->adapters;

			return $this->resolved_adapters;
		}

		try {
			$built = ( $this->adapters )();
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Could not build the geolocation provider chain: %s', $e->getMessage() ),
				array(),
				self::LOG_SOURCE
			);

			$built = array();
		}

		$this->resolved_adapters = is_array( $built ) ? array_values( array_filter( $built, static fn ( mixed $a ): bool => $a instanceof IGeolocation ) ) : array();

		return $this->resolved_adapters;
	}

	/**
	 * Resolve country code from client IP.
	 *
	 * @param string $ip_address Client IP address.
	 * @return string|null Two-letter ISO country code, or null when every provider failed.
	 */
	public function resolve( string $ip_address ): ?string {
		$ip = trim( $ip_address );

		if ( '' === $ip ) {
			return null;
		}

		$attempted = 0;

		foreach ( $this->get_adapters() as $adapter ) {
			try {
				if ( ! $adapter->is_available() ) {
					continue;
				}

				++$attempted;
				$country = Utils::normalize_country_code( $adapter->get_country_by_ip( $ip ) );

				if ( null !== $country ) {
					$this->logger->debug(
						sprintf( 'Resolved country %s using %s.', $country, $adapter->get_name() ),
						array( 'provider' => $adapter->get_name() ),
						self::LOG_SOURCE
					);

					return $country;
				}
			} catch ( Throwable $e ) {
				$this->logger->service(
					sprintf( 'Geolocation provider %s failed: %s', $adapter->get_name(), $e->getMessage() ),
					array( 'provider' => $adapter->get_name() ),
					self::LOG_SOURCE,
					LogLevelEnum::Warning
				);
			}
		}

		$this->logger->service(
			0 === $attempted
				? 'No geolocation provider is available; country detection is disabled.'
				: 'Every geolocation provider failed to resolve the client country.',
			array( 'providers_tried' => $attempted ),
			self::LOG_SOURCE,
			LogLevelEnum::Notice
		);

		do_action( Core::build_hook( HookEnum::GeolocationChainExhausted->value ), $ip );

		return null;
	}
}
