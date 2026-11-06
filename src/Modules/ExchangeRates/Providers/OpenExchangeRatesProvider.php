<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * OpenExchangeRates.org provider.
 *
 * @link https://docs.openexchangerates.org/
 */
class OpenExchangeRatesProvider extends AbstractExchangeRateProvider {

	private const API_URL = 'https://openexchangerates.org/api/latest.json?app_id=%s&base=%s';

	/**
	 * Get the static description of this provider.
	 *
	 * @return ProviderMetadata Provider description.
	 */
	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata(
			key: 'openexchangerates',
			name: 'OpenExchangeRates',
			requires_api_key: true
		);
	}

	/**
	 * Build the upstream request URL for a base currency.
	 *
	 * @param string $base_currency Uppercase ISO 4217 base currency code.
	 * @return string Fully qualified request URL.
	 */
	protected function build_url( string $base_currency ): string {
		return sprintf( self::API_URL, rawurlencode( (string) $this->api_key ), rawurlencode( $base_currency ) );
	}

	/**
	 * Inspect a decoded response for the OpenExchangeRates error envelope.
	 *
	 * @param array<string, mixed> $data Decoded response body.
	 * @return string|null Error description, or null when the response is healthy.
	 */
	protected function detect_api_error( array $data ): ?string {
		if ( empty( $data['error'] ) ) {
			return null;
		}

		$message     = isset( $data['message'] ) && is_scalar( $data['message'] ) ? (string) $data['message'] : '';
		$description = isset( $data['description'] ) && is_scalar( $data['description'] ) ? (string) $data['description'] : '';
		$details     = trim( $message . ' ' . $description );

		return '' !== $details ? $details : $this->describe_error_object( $data['error'] );
	}
}
