<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Settings;

use Closure;

defined( 'ABSPATH' ) || exit;

/**
 * One plugin setting, declared exactly once.
 */
class SettingDefinition {

	public const TYPE_BOOLEAN = 'boolean';
	public const TYPE_STRING  = 'string';
	public const TYPE_INTEGER = 'integer';
	public const TYPE_ARRAY   = 'array';

	public const CONTROL_TOGGLE         = 'toggle';
	public const CONTROL_SELECT         = 'select';
	public const CONTROL_RADIO          = 'radio';
	public const CONTROL_NUMBER         = 'number';
	public const CONTROL_TEXTAREA       = 'textarea';
	public const CONTROL_API_KEY        = 'api_key';
	public const CONTROL_CHECKBOX_GROUP = 'checkbox_group';
	public const CONTROL_ORDERED_LIST   = 'ordered_list';

	/**
	 * Constructor.
	 *
	 * @param string        $key         Option key inside the plugin option row.
	 * @param string        $tab         Settings tab slug the field belongs to.
	 * @param string        $type        One of the TYPE_* constants.
	 * @param string        $control     One of the CONTROL_* constants.
	 * @param mixed         $default_value Default in *storage* representation.
	 * @param string        $label       Translated field label.
	 * @param string        $description Translated field description.
	 * @param Closure|null  $choices     Factory returning `array<string, string>` of value => label.
	 * @param Closure|null  $sanitizer   Custom sanitizer, `fn( mixed $raw ): mixed` returning storage form.
	 * @param Closure|null  $validator   Custom validator, `fn( mixed $value ): bool`.
	 * @param int|null      $min         Minimum accepted integer.
	 * @param int|null      $max         Maximum accepted integer.
	 * @param bool          $secret      True for API keys, which never travel through the option row directly.
	 * @param array<string> $mirrors    Extra option keys written with the same value.
	 * @param string        $provider    Provider key an API key field belongs to.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $tab,
		public readonly string $type,
		public readonly string $control,
		public readonly mixed $default_value,
		public readonly string $label,
		public readonly string $description = '',
		private readonly ?Closure $choices = null,
		private readonly ?Closure $sanitizer = null,
		private readonly ?Closure $validator = null,
		public readonly ?int $min = null,
		public readonly ?int $max = null,
		public readonly bool $secret = false,
		public readonly array $mirrors = array(),
		public readonly string $provider = ''
	) {}

	/**
	 * Get the value => label map backing a select, radio or checkbox control.
	 *
	 * @return array<string, string> Choice map; empty for free-form controls.
	 */
	public function get_choices(): array {
		if ( null === $this->choices ) {
			return array();
		}

		$resolved = ( $this->choices )();

		return is_array( $resolved ) ? $resolved : array();
	}

	/**
	 * Convert a submitted value into its storage representation.
	 *
	 * @param mixed $raw Raw submitted value.
	 * @return mixed Storage representation.
	 */
	public function sanitize( mixed $raw ): mixed {
		if ( null !== $this->sanitizer ) {
			return ( $this->sanitizer )( $raw );
		}

		return match ( $this->type ) {
			self::TYPE_BOOLEAN => self::to_bool( $raw ) ? 'yes' : 'no',
			self::TYPE_INTEGER => $this->clamp( (int) ( is_scalar( $raw ) ? $raw : 0 ) ),
			self::TYPE_ARRAY   => $this->sanitize_list( $raw ),
			default            => sanitize_text_field( (string) ( is_scalar( $raw ) ? $raw : '' ) ),
		};
	}

