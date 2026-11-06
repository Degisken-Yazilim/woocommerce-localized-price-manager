<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Pages;

use D6N\LPM\Modules\AdminPortal\Rest\RestController;
use D6N\LPM\Modules\AdminPortal\Support\CountryReference;
use D6N\LPM\Modules\PricingEngine\CountryGroup\CountryGroupRepository;
use stdClass;

defined( 'ABSPATH' ) || exit;

/**
 * The "Countries & Groups" tab.
 */
class CountriesTab {

	/**
	 * Cells in one country row: the select box, the three sortable columns, the
	 * number format, the group, and the actions the active switch now sits in.
	 *
	 * @var int
	 */
	private const COUNTRY_COLUMNS = 7;

	/**
	 * Rows the table shows per page before the reader changes it.
	 *
	 * @var int
	 */
	public const PER_PAGE = 25;

	/**
	 * Page sizes the reader may choose from.
	 *
	 * @var array<int, int>
	 */
	private const PER_PAGE_CHOICES = array( 25, 50, 100, 250 );

	/**
	 * Grid classes of a form row's label column.
	 *
	 * @var string
	 */
	private const LABEL_COLUMN = 'lpm-col-12 lpm-col-sm-6 lpm-col-md-5';

	/**
	 * Grid classes of a form row's control column.
	 *
	 * @var string
	 */
	private const CONTROL_COLUMN = 'lpm-col-12 lpm-col-sm-6 lpm-col-md-7';

	/**
	 * Constructor.
	 *
	 * @param CountryGroupRepository $groups    Country group repository.
	 * @param CountryReference       $reference WooCommerce country and currency lists.
	 */
	public function __construct(
		private readonly CountryGroupRepository $groups,
		private readonly CountryReference $reference
	) {}

	/**
	 * Render the whole tab.
	 *
	 * @return void
	 */
	public function render(): void {
		$groups = $this->groups->get_active_groups();

		echo '<div class="lpm-countries" data-lpm-route-base="' . esc_attr( RestController::REST_NAMESPACE ) . '">';

		if ( is_wp_error( $groups ) ) {
			$this->render_error( $groups->get_error_message() );
			$groups = array();
		} else {
			$this->render_groups_section( $groups );
		}

		$this->render_countries_section( $groups );

		echo '</div>';
	}

	/**
	 * Render the group table and the add / edit form above the country table.
	 *
	 * @param array<int, stdClass> $groups Active groups.
	 * @return void
	 */
	private function render_groups_section( array $groups ): void {
		echo '<h2>' . esc_html__( 'Country groups', 'localized-price-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A country belongs to at most one group. When the group sets its own currency or rate, it prices every country in it instead of the country\'s own automatic conversion; product and category rules still come first. A group that sets neither leaves its countries to their own currency.', 'localized-price-manager' ) . '</p>';

		echo '<table class="widefat striped lpm-table" id="lpm-country-group-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'localized-price-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'localized-price-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Currency', 'localized-price-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Rate override', 'localized-price-manager' ) . ' ';
		$this->render_help(
			'lpm-group-rate-help',
			__( 'Help: rate override', 'localized-price-manager' ),
			__( 'When a country has no product or category rule, a group with its own rate or currency prices it ahead of the country\'s automatic conversion: a rate set here multiplies the store base price directly instead of using the fetched exchange rate. Leave it empty to convert into the group currency at the current rate.', 'localized-price-manager' )
		);
		echo '</th>';
		echo '<th>' . esc_html__( 'Actions', 'localized-price-manager' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( array() === $groups ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No country groups are defined yet.', 'localized-price-manager' ) . '</td></tr>';
		}

		foreach ( $groups as $group ) {
			$this->render_group_row( $group );
		}

		echo '</tbody></table>';

		$this->render_group_form();
	}

