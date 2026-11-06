<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates\Repositories;

use D6N\LPM\Core\Database\AbstractRepository;
use D6N\LPM\Core\Money\DecimalText;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Exchange rates repository.
 */
class ExchangeRateRepository extends AbstractRepository {

	/**
	 * Get single currency pair rate.
	 *
	 * @param string $base   Base currency.
	 * @param string $target Target currency.
	 * @return string|WP_Error|null Rate as decimal text, null when absent, or WP_Error on failure.
	 */
	public function get_currency_rate( string $base, string $target ): string|WP_Error|null {
		return $this->guarded(
			function () use ( $base, $target ): ?string {
				$result = $this->mapper->get_where(
					array(
						'base_currency'   => strtoupper( $base ),
						'target_currency' => strtoupper( $target ),
					)
				);

				if ( empty( $result ) ) {
					return null;
				}

				$row = $result[0];

				return isset( $row->exchange_rate ) ? DecimalText::plain( $row->exchange_rate ) : null;
			}
		);
	}

	/**
	 * Get the moment the rate table was last written.
	 *
	 * @return int|WP_Error Unix timestamp, 0 when the table holds no rate, or WP_Error on failure.
	 */
	public function get_last_updated_timestamp(): int|WP_Error {
		return $this->guarded(
			function (): int {
				$rows = $this->mapper->query( array(), array(), 'last_updated', 'DESC', 1 );

				if ( array() === $rows ) {
					return 0;
				}

				$stamped = $rows[0]->last_updated ?? '';

				if ( ! is_string( $stamped ) || '' === $stamped ) {
					return 0;
				}

				$timestamp = strtotime( $stamped . ' UTC' );

				return false === $timestamp ? 0 : $timestamp;
			}
		);
	}

	/**
	 * Insert or refresh a set of rate rows in one statement.
	 *
	 * @param array<array<string, mixed>> $rates Rate rows.
	 * @return int|WP_Error Number of affected rows, or WP_Error on failure.
	 */
	public function bulk_upsert_rates( array $rates ): int|WP_Error {
		$rows = array_values( array_filter( $rates, 'is_array' ) );

		if ( empty( $rows ) ) {
			return 0;
		}

		return $this->guarded(
			fn (): int => $this->mapper->bulk_upsert( $rows, array( 'exchange_rate', 'source' ) )
		);
	}
}
