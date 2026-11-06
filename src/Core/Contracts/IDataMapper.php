<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

use D6N\LPM\Core\Exceptions\InvalidDataException;
use D6N\LPM\Core\Exceptions\QueryException;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for database data access operations.
 */
interface IDataMapper {
	/**
	 * Get all records from the table.
	 *
	 * @return array<object> Array of database row objects.
	 * @throws QueryException If database query fails.
	 */
	public function all(): array;

	/**
	 * Create a new record in the database.
	 *
	 * @param array<string, mixed> $data The data to insert.
	 * @return int The inserted record ID.
	 * @throws InvalidDataException If data validation fails.
	 * @throws QueryException If database query fails.
	 */
	public function create( array $data ): int;

	/**
	 * Update an existing record by its primary key.
	 *
	 * @param int                  $id   The primary key value.
	 * @param array<string, mixed> $data The data to update.
	 * @return int Number of rows updated.
	 * @throws InvalidDataException If data validation fails.
	 * @throws QueryException If database query fails.
	 */
	public function update( int $id, array $data ): int;

	/**
	 * Update multiple records matching the given conditions.
	 *
	 * @param array<string, mixed>     $data       The data to update (column => value pairs).
	 * @param array<int|string, mixed> $conditions WHERE conditions; see the note above the interface.
	 * @return int Number of rows affected.
	 * @throws InvalidDataException If data or conditions are invalid.
	 * @throws QueryException If database query fails.
	 */
	public function update_where( array $data, array $conditions ): int;

	/**
	 * Delete a record by its primary key.
	 *
	 * @param int $id The primary key value.
	 * @return int Number of rows deleted.
	 * @throws QueryException If database query fails.
	 */
	public function delete( int $id ): int;

	/**
	 * Delete records matching conditions.
	 *
	 * @param array<int|string, mixed> $conditions The where conditions; see the note above the interface.
	 * @return int Number of rows deleted.
	 * @throws InvalidDataException If conditions are invalid.
	 * @throws QueryException If database query fails.
	 */
	public function delete_where( array $conditions ): int;

	/**
	 * Execute a query with conditions, ordering, and pagination.
	 *
	 * @param array<int|string, mixed> $where    Where conditions; see the note above the interface.
	 * @param array<string, string>    $formats Format specifications for values.
	 * @param string                   $order_by Column(s) to order by.
	 * @param string                   $order    Sort direction (ASC or DESC).
	 * @param int|null                 $limit    Maximum number of records to return.
	 * @param int|null                 $offset   Number of records to skip.
	 * @return array<object> Array of database row objects.
	 * @throws QueryException If database query fails.
	 */
	public function query(
		array $where = array(),
		array $formats = array(),
		string $order_by = 'id',
		string $order = 'ASC',
		?int $limit = null,
		?int $offset = null
	): array;

	/**
	 * Get records matching conditions.
	 *
	 * @param array<int|string, mixed> $conditions The where conditions; see the note above the interface.
	 * @return array<object> Array of database row objects.
	 * @throws QueryException If database query fails.
	 */
	public function get_where( array $conditions ): array;

	/**
	 * Count records matching conditions.
	 *
	 * @param array<int|string, mixed> $where Where conditions; see the note above the interface.
	 * @return int Number of matching records.
	 * @throws QueryException If database query fails.
	 */
	public function count( array $where = array() ): int;

	/**
	 * Read the distinct values of one column.
	 *
	 * @param string                   $column Column to read.
	 * @param array<int|string, mixed> $where  Where conditions; see the note above the interface.
	 * @return array<int, string> Distinct non-empty values, as strings.
	 * @throws QueryException If database query fails.
	 */
	public function distinct_values( string $column, array $where = array() ): array;

	/**
	 * Bulk insert or update (Upsert) records.
	 *
	 * @param array<int|string, mixed> $data           Array of rows to insert/update.
	 * @param array<string>            $update_columns Columns to update if duplicate key exists.
	 * @return int Number of affected rows.
	 * @throws InvalidDataException If data is empty or validation fails.
	 * @throws QueryException If database query fails.
	 */
	public function bulk_upsert( array $data, array $update_columns ): int;
}
