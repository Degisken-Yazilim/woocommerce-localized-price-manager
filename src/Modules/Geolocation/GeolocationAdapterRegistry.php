<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation;

use D6N\LPM\Core\Contracts\IGeolocation;
use D6N\LPM\Core\Contracts\IHttpRequest;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Modules\Geolocation\Adapters\GeoJsAdapter;
use D6N\LPM\Modules\Geolocation\Adapters\HeaderGeolocationAdapter;
use D6N\LPM\Modules\Geolocation\Adapters\Ip2LocationAdapter;
use D6N\LPM\Modules\Geolocation\Adapters\IpApiAdapter;
use D6N\LPM\Modules\Geolocation\Adapters\IpInfoAdapter;
use D6N\LPM\Modules\Geolocation\Adapters\IpStackAdapter;
use D6N\LPM\Modules\Geolocation\Adapters\MaxMindGeolocationAdapter;
use D6N\LPM\Modules\Geolocation\Adapters\WcGeolocationAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Static catalogue of every geolocation provider the plugin ships.
 */
class GeolocationAdapterRegistry {

	public const PROVIDER_HEADER      = 'header';
	public const PROVIDER_MAXMIND     = 'maxmind';
	public const PROVIDER_WOOCOMMERCE = 'wc';
	public const PROVIDER_GEOJS       = 'geojs';
	public const PROVIDER_IPAPI       = 'ipapi';
	public const PROVIDER_IP2LOCATION = 'ip2location';
	public const PROVIDER_IPINFO      = 'ipinfo';
	public const PROVIDER_IPSTACK     = 'ipstack';

	/**
	 * Suffix appended to a provider key to build its API key option name.
	 *
	 * @var string
	 */
	private const API_KEY_SUFFIX = '_api_key';

	/**
	 * Every provider key, in default priority order.
	 *
	 * @var array<int, string>
	 */
	private const PROVIDER_KEYS = array(
		self::PROVIDER_HEADER,
		self::PROVIDER_MAXMIND,
		self::PROVIDER_WOOCOMMERCE,
		self::PROVIDER_GEOJS,
		self::PROVIDER_IPAPI,
		self::PROVIDER_IP2LOCATION,
		self::PROVIDER_IPINFO,
		self::PROVIDER_IPSTACK,
	);

	/**
	 * Get the static metadata of every provider, in default priority order.
	 *
	 * @return array<string, array{name: string, class: class-string<IGeolocation>, requires_api_key: bool, option_key: string|null, description: string}>
	 */
	public static function all(): array {
		return array(
			self::PROVIDER_HEADER      => array(
				'name'             => __( 'CDN Header', 'localized-price-manager' ),
				'class'            => HeaderGeolocationAdapter::class,
				'requires_api_key' => false,
				'option_key'       => null,
				'description'      => __( 'Country resolved by a trusted CDN edge. Requires an enabled CDN vendor.', 'localized-price-manager' ),
			),
			self::PROVIDER_MAXMIND     => array(
				'name'             => __( 'MaxMind', 'localized-price-manager' ),
				'class'            => MaxMindGeolocationAdapter::class,
				'requires_api_key' => false,
				'option_key'       => null,
				'description'      => __( 'Local MaxMind database managed by the WooCommerce integration.', 'localized-price-manager' ),
			),
			self::PROVIDER_WOOCOMMERCE => array(
				'name'             => __( 'WooCommerce Geolocation', 'localized-price-manager' ),
				'class'            => WcGeolocationAdapter::class,
				'requires_api_key' => false,
				'option_key'       => null,
				'description'      => __( 'The geolocation stack WooCommerce is already configured with.', 'localized-price-manager' ),
			),
			self::PROVIDER_GEOJS       => array(
				'name'             => __( 'GeoJS.io', 'localized-price-manager' ),
				'class'            => GeoJsAdapter::class,
				'requires_api_key' => false,
				'option_key'       => null,
				'description'      => __( 'Free keyless country lookup service.', 'localized-price-manager' ),
			),
			self::PROVIDER_IPAPI       => array(
				'name'             => __( 'IP-API.com', 'localized-price-manager' ),
				'class'            => IpApiAdapter::class,
				'requires_api_key' => false,
				'option_key'       => null,
				'description'      => __( 'Free keyless country lookup service, rate limited. Warning: queries travel over plain HTTP — anyone on the network path can read the visitor IP and alter the answer. Prefer an HTTPS provider when possible.', 'localized-price-manager' ),
			),
			self::PROVIDER_IP2LOCATION => array(
				'name'             => __( 'IP2Location.io', 'localized-price-manager' ),
				'class'            => Ip2LocationAdapter::class,
				'requires_api_key' => true,
				'option_key'       => self::PROVIDER_IP2LOCATION . self::API_KEY_SUFFIX,
				'description'      => __( 'Commercial lookup service, requires an API key.', 'localized-price-manager' ),
			),
			self::PROVIDER_IPINFO      => array(
				'name'             => __( 'IPInfo.io', 'localized-price-manager' ),
				'class'            => IpInfoAdapter::class,
				'requires_api_key' => true,
				'option_key'       => self::PROVIDER_IPINFO . self::API_KEY_SUFFIX,
				'description'      => __( 'Commercial lookup service, requires an access token.', 'localized-price-manager' ),
			),
			self::PROVIDER_IPSTACK     => array(
				'name'             => __( 'IPStack.com', 'localized-price-manager' ),
				'class'            => IpStackAdapter::class,
				'requires_api_key' => true,
				'option_key'       => self::PROVIDER_IPSTACK . self::API_KEY_SUFFIX,
				'description'      => __( 'Commercial lookup service, requires an access key.', 'localized-price-manager' ),
			),
		);
	}

