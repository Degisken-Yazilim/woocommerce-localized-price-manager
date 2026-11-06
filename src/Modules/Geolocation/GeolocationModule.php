<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Modules\Geolocation\Services\CustomerCountryPrivacyService;
use D6N\LPM\Modules\Geolocation\Services\CustomerCountryService;
use D6N\LPM\Modules\Geolocation\Services\GeolocationApiKeyService;
use D6N\LPM\Modules\Geolocation\Services\GeolocationProviderQueue;
use D6N\LPM\Modules\Geolocation\Services\GeolocationResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Geolocation bounded-context module bootstrapper.
 */
class GeolocationModule implements IHookable {

	private GeolocationApiKeyService $api_keys;
	private GeolocationProviderQueue $provider_queue;
	private GeolocationResolver $resolver;
	private CustomerCountryService $country_service;
	private CustomerCountryPrivacyService $privacy_service;

	/**
	 * Constructor.
	 *
	 * @param ILogger $logger Logger service instance.
	 * @param ICache  $cache  Cache adapter instance.
	 */
	public function __construct( ILogger $logger, ICache $cache ) {
		$this->api_keys       = new GeolocationApiKeyService( $logger );
		$this->provider_queue = new GeolocationProviderQueue( $this->api_keys, $logger );

		$this->resolver        = new GeolocationResolver( fn (): array => $this->provider_queue->get_adapters(), $logger );
		$this->country_service = new CustomerCountryService( $logger, $cache, $this->resolver );
		$this->privacy_service = new CustomerCountryPrivacyService();
	}

	/**
	 * Get customer country service instance.
	 *
	 * @return CustomerCountryService
	 */
	public function get_country_service(): CustomerCountryService {
		return $this->country_service;
	}

	/**
	 * Get the provider queue the resolver builds its chain from.
	 *
	 * @return GeolocationProviderQueue
	 */
	public function get_provider_queue(): GeolocationProviderQueue {
		return $this->provider_queue;
	}

	/**
	 * Get the API key store shared with the settings screen.
	 *
	 * @return GeolocationApiKeyService
	 */
	public function get_api_key_service(): GeolocationApiKeyService {
		return $this->api_keys;
	}

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$this->country_service->register_hooks();
		$this->privacy_service->register_hooks();
	}
}
