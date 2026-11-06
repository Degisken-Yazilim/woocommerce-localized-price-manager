<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates\Models;

use D6N\LPM\Core\Database\AbstractModel;
use D6N\LPM\Core\Enums\TablesEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Exchange rate database model.
 */
class ExchangeRateModel extends AbstractModel {

	protected string $table = TablesEnum::ExchangeRates->value;

	/**
	 * Modification timestamp column, mapped onto `last_updated`.
	 *
	 * @var string|null
	 */
	protected ?string $updated_at_column = 'last_updated';

	/**
	 * Column name to cast type map.
	 *
	 * @var array<string, string>
	 */
	protected array $casts = array(
		'id'            => 'int',
		'exchange_rate' => 'decimal',
		'last_updated'  => 'datetime',
	);

	/**
	 * Columns that may be written through the model.
	 *
	 * @var array<int, string>
	 */
	protected array $fillable = array(
		'base_currency',
		'target_currency',
		'exchange_rate',
		'source',
	);
}
