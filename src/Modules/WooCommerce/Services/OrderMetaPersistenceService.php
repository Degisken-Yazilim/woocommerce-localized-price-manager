<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Money\DecimalMath;
use D6N\LPM\Core\Money\DecimalText;
use D6N\LPM\Core\Money\ExchangeRate;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\Utils;
use D6N\LPM\Modules\PricingEngine\CountryCurrency\CountryCurrencyService;
use D6N\LPM\Modules\WooCommerce\Support\CartLocator;
use D6N\LPM\Modules\WooCommerce\Support\LocalizedPriceProvider;
use Throwable;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Records what a Phase 2 order was priced in, and what it would have cost at base.
 */
class OrderMetaPersistenceService implements IHookable {

	/**
	 * Order meta key holding the shop base currency.
	 */
	public const META_ORIGINAL_CURRENCY = '_lpm_original_currency';

	/**
	 * Order meta key holding the currency the order was recorded in.
	 */
	public const META_LOCAL_CURRENCY = '_lpm_local_currency';

	/**
	 * Order meta key holding the effective base-to-local rate of the whole order.
	 */
	public const META_EXCHANGE_RATE = '_lpm_exchange_rate';

	/**
	 * Order meta key holding the product subtotal in the shop base currency.
	 */
	public const META_ORIGINAL_TOTAL = '_lpm_original_total';

	/**
	 * Order meta key holding the product subtotal in the local currency.
	 */
	public const META_LOCAL_SUBTOTAL = '_lpm_local_subtotal';

	/**
	 * Order meta key naming the distinct tiers that priced the order's lines.
	 */
	public const META_RESOLUTION_MODES = '_lpm_resolution_modes';

	/**
	 * Order meta key holding the country the pricing rules were resolved for.
	 */
	public const META_CUSTOMER_COUNTRY = '_lpm_customer_country';

	/**
	 * Order item meta key holding the store base price of the line.
	 */
	public const META_ITEM_ORIGINAL_PRICE = '_lpm_original_price';

	/**
	 * Order item meta key holding the shop base currency of the line.
	 */
	public const META_ITEM_ORIGINAL_CURRENCY = '_lpm_original_currency';

	/**
	 * Order item meta key naming the tier that priced the line.
	 */
	public const META_ITEM_RESOLUTION_MODE = '_lpm_resolution_mode';

	/**
	 * Order item meta key holding the multiplier that tier applied.
	 */
	public const META_ITEM_APPLIED_RATE = '_lpm_applied_rate';

	/**
	 * Decimal places the running cart subtotals are accumulated at.
	 *
	 * @var int
	 */
	private const SUBTOTAL_SCALE = 12;

	/**
	 * Decimal places the recorded rates are kept to.
	 *
	 * @var int
	 */
	private const RATE_DECIMALS = ExchangeRate::SCALE;

	/**
	 * Hook priority used for every order hook of this service.
	 */
	private const PRIORITY = 11;

	/**
	 * Constructor.
	 *
	 * @param CountryCurrencyService $country_currency Country to currency read model.
	 * @param LocalizedPriceProvider $prices           Resolved price provider.
	 * @param ILogger                $logger           Logger service.
	 */
	public function __construct(
		private readonly CountryCurrencyService $country_currency,
		private readonly LocalizedPriceProvider $prices,
		private readonly ILogger $logger
	) {}

