<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Shared;

use D6N\LPM\Core\Contracts\IHttpResponse;
use WP_Error;
use WP_HTTP_Requests_Response;
use WP_Http_Cookie;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP Response wrapper implementation.
 *
 * @phpstan-type WpHttpResponse array{
 *     headers: CaseInsensitiveDictionary,
 *     body: string,
 *     response: array{code: int, message: string},
 *     cookies: array<int, WP_Http_Cookie>,
 *     filename: string|null,
 *     http_response: WP_HTTP_Requests_Response
 * }
 */
class HttpResponse implements IHttpResponse {

	/**
	 * Raw WordPress HTTP response array or WP_Error.
	 *
	 * @var WpHttpResponse|WP_Error
	 */
	private array|WP_Error $response;

	/**
	 * Constructor.
	 *
	 * @param WpHttpResponse|WP_Error $response Raw response from wp_remote_get/post.
	 */
	public function __construct( array|WP_Error $response ) {
		$this->response = $response;
	}

	/**
	 * Get HTTP response status code.
	 *
	 * @return int
	 */
	public function get_status_code(): int {
		if ( is_wp_error( $this->response ) ) {
			return 0;
		}

		return (int) wp_remote_retrieve_response_code( $this->response );
	}

	/**
	 * Get raw response body string.
	 *
	 * @return string
	 */
	public function get_body(): string {
		if ( is_wp_error( $this->response ) ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $this->response );
	}

	/**
	 * Get decoded JSON response body.
	 *
	 * @param bool $assoc Return associative array if true.
	 * @return mixed
	 */
	public function get_json( bool $assoc = true ): mixed {
		$body = $this->get_body();

		if ( empty( $body ) ) {
			return null;
		}

		return json_decode( $body, $assoc );
	}

	/**
	 * Check if HTTP request was successful (2xx range).
	 *
	 * @return bool
	 */
	public function is_successful(): bool {
		$code = $this->get_status_code();
		return $code >= 200 && $code < 300;
	}

	/**
	 * Get the transport-level error message, if the request never completed.
	 *
	 * @return string|null Error message, or null when the request reached the server.
	 */
	public function get_error(): ?string {
		if ( ! is_wp_error( $this->response ) ) {
			return null;
		}

		$message = $this->response->get_error_message();

		return '' !== $message ? $message : null;
	}
}
