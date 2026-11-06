<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Migrations;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Contracts\IMigration;
use D6N\LPM\Core\Database\Migrations\Exceptions\MigrationFailedException;
use D6N\LPM\Core\Database\Schema\CreateTableParser;
use D6N\LPM\Core\Database\Schema\TableDefinition;
use wpdb;

/**
 * Base class for every versioned database migration.
 */
abstract class AbstractMigration implements IMigration {

	/**
	 * Priority for schema migrations (tables, columns, indexes).
	 */
	public const PRIORITY_SCHEMA = 1;

	/**
	 * Priority for data migrations (seeding, back-filling, transforming).
	 */
	public const PRIORITY_DATA = 2;

	/**
	 * Optional logger used for progress and failure reporting.
	 *
	 * @var ILogger|null
	 */
	protected ?ILogger $logger = null;

	/**
	 * Constructor.
	 *
	 * @param ILogger|null $logger Optional logger instance.
	 */
	public function __construct( ?ILogger $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Inject a logger after construction.
	 *
	 * @param ILogger|null $logger Logger instance or null to disable logging.
	 * @return void
	 */
	public function set_logger( ?ILogger $logger ): void {
		$this->logger = $logger;
	}

	/**
	 * Get the migration identity.
	 *
	 * @return string
	 */
	public function get_name(): string {
		$class    = static::class;
		$position = strrpos( $class, '\\' );

		return false === $position ? $class : substr( $class, $position + 1 );
	}

	/**
	 * Get the execution priority. Lower numbers run first.
	 *
	 * @return int
	 */
	public function priority(): int {
		return self::PRIORITY_DATA;
	}

	/**
	 * Run a CREATE TABLE statement through dbDelta and verify the result.
	 *
	 * @param string $sql A single dbDelta-compatible CREATE TABLE statement.
	 * @return void
	 * @throws MigrationFailedException If the statement cannot be read, or the table, a column or an index is missing afterwards.
	 */
	protected function execute_schema( string $sql ): void {
		$definition = $this->read_statement( $sql );

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$changes = dbDelta( $sql );

		$error = '' !== $this->get_db()->last_error ? $this->get_db()->last_error : 'none reported';

		$this->verify_applied( $definition, $error );

		$this->logger?->debug(
			'Migration schema statement applied.',
			array(
				'migration' => $this->get_name(),
				'table'     => $definition->table,
				'changes'   => array_values( $changes ),
			)
		);
	}

	/**
	 * Read the one statement execute_schema() is allowed to run.
	 *
	 * @param string $sql Schema statement.
	 * @return TableDefinition
	 * @throws MigrationFailedException If the statement is not a single readable CREATE TABLE.
	 */
	private function read_statement( string $sql ): TableDefinition {
		$parser = new CreateTableParser();
		$count  = $parser->count_statements( $sql );

		if ( $count > 1 ) {
			$this->fail(
				sprintf(
					'The schema statement holds %d CREATE TABLE statements; execute_schema() verifies one at a time.',
					$count
				)
			);
		}

		$definition = $parser->parse( $sql );

		if ( null === $definition ) {
			$this->fail( 'Unable to determine the target table name from the schema statement.' );
		}

		if ( array() === $definition->columns ) {
			$this->fail(
				sprintf(
					'No column definition could be read from the schema statement for table "%s".',
					$definition->table
				)
			);
		}

		return $definition;
	}

	/**
	 * Confirm the database now holds everything the statement asked for.
	 *
	 * @param TableDefinition $definition Parsed CREATE TABLE statement.
	 * @param string          $error      Whatever dbDelta() left on the handle.
	 * @return void
	 * @throws MigrationFailedException If the table, a column or an index is missing.
	 */
	private function verify_applied( TableDefinition $definition, string $error ): void {
		if ( ! $this->table_exists( $definition->table ) ) {
			$this->fail(
				sprintf(
					'dbDelta() did not create table "%s". Database error: %s',
					$definition->table,
					$error
				)
			);
		}

		$missing = $this->find_missing_schema( $definition );

		if ( array() !== $missing ) {
			$this->fail(
				sprintf(
					'dbDelta() reported no failure but table "%s" is missing %s. Database error: %s',
					$definition->table,
					implode( ', ', $missing ),
					$error
				)
			);
		}
	}

	/**
	 * Abandon the migration, naming the reason.
	 *
	 * @param string $reason What went wrong, in the operator's terms.
	 * @return never
	 * @throws MigrationFailedException Always.
	 */
	private function fail( string $reason ): never {
		throw new MigrationFailedException( $this->get_name(), $reason );
	}

	/**
	 * Run a non-DDL statement and fail when the database reports an error.
	 *
	 * @param literal-string    $sql  SQL statement; values belong in $args, never in the string itself.
	 * @param array<int, mixed> $args Optional values to bind through $wpdb->prepare().
	 * @return int Number of affected rows.
	 * @throws MigrationFailedException If the statement fails.
	 */
	protected function execute_statement( string $sql, array $args = array() ): int {
		$db = $this->get_db();

		$query = array() === $args ? $sql : (string) $db->prepare( $sql, ...$args );

		$db->flush();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $db->query( $query );

		if ( false === $result || '' !== (string) $db->last_error ) {
			throw new MigrationFailedException(
				$this->get_name(),
				'' !== (string) $db->last_error ? (string) $db->last_error : 'Statement execution returned false.'
			);
		}

		return (int) $result;
	}

	/**
	 * Drop a table, used by down() implementations.
	 *
	 * @param string $table Fully prefixed table name.
	 * @return void
	 * @throws MigrationFailedException If the drop statement fails.
	 */
	protected function drop_table( string $table ): void {
		$this->execute_statement( 'DROP TABLE IF EXISTS %i', array( $table ) );
	}

	/**
	 * Check whether a table currently exists.
	 *
	 * @param string $table Fully prefixed table name.
	 * @return bool
	 */
	protected function table_exists( string $table ): bool {
		$db = $this->get_db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return null !== $found && '' !== (string) $found;
	}

	/**
	 * Get the charset and collation clause for CREATE TABLE statements.
	 *
	 * @return string
	 */
	protected function get_charset_collate(): string {
		return (string) $this->get_db()->get_charset_collate();
	}

	/**
	 * Get the current UTC timestamp in MySQL datetime format.
	 *
	 * @return string
	 */
	protected function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Get the global WordPress database handle.
	 *
	 * @return wpdb
	 */
	protected function get_db(): wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * List everything a parsed statement asked for that the table does not have.
	 *
	 * @param TableDefinition $definition Parsed CREATE TABLE statement.
	 * @return array<int, string> Descriptions of the absent pieces, empty when nothing is missing.
	 */
	private function find_missing_schema( TableDefinition $definition ): array {
		return array_merge(
			$this->find_missing_columns( $definition ),
			$this->find_missing_indexes( $definition )
		);
	}

	/**
	 * List the requested columns the table does not have.
	 *
	 * @param TableDefinition $definition Parsed CREATE TABLE statement.
	 * @return array<int, string>
	 */
	private function find_missing_columns( TableDefinition $definition ): array {
		$present = $this->read_columns( $definition->table );
		$missing = array();

		foreach ( $definition->columns as $column ) {
			if ( ! in_array( strtolower( $column ), $present, true ) ) {
				$missing[] = sprintf( 'column "%s"', $column );
			}
		}

		return $missing;
	}

	/**
	 * List the requested indexes the table does not have, or has too weakly.
	 *
	 * @param TableDefinition $definition Parsed CREATE TABLE statement.
	 * @return array<int, string>
	 */
	private function find_missing_indexes( TableDefinition $definition ): array {
		$present = $this->read_indexes( $definition->table );
		$missing = array();

		foreach ( $definition->indexes as $index ) {
			$name = strtolower( $index->name );

			if ( ! array_key_exists( $name, $present ) ) {
				$missing[] = sprintf( 'index "%s"', $index->name );

				continue;
			}

			if ( $index->unique && true !== $present[ $name ] ) {
				$missing[] = sprintf( 'the UNIQUE constraint on index "%s"', $index->name );
			}
		}

		return $missing;
	}

	/**
	 * Read the columns a table currently has.
	 *
	 * @phpstan-impure
	 *
	 * @param string $table Fully prefixed table name.
	 * @return array<int, string> Lower-cased column names.
	 */
	private function read_columns( string $table ): array {
		$db = $this->get_db();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $db->get_results( $db->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$columns = array();

		foreach ( $rows as $row ) {
			$field = strtolower( (string) ( $row['Field'] ?? '' ) );

			if ( '' !== $field ) {
				$columns[] = $field;
			}
		}

		return $columns;
	}

	/**
	 * Read the indexes a table currently has.
	 *
	 * @phpstan-impure
	 *
	 * @param string $table Fully prefixed table name.
	 * @return array<string, bool> Lower-cased index name mapped to whether it is unique.
	 */
	private function read_indexes( string $table ): array {
		$db = $this->get_db();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $db->get_results( $db->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$indexes = array();

		foreach ( $rows as $row ) {
			$name = strtolower( (string) ( $row['Key_name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$unique = '0' === (string) ( $row['Non_unique'] ?? '1' );

			$indexes[ $name ] = $unique || ( $indexes[ $name ] ?? false );
		}

		return $indexes;
	}
}
