<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates\Services;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Contracts\ITaskScheduler;
use D6N\LPM\Core\Enums\CacheGroupEnum;
use D6N\LPM\Core\Enums\CronScheduleEnum;
use D6N\LPM\Core\Enums\ExchangeFrequencyEnum;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Money\DecimalMath;
use D6N\LPM\Core\Money\DecimalText;
use D6N\LPM\Core\Money\ExchangeRate;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\CronHelper;
use D6N\LPM\Core\Shared\TaskScheduler;
use D6N\LPM\Core\Shared\Utils;
use D6N\LPM\Modules\ExchangeRates\Providers\AbstractExchangeRateProvider;
use D6N\LPM\Modules\ExchangeRates\Providers\ExchangeRateProviderRegistry;
use D6N\LPM\Modules\ExchangeRates\Repositories\ExchangeRateRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Exchange rate refresh, conversion and scheduling service.
 */
class ExchangeRatesService implements IHookable {

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'ExchangeRates';

	/**
	 * Hook suffix fired after a successful refresh.
	 *
	 * @var string
	 */
	private const HOOK_RATES_UPDATED = HookEnum::CurrencyRateUpdated->value;

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
	 * Delay in seconds before the first scheduled run.
	 *
	 * @var int
	 */
	private const FIRST_RUN_DELAY = 60;

	/**
	 * Lookup service; built once on first use when none was injected.
	 *
	 * @var ExchangeRateLookupService|null
	 */
	private ?ExchangeRateLookupService $lookup;

	/**
	 * Task scheduler; built once on first use when none was injected.
	 *
	 * @var ITaskScheduler|null
	 */
	private ?ITaskScheduler $scheduler;

	/**
	 * Whether the plugin's custom cron intervals have been registered.
	 *
	 * @var bool
	 */
	private bool $schedules_registered = false;

