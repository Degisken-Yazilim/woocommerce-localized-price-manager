<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\CategoryPricing;

use D6N\LPM\Core\Database\AbstractModel;
use D6N\LPM\Core\Enums\TablesEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Category level per-country pricing rule model.
 */
class CategoryPricingModel extends AbstractModel {

	protected string $table = TablesEnum::CategoryPricing->value;

	/**
	 * Column name to cast type map.
	 *
	 * @var array<string, string>
	 */
	protected array $casts = array(
		'id'            => 'int',
		'term_id'       => 'int',
		'exchange_rate' => 'decimal',
		'is_blocked'    => 'bool',
		'is_active'     => 'bool',
		'created_at'    => 'datetime',
		'updated_at'    => 'datetime',
	);

	/**
	 * Columns that may be written through the model.
	 *
	 * @var array<int, string>
	 */
	protected array $fillable = array(
		'term_id',
		'country_code',
		'currency_code',
		'exchange_rate',
		'is_blocked',
		'is_active',
	);

	/**
	 * Columns that may legitimately be absent or null on write.
	 *
	 * @var array<int, string>
	 */
	protected array $nullable = array(
		'currency_code',
		'exchange_rate',
		'is_blocked',
		'is_active',
	);
}
