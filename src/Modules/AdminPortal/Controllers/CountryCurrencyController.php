<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Controllers;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Money\DecimalText;
use D6N\LPM\Core\Money\ExchangeRate;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\Rest\RestController;
use D6N\LPM\Modules\AdminPortal\Support\CountryReference;
use D6N\LPM\Modules\PricingEngine\CountryCurrency\CountryCurrencyRepository;
use D6N\LPM\Modules\PricingEngine\CountryCurrency\CountryCurrencyService;
use D6N\LPM\Modules\PricingEngine\CountryGroup\CountryGroupRepository;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the country → currency mapping and the country groups.
 */
class CountryCurrencyController extends RestController {

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'AdminPortal';

	/**
	 * Bulk actions the table offers.
	 *
	 * @var array<int, string>
	 */
	private const BULK_ACTIONS = array( 'enable', 'disable', 'save', 'reset', 'assign_group' );

	/**
	 * Constructor.
	 *
	 * @param CountryCurrencyRepository $mappings  Country to currency mapping repository.
	 * @param CountryGroupRepository    $groups    Country group repository.
	 * @param CountryCurrencyService    $service   Country currency read model.
	 * @param CountryReference          $reference WooCommerce country and currency lists.
	 * @param ILogger                   $logger    Logger instance.
	 */
	public function __construct(
		private readonly CountryCurrencyRepository $mappings,
		private readonly CountryGroupRepository $groups,
		private readonly CountryCurrencyService $service,
		private readonly CountryReference $reference,
		private readonly ILogger $logger
	) {}

	/**
	 * Register the mapping and group routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/country-currency',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_mappings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_mapping' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->mapping_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/country-currency/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_mappings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->bulk_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/country-currency/(?P<country_code>[A-Za-z]{2})/active',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_mapping_active' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'country_code' => $this->country_code_arg(),
						'active'       => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/country-currency/(?P<country_code>[A-Za-z]{2})/reset',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reset_mapping' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array( 'country_code' => $this->country_code_arg() ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/country-groups',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_groups' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_group' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->group_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/country-groups/(?P<id>[0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_group' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge( array( 'id' => $this->group_id_arg() ), $this->group_args() ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_group' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array( 'id' => $this->group_id_arg() ),
				),
			)
		);
	}

	/**
	 * List every active country → currency mapping, in one response.
	 *
	 * @return WP_REST_Response|WP_Error Response, or WP_Error on a query failure.
	 */
	public function list_mappings(): WP_REST_Response|WP_Error {
		$rows = $this->mappings->get_all_mappings();

		if ( is_wp_error( $rows ) ) {
			return $this->as_rest_error( $rows );
		}

		return $this->respond(
			array(
				'items' => array_map( array( $this, 'mapping_to_array' ), $rows ),
				'total' => count( $rows ),
			)
		);
	}

	/**
	 * Create or update one country → currency mapping.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Response, or WP_Error on an invalid code or a write failure.
	 */
	public function save_mapping( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$country_code = CountryCodeValidator::sanitize( $request->get_param( 'country_code' ) );

		if ( '' === $country_code ) {
			return $this->invalid_country_error();
		}

		$saved = $this->write_mapping( $country_code, $this->collect_mapping_columns( $request ) );

		if ( is_wp_error( $saved ) ) {
			return $this->as_rest_error( $saved );
		}

		$this->announce_mapping_change( array( $country_code ) );

		return $this->respond(
			array(
				'country_code' => $country_code,
				'message'      => __( 'Country currency mapping saved.', 'localized-price-manager' ),
			)
		);
	}

	/**
	 * Apply one bulk action to a set of countries.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Response, or WP_Error when the payload is unusable.
	 */
	public function bulk_mappings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$action = (string) $request->get_param( 'action' );

		if ( ! in_array( $action, self::BULK_ACTIONS, true ) ) {
			return new WP_Error(
				'lpm_invalid_bulk_action',
				__( 'That bulk action is not one this table offers.', 'localized-price-manager' ),
				array( 'status' => 400 )
			);
		}

