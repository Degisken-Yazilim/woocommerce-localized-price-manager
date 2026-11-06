<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\Utils;
use Throwable;
use WC_Order;
use WC_Order_Item;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the original base price next to the converted one, for administrators only.
 */
class OrderPricingDisplay implements IHookable {

	/**
	 * Order list column identifier.
	 */
	private const COLUMN_ID = 'lpm_original_total';

	/**
	 * Orders whose line item table received an "Original Price" header.
	 *
	 * @var array<int, bool>
	 */
	private array $rendered_headers = array();

	/**
	 * Constructor.
	 *
	 * @param ILogger $logger Logger service.
	 */
	public function __construct(
		private readonly ILogger $logger
	) {}

	/**
	 * Register WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$late     = WooHookPriorityEnum::Late->value;
		$standard = WooHookPriorityEnum::Standard->value;

		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_list_columns' ), $late, 1 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_list_column' ), $standard, 2 );

		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_list_columns' ), $late, 1 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_list_column_legacy' ), $standard, 2 );

		add_action( 'woocommerce_admin_order_item_headers', array( $this, 'render_order_item_column_header' ), $standard, 1 );
		add_action( 'woocommerce_admin_order_item_values', array( $this, 'render_order_item_column_value' ), $standard, 3 );

		add_action( 'woocommerce_admin_order_totals_after_total', array( $this, 'render_order_totals_rows' ), $standard, 1 );

		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_provenance_item_meta' ), $standard, 1 );
	}

	/**
	 * Keep the provenance item meta out of WooCommerce's raw meta table.
	 *
	 * @param mixed $keys Meta keys WooCommerce already hides.
	 * @return array<int, string>
	 */
	public function hide_provenance_item_meta( mixed $keys = array() ): array {
		$hidden = is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : array();

		return array_values(
			array_unique(
				array_merge(
					$hidden,
					array(
						OrderMetaPersistenceService::META_ITEM_ORIGINAL_PRICE,
						OrderMetaPersistenceService::META_ITEM_ORIGINAL_CURRENCY,
						OrderMetaPersistenceService::META_ITEM_RESOLUTION_MODE,
						OrderMetaPersistenceService::META_ITEM_APPLIED_RATE,
					)
				)
			)
		);
	}

	/**
	 * Add the "Original Total" column right after the order total.
	 *
	 * @param array<string, string> $columns Existing column definitions.
	 * @return array<string, string>
	 */
	public function add_order_list_columns( array $columns ): array {
		if ( ! $this->user_may_view() ) {
			return $columns;
		}

		$new_column = array(
			self::COLUMN_ID => __( 'Original Total', 'localized-price-manager' ),
		);

		$position = array_search( 'order_total', array_keys( $columns ), true );

		if ( false === $position ) {
			return array_merge( $columns, $new_column );
		}

		return array_merge(
			array_slice( $columns, 0, $position + 1, true ),
			$new_column,
			array_slice( $columns, $position + 1, null, true )
		);
	}

	/**
	 * Render the "Original Total" cell of the HPOS order table.
	 *
	 * @param string $column_name Column identifier.
	 * @param mixed  $order       Order object.
	 * @return void
	 */
	public function render_order_list_column( string $column_name, mixed $order = null ): void {
		if ( self::COLUMN_ID !== $column_name || ! $this->user_may_view() ) {
			return;
		}

		if ( $order instanceof WC_Order ) {
			$this->output_original_total( $order );
		}
	}

	/**
	 * Render the "Original Total" cell of the legacy `shop_order` post table.
	 *
	 * @param string $column_name Column identifier.
	 * @param int    $post_id     Order post ID.
	 * @return void
	 */
	public function render_order_list_column_legacy( string $column_name, int $post_id ): void {
		if ( self::COLUMN_ID !== $column_name || ! $this->user_may_view() ) {
			return;
		}

		$order = $this->get_order( $post_id );

		if ( null !== $order ) {
			$this->output_original_total( $order );
		}
	}

