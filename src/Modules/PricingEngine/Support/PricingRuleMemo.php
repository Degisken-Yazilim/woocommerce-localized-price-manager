<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\Support;

use stdClass;

defined( 'ABSPATH' ) || exit;

/**
 * Request-scoped memo of the product and category rule rows read for each country.
 */
class PricingRuleMemo {

	/**
	 * Most entries one rule table keeps before the memo starts over.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 10000;

	/**
	 * Number of product rule entries held.
	 *
	 * @var int
	 */
	private int $product_entries = 0;

	/**
	 * Number of category rule entries held.
	 *
	 * @var int
	 */
	private int $term_entries = 0;

	/**
	 * Product rule rows keyed by country code, then product ID; null records "no rule".
	 *
	 * @var array<string, array<int, stdClass|null>>
	 */
	private array $product_rules = array();

	/**
	 * Category rule rows keyed by country code, then term ID; null records "no rule".
	 *
	 * @var array<string, array<int, stdClass|null>>
	 */
	private array $term_rules = array();

	/**
	 * Whether the product rule of one product and country is known.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $country_code ISO country code.
	 * @return bool
	 */
	public function has_product_rule( int $product_id, string $country_code ): bool {
		return array_key_exists( $product_id, $this->product_rules[ $country_code ] ?? array() );
	}

	/**
	 * Get the known product rule of one product and country.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $country_code ISO country code.
	 * @return stdClass|null Rule row, or null when there is none or it is not known.
	 */
	public function get_product_rule( int $product_id, string $country_code ): ?stdClass {
		return $this->product_rules[ $country_code ][ $product_id ] ?? null;
	}

	/**
	 * Record the product rule of one product and country.
	 *
	 * @param int           $product_id   Product ID.
	 * @param string        $country_code ISO country code.
	 * @param stdClass|null $rule         Rule row, or null when the product has none.
	 * @return void
	 */
	public function remember_product_rule( int $product_id, string $country_code, ?stdClass $rule ): void {
		if ( ! $this->has_product_rule( $product_id, $country_code ) ) {
			if ( $this->product_entries >= self::MAX_ENTRIES ) {
				$this->product_rules   = array();
				$this->product_entries = 0;
			}

			++$this->product_entries;
		}

		$this->product_rules[ $country_code ][ $product_id ] = $rule;
	}

	/**
	 * Record the product rules of several products, marking every product without a row as "no rule".
	 *
	 * @param array<int, int>   $product_ids  Product IDs that were looked up.
	 * @param string            $country_code ISO country code.
	 * @param array<int, mixed> $rules        Rows found, keyed by product ID.
	 * @return void
	 */
	public function remember_product_rules( array $product_ids, string $country_code, array $rules ): void {
		foreach ( $product_ids as $product_id ) {
			$rule = $rules[ $product_id ] ?? null;

			$this->remember_product_rule( $product_id, $country_code, $rule instanceof stdClass ? $rule : null );
		}
	}

	/**
	 * Get the products whose rule for a country is not known yet.
	 *
	 * @param array<int, int> $product_ids  Product IDs.
	 * @param string          $country_code ISO country code.
	 * @return array<int, int>
	 */
	public function unknown_products( array $product_ids, string $country_code ): array {
		return array_values(
			array_filter(
				$product_ids,
				fn ( int $product_id ): bool => ! $this->has_product_rule( $product_id, $country_code )
			)
		);
	}

	/**
	 * Get the known category rule of one term and country.
	 *
	 * @param int    $term_id      Product category term ID.
	 * @param string $country_code ISO country code.
	 * @return stdClass|null Rule row, or null when there is none or it is not known.
	 */
	public function get_term_rule( int $term_id, string $country_code ): ?stdClass {
		return $this->term_rules[ $country_code ][ $term_id ] ?? null;
	}

	/**
	 * Record the category rules of several terms, marking every term without a row as "no rule".
	 *
	 * @param array<int, int>   $term_ids     Term IDs that were looked up.
	 * @param string            $country_code ISO country code.
	 * @param array<int, mixed> $rules        Rows found, keyed by term ID.
	 * @return void
	 */
	public function remember_term_rules( array $term_ids, string $country_code, array $rules ): void {
		foreach ( $term_ids as $term_id ) {
			$rule = $rules[ $term_id ] ?? null;

			if ( ! array_key_exists( $term_id, $this->term_rules[ $country_code ] ?? array() ) ) {
				if ( $this->term_entries >= self::MAX_ENTRIES ) {
					$this->term_rules   = array();
					$this->term_entries = 0;
				}

				++$this->term_entries;
			}

			$this->term_rules[ $country_code ][ $term_id ] = $rule instanceof stdClass ? $rule : null;
		}
	}

	/**
	 * Get the terms whose rule for a country is not known yet.
	 *
	 * @param array<int, int> $term_ids     Term IDs.
	 * @param string          $country_code ISO country code.
	 * @return array<int, int>
	 */
	public function unknown_terms( array $term_ids, string $country_code ): array {
		$known = $this->term_rules[ $country_code ] ?? array();

		return array_values(
			array_filter(
				$term_ids,
				static fn ( int $term_id ): bool => ! array_key_exists( $term_id, $known )
			)
		);
	}
}
