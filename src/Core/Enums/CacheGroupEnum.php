<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Cache group identifiers and their default TTL values.
 */
enum CacheGroupEnum: string {
	case Pricing         = 'pricing';
	case ExchangeRates   = 'exchange_rates';
	case CountryCurrency = 'country_currency';
	case Geolocation     = 'geolocation';
	case Versioning      = 'versioning';

	/**
	 * Get the default TTL in seconds for this cache group.
	 *
	 * @return int TTL in seconds.
	 */
	public function ttl(): int {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return match ( $this->value ) {
			self::Pricing->value => 6 * 60 * 60,
			self::ExchangeRates->value => 2 * 60 * 60,
			self::Versioning->value => 365 * 24 * 60 * 60,
			default => 24 * 60 * 60,
		};
	}
}
