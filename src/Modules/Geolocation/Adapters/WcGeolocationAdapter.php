<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Adapters;

use WC_Geolocation;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce geolocation adapter.
 */
class WcGeolocationAdapter extends AbstractGeolocationAdapter {

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'WooCommerce Geolocation';
	}

	/**
	 * Check if provider is available.
	 *
	 * @return bool True when WooCommerce geolocation is loaded.
	 */
	public function is_available(): bool {
		return class_exists( WC_Geolocation::class );
	}

	/**
	 * WooCommerce decides itself how to treat non public addresses.
	 *
	 * @return bool
	 */
	protected function requires_public_ip(): bool {
		return false;
	}

	/**
	 * Perform the provider specific lookup.
	 *
	 * @param string $ip Client IP address.
	 * @return string|null Raw country code reported by the provider.
	 */
	protected function lookup( string $ip ): ?string {
		$location = WC_Geolocation::geolocate_ip( $ip, false, false );

		if ( empty( $location['country'] ) || ! is_string( $location['country'] ) ) {
			return null;
		}

		return $location['country'];
	}
}
