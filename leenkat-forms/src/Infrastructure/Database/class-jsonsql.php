<?php
/**
 * Database-specific extraction of JSON values used by administration filters.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Infrastructure\Database;

/** Preserve MySQL JSON scalar semantics without unavailable SQLite functions. */
final class JsonSql {

	/**
	 * Build a prepared JSON text expression for a fixed plugin column.
	 *
	 * @param string $column Internal column identifier, optionally qualified.
	 * @param string $path JSON path supplied as a prepared value.
	 * @return string Safe SQL expression.
	 * @throws \InvalidArgumentException When the internal identifier is invalid.
	 */
	public static function text( string $column, string $path ): string {
		if ( ! preg_match( '/^[a-z_]+(?:\.[a-z_]+)?$/D', $column ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Invalid JSON column.', 'leenkat-forms' ) );
		}
		global $wpdb;
		$quoted = $wpdb->prepare( '%s', $path );
		if ( MigrationLock::sqlite() ) {
			return "CASE JSON_TYPE({$column},{$quoted}) WHEN 'true' THEN 'true' WHEN 'false' THEN 'false' WHEN 'null' THEN 'null' ELSE CAST(JSON_EXTRACT({$column},{$quoted}) AS CHAR) END";
		}
		return "JSON_UNQUOTE(JSON_EXTRACT({$column},{$quoted}))";
	}
}
