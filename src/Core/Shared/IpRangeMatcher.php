<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Match an IP address against a newline separated list of approved ranges.
 */
class IpRangeMatcher {

	/**
	 * Normalise a multiline range list into its canonical stored form.
	 *
	 * @param string $text Raw textarea content.
	 * @return string Canonical newline separated list, empty when nothing valid remains.
	 */
	public static function sanitize_text( string $text ): string {
		$canonical = array();

		foreach ( self::lines( $text ) as $line ) {
			if ( ! self::is_valid_entry( $line ) ) {
				continue;
			}

			$normalised = strtolower( $line );

			if ( ! in_array( $normalised, $canonical, true ) ) {
				$canonical[] = $normalised;
			}
		}

		return implode( "\n", $canonical );
	}

	/**
	 * Check whether one address matches any entry of a range list.
	 *
	 * @param string $ip   Address to test, IPv4 or IPv6.
	 * @param string $text Newline separated list, as stored.
	 * @return bool True when the address falls inside at least one entry.
	 */
	public static function matches( string $ip, string $text ): bool {
		$packed = inet_pton( $ip );

		if ( false === $packed ) {
			return false;
		}

		foreach ( self::lines( $text ) as $entry ) {
			if ( self::entry_matches( $packed, strtolower( $entry ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether a single entry is syntactically valid.
	 *
	 * @param string $entry Entry candidate.
	 * @return bool True when the matcher would accept it.
	 */
	public static function is_valid_entry( string $entry ): bool {
		if ( '' === $entry || str_starts_with( $entry, '#' ) || strlen( $entry ) > 100 ) {
			return false;
		}

		if ( str_contains( $entry, '/' ) ) {
			return self::is_valid_cidr( $entry );
		}

		if ( str_contains( $entry, '*' ) ) {
			return self::is_valid_wildcard( $entry );
		}

		return false !== inet_pton( $entry );
	}

	/**
	 * Split raw text into trimmed, non-empty, non-comment lines.
	 *
	 * @param string $text Raw text.
	 * @return array<int, string> Entry candidates.
	 */
	private static function lines( string $text ): array {
		$lines      = array();
		$split      = preg_split( '/\r\n|\r|\n/', $text );
		$candidates = is_array( $split ) ? $split : array();

		foreach ( $candidates as $line ) {
			$line = trim( $line );

			if ( '' !== $line && ! str_starts_with( $line, '#' ) ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Match one packed address against one normalised entry.
	 *
	 * @param string $packed      Binary address from inet_pton().
	 * @param string $normalised  Lower-cased entry.
	 * @return bool True on match.
	 */
	private static function entry_matches( string $packed, string $normalised ): bool {
		$is_ipv6 = self::is_packed_ipv6( $packed );

		if ( str_contains( $normalised, '/' ) ) {
			return self::cidr_matches( $packed, $is_ipv6, $normalised );
		}

		if ( str_contains( $normalised, '*' ) ) {
			return ! $is_ipv6 && self::wildcard_matches( $packed, $normalised );
		}

		$entry_packed = inet_pton( $normalised );

		return false !== $entry_packed && $entry_packed === $packed;
	}

	/**
	 * Match a packed address against a CIDR entry of its own family.
	 *
	 * @param string $packed    Binary address.
	 * @param bool   $is_ipv6   Whether the address is IPv6.
	 * @param string $normalised Lower-cased CIDR entry.
	 * @return bool True on match.
	 */
	private static function cidr_matches( string $packed, bool $is_ipv6, string $normalised ): bool {
		$parts = explode( '/', $normalised, 2 );

		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[1] ) ) {
			return false;
		}

		$prefix = (int) $parts[1];
		$max    = $is_ipv6 ? 128 : 32;

		if ( $prefix > $max ) {
			return false;
		}

		$network = inet_pton( $parts[0] );

		if ( false === $network ) {
			return false;
		}

		if ( $is_ipv6 !== self::is_packed_ipv6( $network ) || strlen( $network ) !== strlen( $packed ) ) {
			return false;
		}

		return self::prefix_matches( $packed, $network, $prefix );
	}

	/**
	 * Decide the address family of a packed address.
	 *
	 * @param string $packed Binary address from inet_pton().
	 * @return bool True when the address is IPv6.
	 */
	private static function is_packed_ipv6( string $packed ): bool {
		return 16 === strlen( $packed );
	}

	/**
	 * Compare the leading bits of two equal-length binary addresses.
	 *
	 * @param string $packed Binary address.
	 * @param string $network Binary network address.
	 * @param int    $prefix Prefix length in bits.
	 * @return bool True when the prefixes agree.
	 */
	private static function prefix_matches( string $packed, string $network, int $prefix ): bool {
		$full_bytes = intdiv( $prefix, 8 );
		$remainder  = $prefix % 8;

		if ( $full_bytes > 0 && substr( $packed, 0, $full_bytes ) !== substr( $network, 0, $full_bytes ) ) {
			return false;
		}

		if ( 0 === $remainder ) {
			return true;
		}

		$mask = chr( ( 0xff << ( 8 - $remainder ) ) & 0xff );

		return ( ord( $packed[ $full_bytes ] ) & ord( $mask ) ) === ( ord( $network[ $full_bytes ] ) & ord( $mask ) );
	}

	/**
	 * Match a packed IPv4 address against a wildcard entry such as `203.0.*`.
	 *
	 * @param string $packed    Binary IPv4 address.
	 * @param string $normalised Lower-cased wildcard entry.
	 * @return bool True on match.
	 */
	private static function wildcard_matches( string $packed, string $normalised ): bool {
		if ( ! str_ends_with( $normalised, '*' ) ) {
			return false;
		}

		$given = explode( '.', rtrim( $normalised, '*.' ) );

		if ( count( $given ) > 4 || count( $given ) < 2 ) {
			return false;
		}

		$octets = unpack( 'C4', $packed );

		if ( false === $octets ) {
			return false;
		}

		foreach ( $given as $index => $part ) {
			if ( '' === $part || ! ctype_digit( $part ) || (int) $part > 255 ) {
				return false;
			}

			if ( (int) $part !== $octets[ $index + 1 ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a CIDR entry without matching anything.
	 *
	 * @param string $entry Entry containing a slash.
	 * @return bool True when well formed.
	 */
	private static function is_valid_cidr( string $entry ): bool {
		$parts = explode( '/', $entry, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || ! ctype_digit( $parts[1] ) ) {
			return false;
		}

		$is_ipv6 = str_contains( $parts[0], ':' );
		$max     = $is_ipv6 ? 128 : 32;

		if ( (int) $parts[1] > $max ) {
			return false;
		}

		$network = inet_pton( $parts[0] );

		return false !== $network
			&& $is_ipv6 === self::is_packed_ipv6( $network );
	}

	/**
	 * Validate a wildcard entry without matching anything.
	 *
	 * @param string $entry Entry containing an asterisk.
	 * @return bool True when well formed.
	 */
	private static function is_valid_wildcard( string $entry ): bool {
		if ( ! str_ends_with( $entry, '*' ) || substr_count( $entry, '*' ) !== 1 ) {
			return false;
		}

		$groups = explode( '.', rtrim( $entry, '*.' ) );

		if ( count( $groups ) < 2 || count( $groups ) > 4 ) {
			return false;
		}

		foreach ( $groups as $group ) {
			if ( '' === $group || ! ctype_digit( $group ) || (int) $group > 255 ) {
				return false;
			}
		}

		return true;
	}
}