		$items = $request->get_param( 'items' );

		if ( ! is_array( $items ) || array() === $items ) {
			return new WP_Error(
				'lpm_empty_bulk_selection',
				__( 'Select at least one country before applying a bulk action.', 'localized-price-manager' ),
				array( 'status' => 400 )
			);
		}

		$group_id = $this->resolve_bulk_group( $action, $request );

		if ( is_wp_error( $group_id ) ) {
			return $group_id;
		}

		$changed = array();
		$failed  = array();

		foreach ( $items as $item ) {
			$fields       = is_array( $item ) ? $item : array( 'country_code' => $item );
			$country_code = CountryCodeValidator::sanitize( $fields['country_code'] ?? '' );

			if ( '' === $country_code ) {
				$failed[] = is_scalar( $fields['country_code'] ?? null ) ? (string) $fields['country_code'] : '';

				continue;
			}

			if ( is_wp_error( $this->apply_bulk_action( $action, $country_code, $fields, $group_id ) ) ) {
				$failed[] = $country_code;

				continue;
			}

			$changed[] = $country_code;
		}

		$processed = count( $changed );

		if ( array() !== $changed ) {
			$this->announce_mapping_change( $changed );
		}

		$this->logger->audit(
			sprintf( 'Applied the bulk action "%s" to %d countries.', $action, $processed ),
			array(
				'action'    => $action,
				'processed' => $processed,
				'failed'    => $failed,
			),
			self::LOG_SOURCE
		);

