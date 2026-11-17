<?php
/**
 * Removal of stored submissions and their associated files.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Files;

use LeenkatForms\Infrastructure\Database\Transactions;
/**
 * Removal of stored submissions and their associated files.
 */
final class EntryDeletion {
	/**
	 * Remove an authorized entry under the appropriate maintenance lease.
	 *
	 * @param int  $id      Record identifier.
	 * @param bool $privacy Privacy for this operation.
	 * @return true|\WP_Error Result for the requested operation.
	 */
	public static function remove( int $id, bool $privacy = false ): true|\WP_Error {
		if ( $privacy ? ( ! current_user_can( 'erase_others_personal_data' ) || ! current_user_can( 'delete_users' ) ) : ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			return new \WP_Error( 'leenfo_file_access', __( 'Entry unavailable.', 'leenkat-forms' ) );
		}
		try {
			$lock = AttachmentMigration::lock();} catch ( \Throwable ) {
			return new \WP_Error( 'leenfo_file_busy', __( 'File maintenance is busy. Retry later.', 'leenkat-forms' ) );}
			$lease = null;try {
				$lease = FileLease::acquire( $id, true );
				return self::remove_locked( $id, $privacy );} catch ( \Throwable ) {
						return new \WP_Error( 'leenfo_file_busy', __( 'File maintenance is unavailable or entry files are in use. Retry later.', 'leenkat-forms' ) );} finally {
					FileLease::release( $lease );
					delete_option( $lock );}
	}
	/**
	 * Remove an entry while its deletion lease is held.
	 *
	 * @param int  $id      Record identifier.
	 * @param bool $privacy Privacy for this operation.
	 * @return true|\WP_Error Result for the requested operation.
	 */
	private static function remove_locked( int $id, bool $privacy = false ): true|\WP_Error {
		global $wpdb;
		if ( $privacy ? ( ! current_user_can( 'erase_others_personal_data' ) || ! current_user_can( 'delete_users' ) ) : ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			return new \WP_Error( 'leenfo_file_access', __( 'Entry unavailable.', 'leenkat-forms' ) );
		}
		$entries    = $wpdb->prefix . 'leenfo_entries';
		$files      = $wpdb->prefix . 'leenfo_entry_files';
		$deliveries = $wpdb->prefix . 'leenfo_deliveries';
		$values     = $wpdb->prefix . 'leenfo_entry_values';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		if ( ! Transactions::supported( array( $entries, $files, $deliveries, $values ) ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'leenfo_storage', __( 'Entry storage is unavailable.', 'leenkat-forms' ) );
		}
		$committed = false;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$entries} WHERE id=%d FOR UPDATE", $id ) );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing leenfo_-prefixed public hook is retained for backward compatibility; no unprefixed hook is introduced.
			if ( ! $entry || ( ! $privacy && 'trashed' !== $entry->status ) || ! apply_filters( 'leenfo_can_access_entry_file', true, $entry, get_current_user_id() ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing leenfo_-prefixed public hook is retained for backward compatibility; no unprefixed hook is introduced.
				return new \WP_Error( 'leenfo_file_access', __( 'Entry unavailable.', 'leenkat-forms' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT id,status FROM {$deliveries} WHERE entry_id=%d FOR UPDATE", $id ), ARRAY_A );
			if ( $wpdb->last_error ) {
				return new \WP_Error( 'leenfo_storage', __( 'Notification state is unavailable.', 'leenkat-forms' ) );
			}
			foreach ( $jobs as $job ) {
				if ( 'processing' === $job['status'] ) {
					return new \WP_Error( 'leenfo_file_busy', __( 'A notification is being processed. Retry deletion after it finishes.', 'leenkat-forms' ) );
				}
			}
			if ( AttachmentMigration::pendingPublicSource( $id ) ) {
				return new \WP_Error( 'leenfo_legacy_files', __( 'Legacy public sources require approved migration before deletion.', 'leenkat-forms' ) );
			}
			$meta = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $entry->meta );
			foreach ( $meta['uploads'] ?? array() as $file ) {
				if ( is_array( $file ) && empty( $file['attachment_id'] ) && empty( $file['file_id'] ) && empty( $file['storage_key'] ) && FileRecords::legacyPath( $file ) ) {
					return new \WP_Error( 'leenfo_legacy_files', __( 'Private legacy attachments must be migrated before permanent deletion.', 'leenkat-forms' ) );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$deliveries} SET status='cancelled',last_error_code='entry_removed' WHERE entry_id=%d AND status IN ('pending','retry')", $id ) ) ) {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				return new \WP_Error( 'leenfo_storage', __( 'Entry could not be removed.', 'leenkat-forms' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			if ( false === $wpdb->update( $files, array( 'status' => 'deleting' ), array( 'entry_id' => $id ) ) || false === $wpdb->delete( $values, array( 'entry_id' => $id ) ) || false === $wpdb->delete( $deliveries, array( 'entry_id' => $id ) ) || false === $wpdb->delete( $entries, array( 'id' => $id ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				return new \WP_Error( 'leenfo_storage', __( 'Entry could not be removed.', 'leenkat-forms' ) );
			}
			$committed = true;
		} finally {
			if ( ! $committed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$wpdb->query( 'ROLLBACK' );
			}
		}
		FileRecords::cleanup();
		if ( ! AttachmentMigration::purgeEntryBackups( $id ) ) {
			$pending   = get_option( 'leenfo_backup_cleanup_pending', array() );
			$pending[] = $id;
			update_option( 'leenfo_backup_cleanup_pending', array_values( array_unique( $pending ) ), false );
			update_option( 'leenfo_file_cleanup_attention', true, false );
		}return true;
	}
	/**
	 * Register entry deletion actions for housekeeping.
	 */
	public static function hooks(): void {
		add_action(
			'leenfo_file_maintenance',
			static function (): void {
				FileRecords::cleanup();
				PrivateStorage::reap();try {
					$lock = AttachmentMigration::lock();} catch ( \Throwable ) {
					return;}$pending = get_option(
						'leenfo_backup_cleanup_pending',
						array()
					);foreach ( array_slice(
						$pending,
						0,
						100
					) as $id ) {
					if ( AttachmentMigration::purgeEntryBackups( (int) $id ) ) {
						$pending = array_values(
							array_diff(
								$pending,
								array( $id )
							)
						);
					}
					}update_option(
						'leenfo_backup_cleanup_pending',
						$pending,
						false
					);
				delete_option( $lock );}
		);
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( 'leenfo_file_maintenance' ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'leenfo_file_maintenance' );
				}}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			static function ( $erasers ) {
				$erasers['leenkat-form-files'] = array(
					'eraser_friendly_name' => __( 'Leenkat Forms entries and attachments', 'leenkat-forms' ),
					'callback'             => array( self::class, 'erase' ),
				);
				return $erasers;
			}
		);
	}
	/**
	 * Apply entry erasure for the requested operation.
	 *
	 * @param string $email Email for this operation.
	 * @param int    $page  Page for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
		if ( ! is_email( $email ) || ! current_user_can( 'erase_others_personal_data' ) || ! current_user_can( 'delete_users' ) ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'Entry erasure is unavailable.', 'leenkat-forms' );
			return $result;}
		global $wpdb;
		$key    = 'leenfo_erase_cursor_' . get_current_user_id() . '_' . hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
		$cursor = 1 === $page ? array(
			'last' => 0,
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			'max'  => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}leenfo_entries" ),
		) : get_transient( $key );
		if ( $wpdb->last_error ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'Entry storage is unavailable. Restart the erasure request later.', 'leenkat-forms' );
			return $result;}
		if ( ! is_array( $cursor ) ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'The erasure session expired. Restart the request.', 'leenkat-forms' );
			return $result;}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,user_id,form_version_id,payload FROM {$wpdb->prefix}leenfo_entries WHERE id>%d AND id<=%d ORDER BY id LIMIT 100", $cursor['last'], $cursor['max'] ) );
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'Entries could not be verified. Restart the erasure request later.', 'leenkat-forms' );
			return $result;}
		$user     = get_user_by( 'email', $email );
		$versions = array();
		if ( $wpdb->last_error ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'Entry ownership could not be verified. Restart the erasure request later.', 'leenkat-forms' );
			return $result;}
		foreach ( $rows as $row ) {
			$cursor['last'] = (int) $row->id;
			$match          = $user && (int) $row->user_id === (int) $user->ID;
			$id             = (int) $row->form_version_id;
			if ( ! isset( $versions[ $id ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$versions[ $id ] = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $wpdb->get_var( $wpdb->prepare( "SELECT definition FROM {$wpdb->prefix}leenfo_form_versions WHERE id=%d", $id ) ) );
			}$payload = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $row->payload );
			if ( $wpdb->last_error || ! $versions[ $id ] ) {
				$result['items_retained'] = true;
				$result['messages'][]     = __( 'An entry definition could not be verified.', 'leenkat-forms' );
				continue;}
			foreach ( $versions[ $id ]['fields'] ?? array() as $field ) {
				if ( ( $field['type'] ?? '' ) === 'email' && is_string( $payload[ $field['id'] ] ?? null ) && 0 === strcasecmp( trim( $payload[ $field['id'] ] ), $email ) ) {
					$match = true;
				}
			}
			if ( ! $match ) {
				continue;
			}$removed = self::remove( (int) $row->id, true );if ( is_wp_error( $removed ) ) {
				$result['items_retained'] = true;
				$result['messages'][]     = $removed->get_error_message();
			} else {
				$result['items_removed'] = true;
			}
		}
		$result['done'] = count( $rows ) < 100;
		if ( $result['done'] ) {
			delete_transient( $key );
		} else {
			set_transient( $key, $cursor, HOUR_IN_SECONDS );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		if ( get_option( 'leenfo_backup_cleanup_pending', array() ) || (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}leenfo_entry_files WHERE status='cleanup_failed'" ) ) {
			$result['items_retained'] = true;
			$result['messages'][]     = __( 'Attachment cleanup needs administrator attention.', 'leenkat-forms' );
		}$result['messages'] = array_values( array_unique( $result['messages'] ) );
		return $result;
	}
}
