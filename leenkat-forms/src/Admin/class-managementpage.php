<?php
/**
 * Common helpers for administrative lists and action forms.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

/** Shared presentation for the two administration list screens. No entry writes on GET. */
final class ManagementPage {
	/**
	 * Register common management screen hooks.
	 */
	public static function hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'set-screen-option', array( self::class, 'savePerPage' ), 10, 3 );
	}
	/**
	 * Initialize screen options for the current management page.
	 */
	public static function load(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		$entries = 'leenkat-forms-entries' === \LeenkatForms\Security\RequestInput::get( 'page' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		if ( $entries && \LeenkatForms\Security\RequestInput::identifier( 'entry_id' ) ) {
			return;
		}
		$option = $entries ? 'leenfo_entries_per_page' : 'leenfo_forms_per_page';
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Items per page', 'leenkat-forms' ),
				'default' => 20,
				'option'  => $option,
			)
		);
		$screen = get_current_screen();
		if ( $screen ) {
			add_filter(
				'manage_' . $screen->id . '_columns',
				static function () use ( $entries ): array {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
					if ( $entries && \LeenkatForms\Security\RequestInput::identifier( 'entry_id' ) ) {
						return array();
					}
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
					$id = $entries ? \LeenkatForms\Security\RequestInput::identifier( 'form_id' ) : 0;
					if ( $id ) {
						global $wpdb;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
						$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}leenfo_forms WHERE id=%d", $id ) );
						return $form ? ( new EntriesListTable( $form ) )->get_columns() : array();
					}
					return ( new FormsListTable( $entries ) )->get_columns();
				}
			);
			add_filter(
				'default_hidden_columns',
				static function ( array $hidden, $current ) use ( $screen, $entries ): array {
					if ( $current->id !== $screen->id ) {
						return $hidden;
					}
					if ( ! $entries ) {
						return $hidden;
					}
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
					$id = \LeenkatForms\Security\RequestInput::identifier( 'form_id' );
					if ( $id ) {
						global $wpdb;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
						$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}leenfo_forms WHERE id=%d", $id ) );
						if ( $form ) {
							return array_merge( $hidden, ( new EntriesListTable( $form ) )->defaultHiddenColumns() );
						}
					}
					return $hidden;
				},
				10,
				2
			);
		}
	}
	/**
	 * Validate the stored page size setting.
	 *
	 * @param mixed  $status Status of the current record.
	 * @param string $option Option for this operation.
	 * @param mixed  $value  Value being processed.
	 */
	public static function savePerPage( $status, string $option, $value ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! in_array( $option, array( 'leenfo_entries_per_page', 'leenfo_forms_per_page' ), true ) ) {
			return $status;
		}
		if ( ! current_user_can( 'leenfo_forms_per_page' === $option ? 'leenfo_manage_forms' : 'leenfo_view_entries' ) ) {
			return false;
		}
		return max( 1, min( 100, (int) $value ) );
	}
	/**
	 * Load assets for the management screen.
	 */
	public static function assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		if ( ! in_array( \LeenkatForms\Security\RequestInput::get( 'page' ), array( 'leenkat-forms-all', 'leenkat-forms-entries', 'leenkat-forms-file-security' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'leenfo-management', plugins_url( 'assets/admin/management.css', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/management.css' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		if ( 'leenkat-forms-file-security' === \LeenkatForms\Security\RequestInput::get( 'page' ) ) {
			return;
		}
		wp_enqueue_script( 'leenfo-management', plugins_url( 'assets/admin/management.js', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION, true );
		wp_localize_script( 'leenfo-management', 'leenfoManagement', array( 'deleteWarning' => __( 'Permanently delete the selected items? This cannot be undone.', 'leenkat-forms' ) ) );
	}
	/**
	 * Read a scalar text value from request data.
	 *
	 * @param array  $request Incoming WordPress request.
	 * @param string $key     Key for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function text( array $request, string $key ): string {
		return isset( $request[ $key ] ) && is_string( $request[ $key ] ) ? sanitize_text_field( wp_unslash( $request[ $key ] ) ) : '';
	}
	/**
	 * Build an administration URL from the provided query arguments.
	 *
	 * @param array $args Arguments supplied by the caller.
	 * @return string Result for the requested operation.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php' ) ); }
	/**
	 * Format a title for a management screen.
	 *
	 * @param array $definition Form definition being processed.
	 * @return string Result for the requested operation.
	 */
	public static function title( array $definition ): string {
		return is_string( $definition['title'] ?? null ) && '' !== trim( $definition['title'] ) ? $definition['title'] : __( 'Untitled Form', 'leenkat-forms' );
	}
	/**
	 * Format a stored date for the administration interface.
	 *
	 * @param string|null $utc  Utc for this operation.
	 * @param bool        $time Time for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function date( ?string $utc, bool $time = true ): string {
		if ( ! $utc || str_starts_with( $utc, '0000-' ) ) {
			return '—';
		}
		return get_date_from_gmt( $utc, get_option( 'date_format' ) . ( $time ? ' ' . get_option( 'time_format' ) : '' ) );
	}
	/**
	 * Local calendar days, including today; converted to UTC for stored timestamps.
	 *
	 * @param int $days Days for this operation.
	 */
	public static function period( int $days ): array {
		if ( ! in_array( $days, array( 7, 30, 90 ), true ) ) {
			return array();
		}
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$utc   = new \DateTimeZone( 'UTC' );
		return array( $today->modify( '-' . ( $days - 1 ) . ' days' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $today->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}
	/**
	 * Return the supported date range options.
	 *
	 * @return int Result for the requested operation.
	 */
	public static function days(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		$days = (int) \LeenkatForms\Security\RequestInput::get( 'days' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		return in_array( $days, array( 7, 30, 90 ), true ) ? $days : ( '0' === \LeenkatForms\Security\RequestInput::get( 'days' ) ? 0 : 30 );
	}
	/**
	 * Open the management screen wrapper.
	 *
	 * @param string $title        Title for this operation.
	 * @param bool   $add          Add for this operation.
	 * @param string $headingTools Heading tools for this operation.
	 */
	public static function start( string $title, bool $add = false, string $headingTools = '' ): void { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $headingTools and its method binding.
		echo '<div class="wrap lkf-management"><div class="lkf-management-brand"><img src="' . esc_url( plugins_url( 'assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE ) ) . '" width="64" height="64" alt=""><span>' . esc_html__( 'Leenkat Forms', 'leenkat-forms' ) . '</span></div><div class="lkf-management-heading"><h1>' . esc_html( $title ) . '</h1>';
		if ( $add ) {
			echo '<a class="page-title-action" href="' . esc_url( self::url( array( 'page' => 'leenkat-forms-new' ) ) ) . '"><span aria-hidden="true">＋</span> ' . esc_html__( 'Add New', 'leenkat-forms' ) . '</a>';
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Only private internal callers supply constructed, escaped header controls; KSES would remove required controls. Preserve the existing PHP named parameter $headingTools and its method binding.
		echo $headingTools; // Only internal callers provide escaped, constructed header controls.
		echo '</div><div class="lkf-management-content">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		if ( isset( $_GET['leenfo_result'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
			$ok = 'ok' === \LeenkatForms\Security\RequestInput::get( 'leenfo_result' );
			echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $ok ? __( 'Action completed.', 'leenkat-forms' ) : __( 'Some items could not be updated. Forms with entries cannot be permanently deleted.', 'leenkat-forms' ) ) . '</p></div>';
		}
	}
	/**
	 * Close the management screen wrapper.
	 */
	public static function end(): void {
		echo '</div></div>'; }
	/**
	 * Render the date range selector.
	 *
	 * @param int $selected Selected for this operation.
	 */
	public static function periodSelect( int $selected ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		echo '<label class="screen-reader-text" for="lkf-period">' . esc_html__( 'Date range', 'leenkat-forms' ) . '</label><select id="lkf-period" name="days">';
		foreach ( array(
			7  => __( 'Last 7 days', 'leenkat-forms' ),
			30 => __( 'Last 30 days', 'leenkat-forms' ),
			90 => __( 'Last 90 days', 'leenkat-forms' ),
			0  => __( 'All time', 'leenkat-forms' ),
		) as $value => $caption ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $selected, $value, false ) . '>' . esc_html( $caption ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'Filter', 'leenkat-forms' ) . '</button>';
	}
	/**
	 * Render the visible columns control.
	 */
	public static function columnsButton(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		echo '<button type="button" class="button lkf-columns-toggle" aria-label="' . esc_attr__( 'Manage columns', 'leenkat-forms' ) . '" aria-controls="screen-options-wrap" aria-expanded="false"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span></button>';
	}
	/**
	 * Reject malformed or unbounded bulk input; never coerce negative IDs into real records.
	 *
	 * @param mixed $raw Raw for this operation.
	 */
	public static function ids( $raw ): array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || ! $raw || count( $raw ) > 100 ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $id ) {
			if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $id ) || (int) $id < 1 ) {
				return array();
			}
			$ids[] = absint( $id );
		}
		return array_values( array_unique( $ids ) );
	}
	/**
	 * Read the requested bulk operation and selected IDs.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function requestOperation(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
		$row = \LeenkatForms\Security\RequestInput::control( 'leenfo_row_action', true );
		if ( '' !== $row && preg_match( '/^([a-z_]+):([1-9][0-9]{0,17})$/D', $row, $m ) ) {
			return array( $m[1], array( (int) $m[2] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
		$key = 'bottom' === \LeenkatForms\Security\RequestInput::control( 'leenfo_apply', true ) ? 'operation2' : 'operation';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations. Selection is validated element-by-element by ids(); text() unslashes and sanitizes its scalar argument before use.
		return array( \LeenkatForms\Security\RequestInput::control( $key, true ), \LeenkatForms\Security\RequestInput::ids( 'item_ids' ) );
	}
	/**
	 * Return the display label for a management operation.
	 *
	 * @param string $operation Operation for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function operationLabel( string $operation ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$labels = array(
			'publish'     => __( 'Publish', 'leenkat-forms' ),
			'unpublish'   => __( 'Unpublish', 'leenkat-forms' ),
			'duplicate'   => __( 'Duplicate', 'leenkat-forms' ),
			'trash'       => __( 'Move to Trash', 'leenkat-forms' ),
			'restore'     => __( 'Restore', 'leenkat-forms' ),
			'delete'      => __( 'Delete Permanently', 'leenkat-forms' ),
			'mark_read'   => __( 'Mark Read', 'leenkat-forms' ),
			'mark_unread' => __( 'Mark Unread', 'leenkat-forms' ),
			'star'        => __( 'Star', 'leenkat-forms' ),
			'unstar'      => __( 'Unstar', 'leenkat-forms' ),
			'spam'        => __( 'Mark Spam', 'leenkat-forms' ),
			'unspam'      => __( 'Not Spam', 'leenkat-forms' ),
		);
		return $labels[ $operation ] ?? '';
	}
	/**
	 * Reject methods other than POST for a management action.
	 */
	public static function requirePost(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Valid method and length literals contain no slash-sensitive characters. Strict literal comparisons reject malformed metadata before use or mutation.
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			wp_die( esc_html__( 'Use a POST request for this action.', 'leenkat-forms' ), '', array( 'response' => 405 ) );
		}
	}
	/**
	 * Handle the requested management list page.
	 *
	 * @param string $page    Page for this operation.
	 * @param array  $context Context for the requested operation.
	 */
	public static function paginationRequest( string $page, array $context = array() ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
		if ( '' !== \LeenkatForms\Security\RequestInput::control( 'leenfo_row_action', true ) || ! isset( $_POST['paged'] ) || ! is_scalar( $_POST['paged'] ) ) {
			return;
		}
		// Enter in the current-page input may implicitly submit the first Apply button.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
		$key = 'bottom' === \LeenkatForms\Security\RequestInput::control( 'leenfo_apply', true ) ? 'operation2' : 'operation';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
		if ( ! empty( $_POST['item_ids'] ) || ! in_array( \LeenkatForms\Security\RequestInput::post( $key ), array( '', '-1' ), true ) ) {
			return;
		}
		$args = array(
			'page'  => $page,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
			'paged' => max( 1, min( 1000000, \LeenkatForms\Security\RequestInput::identifier( 'paged', true ) ) ),
		);
		foreach ( array( 's', 'orderby', 'order', 'status', 'flag', 'days', 'search_field', 'search_operator', 'date_from', 'date_to' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
			$value = \LeenkatForms\Security\RequestInput::post( $key );
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}
		wp_safe_redirect( self::url( array_merge( $args, $context ) ) );
		exit;
	}
	/**
	 * Build a form control for one row action.
	 *
	 * @param string $operation Operation for this operation.
	 * @param int    $id        Record identifier.
	 * @return string Result for the requested operation.
	 */
	public static function rowButton( string $operation, int $id ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return '<button type="submit" class="button-link' . ( in_array( $operation, array( 'trash', 'delete' ), true ) ? ' submitdelete' : '' ) . '" name="leenfo_row_action" value="' . esc_attr( $operation . ':' . $id ) . '">' . esc_html( self::operationLabel( $operation ) ) . '</button>';
	}
}
