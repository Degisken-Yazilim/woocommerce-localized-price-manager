<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Pages;

use D6N\LPM\Modules\ExchangeRates\Repositories\ExchangeRateRepository;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Core list table for the stored exchange rates.
 */
class RatesListTable extends WP_List_Table {

	/**
	 * Rows shown per page.
	 *
	 * @var int
	 */
	private const PER_PAGE = 20;

	/**
	 * Columns the table may be ordered by.
	 *
	 * @var array<int, string>
	 */
	private const ORDERABLE = array(
		'base_currency',
		'target_currency',
		'exchange_rate',
		'source',
		'last_updated',
	);

	/**
	 * Build the table around the rate repository.
	 *
	 * @param ExchangeRateRepository $repository Rate repository.
	 */
	public function __construct( private readonly ExchangeRateRepository $repository ) {
		parent::__construct(
			array(
				'singular' => 'lpm_rate',
				'plural'   => 'lpm_rates',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get the columns the table renders.
	 *
	 * @return array<string, string> Column key to label map.
	 */
	public function get_columns(): array {
		return array(
			'base_currency'   => __( 'Base', 'localized-price-manager' ),
			'target_currency' => __( 'Target', 'localized-price-manager' ),
			'exchange_rate'   => __( 'Rate', 'localized-price-manager' ),
			'source'          => __( 'Source', 'localized-price-manager' ),
			'last_updated'    => __( 'Last updated', 'localized-price-manager' ),
		);
	}

	/**
	 * Get the sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}> Column key to sort spec map.
	 */
	protected function get_sortable_columns(): array {
		return array(
			'base_currency'   => array( 'base_currency', false ),
			'target_currency' => array( 'target_currency', true ),
			'exchange_rate'   => array( 'exchange_rate', false ),
			'source'          => array( 'source', false ),
			'last_updated'    => array( 'last_updated', false ),
		);
	}

	/**
	 * Read every rate, order it and keep the page that was asked for.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$rows = $this->repository->get_all();
		$rows = is_wp_error( $rows ) ? array() : array_values( array_filter( $rows, 'is_array' ) );

		$this->sort_rows( $rows );

		$total = count( $rows );
		$page  = max( 1, $this->get_pagenum() );

		$this->items = array_slice( $rows, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Render the rate, as the digits the column stores.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string Escaped value.
	 */
	public function column_exchange_rate( array $item ): string {
		$rate = $item['exchange_rate'] ?? '';

		return esc_html( is_scalar( $rate ) ? (string) $rate : '' );
	}

	/**
	 * Render the freshness stamp in the site's own timezone.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string Escaped value.
	 */
	public function column_last_updated( array $item ): string {
		$stored = (string) ( $item['last_updated'] ?? '' );

		if ( '' === $stored ) {
			return '—';
		}

		$timestamp = strtotime( $stored . ' UTC' );

		if ( false === $timestamp ) {
			return esc_html( $stored );
		}

		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		return esc_html( (string) wp_date( $format, $timestamp ) );
	}

	/**
	 * Render the provider that wrote the row.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string Escaped value.
	 */
	public function column_source( array $item ): string {
		$source = (string) ( $item['source'] ?? '' );

		return '' === $source ? '—' : '<code>' . esc_html( $source ) . '</code>';
	}

	/**
	 * Render any column that needs no treatment of its own.
	 *
	 * @param array<string, mixed> $item        Row data.
	 * @param string               $column_name Column key.
	 * @return string Escaped value.
	 */
	public function column_default( $item, $column_name ): string {
		$value = $item[ $column_name ] ?? '';

		return esc_html( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Announce an empty table.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No exchange rates have been stored yet.', 'localized-price-manager' );
	}

	/**
	 * Order the rows by the requested column.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows to order, by reference.
	 * @return void
	 */
	private function sort_rows( array &$rows ): void {
		$column    = $this->resolve_order_by();
		$ascending = 'ASC' === $this->resolve_order();
		$numeric   = 'exchange_rate' === $column;

		usort(
			$rows,
			static function ( array $left, array $right ) use ( $column, $ascending, $numeric ): int {
				$a = $left[ $column ] ?? '';
				$b = $right[ $column ] ?? '';

				$result = $numeric
					? ( (float) $a <=> (float) $b )
					: strcasecmp( is_scalar( $a ) ? (string) $a : '', is_scalar( $b ) ? (string) $b : '' );

				return $ascending ? $result : -$result;
			}
		);
	}

	/**
	 * Resolve the requested sort column against the whitelist.
	 *
	 * @return string Column name.
	 */
	private function resolve_order_by(): string {
		$order_by = $this->get_request_value( 'orderby' );

		return in_array( $order_by, self::ORDERABLE, true ) ? $order_by : 'target_currency';
	}

	/**
	 * Resolve the requested sort direction.
	 *
	 * @return string Either `ASC` or `DESC`.
	 */
	private function resolve_order(): string {
		$order = strtolower( $this->get_request_value( 'order' ) );

		if ( '' === $order ) {
			return 'last_updated' === $this->resolve_order_by() ? 'DESC' : 'ASC';
		}

		return 'desc' === $order ? 'DESC' : 'ASC';
	}

	/**
	 * Read one sanitized value from the request.
	 *
	 * @param string $key Request key.
	 * @return string Sanitized value, or an empty string when absent.
	 */
	private function get_request_value( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST[ $key ] ) || ! is_scalar( $_REQUEST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_key( wp_unslash( (string) $_REQUEST[ $key ] ) );
	}
}
