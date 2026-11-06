<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for domain entity representations.
 */
interface IEntity {

	/**
	 * Validate data against fillable and nullable rules.
	 *
	 * @param array<string, mixed>|object $data      Data to validate.
	 * @param bool                        $is_update True for update operation.
	 * @return bool
	 */
	public function validate( array|object $data, bool $is_update = false ): bool;

	/**
	 * Convert entity attributes to array.
	 *
	 * @param object $row Database row object.
	 * @return array<string, mixed>
	 */
	public function to_array( object $row ): array;
}
