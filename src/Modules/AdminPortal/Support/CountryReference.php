<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Support;

use D6N\LPM\Core\Shared\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The WooCommerce reference lists every administration screen presents.
 */
class CountryReference {

	/**
	 * Memoised country code to name map.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $countries = null;

	/**
	 * Memoised currency code to label map.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $currencies = null;

	/**
	 * Get every country WooCommerce knows, keyed by upper-case ISO code.
	 *
	 * @return array<string, string> Country code to name map.
	 */
	public function get_countries(): array {
		if ( null !== $this->countries ) {
			return $this->countries;
		}

		$names = array();

		foreach ( WC()->countries->get_countries() as $code => $name ) {
			$names[ strtoupper( (string) $code ) ] = (string) $name;
		}

		$this->countries = $names;

		return $this->countries;
	}

	/**
	 * Get the display name of one country.
	 *
	 * @param string $country_code ISO country code.
	 * @return string Country name, or the code itself when the store has no name for it.
	 */
	public function get_country_name( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );
		$name         = $this->get_countries()[ $country_code ] ?? '';

		return '' !== $name ? $name : $country_code;
	}

	/**
	 * Get every currency WooCommerce knows, keyed by upper-case ISO code.
	 *
	 * @return array<string, string> Currency code to label map.
	 */
	public function get_currencies(): array {
		if ( null !== $this->currencies ) {
			return $this->currencies;
		}

		$currencies = array();

		foreach ( get_woocommerce_currencies() as $code => $label ) {
			$currencies[ strtoupper( (string) $code ) ] = (string) $label;
		}

		if ( array() === $currencies ) {
			$base       = strtoupper( Core::get_shop_base_currency() );
			$currencies = array( $base => $base );
		}

		$this->currencies = $currencies;

		return $this->currencies;
	}

	/**
	 * Whether a currency code is one WooCommerce knows.
	 *
	 * @param string $currency_code Currency code to check.
	 * @return bool True when the store's currency list carries the code.
	 */
	public function is_known_currency( string $currency_code ): bool {
		return array_key_exists( strtoupper( trim( $currency_code ) ), $this->get_currencies() );
	}

	/**
	 * Format one currency for a select label.
	 *
	 * @param string $code   Currency code.
	 * @param string $name   Currency name.
	 * @param string $symbol Currency symbol, may be empty.
	 * @return string Label.
	 */
	public static function format_currency_label( string $code, string $name, string $symbol ): string {
		if ( '' === trim( $symbol ) ) {
			return sprintf( '%s (%s)', $name, $code );
		}

		return sprintf( '%s (%s - %s)', $name, $code, $symbol );
	}

	/**
	 * Decode the symbol WooCommerce holds for a currency code.
	 *
	 * @param string $currency_code Currency code.
	 * @return string Symbol, empty when the store holds none.
	 */
	public static function decode_currency_symbol( string $currency_code ): string {
		$symbol = (string) get_woocommerce_currency_symbol( strtoupper( trim( $currency_code ) ) );

		return html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Get the symbol WooCommerce uses for a currency code.
	 *
	 * @param string $currency_code Currency code.
	 * @return string Symbol, empty when the code is not one the store knows.
	 */
	public function get_currency_symbol( string $currency_code ): string {
		$currency_code = strtoupper( trim( $currency_code ) );

		if ( '' === $currency_code || ! $this->is_known_currency( $currency_code ) ) {
			return '';
		}

		return self::decode_currency_symbol( $currency_code );
	}
}
