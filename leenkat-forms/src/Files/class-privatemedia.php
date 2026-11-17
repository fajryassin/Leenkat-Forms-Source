<?php
/**
 * WordPress Media Library records for protected entry attachments.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);

namespace LeenkatForms\Files;

/** A WordPress attachment record for a private form object, with no public media file. */
final class PrivateMedia {
	public const INDEX_CURSOR = 'leenfo_private_media_cursor';
	private const KEY         = '_leenfo_private_media_key';
	private const FILE_ID     = '_leenfo_private_media_file_id';

	/**
	 * Connect private attachment resolution and permissions to WordPress Media.
	 */
	public static function hooks(): void {
		add_filter( 'wp_get_attachment_url', array( self::class, 'url' ), 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( self::class, 'prepare' ), 10, 2 );
		add_filter( 'map_meta_cap', array( self::class, 'permissions' ), 10, 4 );
		add_filter( 'pre_delete_attachment', array( self::class, 'preventDelete' ), 10, 2 );
	}

	/**
	 * Create a private attachment linked to an owned file record.
	 *
	 * @param array $file File row data used to register the attachment.
	 * @return int Attachment ID on success or zero on failure.
	 */
	public static function register( array $file ): int {
		$key = $file['storage_key'];
		if ( ! PrivateStorage::validKey( $key ) ) {
			return 0;
		}
		$id = wp_insert_attachment(
			array(
				'post_title'     => wp_slash( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
				'post_mime_type' => $file['mime'],
				'post_status'    => 'private',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'meta_input'     => array(
					self::KEY     => $key,
					self::FILE_ID => (int) $file['file_id'],
				),
			),
			false,
			0,
			true
		);
		if ( is_wp_error( $id ) || ! $id || get_post_meta( $id, self::KEY, true ) !== $key ||
			(int) get_post_meta( $id, self::FILE_ID, true ) !== (int) $file['file_id'] ) {
			return 0;
		}
		return $id;
	}

	/**
	 * Resolve the live file and entry associated with a private attachment.
	 *
	 * @param int $id WordPress attachment ID.
	 * @return object|null Matching live file row or null.
	 */
	private static function association( int $id ): ?object {
		$key = get_post_meta( $id, self::KEY, true );
		if ( ! is_string( $key ) || ! PrivateStorage::validKey( $key ) ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authorization needs the current entry association in a custom table.
		$record = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, entry_id FROM %i WHERE id = %d AND storage_key = %s AND status = %s LIMIT 1',
				$wpdb->prefix . 'leenfo_entry_files',
				(int) get_post_meta( $id, self::FILE_ID, true ),
				$key,
				'private'
			)
		);
		return $record ? $record : null;
	}

	/**
	 * Provide the permission-checked download URL for an owned attachment.
	 *
	 * @param string|false $url Core attachment URL.
	 * @param int          $id WordPress attachment ID.
	 * @return string|false Original or protected URL, or false when denied.
	 */
	public static function url( $url, int $id ) {
		if ( ! metadata_exists( 'post', $id, self::KEY ) ) {
			return $url;
		}
		$record = self::association( $id );
		return $record && Download::allowed( (int) $record->entry_id ) ? Download::url( (int) $record->id ) : false;
	}

	/**
	 * Expose only authorized download data in the Media Library response.
	 *
	 * @param array    $response Existing response for the attachment.
	 * @param \WP_Post $attachment The attachment post.
	 * @return array Filtered response.
	 */
	public static function prepare( array $response, \WP_Post $attachment ): array {
		if ( metadata_exists( 'post', $attachment->ID, self::KEY ) ) {
			$private_url     = self::url( false, $attachment->ID );
			$response['url'] = $private_url ? $private_url : '';
			if ( '' === $response['url'] ) {
				return array(); }
			$response['link'] = $response['url'];
			unset( $response['sizes'], $response['image'], $response['thumb'] );
		}
		return $response;
	}

