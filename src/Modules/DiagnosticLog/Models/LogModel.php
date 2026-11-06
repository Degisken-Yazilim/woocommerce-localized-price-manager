<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Models;

use D6N\LPM\Core\Database\AbstractModel;
use D6N\LPM\Core\Enums\TablesEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Model class for database log records.
 */
class LogModel extends AbstractModel {

	protected string $table       = TablesEnum::Logs->value;
	protected string $primary_key = 'id';

	/**
	 * Modification timestamp column; none for log rows.
	 *
	 * @var string|null
	 */
	protected ?string $updated_at_column = null;

	/**
	 * Columns that may be written through the model.
	 *
	 * @var array<int, string>
	 */
	protected array $fillable = array(
		'level',
		'channel',
		'source',
		'message',
		'context',
		'user_id',
		'user_login',
		'ip_address',
		'created_at',
	);

	/**
	 * Columns that may legitimately be absent or null on write.
	 *
	 * @var array<int, string>
	 */
	protected array $nullable = array(
		'context',
		'user_id',
		'user_login',
		'ip_address',
	);

	/**
	 * Column name to cast type map.
	 *
	 * @var array<string, string>
	 */
	protected array $casts = array(
		'id'         => 'int',
		'user_id'    => 'int',
		'context'    => 'json',
		'created_at' => 'datetime',
	);
}
