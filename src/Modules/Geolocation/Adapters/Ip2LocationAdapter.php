<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * IP2Location.io geolocation adapter.
 */
class Ip2LocationAdapter extends AbstractApiKeyGeolocationAdapter {

	/**
	 * Endpoint template.
	 *
	 * @var string
	 */
	private const API_URL = 'https://api.ip2location.io/?key=%s&ip=%s&format=json';

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'IP2Location';
	}

	/**
	 * Perform the provider specific lookup.
	 *
	 * @param string $ip Client IP address.
	 * @return string|null Raw country code reported by the provider.
	 */
	protected function lookup( string $ip ): ?string {
		$url = sprintf( self::API_URL, rawurlencode( (string) $this->api_key ), rawurlencode( $ip ) );

		$data = $this->request_json( $url );

		if ( null === $data || ! isset( $data['country_code'] ) || ! is_string( $data['country_code'] ) ) {
			return null;
		}

		return $data['country_code'];
	}
}
