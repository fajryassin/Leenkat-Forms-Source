<?php
/**
 * Runtime and database diagnostics for the plugin.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Infrastructure\Diagnostics;

use LeenkatForms\Infrastructure\Database\Migrations;
/**
 * Runtime and database diagnostics for the plugin.
 */
final class Health {
	/**
	 * Read the table indexes used in the health report.
	 *
	 * @return array Result for the requested operation.
	 */
	private static function indexes(): array {
		global $wpdb;
		$required = array(
			'forms'         => array( 'PRIMARY', 'uuid' ),
			'form_versions' => array( 'PRIMARY', 'form_version' ),
			'entries'       => array( 'PRIMARY', 'uuid', 'form_submission', 'form_status_id' ),
			'entry_values'  => array( 'PRIMARY', 'entry_field_row' ),
			'entry_files'   => array( 'PRIMARY', 'entry_id' ),
			'deliveries'    => array( 'PRIMARY', 'idempotency_key', 'status_due' ),
		);
		$result   = array();
		foreach ( $required as $suffix => $names ) {
			$table = $wpdb->prefix . 'leenfo_' . $suffix;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				$result[ $suffix ] = false;
				continue; }
			// Table name is generated from a fixed allowlist, never from user input.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$keys              = $wpdb->get_col( 'SHOW INDEX FROM `' . str_replace( '`', '``', $table ) . '`', 2 );
			$result[ $suffix ] = empty( array_diff( $names, $keys ) );
		}
		return $result;
	}
	/**
	 * Check whether the installed schema is ready for plugin use.
	 *
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	private static function lifecycle_test(): bool {
		$states = \LeenkatForms\Submissions\EntryStatus::all();
		return count( $states ) === count( array_unique( $states ) )
			&& 6 === count( $states )
			&& \LeenkatForms\Submissions\EntryStatus::canTransition( 'draft', 'complete' )
			&& ! \LeenkatForms\Submissions\EntryStatus::canTransition( 'complete', 'draft' )
			&& ! \LeenkatForms\Submissions\EntryStatus::canTransition( 'invalid', 'complete' );
	}
	/**
	 * Collect runtime dependency and capability information.
	 *
	 * @return array Result for the requested operation.
	 */
	private static function runtime(): array {
		global $wpdb;
		return array(
			'PHP 8.3+'          => version_compare( PHP_VERSION, '8.3', '>=' ),
			'JSON extension'    => extension_loaded( 'json' ),
			'OpenSSL or Sodium' => extension_loaded( 'openssl' ) || extension_loaded( 'sodium' ),
			'MySQL connection'  => $wpdb->check_connection( false ),
		);
	}
	/**
	 * Collect foundational installation and persistence checks.
	 *
	 * @return array Result for the requested operation.
	 */
	private static function foundation_audit(): array {
		global $wpdb;
		$admin        = get_role( 'administrator' );
		$editor       = get_role( 'editor' );
		$caps         = \LeenkatForms\Security\Capabilities::all();
		$admin_caps   = null !== $admin && count( array_filter( $caps, static fn( string $cap ): bool => $admin->has_cap( $cap ) ) ) === count( $caps );
		$editor_caps  = null === $editor ? null : ! array_filter( $caps, static fn( string $cap ): bool => $editor->has_cap( $cap ) );
		$lock         = get_option( 'leenfo_migration_lock', false );
		$table_engine = array();
		foreach ( Migrations::tables() as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				$table_engine[ $table ] = 'Missing';
				continue; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$row                    = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
			$table_engine[ $table ] = strtoupper( (string) ( $row['Engine'] ?? 'Unknown' ) );
		}
		return array(
			'Administrator has all Leenkat Forms capabilities' => $admin_caps ? 'Passed' : 'Check required',
			'Editor has no Leenkat Forms capabilities' => null === $editor_caps ? 'Not applicable' : ( $editor_caps ? 'Passed' : 'Check required' ),
			'Migration lock'                           => false === $lock ? 'Not held' : 'Held (review required)',
			'Stored schema matches code'               => (int) get_option( 'leenfo_db_version', 0 ) === Migrations::VERSION ? 'Passed' : 'Check required',
			'Registered Leenkat Forms REST routes'     => self::public_rest_routes(),
			'Database engines'                         => $table_engine,
		);
	}
	/**
	 * Inspect the registered public REST routes.
	 *
	 * @return string Result for the requested operation.
	 */
	private static function public_rest_routes(): string {
		// Inspect the live REST server rather than treating a constant as proof.
		$server = rest_get_server();
		$routes = $server->get_routes();
		$public = array();
		foreach ( $routes as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/leenkat-forms/' ) ) {
				continue; }
			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) ) {
					continue; }
				$public[] = $route;
				break;
			}
		}
		return array() === $public ? 'No routes registered' : 'Review: ' . implode( ', ', array_unique( $public ) );
	}
	/**
	 * Collect the diagnostic snapshot displayed by the plugin.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function snapshot(): array {
		global $wpdb;
		$tables = array();
		foreach ( Migrations::tables() as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$tables[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		}
		$deliveries     = array();
		$delivery_table = $wpdb->prefix . 'leenfo_deliveries';
		if ( ! empty( $tables[ $delivery_table ] ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$rows = $wpdb->get_results( "SELECT status,COUNT(*) AS total FROM `{$delivery_table}` GROUP BY status" );
			foreach ( $rows ? $rows : array() as $row ) {
				$deliveries[ (string) $row->status ] = (int) $row->total; }
		}
		return array(
			'version'                   => LEENKAT_FORMS_VERSION,
			'php'                       => PHP_VERSION,
			'wordpress'                 => get_bloginfo( 'version' ),
			'schema'                    => (int) get_option( 'leenfo_db_version', 0 ),
			'tables'                    => $tables,
			'error'                     => (string) get_option( 'leenfo_migration_error', '' ),
			'contracts'                 => array( 'ModuleInterface', 'FieldTypeInterface', 'IntegrationInterface' ),
			'definition_contract_tests' => \LeenkatForms\Forms\DefinitionContractTests::run(),
			'definition_test_cases'     => \LeenkatForms\Forms\DefinitionContractTests::cases(),
			'lifecycle_test'            => self::lifecycle_test(),
			'runtime'                   => self::runtime(),
			'schema_contract'           => \LeenkatForms\Forms\DefinitionValidator::SCHEMA_VERSION,
			'entry_states'              => count( \LeenkatForms\Submissions\EntryStatus::all() ),
			'public_read_allowed'       => \LeenkatForms\Security\SubmissionPolicy::PUBLIC_READ_ALLOWED,
			'table_indexes'             => self::indexes(),
			'foundation_audit'          => self::foundation_audit(),
			'deliveries'                => $deliveries,
		);
	}
}