	/**
	 * Print the "Original Price" header of the line item table.
	 *
	 * @param mixed $order Order object.
	 * @return void
	 */
	public function render_order_item_column_header( mixed $order = null ): void {
		if ( ! $order instanceof WC_Order || ! $this->user_may_view() ) {
			return;
		}

		if ( ! $this->order_has_item_meta( $order ) ) {
			return;
		}

		$this->rendered_headers[ $order->get_id() ] = true;

		echo '<th class="lpm-original-price">' . esc_html__( 'Original Price', 'localized-price-manager' ) . '</th>';
	}

	/**
	 * Print the "Original Price" cell of one line item.
	 *
	 * @param mixed $product Product object, or false when the product is gone.
	 * @param mixed $item    Order line item.
	 * @param mixed $item_id Order item ID.
	 * @return void
	 */
	public function render_order_item_column_value( mixed $product = null, mixed $item = null, mixed $item_id = null ): void {
		if ( ! $item instanceof WC_Order_Item || ! $this->user_may_view() ) {
			return;
		}

		if ( ! isset( $this->rendered_headers[ (int) $item->get_order_id() ] ) ) {
			return;
		}

		$formatted = $this->format_amount(
			$item->get_meta( OrderMetaPersistenceService::META_ITEM_ORIGINAL_PRICE ),
			$item->get_meta( OrderMetaPersistenceService::META_ITEM_ORIGINAL_CURRENCY )
		);

		echo '<td class="lpm-original-price">';
		echo null === $formatted ? '<span aria-hidden="true">&mdash;</span>' : wp_kses_post( $formatted );
		echo wp_kses_post( $this->format_provenance( $item ) );
		echo '</td>';
	}

	/**
	 * Print the original total and exchange rate rows under the order totals.
	 *
	 * @param mixed $order_id Order ID.
	 * @return void
	 */
	public function render_order_totals_rows( mixed $order_id = null ): void {
		if ( ! $this->user_may_view() ) {
			return;
		}

		$order = $this->get_order( (int) $order_id );

		if ( null === $order ) {
			return;
		}

		$original_currency = (string) $order->get_meta( OrderMetaPersistenceService::META_ORIGINAL_CURRENCY );
		$local_currency    = (string) $order->get_meta( OrderMetaPersistenceService::META_LOCAL_CURRENCY );
		$exchange_rate     = Utils::parse_float( $order->get_meta( OrderMetaPersistenceService::META_EXCHANGE_RATE ) );
		$original_total    = $this->format_amount(
			$order->get_meta( OrderMetaPersistenceService::META_ORIGINAL_TOTAL ),
			$original_currency
		);

		if ( null !== $original_total ) {
			$this->output_totals_row(
				sprintf(
					/* translators: %s: currency code, for example USD. */
					__( 'Original Total (%s)', 'localized-price-manager' ),
					$original_currency
				),
				wp_kses_post( $original_total )
			);
		}

		if ( null !== $exchange_rate && $exchange_rate > 0.0 && '' !== $original_currency && '' !== $local_currency ) {
			$this->output_totals_row(
				__( 'Exchange Rate', 'localized-price-manager' ),
				'<small>' . esc_html( $this->format_rate( $original_currency, $local_currency, $exchange_rate ) ) . '</small>'
			);
		}
	}

	/**
	 * Print the original total cell of an order list row.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 */
	private function output_original_total( WC_Order $order ): void {
		$formatted = $this->format_amount(
			$order->get_meta( OrderMetaPersistenceService::META_ORIGINAL_TOTAL ),
			$order->get_meta( OrderMetaPersistenceService::META_ORIGINAL_CURRENCY )
		);

		if ( null === $formatted ) {
			echo '<span aria-hidden="true">&mdash;</span>';

			return;
		}

		echo wp_kses_post( $formatted );
	}

