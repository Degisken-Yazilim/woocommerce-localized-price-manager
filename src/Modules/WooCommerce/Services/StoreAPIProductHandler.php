<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\FallbackModeEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\Utils;
use D6N\LPM\Modules\PricingEngine\Services\PriceResolutionService;
use D6N\LPM\Modules\WooCommerce\Support\LocalizedPriceProvider;
use D6N\LPM\Modules\WooCommerce\Support\ProductTypeSupport;
use D6N\LPM\Modules\WooCommerce\Support\StoreApiGuard;
use Throwable;
use WC_Product;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Localizes product collection and single product Store API responses.
 */
class StoreAPIProductHandler implements IHookable {

	/**
	 * Store API routes this handler rewrites.
	 */
	private const ROUTE_PATTERN = '#^/wc/store(/v\d+)?/products#i';

	/**
	 * Price block members that are themselves price blocks.
	 *
	 * @var array<int, string>
	 */
	private const NESTED_BLOCKS = array( 'price_range', 'raw_prices' );

	/**
	 * Constructor.
	 *
	 * @param LocalizedPriceProvider      $prices        Resolved price provider.
	 * @param CurrencyPresentationService $currency      Currency presentation service, source of the number format.
	 * @param ProductTypeSupport          $product_types Product type policy and suppression scope.
	 * @param ILogger                     $logger        Logger service.
	 */
	public function __construct(
		private readonly LocalizedPriceProvider $prices,
		private readonly CurrencyPresentationService $currency,
		private readonly ProductTypeSupport $product_types,
		private readonly ILogger $logger
	) {}

