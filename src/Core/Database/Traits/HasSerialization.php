<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Traits;

use D6N\LPM\Core\Exceptions\InvalidDataException;
use JsonException;

defined( 'ABSPATH' ) || exit;

/**
 * Trait for serializing model data to array and JSON formats.
 */
trait HasSerialization {

	/**
	 * Convert database row object to array.
	 *
	 * @param object $row Database row object.
	 * @return array<string, mixed> The row data as an associative array.
	 * @throws InvalidDataException If JSON conversion fails.
	 */
	public function to_array( object $row ): array {
		try {
			return json_decode( $this->encode_json( $row ), true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new InvalidDataException( esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Encode a value as JSON, treating a refusal to encode as invalid data.
	 *
	 * @param array<string, mixed>|object $data Value to encode.
	 * @return string JSON representation.
	 * @throws InvalidDataException If the value cannot be represented as JSON.
	 */
	private function encode_json( array|object $data ): string {
		$encoded = wp_json_encode( $data );

		if ( false === $encoded ) {
			throw new InvalidDataException( 'Value cannot be represented as JSON.' );
		}

		return $encoded;
	}

	/**
	 * Remove hidden fields from data array/object.
	 *
	 * @param array<string, mixed>|object $data Input data.
	 * @return array<string, mixed>|object Filtered data.
	 */
	protected function filter_hidden( array|object $data ): array|object {
		foreach ( $this->hidden as $key ) {
			if ( is_array( $data ) && array_key_exists( $key, $data ) ) {
				unset( $data[ $key ] );
			} elseif ( is_object( $data ) && property_exists( $data, $key ) ) {
				unset( $data->$key );
			}
		}

		return $data;
	}
}
