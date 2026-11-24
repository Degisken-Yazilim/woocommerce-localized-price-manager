<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Lifecycle;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Contracts\ITaskScheduler;
use D6N\LPM\Core\Database\MigrationRepository;
use D6N\LPM\Core\Database\MigrationRunner;
use D6N\LPM\Core\Enums\ExchangeFrequencyEnum;
use D6N\LPM\Core\Enums\FallbackModeEnum;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\CronHelper;
use D6N\LPM\Core\Shared\TaskScheduler;
use D6N\LPM\Modules\Cache\CacheModule;
use D6N\LPM\Modules\DiagnosticLog\DiagnosticLogModule;
use D6N\LPM\Modules\DiagnosticLog\Services\LifecycleAuditLogger;
use D6N\LPM\Modules\DiagnosticLog\Services\NullLogger;
use D6N\LPM\Modules\ExchangeRates\ExchangeRatesModule;
use D6N\LPM\Modules\ExchangeRates\Services\ExchangeRatesService;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin activation routine.
 */
class Activator {

	/**
	 * Step key: capability check.
	 *
	 * @var string
	 */
	public const STEP_CAPABILITY = 'capability';

	/**
	 * Step key: tracking table plus migrations.
	 *
	 * @var string
	 */
	public const STEP_MIGRATIONS = 'migrations';

	/**
	 * Step key: default option seeding.
	 *
	 * @var string
	 */
	public const STEP_OPTIONS = 'options';

	/**
	 * Step key: custom cron interval registration.
	 *
	 * @var string
	 */
	public const STEP_CRON_SCHEDULES = 'cron_schedules';

	/**
	 * Step key: cron event scheduling.
	 *
	 * @var string
	 */
	public const STEP_CRON_EVENTS = 'cron_events';

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'Lifecycle';

	/**
	 * Default log retention window in days.
	 *
	 * @var int
	 */
	private const DEFAULT_LOG_RETENTION_DAYS = 30;

	/**
	 * Delay in seconds before the first log purge run.
	 *
	 * @var int
	 */
	private const LOG_PURGE_FIRST_RUN_DELAY = 300;

	/**
	 * Logger handed to the migration subsystem, built on first use.
	 *
	 * @var ILogger|null
	 */
	private ?ILogger $lifecycle_logger = null;

	/**
	 * Constructor.
	 *
	 * @param ILogger|null              $logger               Logger instance.
	 * @param MigrationRepository|null  $migration_repository Migration tracking repository.
	 * @param MigrationRunner|null      $migration_runner     Migration runner.
	 * @param ITaskScheduler|null       $scheduler            Cron scheduler.
	 * @param ExchangeRatesService|null $rates_service        Exchange rates service.
	 */
	public function __construct(
		private ?ILogger $logger = null,
		private ?MigrationRepository $migration_repository = null,
		private ?MigrationRunner $migration_runner = null,
		private ?ITaskScheduler $scheduler = null,
		private ?ExchangeRatesService $rates_service = null
	) {}

	/**
	 * Static entry point registered with `register_activation_hook()`.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$activator = new self();

		$activator->announce_failed_steps( $activator->run() );
	}

	/**
	 * Get the option defaults seeded on activation.
	 *
	 * @return array<string, mixed> Option key to default value map.
	 */
	public static function get_default_options(): array {
		return array(
			OptionsEnum::ActiveStatus->value          => 'yes',
			OptionsEnum::FallbackMode->value          => FallbackModeEnum::Direct->value,
			OptionsEnum::LogLevel->value              => LogLevelEnum::Warning->value,
			OptionsEnum::LogRetentionDays->value      => self::DEFAULT_LOG_RETENTION_DAYS,
			OptionsEnum::DbLogEnabled->value          => 'yes',
			OptionsEnum::FileLogEnabled->value        => 'no',
			OptionsEnum::ExchangeRateProvider->value  => '',
			OptionsEnum::ExchangeRateProviders->value => array(),
			OptionsEnum::ExchangeRateFrequency->value => ExchangeFrequencyEnum::ThreeHours->value,
			OptionsEnum::GeolocationProviders->value  => array(),
			OptionsEnum::CountryCookie->value         => 'no',
			OptionsEnum::TrustedHeaders->value        => '',
			OptionsEnum::TrustedCountryHeaders->value => '',
			OptionsEnum::TrustedProxyRanges->value    => '',
			OptionsEnum::WidgetCacheRevision->value   => 0,
			OptionsEnum::RemoveDataOnUninstall->value => 'no',
		);
	}

	/**
	 * Get the fully prefixed log purge cron hook.
	 *
	 * @return string Cron hook name.
	 */
	public static function get_log_purge_hook(): string {
		return Core::build_hook( HookEnum::CleanOldLogs->value );
	}

