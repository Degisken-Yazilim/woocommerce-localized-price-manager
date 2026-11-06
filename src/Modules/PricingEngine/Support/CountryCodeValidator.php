<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\Support;

use D6N\LPM\Core\Shared\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Shared ISO 3166-1 alpha-2 country code validator.
 */
class CountryCodeValidator {

	/**
	 * Request-scoped cache of the known country code list.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $known_codes = null;

	/**
	 * Normalise a raw country code to upper-case with surrounding space removed.
	 *
	 * @param mixed $code Raw country code.
	 * @return string Normalised code, or an empty string when unusable.
	 */
	public static function normalize( mixed $code ): string {
		if ( ! is_string( $code ) ) {
			return '';
		}

		return strtoupper( trim( $code ) );
	}

	/**
	 * Check whether a country code is a real, WooCommerce-known ISO code.
	 *
	 * @param mixed $code Raw country code.
	 * @return bool True when the code may be used for pricing lookups.
	 */
	public static function is_valid( mixed $code ): bool {
		$normalized = self::normalize( $code );

		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $normalized ) ) {
			return false;
		}

		if ( Utils::is_reserved_country_code( $normalized ) ) {
			return false;
		}

		$known = self::get_known_codes();

		if ( empty( $known ) ) {
			return true;
		}

		return in_array( $normalized, $known, true );
	}

	/**
	 * Normalise and validate in one step.
	 *
	 * @param mixed $code Raw country code.
	 * @return string Valid upper-case code, or an empty string when invalid.
	 */
	public static function sanitize( mixed $code ): string {
		$normalized = self::normalize( $code );

		return self::is_valid( $normalized ) ? $normalized : '';
	}

	/**
	 * Get every country code WooCommerce knows about.
	 *
	 * @return array<int, string> Upper-case codes, empty when the store publishes no list.
	 */
	private static function get_known_codes(): array {
		if ( null !== self::$known_codes ) {
			return self::$known_codes;
		}

		self::$known_codes = array();

		$countries = WC()->countries->get_countries();

		if ( array() === $countries ) {
			return self::$known_codes;
		}

		self::$known_codes = array_map( 'strtoupper', array_map( 'strval', array_keys( $countries ) ) );

		return self::$known_codes;
	}
}
