<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Frankfurter provider — free ECB backed API, no API key.
 *
 * @link https://frankfurter.dev/
 */
class FrankfurterProvider extends AbstractExchangeRateProvider {

	private const API_URL = 'https://api.frankfurter.dev/v1/latest?base=%s';

	/**
	 * Get the static description of this provider.
	 *
	 * @return ProviderMetadata Provider description.
	 */
	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata(
			key: 'frankfurter',
			name: 'Frankfurter',
			requires_api_key: false
		);
	}

	/**
	 * Build the upstream request URL for a base currency.
	 *
	 * @param string $base_currency Uppercase ISO 4217 base currency code.
	 * @return string Fully qualified request URL.
	 */
	protected function build_url( string $base_currency ): string {
		return sprintf( self::API_URL, rawurlencode( $base_currency ) );
	}

	/**
	 * Inspect a decoded response for the Frankfurter error envelope.
	 *
	 * @param array<string, mixed> $data Decoded response body.
	 * @return string|null Error description, or null when the response is healthy.
	 */
	protected function detect_api_error( array $data ): ?string {
		if ( empty( $data['message'] ) ) {
			return null;
		}

		return is_scalar( $data['message'] ) ? (string) $data['message'] : 'unknown error';
	}
}
