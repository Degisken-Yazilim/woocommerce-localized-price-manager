<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cache\Services;

use Closure;
use D6N\LPM\Core\Contracts\IActionQueue;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Shared\ActionQueue;
use D6N\LPM\Core\Shared\Core;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-computes resolved prices so shoppers never pay the cold-cache cost.
 */
class CacheWarmUpService implements IHookable {

	/**
	 * Retired recurring cron hook suffix, still cleared on deactivation.
	 */
	public const LEGACY_CRON_HOOK = 'cache_warmup';

	/**
	 * Action Scheduler hook suffix of one warm-up batch.
	 */
	public const BATCH_HOOK = 'cache_warm_up_batch';

	/**
	 * Action Scheduler hook suffix of one category warm-up batch.
	 */
	public const CATEGORY_BATCH_HOOK = 'cache_warm_up_category_batch';

	/**
	 * Action Scheduler group of the warm-up batches.
	 */
	public const ACTION_GROUP = 'localized-price-manager';

	/**
	 * Products handled per batch.
	 */
	public const BATCH_SIZE = 100;

	/**
	 * Lower bound of the proportional popular-product warm-up limit.
	 */
	private const MIN_WARMUP_LIMIT = 300;

	/**
	 * Upper bound of the proportional popular-product warm-up limit.
	 */
	private const MAX_WARMUP_LIMIT = 3000;

	/**
	 * Share of the catalogue considered "popular".
	 */
	private const WARMUP_RATIO = 0.3;

	/**
	 * Price types warmed for every product and country.
	 *
	 * @var array<int, string>
	 */
	private const PRICE_TYPES = array( 'price', 'regular', 'sale' );

	/**
	 * Memoized active country codes for the duration of one run.
	 *
	 * @var array<string>|null
	 */
	private ?array $country_codes = null;

	/**
	 * Whether the missing-queue warning has been logged in this request.
	 *
	 * @var bool
	 */
	private bool $queue_warning_logged = false;

	/**
	 * Constructor.
	 *
	 * @param PriceCacheService $price_cache            Resolved-price cache key owner.
	 * @param ILogger           $logger                 Logger instance.
	 * @param Closure|null      $price_resolver         Closure resolving one product price for one country.
	 * @param Closure|null      $country_provider       Closure returning active ISO country codes.
	 * @param Closure|null      $ruled_country_provider Closure returning the countries an operator has priced.
	 * @param Closure|null      $persistent_cache_probe Closure returning whether the cache driver is persistent memory.
	 * @param IActionQueue|null $queue                  Background action queue.
	 * @param Closure|null      $product_selector       Closure selecting the products a run covers: ( int $limit ): array<int>.
	 * @param Closure|null      $category_selector      Closure paging a category's products: ( int $term_id, int $after_id, int $limit ): array<int>.
	 */
	public function __construct(
		private readonly PriceCacheService $price_cache,
		private readonly ILogger $logger,
		private ?Closure $price_resolver = null,
		private ?Closure $country_provider = null,
		private ?Closure $ruled_country_provider = null,
		private readonly ?Closure $persistent_cache_probe = null,
		private ?IActionQueue $queue = null,
		private ?Closure $product_selector = null,
		private ?Closure $category_selector = null
	) {}

	/**
	 * Inject the closure selecting the products a run covers.
	 *
	 * @param Closure $product_selector Selector: ( int $limit ): array<int>.
	 * @return void
	 */
	public function set_product_selector( Closure $product_selector ): void {
		$this->product_selector = $product_selector;
	}

	/**
	 * Inject the closure paging the products of a category.
	 *
	 * @param Closure $category_selector Selector: ( int $term_id, int $after_id, int $limit ): array<int>.
	 * @return void
	 */
	public function set_category_selector( Closure $category_selector ): void {
		$this->category_selector = $category_selector;
	}

	/**
	 * Inject the price resolution closure after construction.
	 *
	 * @param Closure $price_resolver Resolver closure.
	 * @return void
	 */
	public function set_price_resolver( Closure $price_resolver ): void {
		$this->price_resolver = $price_resolver;
	}

