<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for geolocation service adapters.
 */
interface IGeolocation {

	/**
	 * Detect country code from IP address.
	 *
	 * @param string $ip Client IP address.
	 * @return string|null Two-letter ISO country code or null if lookup fails.
	 */
	public function get_country_by_ip( string $ip ): ?string;

	/**
	 * Detect country code from IP address (alias method).
	 *
	 * @param string $ip Client IP address.
	 * @return string|null Two-letter ISO country code or null if lookup fails.
	 */
	public function get_country( string $ip ): ?string;

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Check if provider is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool;
}
