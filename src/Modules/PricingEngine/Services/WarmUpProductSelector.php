<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\Services;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\TablesEnum;
use D6N\LPM\Modules\PricingEngine\CategoryPricing\CategoryPricingRepository;
use WP_Error;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Selects the published simple products the cache warm-up covers.
 */
class WarmUpProductSelector {

	/**
	 * Database handle.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Post type of a product.
	 */
	private const PRODUCT_POST_TYPE = 'product';

	/**
	 * Status of a product that is on sale to shoppers.
	 */
	private const PUBLISHED = 'publish';

	/**
	 * Taxonomy holding the product type.
	 */
	public const TYPE_TAXONOMY = 'product_type';

	/**
	 * The one product type the plugin prices.
	 */
	public const SIMPLE_TYPE = 'simple';

	/**
	 * Taxonomy category rules are attached to.
	 */
	private const CATEGORY_TAXONOMY = 'product_cat';

	/**
	 * Constructor.
	 *
	 * @param CategoryPricingRepository $category_pricing Category level rule repository.
	 * @param ILogger                   $logger           Logger service.
	 * @param wpdb|null                 $_wpdb            Database handle; the global one when omitted.
	 */
	public function __construct(
		private readonly CategoryPricingRepository $category_pricing,
		private readonly ILogger $logger,
		?wpdb $_wpdb = null
	) {
		global $wpdb;

		$this->wpdb = $_wpdb ?? $wpdb;
	}

	/**
	 * Select ruled products first, then any other, each part in popularity order.
	 *
	 * @param int $limit Maximum number of products.
	 * @return array<int, int> Product IDs.
	 */
	public function select_ruled_first( int $limit ): array {
		if ( $limit <= 0 ) {
			return array();
		}

		$term_ids = $this->ruled_category_tree();
		$db       = $this->wpdb;

		if ( array() === $term_ids ) {
			$ids = $db->get_col(
				$db->prepare(
					'SELECT p.ID
					FROM %i p
					INNER JOIN %i type_rel ON type_rel.object_id = p.ID
					INNER JOIN %i type_tax ON type_tax.term_taxonomy_id = type_rel.term_taxonomy_id AND type_tax.taxonomy = %s
					INNER JOIN %i type_term ON type_term.term_id = type_tax.term_id AND type_term.slug = %s
					LEFT JOIN %i lookup ON lookup.product_id = p.ID
					LEFT JOIN ( SELECT DISTINCT rule.product_id FROM %i rule WHERE rule.is_active = 1 ) ruled ON ruled.product_id = p.ID
					WHERE p.post_type = %s AND p.post_status = %s
					ORDER BY ruled.product_id IS NULL ASC, lookup.total_sales DESC, p.ID DESC
					LIMIT %d',
					$db->posts,
					$db->term_relationships,
					$db->term_taxonomy,
					self::TYPE_TAXONOMY,
					$db->terms,
					self::SIMPLE_TYPE,
					$this->lookup_table(),
					TablesEnum::CountryPricing->table_name(),
					self::PRODUCT_POST_TYPE,
					self::PUBLISHED,
					$limit
				)
			);

			return $this->ids_of( $ids, 'ruled-first' );
		}

		$ids = $db->get_col(
			$db->prepare(
				'SELECT p.ID
				FROM %i p
				INNER JOIN %i type_rel ON type_rel.object_id = p.ID
				INNER JOIN %i type_tax ON type_tax.term_taxonomy_id = type_rel.term_taxonomy_id AND type_tax.taxonomy = %s
				INNER JOIN %i type_term ON type_term.term_id = type_tax.term_id AND type_term.slug = %s
				LEFT JOIN %i lookup ON lookup.product_id = p.ID
				LEFT JOIN (
					SELECT rule.product_id FROM %i rule WHERE rule.is_active = 1
					UNION
					SELECT cat_rel.object_id FROM %i cat_rel
					INNER JOIN %i cat_tax ON cat_tax.term_taxonomy_id = cat_rel.term_taxonomy_id
					WHERE cat_tax.taxonomy = %s AND cat_tax.term_id IN (' . implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) ) . ')
				) ruled ON ruled.product_id = p.ID
				WHERE p.post_type = %s AND p.post_status = %s
				ORDER BY ruled.product_id IS NULL ASC, lookup.total_sales DESC, p.ID DESC
				LIMIT %d',
				$db->posts,
				$db->term_relationships,
				$db->term_taxonomy,
				self::TYPE_TAXONOMY,
				$db->terms,
				self::SIMPLE_TYPE,
				$this->lookup_table(),
				TablesEnum::CountryPricing->table_name(),
				$db->term_relationships,
				$db->term_taxonomy,
				self::CATEGORY_TAXONOMY,
				...array_values( $term_ids ),
				...array( self::PRODUCT_POST_TYPE, self::PUBLISHED, $limit )
			)
		);

		return $this->ids_of( $ids, 'ruled-first' );
	}

