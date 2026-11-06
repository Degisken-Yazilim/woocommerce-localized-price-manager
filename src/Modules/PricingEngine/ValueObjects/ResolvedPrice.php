<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\ValueObjects;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable DTO describing one resolved price.
 */
class ResolvedPrice {

	/**
	 * Keys of a cache payload, in the order to_cache_payload() writes them.
	 *
	 * @var array<int, string>
	 */
	private const PAYLOAD_KEYS = array( 'price', 'original_price', 'currency', 'country_code', 'resolution_mode', 'is_blocked', 'rate' );

	/**
	 * Constructor.
	 *
	 * @param float|string|null $price           Resolved price value, or an empty string when hidden.
	 * @param float|string|null $original_price  Original store base price the resolution started from.
	 * @param string            $currency        Resolved currency code.
	 * @param string            $country_code    Customer country code.
	 * @param string            $resolution_mode Tier that produced the value, e.g. 'product_mode1' or 'fallback_hide'.
	 * @param bool              $is_blocked      Whether a blacklist rule hid the product in this country.
	 * @param string|null       $rate            Multiplier the tier applied as decimal text, or null when it applied none.
	 */
	public function __construct(
		public readonly float|string|null $price,
		public readonly float|string|null $original_price,
		public readonly string $currency,
		public readonly string $country_code,
		public readonly string $resolution_mode,
		public readonly bool $is_blocked = false,
		public readonly ?string $rate = null
	) {}

	/**
	 * Whether this result means "show no price and do not sell".
	 *
	 * @return bool
	 */
	public function is_hidden(): bool {
		return $this->is_blocked || '' === (string) $this->price;
	}

	/**
	 * Get a copy carrying a different price, keeping every other field.
	 *
	 * @param float|string|null $price Replacement price.
	 * @return self
	 */
	public function with_price( float|string|null $price ): self {
		return new self(
			$price,
			$this->original_price,
			$this->currency,
			$this->country_code,
			$this->resolution_mode,
			$this->is_blocked,
			$this->rate
		);
	}

	/**
	 * Get the cache payload carrying every field of this result.
	 *
	 * @return array{price: float|string|null, original_price: float|string|null, currency: string, country_code: string, resolution_mode: string, is_blocked: bool, rate: string|null}
	 */
	public function to_cache_payload(): array {
		return array(
			'price'           => $this->price,
			'original_price'  => $this->original_price,
			'currency'        => $this->currency,
			'country_code'    => $this->country_code,
			'resolution_mode' => $this->resolution_mode,
			'is_blocked'      => $this->is_blocked,
			'rate'            => $this->rate,
		);
	}

	/**
	 * Rebuild a result from a cache payload.
	 *
	 * @param mixed $payload Value read from the cache.
	 * @return self|null The result, or null when the payload does not have the exact shape to_cache_payload() writes.
	 */
	public static function from_cache_payload( mixed $payload ): ?self {
		if ( ! is_array( $payload ) || array_keys( $payload ) !== self::PAYLOAD_KEYS ) {
			return null;
		}

		$price    = $payload['price'] ?? null;
		$original = $payload['original_price'] ?? null;
		$rate     = $payload['rate'] ?? null;

		if ( ! self::is_amount( $price ) || ! self::is_amount( $original ) || ( null !== $rate && ! is_string( $rate ) ) ) {
			return null;
		}

		if ( ! is_string( $payload['currency'] ?? null ) || ! is_string( $payload['country_code'] ?? null ) || ! is_string( $payload['resolution_mode'] ?? null ) ) {
			return null;
		}

		if ( ! is_bool( $payload['is_blocked'] ?? null ) ) {
			return null;
		}

		return new self(
			$price,
			$original,
			$payload['currency'],
			$payload['country_code'],
			$payload['resolution_mode'],
			$payload['is_blocked'],
			$rate
		);
	}

	/**
	 * Whether a value has one of the types a price field may hold.
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 * @phpstan-assert-if-true float|string|null $value
	 */
	private static function is_amount( mixed $value ): bool {
		return null === $value || is_string( $value ) || is_float( $value );
	}
}
