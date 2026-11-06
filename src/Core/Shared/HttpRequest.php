<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Shared;

use D6N\LPM\Core\Contracts\IHttpRequest;
use D6N\LPM\Core\Contracts\IHttpResponse;
use D6N\LPM\Core\Enums\HookEnum;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP Client implementation wrapping WordPress HTTP API.
 */
class HttpRequest implements IHttpRequest {

	/**
	 * Perform GET request.
	 *
	 * @param string               $url     Target URL.
	 * @param array<string, mixed> $headers Request headers.
	 * @param int                  $timeout Timeout in seconds.
	 * @return IHttpResponse
	 */
	public function get( string $url, array $headers = array(), int $timeout = 10 ): IHttpResponse {
		$args = array(
			'timeout'     => $timeout,
			'headers'     => $headers,
			'user-agent'  => 'Localized-Price-Manager/' . Core::get_version(),
			'redirection' => 5,
		);

		$args     = apply_filters( Core::build_hook( HookEnum::HttpRequestArgs->value ), $args, $url, 'GET' );
		$response = wp_remote_get( $url, $args );

		return new HttpResponse( $response );
	}
}