	/**
	 * Run the activation sequence.
	 *
	 * @return array<string, bool> Step key to outcome map.
	 */
	public function run(): array {
		$steps = array( self::STEP_CAPABILITY => $this->check_capability() );

		if ( ! $steps[ self::STEP_CAPABILITY ] ) {
			return $steps;
		}

		$steps[ self::STEP_MIGRATIONS ]     = $this->run_migrations();
		$steps[ self::STEP_OPTIONS ]        = $this->seed_default_options();
		$steps[ self::STEP_CRON_SCHEDULES ] = $this->register_cron_schedules();
		$steps[ self::STEP_CRON_EVENTS ]    = $this->schedule_cron_events();

		return $steps;
	}

	/**
	 * Tell the operator which activation steps did not complete.
	 *
	 * @param array<string, bool> $steps Step key to outcome map, as returned by run().
	 * @return void
	 */
	public function announce_failed_steps( array $steps ): void {
		$labels = array(
			self::STEP_CAPABILITY     => __( 'permission check', 'localized-price-manager' ),
			self::STEP_MIGRATIONS     => __( 'database schema', 'localized-price-manager' ),
			self::STEP_OPTIONS        => __( 'default settings', 'localized-price-manager' ),
			self::STEP_CRON_SCHEDULES => __( 'scheduled task intervals', 'localized-price-manager' ),
			self::STEP_CRON_EVENTS    => __( 'background jobs', 'localized-price-manager' ),
		);

		$failed = array();

		foreach ( $steps as $step => $succeeded ) {
			if ( ! $succeeded ) {
				$failed[] = $labels[ $step ] ?? $step;
			}
		}

		if ( array() === $failed ) {
			return;
		}

		Core::add_admin_notice(
			sprintf(
				/* translators: %s: comma separated list of activation steps. */
				_n(
					'Localized Price Manager activated, but one step did not complete: %s. The plugin may not work correctly until it does; see the diagnostic log.',
					'Localized Price Manager activated, but some steps did not complete: %s. The plugin may not work correctly until they do; see the diagnostic log.',
					count( $failed ),
					'localized-price-manager'
				),
				implode( ', ', $failed )
			),
			'error'
		);
	}

	/**
	 * Check that the caller is allowed to activate plugins.
	 *
	 * @return bool True when activation may proceed.
	 */
	private function check_capability(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		try {
			if ( current_user_can( 'activate_plugins' ) ) {
				return true;
			}
		} catch ( Throwable $e ) {
			$this->log_error( 'Activation capability check failed.', array( 'error' => $e->getMessage() ) );

			return false;
		}

		$this->log_error( 'Activation aborted: the current user cannot activate plugins.' );

		return false;
	}

	/**
	 * Create the migration tracking table and run every pending migration.
	 *
	 * @return bool True when the schema is up to date.
	 */
	private function run_migrations(): bool {
		try {
			if ( ! $this->get_migration_repository()->create_table() ) {
				$this->log_error( 'Activation could not create the migration tracking table.' );

				return false;
			}

			$result = $this->get_migration_runner()->run_up();

			if ( ! $result['success'] ) {
				$this->log_error(
					'Activation ran the migrations but at least one of them failed.',
					array(
						'ran'    => $result['ran'],
						'failed' => $result['failed'],
					)
				);

				return false;
			}

			$this->log_info( 'Activation completed the database migrations.', array( 'ran' => $result['ran'] ) );

			return true;
		} catch ( Throwable $e ) {
			$this->log_error( 'Activation migration step failed.', array( 'error' => $e->getMessage() ) );

			return false;
		}
	}

	/**
	 * Seed every default option key that is currently unset.
	 *
	 * @return bool True when the option row is in the expected shape afterwards.
	 */
	private function seed_default_options(): bool {
		try {
			$stored  = get_option( Core::get_domain(), array() );
			$options = is_array( $stored ) ? $stored : array();
			$seeded  = array();

			foreach ( self::get_default_options() as $key => $value ) {
				if ( array_key_exists( $key, $options ) ) {
					continue;
				}

				$options[ $key ] = $value;
				$seeded[]        = $key;
			}

			if ( array() === $seeded ) {
				return true;
			}

			$stored_row = update_option( Core::get_domain(), $options );

			Core::forget_option_row();

			if ( ! $stored_row ) {
				$this->log_error( 'Activation could not store the default options.', array( 'options' => $seeded ) );

				return false;
			}

			$this->log_info( 'Activation seeded the default options.', array( 'options' => $seeded ) );

			return true;
		} catch ( Throwable $e ) {
			$this->log_error( 'Activation option seeding failed.', array( 'error' => $e->getMessage() ) );

			return false;
		}
	}

