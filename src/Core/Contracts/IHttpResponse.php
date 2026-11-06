<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for HTTP response wrapper.
 */
interface IHttpResponse {
	/**
	 * Get HTTP response status code.
	 *
	 * @return int
	 */
	public function get_status_code(): int;

	/**
	 * Get raw response body string.
	 *
	 * @return string
	 */
	public function get_body(): string;

	/**
	 * Get decoded JSON body.
	 *
	 * @param bool $assoc Return associative array if true.
	 * @return mixed
	 */
	public function get_json( bool $assoc = true ): mixed;

	/**
	 * Check if HTTP request was successful (2xx range).
	 *
	 * @return bool
	 */
	public function is_successful(): bool;

	/**
	 * Get the transport-level error message, if the request never completed.
	 *
	 * @return string|null
	 */
	public function get_error(): ?string;
}
