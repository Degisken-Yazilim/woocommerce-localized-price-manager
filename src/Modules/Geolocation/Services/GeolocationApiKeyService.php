<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Services;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\Geolocation\GeolocationAdapterRegistry;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for the per provider geolocation API keys.
 */
class GeolocationApiKeyService {

	/**
	 * Log source for this service.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'Geolocation';

	/**
	 * Constructor.
	 *
	 * @param ILogger|null $logger Logger instance.
	 */
	public function __construct(
		private readonly ?ILogger $logger = null
	) {}

	/**
	 * Get the decrypted API key of a provider.
	 *
	 * @param string $provider Provider key.
	 * @return string|null Plain-text key, or null when none is stored.
	 */
	public function get( string $provider ): ?string {
		$option = GeolocationAdapterRegistry::get_api_key_option( $provider );

		if ( null === $option ) {
			return null;
		}

		$stored = Core::get_option( $option, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		try {
			$plain = Core::decrypt( $stored );
		} catch ( Throwable $e ) {
			$this->logger?->error(
				sprintf( 'Could not decrypt the %s API key; re-enter it in the settings.', $provider ),
				array( 'provider' => $provider ),
				self::LOG_SOURCE
			);

			return null;
		}

		return '' !== $plain ? $plain : null;
	}

	/**
	 * Get the display form of a provider key.
	 *
	 * @param string $provider Provider key.
	 * @return string Masked key, empty string when none is stored.
	 */
	public function get_masked( string $provider ): string {
		$plain = $this->get( $provider );

		return null === $plain ? '' : Core::mask_api_key( $plain );
	}

	/**
	 * Store the API key of a provider.
	 *
	 * @param string $provider Provider key.
	 * @param string $raw_key  Value submitted by the administrator.
	 * @return bool True when the stored state is correct afterwards, false on failure.
	 */
	public function save( string $provider, string $raw_key ): bool {
		$option = GeolocationAdapterRegistry::get_api_key_option( $provider );

		if ( null === $option ) {
			return false;
		}

		$raw_key = Core::sanitize_api_key( $raw_key );

		if ( Core::is_masked_api_key( $raw_key ) ) {
			return true;
		}

		if ( '' === $raw_key ) {
			return $this->store( $option, '' );
		}

		if ( ! defined( 'AUTH_KEY' ) || ! function_exists( 'openssl_encrypt' ) ) {
			$this->logger?->error(
				sprintf( 'Refused to store the %s API key: encryption is unavailable on this installation.', $provider ),
				array( 'provider' => $provider ),
				self::LOG_SOURCE
			);

			return false;
		}

		try {
			$cipher = Core::encrypt( $raw_key );
		} catch ( Throwable $e ) {
			$this->logger?->error(
				sprintf( 'Could not encrypt the %s API key: %s', $provider, $e->getMessage() ),
				array( 'provider' => $provider ),
				self::LOG_SOURCE
			);

			return false;
		}

		return $this->store( $option, $cipher );
	}

	/**
	 * Remove the stored API key of a provider.
	 *
	 * @param string $provider Provider key.
	 * @return bool True when the key was cleared.
	 */
	public function delete( string $provider ): bool {
		$option = GeolocationAdapterRegistry::get_api_key_option( $provider );

		if ( null === $option ) {
			return false;
		}

		return $this->store( $option, '' );
	}

	/**
	 * Write a value to the option row.
	 *
	 * @param string $option Option name.
	 * @param string $value  Value to store.
	 * @return bool True when the option holds the value afterwards.
	 */
	private function store( string $option, string $value ): bool {
		if ( Core::save_option( $option, $value ) ) {
			return true;
		}

		return $value === (string) Core::get_option( $option, '' );
	}
}