	/**
	 * Register the plugin's custom cron intervals.
	 *
	 * @return bool True when the intervals were registered.
	 */
	private function register_cron_schedules(): bool {
		try {
			CronHelper::register_three_hours_schedule();
			CronHelper::register_four_hours_schedule();
			CronHelper::register_six_hours_schedule();

			return true;
		} catch ( Throwable $e ) {
			$this->log_error( 'Activation could not register the custom cron intervals.', array( 'error' => $e->getMessage() ) );

			return false;
		}
	}

	/**
	 * Schedule the recurring background jobs this plugin owns.
	 *
	 * @return bool True when both events are scheduled.
	 */
	private function schedule_cron_events(): bool {
		return $this->schedule_exchange_rates() && $this->schedule_log_purge();
	}

	/**
	 * Schedule the exchange rate refresh at the configured frequency.
	 *
	 * @return bool True when the event is scheduled.
	 */
	private function schedule_exchange_rates(): bool {
		try {
			if ( ! $this->get_rates_service()->has_providers() ) {
				return true;
			}

			if ( $this->get_rates_service()->setup_scheduler() ) {
				return true;
			}

			$this->log_error( 'Activation could not schedule the exchange rate refresh.' );

			return false;
		} catch ( Throwable $e ) {
			$this->log_error( 'Activation exchange rate scheduling failed.', array( 'error' => $e->getMessage() ) );

			return false;
		}
	}

	/**
	 * Schedule the daily log purge.
	 *
	 * @return bool True when the event is scheduled.
	 */
	private function schedule_log_purge(): bool {
		try {
			$scheduled = $this->get_scheduler()->schedule_recurring_event(
				time() + self::LOG_PURGE_FIRST_RUN_DELAY,
				'daily',
				self::get_log_purge_hook()
			);

			if ( $scheduled ) {
				return true;
			}

			$this->log_error( 'Activation could not schedule the log purge.', array( 'hook' => self::get_log_purge_hook() ) );

			return false;
		} catch ( Throwable $e ) {
			$this->log_error( 'Activation log purge scheduling failed.', array( 'error' => $e->getMessage() ) );

			return false;
		}
	}

	/**
	 * Get the logger, falling back to a null logger when it cannot be built.
	 *
	 * @return ILogger Logger instance.
	 */
	private function get_logger(): ILogger {
		if ( null === $this->logger ) {
			try {
				$this->logger = ( new DiagnosticLogModule() )->get_logger();
			} catch ( Throwable $e ) {
				$this->logger = new NullLogger();
			}
		}

		return $this->logger;
	}

	/**
	 * Get the logger the migration subsystem records through.
	 *
	 * @return ILogger Audit-channel logger.
	 */
	private function get_lifecycle_logger(): ILogger {
		if ( null === $this->lifecycle_logger ) {
			$this->lifecycle_logger = new LifecycleAuditLogger( $this->get_logger() );
		}

		return $this->lifecycle_logger;
	}

	/**
	 * Get the migration tracking repository.
	 *
	 * @return MigrationRepository Tracking repository.
	 */
	private function get_migration_repository(): MigrationRepository {
		if ( null === $this->migration_repository ) {
			$this->migration_repository = new MigrationRepository( $this->get_lifecycle_logger() );
		}

		return $this->migration_repository;
	}

	/**
	 * Get the migration runner.
	 *
	 * @return MigrationRunner Migration runner.
	 */
	private function get_migration_runner(): MigrationRunner {
		if ( null === $this->migration_runner ) {
			$this->migration_runner = new MigrationRunner( $this->get_lifecycle_logger(), $this->get_migration_repository() );
		}

		return $this->migration_runner;
	}

	/**
	 * Get the cron scheduler.
	 *
	 * @return ITaskScheduler Scheduler instance.
	 */
	private function get_scheduler(): ITaskScheduler {
		if ( null === $this->scheduler ) {
			$this->scheduler = new TaskScheduler( $this->get_logger() );
		}

		return $this->scheduler;
	}

	/**
	 * Get the exchange rates service.
	 *
	 * @return ExchangeRatesService Rates service.
	 */
	private function get_rates_service(): ExchangeRatesService {
		if ( null === $this->rates_service ) {
			$logger              = $this->get_logger();
			$cache               = ( new CacheModule( $logger ) )->get_cache_adapter();
			$this->rates_service = ( new ExchangeRatesModule( $logger, $cache ) )->get_service();
		}

		return $this->rates_service;
	}

	/**
	 * Record a completed activation step on the audit channel.
	 *
	 * @param string               $message Message to record.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	private function log_info( string $message, array $context = array() ): void {
		try {
			$this->get_logger()->audit( $message, $context, self::LOG_SOURCE );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Log a failed activation step.
	 *
	 * @param string               $message Message to record.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	private function log_error( string $message, array $context = array() ): void {
		try {
			$this->get_logger()->error( $message, $context, self::LOG_SOURCE );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}
