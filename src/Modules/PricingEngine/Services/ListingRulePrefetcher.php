<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\Services;

use D6N\LPM\Core\Contracts\ICustomerCountryService;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use Throwable;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the pricing rules of every product in a fetched listing in one query per rule table.
 */
class ListingRulePrefetcher implements IHookable {

	/**
	 * Post type whose rows carry product pricing rules.
	 */
	private const PRODUCT_POST_TYPE = 'product';

	/**
	 * Taxonomy category rules are attached to.
	 */
	private const CATEGORY_TAXONOMY = 'product_cat';

	/**
	 * Constructor.
	 *
	 * @param PricingRuleReader       $rules           Product and category rule reader.
	 * @param ICustomerCountryService $country_service Customer country service.
	 * @param ILogger                 $logger          Logger service.
	 * @param PriceResolutionService  $resolution      Price resolution service, the reader of the resolution cache.
	 */
	public function __construct(
		private readonly PricingRuleReader $rules,
		private readonly ICustomerCountryService $country_service,
		private readonly ILogger $logger,
		private readonly PriceResolutionService $resolution
	) {}

	/**
	 * Register WordPress action & filter hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress action & filter hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'the_posts', array( $this, 'prefetch_listing' ), WooHookPriorityEnum::Standard->value, 1 );
		add_filter( 'woocommerce_shortcode_products_query_results', array( $this, 'prefetch_shortcode_results' ), WooHookPriorityEnum::Standard->value, 1 );
	}

	/**
	 * Filter callback for `the_posts`: prefetch the rules of the listed products.
	 *
	 * @param mixed $posts Posts fetched by the query.
	 * @return mixed The posts, unchanged.
	 */
	public function prefetch_listing( mixed $posts ): mixed {
		$this->prefetch_safely( is_array( $posts ) ? $this->product_ids( $posts ) : array(), false );

		return $posts;
	}

	/**
	 * Filter callback for `woocommerce_shortcode_products_query_results`: prefetch the rules of the listed products.
	 *
	 * @param mixed $results Shortcode query results carrying the product IDs in `ids`.
	 * @return mixed The results, unchanged.
	 */
	public function prefetch_shortcode_results( mixed $results ): mixed {
		$ids = is_object( $results ) && isset( $results->ids ) && is_array( $results->ids ) ? $results->ids : array();

		$this->prefetch_safely( $this->positive_ids( $ids ), true );

		return $results;
	}

	/**
	 * Prime the price cache and the pricing rules of a product list that no listing query fetched.
	 *
	 * @param array<mixed> $product_ids Raw product IDs.
	 * @return void
	 */
	public function prime( array $product_ids ): void {
		$this->prefetch_safely( $this->positive_ids( $product_ids ), true );
	}

