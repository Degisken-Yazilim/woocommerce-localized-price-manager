<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Services;

use D6N\LPM\Core\Contracts\IGeolocation;
use D6N\LPM\Core\Contracts\IHttpRequest;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\Geolocation\GeolocationAdapterRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the ordered geolocation provider chain from the plugin settings.
 */
class GeolocationProviderQueue {

	/**
	 * Log source for this service.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'Geolocation';

	/**
	 * Constructor.
	 *
	 * @param GeolocationApiKeyService $api_keys API key store.
	 * @param ILogger|null             $logger   Logger instance.
	 * @param IHttpRequest|null        $http     HTTP client override handed to every adapter.
	 */
	public function __construct(
		private readonly GeolocationApiKeyService $api_keys,
		private readonly ?ILogger $logger = null,
		private readonly ?IHttpRequest $http = null
	) {}

	/**
	 * Get the configured provider keys in priority order.
	 *
	 * @return array<int, string> Known provider keys, empty when the operator enabled none.
	 */
	public function get_provider_keys(): array {
		$configured = Core::get_option( OptionsEnum::GeolocationProviders->value, array() );

		if ( ! is_array( $configured ) || array() === $configured ) {
			return array();
		}

		$keys = array();

		foreach ( $this->flatten( $configured ) as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$key = strtolower( trim( $key ) );

			if ( ! GeolocationAdapterRegistry::has( $key ) ) {
				$this->logger?->notice(
					sprintf( 'Ignoring unknown geolocation provider "%s" in the priority queue.', $key ),
					array(),
					self::LOG_SOURCE
				);

				continue;
			}

			if ( ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * Normalize the stored option into a plain ordered list of provider keys.
	 *
	 * @param array<mixed> $configured Raw option value.
	 * @return array<int, mixed> Ordered candidate keys.
	 */
	private function flatten( array $configured ): array {
		$is_map = false;

		foreach ( $configured as $key => $value ) {
			if ( is_string( $key ) && is_array( $value ) ) {
				$is_map = true;
				break;
			}
		}

		if ( ! $is_map ) {
			return array_values( $configured );
		}

		$active = array();

		foreach ( $configured as $key => $value ) {
			if ( ! is_string( $key ) || ! is_array( $value ) ) {
				continue;
			}

			if ( array_key_exists( 'active', $value ) && ! filter_var( $value['active'], FILTER_VALIDATE_BOOLEAN ) ) {
				continue;
			}

			$active[ $key ] = isset( $value['priority'] ) ? (int) $value['priority'] : PHP_INT_MAX;
		}

		asort( $active );

		return array_keys( $active );
	}

	/**
	 * Check whether the operator enabled at least one known provider.
	 *
	 * @return bool True when the configured chain is not empty.
	 */
	public function has_providers(): bool {
		return array() !== $this->get_provider_keys();
	}

	/**
	 * Build the ordered adapter chain.
	 *
	 * @return array<int, IGeolocation> Ordered adapters, possibly empty.
	 */
	public function get_adapters(): array {
		$adapters = array();

		foreach ( $this->get_provider_keys() as $key ) {
			$adapter = GeolocationAdapterRegistry::make(
				$key,
				$this->logger,
				GeolocationAdapterRegistry::requires_api_key( $key ) ? $this->api_keys->get( $key ) : null,
				$this->http
			);

			if ( null !== $adapter ) {
				$adapters[] = $adapter;
			}
		}

		/**
		 * Filters the ordered chain of geolocation adapters.
		 *
		 * @param array<int, IGeolocation> $adapters Ordered adapters, possibly empty.
		 */
		return apply_filters( Core::build_hook( HookEnum::GeolocationAdapters->value ), $adapters );
	}
}
