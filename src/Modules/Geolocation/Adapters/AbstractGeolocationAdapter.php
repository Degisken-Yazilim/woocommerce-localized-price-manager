<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Adapters;

use D6N\LPM\Core\Contracts\IGeolocation;
use D6N\LPM\Core\Contracts\IHttpRequest;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Core\Shared\HttpRequest;
use D6N\LPM\Core\Shared\Utils;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour for every geolocation adapter.
 */
abstract class AbstractGeolocationAdapter implements IGeolocation {

	/**
	 * Log source used by every adapter.
	 *
	 * @var string
	 */
	protected const LOG_SOURCE = 'Geolocation';

	/**
	 * HTTP client used for remote lookups.
	 *
	 * @var IHttpRequest
	 */
	protected IHttpRequest $http;

	/**
	 * Constructor.
	 *
	 * @param ILogger|null      $logger Logger instance.
	 * @param IHttpRequest|null $http   HTTP client, defaults to the WordPress HTTP API wrapper.
	 */
	public function __construct(
		protected readonly ?ILogger $logger = null,
		?IHttpRequest $http = null
	) {
		$this->http = $http ?? new HttpRequest();
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	abstract public function get_name(): string;

	/**
	 * Check if provider is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Detect country code from IP address (alias method).
	 *
	 * @param string $ip Client IP address.
	 * @return string|null Two-letter ISO country code or null if lookup fails.
	 */
	public function get_country( string $ip ): ?string {
		return $this->get_country_by_ip( $ip );
	}

	/**
	 * Detect country code from IP address.
	 *
	 * @param string $ip Client IP address.
	 * @return string|null Two-letter ISO country code or null if lookup fails.
	 */
	final public function get_country_by_ip( string $ip ): ?string {
		$ip = trim( $ip );

		if ( '' === $ip || ! $this->is_available() ) {
			return null;
		}

		if ( $this->requires_public_ip() && ! Utils::is_public_ip( $ip ) ) {
			$this->log(
				sprintf( '%s lookup skipped: client address is not publicly routable.', $this->get_name() ),
				LogLevelEnum::Debug
			);

			return null;
		}

		try {
			$country = $this->lookup( $ip );
		} catch ( Throwable $e ) {
			$this->log(
				sprintf( '%s lookup failed: %s', $this->get_name(), $e->getMessage() ),
				LogLevelEnum::Warning
			);

			return null;
		}

		$normalized = Utils::normalize_country_code( $country );

		if ( null === $normalized && null !== $country && '' !== $country ) {
			$this->log(
				sprintf( '%s returned an unusable country code.', $this->get_name() ),
				LogLevelEnum::Notice
			);
		}

		return $normalized;
	}

	/**
	 * Perform the provider specific lookup.
	 *
	 * @param string $ip Client IP address, already validated.
	 * @return string|null Raw country code reported by the provider.
	 */
	abstract protected function lookup( string $ip ): ?string;

	/**
	 * Whether the provider needs a publicly routable address to answer.
	 *
	 * @return bool
	 */
	protected function requires_public_ip(): bool {
		return true;
	}

	/**
	 * Perform a GET request and decode the JSON payload.
	 *
	 * @param string $url Fully built request URL.
	 * @return array<string, mixed>|null Decoded payload, or null when the call failed.
	 */
	protected function request_json( string $url ): ?array {
		$response = $this->http->get( $url );

		if ( ! $response->is_successful() ) {
			$this->log(
				sprintf(
					'%s request failed with status %d%s.',
					$this->get_name(),
					$response->get_status_code(),
					null !== $response->get_error() ? ': ' . $response->get_error() : ''
				),
				LogLevelEnum::Warning
			);

			return null;
		}

		$data = $response->get_json();

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Write an entry to the external service log channel.
	 *
	 * @param string       $message Event description.
	 * @param LogLevelEnum $level   Severity of the event.
	 * @return void
	 */
	protected function log( string $message, LogLevelEnum $level = LogLevelEnum::Warning ): void {
		$this->logger?->service( $message, array( 'provider' => $this->get_name() ), self::LOG_SOURCE, $level );
	}
}
