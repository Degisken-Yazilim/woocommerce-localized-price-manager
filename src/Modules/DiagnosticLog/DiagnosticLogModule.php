<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Modules\DiagnosticLog\Handlers\DatabaseLogHandler;
use D6N\LPM\Modules\DiagnosticLog\Handlers\WooCommerceLogHandler;
use D6N\LPM\Modules\DiagnosticLog\Models\LogModel;
use D6N\LPM\Modules\DiagnosticLog\Repositories\LogRepository;
use D6N\LPM\Modules\DiagnosticLog\Services\DiagnosticLogger;
use D6N\LPM\Modules\DiagnosticLog\Services\LogIdentityResolver;
use D6N\LPM\Modules\DiagnosticLog\Services\LogPrivacyService;
use D6N\LPM\Modules\DiagnosticLog\Services\LogPurgeService;
use D6N\LPM\Modules\DiagnosticLog\Services\LogSettings;
use D6N\LPM\Modules\DiagnosticLog\Services\NullLogger;

defined( 'ABSPATH' ) || exit;

/**
 * DiagnosticLog bounded-context module bootstrapper.
 */
class DiagnosticLogModule implements IHookable {

	private DiagnosticLogger $logger;
	private LogRepository $repository;
	private LogPurgeService $purge_service;
	private LogPrivacyService $privacy_service;
	private DatabaseLogHandler $database_handler;
	private WooCommerceLogHandler $file_handler;

	/**
	 * Constructor wiring the log destinations, the logger and the purge service.
	 */
	public function __construct() {
		$settings = new LogSettings();

		$this->repository = new LogRepository( new LogModel(), new NullLogger() );

		$this->database_handler = new DatabaseLogHandler( $this->repository, $settings );
		$this->file_handler     = new WooCommerceLogHandler( $settings );

		$this->logger = new DiagnosticLogger(
			array( $this->database_handler, $this->file_handler ),
			null,
			$settings,
			new LogIdentityResolver( $settings )
		);

		$this->purge_service   = new LogPurgeService( $this->repository, $this->logger, $this->file_handler, $settings );
		$this->privacy_service = new LogPrivacyService( $this->repository, $settings );
	}

	/**
	 * Get the configured logger instance.
	 *
	 * @return DiagnosticLogger
	 */
	public function get_logger(): DiagnosticLogger {
		return $this->logger;
	}

	/**
	 * Get log repository instance.
	 *
	 * @return LogRepository
	 */
	public function get_repository(): LogRepository {
		return $this->repository;
	}

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register module hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		$this->purge_service->register_hooks();
		$this->privacy_service->register_hooks();
	}
}
