<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, construction-free description of an exchange rate provider.
 */
class ProviderMetadata {

	/**
	 * Constructor.
	 *
	 * @param string $key              Registry key, e.g. 'fixer'.
	 * @param string $name             Human readable provider name, also stored in the `source` column.
	 * @param bool   $requires_api_key Whether the provider refuses to answer without a key.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $name,
		public readonly bool $requires_api_key = true
	) {}

	/**
	 * Get the plugin option key holding the encrypted API key.
	 *
	 * @return string Option key, e.g. 'fixer_api_key'.
	 */
	public function get_api_key_option_name(): string {
		return $this->key . '_api_key';
	}
}
