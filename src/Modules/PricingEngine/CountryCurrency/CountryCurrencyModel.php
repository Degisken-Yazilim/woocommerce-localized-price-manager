<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\CountryCurrency;

use D6N\LPM\Core\Database\AbstractModel;
use D6N\LPM\Core\Enums\TablesEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Country to currency mapping model — the Mode 2 base table.
 */
class CountryCurrencyModel extends AbstractModel {

	protected string $table = TablesEnum::CountryCurrency->value;

	/**
	 * Column name to cast type map.
	 *
	 * @var array<string, string>
	 */
	protected array $casts = array(
		'id'         => 'int',
		'decimals'   => 'int',
		'group_id'   => 'int',
		'is_active'  => 'bool',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	);

	/**
	 * Columns that may be written through the model.
	 *
	 * @var array<int, string>
	 */
	protected array $fillable = array(
		'country_code',
		'currency_code',
		'currency_symbol',
		'native_currency_code',
		'decimal_separator',
		'thousand_separator',
		'decimals',
		'group_id',
		'is_active',
	);

	/**
	 * Columns that may legitimately be absent or null on write.
	 *
	 * @var array<int, string>
	 */
	protected array $nullable = array(
		'currency_symbol',
		'native_currency_code',
		'decimal_separator',
		'thousand_separator',
		'decimals',
		'group_id',
		'is_active',
	);
}
