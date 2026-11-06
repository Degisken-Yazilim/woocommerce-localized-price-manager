<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Handlers;

use D6N\LPM\Core\Contracts\ILoggerHandler;
use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Modules\DiagnosticLog\Enums\LogChannelEnum;
use D6N\LPM\Modules\DiagnosticLog\Models\LogIdentity;
use D6N\LPM\Modules\DiagnosticLog\Services\LogSettings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * File destination delegating to WooCommerce's WC_Logger.
 */
class WooCommerceLogHandler implements ILoggerHandler {

	private LogSettings $settings;

	/**
	 * Constructor.
	 *
	 * @param LogSettings|null $settings  Settings reader, defaults to a fresh instance.
	 * @param object|null      $wc_logger Explicit WC_Logger instance; null resolves wc_get_logger() lazily.
	 */
	public function __construct(
		?LogSettings $settings = null,
		private ?object $wc_logger = null
	) {
		$this->settings = $settings ?? new LogSettings();
	}

	/**
	 * Handle log entry write.
	 *
	 * @param string               $level   Log level string.
	 * @param string               $message Log message.
	 * @param string               $source  Log source component/class.
	 * @param array<string, mixed> $context Context parameters including the reserved keys.
	 * @return void
	 */
	public function log( string $level, string $message, string $source, array $context ): void {
		try {
			$level_enum   = LogLevelEnum::from_value( $level );
			$channel_enum = LogChannelEnum::from_value( $context['channel'] ?? null );

			if ( ! $this->should_write( $level_enum, $channel_enum ) ) {
				return;
			}

			$logger = $this->get_wc_logger();

			if ( null === $logger || ! is_callable( array( $logger, 'log' ) ) ) {
				return;
			}

			$logger->log(
				$level_enum->value,
				$this->format_message( $message, $source, $context ),
				array( 'source' => $channel_enum->wc_log_source() )
			);
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[LPM Log Error] WooCommerce log write failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Remove every log file owned by the plugin.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		try {
			$logger = $this->get_wc_logger();

			if ( null === $logger || ! is_callable( array( $logger, 'clear' ) ) ) {
				return;
			}

			foreach ( LogChannelEnum::all_wc_log_sources() as $wc_source ) {
				$logger->clear( $wc_source );
			}
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[LPM Log Error] WooCommerce log clear failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Ask WC_Logger to drop every handle past WooCommerce's retention window.
	 *
	 * @return bool True when the expiry routine ran.
	 */
	public function purge_expired(): bool {
		try {
			$logger = $this->get_wc_logger();

			if ( null === $logger || ! is_callable( array( $logger, 'clear_expired_logs' ) ) ) {
				return false;
			}

			$logger->clear_expired_logs();

			return true;
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[LPM Log Error] WooCommerce log purge failed: %s', $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Check whether a record is allowed to reach the file destination.
	 *
	 * @param LogLevelEnum   $level   Record severity.
	 * @param LogChannelEnum $channel Record channel.
	 * @return bool True when the record must be written to file.
	 */
	private function should_write( LogLevelEnum $level, LogChannelEnum $channel ): bool {
		if ( ! $this->settings->is_file_enabled() ) {
			return false;
		}

		if ( $channel->is_threshold_exempt() ) {
			return true;
		}

		if ( $level->is_at_least( LogLevelEnum::Warning ) ) {
			return true;
		}

		return $this->settings->is_debug_mode() && $level->is_at_least( LogLevelEnum::Info );
	}

	/**
	 * Build the single line handed to WC_Logger.
	 *
	 * @param string               $message Record message.
	 * @param string               $source  Source component.
	 * @param array<string, mixed> $context Record context including the reserved keys.
	 * @return string Formatted log line.
	 */
	private function format_message( string $message, string $source, array $context ): string {
		$identity = LogIdentity::from_context( $context );
		$payload  = $context;

		unset( $payload['channel'], $payload['source'] );

		foreach ( LogIdentity::context_keys() as $key ) {
			unset( $payload[ $key ] );
		}

		$parts = array( sprintf( '[%s] %s', $source, $message ) );

		if ( null !== $identity->user_id || null !== $identity->user_login ) {
			$parts[] = sprintf( 'user=%s(%s)', (string) $identity->user_login, null === $identity->user_id ? '-' : (string) $identity->user_id );
		}

		if ( null !== $identity->ip_address ) {
			$parts[] = sprintf( 'ip=%s', $identity->ip_address );
		}

		if ( array() !== $payload ) {
			$encoded = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$parts[] = false === $encoded ? '{}' : $encoded;
		}

		return implode( ' ', $parts );
	}

	/**
	 * Resolve the WooCommerce logger instance.
	 *
	 * @return object|null WC_Logger instance, or null when WooCommerce is not loaded.
	 */
	private function get_wc_logger(): ?object {
		if ( null !== $this->wc_logger ) {
			return $this->wc_logger;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return null;
		}

		$this->wc_logger = wc_get_logger();

		return $this->wc_logger;
	}
}
