<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Support;

use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic identifier separating WordPress installations that share one cache server.
 */
final class SiteKey {

	/**
	 * Number of hash characters kept in the key.
	 */
	private const LENGTH = 12;

	/**
	 * Build the key of the running installation.
	 *
	 * @return string Short lower-case hexadecimal key.
	 */
	public static function current(): string {
		global $wpdb;

		return self::from(
			defined( 'WP_CACHE_KEY_SALT' ) && is_string( WP_CACHE_KEY_SALT ) ? WP_CACHE_KEY_SALT : null,
			defined( 'DB_HOST' ) && is_string( DB_HOST ) ? DB_HOST : '',
			defined( 'DB_NAME' ) && is_string( DB_NAME ) ? DB_NAME : '',
			$wpdb instanceof wpdb ? (string) $wpdb->prefix : ''
		);
	}

	/**
	 * Build the key from an explicit installation identity.
	 *
	 * @param string|null $salt         WP_CACHE_KEY_SALT, or null when it is not defined.
	 * @param string      $db_host      Database host.
	 * @param string      $db_name      Database name.
	 * @param string      $table_prefix Table prefix of the current site.
	 * @return string Short lower-case hexadecimal key.
	 */
	public static function from( ?string $salt, string $db_host, string $db_name, string $table_prefix ): string {
		$identity = ( null !== $salt && '' !== $salt )
			? 'salt|' . $salt
			: 'db|' . $db_host . '|' . $db_name . '|' . $table_prefix;

		return substr( hash( 'sha256', $identity ), 0, self::LENGTH );
	}
}
