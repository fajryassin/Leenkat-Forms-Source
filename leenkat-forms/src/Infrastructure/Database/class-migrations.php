<?php
/**
 * Installation of the plugin database schema.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Infrastructure\Database;

/**
 * Installation of the plugin database schema.
 */
final class Migrations {
	public const VERSION = 2;
	/**
	 * Build the table names for the current WordPress prefix.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function tables(): array {
		global $wpdb;
		return array_map( static fn( string $name ): string => $wpdb->prefix . 'leenfo_' . $name, array( 'forms', 'form_versions', 'entries', 'entry_values', 'entry_files', 'deliveries' ) );
	}
	/**
	 * Install or update the plugin database schema.
	 *
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function install(): bool {
		global $wpdb;
		if ( (int) get_option( 'leenfo_db_version', 0 ) >= self::VERSION ) {
			return true; }
		$lock = 'leenfo_migration_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			return false; }
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$p       = $wpdb->prefix . 'leenfo_';
			$collate = $wpdb->get_charset_collate();
			$sql     = array(
				"CREATE TABLE {$p}forms (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    uuid varchar(36) NOT NULL,
                    author_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    status varchar(24) NOT NULL DEFAULT 'draft',
                    draft_definition longtext NULL,
                    published_version_id bigint(20) unsigned NULL,
                    created_at_gmt datetime NOT NULL,
                    updated_at_gmt datetime NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY uuid (uuid),
                    KEY status (status)
                ) $collate;",
				"CREATE TABLE {$p}form_versions (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    form_id bigint(20) unsigned NOT NULL,
                    version_no int(10) unsigned NOT NULL,
                    schema_version varchar(20) NOT NULL,
                    definition longtext NOT NULL,
                    definition_hash char(64) NOT NULL,
                    created_at_gmt datetime NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY form_version (form_id,version_no)
                ) $collate;",
				"CREATE TABLE {$p}entries (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    uuid varchar(36) NOT NULL,
                    form_id bigint(20) unsigned NOT NULL,
                    form_version_id bigint(20) unsigned NOT NULL,
                    status varchar(24) NOT NULL DEFAULT 'complete',
                    submission_uuid varchar(36) NOT NULL,
                    user_id bigint(20) unsigned NULL,
                    payload longtext NOT NULL,
                    meta longtext NULL,
                    submitted_at_gmt datetime NOT NULL,
                    updated_at_gmt datetime NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY uuid (uuid),
                    UNIQUE KEY form_submission (form_id,submission_uuid),
                    KEY form_status_id (form_id,status,id),
                    KEY user_entry (user_id,id)
                ) $collate;",
				"CREATE TABLE {$p}entry_values (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    entry_id bigint(20) unsigned NOT NULL,
                    form_id bigint(20) unsigned NOT NULL,
                    field_id varchar(100) NOT NULL,
                    row_index int(10) unsigned NOT NULL DEFAULT 0,
                    value_text varchar(191) NULL,
                    value_number decimal(20,6) NULL,
                    value_date datetime NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY entry_field_row (entry_id,field_id,row_index),
                    KEY form_field_text (form_id,field_id,value_text),
                    KEY entry_id (entry_id)
                ) $collate;",
				"CREATE TABLE {$p}entry_files (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    entry_id bigint(20) unsigned NOT NULL,
                    field_id varchar(100) NOT NULL,
                    storage_key varchar(255) NOT NULL,
                    original_name varchar(255) NOT NULL,
                    mime_detected varchar(120) NOT NULL,
                    byte_size bigint(20) unsigned NOT NULL DEFAULT 0,
                    sha256 char(64) NOT NULL,
                    status varchar(24) NOT NULL DEFAULT 'quarantined',
                    PRIMARY KEY  (id),
                    KEY entry_id (entry_id),
                    KEY storage_key (storage_key(191)),
                    KEY status_id (status,id)
                ) $collate;",
				"CREATE TABLE {$p}deliveries (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    entry_id bigint(20) unsigned NOT NULL,
                    kind varchar(40) NOT NULL,
                    destination_key varchar(191) NOT NULL,
                    status varchar(24) NOT NULL DEFAULT 'pending',
                    attempt_count int(10) unsigned NOT NULL DEFAULT 0,
                    next_attempt_at_gmt datetime NULL,
                    idempotency_key varchar(100) NOT NULL,
                    last_error_code varchar(100) NULL,
                    created_at_gmt datetime NOT NULL,
                    updated_at_gmt datetime NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY idempotency_key (idempotency_key),
                    KEY status_due (status,next_attempt_at_gmt),
                    KEY entry_id (entry_id)
                ) $collate;",
			);
			dbDelta( $sql );
			foreach ( self::tables() as $table ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
				if ( $found !== $table ) {
					update_option( 'leenfo_migration_error', 'Missing table: ' . $table, false );
					return false; }
			}
			update_option( 'leenfo_db_version', self::VERSION, false );
			delete_option( 'leenfo_migration_error' );
			return true;
		} finally {
			delete_option( $lock ); }
	}
}
