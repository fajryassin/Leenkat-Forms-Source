<?php
/**
 * Administrative actions on stored forms.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Forms\Publisher;
use LeenkatForms\Infrastructure\Database\Transactions;

/**
 * Administrative actions on stored forms.
 */
final class FormManager {
	/**
	 * Register the form management page handlers.
	 */
	public static function hooks(): void {
		add_action( 'admin_post_leenfo_form_action', array( self::class, 'formAction' ) );
		add_action( 'admin_post_leenfo_forms_bulk', array( self::class, 'bulk' ) ); }
	/**
	 * Build the site-specific forms table name.
	 *
	 * @return string Result for the requested operation.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'leenfo_forms'; }
	/**
	 * Render the form management screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
		}
		( new FormsListTable() )->renderPage();
	}
	/**
	 * Route the new form administration screen.
	 */
	public static function renderNew(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) ); }
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		$id = absint( $_GET['form_id'] ?? 0 );
		if ( $id ) {
			global $wpdb;
			$table = self::table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE id=%d", $id ) ) ) {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				wp_die( esc_html__( 'Form not found.', 'leenkat-forms' ), '', array( 'response' => 404 ) ); }
		}
		BuilderUi::render( $id );
	}
	/**
	 * Handle the requested action on an authorized form.
	 */
	public static function formAction(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) ); }
		ManagementPage::requirePost();
		$id = absint( $_POST['form_id'] ?? 0 );
		check_admin_referer( 'leenfo_form_action_' . $id );
		$operation = sanitize_key( (string) ( $_POST['operation'] ?? '' ) );
		$ok        = self::perform( $id, $operation );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'leenkat-forms-all',
					'leenfo_result' => $ok ? 'ok' : 'failed',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
	/**
	 * Shared by the existing single-record route and the new list-table route.
	 *
	 * @param int    $id        Record identifier.
	 * @param string $operation Operation for this operation.
	 */
	public static function perform( int $id, string $operation ): bool {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) ) {
			return false;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$form = $wpdb->get_row( $wpdb->prepare( "SELECT id,status FROM `{$table}` WHERE id=%d", $id ) );
		if ( ! $form ) {
			return false;
		}
		$ok = false;
		if ( 'duplicate' === $operation && 'trashed' !== $form->status ) {
			$request = new \WP_REST_Request( 'POST' );
			$request->set_param( 'id', $id );
			return ! is_wp_error( BuilderApi::duplicate( $request ) ); }
		if ( 'publish' === $operation && 'trashed' !== $form->status ) {
			$ok = Publisher::publish( $id ); } elseif ( 'unpublish' === $operation && 'published' === $form->status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$ok = false !== $wpdb->update(
				$table,
				array(
					'status'         => 'draft',
					'updated_at_gmt' => current_time( 'mysql', true ),
				),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			); } elseif ( 'trash' === $operation && 'trashed' !== $form->status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$ok = false !== $wpdb->update(
					$table,
					array(
						'status'         => 'trashed',
						'updated_at_gmt' => current_time( 'mysql', true ),
					),
					array( 'id' => $id ),
					array( '%s', '%s' ),
					array( '%d' )
				); } elseif ( 'restore' === $operation && 'trashed' === $form->status ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$ok = false !== $wpdb->update(
					$table,
					array(
						'status'         => 'draft',
						'updated_at_gmt' => current_time( 'mysql', true ),
					),
					array( 'id' => $id ),
					array( '%s', '%s' ),
					array( '%d' )
				); } elseif ( 'delete' === $operation && 'trashed' === $form->status ) {
					$versions = $wpdb->prefix . 'leenfo_form_versions';
					$entries  = $wpdb->prefix . 'leenfo_entries';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					if ( Transactions::supported( array( $table, $versions, $entries ) ) && false !== $wpdb->query( 'START TRANSACTION' ) ) {
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						$locked = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM `{$table}` WHERE id=%d FOR UPDATE", $id ) );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$entries}` WHERE form_id=%d", $id ) );
						if ( '' === $wpdb->last_error && $locked && 'trashed' === $locked->status && ! $count ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
							$ok = false !== $wpdb->delete( $versions, array( 'form_id' => $id ), array( '%d' ) ) && false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
						}
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Transaction command is chosen only between fixed COMMIT/ROLLBACK literals.
						$wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
					}
				}
				return $ok;
	}
	/**
	 * Apply the selected bulk action to permitted forms.
	 */
	public static function bulk(): void {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
		}
		ManagementPage::requirePost();
		check_admin_referer( 'leenfo_forms_bulk' );
		ManagementPage::paginationRequest( 'leenkat-forms-all' );
		[$operation, $ids] = ManagementPage::requestOperation();
		$ok                = $ids && in_array( $operation, array( 'publish', 'unpublish', 'duplicate', 'trash', 'restore', 'delete' ), true );
		if ( $ok ) {
			foreach ( $ids as $id ) {
				if ( ! self::perform( $id, $operation ) ) {
					$ok = false;
				}
			}
		}
		$status = ManagementPage::text( $_POST, 'status' );
		wp_safe_redirect(
			ManagementPage::url(
				array(
					'page'          => 'leenkat-forms-all',
					'status'        => in_array( $status, array( 'draft', 'published', 'trashed' ), true ) ? $status : '',
					'leenfo_result' => $ok ? 'ok' : 'failed',
				)
			)
		);
		exit;
	}
}
