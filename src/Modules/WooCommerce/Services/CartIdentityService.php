<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\WooCommerce\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\RequestContext;
use D6N\LPM\Modules\WooCommerce\Support\LocalizedPriceProvider;
use Throwable;
use WC_Cart;
use WC_Session;

defined( 'ABSPATH' ) || exit;

/**
 * Gives the cart an identity: the country and pricing basis it was built under.
 */
class CartIdentityService implements IHookable {

	/**
	 * Session key holding the identity a cart was built under.
	 */
	public const SESSION_KEY = '_lpm_cart_identity';

	/**
	 * Constructor.
	 *
	 * @param LocalizedPriceProvider $prices Resolved price provider, and the request's country of record.
	 * @param ILogger                $logger Logger service.
	 */
	public function __construct(
		private readonly LocalizedPriceProvider $prices,
		private readonly ILogger $logger
	) {}

	/**
	 * Register WordPress action hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress action hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'reconcile' ), WooHookPriorityEnum::Standard->value, 1 );
	}

	/**
	 * Compare the cart's identity with the current one and act on a change.
	 *
	 * @param WC_Cart $cart Cart just restored from the session.
	 * @return void
	 */
	public function reconcile( WC_Cart $cart ): void {
		try {
			$this->reconcile_identity( $cart );
		} catch ( Throwable $e ) {
			$this->logger->error(
				sprintf( 'Cart identity reconciliation failed: %s', $e->getMessage() ),
				array(
					'file' => $e->getFile(),
					'line' => $e->getLine(),
				),
				'WooCommerce'
			);
		}
	}

	/**
	 * Get the pricing basis every cart of this request is measured against.
	 *
	 * @return string Identity string, or an empty string when this request cannot name one.
	 */
	private function current_identity(): string {
		$country = $this->prices->get_country();

		if ( '' === $country ) {
			return '';
		}

		return $country . '|' . (int) Core::get_option( OptionsEnum::PricingRevision->value, 0 );
	}

	/**
	 * Stamp the cart, and re-price it when the stamp it already carried has moved.
	 *
	 * @param WC_Cart $cart Cart just restored from the session.
	 * @return void
	 */
	private function reconcile_identity( WC_Cart $cart ): void {
		if ( ! Core::is_active() || RequestContext::is_wp_admin() ) {
			return;
		}

		$session  = $this->get_session();
		$identity = $this->current_identity();

		if ( '' === $identity ) {
			return;
		}

		$previous = (string) $session->get( self::SESSION_KEY, '' );

		if ( $previous === $identity ) {
			return;
		}

		$session->set( self::SESSION_KEY, $identity );

		if ( '' === $previous || $cart->is_empty() ) {
			return;
		}

		$this->reprice( $cart, $previous, $identity );
	}

	/**
	 * Bring a cart built under an older basis up to the current one.
	 *
	 * @param WC_Cart $cart     Cart to re-price.
	 * @param string  $previous Identity the cart was built under.
	 * @param string  $identity Identity now in force.
	 * @return void
	 */
	private function reprice( WC_Cart $cart, string $previous, string $identity ): void {
		$cart->calculate_totals();

		$country_changed = $this->country_of( $previous ) !== $this->country_of( $identity );

		$this->announce( $country_changed );

		$this->logger->info(
			sprintf( 'Cart re-priced: identity moved from %s to %s.', $previous, $identity ),
			array(
				'previous_identity' => $previous,
				'current_identity'  => $identity,
				'country_changed'   => $country_changed,
			),
			'WooCommerce'
		);

		/**
		 * Fires after a cart has been re-priced because its pricing basis moved.
		 *
		 * @param WC_Cart $cart     The re-priced cart.
		 * @param string  $previous Identity the cart was built under.
		 * @param string  $identity Identity now in force.
		 */
		do_action( Core::build_hook( HookEnum::PricingCartIdentityChanged->value ), $cart, $previous, $identity );
	}

	/**
	 * Tell the customer, in the terms that match what actually moved.
	 *
	 * @param bool $country_changed Whether the customer's country is what changed.
	 * @return void
	 */
	private function announce( bool $country_changed ): void {
		$message = $country_changed
			? __( 'Your location changed, so the prices in your cart have been updated to your local currency.', 'localized-price-manager' )
			: __( 'Exchange rates have been updated, so the prices in your cart have been refreshed.', 'localized-price-manager' );

		/**
		 * Filters the notice shown when a cart is re-priced.
		 *
		 * @param string $message         Notice text.
		 * @param bool   $country_changed Whether the customer's country is what changed.
		 */
		$message = (string) apply_filters(
			Core::build_hook( HookEnum::PricingCartIdentityChanged->value ) . '_notice',
			$message,
			$country_changed
		);

		if ( '' === $message ) {
			return;
		}

		wc_add_notice( $message, 'notice' );
	}

	/**
	 * Read the country half of an identity string.
	 *
	 * @param string $identity Identity string.
	 * @return string Country code, or the whole value when it carries no separator.
	 */
	private function country_of( string $identity ): string {
		$parts = explode( '|', $identity );

		return $parts[0];
	}

	/**
	 * Get the session the cart was just restored from.
	 *
	 * @return WC_Session Session handler of the current request.
	 */
	private function get_session(): WC_Session {
		return WC()->session;
	}
}
