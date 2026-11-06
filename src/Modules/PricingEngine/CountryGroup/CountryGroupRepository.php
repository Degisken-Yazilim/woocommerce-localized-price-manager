<?php
declare(strict_types=1);

namespace D6N\LPM\Modules\PricingEngine\CountryGroup;

use D6N\LPM\Core\Database\AbstractRepository;
use stdClass;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Regional country group repository.
 */
class CountryGroupRepository extends AbstractRepository {

	/**
	 * Highest numeric suffix tried while making a derived slug unique.
	 *
	 * @var int
	 */
	private const MAX_SLUG_ATTEMPTS = 50;

	/**
	 * Get one active group by primary key.
	 *
	 * @param int $group_id Group ID.
	 * @return stdClass|WP_Error|null Row, null when absent or inactive, or WP_Error on failure.
	 */
	public function get_active_group( int $group_id ): stdClass|WP_Error|null {
		if ( $group_id <= 0 ) {
			return null;
		}

		return $this->guarded(
			function () use ( $group_id ): ?stdClass {
				$results = $this->mapper->get_where(
					array(
						'id'        => $group_id,
						'is_active' => 1,
					)
				);

				$row = $results[0] ?? null;

				return $row instanceof stdClass ? $row : null;
			}
		);
	}

	/**
	 * Get every active group.
	 *
	 * @return array<stdClass>|WP_Error
	 */
	public function get_active_groups(): array|WP_Error {
		return $this->guarded(
			fn (): array => $this->mapper->get_where( array( 'is_active' => 1 ) )
		);
	}

	/**
	 * Create or update a group identified by its slug.
	 *
	 * @param string               $slug Group slug.
	 * @param array<string, mixed> $data Column values; unknown keys are dropped by the model.
	 * @return int|WP_Error Inserted ID or affected count, or WP_Error on failure.
	 */
	public function save_group( string $slug, array $data ): int|WP_Error {
		$slug = trim( $slug );

		if ( '' === $slug ) {
			return new WP_Error(
				'lpm_invalid_country_group',
				__( 'A slug is required to save a country group.', 'localized-price-manager' )
			);
		}

		$existing = $this->find_row( $slug );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( isset( $data['currency_code'] ) && is_string( $data['currency_code'] ) ) {
			$data['currency_code'] = strtoupper( $data['currency_code'] );
		}

		$data['slug'] = $slug;

		if ( $existing && isset( $existing->id ) ) {
			return $this->update_record( (int) $existing->id, $data );
		}

		return $this->create_record( $data );
	}

	/**
	 * Update one group in place, by primary key.
	 *
	 * @param int                  $group_id Group ID.
	 * @param array<string, mixed> $data     Column values; unknown keys are dropped by the model.
	 * @return int|WP_Error Affected count, or WP_Error on failure.
	 */
	public function update_group( int $group_id, array $data ): int|WP_Error {
		if ( $group_id <= 0 ) {
			return new WP_Error(
				'lpm_invalid_country_group',
				__( 'A group id is required to update a country group.', 'localized-price-manager' )
			);
		}

		if ( isset( $data['currency_code'] ) && is_string( $data['currency_code'] ) ) {
			$data['currency_code'] = strtoupper( $data['currency_code'] );
		}

		if ( isset( $data['slug'] ) && '' === trim( (string) $data['slug'] ) ) {
			return new WP_Error(
				'lpm_invalid_country_group',
				__( 'A slug is required to save a country group.', 'localized-price-manager' )
			);
		}

		return $this->update_record( $group_id, $data );
	}

	/**
	 * Find a free slug for a group, starting from a derived base.
	 *
	 * @param string $base      Slug derived from the group name.
	 * @param int    $ignore_id Group ID that may keep the slug, or 0 for a new group.
	 * @return string|WP_Error Free slug, or WP_Error when none could be found.
	 */
	public function resolve_unique_slug( string $base, int $ignore_id = 0 ): string|WP_Error {
		$base = trim( $base );

		if ( '' === $base ) {
			return new WP_Error(
				'lpm_invalid_country_group',
				__( 'A slug is required to save a country group.', 'localized-price-manager' )
			);
		}

		for ( $suffix = 1; $suffix <= self::MAX_SLUG_ATTEMPTS; $suffix++ ) {
			$candidate = 1 === $suffix ? $base : $base . '-' . $suffix;
			$existing  = $this->find_row( $candidate );

			if ( is_wp_error( $existing ) ) {
				return $existing;
			}

			if ( null === $existing || (int) ( $existing->id ?? 0 ) === $ignore_id ) {
				return $candidate;
			}
		}

		return new WP_Error(
			'lpm_country_group_slug_taken',
			__( 'A free slug could not be derived from this group name.', 'localized-price-manager' )
		);
	}

	/**
	 * Look up a group regardless of its active flag, for write paths.
	 *
	 * @param string $slug Group slug.
	 * @return stdClass|WP_Error|null
	 */
	private function find_row( string $slug ): stdClass|WP_Error|null {
		return $this->guarded(
			function () use ( $slug ): ?stdClass {
				$results = $this->mapper->get_where( array( 'slug' => $slug ) );

				$row = $results[0] ?? null;

				return $row instanceof stdClass ? $row : null;
			}
		);
	}
}
