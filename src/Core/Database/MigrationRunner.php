<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Contracts\IMigration;
use D6N\LPM\Core\Database\Migrations\AbstractMigration;
use D6N\LPM\Core\Enums\OptionsEnum;
use ReflectionClass;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers, orders and executes versioned database migrations.
 */
class MigrationRunner {

	/**
	 * Seconds after which a held lock is treated as abandoned.
	 */
	private const LOCK_TIMEOUT = 300;

	/**
	 * Namespace containing the migration classes.
	 */
	private const MIGRATION_NAMESPACE = __NAMESPACE__ . '\\Migrations\\';

	/**
	 * Priority applied to migrations that do not declare one.
	 */
	private const DEFAULT_PRIORITY = 2;

	/**
	 * Tracking table repository.
	 *
	 * @var MigrationRepository
	 */
	private MigrationRepository $repository;

	/**
	 * Discovered migrations, keyed by class short name.
	 *
	 * @var array<string, IMigration>|null
	 */
	private ?array $discovered = null;

	/**
	 * Directory scanned for migration files.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Stamp written into the lock row while this process holds it.
	 *
	 * @var string|null
	 */
	private ?string $lock_stamp = null;

	/**
	 * Constructor.
	 *
	 * @param ILogger|null             $logger     Optional logger instance.
	 * @param MigrationRepository|null $repository Optional tracking repository.
	 * @param string|null              $directory  Directory to scan; defaults to the shipped one.
	 */
	public function __construct(
		private readonly ?ILogger $logger = null,
		?MigrationRepository $repository = null,
		?string $directory = null
	) {
		$this->repository = $repository ?? new MigrationRepository( $logger );
		$this->directory  = $directory ?? __DIR__ . '/Migrations';
	}

	/**
	 * Acquire the execution lock atomically.
	 *
	 * @return bool True when the lock was acquired by this process.
	 */
	public function acquire_lock(): bool {
		$now   = time();
		$stamp = (string) $now;

		if ( $this->repository->insert_lock( $stamp ) ) {
			$this->lock_stamp = $stamp;

			return true;
		}

		if ( $this->repository->reclaim_stale_lock( $stamp, $now - self::LOCK_TIMEOUT ) ) {
			$this->lock_stamp = $stamp;

			$this->logger?->warning( 'Stale migration lock reclaimed.', array() );

			return true;
		}

		$this->logger?->warning( 'Migration lock active, skipping concurrent execution.', array() );

		return false;
	}

	/**
	 * Delete the execution lock row, whoever holds it.
	 *
	 * @return void
	 */
	public function release_lock(): void {
		delete_option( OptionsEnum::MigrationLock->value );

		$this->lock_stamp = null;
	}

	/**
	 * Release the execution lock only while it still carries this process's stamp.
	 *
	 * @return void
	 */
	private function release_own_lock(): void {
		if ( null === $this->lock_stamp ) {
			return;
		}

		$this->repository->delete_lock( $this->lock_stamp );

		$this->lock_stamp = null;
	}

	/**
	 * Discover every migration class shipped in the Migrations directory.
	 *
	 * @return array<string, IMigration> Instances keyed by class short name, in execution order.
	 */
	public function discover_migrations(): array {
		if ( null !== $this->discovered ) {
			return $this->discovered;
		}

		$found = array();

		$files = glob( $this->directory . '/Migration_*.php' );

		if ( false === $files ) {
			$files = array();
		}

		foreach ( $files as $file ) {
			$basename   = basename( $file, '.php' );
			$class_name = self::MIGRATION_NAMESPACE . $basename;

			if ( ! class_exists( $class_name ) ) {
				require_once $file;
			}

			if ( ! class_exists( $class_name ) ) {
				$this->log_discovery_problem( 'Migration class not found for file.', $basename );
				continue;
			}

			if ( ! is_subclass_of( $class_name, IMigration::class, true ) ) {
				$this->log_discovery_problem( 'Class does not implement IMigration.', $class_name );
				continue;
			}

			$reflection = new ReflectionClass( $class_name );

			if ( $reflection->isAbstract() ) {
				$this->log_discovery_problem( 'Class is abstract.', $class_name );
				continue;
			}

			try {
				$instance = $reflection->newInstance();
			} catch ( Throwable $e ) {
				$this->log_discovery_problem( $e->getMessage(), $class_name );
				continue;
			}

			if ( $instance instanceof AbstractMigration ) {
				$instance->set_logger( $this->logger );
			}

			$found[] = array(
				'sort'      => $this->sort_key( $basename ),
				'priority'  => $this->get_priority( $instance ),
				'migration' => $instance,
			);
		}

		usort(
			$found,
			static function ( array $a, array $b ): int {
				$by_timestamp = $a['sort'] <=> $b['sort'];

				return 0 !== $by_timestamp ? $by_timestamp : ( $a['priority'] <=> $b['priority'] );
			}
		);

		$migrations = array();

		foreach ( $found as $entry ) {
			$migrations[ $entry['migration']->get_name() ] = $entry['migration'];
		}

		$this->discovered = $migrations;

		return $migrations;
	}

