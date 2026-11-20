<?php
/**
 * Filter parsing for the admin entry list and export.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

/** Display-only inbox filters. Inputs never change stored entry values. */
final class EntryListFilters {
	/**
	 * Parse the requested entry date bounds.
	 *
	 * @param array $request Incoming WordPress request.
	 * @return array|false Result for the requested operation.
	 */
	public static function dates( array $request ): array|false {
		$start = \LeenkatForms\Security\RequestInput::text( $request, 'date_from' );
		$end   = \LeenkatForms\Security\RequestInput::text( $request, 'date_to' );
		if ( '' === $start && '' === $end ) {
			return array();
		}
		$dates = array();
		foreach ( array( $start, $end ) as $i => $value ) {
			if ( '' === $value ) {
				$dates[ $i ] = null;
				continue;}
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) {
				return false;
			}
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
				return false;
			}
			$dates[ $i ] = $date;
		}
		if ( '' !== $start && '' !== $end && $start > $end ) {
			return false;
		}
		$utc = new \DateTimeZone( 'UTC' );
		return array( $dates[0] ? $dates[0]->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : '', $dates[1] ? $dates[1]->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : '' );
	}
	/**
	 * Parse the search field, operator and value.
	 *
	 * @param array $fields  Fields handled by this operation.
	 * @param array $request Incoming WordPress request.
	 * @return string Result for the requested operation.
	 */
	public static function search( array $fields, array $request ): string {
		global $wpdb;
		$text = mb_substr( \LeenkatForms\Security\RequestInput::text( $request, 's' ), 0, 100 );
		if ( '' === $text ) {
			return '';
		}
		$source   = \LeenkatForms\Security\RequestInput::text( $request, 'search_field' );
		$source   = $source ? $source : 'all';
		$operator = \LeenkatForms\Security\RequestInput::text( $request, 'search_operator' );
		$operator = $operator ? $operator : 'contains';
		if ( ! in_array( $operator, array( 'contains', 'not_contains', 'equals', 'not_equals' ), true ) || ( 'all' !== $source && 'entry_id' !== $source && ! isset( $fields[ $source ] ) ) ) {
			return ' AND 1=0';
		}
		$contains   = in_array( $operator, array( 'contains', 'not_contains' ), true );
		$pattern    = $contains ? '%' . $wpdb->esc_like( $text ) . '%' : $text;
		$comparison = $contains ? 'LIKE' : '=';
		$parts      = array();
		if ( 'entry_id' === $source ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$parts[] = $wpdb->prepare( "CAST(e.id AS CHAR) {$comparison} %s", $pattern );
		} else {
			foreach ( array_slice( 'all' === $source ? array_keys( $fields ) : array( $source ), 0, 300 ) as $id ) {
				// The JSON path is prepared as a value; field IDs can never become SQL syntax.
				$path = '$.' . wp_json_encode( (string) $id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				$value = \LeenkatForms\Infrastructure\Database\JsonSql::text( 'e.payload', $path );
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The JSON helper prepares its path and accepts only validated internal columns; the comparison is allowlisted and the pattern is prepared here.
				$parts[] = $wpdb->prepare( "COALESCE({$value},'') {$comparison} %s", $pattern );
			}
			if ( 'all' === $source && ctype_digit( $text ) ) {
				$parts[] = $wpdb->prepare( 'e.id=%d', (int) $text );
			}
		}
		if ( ! $parts ) {
			return ' AND 1=0';
		}
		return ' AND ' . ( in_array( $operator, array( 'not_contains', 'not_equals' ), true ) ? 'NOT ' : '' ) . '(' . implode( ' OR ', $parts ) . ')';
	}
	/**
	 * Collect the allowed entry filter and sorting values.
	 *
	 * @param array $request Incoming WordPress request.
	 * @return array Result for the requested operation.
	 */
	public static function context( array $request ): array {
		$args = array();foreach ( array( 's', 'search_field', 'search_operator', 'date_from', 'date_to', 'days', 'status', 'flag', 'orderby', 'order' ) as $key ) {
			$value = \LeenkatForms\Security\RequestInput::text( $request, $key );
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}
		return $args;
	}
}
