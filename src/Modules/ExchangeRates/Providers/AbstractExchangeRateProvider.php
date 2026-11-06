<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates\Providers;

use D6N\LPM\Core\Contracts\IExchangeRate;
use D6N\LPM\Core\Contracts\IHttpRequest;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Shared\HttpRequest;
use D6N\LPM\Core\Money\DecimalText;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for every exchange rate provider.
 */
abstract class AbstractExchangeRateProvider implements IExchangeRate {

	/**
	 * Log source used for every record this class family emits.
	 *
	 * @var string
	 */
	protected const LOG_SOURCE = 'ExchangeRates';

	/**
	 * HTTP client used for the upstream call.
	 *
	 * @var IHttpRequest
	 */
	protected IHttpRequest $http;

	/**
	 * Constructor.
	 *
	 * @param string|null       $api_key Decrypted API key, or null when the provider needs none.
	 * @param ILogger|null      $logger  Logger instance.
	 * @param IHttpRequest|null $http    HTTP client; a WordPress backed client is used when omitted.
	 */
	public function __construct(
		protected ?string $api_key = null,
		protected ?ILogger $logger = null,
		?IHttpRequest $http = null
	) {
		$this->http = $http ?? new HttpRequest();
	}

	/**
	 * Get the static description of this provider.
	 *
	 * @return ProviderMetadata Provider description.
	 */
	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata( 'unknown', 'Unknown', false );
	}

	/**
	 * Get the display name of this provider, also stored as the row `source`.
	 *
	 * @return string Provider name.
	 */
	public function get_name(): string {
		return static::metadata()->name;
	}

	/**
	 * Check whether the provider is usable with the current configuration.
	 *
	 * @return bool True when no key is required or a key is configured.
	 */
	public function is_available(): bool {
		if ( ! static::metadata()->requires_api_key ) {
			return true;
		}

		return null !== $this->api_key && '' !== trim( $this->api_key );
	}

	/**
	 * Fetch the rate set for a base currency.
	 *
	 * @param string $base_currency ISO 4217 base currency code.
	 * @return array<string, numeric-string>|null Normalised `code => rate` map, or null on any failure.
	 */
	public function get_rates( string $base_currency ): ?array {
		$base     = strtoupper( trim( $base_currency ) );
		$metadata = static::metadata();

		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $base ) ) {
			$this->log_service( sprintf( '%s: refused invalid base currency "%s".', $metadata->name, $base_currency ) );
			return null;
		}

		if ( ! $this->is_available() ) {
			$this->log_service( sprintf( '%s: no API key configured.', $metadata->name ) );
			return null;
		}

		try {
			$response = $this->http->get( $this->build_url( $base ) );

			if ( ! $response->is_successful() ) {
				$this->log_service(
					sprintf(
						'%s: HTTP request failed for base %s (status %d): %s',
						$metadata->name,
						$base,
						$response->get_status_code(),
						$response->get_error() ?? 'unexpected status code'
					)
				);
				return null;
			}

			$data = $this->decode_preserving_numbers( $response->get_body() );
			if ( ! is_array( $data ) ) {
				$this->log_service( sprintf( '%s: response for base %s was not valid JSON.', $metadata->name, $base ) );
				return null;
			}

			$api_error = $this->detect_api_error( $data );
			if ( null !== $api_error ) {
				$this->log_service( sprintf( '%s: API error for base %s: %s', $metadata->name, $base, $api_error ) );
				return null;
			}

			$field = $this->get_rates_field();
			if ( ! isset( $data[ $field ] ) || ! is_array( $data[ $field ] ) ) {
				$this->log_service( sprintf( '%s: response for base %s carried no "%s" map.', $metadata->name, $base, $field ) );
				return null;
			}

			$rates = $this->normalise_rates( $data[ $field ] );
			if ( empty( $rates ) ) {
				$this->log_service( sprintf( '%s: response for base %s carried no usable rate.', $metadata->name, $base ) );
				return null;
			}

			return $rates;
		} catch ( Throwable $e ) {
			$this->log_service( sprintf( '%s: exception while fetching base %s: %s', $metadata->name, $base, $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * Build the upstream request URL for a base currency.
	 *
	 * @param string $base_currency Uppercase ISO 4217 base currency code.
	 * @return string Fully qualified request URL.
	 */
	abstract protected function build_url( string $base_currency ): string;

	/**
	 * Inspect a decoded response for the provider specific error envelope.
	 *
	 * @param array<string, mixed> $data Decoded response body.
	 * @return string|null Error description, or null when the response is healthy.
	 */
	abstract protected function detect_api_error( array $data ): ?string;

	/**
	 * Get the response field holding the `code => rate` map.
	 *
	 * @return string Response field name.
	 */
	protected function get_rates_field(): string {
		return 'rates';
	}

	/**
	 * Decode a JSON body with every number kept as the text it was written in.
	 *
	 * @param string $body Raw response body.
	 * @return mixed Decoded payload, or null when the body is not valid JSON.
	 */
	private function decode_preserving_numbers( string $body ): mixed {
		if ( '' === trim( $body ) ) {
			return null;
		}

		$quoted = preg_replace_callback(
			'/"(?:[^"\\\\]++|\\\\.)*+"|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/s',
			static fn ( array $token ): string => str_starts_with( $token[0], '"' ) ? $token[0] : '"' . $token[0] . '"',
			$body
		);

		return null === $quoted ? null : json_decode( $quoted, true );
	}

	/**
	 * Normalise a raw rate map to uppercase codes and positive decimal text.
	 *
	 * @param array<mixed> $rates Raw rate map from the upstream response.
	 * @return array<string, numeric-string> Normalised rate map.
	 */
	protected function normalise_rates( array $rates ): array {
		$normalised = array();

		foreach ( $rates as $code => $rate ) {
			$currency = strtoupper( trim( (string) $code ) );
			if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				continue;
			}

			$parsed = DecimalText::expand( $rate );
			if ( null === $parsed || '0' === $parsed || str_starts_with( $parsed, '-' ) ) {
				continue;
			}

			$normalised[ $currency ] = $parsed;
		}

		return $normalised;
	}

	/**
	 * Describe the error object returned by an `apilayer` style provider.
	 *
	 * @param mixed $error Raw `error` member of the decoded response.
	 * @return string Human readable error description.
	 */
	protected function describe_error_object( mixed $error ): string {
		if ( is_array( $error ) ) {
			$code = isset( $error['code'] ) && is_scalar( $error['code'] ) ? (string) $error['code'] : '';
			$info = '';

			foreach ( array( 'info', 'message', 'type', 'description' ) as $field ) {
				if ( isset( $error[ $field ] ) && is_scalar( $error[ $field ] ) ) {
					$info = (string) $error[ $field ];
					break;
				}
			}

			$description = trim( $code . ' ' . $info );
			return '' !== $description ? $description : 'unsuccessful response';
		}

		if ( is_scalar( $error ) && '' !== (string) $error ) {
			return (string) $error;
		}

		return 'unsuccessful response';
	}

	/**
	 * Report a provider event on the logger's service channel.
	 *
	 * @param string               $message Event description.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	protected function log_service( string $message, array $context = array() ): void {
		$this->logger?->service( $message, $context, self::LOG_SOURCE );
	}
}
