<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Models;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable UTC bounds for the log viewer's date range filter.
 */
class LogDateRange {

	/**
	 * JSON Schema pattern an operator supplied date must match.
	 *
	 * @var string
	 */
	public const DATE_PATTERN = '^\d{4}-\d{2}-\d{2}$';

	/**
	 * Constructor.
	 *
	 * @param string $date_from Lower bound as a UTC `Y-m-d H:i:s` string, or an empty string for "unbounded".
	 * @param string $date_to   Upper bound as a UTC `Y-m-d H:i:s` string, or an empty string for "unbounded".
	 */
	private function __construct(
		private readonly string $date_from,
		private readonly string $date_to
	) {}

	/**
	 * Build the UTC bounds from two dates typed in the site timezone.
	 *
	 * @param string $raw_from Lower bound as `Y-m-d`, or an empty string.
	 * @param string $raw_to   Upper bound as `Y-m-d`, or an empty string.
	 * @return self|WP_Error Range, or WP_Error when a date is unparsable or the range is reversed.
	 */
	public static function from_local_dates( string $raw_from, string $raw_to ): self|WP_Error {
		$from = trim( $raw_from );
		$to   = trim( $raw_to );

		$start = ( '' === $from ) ? '' : self::start_of_day( $from );

		if ( null === $start ) {
			return new WP_Error(
				'lpm_invalid_log_date_from',
				__( 'The start date must be a real calendar date written as YYYY-MM-DD.', 'localized-price-manager' )
			);
		}

		$end = ( '' === $to ) ? '' : self::end_of_day( $to );

		if ( null === $end ) {
			return new WP_Error(
				'lpm_invalid_log_date_to',
				__( 'The end date must be a real calendar date written as YYYY-MM-DD.', 'localized-price-manager' )
			);
		}

		if ( '' !== $start && '' !== $end && $start > $end ) {
			return new WP_Error(
				'lpm_invalid_log_date_range',
				__( 'The start date must not be later than the end date.', 'localized-price-manager' )
			);
		}

		return new self( $start, $end );
	}

	/**
	 * Get the bounds as repository filter arguments.
	 *
	 * @return array<string, string> Subset of `date_from` and `date_to`.
	 */
	public function to_filters(): array {
		$filters = array();

		if ( '' !== $this->date_from ) {
			$filters['date_from'] = $this->date_from;
		}

		if ( '' !== $this->date_to ) {
			$filters['date_to'] = $this->date_to;
		}

		return $filters;
	}

	/**
	 * Convert a local calendar date to the UTC instant its day starts at.
	 *
	 * @param string $date Date as `Y-m-d`.
	 * @return string|null UTC `Y-m-d H:i:s`, or null when the date is not a real one.
	 */
	private static function start_of_day( string $date ): ?string {
		$midnight = self::local_midnight( $date );

		return ( null === $midnight ) ? null : self::to_utc( $midnight );
	}

	/**
	 * Convert a local calendar date to the last UTC instant of its day.
	 *
	 * @param string $date Date as `Y-m-d`.
	 * @return string|null UTC `Y-m-d H:i:s`, or null when the date is not a real one.
	 */
	private static function end_of_day( string $date ): ?string {
		$midnight = self::local_midnight( $date );

		return ( null === $midnight ) ? null : self::to_utc( $midnight->setTime( 23, 59, 59 ) );
	}

	/**
	 * Parse a calendar date as midnight in the site timezone.
	 *
	 * @param string $date Date as `Y-m-d`.
	 * @return DateTimeImmutable|null Local midnight, or null when the date is not a real one.
	 */
	private static function local_midnight( string $date ): ?DateTimeImmutable {
		if ( 1 !== preg_match( '#' . self::DATE_PATTERN . '#D', $date ) ) {
			return null;
		}

		$local = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );

		if ( false === $local || $local->format( 'Y-m-d' ) !== $date ) {
			return null;
		}

		return $local;
	}

	/**
	 * Render a local instant as the UTC string the `created_at` column stores.
	 *
	 * @param DateTimeImmutable $local Instant in the site timezone.
	 * @return string UTC `Y-m-d H:i:s`.
	 */
	private static function to_utc( DateTimeImmutable $local ): string {
		return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
