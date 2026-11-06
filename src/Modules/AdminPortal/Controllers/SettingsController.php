<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Controllers;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\CacheGroupEnum;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\Rest\RestController;
use D6N\LPM\Modules\AdminPortal\Settings\ApiKeyGateway;
use D6N\LPM\Modules\AdminPortal\Settings\SettingDefinition;
use D6N\LPM\Modules\AdminPortal\Settings\SettingsSchema;
use D6N\LPM\Modules\AdminPortal\Settings\SettingsStore;
use D6N\LPM\Modules\ExchangeRates\Services\ExchangeRatesService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller owning the settings surface.
 */
class SettingsController extends RestController {

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'AdminPortal';

	/**
	 * Constructor.
	 *
	 * @param SettingsStore             $store          Schema-backed settings store.
	 * @param ApiKeyGateway             $api_keys       API key read/write gateway.
	 * @param ICache                    $cache          Cache adapter instance.
	 * @param ILogger                   $logger         Logger instance.
	 * @param ExchangeRatesService|null $exchange_rates Rates service, used to reschedule the update cron.
	 */
	public function __construct(
		private readonly SettingsStore $store,
		private readonly ApiKeyGateway $api_keys,
		private readonly ICache $cache,
		private readonly ILogger $logger,
		private readonly ?ExchangeRatesService $exchange_rates = null
	) {}

	/**
	 * Register one read and one write route per settings tab.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( array_keys( SettingsSchema::get_tabs() ) as $tab ) {
			$args = SettingsSchema::rest_args( $tab );

			if ( array() === $args ) {
				continue;
			}

			register_rest_route(
				self::REST_NAMESPACE,
				'/settings/' . $tab,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function () use ( $tab ): WP_REST_Response {
							return $this->get_tab( $tab );
						},
						'permission_callback' => array( $this, 'check_permission' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => function ( WP_REST_Request $request ) use ( $tab ): WP_REST_Response|WP_Error {
							return $this->save_tab( $tab, $request );
						},
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => $args,
					),
				)
			);
		}
	}

	/**
	 * Read every value of one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return WP_REST_Response Response carrying the tab values.
	 */
	public function get_tab( string $tab ): WP_REST_Response {
		return $this->respond(
			array(
				'tab'    => $tab,
				'values' => $this->collect_values( $tab ),
			)
		);
	}

	/**
	 * Persist the submitted fields of one tab.
	 *
	 * @param string          $tab     Tab slug.
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Response, or WP_Error when the tab is unknown.
	 */
	public function save_tab( string $tab, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$definitions = SettingsSchema::for_tab( $tab );

		if ( array() === $definitions ) {
			return new WP_Error(
				'lpm_unknown_settings_tab',
				__( 'Unknown settings tab.', 'localized-price-manager' ),
				array( 'status' => 404 )
			);
		}

		$saved     = array();
		$rejected  = array();
		$frequency = null;

		foreach ( $definitions as $key => $definition ) {
			$submitted = $this->read_param( $request, $definition );

			if ( null === $submitted ) {
				continue;
			}

			if ( $definition->secret ) {
				if ( $this->api_keys->save( $definition, (string) $submitted ) ) {
					$saved[] = $key;
				} else {
					$rejected[] = $key;
				}

				continue;
			}

			if ( OptionsEnum::ExchangeRateFrequency->value === $key && $this->store->has_changed( $key, $submitted ) ) {
				$frequency = (string) $definition->sanitize( $submitted );
			}

			if ( $this->store->save( $key, $submitted ) ) {
				$saved[] = $key;
				continue;
			}

			$rejected[] = $key;
		}

		if ( null !== $frequency ) {
			$this->reschedule_rate_updates( $frequency );
		}

		$this->invalidate( $tab );

		$this->logger->audit(
			sprintf( 'Updated the "%s" settings tab.', $tab ),
			array(
				'tab'      => $tab,
				'saved'    => $saved,
				'rejected' => $rejected,
			),
			self::LOG_SOURCE
		);

		/**
		 * Fires after one settings tab has been written.
		 *
		 * @param string $tab Tab slug that was saved.
		 */
		do_action( Core::build_hook( HookEnum::SettingsSaved->value ), $tab );

		return $this->respond(
			array(
				'tab'      => $tab,
				'saved'    => $saved,
				'rejected' => $rejected,
				'values'   => $this->collect_values( $tab ),
				'message'  => array() === $rejected
					? __( 'Settings saved.', 'localized-price-manager' )
					: __( 'Settings saved, but some values were rejected.', 'localized-price-manager' ),
			)
		);
	}

	/**
	 * Collect the render-ready values of one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return array<string, mixed> Option key to value map.
	 */
	public function collect_values( string $tab ): array {
		$values = array();

		foreach ( SettingsSchema::for_tab( $tab ) as $key => $definition ) {
			$values[ $key ] = $definition->secret
				? $this->api_keys->get_masked( $definition )
				: $definition->cast( $this->store->get_raw( $key ) );
		}

		return $values;
	}

	/**
	 * Read one parameter from the request, distinguishing "absent" from "empty".
	 *
	 * @param WP_REST_Request   $request    Incoming request.
	 * @param SettingDefinition $definition Field definition.
	 * @return mixed Submitted value, or null when the field must be skipped.
	 */
	private function read_param( WP_REST_Request $request, SettingDefinition $definition ): mixed {
		if ( $request->has_param( $definition->key ) ) {
			return $request->get_param( $definition->key );
		}

		if ( ! $this->has_sibling_param( $request, $definition ) ) {
			return null;
		}

		if ( SettingDefinition::TYPE_BOOLEAN === $definition->type ) {
			return 'no';
		}

		if ( SettingDefinition::TYPE_ARRAY === $definition->type ) {
			return array();
		}

		return null;
	}

	/**
	 * Check whether the request carries at least one other field of the same tab.
	 *
	 * @param WP_REST_Request   $request    Incoming request.
	 * @param SettingDefinition $definition Field definition.
	 * @return bool True when another field of the same tab is present.
	 */
	private function has_sibling_param( WP_REST_Request $request, SettingDefinition $definition ): bool {
		foreach ( array_keys( SettingsSchema::for_tab( $definition->tab ) ) as $key ) {
			if ( $key !== $definition->key && $request->has_param( $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Move the exchange rate cron onto the newly selected frequency.
	 *
	 * @param string $frequency Frequency key.
	 * @return void
	 */
	private function reschedule_rate_updates( string $frequency ): void {
		if ( null === $this->exchange_rates ) {
			$this->logger->warning(
				'Exchange rate frequency changed but no rates service is wired; the cron was not rescheduled.',
				array( 'frequency' => $frequency ),
				self::LOG_SOURCE
			);

			return;
		}

		$this->exchange_rates->reschedule( $frequency );
	}

	/**
	 * Drop the caches a settings change can invalidate.
	 *
	 * @param string $tab Tab slug that was saved.
	 * @return void
	 */
	private function invalidate( string $tab ): void {
		$this->cache->flush_group( CacheGroupEnum::Pricing->value );

		if ( SettingsSchema::TAB_EXCHANGE_RATES === $tab ) {
			$this->cache->flush_group( CacheGroupEnum::ExchangeRates->value );
		}

		if ( SettingsSchema::TAB_GEOLOCATION === $tab ) {
			$this->cache->flush_group( CacheGroupEnum::Geolocation->value );
		}
	}
}
