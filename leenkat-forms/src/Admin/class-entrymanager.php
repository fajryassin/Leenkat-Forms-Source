<?php
/**
 * Administrative entry actions and export routing.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

/**
 * Administrative entry actions and export routing.
 */
final class EntryManager {
	/**
	 * Register entry actions and the export handler.
	 */
	public static function hooks(): void {
		add_action( 'admin_post_leenfo_entry_action', array( self::class, 'action' ) );
		add_action( 'admin_post_leenfo_entries_bulk', array( self::class, 'bulk' ) );
		add_action( 'admin_post_leenfo_export_entries', array( self::class, 'export' ) );
	}
	/**
	 * Render the entry management screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) ); }
		global $wpdb;
		$table = $wpdb->prefix . 'leenfo_entries';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		$form_id = absint( $_GET['form_id'] ?? 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		$entry_id = absint( $_GET['entry_id'] ?? 0 );
		if ( ! $entry_id ) {
			if ( ! $form_id ) {
				( new FormsListTable( true ) )->renderPage();
				return; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}leenfo_forms WHERE id=%d", $form_id ) );
			if ( ! $form ) {
				wp_die( esc_html__( 'Form not found.', 'leenkat-forms' ), '', array( 'response' => 404 ) );
			}
			( new EntriesListTable( $form ) )->renderPage();
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id=%d", $entry_id ) );
		if ( ! $entry || ( $form_id && (int) $entry->form_id !== $form_id ) ) {
			ManagementPage::start( __( 'View Entry', 'leenkat-forms' ) );
			echo '<p>' . esc_html__( 'Entry not found.', 'leenkat-forms' ) . '</p>';
			ManagementPage::end();
			return; }
		EntryView::render( $entry );
	}
	/**
	 * Handle a verified single-entry action.
	 */
	public static function action(): void {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) ); }
		ManagementPage::requirePost();
		$id = absint( $_POST['entry_id'] ?? 0 );
		check_admin_referer( 'leenfo_entry_action_' . $id );
		$operation = sanitize_key( (string) ( $_POST['operation'] ?? '' ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$form_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT form_id FROM {$wpdb->prefix}leenfo_entries WHERE id=%d", $id ) );
		$ok      = $form_id && self::perform( $id, $operation );
		wp_safe_redirect(
			ManagementPage::url(
				array(
					'page'          => 'leenkat-forms-entries',
					'form_id'       => $form_id,
					'leenfo_result' => $ok ? 'ok' : 'failed',
				) + ( ( 'entry' === ManagementPage::text( $_POST, 'return_view' ) && 'delete' !== $operation ) ? array( 'entry_id' => $id ) : array() )
			)
		);
		exit;
	}
	/**
	 * Existing transactional state changes, reused without touching submission/delivery engines.
	 *
	 * @param int    $id        Record identifier.
	 * @param string $operation Operation for this operation.
	 */
	public static function perform( int $id, string $operation ): bool {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			return false;
		}
		if ( 'delete' === $operation ) {
			return ! is_wp_error( \LeenkatForms\Files\EntryDeletion::remove( $id ) );
		}
		global $wpdb;
		$entries = $wpdb->prefix . 'leenfo_entries';
		$files   = $wpdb->prefix . 'leenfo_entry_files';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		if ( ! \LeenkatForms\Infrastructure\Database\Transactions::supported( array( $entries, $wpdb->prefix . 'leenfo_entry_values', $wpdb->prefix . 'leenfo_deliveries', $files ) ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT id,status,meta FROM `{$entries}` WHERE id=%d FOR UPDATE", $id ) );
		if ( ! $entry ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$wpdb->query( 'ROLLBACK' );
			return false; }
		$meta = json_decode( (string) $entry->meta, true );
		if ( ! is_array( $meta ) ) {
			$meta = array(); }
		$status = (string) $entry->status;
		$ok     = true;
		if ( 'mark_read' === $operation ) {
			$meta['is_read'] = true; } elseif ( 'mark_unread' === $operation ) {
			$meta['is_read'] = false; } elseif ( 'star' === $operation ) {
				$meta['is_starred'] = true; } elseif ( 'unstar' === $operation ) {
				$meta['is_starred'] = false; } elseif ( 'spam' === $operation && 'complete' === $status ) {
					$status = 'spam'; } elseif ( 'unspam' === $operation && 'spam' === $status ) {
					$status = 'complete'; } elseif ( 'trash' === $operation && 'trashed' !== $status ) {
						$meta['previous_status'] = $status;
						$status                  = 'trashed'; } elseif ( 'restore' === $operation && 'trashed' === $status ) {
						$status = in_array( ( $meta['previous_status'] ?? '' ), array( 'complete', 'spam' ), true ) ? $meta['previous_status'] : 'complete';
						unset( $meta['previous_status'] ); } else {
							$ok = false; }
						if ( $ok ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
							$ok = $wpdb->update(
								$entries,
								array(
									'status'         => $status,
									'meta'           => wp_json_encode( $meta ),
									'updated_at_gmt' => current_time( 'mysql', true ),
								),
								array( 'id' => $id ),
								array( '%s', '%s', '%s' ),
								array( '%d' )
							) !== false;
						}
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Transaction command is chosen only between fixed COMMIT/ROLLBACK literals.
						$committed = $wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
						return $ok && false !== $committed;
	}
	/**
	 * Handle a verified bulk action on selected entries.
	 */
	public static function bulk(): void {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
		}
		ManagementPage::requirePost();
		$form_id = absint( $_POST['form_id'] ?? 0 );
		check_admin_referer( 'leenfo_entries_bulk_' . $form_id );
		if ( '1' === ManagementPage::text( $_POST, 'leenfo_filter' ) ) {
			wp_safe_redirect(
				ManagementPage::url(
					array(
						'page'    => 'leenkat-forms-entries',
						'form_id' => $form_id,
					) + EntryListFilters::context( $_POST )
				)
			);
			exit; }
		ManagementPage::paginationRequest( 'leenkat-forms-entries', array( 'form_id' => $form_id ) );
		[$operation, $ids] = ManagementPage::requestOperation();
		$ok                = self::bulkAllowed( $form_id, $ids, $operation );
		if ( $ok ) {
			foreach ( $ids as $id ) {
				if ( ! self::perform( $id, $operation ) ) {
					$ok = false;
				}
			}
		}
		$status  = ManagementPage::text( $_POST, 'status' );
		$flag    = ManagementPage::text( $_POST, 'flag' );
		$filters = array_intersect_key( EntryListFilters::context( $_POST ), array_flip( array( 's', 'search_field', 'search_operator', 'date_from', 'date_to', 'orderby', 'order' ) ) );
		wp_safe_redirect(
			ManagementPage::url(
				array(
					'page'          => 'leenkat-forms-entries',
					'form_id'       => $form_id,
					'status'        => in_array( $status, array( 'complete', 'spam', 'trashed', 'all' ), true ) ? $status : 'complete',
					'flag'          => in_array( $flag, array( 'unread', 'starred' ), true ) ? $flag : '',
					'days'          => (int) ManagementPage::text( $_POST, 'days' ),
					'leenfo_result' => $ok ? 'ok' : 'failed',
				) + $filters
			)
		);
		exit;
	}
	/**
	 * Check that the selected entries and requested operation are permitted.
	 *
	 * @param int    $formId    Form identifier.
	 * @param array  $ids       Ids for this operation.
	 * @param string $operation Operation for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function bulkAllowed( int $formId, array $ids, string $operation ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing callable method name for PHP callers and callback compatibility. Preserve the existing PHP named parameter $formId and its method binding.
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) || ! $formId || ! $ids || ManagementPage::ids( $ids ) !== $ids || ! in_array( $operation, array( 'mark_read', 'mark_unread', 'star', 'unstar', 'spam', 'unspam', 'trash', 'restore', 'delete' ), true ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
			return false;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// Preflight the entire selection before any change. Never accept IDs from a different form.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL. Preserve the existing PHP named parameter $formId and its method binding.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}leenfo_entries WHERE form_id=%d AND id IN ({$placeholders})", array_merge( array( $formId ), $ids ) ) );
		return count( $ids ) === $count;
	}
	/**
	 * Route an authorized entry export request.
	 */
	public static function export(): void {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) ); }
		$form_id = absint( $_POST['form_id'] ?? 0 );
		check_admin_referer( 'leenfo_export_entries_' . $form_id );
		if ( ! $form_id ) {
			wp_die( esc_html__( 'Choose a form first.', 'leenkat-forms' ), '', array( 'response' => 400 ) ); }
		global $wpdb;
		$table = $wpdb->prefix . 'leenfo_entries';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id,form_id,form_version_id,status,submitted_at_gmt,payload,meta FROM `{$table}` WHERE form_id=%d ORDER BY id DESC LIMIT 1000", $form_id ) );
		$fields = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$payload = json_decode( (string) $row->payload, true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$definition = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $wpdb->get_var( $wpdb->prepare( "SELECT definition FROM {$wpdb->prefix}leenfo_form_versions WHERE id=%d", $row->form_version_id ) ) );
			$meta       = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $row->meta );
			$payload    = \LeenkatForms\Files\FileRecords::safeValues( $definition, is_array( $payload ) ? $payload : array(), $meta['uploads'] ?? array() );
			foreach ( is_array( $payload ) ? array_keys( $payload ) : array() as $field_id ) {
				$fields[ (string) $field_id ] = true; }
		}
		$field_ids = array_slice( array_keys( $fields ), 0, 300 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="leenkat-entries-' . $form_id . '.csv"' );
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array_merge( array( 'Entry ID', 'Form ID', 'Status', 'Submitted UTC' ), $field_ids ), ',', '"', '' );
		foreach ( $rows ? $rows : array() as $row ) {
			$payload = json_decode( (string) $row->payload, true );
			$values  = array();
			foreach ( $field_ids as $field_id ) {
				$value    = is_array( $payload ) ? ( $payload[ $field_id ] ?? '' ) : '';
				$values[] = self::csvCell( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
			}
			fputcsv( $output, array_merge( array( (string) $row->id, (string) $row->form_id, (string) $row->status, (string) $row->submitted_at_gmt ), $values ), ',', '"', '' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
		fclose( $output );
		exit;
	}
	/**
	 * Encode a stored value for an entry CSV cell.
	 *
	 * @param string $value Value being processed.
	 * @return string Result for the requested operation.
	 */
	public static function csvCell( string $value ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return preg_match( '/^[\p{Z}\x00-\x20\x{FEFF}]*[=+@-]|^[\t\r\n]/u', $value ) ? "'" . $value : $value;
	}
}
