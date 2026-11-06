<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Money;

use D6N\LPM\Core\Shared\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Validates a submitted number against a column's own precision, as text.
 */
class DecimalText {

	/**
	 * Reduce a value to canonical decimal text, or refuse it.
	 *
	 * @param mixed $raw                 Raw value of any shape.
	 * @param int   $max_integer_digits  Digits allowed before the decimal point.
	 * @param int   $scale               Digits allowed after it.
	 * @param bool  $allow_zero          Whether zero is a meaningful value here.
	 * @return numeric-string|null Canonical text, or null when the value is not acceptable.
	 */
	public static function canonical( mixed $raw, int $max_integer_digits, int $scale, bool $allow_zero ): ?string {
		$text = self::plain( $raw );

		if ( null === $text ) {
			return null;
		}

		$negative = str_starts_with( $text, '-' );
		$body     = $negative ? substr( $text, 1 ) : $text;
		$parts    = explode( '.', $body, 2 );
		$fraction = $parts[1] ?? '';

		if ( strlen( $parts[0] ) > $max_integer_digits || strlen( $fraction ) > $scale ) {
			return null;
		}

		if ( $negative ) {
			return null;
		}

		if ( '0' === $text && ! $allow_zero ) {
			return null;
		}

		return $text;
	}

	/**
	 * Reduce a machine-written number, exponent notation included, to canonical decimal text.
	 *
	 * @param mixed $raw Raw value of any shape.
	 * @return numeric-string|null Canonical text, or null when the value is not a number.
	 */
	public static function expand( mixed $raw ): ?string {
		if ( is_float( $raw ) ) {
			$raw = is_finite( $raw ) ? sprintf( '%.15g', $raw ) : null;
		}

		if ( ! is_string( $raw ) || 1 !== preg_match( '/^\s*(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d{1,3})\s*$/', $raw, $matches ) ) {
			return self::plain( $raw );
		}

		$digits = $matches[2] . $matches[3];
		$point  = strlen( $matches[2] ) + (int) $matches[4];
		$length = strlen( $digits );

		if ( $point <= 0 ) {
			$body = '0.' . str_repeat( '0', -$point ) . $digits;
		} elseif ( $point >= $length ) {
			$body = $digits . str_repeat( '0', $point - $length );
		} else {
			$body = substr( $digits, 0, $point ) . '.' . substr( $digits, $point );
		}

		return self::plain( $matches[1] . $body );
	}

	/**
	 * Reduce a value to canonical decimal text without bounding its magnitude.
	 *
	 * @param mixed $raw Raw value of any shape.
	 * @return numeric-string|null Canonical text, or null when the value is not a plain decimal.
	 */
	public static function plain( mixed $raw ): ?string {
		$text = Utils::normalize_decimal_string( $raw );

		if ( null === $text ) {
			return null;
		}

		if ( 1 !== preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $text, $matches ) ) {
			return null;
		}

		$digits   = ltrim( $matches[2], '0' );
		$fraction = isset( $matches[3] ) ? rtrim( $matches[3], '0' ) : '';
		$is_zero  = '' === $digits && '' === $fraction;
		$sign     = ( '-' === $matches[1] && ! $is_zero ) ? '-' : '';

		$canonical = $sign . ( '' === $digits ? '0' : $digits ) . ( '' === $fraction ? '' : '.' . $fraction );

		return is_numeric( $canonical ) ? $canonical : null;
	}
}
