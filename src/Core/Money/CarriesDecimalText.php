<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The storage and readers shared by the decimal value types.
 */
trait CarriesDecimalText {

	/**
	 * Constructor.
	 *
	 * @param string $value Canonical decimal text.
	 */
	private function __construct(
		private readonly string $value
	) {}

	/**
	 * The canonical decimal text.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * The canonical decimal text, for string interpolation and DB writes.
	 *
	 * @return string
	 */
	public function __toString(): string {
		return $this->value;
	}
}
