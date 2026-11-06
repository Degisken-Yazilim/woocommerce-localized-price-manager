<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Cli\Commands;

use D6N\LPM\Core\Enums\CacheGroupEnum;
use D6N\LPM\Modules\Cache\Services\CacheWarmUpService;
use D6N\LPM\Modules\Cli\CliContainer;
use WP_CLI;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

defined( 'ABSPATH' ) || exit;

/**
 * `wp d6n-lpm cache` — cache maintenance commands.
 */
class CacheCommand {

	/**
	 * Constructor.
	 *
	 * @param CliContainer $container Shared CLI object graph.
	 */
	public function __construct(
		private readonly CliContainer $container
	) {}

	/**
	 * Flush the plugin cache, either entirely or one group at a time.
	 *
	 * ## OPTIONS
	 *
	 * [--group=<group>]
	 * : Cache group to flush. Flushes every plugin group when omitted.
	 * ---
	 * options:
	 *   - pricing
	 *   - exchange_rates
	 *   - country_currency
	 *   - geolocation
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp d6n-lpm cache flush
	 *     wp d6n-lpm cache flush --group=exchange_rates
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function flush( array $args, array $assoc_args ): void {
		unset( $args );

		$group = strtolower( trim( (string) get_flag_value( $assoc_args, 'group', '' ) ) );
		$cache = $this->container->get_cache();

		if ( '' === $group ) {
			if ( ! $cache->flush_all() ) {
				WP_CLI::error( 'The plugin cache could not be flushed.' );

				return;
			}

			WP_CLI::success( 'Flushed every plugin cache group.' );

			return;
		}

		$resolved = CacheGroupEnum::tryFrom( $group );

		if ( null === $resolved ) {
			WP_CLI::error(
				sprintf(
					'Unknown cache group "%s". Known groups: %s.',
					$group,
					implode( ', ', array_column( CacheGroupEnum::cases(), 'value' ) )
				)
			);

			return;
		}

		/**
		 * @var CacheGroupEnum $resolved
		 */
		if ( ! $cache->flush_group( $resolved->value ) ) {
			WP_CLI::error( sprintf( 'Cache group "%s" could not be flushed.', $resolved->value ) );

			return;
		}

		WP_CLI::success( sprintf( 'Flushed the "%s" cache group.', $resolved->value ) );
	}

	/**
	 * Pre-compute resolved prices of the most popular products for every ruled country.
	 *
	 * ## EXAMPLES
	 *
	 *     wp d6n-lpm cache warm
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public function warm( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		if ( ! function_exists( 'wc_get_products' ) ) {
			WP_CLI::error( 'WooCommerce is not active, so there is no catalogue to warm up.' );

			return;
		}

		$service = $this->container->get_warm_up_service();

		if ( ! $service->has_persistent_cache() ) {
			WP_CLI::warning( 'No persistent object cache is in use, so warmed prices would not outlive this command. Nothing was warmed up.' );

			return;
		}

		if ( array() === $service->get_ruled_country_codes() ) {
			WP_CLI::warning( 'No country carries a product rule, a category rule or a group rate, so there is nothing to warm up.' );

			return;
		}

		WP_CLI::log( 'Warming the resolved price cache…' );

		$processed = 0;
		$warmed    = 0;

		foreach ( array_chunk( $service->get_popular_product_ids(), CacheWarmUpService::BATCH_SIZE ) as $batch ) {
			$warmed    += $service->warm_up_products( $batch );
			$processed += count( $batch );

			WP_CLI::log( sprintf( 'Processed %d products, %d warmed so far.', $processed, $warmed ) );
		}

		format_items(
			'table',
			array( array( 'products_warmed' => $warmed ) ),
			array( 'products_warmed' )
		);

		if ( 0 === $warmed ) {
			WP_CLI::warning( 'Nothing was warmed up. Check that country to currency mappings exist and products are published.' );

			return;
		}

		WP_CLI::success( sprintf( 'Warmed the price cache for %d products.', $warmed ) );
	}
}