	/**
	 * Inject the active country provider closure after construction.
	 *
	 * @param Closure $country_provider Closure returning active ISO country codes.
	 * @return void
	 */
	public function set_country_provider( Closure $country_provider ): void {
		$this->country_provider = $country_provider;
		$this->country_codes    = null;
	}

	/**
	 * Inject the provider of countries an operator has actually priced.
	 *
	 * @param Closure $ruled_country_provider Closure returning ISO country codes.
	 * @return void
	 */
	public function set_ruled_country_provider( Closure $ruled_country_provider ): void {
		$this->ruled_country_provider = $ruled_country_provider;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( Core::build_hook( HookEnum::ExchangeRatesRefreshed->value ), array( $this, 'start_scheduled_warm_up' ), 10, 0 );
		add_action( $this->get_batch_hook(), array( $this, 'run_scheduled_batch' ), 10, 2 );
		add_action( Core::build_hook( HookEnum::ProductRulesSaved->value ), array( $this, 'warm_saved_product' ), 10, 1 );
		add_action( Core::build_hook( HookEnum::CategoryRulesSaved->value ), array( $this, 'start_category_warm_up' ), 10, 1 );
		add_action( $this->get_category_batch_hook(), array( $this, 'run_category_batch' ), 10, 2 );
	}

	/**
	 * Get the fully qualified Action Scheduler hook of one category batch.
	 *
	 * @return string
	 */
	public function get_category_batch_hook(): string {
		return Core::build_hook( self::CATEGORY_BATCH_HOOK );
	}

	/**
	 * Warm one product whose rules were just saved, in the saving request.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function warm_saved_product( int $product_id ): void {
		if ( $product_id <= 0 || ! $this->is_scheduled_run_allowed() || ! $this->can_warm_up() ) {
			return;
		}

		$this->warm_products( array( $product_id ), $this->get_ruled_country_codes() );
	}

	/**
	 * Queue the warm-up of a category whose rules were just saved.
	 *
	 * @param int $term_id Product category term ID.
	 * @return void
	 */
	public function start_category_warm_up( int $term_id ): void {
		if ( $term_id <= 0 || ! $this->is_scheduled_run_allowed() || null === $this->category_selector ) {
			return;
		}

		$queue = $this->get_queue();

		if ( ! $queue->is_available() ) {
			$this->warn_queue_unavailable();

			return;
		}

		$queue->enqueue( $this->get_category_batch_hook(), array( $term_id, 0 ), self::ACTION_GROUP );
	}

	/**
	 * Action Scheduler callback: warm the next page of a category's products, then queue the page after it.
	 *
	 * @param int $term_id  Product category term ID.
	 * @param int $after_id Last product ID the previous page warmed, 0 for the first page.
	 * @return void
	 */
	public function run_category_batch( int $term_id, int $after_id ): void {
		if ( ! $this->is_scheduled_run_allowed() || ! $this->can_warm_up() || null === $this->category_selector ) {
			return;
		}

		$countries = $this->get_ruled_country_codes();

		if ( array() === $countries ) {
			return;
		}

		try {
			$selected = ( $this->category_selector )( $term_id, $after_id, self::BATCH_SIZE );
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Category cache warm up could not select its products: ' . $e->getMessage(), array( 'term_id' => $term_id ), 'Cache' );

			return;
		}

		$product_ids = is_array( $selected ) ? array_values( array_map( 'intval', $selected ) ) : array();

		if ( array() === $product_ids ) {
			return;
		}

		if ( count( $product_ids ) >= self::BATCH_SIZE ) {
			$this->enqueue_next_category_batch( $term_id, max( $product_ids ) );
		}

		$warmed = $this->warm_products( $product_ids, $countries );

		$this->logger->info(
			sprintf( 'Category cache warm up batch done: %d of %d products warmed.', $warmed, count( $product_ids ) ),
			array( 'term_id' => $term_id ),
			'Cache'
		);
	}