	/**
	 * Register WordPress action hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress action hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_currency_meta' ), self::PRIORITY, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'save_order_currency_meta' ), self::PRIORITY, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_line_item_original_price' ), self::PRIORITY, 4 );
	}

	/**
	 * Write the currency provenance of an order that is about to be saved.
	 *
	 * @param WC_Order $order Order being created.
	 * @return void
	 */
	public function save_order_currency_meta( WC_Order $order ): void {
		try {
			$this->persist( $order );
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Order currency meta could not be prepared: %s', $e->getMessage() ),
				array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				),
				'WooCommerce'
			);
		}
	}

	/**
	 * Write the store base price of one order line.
	 *
	 * @param WC_Order_Item_Product $item          Order line item being created.
	 * @param string                $cart_item_key Cart item key.
	 * @param array<string, mixed>  $values        Cart item values.
	 * @param WC_Order              $order         Order being created.
	 * @return void
	 */
	public function save_line_item_original_price( WC_Order_Item_Product $item, string $cart_item_key, array $values, WC_Order $order ): void {
		try {
			$product = $values['data'] ?? null;

			if ( ! ( $product instanceof WC_Product ) ) {
				return;
			}

			$item->add_meta_data( self::META_ITEM_ORIGINAL_PRICE, (string) $this->prices->get_base_price( $product ), true );
			$item->add_meta_data( self::META_ITEM_ORIGINAL_CURRENCY, Core::get_shop_base_currency(), true );

			$resolved = $this->prices->get_charge( $product ) ?? $this->prices->resolve( $product );

			if ( null === $resolved ) {
				return;
			}

			$item->add_meta_data( self::META_ITEM_RESOLUTION_MODE, $resolved->resolution_mode, true );

			if ( null !== $resolved->rate ) {
				$item->add_meta_data( self::META_ITEM_APPLIED_RATE, DecimalMath::round( $resolved->rate, self::RATE_DECIMALS ), true );
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Order line item original price could not be prepared: %s', $e->getMessage() ),
				array( 'cart_item_key' => $cart_item_key ),
				'WooCommerce'
			);
		}
	}

	/**
	 * Attach every order level meta value.
	 *
	 * @param WC_Order $order Order being created.
	 * @return void
	 */
	private function persist( WC_Order $order ): void {
		$base_currency = Core::get_shop_base_currency();
		$order->add_meta_data( self::META_ORIGINAL_CURRENCY, $base_currency, true );

		$country = $this->prices->get_country();

		if ( '' !== $country ) {
			$order->add_meta_data( self::META_CUSTOMER_COUNTRY, $country, true );
		}

		$local_currency = $this->resolve_local_currency( $order, $country );
		$order->add_meta_data( self::META_LOCAL_CURRENCY, $local_currency, true );

		$summary        = $this->summarize_cart( $this->country_currency->get_format_for_currency( $local_currency, $country )['decimals'] );
		$original_total = null === $summary ? null : $summary['base_subtotal'];

		if ( null !== $summary ) {
			$order->add_meta_data( self::META_ORIGINAL_TOTAL, $summary['base_subtotal'], true );
			$this->persist_resolution( $order, $summary, $base_currency, $local_currency );
		}

		$this->logger->info(
			sprintf( 'Prepared LPM order meta (%s to %s, country %s).', $base_currency, $local_currency, '' !== $country ? $country : 'unknown' ),
			array(
				'original_currency' => $base_currency,
				'local_currency'    => $local_currency,
				'original_total'    => $original_total,
			),
			'WooCommerce'
		);

		/**
		 * Fires once the localized order meta has been attached to an order.
		 *
		 * @param WC_Order $order          The order being created.
		 * @param string   $country        Customer ISO country code, empty when unknown.
		 * @param string   $local_currency Currency the order is recorded in.
		 */
		do_action( Core::build_hook( HookEnum::PricingOrderMetaSaved->value ), $order, $country, $local_currency );
	}

	/**
	 * Determine the currency the order is being recorded in.
	 *
	 * @param WC_Order $order   Order being created.
	 * @param string   $country Customer ISO country code, possibly empty.
	 * @return string Upper-case currency code.
	 */
	private function resolve_local_currency( WC_Order $order, string $country ): string {
		$order_currency = strtoupper( trim( (string) $order->get_currency() ) );

		if ( '' !== $order_currency ) {
			return $order_currency;
		}

		if ( '' !== $country ) {
			$mapped = $this->country_currency->get_currency_for_country( $country );

			if ( null !== $mapped && '' !== $mapped ) {
				return $mapped;
			}
		}

		return Core::get_global_currency();
	}

	/**
	 * Write everything that describes how the order was really priced.
	 *
	 * @param WC_Order                                                                             $order          Order being created.
	 * @param array{base_subtotal: string, local_subtotal: string|null, modes: array<int, string>} $summary        Cart summary.
	 * @param string                                                                               $base_currency  Shop base currency.
	 * @param string                                                                               $local_currency Currency the order is recorded in.
	 * @return void
	 */
	private function persist_resolution( WC_Order $order, array $summary, string $base_currency, string $local_currency ): void {
		if ( ! empty( $summary['modes'] ) ) {
			$order->add_meta_data( self::META_RESOLUTION_MODES, implode( ',', $summary['modes'] ), true );
		}

		$local_subtotal = $summary['local_subtotal'];

		if ( null === $local_subtotal || $local_currency === $base_currency ) {
			return;
		}

		$order->add_meta_data( self::META_LOCAL_SUBTOTAL, $local_subtotal, true );
		$order->add_meta_data(
			self::META_EXCHANGE_RATE,
			DecimalMath::divide( $local_subtotal, $summary['base_subtotal'], self::RATE_DECIMALS ),
			true
		);
	}

	/**
	 * Sum the cart, in both currencies, and collect the tiers that priced it.
	 *
	 * @param int $local_decimals Decimal places of the currency the order is recorded in.
	 * @return array{base_subtotal: string, local_subtotal: string|null, modes: array<int, string>}|null Summary, or null when there is no cart worth summing.
	 */
	private function summarize_cart( int $local_decimals ): ?array {
		$cart = CartLocator::current();

		if ( null === $cart ) {
			return null;
		}

		$base_subtotal  = '0';
		$local_subtotal = '0';
		$modes          = array();
		$fully_resolved = true;

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;

			if ( ! ( $product instanceof WC_Product ) ) {
				continue;
			}

			$quantity      = (string) max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
			$base_subtotal = DecimalMath::add(
				$base_subtotal,
				DecimalMath::multiply( (string) $this->prices->get_base_price( $product ), $quantity, self::SUBTOTAL_SCALE ),
				self::SUBTOTAL_SCALE
			);

			$resolved = $this->prices->get_charge( $product ) ?? $this->prices->resolve( $product );
			$local    = null === $resolved ? null : DecimalText::plain( $resolved->price );

			if ( null === $resolved || null === $local ) {
				$fully_resolved = false;

				continue;
			}

			$modes[]        = $resolved->resolution_mode;
			$local_subtotal = DecimalMath::add(
				$local_subtotal,
				DecimalMath::multiply( $local, $quantity, self::SUBTOTAL_SCALE ),
				self::SUBTOTAL_SCALE
			);
		}

		if ( '0' === $base_subtotal ) {
			return null;
		}

		return array(
			'base_subtotal'  => DecimalMath::round( $base_subtotal, Core::get_shop_price_decimals() ),
			'local_subtotal' => $fully_resolved ? DecimalMath::round( $local_subtotal, max( 0, $local_decimals ) ) : null,
			'modes'          => $this->normalize_modes( $modes ),
		);
	}

	/**
	 * Reduce the per-line tiers to the distinct, ordered set that names the mix.
	 *
	 * @param array<int, string> $modes Tier of every resolved line.
	 * @return array<int, string> Distinct tiers, sorted.
	 */
	private function normalize_modes( array $modes ): array {
		$distinct = array_values( array_unique( array_filter( $modes ) ) );

		sort( $distinct );

		return $distinct;
	}
}
