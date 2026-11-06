<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\CountryPricing;

use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Money\ExchangeRate;
use D6N\LPM\Core\Money\MoneyAmount;
use Throwable;
use WC_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The rules a product-level country pricing row must satisfy against its base product prices.
 */
class CountryPricingRuleGuard {

	/**
	 * Refusal reason: the row carries a local price or sale price and an exchange rate.
	 *
	 * @var string
	 */
	public const REFUSED_PRICE_AND_RATE = 'price_and_rate';

	/**
	 * Refusal reason: the row carries a local price while the base product has no regular price.
	 *
	 * @var string
	 */
	public const REFUSED_PRICE_WITHOUT_BASE_PRICE = 'price_without_base_price';

	/**
	 * Refusal reason: the base product is on sale and the row carries a local price without a local sale price.
	 *
	 * @var string
	 */
	public const REFUSED_SALE_PRICE_REQUIRED = 'sale_price_required';

	/**
	 * Refusal reason: the base product is on sale and the row carries a local sale price without a local price.
	 *
	 * @var string
	 */
	public const REFUSED_SALE_PRICE_WITHOUT_PRICE = 'sale_price_without_price';

	/**
	 * Products whose local sale prices were cleared in this request.
	 *
	 * @var array<int, true>
	 */
	private array $cleared = array();

	/**
	 * Constructor.
	 *
	 * @param CountryPricingRepository $repository Country pricing repository.
	 * @param ILogger|null             $logger     Logger service.
	 */
	public function __construct(
		private readonly CountryPricingRepository $repository,
		private readonly ?ILogger $logger = null
	) {}

	/**
	 * Register the product save listener that keeps local sale prices in step with the base sale price.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_updated' ), 10, 2 );
	}

	/**
	 * Clear the local sale prices of a product saved without a base sale price, whatever saved it.
	 *
	 * @param int   $product_id Product ID.
	 * @param mixed $product    Product WooCommerce just saved.
	 * @return void
	 */
	public function on_product_updated( int $product_id, mixed $product = null ): void {
		try {
			if ( ! $product instanceof WC_Product ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			}

			if ( ! $product instanceof WC_Product ) {
				return;
			}

			$dropped = $this->drop_sale_prices( $product_id, MoneyAmount::from( (string) $product->get_sale_price( 'edit' ) ) );

			if ( is_wp_error( $dropped ) ) {
				$this->logger?->error(
					sprintf( 'Failed to clear the local sale prices of product %1$d: %2$s', $product_id, $dropped->get_error_message() ),
					array( 'product_id' => $product_id ),
					'PricingEngine'
				);
			}
		} catch ( Throwable $e ) {
			$this->logger?->error(
				sprintf( 'Failed to clear the local sale prices of product %1$d: %2$s', $product_id, $e->getMessage() ),
				array( 'product_id' => $product_id ),
				'PricingEngine'
			);
		}
	}

	/**
	 * Check whether this request cleared the local sale prices of a product.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True when at least one stored local sale price was cleared.
	 */
	public function has_cleared_sale_prices( int $product_id ): bool {
		return isset( $this->cleared[ $product_id ] );
	}

	/**
	 * Check one row's amounts against the base product prices.
	 *
	 * @param MoneyAmount|null  $price           Local regular price.
	 * @param MoneyAmount|null  $sale_price      Local sale price.
	 * @param ExchangeRate|null $rate            Exchange rate override.
	 * @param MoneyAmount|null  $base_price      Base product regular price.
	 * @param MoneyAmount|null  $base_sale_price Base product sale price.
	 * @return array{local_price: ?string, local_sale_price: ?string, exchange_rate: ?string}|string Column values to store, or the refusal reason.
	 */
	public function check_amounts(
		?MoneyAmount $price,
		?MoneyAmount $sale_price,
		?ExchangeRate $rate,
		?MoneyAmount $base_price,
		?MoneyAmount $base_sale_price
	): array|string {
		if ( null === $base_sale_price ) {
			$sale_price = null;
		}

		$has_local_amount = null !== $price || null !== $sale_price;

		if ( $has_local_amount && null !== $rate ) {
			return self::REFUSED_PRICE_AND_RATE;
		}

		if ( null !== $price && null === $base_price ) {
			return self::REFUSED_PRICE_WITHOUT_BASE_PRICE;
		}

		if ( null !== $price && null !== $base_sale_price && null === $sale_price ) {
			return self::REFUSED_SALE_PRICE_REQUIRED;
		}

		if ( null === $price && null !== $sale_price ) {
			return self::REFUSED_SALE_PRICE_WITHOUT_PRICE;
		}

		return array(
			'local_price'      => $price?->value(),
			'local_sale_price' => $sale_price?->value(),
			'exchange_rate'    => $rate?->value(),
		);
	}

	/**
	 * Remove the local sale price from every rule of a product whose base product has no sale price.
	 *
	 * @param int              $product_id      Product ID.
	 * @param MoneyAmount|null $base_sale_price Base product sale price.
	 * @return int|WP_Error Number of rules changed, or WP_Error on failure.
	 */
	public function drop_sale_prices( int $product_id, ?MoneyAmount $base_sale_price ): int|WP_Error {
		if ( null !== $base_sale_price ) {
			return 0;
		}

		$cleared = $this->repository->clear_sale_prices( $product_id );

		if ( is_int( $cleared ) && $cleared > 0 ) {
			$this->cleared[ $product_id ] = true;
		}

		return $cleared;
	}
}
