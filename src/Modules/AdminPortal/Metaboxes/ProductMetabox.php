<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Metaboxes;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Enums\NonceEnum;
use D6N\LPM\Core\Enums\WooHookPriorityEnum;
use D6N\LPM\Core\Money\ExchangeRate;
use D6N\LPM\Core\Money\MoneyAmount;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\Support\CountryReference;
use D6N\LPM\Modules\Cache\Services\PriceCacheService;
use D6N\LPM\Modules\PricingEngine\CountryPricing\CountryPricingRepository;
use D6N\LPM\Modules\PricingEngine\CountryPricing\CountryPricingRuleGuard;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Product data panel for the per-country pricing rules of a simple product.
 */
class ProductMetabox implements IHookable {

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'AdminPortal';

	/**
	 * Refusal reason: an amount, rate or currency the schema does not accept.
	 *
	 * @var string
	 */
	private const REFUSED_INVALID_VALUE = 'invalid_value';

	/**
	 * Constructor.
	 *
	 * @param CountryPricingRepository $repository  Country pricing repository.
	 * @param PriceCacheService        $price_cache Resolved-price cache, the owner of its key scheme.
	 * @param ILogger                  $logger      Logger instance.
	 * @param CountryReference         $reference   WooCommerce country and currency lists.
	 * @param CountryPricingRuleGuard  $rule_guard  Rules a rule row must satisfy against the base product prices.
	 */
	public function __construct(
		private readonly CountryPricingRepository $repository,
		private readonly PriceCacheService $price_cache,
		private readonly ILogger $logger,
		private readonly CountryReference $reference,
		private readonly CountryPricingRuleGuard $rule_guard
	) {}

	/**
	 * Register WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress action and filter hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ), WooHookPriorityEnum::Late->value, 1 );
	}

	/**
	 * Add the "Localized Prices" tab to the product data box.
	 *
	 * @param array<string, mixed> $tabs Current tabs.
	 * @return array<string, mixed> Tabs including this module's own.
	 */
	public function add_product_data_tab( array $tabs ): array {
		$tabs['lpm_country_pricing'] = array(
			'label'    => __( 'Localized Prices', 'localized-price-manager' ),
			'target'   => 'lpm_country_pricing_data',
			'class'    => array( 'show_if_simple' ),
			'priority' => 70,
		);

		return $tabs;
	}

