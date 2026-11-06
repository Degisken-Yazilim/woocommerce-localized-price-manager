<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Shared;

use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\IPDetectionStrategyEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Utility class providing helper functions.
 */
class Utils {

	/**
	 * Request-scoped cache of the known country code list.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $known_country_codes = null;

	/**
	 * Round currency amount with floating-point drift protection.
	 *
	 * @param float|string $amount   Target amount.
	 * @param int          $decimals Decimal precision.
	 * @return float Rounded currency amount.
	 */
	public static function round_precision( float|string $amount, int $decimals = 2 ): float {
		$str_amount = (string) $amount;

		if ( is_numeric( $str_amount ) && extension_loaded( 'bcmath' ) && function_exists( 'bcadd' ) ) {
			$padded = bcadd( $str_amount, '0', $decimals + 4 );
			return round( (float) $padded, $decimals, PHP_ROUND_HALF_UP );
		}

		return round( (float) $amount, $decimals, PHP_ROUND_HALF_UP );
	}

	/**
	 * Get the client IP address from various HTTP headers.
	 *
	 * @return string|null Client IP address or null if not found.
	 */
	public static function get_client_ip(): ?string {
		return self::get_client_ip_with_strategy( IPHeaderConfig::effective_strategy() );
	}

	/**
	 * Get the client IP address using a specific detection strategy.
	 *
	 * @param IPDetectionStrategyEnum $strategy The detection strategy to use.
	 * @return string|null Client IP address or null if not found.
	 */
	public static function get_client_ip_with_strategy( IPDetectionStrategyEnum $strategy ): ?string {
		$headers     = IPHeaderConfig::get_headers_for_strategy( $strategy );
		$remote_addr = self::read_server_value( IPHeaderConfig::REMOTE_ADDR );

		foreach ( $headers as $header ) {
			$raw_header = self::read_server_value( $header );

			if ( '' === $raw_header ) {
				continue;
			}

			$forwarded = IPHeaderConfig::needs_verification( $header );
			$ip_list   = array_map( 'trim', explode( ',', $raw_header ) );

			foreach ( $ip_list as $ip ) {
				$ip        = self::strip_port( $ip );
				$is_usable = $forwarded ? self::is_public_ip( $ip ) : self::is_valid_ip( $ip );

				if ( ! $is_usable ) {
					continue;
				}

				if ( $forwarded && ! self::is_forwarded_ip_trusted( $ip, $header, $remote_addr, $strategy ) ) {
					continue;
				}

				return $ip;
			}
		}

		return null;
	}

	/**
	 * Describe the IP detection context of the current request.
	 *
	 * @param IPDetectionStrategyEnum|null $strategy Strategy whose headers were inspected.
	 * @return array<string, mixed> Diagnostic context.
	 */
	public static function describe_ip_detection( ?IPDetectionStrategyEnum $strategy = null ): array {
		$strategy    = $strategy ?? IPDetectionStrategyEnum::fallback();
		$remote_addr = self::read_server_value( IPHeaderConfig::REMOTE_ADDR );
		$present     = array();

		foreach ( IPHeaderConfig::get_headers_for_strategy( $strategy ) as $header ) {
			if ( '' !== self::read_server_value( $header ) ) {
				$present[] = $header;
			}
		}

		return array(
			'strategy'           => $strategy->value,
			'headers_present'    => $present,
			'remote_addr_valid'  => self::is_valid_ip( $remote_addr ),
			'remote_addr_public' => self::is_public_ip( $remote_addr ),
			'trusted_headers'    => IPHeaderConfig::get_trusted_headers(),
		);
	}