	/**
	 * Render one group row.
	 *
	 * @param stdClass $group Group row.
	 * @return void
	 */
	private function render_group_row( stdClass $group ): void {
		$name     = (string) ( $group->name ?? '' );
		$currency = strtoupper( (string) ( $group->currency_code ?? '' ) );
		$rate     = isset( $group->exchange_rate ) ? (string) $group->exchange_rate : '';

		printf(
			'<tr data-lpm-group-id="%1$s" data-lpm-group-name="%2$s" data-lpm-group-currency="%3$s" data-lpm-group-rate="%4$s">',
			esc_attr( (string) (int) ( $group->id ?? 0 ) ),
			esc_attr( $name ),
			esc_attr( $currency ),
			esc_attr( $rate )
		);
		echo '<td>' . esc_html( $name ) . '</td>';
		echo '<td><code>' . esc_html( (string) ( $group->slug ?? '' ) ) . '</code></td>';
		echo '<td>' . esc_html( $currency ) . '</td>';
		echo '<td>' . esc_html( '' !== $rate ? $rate : '—' ) . '</td>';
		echo '<td class="lpm-cc-actions">';
		$this->render_icon_button( 'edit-group', 'lpm-cc-edit', 'dashicons-edit', __( 'Edit group', 'localized-price-manager' ) );
		$this->render_icon_button( 'delete-group', 'lpm-cc-delete', 'dashicons-trash', __( 'Delete group', 'localized-price-manager' ) );
		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Render the group create / edit form.
	 *
	 * @return void
	 */
	private function render_group_form(): void {
		echo '<form class="lpm-group-form" id="lpm-country-group-form">';
		echo '<h3 id="lpm-group-form-title">' . esc_html__( 'Add a group', 'localized-price-manager' ) . '</h3>';
		echo '<input type="hidden" name="id" id="lpm-group-id" value="" />';

		$this->open_form_row( 'lpm-group-name', __( 'Name', 'localized-price-manager' ), '' );
		echo '<input type="text" class="lpm-input" id="lpm-group-name" name="name" required />';
		$this->close_form_row();

		$this->open_form_row( 'lpm-group-currency', __( 'Currency', 'localized-price-manager' ), __( 'The currency every country in this group is priced in, instead of the country\'s own currency. Leave it empty to keep each country\'s own currency.', 'localized-price-manager' ) );
		$this->render_currency_select( 'lpm-group-currency', 'currency_code', '', __( 'Currency', 'localized-price-manager' ) );
		$this->close_form_row();

		$this->open_form_row(
			'lpm-group-rate',
			__( 'Rate override', 'localized-price-manager' ),
			__( 'A rate set here multiplies the store base price directly instead of using the fetched exchange rate, for every country in the group. Leave it empty to convert into the group currency at the current rate.', 'localized-price-manager' )
		);
		echo '<input type="number" step="0.000000000001" min="0" class="lpm-input" id="lpm-group-rate" name="exchange_rate" />';
		$this->close_form_row();

		echo '<p class="lpm-form-actions submit">';
		echo '<button type="submit" class="button button-primary lpm-btn lpm-btn-primary" id="lpm-group-submit">' . esc_html__( 'Save group', 'localized-price-manager' ) . '</button> ';
		echo '<button type="button" class="button lpm-btn" id="lpm-group-cancel" hidden>' . esc_html__( 'Cancel', 'localized-price-manager' ) . '</button>';
		echo '</p>';
		echo '</form>';
	}

	/**
	 * Render the country table, its toolbars and the templates the script clones.
	 *
	 * @param array<int, stdClass> $groups Active groups.
	 * @return void
	 */
	private function render_countries_section( array $groups ): void {
		echo '<h2>' . esc_html__( 'Country currencies', 'localized-price-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The currency, symbol and number format each country is priced in. Switch "Active" off to stop pricing a country — the row stays here with everything you configured, so switching it back on restores it. "Reset" is the destructive one: it replaces your settings with the seeded native currency.', 'localized-price-manager' ) . '</p>';

		printf(
			'<div class="lpm-cc-datatable-container" id="lpm-country-currency" data-lpm-route="%1$s" data-lpm-per-page="%2$s">',
			esc_attr( 'country-currency' ),
			esc_attr( (string) self::PER_PAGE )
		);

		$this->render_top_toolbar( $groups );

		echo '<div class="lpm-dt-table-wrapper">';
		echo '<div class="lpm-dt-overlay" aria-hidden="true">';
		echo '<span class="lpm-loading-container"><span class="lpm-loading-spinner"></span></span>';
		echo '</div>';
		echo '<table class="widefat striped lpm-table" id="lpm-country-currency-table" aria-busy="true">';
		$this->render_country_head();
		echo '<tbody class="lpm-dt-body">';
		$this->render_loading_row();
		echo '</tbody></table>';
		echo '</div>';

		$this->render_bottom_toolbar();
		$this->render_templates( $groups );

		echo '</div>';
	}

	/**
	 * Render the bulk action, filter and search toolbar.
	 *
	 * @param array<int, stdClass> $groups Active groups.
	 * @return void
	 */
	private function render_top_toolbar( array $groups ): void {
		echo '<div class="lpm-dt-toolbar">';
		echo '<div class="lpm-dt-toolbar-left">';
		echo '<label class="screen-reader-text" for="lpm-cc-bulk-action">' . esc_html__( 'Bulk action', 'localized-price-manager' ) . '</label>';
		echo '<select class="lpm-select lpm-cc-bulk-action" id="lpm-cc-bulk-action">';
		echo '<option value="">' . esc_html__( 'Bulk actions', 'localized-price-manager' ) . '</option>';
		echo '<option value="enable">' . esc_html__( 'Enable selected', 'localized-price-manager' ) . '</option>';
		echo '<option value="disable">' . esc_html__( 'Disable selected', 'localized-price-manager' ) . '</option>';
		echo '<option value="save">' . esc_html__( 'Save selected', 'localized-price-manager' ) . '</option>';
		echo '<option value="reset">' . esc_html__( 'Reset selected to native', 'localized-price-manager' ) . '</option>';
		echo '<option value="assign_group">' . esc_html__( 'Assign selected to group', 'localized-price-manager' ) . '</option>';
		echo '</select> ';

		echo '<label class="screen-reader-text" for="lpm-cc-bulk-group">' . esc_html__( 'Group to assign', 'localized-price-manager' ) . '</label>';
		echo '<select class="lpm-select lpm-cc-bulk-group" id="lpm-cc-bulk-group" hidden="hidden">';
		echo '<option value="0">' . esc_html__( '— No group —', 'localized-price-manager' ) . '</option>';
		$this->render_group_choices( $groups );
		echo '</select> ';

		echo '<button type="button" class="button lpm-btn lpm-btn-sm lpm-cc-bulk-apply" id="lpm-cc-bulk-apply">' . esc_html__( 'Apply', 'localized-price-manager' ) . '</button>';
		echo '</div>';

		$this->render_filters( $groups );

		echo '<div class="lpm-dt-toolbar-right">';
		echo '<label class="screen-reader-text" for="lpm-cc-search">' . esc_html__( 'Search countries', 'localized-price-manager' ) . '</label>';
		echo '<input type="search" class="lpm-input lpm-dt-search" id="lpm-cc-search" placeholder="' . esc_attr__( 'Search country or currency…', 'localized-price-manager' ) . '" />';
		echo '<label class="lpm-dt-per-page" for="lpm-cc-per-page">' . esc_html__( 'Per page', 'localized-price-manager' ) . ' ';
		echo '<select class="lpm-select lpm-dt-per-page-select" id="lpm-cc-per-page">';

		foreach ( self::PER_PAGE_CHOICES as $size ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( (string) $size ),
				self::PER_PAGE === $size ? ' selected="selected"' : ''
			);
		}

		echo '</select></label>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the three filters the country table narrows itself by.
	 *
	 * @param array<int, stdClass> $groups Active groups.
	 * @return void
	 */
	private function render_filters( array $groups ): void {
		echo '<div class="lpm-dt-toolbar-filters">';

		echo '<label class="lpm-dt-filter" for="lpm-cc-filter-status">' . esc_html__( 'Status', 'localized-price-manager' ) . ' ';
		echo '<select class="lpm-select lpm-cc-filter" id="lpm-cc-filter-status" data-lpm-filter="status">';
		echo '<option value="">' . esc_html__( 'All', 'localized-price-manager' ) . '</option>';
		echo '<option value="enabled">' . esc_html__( 'Enabled', 'localized-price-manager' ) . '</option>';
		echo '<option value="disabled">' . esc_html__( 'Disabled', 'localized-price-manager' ) . '</option>';
		echo '</select></label>';

		echo '<label class="lpm-dt-filter" for="lpm-cc-filter-currency">' . esc_html__( 'Currency', 'localized-price-manager' ) . ' ';
		echo '<select class="lpm-select lpm-cc-filter" id="lpm-cc-filter-currency" data-lpm-filter="currency">';
		echo '<option value="">' . esc_html__( 'All', 'localized-price-manager' ) . '</option>';
		echo '</select></label>';

		echo '<label class="lpm-dt-filter" for="lpm-cc-filter-group">' . esc_html__( 'Group', 'localized-price-manager' ) . ' ';
		echo '<select class="lpm-select lpm-cc-filter" id="lpm-cc-filter-group" data-lpm-filter="group">';
		echo '<option value="">' . esc_html__( 'All', 'localized-price-manager' ) . '</option>';
		echo '<option value="0">' . esc_html__( '— No group —', 'localized-price-manager' ) . '</option>';
		$this->render_group_choices( $groups );
		echo '</select></label>';

		echo '</div>';
	}

	/**
	 * Write one `<option>` per active group, with no leading choice of its own.
	 *
	 * @param array<int, stdClass> $groups Active groups.
	 * @return void
	 */
	private function render_group_choices( array $groups ): void {
		foreach ( $groups as $group ) {
			$id = (int) ( $group->id ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( (string) $id ),
				esc_html( (string) ( $group->name ?? '' ) )
			);
		}
	}

	/**
	 * Render the paging toolbar below the table.
	 *
	 * @return void
	 */
	private function render_bottom_toolbar(): void {
		echo '<div class="lpm-dt-toolbar">';
		echo '<div class="lpm-dt-toolbar-left">';
		echo '<span class="lpm-dt-info" id="lpm-cc-info" role="status" aria-live="polite">';
		echo esc_html__( 'Loading countries…', 'localized-price-manager' );
		echo '</span>';
		echo '</div>';
		echo '<div class="lpm-dt-toolbar-right">';
		echo '<nav class="lpm-pagination lpm-dt-pagination" id="lpm-cc-pagination" aria-label="' . esc_attr__( 'Country table pages', 'localized-price-manager' ) . '"></nav>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the country table head, marking the sortable columns.
	 *
	 * @return void
	 */
	private function render_country_head(): void {
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" class="lpm-cc-select-all" id="lpm-cc-select-all" aria-label="' . esc_attr__( 'Select every country on this page', 'localized-price-manager' ) . '" /></td>';

		$columns = array(
			'country_code'         => __( 'Country', 'localized-price-manager' ),
			'currency_code'        => __( 'Currency', 'localized-price-manager' ),
			'native_currency_code' => __( 'Native', 'localized-price-manager' ),
		);

		foreach ( $columns as $column => $label ) {
			printf(
				'<th scope="col" class="lpm-dt-sortable%1$s" data-lpm-sort="%2$s" tabindex="0" role="button" aria-label="%3$s">%4$s</th>',
				'country_code' === $column ? ' lpm-dt-sort-asc' : '',
				esc_attr( $column ),
				/* translators: %s: column label. */
				esc_attr( sprintf( __( 'Sort by %s', 'localized-price-manager' ), $label ) ),
				esc_html( $label )
			);
		}

		echo '<th scope="col">' . esc_html__( 'Number format', 'localized-price-manager' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Group', 'localized-price-manager' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'localized-price-manager' ) . '</th>';
		echo '</tr></thead>';
	}

	/**
	 * Render the templates the script clones when it re-renders the body.
	 *
	 * @param array<int, stdClass> $groups Active groups.
	 * @return void
	 */
	private function render_templates( array $groups ): void {
		echo '<template id="lpm-cc-currency-options">';
		$this->render_currency_options( '' );
		echo '</template>';

		echo '<template id="lpm-cc-group-options">';
		$this->render_group_options( 0, $groups );
		echo '</template>';

		echo '<template id="lpm-cc-row-template">';
		echo '<tr data-lpm-country="">';
		printf(
			'<td class="check-column"><input type="checkbox" class="lpm-cc-select" value="" aria-label="%1$s" /></td>',
			esc_attr__( 'Select this country', 'localized-price-manager' )
		);
		echo '<td class="lpm-cc-country"><span class="lpm-cc-country-name"></span> <code class="lpm-cc-country-code"></code></td>';
		printf(
			'<td><select class="lpm-select lpm-select-sm lpm-cc-edit-currency" name="currency_code" aria-label="%1$s"></select></td>',
			esc_attr__( 'Currency code', 'localized-price-manager' )
		);
		echo '<td class="lpm-cc-native"></td>';
		echo '<td class="lpm-cc-format"><code></code></td>';
		printf(
			'<td><select class="lpm-select lpm-select-sm lpm-cc-edit-group" name="group_id" aria-label="%1$s"></select></td>',
			esc_attr__( 'Country group', 'localized-price-manager' )
		);
		echo '<td class="lpm-cc-actions">';
		printf(
			'<label class="lpm-switch lpm-cc-active"><input type="checkbox" class="lpm-cc-toggle-active" data-lpm-action="toggle-active" aria-label="%1$s" /><span class="lpm-switch-slider"></span></label>',
			esc_attr__( 'Price this country', 'localized-price-manager' )
		);
		$this->render_icon_button( 'save', 'lpm-cc-save', 'dashicons-yes', __( 'Save this country', 'localized-price-manager' ) );
		$this->render_icon_button( 'reset', 'lpm-cc-reset', 'dashicons-image-rotate', __( 'Reset this country to its native currency', 'localized-price-manager' ) );
		echo '</td>';
		echo '</tr>';
		echo '</template>';
	}

	/**
	 * Render a currency `<select>` holding every WooCommerce currency.
	 *
	 * @param string $id       Control DOM id.
	 * @param string $name     Control name.
	 * @param string $selected Selected currency code.
	 * @param string $label    Accessible label.
	 * @return void
	 */
	private function render_currency_select( string $id, string $name, string $selected, string $label ): void {
		printf(
			'<select class="lpm-select lpm-select-sm" id="%1$s" name="%2$s" aria-label="%3$s">',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $label )
		);

		$this->render_currency_options( $selected );

		echo '</select>';
	}

	/**
	 * Render the options of a currency select, "none" first.
	 *
	 * @param string $selected Selected currency code.
	 * @return void
	 */
	private function render_currency_options( string $selected ): void {
		printf(
			'<option value=""%1$s>%2$s</option>',
			'' === $selected ? ' selected="selected"' : '',
			esc_html__( '— None —', 'localized-price-manager' )
		);

		foreach ( $this->reference->get_currencies() as $code => $currency_label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $code ),
				$code === $selected ? ' selected="selected"' : '',
				esc_html(
					CountryReference::format_currency_label(
						(string) $code,
						$currency_label,
						$this->reference->get_currency_symbol( (string) $code )
					)
				)
			);
		}
	}

