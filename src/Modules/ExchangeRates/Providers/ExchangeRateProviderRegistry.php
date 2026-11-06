<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates\Providers;

use D6N\LPM\Core\Contracts\IHttpRequest;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Shared\Core;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of every known exchange rate provider.
 */
class ExchangeRateProviderRegistry {

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'ExchangeRates';

	/**
	 * Provider key used when the administrator has not chosen one.
	 *
	 * @var string
	 */
	public const DEFAULT_PROVIDER = 'frankfurter';

	/**
	 * Option key holding the per provider priority and active/passive toggles.
	 *
	 * @var string
	 */
	public const OPTION_PROVIDER_SETTINGS = OptionsEnum::ExchangeRateProviders->value;

	/**
	 * Known providers in default priority order (free fallback last).
	 *
	 * @var array<string, class-string<AbstractExchangeRateProvider>>
	 */
	private const PROVIDER_CLASSES = array(
		'fixer'             => FixerProvider::class,
		'openexchangerates' => OpenExchangeRatesProvider::class,
		'exchangeratesapi'  => ExchangeRatesApiProvider::class,
		'fxratesapi'        => FxRatesApiProvider::class,
		'exchangerate_api'  => ExchangeRateApiProvider::class,
		'frankfurter'       => FrankfurterProvider::class,
	);

	/**
	 * Providers already built during this request, keyed by provider key.
	 *
	 * @var array<string, AbstractExchangeRateProvider|null>
	 */
	private array $instances = array();

	/**
	 * Constructor.
	 *
	 * @param ILogger           $logger Logger instance.
	 * @param IHttpRequest|null $http   Shared HTTP client handed to every provider.
	 */
	public function __construct(
		private readonly ILogger $logger,
		private readonly ?IHttpRequest $http = null
	) {}

	/**
	 * Get the provider class map.
	 *
	 * @return array<string, class-string<AbstractExchangeRateProvider>> Provider key to class map.
	 */
	public static function get_provider_classes(): array {
		return self::PROVIDER_CLASSES;
	}

	/**
	 * Get the metadata of every registered provider.
	 *
	 * @return array<string, ProviderMetadata> Provider key to metadata map.
	 */
	public static function get_all_metadata(): array {
		$metadata = array();
		foreach ( self::get_provider_classes() as $key => $class ) {
			$metadata[ $key ] = $class::metadata();
		}

		/**
		 * Filters the metadata of every registered exchange rate provider.
		 *
		 * @param array<string, ProviderMetadata> $metadata Provider key to metadata map.
		 */
		return apply_filters( Core::build_hook( HookEnum::ExchangeRateProviders->value ), $metadata );
	}

	/**
	 * Get the metadata of a single provider.
	 *
	 * @param string $key Provider key.
	 * @return ProviderMetadata|null Metadata, or null when the key is unknown.
	 */
	public static function get_metadata_for( string $key ): ?ProviderMetadata {
		return self::get_all_metadata()[ $key ] ?? null;
	}

	/**
	 * Get a provider key to display name map for admin select fields.
	 *
	 * @return array<string, string> Provider key to display name map.
	 */
	public static function get_choices(): array {
		$choices = array();
		foreach ( self::get_all_metadata() as $key => $metadata ) {
			$choices[ $key ] = $metadata->name;
		}

		return $choices;
	}

	/**
	 * Get the provider key selected by the administrator.
	 *
	 * @return string Selected provider key, falling back to the free provider.
	 */
	public static function get_selected_key(): string {
		$selected = Core::get_option( OptionsEnum::ExchangeRateProvider->value, self::DEFAULT_PROVIDER );
		$selected = is_string( $selected ) ? strtolower( trim( $selected ) ) : '';
		$classes  = self::get_provider_classes();

		return array_key_exists( $selected, $classes ) ? $selected : self::DEFAULT_PROVIDER;
	}

	/**
	 * Get the ticked provider keys in the order they should be tried.
	 *
	 * @return array<string> Ordered provider keys, empty when the operator ticked none.
	 */
	public static function get_ordered_keys(): array {
		$settings = self::get_provider_settings();
		$selected = self::get_selected_key();
		$keys     = array_keys( self::get_provider_classes() );
		$weights  = array();

		foreach ( $keys as $index => $key ) {
			if ( ! self::is_active( $key, $settings ) ) {
				continue;
			}

			$configured      = $settings[ $key ]['priority'] ?? null;
			$weights[ $key ] = is_numeric( $configured ) ? (int) $configured : ( $index + 1 ) * 10;
		}

		asort( $weights, SORT_NUMERIC );
		$ordered = array_keys( $weights );

		if ( ! in_array( $selected, $ordered, true ) ) {
			return $ordered;
		}

		$ordered = array_values( array_diff( $ordered, array( $selected ) ) );
		array_unshift( $ordered, $selected );

		return $ordered;
	}

	/**
	 * Check whether the operator ticked at least one known provider.
	 *
	 * @return bool True when the configured queue is not empty.
	 */
	public function has_providers(): bool {
		return array() !== self::get_ordered_keys();
	}

