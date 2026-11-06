<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\Utils;
use D6N\LPM\Modules\PricingEngine\Services\PriceResolutionService;
use D6N\LPM\Modules\WooCommerce\Support\CartLocator;
use D6N\LPM\Modules\WooCommerce\Support\LocalizedPriceProvider;
use D6N\LPM\Modules\WooCommerce\Support\ProductTypeSupport;
use D6N\LPM\Modules\WooCommerce\Support\StoreApiGuard;
use Throwable;
use WC_Cart;
use WC_Product;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Localizes cart item prices served through the WooCommerce Store API.
 */
class StoreAPICartHandler implements IHookable {

	/**
	 * Store API routes whose cart items are relabelled.
	 */
	private const ROUTE_PATTERN = '#^/wc/store(/v\d+)?/cart#i';

	/**
	 * Price block members that carry their own amounts and currency descriptor.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const AMOUNT_FIELDS = array(
		'prices' => array( 'price', 'regular_price', 'sale_price' ),
		'totals' => array( 'line_subtotal', 'line_subtotal_tax', 'line_total', 'line_total_tax' ),
	);

	/**
	 * Memoised descriptor of the shop's own currency, null until first built.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $store_currency = null;

	/**
	 * Constructor.
	 *
	 * @param LocalizedPriceProvider $prices        Resolved price provider.
	 * @param ProductTypeSupport     $product_types Product type policy and suppression scope.
	 * @param ILogger                $logger        Logger service.
	 */
	public function __construct(
		private readonly LocalizedPriceProvider $prices,
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
		if ( ! $this->is_store_api_available() ) {
			return;
		}

		$priority = WooHookPriorityEnum::Standard->value;

		add_filter( 'woocommerce_store_api_cart_item_product', array( $this, 'override_cart_item_product_price' ), $priority, 1 );
		add_filter( 'rest_pre_dispatch', array( $this, 'prepare_store_api_cart' ), $priority, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'relabel_unsupported_cart_items' ), $priority, 3 );

