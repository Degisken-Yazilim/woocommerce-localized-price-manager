<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Services;

use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Shared\Core;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, memoised access to the diagnostic log settings.
 */
class LogSettings {

	public const DEFAULT_MIN_LEVEL      = LogLevelEnum::Warning;
	public const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Memoised option values keyed by option name.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = array();

	/**
	 * Get the configured minimum log level.
	 *
	 * @return LogLevelEnum Configured minimum level.
	 */
	public function get_min_level(): LogLevelEnum {
		return LogLevelEnum::from_value(
			$this->read( OptionsEnum::LogLevel->value, self::DEFAULT_MIN_LEVEL->value ),
			self::DEFAULT_MIN_LEVEL
		);
	}

	/**
	 * Check whether the database destination is enabled.
	 *
	 * @return bool True when log records may be written to the database table.
	 */
	public function is_database_enabled(): bool {
		return $this->to_bool( $this->read( OptionsEnum::DbLogEnabled->value, 'yes' ) );
	}

	/**
	 * Check whether the file destination is enabled.
	 *
	 * @return bool True when log records may be handed to WC_Logger.
	 */
	public function is_file_enabled(): bool {
		return $this->to_bool( $this->read( OptionsEnum::FileLogEnabled->value, 'no' ) );
	}

	/**
	 * Get the log retention window in days.
	 *
	 * @return int Retention window, always at least one day.
	 */
	public function get_retention_days(): int {
		$days = (int) $this->read( OptionsEnum::LogRetentionDays->value, self::DEFAULT_RETENTION_DAYS );

		return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
	}

	/**
	 * Check whether the site runs with WP_DEBUG enabled.
	 *
	 * @return bool True when WP_DEBUG is defined and truthy.
	 */
	public function is_debug_mode(): bool {
		return defined( 'WP_DEBUG' ) && (bool) constant( 'WP_DEBUG' );
	}

	/**
	 * Read a plugin option, degrading to the default outside WordPress.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $fallback Value used when the option is unavailable.
	 * @return mixed Option value or fallback.
	 */
	private function read( string $name, mixed $fallback ): mixed {
		if ( array_key_exists( $name, $this->cache ) ) {
			return $this->cache[ $name ];
		}

		try {
			$value = Core::get_option( $name, $fallback );
		} catch ( Throwable $e ) {
			$value = $fallback;
		}

		$this->cache[ $name ] = $value;

		return $value;
	}

	/**
	 * Normalise the several boolean representations WordPress options use.
	 *
	 * @param mixed $value Raw option value.
	 * @return bool Normalised boolean.
	 */
	private function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 0 !== $value;
		}

		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', 'no', 'off', 'false', '0' ), true );
		}

		return (bool) $value;
	}
}
