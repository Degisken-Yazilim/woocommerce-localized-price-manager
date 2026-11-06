<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Lifecycle;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Enums\TablesEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\Cache\CacheModule;
use D6N\LPM\Modules\Cache\Services\CacheManager;
use Automattic\WooCommerce\Utilities\LoggingUtil;
use D6N\LPM\Modules\DiagnosticLog\DiagnosticLogModule;
use D6N\LPM\Modules\DiagnosticLog\Enums\LogChannelEnum;
use D6N\LPM\Modules\DiagnosticLog\Handlers\WooCommerceLogHandler;
use D6N\LPM\Modules\DiagnosticLog\Services\NullLogger;
use RuntimeException;
use Throwable;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin uninstall routine, invoked from `uninstall.php`.
 */
class Uninstaller {

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'Lifecycle';

	/**
	 * User meta keys written by the geolocation module.
	 *
	 * @var array<int, string>
	 */
	private const USER_META_KEYS = array(
		'_lpm_country',
		'_lpm_country_last_check',
	);

	/**
	 * Standalone option rows the plugin writes besides its settings row.
	 *
	 * @var array<int, string>
	 */
	private const OPTION_ROWS = array(
		OptionsEnum::MigrationLock->value,
		OptionsEnum::CacheWarmUpRun->value,
		OptionsEnum::CacheWarmUpProducts->value,
		OptionsEnum::CacheProbe->value,
	);

	/**
	 * Option rows earlier releases wrote and the current code no longer does.
	 *
	 * @var array<int, string>
	 */
	private const LEGACY_OPTION_ROWS = array(
		'd6n_lpm_cache_warmup_lock',
	);

	/**
	 * Transients the plugin writes outside its cache adapter.
	 *
	 * @var array<int, string>
	 */
	private const TRANSIENTS = array(
		SchemaUpgrader::RETRY_GUARD,
	);

	/**
	 * Option name prefixes of the rows the cache adapter writes under generated names.
	 *
	 * @var array<int, string>
	 */
	private const OPTION_PREFIXES = array(
		'd6n_lpm_cache_lock_',
		'_transient_d6n_lpm_cache:',
		'_transient_timeout_d6n_lpm_cache:',
	);

	/**
	 * Constructor.
	 *
	 * @param ILogger|null               $logger      Logger instance.
	 * @param Deactivator|null           $deactivator Cron cleanup collaborator.
	 * @param wpdb|null                  $db          Database handle, defaults to the global `$wpdb`.
	 * @param WooCommerceLogHandler|null $log_handler File log destination to clear.
	 * @param ICache|null                $cache       Cache adapter to flush.
	 * @param string|null                $log_dir     WooCommerce log directory; resolved from WooCommerce when omitted.
	 */
	public function __construct(
		private ?ILogger $logger = null,
		private ?Deactivator $deactivator = null,
		private ?wpdb $db = null,
		private ?WooCommerceLogHandler $log_handler = null,
		private ?ICache $cache = null,
		private ?string $log_dir = null
	) {}

	/**
	 * Static entry point called by `uninstall.php`.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		( new self() )->run();
	}

	/**
	 * Check whether the administrator opted into destructive cleanup.
	 *
	 * @return bool True when plugin data must be removed.
	 */
	public static function is_data_removal_enabled(): bool {
		$value = Core::get_option( OptionsEnum::RemoveDataOnUninstall->value, 'no' );

		return in_array( $value, array( true, 1, '1', 'yes', 'on' ), true );
	}

	/**
	 * Run the uninstall routine.
	 *
	 * @return bool True when plugin data was removed, false when the gate kept it.
	 */
	public function run(): bool {
		$this->clear_cron_events();

		if ( ! self::is_data_removal_enabled() ) {
			$this->log_info( 'Uninstall kept the plugin data: the removal setting is off.' );

			return false;
		}

		$this->delete_meta();
		$this->flush_cache();
		$this->delete_options();

		$this->logger = new NullLogger();

		$this->delete_log_files();
		$this->delete_generated_options();
		$this->drop_tables();

		return true;
	}

