<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Money;

use Stringable;

defined( 'ABSPATH' ) || exit;

/**
 * An amount of money, at the precision the money columns hold.
 */
class MoneyAmount implements Stringable {

	use CarriesDecimalText;

	/**
	 * Decimal places every money column holds.
	 *
	 * @var int
	 */
	private const SCALE = 8;

	/**
	 * Digits allowed before the decimal point.
	 *
	 * @var int
	 */
	private const MAX_INTEGER_DIGITS = 16;

	/**
	 * Build from a submitted, stored or computed value.
	 *
	 * @param mixed $raw Raw value of any shape.
	 * @return self|null The amount, or null when it is not one this type accepts.
	 */
	public static function from( mixed $raw ): ?self {
		if ( $raw instanceof self ) {
			return $raw;
		}

		if ( $raw instanceof ExchangeRate ) {
			$raw = $raw->value();
		}

		$text = DecimalText::canonical( $raw, self::MAX_INTEGER_DIGITS, self::SCALE, true );

		return null === $text ? null : new self( $text );
	}
}
