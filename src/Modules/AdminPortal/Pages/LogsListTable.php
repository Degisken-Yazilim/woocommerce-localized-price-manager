<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Pages;

use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Core\Enums\NonceEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\DiagnosticLog\Enums\LogChannelEnum;
use D6N\LPM\Modules\DiagnosticLog\Models\LogDateRange;
use D6N\LPM\Modules\DiagnosticLog\Repositories\LogRepository;
use WP_Error;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Core list table for the diagnostic log page.
 */
class LogsListTable extends WP_List_Table {

	/**
	 * Request key the clear-logs button submits under.
	 *
	 * @var string
	 */
	public const CLEAR_REQUEST_KEY = 'lpm_clear_logs';

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
	private const ORDERABLE = array( 'id', 'channel', 'level', 'source', 'created_at' );

	/**
	 * Total number of rows matching the current filters.
	 *
	 * @var int
	 */
	private int $total_items = 0;

	/**
	 * Message explaining why the current filter set could not be applied.
	 *
	 * @var string
	 */
	private string $filter_error = '';

	/**
	 * Constructor.
	 *
	 * @param LogRepository $repository Log repository instance.
	 */
	public function __construct( private readonly LogRepository $repository ) {
		parent::__construct(
			array(
				'singular' => 'lpm_log',
				'plural'   => 'lpm_logs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define the table columns.
	 *
	 * @return array<string, string> Column key to label map.
	 */
	public function get_columns(): array {
		return array(
			'created_at' => __( 'Date', 'localized-price-manager' ),
			'channel'    => __( 'Channel', 'localized-price-manager' ),
			'level'      => __( 'Level', 'localized-price-manager' ),
			'source'     => __( 'Source', 'localized-price-manager' ),
			'message'    => __( 'Message', 'localized-price-manager' ),
			'user_login' => __( 'User', 'localized-price-manager' ),
			'ip_address' => __( 'IP address', 'localized-price-manager' ),
		);
	}

	/**
	 * Define the sortable columns.
	 *
	 * @return array<string, array<int, mixed>> Column key to sort descriptor map.
	 */
	protected function get_sortable_columns(): array {
		return array(
			'created_at' => array( 'created_at', true ),
			'channel'    => array( 'channel', false ),
			'level'      => array( 'level', false ),
			'source'     => array( 'source', false ),
		);
	}

	/**
	 * Build the channel filter links shown above the table.
	 *
	 * @return array<string, string> View key to link markup map.
	 */
	protected function get_views(): array {
		$base_url = LogsPage::tab_url();
		$current  = $this->get_request_value( 'channel' );

		$views = array(
			'all' => $this->build_view_link(
				$base_url,
				__( 'All', 'localized-price-manager' ),
				$this->count_for( array() ),
				'' === $current
			),
		);

		foreach ( LogChannelEnum::cases() as $channel ) {
			$views[ 'channel_' . $channel->value ] = $this->build_view_link(
				add_query_arg( 'channel', $channel->value, $base_url ),
				$channel->label(),
				$this->count_for( array( 'channel' => $channel->value ) ),
				$current === $channel->value
			);
		}

		return $views;
	}

	/**
	 * Fetch the rows and configure pagination.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->filter_error    = '';
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$filters = $this->build_filters();

		if ( is_wp_error( $filters ) ) {
			$this->filter_error = $filters->get_error_message();

			$this->show_rows( array(), 0 );

			return;
		}

		$page  = max( 1, $this->get_pagenum() );
		$total = $this->count_for( $filters );

		$rows = $this->repository->get_filtered_logs(
			array_merge(
				$filters,
				array(
					'limit'    => self::PER_PAGE,
					'offset'   => ( $page - 1 ) * self::PER_PAGE,
					'order_by' => $this->resolve_order_by(),
					'order'    => $this->resolve_order(),
				)
			)
		);

		$this->show_rows( is_wp_error( $rows ) ? array() : $rows, $total );
	}

	/**
	 * Get the message explaining a rejected filter set.
	 *
	 * @return string Message, or an empty string when the filters were usable.
	 */
	public function get_filter_error(): string {
		return $this->filter_error;
	}

	/**
	 * Render the level column as a badge.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string Escaped markup.
	 */
	public function column_level( array $item ): string {
		$level = LogLevelEnum::from_value( $item['level'] ?? '' );

		return sprintf(
			'<span class="lpm-badge lpm-badge-%1$s">%2$s</span>',
			esc_attr( $level->value ),
			esc_html( strtoupper( $level->value ) )
		);
	}

	/**
	 * Render the date column in the site's timezone and date format.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string Escaped value.
	 */
	public function column_created_at( array $item ): string {
		$stored = (string) ( $item['created_at'] ?? '' );

		if ( '' === $stored ) {
			return '';
		}

		$timestamp = strtotime( $stored . ' UTC' );

		if ( false === $timestamp ) {
			return esc_html( $stored );
		}

		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		return esc_html( (string) wp_date( $format, $timestamp ) );
	}

	/**
	 * Render the message column, truncated to a readable preview.
	 *
	 * @param array<string, mixed> $item Row data.
	 * @return string Escaped markup.
	 */
	public function column_message( array $item ): string {
		$message = (string) ( $item['message'] ?? '' );
		$preview = mb_strlen( $message ) > 140 ? mb_substr( $message, 0, 140 ) . '…' : $message;

		return esc_html( $preview );
	}

	/**
	 * Render any other column.
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
	 * Message shown when nothing matches the filters.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No log entries found.', 'localized-price-manager' );
	}

	/**
	 * Render the level dropdown and the date range fields next to the bulk actions.
	 *
	 * @param string $which Either `top` or `bottom`.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$level = $this->get_request_value( 'level' );

		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="lpm-filter-level">' . esc_html__( 'Filter by level', 'localized-price-manager' ) . '</label>';
		echo '<select name="level" id="lpm-filter-level">';
		echo '<option value="">' . esc_html__( 'All levels', 'localized-price-manager' ) . '</option>';

		foreach ( LogLevelEnum::cases() as $case ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $case->value ),
				$case->value === $level ? ' selected="selected"' : '',
				esc_html( $case->label() )
			);
		}

		echo '</select>';

		$this->render_date_field( 'date_from', __( 'Filter from date', 'localized-price-manager' ) );
		$this->render_date_field( 'date_to', __( 'Filter to date', 'localized-price-manager' ) );

		submit_button( __( 'Filter', 'localized-price-manager' ), '', 'lpm-filter', false );
		$this->render_clear_button();
		echo '</div>';
	}

	/**
	 * Render the clear-logs button beside the filter button.
	 *
	 * @return void
	 */
	private function render_clear_button(): void {
		if ( ! Core::can_purge_logs() ) {
			return;
		}

		$deletable = $this->deletable_count();

		if ( $deletable < 1 ) {
			return;
		}

		wp_nonce_field( NonceEnum::ClearLogs->action(), NonceEnum::ClearLogs->value );

		printf(
			'<button type="submit" formmethod="post" class="button button-link-delete lpm-clear-logs" name="%1$s" value="1" data-lpm-clear-count="%2$d">%3$s</button>',
			esc_attr( self::CLEAR_REQUEST_KEY ),
			absint( $deletable ),
			esc_html(
				sprintf(
					/* translators: %s: number of log entries the current filter matches. */
					_n( 'Clear %s entry', 'Clear %s entries', $deletable, 'localized-price-manager' ),
					number_format_i18n( $deletable )
				)
			)
		);
	}

	/**
	 * Count what a clear would remove under the current filter.
	 *
	 * @return int Deletable entries, zero when the filter is unusable.
	 */
	private function deletable_count(): int {
		$filters = $this->build_filters();

		if ( is_wp_error( $filters ) ) {
			return 0;
		}

		$count = $this->repository->count_deletable_logs( $filters );

		return is_wp_error( $count ) ? 0 : $count;
	}

	/**
	 * Render one date bound input, repopulated from the current request.
	 *
	 * @param string $name  Request key, either `date_from` or `date_to`.
	 * @param string $label Screen reader label.
	 * @return void
	 */
	private function render_date_field( string $name, string $label ): void {
		$id = 'lpm-filter-' . str_replace( '_', '-', $name );

		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="date" name="%3$s" id="%1$s" value="%4$s" />',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $this->get_request_value( $name ) )
		);
	}

	/**
	 * Build the repository filter set from the current request.
	 *
	 * @return array<string, mixed>|WP_Error Repository filters, or WP_Error when the date range is unusable.
	 */
	public function build_filters(): array|WP_Error {
		$filters = array();

		$channel = $this->get_request_value( 'channel' );

		if ( '' !== $channel && LogChannelEnum::from_value( $channel )->value === $channel ) {
			$filters['channel'] = $channel;
		}

		$level = $this->get_request_value( 'level' );

		if ( '' !== $level && LogLevelEnum::from_value( $level )->value === $level ) {
			$filters['level'] = $level;
		}

		$search = $this->get_request_value( 's' );

		if ( '' !== $search ) {
			$filters['message'] = $search;
		}

		$range = LogDateRange::from_local_dates(
			$this->get_request_value( 'date_from' ),
			$this->get_request_value( 'date_to' )
		);

		if ( is_wp_error( $range ) ) {
			return $range;
		}

		return array_merge( $filters, $range->to_filters() );
	}

	/**
	 * Publish a fetched page and its pagination arguments.
	 *
	 * @param array<int, array<string, mixed>> $rows  Rows to display.
	 * @param int                              $total Total rows matching the filters.
	 * @return void
	 */
	private function show_rows( array $rows, int $total ): void {
		$this->items       = $rows;
		$this->total_items = $total;

		$this->set_pagination_args(
			array(
				'total_items' => $this->total_items,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $this->total_items / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Count the rows matching a filter set.
	 *
	 * @param array<string, mixed> $filters Repository filters.
	 * @return int Row count, or 0 when the query failed.
	 */
	private function count_for( array $filters ): int {
		$count = $this->repository->count_filtered_logs( $filters );

		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Build one channel filter link.
	 *
	 * @param string $url     Target URL.
	 * @param string $label   Link label.
	 * @param int    $count   Row count.
	 * @param bool   $current Whether the link is the active one.
	 * @return string Link markup.
	 */
	private function build_view_link( string $url, string $label, int $count, bool $current ): string {
		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			$count
		);
	}

	/**
	 * Resolve the requested sort column against the whitelist.
	 *
	 * @return string Column name.
	 */
	private function resolve_order_by(): string {
		$order_by = $this->get_request_value( 'orderby' );

		return in_array( $order_by, self::ORDERABLE, true ) ? $order_by : 'created_at';
	}

	/**
	 * Resolve the requested sort direction.
	 *
	 * @return string Either `ASC` or `DESC`.
	 */
	private function resolve_order(): string {
		return 'asc' === strtolower( $this->get_request_value( 'order' ) ) ? 'ASC' : 'DESC';
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
		return trim( sanitize_text_field( wp_unslash( (string) $_REQUEST[ $key ] ) ) );
	}
}