	/**
	 * Register WordPress filter hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress filter hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		if ( ! StoreApiGuard::is_available() ) {
			return;
		}

		$priority = WooHookPriorityEnum::Standard->value;

		add_filter( 'rest_post_dispatch', array( $this, 'filter_product_response' ), $priority, 3 );

		add_filter( 'woocommerce_hydration_request_after_callbacks', array( $this, 'filter_product_response' ), $priority, 3 );
	}

	/**
	 * Rewrite the prices of a Store API product response.
	 *
	 * @param mixed $response Response object the REST server produced.
	 * @param mixed $server   REST server instance.
	 * @param mixed $request  Request that produced the response.
	 * @return mixed The response, localized when it is a Store API product response.
	 */
	public function filter_product_response( mixed $response, mixed $server = null, mixed $request = null ): mixed {
		try {
			if ( ! Core::is_active() || ! ( $response instanceof WP_REST_Response ) ) {
				return $response;
			}

			if ( ! $this->is_product_route( $request ) ) {
				return $response;
			}

			$format = $this->currency->get_format();

			if ( null === $format || '' === $format['currency'] ) {
				$format = $this->fallback_format();
			}

			if ( null === $format && ! $this->fallback_hides() ) {
				return $response;
			}

			$data = $response->get_data();

			if ( ! is_array( $data ) ) {
				return $response;
			}

			$response->set_data( $this->localize_payload( $data, $format ) );
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Store API product response localization failed: %s', $e->getMessage() ),
				array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				),
				'WooCommerce'
			);
		}

		return $response;
	}

	/**
	 * Build the number format the `convert` fallback implies for a visitor
	 * whose country cannot be resolved.
	 *
	 * @return array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int}|null Format, or null when the fallback does not convert.
	 */
	private function fallback_format(): ?array {
		$mode = FallbackModeEnum::from_option( Core::get_option( OptionsEnum::FallbackMode->value, FallbackModeEnum::Direct->value ) );

		if ( FallbackModeEnum::Convert !== $mode ) {
			return null;
		}

		$currency = Core::get_global_currency();

		return array(
			'currency'           => $currency,
			'symbol'             => (string) get_woocommerce_currency_symbol( $currency ),
			'decimal_separator'  => wc_get_price_decimal_separator(),
			'thousand_separator' => wc_get_price_thousand_separator(),
			'decimals'           => max( 0, (int) wc_get_price_decimals() ),
		);
	}

	/**
	 * Check whether the global fallback hides the price of a visitor whose country cannot be resolved.
	 *
	 * @return bool
	 */
	private function fallback_hides(): bool {
		return FallbackModeEnum::Hide === FallbackModeEnum::from_option( Core::get_option( OptionsEnum::FallbackMode->value, FallbackModeEnum::Direct->value ) );
	}

	/**
	 * Check whether a request targets a Store API product route.
	 *
	 * @param mixed $request Request object.
	 * @return bool
	 */
	private function is_product_route( mixed $request ): bool {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return false;
		}

		return 1 === preg_match( self::ROUTE_PATTERN, (string) $request->get_route() );
	}

	/**
	 * Localize either a single product payload or a collection of them.
	 *
	 * @param array<mixed>                                                                                                       $data   Response payload.
	 * @param array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int}|null $format Country number format, or null when the visitor has none.
	 * @return array<mixed>
	 */
	private function localize_payload( array $data, ?array $format ): array {
		if ( isset( $data['prices'] ) ) {
			return $this->localize_product( $data, $format );
		}

		foreach ( $data as $index => $item ) {
			if ( is_array( $item ) && isset( $item['prices'] ) ) {
				$data[ $index ] = $this->localize_product( $item, $format );
			}
		}

		return $data;
	}

	/**
	 * Rewrite the `prices` block of one product payload.
	 *
	 * @param array<string, mixed>                                                                                               $item   Product payload.
	 * @param array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int}|null $format Country number format, or null when the visitor has none.
	 * @return array<string, mixed>
	 */
	private function localize_product( array $item, ?array $format ): array {
		$source = $item['prices'];

		if ( ! is_array( $source ) && ! is_object( $source ) ) {
			return $item;
		}

		$product = $this->get_product( $item );

		if ( null !== $product && ! $this->product_types->is_supported( $product ) ) {
			return $item;
		}

		$hidden = null !== $product && $this->is_hidden( $product );

		if ( null === $format ) {
			return $hidden ? $this->blank_item( $item, $source ) : $item;
		}

		$format           = $this->product_format( $product, $format );
		$format['symbol'] = html_entity_decode( $format['symbol'] );
		$prices           = $this->block_to_array( $source );
		$minor_unit       = max( 0, $format['decimals'] );

		if ( $hidden ) {
			$prices = $this->blank_amounts( $prices );
		} elseif ( null !== $product ) {
			$prices = $this->apply_amounts( $prices, $product, $minor_unit );
		} else {
			$prices = $this->rescale_amounts( $prices, $minor_unit );
		}

		$item['prices'] = $this->restore_block_shape(
			$this->apply_currency( $prices, $format, $minor_unit ),
			$source
		);

		return $item;
	}

	/**
	 * Get the format of the currency a product is actually priced in.
	 *
	 * @param WC_Product|null                                                                                               $product Product, or null when unknown.
	 * @param array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int} $format  Page format.
	 * @return array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int}
	 */
	private function product_format( ?WC_Product $product, array $format ): array {
		if ( null === $product ) {
			return $format;
		}

		$resolved = $this->prices->resolve( $product );

		if ( null === $resolved || $resolved->is_hidden() || '' === $resolved->currency || strtoupper( $resolved->currency ) === $format['currency'] ) {
			return $format;
		}

		return $this->currency->get_format_for_currency( strtoupper( $resolved->currency ) );
	}

	/**
	 * Blank the amounts of a product payload and leave its currency block as the Store API produced it.
	 *
	 * @param array<string, mixed>        $item   Product payload.
	 * @param array<string, mixed>|object $source Price block of the payload.
	 * @return array<string, mixed>
	 */
	private function blank_item( array $item, array|object $source ): array {
		$item['prices'] = $this->restore_block_shape( $this->blank_amounts( $this->block_to_array( $source ) ), $source );

		return $item;
	}

	/**
	 * Check whether the price of a product is hidden for the visitor.
	 *
	 * @param WC_Product $product Product to check.
	 * @return bool
	 */
	private function is_hidden( WC_Product $product ): bool {
		$resolved = $this->prices->resolve( $product );

		return null !== $resolved && $resolved->is_hidden();
	}

	/**
	 * Replace every amount of a price block with an empty string.
	 *
	 * @param array<string, mixed> $prices Store API price block.
	 * @return array<string, mixed>
	 */
	private function blank_amounts( array $prices ): array {
		foreach ( array( 'price', 'regular_price', 'sale_price' ) as $field ) {
			if ( array_key_exists( $field, $prices ) ) {
				$prices[ $field ] = '';
			}

			if ( isset( $prices['raw_prices'] ) && is_array( $prices['raw_prices'] ) && array_key_exists( $field, $prices['raw_prices'] ) ) {
				$prices['raw_prices'][ $field ] = '';
			}
		}

		return $prices;
	}

	/**
	 * Normalize a Store API price block, and its nested blocks, to plain arrays.
	 *
	 * @param array<string, mixed>|object $block Price block as the response carries it.
	 * @return array<string, mixed> Price block with array access.
	 */
	private function block_to_array( array|object $block ): array {
		$prices = is_object( $block ) ? get_object_vars( $block ) : $block;

		foreach ( self::NESTED_BLOCKS as $key ) {
			if ( isset( $prices[ $key ] ) && is_object( $prices[ $key ] ) ) {
				$prices[ $key ] = get_object_vars( $prices[ $key ] );
			}
		}

		return $prices;
	}

	/**
	 * Give a localized price block the shape the response carried it in.
	 *
	 * @param array<string, mixed>        $prices Localized price block.
	 * @param array<string, mixed>|object $source Block the localized values replace.
	 * @return array<string, mixed>|object Price block in the original shape.
	 */
	private function restore_block_shape( array $prices, array|object $source ): array|object {
		foreach ( self::NESTED_BLOCKS as $key ) {
			$original = is_object( $source ) ? ( $source->{$key} ?? null ) : ( $source[ $key ] ?? null );

			if ( is_object( $original ) && isset( $prices[ $key ] ) && is_array( $prices[ $key ] ) ) {
				$prices[ $key ] = (object) $prices[ $key ];
			}
		}

		return is_object( $source ) ? (object) $prices : $prices;
	}

	/**
	 * Replace every amount with the resolved price of the matching price type.
	 *
	 * @param array<string, mixed> $prices     Store API price block.
	 * @param WC_Product           $product    Product the block belongs to.
	 * @param int                  $minor_unit Number of decimals of the visitor's currency.
	 * @return array<string, mixed>
	 */
	private function apply_amounts( array $prices, WC_Product $product, int $minor_unit ): array {
		$types = array(
			'price'         => PriceResolutionService::PRICE_TYPE_ACTIVE,
			'regular_price' => PriceResolutionService::PRICE_TYPE_REGULAR,
			'sale_price'    => PriceResolutionService::PRICE_TYPE_SALE,
		);

		$raw_precision = isset( $prices['raw_prices']['precision'] )
			? max( 0, (int) $prices['raw_prices']['precision'] )
			: 6;

		foreach ( $types as $field => $price_type ) {
			if ( ! array_key_exists( $field, $prices ) ) {
				continue;
			}

			$resolved = $this->prices->resolve( $product, $price_type );

			if ( null === $resolved ) {
				$prices = $this->fill_absent_sale_price( $prices, $product, $field, $price_type );

				continue;
			}

			$amount = Utils::parse_float( $resolved->price );

			if ( null === $amount ) {
				continue;
			}

			$prices[ $field ] = $this->to_minor_units( $amount, $minor_unit );

			if ( isset( $prices['raw_prices'] ) && is_array( $prices['raw_prices'] ) && array_key_exists( $field, $prices['raw_prices'] ) ) {
				$prices['raw_prices'][ $field ] = $this->to_minor_units( $amount, $raw_precision );
			}
		}

		return $this->rescale_range( $this->mirror_absent_sale_price( $prices ), $minor_unit );
	}

	/**
	 * Give a product without a base sale price the active price as its sale price.
	 *
	 * @param array<string, mixed> $prices     Store API price block.
	 * @param WC_Product           $product    Product the block belongs to.
	 * @param string               $field      Price block field being handled.
	 * @param string               $price_type Price type of that field.
	 * @return array<string, mixed>
	 */
	private function fill_absent_sale_price( array $prices, WC_Product $product, string $field, string $price_type ): array {
		if ( PriceResolutionService::PRICE_TYPE_SALE !== $price_type || ! array_key_exists( 'price', $prices ) ) {
			return $prices;
		}

		if ( $this->prices->has_base_price( $product, $price_type ) ) {
			return $prices;
		}

		$prices[ $field ] = $prices['price'];

		if (
			isset( $prices['raw_prices'] )
			&& is_array( $prices['raw_prices'] )
			&& array_key_exists( $field, $prices['raw_prices'] )
			&& array_key_exists( 'price', $prices['raw_prices'] )
		) {
			$prices['raw_prices'][ $field ] = $prices['raw_prices']['price'];
		}

		return $prices;
	}

	/**
	 * Keep `sale_price` on the WooCommerce convention when there is no sale.
	 *
	 * @param array<string, mixed> $prices Store API price block.
	 * @return array<string, mixed>
	 */
	private function mirror_absent_sale_price( array $prices ): array {
		if ( ! array_key_exists( 'sale_price', $prices ) || ! array_key_exists( 'price', $prices ) ) {
			return $prices;
		}

		$sale = Utils::parse_float( $prices['sale_price'] );

		if ( null !== $sale && $sale > 0.0 ) {
			return $prices;
		}

		$prices['sale_price'] = $prices['price'];

		if (
			isset( $prices['raw_prices'] )
			&& is_array( $prices['raw_prices'] )
			&& array_key_exists( 'sale_price', $prices['raw_prices'] )
			&& array_key_exists( 'price', $prices['raw_prices'] )
		) {
			$prices['raw_prices']['sale_price'] = $prices['raw_prices']['price'];
		}

		return $prices;
	}

	/**
	 * Rescale the existing amounts when the product itself could not be loaded.
	 *
	 * @param array<string, mixed> $prices     Store API price block.
	 * @param int                  $minor_unit Number of decimals of the visitor's currency.
	 * @return array<string, mixed>
	 */
	private function rescale_amounts( array $prices, int $minor_unit ): array {
		$from = isset( $prices['currency_minor_unit'] ) ? max( 0, (int) $prices['currency_minor_unit'] ) : $minor_unit;

		if ( $from === $minor_unit ) {
			return $prices;
		}

		foreach ( array( 'price', 'regular_price', 'sale_price' ) as $field ) {
			if ( ! array_key_exists( $field, $prices ) ) {
				continue;
			}

			$amount = Utils::parse_float( $prices[ $field ] );

			if ( null === $amount ) {
				continue;
			}

			$prices[ $field ] = $this->to_minor_units( $amount / ( 10 ** $from ), $minor_unit );
		}

		return $this->rescale_range( $prices, $minor_unit, $from );
	}

	/**
	 * Rescale the min/max amounts of a variable product price range.
	 *
	 * @param array<string, mixed> $prices     Store API price block.
	 * @param int                  $minor_unit Number of decimals of the visitor's currency.
	 * @param int|null             $from       Minor unit the range is currently expressed in.
	 * @return array<string, mixed>
	 */
	private function rescale_range( array $prices, int $minor_unit, ?int $from = null ): array {
		if ( ! isset( $prices['price_range'] ) || ! is_array( $prices['price_range'] ) ) {
			return $prices;
		}

		$source = $from ?? ( isset( $prices['currency_minor_unit'] ) ? max( 0, (int) $prices['currency_minor_unit'] ) : $minor_unit );

		if ( $source === $minor_unit ) {
			return $prices;
		}

		foreach ( array( 'min_amount', 'max_amount' ) as $field ) {
			if ( ! array_key_exists( $field, $prices['price_range'] ) ) {
				continue;
			}

			$amount = Utils::parse_float( $prices['price_range'][ $field ] );

			if ( null === $amount ) {
				continue;
			}

			$prices['price_range'][ $field ] = $this->to_minor_units( $amount / ( 10 ** $source ), $minor_unit );
		}

		return $prices;
	}

	/**
	 * Replace the currency descriptor of a price block.
	 *
	 * @param array<string, mixed>                                                                                          $prices     Store API price block.
	 * @param array{currency: string, symbol: string, decimal_separator: string, thousand_separator: string, decimals: int} $format     Country number format.
	 * @param int                                                                                                           $minor_unit Number of decimals of the visitor's currency.
	 * @return array<string, mixed>
	 */
	private function apply_currency( array $prices, array $format, int $minor_unit ): array {
		$prices['currency_code']               = $format['currency'];
		$prices['currency_symbol']             = $format['symbol'];
		$prices['currency_minor_unit']         = $minor_unit;
		$prices['currency_decimal_separator']  = $format['decimal_separator'];
		$prices['currency_thousand_separator'] = $format['thousand_separator'];

		[ $prefix, $suffix ] = $this->split_price_format( $format['symbol'] );

		$prices['currency_prefix'] = $prefix;
		$prices['currency_suffix'] = $suffix;

		return $prices;
	}

	/**
	 * Split the store price format into the prefix and suffix around the amount.
	 *
	 * @param string $symbol Currency symbol to substitute.
	 * @return array{0: string, 1: string}
	 */
	private function split_price_format( string $symbol ): array {
		$format = (string) get_woocommerce_price_format();
		$parts  = explode( '%2$s', $format, 2 );

		return array(
			str_replace( '%1$s', $symbol, $parts[0] ),
			str_replace( '%1$s', $symbol, $parts[1] ?? '' ),
		);
	}

	/**
	 * Express a decimal amount in the minor units the Store API serialises.
	 *
	 * @param float $amount     Decimal amount.
	 * @param int   $minor_unit Number of decimals.
	 * @return string
	 */
	private function to_minor_units( float $amount, int $minor_unit ): string {
		return (string) (int) round( $amount * ( 10 ** $minor_unit ) );
	}

	/**
	 * Load the product a payload item describes.
	 *
	 * @param array<string, mixed> $item Product payload.
	 * @return WC_Product|null
	 */
	private function get_product( array $item ): ?WC_Product {
		$product_id = Utils::parse_int( $item['id'] ?? null ) ?? 0;

		if ( $product_id <= 0 ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return $product instanceof WC_Product ? $product : null;
	}
}
