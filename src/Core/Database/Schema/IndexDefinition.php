<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * One named index a CREATE TABLE statement asks the database for.
 */
class IndexDefinition {

	/**
	 * Constructor.
	 *
	 * @param string $name   Index name exactly as written, or "PRIMARY" for a primary key.
	 * @param bool   $unique Whether the statement asked for a unique index.
	 */
	public function __construct(
		public readonly string $name,
		public readonly bool $unique
	) {}
}
