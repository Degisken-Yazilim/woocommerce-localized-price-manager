<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * GeoJS.io geolocation adapter.
 */
class GeoJsAdapter extends AbstractGeolocationAdapter {

	/**
	 * Endpoint template.
	 *
	 * @var string
	 */
	private const API_URL = 'https://get.geojs.io/v1/ip/country/%s.json';

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'GeoJS';
	}

	/**
	 * Perform the provider specific lookup.
	 *
	 * @param string $ip Client IP address.
	 * @return string|null Raw country code reported by the provider.
	 */
	protected function lookup( string $ip ): ?string {
		$data = $this->request_json( sprintf( self::API_URL, rawurlencode( $ip ) ) );

		if ( null === $data || ! isset( $data['country'] ) || ! is_string( $data['country'] ) ) {
			return null;
		}

		return $data['country'];
	}
}
