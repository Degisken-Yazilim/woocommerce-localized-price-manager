<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Shared;

use D6N\LPM\Core\Enums\OptionsEnum;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Core utility class providing plugin configuration and helper methods.
 */
class Core {

	/**
	 * Seconds an undisplayed admin notice queue survives.
	 *
	 * @var int
	 */
	private const NOTICE_QUEUE_TTL = 300;

	/**
	 * WooCommerce options that change how a price is computed or formatted.
	 *
	 * @var array<int, string>
	 */
	public const WC_MONEY_OPTIONS = array(
		'woocommerce_currency',
		'woocommerce_currency_pos',
		'woocommerce_price_decimal_sep',
		'woocommerce_price_thousand_sep',
		'woocommerce_price_num_decimals',
	);

	/**
	 * The plugin option row as WordPress answered the first read, `null` until then.
	 *
	 * @var mixed
	 */
	private static mixed $option_row = null;

	/**
	 * Get plugin domain/text domain.
	 *
	 * @return string Plugin domain identifier.
	 */
	public static function get_domain(): string {
		return D6N_LPM_DOMAIN;
	}

	/**
	 * Get plugin version.
	 *
	 * @return string Plugin version number.
	 */
	public static function get_version(): string {
		return D6N_LPM_VERSION;
	}

	/**
	 * Get plugin directory URL.
	 *
	 * @return string URL to the plugin directory.
	 */
	public static function get_plugin_dir_url(): string {
		return D6N_LPM_DIR_URL;
	}

	/**
	 * Check if the plugin master switch is active.
	 *
	 * @return bool True if active, false otherwise.
	 */
	public static function is_active(): bool {
		$option = self::option_row();

		if ( ! is_array( $option ) ) {
			return false;
		}

		$enabled = $option[ OptionsEnum::MasterEnabled->value ] ?? 'yes';

		return 'yes' === $enabled || true === $enabled || 1 === $enabled || '1' === $enabled;
	}

	/**
	 * Get required capability for admin access.
	 *
	 * @return string
	 */
	public static function get_admin_capability(): string {
		return 'manage_woocommerce';
	}

