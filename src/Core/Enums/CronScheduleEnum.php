<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Custom cron schedule identifiers and interval durations.
 */
enum CronScheduleEnum: string {
	case ThreeHours = 'three_hours';
	case FourHours  = 'four_hours';
	case SixHours   = 'six_hours';

	/**
	 * Get interval duration in seconds for this schedule.
	 *
	 * @return int Interval in seconds.
	 */
	public function interval(): int {
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return match ( $this ) {
			self::ThreeHours => 60 * 60 * 3,
			self::FourHours  => 60 * 60 * 4,
			self::SixHours   => 60 * 60 * 6,
		};
	}
}
