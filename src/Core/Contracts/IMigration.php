<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for database migration classes.
 */
interface IMigration {
	/**
	 * Run the migration up.
	 *
	 * @return void
	 */
	public function up(): void;

	/**
	 * Rollback the migration down.
	 *
	 * @return void
	 */
	public function down(): void;

	/**
	 * Get migration unique name.
	 *
	 * @return string
	 */
	public function get_name(): string;
}
