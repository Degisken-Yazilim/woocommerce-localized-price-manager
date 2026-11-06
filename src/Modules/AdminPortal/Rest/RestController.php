<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Rest;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\RequestContext;
use WP_Error;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for every admin portal REST controller.
 */
abstract class RestController implements IHookable {

	/**
	 * REST namespace shared by every route of this plugin.
	 *
	 * @var string
	 */
	public const REST_NAMESPACE = 'd6n-lpm/v1';

	/**
	 * Register the controller's routes.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes_for_request' ) );
	}

	/**
	 * Register the routes unless the request is a REST request for another namespace.
	 *
	 * @return void
	 */
	public function register_routes_for_request(): void {
		$route = RequestContext::get_rest_route();

		if ( '' !== $route && ! $this->addresses_plugin_namespace( $route ) ) {
			return;
		}

		$this->register_routes();
	}

	/**
	 * Permission callback shared by every route.
	 *
	 * @return bool|WP_Error True when allowed, WP_Error otherwise.
	 */
	public function check_permission(): bool|WP_Error {
		if ( current_user_can( Core::get_admin_capability() ) ) {
			return true;
		}

		return new WP_Error(
			'lpm_rest_forbidden',
			__( 'You are not allowed to manage Localized Price Manager settings.', 'localized-price-manager' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * Whether a REST route lies in this plugin's namespace.
	 *
	 * @param string $route Route without its leading slash.
	 * @return bool
	 */
	private function addresses_plugin_namespace( string $route ): bool {
		$length = strlen( self::REST_NAMESPACE );

		return 0 === strncasecmp( $route, self::REST_NAMESPACE, $length )
			&& ( strlen( $route ) === $length || '/' === $route[ $length ] );
	}

	/**
	 * Build a successful JSON response.
	 *
	 * @param array<string, mixed> $data   Payload.
	 * @param int                  $status HTTP status code.
	 * @return WP_REST_Response Response.
	 */
	protected function respond( array $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Convert a repository WP_Error into a REST-shaped WP_Error.
	 *
	 * @param WP_Error $error  Repository error.
	 * @param int      $status HTTP status code to attach.
	 * @return WP_Error Error carrying a status.
	 */
	protected function as_rest_error( WP_Error $error, int $status = 500 ): WP_Error {
		$code = (string) $error->get_error_code();

		$rest_error = new WP_Error();
		$rest_error->add(
			'' !== $code ? $code : 'lpm_rest_error',
			$error->get_error_message(),
			array( 'status' => $status )
		);

		return $rest_error;
	}
}
