<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Rounding strategy applied to exchange-rate converted prices.
 */
enum RoundingModeEnum: string {
	case None = 'none';
	case Up   = 'round_up';
	case Down = 'round_down';

	/**
	 * Resolve a stored option value into a valid mode, falling back to None.
	 *
	 * @param mixed $value Raw option value.
	 * @return self
	 */
	public static function from_option( mixed $value ): self {
		if ( $value instanceof self ) {
			return $value;
		}

		return is_string( $value ) ? ( self::tryFrom( $value ) ?? self::None ) : self::None;
	}

	/**
	 * Get the translated admin label for this mode.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return match ( $this->value ) {
			self::Up->value   => __( 'Always round up', 'localized-price-manager' ),
			self::Down->value => __( 'Always round down', 'localized-price-manager' ),
			default           => __( 'Standard rounding', 'localized-price-manager' ),
		};
	}
}
