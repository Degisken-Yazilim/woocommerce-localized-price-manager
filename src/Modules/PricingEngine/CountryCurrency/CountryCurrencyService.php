<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\CountryCurrency;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\CacheGroupEnum;
use D6N\LPM\Core\Reference\CountryCurrencyMap;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\Cache\ValueObjects\CacheMiss;
use D6N\LPM\Modules\PricingEngine\CountryGroup\CountryGroupRepository;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Read model over the country → currency mapping and its country group.
 */
class CountryCurrencyService {

	/**
	 * Request-scoped mapping memo, keyed by country code.
	 *
	 * @var array<string, stdClass|false>
	 */
	private array $mapping_memo = array();

	/**
	 * Constructor.
	 *
	 * @param CountryCurrencyRepository $mappings Country to currency mapping repository.
	 * @param CountryGroupRepository    $groups   Country group repository.
	 * @param ICache                    $cache    Cache adapter.
	 * @param ILogger                   $logger   Logger service.
	 */
	public function __construct(
		private readonly CountryCurrencyRepository $mappings,
		private readonly CountryGroupRepository $groups,
		private readonly ICache $cache,
		private readonly ILogger $logger
	) {}

	/**
	 * Get the active mapping row of a country.
	 *
	 * @param string $country_code ISO country code.
	 * @return stdClass|null Mapping row, or null when the country has none.
	 */
	public function get_mapping( string $country_code ): ?stdClass {
		$country_code = CountryCodeValidator::sanitize( $country_code );

		if ( '' === $country_code ) {
			return null;
		}

		if ( array_key_exists( $country_code, $this->mapping_memo ) ) {
			$memoised = $this->mapping_memo[ $country_code ];

			return false === $memoised ? null : $memoised;
		}

		$cache_key = 'country_mapping_' . $country_code;
		$cached    = $this->cache->get( $cache_key, CacheGroupEnum::CountryCurrency->value );

		if ( CacheMiss::instance() !== $cached ) {
			$row = $cached instanceof stdClass ? $cached : null;

			$this->mapping_memo[ $country_code ] = $row ?? false;

			return $row;
		}

		$row = $this->mappings->get_by_country( $country_code );

		if ( is_wp_error( $row ) ) {
			$this->logger->warning(
				sprintf( 'Country currency mapping lookup failed for %s.', $country_code ),
				array( 'error' => $row->get_error_message() ),
				'PricingEngine'
			);

			$row = null;
		}

		$row = $row instanceof stdClass ? $row : null;

		$this->cache->set( $cache_key, $row, CacheGroupEnum::CountryCurrency->ttl(), CacheGroupEnum::CountryCurrency->value );
		$this->mapping_memo[ $country_code ] = $row ?? false;

		return $row;
	}

	/**
	 * Get the currency code mapped to a country.
	 *
	 * @param string $country_code ISO country code.
	 * @return string|null Upper-case currency code, or null when unmapped.
	 */
	public function get_currency_for_country( string $country_code ): ?string {
		$mapping = $this->get_mapping( $country_code );

		if ( null === $mapping || empty( $mapping->currency_code ) ) {
			return null;
		}

		return strtoupper( (string) $mapping->currency_code );
	}

	/**
	 * Get the active country group of a country, if any.
	 *
	 * @param string $country_code ISO country code.
	 * @return stdClass|null Group row, or null when the country belongs to none.
	 */
	public function get_group_for_country( string $country_code ): ?stdClass {
		$mapping = $this->get_mapping( $country_code );

		if ( null === $mapping || empty( $mapping->group_id ) ) {
			return null;
		}

		$group = $this->groups->get_active_group( (int) $mapping->group_id );

		if ( is_wp_error( $group ) ) {
			$this->logger->warning(
				sprintf( 'Country group lookup failed for %s.', $country_code ),
				array( 'error' => $group->get_error_message() ),
				'PricingEngine'
			);

			return null;
		}

		return $group instanceof stdClass ? $group : null;
	}