	/**
	 * Get every provider key in default priority order.
	 *
	 * @return array<int, string> Provider keys.
	 */
	public static function keys(): array {
		return self::PROVIDER_KEYS;
	}

	/**
	 * Check whether a provider key is known.
	 *
	 * @param string $key Provider key.
	 * @return bool True when the provider exists.
	 */
	public static function has( string $key ): bool {
		return in_array( $key, self::PROVIDER_KEYS, true );
	}

	/**
	 * Get the metadata of a single provider.
	 *
	 * @param string $key Provider key.
	 * @return array{name: string, class: class-string<IGeolocation>, requires_api_key: bool, option_key: string|null, description: string}|null Metadata, or null when the provider is unknown.
	 */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Check whether a provider needs an API key to work.
	 *
	 * @param string $key Provider key.
	 * @return bool True when a key is required.
	 */
	public static function requires_api_key( string $key ): bool {
		return true === ( self::get( $key )['requires_api_key'] ?? false );
	}

	/**
	 * Get the option name holding a provider's API key.
	 *
	 * @param string $key Provider key.
	 * @return string|null Option name, or null when the provider needs no key.
	 */
	public static function get_api_key_option( string $key ): ?string {
		return self::get( $key )['option_key'] ?? null;
	}

	/**
	 * Get the provider keys that store an API key.
	 *
	 * @return array<int, string> Provider keys.
	 */
	public static function keyed_providers(): array {
		return array_keys( array_filter( self::all(), static fn ( array $meta ): bool => $meta['requires_api_key'] ) );
	}

	/**
	 * Instantiate the adapter of a provider.
	 *
	 * @param string            $key     Provider key.
	 * @param ILogger|null      $logger  Logger instance handed to the adapter.
	 * @param string|null       $api_key Plain-text API key, already decrypted.
	 * @param IHttpRequest|null $http HTTP client override, mainly for tests.
	 * @return IGeolocation|null Adapter instance, or null when the provider is unknown.
	 */
	public static function make( string $key, ?ILogger $logger = null, ?string $api_key = null, ?IHttpRequest $http = null ): ?IGeolocation {
		$meta = self::get( $key );

		if ( null === $meta ) {
			return null;
		}

		$class = $meta['class'];

		if ( $meta['requires_api_key'] ) {
			return new $class( $api_key, $logger, $http );
		}

		return new $class( $logger, $http );
	}
}
