<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Shared;

use D6N\LPM\Core\Enums\HookEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the request-context questions every localization guard in this plugin asks.
 */
class RequestContext {

	/**
	 * Shape an AJAX action name has to have before it is used to build a hook name.
	 */
	private const ACTION_PATTERN = '/^[A-Za-z0-9_\-]{1,100}$/';

	/**
	 * REST route prefix of the Store API, the one namespace served to a visitor.
	 */
	private const STORE_API_ROUTE_PREFIX = 'wc/store/';

	/**
	 * Verdict of the foreign REST check once WordPress has parsed the request, `null` until then.
	 *
	 * @var bool|null
	 */
	private static ?bool $foreign_rest = null;

	/**
	 * Whether this request is being served on behalf of wp-admin.
	 *
	 * @return bool True when nothing in this request should be localized for a visitor.
	 */
	public static function is_wp_admin(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		if ( ! wp_doing_ajax() ) {
			return true;
		}

		return ! self::is_storefront_ajax();
	}

	/**
	 * Whether this request is a REST request that is not the Store API.
	 *
	 * @return bool True when the request is REST but not Store API.
	 */
	public static function is_foreign_rest_request(): bool {
		if ( null !== self::$foreign_rest ) {
			return self::$foreign_rest;
		}

		$route   = self::get_rest_route();
		$foreign = '' !== $route && 0 !== strncasecmp( $route, self::STORE_API_ROUTE_PREFIX, strlen( self::STORE_API_ROUTE_PREFIX ) );

		if ( did_action( 'parse_request' ) > 0 ) {
			self::$foreign_rest = $foreign;
		}

		return $foreign;
	}

	/**
	 * Get the REST route this request addresses, if it addresses one at all.
	 *
	 * @return string Route without its leading slash, or an empty string outside REST.
	 */
	public static function get_rest_route(): string {
		if ( did_action( 'parse_request' ) > 0 ) {
			return self::get_parsed_rest_route();
		}

		$route = self::read_request_value( $_GET, 'rest_route' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request routing check, never a write.

		if ( '' !== $route ) {
			return ltrim( $route, '/' );
		}

		$uri      = self::read_request_value( $_SERVER, 'REQUEST_URI' );
		$prefix   = '/' . trim( (string) rest_get_url_prefix(), '/' ) . '/';
		$position = strpos( $uri, $prefix );

		return false === $position ? '' : substr( $uri, $position + strlen( $prefix ) );
	}

	/**
	 * Get the REST route WordPress itself resolved for this request.
	 *
	 * @return string Route without its leading slash, or an empty string outside REST.
	 */
	private static function get_parsed_rest_route(): string {
		$wp    = $GLOBALS['wp'] ?? null;
		$route = $wp instanceof \WP ? ( $wp->query_vars['rest_route'] ?? '' ) : '';

		return is_string( $route ) ? ltrim( $route, '/' ) : '';
	}

	/**
	 * Read one request superglobal member as a sanitized string.
	 *
	 * @param array<string, mixed> $source Superglobal to read.
	 * @param string               $key    Member name.
	 * @return string Sanitized value, or an empty string when the member is absent or not a string.
	 */
	private static function read_request_value( array $source, string $key ): string {
		$value = $source[ $key ] ?? '';

		return is_string( $value ) ? sanitize_text_field( wp_unslash( $value ) ) : '';
	}

	/**
	 * Whether the AJAX action of this request is one an anonymous shopper may call.
	 *
	 * @return bool True when the action carries a `wp_ajax_nopriv_` listener.
	 */
	private static function is_storefront_ajax(): bool {
		$action = self::requested_action();

		if ( '' === $action ) {
			return false;
		}

		$is_storefront = false !== has_action( 'wp_ajax_nopriv_' . $action );

		/**
		 * Filters whether an `admin-ajax.php` action is a storefront request.
		 *
		 * @param bool   $is_storefront Whether the action serves the storefront.
		 * @param string $action        The `action` request parameter.
		 */
		return (bool) apply_filters(
			Core::build_hook( HookEnum::RequestIsStorefrontAjax->value ),
			$is_storefront,
			$action
		);
	}

	/**
	 * Read the `action` parameter `admin-ajax.php` dispatches on.
	 *
	 * @return string Validated action name, or an empty string when there is none.
	 */
	private static function requested_action(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check; the value only ever selects a code path.
		if ( ! isset( $_REQUEST['action'] ) || ! is_string( $_REQUEST['action'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check; the value only ever selects a code path.
		$action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

		return 1 === preg_match( self::ACTION_PATTERN, $action ) ? $action : '';
	}
}