	/**
	 * Render the panel contents.
	 *
	 * @return void
	 */
	public function render_product_data_panel(): void {
		global $post;

		$product_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;

		wp_nonce_field( NonceEnum::ProductMetabox->action(), NonceEnum::ProductMetabox->value );

		$rules = $product_id > 0 ? $this->repository->get_by_product( $product_id, false ) : array();
		$rules = is_wp_error( $rules ) ? array() : $rules;

		$countries  = $this->reference->get_countries();
		$currencies = $this->reference->get_currencies();

		echo '<div id="lpm_country_pricing_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group lpm-country-pricing">';
		echo '<h3>' . esc_html__( 'Per-country price overrides', 'localized-price-manager' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Countries listed here override every category and global rule. Set a price, or leave the price empty and set a rate to derive it. With both left empty the base price is converted into the row\'s currency at the stored exchange rate.', 'localized-price-manager' ) . '</p>';

		echo '<table class="widefat striped lpm-country-pricing-table" id="lpm_country_pricing_table">';
		$this->render_head();
		echo '<tbody id="lpm_country_pricing_rows">';

		foreach ( $rules as $rule ) {
			$this->render_row( (array) $rule, $countries, $currencies );
		}

		echo '</tbody></table>';

		echo '<p class="lpm-country-pricing-actions">';
		echo '<button type="button" class="button button-secondary" id="lpm_add_country_row_btn">' . esc_html__( '+ Add country', 'localized-price-manager' ) . '</button>';
		echo '</p>';

		echo '<template id="lpm-country-row-template">';
		$this->render_row( array(), $countries, $currencies );
		echo '</template>';

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the table head.
	 *
	 * @return void
	 */
	private function render_head(): void {
		echo '<thead><tr>';
		$this->render_header_cell( __( 'Active', 'localized-price-manager' ), __( 'Enable or disable this country rule.', 'localized-price-manager' ), 'lpm-help-active' );
		$this->render_header_cell( __( 'Country', 'localized-price-manager' ), __( 'Select the country this rule applies to.', 'localized-price-manager' ), 'lpm-help-country' );
		$this->render_header_cell( __( 'Currency', 'localized-price-manager' ), __( 'Target currency for this country. Leave empty to use the country default currency.', 'localized-price-manager' ), 'lpm-help-currency' );
		$this->render_header_cell( __( 'Price', 'localized-price-manager' ), __( 'Fixed regular price for this country. Leave the price and the rate empty to convert the base price automatically.', 'localized-price-manager' ), 'lpm-help-price' );
		$this->render_header_cell( __( 'Sale price', 'localized-price-manager' ), __( 'Fixed sale price for this country. Active only when the base product has a sale price.', 'localized-price-manager' ), 'lpm-help-sale-price' );
		$this->render_header_cell( __( 'Rate', 'localized-price-manager' ), __( 'Custom exchange rate multiplier. Leave empty to use the store exchange rate.', 'localized-price-manager' ), 'lpm-help-rate' );
		$this->render_header_cell( __( 'Disable sale', 'localized-price-manager' ), __( 'Hide this product and prevent customers from purchasing it in this country.', 'localized-price-manager' ), 'lpm-help-disable-sale' );
		echo '<th>' . esc_html__( 'Remove', 'localized-price-manager' ) . '</th>';
		echo '</tr></thead>';
	}

	/**
	 * Render one table header cell carrying a help popover.
	 *
	 * @param string $label     Column label.
	 * @param string $help_text Popover text.
	 * @param string $help_id   Popover DOM id.
	 * @return void
	 */
	private function render_header_cell( string $label, string $help_text, string $help_id ): void {
		echo '<th class="lpm-th-with-help">';
		echo '<span class="lpm-th-label">' . esc_html( $label ) . '</span> ';
		/* translators: %s: label of the field this help text belongs to. */
		$this->render_help( $help_id, sprintf( __( 'Help: %s', 'localized-price-manager' ), $label ), $help_text );
		echo '</th>';
	}

	/**
	 * Render the `?` popover trigger and popover bubble.
	 *
	 * @param string $help_id Popover DOM id.
	 * @param string $label   Accessible trigger label.
	 * @param string $text    Popover text.
	 * @return void
	 */
	private function render_help( string $help_id, string $label, string $text ): void {
		echo '<span class="lpm-help" data-lpm-help="1">';
		printf(
			'<button type="button" class="lpm-help-trigger" aria-describedby="%1$s" aria-label="%2$s">?</button>',
			esc_attr( $help_id ),
			esc_attr( $label )
		);
		printf(
			'<span class="lpm-help-popover" id="%1$s" role="tooltip">%2$s</span>',
			esc_attr( $help_id ),
			esc_html( $text )
		);
		echo '</span>';
	}

	/**
	 * Render one rule row.
	 *
	 * @param array<string, mixed>  $rule       Stored rule, empty for the blank template row.
	 * @param array<string, string> $countries  Country choices.
	 * @param array<string, string> $currencies Currency choices.
	 * @return void
	 */
	private function render_row( array $rule, array $countries, array $currencies ): void {
		$country  = strtoupper( (string) ( $rule['country_code'] ?? '' ) );
		$currency = strtoupper( (string) ( $rule['local_currency'] ?? '' ) );

		echo '<tr class="lpm-country-pricing-row">';

		echo '<td class="lpm-cell-active">';
		$this->render_flag_toggle(
			'lpm_is_active[]',
			! array_key_exists( 'is_active', $rule ) || (bool) $rule['is_active'],
			__( 'Rule is active', 'localized-price-manager' )
		);
		echo '</td>';

		echo '<td class="lpm-cell-country">';
		$this->render_select( 'lpm_country_codes[]', $countries, $country, __( '— Select country —', 'localized-price-manager' ) );
		echo '</td>';

		echo '<td class="lpm-cell-currency">';
		$this->render_select( 'lpm_local_currencies[]', $currencies, $currency, __( '— Country default —', 'localized-price-manager' ) );
		echo '</td>';

		echo '<td class="lpm-cell-price">';
		$this->render_number( 'lpm_local_prices[]', $rule['local_price'] ?? null, '0.00000001' );
		echo '</td>';

		echo '<td class="lpm-cell-sale-price">';
		$this->render_number( 'lpm_local_sale_prices[]', $rule['local_sale_price'] ?? null, '0.00000001' );
		echo '</td>';

		echo '<td class="lpm-cell-rate">';
		$this->render_number( 'lpm_exchange_rates[]', $rule['exchange_rate'] ?? null, '0.000000000001' );
		echo '</td>';

		echo '<td class="lpm-cell-blocked">';
		$this->render_flag_toggle(
			'lpm_is_blocked[]',
			(bool) ( $rule['is_blocked'] ?? false ),
			__( 'Block this country', 'localized-price-manager' )
		);
		echo '</td>';

		echo '<td class="lpm-cell-remove"><button type="button" class="button lpm-remove-row-btn" aria-label="' . esc_attr__( 'Remove row', 'localized-price-manager' ) . '">&times;</button></td>';
		echo '</tr>';
	}

	/**
	 * Render a select control.
	 *
	 * @param string                $name        Field name.
	 * @param array<string, string> $choices     Value to label map.
	 * @param string                $current     Selected value.
	 * @param string                $placeholder Empty option label.
	 * @return void
	 */
	private function render_select( string $name, array $choices, string $current, string $placeholder ): void {
		echo '<select name="' . esc_attr( $name ) . '" class="lpm-select">';
		echo '<option value="">' . esc_html( $placeholder ) . '</option>';

		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( strtoupper( (string) $value ), strtoupper( $current ), false ),
				esc_html( sprintf( '%1$s (%2$s)', (string) $label, strtoupper( (string) $value ) ) )
			);
		}

