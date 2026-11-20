<?php
/**
 * Path validation, storage and cleanup of protected uploads.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Files;

/** New encrypted objects and read-compatible private objects from the submitted release. */
final class PrivateStorage {
	/**
	 * Check whether an absolute path is contained within the specified root.
	 *
	 * @param string $path Filesystem path to check.
	 * @param string $base Base for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function inside( string $path, string $base ): bool {
		$path = strtolower( str_replace( '\\', '/', rtrim( $path, '/\\' ) ) );
		$base = strtolower( str_replace( '\\', '/', rtrim( $base, '/\\' ) ) );
		return $path === $base || str_starts_with( $path, $base . '/' );
	}
	/**
	 * Check that a file path does not traverse symbolic links.
	 *
	 * @param string $path Filesystem path to check.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function noLinks( string $path ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$normalized = str_replace( '\\', '/', $path );
		$parts      = array();
		foreach ( explode( '/', $normalized ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}if ( '..' === $part ) {
				if ( ! $parts || '..' === end( $parts ) ) {
					return false;
				}array_pop( $parts );
			} else {
				$parts[] = $part;
			}
		}
		$cursor = ( str_starts_with( $normalized, '/' ) ? '/' : '' ) . implode( '/', $parts );
		// Windows readlink resolves ordinary directories too; a changed target reveals a junction.
		while ( '' !== $cursor && dirname( $cursor ) !== $cursor ) {
			if ( is_link( $cursor ) ) {
				return false;
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The path can stop resolving as a link during inspection. The existing false-result handling avoids exposing the private path.
			}$target = @readlink( $cursor );
			if ( is_string( $target ) && strtolower( str_replace( '\\', '/', rtrim( $target, '/\\' ) ) ) !== strtolower( str_replace( '\\', '/', rtrim( $cursor, '/\\' ) ) ) ) {
				return false;
			}
			$cursor = dirname( $cursor );
		}
		return true;
	}
	/**
	 * Resolve the private upload directory.
	 *
	 * @return string Result for the requested operation.
	 */
	public static function root(): string {
		return EncryptedStorage::root( true );}
	/**
	 * Resolve the current WordPress-native compatibility directory.
	 *
	 * @throws \RuntimeException When WordPress uploads are unavailable.
	 * @return string Result for the requested operation.
	 */
	public static function legacyDefault(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || ! is_string( $uploads['basedir'] ?? null ) ) {
			throw new \RuntimeException( esc_html__( 'Private file storage is unavailable.', 'leenkat-forms' ) );
		}
		return rtrim( wp_normalize_path( $uploads['basedir'] ), '/' ) . '/leenkat-forms/legacy';
	}
	/**
	 * Check that a legacy storage directory is outside known public roots.
	 *
	 * @param string $path   Filesystem path to check.
	 * @param bool   $legacy Legacy for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function outsidePublicRoots( string $path, bool $legacy = false ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$site    = rtrim( ABSPATH, '/\\' );
		$uploads = wp_upload_dir( null, false );
		// WordPress slash-encodes $_SERVER. Restore the path once before canonical filesystem validation.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve the unslashed path separators, reject control characters, resolve with realpath(), then compare canonical filesystem roots below. Text sanitizers can silently change valid directory names.
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : '';
		if ( preg_match( '/[\x00-\x1f\x7f]/', $document_root ) ) {
			return false;
		}
		$roots = array( $site, $uploads['basedir'] ?? '', $document_root );
		if ( $legacy ) {
			$roots[] = dirname( $site );
		}
		foreach ( $roots as $web ) {
			$base = '' !== $web ? realpath( $web ) : false;
			if ( $base && ( self::inside( $path, $base ) || ( $legacy && self::inside( $base, $path ) ) ) ) {
				return false;
			}
		}
		return self::noLinks( $path );
	}
	/**
	 * Resolve the verified root for legacy private upload files.
	 *
	 * @return string Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function legacy_source_root(): string {
		$root = defined( 'LEENKAT_FORMS_LEGACY_STORAGE_DIR' ) ? (string) LEENKAT_FORMS_LEGACY_STORAGE_DIR : ( defined( 'LKF_PRIVATE_STORAGE_DIR' ) ? (string) LKF_PRIVATE_STORAGE_DIR : ( get_option( 'leenfo_legacy_private_source', '' ) ? get_option( 'leenfo_legacy_private_source', '' ) : self::historical_source() ) );
		if ( ! preg_match( '~^(?:[A-Za-z]:[/\\\\]|/)~', $root ) || ! self::noLinks( $root ) ) {
			throw new \RuntimeException( esc_html__( 'Private file storage is unavailable.', 'leenkat-forms' ) );
		}
		$normal = wp_normalize_path( $root );
		if ( preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', $normal ) || str_starts_with( $normal, '//' ) ) {
			throw new \RuntimeException( esc_html__( 'Unsafe private storage path.', 'leenkat-forms' ) );
		}
		$real = realpath( $root );
		if ( false === $real && ! file_exists( $root ) && ! is_link( $root ) ) {
			return rtrim( $normal, '/' );
		}
		if ( ! $real || ! is_dir( $real ) || ! self::outsidePublicRoots( $real, true ) ) {
			throw new \RuntimeException( esc_html__( 'Private file storage must be outside the web roots.', 'leenkat-forms' ) );
		}
		return wp_normalize_path( $real );
	}
	/**
	 * Locate the read-only historical default for discovery of pre-upgrade files.
	 *
	 * @return string Historical path. No creation or new writes are permitted here.
	 */
	private static function historical_source(): string {
		$site = rtrim( ABSPATH, '/\\\\' );
		return dirname( $site, 2 ) . '/leenkat-private/' . substr( hash( 'sha256', $site . '|' . get_current_blog_id() ), 0, 24 );
	}

	/**
	 * Resolve the current WordPress-native compatibility namespace.
	 *
	 * @return string Current uploads path, never an ABSPATH ancestor.
	 */
	public static function legacyRoot(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the callable interface while making the active namespace WordPress-native.
		return self::legacyDefault();
	}

	/**
	 * Derive an encrypted mirror identity without changing persisted legacy link claims.
	 *
	 * @param string $key Validated historical storage key.
	 * @return string Encrypted compatibility object key.
	 */
	public static function legacy_alias( string $key ): string {
		return 'p2-' . hash( 'sha256', 'leenkat-forms-legacy-mirror|' . $key ) . '.lkf';
	}

	/**
	 * Check whether a storage key identifies an encrypted file.
	 *
	 * @param string $key Key for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function encrypted( string $key ): bool {
		return (bool) preg_match( '/^(?:p2-|p3-[1-9][0-9]{0,18}-)[a-f0-9]{64}\.lkf$/D', $key );}
	/**
	 * Validate a key used to locate a private file.
	 *
	 * @param string $key Key for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function validKey( string $key ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return self::encrypted( $key ) || (bool) preg_match( '/^(?:p1-[a-f0-9]{64}\.[a-z0-9]{1,10}|t1-[a-f0-9]{64}\.csv)$/D', $key );}
	/**
	 * Resolve a verified private file from its stored key.
	 *
	 * @param string $key Key for this operation.
	 */
	public static function path( string $key ) {
		if ( ! self::validKey( $key ) ) {
			return null;
		}
		if ( self::encrypted( $key ) ) {
			return EncryptedStorage::open( $key );
		}
		$alias = self::legacy_alias( $key );
		if ( null !== EncryptedStorage::ciphertext( $alias ) ) {
			return EncryptedStorage::open( $alias );
		}
		try {
			$root = self::legacy_source_root();} catch ( \Throwable ) {
			return null;}
			$path = $root . DIRECTORY_SEPARATOR . $key;
			$real = realpath( $path );
			return $real && self::noLinks( $path ) && self::inside( $real, $root ) && is_file( $real ) && is_readable( $real ) ? $real : null;
	}
	/**
	 * Store a verified private upload and return its storage key.
	 *
	 * @param mixed  $source    Source for this operation.
	 * @param string $extension Extension for this operation.
	 * @param int    $size      Size for this operation.
	 * @param string $hash      Hash for this operation.
	 * @param int    $formId    Form identifier.
	 * @return string Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 * @throws \Throwable Raised when the underlying operation fails.
	 */
	public static function put( $source, string $extension, int $size, string $hash, int $formId = 0 ): string { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		if ( ! preg_match( '/^[a-z0-9]{1,10}$/D', $extension ) || $size < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) || ( ! is_resource( $source ) && ( ! is_string( $source ) || ! self::noLinks( $source ) || ! is_file( $source ) ) ) ) {
			throw new \RuntimeException( esc_html__( 'The file could not be stored securely.', 'leenkat-forms' ) );
		}
		if ( $size > EncryptedStorage::MAX_BYTES ) {
			throw new \RuntimeException( esc_html__( 'The file is too large.', 'leenkat-forms' ) );
		}
		$key    = ( $formId > 0 ? 'p3-' . $formId . '-' : 'p2-' ) . bin2hex( random_bytes( 32 ) ) . '.lkf'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		$intent = EncryptedStorage::objectRoot( $key, true ) . '/' . $key . '.pending';
		// Intent markers contain only a timestamp. Keep them until the database transaction commits.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol. The verified local path can disappear or an exclusive create can lose a race. The existing branch handles false without leaking a private path.
		$marker = @fopen( $intent, 'xb' );
		if ( ! $marker ) {
			throw new \RuntimeException( esc_html__( 'The file could not be stored securely.', 'leenkat-forms' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol.
		$marked = fwrite( $marker, (string) time() ) !== false;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol.
		fclose( $marker );
		try {
			$borrowed = is_resource( $source );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol. The verified local path can disappear or an exclusive create can lose a race. The existing branch handles false without leaking a private path.
			$in = $borrowed ? $source : @fopen( $source, 'rb' );
			if ( ! $marked || ! $in ) {
				throw new \RuntimeException( esc_html__( 'The file could not be stored securely.', 'leenkat-forms' ) );
			}
			try {
				EncryptedStorage::write( $key, $in );
			} finally {
				if ( ! $borrowed ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol.
					fclose( $in );
				}
			}
				$verified = self::verify( $key, $size, $hash );
			if ( ! $verified ) {
				throw new \RuntimeException( esc_html__( 'The file could not be stored securely.', 'leenkat-forms' ) );
			}
				EncryptedStorage::close( $verified );
				return $key;
		} catch ( \Throwable $error ) {
			self::remove( $key );
			throw $error;}
	}
	/**
	 * Verify the expected size and digest of a private upload.
	 *
	 * @param string $key  Key for this operation.
	 * @param int    $size Size for this operation.
	 * @param string $hash Hash for this operation.
	 */
	public static function verify( string $key, int $size, string $hash ) {
		if ( $size < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			return null;
		}
		if ( self::encrypted( $key ) ) {
			return EncryptedStorage::open( $key, $size, $hash );
		}
		$alias = self::legacy_alias( $key );
		if ( null !== EncryptedStorage::ciphertext( $alias ) ) {
			return EncryptedStorage::open( $alias, $size, $hash );
		}
		$path = self::path( $key );
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A checked file can disappear or become unreadable before hashing. Preserve the false result for the existing integrity check without disclosing the local path.
		return $path && filesize( $path ) === $size && hash_equals( $hash, (string) @hash_file( 'sha256', $path ) ) ? $path : null;
	}
	/**
	 * Check whether the stored private file matches its recorded metadata.
	 *
	 * @param string $key  Key for this operation.
	 * @param int    $size Size for this operation.
	 * @param string $hash Hash for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function matches( string $key, int $size, string $hash ): bool {
		$path = self::verify( $key, $size, $hash );
		if ( ! $path ) {
			return false;
		}
		self::close( $path );
		return true;
	}
	/**
	 * Resolve the storage directory for the specified private object.
	 *
	 * @param string $key Key for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function object_root( string $key ): string {
		return self::encrypted( $key ) ? EncryptedStorage::objectRoot( $key ) : self::legacy_source_root();}
	/**
	 * Mark a private upload as awaiting its entry transaction.
	 *
	 * @param string $key Key for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function pending( string $key ): bool {
		if ( ! self::validKey( $key ) ) {
			return false;
		}
		try {
			$marker = self::object_root( $key ) . '/' . $key . '.pending';} catch ( \Throwable ) {
			return false;}
			return self::noLinks( $marker ) && is_file( $marker );
	}
	/**
	 * Finalize the retention of a private upload.
	 *
	 * @param string $key Key for this operation.
	 */
	public static function keep( string $key ): void {
		if ( ! self::validKey( $key ) ) {
			return;
		}
		$intent = self::object_root( $key ) . '/' . $key . '.pending';
		if ( self::noLinks( $intent ) && is_file( $intent ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol. The verified path may disappear between validation and cleanup. Preserve the existing boolean or best-effort result without disclosing the path.
			@unlink( $intent );
		}
	}
	/**
	 * Remove an authorized private object.
	 *
	 * @param string $key Key for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function remove( string $key ): bool {
		if ( ! self::validKey( $key ) ) {
			return false;
		}
		$candidate = self::object_root( $key ) . '/' . $key;
		if ( ! self::noLinks( $candidate ) || ! self::noLinks( $candidate . '.pending' ) || ! self::noLinks( $candidate . '.partial' ) ) {
			return false;
		}
		if ( ! self::encrypted( $key ) ) {
			$alias = self::legacy_alias( $key );
			if ( EncryptedStorage::ciphertext( $alias ) && ! self::remove( $alias ) ) {
				return false;
			}
		}
		$path = self::encrypted( $key ) ? EncryptedStorage::ciphertext( $key ) : ( is_file( $candidate ) ? $candidate : null );
		if ( ! $path && file_exists( $candidate ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- The opaque object intent must be created exclusively and cleanup must be synchronous in the verified local directory; WP_Filesystem cannot preserve this protocol. The verified path may disappear between validation and cleanup. Preserve the existing boolean or best-effort result without disclosing the path.
		if ( $path && ! @unlink( $path ) ) {
			return false;
		}
		if ( file_exists( $candidate . '.partial' ) && ! wp_delete_file_from_directory( $candidate . '.partial', dirname( $candidate ) ) ) {
			return false;
		}
		return ! file_exists( $candidate . '.pending' ) || wp_delete_file_from_directory( $candidate . '.pending', dirname( $candidate ) );
	}
	/**
	 * Release a resource returned by private storage.
	 *
	 * @param mixed $path Filesystem path to check.
	 */
	public static function close( $path ): void {
		EncryptedStorage::close( $path );}
	/**
	 * Clean up expired pending private uploads.
	 */
	public static function reap(): void {
		global $wpdb;
		$roots = array();
		foreach ( array( static fn()=>EncryptedStorage::root(), array( self::class, 'legacy_source_root' ) ) as $resolve ) {
			try {
				$root = $resolve();
				if ( is_dir( $root ) ) {
					$roots[] = $root;
				}
			} catch ( \Throwable ) {
				// An unavailable legacy root contributes no files to this scan.
				continue;
			}
		}
		try {
			$roots = array_merge( $roots, array_values( EncryptedStorage::formRoots() ) );
		} catch ( \Throwable ) {
			update_option( 'leenfo_file_cleanup_attention', true, false );
		}
		foreach ( $roots as $root ) {
			foreach ( new \DirectoryIterator( $root ) as $file ) {
				if ( ! $file->isFile() || $file->isLink() || ! str_ends_with( $file->getFilename(), '.pending' ) ) {
					continue;
				}$key = substr( $file->getFilename(), 0, -8 );
				if ( ! self::validKey( $key ) || self::object_root( $key ) !== $root || $file->getMTime() > time() - DAY_IN_SECONDS ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$refs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}leenfo_entry_files WHERE storage_key=%s", $key ) );
				if ( $wpdb->last_error ) {
					update_option( 'leenfo_file_cleanup_attention', true, false );
					continue;}try {
					if ( $refs || AttachmentMigration::references( $key ) ) {
						self::keep( $key );
						continue;
					}
					} catch ( \Throwable ) {
						update_option( 'leenfo_file_cleanup_attention', true, false );
						continue;
					}
					if ( ! self::remove( $key ) ) {
						update_option( 'leenfo_file_cleanup_attention', true, false );
					}
			}
		}
	}
}
