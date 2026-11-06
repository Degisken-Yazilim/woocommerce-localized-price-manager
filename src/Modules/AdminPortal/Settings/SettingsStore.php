<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\AdminPortal\Settings;

use D6N\LPM\Core\Shared\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plugin settings through the schema.
 */
class SettingsStore {

	/**
	 * Get the stored value of one setting in its storage representation.
	 *
	 * @param string $key Option key.
	 * @return mixed Stored value, or the schema default.
	 */
	public function get_raw( string $key ): mixed {
		return Core::get_option( $key, SettingsSchema::default_for( $key ) );
	}

	/**
	 * Get the stored value of one setting, cast to its declared type.
	 *
	 * @param string $key Option key.
	 * @return mixed Typed value.
	 */
	public function get( string $key ): mixed {
		$definition = SettingsSchema::get( $key );

		if ( null === $definition ) {
			return Core::get_option( $key, false );
		}

		return $definition->cast( $this->get_raw( $key ) );
	}

	/**
	 * Persist one setting after sanitizing and validating it.
	 *
	 * @param string $key Option key.
	 * @param mixed  $raw Raw submitted value.
	 * @return bool True when the value was accepted and stored.
	 */
	public function save( string $key, mixed $raw ): bool {
		$definition = SettingsSchema::get( $key );

		if ( null === $definition || $definition->secret ) {
			return false;
		}

		$value = $definition->sanitize( $raw );

		if ( ! $definition->is_valid( $value ) ) {
			return false;
		}

		$was_already_stored = ! $this->has_changed( $key, $raw );

		$stored = Core::save_option( $key, $value );

		foreach ( $definition->mirrors as $mirror ) {
			Core::save_option( $mirror, $value );
		}

		return $stored || $was_already_stored;
	}

	/**
	 * Check whether a submitted value differs from the stored one.
	 *
	 * @param string $key Option key.
	 * @param mixed  $raw Raw submitted value.
	 * @return bool True when the value would change the stored state.
	 */
	public function has_changed( string $key, mixed $raw ): bool {
		$definition = SettingsSchema::get( $key );

		return $this->get_raw( $key ) !== ( null === $definition ? $raw : $definition->sanitize( $raw ) );
	}
}
