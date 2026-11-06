<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Database table name constants for all plugin tables (without wpdb prefix).
 */
enum TablesEnum: string {
	/** Product-level per-country pricing rules (Mode 1). */
	case CountryPricing = 'd6n_lpm_country_pricing';

	/** Category-level per-country pricing rules. */
	case CategoryPricing = 'd6n_lpm_category_pricing';

	/** Country to currency, symbol and number-format mapping (Mode 2 base). */
	case CountryCurrency = 'd6n_lpm_country_currency';

	/** Named country groups sharing one currency rule. */
	case CountryGroup = 'd6n_lpm_country_group';

	/** Exchange rates keyed by base and target currency. */
	case ExchangeRates = 'd6n_lpm_exchange_rates';

	/** Diagnostic log entries. */
	case Logs = 'd6n_lpm_logs';

	/** Migration execution tracking. */
	case Migrations = 'd6n_lpm_migrations';

	/**
	 * Get the fully prefixed table name for the current site.
	 *
	 * @return string
	 */
	public function table_name(): string {
		global $wpdb;
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return $wpdb->prefix . $this->value;
	}

	/**
	 * Get every prefixed table name, used by uninstall routines.
	 *
	 * @return array<int, string>
	 */
	public static function all_table_names(): array {
		return array_map( static fn ( self $table ): string => $table->table_name(), self::cases() );
	}
}
