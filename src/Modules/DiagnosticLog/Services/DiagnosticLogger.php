<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Services;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Contracts\ILoggerHandler;
use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Modules\DiagnosticLog\Enums\LogChannelEnum;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Main diagnostic logger service dispatching entries across active handlers.
 */
class DiagnosticLogger implements ILogger {

	/**
	 * Registered log handlers.
	 *
	 * @var array<ILoggerHandler>
	 */
	private array $handlers = array();

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private bool $is_dispatching = false;

	/**
	 * Resolved minimum level, memoised after the first threshold check.
	 *
	 * @var LogLevelEnum|null
	 */
	private ?LogLevelEnum $resolved_min_level = null;

	private LogSettings $settings;
	private LogIdentityResolver $identity_resolver;

	/**
	 * Constructor.
	 *
	 * @param array<ILoggerHandler>    $handlers          Initial log handlers.
	 * @param string|null              $min_level         Explicit minimum level; null reads OptionsEnum::LogLevel.
	 * @param LogSettings|null         $settings          Settings reader, defaults to a fresh instance.
	 * @param LogIdentityResolver|null $identity_resolver PII policy resolver, defaults to a fresh instance.
	 */
	public function __construct(
		array $handlers = array(),
		private readonly ?string $min_level = null,
		?LogSettings $settings = null,
		?LogIdentityResolver $identity_resolver = null
	) {
		$this->handlers          = $handlers;
		$this->settings          = $settings ?? new LogSettings();
		$this->identity_resolver = $identity_resolver ?? new LogIdentityResolver();
	}

	/**
	 * Get the effective minimum level applied to non-exempt channels.
	 *
	 * @return LogLevelEnum Configured minimum level.
	 */
	public function get_min_level(): LogLevelEnum {
		if ( null === $this->resolved_min_level ) {
			$this->resolved_min_level = ( null === $this->min_level )
				? $this->settings->get_min_level()
				: LogLevelEnum::from_value( $this->min_level, LogSettings::DEFAULT_MIN_LEVEL );
		}

		return $this->resolved_min_level;
	}

	/**
	 * Log an administrative action on the audit channel.
	 *
	 * @param string               $message Audit event description.
	 * @param array<string, mixed> $context Additional attributes.
	 * @param string               $source  Source component.
	 * @param mixed                $level   Optional severity, defaults to info.
	 * @return void
	 */
	public function audit( string $message, array $context = array(), string $source = 'Audit', mixed $level = null ): void {
		$this->log_to_channel( LogChannelEnum::Audit, $level ?? LogLevelEnum::Info, $message, $context, $source );
	}

	/**
	 * Log a cache event on the cache channel.
	 *
	 * @param string               $message Cache event message.
	 * @param array<string, mixed> $context Additional attributes.
	 * @param string               $source  Source component.
	 * @param mixed                $level   Optional severity, defaults to info.
	 * @return void
	 */
	public function cache( string $message, array $context = array(), string $source = 'Cache', mixed $level = null ): void {
		$this->log_to_channel( LogChannelEnum::Cache, $level ?? LogLevelEnum::Info, $message, $context, $source );
	}

	/**
	 * Log an external API service event on the service channel.
	 *
	 * @param string               $message Service event message.
	 * @param array<string, mixed> $context Additional attributes.
	 * @param string               $source  Source component.
	 * @param mixed                $level   Optional severity, defaults to info.
	 * @return void
	 */
	public function service( string $message, array $context = array(), string $source = 'Service', mixed $level = null ): void {
		$this->log_to_channel( LogChannelEnum::Service, $level ?? LogLevelEnum::Info, $message, $context, $source );
	}

	/**
	 * Log a record on an explicit channel with an explicit severity.
	 *
	 * @param LogChannelEnum       $channel Channel selecting destination and policy.
	 * @param mixed                $level   Severity string or LogLevelEnum.
	 * @param mixed                $message Message string, Stringable or Throwable.
	 * @param array<string, mixed> $context Additional attributes.
	 * @param string               $source  Source component.
	 * @return void
	 */
	public function log_to_channel( LogChannelEnum $channel, mixed $level, mixed $message, array $context = array(), string $source = '' ): void {
		$context['channel'] = $channel->value;
		$this->log( $level, $message, $context, $source );
	}

