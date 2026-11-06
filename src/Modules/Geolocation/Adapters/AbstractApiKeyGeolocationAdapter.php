<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Adapters;

use D6N\LPM\Core\Contracts\IHttpRequest;
use D6N\LPM\Core\Contracts\ILogger;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for providers that require an API key.
 */
abstract class AbstractApiKeyGeolocationAdapter extends AbstractGeolocationAdapter {

	/**
	 * Constructor.
	 *
	 * @param string|null       $api_key Plain-text API key.
	 * @param ILogger|null      $logger  Logger instance.
	 * @param IHttpRequest|null $http    HTTP client.
	 */
	public function __construct(
		protected readonly ?string $api_key = null,
		?ILogger $logger = null,
		?IHttpRequest $http = null
	) {
		parent::__construct( $logger, $http );
	}

	/**
	 * Check if provider is available.
	 *
	 * @return bool True when an API key is configured.
	 */
	public function is_available(): bool {
		return null !== $this->api_key && '' !== trim( $this->api_key );
	}
}
