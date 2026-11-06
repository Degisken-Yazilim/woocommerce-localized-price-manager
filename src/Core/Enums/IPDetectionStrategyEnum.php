<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

// phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
// phpcs:disable PHPCompatibility.FunctionDeclarations.NewParamTypeDeclarations.SelfOutsideClassScopeFound

/**
 * IP detection strategy mode identifiers.
 */
enum IPDetectionStrategyEnum: string {
	case Strict     = 'strict';
	case TrustedCdn = 'trusted_cdn';

	/**
	 * Get the strategy used when nothing is configured.
	 *
	 * @return self
	 */
	public static function fallback(): self {
		return self::Strict;
	}

	/**
	 * Resolve a strategy from a stored option value.
	 *
	 * @param mixed $value    Raw option value.
	 * @param self  $fallback Strategy to use when the value is unusable.
	 * @return self
	 */
	public static function from_option( mixed $value, self $fallback = self::Strict ): self {
		if ( $value instanceof self ) {
			return $value;
		}

		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return $fallback;
		}

		return self::tryFrom( (string) $value ) ?? $fallback;
	}

	/**
	 * Get the human readable label for the settings screen.
	 *
	 * @return string Translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Strict     => __( 'Strict — only the connecting IP address', 'localized-price-manager' ),
			self::TrustedCdn => __( 'Trusted CDN — also read the forwarded headers declared below', 'localized-price-manager' ),
		};
	}
}