		add_filter( 'woocommerce_hydration_request_after_callbacks', array( $this, 'relabel_unsupported_cart_items' ), $priority, 3 );
	}

	/**
	 * Put the currency of every unsupported cart item back to the shop's own.
	 *
	 * @param mixed $response Response object the REST server produced.
	 * @param mixed $server   REST server instance.
	 * @param mixed $request  Request that produced the response.
	 * @return mixed The response, relabelled when it is a Store API cart response.
	 */
	public function relabel_unsupported_cart_items( mixed $response, mixed $server = null, mixed $request = null ): mixed {
		try {
			if ( ! Core::is_active() || ! ( $response instanceof WP_REST_Response ) || ! $this->is_cart_route( $request ) ) {
				return $response;
			}

			$data = $response->get_data();

			if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
				return $response;
			}

			$cart = CartLocator::current();

			foreach ( $data['items'] as $index => $item ) {
				if ( is_array( $item ) && $this->is_unsupported_item( $item, $cart ) ) {
					$data['items'][ $index ] = $this->relabel_item( $item );
				}
			}

			$response->set_data( $data );
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Store API cart relabelling failed: %s', $e->getMessage() ),
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
	 * Recalculate cart totals before Store API processes a cart route.
	 *
	 * @param mixed $result  Response object or null.
	 * @param mixed $server  REST server instance.
	 * @param mixed $request Request object.
	 * @return mixed
	 */
	public function prepare_store_api_cart( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		try {
			if ( ! Core::is_active() || ! $this->is_cart_route( $request ) ) {
				return $result;
			}

			if ( function_exists( 'wc_load_cart' ) && null === CartLocator::current() ) {
				wc_load_cart();
			}

			$cart = CartLocator::current();

			if ( null !== $cart && ! $cart->is_empty() ) {
				$cart->calculate_totals();
			}
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Store API cart preparation failed: %s', $e->getMessage() ),
				array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				),
				'WooCommerce'
			);
		}

		return $result;
	}

	/**
	 * Check whether a request targets the Store API cart.
	 *
	 * @param mixed $request Request object.
	 * @return bool
	 */
	private function is_cart_route( mixed $request ): bool {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return false;
		}

		return 1 === preg_match( self::ROUTE_PATTERN, (string) $request->get_route() );
	}

	/**
	 * Check whether a cart item's product is one this plugin does not price.
	 *
	 * @param array<string, mixed> $item Cart item payload.
	 * @param WC_Cart|null         $cart Cart of the request, when it has one.
	 * @return bool
	 */
	private function is_unsupported_item( array $item, ?WC_Cart $cart ): bool {
		$product = $cart?->get_cart_item( (string) ( $item['key'] ?? '' ) )['data'] ?? null;

		if ( ! ( $product instanceof WC_Product ) ) {
			$product_id = Utils::parse_int( $item['id'] ?? null ) ?? 0;
			$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;
		}

		return $product instanceof WC_Product && ! $this->product_types->is_supported( $product );
	}

	/**
	 * Rewrite both currency-bearing blocks of one cart item.
	 *
	 * @param array<string, mixed> $item Cart item payload.
	 * @return array<string, mixed>
	 */
	private function relabel_item( array $item ): array {
		foreach ( self::AMOUNT_FIELDS as $block => $fields ) {
			if ( isset( $item[ $block ] ) && ( is_array( $item[ $block ] ) || is_object( $item[ $block ] ) ) ) {
				$item[ $block ] = $this->relabel_block( $item[ $block ], $fields );
			}
		}

		return $item;
	}

	/**
	 * Rescale one block's amounts into the shop's minor unit and relabel it.
	 *
	 * @param array<string, mixed>|object $block  Price or totals block as the response carries it.
	 * @param array<int, string>          $fields Amount members of that block.
	 * @return array<string, mixed>|object Block in the shape it arrived in.
	 */
	private function relabel_block( array|object $block, array $fields ): array|object {
		$values   = is_object( $block ) ? get_object_vars( $block ) : $block;
		$store    = $this->get_store_currency();
		$from     = isset( $values['currency_minor_unit'] ) ? max( 0, (int) $values['currency_minor_unit'] ) : 0;
		$to       = max( 0, (int) $store['currency_minor_unit'] );
		$rescaled = array_merge( $values, $store );

		if ( $from !== $to ) {
			foreach ( $fields as $field ) {
				if ( array_key_exists( $field, $values ) ) {
					$amount = Utils::parse_float( $values[ $field ] );

					if ( null !== $amount ) {
						$rescaled[ $field ] = (string) (int) round( $amount / ( 10 ** $from ) * ( 10 ** $to ) );
					}
				}
			}
		}

		return is_object( $block ) ? (object) $rescaled : $rescaled;
	}

	/**
	 * Build the descriptor the Store API would have produced for an unfiltered store.
	 *
	 * @return array<string, mixed>
	 */
	private function get_store_currency(): array {
		if ( null !== $this->store_currency ) {
			return $this->store_currency;
		}

		$this->store_currency = $this->product_types->without_localization(
			static function (): array {
				$currency = (string) get_woocommerce_currency();
				$symbol   = html_entity_decode( (string) get_woocommerce_currency_symbol( $currency ) );
				$position = (string) get_option( 'woocommerce_currency_pos' );

				$prefix = match ( $position ) {
					'left'        => $symbol,
					'left_space'  => $symbol . ' ',
					default       => '',
				};
				$suffix = match ( $position ) {
					'right'       => $symbol,
					'right_space' => ' ' . $symbol,
					default       => '',
				};

				return array(
					'currency_code'               => $currency,
					'currency_symbol'             => $symbol,
					'currency_minor_unit'         => (int) wc_get_price_decimals(),
					'currency_decimal_separator'  => (string) wc_get_price_decimal_separator(),
					'currency_thousand_separator' => (string) wc_get_price_thousand_separator(),
					'currency_prefix'             => $prefix,
					'currency_suffix'             => $suffix,
				);
			}
		);

		return $this->store_currency;
	}

	/**
	 * Check whether the Store API can be hooked at all.
	 *
	 * @return bool
	 */
	public function is_store_api_available(): bool {
		return StoreApiGuard::is_available();
	}

	/**
	 * Write the localized price onto a product the Store API is about to serialise.
	 *
	 * @param WC_Product $product Product instance.
	 * @return WC_Product
	 */
	public function override_cart_item_product_price( WC_Product $product ): WC_Product {
		try {
			if ( ! Core::is_active() ) {
				return $product;
			}

			$country              = $this->prices->get_country();
			$active_cart_currency = null;
			$session_country      = function_exists( 'WC' ) && null !== WC()->session
				? WC()->session->get( 'd6n_lpm_cart_country' )
				: null;

			if ( $session_country === $country && function_exists( 'WC' ) && null !== WC()->session ) {
				$session_currency = WC()->session->get( 'd6n_lpm_active_cart_currency' );
				if ( is_string( $session_currency ) && '' !== $session_currency ) {
					$active_cart_currency = strtoupper( trim( $session_currency ) );
				}
			}

			if ( null === $active_cart_currency && function_exists( 'WC' ) && null !== WC()->cart && ! WC()->cart->is_empty() ) {
				$items         = WC()->cart->get_cart();
				$last_item_key = null !== WC()->session ? WC()->session->get( 'd6n_lpm_last_added_item_key' ) : null;
				$target_item   = null;

				if ( is_string( $last_item_key ) && isset( $items[ $last_item_key ]['data'] ) && $items[ $last_item_key ]['data'] instanceof WC_Product ) {
					$target_item = $items[ $last_item_key ]['data'];
				} else {
					$last_entry = end( $items );
					if ( is_array( $last_entry ) && isset( $last_entry['data'] ) && $last_entry['data'] instanceof WC_Product ) {
						$target_item = $last_entry['data'];
					}
				}

				if ( null !== $target_item ) {
					$target_resolved = $this->prices->resolve( $target_item );
					if ( null !== $target_resolved && '' !== $target_resolved->currency ) {
						$active_cart_currency = strtoupper( trim( $target_resolved->currency ) );
						if ( null !== WC()->session ) {
							WC()->session->set( 'd6n_lpm_active_cart_currency', $active_cart_currency );
							WC()->session->set( 'd6n_lpm_cart_country', $country );
						}
					}
				}
			}

			$resolved = $this->prices->resolve( $product );

			if ( null !== $active_cart_currency ) {
				if ( null !== $resolved && $resolved->currency === $active_cart_currency ) {
					$price = (string) $resolved->price;
				} else {
					$base_price = (string) $this->prices->get_base_price( $product, PriceResolutionService::PRICE_TYPE_ACTIVE );
					$converted  = $this->prices->convert_amount( $base_price, $active_cart_currency );
					$price      = $converted ?? ( null !== $resolved ? (string) $resolved->price : $base_price );
				}
			} else {
				if ( null === $resolved ) {
					return $product;
				}
				$price = (string) $resolved->price;
			}

			if ( '' === $price ) {
				return $product;
			}

			$product->set_price( $price );

			$this->prices->mark_localized( $product, PriceResolutionService::PRICE_TYPE_ACTIVE );
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Store API cart item pricing failed for product %d: %s', $product->get_id(), $e->getMessage() ),
				array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				),
				'WooCommerce'
			);
		}

		return $product;
	}
}