	/**
	 * Enqueue the category batch that follows the current one.
	 *
	 * @param int $term_id  Product category term ID.
	 * @param int $after_id Last product ID of the current batch.
	 * @return void
	 */
	private function enqueue_next_category_batch( int $term_id, int $after_id ): void {
		$queue = $this->get_queue();

		if ( ! $queue->is_available() ) {
			$this->warn_queue_unavailable();

			return;
		}

		$queue->enqueue( $this->get_category_batch_hook(), array( $term_id, $after_id ), self::ACTION_GROUP );
	}

	/**
	 * Get the fully qualified Action Scheduler hook of one batch.
	 *
	 * @return string
	 */
	public function get_batch_hook(): string {
		return Core::build_hook( self::BATCH_HOOK );
	}

	/**
	 * Start a new batch chain, superseding any chain still queued.
	 *
	 * @return void
	 */
	public function start_scheduled_warm_up(): void {
		if ( ! $this->is_scheduled_run_allowed() || ! $this->can_warm_up() ) {
			return;
		}

		$queue = $this->get_queue();

		if ( ! $queue->is_available() ) {
			$this->warn_queue_unavailable();

			return;
		}

		if ( array() === $this->get_ruled_country_codes() ) {
			$this->logger->info( 'Scheduled cache warm up skipped: no ruled country carries an active currency mapping.', array(), 'Cache' );

			return;
		}

		$product_ids = $this->get_popular_product_ids();

		if ( array() === $product_ids ) {
			return;
		}

		$run_id = wp_generate_uuid4();

		update_option( OptionsEnum::CacheWarmUpRun->value, $run_id, false );
		update_option( OptionsEnum::CacheWarmUpProducts->value, $product_ids, false );
		$queue->cancel_all( $this->get_batch_hook() );

		$action_id = $queue->enqueue( $this->get_batch_hook(), array( $run_id, 0 ), self::ACTION_GROUP );

		$this->logger->info( 'Scheduled cache warm up started.', array( 'action_id' => $action_id ), 'Cache' );
	}

	/**
	 * Action Scheduler callback: warm one batch of the run's product list, then queue the next.
	 *
	 * @param string $run_id Chain identifier.
	 * @param int    $offset Position of the batch in the run's product list.
	 * @return void
	 */
	public function run_scheduled_batch( string $run_id, int $offset ): void {
		if ( ! $this->is_scheduled_run_allowed() ) {
			return;
		}

		if ( get_option( OptionsEnum::CacheWarmUpRun->value, '' ) !== $run_id ) {
			$this->logger->debug( 'Cache warm up batch skipped: a newer run superseded it.', array( 'offset' => $offset ), 'Cache' );

			return;
		}

		if ( ! $this->can_warm_up() ) {
			return;
		}

		$offset      = max( 0, $offset );
		$stored      = get_option( OptionsEnum::CacheWarmUpProducts->value, array() );
		$all_ids     = is_array( $stored ) ? array_values( array_map( 'intval', $stored ) ) : array();
		$product_ids = array_slice( $all_ids, $offset, self::BATCH_SIZE );
		$countries   = $this->get_ruled_country_codes();

		if ( array() === $product_ids || array() === $countries ) {
			$this->end_run();

			return;
		}

		$has_next = $offset + self::BATCH_SIZE < count( $all_ids );

		if ( $has_next ) {
			$this->enqueue_next_batch( $run_id, $offset + self::BATCH_SIZE );
		}

		$warmed = $this->warm_products( $product_ids, $countries );

		$this->logger->info(
			sprintf( 'Cache warm up batch done: %d of %d products warmed.', $warmed, count( $product_ids ) ),
			array(
				'offset' => $offset,
				'total'  => count( $all_ids ),
			),
			'Cache'
		);

		if ( ! $has_next ) {
			$this->end_run();
		}
	}

