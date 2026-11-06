<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database;

use D6N\LPM\Core\Contracts\IDataMapper;
use D6N\LPM\Core\Contracts\IEntity;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Contracts\IRepository;
use D6N\LPM\Core\Database\Traits\HasErrorHandling;
use D6N\LPM\Core\Database\Traits\HasHookBuilder;
use D6N\LPM\Core\Database\Traits\HasSanitization;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base repository class for model operations.
 */
abstract class AbstractRepository implements IRepository {
	use HasErrorHandling;
	use HasHookBuilder;
	use HasSanitization;

	protected IDataMapper&IEntity $mapper;
	private ILogger $logger;

	/**
	 * Constructor.
	 *
	 * @param IDataMapper&IEntity $mapper Data mapper entity instance.
	 * @param ILogger             $logger Logger instance.
	 */
	public function __construct(
		IDataMapper&IEntity $mapper,
		ILogger $logger
	) {
		$this->mapper = $mapper;
		$this->logger = $logger;
	}

	/**
	 * Get logger instance.
	 *
	 * @return ILogger
	 */
	protected function get_logger(): ILogger {
		return $this->logger;
	}

	/**
	 * Get all records from database.
	 *
	 * @return array<array<string, mixed>>|WP_Error
	 */
	public function get_all(): array|WP_Error {
		return $this->guarded(
			function (): array {
				$collection = $this->to_array_collection( $this->mapper->all() );
				return (array) apply_filters( $this->build_hook( 'all_results' ), $collection );
			}
		);
	}

	/**
	 * Create new record.
	 *
	 * @param array<string, mixed> $data Attribute data.
	 * @return int|WP_Error Inserted ID or error.
	 */
	public function create_record( array $data ): int|WP_Error {
		return $this->guarded(
			function () use ( $data ): int {
				$data = $this->sanitize_data( $data );
				$id   = $this->mapper->create( $data );
				do_action( $this->build_hook( 'record_created' ), $id, $data );
				return $id;
			}
		);
	}

	/**
	 * Update record by ID.
	 *
	 * @param int                  $id   Primary key.
	 * @param array<string, mixed> $data Attribute data.
	 * @return int|WP_Error Affected count or error.
	 */
	public function update_record( int $id, array $data ): int|WP_Error {
		return $this->guarded(
			function () use ( $id, $data ): int {
				$data     = $this->sanitize_data( $data );
				$affected = $this->mapper->update( $id, $data );
				do_action( $this->build_hook( 'record_updated' ), $id, $data, $affected );
				return $affected;
			}
		);
	}

	/**
	 * Delete record by ID.
	 *
	 * @param int $id Primary key.
	 * @return int|WP_Error Deleted count or error.
	 */
	public function delete_record( int $id ): int|WP_Error {
		return $this->guarded(
			function () use ( $id ): int {
				$deleted = $this->mapper->delete( $id );
				do_action( $this->build_hook( 'record_deleted' ), $id );
				return $deleted;
			}
		);
	}

	/**
	 * Run a mapper operation and translate any failure into a WP_Error.
	 *
	 * @param callable $operation Operation to execute.
	 * @return mixed Operation result, or WP_Error on failure.
	 */
	protected function guarded( callable $operation ): mixed {
		try {
			return $operation();
		} catch ( Throwable $e ) {
			return $this->handle_error( $e );
		}
	}

	/**
	 * Convert result set to array collection.
	 *
	 * @param array<object> $results Result set.
	 * @return array<array<string, mixed>>
	 */
	protected function to_array_collection( array $results ): array {
		$collection = array();
		foreach ( $results as $row ) {
			$collection[] = $this->mapper->to_array( $row );
		}
		return $collection;
	}
}
