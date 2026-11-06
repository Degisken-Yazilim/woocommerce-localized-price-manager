<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Metaboxes;

use D6N\LPM\Core\Contracts\ICache;
use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Contracts\ILogger;
use D6N\LPM\Core\Enums\CacheGroupEnum;
use D6N\LPM\Core\Enums\HookEnum;
use D6N\LPM\Core\Money\ExchangeRate;
use D6N\LPM\Core\Shared\Core;
use D6N\LPM\Modules\AdminPortal\Support\CountryReference;
use D6N\LPM\Modules\PricingEngine\CategoryPricing\CategoryPricingRepository;
use D6N\LPM\Modules\PricingEngine\Support\CountryCodeValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Product category term fields for the category level pricing rules.
 */
class CategoryMetabox implements IHookable {

	/**
	 * Nonce action of the term form.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'd6n_lpm_save_category_pricing';

	/**
	 * Nonce field name of the term form.
	 *
	 * @var string
	 */
	public const NONCE_FIELD = 'd6n_lpm_category_pricing_nonce';

	/**
	 * Capability required to edit product category pricing.
	 *
	 * @var string
	 */
	public const CAPABILITY = 'manage_product_terms';

	/**
	 * Log source used for every record this class emits.
	 *
	 * @var string
	 */
	private const LOG_SOURCE = 'AdminPortal';

	/**
	 * Constructor.
	 *
	 * @param CategoryPricingRepository $repository Category pricing rule repository.
	 * @param ICache                    $cache      Cache adapter.
	 * @param ILogger                   $logger     Logger instance.
	 * @param CountryReference          $reference  WooCommerce country and currency lists.
	 */
	public function __construct(
		private readonly CategoryPricingRepository $repository,
		private readonly ICache $cache,
		private readonly ILogger $logger,
		private readonly CountryReference $reference
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
		add_action( 'product_cat_add_form_fields', array( $this, 'render_add_category_fields' ) );
		add_action( 'product_cat_edit_form_fields', array( $this, 'render_edit_category_fields' ), 10, 1 );
		add_action( 'created_product_cat', array( $this, 'save_category_meta' ), 10, 1 );
		add_action( 'edited_product_cat', array( $this, 'save_category_meta' ), 10, 1 );
	}

