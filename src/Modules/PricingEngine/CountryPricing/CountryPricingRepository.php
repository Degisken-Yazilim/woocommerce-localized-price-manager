<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\CountryPricing;

use D6N\LPM\Core\Database\AbstractRepository;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Product level per-country pricing rule repository.
 */
class CountryPricingRepository extends AbstractRepository {

	/**
	 * Get the active Mode 1 rule for a product and country.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $country_code ISO country code.
	 * @return stdClass|WP_Error|null Row, null when absent, or WP_Error on failure.
	 */
	public function get_by_product_and_country( int $product_id, string $country_code ): stdClass|WP_Error|null {
		$country_code = CountryCodeValidator::normalize( $country_code );

		if ( $product_id <= 0 || '' === $country_code ) {
			return null;
		}

		return $this->guarded(
			function () use ( $product_id, $country_code ): ?stdClass {
				$results = $this->mapper->get_where(
					array(
						'product_id'   => $product_id,
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
	 * Get the active rules of several products for one country in one query, keyed by product ID.
	 *
	 * @param array<int> $product_ids  Product IDs to look up.
	 * @param string     $country_code ISO country code.
	 * @return array<int, stdClass>|WP_Error Map of product ID to row, or WP_Error on failure.
	 */
	public function get_by_products_and_country( array $product_ids, string $country_code ): array|WP_Error {
		$country_code = CountryCodeValidator::normalize( $country_code );
		$product_ids  = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ), static fn ( int $id ): bool => $id > 0 ) ) );

		if ( empty( $product_ids ) || '' === $country_code ) {
			return array();
		}

		return $this->guarded(
			function () use ( $product_ids, $country_code ): array {
				$results = $this->mapper->get_where(
					array(
						'country_code' => $country_code,
						'is_active'    => 1,
						array( 'product_id', 'IN', $product_ids ),
					)
				);

				$map = array();

				foreach ( $results as $row ) {
					if ( $row instanceof stdClass && isset( $row->product_id ) ) {
						$map[ (int) $row->product_id ] = $row;
					}
				}

				return $map;
			}
		);
	}

	/**
	 * Get every rule stored for a product.
	 *
	 * @param int  $product_id  Product ID.
	 * @param bool $only_active Whether to restrict the result to active rules.
	 * @return array<stdClass>|WP_Error
	 */
	public function get_by_product( int $product_id, bool $only_active = true ): array|WP_Error {
		if ( $product_id <= 0 ) {
			return array();
		}

		return $this->guarded(
			function () use ( $product_id, $only_active ): array {
				$conditions = array( 'product_id' => $product_id );

				if ( $only_active ) {
					$conditions['is_active'] = 1;
				}

				return $this->mapper->get_where( $conditions );
			}
		);
	}

	/**
	 * Delete rules for countries no longer present in the submitted set.
	 *
	 * @param int           $product_id    Product ID.
	 * @param array<string> $country_codes Kept country codes.
	 * @return int|WP_Error Number of deleted rows, or WP_Error on failure.
	 */
	public function delete_excluded_countries( int $product_id, array $country_codes ): int|WP_Error {
		$kept = array_values(
			array_filter(
				array_map(
					static fn ( $code ): string => CountryCodeValidator::normalize( $code ),
					$country_codes
				)
			)
		);

		if ( empty( $kept ) ) {
			return $this->delete_by_product( $product_id );
		}

		return $this->guarded(
			fn (): int => $this->mapper->delete_where(
				array(
					'product_id' => $product_id,
					array( 'country_code', 'NOT IN', $kept ),
				)
			)
		);
	}

	/**
	 * Delete every rule of a product.
	 *
	 * @param int $product_id Product ID.
	 * @return int|WP_Error Number of deleted rows, or WP_Error on failure.
	 */
	public function delete_by_product( int $product_id ): int|WP_Error {
		return $this->guarded(
			fn (): int => $this->mapper->delete_where( array( 'product_id' => $product_id ) )
		);
	}

	/**
	 * Create or update a full rule row.
	 *
	 * @param int                  $product_id   Product ID.
	 * @param string               $country_code ISO country code.
	 * @param array<string, mixed> $data         Column values; unknown keys are dropped by the model.
	 * @return int|WP_Error Inserted ID or affected count, or WP_Error on failure.
	 */
	public function save_rule( int $product_id, string $country_code, array $data ): int|WP_Error {
		$country_code = CountryCodeValidator::normalize( $country_code );

		if ( $product_id <= 0 || '' === $country_code ) {
			return new WP_Error(
				'lpm_invalid_pricing_rule',
				__( 'A product ID and a country code are required to save a pricing rule.', 'localized-price-manager' )
			);
		}

		$existing = $this->find_row( $product_id, $country_code );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		foreach ( array( 'local_currency', 'input_currency' ) as $currency_column ) {
			if ( isset( $data[ $currency_column ] ) && is_string( $data[ $currency_column ] ) ) {
				$data[ $currency_column ] = strtoupper( $data[ $currency_column ] );
			}
		}

		$data['product_id']   = $product_id;
		$data['country_code'] = $country_code;

		if ( $existing && isset( $existing->id ) ) {
			return $this->update_record( (int) $existing->id, $data );
		}

		return $this->create_record( $data );
	}

	/**
	 * Clear the local sale price of every rule of a product.
	 *
	 * @param int $product_id Product ID.
	 * @return int|WP_Error Number of updated rows, or WP_Error on failure.
	 */
	public function clear_sale_prices( int $product_id ): int|WP_Error {
		if ( $product_id <= 0 ) {
			return 0;
		}

		return $this->guarded(
			fn (): int => $this->mapper->update_where(
				array( 'local_sale_price' => null ),
				array(
					'product_id' => $product_id,
					array( 'local_sale_price', 'IS NOT NULL', null ),
				)
			)
		);
	}

	/**
	 * Look up a row regardless of its active flag, for write paths.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $country_code Normalised ISO country code.
	 * @return stdClass|WP_Error|null
	 */
	private function find_row( int $product_id, string $country_code ): stdClass|WP_Error|null {
		return $this->guarded(
			function () use ( $product_id, $country_code ): ?stdClass {
				$results = $this->mapper->get_where(
					array(
						'product_id'   => $product_id,
						'country_code' => $country_code,
					)
				);

				$row = $results[0] ?? null;

				return $row instanceof stdClass ? $row : null;
			}
		);
	}
	/**
	 * Every country that carries an active product-level rule.
	 *
	 * @return array<int, string>|WP_Error Country codes, or WP_Error on failure.
	 */
	public function get_ruled_countries(): array|WP_Error {
		return $this->guarded(
			fn (): array => $this->mapper->distinct_values( 'country_code', array( 'is_active' => 1 ) )
		);
	}
}
