<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\CountryCurrency;

use D6N\LPM\Core\Database\AbstractRepository;
use D6N\LPM\Core\Reference\CountryCurrencyMap;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Country to currency mapping repository.
 */
class CountryCurrencyRepository extends AbstractRepository {

	/**
	 * Get the active mapping of one country.
	 *
	 * @param string $country_code ISO country code.
	 * @return stdClass|WP_Error|null Row, null when absent, or WP_Error on failure.
	 */
	public function get_by_country( string $country_code ): stdClass|WP_Error|null {
		$country_code = CountryCodeValidator::normalize( $country_code );

		if ( '' === $country_code ) {
			return null;
		}

		return $this->guarded(
			function () use ( $country_code ): ?stdClass {
				$results = $this->mapper->get_where(
					array(
						'country_code' => $country_code,
						'is_active'    => 1,
					)
				);

				$row = $results[0] ?? null;

				return $row instanceof stdClass ? $row : null;
			}
		);
	}

	/**
	 * Get every active mapping.
	 *
	 * @return array<stdClass>|WP_Error
	 */
	public function get_active_mappings(): array|WP_Error {
		return $this->guarded(
			fn (): array => $this->mapper->get_where( array( 'is_active' => 1 ) )
		);
	}

	/**
	 * Get every mapping, active or not.
	 *
	 * @return array<stdClass>|WP_Error
	 */
	public function get_all_mappings(): array|WP_Error {
		return $this->guarded(
			fn (): array => $this->mapper->get_where( array() )
		);
	}

	/**
	 * Create or update the mapping of one country.
	 *
	 * @param string               $country_code ISO country code.
	 * @param array<string, mixed> $data         Column values; unknown keys are dropped by the model.
	 * @return int|WP_Error Inserted ID or affected count, or WP_Error on failure.
	 */
	public function save_mapping( string $country_code, array $data ): int|WP_Error {
		$country_code = CountryCodeValidator::normalize( $country_code );

		if ( '' === $country_code ) {
			return new WP_Error(
				'lpm_invalid_country_mapping',
				__( 'A country code is required to save a currency mapping.', 'localized-price-manager' )
			);
		}

		$existing = $this->find_row( $country_code );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		foreach ( array( 'currency_code', 'native_currency_code' ) as $currency_column ) {
			if ( isset( $data[ $currency_column ] ) && is_string( $data[ $currency_column ] ) ) {
				$data[ $currency_column ] = strtoupper( $data[ $currency_column ] );
			}
		}

		$data                 = $this->with_reference_format( $existing, $data );
		$data['country_code'] = $country_code;

		if ( $existing && isset( $existing->id ) ) {
			return $this->update_record( (int) $existing->id, $data );
		}

		return $this->create_record( $data );
	}

	/**
	 * Take the symbol and decimals of a newly chosen currency from the bundled reference map.
	 *
	 * @param stdClass|null        $existing Stored row, or null for a new mapping.
	 * @param array<string, mixed> $data     Column values about to be written.
	 * @return array<string, mixed> Column values, the symbol and decimals filled in when the currency changes.
	 */
	private function with_reference_format( ?stdClass $existing, array $data ): array {
		$currency = isset( $data['currency_code'] ) && is_string( $data['currency_code'] ) ? $data['currency_code'] : '';
		$current  = null !== $existing ? strtoupper( (string) ( $existing->currency_code ?? '' ) ) : '';

		if ( '' === $currency || $currency === $current ) {
			return $data;
		}

		if ( ! array_key_exists( 'currency_symbol', $data ) ) {
			$data['currency_symbol'] = CountryCurrencyMap::get_currency_symbol( $currency ) ?? '';
		}

		if ( ! array_key_exists( 'decimals', $data ) ) {
			$data['decimals'] = CountryCurrencyMap::get_currency_decimals( $currency ) ?? Core::get_shop_price_decimals();
		}

		return $data;
	}

	/**
	 * Look up a mapping regardless of its active flag, for write paths.
	 *
	 * @param string $country_code Normalised ISO country code.
	 * @return stdClass|WP_Error|null
	 */
	private function find_row( string $country_code ): stdClass|WP_Error|null {
		return $this->guarded(
			function () use ( $country_code ): ?stdClass {
				$results = $this->mapper->get_where( array( 'country_code' => $country_code ) );

				$row = $results[0] ?? null;

				return $row instanceof stdClass ? $row : null;
			}
		);
	}
	/**
	 * Every active country that belongs to a country group.
	 *
	 * @return array<int, string>|WP_Error Country codes, or WP_Error on failure.
	 */
	public function get_grouped_countries(): array|WP_Error {
		return $this->guarded(
			fn (): array => $this->mapper->distinct_values(
				'country_code',
				array(
					'is_active' => 1,
					array( 'group_id', 'IS NOT NULL', null ),
				)
			)
		);
	}

	/**
	 * The active countries that belong to one group.
	 *
	 * @param int $group_id Group ID.
	 * @return array<int, string>|WP_Error Country codes, or WP_Error on failure.
	 */
	public function get_group_member_codes( int $group_id ): array|WP_Error {
		if ( $group_id <= 0 ) {
			return array();
		}

		return $this->guarded(
			fn (): array => $this->mapper->distinct_values(
				'country_code',
				array(
					'group_id'  => $group_id,
					'is_active' => 1,
				)
			)
		);
	}

	/**
	 * Clear the group of every *inactive* mapping that points at it.
	 *
	 * @param int $group_id Group ID.
	 * @return int|WP_Error Rows released, or WP_Error on failure.
	 */
	public function release_inactive_group_members( int $group_id ): int|WP_Error {
		if ( $group_id <= 0 ) {
			return 0;
		}

		return $this->guarded(
			fn (): int => $this->mapper->update_where(
				array( 'group_id' => null ),
				array(
					'group_id'  => $group_id,
					'is_active' => 0,
				)
			)
		);
	}
}