	/**
	 * Check whether a storage representation is acceptable.
	 *
	 * @param mixed $value Storage representation, normally the output of sanitize().
	 * @return bool True when the value may be persisted.
	 */
	public function is_valid( mixed $value ): bool {
		if ( null !== $this->validator ) {
			return (bool) ( $this->validator )( $value );
		}

		$choices = $this->get_choices();

		if ( self::TYPE_ARRAY === $this->type ) {
			if ( ! is_array( $value ) ) {
				return false;
			}

			if ( array() === $choices ) {
				return true;
			}

			foreach ( $value as $item ) {
				if ( ! array_key_exists( (string) $item, $choices ) ) {
					return false;
				}
			}

			return true;
		}

		if ( self::TYPE_INTEGER === $this->type ) {
			$int = (int) $value;

			if ( null !== $this->min && $int < $this->min ) {
				return false;
			}

			if ( null !== $this->max && $int > $this->max ) {
				return false;
			}

			return array() === $choices || array_key_exists( (string) $int, $choices );
		}

		if ( self::TYPE_BOOLEAN === $this->type ) {
			return in_array( $value, array( 'yes', 'no' ), true );
		}

		if ( array() === $choices ) {
			return true;
		}

		return array_key_exists( (string) $value, $choices );
	}

	/**
	 * Convert a stored value into its PHP typed form.
	 *
	 * @param mixed $stored Value read from the option row.
	 * @return mixed Typed value.
	 */
	public function cast( mixed $stored ): mixed {
		return match ( $this->type ) {
			self::TYPE_BOOLEAN => self::to_bool( $stored ),
			self::TYPE_INTEGER => (int) ( is_scalar( $stored ) ? $stored : 0 ),
			self::TYPE_ARRAY   => is_array( $stored ) ? array_values( $stored ) : array(),
			default            => (string) ( is_scalar( $stored ) ? $stored : '' ),
		};
	}

	/**
	 * Build the REST `args` entry describing this field.
	 *
	 * @return array<string, mixed> Argument schema understood by register_rest_route().
	 */
	public function to_rest_arg(): array {
		$arg = array(
			'type'              => self::TYPE_ARRAY === $this->type ? 'array' : $this->type,
			'required'          => false,
			'description'       => '' !== $this->description ? $this->description : $this->label,
			'validate_callback' => function ( mixed $value ): bool {
				return $this->is_valid( $this->sanitize( $value ) );
			},
			'sanitize_callback' => function ( mixed $value ): mixed {
				return $this->sanitize( $value );
			},
		);

		$choices = $this->get_choices();

		if ( self::TYPE_ARRAY === $this->type ) {
			$arg['items'] = array( 'type' => 'string' );

			if ( array() !== $choices ) {
				$arg['items']['enum'] = array_map( 'strval', array_keys( $choices ) );
			}

			return $arg;
		}

		if ( self::TYPE_BOOLEAN === $this->type ) {
			$arg['type'] = array( 'boolean', 'string' );

			return $arg;
		}

		if ( array() !== $choices ) {
			$arg['enum'] = array_map(
				self::TYPE_INTEGER === $this->type ? 'intval' : 'strval',
				array_keys( $choices )
			);
		}

		if ( self::TYPE_INTEGER === $this->type ) {
			if ( null !== $this->min ) {
				$arg['minimum'] = $this->min;
			}

			if ( null !== $this->max ) {
				$arg['maximum'] = $this->max;
			}
		}

		return $arg;
	}

	/**
	 * Normalise any of the representations WordPress uses for a boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool Normalised boolean.
	 */
	public static function to_bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 0 !== $value;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		return in_array( strtolower( trim( $value ) ), array( '1', 'yes', 'on', 'true' ), true );
	}

	/**
	 * Clamp an integer into the configured range.
	 *
	 * @param int $value Raw integer.
	 * @return int Clamped integer.
	 */
	private function clamp( int $value ): int {
		if ( null !== $this->min && $value < $this->min ) {
			return $this->min;
		}

		if ( null !== $this->max && $value > $this->max ) {
			return $this->max;
		}

		return $value;
	}

	/**
	 * Sanitize a submitted list into an ordered list of unique known keys.
	 *
	 * @param mixed $raw Raw submitted value.
	 * @return array<int, string> Ordered list.
	 */
	private function sanitize_list( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$choices = $this->get_choices();
		$list    = array();

		foreach ( $raw as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = sanitize_key( (string) $item );

			if ( '' === $item || in_array( $item, $list, true ) ) {
				continue;
			}

			if ( array() !== $choices && ! array_key_exists( $item, $choices ) ) {
				continue;
			}

			$list[] = $item;
		}

		return $list;
	}
}
