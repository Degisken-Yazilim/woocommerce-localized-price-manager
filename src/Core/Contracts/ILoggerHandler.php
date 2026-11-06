<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for logger handler implementations.
 */
interface ILoggerHandler {
	/**
	 * Write a log entry.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Log message.
	 * @param string               $source  Source component that produced the record.
	 * @param array<string, mixed> $context Additional context payload plus the reserved keys.
	 * @return void
	 */
	public function log( string $level, string $message, string $source, array $context ): void;

	/**
	 * Uninstall/clean storage.
	 *
	 * @return void
	 */
	public function uninstall(): void;
}