	/**
	 * Require entry access for private attachment metadata and edits.
	 *
	 * @param array  $caps Required primitive WordPress capabilities.
	 * @param string $cap Requested meta capability.
	 * @param int    $userId WordPress user ID.
	 * @param array  $args Capability arguments starting with the post ID.
	 * @return array Required capabilities or an explicit denial.
	 */
	public static function permissions( array $caps, string $cap, int $userId, array $args ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $userId and its method binding.
		if ( ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post' ), true ) || empty( $args[0] ) ||
			! metadata_exists( 'post', (int) $args[0], self::KEY ) ) {
			return $caps;
		}
		$record = self::association( (int) $args[0] );
		if ( ! $record || get_current_user_id() !== $userId || ! Download::allowed( (int) $record->entry_id ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $userId and its method binding.
			return array( 'do_not_allow' );
		}
		return 'delete_post' === $cap ? array( 'do_not_allow' ) : $caps;
	}

	/**
	 * Reserve private attachment deletion for plugin-managed cleanup.
	 *
	 * @param bool|null $delete Existing deletion decision.
	 * @param \WP_Post  $post The attachment being deleted.
	 * @return bool|null Existing decision or an explicit denial.
	 */
	public static function preventDelete( $delete, \WP_Post $post ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return metadata_exists( 'post', $post->ID, self::KEY ) ? false : $delete;
	}

	/**
	 * Find owned attachment IDs using the indexed meta key and exact value.
	 *
	 * Core indexes meta_key. The posts join caps the result without date ordering.
	 *
	 * @param string $name Private metadata key.
	 * @param string $value Exact metadata value to compare.
	 * @param int    $limit Maximum number of attachment IDs.
	 * @param bool   $private_only Whether to restrict results to private status.
	 * @return int[]|null Matching IDs, or null when the lookup fails.
	 */
	private static function matching_attachments( string $name, string $value, int $limit, bool $private_only ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress get_posts() adds ordering and runs a meta query over the same data. Cleanup and deduplication require current ownership even inside a database transaction.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT p.ID FROM %i m INNER JOIN %i p ON p.ID = m.post_id
             WHERE m.meta_key = %s AND BINARY m.meta_value = %s AND p.post_type = %s
             AND p.post_status IN (%s, %s, %s) LIMIT %d',
				$wpdb->postmeta,
				$wpdb->posts,
				$name,
				$value,
				'attachment',
				'private',
				$private_only ? 'private' : 'inherit',
				$private_only ? 'private' : 'trash',
				$limit
			)
		);
		return ! $wpdb->last_error && is_array( $ids ) ? array_map( 'intval', $ids ) : null;
	}

	/**
	 * Index verified legacy objects without moving originals or changing file IDs.
	 *
	 * @return bool Whether the current batch completed successfully.
	 */
	public static function indexLegacy(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		global $wpdb;
		$cursor = (int) get_option( self::INDEX_CURSOR, 0 );
		$table  = $wpdb->prefix . 'leenfo_entry_files';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The batch follows current private associations in the plugin table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id > %d AND status = %s AND storage_key NOT LIKE %s ORDER BY id LIMIT 25',
				$table,
				$cursor,
				'private',
				'p3-%'
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			return false;
		}
		foreach ( $rows as $row ) {
			$lease = FileLease::acquire( (int) $row['entry_id'], true );
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Re-check the association after obtaining its exclusive entry lease.
				$current = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $row['id'] ), ARRAY_A );
				if ( $wpdb->last_error ) {
					return false;
				}
				if ( $current && 'private' === $current['status'] && Download::allowed( (int) $current['entry_id'] ) ) {
					$matches = self::matching_attachments( self::FILE_ID, (string) $row['id'], 1, true );
					if ( null === $matches ) {
						return false;
					}
					if ( ! $matches ) {
						$verified = FileRecords::resolve( $current );
						if ( ! $verified ) {
							return false;
						}
						PrivateStorage::close( $verified );
						if ( ! self::register(
							array(
								'file_id'     => (int) $current['id'],
								'storage_key' => $current['storage_key'],
								'name'        => $current['original_name'],
								'mime'        => $current['mime_detected'],
							)
						) ) {
							return false;
						}
					}
				}
				update_option( self::INDEX_CURSOR, (int) $row['id'], false );
			} finally {
				FileLease::release( $lease );
			}
		}
		return true;
	}

	/**
	 * Remove matching Media records after the controlled file cleanup releases their associations.
	 *
	 * @param string $key Private storage object key.
	 * @return bool Whether cleanup is complete.
	 */
	public static function forget( string $key ): bool {
		if ( ! PrivateStorage::validKey( $key ) ) {
			return false;
		}
		$ids = self::matching_attachments( self::KEY, $key, 2, false );
		if ( null === $ids ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( get_post_meta( $id, self::KEY, true ) !== $key || get_attached_file( $id, true ) ) {
				return false;
			}
			// Only our attachment may bypass our guard. Core still runs its other deletion checks.
			delete_post_meta( $id, self::KEY, $key );
			if ( ! wp_delete_attachment( $id, true ) ) {
				update_post_meta( $id, self::KEY, $key );
				return false;
			}
		}
		return count( $ids ) < 2;
	}
}
