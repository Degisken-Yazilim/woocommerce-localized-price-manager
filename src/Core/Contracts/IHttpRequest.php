<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for HTTP request wrapper.
 */
interface IHttpRequest {
	/**
	 * Perform GET request.
	 *
	 * @param string               $url     Target URL.
	 * @param array<string, mixed> $headers Headers array.
	 * @param int                  $timeout Timeout in seconds.
	 * @return IHttpResponse
	 */
	public function get( string $url, array $headers = array(), int $timeout = 10 ): IHttpResponse;
}
