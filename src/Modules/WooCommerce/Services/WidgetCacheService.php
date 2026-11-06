<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\WooCommerce\Support\LocalizedPriceProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Partitions WooCommerce's rendered widget HTML cache by customer country.
 */
class WidgetCacheService implements IHookable {

	/**
	 * Object cache group WooCommerce keeps rendered widget HTML in.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'widget';

	/**
	 * Partition suffix used when no country could be resolved for the visitor.
	 *
	 * @var string
	 */
	private const UNRESOLVED_PARTITION = 'none';

	/**
	 * Constructor.
	 *
	 * @param LocalizedPriceProvider $price_provider Request-scoped resolved price provider.
	 */
	public function __construct(
		private readonly LocalizedPriceProvider $price_provider
	) {}

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
		add_filter( 'woocommerce_cached_widget_id', array( $this, 'partition_widget_id' ), WooHookPriorityEnum::Standard->value, 1 );

		add_action( 'save_post', array( $this, 'flush_partitions' ) );
		add_action( 'deleted_post', array( $this, 'flush_partitions' ) );
		add_action( 'switch_theme', array( $this, 'flush_partitions' ) );

		add_action( Core::build_hook( HookEnum::CurrencyRateUpdated->value ), array( $this, 'flush_partitions' ) );
		add_action( Core::build_hook( HookEnum::CountryCurrencySaved->value ), array( $this, 'flush_partitions' ) );
		add_action( Core::build_hook( HookEnum::SettingsSaved->value ), array( $this, 'flush_partitions' ) );

		foreach ( Core::WC_MONEY_OPTIONS as $option ) {
			add_action( 'update_option_' . $option, array( $this, 'flush_partitions' ), 10, 0 );
		}
	}

	/**
	 * Append the visitor's country and the current generation to a WooCommerce
	 * widget cache id.
	 *
	 * @param mixed $widget_id Cache id WooCommerce built, possibly already filtered.
	 * @return mixed Partitioned cache id, or the input when it is not a usable id.
	 */
	public function partition_widget_id( mixed $widget_id ): mixed {
		if ( ! is_string( $widget_id ) || '' === $widget_id ) {
			return $widget_id;
		}

		$country = $this->price_provider->get_country();

		return $widget_id . '-lpm-' . self::generation() . '-' . ( '' === $country ? self::UNRESOLVED_PARTITION : strtolower( $country ) );
	}

	/**
	 * Invalidate every country partition of the rendered widget HTML cache.
	 *
	 * @return void
	 */
	public function flush_partitions(): void {
		$current = (int) Core::get_option( OptionsEnum::WidgetCacheRevision->value, 0 );
		Core::save_option( OptionsEnum::WidgetCacheRevision->value, $current + 1 );

		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}
	}

	/**
	 * Read the current widget partition generation.
	 *
	 * @return int Non-negative generation stamp.
	 */
	private static function generation(): int {
		return max( 0, (int) Core::get_option( OptionsEnum::WidgetCacheRevision->value, 0 ) );
	}
}
