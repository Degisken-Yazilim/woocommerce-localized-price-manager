<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Adapters;

use MaxMind\Db\Reader;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * MaxMind geolocation adapter backed by the WooCommerce integration.
 */
class MaxMindGeolocationAdapter extends AbstractGeolocationAdapter {

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'MaxMind';
	}

	/**
	 * Check if provider is available.
	 *
	 * @return bool True when a readable MaxMind database is present.
	 */
	public function is_available(): bool {
		return '' !== $this->get_database_path() && class_exists( Reader::class );
	}

	/**
	 * Local database lookups work for any address.
	 *
	 * @return bool
	 */
	protected function requires_public_ip(): bool {
		return false;
	}

	/**
	 * Perform the provider specific lookup.
	 *
	 * @param string $ip Client IP address.
	 * @return string|null Raw country code reported by the provider.
	 */
	protected function lookup( string $ip ): ?string {
		$reader = new Reader( $this->get_database_path() );
		$record = $reader->get( $ip );

		if ( ! is_array( $record ) ) {
			return null;
		}

		$code = $record['country']['iso_code'] ?? null;

		return is_string( $code ) ? $code : null;
	}

	/**
	 * Resolve the path of the WooCommerce managed MaxMind database.
	 *
	 * @return string Absolute readable path, or an empty string when unavailable.
	 */
	private function get_database_path(): string {
		try {
			$integration = WC()->integrations->get_integration( 'maxmind_geolocation' );

			if ( ! is_object( $integration ) || ! method_exists( $integration, 'get_database_service' ) ) {
				return '';
			}

			$service = $integration->get_database_service();

			if ( ! is_object( $service ) || ! method_exists( $service, 'get_database_path' ) ) {
				return '';
			}

			$path = (string) $service->get_database_path();
		} catch ( Throwable $e ) {
			return '';
		}

		if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		return $path;
	}
}
