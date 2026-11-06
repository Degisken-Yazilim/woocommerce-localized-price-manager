<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Services;

use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Core\Shared\Utils;
use D6N\LPM\Modules\DiagnosticLog\Enums\LogChannelEnum;
use D6N\LPM\Modules\DiagnosticLog\Models\LogIdentity;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the PII policy to a single log record.
 */
class LogIdentityResolver {

	/**
	 * Number of trailing IPv6 bits removed when masking (128 - 80 = 48 kept).
	 */
	private const IPV6_MASKED_BITS = 80;

	/**
	 * Settings reader answering whether the site runs in debug mode.
	 *
	 * @var LogSettings|null
	 */
	private ?LogSettings $settings = null;

	/**
	 * Constructor.
	 *
	 * @param LogSettings|null $settings Settings reader, defaults to a fresh instance.
	 */
	public function __construct( ?LogSettings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Resolve the identity recorded for a log entry.
	 *
	 * @param LogChannelEnum $channel Channel the record belongs to.
	 * @param LogLevelEnum   $level   Severity of the record.
	 * @return LogIdentity Redacted identity for this record.
	 */
	public function resolve( LogChannelEnum $channel, LogLevelEnum $level ): LogIdentity {
		$user_id    = null;
		$user_login = null;

		try {
			if ( is_user_logged_in() ) {
				$user = self::narrow_current_user( wp_get_current_user() );

				if ( null !== $user ) {
					$user_id    = ( isset( $user->ID ) && (int) $user->ID > 0 ) ? (int) $user->ID : null;
					$user_login = ( isset( $user->user_login ) && '' !== $user->user_login ) ? (string) $user->user_login : null;
				}
			}
		} catch ( Throwable $e ) {
			$user_id    = null;
			$user_login = null;
		}

		$ip_address = $this->read_client_ip();

		if ( ! $this->keeps_full_ip( $channel, $level ) ) {
			$ip_address = $this->mask_ip( $ip_address );
		}

		return new LogIdentity( $user_id, $user_login, $ip_address );
	}

	/**
	 * Check whether a record stores the client IP unmasked.
	 *
	 * @param LogChannelEnum $channel Channel the record belongs to.
	 * @param LogLevelEnum   $level   Severity of the record.
	 * @return bool True for audit records, records of warning severity and above, and every record in debug mode.
	 */
	private function keeps_full_ip( LogChannelEnum $channel, LogLevelEnum $level ): bool {
		return $channel->requires_full_ip()
			|| $level->is_at_least( LogLevelEnum::Warning )
			|| $this->get_settings()->is_debug_mode();
	}

	/**
	 * Get the settings reader, creating the default one on first use.
	 *
	 * @return LogSettings Settings reader.
	 */
	private function get_settings(): LogSettings {
		$this->settings ??= new LogSettings();

		return $this->settings;
	}

	/**
	 * Narrow a raw `wp_get_current_user()` value to an object, or to null.
	 *
	 * @param mixed $user Raw current user value.
	 * @return object|null The value when it is an object, null otherwise.
	 */
	private static function narrow_current_user( mixed $user ): ?object {
		return is_object( $user ) ? $user : null;
	}

	/**
	 * Mask an IP address so it can no longer identify a single host.
	 *
	 * @param string|null $ip_address Raw IP address or null.
	 * @return string|null Masked IP address, or null when there is nothing to mask.
	 */
	public function mask_ip( ?string $ip_address ): ?string {
		if ( null === $ip_address || '' === $ip_address ) {
			return null;
		}

		if ( filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$octets    = explode( '.', $ip_address );
			$octets[3] = '0';

			return implode( '.', $octets );
		}

		if ( filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $this->mask_ipv6( $ip_address );
		}

		return null;
	}

	/**
	 * Zero the trailing bits of an IPv6 address.
	 *
	 * @param string $ip_address Validated IPv6 address.
	 * @return string|null Masked address, or null when it cannot be rendered back.
	 */
	private function mask_ipv6( string $ip_address ): ?string {
		$packed     = (string) inet_pton( $ip_address );
		$kept_bytes = intdiv( 128 - self::IPV6_MASKED_BITS, 8 );
		$masked     = substr( $packed, 0, $kept_bytes ) . str_repeat( "\0", 16 - $kept_bytes );
		$readable   = inet_ntop( $masked );

		return false === $readable ? null : $readable;
	}

	/**
	 * Read the client IP address, degrading to null when unavailable.
	 *
	 * @return string|null Client IP address or null.
	 */
	protected function read_client_ip(): ?string {
		try {
			return Utils::get_client_ip();
		} catch ( Throwable $e ) {
			return null;
		}
	}
}