	/**
	 * Print one label/value row of the admin order totals table.
	 *
	 * @param string $label Row label, printed as text.
	 * @param string $value Row value, already escaped markup.
	 * @return void
	 */
	private function output_totals_row( string $label, string $value ): void {
		echo '<tr><td class="label">' . esc_html( $label ) . '</td><td width="1%"></td><td class="total">';
		echo wp_kses_post( $value );
		echo '</td></tr>';
	}

	/**
	 * Format an amount in an explicit currency.
	 *
	 * @param mixed $amount   Raw meta value.
	 * @param mixed $currency Raw currency meta value.
	 * @return string|null Formatted price markup, or null when the meta is absent.
	 */
	private function format_amount( mixed $amount, mixed $currency ): ?string {
		$parsed   = Utils::parse_float( $amount );
		$currency = strtoupper( trim( (string) $currency ) );

		if ( null === $parsed || '' === $currency ) {
			return null;
		}

		return (string) wc_price( $parsed, array( 'currency' => $currency ) );
	}

	/**
	 * Render the tier that priced a line and the multiplier it applied.
	 *
	 * @param WC_Order_Item $item Order line item.
	 * @return string Markup, empty when the line records no tier.
	 */
	private function format_provenance( WC_Order_Item $item ): string {
		$mode = trim( (string) $item->get_meta( OrderMetaPersistenceService::META_ITEM_RESOLUTION_MODE ) );

		if ( '' === $mode ) {
			return '';
		}

		$rate  = Utils::parse_float( $item->get_meta( OrderMetaPersistenceService::META_ITEM_APPLIED_RATE ) );
		$label = null === $rate ? $mode : $mode . ' × ' . (string) $rate;

		return '<br /><small class="lpm-resolution-mode">' . esc_html( $label ) . '</small>';
	}

	/**
	 * Build the human readable "1 USD = 34.20 TRY" string.
	 *
	 * @param string $from Source currency code.
	 * @param string $to   Target currency code.
	 * @param float  $rate Exchange rate.
	 * @return string
	 */
	private function format_rate( string $from, string $to, float $rate ): string {
		$decimals  = max( 2, (int) wc_get_price_decimals() );
		$formatted = (string) number_format_i18n( $rate, $decimals );

		return sprintf( '1 %1$s = %2$s %3$s', $from, $formatted, $to );
	}

	/**
	 * Check whether any line item of an order carries the original price meta.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function order_has_item_meta( WC_Order $order ): bool {
		try {
			foreach ( $order->get_items() as $item ) {
				if ( $this->item_has_price_meta( $item ) ) {
					return true;
				}
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Order item meta inspection failed for order %d: %s', $order->get_id(), $e->getMessage() ),
				array(),
				'WooCommerce'
			);
		}

		return false;
	}

	/**
	 * Check whether one line item carries a usable original price and currency.
	 *
	 * @param mixed $item Whatever the item filter left in the array.
	 * @return bool True when both provenance meta values are present.
	 */
	private function item_has_price_meta( mixed $item ): bool {
		if ( ! $item instanceof WC_Order_Item ) {
			return false;
		}

		$price    = $item->get_meta( OrderMetaPersistenceService::META_ITEM_ORIGINAL_PRICE );
		$currency = $item->get_meta( OrderMetaPersistenceService::META_ITEM_ORIGINAL_CURRENCY );

		return null !== Utils::parse_float( $price ) && '' !== trim( (string) $currency );
	}

	/**
	 * Load an order by ID.
	 *
	 * @param int $order_id Order ID.
	 * @return WC_Order|null
	 */
	private function get_order( int $order_id ): ?WC_Order {
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Check whether the current user may see base pricing information.
	 *
	 * @return bool
	 */
	private function user_may_view(): bool {
		return (bool) current_user_can( Core::get_admin_capability() );
	}
}
