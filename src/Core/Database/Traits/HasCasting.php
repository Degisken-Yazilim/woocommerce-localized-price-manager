<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Traits;

use D6N\LPM\Core\Money\DecimalText;
use D6N\LPM\Core\Shared\Utils;
use DateTimeInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Trait for type casting model attributes.
 */
trait HasCasting {

	/**
	 * Default casts applied to every model on top of its own $casts.
	 *
	 * @return array<string, string>
	 */
	private function get_effective_casts(): array {
		return array_merge(
			array(
				'id'         => 'int',
				'is_active'  => 'bool',
				'created_at' => 'datetime',
				'updated_at' => 'datetime',
			),
			$this->casts
		);
	}

	/**
	 * Apply type casting to row data.
	 *
	 * @param array<string, mixed>|object $row Row data.
	 * @return object|array<string, mixed> Cast row data.
	 */
	protected function apply_casts( array|object $row ): object|array {
		if ( empty( $row ) ) {
			return $row;
		}

		$casts = $this->get_effective_casts();

		foreach ( $casts as $key => $type ) {
			if ( is_array( $row ) && ! array_key_exists( $key, $row ) ) {
				continue;
			}
			if ( is_object( $row ) && ! property_exists( $row, $key ) ) {
				continue;
			}

			$value = is_array( $row ) ? $row[ $key ] : $row->$key;
			if ( null === $value ) {
				is_array( $row ) ? $row[ $key ] = null : $row->$key = null;
				continue;
			}

			$value = match ( $type ) {
				'int'      => Utils::parse_int( $value ),
				'float'    => Utils::parse_float( $value ),
				'decimal'  => $this->cast_decimal( $value ),
				'bool'     => Utils::parse_bool( $value ),
				'json'     => is_string( $value ) ? json_decode( $value, true ) : (array) $value,
				'datetime' => $this->cast_datetime_for_read( $value ),
				default    => $this->cast_to_string( $value ),
			};

			is_array( $row ) ? $row[ $key ] = $value : $row->$key = $value;
		}

		return $row;
	}

	/**
	 * Format data for database insertion.
	 *
	 * @param array<string, mixed> $data Input data.
	 * @return array<string, mixed> Formatted data.
	 */
	protected function format_for_database( array $data ): array {
		if ( empty( $data ) ) {
			return $data;
		}

		$casts = $this->get_effective_casts();

		foreach ( $data as $key => $value ) {
			if ( null === $value && in_array( $key, $this->nullable, true ) ) {
				$data[ $key ] = null;
				continue;
			}

			if ( ! array_key_exists( $key, $casts ) || null === $value ) {
				continue;
			}

			$type = $casts[ $key ];

			$value = match ( $type ) {
				'int'      => Utils::parse_int( $value ),
				'float'    => Utils::parse_float( $value ),
				'decimal'  => $this->cast_decimal( $value ),
				'bool'     => Utils::parse_bool( $value ),
				'json'     => wp_json_encode( $value ),
				'datetime' => $this->cast_datetime_for_storage( $value ),
				default    => $this->cast_to_string( $value ),
			};

			$data[ $key ] = $value;
		}

		return $data;
	}

	/**
	 * Keep a `decimal` column as the digits it holds, in both directions.
	 *
	 * @param mixed $value Raw column or input value.
	 * @return mixed Canonical decimal text, or the untouched value when it is not one.
	 */
	private function cast_decimal( mixed $value ): mixed {
		return DecimalText::plain( $value ) ?? $value;
	}

	/**
	 * Return a stored datetime value unchanged.
	 *
	 * @param mixed $value Raw column value.
	 * @return mixed Stored string, or the untouched value when it is not scalar.
	 */
	private function cast_datetime_for_read( mixed $value ): mixed {
		if ( $value instanceof DateTimeInterface ) {
			return $value->format( 'Y-m-d H:i:s' );
		}

		return is_scalar( $value ) ? (string) $value : $value;
	}

	/**
	 * Normalise a datetime value to a UTC `Y-m-d H:i:s` string for storage.
	 *
	 * @param mixed $value Incoming value (string, timestamp or DateTimeInterface).
	 * @return mixed UTC datetime string, or the untouched value when unparsable.
	 */
	private function cast_datetime_for_storage( mixed $value ): mixed {
		if ( $value instanceof DateTimeInterface ) {
			return gmdate( 'Y-m-d H:i:s', $value->getTimestamp() );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $value );
		}

		if ( ! is_string( $value ) ) {
			return $value;
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? $value : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Cast a value to string without triggering "Array to string conversion".
	 *
	 * @param mixed $value Value to cast.
	 * @return mixed String representation, or the untouched value when it has none.
	 */
	private function cast_to_string( mixed $value ): mixed {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
			return (string) $value;
		}

		return $value;
	}
}
