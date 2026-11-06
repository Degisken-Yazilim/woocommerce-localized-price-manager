<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a CREATE TABLE statement asks for that can be checked afterwards.
 */
class TableDefinition {

	/**
	 * Constructor.
	 *
	 * @param string                      $table   Table name exactly as written, without back quotes.
	 * @param array<int, string>          $columns Column names in declaration order, without back quotes.
	 * @param array<int, IndexDefinition> $indexes Named indexes in declaration order.
	 */
	public function __construct(
		public readonly string $table,
		public readonly array $columns,
		public readonly array $indexes
	) {}
}
