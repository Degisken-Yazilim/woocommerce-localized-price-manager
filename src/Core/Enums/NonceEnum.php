<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress nonce action names and nonce keys.
 */
enum NonceEnum: string {
	case Settings       = 'd6n_lpm_settings_nonce';
	case ProductMetabox = 'd6n_lpm_metabox_nonce';
	case Ajax           = 'd6n_lpm_ajax_nonce';
	case ClearLogs      = 'd6n_lpm_clear_logs_nonce';
	case RefreshRates   = 'd6n_lpm_refresh_rates_nonce';

	/**
	 * Get the nonce action string.
	 *
	 * @return string
	 */
	public function action(): string {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return match ( $this ) {
			self::Settings       => 'd6n_lpm_save_settings',
			self::ProductMetabox => 'd6n_lpm_save_metabox',
			self::Ajax           => 'd6n_lpm_ajax',
			self::ClearLogs      => 'd6n_lpm_clear_logs',
			self::RefreshRates   => 'd6n_lpm_refresh_rates',
		};
	}
}