	/**
	 * Get the product IDs a warm-up covers: ruled products first, then the rest, each in popularity order.
	 *
	 * @return array<int> Product IDs.
	 */
	public function get_popular_product_ids(): array {
		$total = $this->get_total_product_count();

		if ( 0 === $total || null === $this->product_selector ) {
			return array();
		}

		try {
			$selected = ( $this->product_selector )( $this->calculate_warm_up_limit( $total ) );
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Cache warm up could not select its products: ' . $e->getMessage(), array(), 'Cache' );

			return array();
		}

		return is_array( $selected ) ? array_values( array_map( 'intval', $selected ) ) : array();
	}

	/**
	 * Warm a batch of products for every ruled country on a persistent cache.
	 *
	 * @param array<int> $product_ids Product IDs.
	 * @return int Number of products warmed.
	 */
	public function warm_up_products( array $product_ids ): int {
		if ( ! $this->has_persistent_cache() || ! $this->can_warm_up() ) {
			return 0;
		}

		return $this->warm_products( $product_ids, $this->get_ruled_country_codes() );
	}

	/**
	 * Forget the finished run.
	 *
	 * @return void
	 */
	private function end_run(): void {
		delete_option( OptionsEnum::CacheWarmUpRun->value );
		delete_option( OptionsEnum::CacheWarmUpProducts->value );
	}

	/**
	 * Warm a list of products for a list of countries, writing the whole batch at once.
	 *
	 * @param array<int>    $product_ids Product IDs.
	 * @param array<string> $countries   ISO country codes.
	 * @return int Number of products warmed.
	 */
	private function warm_products( array $product_ids, array $countries ): int {
		$items_by_product = array();

		foreach ( $product_ids as $product_id ) {
			$items = $this->collect_items( $product_id, $countries );

			if ( array() !== $items ) {
				$items_by_product[ $product_id ] = $items;
			}
		}

		if ( array() === $items_by_product ) {
			return 0;
		}

		return count( array_filter( $this->price_cache->set_multiple( $items_by_product ) ) );
	}

	/**
	 * Resolve the entries of one product for a list of countries.
	 *
	 * @param int           $product_id Product ID.
	 * @param array<string> $countries  ISO country codes.
	 * @return array<int, array<string, mixed>> Price rows; empty when the product has none or could not be resolved.
	 */
	private function collect_items( int $product_id, array $countries ): array {
		if ( $product_id <= 0 || null === $this->price_resolver || array() === $countries ) {
			return array();
		}

		$items = array();

		try {
			foreach ( $countries as $country_code ) {
				foreach ( self::PRICE_TYPES as $price_type ) {
					$resolved = ( $this->price_resolver )( $product_id, $price_type, $country_code );

					if ( null === $resolved ) {
						continue;
					}

					$items[] = array(
						'country_code' => $country_code,
						'price_type'   => $price_type,
						'value'        => $resolved,
					);
				}
			}
		} catch ( Throwable $e ) {
			$this->logger->warning(
				sprintf( 'Cache warm up failed for product %d: %s', $product_id, $e->getMessage() ),
				array(),
				'Cache'
			);

			return array();
		}

		return $items;
	}

	/**
	 * Enqueue the batch that follows the current one.
	 *
	 * @param string $run_id Chain identifier.
	 * @param int    $offset Offset of the next batch.
	 * @return void
	 */
	private function enqueue_next_batch( string $run_id, int $offset ): void {
		$queue = $this->get_queue();

		if ( ! $queue->is_available() ) {
			$this->warn_queue_unavailable();

			return;
		}

		$queue->enqueue( $this->get_batch_hook(), array( $run_id, $offset ), self::ACTION_GROUP );
	}

	/**
	 * Whether the background warm-up may run in this request.
	 *
	 * @return bool
	 */
	private function is_scheduled_run_allowed(): bool {
		if ( ! Core::is_active() ) {
			$this->logger->debug( 'Scheduled cache warm up skipped: the plugin master switch is off.', array(), 'Cache' );

			return false;
		}

		if ( ! $this->has_persistent_cache() ) {
			$this->logger->debug( 'Scheduled cache warm up skipped: no persistent object cache is in use.', array(), 'Cache' );

			return false;
		}

		return true;
	}

