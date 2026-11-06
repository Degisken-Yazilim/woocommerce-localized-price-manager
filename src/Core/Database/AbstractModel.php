<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database;

use D6N\LPM\Core\Contracts\IModel;
use D6N\LPM\Core\Database\Traits\HasCasting;
use D6N\LPM\Core\Database\Traits\HasHookBuilder;
use D6N\LPM\Core\Database\Traits\HasQueryBuilder;
use D6N\LPM\Core\Database\Traits\HasSerialization;
use D6N\LPM\Core\Database\Traits\HasValidation;
use D6N\LPM\Core\Exceptions\InvalidDataException;
use D6N\LPM\Core\Exceptions\QueryException;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Base model class providing data access and entity mapping.
 */
abstract class AbstractModel implements IModel {
	use HasCasting;
	use HasSerialization;
	use HasValidation;
	use HasQueryBuilder;
	use HasHookBuilder;

	protected string $table;
	protected string $primary_key = 'id';

	/**
	 * Cast type keyed by column name, e.g. `'price' => 'float'`.
	 *
	 * @var array<string, string>
	 */
	protected array $casts = array();

	/**
	 * Columns a caller is allowed to write.
	 *
	 * @var array<int, string>
	 */
	protected array $fillable = array();

	/**
	 * Columns from $fillable that may legitimately hold null.
	 *
	 * @var array<int, string>
	 */
	protected array $nullable = array();

	/**
	 * Columns stripped from every read result.
	 *
	 * @var array<string>
	 */
	protected array $hidden = array();

	/**
	 * Column holding the row creation timestamp, or null when the table has none.
	 *
	 * @var string|null
	 */
	protected ?string $created_at_column = 'created_at';

	/**
	 * Column holding the row modification timestamp, or null when the table has none.
	 *
	 * @var string|null
	 */
	protected ?string $updated_at_column = 'updated_at';

	protected wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $_wpdb Optional database instance.
	 */
	public function __construct( ?wpdb $_wpdb = null ) {
		global $wpdb;
		$this->wpdb = $_wpdb ?? $wpdb;

		if ( str_starts_with( $this->table, $this->wpdb->prefix ) ) {
			$this->table = substr( $this->table, strlen( $this->wpdb->prefix ) );
		}
		$this->table = $this->wpdb->prefix . $this->table;
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public function get_table(): string {
		return $this->table;
	}

	/**
	 * Get all records from the table.
	 *
	 * @return array<object>
	 * @throws QueryException If database query fails.
	 */
	public function all(): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM %i', $this->table ) );

		if ( $this->wpdb->last_error ) {
			throw new QueryException( esc_html( $this->wpdb->last_error ) );
		}

		if ( empty( $results ) ) {
			return array();
		}

		foreach ( $results as &$row ) {
			$row = $this->apply_casts( $row );
			$row = $this->filter_hidden( $row );
		}

		return apply_filters( $this->build_hook( 'all_results' ), $results );
	}

	/**
	 * Create new record.
	 *
	 * @param array<string, mixed> $data Attribute data.
	 * @return int Inserted ID.
	 * @throws InvalidDataException If validation fails.
	 * @throws QueryException If database query fails.
	 */
	public function create( array $data ): int {
		$data = $this->filter_fillable( $data );

		if ( ! $this->validate( $data ) ) {
			throw new InvalidDataException( 'Data validation failed for create operation.' );
		}

		$data = $this->format_for_database( $data );
		$data = $this->apply_timestamps( $data, true );

		$inserted = $this->wpdb->insert( $this->table, $data );

		if ( false === $inserted ) {
			$error_msg = $this->wpdb->last_error ? $this->wpdb->last_error : 'Failed to insert database row.';
			throw new QueryException( esc_html( $error_msg ) );
		}

		$id = (int) $this->wpdb->insert_id;
		do_action( $this->build_hook( 'created' ), $id, $data );

		return $id;
	}

	/**
	 * Update record by primary key.
	 *
	 * @param int                  $id   Primary key.
	 * @param array<string, mixed> $data Attribute data.
	 * @return int Number of updated rows.
	 * @throws InvalidDataException If validation fails.
	 * @throws QueryException If database query fails.
	 */
	public function update( int $id, array $data ): int {
		$data = $this->filter_fillable( $data );

		if ( ! $this->validate( $data, true ) ) {
			throw new InvalidDataException( 'Data validation failed for update operation.' );
		}

		$data = $this->format_for_database( $data );
		$data = $this->apply_timestamps( $data, false );

		$updated = $this->wpdb->update( $this->table, $data, array( $this->primary_key => $id ) );

		if ( false === $updated && $this->wpdb->last_error ) {
			throw new QueryException( esc_html( $this->wpdb->last_error ) );
		}

		$affected = false === $updated ? 0 : $updated;
		do_action( $this->build_hook( 'updated' ), $id, $data, $affected );

		return $affected;
	}

