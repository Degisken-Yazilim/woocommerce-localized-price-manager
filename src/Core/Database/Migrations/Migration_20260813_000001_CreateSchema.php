<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Migrations;

use D6N\LPM\Core\Database\Migrations\Exceptions\MigrationFailedException;
use D6N\LPM\Core\Enums\TablesEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the complete 1.0.0 schema.
 */
class Migration_20260813_000001_CreateSchema extends AbstractMigration {

	/**
	 * Schema migrations run before data migrations.
	 *
	 * @return int
	 */
	public function priority(): int {
		return self::PRIORITY_SCHEMA;
	}

	/**
	 * Create every plugin table.
	 *
	 * @return void
	 * @throws MigrationFailedException If any table is missing after dbDelta() runs.
	 */
	public function up(): void {
		$charset_collate = $this->get_charset_collate();

		$this->execute_schema( $this->logs_sql( $charset_collate ) );

		$this->execute_schema( $this->country_pricing_sql( $charset_collate ) );
		$this->execute_schema( $this->category_pricing_sql( $charset_collate ) );
		$this->execute_schema( $this->country_currency_sql( $charset_collate ) );
		$this->execute_schema( $this->country_group_sql( $charset_collate ) );
		$this->execute_schema( $this->exchange_rates_sql( $charset_collate ) );
		$this->execute_schema( $this->migrations_sql( $charset_collate ) );

		$this->logger?->info(
			'Plugin schema created.',
			array( 'migration' => $this->get_name() )
		);
	}

	/**
	 * Drop every table this migration created.
	 *
	 * @return void
	 * @throws MigrationFailedException If a drop statement fails.
	 */
	public function down(): void {
		$tables = array(
			TablesEnum::CountryPricing,
			TablesEnum::CategoryPricing,
			TablesEnum::CountryCurrency,
			TablesEnum::CountryGroup,
			TablesEnum::ExchangeRates,
			TablesEnum::Logs,
		);

		foreach ( $tables as $table ) {
			$this->drop_table( $table->table_name() );
		}

		$this->logger?->info(
			'Plugin schema dropped.',
			array( 'migration' => $this->get_name() )
		);
	}

	/**
	 * Build the country_pricing CREATE TABLE statement.
	 *
	 * @param string $charset_collate Charset and collation clause.
	 * @return string
	 */
	private function country_pricing_sql( string $charset_collate ): string {
		$table = TablesEnum::CountryPricing->table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			product_id bigint(20) unsigned NOT NULL,
			country_code char(2) NOT NULL,
			input_price decimal(24,8) default NULL,
			input_sale_price decimal(24,8) default NULL,
			input_currency char(3) NOT NULL default '',
			local_price decimal(24,8) default NULL,
			local_sale_price decimal(24,8) default NULL,
			local_currency char(3) NOT NULL default '',
			exchange_rate decimal(20,12) default NULL,
			auto_convert tinyint(1) NOT NULL default 0,
			is_blocked tinyint(1) NOT NULL default 0,
			is_active tinyint(1) NOT NULL default 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_country (product_id, country_code),
			KEY country_code (country_code),
			KEY product_active (product_id, is_active)
		) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * Build the category_pricing CREATE TABLE statement.
	 *
	 * @param string $charset_collate Charset and collation clause.
	 * @return string
	 */
	private function category_pricing_sql( string $charset_collate ): string {
		$table = TablesEnum::CategoryPricing->table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			term_id bigint(20) unsigned NOT NULL,
			country_code char(2) NOT NULL,
			currency_code char(3) NOT NULL default '',
			exchange_rate decimal(20,12) default NULL,
			is_blocked tinyint(1) NOT NULL default 0,
			is_active tinyint(1) NOT NULL default 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY term_country (term_id, country_code),
			KEY country_code (country_code),
			KEY term_active (term_id, is_active)
		) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * Build the country_currency CREATE TABLE statement.
	 *
	 * @param string $charset_collate Charset and collation clause.
	 * @return string
	 */
	private function country_currency_sql( string $charset_collate ): string {
		$table = TablesEnum::CountryCurrency->table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			country_code char(2) NOT NULL,
			currency_code char(3) NOT NULL,
			currency_symbol varchar(16) NOT NULL default '',
			native_currency_code char(3) NOT NULL default '',
			decimal_separator varchar(4) NOT NULL default '.',
			thousand_separator varchar(4) NOT NULL default ',',
			decimals tinyint(1) unsigned NOT NULL default 2,
			group_id bigint(20) unsigned default NULL,
			is_active tinyint(1) NOT NULL default 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY country_code (country_code),
			KEY currency_code (currency_code),
			KEY group_id (group_id)
		) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * Build the country_group CREATE TABLE statement.
	 *
	 * @param string $charset_collate Charset and collation clause.
	 * @return string
	 */
	private function country_group_sql( string $charset_collate ): string {
		$table = TablesEnum::CountryGroup->table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			name varchar(191) NOT NULL,
			slug varchar(191) NOT NULL,
			currency_code char(3) NOT NULL default '',
			exchange_rate decimal(20,12) default NULL,
			is_active tinyint(1) NOT NULL default 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * Build the exchange_rates CREATE TABLE statement.
	 *
	 * @param string $charset_collate Charset and collation clause.
	 * @return string
	 */
	private function exchange_rates_sql( string $charset_collate ): string {
		$table = TablesEnum::ExchangeRates->table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			base_currency char(3) NOT NULL,
			target_currency char(3) NOT NULL,
			exchange_rate decimal(20,12) NOT NULL,
			source varchar(50) NOT NULL default 'manual',
			last_updated datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY base_target (base_currency, target_currency),
			KEY source_base (source, base_currency),
			KEY last_updated (last_updated)
		) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * Build the logs CREATE TABLE statement.
	 *
	 * @param string $charset_collate Charset and collation clause.
	 * @return string
	 */
	private function logs_sql( string $charset_collate ): string {
		$table = TablesEnum::Logs->table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			level varchar(20) NOT NULL,
			channel varchar(20) NOT NULL default 'error',
			source varchar(191) NOT NULL default '',
			message longtext NOT NULL,
			context longtext,
			user_id bigint(20) unsigned default NULL,
			user_login varchar(60) default NULL,
			ip_address varchar(45) default NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY channel_created (channel, created_at),
			KEY level_created (level, created_at),
			KEY created_at (created_at),
			KEY user_id (user_id)
		) ENGINE=InnoDB {$charset_collate};";
	}

	/**
	 * Build the migrations CREATE TABLE statement.
	 *
	 * @param string $charset_collate Charset and collation clause.
	 * @return string
	 */
	private function migrations_sql( string $charset_collate ): string {
		$table = TablesEnum::Migrations->table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			migration varchar(191) NOT NULL,
			batch int(11) unsigned NOT NULL default 0,
			status varchar(20) NOT NULL default 'pending',
			priority tinyint(1) unsigned NOT NULL default 2,
			started_at datetime default NULL,
			completed_at datetime default NULL,
			error longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY migration (migration),
			KEY status (status),
			KEY batch (batch)
		) ENGINE=InnoDB {$charset_collate};";
	}
}
