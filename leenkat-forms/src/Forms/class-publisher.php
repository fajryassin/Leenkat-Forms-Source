<?php
/**
 * Publication and retrieval of immutable form revisions.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Publishes immutable snapshots while retaining the editable dev.8 draft column. */
final class Publisher {
	/**
	 * Publish an authorized form definition revision.
	 *
	 * @param int        $formId Form identifier.
	 * @param array|null $errors Collected validation errors.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function publish( int $formId, ?array &$errors = null ): bool { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId.
		$form_id = $formId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Bind the existing PHP named argument to a local variable.
		$errors  = array();
		global $wpdb;
		$forms    = $wpdb->prefix . 'leenfo_forms';
		$versions = $wpdb->prefix . 'leenfo_form_versions';
		if ( ! \LeenkatForms\Infrastructure\Database\Transactions::supported( array( $forms, $versions ) ) ) {
			return false; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false; }
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$forms}` WHERE id = %d FOR UPDATE", $form_id ) );
			if ( ! $form || 'trashed' === $form->status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$wpdb->query( 'ROLLBACK' );
				return false; }
			$definition = json_decode( (string) $form->draft_definition, true );
			if ( ! is_array( $definition ) ) {
				$errors[] = __( 'Invalid form request.', 'leenkat-forms' );
			} else {
				$errors = DefinitionValidator::validate( $definition, true );
				if ( empty( $definition['fields'] ) ) {
					$errors[] = __( 'Could not publish form. Save a valid form with at least one field first.', 'leenkat-forms' );
				}
			}
			if ( array() !== $errors ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			$encoded = DefinitionCodec::encode( $definition );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$number = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(version_no),0) FROM `{$versions}` WHERE form_id = %d", $form_id ) ) + 1;
			$now    = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
			if ( ! $wpdb->insert(
				$versions,
				array(
					'form_id'         => $form_id,
					'version_no'      => $number,
					'schema_version'  => '1',
					'definition'      => $encoded,
					'definition_hash' => hash( 'sha256', $encoded ),
					'created_at_gmt'  => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s' )
			) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			$version_id = (int) $wpdb->insert_id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			if ( $wpdb->update(
				$forms,
				array(
					'published_version_id' => $version_id,
					'status'               => 'published',
					'updated_at_gmt'       => $now,
				),
				array( 'id' => $form_id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			) === false ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false; }
			return true;
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$wpdb->query( 'ROLLBACK' );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failure is logged with a fixed safe message, without entry values, credentials or private paths.
			error_log( __( 'Leenkat Forms publish failed.', 'leenkat-forms' ) );
			return false;
		}
	}

	/**
	 * Load the current published form revision.
	 *
	 * @param int $formId Form identifier.
	 * @return array|null Result for the requested operation.
	 */
	public static function published( int $formId ): ?array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		global $wpdb;
		$forms    = $wpdb->prefix . 'leenfo_forms';
		$versions = $wpdb->prefix . 'leenfo_form_versions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL. Preserve the existing PHP named parameter $formId and its method binding.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT f.id, f.published_version_id, v.definition FROM `{$forms}` f INNER JOIN `{$versions}` v ON v.id = f.published_version_id AND v.form_id = f.id WHERE f.id = %d AND f.status = 'published'", $formId ) );
		if ( ! $row ) {
			return null; }
		$definition = json_decode( (string) $row->definition, true );
		if ( ! is_array( $definition ) || array() !== DefinitionValidator::validate( $definition, true, false ) ) {
			return null; }
		return array(
			'form_id'    => (int) $row->id,
			'version_id' => (int) $row->published_version_id,
			'definition' => $definition,
		);
	}
}