	/**
	 * Clear the plugin cron events, whatever the removal setting says.
	 *
	 * @return void
	 */
	private function clear_cron_events(): void {
		try {
			$this->get_deactivator()->run();
		} catch ( Throwable $e ) {
			$this->log_error( 'Uninstall could not clear the plugin cron events.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Drop every table the plugin owns.
	 *
	 * @return void
	 */
	private function drop_tables(): void {
		try {
			$db = $this->get_db();

			foreach ( TablesEnum::all_table_names() as $table ) {
				$statement = $db->prepare( 'DROP TABLE IF EXISTS %i', $table );

				if ( null === $statement ) {
					$this->log_error( 'Uninstall could not prepare a DROP TABLE statement.', array( 'table' => $table ) );

					continue;
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$db->query( $statement );
			}
		} catch ( Throwable $e ) {
			$this->log_error( 'Uninstall could not drop the plugin tables.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Delete the plugin option rows and transients.
	 *
	 * @return void
	 */
	private function delete_options(): void {
		try {
			delete_option( Core::get_domain() );

			Core::forget_option_row();

			foreach ( array_merge( self::OPTION_ROWS, self::LEGACY_OPTION_ROWS ) as $option ) {
				delete_option( $option );
			}

			delete_transient( Core::get_domain() . '_messages' );

			foreach ( self::TRANSIENTS as $transient ) {
				delete_transient( $transient );
			}

			delete_transient( Core::build_hook( CacheManager::LEGACY_PROBE_TRANSIENT ) );
		} catch ( Throwable $e ) {
			$this->log_error( 'Uninstall could not delete the plugin options.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Delete the option rows the cache adapter wrote under generated names.
	 *
	 * @return void
	 */
	private function delete_generated_options(): void {
		try {
			$db       = $this->get_db();
			$patterns = array_map(
				static fn ( string $prefix ): string => $db->esc_like( $prefix ) . '%',
				self::OPTION_PREFIXES
			);

			$statement = $db->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$db->options,
				...$patterns
			);

			if ( null === $statement ) {
				$this->log_error( 'Uninstall could not prepare the cache option cleanup.' );

				return;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$db->query( $statement );
		} catch ( Throwable $e ) {
			$this->log_error( 'Uninstall could not delete the cache option rows.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Delete the plugin's user meta across every user.
	 *
	 * @return void
	 */
	private function delete_meta(): void {
		try {
			foreach ( self::USER_META_KEYS as $meta_key ) {
				delete_metadata( 'user', 0, $meta_key, '', true );
			}
		} catch ( Throwable $e ) {
			$this->log_error( 'Uninstall could not delete the plugin meta data.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Remove the WooCommerce log files owned by the plugin.
	 *
	 * @return void
	 */
	private function delete_log_files(): void {
		try {
			$this->get_log_handler()->uninstall();
			$this->sweep_log_directory();
		} catch ( Throwable $e ) {
			$this->log_error( 'Uninstall could not remove the plugin log files.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Delete every file in the WooCommerce log directory written under a plugin log source.
	 *
	 * @return void
	 */
	private function sweep_log_directory(): void {
		$directory = $this->get_log_dir();

		if ( null === $directory || ! is_dir( $directory ) ) {
			return;
		}

		$files = scandir( $directory );

		if ( false === $files ) {
			return;
		}

		$directory = rtrim( $directory, '/\\' ) . '/';

		foreach ( $files as $file ) {
			if ( self::is_plugin_log_file( $file ) && is_file( $directory . $file ) ) {
				wp_delete_file( $directory . $file );
			}
		}
	}

	/**
	 * Check whether a log file name belongs to one of the plugin's log sources.
	 *
	 * @param string $file File name without a directory.
	 * @return bool True when the name is `<source>[.<rotation>]-<Y-m-d>-<hash>.log` for a plugin source.
	 */
	private static function is_plugin_log_file( string $file ): bool {
		foreach ( LogChannelEnum::all_wc_log_sources() as $source ) {
			$pattern = '/^' . preg_quote( $source, '/' ) . '(\.\d+)?-\d{4}-\d{2}-\d{2}-[A-Za-z0-9]+\.log$/';

			if ( 1 === preg_match( $pattern, $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the directory WooCommerce writes its log files to.
	 *
	 * @return string|null Directory path, or null when WooCommerce is not loaded.
	 */
	private function get_log_dir(): ?string {
		if ( null !== $this->log_dir ) {
			return $this->log_dir;
		}

		if ( class_exists( LoggingUtil::class ) ) {
			return LoggingUtil::get_log_directory( false );
		}

		if ( defined( 'WC_LOG_DIR' ) ) {
			return (string) constant( 'WC_LOG_DIR' );
		}

		return null;
	}

	/**
	 * Drop the plugin's cached entries.
	 *
	 * @return void
	 */
	private function flush_cache(): void {
		try {
			$this->get_cache()->flush_all();
		} catch ( Throwable $e ) {
			$this->log_error( 'Uninstall could not flush the plugin cache.', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * Get the file log destination.
	 *
	 * @return WooCommerceLogHandler File log handler.
	 */
	private function get_log_handler(): WooCommerceLogHandler {
		if ( null === $this->log_handler ) {
			$this->log_handler = new WooCommerceLogHandler();
		}

		return $this->log_handler;
	}

	/**
	 * Get the cache adapter.
	 *
	 * @return ICache Cache adapter.
	 */
	private function get_cache(): ICache {
		if ( null === $this->cache ) {
			$this->cache = ( new CacheModule( $this->get_logger() ) )->get_cache_adapter();
		}

		return $this->cache;
	}

	/**
	 * Get the deactivation collaborator.
	 *
	 * @return Deactivator Deactivator instance.
	 */
	private function get_deactivator(): Deactivator {
		if ( null === $this->deactivator ) {
			$this->deactivator = new Deactivator( $this->get_logger() );
		}

		return $this->deactivator;
	}

	/**
	 * Get the WordPress database handle.
	 *
	 * @return wpdb Database handle.
	 * @throws RuntimeException If the global database handle is missing.
	 */
	private function get_db(): wpdb {
		if ( null === $this->db ) {
			global $wpdb;

			if ( ! $wpdb instanceof wpdb ) {
				throw new RuntimeException( 'The WordPress database handle is unavailable.' );
			}

			$this->db = $wpdb;
		}

		return $this->db;
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
	 * Log an informational uninstall event.
	 *
	 * @param string               $message Message to record.
	 * @param array<string, mixed> $context Additional context data.
	 * @return void
	 */
	private function log_info( string $message, array $context = array() ): void {
		try {
			$this->get_logger()->info( $message, $context, self::LOG_SOURCE );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Log a failed uninstall step.
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
