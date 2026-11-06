<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Exchange rate update frequency settings.
 */
enum ExchangeFrequencyEnum: string {
	case ThreeHours = 'three_hours';
	case FourHours  = 'four_hours';
	case SixHours   = 'six_hours';
	case Daily      = 'daily';
}
