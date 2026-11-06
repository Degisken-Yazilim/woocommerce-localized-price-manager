<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Repositories;

use D6N\LPM\Core\Database\AbstractRepository;
use D6N\LPM\Modules\DiagnosticLog\Enums\LogChannelEnum;
use WP_Error;
use wpdb;

defined( 'ABSPATH' ) || exit;

/**
 * Repository implementation for diagnostic log entries.
 */
class LogRepository extends AbstractRepository {

	/**
	 * Seconds in one retention day.
	 *
	 * @var int
	 */
	private const SECONDS_PER_DAY = 86400;

	/**
	 * Get logs with filter criteria.
	 *
	 * @param array<string, mixed> $args Filter arguments (channel, level, source, message, user_id, date_from, date_to, limit, offset).
	 * @return array<array<string, mixed>>|WP_Error Array of log records, or WP_Error on failure.
	 */
	public function get_filtered_logs( array $args = array() ): array|WP_Error {
		return $this->guarded(
			function () use ( $args ): array {
				$where = $this->build_where_conditions( $args );

				$limit    = isset( $args['limit'] ) ? (int) $args['limit'] : 100;
				$offset   = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
				$order_by = isset( $args['order_by'] ) ? (string) $args['order_by'] : 'created_at';
				$order    = isset( $args['order'] ) ? (string) $args['order'] : 'DESC';

				$results = $this->mapper->query( $where, array(), $order_by, $order, $limit, $offset );

				return $this->to_array_collection( $results );
			}
		);
	}

	/**
	 * Count matching log entries.
	 *
	 * @param array<string, mixed> $args Filter criteria.
	 * @return int|WP_Error Total matching records count, or WP_Error on failure.
	 */
	public function count_filtered_logs( array $args = array() ): int|WP_Error {
		return $this->guarded(
			fn (): int => $this->mapper->count( $this->build_where_conditions( $args ) )
		);
	}

	/**
	 * Delete every entry matching a filter, except the audit trail.
	 *
	 * @param array<string, mixed> $args Filter criteria, same shape as the read methods.
	 * @return int|WP_Error Number of deleted records, or WP_Error on failure.
	 */
	public function delete_filtered_logs( array $args = array() ): int|WP_Error {
		return $this->guarded(
			function () use ( $args ): int {
				$where   = $this->build_where_conditions( $args );
				$where[] = array( 'channel', '!=', LogChannelEnum::Audit->value );

				return $this->mapper->delete_where( $where );
			}
		);
	}

	/**
	 * Count the entries a filtered delete would remove.
	 *
	 * @param array<string, mixed> $args Filter criteria.
	 * @return int|WP_Error Matching record count, or WP_Error on failure.
	 */
	public function count_deletable_logs( array $args = array() ): int|WP_Error {
		return $this->guarded(
			function () use ( $args ): int {
				$where   = $this->build_where_conditions( $args );
				$where[] = array( 'channel', '!=', LogChannelEnum::Audit->value );

				return $this->mapper->count( $where );
			}
		);
	}

	/**
	 * Purge logs older than specified retention days.
	 *
	 * @param int $retention_days Number of days to keep (default 30).
	 * @return int|WP_Error Number of purged records, or WP_Error on failure.
	 */
	public function purge_old_logs( int $retention_days = 30 ): int|WP_Error {
		return $this->guarded(
			function () use ( $retention_days ): int {
				$cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * self::SECONDS_PER_DAY ) );

				return $this->mapper->delete_where(
					array(
						array( 'created_at', '<', $cutoff_date ),
					)
				);
			}
		);
	}

	/**
	 * Get the entries recorded for one user, oldest first.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $user_login User login.
	 * @param int    $limit      Page size.
	 * @param int    $offset     Rows to skip.
	 * @return array<array<string, mixed>>|WP_Error Matching records, or WP_Error on failure.
	 */
	public function get_user_logs( int $user_id, string $user_login, int $limit, int $offset ): array|WP_Error {
		return $this->guarded(
			fn (): array => $this->to_array_collection(
				$this->mapper->query( self::user_conditions( $user_id, $user_login ), array(), 'id', 'ASC', $limit, $offset )
			)
		);
	}

	/**
	 * Remove the user identity and the IP address from every entry recorded for one user.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $user_login User login.
	 * @return int|WP_Error Number of anonymised records, or WP_Error on failure.
	 */
	public function anonymize_user_logs( int $user_id, string $user_login ): int|WP_Error {
		return $this->guarded(
			fn (): int => $this->mapper->update_where(
				array(
					'user_id'    => null,
					'user_login' => null,
					'ip_address' => null,
				),
				self::user_conditions( $user_id, $user_login )
			)
		);
	}

	/**
	 * Build the conditions matching the entries recorded for one user.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $user_login User login.
	 * @return array<int|string, mixed> Conditions accepted by IDataMapper.
	 */
	private static function user_conditions( int $user_id, string $user_login ): array {
		return array(
			'OR' => array(
				'user_id'    => $user_id,
				'user_login' => $user_login,
			),
		);
	}

	/**
	 * Build where condition array from input filters.
	 *
	 * @param array<string, mixed> $args Filter parameters.
	 * @return array<int|string, mixed> Conditions accepted by IDataMapper.
	 */
	private function build_where_conditions( array $args ): array {
		$where = array();

		if ( ! empty( $args['channel'] ) ) {
			$where['channel'] = (string) $args['channel'];
		}

		if ( ! empty( $args['level'] ) ) {
			$where['level'] = (string) $args['level'];
		}

		if ( ! empty( $args['source'] ) ) {
			$where[] = array( 'source', 'LIKE', self::like_term( (string) $args['source'] ) );
		}

		if ( ! empty( $args['message'] ) ) {
			$where[] = array( 'message', 'LIKE', self::like_term( (string) $args['message'] ) );
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where['user_id'] = (int) $args['user_id'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[] = array( 'created_at', '>=', (string) $args['date_from'] );
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[] = array( 'created_at', '<=', (string) $args['date_to'] );
		}

		return $where;
	}

	/**
	 * Wrap a search term into the `%…%` form with its own wildcards neutralised.
	 *
	 * @param string $term Raw search term.
	 * @return string Escaped, wrapped LIKE value.
	 */
	private static function like_term( string $term ): string {
		global $wpdb;

		$escaped = $wpdb instanceof wpdb
			? (string) $wpdb->esc_like( trim( $term ) )
			: addcslashes( trim( $term ), '_%\\' );

		return '%' . $escaped . '%';
	}
}