	/**
	 * Constructor.
	 *
	 * @param ExchangeRateRepository            $repository Rate repository.
	 * @param AbstractExchangeRateProvider      $provider   Fallback provider used when no registry is injected.
	 * @param ICache                            $cache      Cache adapter.
	 * @param ILogger                           $logger     Logger instance.
	 * @param ExchangeRateLookupService|null    $lookup     Rate lookup service.
	 * @param ExchangeRateProviderRegistry|null $registry   Provider registry driving the priority queue.
	 * @param ITaskScheduler|null               $scheduler  Cron scheduler.
	 */
	public function __construct(
		private readonly ExchangeRateRepository $repository,
		private readonly AbstractExchangeRateProvider $provider,
		private readonly ICache $cache,
		private readonly ILogger $logger,
		?ExchangeRateLookupService $lookup = null,
		private readonly ?ExchangeRateProviderRegistry $registry = null,
		?ITaskScheduler $scheduler = null
	) {
		$this->lookup    = $lookup;
		$this->scheduler = $scheduler;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( $this->get_cron_hook(), array( $this, 'run_scheduled_update' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Schedule the recurring refresh when the event has gone missing.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! Core::is_active() || ! $this->has_providers() || false !== wp_next_scheduled( $this->get_cron_hook() ) ) {
			return;
		}

		$this->setup_scheduler();
	}

	/**
	 * Cron entry point: refresh the rates and discard the outcome flag.
	 *
	 * @return void
	 */
	public function run_scheduled_update(): void {
		if ( ! Core::is_active() ) {
			$this->logger->debug(
				'Scheduled exchange rate refresh skipped: the plugin master switch is off.',
				array(),
				self::LOG_SOURCE
			);

			return;
		}

		$this->update_exchange_rates();
	}

	/**
	 * Get the cron hook that triggers a refresh.
	 *
	 * @return string Fully prefixed hook name.
	 */
	public function get_cron_hook(): string {
		return Core::build_hook( HookEnum::UpdateExchangeRates->value );
	}

	/**
	 * Schedule the recurring refresh using the configured frequency.
	 *
	 * @return bool True when the event is scheduled.
	 */
	public function setup_scheduler(): bool {
		if ( ! $this->has_providers() ) {
			return false;
		}

		$frequency = Core::get_option(
			OptionsEnum::ExchangeRateFrequency->value,
			ExchangeFrequencyEnum::ThreeHours->value
		);

		return $this->schedule_event( is_string( $frequency ) ? $frequency : '' );
	}

	/**
	 * Move the recurring refresh onto a different frequency.
	 *
	 * @param string $frequency ExchangeFrequencyEnum value.
	 * @return bool True when the event is scheduled again.
	 */
	public function reschedule( string $frequency ): bool {
		$this->get_scheduler()->clear_scheduled_hook( $this->get_cron_hook() );

		return $this->schedule_event( $frequency );
	}

	/**
	 * Get the rate that converts one unit of $from into $to.
	 *
	 * @param string $from Source currency code.
	 * @param string $to   Target currency code.
	 * @return string|null Rate as decimal text, or null when the pair cannot be resolved.
	 */
	public function get_exchange_rate( string $from, string $to ): ?string {
		$source = $this->normalise_currency( $from );
		$target = $this->normalise_currency( $to );

		if ( null === $source || null === $target ) {
			return null;
		}

		if ( $source === $target ) {
			return '1';
		}

		$rate = $this->cache->remember(
			"rate_{$source}_to_{$target}",
			fn (): ?string => $this->get_lookup()->get_rate( $source, $target ),
			CacheGroupEnum::ExchangeRates->ttl(),
			CacheGroupEnum::ExchangeRates->value
		);

		return ExchangeRate::from( $rate )?->value();
	}

	/**
	 * Check whether the operator ticked at least one exchange rate provider.
	 *
	 * @return bool True when a refresh has a provider to ask.
	 */
	public function has_providers(): bool {
		return null === $this->registry || $this->registry->has_providers();
	}

	/**
	 * Refresh the stored rates for every base currency.
	 *
	 * @return bool True when at least one base currency was refreshed.
	 */
	public function update_exchange_rates(): bool {
		if ( ! $this->has_providers() ) {
			$this->logger->service(
				'Exchange rate update skipped: no exchange rate provider is ticked; the previously stored rates are kept.',
				array(),
				self::LOG_SOURCE
			);
			return false;
		}

		$queue = $this->get_provider_queue();

		if ( empty( $queue ) ) {
			$this->logger->service( 'No exchange rate provider is active.', array(), self::LOG_SOURCE );
			return false;
		}

		$updated_bases = array();
		$written_rows  = 0;

		foreach ( $this->get_base_currencies() as $base ) {
			$rows = $this->fetch_rows_for_base( $base, $queue );

			if ( empty( $rows ) ) {
				continue;
			}

			if ( is_wp_error( $this->persist_rows( $base, $rows ) ) ) {
				continue;
			}

			$updated_bases[] = $base;
			$written_rows   += count( $rows );
		}

		if ( empty( $updated_bases ) ) {
			$this->logger->service(
				'Exchange rate update finished without storing a single rate; the previously stored rates are kept.',
				array(),
				self::LOG_SOURCE
			);
			return false;
		}

		$this->announce_refresh( $updated_bases );

		$this->logger->service(
			sprintf( 'Stored %d exchange rates for base %s.', $written_rows, implode( ', ', $updated_bases ) ),
			array( 'bases' => $updated_bases ),
			self::LOG_SOURCE
		);

		return true;
	}

	/**
	 * Store a prepared rate set for one base currency and announce the refresh like a scheduled one.
	 *
	 * @param string                      $base Base currency code.
	 * @param array<array<string, mixed>> $rows Rate rows ready for the repository.
	 * @return int|WP_Error Number of affected rows, or WP_Error when the write failed.
	 */
	public function store_rates( string $base, array $rows ): int|WP_Error {
		$written = $this->persist_rows( $base, $rows );

		if ( ! is_wp_error( $written ) ) {
			$this->announce_refresh( array( $base ) );
		}

		return $written;
	}

	/**
	 * Write one base currency's rate rows, logging a failed write.
	 *
	 * @param string                      $base Base currency code.
	 * @param array<array<string, mixed>> $rows Rate rows.
	 * @return int|WP_Error Number of affected rows, or WP_Error when the write failed.
	 */
	private function persist_rows( string $base, array $rows ): int|WP_Error {
		$written = $this->repository->bulk_upsert_rates( $rows );

		if ( is_wp_error( $written ) ) {
			$this->logger->error(
				sprintf( 'Failed to store exchange rates for base %s: %s', $base, $written->get_error_message() ),
				array( 'base_currency' => $base ),
				self::LOG_SOURCE
			);
		}

		return $written;
	}

	/**
	 * Drop the cached rates and fire the refresh actions for the bases just stored.
	 *
	 * @param array<int, string> $updated_bases Base currencies whose rates were stored.
	 * @return void
	 */
	private function announce_refresh( array $updated_bases ): void {
		$this->cache->flush_group( CacheGroupEnum::ExchangeRates->value );
		$this->get_lookup()->flush();

		foreach ( $updated_bases as $base ) {
			/**
			 * Fires once per base currency whose stored rates have just changed.
			 *
			 * @param string $currency_code Base currency whose rates were refreshed.
			 */
			do_action( Core::build_hook( self::HOOK_RATES_UPDATED ), $base );
		}

		/**
		 * Fires once after a refresh stored rates for at least one base currency.
		 *
		 * @param array<int, string> $bases Base currencies whose rates were refreshed.
		 */
		do_action( Core::build_hook( HookEnum::ExchangeRatesRefreshed->value ), $updated_bases );
	}

	/**
	 * Fetch a rate set for one base currency from the provider queue.
	 *
	 * @param string                              $base  Base currency code.
	 * @param array<AbstractExchangeRateProvider> $queue Ordered providers to try.
	 * @return array<array<string, mixed>> Rows ready for the repository; empty when no provider answered.
	 */
	private function fetch_rows_for_base( string $base, array $queue ): array {
		foreach ( $queue as $provider ) {
			$rates = $provider->get_rates( $base );

			if ( empty( $rates ) ) {
				continue;
			}

			$rows = $this->build_rows( $base, $rates, $provider->get_name() );

			if ( ! empty( $rows ) ) {
				return $rows;
			}
		}

		$this->logger->service(
			sprintf( 'Every exchange rate provider failed for base %s; the stored rates are kept.', $base ),
			array( 'base_currency' => $base ),
			self::LOG_SOURCE
		);

		return array();
	}

	/**
	 * Turn a provider rate map into repository rows.
	 *
	 * @param string               $base   Base currency code.
	 * @param array<string, mixed> $rates  Provider rate map.
	 * @param string               $source Provider display name.
	 * @return array<array<string, mixed>> Rate rows.
	 */
	private function build_rows( string $base, array $rates, string $source ): array {
		$trimmed    = trim( $source );
		$provenance = substr( '' !== $trimmed ? $trimmed : 'manual', 0, self::SOURCE_MAX_LENGTH );
		$rows       = array();

		foreach ( $rates as $code => $rate ) {
			$target = $this->normalise_currency( (string) $code );

			if ( null === $target || $target === $base ) {
				continue;
			}

			$text   = DecimalText::expand( $rate );
			$parsed = null === $text ? null : ExchangeRate::from( DecimalMath::round( $text, self::RATE_SCALE ) );

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
	 * Get the provider queue to try.
	 *
	 * @return array<AbstractExchangeRateProvider> Ordered providers.
	 */
	private function get_provider_queue(): array {
		if ( null === $this->registry ) {
			return array( $this->provider );
		}

		return $this->registry->get_queue();
	}

	/**
	 * Get the base currencies the rate table is maintained for.
	 *
	 * @return array<string> Deduplicated base currency codes.
	 */
	private function get_base_currencies(): array {
		$candidates = array(
			'USD',
			'EUR',
			Core::get_global_currency(),
			Core::get_shop_base_currency(),
		);

		$bases = array();

		foreach ( $candidates as $candidate ) {
			$currency = $this->normalise_currency( $candidate );

			if ( null !== $currency && ! in_array( $currency, $bases, true ) ) {
				$bases[] = $currency;
			}
		}

		return $bases;
	}

	/**
	 * Validate and normalise an ISO 4217 currency code.
	 *
	 * @param string $currency Raw currency code.
	 * @return string|null Uppercase code, or null when malformed.
	 */
	private function normalise_currency( string $currency ): ?string {
		$normalised = strtoupper( trim( $currency ) );

		return 1 === preg_match( '/^[A-Z]{3}$/', $normalised ) ? $normalised : null;
	}

	/**
	 * Get the lookup service.
	 *
	 * @return ExchangeRateLookupService Lookup service instance.
	 */
	private function get_lookup(): ExchangeRateLookupService {
		if ( null === $this->lookup ) {
			$this->lookup = new ExchangeRateLookupService( $this->repository, Core::get_global_currency() );
		}

		return $this->lookup;
	}

	/**
	 * Get the task scheduler.
	 *
	 * @return ITaskScheduler Scheduler instance.
	 */
	private function get_scheduler(): ITaskScheduler {
		if ( null === $this->scheduler ) {
			$this->scheduler = new TaskScheduler( $this->logger );
		}

		return $this->scheduler;
	}

	/**
	 * Schedule the recurring refresh for a frequency setting.
	 *
	 * @param string $frequency ExchangeFrequencyEnum value.
	 * @return bool True when the event is scheduled.
	 */
	private function schedule_event( string $frequency ): bool {
		$recurrence = $this->get_recurrence( $frequency );

		$this->register_custom_schedules();

		$scheduled = $this->get_scheduler()->schedule_recurring_event(
			time() + self::FIRST_RUN_DELAY,
			$recurrence,
			$this->get_cron_hook()
		);

		if ( ! $scheduled ) {
			$this->logger->error(
				sprintf( 'Failed to schedule the exchange rate refresh with recurrence "%s".', $recurrence ),
				array( 'hook' => $this->get_cron_hook() ),
				self::LOG_SOURCE
			);
		}

		return $scheduled;
	}

	/**
	 * Map an update frequency setting onto a WordPress cron recurrence.
	 *
	 * @param string $frequency ExchangeFrequencyEnum value.
	 * @return string Cron recurrence key.
	 */
	private function get_recurrence( string $frequency ): string {
		$setting = ExchangeFrequencyEnum::tryFrom( strtolower( trim( $frequency ) ) )
			?? ExchangeFrequencyEnum::ThreeHours;

		return match ( $setting ) {
			ExchangeFrequencyEnum::ThreeHours => CronScheduleEnum::ThreeHours->value,
			ExchangeFrequencyEnum::FourHours  => CronScheduleEnum::FourHours->value,
			ExchangeFrequencyEnum::SixHours   => CronScheduleEnum::SixHours->value,
			ExchangeFrequencyEnum::Daily      => 'daily',
		};
	}

	/**
	 * Make sure the plugin's custom cron intervals exist before scheduling.
	 *
	 * @return void
	 */
	private function register_custom_schedules(): void {
		if ( $this->schedules_registered ) {
			return;
		}

		CronHelper::register_three_hours_schedule();
		CronHelper::register_four_hours_schedule();
		CronHelper::register_six_hours_schedule();

		$this->schedules_registered = true;
	}
}
