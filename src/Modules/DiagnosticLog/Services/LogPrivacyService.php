<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\DiagnosticLog\Services;

use D6N\LPM\Core\Contracts\IHookable;
use D6N\LPM\Core\Enums\LogLevelEnum;
use D6N\LPM\Modules\DiagnosticLog\Enums\LogChannelEnum;
use D6N\LPM\Modules\DiagnosticLog\Repositories\LogRepository;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data exporter, eraser and privacy policy text for the diagnostic log.
 */
class LogPrivacyService implements IHookable {

	/**
	 * Exporter and eraser identifier.
	 *
	 * @var string
	 */
	public const ID = 'localized-price-manager-diagnostic-log';

	/**
	 * Log entries exported per page.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param LogRepository $repository Log repository.
	 * @param LogSettings   $settings   Log settings reader.
	 */
	public function __construct(
		private readonly LogRepository $repository,
		private readonly LogSettings $settings
	) {}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
	}

	/**
	 * Add the diagnostic log exporter.
	 *
	 * @param array<string, mixed> $exporters Registered exporters.
	 * @return array<string, mixed> Exporters including this one.
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Localized Price Manager diagnostic log', 'localized-price-manager' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the diagnostic log eraser.
	 *
	 * @param array<string, mixed> $erasers Registered erasers.
	 * @return array<string, mixed> Erasers including this one.
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Localized Price Manager diagnostic log', 'localized-price-manager' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export the log entries recorded for the user owning an email address.
	 *
	 * @param string $email_address Email address of the data subject.
	 * @param int    $page          Page number, starting at 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool} Export page.
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$user = $this->find_user( $email_address );

		if ( null === $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$rows = $this->repository->get_user_logs(
			(int) $user->ID,
			(string) $user->user_login,
			self::PAGE_SIZE,
			( max( 1, $page ) - 1 ) * self::PAGE_SIZE
		);

		if ( $rows instanceof WP_Error ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		return array(
			'data' => array_map( array( $this, 'to_export_item' ), $rows ),
			'done' => count( $rows ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Remove the user identity and IP address from the log entries recorded for the user owning an email address.
	 *
	 * @param string $email_address Email address of the data subject.
	 * @param int    $page          Page number, starting at 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} Erasure result.
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user = $this->find_user( $email_address );

		if ( null === $user ) {
			return self::erasure_result( false, false, array() );
		}

		$anonymized = $this->repository->anonymize_user_logs( (int) $user->ID, (string) $user->user_login );

		if ( $anonymized instanceof WP_Error ) {
			return self::erasure_result(
				false,
				true,
				array( __( 'The diagnostic log entries could not be anonymized.', 'localized-price-manager' ) )
			);
		}

		return self::erasure_result( $anonymized > 0, false, array() );
	}

	/**
	 * Register the suggested privacy policy text.
	 *
	 * @return void
	 */
	public function add_privacy_policy_content(): void {
		wp_add_privacy_policy_content(
			__( 'Localized Price Manager', 'localized-price-manager' ),
			wp_kses_post( wpautop( $this->get_privacy_policy_text(), false ) )
		);
	}

	/**
	 * Build the suggested privacy policy text.
	 *
	 * @return string Policy text, one paragraph per line pair.
	 */
	public function get_privacy_policy_text(): string {
		$paragraphs = array(
			__( 'To show prices in the currency of your country, this store determines your country from your IP address. Your IP address is sent to the geolocation services enabled in the plugin settings, which return the country it belongs to.', 'localized-price-manager' ),
			__( 'When the WooCommerce geolocation service is enabled and the store has no local MaxMind geolocation database, WooCommerce itself may send your IP address to an external geolocation service to look up its country, and keeps the answer for a while in a temporary cache entry named after your IP address. This is WooCommerce\'s own behaviour, which the plugin does not change.', 'localized-price-manager' ),
			__( 'The detected country is stored in your WooCommerce session while you browse as a guest, and in your user profile when you are logged in, together with the time it was last checked.', 'localized-price-manager' ),
			__( 'The plugin keeps a diagnostic log. Its entries may record your user ID, your username and your IP address. The IP address is stored in full on audit entries and on entries of warning severity or above, and with its last part removed on the other entries. While the site runs in debug mode, the IP address is stored in full on every entry.', 'localized-price-manager' ),
			sprintf(
				/* translators: %d: number of days log entries are kept. */
				_n(
					'Diagnostic log entries are deleted automatically after %d day, as set by the log retention setting.',
					'Diagnostic log entries are deleted automatically after %d days, as set by the log retention setting.',
					$this->settings->get_retention_days(),
					'localized-price-manager'
				),
				$this->settings->get_retention_days()
			),
		);

		return implode( "\n\n", $paragraphs );
	}

	/**
	 * Convert one log row into an exporter item.
	 *
	 * @param array<string, mixed> $row Log row.
	 * @return array<string, mixed> Exporter item.
	 */
	private function to_export_item( array $row ): array {
		$channel = LogChannelEnum::tryFrom( (string) ( $row['channel'] ?? '' ) );
		$level   = LogLevelEnum::tryFrom( (string) ( $row['level'] ?? '' ) );

		$fields = array(
			__( 'Date (UTC)', 'localized-price-manager' ) => (string) ( $row['created_at'] ?? '' ),
			__( 'Channel', 'localized-price-manager' )    => null !== $channel ? $channel->label() : (string) ( $row['channel'] ?? '' ),
			__( 'Severity', 'localized-price-manager' )   => null !== $level ? $level->label() : (string) ( $row['level'] ?? '' ),
			__( 'Source', 'localized-price-manager' )     => (string) ( $row['source'] ?? '' ),
			__( 'Message', 'localized-price-manager' )    => (string) ( $row['message'] ?? '' ),
			__( 'Username', 'localized-price-manager' )   => (string) ( $row['user_login'] ?? '' ),
			__( 'IP address', 'localized-price-manager' ) => (string) ( $row['ip_address'] ?? '' ),
		);

		$data = array();

		foreach ( $fields as $name => $value ) {
			if ( '' !== $value ) {
				$data[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
		}

		return array(
			'group_id'          => self::ID,
			'group_label'       => __( 'Localized Price Manager diagnostic log', 'localized-price-manager' ),
			'group_description' => __( 'Diagnostic log entries recorded while you were logged in.', 'localized-price-manager' ),
			'item_id'           => 'lpm-log-' . (int) ( $row['id'] ?? 0 ),
			'data'              => $data,
		);
	}

	/**
	 * Find the user owning an email address.
	 *
	 * @param string $email_address Email address.
	 * @return WP_User|null User, or null when no account uses the address.
	 */
	private function find_user( string $email_address ): ?WP_User {
		$user = get_user_by( 'email', $email_address );

		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Build an eraser answer.
	 *
	 * @param bool               $removed  Whether personal data was removed.
	 * @param bool               $retained Whether personal data was kept.
	 * @param array<int, string> $messages Messages for the operator.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} Erasure result.
	 */
	private static function erasure_result( bool $removed, bool $retained, array $messages ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
