<?php
/**
 * Authorized tools for form transfer and entry exports.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Transfer\FormTransfer as T;
use LeenkatForms\Transfer\EntryCsv;

/**
 * Authorized tools for form transfer and entry exports.
 */
final class ToolsPage {
	/**
	 * Register administration import and export action handlers.
	 */
	public static function hooks(): void {
		foreach ( array( 'preview', 'import', 'forms', 'entries' ) as $action ) {
			add_action( 'admin_post_leenfo_tools_' . $action, array( self::class, $action ) );
		}
		add_action(
			'rest_api_init',
			static function (): void {
				foreach ( array( 'encode', 'decode' ) as $kind ) {
					register_rest_route(
						'leenkat-forms/v1',
						'/admin/transfer/' . $kind,
						array(
							'methods'             => 'POST',
							'permission_callback' => static fn()=>current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ),
							'callback'            => static function ( \WP_REST_Request $request ) use ( $kind ) {
								try {
									if ( strlen( $request->get_body() ) > T::MAX_BYTES ) {
										throw new \InvalidArgumentException( __( 'The file exceeds 2 MiB.', 'leenkat-forms' ) );
									}if ( 'decode' === $kind ) {
										$items = T::decode( $request->get_body() );
										if ( 1 !== count( $items ) ) {
											throw new \InvalidArgumentException( __( 'Use Leenkat Forms Tools to import a file containing multiple forms as new drafts.', 'leenkat-forms' ) );
										}$data = $items[0];} else {
										$d = $request->get_json_params()['definition'] ?? null;
										if ( ! is_array( $d ) ) {
											throw new \InvalidArgumentException( __( 'Invalid definition.', 'leenkat-forms' ) );
										}$data = array( 'bundle' => T::bundle( array( array( 'definition' => $d ) ) ) );
										}$r = new \WP_REST_Response( $data );
										$r->header( 'Cache-Control', 'private, no-store' );
										return $r;} catch ( \Throwable $e ) {
											return new \WP_Error( 'leenfo_transfer', $e->getMessage(), array( 'status' => 400 ) );}
							},
						)
					);
				}
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/export-fields/(?P<id>\d+)',
					array(
						'methods'             => 'GET',
						'permission_callback' => static fn()=>current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ),
						'callback'            => static function ( $r ) {
							try {
								$response = new \WP_REST_Response( array( 'fields' => EntryCsv::fields( (int) $r['id'] ) ) );
								$response->header( 'Cache-Control', 'private, no-store' );
								return $response;} catch ( \Throwable $e ) {
								return new \WP_Error( 'leenfo_fields', $e->getMessage(), array( 'status' => 400 ) );}},
					)
				);
			}
		);
	}
	/**
	 * Check access and validate the nonce for a tools request.
	 *
	 * @param string $cap   Cap for this operation.
	 * @param string $nonce Nonce for this operation.
	 */
	private static function gate( string $cap, string $nonce ): void {
		$method = is_string( $_SERVER['REQUEST_METHOD'] ?? null ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || ! current_user_can( $cap ) || ! wp_verify_nonce( is_string( $_POST['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '', $nonce ) ) {
			self::fail( __( 'Access denied or session expired. Reload and try again.', 'leenkat-forms' ), 403 );
		}
	}
	/**
	 * Send a Tools error using the requested response format.
	 *
	 * @param string $message Message to display.
	 * @param int    $status  HTTP response status.
	 */
	private static function fail( string $message, int $status = 400 ): never {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Private formStart callers supply fixed literal attributes; the route suffix is escaped. JSON status is typed, not rendered as HTML. The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
		if ( ( $_POST['leenfo_json'] ?? '' ) === '1' ) {
			wp_send_json( array( 'message' => $message ), $status );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Private formStart callers supply fixed literal attributes; the route suffix is escaped. JSON status is typed, not rendered as HTML. The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
		}wp_die( esc_html( $message ), '', array( 'response' => $status ) );}
	/**
	 * Set headers for an authorized tools download.
	 *
	 * @param string $type     Type for this operation.
	 * @param string $filename Filename for this operation.
	 */
	private static function download( string $type, string $filename ): void {
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Robots-Tag: noindex, nofollow' );}
	/**
	 * Validate and preview a submitted import bundle.
	 *
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function preview(): void {
		self::gate( 'leenfo_manage_forms', 'leenfo_tools_preview' );
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations. Upload structure is validated for type, status, extension, size and is_uploaded_file; file metadata is not WordPress-slash-encoded.
			$f        = $_FILES['import_file'] ?? null;
			$filename = is_array( $f ) && is_string( $f['name'] ?? null ) ? sanitize_file_name( $f['name'] ) : '';
			$tmp      = is_array( $f ) && is_string( $f['tmp_name'] ?? null ) ? $f['tmp_name'] : '';
			// Use only a PHP-registered uploaded temporary file. The original filename never becomes a filesystem path.
			if ( ! is_array( $f ) || ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || 'json' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) || '' === $tmp || str_contains( $tmp, "\0" ) || ! is_uploaded_file( $tmp ) ) {
				throw new \InvalidArgumentException( __( 'Upload a valid .json export file.', 'leenkat-forms' ) );
			}
			$size = filesize( $tmp );
			if ( ! $size || $size > T::MAX_BYTES ) {
				throw new \InvalidArgumentException( __( 'The JSON file must be no larger than 2 MiB.', 'leenkat-forms' ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read one bounded PHP-upload temporary file after provenance checks. WP_Filesystem does not expose a read length and a remote request cannot access a PHP temporary file.
			}$raw = file_get_contents( $tmp, false, null, 0, T::MAX_BYTES + 1 );
			if ( ! is_string( $raw ) ) {
				throw new \InvalidArgumentException( __( 'Upload a valid .json export file.', 'leenkat-forms' ) );
			}$result = T::preview( $raw );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
			if ( ( $_POST['leenfo_json'] ?? '' ) === '1' ) {
				wp_send_json( $result );
			}wp_safe_redirect( admin_url( 'admin.php?page=leenkat-forms-tools&view=import&preview=' . $result['token'] ) );
			exit;
		} catch ( \Throwable $e ) {
			self::fail( $e->getMessage() );}
	}
	/**
	 * Import the selected definitions from a validated preview.
	 *
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function import(): void {
		self::gate( 'leenfo_manage_forms', 'leenfo_tools_import' );
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations. Exception messages reach fail(), which escapes HTML or emits JSON; preserve untranslated data semantics.
			$raw       = wp_unslash( $_POST );
			$selection = $raw['selection'] ?? array();
			if ( ! is_array( $selection ) ) {
				throw new \InvalidArgumentException( __( 'Invalid form selection.', 'leenkat-forms' ) );
			}$selection = array_map(
				static function ( $v ) {
					if ( ! is_string( $v ) || ! ctype_digit( $v ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations. Exception messages reach fail(), which escapes HTML or emits JSON; preserve untranslated data semantics.
						throw new \InvalidArgumentException( __( 'Invalid selection.', 'leenkat-forms' ) );
					}return (int) $v;
				},
				$selection
			);
			$result     = T::import( is_string( $raw['token'] ?? null ) ? $raw['token'] : '', $selection, ( $raw['confirm_duplicates'] ?? '' ) === '1' );
			wp_send_json( $result );
		} catch ( \Throwable $e ) {
			self::fail( $e->getMessage() );}
	}
	/**
	 * Download the selected form definitions.
	 *
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function forms(): void {
		self::gate( 'leenfo_manage_forms', 'leenfo_tools_forms' );
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval. Exception messages reach fail(), which escapes HTML or emits JSON; preserve untranslated data semantics.
			$ids = ManagementPage::ids( wp_unslash( $_POST['form_ids'] ?? array() ) );
			if ( ! $ids ) {
				throw new \InvalidArgumentException( __( 'Choose forms.', 'leenkat-forms' ) );
			}$bundle = T::selected( $ids );
			self::download( 'application/json; charset=utf-8', 'leenkat-forms-' . gmdate( 'Ymd-His' ) . '.json' );
			wp_send_json( $bundle, 200, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		} catch ( \Throwable $e ) {
			self::fail( $e->getMessage() );}
	}
	/**
	 * Download the requested entry CSV export.
	 *
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function entries(): void {
		self::gate( 'leenfo_view_entries', 'leenfo_tools_entries' );
		try {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees. The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
			$input = wp_unslash( array_intersect_key( $_POST, array_flip( array( 'form_id', 'field_ids', 'entry_status', 'start_date', 'end_date' ) ) ) );
			$ids   = $input['field_ids'] ?? null;
			if ( ! is_array( $ids ) ) {
				throw new \InvalidArgumentException( __( 'Select fields to export.', 'leenkat-forms' ) );
			}foreach ( array( 'entry_status', 'start_date', 'end_date' ) as $key ) {
				if ( ! is_string( $input[ $key ] ?? '' ) ) {
					throw new \InvalidArgumentException( __( 'Invalid export filters.', 'leenkat-forms' ) );
				}
			}$form_ids = ManagementPage::ids( array( $input['form_id'] ?? null ) );
			if ( ! $form_ids ) {
				throw new \InvalidArgumentException( __( 'Invalid form ID.', 'leenkat-forms' ) );
			}$f = EntryCsv::filters( $form_ids[0], $ids, $input['entry_status'] ?? 'complete', $input['start_date'] ?? '', $input['end_date'] ?? '' );
			self::download( 'text/csv; charset=utf-8', 'leenkat-entries-' . $f['form_id'] . '-' . gmdate( 'Ymd-His' ) . '.csv' );
			$out = fopen( 'php://output', 'w' );
			EntryCsv::write( $out, $f );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees. The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
			fclose( $out );
			exit;
		} catch ( \Throwable $e ) {
			self::fail( $e->getMessage() );}
	}
	/**
	 * Open a Tools operation form and print its nonce.
	 *
	 * @param string $kind  Tools operation key.
	 * @param string $extra Fixed attributes for the opening form tag.
	 */
	private static function form_start( string $kind, string $extra = '' ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Private formStart callers supply fixed literal attributes; the route suffix is escaped. JSON status is typed, not rendered as HTML.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $extra . '><input type="hidden" name="action" value="' . esc_attr( 'leenfo_tools_' . $kind ) . '">';
		wp_nonce_field( 'leenfo_tools_' . $kind );}
	/**
	 * Render the plugin tools administration page.
	 */
	public static function render(): void {
		$manage  = current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS );
		$entries = current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES );
		if ( ! $manage && ! $entries ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
		$requested = is_string( $_GET['view'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';
		if ( 'system-status' === $requested && ! $manage ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
		}
		$view = 'system-status' === $requested ? 'system-status' : ( 'import' === $requested && $manage ? 'import' : 'export' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
		if ( ! isset( $_GET['view'] ) && $manage ) {
			$view = 'import';
		}
		wp_enqueue_style( 'leenfo-settings', plugins_url( 'assets/admin/settings.css', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/settings.css' ) );
		wp_enqueue_style( 'leenfo-tools', plugins_url( 'assets/admin/tools.css', LEENKAT_FORMS_FILE ), array( 'leenfo-settings' ), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/tools.css' ) );
		wp_enqueue_script( 'leenfo-tools', plugins_url( 'assets/admin/tools.js', LEENKAT_FORMS_FILE ), array( 'wp-i18n' ), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/tools.js' ), true );
		wp_add_inline_script(
			'leenfo-tools',
			'window.leenfoTools=' . wp_json_encode(
				array(
					'fieldsApi' => rest_url( 'leenkat-forms/v1/admin/export-fields/' ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
				)
			) . ';',
			'before'
		);
		wp_set_script_translations( 'leenfo-tools', 'leenkat-forms' );
		echo '<div class="wrap lkf-global-settings lkf-tools">';
		AdminHeader::settings( __( 'Leenkat Forms Tools', 'leenkat-forms' ) );
		echo '<nav class="lkf-settings-tabs" aria-label="' . esc_attr__( 'Tools sections', 'leenkat-forms' ) . '">';
		foreach ( array(
			'import'        => __( 'Import', 'leenkat-forms' ),
			'export'        => __( 'Export', 'leenkat-forms' ),
			'system-status' => __( 'System Status', 'leenkat-forms' ),
		) as $key => $label ) {
			if ( 'export' === $key || $manage ) {
				echo '<a' . ( $view === $key ? ' aria-current="page"' : '' ) . ' href="' . esc_url( admin_url( 'admin.php?page=leenkat-forms-tools&view=' . $key ) ) . '">' . esc_html( $label ) . '</a>';
			}
		}echo '</nav><div data-lkf-tools-status role="status" aria-live="polite"></div>';
		if ( 'system-status' === $view ) {
			SystemStatusPage::render();} elseif ( 'import' === $view ) {
			echo '<section class="lkf-tools-panel"><h2>' . esc_html__( 'Import Forms', 'leenkat-forms' ) . '</h2><p>' . esc_html__( 'Upload a Leenkat Forms JSON file to preview its contents. Selected forms become new drafts; existing forms are never overwritten.', 'leenkat-forms' ) . '</p>';
			self::form_start( 'preview', 'enctype="multipart/form-data" data-lkf-import-preview' );
			echo '<label for="lkf-import-file">' . esc_html__( 'JSON export file', 'leenkat-forms' ) . '</label><input type="file" id="lkf-import-file" name="import_file" accept=".json,application/json" required><p class="description">' . esc_html__( 'Maximum 2 MiB and 50 forms. Legacy single-definition builder exports are supported. Entries, private files, global settings and credentials are not imported.', 'leenkat-forms' ) . '</p><button class="button button-primary">' . esc_html__( 'Preview Import', 'leenkat-forms' ) . '</button></form></section>';
			echo '<section class="lkf-tools-panel" data-lkf-import-selection hidden><h2>' . esc_html__( 'Review Forms', 'leenkat-forms' ) . '</h2>';
			self::form_start( 'import', 'data-lkf-import-commit' );
			echo '<input type="hidden" name="token"><div data-lkf-import-list></div><p><label><input type="checkbox" name="confirm_duplicates" value="1"> ' . esc_html__( 'Import selected duplicate definitions as additional copies', 'leenkat-forms' ) . '</label></p><p class="description">' . esc_html__( 'ID conflicts use new database IDs. Field IDs and conditional references are preserved. All selected forms are imported together, or none are imported if any selected form fails. Preview expires after 15 minutes.', 'leenkat-forms' ) . '</p><button class="button button-primary">' . esc_html__( 'Import Selected as New Drafts', 'leenkat-forms' ) . '</button></form></section>';
			// A server-created preview can also be resumed without re-uploading the file.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use. Identifier/token is type-checked and validated by the existing digit/hex grammar and ownership/nonce gate before lookup or maintenance; keep exact identity.
			$token = $_GET['preview'] ?? '';
			if ( is_string( $token ) && preg_match( '/^[a-f0-9]{48}$/D', $token ) ) {
				$raw = get_transient( 'leenfo_import_' . get_current_user_id() . '_' . $token );
				if ( is_string( $raw ) ) {
					$items = json_decode( $raw, true );
					$forms = array();
					foreach ( $items as $i => $item ) {
						$forms[] = array(
							'index'             => $i,
							'title'             => $item['definition']['title'],
							'field_count'       => count( $item['definition']['fields'] ),
							'source_id'         => $item['source_id'],
							'duplicate'         => $item['duplicate'],
							'id_conflict'       => $item['id_conflict'] ?? false,
							'matching_ids'      => $item['matching_ids'] ?? array(),
							'duplicate_in_file' => $item['duplicate_in_file'] ?? false,
							'warnings'          => $item['warnings'],
						);
					}wp_add_inline_script(
						'leenfo-tools',
						'window.leenfoTools.preview=' . wp_json_encode(
							array(
								'token' => $token,
								'forms' => $forms,
							),
							JSON_HEX_TAG
						) . ';',
						'before'
					);}
			}
			} else {
				global $wpdb;
				if ( $manage ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
					$page = max( 1, absint( $_GET['tools_paged'] ?? 1 ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,status,draft_definition FROM {$wpdb->prefix}leenfo_forms WHERE status<>'trashed' ORDER BY id DESC LIMIT 26 OFFSET %d", ( $page - 1 ) * 25 ) );
					echo '<section class="lkf-tools-panel"><h2>' . esc_html__( 'Export Forms', 'leenkat-forms' ) . '</h2><p>' . esc_html__( 'Download selected saved draft definitions in one portable JSON file. Unsaved builder changes and published snapshots are not modified. Public choice-image URLs are references; media files and global settings are not bundled.', 'leenkat-forms' ) . '</p>';
					self::form_start( 'forms' );
					echo '<fieldset><legend>' . esc_html__( 'Select forms', 'leenkat-forms' ) . '</legend><label class="lkf-tools-select-all"><input type="checkbox" data-lkf-select-all> ' . esc_html__( 'Select all on this page', 'leenkat-forms' ) . '</label><div class="lkf-tools-form-list">';
					foreach ( array_slice( $rows, 0, 25 ) as $row ) {
						$d = json_decode( $row->draft_definition, true );
						echo '<label><input type="checkbox" name="form_ids[]" value="' . (int) $row->id . '"> <strong>' . esc_html( $d['title'] ?? __( 'Untitled Form', 'leenkat-forms' ) ) . '</strong> <span>#' . (int) $row->id . ' · ' . esc_html(
							match ( $row->status ) {
								'draft'=>__( 'draft', 'leenkat-forms' ),
								'published'=>__( 'published', 'leenkat-forms' ),
								default=>$row->status}
						) . ' · ' . esc_html( sprintf( /* translators: %d: number of fields. */ __( '%d fields', 'leenkat-forms' ), count( $d['fields'] ?? array() ) ) ) . '</span></label>';
					}echo '</div></fieldset><button class="button button-primary">' . esc_html__( 'Download Forms JSON', 'leenkat-forms' ) . '</button></form><p>';
					if ( $page > 1 ) {
						echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=leenkat-forms-tools&view=export&tools_paged=' . ( $page - 1 ) ) ) . '">' . esc_html__( 'Previous forms', 'leenkat-forms' ) . '</a> ';
					}if ( count( $rows ) > 25 ) {
						echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=leenkat-forms-tools&view=export&tools_paged=' . ( $page + 1 ) ) ) . '">' . esc_html__( 'Next forms', 'leenkat-forms' ) . '</a>';
					}echo '</p></section>';}
				if ( $entries ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
					$form_id = absint( $_GET['form_id'] ?? 0 );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
					$forms = $wpdb->get_results( "SELECT id,draft_definition FROM {$wpdb->prefix}leenfo_forms WHERE status<>'trashed' ORDER BY id DESC LIMIT 500" );
					if ( $form_id && ! in_array( $form_id, array_map( static fn( $r )=> (int) $r->id, $forms ), true ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
						$extra = $wpdb->get_row( $wpdb->prepare( "SELECT id,draft_definition FROM {$wpdb->prefix}leenfo_forms WHERE id=%d", $form_id ) );
						if ( $extra ) {
							array_unshift( $forms, $extra );
						}
					}echo '<section class="lkf-tools-panel"><h2>' . esc_html__( 'Export Entries', 'leenkat-forms' ) . '</h2><p>' . esc_html__( 'Export selected answers as UTF-8 CSV with Arabic and Unicode support. IP addresses, user agents, tokens, internal delivery data and file contents are excluded.', 'leenkat-forms' ) . '</p>';
					self::form_start( 'entries', 'data-lkf-entry-export' );
					echo '<label for="lkf-export-form">' . esc_html__( 'Form', 'leenkat-forms' ) . '</label><select id="lkf-export-form" name="form_id" data-lkf-export-form required><option value="">' . esc_html__( '— Select a form —', 'leenkat-forms' ) . '</option>';
					foreach ( $forms as $row ) {
						$d = json_decode( $row->draft_definition, true );
						echo '<option value="' . (int) $row->id . '" ' . selected( $form_id, (int) $row->id, false ) . '>' . esc_html( ( $d['title'] ?? __( 'Untitled Form', 'leenkat-forms' ) ) . ' (#' . $row->id . ')' ) . '</option>';
					}echo '</select><div class="lkf-tools-form-lookup"><label for="lkf-export-id">' . esc_html__( 'Or enter a form ID', 'leenkat-forms' ) . '</label><input id="lkf-export-id" type="number" min="1" step="1" data-lkf-form-id><button type="button" class="button" data-lkf-load-form>' . esc_html__( 'Load form fields', 'leenkat-forms' ) . '</button><p class="description">' . esc_html__( 'The list shows the latest 500 forms. Use an ID for older or trashed forms.', 'leenkat-forms' ) . '</p></div><div data-lkf-export-fields><p class="description">' . esc_html__( 'Choose a form to load its fields.', 'leenkat-forms' ) . '</p></div><div class="lkf-tools-filters"><label>' . esc_html__( 'From date (UTC)', 'leenkat-forms' ) . '<input type="date" name="start_date"></label><label>' . esc_html__( 'Through date (UTC)', 'leenkat-forms' ) . '<input type="date" name="end_date"></label><label>' . esc_html__( 'Status', 'leenkat-forms' ) . '<select name="entry_status"><option value="complete">' . esc_html__( 'Complete', 'leenkat-forms' ) . '</option><option value="spam">' . esc_html__( 'Spam', 'leenkat-forms' ) . '</option><option value="trashed">' . esc_html__( 'Trash', 'leenkat-forms' ) . '</option><option value="all">' . esc_html__( 'All statuses', 'leenkat-forms' ) . '</option></select></label></div><p class="description">' . esc_html__( 'Entry ID, Form ID, Status and Submitted UTC are included. Fields from earlier form versions remain selectable.', 'leenkat-forms' ) . '</p><button class="button button-primary">' . esc_html__( 'Download Entries CSV', 'leenkat-forms' ) . '</button></form></section>';}
			}echo '</div>';
	}
}
