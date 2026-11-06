<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\ExchangeRates\Services;

use D6N\LPM\Core\Money\DecimalMath;
use D6N\LPM\Core\Money\ExchangeRate;
use D6N\LPM\Modules\ExchangeRates\Repositories\ExchangeRateRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a rate between two currencies from the locally stored rate table.
 */
class ExchangeRateLookupService {

	/**
	 * Maximum number of chained steps allowed to resolve one pair.
	 *
	 * @var int
	 */
	private const MAX_STEPS = 3;

	/**
	 * Decimal precision of the stored rates, matching decimal(20,12).
	 *
	 * @var int
	 */
	private const RATE_SCALE = 12;

	/**
	 * Per request memo of single hop lookups, keyed "FROM_TO".
	 *
	 * @var array<string, string|null>
	 */
	private array $pair_cache = array();

	/**
	 * Constructor.
	 *
	 * @param ExchangeRateRepository $repository    Exchange rate repository.
	 * @param string                 $base_currency Shop base currency code.
	 */
	public function __construct(
		private readonly ExchangeRateRepository $repository,
		private readonly string $base_currency
	) {}

	/**
	 * Get the rate that converts one unit of $from into $to.
	 *
	 * @param string $from Source currency code.
	 * @param string $to   Target currency code.
	 * @param int    $step Current step in the resolution chain, starting at 1.
	 * @return string|null Rate as decimal text, or null when the pair cannot be resolved.
	 */
	public function get_rate( string $from, string $to, int $step = 1 ): ?string {
		if ( $step > self::MAX_STEPS ) {
			return null;
		}

		$from = strtoupper( trim( $from ) );
		$to   = strtoupper( trim( $to ) );

		if ( $from === $to ) {
			return '1';
		}

		$single_hop = $this->resolve_single_hop( $from, $to );
		if ( null !== $single_hop ) {
			return $single_hop;
		}

		foreach ( $this->get_pivot_currencies() as $pivot ) {
			if ( $pivot === $from || $pivot === $to ) {
				continue;
			}

			$first_leg = $this->resolve_single_hop( $from, $pivot );
			if ( null === $first_leg ) {
				continue;
			}

			$second_leg = $this->get_rate( $pivot, $to, $step + 1 );
			if ( null === $second_leg ) {
				continue;
			}

			return DecimalMath::multiply( $first_leg, $second_leg, self::RATE_SCALE );
		}

		return null;
	}

	/**
	 * Drop the memoised lookups.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->pair_cache = array();
	}

	/**
	 * Resolve a pair from a single stored row, direct or inverted.
	 *
	 * @param string $from Source currency code.
	 * @param string $to   Target currency code.
	 * @return string|null Rate as decimal text, or null when neither direction is stored.
	 */
	private function resolve_single_hop( string $from, string $to ): ?string {
		$direct = $this->read_rate( $from, $to );
		if ( null !== $direct ) {
			return $direct;
		}

		$inverse = $this->read_rate( $to, $from );
		if ( null === $inverse ) {
			return null;
		}

		return DecimalMath::divide( '1', $inverse, self::RATE_SCALE );
	}

	/**
	 * Get the currencies the rate table is populated for.
	 *
	 * @return array<string> Pivot currency codes.
	 */
	private function get_pivot_currencies(): array {
		$pivots = array( strtoupper( trim( $this->base_currency ) ), 'USD', 'EUR' );

		return array_values( array_unique( array_filter( $pivots ) ) );
	}

	/**
	 * Read one stored rate, treating a repository error as "no rate".
	 *
	 * @param string $from Source currency code.
	 * @param string $to   Target currency code.
	 * @return string|null Positive stored rate as decimal text, or null.
	 */
	private function read_rate( string $from, string $to ): ?string {
		$cache_key = $from . '_' . $to;

		if ( array_key_exists( $cache_key, $this->pair_cache ) ) {
			return $this->pair_cache[ $cache_key ];
		}

		$stored = $this->repository->get_currency_rate( $from, $to );
		$rate   = is_wp_error( $stored ) ? null : ExchangeRate::from( $stored );

		$this->pair_cache[ $cache_key ] = $rate?->value();

		return $this->pair_cache[ $cache_key ];
	}
}
