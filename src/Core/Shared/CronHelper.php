<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Shared;

use D6N\LPM\Core\Enums\CronScheduleEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Helper class for custom WordPress cron schedule registration.
 */
// phpcs:disable WordPress.WP.CronInterval.ChangeDetected
class CronHelper {

	/**
	 * Register the three-hour cron schedule (8 times per day).
	 *
	 * @return void
	 */
	public static function register_three_hours_schedule(): void {
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				$schedules[ CronScheduleEnum::ThreeHours->value ] = array(
					'interval' => CronScheduleEnum::ThreeHours->interval(),
					'display'  => __( 'Every 3 Hours (8 times per day)', 'localized-price-manager' ),
				);
				return $schedules;
			},
			11
		);
	}

	/**
	 * Register the four-hour cron schedule.
	 *
	 * @return void
	 */
	public static function register_four_hours_schedule(): void {
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				$schedules[ CronScheduleEnum::FourHours->value ] = array(
					'interval' => CronScheduleEnum::FourHours->interval(),
					'display'  => __( 'Every 4 Hours', 'localized-price-manager' ),
				);
				return $schedules;
			},
			11
		);
	}

	/**
	 * Register the six-hour cron schedule.
	 *
	 * @return void
	 */
	public static function register_six_hours_schedule(): void {
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				$schedules[ CronScheduleEnum::SixHours->value ] = array(
					'interval' => CronScheduleEnum::SixHours->interval(),
					'display'  => __( 'Every 6 Hours', 'localized-price-manager' ),
				);
				return $schedules;
			},
			11
		);
	}
}
// phpcs:enable WordPress.WP.CronInterval.ChangeDetected
