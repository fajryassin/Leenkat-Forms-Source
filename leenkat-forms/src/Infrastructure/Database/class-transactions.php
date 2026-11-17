<?php
/**
 * Database transaction support checks for submission writes.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Infrastructure\Database;

/**
 * Database transaction support checks for submission writes.
 */
final class Transactions {
	/**
	 * Check whether the required tables support transactions.
	 *
	 * @param array $tables Tables for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function supported( array $tables ): bool {
		global $wpdb;
		foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
			if ( ! is_array( $status ) || strtoupper( (string) ( $status['Engine'] ?? '' ) ) !== 'INNODB' ) {
				return false; }
		}
		return true;
	}
}
