<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\Services;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Money\ExchangeRate;
use D6N\LPM\Modules\PricingEngine\CategoryPricing\CategoryPricingRepository;
use D6N\LPM\Modules\PricingEngine\CountryCurrency\CountryCurrencyRepository;
use D6N\LPM\Modules\PricingEngine\CountryGroup\CountryGroupRepository;
use D6N\LPM\Modules\PricingEngine\CountryPricing\CountryPricingRepository;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the countries an operator has priced through a product rule, a category rule or a group rate.
 */
class RuledCountryService {

	/**
	 * Constructor.
	 *
	 * @param CountryPricingRepository  $country_pricing  Product level rule repository.
	 * @param CategoryPricingRepository $category_pricing Category level rule repository.
	 * @param CountryGroupRepository    $groups           Country group repository.
	 * @param CountryCurrencyRepository $country_currency Country to currency mapping repository.
	 * @param ILogger                   $logger           Logger service.
	 */
	public function __construct(
		private readonly CountryPricingRepository $country_pricing,
		private readonly CategoryPricingRepository $category_pricing,
		private readonly CountryGroupRepository $groups,
		private readonly CountryCurrencyRepository $country_currency,
		private readonly ILogger $logger
	) {}

	/**
	 * Get the ruled countries.
	 *
	 * @return array<int, string> Unique ISO country codes.
	 */
	public function get_ruled_countries(): array {
		$codes = array_merge(
			$this->codes_of( $this->country_pricing->get_ruled_countries() ),
			$this->codes_of( $this->category_pricing->get_ruled_countries() ),
			$this->get_rate_group_countries()
		);

		$ruled = array();

		foreach ( $codes as $code ) {
			$code = CountryCodeValidator::sanitize( (string) $code );

			if ( '' !== $code ) {
				$ruled[ $code ] = $code;
			}
		}

		return array_values( $ruled );
	}

	/**
	 * Get the members of every active group that carries its own exchange rate.
	 *
	 * @return array<int, string> ISO country codes.
	 */
	private function get_rate_group_countries(): array {
		$groups = $this->groups->get_active_groups();

		if ( $groups instanceof WP_Error ) {
			$this->warn( $groups );

			return array();
		}

		$codes = array();

		foreach ( $groups as $group ) {
			if ( null === ExchangeRate::from( $group->exchange_rate ?? null ) ) {
				continue;
			}

			$codes = array_merge( $codes, $this->codes_of( $this->country_currency->get_group_member_codes( (int) ( $group->id ?? 0 ) ) ) );
		}

		return $codes;
	}

	/**
	 * Unwrap a repository result, logging a failure.
	 *
	 * @param array<int, mixed>|WP_Error $result Repository result.
	 * @return array<int, mixed> Country codes, empty on failure.
	 */
	private function codes_of( array|WP_Error $result ): array {
		if ( $result instanceof WP_Error ) {
			$this->warn( $result );

			return array();
		}

		return $result;
	}

	/**
	 * Log a failed lookup.
	 *
	 * @param WP_Error $error Repository error.
	 * @return void
	 */
	private function warn( WP_Error $error ): void {
		$this->logger->warning( 'Ruled country lookup failed.', array( 'error' => $error->get_error_message() ), 'PricingEngine' );
	}
}
