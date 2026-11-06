<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Adapters;

use D6N\LPM\Core\Shared\IPHeaderConfig;
use D6N\LPM\Core\Shared\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Edge header geolocation adapter.
 */
class HeaderGeolocationAdapter extends AbstractGeolocationAdapter {

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'CDN Header';
	}

	/**
	 * Check if provider is available.
	 *
	 * @return bool True when a declared country header may be read on this request.
	 */
	public function is_available(): bool {
		return array() !== IPHeaderConfig::get_trusted_country_headers();
	}

	/**
	 * The country is already resolved, so the address itself is not used.
	 *
	 * @return bool
	 */
	protected function requires_public_ip(): bool {
		return false;
	}

	/**
	 * Perform the provider specific lookup.
	 *
	 * @param string $ip Client IP address, unused by this provider.
	 * @return string|null Raw country code reported by the edge.
	 */
	protected function lookup( string $ip ): ?string {
		if ( ! IPHeaderConfig::is_trusted_proxy( self::remote_addr() ) ) {
			return null;
		}

		foreach ( IPHeaderConfig::get_trusted_country_headers() as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$code = strtoupper( trim( (string) sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) ) );

			if ( Utils::is_valid_country_code( $code ) ) {
				return $code;
			}
		}

		return null;
	}

	/**
	 * Read the TCP peer address of the current request.
	 *
	 * @return string Peer address, empty when the server provides none.
	 */
	private static function remote_addr(): string {
		if ( empty( $_SERVER[ IPHeaderConfig::REMOTE_ADDR ] ) || ! is_string( $_SERVER[ IPHeaderConfig::REMOTE_ADDR ] ) ) {
			return '';
		}

		return trim( (string) sanitize_text_field( wp_unslash( $_SERVER[ IPHeaderConfig::REMOTE_ADDR ] ) ) );
	}
}
