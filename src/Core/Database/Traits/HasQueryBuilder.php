<?php
declare(strict_types=1);

namespace D6N\LPM\Core\Database\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * Trait for building SQL query components safely.
 */
trait HasQueryBuilder {

	/**
	 * Build WHERE clause SQL from conditions array.
	 *
	 * @param array<int|string, mixed> $conditions Array of conditions; see the trait docblock for the accepted shapes.
	 * @param array<string, string>    $formats    Value placeholders keyed by column name.
	 * @param string                   $relation   Logical operator joining top-level clauses ('AND' or 'OR').
	 * @return array{0: literal-string, 1: list<mixed>} Tuple of [clause string, bind parameters].
	 */
	protected function build_where_clauses( array $conditions, array $formats = array(), string $relation = 'AND' ): array {
		$clauses      = array();
		$query_params = array();

		$glue = 'OR' === strtoupper( $relation ) ? ' OR ' : ' AND ';

		foreach ( $conditions as $key => $value ) {
			$result = $this->process_single_condition( $key, $value, $formats );
			if ( null === $result ) {
				continue;
			}

			[ $clause, $params ] = $result;

			if ( '' === $clause ) {
				continue;
			}

			$clauses[]    = $clause;
			$query_params = array_merge( $query_params, $params );
		}

		$clause_string = empty( $clauses ) ? '' : '(' . implode( $glue, $clauses ) . ')';

		return array( $clause_string, $query_params );
	}

	/**
	 * Turn a single condition entry into its clause and bind parameters.
	 *
	 * @param mixed                 $key     Column name, integer index, or the 'AND'/'OR' of a nested group.
	 * @param mixed                 $value   Comparison value, operator triple, or nested condition set.
	 * @param array<string, string> $formats Value placeholders keyed by column name.
	 * @return array{0: literal-string, 1: list<mixed>}|null Null when the entry carries no usable comparison.
	 */
	private function process_single_condition( mixed $key, mixed $value, array $formats ): ?array {
		if ( is_string( $key ) && is_array( $value ) && $this->is_nested_group( $key ) ) {
			return $this->build_nested_group_clause( $key, $value, $formats );
		}

		$parsed = $this->parse_condition( $key, $value, $formats );
		if ( null === $parsed ) {
			return null;
		}

		[ $column, $operator, $val, $format ] = $parsed;

		return $this->build_condition_clause( $column, $operator, $val, $format );
	}

	/**
	 * Check whether a condition key opens a nested group.
	 *
	 * @param string $key Condition key.
	 * @return bool
	 */
	private function is_nested_group( string $key ): bool {
		return in_array( strtoupper( $key ), array( 'AND', 'OR' ), true );
	}

	/**
	 * Build the parenthesised clause for a nested AND/OR group.
	 *
	 * @param string                   $key     Group relation, 'AND' or 'OR'.
	 * @param array<int|string, mixed> $value   Nested conditions.
	 * @param array<string, string>    $formats Value placeholders keyed by column name.
	 * @return array{0: literal-string, 1: list<mixed>} Empty clause when the group holds nothing usable.
	 */
	private function build_nested_group_clause( string $key, array $value, array $formats ): array {
		[ $sub_clause_string, $sub_query_params ] = $this->build_where_clauses( $value, $formats, strtoupper( $key ) );

		if ( '' === $sub_clause_string ) {
			return array( '', array() );
		}

		return array( '(' . $sub_clause_string . ')', $sub_query_params );
	}

	/**
	 * Resolve a condition into its column, operator, value and placeholder.
	 *
	 * @param mixed                 $key     Column name for the string-key form, integer index otherwise.
	 * @param mixed                 $value   Comparison value for the string-key form, operator triple otherwise.
	 * @param array<string, string> $formats Value placeholders keyed by column name.
	 * @return array{0: string, 1: literal-string, 2: mixed, 3: literal-string}|null Null when the condition is unusable.
	 */
	private function parse_condition( mixed $key, mixed $value, array $formats ): ?array {
		if ( is_string( $key ) && ! is_numeric( $key ) ) {
			$format = $this->get_validated_format( $formats[ $key ] ?? null, $value );

			return array( sanitize_key( $key ), '=', $value, $format );
		}

		if ( is_array( $value ) && count( $value ) >= 2 && count( $value ) <= 3 ) {
			return $this->parse_array_condition( array_values( $value ) );
		}

		return null;
	}

