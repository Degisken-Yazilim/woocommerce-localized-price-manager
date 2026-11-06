<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * Data sanitization functionality for repositories.
 */
trait HasSanitization {

	/**
	 * Sanitize data before database operations.
	 *
	 * @param array<string, mixed> $data Input data.
	 * @return array<string, mixed> Sanitized data.
	 */
	protected function sanitize_data( array $data ): array {
		return array_map(
			static function ( $value ) {
				return is_string( $value ) ? sanitize_text_field( $value ) : $value;
			},
			$data
		);
	}
}
