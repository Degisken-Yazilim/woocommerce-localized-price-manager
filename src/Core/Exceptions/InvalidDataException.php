<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Exceptions;

use Exception;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Exception thrown when invalid data is provided.
 */
class InvalidDataException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string         $message  Error message.
	 * @param string         $field    Field name.
	 * @param int            $code     Exception code.
	 * @param Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'Invalid data provided.',
		string $field = '',
		int $code = 400,
		?Throwable $previous = null
	) {
		if ( ! empty( $field ) ) {
			$message = sprintf( 'Invalid data for field "%s": %s', $field, $message );
		}

		parent::__construct( $message, $code, $previous );
	}
}
