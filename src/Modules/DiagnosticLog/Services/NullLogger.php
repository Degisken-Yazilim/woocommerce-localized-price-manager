<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Services;

use D6N\LPM\Core\Contracts\ILogger;

defined( 'ABSPATH' ) || exit;

/**
 * Logger that discards every record.
 */
class NullLogger implements ILogger {

	/**
	 * Discard a log record at an arbitrary level.
	 *
	 * @param mixed                $level   Log level string or LogLevelEnum instance.
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function log( mixed $level, mixed $message, array $context = array(), string $source = '' ): void {}

	/**
	 * Discard an emergency record.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function emergency( mixed $message, array $context = array(), string $source = '' ): void {}

	/**
	 * Discard an alert record.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function alert( mixed $message, array $context = array(), string $source = '' ): void {}

	/**
	 * Discard a critical record.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function critical( mixed $message, array $context = array(), string $source = '' ): void {}

	/**
	 * Discard an error record.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function error( mixed $message, array $context = array(), string $source = '' ): void {}

	/**
	 * Discard a warning record.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function warning( mixed $message, array $context = array(), string $source = '' ): void {}

	/**
	 * Discard a notice record.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function notice( mixed $message, array $context = array(), string $source = '' ): void {}

	/**
	 * Discard an info record.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function info( mixed $message, array $context = array(), string $source = '' ): void {}

	/**
	 * Discard a debug record.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function debug( mixed $message, array $context = array(), string $source = '' ): void {}

	/**
	 * Discard an audit record.
	 *
	 * @param string               $message Audit event description.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @param mixed                $level   Optional severity of the audited event.
	 * @return void
	 */
	public function audit( string $message, array $context = array(), string $source = 'Audit', mixed $level = null ): void {}

	/**
	 * Discard a cache record.
	 *
	 * @param string               $message Cache event description.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @param mixed                $level   Optional severity of the cache event.
	 * @return void
	 */
	public function cache( string $message, array $context = array(), string $source = 'Cache', mixed $level = null ): void {}

	/**
	 * Discard a service record.
	 *
	 * @param string               $message Service event description.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @param mixed                $level   Optional severity of the service event.
	 * @return void
	 */
	public function service( string $message, array $context = array(), string $source = 'Service', mixed $level = null ): void {}
}
