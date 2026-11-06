<?php
/**
 * Plugin Name: Localized Price Manager
 * Description: Localized Price Manager for WooCommerce
 * Version: 1.0.0
 * Author: Türker Yıldırım <me@turkeryildirim.com>
 * Text Domain: localized-price-manager
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Requires PHP: 8.2
 * WC requires at least: 7.9
 * WC tested up to: 11.2
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

use D6N\LPM\Modules\Cli\CliModule;
use D6N\LPM\Core\Lifecycle\Activator;
use D6N\LPM\Core\Lifecycle\Deactivator;
use D6N\LPM\Core\Run;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\CronHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'D6N_LPM_BASENAME', plugin_basename( __FILE__ ) );
define( 'D6N_LPM_DIR', plugin_dir_path( __FILE__ ) );
define( 'D6N_LPM_DIR_URL', plugin_dir_url( __FILE__ ) );

const D6N_LPM_VERSION         = '1.0.0';
const D6N_LPM_MIN_PHP_VERSION = '8.2';
const D6N_LPM_MIN_WP_VERSION  = '6.2';
const D6N_LPM_MIN_WC_VERSION  = '7.9';
const D6N_LPM_DOMAIN          = 'localized-price-manager';

require_once D6N_LPM_DIR . 'autoload.php';

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		CronHelper::register_three_hours_schedule();
		CronHelper::register_four_hours_schedule();
		CronHelper::register_six_hours_schedule();

		load_plugin_textdomain(
			'localized-price-manager',
			false,
			dirname( D6N_LPM_BASENAME ) . '/languages/'
		);

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CliModule::register();
		}

		$active_wc  = function_exists( 'WC' ) ? (string) WC()->version : '';
		$wc_missing = ! class_exists( 'WooCommerce' );
		$wc_too_old = ! $wc_missing && '' !== $active_wc && version_compare( $active_wc, D6N_LPM_MIN_WC_VERSION, '<' );

		if ( $wc_missing || $wc_too_old ) {
			if ( is_admin() && ! wp_doing_ajax() ) {
				add_action(
					'admin_notices',
					static function () use ( $wc_missing, $active_wc ): void {
						$blocker = $wc_missing
							? __( 'Localized Price Manager requires WooCommerce to be installed and active.', 'localized-price-manager' )
							: sprintf(
								/* translators: 1: minimum supported WooCommerce version, 2: active WooCommerce version. */
								__( 'Localized Price Manager needs WooCommerce %1$s or newer. WooCommerce %2$s is active, so localized prices stay switched off.', 'localized-price-manager' ),
								D6N_LPM_MIN_WC_VERSION,
								$active_wc
							);

						Core::add_admin_notice( $blocker, 'error' );
						Core::display_admin_notices();
					}
				);
			}
			return;
		}

		add_action(
			'init',
			static function () {
				$app = new Run();
				$app->register();
			},
			1
		);
	},
	11
);