	/**
	 * Resolve an integer-keyed `array( column, operator, value )` condition.
	 *
	 * @param array<int, mixed> $value Condition members, already re-indexed.
	 * @return array{0: string, 1: literal-string, 2: mixed, 3: literal-string}|null Null when the condition is unusable.
	 */
	private function parse_array_condition( array $value ): ?array {
		$column = sanitize_key( trim( (string) $value[0] ) );

		if ( empty( $column ) ) {
			return null;
		}

		if ( 2 === count( $value ) ) {
			return array( $column, '=', $value[1], $this->determine_format( $value[1] ) );
		}

		$operator = strtoupper( trim( (string) $value[1] ) );

		$allowed_operators = array( '=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL' );

		if ( ! in_array( $operator, $allowed_operators, true ) ) {
			return null;
		}

		$val = $value[2];

		$format = in_array( $operator, array( 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL' ), true )
			? '%s'
			: $this->determine_format( $val );

		return array( $column, $operator, $val, $format );
	}

	/**
	 * Build the SQL fragment for one parsed condition.
	 *
	 * @param string         $column   Sanitised column name, bound through an `%i` placeholder.
	 * @param literal-string $operator Comparison operator taken from the allow-list.
	 * @param mixed          $val      Comparison value.
	 * @param literal-string $format   Placeholder describing the value.
	 * @return array{0: literal-string, 1: list<mixed>}|null Null when the comparison has no SQL equivalent.
	 */
	private function build_condition_clause( string $column, string $operator, mixed $val, string $format ): ?array {
		if ( 'IS NULL' === $operator ) {
			return $this->build_null_clause( $column, true );
		}

		if ( 'IS NOT NULL' === $operator ) {
			return $this->build_null_clause( $column, false );
		}

		if ( in_array( $operator, array( 'IN', 'NOT IN' ), true ) ) {
			return $this->build_in_clause( $column, $operator, $val );
		}

		return $this->build_basic_clause( $column, $operator, $val, $format );
	}

	/**
	 * Build an IS NULL / IS NOT NULL clause.
	 *
	 * @param string $column  Column name, bound through an `%i` placeholder.
	 * @param bool   $is_null True for IS NULL, false for IS NOT NULL.
	 * @return array{0: literal-string, 1: list<mixed>}
	 */
	private function build_null_clause( string $column, bool $is_null ): array {
		return array( $is_null ? '%i IS NULL' : '%i IS NOT NULL', array( $column ) );
	}

	/**
	 * Build an IN / NOT IN clause.
	 *
	 * @param string        $column   Column name, bound through an `%i` placeholder.
	 * @param 'IN'|'NOT IN' $operator Set operator.
	 * @param mixed         $val      Candidate values.
	 * @return array{0: literal-string, 1: list<mixed>}|null Null when there is nothing to match against.
	 */
	private function build_in_clause( string $column, string $operator, mixed $val ): ?array {
		if ( ! is_array( $val ) || empty( $val ) ) {
			return null;
		}

		$sanitized_values = array_values(
			array_map(
				static function ( mixed $v ): mixed {
					if ( is_numeric( $v ) ) {
						return $v;
					}
					return sanitize_text_field( (string) $v );
				},
				$val
			)
		);

		$placeholders = implode( ',', array_fill( 0, count( $sanitized_values ), '%s' ) );
		$clause       = 'IN' === $operator
			? '%i IN (' . $placeholders . ')'
			: '%i NOT IN (' . $placeholders . ')';

		return array( $clause, array_merge( array( $column ), $sanitized_values ) );
	}

	/**
	 * Build a single-value comparison clause.
	 *
	 * @param string         $column   Column name, bound through an `%i` placeholder.
	 * @param literal-string $operator Comparison operator taken from the allow-list.
	 * @param mixed          $val      Comparison value.
	 * @param literal-string $format   Placeholder describing the value.
	 * @return array{0: literal-string, 1: list<mixed>}|null Null when a null value cannot be expressed with this operator.
	 */
	private function build_basic_clause( string $column, string $operator, mixed $val, string $format ): ?array {
		if ( null === $val ) {
			if ( '=' === $operator ) {
				return $this->build_null_clause( $column, true );
			}
			if ( '!=' === $operator ) {
				return $this->build_null_clause( $column, false );
			}
			return null;
		}

		return array( '%i ' . $operator . ' ' . $format, array( $column, $val ) );
	}

	/**
	 * Build the ORDER BY list.
	 *
	 * @param string|array<int, mixed> $order_by Column name, or a list of column names.
	 * @param string                   $order    Sort direction; anything other than DESC reads as ASC.
	 * @return string Comma separated ORDER BY list, empty when no column survived sanitisation.
	 */
	protected function build_order_by( string|array $order_by, string $order ): string {
		$order          = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';
		$order_by_parts = array();

		if ( is_string( $order_by ) ) {
			$sanitized = sanitize_sql_orderby( $order_by );
			if ( $sanitized ) {
				$order_by_parts[] = $sanitized . ' ' . $order;
			}
		} elseif ( is_array( $order_by ) ) {
			foreach ( $order_by as $col ) {
				if ( is_string( $col ) && ! empty( $col ) ) {
					$sanitized = sanitize_sql_orderby( $col );
					if ( $sanitized ) {
						$order_by_parts[] = $sanitized . ' ' . $order;
					}
				}
			}
		}

		return implode( ', ', $order_by_parts );
	}

	/**
	 * Map a PHP value onto the wpdb placeholder that carries it.
	 *
	 * @param mixed $value Value to inspect.
	 * @return '%d'|'%f'|'%s'|'NULL' Placeholder, or the NULL literal for a null value.
	 */
	protected function determine_format( mixed $value ): string {
		if ( is_null( $value ) ) {
			return 'NULL';
		}
		if ( is_int( $value ) ) {
			return '%d';
		}
		if ( is_float( $value ) ) {
			return '%f';
		}
		return '%s';
	}

	/**
	 * Accept a caller supplied placeholder, or infer one from the value.
	 *
	 * @param string|null $format         Requested placeholder, or null to infer one.
	 * @param mixed       $fallback_value Value inspected when the placeholder is absent or not allowed.
	 * @return '%d'|'%f'|'%s'|'NULL' Placeholder to use.
	 */
	private function get_validated_format( ?string $format, mixed $fallback_value ): string {
		$allowed_formats = array( '%s', '%d', '%f', 'NULL' );

		if ( null === $format ) {
			return $this->determine_format( $fallback_value );
		}

		if ( in_array( $format, $allowed_formats, true ) ) {
			return $format;
		}

		return $this->determine_format( $fallback_value );
	}
}
