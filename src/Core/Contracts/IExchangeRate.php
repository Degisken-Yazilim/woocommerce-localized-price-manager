<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for a single exchange rate provider instance.
 */
interface IExchangeRate {
	/**
	 * Get the display name of this provider, also stored as the row `source`.
	 *
	 * @return string Provider name.
	 */
	public function get_name(): string;

	/**
	 * Check whether the provider is usable with the current configuration.
	 *
	 * @return bool True when no API key is required or a key is configured.
	 */
	public function is_available(): bool;

	/**
	 * Fetch the rate set for a base currency.
	 *
	 * @param string $base_currency ISO 4217 base currency code.
	 * @return array<string, numeric-string>|null Normalised `code => rate` map, or null on any failure.
	 */
	public function get_rates( string $base_currency ): ?array;
}
