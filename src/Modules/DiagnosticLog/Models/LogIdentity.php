<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Models;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable identity attached to a single log record.
 */
class LogIdentity {

	/**
	 * Constructor.
	 *
	 * @param int|null    $user_id    Acting user ID, or null for guests and system events.
	 * @param string|null $user_login Acting user login, or null for guests and system events.
	 * @param string|null $ip_address Client IP, full or masked, or null when the policy drops it.
	 */
	public function __construct(
		public readonly ?int $user_id = null,
		public readonly ?string $user_login = null,
		public readonly ?string $ip_address = null
	) {}

	/**
	 * Get the identity as the reserved context keys used by handlers.
	 *
	 * @return array<string, mixed> Reserved identity keys.
	 */
	public function to_context(): array {
		return array(
			'user_id'    => $this->user_id,
			'user_login' => $this->user_login,
			'ip_address' => $this->ip_address,
		);
	}

	/**
	 * Build an identity from the reserved context keys of a log record.
	 *
	 * @param array<string, mixed> $context Log context carrying the reserved keys.
	 * @return self Identity instance.
	 */
	public static function from_context( array $context ): self {
		$user_id    = isset( $context['user_id'] ) ? (int) $context['user_id'] : null;
		$user_login = isset( $context['user_login'] ) ? (string) $context['user_login'] : null;
		$ip_address = isset( $context['ip_address'] ) ? (string) $context['ip_address'] : null;

		return new self(
			( null !== $user_id && $user_id > 0 ) ? $user_id : null,
			( null !== $user_login && '' !== $user_login ) ? $user_login : null,
			( null !== $ip_address && '' !== $ip_address ) ? $ip_address : null
		);
	}

	/**
	 * Get the reserved context keys owned by the identity.
	 *
	 * @return array<string> Reserved key names.
	 */
	public static function context_keys(): array {
		return array( 'user_id', 'user_login', 'ip_address' );
	}
}
