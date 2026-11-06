<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for repository pattern operations.
 */
interface IRepository {
	/**
	 * Get all records as array collections.
	 *
	 * @return array<array<string, mixed>>|WP_Error Collection, or WP_Error on failure.
	 */
	public function get_all(): array|WP_Error;

	/**
	 * Create a new record.
	 *
	 * @param array<string, mixed> $data Attribute data.
	 * @return int|WP_Error Inserted ID, or WP_Error on failure.
	 */
	public function create_record( array $data ): int|WP_Error;

	/**
	 * Update an existing record.
	 *
	 * @param int                  $id   Primary key value.
	 * @param array<string, mixed> $data Attribute data.
	 * @return int|WP_Error Number of affected rows, or WP_Error on failure.
	 */
	public function update_record( int $id, array $data ): int|WP_Error;

	/**
	 * Delete a record by primary key.
	 *
	 * @param int $id Primary key value.
	 * @return int|WP_Error Number of deleted rows, or WP_Error on failure.
	 */
	public function delete_record( int $id ): int|WP_Error;
}
