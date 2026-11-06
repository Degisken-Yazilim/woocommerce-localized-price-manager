<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\CategoryPricing;

use D6N\LPM\Core\Database\AbstractRepository;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Category level per-country pricing rule repository.
 */
class CategoryPricingRepository extends AbstractRepository {

	/**
	 * Get the active rules of several terms in one query, keyed by term ID.
	 *
	 * @param array<int> $term_ids     Term IDs to look up.
	 * @param string     $country_code ISO country code.
	 * @return array<int, stdClass>|WP_Error Map of term ID to row, or WP_Error on failure.
	 */
	public function get_by_terms_and_country( array $term_ids, string $country_code ): array|WP_Error {
		$country_code = CountryCodeValidator::normalize( $country_code );
		$term_ids     = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ), static fn ( int $id ): bool => $id > 0 ) ) );

		if ( empty( $term_ids ) || '' === $country_code ) {
			return array();
		}

		return $this->guarded(
			function () use ( $term_ids, $country_code ): array {
				$results = $this->mapper->get_where(
					array(
						'country_code' => $country_code,
						'is_active'    => 1,
						array( 'term_id', 'IN', $term_ids ),
					)
				);

				$map = array();

				foreach ( $results as $row ) {
					if ( $row instanceof stdClass && isset( $row->term_id ) ) {
						$map[ (int) $row->term_id ] = $row;
					}
				}

				return $map;
			}
		);
	}

	/**
	 * Get every active rule stored for one term.
	 *
	 * @param int  $term_id     Product category term ID.
	 * @param bool $only_active Whether to restrict the result to active rules.
	 * @return array<stdClass>|WP_Error
	 */
	public function get_by_term( int $term_id, bool $only_active = true ): array|WP_Error {
		if ( $term_id <= 0 ) {
			return array();
		}

		return $this->guarded(
			function () use ( $term_id, $only_active ): array {
				$conditions = array( 'term_id' => $term_id );

				if ( $only_active ) {
					$conditions['is_active'] = 1;
				}

				return $this->mapper->get_where( $conditions );
			}
		);
	}

	/**
	 * Create or update the rule of one term and country.
	 *
	 * @param int                  $term_id      Product category term ID.
	 * @param string               $country_code ISO country code.
	 * @param array<string, mixed> $data         Column values; unknown keys are dropped by the model.
	 * @return int|WP_Error Inserted ID or affected count, or WP_Error on failure.
	 */
	public function save_rule( int $term_id, string $country_code, array $data ): int|WP_Error {
		$country_code = CountryCodeValidator::normalize( $country_code );

		if ( $term_id <= 0 || '' === $country_code ) {
			return new WP_Error(
				'lpm_invalid_category_rule',
				__( 'A term ID and a country code are required to save a category rule.', 'localized-price-manager' )
			);
		}

		$existing = $this->find_row( $term_id, $country_code );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( isset( $data['currency_code'] ) && is_string( $data['currency_code'] ) ) {
			$data['currency_code'] = strtoupper( $data['currency_code'] );
		}

		$data['term_id']      = $term_id;
		$data['country_code'] = $country_code;

		if ( $existing && isset( $existing->id ) ) {
			return $this->update_record( (int) $existing->id, $data );
		}

		return $this->create_record( $data );
	}

	/**
	 * Look up a row regardless of its active flag, for write paths.
	 *
	 * @param int    $term_id      Term ID.
	 * @param string $country_code Normalised ISO country code.
	 * @return stdClass|WP_Error|null
	 */
	private function find_row( int $term_id, string $country_code ): stdClass|WP_Error|null {
		return $this->guarded(
			function () use ( $term_id, $country_code ): ?stdClass {
				$results = $this->mapper->get_where(
					array(
						'term_id'      => $term_id,
						'country_code' => $country_code,
					)
				);

				$row = $results[0] ?? null;

				return $row instanceof stdClass ? $row : null;
			}
		);
	}
	/**
	 * Every country that carries an active category-level rule.
	 *
	 * @return array<int, string>|WP_Error Country codes, or WP_Error on failure.
	 */
	public function get_ruled_countries(): array|WP_Error {
		return $this->guarded(
			fn (): array => $this->mapper->distinct_values( 'country_code', array( 'is_active' => 1 ) )
		);
	}

	/**
	 * Every category term that carries at least one active rule.
	 *
	 * @return array<int, int>|WP_Error Term IDs, or WP_Error on failure.
	 */
	public function get_ruled_term_ids(): array|WP_Error {
		return $this->guarded(
			fn (): array => array_values(
				array_filter(
					array_map( 'intval', $this->mapper->distinct_values( 'term_id', array( 'is_active' => 1 ) ) ),
					static fn ( int $term_id ): bool => $term_id > 0
				)
			)
		);
	}
}
