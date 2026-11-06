<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Traits;

use D6N\LPM\Core\Shared\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Trait for building WordPress hooks and filters for models and repositories.
 */
trait HasHookBuilder {

	/**
	 * Get short model slug derived from class name.
	 *
	 * @return string
	 */
	protected function get_model_slug(): string {
		$short = substr( static::class, strrpos( static::class, '\\' ) + 1 );
		$short = str_replace( array( 'Model', 'Repository' ), '', $short );
		return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $short ) );
	}

	/**
	 * Build model-specific hook name.
	 *
	 * @param string $suffix Hook suffix.
	 * @return non-empty-string Full hook name; the plugin prefix alone makes it non-empty.
	 */
	protected function build_hook( string $suffix ): string {
		return Core::build_hook( $this->get_model_slug() . '_' . $suffix );
	}
}
