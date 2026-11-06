<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Exceptions;

use Exception;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Exception thrown when a database query fails.
 */
class QueryException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param string         $message  Error message.
	 * @param string         $query    SQL query string.
	 * @param int            $code     Exception code.
	 * @param Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'Database query failed.',
		string $query = '',
		int $code = 500,
		?Throwable $previous = null
	) {
		if ( ! empty( $query ) ) {
			$message .= sprintf( ' Query: %s', $query );
		}

		parent::__construct( $message, $code, $previous );
	}
}
