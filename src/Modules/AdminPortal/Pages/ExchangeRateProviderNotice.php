<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Pages;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\Settings\SettingsSchema;
use D6N\LPM\Modules\ExchangeRates\Providers\ExchangeRateProviderRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Admin warning shown while no exchange rate provider is ticked.
 */
class ExchangeRateProviderNotice implements IHookable {

	/**
	 * Constructor.
	 *
	 * @param ExchangeRateProviderRegistry $providers Exchange rate provider registry.
	 */
	public function __construct( private readonly ExchangeRateProviderRegistry $providers ) {}

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
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Print the warning while the provider queue is empty.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Core::get_admin_capability() ) || $this->providers->has_providers() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Localized Price Manager: exchange rates are not refreshed until at least one exchange rate provider is ticked. Converted prices use the rates already stored.', 'localized-price-manager' ),
			esc_url( self::settings_url() ),
			esc_html__( 'Open the Rate Settings', 'localized-price-manager' )
		);
	}

	/**
	 * Build the URL of the Rate Settings tab.
	 *
	 * @return string Admin URL.
	 */
	public static function settings_url(): string {
		return add_query_arg(
			array(
				'page' => SettingsPage::PAGE_SLUG,
				'tab'  => SettingsSchema::TAB_EXCHANGE_RATES,
			),
			admin_url( 'admin.php' )
		);
	}
}
