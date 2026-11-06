<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\CountryPricing;

use D6N\LPM\Core\Database\AbstractModel;
use D6N\LPM\Core\Enums\TablesEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Product level per-country pricing rule model.
 */
class CountryPricingModel extends AbstractModel {

	protected string $table = TablesEnum::CountryPricing->value;

	/**
	 * Column name to cast type map.
	 *
	 * @var array<string, string>
	 */
	protected array $casts = array(
		'id'               => 'int',
		'product_id'       => 'int',
		'input_price'      => 'decimal',
		'input_sale_price' => 'decimal',
		'local_price'      => 'decimal',
		'local_sale_price' => 'decimal',
		'exchange_rate'    => 'decimal',
		'auto_convert'     => 'bool',
		'is_blocked'       => 'bool',
		'is_active'        => 'bool',
		'created_at'       => 'datetime',
		'updated_at'       => 'datetime',
	);

	/**
	 * Columns that may be written through the model.
	 *
	 * @var array<int, string>
	 */
	protected array $fillable = array(
		'product_id',
		'country_code',
		'input_price',
		'input_sale_price',
		'input_currency',
		'local_price',
		'local_sale_price',
		'local_currency',
		'exchange_rate',
		'auto_convert',
		'is_blocked',
		'is_active',
	);

	/**
	 * Columns that may legitimately be absent or null on write.
	 *
	 * @var array<int, string>
	 */
	protected array $nullable = array(
		'input_price',
		'input_sale_price',
		'input_currency',
		'local_price',
		'local_sale_price',
		'local_currency',
		'exchange_rate',
		'auto_convert',
		'is_blocked',
		'is_active',
	);
}