	/**
	 * Get a provider instance ready to answer requests.
	 *
	 * @param string $key Provider key.
	 * @return AbstractExchangeRateProvider|null Provider, or null when unknown or unconfigured.
	 */
	public function make( string $key ): ?AbstractExchangeRateProvider {
		if ( array_key_exists( $key, $this->instances ) ) {
			return $this->instances[ $key ];
		}

		$this->instances[ $key ] = null;

		$class = self::get_provider_classes()[ $key ] ?? null;
		if ( null === $class ) {
			$this->logger->service( sprintf( 'Unknown exchange rate provider "%s" requested.', $key ), array(), self::LOG_SOURCE );
			return null;
		}

		$metadata = $class::metadata();
		$api_key  = $metadata->requires_api_key ? $this->get_api_key( $key ) : null;
		$provider = new $class( $api_key, $this->logger, $this->http );

		if ( ! $provider->is_available() ) {
			$this->logger->service(
				sprintf( 'Exchange rate provider %s is skipped: no API key configured.', $metadata->name ),
				array( 'provider' => $key ),
				self::LOG_SOURCE
			);
			return null;
		}

		$this->instances[ $key ] = $provider;

		return $provider;
	}

	/**
	 * Build the ordered provider queue.
	 *
	 * @return array<AbstractExchangeRateProvider> Ordered, ready to use providers.
	 */
	public function get_queue(): array {
		$known = self::get_all_metadata();
		$queue = array();

		foreach ( self::get_ordered_keys() as $key ) {
			$metadata = $known[ $key ] ?? null;
			if ( null === $metadata ) {
				continue;
			}

			$provider = $this->make( $key );
			if ( null !== $provider ) {
				$queue[] = $provider;
			}
		}

		return $queue;
	}

	/**
	 * Get the decrypted API key of a provider.
	 *
	 * @param string $key Provider key.
	 * @return string|null Plain text API key, or null when unset or undecryptable.
	 */
	public function get_api_key( string $key ): ?string {
		$metadata = self::get_metadata_for( $key );
		if ( null === $metadata ) {
			return null;
		}

		$stored = Core::get_option( $metadata->get_api_key_option_name(), '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		try {
			$plain = Core::decrypt( $stored );
		} catch ( RuntimeException $e ) {
			$this->logger->service(
				sprintf( 'Failed to decrypt the %s API key: %s', $metadata->name, $e->getMessage() ),
				array( 'provider' => $key ),
				self::LOG_SOURCE
			);
			return null;
		}

		return '' !== $plain ? $plain : null;
	}

	/**
	 * Get the API key of a provider masked for display.
	 *
	 * @param string $key Provider key.
	 * @return string Masked key, or an empty string when no key is stored.
	 */
	public function get_masked_api_key( string $key ): string {
		$plain = $this->get_api_key( $key );

		return null === $plain ? '' : Core::mask_api_key( $plain );
	}

	/**
	 * Store the API key of a provider encrypted.
	 *
	 * @param string $key     Provider key.
	 * @param string $api_key Plain text API key as submitted by the administrator.
	 * @return bool True when the key was stored or intentionally left untouched.
	 */
	public function save_api_key( string $key, string $api_key ): bool {
		$metadata = self::get_metadata_for( $key );
		if ( null === $metadata ) {
			return false;
		}

		$sanitized = Core::sanitize_api_key( $api_key );

		if ( Core::is_masked_api_key( $sanitized ) ) {
			return true;
		}

		try {
			$stored = Core::save_option( $metadata->get_api_key_option_name(), Core::encrypt( $sanitized ) );
		} catch ( RuntimeException $e ) {
			$this->logger->service(
				sprintf( 'Failed to encrypt the %s API key: %s', $metadata->name, $e->getMessage() ),
				array( 'provider' => $key ),
				self::LOG_SOURCE
			);
			return false;
		}

		return $stored;
	}

	/**
	 * Remove the stored API key of a provider.
	 *
	 * @param string $key Provider key.
	 * @return bool True when no key is stored afterwards.
	 */
	public function delete_api_key( string $key ): bool {
		$metadata = self::get_metadata_for( $key );
		if ( null === $metadata ) {
			return false;
		}

		$option = $metadata->get_api_key_option_name();

		if ( Core::save_option( $option, '' ) ) {
			return true;
		}

		return '' === (string) Core::get_option( $option, '' );
	}

	/**
	 * Read the stored priority and active/passive settings.
	 *
	 * @return array<string, array<string, mixed>> Per provider settings.
	 */
	private static function get_provider_settings(): array {
		$settings = Core::get_option( self::OPTION_PROVIDER_SETTINGS, array() );

		if ( ! is_array( $settings ) ) {
			return array();
		}

		if ( array_values( $settings ) === $settings ) {
			$normalized = array();

			foreach ( $settings as $priority => $key ) {
				if ( is_string( $key ) && '' !== $key ) {
					$normalized[ $key ] = array(
						'active'   => true,
						'priority' => $priority,
					);
				}
			}

			return $normalized;
		}

		return $settings;
	}

	/**
	 * Check whether a provider is toggled active.
	 *
	 * @param string                              $key      Provider key.
	 * @param array<string, array<string, mixed>> $settings Stored provider settings.
	 * @return bool True when the provider may be used.
	 */
	private static function is_active( string $key, array $settings ): bool {
		$entry = $settings[ $key ] ?? null;

		if ( ! is_array( $entry ) ) {
			return false;
		}

		if ( ! array_key_exists( 'active', $entry ) ) {
			return true;
		}

		return in_array( $entry['active'], array( true, 1, '1', 'yes', 'on' ), true );
	}
}
