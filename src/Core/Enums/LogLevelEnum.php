<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

use Stringable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
// phpcs:disable PHPCompatibility.FunctionDeclarations.NewParamTypeDeclarations.SelfOutsideClassScopeFound

/**
 * Standard log level identifiers.
 */
enum LogLevelEnum: string {
	case Emergency = 'emergency';
	case Alert     = 'alert';
	case Critical  = 'critical';
	case Error     = 'error';
	case Warning   = 'warning';
	case Notice    = 'notice';
	case Info      = 'info';
	case Debug     = 'debug';

	/**
	 * Get the numeric severity weight of the level (PSR-3 ordering).
	 *
	 * @return int Severity weight; a higher number means a more severe event.
	 */
	public function severity(): int {
		return match ( $this ) {
			self::Debug     => 100,
			self::Info      => 200,
			self::Notice    => 250,
			self::Warning   => 300,
			self::Error     => 400,
			self::Critical  => 500,
			self::Alert     => 550,
			self::Emergency => 600,
		};
	}

	/**
	 * Get the translated label for a screen an operator chooses a level on.
	 *
	 * @return string Translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Emergency => __( 'Emergency', 'localized-price-manager' ),
			self::Alert     => __( 'Alert', 'localized-price-manager' ),
			self::Critical  => __( 'Critical', 'localized-price-manager' ),
			self::Error     => __( 'Error', 'localized-price-manager' ),
			self::Warning   => __( 'Warning', 'localized-price-manager' ),
			self::Notice    => __( 'Notice', 'localized-price-manager' ),
			self::Info      => __( 'Info', 'localized-price-manager' ),
			self::Debug     => __( 'Debug', 'localized-price-manager' ),
		};
	}

	/**
	 * Check whether this level is at least as severe as the given level.
	 *
	 * @param self $minimum Minimum level to compare against.
	 * @return bool True when this level passes the given threshold.
	 */
	public function is_at_least( self $minimum ): bool {
		return $this->severity() >= $minimum->severity();
	}

	/**
	 * Resolve an arbitrary value into a level, falling back when unknown.
	 *
	 * @param mixed $level    Raw level value.
	 * @param self  $fallback Level returned when the value cannot be resolved.
	 * @return self Resolved level.
	 */
	public static function from_value( mixed $level, self $fallback = self::Info ): self {
		if ( $level instanceof self ) {
			return $level;
		}

		if ( is_string( $level ) || $level instanceof Stringable ) {
			return self::tryFrom( strtolower( trim( (string) $level ) ) ) ?? $fallback;
		}

		return $fallback;
	}
}
