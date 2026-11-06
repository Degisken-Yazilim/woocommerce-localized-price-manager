<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Arithmetic on money and rates that answers in decimal text.
 */
class DecimalMath {

	/**
	 * Multiply two decimal values at a given scale.
	 *
	 * @param string $amount Left operand as decimal text.
	 * @param string $factor Right operand as decimal text.
	 * @param int    $scale  Decimal places to compute to.
	 * @return numeric-string Product as decimal text, or '0' when either operand is not a number.
	 */
	public static function multiply( string $amount, string $factor, int $scale ): string {
		$left  = DecimalText::plain( $amount );
		$right = DecimalText::plain( $factor );

		if ( null === $left || null === $right ) {
			return '0';
		}

		$scale = max( 0, $scale );

		if ( self::has_bcmath() ) {
			return self::tidy( bcmul( $left, $right, $scale ) );
		}

		return self::from_float( (float) $left * (float) $right, $scale );
	}

	/**
	 * Add two decimal values at a given scale.
	 *
	 * @param string $left  First operand as decimal text.
	 * @param string $right Second operand as decimal text.
	 * @param int    $scale Decimal places to compute to.
	 * @return numeric-string Sum as decimal text, or '0' when either operand is not a number.
	 */
	public static function add( string $left, string $right, int $scale ): string {
		$first  = DecimalText::plain( $left );
		$second = DecimalText::plain( $right );

		if ( null === $first || null === $second ) {
			return '0';
		}

		$scale = max( 0, $scale );

		if ( self::has_bcmath() ) {
			return self::tidy( bcadd( $first, $second, $scale ) );
		}

		return self::from_float( (float) $first + (float) $second, $scale );
	}

	/**
	 * Divide one decimal value by another at a given scale.
	 *
	 * @param string $dividend Numerator as decimal text.
	 * @param string $divisor  Denominator as decimal text.
	 * @param int    $scale    Decimal places to compute to.
	 * @return numeric-string Quotient as decimal text, or '0' when the division is not defined.
	 */
	public static function divide( string $dividend, string $divisor, int $scale ): string {
		$left  = DecimalText::plain( $dividend );
		$right = DecimalText::plain( $divisor );

		if ( null === $left || null === $right || '0' === $right ) {
			return '0';
		}

		$scale = max( 0, $scale );

		if ( self::has_bcmath() ) {
			return self::tidy( (string) bcdiv( $left, $right, $scale ) );
		}

		return self::from_float( (float) $left / (float) $right, $scale );
	}

	/**
	 * Round a decimal value to a number of places, half away from zero.
	 *
	 * @param string $amount   Value as decimal text.
	 * @param int    $decimals Decimal places to keep.
	 * @return numeric-string Rounded value as decimal text, or '0' when the value is not a number.
	 */
	public static function round( string $amount, int $decimals ): string {
		$text = DecimalText::plain( $amount );

		if ( null === $text ) {
			return '0';
		}

		$decimals = max( 0, $decimals );

		if ( ! self::has_bcmath() ) {
			return self::from_float( round( (float) $text, $decimals, PHP_ROUND_HALF_UP ), $decimals );
		}

		$negative = str_starts_with( $text, '-' );
		$absolute = self::tidy( $negative ? substr( $text, 1 ) : $text );

		$half     = self::tidy( 0 === $decimals ? '0.5' : '0.' . str_repeat( '0', $decimals ) . '5' );
		$rendered = self::tidy( bcadd( $absolute, $half, $decimals ) );

		return ( $negative && '0' !== $rendered ) ? self::tidy( '-' . $rendered ) : $rendered;
	}

	/**
	 * Whether BC Math is available in this process.
	 *
	 * @return bool
	 */
	private static function has_bcmath(): bool {
		return extension_loaded( 'bcmath' ) && function_exists( 'bcmul' );
	}

	/**
	 * Render a float result as decimal text at a given scale.
	 *
	 * @param float $value    Computed value.
	 * @param int   $decimals Decimal places to render.
	 * @return numeric-string
	 */
	private static function from_float( float $value, int $decimals ): string {
		if ( ! is_finite( $value ) ) {
			return '0';
		}

		return self::tidy( number_format( $value, $decimals, '.', '' ) );
	}

	/**
	 * Reduce a computed result to canonical text.
	 *
	 * @param string $value Computed decimal text.
	 * @return numeric-string
	 */
	private static function tidy( string $value ): string {
		return DecimalText::plain( $value ) ?? '0';
	}
}
