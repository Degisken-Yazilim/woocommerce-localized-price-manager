<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the columns and the named indexes out of a CREATE TABLE statement.
 */
class CreateTableParser {

	/**
	 * Identifier body, matching what dbDelta() itself accepts as a name.
	 */
	private const IDENTIFIER = '(?:[0-9a-zA-Z$_]|[\xC2-\xDF][\x80-\xBF])(?:[0-9a-zA-Z$_-]|[\xC2-\xDF][\x80-\xBF])*';

	/**
	 * Matches the statement header and captures the table name.
	 */
	private const TABLE_PATTERN = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(' . self::IDENTIFIER . ')`?/i';

	/**
	 * Matches every definition that describes a key rather than a column.
	 */
	private const KEY_LEAD_PATTERN = '/^(?:PRIMARY|UNIQUE|KEY|INDEX|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN|CHECK)\b/i';

	/**
	 * Matches a primary key definition.
	 */
	private const PRIMARY_KEY_PATTERN = '/^PRIMARY\s+KEY\b/i';

	/**
	 * Matches a named key definition and captures its modifier and its name.
	 */
	private const NAMED_KEY_PATTERN = '/^(?:(UNIQUE|FULLTEXT|SPATIAL)\s+)?(?:KEY|INDEX)\s+`?(' . self::IDENTIFIER . ')`?\s*\(/i';

	/**
	 * Matches a column definition and captures its name.
	 */
	private const COLUMN_PATTERN = '/^`?(' . self::IDENTIFIER . ')`?\s+\S/';

	/**
	 * Name MySQL always gives the primary key, whatever the statement called it.
	 */
	private const PRIMARY_KEY_NAME = 'PRIMARY';

	/**
	 * Count the CREATE TABLE statements a string holds.
	 *
	 * @param string $sql One or more SQL statements.
	 * @return int
	 */
	public function count_statements( string $sql ): int {
		return (int) preg_match_all( self::TABLE_PATTERN, $sql );
	}

	/**
	 * Parse a CREATE TABLE statement.
	 *
	 * @param string $sql A single CREATE TABLE statement.
	 * @return TableDefinition|null Null when the statement is not a CREATE TABLE.
	 */
	public function parse( string $sql ): ?TableDefinition {
		if ( 1 !== preg_match( self::TABLE_PATTERN, $sql, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$table  = (string) $matches[1][0];
		$offset = (int) $matches[0][1] + strlen( (string) $matches[0][0] );

		$columns = array();
		$indexes = array();

		foreach ( $this->split_definitions( $sql, $offset ) as $definition ) {
			if ( 1 === preg_match( self::KEY_LEAD_PATTERN, $definition ) ) {
				$index = $this->read_index( $definition );

				if ( null !== $index ) {
					$indexes[] = $index;
				}

				continue;
			}

			if ( 1 === preg_match( self::COLUMN_PATTERN, $definition, $column ) ) {
				$columns[] = $column[1];
			}
		}

		return new TableDefinition( $table, $columns, $indexes );
	}

	/**
	 * Split the body of a CREATE TABLE statement into its definitions.
	 *
	 * @param string $sql    The whole statement.
	 * @param int    $offset Position just after the table name.
	 * @return array<int, string> Trimmed definitions, empty when the body never closes.
	 */
	private function split_definitions( string $sql, int $offset ): array {
		$start = strpos( $sql, '(', $offset );

		if ( false === $start ) {
			return array();
		}

		$length      = strlen( $sql );
		$definitions = array();
		$buffer      = '';
		$depth       = 0;
		$quote       = '';

		for ( $position = $start; $position < $length; $position++ ) {
			$character = $sql[ $position ];

			if ( '' !== $quote ) {
				$buffer .= $character;

				if ( '\\' === $character && '`' !== $quote && $position + 1 < $length ) {
					++$position;
					$buffer .= $sql[ $position ];

					continue;
				}

				if ( $character === $quote ) {
					$quote = '';
				}

				continue;
			}

			if ( "'" === $character || '"' === $character || '`' === $character ) {
				$quote   = $character;
				$buffer .= $character;

				continue;
			}

			if ( '(' === $character ) {
				++$depth;

				if ( 1 === $depth ) {
					continue;
				}
			}

			if ( ')' === $character ) {
				--$depth;

				if ( 0 === $depth ) {
					return $this->collect( $definitions, $buffer );
				}
			}

			if ( ',' === $character && 1 === $depth ) {
				$definitions = $this->collect( $definitions, $buffer );
				$buffer      = '';

				continue;
			}

			$buffer .= $character;
		}

		return array();
	}

	/**
	 * Append one definition, dropping it when it holds nothing.
	 *
	 * @param array<int, string> $definitions Definitions collected so far.
	 * @param string             $buffer      Text read since the last separator.
	 * @return array<int, string>
	 */
	private function collect( array $definitions, string $buffer ): array {
		$definition = trim( $buffer );

		if ( '' !== $definition ) {
			$definitions[] = $definition;
		}

		return $definitions;
	}

	/**
	 * Read one key definition.
	 *
	 * @param string $definition A definition already known to describe a key.
	 * @return IndexDefinition|null Null when the key cannot be addressed by name.
	 */
	private function read_index( string $definition ): ?IndexDefinition {
		if ( 1 === preg_match( self::PRIMARY_KEY_PATTERN, $definition ) ) {
			return new IndexDefinition( self::PRIMARY_KEY_NAME, true );
		}

		if ( 1 !== preg_match( self::NAMED_KEY_PATTERN, $definition, $matches ) ) {
			return null;
		}

		return new IndexDefinition( $matches[2], 'UNIQUE' === strtoupper( $matches[1] ) );
	}
}
