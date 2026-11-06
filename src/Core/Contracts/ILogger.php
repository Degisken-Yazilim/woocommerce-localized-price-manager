<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for logger implementations.
 */
interface ILogger {
	/**
	 * Log a message at an arbitrary level.
	 *
	 * @param mixed                $level   Log level string or LogLevelEnum instance.
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function log( mixed $level, mixed $message, array $context = array(), string $source = '' ): void;

	/**
	 * Log emergency level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function emergency( mixed $message, array $context = array(), string $source = '' ): void;

	/**
	 * Log alert level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function alert( mixed $message, array $context = array(), string $source = '' ): void;

	/**
	 * Log critical level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function critical( mixed $message, array $context = array(), string $source = '' ): void;

	/**
	 * Log error level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function error( mixed $message, array $context = array(), string $source = '' ): void;

	/**
	 * Log warning level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function warning( mixed $message, array $context = array(), string $source = '' ): void;

	/**
	 * Log notice level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function notice( mixed $message, array $context = array(), string $source = '' ): void;

	/**
	 * Log info level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function info( mixed $message, array $context = array(), string $source = '' ): void;

	/**
	 * Log debug level message.
	 *
	 * @param mixed                $message Log message or exception.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @return void
	 */
	public function debug( mixed $message, array $context = array(), string $source = '' ): void;

	/**
	 * Log an administrative action on the audit channel.
	 *
	 * @param string               $message Audit event description.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @param mixed                $level   Optional severity of the audited event.
	 * @return void
	 */
	public function audit( string $message, array $context = array(), string $source = 'Audit', mixed $level = null ): void;

	/**
	 * Log a cache event on the cache channel.
	 *
	 * @param string               $message Cache event description.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @param mixed                $level   Optional severity of the cache event.
	 * @return void
	 */
	public function cache( string $message, array $context = array(), string $source = 'Cache', mixed $level = null ): void;

	/**
	 * Log an external service event on the service channel.
	 *
	 * @param string               $message Service event description.
	 * @param array<string, mixed> $context Additional context data.
	 * @param string               $source  Source component that produced the record.
	 * @param mixed                $level   Optional severity of the service event.
	 * @return void
	 */
	public function service( string $message, array $context = array(), string $source = 'Service', mixed $level = null ): void;
}