	/**
	 * Log entry with a specific level.
	 *
	 * @param mixed                $level   Log level string or LogLevelEnum.
	 * @param mixed                $message Message string, Stringable or Throwable.
	 * @param array<string, mixed> $context Additional parameters.
	 * @param string               $source  Source component.
	 * @return void
	 */
	public function log( mixed $level, mixed $message, array $context = array(), string $source = '' ): void {
		if ( $this->is_dispatching ) {
			return;
		}

		$level_enum   = LogLevelEnum::from_value( $level );
		$channel_enum = LogChannelEnum::from_value( $context['channel'] ?? null );

		if ( ! $this->should_log( $level_enum, $channel_enum ) ) {
			return;
		}

		if ( '' === $source ) {
			$source = isset( $context['source'] ) ? (string) $context['source'] : 'System';
		}
		unset( $context['source'] );

		if ( $message instanceof Throwable ) {
			$context['exception_class'] = get_class( $message );
			$context['file']            = $message->getFile();
			$context['line']            = $message->getLine();
			$context['trace']           = $message->getTraceAsString();
			$message_text               = $message->getMessage();
		} else {
			$message_text = (string) $message;
		}

		$identity           = $this->identity_resolver->resolve( $channel_enum, $level_enum );
		$context            = array_merge( $context, $identity->to_context() );
		$context['channel'] = $channel_enum->value;

		$this->dispatch( $level_enum, $message_text, $source, $context );
	}

	/**
	 * Log emergency level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component.
	 * @return void
	 */
	public function emergency( mixed $message, array $context = array(), string $source = '' ): void {
		$this->log( LogLevelEnum::Emergency, $message, $context, $source );
	}

	/**
	 * Log alert level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component.
	 * @return void
	 */
	public function alert( mixed $message, array $context = array(), string $source = '' ): void {
		$this->log( LogLevelEnum::Alert, $message, $context, $source );
	}

	/**
	 * Log critical level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component.
	 * @return void
	 */
	public function critical( mixed $message, array $context = array(), string $source = '' ): void {
		$this->log( LogLevelEnum::Critical, $message, $context, $source );
	}

	/**
	 * Log error level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component.
	 * @return void
	 */
	public function error( mixed $message, array $context = array(), string $source = '' ): void {
		$this->log( LogLevelEnum::Error, $message, $context, $source );
	}

	/**
	 * Log warning level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component.
	 * @return void
	 */
	public function warning( mixed $message, array $context = array(), string $source = '' ): void {
		$this->log( LogLevelEnum::Warning, $message, $context, $source );
	}

	/**
	 * Log notice level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component.
	 * @return void
	 */
	public function notice( mixed $message, array $context = array(), string $source = '' ): void {
		$this->log( LogLevelEnum::Notice, $message, $context, $source );
	}

	/**
	 * Log info level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component.
	 * @return void
	 */
	public function info( mixed $message, array $context = array(), string $source = '' ): void {
		$this->log( LogLevelEnum::Info, $message, $context, $source );
	}

	/**
	 * Log debug level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component.
	 * @return void
	 */
	public function debug( mixed $message, array $context = array(), string $source = '' ): void {
		$this->log( LogLevelEnum::Debug, $message, $context, $source );
	}

	/**
	 * Hand a finished record to every registered handler.
	 *
	 * @param LogLevelEnum         $level   Record severity.
	 * @param string               $message Record message.
	 * @param string               $source  Source component.
	 * @param array<string, mixed> $context Record context including the reserved keys.
	 * @return void
	 */
	private function dispatch( LogLevelEnum $level, string $message, string $source, array $context ): void {
		$this->is_dispatching = true;

		try {
			foreach ( $this->handlers as $handler ) {
				try {
					$handler->log( $level->value, $message, $source, $context );
				} catch ( Throwable $e ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( sprintf( '[LPM Log Handler Error] %s', $e->getMessage() ) );
				}
			}
		} finally {
			$this->is_dispatching = false;
		}
	}

	/**
	 * Check whether a record passes the configured threshold.
	 *
	 * @param LogLevelEnum   $level   Record severity.
	 * @param LogChannelEnum $channel Record channel.
	 * @return bool True when the record must be recorded.
	 */
	private function should_log( LogLevelEnum $level, LogChannelEnum $channel ): bool {
		if ( $channel->is_threshold_exempt() ) {
			return true;
		}

		return $level->is_at_least( $this->get_min_level() );
	}
}
