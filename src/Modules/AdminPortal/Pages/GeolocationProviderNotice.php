<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Pages;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\Settings\SettingsSchema;
use D6N\LPM\Modules\Geolocation\Services\GeolocationProviderQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Admin warning shown while no geolocation provider is enabled.
 */
class GeolocationProviderNotice implements IHookable {

	/**
	 * Constructor.
	 *
	 * @param GeolocationProviderQueue $providers Configured provider queue.
	 */
	public function __construct( private readonly GeolocationProviderQueue $providers ) {}

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
	 * Print the warning while the provider chain is empty.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Core::get_admin_capability() ) || $this->providers->has_providers() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Localized Price Manager: localized prices will not work until at least one geolocation provider is enabled.', 'localized-price-manager' ),
			esc_url( self::settings_url() ),
			esc_html__( 'Open the Geolocation settings', 'localized-price-manager' )
		);
	}

	/**
	 * Build the URL of the Geolocation settings tab.
	 *
	 * @return string Admin URL.
	 */
	public static function settings_url(): string {
		return add_query_arg(
			array(
				'page' => SettingsPage::PAGE_SLUG,
				'tab'  => SettingsSchema::TAB_GEOLOCATION,
			),
			admin_url( 'admin.php' )
		);
	}
}
