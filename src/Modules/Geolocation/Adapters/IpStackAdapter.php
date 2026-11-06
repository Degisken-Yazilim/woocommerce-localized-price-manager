<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * IPStack.com geolocation adapter.
 */
class IpStackAdapter extends AbstractApiKeyGeolocationAdapter {

	/**
	 * Endpoint template.
	 *
	 * @var string
	 */
	private const API_URL = 'https://api.ipstack.com/%s?access_key=%s';

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'IPStack';
	}

	/**
	 * Perform the provider specific lookup.
	 *
	 * @param string $ip Client IP address.
	 * @return string|null Raw country code reported by the provider.
	 */
	protected function lookup( string $ip ): ?string {
		$url = sprintf( self::API_URL, rawurlencode( $ip ), rawurlencode( (string) $this->api_key ) );

		$data = $this->request_json( $url );

		if ( null === $data || ! isset( $data['country_code'] ) || ! is_string( $data['country_code'] ) ) {
			return null;
		}

		return $data['country_code'];
	}
}