	/**
	 * Get the presentation format of a country's active currency mapping.
	 *
	 * @param string $country_code ISO country code.
	 * @return array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int}|null Format, or null when the country has no active mapping.
	 */
	public function get_format_for_country( string $country_code ): ?array {
		$mapping = $this->get_mapping( $country_code );

		if ( null === $mapping ) {
			return null;
		}

		$currency = ! empty( $mapping->currency_code )
			? strtoupper( (string) $mapping->currency_code )
			: '';

		$symbol = ! empty( $mapping->currency_symbol )
			? (string) $mapping->currency_symbol
			: '';

		if ( '' === $symbol && '' !== $currency ) {
			$symbol = (string) get_woocommerce_currency_symbol( $currency );
		}

		return array(
			'currency'           => $currency,
			'symbol'             => $symbol,
			'decimal_separator'  => isset( $mapping->decimal_separator ) && '' !== $mapping->decimal_separator
				? (string) $mapping->decimal_separator
				: '.',
			'thousand_separator' => isset( $mapping->thousand_separator ) && '' !== $mapping->thousand_separator
				? (string) $mapping->thousand_separator
				: ',',
			'decimals'           => isset( $mapping->decimals )
				? (int) $mapping->decimals
				: 2,
		);
	}

	/**
	 * Get the presentation format of a currency, the visitor's mapping first when it names that currency.
	 *
	 * @param string      $currency_code Upper-case ISO currency code.
	 * @param string|null $country_code  Optional ISO country code for separators.
	 * @return array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int}
	 */
	public function get_format_for_currency( string $currency_code, ?string $country_code = null ): array {
		$currency = strtoupper( trim( $currency_code ) );
		$mapped   = ! empty( $country_code ) ? $this->get_format_for_country( $country_code ) : null;

		if ( null !== $mapped && $mapped['currency'] === $currency ) {
			return $mapped;
		}

		$symbol   = function_exists( 'get_woocommerce_currency_symbol' ) ? (string) get_woocommerce_currency_symbol( $currency ) : $currency;
		$decimals = Core::get_shop_base_currency() === $currency
			? Core::get_shop_price_decimals()
			: max( 0, CountryCurrencyMap::get_currency_decimals( $currency ) ?? Core::get_shop_price_decimals() );

		return array(
			'currency'           => $currency,
			'symbol'             => '' !== $symbol ? $symbol : $currency,
			'decimal_separator'  => null !== $mapped && '' !== $mapped['decimal_separator'] ? $mapped['decimal_separator'] : '.',
			'thousand_separator' => null !== $mapped && '' !== $mapped['thousand_separator'] ? $mapped['thousand_separator'] : ',',
			'decimals'           => $decimals,
		);
	}

	/**
	 * Restore a country's seeded native currency and number format.
	 *
	 * @param string $country_code ISO country code.
	 * @return int|WP_Error Affected row count, or WP_Error when the country is unknown or the write fails.
	 */
	public function reset_to_native( string $country_code ): int|WP_Error {
		$country_code = CountryCodeValidator::sanitize( $country_code );

		if ( '' === $country_code ) {
			return new WP_Error(
				'lpm_invalid_country_code',
				__( 'A valid country code is required to reset a currency mapping.', 'localized-price-manager' )
			);
		}

		$seed = CountryCurrencyMap::get_map()[ $country_code ] ?? null;

		if ( null === $seed ) {
			return new WP_Error(
				'lpm_unknown_country_code',
				__( 'No native currency is known for this country.', 'localized-price-manager' )
			);
		}

		$mapping = $this->get_mapping( $country_code );
		$native  = null !== $mapping && ! empty( $mapping->native_currency_code )
			? strtoupper( (string) $mapping->native_currency_code )
			: strtoupper( (string) $seed[0] );

		$saved = $this->mappings->save_mapping(
			$country_code,
			array(
				'currency_code'        => $native,
				'currency_symbol'      => (string) $seed[1],
				'native_currency_code' => $native,
				'decimal_separator'    => (string) $seed[2],
				'thousand_separator'   => (string) $seed[3],
				'decimals'             => (int) $seed[4],
				'is_active'            => 1,
			)
		);

		$this->flush( $country_code );

		return $saved;
	}

	/**
	 * Drop the memoised and cached mapping of one country, or of all countries.
	 *
	 * @param string $country_code ISO country code, or an empty string for every country.
	 * @return void
	 */
	public function flush( string $country_code = '' ): void {
		$country_code = CountryCodeValidator::normalize( $country_code );

		if ( '' === $country_code ) {
			$this->mapping_memo = array();
			$this->cache->flush_group( CacheGroupEnum::CountryCurrency->value );

			return;
		}

		unset( $this->mapping_memo[ $country_code ] );
		$this->cache->delete( 'country_mapping_' . $country_code, CacheGroupEnum::CountryCurrency->value );
	}
}
