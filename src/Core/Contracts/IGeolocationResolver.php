<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for Chain of Responsibility geolocation resolver.
 */
interface IGeolocationResolver {
	/**
	 * Resolve country code for an IP address across configured provider chain.
	 *
	 * @param string $ip_address Target IP address.
	 * @return string|null Resolved country code or null.
	 */
	public function resolve( string $ip_address ): ?string;
}