	/**
	 * Select the products of a category and its descendants, in ID order after a cursor.
	 *
	 * @param int $term_id  Product category term ID.
	 * @param int $after_id Last product ID the previous page returned, 0 for the first page.
	 * @param int $limit    Maximum number of products.
	 * @return array<int, int> Product IDs, ascending.
	 */
	public function select_in_category( int $term_id, int $after_id, int $limit ): array {
		if ( $term_id <= 0 || $limit <= 0 ) {
			return array();
		}

		$term_ids = $this->with_descendants( array( $term_id ) );
		$db       = $this->wpdb;

		$ids = $db->get_col(
			$db->prepare(
				'SELECT DISTINCT p.ID
				FROM %i p
				INNER JOIN %i type_rel ON type_rel.object_id = p.ID
				INNER JOIN %i type_tax ON type_tax.term_taxonomy_id = type_rel.term_taxonomy_id AND type_tax.taxonomy = %s
				INNER JOIN %i type_term ON type_term.term_id = type_tax.term_id AND type_term.slug = %s
				INNER JOIN %i cat_rel ON cat_rel.object_id = p.ID
				INNER JOIN %i cat_tax ON cat_tax.term_taxonomy_id = cat_rel.term_taxonomy_id AND cat_tax.taxonomy = %s
				WHERE cat_tax.term_id IN (' . implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) ) . ')
				AND p.post_type = %s AND p.post_status = %s AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT %d',
				$db->posts,
				$db->term_relationships,
				$db->term_taxonomy,
				self::TYPE_TAXONOMY,
				$db->terms,
				self::SIMPLE_TYPE,
				$db->term_relationships,
				$db->term_taxonomy,
				self::CATEGORY_TAXONOMY,
				...array_values( $term_ids ),
				...array( self::PRODUCT_POST_TYPE, self::PUBLISHED, max( 0, $after_id ), $limit )
			)
		);

		return $this->ids_of( $ids, 'category' );
	}

	/**
	 * Get every category carrying an active rule, together with its descendants.
	 *
	 * @return array<int, int> Unique positive term IDs.
	 */
	private function ruled_category_tree(): array {
		$ruled = $this->category_pricing->get_ruled_term_ids();

		if ( $ruled instanceof WP_Error ) {
			$this->logger->warning( 'Ruled category lookup failed.', array( 'error' => $ruled->get_error_message() ), 'PricingEngine' );

			return array();
		}

		return $this->with_descendants( $ruled );
	}

	/**
	 * Expand a term list with every descendant category.
	 *
	 * @param array<int, int> $term_ids Term IDs.
	 * @return array<int, int> Unique positive term IDs.
	 */
	private function with_descendants( array $term_ids ): array {
		$tree = array();

		foreach ( $term_ids as $term_id ) {
			$term_id = (int) $term_id;

			if ( $term_id <= 0 ) {
				continue;
			}

			$tree[ $term_id ] = $term_id;

			$children = get_term_children( $term_id, self::CATEGORY_TAXONOMY );

			if ( ! is_array( $children ) ) {
				continue;
			}

			foreach ( $children as $child ) {
				$child = (int) $child;

				if ( $child > 0 ) {
					$tree[ $child ] = $child;
				}
			}
		}

		return array_values( $tree );
	}

	/**
	 * Turn a column result into product IDs, logging a failed query.
	 *
	 * @param array<int, mixed> $ids       Column values.
	 * @param string            $selection Selection name, for the log.
	 * @return array<int, int> Product IDs.
	 */
	private function ids_of( array $ids, string $selection ): array {
		$db = $this->wpdb;

		if ( '' !== $db->last_error ) {
			$this->logger->warning(
				'Cache warm-up product selection failed.',
				array(
					'selection' => $selection,
					'error'     => $db->last_error,
				),
				'PricingEngine'
			);

			return array();
		}

		return array_values( array_filter( array_map( 'intval', $ids ), static fn ( int $id ): bool => $id > 0 ) );
	}

	/**
	 * Get the WooCommerce product lookup table name.
	 *
	 * @return string
	 */
	private function lookup_table(): string {
		return $this->wpdb->prefix . 'wc_product_meta_lookup';
	}
}