	/**
	 * Render the options of a group select, "none" first.
	 *
	 * @param int                  $group_id Current group id, or 0.
	 * @param array<int, stdClass> $groups   Active groups.
	 * @return void
	 */
	private function render_group_options( int $group_id, array $groups ): void {
		printf(
			'<option value="0"%1$s>%2$s</option>',
			0 === $group_id ? ' selected="selected"' : '',
			esc_html__( '— None —', 'localized-price-manager' )
		);

		foreach ( $groups as $group ) {
			$id = (int) ( $group->id ?? 0 );

			if ( $id <= 0 ) {
				continue;
			}

			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $id ),
				$id === $group_id ? ' selected="selected"' : '',
				esc_html( (string) ( $group->name ?? '' ) )
			);
		}
	}

	/**
	 * Render one row action as an icon button.
	 *
	 * @param string $action  Action name carried on the button.
	 * @param string $variant Colour variant class from `_icon-buttons.scss`.
	 * @param string $icon    Dashicon class.
	 * @param string $label   Accessible label.
	 * @return void
	 */
	private function render_icon_button( string $action, string $variant, string $icon, string $label ): void {
		printf(
			'<button type="button" class="lpm-icon-btn %1$s" data-lpm-action="%2$s" title="%3$s" aria-label="%3$s"><span class="dashicons %4$s" aria-hidden="true"></span></button>',
			esc_attr( $variant ),
			esc_attr( $action ),
			esc_attr( $label ),
			esc_attr( $icon )
		);
	}

	/**
	 * Open a two column form row.
	 *
	 * @param string $id          Control DOM id.
	 * @param string $label       Field label.
	 * @param string $description Popover text, empty for none.
	 * @return void
	 */
	private function open_form_row( string $id, string $label, string $description ): void {
		echo '<div class="lpm-form-group lpm-row">';
		printf( '<div class="lpm-form-group-label %s">', esc_attr( self::LABEL_COLUMN ) );
		echo '<label class="lpm-form-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';

		if ( '' !== $description ) {
			/* translators: %s: label of the field this help text belongs to. */
			$this->render_help( $id . '-help', sprintf( __( 'Help: %s', 'localized-price-manager' ), $label ), $description );
		}

		echo '</div>';
		printf( '<div class="lpm-form-group-control %s">', esc_attr( self::CONTROL_COLUMN ) );
	}

	/**
	 * Close a two column form row.
	 *
	 * @return void
	 */
	private function close_form_row(): void {
		echo '</div></div>';
	}

	/**
	 * Render the `?` popover used across the settings screen.
	 *
	 * @param string $help_id Popover DOM id.
	 * @param string $label   Accessible label of the trigger.
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
	 * Render the row shown while the country list is being fetched.
	 *
	 * @return void
	 */
	private function render_loading_row(): void {
		echo '<tr class="lpm-dt-state-row" id="lpm-cc-loading-row">';
		printf( '<td colspan="%d">', (int) self::COUNTRY_COLUMNS );
		echo '<span class="lpm-loading-container">';
		echo '<span class="lpm-loading-spinner"></span>';
		echo '<span class="lpm-loading-text">' . esc_html__( 'Loading countries…', 'localized-price-manager' ) . '</span>';
		echo '</span>';
		echo '</td></tr>';
	}

	/**
	 * Render a repository failure as an admin notice.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function render_error( string $message ): void {
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}
}
