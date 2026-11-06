<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Settings;

use D6N\LPM\Core\Enums\ExchangeFrequencyEnum;
use D6N\LPM\Core\Enums\FallbackModeEnum;
use D6N\LPM\Core\Enums\IPDetectionStrategyEnum;
use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Enums\RoundingModeEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\IPHeaderConfig;
use D6N\LPM\Core\Shared\IpRangeMatcher;
use D6N\LPM\Modules\AdminPortal\Support\CountryReference;
use D6N\LPM\Modules\ExchangeRates\Providers\ExchangeRateProviderRegistry;
use D6N\LPM\Modules\Geolocation\GeolocationAdapterRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for every configurable plugin setting.
 */
class SettingsSchema {

	public const TAB_GENERAL        = 'general';
	public const TAB_EXCHANGE_RATES = 'exchange-rates';
	public const TAB_RATES          = 'rates';
	public const TAB_GEOLOCATION    = 'geolocation';
	public const TAB_COUNTRIES      = 'countries';
	public const TAB_LOGS           = 'logs';

	/**
	 * Memoised definition map.
	 *
	 * @var array<string, SettingDefinition>|null
	 */
	private static ?array $definitions = null;

	/**
	 * Get every tab slug mapped to its translated label.
	 *
	 * @return array<string, string> Tab slug to label map, in display order.
	 */
	public static function get_tabs(): array {
		return array(
			self::TAB_GENERAL        => __( 'General', 'localized-price-manager' ),
			self::TAB_EXCHANGE_RATES => __( 'Rate Settings', 'localized-price-manager' ),
			self::TAB_RATES          => __( 'Exchange Rates', 'localized-price-manager' ),
			self::TAB_GEOLOCATION    => __( 'Geolocation', 'localized-price-manager' ),
			self::TAB_COUNTRIES      => __( 'Countries & Groups', 'localized-price-manager' ),
			self::TAB_LOGS           => __( 'Logs', 'localized-price-manager' ),
		);
	}

	/**
	 * Check whether a tab slug exists.
	 *
	 * @param string $tab Tab slug.
	 * @return bool True when the tab is known.
	 */
	public static function has_tab( string $tab ): bool {
		return array_key_exists( $tab, self::get_tabs() );
	}

	/**
	 * Get the default tab slug.
	 *
	 * @return string Tab slug.
	 */
	public static function get_default_tab(): string {
		return self::TAB_GENERAL;
	}

	/**
	 * Get every declared setting, keyed by option name.
	 *
	 * @return array<string, SettingDefinition> Definitions in declaration order.
	 */
	public static function all(): array {
		if ( null !== self::$definitions ) {
			return self::$definitions;
		}

		$definitions = array();

		foreach ( array_merge( self::general_fields(), self::exchange_rate_fields(), self::geolocation_fields() ) as $definition ) {
			$definitions[ $definition->key ] = $definition;
		}

		self::$definitions = $definitions;

		return self::$definitions;
	}

