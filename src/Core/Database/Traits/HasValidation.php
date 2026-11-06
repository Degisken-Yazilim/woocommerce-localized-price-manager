<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * Trait for validating model data.
 */
trait HasValidation {

	/**
	 * Validate data against fillable and nullable rules.
	 *
	 * @param array<string, mixed>|object $data      Data to validate.
	 * @param bool                        $is_update True for update operation.
	 * @return bool
	 */
	public function validate( array|object $data, bool $is_update = false ): bool {
		if ( empty( $this->fillable ) ) {
			return true;
		}

		$valid = true;

		foreach ( $this->fillable as $field ) {
			if ( in_array( $field, $this->nullable, true ) ) {
				continue;
			}

			if ( $is_update && ! array_key_exists( $field, (array) $data ) ) {
				continue;
			}

			$value = is_array( $data ) ? ( $data[ $field ] ?? null ) : ( $data->$field ?? null );

			if ( null === $value || '' === $value ) {
				$valid = false;
				break;
			}
		}

		return (bool) apply_filters( $this->build_hook( 'validate' ), $valid, $data, $is_update );
	}

	/**
	 * Filter data to only include fillable fields.
	 *
	 * @param array<string, mixed>|object $data Input data.
	 * @return array<string, mixed> Filtered data array.
	 */
	protected function filter_fillable( array|object $data ): array {
		$filtered = array();

		foreach ( $this->fillable as $key ) {
			if ( is_array( $data ) && array_key_exists( $key, $data ) ) {
				$filtered[ $key ] = $data[ $key ];
			} elseif ( is_object( $data ) && property_exists( $data, $key ) ) {
				$filtered[ $key ] = $data->$key;
			}
		}

		return $filtered;
	}
}
