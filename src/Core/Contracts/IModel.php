<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for a table-backed model.
 */
interface IModel extends IDataMapper, IEntity {
	/**
	 * Get the prefixed table name this model reads and writes.
	 *
	 * @return string Table name including the `$wpdb` prefix.
	 */
	public function get_table(): string;
}