		echo '</select>';
	}

	/**
	 * Render a yes/no switch over the hidden input that carries its value.
	 *
	 * @param string $name    Field name.
	 * @param bool   $current Current state.
	 * @param string $label   Accessible label for the switch.
	 * @return void
	 */
	private function render_flag_toggle( string $name, bool $current, string $label ): void {
		echo '<label class="lpm-switch">';
		printf(
			'<input type="hidden" class="lpm-flag-value" name="%1$s" value="%2$s" />',
			esc_attr( $name ),
			$current ? '1' : '0'
		);
		printf(
			'<input type="checkbox" class="lpm-flag-toggle" aria-label="%1$s"%2$s />',
			esc_attr( $label ),
			$current ? ' checked="checked"' : ''
		);
		echo '<span class="lpm-switch-slider"></span>';
		echo '</label>';
	}

	/**
	 * Render a number input.
	 *
	 * @param string $name  Field name.
	 * @param mixed  $value Current value.
	 * @param string $step  Step attribute.
	 * @return void
	 */
	private function render_number( string $name, mixed $value, string $step ): void {
		$printable = ( null === $value || '' === $value ) ? '' : (string) $value;

		printf(
			'<input type="number" step="%1$s" min="0" name="%2$s" value="%3$s" class="lpm-input" />',
			esc_attr( $step ),
			esc_attr( $name ),
			esc_attr( $printable )
		);
	}

	/**
	 * Persist the submitted rules.
	 *
	 * @param int $post_id Product post ID.
	 * @return void
	 */
	public function save_product_meta( int $post_id ): void {
		if ( ! isset( $_POST[ NonceEnum::ProductMetabox->value ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ NonceEnum::ProductMetabox->value ] ) ), NonceEnum::ProductMetabox->action() ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$codes = $this->read_post_list( 'lpm_country_codes' );

		$prices       = $this->read_post_list( 'lpm_local_prices' );
		$sale_prices  = $this->read_post_list( 'lpm_local_sale_prices' );
		$currencies   = $this->read_post_list( 'lpm_local_currencies' );
		$rates        = $this->read_post_list( 'lpm_exchange_rates' );
		$auto_convert = $this->read_post_list( 'lpm_auto_convert' );
		$blocked      = $this->read_post_list( 'lpm_is_blocked' );
		$active       = $this->read_post_list( 'lpm_is_active' );

		$snapshot = $this->get_base_price_snapshot( $post_id );

		$submitted = array();
		$written   = array();
		$failed    = array();
		$refused   = array();

		foreach ( $codes as $index => $raw_code ) {
			$country_code = CountryCodeValidator::sanitize( $raw_code );

			if ( '' === $country_code || in_array( $country_code, $submitted, true ) ) {
				continue;
			}

			$submitted[] = $country_code;

			$data = $this->build_rule_data(
				array(
					'price'        => (string) ( $prices[ $index ] ?? '' ),
					'sale_price'   => (string) ( $sale_prices[ $index ] ?? '' ),
					'currency'     => (string) ( $currencies[ $index ] ?? '' ),
					'rate'         => (string) ( $rates[ $index ] ?? '' ),
					'auto_convert' => (string) ( $auto_convert[ $index ] ?? '0' ),
					'blocked'      => (string) ( $blocked[ $index ] ?? '0' ),
					'active'       => (string) ( $active[ $index ] ?? '1' ),
				),
				$snapshot
			);

			if ( is_string( $data ) ) {
				$refused[ $data ][] = $country_code;

				$this->logger->error(
					sprintf( 'Refused the %1$s pricing rule of product %2$d: %3$s', $country_code, $post_id, $this->describe_refusal( $data ) ),
					array(
						'product_id'   => $post_id,
						'country_code' => $country_code,
					),
					self::LOG_SOURCE
				);

				continue;
			}

			$saved = $this->repository->save_rule( $post_id, $country_code, $data );

			if ( is_wp_error( $saved ) ) {
				$failed[] = $country_code;

				$this->logger->error(
					sprintf( 'Failed to save the %1$s pricing rule of product %2$d: %3$s', $country_code, $post_id, $saved->get_error_message() ),
					array(
						'product_id'   => $post_id,
						'country_code' => $country_code,
					),
					self::LOG_SOURCE
				);

				continue;
			}

			$written[] = $country_code;

			$this->logger->audit(
				sprintf( 'Updated the %1$s price rule of product %2$d.', $country_code, $post_id ),
				array(
					'product_id'   => $post_id,
					'country_code' => $country_code,
					'rule'         => $data,
				),
				self::LOG_SOURCE
			);
		}

		$pruned = $this->repository->delete_excluded_countries( $post_id, $submitted );

		if ( is_wp_error( $pruned ) ) {
			$this->logger->error(
				sprintf( 'Failed to prune the pricing rules of product %1$d: %2$s', $post_id, $pruned->get_error_message() ),
				array( 'product_id' => $post_id ),
				self::LOG_SOURCE
			);
		}

		$dropped = $this->rule_guard->drop_sale_prices( $post_id, $snapshot['sale_price'] );

		if ( is_wp_error( $dropped ) ) {
			$this->logger->error(
				sprintf( 'Failed to clear the local sale prices of product %1$d: %2$s', $post_id, $dropped->get_error_message() ),
				array( 'product_id' => $post_id ),
				self::LOG_SOURCE
			);
		}

		$this->price_cache->flush_product( $post_id );

		/**
		 * Fires after the pricing rules of a product were saved and its cached prices dropped.
		 *
		 * @param int $post_id Product ID.
		 */
		do_action( Core::build_hook( HookEnum::ProductRulesSaved->value ), $post_id );

		$this->announce_failed_rules( $failed, $refused, $this->rule_guard->has_cleared_sale_prices( $post_id ) );
	}

	/**
	 * Describe a refusal reason for the log.
	 *
	 * @param string $reason Refusal reason.
	 * @return string Description.
	 */
	private function describe_refusal( string $reason ): string {
		return match ( $reason ) {
			CountryPricingRuleGuard::REFUSED_PRICE_AND_RATE           => 'the row carries both a local price and an exchange rate.',
			CountryPricingRuleGuard::REFUSED_PRICE_WITHOUT_BASE_PRICE => 'the row carries a local price while the product has no regular price.',
			CountryPricingRuleGuard::REFUSED_SALE_PRICE_REQUIRED      => 'the product is on sale and the row carries a local price without a local sale price.',
			CountryPricingRuleGuard::REFUSED_SALE_PRICE_WITHOUT_PRICE => 'the product is on sale and the row carries a local sale price without a local price.',
			default                                                   => 'the submitted amount, rate or currency is not one the schema accepts.',
		};
	}

	/**
	 * Turn one submitted row into column values, or refuse it.
	 *
	 * @param array<string, string>                                                  $row      Raw submitted values for one row.
	 * @param array{price: ?MoneyAmount, sale_price: ?MoneyAmount, currency: string} $snapshot Store base price snapshot.
	 * @return array<string, mixed>|string Column values, or the refusal reason.
	 */
	private function build_rule_data( array $row, array $snapshot ): array|string {
		$is_explicit_auto = '1' === ( $row['auto_convert'] ?? '' );
		$currency         = strtoupper( trim( $row['currency'] ) );

		if ( '' !== $currency && ! $this->reference->is_known_currency( $currency ) ) {
			return self::REFUSED_INVALID_VALUE;
		}

		$price      = $this->money( $is_explicit_auto ? '' : $row['price'] );
		$sale_price = $this->money( $is_explicit_auto ? '' : $row['sale_price'] );
		$rate       = $this->rate( $row['rate'] );

		if ( false === $price || false === $sale_price || false === $rate ) {
			return self::REFUSED_INVALID_VALUE;
		}

		$amounts = $this->rule_guard->check_amounts( $price, $sale_price, $rate, $snapshot['price'], $snapshot['sale_price'] );

		if ( is_string( $amounts ) ) {
			return $amounts;
		}

		$is_auto = $is_explicit_auto || ( null === $amounts['local_price'] && null === $amounts['exchange_rate'] );

		return array(
			'local_price'      => $amounts['local_price'],
			'local_sale_price' => $amounts['local_sale_price'],
			'local_currency'   => $currency,
			'exchange_rate'    => $amounts['exchange_rate'],
			'auto_convert'     => $is_auto ? 1 : 0,
			'is_blocked'       => '1' === $row['blocked'] ? 1 : 0,
			'is_active'        => '0' === $row['active'] ? 0 : 1,
			'input_price'      => $snapshot['price']?->value(),
			'input_sale_price' => $snapshot['sale_price']?->value(),
			'input_currency'   => $snapshot['currency'],
		);
	}

	/**
	 * Read a submitted money field.
	 *
	 * @param string $raw Raw submitted value.
	 * @return MoneyAmount|null|false The amount, null when blank, false when refused.
	 */
	private function money( string $raw ): MoneyAmount|null|false {
		if ( '' === trim( $raw ) ) {
			return null;
		}

		return MoneyAmount::from( $raw ) ?? false;
	}

	/**
	 * Read a submitted rate field, with the same three outcomes as a money field.
	 *
	 * @param string $raw Raw submitted value.
	 * @return ExchangeRate|null|false The rate, null when blank, false when refused.
	 */
	private function rate( string $raw ): ExchangeRate|null|false {
		if ( '' === trim( $raw ) ) {
			return null;
		}

		return ExchangeRate::from( $raw ) ?? false;
	}

	/**
	 * Tell the operator which rules did not reach the table.
	 *
	 * @param array<int, string>                $failed  Country codes whose write failed.
	 * @param array<string, array<int, string>> $refused Country codes whose submitted values were refused, keyed by refusal reason.
	 * @param bool                              $sale_prices_cleared Whether the same save cleared stored local sale prices.
	 * @return void
	 */
	private function announce_failed_rules( array $failed, array $refused, bool $sale_prices_cleared ): void {
		if ( ! empty( $failed ) ) {
			Core::add_admin_notice(
				sprintf(
					/* translators: %s: comma separated list of ISO 3166-1 alpha-2 country codes. */
					_n(
						'Localized Price Manager could not save the price rule for %s.',
						'Localized Price Manager could not save the price rules for %s.',
						count( $failed ),
						'localized-price-manager'
					),
					implode( ', ', $failed )
				) . ' ' . $this->kept_notice( count( $failed ), $sale_prices_cleared ),
				'error'
			);
		}

		foreach ( $refused as $reason => $countries ) {
			Core::add_admin_notice(
				sprintf( $this->refusal_notice( (string) $reason, count( $countries ) ), implode( ', ', $countries ) )
					. ' ' . $this->kept_notice( count( $countries ), $sale_prices_cleared ),
				'error'
			);
		}
	}

	/**
	 * The notice sentence telling what became of the rules already stored for the countries named.
	 *
	 * @param int  $count               Number of countries named.
	 * @param bool $sale_prices_cleared Whether the same save cleared stored local sale prices.
	 * @return string Notice sentence.
	 */
	private function kept_notice( int $count, bool $sale_prices_cleared ): string {
		if ( $sale_prices_cleared ) {
			return _n(
				'The rule already stored for it was kept, except that any local sale price it carried was cleared, because the product is no longer on sale.',
				'The rules already stored for them were kept, except that any local sale prices they carried were cleared, because the product is no longer on sale.',
				$count,
				'localized-price-manager'
			);
		}

		return _n(
			'The rule already stored for it was kept unchanged.',
			'The rules already stored for them were kept unchanged.',
			$count,
			'localized-price-manager'
		);
	}

	/**
	 * The notice template for one refusal reason.
	 *
	 * @param string $reason Refusal reason.
	 * @param int    $count  Number of countries refused for it.
	 * @return string Notice template carrying one %s for the country list.
	 */
	private function refusal_notice( string $reason, int $count ): string {
		if ( CountryPricingRuleGuard::REFUSED_PRICE_AND_RATE === $reason ) {
			/* translators: %s: comma separated list of ISO 3166-1 alpha-2 country codes. */
			return _n(
				'Localized Price Manager did not save the price rule for %s: a rule may carry a local price or an exchange rate, not both.',
				'Localized Price Manager did not save the price rules for %s: a rule may carry a local price or an exchange rate, not both.',
				$count,
				'localized-price-manager'
			);
		}

		if ( CountryPricingRuleGuard::REFUSED_PRICE_WITHOUT_BASE_PRICE === $reason ) {
			/* translators: %s: comma separated list of ISO 3166-1 alpha-2 country codes. */
			return _n(
				'Localized Price Manager did not save the price rule for %s: a local price needs the product to have a regular price.',
				'Localized Price Manager did not save the price rules for %s: a local price needs the product to have a regular price.',
				$count,
				'localized-price-manager'
			);
		}

		if ( CountryPricingRuleGuard::REFUSED_SALE_PRICE_WITHOUT_PRICE === $reason ) {
			/* translators: %s: comma separated list of ISO 3166-1 alpha-2 country codes. */
			return _n(
				'Localized Price Manager did not save the price rule for %s: a local sale price needs a local regular price too.',
				'Localized Price Manager did not save the price rules for %s: a local sale price needs a local regular price too.',
				$count,
				'localized-price-manager'
			);
		}

		if ( CountryPricingRuleGuard::REFUSED_SALE_PRICE_REQUIRED === $reason ) {
			/* translators: %s: comma separated list of ISO 3166-1 alpha-2 country codes. */
			return _n(
				'Localized Price Manager did not save the price rule for %s: the product is on sale, so a local price needs a local sale price too.',
				'Localized Price Manager did not save the price rules for %s: the product is on sale, so a local price needs a local sale price too.',
				$count,
				'localized-price-manager'
			);
		}

		/* translators: %s: comma separated list of ISO 3166-1 alpha-2 country codes. */
		return _n(
			'Localized Price Manager did not save the price rule for %s: a price, sale price or rate was negative, too large, or had more decimal places than the currency allows, or the currency was not one WooCommerce knows.',
			'Localized Price Manager did not save the price rules for %s: a price, sale price or rate was negative, too large, or had more decimal places than the currency allows, or the currency was not one WooCommerce knows.',
			$count,
			'localized-price-manager'
		);
	}

	/**
	 * Read one parallel row array out of the request.
	 *
	 * @param string $key Field name.
	 * @return array<int, string> Sanitized values.
	 */
	private function read_post_list( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) );

		return array_values( array_map( 'strval', $raw ) );
	}

	/**
	 * Snapshot the store base price so the admin screen can detect later drift.
	 *
	 * @param int $product_id Product ID.
	 * @return array{price: ?MoneyAmount, sale_price: ?MoneyAmount, currency: string} Snapshot.
	 */
	private function get_base_price_snapshot( int $product_id ): array {
		$snapshot = array(
			'price'      => null,
			'sale_price' => null,
			'currency'   => Core::get_shop_base_currency(),
		);

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return $snapshot;
		}

		$snapshot['price']      = MoneyAmount::from( (string) $product->get_regular_price() );
		$snapshot['sale_price'] = MoneyAmount::from( (string) $product->get_sale_price() );

		return $snapshot;
	}
}
