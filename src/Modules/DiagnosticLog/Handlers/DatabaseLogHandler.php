<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Handlers;

use D6N\LPM\Core\Contracts\ILoggerHandler;
use D6N\LPM\Modules\DiagnosticLog\Enums\LogChannelEnum;
use D6N\LPM\Modules\DiagnosticLog\Models\LogIdentity;
use D6N\LPM\Modules\DiagnosticLog\Repositories\LogRepository;
use D6N\LPM\Modules\DiagnosticLog\Services\LogSettings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Database destination writing log records to `d6n_lpm_logs`.
 */
class DatabaseLogHandler implements ILoggerHandler {

	private LogSettings $settings;

	/**
	 * Constructor.
	 *
	 * @param LogRepository    $repository Log repository instance.
	 * @param LogSettings|null $settings   Settings reader, defaults to a fresh instance.
	 */
	public function __construct(
		private readonly LogRepository $repository,
		?LogSettings $settings = null
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
			$channel = LogChannelEnum::from_value( $context['channel'] ?? null );

			if ( ! $this->is_enabled_for( $channel ) ) {
				return;
			}

			$identity = LogIdentity::from_context( $context );
			$payload  = $this->strip_reserved_keys( $context );

			$record = array(
				'level'      => $level,
				'channel'    => $channel->value,
				'source'     => $source,
				'message'    => $message,
				'context'    => $payload,
				'user_id'    => $identity->user_id,
				'user_login' => $identity->user_login,
				'ip_address' => $identity->ip_address,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			);

			$this->repository->create_record( $record );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[LPM Log Error] Database log write failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Uninstall/clean storage.
	 *
	 * @return void
	 */
	public function uninstall(): void {}

	/**
	 * Check whether this destination accepts the given channel.
	 *
	 * @param LogChannelEnum $channel Channel of the record.
	 * @return bool True when the record must be written.
	 */
	private function is_enabled_for( LogChannelEnum $channel ): bool {
		return $channel->is_threshold_exempt() || $this->settings->is_database_enabled();
	}

	/**
	 * Remove the reserved keys from the free-form context payload.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, mixed> Context without reserved keys.
	 */
	private function strip_reserved_keys( array $context ): array {
		foreach ( array_merge( array( 'channel', 'source' ), LogIdentity::context_keys() ) as $key ) {
			unset( $context[ $key ] );
		}

		return $context;
	}
}
