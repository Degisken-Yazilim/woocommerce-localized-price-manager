<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cli\Commands;

use D6N\LPM\Core\Money\DecimalMath;
use D6N\LPM\Core\Money\DecimalText;
use D6N\LPM\Core\Money\ExchangeRate;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\Utils;
use D6N\LPM\Modules\Cli\CliContainer;
use D6N\LPM\Modules\ExchangeRates\Providers\AbstractExchangeRateProvider;
use D6N\LPM\Modules\ExchangeRates\Providers\ExchangeRateProviderRegistry;
use WP_CLI;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

defined( 'ABSPATH' ) || exit;

/**
 * `wp d6n-lpm rates update` — exchange rate refresh command.
 */
class RatesCommand {

	/**
	 * Decimal precision of stored rates, matching decimal(20,12).
	 *
	 * @var int
	 */
	private const RATE_SCALE = 12;

	/**
	 * Maximum length of the `source` column.
	 *
	 * @var int
	 */
	private const SOURCE_MAX_LENGTH = 50;

	/**
	 * Constructor.
	 *
	 * @param CliContainer $container Shared CLI object graph.
	 */
	public function __construct(
		private readonly CliContainer $container
	) {}

	/**
	 * Refresh the stored exchange rates.
	 *
	 * ## OPTIONS
	 *
	 * [--base=<currency>]
	 * : ISO 4217 base currency to refresh. Defaults to every configured base.
	 *
	 * [--provider=<key>]
	 * : Provider key to use instead of the configured priority queue.
	 *
	 * ## EXAMPLES
	 *
	 *     wp d6n-lpm rates update
	 *     wp d6n-lpm rates update --base=EUR
	 *     wp d6n-lpm rates update --base=USD --provider=frankfurter
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function update( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! $this->container->get_rates_registry()->has_providers() ) {
			WP_CLI::warning( 'No exchange rate provider is ticked, so no rate was fetched. Tick at least one under Active providers on the Rate Settings tab; the stored rates stay in use.' );

			return;
		}

		$base     = strtoupper( trim( (string) get_flag_value( $assoc_args, 'base', '' ) ) );
		$provider = strtolower( trim( (string) get_flag_value( $assoc_args, 'provider', '' ) ) );

		if ( '' === $base && '' === $provider ) {
			$this->refresh_all();

			return;
		}

		$this->refresh_one( '' !== $base ? $base : Core::get_shop_base_currency(), $provider );
	}

	/**
	 * Run the full refresh the cron job performs.
	 *
	 * @return void
	 */
	private function refresh_all(): void {
		WP_CLI::log( 'Refreshing every configured base currency…' );

		if ( ! $this->container->get_rates_service()->update_exchange_rates() ) {
			WP_CLI::error( 'No rate could be stored; the previously stored rates were kept. See the plugin log.' );

			return;
		}

		WP_CLI::success( 'Exchange rates refreshed.' );
	}

	/**
	 * Refresh a single base currency, optionally from one named provider.
	 *
	 * @param string $base         ISO 4217 base currency code.
	 * @param string $provider_key Provider key, or an empty string for the configured queue.
	 * @return void
	 */
	private function refresh_one( string $base, string $provider_key ): void {
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $base ) ) {
			WP_CLI::error( sprintf( '"%s" is not a valid ISO 4217 currency code.', $base ) );

			return;
		}

		foreach ( $this->build_queue( $base, $provider_key ) as $provider ) {
			$rates = $provider->get_rates( $base );

			if ( empty( $rates ) ) {
				WP_CLI::log( sprintf( 'Provider %s returned no rates for %s.', $provider->get_name(), $base ) );
				continue;
			}

			$rows = $this->build_rows( $base, $rates, $provider->get_name() );

			if ( array() === $rows ) {
				continue;
			}

			$this->store( $base, $provider->get_name(), $rows );

			return;
		}

		WP_CLI::error( sprintf( 'Every provider failed for base %s; the stored rates were kept.', $base ) );
	}

	/**
	 * Build the provider queue for a base currency.
	 *
	 * @param string $base         ISO 4217 base currency code.
	 * @param string $provider_key Provider key, or an empty string for the configured queue.
	 * @return array<int, AbstractExchangeRateProvider> Ordered providers.
	 */
	private function build_queue( string $base, string $provider_key ): array {
		$registry = $this->container->get_rates_registry();

		if ( '' === $provider_key ) {
			$queue = $registry->get_queue();

			if ( array() === $queue ) {
				WP_CLI::error( 'No exchange rate provider is active.' );

				return array();
			}

			return $queue;
		}

		$provider = $registry->make( $provider_key );

		if ( null === $provider ) {
			WP_CLI::error(
				sprintf(
					'Provider "%s" is unknown or has no API key configured. Known providers: %s.',
					$provider_key,
					implode( ', ', array_keys( ExchangeRateProviderRegistry::get_provider_classes() ) )
				)
			);

			return array();
		}

		return array( $provider );
	}

	/**
	 * Turn a provider rate map into repository rows.
	 *
	 * @param string               $base   Base currency code.
	 * @param array<string, mixed> $rates  Provider rate map.
	 * @param string               $source Provider display name.
	 * @return array<int, array<string, mixed>> Rate rows.
	 */
	private function build_rows( string $base, array $rates, string $source ): array {
		$trimmed    = trim( $source );
		$provenance = substr( '' !== $trimmed ? $trimmed : 'manual', 0, self::SOURCE_MAX_LENGTH );
		$rows       = array();

		foreach ( $rates as $code => $rate ) {
			$target = strtoupper( trim( (string) $code ) );

			if ( 1 !== preg_match( '/^[A-Z]{3}$/', $target ) || $target === $base ) {
				continue;
			}

			$parsed = ExchangeRate::from( DecimalMath::round( DecimalText::expand( $rate ) ?? '', self::RATE_SCALE ) );

			if ( null === $parsed ) {
				continue;
			}

			$rows[] = array(
				'base_currency'   => $base,
				'target_currency' => $target,
				'exchange_rate'   => $parsed->value(),
				'source'          => $provenance,
			);
		}

		return $rows;
	}

	/**
	 * Store the rows and report the outcome.
	 *
	 * @param string                           $base     Base currency code.
	 * @param string                           $provider Provider display name.
	 * @param array<int, array<string, mixed>> $rows     Rate rows.
	 * @return void
	 */
	private function store( string $base, string $provider, array $rows ): void {
		$written = $this->container->get_rates_service()->store_rates( $base, $rows );

		if ( is_wp_error( $written ) ) {
			WP_CLI::error( sprintf( 'Storing the rates for %s failed: %s', $base, $written->get_error_message() ) );

			return;
		}

		format_items(
			'table',
			array(
				array(
					'base'     => $base,
					'provider' => $provider,
					'fetched'  => count( $rows ),
					'stored'   => (int) $written,
				),
			),
			array( 'base', 'provider', 'fetched', 'stored' )
		);

		WP_CLI::success( sprintf( 'Refreshed %d rates for base %s from %s.', count( $rows ), $base, $provider ) );
	}
}
