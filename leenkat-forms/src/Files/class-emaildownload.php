<?php
/**
 * Signed links for file downloads included in notifications.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Files;

/** Email bearer links: one file, seven days; fragments keep credentials out of request URLs. */
final class EmailDownload {
	public const TTL = 604800;
	/**
	 * Register the email download action for authenticated and public visitors.
	 */
	public static function hooks(): void {
		add_action( 'admin_post_leenfo_email_file', array( self::class, 'serve' ) );
		add_action( 'admin_post_nopriv_leenfo_email_file', array( self::class, 'serve' ) );
	}
	/**
	 * Encode signed-link data using unpadded base64url.
	 *
	 * @param string $raw Raw for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function encode( string $raw ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Existing email-link and CSP nonce formats require textual base64 representation of the binary payload.
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );}
	/**
	 * Decode canonical base64url used in signed links.
	 *
	 * @param string $raw Raw for this operation.
	 * @return string|false Result for the requested operation.
	 */
	private static function decode( string $raw ): string|false {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/D', $raw ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Existing email-link and CSP nonce formats require textual base64 representation of the binary payload.
		$decoded = base64_decode( strtr( $raw, '-_', '+/' ), true );
		return is_string( $decoded ) && self::encode( $decoded ) === $raw ? $decoded : false;
	}
	/**
	 * Sign the link payload using the authentication salt.
	 *
	 * @param string $body Message or response body.
	 * @return string Result for the requested operation.
	 */
	private static function signature( string $body ): string {
		return hash_hmac( 'sha256', 'lkf-email-download-v1|' . $body, wp_salt( 'auth' ), true );}
	/**
	 * Build the canonical email download endpoint URL.
	 *
	 * @return string Result for the requested operation.
	 */
	private static function endpoint(): string {
		// Cron/CLI workers may not have an HTTPS request context on an HTTPS site.
		$https = is_ssl() || 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) || 'https' === wp_parse_url( (string) get_option( 'siteurl', '' ), PHP_URL_SCHEME );
		return add_query_arg( 'action', 'leenfo_email_file', admin_url( 'admin-post.php', $https ? 'https' : 'admin' ) );
	}
	/**
	 * Create a time-limited download URL for an eligible attachment.
	 *
	 * @param int   $entryId Entry identifier.
	 * @param array $file    File data for this operation.
	 * @return string|null Result for the requested operation.
	 */
	public static function url( int $entryId, array $file ): ?string { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId.
		$entry_id = $entryId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Bind the existing PHP named argument to a local variable.
		global $wpdb;
		$id    = (int) ( $file['file_id'] ?? 0 );
		$field = $file['field_id'] ?? '';
		if ( $entry_id < 1 || $id < 1 || ! is_string( $field ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT f.* FROM {$wpdb->prefix}leenfo_entry_files f INNER JOIN {$wpdb->prefix}leenfo_entries e ON e.id=f.entry_id WHERE f.id=%d AND f.entry_id=%d AND f.field_id=%s AND e.status='complete'", $id, $entry_id, $field ), ARRAY_A );
		if ( ! $row || ! in_array( $row['status'], array( 'private', 'media' ), true ) ) {
			return null;
		}
		$now   = time();
		$body  = self::encode(
			wp_json_encode(
				array(
					'f' => $id,
					'e' => $entry_id,
					'h' => $row['sha256'],
					'i' => $now,
					'x' => $now + self::TTL,
					'n' => bin2hex( random_bytes( 32 ) ),
				)
			)
		);
		$token = 'v1.' . $body . '.' . self::encode( self::signature( $body ) );
		return self::endpoint() . '#token=' . $token;
	}
	/**
	 * Stateless verification does not create options, credentials, logs or database rows.
	 *
	 * @param string   $token Token supplied for validation or encoding.
	 * @param int|null $now   Now for this operation.
	 */
	public static function claims( string $token, ?int $now = null ): ?array {
		if ( strlen( $token ) > 768 ) {
			return null;
		}$pieces = explode( '.', $token );
		if ( 3 !== count( $pieces ) || 'v1' !== $pieces[0] ) {
			return null;
		}
		$raw       = self::decode( $pieces[1] );
		$signature = self::decode( $pieces[2] );
		if ( ! is_string( $raw ) || ! is_string( $signature ) || 32 !== strlen( $signature ) || ! hash_equals( self::signature( $pieces[1] ), $signature ) ) {
			return null;
		}
		$c     = json_decode( $raw, true );
		$now ??= time();
		if ( ! is_array( $c ) || array_keys( $c ) !== array( 'f', 'e', 'h', 'i', 'x', 'n' ) ) {
			return null;
		}
		foreach ( array( 'f', 'e', 'i', 'x' ) as $key ) {
			if ( ! is_int( $c[ $key ] ) || $c[ $key ] < 1 ) {
				return null;
			}
		}
		if ( ! is_string( $c['h'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $c['h'] ) || ! is_string( $c['n'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $c['n'] ) ) {
			return null;
		}
		if ( $c['i'] > $now + 30 || self::TTL !== $c['x'] - $c['i'] || $c['x'] <= $now ) {
			return null;
		}
		return $c;
	}
	/**
	 * Resolve the live file record for verified link claims.
	 *
	 * @param array $claims Claims for this operation.
	 * @return array|null Result for the requested operation.
	 */
	public static function record( array $claims ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT f.* FROM {$wpdb->prefix}leenfo_entry_files f INNER JOIN {$wpdb->prefix}leenfo_entries e ON e.id=f.entry_id WHERE f.id=%d AND f.entry_id=%d AND e.status='complete'", $claims['f'], $claims['e'] ), ARRAY_A );
		return $row && in_array( $row['status'], array( 'private', 'media' ), true ) && hash_equals( $row['sha256'], $claims['h'] ) ? $row : null;
	}
	/**
	 * Send private-response and browser-hardening headers.
	 */
	private static function headers(): void {
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
	}
	/**
	 * Terminate an invalid, expired or unavailable download request.
	 */
	private static function unavailable(): never {
		self::headers();
		header( "Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'" );
		wp_die( esc_html__( 'This download link is invalid, expired or no longer available.', 'leenkat-forms' ), '', array( 'response' => 404 ) );
	}
	/**
	 * Verify the signed email download request or show its landing page.
	 */
	public static function serve(): never {
		self::headers();
		$method = is_string( $_SERVER['REQUEST_METHOD'] ?? null ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( 'POST' === $method ) {
			// Credentials are accepted only in a bounded POST body, never query parameters.
			$length = is_string( $_SERVER['CONTENT_LENGTH'] ?? null ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : '0';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A query-string token causes rejection only. Public email downloads authorize the requested object with the signed POST claim below.
			if ( isset( $_GET['token'] ) || ! ctype_digit( $length ) || (int) $length > 2048 ) {
				self::unavailable();
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public receipt/download access uses validated scoped bearer claims or an entry-specific capability/nonce gate; a generic logged-in WordPress nonce is not the public credential. Receipt/download credential is strictly parsed, scoped and cryptographically verified by claims()/ticket(); sanitizing opaque credentials would change their identity.
			$token  = is_string( $_POST['token'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			$claims = self::claims( $token );
			if ( ! $claims || ! self::record( $claims ) ) {
				self::unavailable();
			}
			$lease = null;
			try {
				$lease = FileLease::acquire( $claims['e'] );
				$row   = self::record( $claims );
				$path  = $row ? FileRecords::resolve( $row ) : null;
			} catch ( \Throwable ) {
				FileLease::release( $lease );
				self::unavailable();}
			if ( ! $path ) {
				FileLease::release( $lease );
				self::unavailable();}
			Download::stream( $path, $row['original_name'], $lease );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public receipt/download access uses validated scoped bearer claims or an entry-specific capability/nonce gate; a generic logged-in WordPress nonce is not the public credential.
		if ( 'GET' !== $method || isset( $_GET['token'] ) ) {
			self::unavailable();
		}
		// Minimal standalone landing: no theme, analytics, external assets or credential-bearing URLs.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Existing email-link and CSP nonce formats require textual base64 representation of the binary payload.
		$nonce = base64_encode( random_bytes( 24 ) );
		header( "Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
		header( 'Content-Type: text/html; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core get_language_attributes returns escaped language/direction attributes; the remaining dynamic document values are escaped in their output contexts.
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta name="referrer" content="no-referrer"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__( 'Download attachment', 'leenkat-forms' ) . '</title></head><body><main><h1>' . esc_html__( 'Download attachment', 'leenkat-forms' ) . '</h1><p id="message">' . esc_html__( 'This secure email link expires seven days after it was created. Anyone with the link can download this attachment until it expires.', 'leenkat-forms' ) . '</p><form method="post" action="' . esc_url( self::endpoint() ) . '"><input type="hidden" name="token" id="token"><button id="download" disabled>' . esc_html__( 'Download file', 'leenkat-forms' ) . '</button></form><noscript>' . esc_html__( 'Enable JavaScript to open this secure email link.', 'leenkat-forms' ) . '</noscript></main>';
		self::print_landing_script( $nonce );
		echo '</body></html>';
		exit;
	}

	/**
	 * Output the authorized script that submits a fragment credential by POST.
	 *
	 * @param string $nonce Nonce for this operation.
	 */
	private static function print_landing_script( string $nonce ): void {
		$handle = 'leenkat-forms-email-download';
		wp_register_script( $handle, false, array(), LEENKAT_FORMS_VERSION, true );
		wp_enqueue_script( $handle );
		wp_add_inline_script(
			$handle,
			<<<'JS'
(()=>{const fragment=location.hash;history.replaceState(null,"",location.pathname+location.search);const token=fragment.startsWith("#token=")?fragment.slice(7):"";if(/^v1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(token)&&token.length<=768){document.getElementById("token").value=token;document.getElementById("download").disabled=false;}})();
JS
		);
		$attributes = static function ( array $attributes ) use ( $nonce, $handle ): array {
			if ( ( $attributes['id'] ?? '' ) === $handle . '-js-after' ) {
				$attributes['nonce'] = $nonce; }
			return $attributes;
		};
		add_filter( 'wp_inline_script_attributes', $attributes );
		try {
			wp_scripts()->do_items( array( $handle ) ); } finally {
			remove_filter( 'wp_inline_script_attributes', $attributes ); }
	}
}
