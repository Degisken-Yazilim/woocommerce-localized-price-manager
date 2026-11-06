<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Enums;

use Stringable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
// phpcs:disable PHPCompatibility.FunctionDeclarations.NewParamTypeDeclarations.SelfOutsideClassScopeFound

/**
 * Diagnostic log channel identifiers.
 */
enum LogChannelEnum: string {
	case Cache   = 'cache';
	case Error   = 'error';
	case Service = 'service';
	case Audit   = 'audit';

	/**
	 * Get the translated label for the channel filter links above the log table.
	 *
	 * @return string Translated label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::Cache   => __( 'Cache', 'localized-price-manager' ),
			self::Error   => __( 'Error', 'localized-price-manager' ),
			self::Service => __( 'Service', 'localized-price-manager' ),
			self::Audit   => __( 'Audit', 'localized-price-manager' ),
		};
	}

	/**
	 * Check whether the channel ignores the configured minimum level.
	 *
	 * @return bool True when records on this channel are never suppressed.
	 */
	public function is_threshold_exempt(): bool {
		return self::Audit === $this;
	}

	/**
	 * Check whether the channel always records the full, unmasked client IP.
	 *
	 * @return bool True when the full IP address is an audit requirement.
	 */
	public function requires_full_ip(): bool {
		return self::Audit === $this;
	}

	/**
	 * Get the WooCommerce log source used for the file destination.
	 *
	 * @return string WooCommerce log source slug.
	 */
	public function wc_log_source(): string {
		return 'lpm-' . $this->value;
	}

	/**
	 * Resolve an arbitrary value into a channel, falling back when unknown.
	 *
	 * @param mixed $channel  Raw channel value.
	 * @param self  $fallback Channel returned when the value cannot be resolved.
	 * @return self Resolved channel.
	 */
	public static function from_value( mixed $channel, self $fallback = self::Error ): self {
		if ( $channel instanceof self ) {
			return $channel;
		}

		if ( is_string( $channel ) || $channel instanceof Stringable ) {
			return self::tryFrom( strtolower( trim( (string) $channel ) ) ) ?? $fallback;
		}

		return $fallback;
	}

	/**
	 * Get every WooCommerce log source owned by this plugin.
	 *
	 * @return array<string> List of WC log source slugs.
	 */
	public static function all_wc_log_sources(): array {
		return array_map(
			static function ( self $channel ): string {
				return $channel->wc_log_source();
			},
			self::cases()
		);
	}
}
