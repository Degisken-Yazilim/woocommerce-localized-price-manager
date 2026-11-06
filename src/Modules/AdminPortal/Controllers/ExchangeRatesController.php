<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Controllers;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Money\DecimalText;
use D6N\LPM\Modules\AdminPortal\Rest\RestController;
use D6N\LPM\Modules\ExchangeRates\Repositories\ExchangeRateRepository;
use D6N\LPM\Modules\ExchangeRates\Services\ExchangeRatesService;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller exposing the stored exchange rates and a manual refresh.
 */
class ExchangeRatesController extends RestController {

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'AdminPortal';

	/**
	 * Constructor.
	 *
	 * @param ExchangeRateRepository    $repository Rate repository.
	 * @param ILogger                   $logger     Logger instance.
	 * @param ExchangeRatesService|null $service    Rates service used for the manual refresh.
	 */
	public function __construct(
		private readonly ExchangeRateRepository $repository,
		private readonly ILogger $logger,
		private readonly ?ExchangeRatesService $service = null
	) {}

	/**
	 * Register the rate routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/exchange-rates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_rates' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/exchange-rates/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh_rates' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * List every stored rate.
	 *
	 * @return WP_REST_Response|WP_Error Response, or WP_Error on a query failure.
	 */
	public function list_rates(): WP_REST_Response|WP_Error {
		$rows = $this->repository->get_all();

		if ( is_wp_error( $rows ) ) {
			return $this->as_rest_error( $rows );
		}

		return $this->respond(
			array(
				'items'        => array_map( array( $this, 'rate_to_array' ), $rows ),
				'total'        => count( $rows ),
				'last_updated' => $this->last_updated(),
			)
		);
	}

	/**
	 * Run the rate update immediately.
	 *
	 * @return WP_REST_Response|WP_Error Response, or WP_Error when no service is wired.
	 */
	public function refresh_rates(): WP_REST_Response|WP_Error {
		if ( null === $this->service ) {
			return new WP_Error(
				'lpm_rates_service_unavailable',
				__( 'The exchange rates service is not available.', 'localized-price-manager' ),
				array( 'status' => 503 )
			);
		}

		$updated = $this->service->update_exchange_rates();

		$this->logger->audit(
			$updated ? 'Exchange rates refreshed manually.' : 'Manual exchange rate refresh failed.',
			array( 'success' => $updated ),
			self::LOG_SOURCE
		);

		return $this->respond(
			array(
				'success'      => $updated,
				'last_updated' => $this->last_updated(),
				'message'      => $updated
					? __( 'Exchange rates updated.', 'localized-price-manager' )
					: __( 'No provider could supply rates. Check the service log.', 'localized-price-manager' ),
			),
			$updated ? 200 : 502
		);
	}

	/**
	 * Get the Unix timestamp of the most recently stored rate.
	 *
	 * @return int Unix timestamp, or 0 when no rate is stored.
	 */
	private function last_updated(): int {
		$timestamp = $this->repository->get_last_updated_timestamp();

		return is_wp_error( $timestamp ) ? 0 : $timestamp;
	}

	/**
	 * Flatten one rate row for the REST payload.
	 *
	 * @param array<string, mixed> $row Rate row.
	 * @return array<string, mixed> Flat row.
	 */
	private function rate_to_array( array $row ): array {
		return array(
			'base_currency'   => (string) ( $row['base_currency'] ?? '' ),
			'target_currency' => (string) ( $row['target_currency'] ?? '' ),
			'exchange_rate'   => DecimalText::plain( $row['exchange_rate'] ?? '0' ) ?? '0',
			'source'          => (string) ( $row['source'] ?? '' ),
			'last_updated'    => (string) ( $row['last_updated'] ?? '' ),
		);
	}
}