	/**
	 * Whether resolved prices land in a persistent memory cache.
	 *
	 * @return bool
	 */
	public function has_persistent_cache(): bool {
		if ( null === $this->persistent_cache_probe ) {
			return false;
		}

		return true === ( $this->persistent_cache_probe )();
	}

	/**
	 * Log the missing Action Scheduler once per request.
	 *
	 * @return void
	 */
	private function warn_queue_unavailable(): void {
		if ( $this->queue_warning_logged ) {
			return;
		}

		$this->queue_warning_logged = true;

		$this->logger->warning( 'Scheduled cache warm up skipped: Action Scheduler is not available.', array(), 'Cache' );
	}

	/**
	 * Get the background action queue.
	 *
	 * @return IActionQueue
	 */
	private function get_queue(): IActionQueue {
		if ( null === $this->queue ) {
			$this->queue = new ActionQueue();
		}

		return $this->queue;
	}

	/**
	 * Check whether the service has everything it needs to do useful work.
	 *
	 * @return bool
	 */
	private function can_warm_up(): bool {
		if ( null === $this->price_resolver ) {
			$this->logger->debug( 'Cache warm up skipped: no price resolver wired.', array(), 'Cache' );
			return false;
		}

		if ( ! function_exists( 'wc_get_products' ) ) {
			$this->logger->debug( 'Cache warm up skipped: WooCommerce is not loaded.', array(), 'Cache' );
			return false;
		}

		return true;
	}

	/**
	 * Clamp the popular warm-up limit to the configured bounds.
	 *
	 * @param int $total_products Published product count.
	 * @return int Warm-up limit.
	 */
	private function calculate_warm_up_limit( int $total_products ): int {
		return (int) max(
			self::MIN_WARMUP_LIMIT,
			min( self::MAX_WARMUP_LIMIT, $total_products * self::WARMUP_RATIO )
		);
	}

	/**
	 * Count published products.
	 *
	 * @return int
	 */
	private function get_total_product_count(): int {
		$counts = wp_count_posts( 'product' );

		return (int) ( $counts->publish ?? 0 );
	}

	/**
	 * Resolve the ruled countries that also carry an active currency mapping.
	 *
	 * @return array<string> ISO country codes.
	 */
	public function get_ruled_country_codes(): array {
		if ( null === $this->ruled_country_provider ) {
			return array();
		}

		try {
			$ruled = ( $this->ruled_country_provider )();
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Cache warm up could not resolve the ruled countries: ' . $e->getMessage(), array(), 'Cache' );

			return array();
		}

		if ( ! is_array( $ruled ) ) {
			return array();
		}

		return array_values(
			array_intersect(
				array_unique( $this->normalise_codes( $ruled ) ),
				$this->get_active_country_codes()
			)
		);
	}

	/**
	 * Resolve the active country codes once per run.
	 *
	 * @return array<string> ISO country codes.
	 */
	private function get_active_country_codes(): array {
		if ( null !== $this->country_codes ) {
			return $this->country_codes;
		}

		if ( null === $this->country_provider ) {
			$this->country_codes = array();
			return $this->country_codes;
		}

		try {
			$codes = ( $this->country_provider )();
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Cache warm up could not resolve active countries: ' . $e->getMessage(), array(), 'Cache' );
			$this->country_codes = array();
			return $this->country_codes;
		}

		$this->country_codes = is_array( $codes ) ? $this->normalise_codes( $codes ) : array();

		return $this->country_codes;
	}

	/**
	 * Upper-case and trim a list of country codes, dropping empty ones.
	 *
	 * @param array<mixed> $codes Raw country codes.
	 * @return array<string> ISO country codes.
	 */
	private function normalise_codes( array $codes ): array {
		return array_values(
			array_filter(
				array_map( static fn ( $code ): string => strtoupper( trim( is_scalar( $code ) ? (string) $code : '' ) ), $codes ),
				static fn ( string $code ): bool => '' !== $code
			)
		);
	}
}
