<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Support;

use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Type-preserving JSON codec for cache payloads.
 */
final class PayloadCodec {

	/**
	 * Key wrapping the properties of an encoded stdClass.
	 */
	public const OBJECT_MARKER = '__d6n_lpm_object';

	/**
	 * Maximum nesting depth accepted on both sides.
	 */
	private const DEPTH = 512;

	/**
	 * Encode a payload into a JSON string.
	 *
	 * @param mixed $value Scalar, null, array or stdClass, nested to any supported depth.
	 * @return string JSON payload.
	 * @throws InvalidArgumentException When the value holds a type the codec cannot restore exactly.
	 */
	public static function encode( mixed $value ): string {
		try {
			$encoded = wp_json_encode( self::prepare( $value ), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR, self::DEPTH );
		} catch ( JsonException $e ) {
			throw new InvalidArgumentException( esc_html( 'Cache payload could not be JSON encoded: ' . $e->getMessage() ) );
		}

		if ( ! is_string( $encoded ) ) {
			throw new InvalidArgumentException( 'Cache payload could not be JSON encoded.' );
		}

		return $encoded;
	}

	/**
	 * Decode a JSON string produced by encode().
	 *
	 * @param string $raw JSON payload.
	 * @return mixed The value exactly as it was encoded.
	 * @throws UnexpectedValueException When the payload is not valid JSON.
	 */
	public static function decode( string $raw ): mixed {
		try {
			$decoded = json_decode( $raw, true, self::DEPTH, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new UnexpectedValueException( esc_html( 'Cache payload could not be JSON decoded: ' . $e->getMessage() ) );
		}

		return self::restore( $decoded );
	}

	/**
	 * Convert a value into its JSON-ready form, marking every stdClass.
	 *
	 * @param mixed $value Value to prepare.
	 * @return mixed JSON-ready value.
	 * @throws InvalidArgumentException When the value cannot be restored exactly.
	 */
	private static function prepare( mixed $value ): mixed {
		if ( $value instanceof stdClass ) {
			return array( self::OBJECT_MARKER => self::prepare_children( get_object_vars( $value ) ) );
		}

		if ( is_array( $value ) ) {
			if ( self::is_marked( $value ) ) {
				throw new InvalidArgumentException( 'Cache payload arrays may not use the reserved object marker as their only key.' );
			}

			return self::prepare_children( $value );
		}

		if ( is_string( $value ) ) {
			self::assert_utf8( $value );
			return $value;
		}

		if ( is_float( $value ) && ! is_finite( $value ) ) {
			throw new InvalidArgumentException( 'Cache payload floats must be finite.' );
		}

		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}

		throw new InvalidArgumentException( esc_html( sprintf( 'Cache payload type %s is not supported.', get_debug_type( $value ) ) ) );
	}

	/**
	 * Prepare every member of an array or object.
	 *
	 * @param array<int|string, mixed> $children Members keyed by index or name.
	 * @return array<int|string, mixed> Prepared members.
	 * @throws InvalidArgumentException When a member cannot be restored exactly.
	 */
	private static function prepare_children( array $children ): array {
		$prepared = array();

		foreach ( $children as $key => $child ) {
			if ( is_string( $key ) ) {
				self::assert_utf8( $key );
			}

			$prepared[ $key ] = self::prepare( $child );
		}

		return $prepared;
	}

	/**
	 * Rebuild the original value from decoded JSON.
	 *
	 * @param mixed $value Decoded value.
	 * @return mixed Restored value.
	 */
	private static function restore( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( self::is_marked( $value ) && is_array( $value[ self::OBJECT_MARKER ] ) ) {
			return (object) self::restore_children( $value[ self::OBJECT_MARKER ] );
		}

		return self::restore_children( $value );
	}

	/**
	 * Restore every member of a decoded array.
	 *
	 * @param array<int|string, mixed> $children Decoded members.
	 * @return array<int|string, mixed> Restored members.
	 */
	private static function restore_children( array $children ): array {
		foreach ( $children as $key => $child ) {
			$children[ $key ] = self::restore( $child );
		}

		return $children;
	}

	/**
	 * Check whether an array has the shape of an encoded stdClass.
	 *
	 * @param array<int|string, mixed> $value Array to inspect.
	 * @return bool
	 */
	private static function is_marked( array $value ): bool {
		return 1 === count( $value ) && array_key_exists( self::OBJECT_MARKER, $value );
	}

	/**
	 * Refuse a string JSON cannot carry unchanged.
	 *
	 * @param string $value String to check.
	 * @return void
	 * @throws InvalidArgumentException When the string is not valid UTF-8.
	 */
	private static function assert_utf8( string $value ): void {
		if ( 1 !== preg_match( '//u', $value ) ) {
			throw new InvalidArgumentException( 'Cache payload strings must be valid UTF-8.' );
		}
	}
}