	/**
	 * Get the migrations that have not completed yet.
	 *
	 * @return array<string, IMigration> Pending migrations in execution order.
	 */
	public function get_pending(): array {
		$completed = $this->repository->get_completed();

		return array_filter(
			$this->discover_migrations(),
			static fn ( string $name ): bool => ! in_array( $name, $completed, true ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Run every pending migration inside a single batch, stopping at the first failure.
	 *
	 * @return array{success: bool, ran: int, failed: int, locked: bool}
	 */
	public function run_up(): array {
		$result = array(
			'success' => true,
			'ran'     => 0,
			'failed'  => 0,
			'locked'  => false,
		);

		if ( ! $this->acquire_lock() ) {
			$result['success'] = false;
			$result['locked']  = true;

			return $result;
		}

		try {
			if ( ! $this->repository->create_table() ) {
				$result['success'] = false;

				return $result;
			}

			$this->repository->reset_stuck();

			$pending = $this->get_pending();

			if ( array() === $pending ) {
				return $result;
			}

			$batch = $this->repository->next_batch();

			foreach ( $pending as $name => $migration ) {
				$this->repository->mark_started( $name, $this->get_priority( $migration ), $batch );

				try {
					$migration->up();
					$this->repository->mark_completed( $name );
					++$result['ran'];
				} catch ( Throwable $e ) {
					$this->repository->mark_failed( $name, $e->getMessage() );

					$result['success'] = false;
					++$result['failed'];

					$this->logger?->error(
						'Migration failed, stopping the run.',
						array(
							'migration' => $name,
							'batch'     => $batch,
							'error'     => $e->getMessage(),
						)
					);

					break;
				}
			}

			if ( $result['success'] ) {
				$this->logger?->info(
					'Migrations completed.',
					array(
						'batch' => $batch,
						'ran'   => $result['ran'],
					)
				);
			}

			return $result;
		} finally {
			$this->release_own_lock();
		}
	}

	/**
	 * Roll back every completed migration in a batch, newest first.
	 *
	 * @param int $batch Batch number to revert.
	 * @return bool True when every migration in the batch was reverted.
	 */
	public function rollback_batch( int $batch ): bool {
		if ( ! $this->acquire_lock() ) {
			return false;
		}

		try {
			$names = $this->repository->get_by_batch( $batch );

			if ( array() === $names ) {
				return true;
			}

			$migrations = $this->discover_migrations();
			$success    = true;

			foreach ( array_reverse( $names ) as $name ) {
				if ( ! isset( $migrations[ $name ] ) ) {
					$success = false;

					$this->logger?->warning(
						'Recorded migration has no matching class, cannot roll back.',
						array( 'migration' => $name )
					);

					continue;
				}

				try {
					$migrations[ $name ]->down();
					$this->repository->delete_migration( $name );
				} catch ( Throwable $e ) {
					$this->repository->mark_failed( $name, $e->getMessage() );
					$success = false;

					$this->logger?->error(
						'Migration rollback failed, stopping.',
						array(
							'migration' => $name,
							'batch'     => $batch,
							'error'     => $e->getMessage(),
						)
					);

					break;
				}
			}

			return $success;
		} finally {
			$this->release_own_lock();
		}
	}

	/**
	 * Get the tracking repository.
	 *
	 * @return MigrationRepository
	 */
	public function get_repository(): MigrationRepository {
		return $this->repository;
	}

	/**
	 * Build the numeric ordering key from a migration file name.
	 *
	 * @param string $basename File name without extension.
	 * @return int Zero when the name carries no timestamp.
	 */
	private function sort_key( string $basename ): int {
		if ( 1 === preg_match( '/^Migration_([0-9_]+?)_[A-Za-z]/', $basename, $matches ) ) {
			return (int) str_replace( '_', '', $matches[1] );
		}

		return 0;
	}

	/**
	 * Get a migration's declared priority.
	 *
	 * @param IMigration $migration Migration instance.
	 * @return int
	 */
	private function get_priority( IMigration $migration ): int {
		return $migration instanceof AbstractMigration ? $migration->priority() : self::DEFAULT_PRIORITY;
	}

	/**
	 * Log a discovery problem when a logger is available.
	 *
	 * @param string $reason  Human readable reason.
	 * @param string $subject File or class the problem relates to.
	 * @return void
	 */
	private function log_discovery_problem( string $reason, string $subject ): void {
		$this->logger?->warning(
			'Migration discovery skipped an entry.',
			array(
				'subject' => $subject,
				'reason'  => $reason,
			)
		);
	}
}
