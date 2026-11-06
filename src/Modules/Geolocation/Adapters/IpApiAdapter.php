<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * IP-API.com geolocation adapter.
 */
class IpApiAdapter extends AbstractGeolocationAdapter {

	/**
	 * Endpoint template.
	 *
	 * @var string
	 */
	private const API_URL = 'http://ip-api.com/json/%s?fields=status,countryCode';

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'IP-API.com';
	}

	/**
	 * Perform the provider specific lookup.
	 *
	 * @param string $ip Client IP address.
	 * @return string|null Raw country code reported by the provider.
	 */
	protected function lookup( string $ip ): ?string {
		$data = $this->request_json( sprintf( self::API_URL, rawurlencode( $ip ) ) );

		if ( null === $data ) {
			return null;
		}

		if ( ! isset( $data['status'] ) || 'success' !== $data['status'] ) {
			return null;
		}

		if ( ! isset( $data['countryCode'] ) || ! is_string( $data['countryCode'] ) ) {
			return null;
		}

		return $data['countryCode'];
	}
}
