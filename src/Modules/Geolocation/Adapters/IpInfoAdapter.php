<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * IPInfo.io geolocation adapter.
 */
class IpInfoAdapter extends AbstractApiKeyGeolocationAdapter {

	/**
	 * Endpoint template.
	 *
	 * @var string
	 */
	private const API_URL = 'https://api.ipinfo.io/lite/%s?token=%s';

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'IPInfo';
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

		if ( null === $data ) {
			return null;
		}

		$code = $data['country_code'] ?? $data['country'] ?? null;

		return is_string( $code ) ? $code : null;
	}
}
