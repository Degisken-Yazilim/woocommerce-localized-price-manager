<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Migrations\Exceptions;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Base exception for every migration subsystem failure.
 */
class MigrationException extends RuntimeException {
}