	/**
	 * Prefetch the rules of a product list, logging any failure.
	 *
	 * @param array<int, int> $product_ids Unique positive product IDs.
	 * @param bool            $load_posts  Whether the posts of the uncached products are not loaded yet.
	 * @return void
	 */
	private function prefetch_safely( array $product_ids, bool $load_posts ): void {
		try {
			$this->prefetch( $product_ids, $load_posts );
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Pricing rule prefetch failed: %s', $e->getMessage() ),
				array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				),
				'PricingEngine'
			);
		}
	}

	/**
	 * Prefetch the product rules of the uncached simple products, then the category rules of those that carry none.
	 *
	 * @param array<int, int> $product_ids Unique positive product IDs.
	 * @param bool            $load_posts  Whether the posts of the uncached products are not loaded yet.
	 * @return void
	 */
	private function prefetch( array $product_ids, bool $load_posts ): void {
		if ( empty( $product_ids ) ) {
			return;
		}

		if ( ! Core::is_active() || ! $this->rules->is_memo_enabled() ) {
			return;
		}

		$country_code = CountryCodeValidator::sanitize( (string) $this->country_service->get_current_user_country() );

		if ( '' === $country_code ) {
			return;
		}

		$product_ids = $this->uncached( $product_ids, $country_code );

		if ( empty( $product_ids ) ) {
			return;
		}

		if ( $load_posts ) {
			_prime_post_caches( $product_ids, false, false );
		}

		update_object_term_cache( $product_ids, self::PRODUCT_POST_TYPE );

		$product_ids = $this->simple_products( $product_ids );

		if ( empty( $product_ids ) ) {
			return;
		}

		$unruled = $this->rules->prefetch_product_rules( $product_ids, $country_code );

		if ( $unruled instanceof WP_Error ) {
			$this->warn( 'Product pricing rule prefetch failed.', $unruled );
			return;
		}

		$term_ids = $this->term_ids( $unruled );

		if ( empty( $term_ids ) ) {
			return;
		}

		$error = $this->rules->prefetch_category_rules( $term_ids, $country_code );

		if ( null !== $error ) {
			$this->warn( 'Category pricing rule prefetch failed.', $error );
		}
	}

	/**
	 * Get the products whose active price resolution is not cached for a country.
	 *
	 * @param array<int, int> $product_ids  Product IDs.
	 * @param string          $country_code ISO country code.
	 * @return array<int, int> Product IDs that still need their rules.
	 */
	private function uncached( array $product_ids, string $country_code ): array {
		$cached = $this->resolution->get_cached_resolutions( $product_ids, $country_code );

		return array_values(
			array_filter(
				$product_ids,
				static fn ( int $product_id ): bool => null === ( $cached[ $product_id ][ PriceResolutionService::PRICE_TYPE_ACTIVE ] ?? null )
			)
		);
	}

	/**
	 * Get the simple products of a list whose term cache is primed.
	 *
	 * @param array<int, int> $product_ids Product IDs.
	 * @return array<int, int> Product IDs of the simple products.
	 */
	private function simple_products( array $product_ids ): array {
		return array_values(
			array_filter(
				$product_ids,
				fn ( int $product_id ): bool => WarmUpProductSelector::SIMPLE_TYPE === $this->product_type( $product_id )
			)
		);
	}

	/**
	 * Get the type of one product from its product type term.
	 *
	 * @param int $product_id Product ID.
	 * @return string Type slug, simple when the product carries no type term.
	 */
	private function product_type( int $product_id ): string {
		$terms = get_the_terms( $product_id, WarmUpProductSelector::TYPE_TAXONOMY );

		if ( ! is_array( $terms ) || array() === $terms ) {
			return WarmUpProductSelector::SIMPLE_TYPE;
		}

		return (string) reset( $terms )->slug;
	}

	/**
	 * Get the unique positive IDs of a raw ID list.
	 *
	 * @param array<mixed> $ids Raw IDs.
	 * @return array<int, int> Unique positive IDs.
	 */
	private function positive_ids( array $ids ): array {
		$unique = array();

		foreach ( $ids as $id ) {
			$id = is_numeric( $id ) ? (int) $id : 0;

			if ( $id > 0 ) {
				$unique[ $id ] = $id;
			}
		}

		return array_values( $unique );
	}

	/**
	 * Get the IDs of the product posts in a post list.
	 *
	 * @param array<mixed> $posts Posts fetched by the query.
	 * @return array<int, int> Unique positive product IDs.
	 */
	private function product_ids( array $posts ): array {
		$ids = array();

		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post && self::PRODUCT_POST_TYPE === $post->post_type && $post->ID > 0 ) {
				$ids[ $post->ID ] = $post->ID;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Get the category terms of several products together with their ancestors.
	 *
	 * @param array<int, int> $product_ids Product IDs whose term cache is primed.
	 * @return array<int, int> Unique positive term IDs.
	 */
	private function term_ids( array $product_ids ): array {
		if ( empty( $product_ids ) ) {
			return array();
		}

		$term_ids = array();

		foreach ( $product_ids as $product_id ) {
			$terms = get_the_terms( $product_id, self::CATEGORY_TAXONOMY );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$term_id = (int) $term->term_id;

				if ( $term_id <= 0 || isset( $term_ids[ $term_id ] ) ) {
					continue;
				}

				$term_ids[ $term_id ] = $term_id;

				foreach ( (array) get_ancestors( $term_id, self::CATEGORY_TAXONOMY, 'taxonomy' ) as $ancestor ) {
					$ancestor = (int) $ancestor;

					if ( $ancestor > 0 ) {
						$term_ids[ $ancestor ] = $ancestor;
					}
				}
			}
		}

		return array_values( $term_ids );
	}

	/**
	 * Log a failed prefetch query.
	 *
	 * @param string   $message Context message.
	 * @param WP_Error $error   Repository error.
	 * @return void
	 */
	private function warn( string $message, WP_Error $error ): void {
		$this->logger->warning( $message, array( 'error' => $error->get_error_message() ), 'PricingEngine' );
	}
}