	/**
	 * Get one definition by option key.
	 *
	 * @param string $key Option key.
	 * @return SettingDefinition|null Definition, or null when the key is not configurable.
	 */
	public static function get( string $key ): ?SettingDefinition {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Get every definition belonging to one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return array<string, SettingDefinition> Definitions keyed by option key.
	 */
	public static function for_tab( string $tab ): array {
		return array_filter(
			self::all(),
			static fn ( SettingDefinition $definition ): bool => $definition->tab === $tab
		);
	}

	/**
	 * Get the default of one setting in its storage representation.
	 *
	 * @param string $key Option key.
	 * @return mixed Default value, or false when the key is unknown.
	 */
	public static function default_for( string $key ): mixed {
		return self::get( $key )->default_value ?? false;
	}

	/**
	 * Build the REST argument schema of one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return array<string, array<string, mixed>> Argument schema for register_rest_route().
	 */
	public static function rest_args( string $tab ): array {
		$args = array();

		foreach ( self::for_tab( $tab ) as $key => $definition ) {
			$args[ $key ] = $definition->to_rest_arg();
		}

		return $args;
	}

	/**
	 * Declare the General tab.
	 *
	 * @return array<int, SettingDefinition> Definitions.
	 */
	private static function general_fields(): array {
		return array(
			new SettingDefinition(
				key: OptionsEnum::ActiveStatus->value,
				tab: self::TAB_GENERAL,
				type: SettingDefinition::TYPE_BOOLEAN,
				control: SettingDefinition::CONTROL_TOGGLE,
				default_value: 'yes',
				label: __( 'Localized pricing', 'localized-price-manager' ),
				description: __( 'Master switch. When off, WooCommerce prices are left untouched.', 'localized-price-manager' ),
				mirrors: array( OptionsEnum::MasterEnabled->value )
			),
			new SettingDefinition(
				key: OptionsEnum::CustomerGeolocation->value,
				tab: self::TAB_GENERAL,
				type: SettingDefinition::TYPE_STRING,
				control: SettingDefinition::CONTROL_RADIO,
				default_value: 'geolocation',
				label: __( 'Customer country source', 'localized-price-manager' ),
				description: __( 'Which country wins when the billing address and the detected IP disagree.', 'localized-price-manager' ),
				choices: static fn (): array => array(
					'address'     => __( 'Billing address', 'localized-price-manager' ),
					'geolocation' => __( 'IP geolocation', 'localized-price-manager' ),
				)
			),
			new SettingDefinition(
				key: OptionsEnum::LocalPriceRounding->value,
				tab: self::TAB_GENERAL,
				type: SettingDefinition::TYPE_STRING,
				control: SettingDefinition::CONTROL_SELECT,
				default_value: RoundingModeEnum::None->value,
				label: __( 'Converted price rounding', 'localized-price-manager' ),
				description: __( 'Applied to automatically converted prices only. Manually entered prices are never rounded.', 'localized-price-manager' ),
				choices: static fn (): array => self::enum_choices( RoundingModeEnum::cases() )
			),
			new SettingDefinition(
				key: OptionsEnum::FallbackMode->value,
				tab: self::TAB_GENERAL,
				type: SettingDefinition::TYPE_STRING,
				control: SettingDefinition::CONTROL_SELECT,
				default_value: FallbackModeEnum::Direct->value,
				label: __( 'Unconfigured country behaviour', 'localized-price-manager' ),
				description: __( 'What to do when no product, category or country rule matches. Hiding removes the price and the add to cart button and blocks the purchase, but the product still appears in catalogue listings, where sorting and the price filter widget continue to use store base prices.', 'localized-price-manager' ),
				choices: static fn (): array => self::enum_choices( FallbackModeEnum::cases() )
			),
			new SettingDefinition(
				key: OptionsEnum::LogLevel->value,
				tab: self::TAB_GENERAL,
				type: SettingDefinition::TYPE_STRING,
				control: SettingDefinition::CONTROL_SELECT,
				default_value: LogLevelEnum::Warning->value,
				label: __( 'Minimum log level', 'localized-price-manager' ),
				description: __( 'Records below this severity are discarded. The audit channel ignores this threshold.', 'localized-price-manager' ),
				choices: static function (): array {
					$choices = array();

					foreach ( LogLevelEnum::cases() as $case ) {
						$choices[ $case->value ] = $case->label();
					}

					return $choices;
				}
			),
			new SettingDefinition(
				key: OptionsEnum::DbLogEnabled->value,
				tab: self::TAB_GENERAL,
				type: SettingDefinition::TYPE_BOOLEAN,
				control: SettingDefinition::CONTROL_TOGGLE,
				default_value: 'yes',
				label: __( 'Database logging', 'localized-price-manager' ),
				description: __( 'Store log records in the plugin log table so the log viewer can show them.', 'localized-price-manager' )
			),
			new SettingDefinition(
				key: OptionsEnum::FileLogEnabled->value,
				tab: self::TAB_GENERAL,
				type: SettingDefinition::TYPE_BOOLEAN,
				control: SettingDefinition::CONTROL_TOGGLE,
				default_value: 'no',
				label: __( 'File logging', 'localized-price-manager' ),
				description: __( 'Mirror records into the protected WooCommerce log directory.', 'localized-price-manager' )
			),
			new SettingDefinition(
				key: OptionsEnum::LogRetentionDays->value,
				tab: self::TAB_GENERAL,
				type: SettingDefinition::TYPE_INTEGER,
				control: SettingDefinition::CONTROL_SELECT,
				default_value: 30,
				label: __( 'Log retention', 'localized-price-manager' ),
				description: __( 'Older records are removed by the scheduled purge task.', 'localized-price-manager' ),
				choices: static fn (): array => array(
					'7'  => __( '7 days', 'localized-price-manager' ),
					'14' => __( '14 days', 'localized-price-manager' ),
					'30' => __( '30 days', 'localized-price-manager' ),
					'60' => __( '60 days', 'localized-price-manager' ),
					'90' => __( '90 days', 'localized-price-manager' ),
				),
				min: 7,
				max: 90
			),
			new SettingDefinition(
				key: OptionsEnum::RemoveDataOnUninstall->value,
				tab: self::TAB_GENERAL,
				type: SettingDefinition::TYPE_BOOLEAN,
				control: SettingDefinition::CONTROL_TOGGLE,
				default_value: 'no',
				label: __( 'Delete all data on uninstall', 'localized-price-manager' ),
				description: __( 'Leave this off unless you mean it. While it is on, deleting the plugin permanently removes its database tables (every per-country and per-category price rule, country currency mapping, country group, stored exchange rate and database log record), these settings, its transients and cache entries, its log files in the WooCommerce log folder and the country stored on each customer profile. Order meta written at checkout is kept. The plugin\'s scheduled tasks are removed on uninstall whether this is on or off. It cannot be undone, and deactivating the plugin does not trigger it.', 'localized-price-manager' )
			),
		);
	}

	/**
	 * Declare the Exchange Rates tab, including one field per provider API key.
	 *
	 * @return array<int, SettingDefinition> Definitions.
	 */
	private static function exchange_rate_fields(): array {
		$fields = array(
			new SettingDefinition(
				key: OptionsEnum::GlobalCurrency->value,
				tab: self::TAB_EXCHANGE_RATES,
				type: SettingDefinition::TYPE_STRING,
				control: SettingDefinition::CONTROL_SELECT,
				default_value: '',
				label: __( 'Global fallback currency', 'localized-price-manager' ),
				description: __( 'Used by the "convert" fallback mode. Defaults to the shop base currency.', 'localized-price-manager' ),
				choices: static fn (): array => self::currency_choices()
			),
			new SettingDefinition(
				key: OptionsEnum::ExchangeRateProvider->value,
				tab: self::TAB_EXCHANGE_RATES,
				type: SettingDefinition::TYPE_STRING,
				control: SettingDefinition::CONTROL_SELECT,
				default_value: ExchangeRateProviderRegistry::DEFAULT_PROVIDER,
				label: __( 'Preferred provider', 'localized-price-manager' ),
				description: __( 'Tried first when it is also checked under Active providers below. A preferred provider that is not checked is never used.', 'localized-price-manager' ),
				choices: static fn (): array => ExchangeRateProviderRegistry::get_choices()
			),
			new SettingDefinition(
				key: OptionsEnum::ExchangeRateProviders->value,
				tab: self::TAB_EXCHANGE_RATES,
				type: SettingDefinition::TYPE_ARRAY,
				control: SettingDefinition::CONTROL_ORDERED_LIST,
				default_value: array(),
				label: __( 'Active providers', 'localized-price-manager' ),
				description: __( 'Only the checked providers are tried, in the order shown, with the preferred provider moved to the front when it is checked. Unchecked providers are never used. With no provider checked, no exchange rate is fetched at all; converted prices keep using the rates already stored.', 'localized-price-manager' ),
				choices: static fn (): array => ExchangeRateProviderRegistry::get_choices()
			),
			new SettingDefinition(
				key: OptionsEnum::ExchangeRateFrequency->value,
				tab: self::TAB_EXCHANGE_RATES,
				type: SettingDefinition::TYPE_STRING,
				control: SettingDefinition::CONTROL_SELECT,
				default_value: ExchangeFrequencyEnum::ThreeHours->value,
				label: __( 'Update frequency', 'localized-price-manager' ),
				description: __( 'How often the background task refreshes the rate table.', 'localized-price-manager' ),
				choices: static fn (): array => array(
					ExchangeFrequencyEnum::ThreeHours->value => __( 'Every 3 hours (8x per day)', 'localized-price-manager' ),
					ExchangeFrequencyEnum::FourHours->value => __( 'Every 4 hours (6x per day)', 'localized-price-manager' ),
					ExchangeFrequencyEnum::SixHours->value => __( 'Every 6 hours (4x per day)', 'localized-price-manager' ),
					ExchangeFrequencyEnum::Daily->value    => __( 'Once a day', 'localized-price-manager' ),
				)
			),
		);

		foreach ( ExchangeRateProviderRegistry::get_all_metadata() as $key => $metadata ) {
			if ( ! $metadata->requires_api_key ) {
				continue;
			}

			$fields[] = self::api_key_field(
				$metadata->get_api_key_option_name(),
				self::TAB_EXCHANGE_RATES,
				$key,
				/* translators: %s: provider name. */
				sprintf( __( '%s API key', 'localized-price-manager' ), $metadata->name )
			);
		}

		return $fields;
	}

	/**
	 * Declare the Geolocation tab, including one field per provider API key.
	 *
	 * @return array<int, SettingDefinition> Definitions.
	 */
	private static function geolocation_fields(): array {
		$fields = array(
			new SettingDefinition(
				key: OptionsEnum::GeolocationProviders->value,
				tab: self::TAB_GEOLOCATION,
				type: SettingDefinition::TYPE_ARRAY,
				control: SettingDefinition::CONTROL_ORDERED_LIST,
				default_value: array(),
				label: __( 'Active providers', 'localized-price-manager' ),
				description: __( 'Checked providers form the detection chain, tried in the order shown. With no provider checked, no visitor country is detected and prices are not localized for visitors who need IP detection.', 'localized-price-manager' ),
				choices: static fn (): array => self::geolocation_choices()
			),
		);

		foreach ( GeolocationAdapterRegistry::keyed_providers() as $provider ) {
			$option = (string) GeolocationAdapterRegistry::get_api_key_option( $provider );
			$name   = (string) ( GeolocationAdapterRegistry::get( $provider )['name'] ?? $provider );

			$fields[] = self::api_key_field(
				$option,
				self::TAB_GEOLOCATION,
				$provider,
				/* translators: %s: provider name. */
				sprintf( __( '%s API key', 'localized-price-manager' ), $name )
			);
		}

		$fields[] = new SettingDefinition(
			key: OptionsEnum::IPDetectionStrategy->value,
			tab: self::TAB_GEOLOCATION,
			type: SettingDefinition::TYPE_STRING,
			control: SettingDefinition::CONTROL_RADIO,
			default_value: IPDetectionStrategyEnum::Strict->value,
			label: __( 'IP detection strategy', 'localized-price-manager' ),
			description: __( 'Leave this on Strict unless the site is behind a CDN or reverse proxy. Strict reads the connecting address and nothing else, which no visitor can forge.', 'localized-price-manager' ),
			choices: static function (): array {
				$choices = array();

				foreach ( IPDetectionStrategyEnum::cases() as $case ) {
					$choices[ $case->value ] = $case->label();
				}

				return $choices;
			}
		);

		$fields[] = new SettingDefinition(
			key: OptionsEnum::TrustedHeaders->value,
			tab: self::TAB_GEOLOCATION,
			type: SettingDefinition::TYPE_STRING,
			control: SettingDefinition::CONTROL_TEXTAREA,
			default_value: '',
			label: __( 'Trusted client IP headers', 'localized-price-manager' ),
			description: __( 'One header per line, most trusted first — for example CF-Connecting-IP, True-Client-IP, X-Real-IP. Only these are read, and only from a connecting address inside the approved ranges below. Name the header your own ingress writes: listing one you do not run lets visitors spoof their country. Left empty, no forwarded header is read at all.', 'localized-price-manager' ),
			sanitizer: static fn ( mixed $raw ): string => self::sanitize_header_list( $raw ),
			validator: static fn ( mixed $value ): bool => is_string( $value )
		);

		$fields[] = new SettingDefinition(
			key: OptionsEnum::TrustedCountryHeaders->value,
			tab: self::TAB_GEOLOCATION,
			type: SettingDefinition::TYPE_STRING,
			control: SettingDefinition::CONTROL_TEXTAREA,
			default_value: '',
			label: __( 'Trusted country headers', 'localized-price-manager' ),
			description: __( 'Optional. One header per line — for example CF-IPCountry. These carry a country code the edge has already resolved, so a match skips IP geolocation entirely. Same trust rules as above. Left empty, the country is always resolved from the IP address.', 'localized-price-manager' ),
			sanitizer: static fn ( mixed $raw ): string => self::sanitize_header_list( $raw ),
			validator: static fn ( mixed $value ): bool => is_string( $value )
		);

		$fields[] = new SettingDefinition(
			key: OptionsEnum::TrustedProxyRanges->value,
			tab: self::TAB_GEOLOCATION,
			type: SettingDefinition::TYPE_STRING,
			control: SettingDefinition::CONTROL_TEXTAREA,
			default_value: '',
			label: __( 'Approved proxy IP ranges', 'localized-price-manager' ),
			description: __( 'One entry per line: a single IP, a CIDR range (203.0.113.0/24) or an IPv4 wildcard (203.0.113.*). A header above is read only when the connecting address matches one of these. Providers rotate their networks, so take the current list from your own provider and keep it current — cloudipdb.io/providers collects the published lists in one place, but the provider is the authority.', 'localized-price-manager' ),
			sanitizer: static fn ( mixed $raw ): string => IpRangeMatcher::sanitize_text( is_scalar( $raw ) ? (string) $raw : '' ),
			validator: static fn ( mixed $value ): bool => is_string( $value )
		);

		return $fields;
	}

	/**
	 * Build one API key field.
	 *
	 * @param string $option   Option key holding the encrypted value.
	 * @param string $tab      Tab slug.
	 * @param string $provider Provider key.
	 * @param string $label    Translated label.
	 * @return SettingDefinition Definition.
	 */
	private static function api_key_field( string $option, string $tab, string $provider, string $label ): SettingDefinition {
		return new SettingDefinition(
			key: $option,
			tab: $tab,
			type: SettingDefinition::TYPE_STRING,
			control: SettingDefinition::CONTROL_API_KEY,
			default_value: '',
			label: $label,
			description: __( 'Leave the masked value untouched to keep the stored key. Clear the field to remove it.', 'localized-price-manager' ),
			sanitizer: static fn ( mixed $raw ): string => Core::sanitize_api_key( (string) ( is_scalar( $raw ) ? $raw : '' ) ),
			validator: static fn ( mixed $value ): bool => is_string( $value ),
			secret: true,
			provider: $provider
		);
	}

	/**
	 * Build a value => label map from backed enum cases that expose label().
	 *
	 * @param array<int, FallbackModeEnum|RoundingModeEnum> $cases Enum cases.
	 * @return array<string, string> Choice map.
	 */
	private static function enum_choices( array $cases ): array {
		$choices = array();

		foreach ( $cases as $case ) {
			$choices[ $case->value ] = $case->label();
		}

		return $choices;
	}

	/**
	 * Build the currency choice map.
	 *
	 * @return array<string, string> Currency code to label map.
	 */
	private static function currency_choices(): array {
		$choices = array( '' => __( 'Shop base currency', 'localized-price-manager' ) );

		$currencies = get_woocommerce_currencies();

		if ( array() === $currencies ) {
			$currencies = array( Core::get_shop_base_currency() => Core::get_shop_base_currency() );
		}

		foreach ( $currencies as $code => $name ) {
			$choices[ (string) $code ] = CountryReference::format_currency_label(
				(string) $code,
				(string) $name,
				CountryReference::decode_currency_symbol( (string) $code )
			);
		}

		return $choices;
	}

	/**
	 * Build the geolocation provider choice map.
	 *
	 * @return array<string, string> Provider key to name map.
	 */
	private static function geolocation_choices(): array {
		$choices = array();

		foreach ( GeolocationAdapterRegistry::all() as $key => $meta ) {
			$choices[ $key ] = $meta['name'];
		}

		return $choices;
	}

	/**
	 * Sanitize a submitted newline separated header-name list.
	 *
	 * @param mixed $raw Submitted value.
	 * @return string Normalised list, one header per line.
	 */
	private static function sanitize_header_list( mixed $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$lines = preg_split( '/\R/', (string) $raw );

		if ( false === $lines ) {
			return '';
		}

		$headers = array();

		foreach ( $lines as $line ) {
			$header = IPHeaderConfig::normalize_header( $line );

			if ( '' !== $header && ! in_array( $header, $headers, true ) ) {
				$headers[] = $header;
			}
		}

		return implode( "\n", $headers );
	}
}
