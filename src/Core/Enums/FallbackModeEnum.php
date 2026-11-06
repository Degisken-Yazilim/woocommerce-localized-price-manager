<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Behaviour applied when no explicit pricing rule matches the customer country.
 */
enum FallbackModeEnum: string {
	/** Display the raw store base price without conversion. */
	case Direct = 'direct';

	/** Convert the store base price into the configured global currency. */
	case Convert = 'convert';

	/** Hide the price, the product and block purchasing. */
	case Hide = 'hide';

	/**
	 * Resolve a stored option value into a valid mode, falling back to Direct.
	 *
	 * @param mixed $value Raw option value.
	 * @return self
	 */
	public static function from_option( mixed $value ): self {
		if ( $value instanceof self ) {
			return $value;
		}

		return is_string( $value ) ? ( self::tryFrom( $value ) ?? self::Direct ) : self::Direct;
	}

	/**
	 * Get the translated admin label for this mode.
	 *
	 * @return string
	 */
	public function label(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return match ( $this->value ) {
			self::Convert->value => __( 'Convert to global currency', 'localized-price-manager' ),
			self::Hide->value    => __( 'Hide price and product', 'localized-price-manager' ),
			default              => __( 'Show store base price', 'localized-price-manager' ),
		};
	}
}
