<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Money;

use Stringable;

defined( 'ABSPATH' ) || exit;

/**
 * A currency conversion multiplier, at the precision the rate columns hold.
 */
class ExchangeRate implements Stringable {

	/**
	 * Decimal places every rate column holds.
	 *
	 * @var int
	 */
	public const SCALE = 12;

	/**
	 * Digits allowed before the decimal point.
	 *
	 * @var int
	 */
	private const MAX_INTEGER_DIGITS = 8;

	use CarriesDecimalText;

	/**
	 * Build from a submitted, stored or computed value.
	 *
	 * @param mixed $raw Raw value of any shape.
	 * @return self|null The rate, or null when it is not one this type accepts.
	 */
	public static function from( mixed $raw ): ?self {
		if ( $raw instanceof self ) {
			return $raw;
		}

		if ( $raw instanceof MoneyAmount ) {
			$raw = $raw->value();
		}

		$text = DecimalText::canonical( $raw, self::MAX_INTEGER_DIGITS, self::SCALE, false );

		return null === $text ? null : new self( $text );
	}
}
