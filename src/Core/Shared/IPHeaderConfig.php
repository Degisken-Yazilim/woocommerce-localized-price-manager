<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Shared;

use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\IPDetectionStrategyEnum;
use D6N\LPM\Core\Enums\OptionsEnum;

defined( 'ABSPATH' ) || exit;

/**
 * IP header metadata and the forwarded-header trust model.
 */
class IPHeaderConfig {

	/**
	 * Server key holding the TCP peer address.
	 *
	 * @var string
	 */
	public const REMOTE_ADDR = 'REMOTE_ADDR';

	/**
	 * Resolve the detection strategy in force for this request.
	 *
	 * @return IPDetectionStrategyEnum Effective strategy.
	 */
	public static function effective_strategy(): IPDetectionStrategyEnum {
		/**
		 * Filters the IP detection strategy for this request.
		 *
		 * @param IPDetectionStrategyEnum $strategy Default strategy.
		 */
		$strategy = apply_filters(
			Core::build_hook( HookEnum::IPDetectionStrategy->value ),
			IPDetectionStrategyEnum::fallback()
		);

		return IPDetectionStrategyEnum::from_option( $strategy );
	}

	/**
	 * Get the ordered server keys to inspect for a strategy.
	 *
	 * @param IPDetectionStrategyEnum $strategy Strategy enum.
	 * @return array<int, string> Ordered server keys.
	 */
	public static function get_headers_for_strategy( IPDetectionStrategyEnum $strategy ): array {
		if ( IPDetectionStrategyEnum::TrustedCdn !== $strategy ) {
			return array( self::REMOTE_ADDR );
		}

		return array( ...self::get_trusted_headers(), self::REMOTE_ADDR );
	}

	/**
	 * Check if a header may only be used after a trust decision.
	 *
	 * @param string $header Server key.
	 * @return bool True when the header must pass the trust check.
	 */
	public static function needs_verification( string $header ): bool {
		return self::REMOTE_ADDR !== strtoupper( $header );
	}

	/**
	 * Get the forwarded client-IP headers the operator declared.
	 *
	 * @return array<int, string> Normalised server keys, in the operator's order.
	 */
	public static function get_trusted_headers(): array {
		return self::read_header_list( OptionsEnum::TrustedHeaders->value );
	}

	/**
	 * Get the pre-resolved country headers the operator declared.
	 *
	 * @return array<int, string> Normalised server keys, in the operator's order.
	 */
	public static function get_declared_country_headers(): array {
		return self::read_header_list( OptionsEnum::TrustedCountryHeaders->value );
	}

	/**
	 * Check whether the current request arrived through a trusted proxy.
	 *
	 * @param string                       $remote_addr TCP peer address of the current request.
	 * @param IPDetectionStrategyEnum|null $strategy    Strategy in force; resolved when omitted.
	 * @return bool True when the peer may speak for the site's ingress.
	 */
	public static function is_trusted_proxy( string $remote_addr, ?IPDetectionStrategyEnum $strategy = null ): bool {
		$strategy = $strategy ?? self::effective_strategy();

		if ( IPDetectionStrategyEnum::TrustedCdn !== $strategy ) {
			return false;
		}

		if ( ! Utils::is_valid_ip( $remote_addr ) ) {
			return false;
		}

		$ranges = self::get_trusted_proxy_ranges_text();

		if ( '' === trim( $ranges ) ) {
			return false;
		}

		return IpRangeMatcher::matches( $remote_addr, $ranges );
	}

	/**
	 * Get the approved proxy range text as the operator stored it.
	 *
	 * @return string Newline separated entries, empty when nothing is approved.
	 */
	public static function get_trusted_proxy_ranges_text(): string {
		$stored = Core::get_option( OptionsEnum::TrustedProxyRanges->value, '' );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Check whether a forwarded header may be honoured for this request.
	 *
	 * @param string                       $header      Server key.
	 * @param string                       $remote_addr TCP peer address of the current request.
	 * @param IPDetectionStrategyEnum|null $strategy    Strategy in force; resolved when omitted.
	 * @return bool True when the header is trustworthy.
	 */
	public static function is_header_trusted( string $header, string $remote_addr, ?IPDetectionStrategyEnum $strategy = null ): bool {
		if ( ! self::needs_verification( $header ) ) {
			return true;
		}

		if ( ! in_array( self::normalize_header( $header ), self::get_trusted_headers(), true ) ) {
			return false;
		}

		return self::is_trusted_proxy( $remote_addr, $strategy );
	}

	/**
	 * Get the country headers that may be read on this request.
	 *
	 * @param IPDetectionStrategyEnum|null $strategy Strategy in force; resolved when omitted.
	 * @return array<int, string> Server keys holding a pre-resolved country code.
	 */
	public static function get_trusted_country_headers( ?IPDetectionStrategyEnum $strategy = null ): array {
		if ( ! self::is_trusted_proxy( self::read_remote_addr(), $strategy ) ) {
			return array();
		}

		return self::get_declared_country_headers();
	}

	/**
	 * Read and normalise one newline separated header-name setting.
	 *
	 * @param string $option Option key holding the list.
	 * @return array<int, string> Normalised server keys, deduplicated, in order.
	 */
	private static function read_header_list( string $option ): array {
		$stored = Core::get_option( $option, '' );

		if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
			return array();
		}

		$lines = preg_split( '/\R/', $stored );

		if ( false === $lines ) {
			return array();
		}

		$headers = array();

		foreach ( $lines as $line ) {
			$header = self::normalize_header( $line );

			if ( '' !== $header && ! in_array( $header, $headers, true ) ) {
				$headers[] = $header;
			}
		}

		return $headers;
	}

	/**
	 * Normalise a header name to the `$_SERVER` spelling.
	 *
	 * @param string $header Raw header name.
	 * @return string Server key, or an empty string when the name is unusable.
	 */
	public static function normalize_header( string $header ): string {
		$name = strtoupper( trim( $header ) );
		$name = (string) preg_replace( '/[^A-Z0-9]+/', '_', $name );
		$name = trim( $name, '_' );

		if ( '' === $name || self::REMOTE_ADDR === $name ) {
			return $name;
		}

		return str_starts_with( $name, 'HTTP_' ) ? $name : 'HTTP_' . $name;
	}

	/**
	 * Read the TCP peer address of the current request.
	 *
	 * @return string Peer address, empty when absent.
	 */
	private static function read_remote_addr(): string {
		if ( empty( $_SERVER[ self::REMOTE_ADDR ] ) || ! is_string( $_SERVER[ self::REMOTE_ADDR ] ) ) {
			return '';
		}

		return trim( (string) sanitize_text_field( wp_unslash( $_SERVER[ self::REMOTE_ADDR ] ) ) );
	}
}
