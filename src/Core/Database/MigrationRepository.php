<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Enums\TablesEnum;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for the migration tracking table and the migration execution lock row.
 */
class MigrationRepository {

	/**
	 * Recorded but not yet started.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Currently executing.
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * Finished successfully.
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Finished with an error.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Seconds after which a running migration is considered abandoned.
	 */
	public const DEFAULT_STUCK_TIMEOUT = 900;

	/**
	 * Constructor.
	 *
	 * @param ILogger|null $logger Optional logger instance.
	 */
	public function __construct(
		private readonly ?ILogger $logger = null
	) {}

	/**
	 * Create the tracking table and confirm it exists afterwards.
	 *
	 * @return bool True when the table exists once the call returns.
	 */
	public function create_table(): bool {
		$db    = $this->get_db();
		$table = $this->get_table();

		$charset_collate = (string) $db->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			migration varchar(191) NOT NULL,
			batch int(11) unsigned NOT NULL default 0,
			status varchar(20) NOT NULL default 'pending',
			priority tinyint(1) unsigned NOT NULL default 2,
			started_at datetime default NULL,
			completed_at datetime default NULL,
			error longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY migration (migration),
			KEY status (status),
			KEY batch (batch)
		) ENGINE=InnoDB {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );

		$exists = $this->table_exists();

		if ( ! $exists ) {
			$this->logger?->error(
				'Migration tracking table could not be created.',
				array(
					'table' => $table,
					'error' => (string) $db->last_error,
				)
			);
		}

