<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Standard WooCommerce hook execution priorities.
 */
enum WooHookPriorityEnum: int {
	case Early    = 5;
	case Standard = 10;
	case Late     = 20;
	case VeryLate = 99;
}
