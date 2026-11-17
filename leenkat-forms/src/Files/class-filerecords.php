<?php
/**
 * Database records and resolution of uploaded entry files.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Files;

/**
 * Database records and resolution of uploaded entry files.
 */
final class FileRecords {
	/**
	 * Register stored file metadata for an entry field.
	 *
	 * @param int   $entryId Entry identifier.
	 * @param array $files   Files for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function register( int $entryId, array &$files ): bool { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
		global $wpdb;
		foreach ( $files as &$file ) {
			$media = ( $file['kind'] ?? '' ) === 'media' && ! empty( $file['attachment_id'] );
			$key   = $media ? 'media:' . (int) $file['attachment_id'] : ( $file['storage_key'] ?? '' );
			if ( ! $media && ! PrivateStorage::validKey( $key ) ) {
				return false;
			}
			$row = array(
				'entry_id'      => $entryId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
				'field_id'      => $file['field_id'],
				'storage_key'   => $key,
				'original_name' => $file['name'],
				'mime_detected' => $file['mime'],
				'byte_size'     => $file['size'],
				'sha256'        => $file['sha256'],
				'status'        => $media ? 'media' : 'private',
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
			if ( ! $wpdb->insert( $wpdb->prefix . 'leenfo_entry_files', $row ) ) {
				return false;
			}$file['file_id'] = (int) $wpdb->insert_id;
			if ( ! $media && str_starts_with( $key, 'p3-' ) ) {
				$file['attachment_id'] = PrivateMedia::register( $file );
				if ( ! $file['attachment_id'] ) {
					return false;
				}
			}
			if ( $media ) {
				delete_post_meta( (int) $file['attachment_id'], '_leenfo_upload_lease' );
				unset( $file['lease'] );}
			if ( ! $media ) {
				unset( $file['path'], $file['url'] );
			}
		}unset( $file );
		return true;
	}
	/**
	 * Finalize file records after the entry transaction commits.
	 *
	 * @param array $files Files for this operation.
	 */
	public static function committed( array $files ): void {
		foreach ( $files as $file ) {
			if ( isset( $file['storage_key'] ) ) {
				try {
					PrivateStorage::keep( $file['storage_key'] );} catch ( \Throwable ) {
					update_option( 'leenfo_file_cleanup_attention', true, false );}
			}
		}}
	/**
	 * Read attachment records owned by an entry.
	 *
	 * @param int $id Entry ID.
	 * @return array Attachment records ordered by ID.
	 */
	public static function forEntry( int $id ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}leenfo_entry_files WHERE entry_id=%d ORDER BY id", $id ), ARRAY_A );
		return $rows ? $rows : array();
	}
	/**
	 * Verify and resolve a file from the legacy upload record.
	 *
	 * @param array $file File data for this operation.
	 * @return string|null Result for the requested operation.
	 */
	public static function legacyPath( array $file ): ?string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$candidate = $file['path'] ?? '';
		if ( ! is_string( $candidate ) || '' === $candidate || ! PrivateStorage::noLinks( $candidate ) ) {
			return null;
		}
		$base = realpath( wp_upload_dir()['basedir'] );
		if ( $base && ! PrivateStorage::inside( $candidate, $base ) && defined( 'LEENKAT_FORMS_LEGACY_UPLOADS_DIR' ) ) {
			$old      = rtrim( wp_normalize_path( (string) LEENKAT_FORMS_LEGACY_UPLOADS_DIR ), '/' );
			$original = wp_normalize_path( $candidate );
			if ( '' !== $old && PrivateStorage::inside( $original, $old ) && strlen( $original ) > strlen( $old ) && ! preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', $original ) ) {
				$candidate = $base . substr( $original, strlen( $old ) );
			}
		}
		$real = realpath( $candidate );
		return $real && $base && PrivateStorage::inside( $real, $base ) && is_file( $real ) && is_readable( $real ) && PrivateStorage::noLinks( $real ) ? $real : null;
	}
	/**
	 * Open or resolve the stored attachment represented by a file record.
	 *
	 * @param array $row Stored record for this operation.
	 */
	public static function resolve( array $row ) {
		if ( ( $row['status'] ?? '' ) === 'private' ) {
			return PrivateStorage::verify( $row['storage_key'], (int) $row['byte_size'], $row['sha256'] );
		}
		if ( ( $row['status'] ?? '' ) !== 'media' || ! preg_match( '/^media:([1-9][0-9]*)$/D', $row['storage_key'] ?? '', $match ) ) {
			return null;
		}
		$path = get_attached_file( (int) $match[1] );
		$real = is_string( $path ) ? self::legacyPath( array( 'path' => $path ) ) : null;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A checked file can disappear or become unreadable before hashing. Preserve the false result for the existing integrity check without disclosing the local path.
		return $real && filesize( $real ) === (int) $row['byte_size'] && hash_equals( $row['sha256'], (string) @hash_file( 'sha256', $real ) ) ? $real : null;
	}
	/**
	 * Create attachment values suitable for storage in entry data.
	 *
	 * @param array $definition Form definition being processed.
	 * @param array $values     Values from the current submission.
	 * @param array $uploads    Uploads for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function safeValues( array $definition, array $values, array $uploads = array() ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		foreach ( $definition['fields'] ?? array() as $field ) {
			if ( ( $field['type'] ?? '' ) !== 'file_upload' || ! empty( $field['settings']['media_library'] ) ) {
				continue;
			}$id = $field['id'];
			if ( ! isset( $values[ $id ] ) ) {
				continue;
			}$names = array();
			foreach ( $uploads as $file ) {
				if ( ( $file['field_id'] ?? '' ) === $id && is_string( $file['name'] ?? null ) ) {
					$names[] = sanitize_file_name( $file['name'] );
				}
			}
			if ( ! $names ) {
				foreach ( explode( "\n", is_scalar( $values[ $id ] ) ? (string) $values[ $id ] : '' ) as $line ) {
					$line = preg_replace( '~:\s*https?://.*$~i', '', $line );
					$line = sanitize_file_name( wp_basename( str_replace( '\\', '/', $line ) ) );
					if ( '' !== $line ) {
						$names[] = $line;
					}
				}
			}
			$values[ $id ] = implode( "\n", $names );
		}return $values;
	}
	/**
	 * Backward-compatible reads only: metadata comes from the associated entry, never a request path.
	 *
	 * @param int $entryId Entry identifier.
	 */
	public static function notificationFiles( int $entryId ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing callable method name for PHP callers and callback compatibility. Preserve the existing PHP named parameter $entryId and its method binding.
		global $wpdb;
		$rows  = self::forEntry( $entryId ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
		$files = array_map(
			static fn( $r )=>array(
				'file_id'  => (int) $r['id'],
				'field_id' => $r['field_id'],
				'name'     => $r['original_name'],
			),
			$rows
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Preserve the existing PHP named parameter $entryId and its method binding.
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$wpdb->prefix}leenfo_entries WHERE id=%d", $entryId ) );
		$meta = json_decode( (string) $raw, true );
		foreach ( $meta['uploads'] ?? array() as $index => $file ) {
			if ( ! is_array( $file ) || ! empty( $file['file_id'] ) ) {
				continue;
			}$files[] = array(
				'legacy_index' => $index,
				'field_id'     => $file['field_id'] ?? '',
				'name'         => $file['name'] ?? '',
			);
		}return $files;
	}
	/**
	 * Resolve the path or stream used to prepare an email attachment.
	 *
	 * @param array $file    File data for this operation.
	 * @param int   $entryId Entry identifier.
	 */
	public static function mailPath( array $file, int $entryId ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing callable method name for PHP callers and callback compatibility. Preserve the existing PHP named parameter $entryId.
		$entry_id = $entryId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Bind the existing PHP named argument to a local variable.
		global $wpdb;
		if ( $entry_id > 0 ) {
			if ( isset( $file['file_id'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}leenfo_entry_files WHERE id=%d AND entry_id=%d AND field_id=%s", (int) $file['file_id'], $entry_id, $file['field_id'] ), ARRAY_A );
				return $row ? self::resolve( $row ) : null;}
			if ( ! isset( $file['legacy_index'] ) ) {
				return null;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			}$meta  = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$wpdb->prefix}leenfo_entries WHERE id=%d", $entry_id ) ), true );
			$record = $meta['uploads'][ $file['legacy_index'] ] ?? null;
			return is_array( $record ) && ( $record['field_id'] ?? '' ) === $file['field_id'] ? self::legacyPath( $record ) : null;
		}
		if ( ! empty( $file['attachment_id'] ) && is_string( $file['lease'] ?? null ) && hash_equals( (string) get_post_meta( (int) $file['attachment_id'], '_leenfo_upload_lease', true ), $file['lease'] ) ) {
			$path = get_attached_file( (int) $file['attachment_id'] );
			return is_string( $path ) ? self::legacyPath( array( 'path' => $path ) ) : null;}
		$key = $file['storage_key'] ?? '';
		if ( ! is_string( $key ) || ! PrivateStorage::pending( $key ) ) {
			return null;
		}
		return PrivateStorage::verify( $key, (int) ( $file['size'] ?? 0 ), (string) ( $file['sha256'] ?? '' ) );
	}
	/**
	 * Clean up a file that was not retained by the entry.
	 *
	 * @param array $files Files for this operation.
	 */
	public static function discard( array $files ): void {
		global $wpdb;
		foreach ( $files as $file ) {
			if ( isset( $file['storage_key'] ) && PrivateStorage::validKey( $file['storage_key'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$refs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}leenfo_entry_files WHERE storage_key=%s", $file['storage_key'] ) );
				if ( $wpdb->last_error ) {
					update_option( 'leenfo_file_cleanup_attention', true, false );
					continue;
				}if ( ! $refs && ( ! PrivateStorage::remove( $file['storage_key'] ) || ! PrivateMedia::forget( $file['storage_key'] ) ) ) {
					update_option( 'leenfo_file_cleanup_attention', true, false );
				}
			} elseif ( ( $file['kind'] ?? '' ) === 'media' && empty( $file['attachment_id'] ) ) {
				$path = self::legacyPath( $file );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees. A checked file can disappear or become unreadable before hashing. Preserve the false result for the existing integrity check without disclosing the local path. The verified path may disappear between validation and cleanup. Preserve the existing boolean or best-effort result without disclosing the path.
				if ( $path && preg_match( '/^lkf-[a-f0-9]{48}\.[a-z0-9]+$/D', basename( $path ) ) && hash_equals( (string) ( $file['sha256'] ?? '' ), (string) @hash_file( 'sha256', $path ) ) && ! @unlink( $path ) ) {
					update_option( 'leenfo_file_cleanup_attention', true, false );
				}
			} elseif ( ! empty( $file['attachment_id'] ) ) {
				$id   = (int) $file['attachment_id'];
				$path = get_attached_file( $id );
				if ( is_string( $path ) && self::legacyPath( array( 'path' => $path ) ) && ( $file['path'] ?? '' ) === $path ) {
					if ( ! wp_delete_attachment( $id, true ) ) {
						update_option( 'leenfo_file_cleanup_attention', true, false );
					}
				}
			}
		}
	}
	/**
	 * Rows survive a failed filesystem deletion so retries are safe and visible.
	 *
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function cleanup(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'leenfo_entry_files';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status IN ('deleting','cleanup_failed') ORDER BY id LIMIT 100", ARRAY_A );
		foreach ( $rows as $candidate ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			if ( $wpdb->query( 'START TRANSACTION' ) === false ) {
				return;
			}
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				$refs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE storage_key=%s ORDER BY id FOR UPDATE", $candidate['storage_key'] ), ARRAY_A );
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				if ( $wpdb->last_error ) {
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					throw new \RuntimeException( __( 'File references unavailable.', 'leenkat-forms' ) );
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				}$row = null;
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				foreach ( $refs as $ref ) {
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					if ( (int) $ref['id'] === (int) $candidate['id'] ) {
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						$row = $ref;
					}
				}
				if ( ! $row ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					$wpdb->query( 'ROLLBACK' );
					continue;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				}$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}leenfo_entries WHERE id=%d", $row['entry_id'] ) );
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( __( 'Entry reference unavailable.', 'leenkat-forms' ) );
				}if ( $exists ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					$wpdb->query( 'ROLLBACK' );
					continue;}
				try {
					$ok = count( $refs ) > 1 || str_starts_with( $row['storage_key'], 'media:' ) || ( PrivateStorage::remove( $row['storage_key'] ) && PrivateMedia::forget( $row['storage_key'] ) );
				} catch ( \Throwable ) {
					$ok = false;}
				if ( $ok ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					if ( $wpdb->delete( $table, array( 'id' => $row['id'] ) ) === false ) {
						throw new \RuntimeException( __( 'Cleanup association unavailable.', 'leenkat-forms' ) );
					}
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					if ( $wpdb->update( $table, array( 'status' => 'cleanup_failed' ), array( 'id' => $row['id'] ) ) === false ) {
						throw new \RuntimeException( __( 'Cleanup retry unavailable.', 'leenkat-forms' ) );
					}update_option( 'leenfo_file_cleanup_attention', true, false );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				}if ( $wpdb->query( 'COMMIT' ) === false ) {
					throw new \RuntimeException( __( 'Cleanup could not commit.', 'leenkat-forms' ) );
				}
			} catch ( \Throwable ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$wpdb->query( 'ROLLBACK' );
				update_option( 'leenfo_file_cleanup_attention', true, false );}
		}
	}
}
