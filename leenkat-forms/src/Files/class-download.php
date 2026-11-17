<?php
/**
 * Authenticated administration downloads for private entry attachments.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Files;

/**
 * Authenticated administration downloads for private entry attachments.
 */
final class Download {
	/**
	 * Register current download hooks and legacy request routing.
	 */
	public static function hooks(): void {
		add_action( 'admin_post_leenfo_download_file', array( self::class, 'serve' ) );
		add_action( 'admin_post_nopriv_leenfo_download_file', array( self::class, 'unavailable' ) );
		add_action( 'admin_init', array( self::class, 'legacyRequest' ) );
	}

	/** Keep previously issued URLs working without registering the old global actions. */
	public static function legacyRequest(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		global $pagenow;
		if ( 'admin-post.php' !== $pagenow ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only action routing for previously issued links. The dispatch verifies the original entry/file nonce, and EmailDownload verifies a signed bearer token before opening any file.
		$action = is_string( $_GET['action'] ?? null ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( 'lkf_download_file' === $action ) {
			self::dispatch( true );
		}
		if ( 'lkf_email_file' === $action ) {
			EmailDownload::serve();
		}
	}
	/**
	 * Terminate the request with a file-unavailable response.
	 */
	public static function unavailable(): never {
		nocache_headers();
		wp_die( esc_html__( 'File unavailable.', 'leenkat-forms' ), '', array( 'response' => 404 ) );
	}
	/**
	 * Check whether the current user may access the specified entry.
	 *
	 * @param int $entryId Entry identifier.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function allowed( int $entryId ): bool { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
		if ( ! is_user_logged_in() || ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Preserve the existing PHP named parameter $entryId and its method binding.
		$entry = $wpdb->get_row( $wpdb->prepare( "SELECT id,form_id,status FROM {$wpdb->prefix}leenfo_entries WHERE id=%d", $entryId ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing leenfo_-prefixed public hook is retained for backward compatibility; no unprefixed hook is introduced.
		return $entry && 'trashed' !== $entry->status && (bool) apply_filters( 'leenfo_can_access_entry_file', true, $entry, get_current_user_id() );
	}
	/**
	 * Build a nonce-protected URL for a registered file.
	 *
	 * @param int $fileId File record identifier.
	 * @return string Result for the requested operation.
	 */
	public static function url( int $fileId ): string { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fileId and its method binding.
		return add_query_arg(
			array(
				'action'   => 'leenfo_download_file',
				'file_id'  => $fileId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fileId and its method binding.
				'_wpnonce' => wp_create_nonce( 'leenfo_file_' . $fileId ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fileId and its method binding.
			),
			admin_url( 'admin-post.php' )
		);}
	/**
	 * Build the administrator URL for a legacy entry attachment.
	 *
	 * @param int $entryId Entry identifier.
	 * @param int $index   Index for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function legacyUrl( int $entryId, int $index ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing callable method name for PHP callers and callback compatibility. Preserve the existing PHP named parameter $entryId and its method binding.
		return add_query_arg(
			array(
				'action'       => 'leenfo_download_file',
				'entry_id'     => $entryId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
				'legacy_index' => $index,
				'_wpnonce'     => wp_create_nonce( 'leenfo_legacy_file_' . $entryId . '_' . $index ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
			),
			admin_url( 'admin-post.php' )
		);}
	/**
	 * Route a request for a registered private file.
	 */
	public static function serve(): never {
		self::dispatch( false );
	}

	/**
	 * Validate and handle the requested private file download.
	 *
	 * @param bool $legacy Legacy for this operation.
	 */
	private static function dispatch( bool $legacy ): never {
		$prefix = $legacy ? 'lkf_' : 'leenfo_';
		if ( ! is_user_logged_in() || ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			self::unavailable();
		}
		global $wpdb;
		$nonce = is_string( $_GET['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$id    = is_string( $_GET['file_id'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['file_id'] ) ) : '';
		$path  = null;
		$name  = 'attachment';
		$lease = null;
		if ( is_string( $id ) && ctype_digit( $id ) && strlen( $id ) < 20 && (int) $id > 0 ) {
			if ( ! wp_verify_nonce( $nonce, $prefix . 'file_' . $id ) ) {
				self::unavailable();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}leenfo_entry_files WHERE id=%d", (int) $id ), ARRAY_A );
			if ( ! $row || ! self::allowed( (int) $row['entry_id'] ) ) {
				self::unavailable();
			}
			try {
				$lease = FileLease::acquire( (int) $row['entry_id'], false );
			} catch ( \Throwable ) {
				self::unavailable();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}leenfo_entry_files WHERE id=%d", (int) $id ), ARRAY_A );
			if ( ! $row || ! self::allowed( (int) $row['entry_id'] ) ) {
				self::unavailable();
			}
			$path = FileRecords::resolve( $row );
			$name = $row['original_name'];
		} else {
			$entry = is_string( $_GET['entry_id'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['entry_id'] ) ) : '';
			$index = is_string( $_GET['legacy_index'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['legacy_index'] ) ) : '';
			if ( ! is_string( $entry ) || ! ctype_digit( $entry ) || ! is_string( $index ) || ! ctype_digit( $index ) || strlen( $entry ) > 19 || strlen( $index ) > 3 || ! wp_verify_nonce( $nonce, $prefix . 'legacy_file_' . $entry . '_' . $index ) || ! self::allowed( (int) $entry ) ) {
				self::unavailable();
			}
			try {
				$lease = FileLease::acquire( (int) $entry, false );
			} catch ( \Throwable ) {
				self::unavailable();
			}
			if ( ! self::allowed( (int) $entry ) ) {
				self::unavailable();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$meta = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$wpdb->prefix}leenfo_entries WHERE id=%d", (int) $entry ) ), true );
			$file = $meta['uploads'][ (int) $index ] ?? null;
			if ( ! is_array( $file ) ) {
				self::unavailable();
			}
			$path = FileRecords::legacyPath( $file );
			$name = $file['name'] ?? 'attachment';
		}
		if ( ! $path ) {
			self::unavailable();
		}
		self::stream( $path, (string) $name, $lease );
	}
	/**
	 * Shared verified-file response; callers own authorization and the active lease.
	 *
	 * @param mixed  $path  Filesystem path to check.
	 * @param string $name  Name used to resolve the requested item.
	 * @param mixed  $lease Lease for this operation.
	 */
	public static function stream( $path, string $name, $lease ): never {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees. The verified local path can disappear or an exclusive create can lose a race. The existing branch handles false without leaking a private path.
		$stream = is_resource( $path ) ? $path : @fopen( $path, 'rb' );
		if ( ! $stream ) {
			self::unavailable();
		}
		$stat  = fstat( $stream );
		$name  = sanitize_file_name( str_replace( array( "\r", "\n", "\0" ), '', $name ) );
		$name  = $name ? $name : 'attachment';
		$ascii = preg_replace( '/[^A-Za-z0-9._-]/', '_', $name );
		$ascii = $ascii ? $ascii : 'attachment';
		header( 'Referrer-Policy: no-referrer' );
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: sandbox' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Content-Length: ' . (string) $stat['size'] );
		while ( ! feof( $stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read at most 64 KiB from the authorized binary stream without materializing a plaintext file.
			$chunk = fread( $stream, 65536 );
			if ( false === $chunk ) {
				break;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is an authorized attachment byte stream. Escaping it as HTML would corrupt the file.
			echo $chunk;
			if ( connection_aborted() ) {
				break;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native authorized read stream before releasing the file lease.
		fclose( $stream );
		FileLease::release( $lease );
		exit;
	}
}