		return $exists;
	}

	/**
	 * Record a migration as running, creating its row when absent.
	 *
	 * @param string $migration Migration class name.
	 * @param int    $priority  Execution priority.
	 * @param int    $batch     Batch the migration belongs to.
	 * @return bool True on success.
	 */
	public function mark_started( string $migration, int $priority, int $batch ): bool {
		$now = $this->now();

		$sql = 'INSERT INTO %i ( migration, batch, status, priority, started_at, completed_at, error, created_at )
			VALUES ( %s, %d, %s, %d, %s, NULL, NULL, %s )
			ON DUPLICATE KEY UPDATE
				batch = VALUES( batch ),
				status = VALUES( status ),
				priority = VALUES( priority ),
				started_at = VALUES( started_at ),
				completed_at = NULL,
				error = NULL';

		$result = $this->run_prepared(
			$sql,
			$this->get_table(),
			$migration,
			$batch,
			self::STATUS_RUNNING,
			$priority,
			$now,
			$now
		);

		return false !== $result;
	}

	/**
	 * Mark a migration as completed and clear any previous error.
	 *
	 * @param string $migration Migration class name.
	 * @return bool True on success.
	 */
	public function mark_completed( string $migration ): bool {
		$result = $this->run_prepared(
			'UPDATE %i SET status = %s, completed_at = %s, error = NULL WHERE migration = %s',
			$this->get_table(),
			self::STATUS_COMPLETED,
			$this->now(),
			$migration
		);

		return false !== $result;
	}

	/**
	 * Mark a migration as failed and store the error text.
	 *
	 * @param string $migration Migration class name.
	 * @param string $error     Error message.
	 * @return bool True on success.
	 */
	public function mark_failed( string $migration, string $error ): bool {
		$result = $this->run_prepared(
			'UPDATE %i SET status = %s, error = %s WHERE migration = %s',
			$this->get_table(),
			self::STATUS_FAILED,
			$error,
			$migration
		);

		return false !== $result;
	}

	/**
	 * Get the names of every completed migration.
	 *
	 * @return array<int, string>
	 */
	public function get_completed(): array {
		$db = $this->get_db();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $db->get_col(
			$db->prepare(
				'SELECT migration FROM %i WHERE status = %s ORDER BY id ASC',
				$this->get_table(),
				self::STATUS_COMPLETED
			)
		);

		return array_map( 'strval', $rows );
	}

	/**
	 * Get the completed migrations belonging to a batch, in execution order.
	 *
	 * @param int $batch Batch number.
	 * @return array<int, string>
	 */
	public function get_by_batch( int $batch ): array {
		$db = $this->get_db();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $db->get_col(
			$db->prepare(
				'SELECT migration FROM %i WHERE batch = %d AND status = %s ORDER BY id ASC',
				$this->get_table(),
				$batch,
				self::STATUS_COMPLETED
			)
		);

		return array_map( 'strval', $rows );
	}

	/**
	 * Get the highest batch number recorded so far.
	 *
	 * @return int Zero when nothing has been recorded.
	 */
	public function get_latest_batch(): int {
		$db = $this->get_db();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max = $db->get_var(
			$db->prepare( 'SELECT MAX(batch) FROM %i', $this->get_table() )
		);

		return null === $max ? 0 : (int) $max;
	}

	/**
	 * Get the batch number the next run should use.
	 *
	 * @return int
	 */
	public function next_batch(): int {
		return $this->get_latest_batch() + 1;
	}

	/**
	 * Delete a migration row, used when rolling back.
	 *
	 * @param string $migration Migration class name.
	 * @return bool True on success.
	 */
	public function delete_migration( string $migration ): bool {
		$db = $this->get_db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $db->delete(
			$this->get_table(),
			array( 'migration' => $migration ),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Return migrations abandoned in the running state back to pending.
	 *
	 * @param int $timeout_seconds Age in seconds after which a running row is stuck.
	 * @return int Number of rows reset.
	 */
	public function reset_stuck( int $timeout_seconds = self::DEFAULT_STUCK_TIMEOUT ): int {
		$threshold = gmdate( 'Y-m-d H:i:s', time() - max( 0, $timeout_seconds ) );

		$result = $this->run_prepared(
			'UPDATE %i SET status = %s, started_at = NULL
				WHERE status = %s AND ( started_at IS NULL OR started_at < %s )',
			$this->get_table(),
			self::STATUS_PENDING,
			self::STATUS_RUNNING,
			$threshold
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Count the recorded migrations per status.
	 *
	 * @return array<string, int> Counts keyed by pending, running, completed and failed.
	 */
	public function get_status_summary(): array {
		$db = $this->get_db();

		$summary = array(
			self::STATUS_PENDING   => 0,
			self::STATUS_RUNNING   => 0,
			self::STATUS_COMPLETED => 0,
			self::STATUS_FAILED    => 0,
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $db->get_results(
			$db->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $this->get_table() ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return $summary;
		}

		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );

			if ( array_key_exists( $status, $summary ) ) {
				$summary[ $status ] = (int) ( $row['total'] ?? 0 );
			}
		}

		return $summary;
	}

	/**
	 * Check whether the tracking table exists.
	 *
	 * @return bool
	 */
	public function table_exists(): bool {
		$db = $this->get_db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $this->get_table() ) );

		return null !== $found && '' !== (string) $found;
	}

	/**
	 * Insert the execution lock row unless one already exists.
	 *
	 * @param string $stamp Value identifying this holder.
	 * @return bool True when this call created the row.
	 */
	public function insert_lock( string $stamp ): bool {
		$result = $this->run_prepared(
			'INSERT IGNORE INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )',
			$this->get_db()->options,
			OptionsEnum::MigrationLock->value,
			$stamp,
			'no'
		);

		return 1 === $result;
	}

	/**
	 * Take over an execution lock row whose stamp is older than a threshold.
	 *
	 * @param string $stamp       Value identifying the new holder.
	 * @param int    $held_before Unix timestamp a stamp must be older than to be taken over.
	 * @return bool True when this call replaced the stale stamp.
	 */
	public function reclaim_stale_lock( string $stamp, int $held_before ): bool {
		$result = $this->run_prepared(
			'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d',
			$this->get_db()->options,
			$stamp,
			OptionsEnum::MigrationLock->value,
			$held_before
		);

		return 1 === $result;
	}

	/**
	 * Delete the execution lock row while it still carries the given stamp.
	 *
	 * @param string $stamp Value the holder wrote.
	 * @return bool True when the row was deleted.
	 */
	public function delete_lock( string $stamp ): bool {
		$result = $this->run_prepared(
			'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
			$this->get_db()->options,
			OptionsEnum::MigrationLock->value,
			$stamp
		);

		return 1 === $result;
	}

	/**
	 * Get the fully prefixed tracking table name.
	 *
	 * @return string
	 */
	public function get_table(): string {
		return TablesEnum::Migrations->table_name();
	}

	/**
	 * Bind and run a write statement.
	 *
	 * @param literal-string $sql     Statement built only from literals and placeholders.
	 * @param mixed          ...$args Values to bind, in placeholder order.
	 * @return int|bool Rows affected, or false when the statement could not be bound or run.
	 */
	private function run_prepared( string $sql, mixed ...$args ): int|bool {
		$db       = $this->get_db();
		$prepared = $db->prepare( $sql, ...$args );

		if ( null === $prepared ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $db->query( $prepared );
	}

	/**
	 * Get the current UTC timestamp in MySQL datetime format.
	 *
	 * @return string
	 */
	private function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Get the global WordPress database handle.
	 *
	 * @return wpdb
	 */
	private function get_db(): wpdb {
		global $wpdb;

		return $wpdb;
	}
}
