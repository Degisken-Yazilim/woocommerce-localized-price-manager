<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Settings;

use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\ExchangeRates\Providers\ExchangeRateProviderRegistry;
use D6N\LPM\Modules\Geolocation\Services\GeolocationApiKeyService;

defined( 'ABSPATH' ) || exit;

/**
 * Single door between the settings screen and the two API key services.
 */
class ApiKeyGateway {

	/**
	 * Constructor.
	 *
	 * @param ExchangeRateProviderRegistry $rate_providers Exchange rate provider registry.
	 * @param GeolocationApiKeyService     $geolocation    Geolocation API key service.
	 */
	public function __construct(
		private readonly ExchangeRateProviderRegistry $rate_providers,
		private readonly GeolocationApiKeyService $geolocation
	) {}

	/**
	 * Get the masked key rendered into the settings form.
	 *
	 * @param SettingDefinition $definition API key definition.
	 * @return string Masked key, or an empty string when none is stored.
	 */
	public function get_masked( SettingDefinition $definition ): string {
		if ( ! $definition->secret || '' === $definition->provider ) {
			return '';
		}

		if ( SettingsSchema::TAB_EXCHANGE_RATES === $definition->tab ) {
			return $this->rate_providers->get_masked_api_key( $definition->provider );
		}

		return $this->geolocation->get_masked( $definition->provider );
	}

	/**
	 * Store a submitted API key.
	 *
	 * @param SettingDefinition $definition API key definition.
	 * @param string            $raw_key    Value submitted by the administrator.
	 * @return bool True when the stored state is correct afterwards.
	 */
	public function save( SettingDefinition $definition, string $raw_key ): bool {
		if ( ! $definition->secret || '' === $definition->provider ) {
			return false;
		}

		$sanitized = Core::sanitize_api_key( $raw_key );

		if ( Core::is_masked_api_key( $sanitized ) ) {
			return true;
		}

		if ( SettingsSchema::TAB_EXCHANGE_RATES === $definition->tab ) {
			return '' === $sanitized
				? $this->rate_providers->delete_api_key( $definition->provider )
				: $this->rate_providers->save_api_key( $definition->provider, $sanitized );
		}

		return '' === $sanitized
			? $this->geolocation->delete( $definition->provider )
			: $this->geolocation->save( $definition->provider, $sanitized );
	}
}