	/**
	 * Whether the current user may delete diagnostic log entries.
	 *
	 * @return bool True when the current user may purge log entries.
	 */
	public static function can_purge_logs(): bool {
		if ( is_multisite() ) {
			return is_super_admin();
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Get plugin option value.
	 *
	 * @param string $name     Option name to retrieve.
	 * @param mixed  $fallback Fallback value if option doesn't exist.
	 * @return mixed Option value or fallback.
	 */
	public static function get_option( string $name, mixed $fallback = false ): mixed {
		$option = self::option_row();

		if ( false === $option ) {
			return $fallback;
		}

		return $option[ $name ] ?? $fallback;
	}

	/**
	 * Save plugin option value.
	 *
	 * @param string $name  Option name to save.
	 * @param mixed  $value Option value to save.
	 * @return bool True if saved successfully, false otherwise.
	 */
	public static function save_option( string $name, mixed $value ): bool {
		$option = get_option( self::get_domain() );
		$option = ( false === $option ) ? array() : (array) $option;

		$option[ $name ] = $value;

		$saved = update_option( self::get_domain(), $option );

		self::forget_option_row();

		return $saved;
	}

	/**
	 * Drop the remembered option row so the next read goes back to WordPress.
	 *
	 * @return void
	 */
	public static function forget_option_row(): void {
		self::$option_row = null;
	}

	/**
	 * Read the plugin option row once per request.
	 *
	 * @return mixed The stored row, or false when there is none.
	 */
	private static function option_row(): mixed {
		if ( null === self::$option_row ) {
			self::$option_row = get_option( self::get_domain() );
		}

		return self::$option_row;
	}

	/**
	 * Display admin notices from transient storage.
	 *
	 * @return bool|null
	 */
	public static function display_admin_notices(): ?bool {
		$errors = get_transient( self::get_domain() . '_messages' );
		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return null;
		}
		foreach ( $errors as $error ) {
			[ $type, $message ] = $error;
			if ( empty( $message ) ) {
				continue;
			}
			$type = match ( $type ) {
				'update', 'updated', 'inserted', 'saved', 'success' => 'success',
				'delete', 'deleted', 'remove', 'removed', 'error' => 'error',
				default => 'info',
			};
			?>
			<div class="notice-<?php echo esc_attr( $type ); ?> notice is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}

		return delete_transient( self::get_domain() . '_messages' );
	}

	/**
	 * Add admin notice to transient queue.
	 *
	 * @param string $message Notice message to display.
	 * @param string $type    Notice type.
	 * @return bool
	 */
	public static function add_admin_notice( string $message, string $type = 'notice' ): bool {
		$errors = get_transient( self::get_domain() . '_messages' );
		if ( empty( $errors ) || ! is_array( $errors ) ) {
			$errors = array();
		}

		$notice = array( $type, $message );

		if ( in_array( $notice, $errors, true ) ) {
			return true;
		}

		$errors[] = $notice;
		return set_transient( self::get_domain() . '_messages', $errors, self::NOTICE_QUEUE_TTL );
	}

	/**
	 * Get WooCommerce shop base currency.
	 *
	 * @return string Uppercase currency code.
	 */
	public static function get_shop_base_currency(): string {
		$wc_currency = get_option( 'woocommerce_currency', 'USD' );
		return strtoupper( is_string( $wc_currency ) && ! empty( $wc_currency ) ? $wc_currency : 'USD' );
	}

	/**
	 * Get the country the shop itself sells from.
	 *
	 * @return string Uppercase ISO 3166-1 alpha-2 code, empty when unset.
	 */
	public static function get_shop_base_country(): string {
		$stored = get_option( 'woocommerce_default_country', '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		$country = strtoupper( trim( explode( ':', $stored, 2 )[0] ) );

		return 1 === preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	/**
	 * Get the number of decimals the shop's own base currency is kept in.
	 *
	 * @return int Decimal count, never negative.
	 */
	public static function get_shop_price_decimals(): int {
		$decimals = get_option( 'woocommerce_price_num_decimals', 2 );

		return max( 0, is_scalar( $decimals ) ? (int) $decimals : 2 );
	}

	/**
	 * Get LPM global currency setting, falling back to shop base currency.
	 *
	 * @return string Uppercase currency code.
	 */
	public static function get_global_currency(): string {
		$global = self::get_option( OptionsEnum::GlobalCurrency->value );
		return ! empty( $global ) ? strtoupper( (string) $global ) : self::get_shop_base_currency();
	}

	/**
	 * Build hook name.
	 *
	 * @param string $suffix Hook suffix.
	 * @return non-empty-string Full hook name.
	 */
	public static function build_hook( string $suffix ): string {
		return self::get_hook_prefix() . $suffix;
	}

	/**
	 * Get WordPress action hook prefix.
	 *
	 * @return non-empty-string
	 */
	public static function get_hook_prefix(): string {
		return 'd6n_lpm_';
	}

	private const ENC_PREFIX = '$lpm$';

	private const CIPHER_METHOD = 'aes-256-gcm';

	private const NONCE_BYTES = 12;

	private const TAG_BYTES = 16;

	/**
	 * Derive the symmetric cipher key from the installation's auth salt.
	 *
	 * @return string Raw 32 byte key.
	 * @throws RuntimeException If the installation has no usable auth salt.
	 */
	private static function get_cipher_key(): string {
		if ( ! defined( 'AUTH_KEY' ) || ! is_string( AUTH_KEY ) || '' === AUTH_KEY ) {
			throw new RuntimeException( 'AUTH_KEY is not defined; encrypted values cannot be processed.' );
		}

		return hash( 'sha256', AUTH_KEY, true );
	}

	/**
	 * Encrypt plain-text string using authenticated AES-256-GCM.
	 *
	 * @param string $plain_text Value to encrypt.
	 * @return string Encrypted string.
	 * @throws RuntimeException If encryption fails.
	 */
	public static function encrypt( string $plain_text ): string {
		if ( '' === $plain_text ) {
			return '';
		}

		$key   = self::get_cipher_key();
		$nonce = random_bytes( self::NONCE_BYTES );
		$tag   = '';

		$cipher = openssl_encrypt( $plain_text, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $nonce, $tag );

		if ( false === $cipher || strlen( $tag ) !== self::TAG_BYTES ) {
			throw new RuntimeException( 'Failed to encrypt data.' );
		}

		return self::ENC_PREFIX . base64_encode( $nonce . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Check whether a stored value already carries the cipher envelope.
	 *
	 * @param string $value Stored value.
	 * @return bool True when the value is already encrypted.
	 */
	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::ENC_PREFIX );
	}

	/**
	 * Decrypt string encrypted with encrypt().
	 *
	 * @param string $cipher_text Encrypted string or plain text.
	 * @return string Decrypted plain text.
	 * @throws RuntimeException If decryption fails.
	 */
	public static function decrypt( string $cipher_text ): string {
		if ( '' === $cipher_text || ! self::is_encrypted( $cipher_text ) ) {
			return $cipher_text;
		}

		$key  = self::get_cipher_key();
		$data = base64_decode( substr( $cipher_text, strlen( self::ENC_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $data ) {
			throw new RuntimeException( 'Failed to decode base64 encrypted data.' );
		}
		if ( strlen( $data ) < self::NONCE_BYTES + self::TAG_BYTES + 1 ) {
			throw new RuntimeException( 'Encrypted data is too short to be valid.' );
		}

		$nonce     = substr( $data, 0, self::NONCE_BYTES );
		$tag       = substr( $data, self::NONCE_BYTES, self::TAG_BYTES );
		$encrypted = substr( $data, self::NONCE_BYTES + self::TAG_BYTES );
		$decrypted = openssl_decrypt( $encrypted, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $nonce, $tag );

		if ( false === $decrypted ) {
			throw new RuntimeException( 'Failed to decrypt data. The data may be corrupted or tampered with.' );
		}

		return $decrypted;
	}

	/**
	 * Mask API key for display.
	 *
	 * @param string $key Plain-text API key.
	 * @return string Masked key.
	 */
	public static function mask_api_key( string $key ): string {
		$len = strlen( $key );
		if ( $len <= 5 ) {
			return str_repeat( '*', $len );
		}
		return str_repeat( '*', $len - 5 ) . substr( $key, -5 );
	}

	/**
	 * Sanitize API key input.
	 *
	 * @param string $key Raw API key.
	 * @return string
	 */
	public static function sanitize_api_key( string $key ): string {
		return trim( wp_unslash( $key ) );
	}

	/**
	 * Check if value is a masked API key.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	public static function is_masked_api_key( string $value ): bool {
		return 1 === preg_match( '/^\*{5,}[^*]{5}$/', $value );
	}
}