	/**
	 * Render the fields on the "Add new category" screen.
	 *
	 * @return void
	 */
	public function render_add_category_fields(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<div class="form-field lpm-category-pricing">';
		echo '<label>' . esc_html__( 'Localized pricing rules', 'localized-price-manager' ) . '</label>';
		$this->render_rules_table( array() );
		echo '<p class="description">' . esc_html__( 'A category rule may set a target currency, a rate override or a purchase block. A fixed amount is never meaningful across a whole category.', 'localized-price-manager' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Render the fields on the "Edit category" screen.
	 *
	 * @param object $term Category term object.
	 * @return void
	 */
	public function render_edit_category_fields( object $term ): void {
		$term_id = (int) ( $term->term_id ?? 0 );
		$rules   = $term_id > 0 ? $this->repository->get_by_term( $term_id, false ) : array();
		$rules   = is_wp_error( $rules ) ? array() : $rules;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<tr class="form-field lpm-category-pricing">';
		echo '<th scope="row"><label>' . esc_html__( 'Localized pricing rules', 'localized-price-manager' ) . '</label></th>';
		echo '<td>';
		$this->render_rules_table( $rules );
		echo '<p class="description">' . esc_html__( 'A category rule may set a target currency, a rate override or a purchase block. A fixed amount is never meaningful across a whole category.', 'localized-price-manager' ) . '</p>';
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Render the rules table with one row per stored rule plus a blank template.
	 *
	 * @param array<int, object> $rules Stored rules.
	 * @return void
	 */
	private function render_rules_table( array $rules ): void {
		$countries  = $this->reference->get_countries();
		$currencies = $this->reference->get_currencies();

		echo '<table class="widefat striped lpm-category-pricing-table">';
		echo '<thead><tr>';
		$this->render_header_cell( __( 'Active', 'localized-price-manager' ), __( 'Enable or disable this country rule.', 'localized-price-manager' ), 'lpm-help-cat-active' );
		$this->render_header_cell( __( 'Country', 'localized-price-manager' ), __( 'Select the country this rule applies to.', 'localized-price-manager' ), 'lpm-help-cat-country' );
		$this->render_header_cell( __( 'Currency', 'localized-price-manager' ), __( 'Target currency for this country. Leave empty to use the country default currency.', 'localized-price-manager' ), 'lpm-help-cat-currency' );
		$this->render_header_cell( __( 'Rate', 'localized-price-manager' ), __( 'Custom exchange rate multiplier. Leave empty to use the store exchange rate.', 'localized-price-manager' ), 'lpm-help-cat-rate' );
		$this->render_header_cell( __( 'Disable sale', 'localized-price-manager' ), __( 'Hide products in this category and prevent customers from purchasing them in this country.', 'localized-price-manager' ), 'lpm-help-cat-disable-sale' );
		echo '<th>' . esc_html__( 'Remove', 'localized-price-manager' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody class="lpm-category-pricing-rows">';

		foreach ( $rules as $rule ) {
			$this->render_rule_row( (array) $rule, $countries, $currencies );
		}

		echo '</tbody></table>';

		echo '<p><button type="button" class="button button-secondary lpm-add-category-row-btn">' . esc_html__( '+ Add country rule', 'localized-price-manager' ) . '</button></p>';

		echo '<template class="lpm-category-row-template">';
		$this->render_rule_row( array(), $countries, $currencies );
		echo '</template>';
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
	 * @param array<string, mixed>  $rule       Stored rule, empty for the template row.
	 * @param array<string, string> $countries  Country choices.
	 * @param array<string, string> $currencies Currency choices.
	 * @return void
	 */
	private function render_rule_row( array $rule, array $countries, array $currencies ): void {
		$country  = strtoupper( (string) ( $rule['country_code'] ?? '' ) );
		$currency = strtoupper( (string) ( $rule['currency_code'] ?? '' ) );
		$rate     = $rule['exchange_rate'] ?? null;

		echo '<tr class="lpm-category-pricing-row">';

		echo '<td class="lpm-cell-active">';
		$this->render_flag_toggle(
			'lpm_cat_active[]',
			! array_key_exists( 'is_active', $rule ) || (bool) $rule['is_active'],
			__( 'Rule is active', 'localized-price-manager' )
		);
		echo '</td>';

		echo '<td class="lpm-cell-country">';
		$this->render_choice_select(
			'lpm_cat_country_codes[]',
			$countries,
			$country,
			__( '— Select country —', 'localized-price-manager' )
		);
		echo '</td>';

		echo '<td class="lpm-cell-currency">';
		$this->render_choice_select(
			'lpm_cat_currencies[]',
			$currencies,
			$currency,
			__( '— Country default —', 'localized-price-manager' )
		);
		echo '</td>';

		echo '<td class="lpm-cell-rate">';
		printf(
			'<input type="number" step="0.000000000001" min="0" name="lpm_cat_rates[]" value="%s" class="lpm-input" />',
			esc_attr( null === $rate ? '' : (string) $rate )
		);
		echo '</td>';

		echo '<td class="lpm-cell-blocked">';
		$this->render_flag_toggle(
			'lpm_cat_blocked[]',
			(bool) ( $rule['is_blocked'] ?? false ),
			__( 'Block this country', 'localized-price-manager' )
		);
		echo '</td>';

		echo '<td class="lpm-cell-remove"><button type="button" class="button lpm-remove-row-btn" aria-label="' . esc_attr__( 'Remove row', 'localized-price-manager' ) . '">&times;</button></td>';
		echo '</tr>';
	}

	/**
	 * Render a select over one of the WooCommerce reference lists.
	 *
	 * @param string                $name        Field name.
	 * @param array<string, string> $choices     Value to label map.
	 * @param string                $current     Selected value.
	 * @param string                $placeholder Empty option label.
	 * @return void
	 */
	private function render_choice_select( string $name, array $choices, string $current, string $placeholder ): void {
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
	 * @param string $name    Submitted field name.
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
	 * Persist the submitted category rules.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function save_category_meta( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$codes = $this->read_post_list( 'lpm_cat_country_codes' );

		$currencies = $this->read_post_list( 'lpm_cat_currencies' );
		$rates      = $this->read_post_list( 'lpm_cat_rates' );
		$blocked    = $this->read_post_list( 'lpm_cat_blocked' );
		$active     = $this->read_post_list( 'lpm_cat_active' );

		$submitted = array();
		$written   = array();
		$failed    = array();
		$rejected  = array();

		foreach ( $codes as $index => $raw_code ) {
			$country_code = CountryCodeValidator::sanitize( $raw_code );

			if ( '' === $country_code || in_array( $country_code, $submitted, true ) ) {
				continue;
			}

			$submitted[] = $country_code;

			$data = $this->build_rule_data(
				array(
					'currency' => (string) ( $currencies[ $index ] ?? '' ),
					'rate'     => (string) ( $rates[ $index ] ?? '' ),
					'blocked'  => (string) ( $blocked[ $index ] ?? '0' ),
					'active'   => (string) ( $active[ $index ] ?? '1' ),
				)
			);

			if ( null === $data ) {
				$rejected[] = $country_code;

				$this->logger->error(
					sprintf( 'Refused the %1$s rule of category %2$d: the submitted rate or currency is not one the schema accepts.', $country_code, $term_id ),
					array(
						'term_id'      => $term_id,
						'country_code' => $country_code,
					),
					self::LOG_SOURCE
				);

				continue;
			}

			$saved = $this->repository->save_rule( $term_id, $country_code, $data );

			if ( is_wp_error( $saved ) ) {
				$failed[] = $country_code;

				$this->logger->error(
					sprintf( 'Failed to save the %1$s rule of category %2$d: %3$s', $country_code, $term_id, $saved->get_error_message() ),
					array(
						'term_id'      => $term_id,
						'country_code' => $country_code,
					),
					self::LOG_SOURCE
				);

				continue;
			}

			$written[] = $country_code;
		}

		$this->prune_removed_rules( $term_id, $submitted );

		$this->cache->flush_group( CacheGroupEnum::Pricing->value );

		/**
		 * Fires after the pricing rules of a product category were saved and every cached price dropped.
		 *
		 * @param int $term_id Product category term ID.
		 */
		do_action( Core::build_hook( HookEnum::CategoryRulesSaved->value ), $term_id );

		$this->logger->audit(
			sprintf( 'Updated the pricing rules of category %d.', $term_id ),
			array(
				'term_id'   => $term_id,
				'countries' => $written,
			),
			self::LOG_SOURCE
		);

		$this->announce_failed_rules( $failed, $rejected );
	}

	/**
	 * Turn one submitted row into column values, or refuse it.
	 *
	 * @param array<string, string> $row Raw submitted values for one row.
	 * @return array<string, mixed>|null Column values, or null when the row is refused.
	 */
	private function build_rule_data( array $row ): ?array {
		$currency = strtoupper( trim( $row['currency'] ) );

		if ( '' !== $currency && ! $this->reference->is_known_currency( $currency ) ) {
			return null;
		}

		$raw_rate = trim( $row['rate'] );
		$rate     = '' === $raw_rate ? null : ExchangeRate::from( $raw_rate );

		if ( '' !== $raw_rate && null === $rate ) {
			return null;
		}

		return array(
			'currency_code' => $currency,
			'exchange_rate' => $rate?->value(),
			'is_blocked'    => '1' === $row['blocked'] ? 1 : 0,
			'is_active'     => '0' === $row['active'] ? 0 : 1,
		);
	}

	/**
	 * Tell the operator which category rules did not reach the table.
	 *
	 * @param array<int, string> $failed   Country codes whose write failed.
	 * @param array<int, string> $rejected Country codes whose submitted values were refused.
	 * @return void
	 */
	private function announce_failed_rules( array $failed, array $rejected = array() ): void {
		if ( ! empty( $failed ) ) {
			Core::add_admin_notice(
				sprintf(
					/* translators: %s: comma separated list of ISO 3166-1 alpha-2 country codes. */
					_n(
						'Localized Price Manager could not save the category price rule for %s. The rule already stored for it was kept unchanged.',
						'Localized Price Manager could not save the category price rules for %s. The rules already stored for them were kept unchanged.',
						count( $failed ),
						'localized-price-manager'
					),
					implode( ', ', $failed )
				),
				'error'
			);
		}

		if ( ! empty( $rejected ) ) {
			Core::add_admin_notice(
				sprintf(
					/* translators: %s: comma separated list of ISO 3166-1 alpha-2 country codes. */
					_n(
						'Localized Price Manager did not save the category price rule for %s: the rate was negative, zero, too large, or had more decimal places than the column allows, or the currency was not one WooCommerce knows. The rule already stored for it was kept unchanged.',
						'Localized Price Manager did not save the category price rules for %s: a rate was negative, zero, too large, or had more decimal places than the column allows, or the currency was not one WooCommerce knows. The rules already stored for them were kept unchanged.',
						count( $rejected ),
						'localized-price-manager'
					),
					implode( ', ', $rejected )
				),
				'error'
			);
		}
	}

	/**
	 * Delete the rules of countries that were removed from the form.
	 *
	 * @param int                $term_id   Term ID.
	 * @param array<int, string> $submitted Country codes the form still carries.
	 * @return void
	 */
	private function prune_removed_rules( int $term_id, array $submitted ): void {
		$existing = $this->repository->get_by_term( $term_id, false );

		if ( is_wp_error( $existing ) ) {
			$this->logger->error(
				sprintf( 'Failed to read the rules of category %1$d while pruning: %2$s', $term_id, $existing->get_error_message() ),
				array( 'term_id' => $term_id ),
				self::LOG_SOURCE
			);

			return;
		}

		foreach ( $existing as $rule ) {
			$country = CountryCodeValidator::normalize( $rule->country_code ?? '' );

			if ( '' === $country || in_array( $country, $submitted, true ) ) {
				continue;
			}

			$this->repository->delete_record( (int) ( $rule->id ?? 0 ) );
		}
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
}
