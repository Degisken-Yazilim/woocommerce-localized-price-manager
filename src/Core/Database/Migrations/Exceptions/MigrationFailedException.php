<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Migrations\Exceptions;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a migration statement executes but does not produce the expected result.
 */
class MigrationFailedException extends MigrationException {

	/**
	 * Constructor.
	 *
	 * @param string         $migration_name Migration class name that failed.
	 * @param string         $error_message  Underlying database or validation error.
	 * @param Throwable|null $previous       Previous exception in the chain.
	 */
	public function __construct( string $migration_name, string $error_message, ?Throwable $previous = null ) {
		parent::__construct(
			sprintf( 'Migration "%s" failed: %s', $migration_name, $error_message ),
			0,
			$previous
		);
	}
}
