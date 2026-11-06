<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\Services;

use D6N\LPM\Core\Shared\RequestContext;
use D6N\LPM\Modules\PricingEngine\CategoryPricing\CategoryPricingRepository;
use D6N\LPM\Modules\PricingEngine\CountryPricing\CountryPricingRepository;
use D6N\LPM\Modules\PricingEngine\Support\PricingRuleMemo;
use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the product and category rules price resolution needs, through a request-scoped memo.
 */
class PricingRuleReader {

	/**
	 * Constructor.
	 *
	 * @param CountryPricingRepository  $country_pricing  Product level rule repository.
	 * @param CategoryPricingRepository $category_pricing Category level rule repository.
	 * @param PricingRuleMemo           $memo             Request-scoped rule memo.
	 */
	public function __construct(
		private readonly CountryPricingRepository $country_pricing,
		private readonly CategoryPricingRepository $category_pricing,
		private readonly PricingRuleMemo $memo
	) {}

	/**
	 * Whether rule rows may be memoised and prefetched in this request.
	 *
	 * @return bool False in wp-admin and in a REST request outside the Store API.
	 */
	public function is_memo_enabled(): bool {
		return ! RequestContext::is_wp_admin() && ! RequestContext::is_foreign_rest_request();
	}

	/**
	 * Get the active product rule of one product and country.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $country_code ISO country code.
	 * @return stdClass|WP_Error|null Row, null when absent, or WP_Error on failure.
	 */
	public function get_product_rule( int $product_id, string $country_code ): stdClass|WP_Error|null {
		if ( ! $this->is_memo_enabled() ) {
			return $this->country_pricing->get_by_product_and_country( $product_id, $country_code );
		}

		if ( $this->memo->has_product_rule( $product_id, $country_code ) ) {
			return $this->memo->get_product_rule( $product_id, $country_code );
		}

		$row = $this->country_pricing->get_by_product_and_country( $product_id, $country_code );

		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$rule = $row instanceof stdClass ? $row : null;

		$this->memo->remember_product_rule( $product_id, $country_code, $rule );

		return $rule;
	}

	/**
	 * Get the active category rules of several terms for one country, keyed by term ID.
	 *
	 * @param array<int, int> $term_ids     Term IDs.
	 * @param string          $country_code ISO country code.
	 * @return array<int, stdClass>|WP_Error Map of term ID to row, or WP_Error on failure.
	 */
	public function get_category_rules( array $term_ids, string $country_code ): array|WP_Error {
		if ( ! $this->is_memo_enabled() ) {
			return $this->category_pricing->get_by_terms_and_country( $term_ids, $country_code );
		}

		$unknown = $this->memo->unknown_terms( $term_ids, $country_code );
		$map     = array();

		foreach ( $term_ids as $term_id ) {
			$rule = $this->memo->get_term_rule( $term_id, $country_code );

			if ( null !== $rule ) {
				$map[ $term_id ] = $rule;
			}
		}

		if ( empty( $unknown ) ) {
			return $map;
		}

		$rules = $this->category_pricing->get_by_terms_and_country( $unknown, $country_code );

		if ( is_wp_error( $rules ) ) {
			return $rules;
		}

		$this->memo->remember_term_rules( $unknown, $country_code, $rules );

		foreach ( $unknown as $term_id ) {
			$rule = $rules[ $term_id ] ?? null;

			if ( $rule instanceof stdClass ) {
				$map[ $term_id ] = $rule;
			}
		}

		return $map;
	}

	/**
	 * Load the product rules of several products for one country in a single query.
	 *
	 * @param array<int, int> $product_ids  Product IDs.
	 * @param string          $country_code ISO country code.
	 * @return array<int, int>|WP_Error IDs of the memoised products that carry no product rule, or WP_Error on failure.
	 */
	public function prefetch_product_rules( array $product_ids, string $country_code ): array|WP_Error {
		if ( ! $this->is_memo_enabled() ) {
			return array();
		}

		$unknown = $this->memo->unknown_products( $product_ids, $country_code );

		if ( ! empty( $unknown ) ) {
			$rules = $this->country_pricing->get_by_products_and_country( $unknown, $country_code );

			if ( is_wp_error( $rules ) ) {
				return $rules;
			}

			$this->memo->remember_product_rules( $unknown, $country_code, $rules );
		}

		return array_values(
			array_filter(
				$product_ids,
				fn ( int $product_id ): bool => null === $this->memo->get_product_rule( $product_id, $country_code )
			)
		);
	}

	/**
	 * Load the category rules of several terms for one country in a single query.
	 *
	 * @param array<int, int> $term_ids     Term IDs.
	 * @param string          $country_code ISO country code.
	 * @return WP_Error|null WP_Error on failure, null otherwise.
	 */
	public function prefetch_category_rules( array $term_ids, string $country_code ): ?WP_Error {
		if ( ! $this->is_memo_enabled() ) {
			return null;
		}

		$rules = $this->get_category_rules( $term_ids, $country_code );

		return is_wp_error( $rules ) ? $rules : null;
	}
}
