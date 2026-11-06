<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Controllers;

use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Modules\AdminPortal\Rest\RestController;
use D6N\LPM\Modules\DiagnosticLog\Enums\LogChannelEnum;
use D6N\LPM\Modules\DiagnosticLog\Models\LogDateRange;
use D6N\LPM\Modules\DiagnosticLog\Repositories\LogRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for querying diagnostic logs.
 */
class LogViewerController extends RestController {

	/**
	 * Largest page size the endpoint will serve.
	 *
	 * @var int
	 */
	public const MAX_LIMIT = 200;

	/**
	 * Page size used when the caller does not ask for one.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * Schema pattern accepted by the two date bounds.
	 *
	 * @var string
	 */
	private const DATE_PATTERN = '^$|' . LogDateRange::DATE_PATTERN;

	/**
	 * Constructor.
	 *
	 * @param LogRepository $repository Log repository instance.
	 */
	public function __construct(
		private readonly LogRepository $repository
	) {}

	/**
	 * Register the log query route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/logs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_logs' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'channel'   => array(
							'type'        => 'string',
							'required'    => false,
							'enum'        => array_merge( array( '' ), array_column( LogChannelEnum::cases(), 'value' ) ),
							'description' => __( 'Restrict the result to one log channel.', 'localized-price-manager' ),
						),
						'level'     => array(
							'type'        => 'string',
							'required'    => false,
							'enum'        => array_merge( array( '' ), array_column( LogLevelEnum::cases(), 'value' ) ),
							'description' => __( 'Restrict the result to one severity level.', 'localized-price-manager' ),
						),
						'search'    => array(
							'type'        => 'string',
							'required'    => false,
							'description' => __( 'Substring searched in the log message.', 'localized-price-manager' ),
						),
						'date_from' => array(
							'type'        => 'string',
							'required'    => false,
							'pattern'     => self::DATE_PATTERN,
							'description' => __( 'Only return entries from the start of this calendar date, read in the site timezone (YYYY-MM-DD).', 'localized-price-manager' ),
						),
						'date_to'   => array(
							'type'        => 'string',
							'required'    => false,
							'pattern'     => self::DATE_PATTERN,
							'description' => __( 'Only return entries up to the end of this calendar date, read in the site timezone (YYYY-MM-DD).', 'localized-price-manager' ),
						),
						'limit'     => array(
							'type'        => 'integer',
							'required'    => false,
							'default'     => self::DEFAULT_LIMIT,
							'minimum'     => 1,
							'maximum'     => self::MAX_LIMIT,
							'description' => __( 'Page size.', 'localized-price-manager' ),
						),
						'offset'    => array(
							'type'        => 'integer',
							'required'    => false,
							'default'     => 0,
							'minimum'     => 0,
							'description' => __( 'Number of records to skip.', 'localized-price-manager' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Query the log table.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Response, or WP_Error on an invalid filter or a query failure.
	 */
	public function get_logs( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$channel = $this->resolve_channel( (string) $request->get_param( 'channel' ) );

		if ( is_wp_error( $channel ) ) {
			return $channel;
		}

		$level = $this->resolve_level( (string) $request->get_param( 'level' ) );

		if ( is_wp_error( $level ) ) {
			return $level;
		}

		$range = LogDateRange::from_local_dates(
			(string) $request->get_param( 'date_from' ),
			(string) $request->get_param( 'date_to' )
		);

		if ( is_wp_error( $range ) ) {
			return $this->as_rest_error( $range, 400 );
		}

		$limit  = $this->clamp_limit( $request->get_param( 'limit' ) );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$search = trim( (string) $request->get_param( 'search' ) );

		$filters = array_merge(
			array(
				'channel' => $channel,
				'level'   => $level,
				'message' => $search,
			),
			$range->to_filters()
		);

		$logs = $this->repository->get_filtered_logs(
			array_merge(
				$filters,
				array(
					'limit'  => $limit,
					'offset' => $offset,
				)
			)
		);

		if ( is_wp_error( $logs ) ) {
			return $this->as_rest_error( $logs );
		}

		$total = $this->repository->count_filtered_logs( $filters );

		if ( is_wp_error( $total ) ) {
			return $this->as_rest_error( $total );
		}

		return $this->respond(
			array(
				'logs'   => $logs,
				'total'  => $total,
				'limit'  => $limit,
				'offset' => $offset,
			)
		);
	}

	/**
	 * Validate the channel filter against the channel enum.
	 *
	 * @param string $raw Raw channel value.
	 * @return string|WP_Error Channel value, an empty string for "no filter", or WP_Error when unknown.
	 */
	private function resolve_channel( string $raw ): string|WP_Error {
		$normalized = strtolower( trim( $raw ) );

		if ( '' === $normalized ) {
			return '';
		}

		$channel = LogChannelEnum::from_value( $normalized );

		if ( $channel->value !== $normalized ) {
			return new WP_Error(
				'lpm_invalid_log_channel',
				__( 'Unknown log channel.', 'localized-price-manager' ),
				array( 'status' => 400 )
			);
		}

		return $channel->value;
	}

	/**
	 * Validate the level filter against the level enum.
	 *
	 * @param string $raw Raw level value.
	 * @return string|WP_Error Level value, an empty string for "no filter", or WP_Error when unknown.
	 */
	private function resolve_level( string $raw ): string|WP_Error {
		$normalized = strtolower( trim( $raw ) );

		if ( '' === $normalized ) {
			return '';
		}

		$level = LogLevelEnum::from_value( $normalized );

		if ( $level->value !== $normalized ) {
			return new WP_Error(
				'lpm_invalid_log_level',
				__( 'Unknown log level.', 'localized-price-manager' ),
				array( 'status' => 400 )
			);
		}

		return $level->value;
	}

	/**
	 * Clamp the requested page size into the served range.
	 *
	 * @param mixed $raw Raw limit value.
	 * @return int Page size between 1 and MAX_LIMIT.
	 */
	private function clamp_limit( mixed $raw ): int {
		$limit = (int) ( is_scalar( $raw ) ? $raw : 0 );

		if ( $limit <= 0 ) {
			return self::DEFAULT_LIMIT;
		}

		return min( $limit, self::MAX_LIMIT );
	}
}
