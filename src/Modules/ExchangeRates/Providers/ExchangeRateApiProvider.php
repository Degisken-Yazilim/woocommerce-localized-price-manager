<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * ExchangeRate-API.com provider.
 *
 * @link https://www.exchangerate-api.com/docs/overview
 */
class ExchangeRateApiProvider extends AbstractExchangeRateProvider {

	private const API_URL = 'https://v6.exchangerate-api.com/v6/%s/latest/%s';

	/**
	 * Get the static description of this provider.
	 *
	 * @return ProviderMetadata Provider description.
	 */
	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata(
			key: 'exchangerate_api',
			name: 'ExchangeRate-API.com',
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
	 * Get the response field holding the `code => rate` map.
	 *
	 * @return string Response field name.
	 */
	protected function get_rates_field(): string {
		return 'conversion_rates';
	}

	/**
	 * Inspect a decoded response for the ExchangeRate-API.com error envelope.
	 *
	 * @param array<string, mixed> $data Decoded response body.
	 * @return string|null Error description, or null when the response is healthy.
	 */
	protected function detect_api_error( array $data ): ?string {
		if ( isset( $data['result'] ) && 'success' === $data['result'] ) {
			return null;
		}

		return $this->describe_error_object( $data['error-type'] ?? null );
	}
}