	/**
	 * Validate if a string is a syntactically valid IP address.
	 *
	 * @param string $ip The IP address to validate.
	 * @return bool True if the value is a well formed IPv4 or IPv6 address.
	 */
	public static function is_valid_ip( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * Validate if a string is a routable, publicly reachable IP address.
	 *
	 * @param string $ip The IP address to validate.
	 * @return bool True if the value is a public IP address.
	 */
	public static function is_public_ip( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Validate a two letter ISO 3166-1 alpha-2 country code.
	 *
	 * @param mixed $code Candidate country code.
	 * @return bool True when the code is a usable country code.
	 */
	public static function is_valid_country_code( mixed $code ): bool {
		return null !== self::normalize_country_code( $code );
	}

	/**
	 * Check whether a normalised two-letter code sits in a reserved or
	 * user-assigned ISO block that can never name a real country.
	 *
	 * @param string $code Upper-case two-letter code.
	 * @return bool True when the code is reserved and must be rejected.
	 */
	public static function is_reserved_country_code( string $code ): bool {
		return 1 === preg_match( '/^(AA|Q[M-Z]|X[A-JL-Z]|ZZ|T1|EU|AP)$/', $code );
	}

	/**
	 * Normalize and validate a country code.
	 *
	 * @param mixed $code Candidate country code.
	 * @return string|null Uppercase country code, or null when it is not valid.
	 */
	public static function normalize_country_code( mixed $code ): ?string {
		if ( ! is_string( $code ) ) {
			return null;
		}

		$code = strtoupper( trim( $code ) );

		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return null;
		}

		if ( self::is_reserved_country_code( $code ) ) {
			return null;
		}

		$known = self::get_known_country_codes();

		if ( array() !== $known && ! in_array( $code, $known, true ) ) {
			return null;
		}

		return $code;
	}

	/**
	 * Get every country code WooCommerce knows about.
	 *
	 * @return array<int, string> Uppercase country codes, empty when the store publishes no list.
	 */
	public static function get_known_country_codes(): array {
		if ( null !== self::$known_country_codes ) {
			return self::$known_country_codes;
		}

		$list = WC()->countries->get_countries();

		if ( array() === $list ) {
			return array();
		}

		self::$known_country_codes = array_map( 'strtoupper', array_map( 'strval', array_keys( $list ) ) );

		return self::$known_country_codes;
	}

	/**
	 * Read and sanitize a `$_SERVER` value.
	 *
	 * @param string $key Server key.
	 * @return string Sanitized value, empty string when the key is absent.
	 */
	private static function read_server_value( string $key ): string {
		if ( empty( $_SERVER[ $key ] ) ) {
			return '';
		}

		return trim( (string) sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
	}

	/**
	 * Strip a trailing port from an IPv4 address.
	 *
	 * @param string $ip Raw address, possibly `1.2.3.4:8080`.
	 * @return string Address without the port.
	 */
	private static function strip_port( string $ip ): string {
		if ( str_contains( $ip, '.' ) && str_contains( $ip, ':' ) ) {
			return explode( ':', $ip )[0];
		}

		return $ip;
	}

	/**
	 * Decide whether a forwarded address may be used as the client IP.
	 *
	 * @param string                  $ip          Candidate address taken from the header.
	 * @param string                  $header      Server key the address came from.
	 * @param string                  $remote_addr TCP peer address of the current request.
	 * @param IPDetectionStrategyEnum $strategy    Strategy this resolution is running under.
	 * @return bool True when the address is trustworthy.
	 */
	private static function is_forwarded_ip_trusted( string $ip, string $header, string $remote_addr, IPDetectionStrategyEnum $strategy ): bool {
		/**
		 * Filters whether a forwarded address may be used as the client IP.
		 *
		 * @param bool   $trusted Whether the plugin's own checks passed.
		 * @param string $ip      Candidate address taken from the header.
		 * @param string $header  Server key the address came from.
		 */
		$trust = apply_filters(
			Core::build_hook( HookEnum::TrustCDNHeaderIP->value ),
			IPHeaderConfig::is_header_trusted( $header, $remote_addr, $strategy ),
			$ip,
			$header
		);

		return (bool) $trust;
	}

	/**
	 * Parse a value to integer.
	 *
	 * @param mixed $value The value to parse.
	 * @return int|null Parsed integer or null if not numeric.
	 */
	public static function parse_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$value = trim( $value );
			$value = str_replace( array( ',', ' ' ), '', $value );
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$float_value = round( (float) $value );

		if ( $float_value > PHP_INT_MAX || $float_value < PHP_INT_MIN ) {
			return null;
		}

		return (int) $float_value;
	}

	/**
	 * Parse a value to float.
	 *
	 * @param mixed $value The value to parse.
	 * @return float|null Parsed float or null if not numeric.
	 */
	public static function parse_float( mixed $value ): ?float {
		if ( is_float( $value ) || is_int( $value ) ) {
			return (float) $value;
		}

		$normalized = self::normalize_decimal_string( $value );

		return null === $normalized ? null : (float) $normalized;
	}

	/**
	 * Reduce a submitted number to plain decimal text, without casting it.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string|null Plain decimal text, or null when the value is not a number.
	 */
	public static function normalize_decimal_string( mixed $value ): ?string {
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return null;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( str_contains( $value, '.' ) && str_contains( $value, ',' ) ) {
			$value = strrpos( $value, ',' ) > strrpos( $value, '.' )
				? str_replace( array( '.', ',' ), array( '', '.' ), $value )
				: str_replace( ',', '', $value );
		} elseif ( str_contains( $value, ',' ) ) {
			$value = substr_count( $value, ',' ) > 1
				? str_replace( ',', '', $value )
				: str_replace( ',', '.', $value );
		} elseif ( substr_count( $value, '.' ) > 1 ) {
			$value = str_replace( '.', '', $value );
		}

		return is_numeric( $value ) ? $value : null;
	}

	/**
	 * Parse a value to boolean.
	 *
	 * @param mixed $value The value to parse.
	 * @return bool|null Parsed boolean or null if value is not a recognized boolean representation.
	 */
	public static function parse_bool( mixed $value ): ?bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}
}