		return $this->respond(
			array(
				'action'    => $action,
				'processed' => $processed,
				'failed'    => $failed,
				'message'   => $this->bulk_message( $action, $processed, count( $failed ) ),
			)
		);
	}

	/**
	 * Switch one mapping on or off.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Response, or WP_Error on an invalid code or a write failure.
	 */
	public function set_mapping_active( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$country_code = CountryCodeValidator::sanitize( $request->get_param( 'country_code' ) );

		if ( '' === $country_code ) {
			return $this->invalid_country_error();
		}

		$active = (bool) $request->get_param( 'active' );
		$saved  = $this->set_active( $country_code, $active );

		if ( is_wp_error( $saved ) ) {
			return $this->as_rest_error( $saved );
		}

		$this->announce_mapping_change( array( $country_code ) );

		return $this->respond(
			array(
				'country_code' => $country_code,
				'active'       => $active,
				'message'      => $active
					? __( 'Country currency mapping enabled.', 'localized-price-manager' )
					: __( 'Country currency mapping disabled.', 'localized-price-manager' ),
			)
		);
	}

	/**
	 * Restore the seeded native currency of one country.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Response, or WP_Error on an invalid code or a write failure.
	 */
	public function reset_mapping( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$country_code = CountryCodeValidator::sanitize( $request->get_param( 'country_code' ) );

		if ( '' === $country_code ) {
			return $this->invalid_country_error();
		}

		$reset = $this->reset_to_native( $country_code );

		if ( is_wp_error( $reset ) ) {
			return $this->as_rest_error( $reset, 400 );
		}

		$this->announce_mapping_change( array( $country_code ) );

		return $this->respond(
			array(
				'country_code' => $country_code,
				'message'      => __( 'Country reset to its native currency.', 'localized-price-manager' ),
			)
		);
	}

	/**
	 * List every active country group.
	 *
	 * @return WP_REST_Response|WP_Error Response, or WP_Error on a query failure.
	 */
	public function list_groups(): WP_REST_Response|WP_Error {
		$rows = $this->groups->get_active_groups();

		if ( is_wp_error( $rows ) ) {
			return $this->as_rest_error( $rows );
		}

		return $this->respond(
			array(
				'items' => array_map( array( $this, 'group_to_array' ), $rows ),
				'total' => count( $rows ),
			)
		);
	}

	/**
	 * Create one country group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Response, or WP_Error on an invalid name or a write failure.
	 */
	public function save_group( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$name = trim( (string) $request->get_param( 'name' ) );
		$slug = $this->derive_slug( $name );

		if ( is_wp_error( $slug ) ) {
			return $slug;
		}

		$unique = $this->groups->resolve_unique_slug( $slug );

		if ( is_wp_error( $unique ) ) {
			return $this->as_rest_error( $unique, 400 );
		}

		$columns = $this->collect_group_columns( $request, $name );

		if ( is_wp_error( $columns ) ) {
			return $this->as_rest_error( $columns, 400 );
		}

		$saved = $this->groups->save_group( $unique, $columns );

		if ( is_wp_error( $saved ) ) {
			return $this->as_rest_error( $saved );
		}

		$saved_id = $saved;

		$this->service->flush();
		$this->announce_group_change( $saved_id );

		$this->logger->audit(
			sprintf( 'Saved the country group "%s".', $unique ),
			array( 'slug' => $unique ),
			self::LOG_SOURCE
		);

		return $this->respond(
			array(
				'slug'    => $unique,
				'message' => __( 'Country group saved.', 'localized-price-manager' ),
			)
		);
	}

	/**
	 * Rename or re-price one existing country group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Response, or WP_Error on an invalid name or a write failure.
	 */
	public function update_group( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$group_id = (int) $request->get_param( 'id' );
		$name     = trim( (string) $request->get_param( 'name' ) );
		$slug     = $this->derive_slug( $name );

		if ( is_wp_error( $slug ) ) {
			return $slug;
		}

		$unique = $this->groups->resolve_unique_slug( $slug, $group_id );

		if ( is_wp_error( $unique ) ) {
			return $this->as_rest_error( $unique, 400 );
		}

		$data = $this->collect_group_columns( $request, $name );

		if ( is_wp_error( $data ) ) {
			return $this->as_rest_error( $data, 400 );
		}

		$data['slug'] = $unique;

		$saved = $this->groups->update_group( $group_id, $data );

		if ( is_wp_error( $saved ) ) {
			return $this->as_rest_error( $saved );
		}

		$this->service->flush();
		$this->announce_group_change( $group_id );

		$this->logger->audit(
			sprintf( 'Updated country group %d.', $group_id ),
			array(
				'group_id' => $group_id,
				'slug'     => $unique,
			),
			self::LOG_SOURCE
		);

		return $this->respond(
			array(
				'id'      => $group_id,
				'slug'    => $unique,
				'message' => __( 'Country group updated.', 'localized-price-manager' ),
			)
		);
	}

	/**
	 * Delete one country group.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error Response, or WP_Error on a write failure.
	 */
	public function delete_group( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$group_id = (int) $request->get_param( 'id' );
		$members  = $this->mappings->get_group_member_codes( $group_id );

		if ( is_wp_error( $members ) ) {
			return $this->as_rest_error( $members );
		}

		if ( array() !== $members ) {
			return new WP_Error(
				'lpm_group_in_use',
				sprintf(
					/* translators: %s: comma separated list of ISO 3166-1 alpha-2 country codes. */
					_n(
						'This group still prices %s. Move that country to another group, or leave it ungrouped, before deleting this one.',
						'This group still prices %s. Move those countries to another group, or leave them ungrouped, before deleting this one.',
						count( $members ),
						'localized-price-manager'
					),
					implode( ', ', $members )
				),
				array( 'status' => 400 )
			);
		}

		$released = $this->mappings->release_inactive_group_members( $group_id );

		if ( is_wp_error( $released ) ) {
			$this->logger->error(
				sprintf( 'Could not release the inactive members of country group %d: %s', $group_id, $released->get_error_message() ),
				array( 'group_id' => $group_id ),
				self::LOG_SOURCE
			);
		}

		$deleted = $this->groups->delete_record( $group_id );

		if ( is_wp_error( $deleted ) ) {
			return $this->as_rest_error( $deleted );
		}

		$this->service->flush();

		$this->logger->audit(
			sprintf( 'Deleted country group %d.', $group_id ),
			array(
				'group_id' => $group_id,
				'released' => is_wp_error( $released ) ? 0 : $released,
			),
			self::LOG_SOURCE
		);

		return $this->respond(
			array(
				'id'      => $group_id,
				'message' => __( 'Country group deleted.', 'localized-price-manager' ),
			)
		);
	}

	/**
	 * Announce that a group's own currency or rate changed.
	 *
	 * @param int $group_id Group whose row was written.
	 * @return void
	 */
	private function announce_group_change( int $group_id ): void {
		$members = $this->mappings->get_group_member_codes( $group_id );

		$this->announce_mapping_change( is_wp_error( $members ) ? array() : $members );
	}

	/**
	 * Announce that the stored country → currency mapping changed.
	 *
	 * @param array<int, string> $country_codes ISO country codes whose mapping changed.
	 * @return void
	 */
	private function announce_mapping_change( array $country_codes ): void {
		/**
		 * Fires after one or more country → currency mappings have been written.
		 *
		 * @param array<int, string> $country_codes ISO 3166-1 alpha-2 codes whose mapping changed.
		 */
		do_action( Core::build_hook( HookEnum::CountryCurrencySaved->value ), $country_codes );
	}

	/**
	 * Run one member of a bulk selection.
	 *
	 * @param string               $action       Bulk action name.
	 * @param string               $country_code Validated ISO country code.
	 * @param array<string, mixed> $fields       Column values carried by the member.
	 * @param int|null             $group_id     Group the whole selection moves to, null to clear it.
	 * @return int|WP_Error Affected count, or WP_Error on failure.
	 */
	private function apply_bulk_action( string $action, string $country_code, array $fields, ?int $group_id = null ): int|WP_Error {
		if ( 'assign_group' === $action ) {
			return $this->write_mapping( $country_code, array( 'group_id' => $group_id ) );
		}

		if ( 'reset' === $action ) {
			return $this->reset_to_native( $country_code );
		}

		if ( 'enable' === $action ) {
			return $this->set_active( $country_code, true );
		}

		if ( 'disable' === $action ) {
			return $this->set_active( $country_code, false );
		}

		return $this->write_mapping( $country_code, $this->collect_bulk_columns( $fields ) );
	}

	/**
	 * Write one mapping and refresh the read model behind it.
	 *
	 * @param string                        $country_code Validated ISO country code.
	 * @param array<string, mixed>|WP_Error $data         Column values, or a collector's refusal.
	 * @return int|WP_Error Affected count, or WP_Error on failure.
	 */
	private function write_mapping( string $country_code, array|WP_Error $data ): int|WP_Error {
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$saved = $this->mappings->save_mapping( $country_code, $data );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->service->flush( $country_code );

		$this->logger->audit(
			sprintf( 'Saved the currency mapping of %s.', $country_code ),
			array(
				'country_code' => $country_code,
				'data'         => $data,
			),
			self::LOG_SOURCE
		);

		return $saved;
	}

	/**
	 * Flip one mapping's active flag, either way.
	 *
	 * @param string $country_code Validated ISO country code.
	 * @param bool   $active       Whether the mapping may reach a price.
	 * @return int|WP_Error Affected count, or WP_Error on failure.
	 */
	private function set_active( string $country_code, bool $active ): int|WP_Error {
		$saved = $this->mappings->save_mapping(
			$country_code,
			array( 'is_active' => $active ? 1 : 0 )
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->service->flush( $country_code );

		$this->logger->audit(
			sprintf(
				$active
					? 'Enabled the currency mapping of %s.'
					: 'Disabled the currency mapping of %s.',
				$country_code
			),
			array( 'country_code' => $country_code ),
			self::LOG_SOURCE
		);

		return $saved;
	}

	/**
	 * Restore one country's seeded native currency.
	 *
	 * @param string $country_code Validated ISO country code.
	 * @return int|WP_Error Affected count, or WP_Error on failure.
	 */
	private function reset_to_native( string $country_code ): int|WP_Error {
		$reset = $this->service->reset_to_native( $country_code );

		if ( is_wp_error( $reset ) ) {
			return $reset;
		}

		$this->logger->audit(
			sprintf( 'Reset %s to its native currency.', $country_code ),
			array( 'country_code' => $country_code ),
			self::LOG_SOURCE
		);

		return $reset;
	}

	/**
	 * Collect the mapping columns one request actually carries.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array<string, mixed>|WP_Error Column values, or WP_Error on an unknown currency.
	 */
	private function collect_mapping_columns( WP_REST_Request $request ): array|WP_Error {
		$data = array( 'is_active' => $request->get_param( 'is_active' ) ? 1 : 0 );

		foreach ( array( 'currency_symbol', 'decimal_separator', 'thousand_separator' ) as $column ) {
			$value = $request->get_param( $column );

			if ( is_string( $value ) && '' !== $value ) {
				$data[ $column ] = $value;
			}
		}

		$currency = $request->get_param( 'currency_code' );

		if ( is_string( $currency ) && '' !== $currency ) {
			if ( ! $this->reference->is_known_currency( $currency ) ) {
				return $this->unknown_currency_error( $currency );
			}

			$data['currency_code'] = strtoupper( $currency );
		}

		$decimals = $request->get_param( 'decimals' );

		if ( is_numeric( $decimals ) ) {
			$data['decimals'] = (int) $decimals;
		}

		if ( $request->has_param( 'group_id' ) ) {
			$group_id = (int) $request->get_param( 'group_id' );

			$data['group_id'] = $group_id > 0 ? $group_id : null;
		}

		return $data;
	}

	/**
	 * Collect the mapping columns one bulk member carries.
	 *
	 * @param array<string, mixed> $fields Member fields.
	 * @return array<string, mixed>|WP_Error Column values, or WP_Error on an unknown currency.
	 */
	private function collect_bulk_columns( array $fields ): array|WP_Error {
		$data = array( 'is_active' => 1 );

		$currency = $fields['currency_code'] ?? null;

		if ( is_string( $currency ) && '' !== $currency ) {
			if ( ! $this->reference->is_known_currency( $currency ) ) {
				return $this->unknown_currency_error( $currency );
			}

			$data['currency_code'] = strtoupper( $currency );
		}

		if ( array_key_exists( 'group_id', $fields ) ) {
			$group_id = (int) $fields['group_id'];

			$data['group_id'] = $group_id > 0 ? $group_id : null;
		}

		return $data;
	}

	/**
	 * Collect the group columns one request carries.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $name    Trimmed group name.
	 * @return array<string, mixed>|WP_Error Column values, or WP_Error on an unusable rate or currency.
	 */
	private function collect_group_columns( WP_REST_Request $request, string $name ): array|WP_Error {
		$currency = strtoupper( trim( (string) $request->get_param( 'currency_code' ) ) );

		if ( '' !== $currency && ! $this->reference->is_known_currency( $currency ) ) {
			return $this->unknown_currency_error( $currency );
		}

		$raw_rate = $request->get_param( 'exchange_rate' );
		$has_rate = null !== $raw_rate && '' !== $raw_rate;
		$rate     = $has_rate ? ExchangeRate::from( $raw_rate ) : null;

		if ( $has_rate && null === $rate ) {
			return new WP_Error(
				'lpm_invalid_exchange_rate',
				__( 'An exchange rate has to be greater than zero, below 100000000, and carry no more than twelve decimal places.', 'localized-price-manager' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'name'          => $name,
			'currency_code' => $currency,
			'exchange_rate' => $rate?->value(),
			'is_active'     => $request->get_param( 'is_active' ) ? 1 : 0,
		);
	}

	/**
	 * The error a currency outside the store's own list answers with.
	 *
	 * @param string $currency_code Submitted code.
	 * @return WP_Error
	 */
	private function unknown_currency_error( string $currency_code ): WP_Error {
		return new WP_Error(
			'lpm_unknown_currency',
			sprintf(
				/* translators: %s: submitted currency code. */
				__( '"%s" is not a currency WooCommerce knows.', 'localized-price-manager' ),
				strtoupper( trim( $currency_code ) )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Resolve the group a whole selection moves to.
	 *
	 * @param string          $action  Bulk action name.
	 * @param WP_REST_Request $request Incoming request.
	 * @return int|null|WP_Error Group ID, null to clear, or WP_Error when the id names no active group.
	 */
	private function resolve_bulk_group( string $action, WP_REST_Request $request ): int|null|WP_Error {
		if ( 'assign_group' !== $action ) {
			return null;
		}

		$group_id = (int) $request->get_param( 'group_id' );

		if ( $group_id <= 0 ) {
			return null;
		}

		$group = $this->groups->get_active_group( $group_id );

		if ( is_wp_error( $group ) ) {
			return $group;
		}

		if ( null === $group ) {
			return new WP_Error(
				'lpm_unknown_country_group',
				__( 'That country group does not exist, or is no longer active.', 'localized-price-manager' ),
				array( 'status' => 400 )
			);
		}

		return $group_id;
	}

	/**
	 * Turn a group name into the slug the row is stored under.
	 *
	 * @param string $name Trimmed group name.
	 * @return string|WP_Error Slug, or WP_Error when the name yields none.
	 */
	private function derive_slug( string $name ): string|WP_Error {
		$slug = '' === $name ? '' : sanitize_title( $name );

		if ( '' === $name || '' === $slug ) {
			return new WP_Error(
				'lpm_invalid_country_group',
				__( 'A country group needs a name it can be identified by.', 'localized-price-manager' ),
				array( 'status' => 400 )
			);
		}

		return $slug;
	}

	/**
	 * Build the message a finished bulk action reports.
	 *
	 * @param string $action    Bulk action name.
	 * @param int    $processed Number of countries written.
	 * @param int    $failed    Number of countries skipped.
	 * @return string Message.
	 */
	private function bulk_message( string $action, int $processed, int $failed ): string {
		if ( $failed > 0 ) {
			return sprintf(
				/* translators: 1: number of countries updated, 2: number of countries skipped. */
				__( '%1$d updated, %2$d skipped.', 'localized-price-manager' ),
				$processed,
				$failed
			);
		}

		if ( 'reset' === $action ) {
			return sprintf(
				/* translators: %d: number of countries reset. */
				_n( '%d country reset to its native currency.', '%d countries reset to their native currency.', $processed, 'localized-price-manager' ),
				$processed
			);
		}

		if ( 'assign_group' === $action ) {
			return sprintf(
				/* translators: %d: number of countries whose group changed. */
				_n( "%d country's group updated.", "%d countries' groups updated.", $processed, 'localized-price-manager' ),
				$processed
			);
		}

		if ( 'disable' === $action ) {
			return sprintf(
				/* translators: %d: number of countries disabled. */
				_n( '%d country disabled.', '%d countries disabled.', $processed, 'localized-price-manager' ),
				$processed
			);
		}

		return sprintf(
			/* translators: %d: number of countries saved. */
			_n( '%d country saved.', '%d countries saved.', $processed, 'localized-price-manager' ),
			$processed
		);
	}

	/**
	 * Describe the writable mapping columns.
	 *
	 * @return array<string, array<string, mixed>> Argument schema.
	 */
	private function mapping_args(): array {
		return array(
			'country_code'       => $this->country_code_arg(),
			'currency_code'      => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[A-Za-z]{3}$',
			),
			'currency_symbol'    => array(
				'type'      => 'string',
				'required'  => false,
				'maxLength' => 20,
			),
			'decimal_separator'  => array(
				'type'      => 'string',
				'required'  => false,
				'maxLength' => 4,
			),
			'thousand_separator' => array(
				'type'      => 'string',
				'required'  => false,
				'maxLength' => 4,
			),
			'decimals'           => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 0,
				'maximum'  => 4,
			),
			'group_id'           => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 0,
			),
			'is_active'          => array(
				'type'     => 'boolean',
				'required' => false,
				'default'  => true,
			),
		);
	}

	/**
	 * Describe the bulk action payload.
	 *
	 * @return array<string, array<string, mixed>> Argument schema.
	 */
	private function bulk_args(): array {
		return array(
			'action'   => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => self::BULK_ACTIONS,
			),
			'group_id' => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
			'items'    => array(
				'type'     => 'array',
				'required' => true,
				'minItems' => 1,
				'items'    => array(
					'type'       => 'object',
					'properties' => array(
						'country_code'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'currency_code' => array(
							'type'    => 'string',
							'pattern' => '^[A-Za-z]{3}$',
						),
						'group_id'      => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
					),
				),
			),
		);
	}

	/**
	 * Describe the writable group columns.
	 *
	 * @return array<string, array<string, mixed>> Argument schema.
	 */
	private function group_args(): array {
		return array(
			'name'          => array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => 191,
			),
			'currency_code' => array(
				'type'      => 'string',
				'required'  => false,
				'default'   => '',
				'pattern'   => '^([A-Za-z]{3})?$',
				'maxLength' => 3,
			),
			'exchange_rate' => array(
				'type'             => array( 'number', 'string', 'null' ),
				'required'         => false,
				'default'          => null,
				'minimum'          => 0,
				'exclusiveMinimum' => true,
				'maximum'          => 99999999.99999999,
			),
			'is_active'     => array(
				'type'     => 'boolean',
				'required' => false,
				'default'  => true,
			),
		);
	}

	/**
	 * Describe the group id path argument.
	 *
	 * @return array<string, mixed> Argument schema.
	 */
	private function group_id_arg(): array {
		return array(
			'type'     => 'integer',
			'required' => true,
			'minimum'  => 1,
		);
	}

	/**
	 * Describe the country code argument.
	 *
	 * @return array<string, mixed> Argument schema.
	 */
	private function country_code_arg(): array {
		return array(
			'type'              => 'string',
			'required'          => true,
			'validate_callback' => static fn ( mixed $value ): bool => CountryCodeValidator::is_valid( $value ),
		);
	}

	/**
	 * Build the response returned for an unusable country code.
	 *
	 * @return WP_Error Error carrying a 400 status.
	 */
	private function invalid_country_error(): WP_Error {
		return new WP_Error(
			'lpm_invalid_country_code',
			__( 'A valid ISO 3166-1 alpha-2 country code is required.', 'localized-price-manager' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Flatten one mapping row for the REST payload.
	 *
	 * @param object $row Mapping row.
	 * @return array<string, mixed> Flat row.
	 */
	private function mapping_to_array( object $row ): array {
		$country_code = strtoupper( (string) ( $row->country_code ?? '' ) );
		$native       = strtoupper( (string) ( $row->native_currency_code ?? '' ) );

		return array(
			'id'                     => (int) ( $row->id ?? 0 ),
			'country_code'           => $country_code,
			'country_name'           => '' === $country_code ? '' : $this->reference->get_country_name( $country_code ),
			'currency_code'          => (string) ( $row->currency_code ?? '' ),
			'currency_symbol'        => (string) ( $row->currency_symbol ?? '' ),
			'native_currency_code'   => $native,
			'native_currency_symbol' => $this->reference->get_currency_symbol( $native ),
			'decimal_separator'      => (string) ( $row->decimal_separator ?? '.' ),
			'thousand_separator'     => (string) ( $row->thousand_separator ?? ',' ),
			'decimals'               => (int) ( $row->decimals ?? 2 ),
			'group_id'               => isset( $row->group_id ) ? (int) $row->group_id : null,
			'is_active'              => (bool) ( $row->is_active ?? true ),
		);
	}

	/**
	 * Flatten one group row for the REST payload.
	 *
	 * @param object $row Group row.
	 * @return array<string, mixed> Flat row.
	 */
	private function group_to_array( object $row ): array {
		return array(
			'id'            => (int) ( $row->id ?? 0 ),
			'name'          => (string) ( $row->name ?? '' ),
			'slug'          => (string) ( $row->slug ?? '' ),
			'currency_code' => (string) ( $row->currency_code ?? '' ),
			'exchange_rate' => isset( $row->exchange_rate ) ? DecimalText::plain( $row->exchange_rate ) : null,
			'is_active'     => (bool) ( $row->is_active ?? true ),
		);
	}
}
