<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Services;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\ICustomerCountryService;
use D6N\LPM\Core\Contracts\IGeolocationResolver;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\CacheGroupEnum;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\IPDetectionStrategyEnum;
use D6N\LPM\Core\Enums\OptionsEnum;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Core\Shared\Utils;
use WC_Session;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Customer country detection service.
 */
class CustomerCountryService implements ICustomerCountryService, IHookable {

	private const VALIDITY_PERIOD       = 43200;
	private const GUEST_VALIDITY_PERIOD = 86400;

	/**
	 * Log source for this service.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'Geolocation';

	/**
	 * Country the client IP resolved to this request, `null` when it resolved to none.
	 *
	 * @var string|null
	 */
	private ?string $ip_country = null;

	/**
	 * Whether the client IP has been resolved this request.
	 *
	 * @var bool
	 */
	private bool $ip_country_resolved = false;

	private bool $cookie_localization_enabled = false;

	private bool $cookie_sync_allowed = false;

	private ?string $cookie_value_written = null;

	/**
	 * Constructor.
	 *
	 * @param ILogger              $logger      Logger instance.
	 * @param ICache               $cache       Cache adapter instance.
	 * @param IGeolocationResolver $geolocation Geolocation resolver instance.
	 */
	public function __construct(
		private readonly ILogger $logger,
		private readonly ICache $cache,
		private readonly IGeolocationResolver $geolocation
	) {}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_login', array( $this, 'on_user_login' ), 11, 2 );
		add_action( 'wp_loaded', array( $this, 'detect_guest_country' ), 20 );
		add_filter( Core::build_hook( HookEnum::IPDetectionStrategy->value ), array( $this, 'configure_ip_detection_strategy' ) );
	}

	/**
	 * Register country cookie synchronization.
	 *
	 * @param bool $localizes Whether localized pricing is active.
	 * @return void
	 */
	public function register_cookie_sync( bool $localizes ): void {
		$this->cookie_localization_enabled = $localizes;
		$this->cookie_sync_allowed         = ( ! is_admin() || wp_doing_ajax() ) && ! wp_doing_cron() && ( ! defined( 'WP_CLI' ) || ! WP_CLI );
		add_action( 'wp', array( $this, 'sync_country_cookie_on_load' ), 21 );
	}

	/**
	 * Synchronize the country cookie before storefront output.
	 *
	 * @return void
	 */
	public function sync_country_cookie_on_load(): void {
		if ( is_admin() || ! is_woocommerce() || 'GET' !== filter_input( INPUT_SERVER, 'REQUEST_METHOD' ) ) {
			return;
		}

		if ( ! $this->cookie_localization_enabled || 'yes' !== Core::get_option( OptionsEnum::CountryCookie->value, 'no' ) ) {
			$this->sync_country_cookie( null );
			return;
		}

		$this->get_current_user_country();
	}

	/**
	 * Get current visitor's country code.
	 *
	 * @return string|null Two-letter ISO country code.
	 */
	public function get_current_user_country(): ?string {
		$override = $this->get_debug_country_override();

		if ( null !== $override ) {
			return $override;
		}

		$country = $this->resolve_current_country();
		if ( $this->cookie_sync_allowed ) {
			$this->sync_country_cookie( $country );
		}

		return $country;
	}

	/**
	 * Resolve the current visitor's country.
	 *
	 * @return string|null Country code or null.
	 */
	private function resolve_current_country(): ?string {
		$mode = Core::get_option( OptionsEnum::CustomerGeolocation->value, 'geolocation' );

		if ( is_user_logged_in() ) {
			if ( 'address' === $mode ) {
				$country = $this->get_country_from_customer_address();

				if ( null !== $country ) {
					return $this->filter_user_country( $country );
				}

				return null;
			}

			$user_id    = get_current_user_id();
			$last_check = get_user_meta( $user_id, '_lpm_country_last_check', true );
			$country    = null;

			if ( empty( $last_check ) || (int) $last_check < time() - self::VALIDITY_PERIOD ) {
				$country = $this->resolve_ip2country();

				if ( null !== $country ) {
					update_user_meta( $user_id, '_lpm_country', $country );
					update_user_meta( $user_id, '_lpm_country_last_check', time() );
				}
			} else {
				$country = Utils::normalize_country_code( get_user_meta( $user_id, '_lpm_country', true ) );
			}

			return null !== $country ? $this->filter_user_country( $country ) : null;
		}

		if ( 'address' === $mode ) {
			return null;
		}

		$session_country = $this->get_country_from_session();

		if ( null !== $session_country ) {
			/**
			 * Filters the country code detected for a guest visitor.
			 *
			 * @param string $country Upper-case two-letter ISO 3166-1 alpha-2 country code.
			 */
			return apply_filters( Core::build_hook( HookEnum::CountryGetGuestCountry->value ), $session_country );
		}

		return $this->resolve_ip2country();
	}

	/**
	 * Synchronize the country cookie.
	 *
	 * @param string|null $country Resolved country code.
	 * @return void
	 */
	private function sync_country_cookie( ?string $country ): void {
		$enabled = $this->cookie_localization_enabled && 'yes' === Core::get_option( OptionsEnum::CountryCookie->value, 'no' );
		$value   = '';

		if ( null !== $this->cookie_value_written ) {
			$value = $this->cookie_value_written;
		} elseif ( array_key_exists( 'lpm_country', $_COOKIE ) ) {
			$value = is_string( $_COOKIE['lpm_country'] ) ? filter_var( wp_unslash( $_COOKIE['lpm_country'] ), FILTER_VALIDATE_REGEXP, array( 'options' => array( 'regexp' => '/^[A-Z]{2}$/D' ) ) ) : null;
		}
		$country = $enabled ? Utils::normalize_country_code( $country ) : null;

		if ( $value === ( $country ?? '' ) ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( headers_sent() ) {
			return;
		}

		$written = setcookie(
			'lpm_country',
			$country ?? '',
			array(
				'expires'  => time() + ( null !== $country ? self::GUEST_VALIDITY_PERIOD : -YEAR_IN_SECONDS ),
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => (string) COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		if ( $written ) {
			$this->cookie_value_written = $country ?? '';
		}
	}

	/**
	 * Configure IP detection strategy from the plugin settings.
	 *
	 * @param IPDetectionStrategyEnum $default_strategy Default strategy.
	 * @return IPDetectionStrategyEnum Configured strategy.
	 */
	public function configure_ip_detection_strategy( IPDetectionStrategyEnum $default_strategy ): IPDetectionStrategyEnum {
		$setting = Core::get_option( OptionsEnum::IPDetectionStrategy->value, $default_strategy->value );

		return IPDetectionStrategyEnum::from_option( $setting, $default_strategy );
	}

	/**
	 * Event handler for user login.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       User object.
	 * @return void
	 */
	public function on_user_login( string $user_login, WP_User $user ): void {
		$country = $this->resolve_ip2country();

		if ( null !== $country ) {
			update_user_meta( $user->ID, '_lpm_country', $country );
			update_user_meta( $user->ID, '_lpm_country_last_check', time() );

			do_action( Core::build_hook( HookEnum::CountryCustomerDetected->value ), $country, $user );
		}

		if ( ! $this->cookie_sync_allowed || ! $this->cookie_localization_enabled || 'yes' !== Core::get_option( OptionsEnum::CountryCookie->value, 'no' ) || null !== $this->get_debug_country_override() ) {
			return;
		}

		if ( 'address' === Core::get_option( OptionsEnum::CustomerGeolocation->value, 'geolocation' ) ) {
			$country = $this->get_country_from_user_meta_address( $user->ID );
		} elseif ( null === $country ) {
			$last_check = get_user_meta( $user->ID, '_lpm_country_last_check', true );

			if ( ! empty( $last_check ) && (int) $last_check >= time() - self::VALIDITY_PERIOD ) {
				$country = Utils::normalize_country_code( get_user_meta( $user->ID, '_lpm_country', true ) );
			}
		}

		if ( null !== $country ) {
			$country = apply_filters( Core::build_hook( HookEnum::CountryGetUserCountry->value ), $country, $user );
		}

		$this->sync_country_cookie( $country );
	}

	/**
	 * Detect and store country for guest visitor.
	 *
	 * @return void
	 */
	public function detect_guest_country(): void {
		$session = $this->get_session();

		if ( is_user_logged_in() || null === $session ) {
			return;
		}

		$last_check = $session->get( '_lpm_country_last_check' );

		if ( ! empty( $last_check ) && (int) $last_check > time() - self::GUEST_VALIDITY_PERIOD ) {
			return;
		}

		$country = $this->resolve_ip2country();

		if ( null === $country ) {
			return;
		}

		$session->set( '_lpm_country', $country );
		$session->set( '_lpm_country_last_check', time() );

		do_action( Core::build_hook( HookEnum::CountryGuestDetected->value ), $country );

		if ( $this->cookie_sync_allowed && $this->cookie_localization_enabled && 'yes' === Core::get_option( OptionsEnum::CountryCookie->value, 'no' ) ) {
			$this->get_current_user_country();
		}
	}


	/**
	 * Read the country preview override from the query string or session.
	 *
	 * @return string|null Country code, or null when there is no usable override.
	 */
	private function get_debug_country_override(): ?string {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return null;
		}

		if ( ! $this->may_preview_another_country() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only debug preview switch.
		if ( isset( $_GET['lpm_country'] ) && $this->has_valid_preview_nonce() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only debug preview switch.
			if ( ! is_string( $_GET['lpm_country'] ) ) {
				return null;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only debug preview switch.
			$requested = sanitize_text_field( wp_unslash( $_GET['lpm_country'] ) );

			if ( 'clear' === strtolower( $requested ) || '' === $requested ) {
				$this->mark_request_uncacheable();
				$session = $this->get_session();
				if ( null !== $session ) {
					$session->set( '_lpm_debug_country', null );
				}

				return null;
			}

			$country = Utils::normalize_country_code( $requested );

			if ( null === $country ) {
				$this->logger->debug(
					'Ignored an invalid lpm_country debug override.',
					array(),
					self::LOG_SOURCE
				);

				return null;
			}

			$this->mark_request_uncacheable();

			$session = $this->get_session();
			if ( null !== $session ) {
				$session->set( '_lpm_debug_country', $country );
			}

			return $country;
		}

		$session = $this->get_session();
		if ( null !== $session ) {
			$preview = Utils::normalize_country_code( $session->get( '_lpm_debug_country' ) );

			if ( null !== $preview ) {
				$this->mark_request_uncacheable();

				return $preview;
			}
		}

		return null;
	}

	/**
	 * Verify the preview action for the current session.
	 *
	 * @return bool Whether the request carries a valid nonce.
	 */
	private function has_valid_preview_nonce(): bool {
		$action = 'd6n_lpm_country_preview';
		if ( ! is_user_logged_in() ) {
			$session     = $this->get_session();
			$customer_id = null !== $session ? $session->get_customer_id() : '';
			if ( '' === $customer_id ) {
				return false;
			}
			$action .= ':' . $customer_id;
		}

		return isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) && false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $action );
	}

	/**
	 * Check whether this visitor is allowed to preview another country.
	 *
	 * @return bool True when the override may be honoured.
	 */
	private function may_preview_another_country(): bool {
		$allowed = current_user_can( Core::get_admin_capability() );

		/**
		 * Filters whether the current visitor may preview the storefront as
		 * another country through `?lpm_country=XX`.
		 *
		 * @param bool $allowed True when the visitor holds the shop management capability.
		 */
		return (bool) apply_filters( Core::build_hook( HookEnum::CountryAllowPreview->value ), $allowed );
	}

	/**
	 * Mark the current request as uncacheable.
	 *
	 * @return void
	 */
	private function mark_request_uncacheable(): void {
		foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				define( $constant, true );
			}
		}
	}

	/**
	 * Apply the user country filter to a resolved code.
	 *
	 * @param string $country Country code.
	 * @return string Filtered country code.
	 */
	private function filter_user_country( string $country ): string {
		/**
		 * Filters the country code detected for a logged-in customer.
		 *
		 * @param string  $country Upper-case two-letter ISO 3166-1 alpha-2 country code.
		 * @param WP_User $user    The logged-in user.
		 */
		return apply_filters( Core::build_hook( HookEnum::CountryGetUserCountry->value ), $country, wp_get_current_user() );
	}

	/**
	 * Read the country stored on the WooCommerce customer object.
	 *
	 * @return string|null Country code or null.
	 */
	private function get_country_from_customer_address(): ?string {
		// @phpstan-ignore nullCoalesce.property
		$customer = WC()->customer ?? null;

		// @phpstan-ignore function.alreadyNarrowedType
		if ( ! is_object( $customer ) ) {
			return $this->get_country_from_user_meta_address( get_current_user_id() );
		}

		$country = Utils::normalize_country_code( $customer->get_billing_country() );

		return $country ?? Utils::normalize_country_code( $customer->get_shipping_country() );
	}

	/**
	 * Read the saved address country of a user from user meta, as the session-backed customer reports it.
	 *
	 * @param int $user_id User ID.
	 * @return string|null Country code or null.
	 */
	private function get_country_from_user_meta_address( int $user_id ): ?string {
		$billing = get_user_meta( $user_id, 'billing_country', true );

		if ( ! $billing ) {
			$billing = wc_get_customer_default_location()['country'];
		}

		$country = Utils::normalize_country_code( $billing );

		return $country ?? Utils::normalize_country_code( get_user_meta( $user_id, 'shipping_country', true ) );
	}

	/**
	 * Read the country cached on the guest session.
	 *
	 * @return string|null Country code or null.
	 */
	private function get_country_from_session(): ?string {
		$session = $this->get_session();

		if ( null === $session ) {
			return null;
		}

		return Utils::normalize_country_code( $session->get( '_lpm_country' ) );
	}

	/**
	 * Get the WooCommerce session handler when one is available.
	 *
	 * @return WC_Session|null Session handler, or null when this request has none.
	 */
	private function get_session(): ?WC_Session {
		// @phpstan-ignore-next-line
		return self::narrow_session( WC()->session ?? null );
	}

	/**
	 * Narrow a raw `WC()->session` value to a session handler, or to null.
	 *
	 * @param mixed $session Raw property value.
	 * @return WC_Session|null Session handler, or null when the value is not one.
	 */
	private static function narrow_session( mixed $session ): ?WC_Session {
		return $session instanceof WC_Session ? $session : null;
	}

	/**
	 * Resolve the country of the current client IP, through the cache.
	 *
	 * @return string|null Country code or null when detection failed.
	 */
	private function resolve_ip2country(): ?string {
		if ( $this->ip_country_resolved ) {
			return $this->ip_country;
		}

		$this->ip_country_resolved = true;
		$this->ip_country          = $this->lookup_ip2country();

		return $this->ip_country;
	}

	/**
	 * Look up the country of the current client IP, through the cache.
	 *
	 * @return string|null Country code or null when detection failed.
	 */
	private function lookup_ip2country(): ?string {
		$ip = Utils::get_client_ip();

		if ( null === $ip ) {
			$this->logger->warning(
				'Could not determine the client IP address, so no country can be detected.',
				Utils::describe_ip_detection(),
				self::LOG_SOURCE
			);

			return null;
		}

		$country = $this->cache->remember(
			'ip_' . self::fingerprint_ip( $ip ),
			fn (): ?string => $this->geolocation->resolve( $ip ),
			CacheGroupEnum::Geolocation->ttl(),
			CacheGroupEnum::Geolocation->value
		);

		return Utils::normalize_country_code( $country );
	}

	/**
	 * Reduce a client IP to a key that does not carry it.
	 *
	 * @param string $ip Client IP address.
	 * @return string Stable, non-reversible identifier for that address.
	 */
	private static function fingerprint_ip( string $ip ): string {
		return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}
}
