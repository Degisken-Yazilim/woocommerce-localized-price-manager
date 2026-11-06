<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Pages;

use D6N\LPM\Core\Enums\NonceEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\Settings\SettingsSchema;
use D6N\LPM\Modules\DiagnosticLog\Repositories\LogRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The diagnostic log admin page.
 */
class LogsPage {

	/**
	 * Script handle the clear-log confirmation is attached to.
	 */
	private const SCRIPT_HANDLE = 'lpm-settings-logs';

	/**
	 * Build the URL of the log tab, with arguments.
	 *
	 * @param array<string, int|string> $args Extra query arguments.
	 * @return string Absolute admin URL.
	 */
	public static function tab_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => SettingsPage::PAGE_SLUG,
					'tab'  => SettingsSchema::TAB_LOGS,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * List table instance, built once the screen is known.
	 *
	 * @var LogsListTable|null
	 */
	private ?LogsListTable $table = null;

	/**
	 * Constructor.
	 *
	 * @param LogRepository $repository Log repository instance.
	 */
	public function __construct( private readonly LogRepository $repository ) {}

	/**
	 * Get the list table, building it on first use.
	 *
	 * @return LogsListTable List table.
	 */
	public function get_table(): LogsListTable {
		if ( null === $this->table ) {
			$this->table = new LogsListTable( $this->repository );
		}

		return $this->table;
	}

	/**
	 * Render the log page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Core::get_admin_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view the diagnostic log.', 'localized-price-manager' ), '', array( 'response' => 403 ) );
		}

		$table = $this->get_table();
		$table->prepare_items();

		echo '<div class="lpm-logs">';

		$filter_error = $table->get_filter_error();

		if ( '' !== $filter_error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $filter_error )
			);
		}

		$this->render_cleared_notice();

		$table->views();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( SettingsPage::PAGE_SLUG ) );
		printf( '<input type="hidden" name="tab" value="%s" />', esc_attr( SettingsSchema::TAB_LOGS ) );
		printf( '<input type="hidden" name="channel" value="%s" />', esc_attr( $this->current_channel() ) );

		$table->search_box( __( 'Search log', 'localized-price-manager' ), 'lpm-log-search' );
		$table->display();

		echo '</form>';
		$this->render_clear_confirmation_script();
		echo '</div>';
	}

	/**
	 * Delete the entries the current filter matches, when that is what was asked.
	 *
	 * @return string|null URL to redirect to, or null when this is an ordinary view.
	 */
	public function handle_clear_request(): ?string {
		if ( ! isset( $_POST[ LogsListTable::CLEAR_REQUEST_KEY ] ) ) {
			return null;
		}

		if ( ! Core::can_purge_logs() ) {
			wp_die(
				esc_html__( 'You are not allowed to delete diagnostic log entries.', 'localized-price-manager' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( NonceEnum::ClearLogs->action(), NonceEnum::ClearLogs->value );

		$filters = $this->get_table()->build_filters();

		if ( is_wp_error( $filters ) ) {
			return $this->redirect_url( array( 'lpm-clear-failed' => 1 ) );
		}

		$deleted = $this->repository->delete_filtered_logs( $filters );

		if ( is_wp_error( $deleted ) ) {
			return $this->redirect_url( array( 'lpm-clear-failed' => 1 ) );
		}

		return $this->redirect_url( array( 'lpm-cleared' => $deleted ) );
	}

	/**
	 * Read the channel currently being viewed.
	 *
	 * @return string Sanitized channel slug.
	 */
	private function current_channel(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['channel'] ) || ! is_scalar( $_GET['channel'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return sanitize_key( wp_unslash( (string) $_GET['channel'] ) );
	}

	/**
	 * Build a redirect back to this screen, keeping the filter the operator set.
	 *
	 * @param array<string, int|string> $extra Arguments describing the outcome.
	 * @return string Redirect URL.
	 */
	private function redirect_url( array $extra ): string {
		$args = array();

		foreach ( array( 'channel', 'level', 's', 'date_from', 'date_to' ) as $key ) {
			$value = $this->posted( $key );

			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}

		return self::tab_url( array_merge( $args, $extra ) );
	}

	/**
	 * Read one posted filter value.
	 *
	 * @param string $key Request key.
	 * @return string Sanitized value, empty when absent.
	 */
	private function posted( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verified the nonce before reading any of this.
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		return trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) );
	}

	/**
	 * Report the outcome of a clear, once, after the redirect.
	 *
	 * @return void
	 */
	private function render_cleared_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reporting an outcome the redirect carried; it changes nothing.
		if ( isset( $_GET['lpm-clear-failed'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'The log entries could not be deleted. Nothing was removed.', 'localized-price-manager' )
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		if ( ! isset( $_GET['lpm-cleared'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$deleted = absint( $_GET['lpm-cleared'] );

		printf(
			'<div class="notice notice-success"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of deleted log entries. */
					_n( 'Deleted %s log entry.', 'Deleted %s log entries.', $deleted, 'localized-price-manager' ),
					number_format_i18n( $deleted )
				)
			)
		);
	}

	/**
	 * Attach the confirmation asked before deleting log entries.
	 *
	 * @return void
	 */
	private function render_clear_confirmation_script(): void {
		if ( ! Core::can_purge_logs() ) {
			return;
		}

		$message = wp_json_encode(
			/* translators: %s: number of log entries the current filter matches. */
			__( 'Delete %s log entries matching the current filter? This cannot be undone.', 'localized-price-manager' )
		);

		wp_register_script( self::SCRIPT_HANDLE, false, array(), Core::get_version(), true );
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'document.addEventListener("click",function(e){var b=e.target.closest(".lpm-clear-logs");if(!b)return;if(!window.confirm(' . $message . '.replace("%s",b.dataset.lpmClearCount)))e.preventDefault();});'
		);
	}
}
