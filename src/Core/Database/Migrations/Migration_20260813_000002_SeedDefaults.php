<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Migrations;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use D6N\LPM\Core\Database\Migrations\Exceptions\MigrationFailedException;
use D6N\LPM\Core\Enums\TablesEnum;
use D6N\LPM\Core\Reference\CountryCurrencyMap;

/**
 * Seeds the default data rows: the country to currency map, in full.
 */
class Migration_20260813_000002_SeedDefaults extends AbstractMigration {

	/**
	 * Number of rows inserted per statement.
	 */
	private const CHUNK_SIZE = 50;

	/**
	 * Data migrations run after the schema is in place.
	 *
	 * @return int
	 */
	public function priority(): int {
		return self::PRIORITY_DATA;
	}

	/**
	 * Insert one row per mapped country, filling in whatever is missing.
	 *
	 * @return void
	 * @throws MigrationFailedException If the table is missing, an insert fails, or
	 *                                 a mapped country is still absent afterwards.
	 */
	public function up(): void {
		$table = TablesEnum::CountryCurrency->table_name();

		if ( ! $this->table_exists( $table ) ) {
			throw new MigrationFailedException(
				$this->get_name(),
				sprintf( 'Table "%s" does not exist; the schema migration must run first.', $table )
			);
		}

		$map      = CountryCurrencyMap::get_map();
		$now      = $this->now();
		$inserted = 0;

		foreach ( array_chunk( $map, self::CHUNK_SIZE, true ) as $chunk ) {
			$inserted += $this->insert_chunk( $table, $chunk, $now );
		}

		$missing = $this->find_missing_countries( $table, $map );

		if ( array() !== $missing ) {
			throw new MigrationFailedException(
				$this->get_name(),
				sprintf(
					'Seeding left %1$d of %2$d country mappings unwritten: %3$s.',
					count( $missing ),
					count( $map ),
					implode( ', ', $missing )
				)
			);
		}

		$this->logger?->info(
			'Country currency mappings seeded.',
			array(
				'migration' => $this->get_name(),
				'inserted'  => $inserted,
			)
		);
	}

	/**
	 * Remove every seeded row.
	 *
	 * @return void
	 * @throws MigrationFailedException If the delete statement fails.
	 */
	public function down(): void {
		$table = TablesEnum::CountryCurrency->table_name();

		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$this->execute_statement( 'DELETE FROM %i', array( $table ) );

		$this->logger?->info(
			'Country currency mappings removed.',
			array( 'migration' => $this->get_name() )
		);
	}

	/**
	 * Insert one chunk of the map as a single multi-row statement.
	 *
	 * @param string                                                                   $table Fully prefixed table name.
	 * @param array<string, array{0: string, 1: string, 2: string, 3: string, 4: int}> $chunk Slice of the country map.
	 * @param string                                                                   $now   UTC timestamp for created_at and updated_at.
	 * @return int Number of rows inserted.
	 * @throws MigrationFailedException If the insert fails.
	 */
	private function insert_chunk( string $table, array $chunk, string $now ): int {
		if ( array() === $chunk ) {
			return 0;
		}

		$placeholders = array();
		$values       = array( $table );

		foreach ( $chunk as $country_code => $entry ) {
			$placeholders[] = '( %s, %s, %s, %s, %s, %s, %d, 1, %s, %s )';

			$values[] = strtoupper( (string) $country_code );
			$values[] = strtoupper( (string) $entry[0] );
			$values[] = (string) $entry[1];
			$values[] = strtoupper( (string) $entry[0] );
			$values[] = (string) $entry[2];
			$values[] = (string) $entry[3];
			$values[] = (int) $entry[4];
			$values[] = $now;
			$values[] = $now;
		}

		$sql = 'INSERT IGNORE INTO %i (
			country_code,
			currency_code,
			currency_symbol,
			native_currency_code,
			decimal_separator,
			thousand_separator,
			decimals,
			is_active,
			created_at,
			updated_at
		) VALUES ' . implode( ', ', $placeholders );

		return $this->execute_statement( $sql, $values );
	}

	/**
	 * Find the mapped countries the table does not hold.
	 *
	 * @param string                                                                   $table Fully prefixed table name.
	 * @param array<string, array{0: string, 1: string, 2: string, 3: string, 4: int}> $map   The bundled country map.
	 * @return array<int, string> Mapped country codes absent from the table.
	 */
	private function find_missing_countries( string $table, array $map ): array {
		$db = $this->get_db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = $db->get_col( $db->prepare( 'SELECT country_code FROM %i', $table ) );

		$stored = array_map(
			static fn ( mixed $code ): string => strtoupper( (string) $code ),
			$stored
		);

		$expected = array_map(
			static fn ( string $code ): string => strtoupper( $code ),
			array_keys( $map )
		);

		return array_values( array_diff( $expected, $stored ) );
	}
}
