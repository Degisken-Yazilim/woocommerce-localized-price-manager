<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Traits;

use D6N\LPM\Core\Contracts\ILogger;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Error handling functionality for database repositories.
 */
trait HasErrorHandling {

	/**
	 * Get the logger instance.
	 *
	 * @return ILogger
	 */
	abstract protected function get_logger(): ILogger;

	/**
	 * Handle and log throwables or WordPress errors.
	 *
	 * @param Throwable|WP_Error $error Caught throwable or error object.
	 * @return WP_Error
	 */
	protected function handle_error( Throwable|WP_Error $error ): WP_Error {
		if ( $error instanceof Throwable ) {
			$message = $error->getMessage();
			$context = array(
				'file'  => $error->getFile(),
				'line'  => $error->getLine(),
				'trace' => $error->getTraceAsString(),
			);
		} else {
			$message = $error->get_error_message();
			$context = array(
				'code' => $error->get_error_code(),
				'data' => $error->get_error_data(),
			);
		}

		$this->get_logger()->error( $message, $context );

		return new WP_Error(
			'database_error',
			__( 'An error occurred while processing your database request.', 'localized-price-manager' ),
			array( 'status' => 500 )
		);
	}
}
