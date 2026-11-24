<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Pages;

use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\Controllers\SettingsController;
use D6N\LPM\Modules\AdminPortal\Settings\FieldRenderer;
use D6N\LPM\Modules\AdminPortal\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * The tabbed settings screen.
 */
class SettingsPage {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'localized-price-manager';

	/**
	 * Constructor.
	 *
	 * @param SettingsController $settings  Settings controller, reused for its value collection.
	 * @param FieldRenderer      $renderer  Field renderer.
	 * @param CountriesTab       $countries Renderer of the countries and groups tab.
	 * @param LogsPage           $logs      Renderer of the diagnostic log tab.
	 * @param RatesTab           $rates     Renderer of the stored exchange rates tab.
	 */
	public function __construct(
		private readonly SettingsController $settings,
		private readonly FieldRenderer $renderer,
		private readonly CountriesTab $countries,
		private readonly LogsPage $logs,
		private readonly RatesTab $rates
	) {}

	/**
	 * Get the tab currently being viewed.
	 *
	 * @return string Tab slug.
	 */
	public function get_current_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( (string) $_GET['tab'] ) )
			: '';

		return SettingsSchema::has_tab( $requested ) ? $requested : SettingsSchema::get_default_tab();
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Core::get_admin_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'localized-price-manager' ), '', array( 'response' => 403 ) );
		}

		$tab = $this->get_current_tab();

		if ( SettingsSchema::TAB_LOGS === $tab ) {
			$redirect = $this->logs->handle_clear_request();

			if ( null !== $redirect ) {
				wp_safe_redirect( $redirect );

				exit;
			}
		}

		if ( SettingsSchema::TAB_RATES === $tab ) {
			$redirect = $this->rates->handle_refresh_request();

			if ( null !== $redirect ) {
				wp_safe_redirect( $redirect );

				exit;
			}
		}

		echo '<div class="wrap lpm-settings">';
		echo '<h1 class="wp-heading-inline">';
		echo '<img src="' . esc_url( Core::get_plugin_dir_url() . 'assets/images/logo-512x512.png' ) . '" alt="" width="36" height="36" class="lpm-header-logo"> ';
		echo esc_html__( 'Localized Price Manager', 'localized-price-manager' );
		echo '</h1>';

		$this->render_tab_nav( $tab );

		echo '<div class="lpm-notice-area" id="lpm-notices" role="status" aria-live="polite"></div>';

		if ( SettingsSchema::TAB_COUNTRIES === $tab ) {
			$this->countries->render();
		} elseif ( SettingsSchema::TAB_LOGS === $tab ) {
			$this->logs->render();
		} elseif ( SettingsSchema::TAB_RATES === $tab ) {
			$this->rates->render();
		} else {
			if ( SettingsSchema::TAB_GEOLOCATION === $tab && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$preview_url = wp_nonce_url( add_query_arg( 'lpm_country', 'DE', home_url( '/' ) ), 'd6n_lpm_country_preview' );
				printf(
					'<p><a id="lpm-country-preview-url" href="%1$s">%2$s</a> %3$s</p>',
					esc_url( $preview_url ),
					esc_html__( 'Preview the store as Germany', 'localized-price-manager' ),
					esc_html__( 'Change DE in the link to another country code or clear to end the preview.', 'localized-price-manager' )
				);
			}
			$this->render_settings_form( $tab );
		}

		echo '</div>';
	}

	/**
	 * Render the tab navigation.
	 *
	 * @param string $current Active tab slug.
	 * @return void
	 */
	private function render_tab_nav( string $current ): void {
		echo '<nav class="nav-tab-wrapper lpm-tab-nav">';

		foreach ( SettingsSchema::get_tabs() as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $this->get_tab_url( $slug ) ),
				$slug === $current ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Render one tab's form, pre-filled from the stored values.
	 *
	 * @param string $tab Tab slug.
	 * @return void
	 */
	private function render_settings_form( string $tab ): void {
		$definitions = SettingsSchema::for_tab( $tab );
		$values      = $this->settings->collect_values( $tab );

		printf(
			'<form class="lpm-settings-form" id="lpm-settings-form" method="post" data-lpm-tab="%1$s" data-lpm-route="%2$s">',
			esc_attr( $tab ),
			esc_attr( 'settings/' . $tab )
		);

		foreach ( $definitions as $key => $definition ) {
			$this->renderer->render( $definition, $values[ $key ] ?? $definition->default_value );
		}

		echo '<p class="lpm-form-actions submit">';
		echo '<button type="submit" class="button button-primary lpm-btn lpm-btn-primary" id="lpm-save-settings">' . esc_html__( 'Save changes', 'localized-price-manager' ) . '</button>';

		if ( SettingsSchema::TAB_EXCHANGE_RATES === $tab ) {
			echo ' <button type="button" class="button lpm-btn" id="lpm-refresh-rates">' . esc_html__( 'Update rates now', 'localized-price-manager' ) . '</button>';
		}

		echo '</p>';
		echo '</form>';
	}

	/**
	 * Build the URL of one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string Admin URL.
	 */
	private function get_tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}
}
