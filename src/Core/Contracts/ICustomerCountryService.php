<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Contracts;

use D6N\LPM\Core\Enums\IPDetectionStrategyEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for customer country detection services.
 */
interface ICustomerCountryService extends IHookable {

	/**
	 * Get the current visitor's country code.
	 *
	 * @return string|null ISO country code or null if not detected.
	 */
	public function get_current_user_country(): ?string;

	/**
	 * Configure the IP detection strategy from plugin settings.
	 *
	 * @param IPDetectionStrategyEnum $default_strategy The default strategy.
	 * @return IPDetectionStrategyEnum The configured strategy.
	 */
	public function configure_ip_detection_strategy( IPDetectionStrategyEnum $default_strategy ): IPDetectionStrategyEnum;
}
