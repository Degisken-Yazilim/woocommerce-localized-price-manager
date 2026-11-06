<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\Geolocation\Services;

use D6N\LPM\Core\Contracts\IHookable;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data exporter and eraser for the country stored on a customer's user profile.
 */
class CustomerCountryPrivacyService implements IHookable {

	/**
	 * Exporter and eraser identifier.
	 *
	 * @var string
	 */
	public const ID = 'localized-price-manager-customer-country';

	/**
	 * User meta key holding the detected country.
	 *
	 * @var string
	 */
	public const META_COUNTRY = '_lpm_country';

	/**
	 * User meta key holding when the country was last checked.
	 *
	 * @var string
	 */
	public const META_LAST_CHECK = '_lpm_country_last_check';

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
	}

	/**
	 * Add the customer country exporter.
	 *
	 * @param array<string, mixed> $exporters Registered exporters.
	 * @return array<string, mixed> Exporters including this one.
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Localized Price Manager customer country', 'localized-price-manager' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the customer country eraser.
	 *
	 * @param array<string, mixed> $erasers Registered erasers.
	 * @return array<string, mixed> Erasers including this one.
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Localized Price Manager customer country', 'localized-price-manager' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export the country stored for the user owning an email address.
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

		$data       = array();
		$country    = (string) get_user_meta( (int) $user->ID, self::META_COUNTRY, true );
		$last_check = (int) get_user_meta( (int) $user->ID, self::META_LAST_CHECK, true );

		if ( '' !== $country ) {
			$data[] = array(
				'name'  => __( 'Detected country', 'localized-price-manager' ),
				'value' => $country,
			);
		}

		if ( $last_check > 0 ) {
			$data[] = array(
				'name'  => __( 'Country last checked (UTC)', 'localized-price-manager' ),
				'value' => gmdate( 'Y-m-d H:i:s', $last_check ),
			);
		}

		if ( array() === $data ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		return array(
			'data' => array(
				array(
					'group_id'          => self::ID,
					'group_label'       => __( 'Localized Price Manager', 'localized-price-manager' ),
					'group_description' => __( 'The country detected from your IP address to show prices in your currency.', 'localized-price-manager' ),
					'item_id'           => self::ID . '-' . (int) $user->ID,
					'data'              => $data,
				),
			),
			'done' => true,
		);
	}

	/**
	 * Delete the country stored for the user owning an email address.
	 *
	 * @param string $email_address Email address of the data subject.
	 * @param int    $page          Page number, starting at 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} Erasure result.
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		$user    = $this->find_user( $email_address );
		$removed = false;

		if ( null !== $user ) {
			$removed = delete_user_meta( (int) $user->ID, self::META_COUNTRY );
			$removed = delete_user_meta( (int) $user->ID, self::META_LAST_CHECK ) || $removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
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
}
