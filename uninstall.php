<?php
/**
 * Uninstall routine for Localized Price Manager.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'D6N_LPM_DOMAIN' ) ) {
	define( 'D6N_LPM_DOMAIN', 'localized-price-manager' );
}

if ( ! defined( 'D6N_LPM_VERSION' ) ) {
	define( 'D6N_LPM_VERSION', '1.0.0' );
}

if ( ! defined( 'D6N_LPM_DIR' ) ) {
	define( 'D6N_LPM_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'D6N_LPM_DIR_URL' ) ) {
	define( 'D6N_LPM_DIR_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'D6N_LPM_BASENAME' ) ) {
	define( 'D6N_LPM_BASENAME', plugin_basename( __DIR__ . '/localized-price-manager.php' ) );
}

if ( ! defined( 'D6N_LPM_MIN_PHP_VERSION' ) ) {
	define( 'D6N_LPM_MIN_PHP_VERSION', '8.2' );
}

if ( ! defined( 'D6N_LPM_MIN_WP_VERSION' ) ) {
	define( 'D6N_LPM_MIN_WP_VERSION', '6.2' );
}

if ( ! defined( 'D6N_LPM_MIN_WC_VERSION' ) ) {
	define( 'D6N_LPM_MIN_WC_VERSION', '7.9' );
}

require_once __DIR__ . '/autoload.php';

\D6N\LPM\Core\Lifecycle\Uninstaller::uninstall();
