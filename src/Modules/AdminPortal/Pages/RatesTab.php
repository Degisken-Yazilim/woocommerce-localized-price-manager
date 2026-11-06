<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Pages;

use D6N\LPM\Core\Enums\NonceEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\Settings\SettingsSchema;
use D6N\LPM\Modules\ExchangeRates\Repositories\ExchangeRateRepository;
use D6N\LPM\Modules\ExchangeRates\Services\ExchangeRatesService;

defined( 'ABSPATH' ) || exit;

/**
 * The stored exchange rates tab.
 */
class RatesTab {

	/**
	 * Request key the refresh button submits under.
	 *
	 * @var string
	 */
	public const REFRESH_REQUEST_KEY = 'lpm_refresh_rates';

	/**
	 * Build the URL of this tab, with arguments.
	 *
	 * @param array<string, int|string> $args Extra query arguments.
	 * @return string Absolute admin URL.
	 */
	public static function tab_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => SettingsPage::PAGE_SLUG,
					'tab'  => SettingsSchema::TAB_RATES,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * List table instance, built once the screen is known.
	 *
	 * @var RatesListTable|null
	 */
	private ?RatesListTable $table = null;

	/**
	 * Constructor.
	 *
	 * @param ExchangeRateRepository    $repository Rate repository instance.
	 * @param ExchangeRatesService|null $service    Rate update service, when one is wired.
	 */
	public function __construct(
		private readonly ExchangeRateRepository $repository,
		private readonly ?ExchangeRatesService $service = null
	) {}

	/**
	 * Get the list table, building it on first use.
	 *
	 * @return RatesListTable List table.
	 */
	public function get_table(): RatesListTable {
		if ( null === $this->table ) {
			$this->table = new RatesListTable( $this->repository );
		}

		return $this->table;
	}

	/**
	 * Render the tab body.
	 *
	 * @return void
	 */
	public function render(): void {
		$table = $this->get_table();
		$table->prepare_items();

		echo '<div class="lpm-rates">';

		$this->render_outcome_notice();
		$this->render_summary();
		$this->render_refresh_form();

		$table->display();

		echo '</div>';
	}

	/**
	 * Refresh the rates when that is what was asked.
	 *
	 * @return string|null URL to redirect to, or null when this is an ordinary view.
	 */
	public function handle_refresh_request(): ?string {
		if ( ! isset( $_POST[ self::REFRESH_REQUEST_KEY ] ) ) {
			return null;
		}

		if ( ! current_user_can( Core::get_admin_capability() ) ) {
			wp_die(
				esc_html__( 'You are not allowed to refresh the exchange rates.', 'localized-price-manager' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( NonceEnum::RefreshRates->action(), NonceEnum::RefreshRates->value );

		if ( null === $this->service ) {
			return self::tab_url( array( 'lpm-rates-unavailable' => 1 ) );
		}

		$outcome = $this->service->update_exchange_rates() ? 'lpm-rates-refreshed' : 'lpm-rates-failed';

		return self::tab_url( array( $outcome => 1 ) );
	}

	/**
	 * Report the outcome of a refresh, once, after the redirect.
	 *
	 * @return void
	 */
	private function render_outcome_notice(): void {
		$notices = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reporting an outcome the redirect carried; it changes nothing.
			'lpm-rates-refreshed'   => array( 'success', __( 'Exchange rates updated.', 'localized-price-manager' ) ),
			'lpm-rates-failed'      => array( 'error', __( 'No provider could supply rates. Check the service log.', 'localized-price-manager' ) ),
			'lpm-rates-unavailable' => array( 'error', __( 'The exchange rates service is not available.', 'localized-price-manager' ) ),
		);

		foreach ( $notices as $key => $notice ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			if ( ! isset( $_GET[ $key ] ) ) {
				continue;
			}

			printf(
				'<div class="notice notice-%1$s"><p>%2$s</p></div>',
				esc_attr( $notice[0] ),
				esc_html( $notice[1] )
			);

			return;
		}
	}

	/**
	 * State when the table as a whole was last written.
	 *
	 * @return void
	 */
	private function render_summary(): void {
		$timestamp = $this->repository->get_last_updated_timestamp();
		$timestamp = is_wp_error( $timestamp ) ? 0 : (int) $timestamp;

		if ( 0 === $timestamp ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'No rate has been stored yet. Rates arrive with the scheduled refresh, or with the button below.', 'localized-price-manager' )
			);

			return;
		}

		$format = (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: date and time of the most recent rate update. */
					__( 'Rates last updated: %s', 'localized-price-manager' ),
					(string) wp_date( $format, $timestamp )
				)
			)
		);
	}

	/**
	 * Render the manual refresh button.
	 *
	 * @return void
	 */
	private function render_refresh_form(): void {
		echo '<form method="post" class="lpm-rates-refresh">';

		wp_nonce_field( NonceEnum::RefreshRates->action(), NonceEnum::RefreshRates->value );

		printf(
			'<button type="submit" class="button" name="%1$s" value="1">%2$s</button>',
			esc_attr( self::REFRESH_REQUEST_KEY ),
			esc_html__( 'Refresh rates now', 'localized-price-manager' )
		);

		echo '</form>';
	}
}
