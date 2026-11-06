<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Services;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Modules\DiagnosticLog\Enums\LogChannelEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Logger decorator putting schema and activation records on the audit channel.
 */
class LifecycleAuditLogger implements ILogger {

	/**
	 * Constructor.
	 *
	 * @param ILogger $inner Logger the re-channelled records are forwarded to.
	 */
	public function __construct(
		private readonly ILogger $inner
	) {}

	/**
	 * Log a message at an arbitrary level, on the audit channel.
	 *
	 * @param mixed                $level   Log level string or LogLevelEnum instance.
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function log( mixed $level, mixed $message, array $context = array(), string $source = '' ): void {
		$context['channel'] = LogChannelEnum::Audit->value;

		$this->inner->log( $level, $message, $context, $source );
	}

	/**
	 * Log emergency level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
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
	 * @param string               $source  Source component that produced the record.
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
	 * @param string               $source  Source component that produced the record.
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
	 * @param string               $source  Source component that produced the record.
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
	 * @param string               $source  Source component that produced the record.
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
	 * @param string               $source  Source component that produced the record.
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
	 * @param string               $source  Source component that produced the record.
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
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function debug( mixed $message, array $context = array(), string $source = '' ): void {
		$this->log( LogLevelEnum::Debug, $message, $context, $source );
	}

	/**
	 * Log an administrative action on the audit channel.
	 *
	 * @param string               $message Audit event description.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @param mixed                $level   Optional severity of the audited event.
	 * @return void
	 */
	public function audit( string $message, array $context = array(), string $source = 'Audit', mixed $level = null ): void {
		$this->inner->audit( $message, $context, $source, $level );
	}

	/**
	 * Log a cache event on the cache channel.
	 *
	 * @param string               $message Cache event description.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @param mixed                $level   Optional severity of the cache event.
	 * @return void
	 */
	public function cache( string $message, array $context = array(), string $source = 'Cache', mixed $level = null ): void {
		$this->inner->cache( $message, $context, $source, $level );
	}

	/**
	 * Log an external service event on the service channel.
	 *
	 * @param string               $message Service event description.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @param mixed                $level   Optional severity of the service event.
	 * @return void
	 */
	public function service( string $message, array $context = array(), string $source = 'Service', mixed $level = null ): void {
		$this->inner->service( $message, $context, $source, $level );
	}
}