	/**
	 * Update records matching conditions.
	 *
	 * @param array<string, mixed>     $data       Data array.
	 * @param array<int|string, mixed> $conditions Where conditions; see the IDataMapper docblock for the accepted shapes.
	 * @return int Number of affected rows.
	 * @throws QueryException If database query fails.
	 */
	public function update_where( array $data, array $conditions ): int {
		if ( empty( $data ) || empty( $conditions ) ) {
			return 0;
		}

		$data = $this->filter_fillable( $data );
		$data = $this->format_for_database( $data );
		$data = $this->apply_timestamps( $data, false );

		[ $where_sql, $params ] = $this->build_where_clauses( $conditions );

		if ( empty( $where_sql ) ) {
			return 0;
		}

		$set_parts  = array();
		$set_params = array();

		foreach ( $data as $col => $val ) {
			$format = $this->determine_format( $val );

			$set_parts[]  = '%i = ' . $format;
			$set_params[] = $col;

			if ( 'NULL' !== $format ) {
				$set_params[] = $val;
			}
		}

		$sql        = 'UPDATE %i SET ' . implode( ', ', $set_parts ) . ' WHERE ' . $where_sql;
		$all_params = array_merge( array( $this->table ), $set_params, $params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->wpdb->query( $this->prepare_statement( $sql, ...$all_params ) );

		if ( false === $result ) {
			throw new QueryException( esc_html( $this->wpdb->last_error ) );
		}

		return (int) $result;
	}

	/**
	 * Delete record by primary key.
	 *
	 * @param int $id Primary key.
	 * @return int Number of deleted rows.
	 * @throws QueryException If database query fails.
	 */
	public function delete( int $id ): int {
		$deleted = $this->wpdb->delete( $this->table, array( $this->primary_key => $id ) );

		if ( false === $deleted ) {
			throw new QueryException( esc_html( $this->wpdb->last_error ) );
		}

		do_action( $this->build_hook( 'deleted' ), $id );

		return (int) $deleted;
	}

	/**
	 * Delete records matching conditions.
	 *
	 * @param array<int|string, mixed> $conditions Where conditions; see the IDataMapper docblock for the accepted shapes.
	 * @return int Number of deleted rows.
	 * @throws QueryException If database query fails.
	 */
	public function delete_where( array $conditions ): int {
		if ( empty( $conditions ) ) {
			return 0;
		}

		[ $where_sql, $params ] = $this->build_where_clauses( $conditions );

		if ( empty( $where_sql ) ) {
			return 0;
		}

		$sql = 'DELETE FROM %i WHERE ' . $where_sql;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->wpdb->query( $this->prepare_statement( $sql, $this->table, ...$params ) );

		if ( false === $result ) {
			throw new QueryException( esc_html( $this->wpdb->last_error ) );
		}

		return (int) $result;
	}

	/**
	 * Query records with conditions, order, and limit.
	 *
	 * @param array<int|string, mixed> $where    Conditions; see the IDataMapper docblock for the accepted shapes.
	 * @param array<string, string>    $formats  Value placeholders keyed by column name.
	 * @param string                   $order_by Order by column.
	 * @param string                   $order    ASC or DESC.
	 * @param int|null                 $limit    Limit.
	 * @param int|null                 $offset   Offset.
	 * @return array<object>
	 * @throws QueryException If database query fails.
	 */
	public function query(
		array $where = array(),
		array $formats = array(),
		string $order_by = 'id',
		string $order = 'ASC',
		?int $limit = null,
		?int $offset = null
	): array {
		$sql    = 'SELECT * FROM %i';
		$params = array( $this->table );

		[ $where_sql, $where_params ] = $this->build_where_clauses( $where, $formats );

		if ( '' !== $where_sql ) {
			$sql   .= ' WHERE ' . $where_sql;
			$params = array_merge( $params, $where_params );
		}

		$prepared = $this->prepare_statement( $sql, ...$params );

		$order_sql = $this->build_order_by( $order_by, $order );
		if ( '' !== $order_sql ) {
			$prepared .= ' ORDER BY ' . $order_sql;
		}

		if ( null !== $limit ) {
			$prepared .= null === $offset
				? $this->prepare_statement( ' LIMIT %d', $limit )
				: $this->prepare_statement( ' LIMIT %d OFFSET %d', $limit, $offset );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $prepared );

		if ( $this->wpdb->last_error ) {
			throw new QueryException( esc_html( $this->wpdb->last_error ) );
		}

		if ( empty( $results ) ) {
			return array();
		}

		foreach ( $results as &$row ) {
			$row = $this->apply_casts( $row );
			$row = $this->filter_hidden( $row );
		}

		return apply_filters( $this->build_hook( 'query_results' ), $results, $where );
	}

	/**
	 * Get records matching conditions.
	 *
	 * @param array<int|string, mixed> $conditions Conditions; see the IDataMapper docblock for the accepted shapes.
	 * @return array<object>
	 * @throws QueryException If database query fails.
	 */
	public function get_where( array $conditions ): array {
		return $this->query( $conditions );
	}

	/**
	 * Count matching records.
	 *
	 * @param array<int|string, mixed> $where Conditions; see the IDataMapper docblock for the accepted shapes.
	 * @return int
	 * @throws QueryException If database query fails.
	 */
	public function count( array $where = array() ): int {
		$sql    = 'SELECT COUNT(*) FROM %i';
		$params = array( $this->table );

		[ $where_sql, $where_params ] = $this->build_where_clauses( $where );

		if ( '' !== $where_sql ) {
			$sql   .= ' WHERE ' . $where_sql;
			$params = array_merge( $params, $where_params );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = $this->wpdb->get_var( $this->prepare_statement( $sql, ...$params ) );

		if ( $this->wpdb->last_error ) {
			throw new QueryException( esc_html( $this->wpdb->last_error ) );
		}

		return (int) $count;
	}

	/**
	 * Read the distinct values of one column.
	 *
	 * @param string                   $column Column to read.
	 * @param array<int|string, mixed> $where  Conditions; see the IDataMapper docblock.
	 * @return array<int, string> Distinct non-empty values, as strings.
	 * @throws QueryException If database query fails.
	 */
	public function distinct_values( string $column, array $where = array() ): array {
		$sql    = 'SELECT DISTINCT %i FROM %i';
		$params = array( $column, $this->table );

		[ $where_sql, $where_params ] = $this->build_where_clauses( $where );

		if ( '' !== $where_sql ) {
			$sql   .= ' WHERE ' . $where_sql;
			$params = array_merge( $params, $where_params );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$values = $this->wpdb->get_col( $this->prepare_statement( $sql, ...$params ) );

		if ( $this->wpdb->last_error ) {
			throw new QueryException( esc_html( $this->wpdb->last_error ) );
		}

		return array_values(
			array_filter(
				array_map( static fn ( mixed $value ): string => trim( (string) $value ), $values ),
				static fn ( string $value ): bool => '' !== $value
			)
		);
	}

	/**
	 * Bulk insert or update records.
	 *
	 * @param array<int|string, mixed> $data           Rows array; non-row members are skipped.
	 * @param array<string>            $update_columns Columns to update on duplicate key.
	 * @return int Number of affected rows.
	 * @throws InvalidDataException If input data is empty or holds no fillable columns.
	 * @throws QueryException If database query fails.
	 */
	public function bulk_upsert( array $data, array $update_columns ): int {
		if ( empty( $data ) ) {
			throw new InvalidDataException( 'Bulk upsert requires non-empty data array.' );
		}

		$rows = $this->prepare_upsert_rows( $data );

		if ( empty( $rows ) ) {
			throw new InvalidDataException( 'Bulk upsert requires at least one row with fillable columns.' );
		}

		$columns        = array_keys( $rows[0] );
		$values_clauses = array();
		$value_params   = array();

		foreach ( $rows as $row ) {
			$placeholders = array();

			foreach ( $columns as $col ) {
				$val    = $row[ $col ] ?? null;
				$format = $this->determine_format( $val );

				$placeholders[] = $format;

				if ( 'NULL' !== $format ) {
					$value_params[] = $val;
				}
			}

			$values_clauses[] = '(' . implode( ',', $placeholders ) . ')';
		}

		$cols_sql   = implode( ',', array_fill( 0, count( $columns ), '%i' ) );
		$values_sql = implode( ',', $values_clauses );

		[ $duplicate_sql, $duplicate_params ] = $this->build_on_duplicate_clause( $update_columns );

		$sql = 'INSERT INTO %i (' . $cols_sql . ') VALUES ' . $values_sql . ' ' . $duplicate_sql;

		$all_params = array_merge( array( $this->table ), $columns, $value_params, $duplicate_params );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$affected = $this->wpdb->query( $this->prepare_statement( $sql, ...$all_params ) );

		if ( false === $affected ) {
			throw new QueryException( esc_html( $this->wpdb->last_error ) );
		}

		return (int) $affected;
	}

	/**
	 * Run bulk upsert rows through the standard write pipeline.
	 *
	 * @param array<int|string, mixed> $data Raw rows; non-row members are skipped.
	 * @return list<array<string, mixed>> Normalised rows sharing one column set.
	 */
	private function prepare_upsert_rows( array $data ): array {
		$rows = array();

		foreach ( $data as $row ) {
			if ( ! is_array( $row ) && ! is_object( $row ) ) {
				continue;
			}

			$prepared = $this->format_for_database( $this->filter_fillable( $row ) );

			if ( empty( $prepared ) ) {
				continue;
			}

			$rows[] = $this->apply_timestamps( $prepared, true );
		}

		if ( empty( $rows ) ) {
			return array();
		}

		$columns = array_keys( $rows[0] );

		foreach ( $rows as $index => $row ) {
			$normalised = array();
			foreach ( $columns as $column ) {
				$normalised[ $column ] = $row[ $column ] ?? null;
			}
			$rows[ $index ] = $normalised;
		}

		return $rows;
	}

	/**
	 * Build the ON DUPLICATE KEY UPDATE clause for the running server.
	 *
	 * @param array<string> $update_columns Caller supplied columns to refresh.
	 * @return array{0: literal-string, 1: list<string>} ON DUPLICATE KEY UPDATE clause and the column
	 *                                                   names bound to its `%i` placeholders; both are
	 *                                                   empty when there is nothing to update.
	 */
	private function build_on_duplicate_clause( array $update_columns ): array {
		$columns = $update_columns;

		if ( null !== $this->updated_at_column ) {
			$columns[] = $this->updated_at_column;
		}

		$columns = array_values( array_unique( array_filter( $columns, 'is_string' ) ) );

		if ( empty( $columns ) ) {
			return array( '', array() );
		}

		$use_alias      = $this->supports_row_alias_upsert();
		$update_clauses = array();
		$params         = array();

		foreach ( $columns as $col ) {
			$update_clauses[] = $use_alias ? '%i = lpm_new.%i' : '%i = VALUES(%i)';
			$params[]         = $col;
			$params[]         = $col;
		}

		$clause = ( $use_alias ? 'AS lpm_new ' : '' ) . 'ON DUPLICATE KEY UPDATE ' . implode( ',', $update_clauses );

		return array( $clause, $params );
	}

	/**
	 * Bind values into a statement, refusing to run one that could not be bound.
	 *
	 * @param literal-string $sql     Statement built only from literals and placeholders.
	 * @param mixed          ...$args Values to bind, in placeholder order.
	 * @return string The prepared statement.
	 * @throws QueryException If the statement could not be prepared.
	 */
	private function prepare_statement( string $sql, mixed ...$args ): string {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This is the call that prepares; $sql is a literal-string by declaration.
		$prepared = $this->wpdb->prepare( $sql, ...$args );

		if ( null === $prepared ) {
			throw new QueryException( 'Failed to bind values into the database statement.' );
		}

		return $prepared;
	}

	/**
	 * Determine whether the server understands the row-alias upsert syntax.
	 *
	 * @return bool
	 */
	private function supports_row_alias_upsert(): bool {
		$server_info = (string) $this->wpdb->db_server_info();

		if ( false !== stripos( $server_info, 'mariadb' ) ) {
			return false;
		}

		return version_compare( (string) $this->wpdb->db_version(), '8.0.19', '>=' );
	}

	/**
	 * Stamp the configured timestamp columns onto a prepared row.
	 *
	 * @param array<string, mixed> $data      Row data already formatted for the database.
	 * @param bool                 $is_insert True when the row may be inserted.
	 * @return array<string, mixed>
	 */
	private function apply_timestamps( array $data, bool $is_insert ): array {
		$now = gmdate( 'Y-m-d H:i:s' );

		if ( $is_insert && null !== $this->created_at_column ) {
			$data[ $this->created_at_column ] = $now;
		}

		if ( null !== $this->updated_at_column ) {
			$data[ $this->updated_at_column ] = $now;
		}

		return $data;
	}
}
