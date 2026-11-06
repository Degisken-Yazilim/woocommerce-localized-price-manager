<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a schema definition as a pre-filled HTML control.
 */
class FieldRenderer {

	/**
	 * Grid classes of the label column.
	 *
	 * @var string
	 */
	private const LABEL_COLUMN = 'lpm-col-12 lpm-col-sm-6 lpm-col-md-5';

	/**
	 * Grid classes of the control column.
	 *
	 * @var string
	 */
	private const CONTROL_COLUMN = 'lpm-col-12 lpm-col-sm-6 lpm-col-md-7';

	/**
	 * Render one field as a two column grid row.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param mixed             $value      Current value in its storage representation.
	 * @return void
	 */
	public function render( SettingDefinition $definition, mixed $value ): void {
		$id = 'lpm-field-' . sanitize_html_class( $definition->key );

		echo '<div class="lpm-form-group lpm-row" data-lpm-field="' . esc_attr( $definition->key ) . '">';

		printf( '<div class="lpm-form-group-label %s">', esc_attr( self::LABEL_COLUMN ) );
		echo '<label class="lpm-form-label" for="' . esc_attr( $id ) . '">' . esc_html( $definition->label ) . '</label>';
		$this->render_help( $definition, $id );
		echo '</div>';

		printf( '<div class="lpm-form-group-control %s">', esc_attr( self::CONTROL_COLUMN ) );

		match ( $definition->control ) {
			SettingDefinition::CONTROL_TOGGLE         => $this->render_toggle( $definition, $id, $value ),
			SettingDefinition::CONTROL_RADIO          => $this->render_radio( $definition, $id, $value ),
			SettingDefinition::CONTROL_NUMBER         => $this->render_number( $definition, $id, $value ),
			SettingDefinition::CONTROL_API_KEY        => $this->render_api_key( $definition, $id, $value ),
			SettingDefinition::CONTROL_CHECKBOX_GROUP => $this->render_checkbox_group( $definition, $id, $value ),
			SettingDefinition::CONTROL_ORDERED_LIST   => $this->render_ordered_list( $definition, $id, $value ),
			SettingDefinition::CONTROL_TEXTAREA       => $this->render_textarea( $definition, $id, $value ),
			SettingDefinition::CONTROL_SELECT         => $this->render_select( $definition, $id, $value ),
			default                                   => $this->render_text( $definition, $id, $value ),
		};

		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render the `?` that reveals the field description.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param string            $id         Control DOM id.
	 * @return void
	 */
	private function render_help( SettingDefinition $definition, string $id ): void {
		if ( '' === $definition->description ) {
			return;
		}

		$help_id = $id . '-help';

		/* translators: %s: label of the field this help text belongs to. */
		$trigger_label = sprintf( __( 'Help: %s', 'localized-price-manager' ), $definition->label );

		echo '<span class="lpm-help" data-lpm-help="1">';
		printf(
			'<button type="button" class="lpm-help-trigger" aria-describedby="%1$s" aria-label="%2$s">?</button>',
			esc_attr( $help_id ),
			esc_attr( $trigger_label )
		);
		printf(
			'<span class="lpm-help-popover" id="%1$s" role="tooltip">%2$s</span>',
			esc_attr( $help_id ),
			esc_html( $definition->description )
		);
		echo '</span>';
	}

	/**
	 * Render an on/off switch.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param string            $id         Control DOM id.
	 * @param mixed             $value      Current value.
	 * @return void
	 */
	private function render_toggle( SettingDefinition $definition, string $id, mixed $value ): void {
		$checked = SettingDefinition::to_bool( $value );

		echo '<label class="lpm-switch" for="' . esc_attr( $id ) . '">';
		printf(
			'<input type="checkbox" class="lpm-exportable" id="%1$s" name="%2$s" value="yes"%3$s />',
			esc_attr( $id ),
			esc_attr( $definition->key ),
			$checked ? ' checked="checked"' : ''
		);
		echo '<span class="lpm-switch-slider"></span>';
		echo '</label>';
	}

	/**
	 * Render a select control.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param string            $id         Control DOM id.
	 * @param mixed             $value      Current value.
	 * @return void
	 */
	private function render_select( SettingDefinition $definition, string $id, mixed $value ): void {
		$current = (string) ( is_scalar( $value ) ? $value : '' );

		printf(
			'<select class="lpm-select lpm-exportable" id="%1$s" name="%2$s">',
			esc_attr( $id ),
			esc_attr( $definition->key )
		);

		foreach ( $definition->get_choices() as $choice => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $choice ),
				(string) $choice === $current ? ' selected="selected"' : '',
				esc_html( (string) $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Render a radio group.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param string            $id         Control DOM id.
	 * @param mixed             $value      Current value.
	 * @return void
	 */
	private function render_radio( SettingDefinition $definition, string $id, mixed $value ): void {
		$current = (string) ( is_scalar( $value ) ? $value : '' );

		echo '<div class="lpm-form-inline" id="' . esc_attr( $id ) . '">';

		foreach ( $definition->get_choices() as $choice => $label ) {
			$choice_id = $id . '-' . sanitize_html_class( (string) $choice );

			echo '<label class="lpm-radio" for="' . esc_attr( $choice_id ) . '">';
			printf(
				'<input type="radio" class="lpm-exportable" id="%1$s" name="%2$s" value="%3$s"%4$s /> %5$s',
				esc_attr( $choice_id ),
				esc_attr( $definition->key ),
				esc_attr( (string) $choice ),
				(string) $choice === $current ? ' checked="checked"' : '',
				esc_html( (string) $label )
			);
			echo '</label>';
		}

		echo '</div>';
	}

	/**
	 * Render a number input.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param string            $id         Control DOM id.
	 * @param mixed             $value      Current value.
	 * @return void
	 */
	private function render_number( SettingDefinition $definition, string $id, mixed $value ): void {
		printf(
			'<input type="number" class="lpm-input lpm-exportable" id="%1$s" name="%2$s" value="%3$s"%4$s%5$s />',
			esc_attr( $id ),
			esc_attr( $definition->key ),
			esc_attr( (string) ( is_scalar( $value ) ? $value : '' ) ),
			null !== $definition->min ? ' min="' . esc_attr( (string) $definition->min ) . '"' : '',
			null !== $definition->max ? ' max="' . esc_attr( (string) $definition->max ) . '"' : ''
		);
	}

	/**
	 * Render a free text input.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param string            $id         Control DOM id.
	 * @param mixed             $value      Current value.
	 * @return void
	 */
	private function render_text( SettingDefinition $definition, string $id, mixed $value ): void {
		printf(
			'<input type="text" class="lpm-input lpm-exportable" id="%1$s" name="%2$s" value="%3$s" />',
			esc_attr( $id ),
			esc_attr( $definition->key ),
			esc_attr( (string) ( is_scalar( $value ) ? $value : '' ) )
		);
	}

	/**
	 * Render a multi-line text control.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param string            $id         Control DOM id.
	 * @param mixed             $value      Current value.
	 * @return void
	 */
	private function render_textarea( SettingDefinition $definition, string $id, mixed $value ): void {
		printf(
			'<textarea class="lpm-textarea lpm-exportable" id="%1$s" name="%2$s" rows="6" spellcheck="false">%3$s</textarea>',
			esc_attr( $id ),
			esc_attr( $definition->key ),
			esc_textarea( (string) ( is_scalar( $value ) ? $value : '' ) )
		);
	}

	/**
	 * Render an API key input pre-filled with the masked stored key.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param string            $id         Control DOM id.
	 * @param mixed             $value      Masked key.
	 * @return void
	 */
	private function render_api_key( SettingDefinition $definition, string $id, mixed $value ): void {
		printf(
			'<input type="text" class="lpm-input lpm-exportable lpm-api-key" id="%1$s" name="%2$s" value="%3$s" autocomplete="off" spellcheck="false" data-lpm-masked="%3$s" />',
			esc_attr( $id ),
			esc_attr( $definition->key ),
			esc_attr( (string) ( is_scalar( $value ) ? $value : '' ) )
		);
	}

	/**
	 * Render a multi-select checkbox group.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param string            $id         Control DOM id.
	 * @param mixed             $value      Current list.
	 * @return void
	 */
	private function render_checkbox_group( SettingDefinition $definition, string $id, mixed $value ): void {
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array();

		echo '<div class="lpm-checkbox-group" id="' . esc_attr( $id ) . '">';

		foreach ( $definition->get_choices() as $choice => $label ) {
			$choice_id = $id . '-' . sanitize_html_class( (string) $choice );

			echo '<label class="lpm-checkbox" for="' . esc_attr( $choice_id ) . '">';
			printf(
				'<input type="checkbox" class="lpm-exportable" id="%1$s" name="%2$s[]" value="%3$s"%4$s /> %5$s',
				esc_attr( $choice_id ),
				esc_attr( $definition->key ),
				esc_attr( (string) $choice ),
				in_array( (string) $choice, $selected, true ) ? ' checked="checked"' : '',
				esc_html( (string) $label )
			);
			echo '</label>';
		}

		echo '</div>';
	}

	/**
	 * Render an ordered, checkable provider list.
	 *
	 * @param SettingDefinition $definition Field definition.
	 * @param string            $id         Control DOM id.
	 * @param mixed             $value      Current ordered list.
	 * @return void
	 */
	private function render_ordered_list( SettingDefinition $definition, string $id, mixed $value ): void {
		$choices  = $definition->get_choices();
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array();
		$ordered  = array();

		foreach ( $selected as $choice ) {
			if ( array_key_exists( $choice, $choices ) ) {
				$ordered[ $choice ] = true;
			}
		}

		foreach ( array_keys( $choices ) as $choice ) {
			if ( ! array_key_exists( (string) $choice, $ordered ) ) {
				$ordered[ (string) $choice ] = false;
			}
		}

		$last     = count( $ordered ) - 1;
		$position = 0;

		echo '<ul class="lpm-ordered-list" id="' . esc_attr( $id ) . '" data-lpm-ordered-list="1">';

		foreach ( $ordered as $choice => $is_checked ) {
			$choice_id = $id . '-' . sanitize_html_class( (string) $choice );

			echo '<li class="lpm-ordered-list-item">';
			echo '<label class="lpm-checkbox" for="' . esc_attr( $choice_id ) . '">';
			printf(
				'<input type="checkbox" class="lpm-exportable" id="%1$s" name="%2$s[]" value="%3$s"%4$s /> %5$s',
				esc_attr( $choice_id ),
				esc_attr( $definition->key ),
				esc_attr( (string) $choice ),
				$is_checked ? ' checked="checked"' : '',
				esc_html( (string) ( $choices[ $choice ] ?? $choice ) )
			);
			echo '</label>';
			echo '<span class="lpm-ordered-list-controls">';
			$this->render_move_button( 'up', __( 'Move up', 'localized-price-manager' ), 0 === $position );
			$this->render_move_button( 'down', __( 'Move down', 'localized-price-manager' ), $position === $last );
			echo '</span>';
			echo '</li>';

			++$position;
		}

		echo '</ul>';

		printf(
			'<span class="screen-reader-text" role="status" aria-live="polite" data-lpm-ordered-status="%1$s"></span>',
			/* translators: 1: item label, 2: new position, 3: number of items. */
			esc_attr__( '%1$s moved to position %2$d of %3$d.', 'localized-price-manager' )
		);
	}

	/**
	 * Render one move button of the ordered list.
	 *
	 * @param string $direction Either `up` or `down`.
	 * @param string $label     Accessible label.
	 * @param bool   $disabled  Whether the button has nowhere to move to.
	 * @return void
	 */
	private function render_move_button( string $direction, string $label, bool $disabled ): void {
		printf(
			'<button type="button" class="lpm-icon-btn" data-lpm-move="%1$s" aria-label="%2$s"%3$s>%4$s</button>',
			esc_attr( $direction ),
			esc_attr( $label ),
			$disabled ? ' disabled="disabled"' : '',
			'up' === $direction ? '&uarr;' : '&darr;'
		);
	}
}
