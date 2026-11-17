<?php
/**
 * Administrative controls for private file maintenance.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Files\AttachmentMigration as Migration;
use LeenkatForms\Files\PrivateStorage;
use LeenkatForms\Files\FileRecords;
use LeenkatForms\Files\PrivateMedia;
/**
 * Administrative controls for private file maintenance.
 */
final class FileSecurityPage {
	/**
	 * Register administrative private file action handlers.
	 */
	public static function hooks(): void {
		add_action( 'admin_post_leenfo_file_maintenance', array( self::class, 'action' ) );}
	/**
	 * Verify access to the private file maintenance screen.
	 */
	private static function gate(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
		}}
	/**
	 * Handle an authorized file maintenance operation.
	 *
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function action(): void {
		self::gate();
		check_admin_referer( 'leenfo_file_maintenance' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
		$operation = $_POST['operation'] ?? '';
		if ( ! is_string( $operation ) ) {
			wp_die( esc_html__( 'Invalid operation.', 'leenkat-forms' ), '', array( 'response' => 400 ) );
		}
		try {
			if ( 'plan' === $operation ) {
				$result = Migration::plan();
				$run    = $result['run_id'];
			} elseif ( 'copy' === $operation || 'finalize' === $operation ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Identifier/token is type-checked and validated by the existing digit/hex grammar and ownership/nonce gate before lookup or maintenance; keep exact identity.
				$run = is_string( $_POST['run_id'] ?? null ) ? $_POST['run_id'] : '';
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Identifier/token is type-checked and validated by the existing digit/hex grammar and ownership/nonce gate before lookup or maintenance; keep exact identity.
				if ( 'finalize' === $operation && ( $_POST['confirm_remove'] ?? '' ) !== '1' ) {
					throw new \RuntimeException( __( 'Explicit approval to remove legacy public copies is required.', 'leenkat-forms' ) );
				}
				Migration::apply( $run, 'finalize' === $operation );
			} elseif ( 'rollback' === $operation ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Identifier/token is type-checked and validated by the existing digit/hex grammar and ownership/nonce gate before lookup or maintenance; keep exact identity.
				$run = is_string( $_POST['run_id'] ?? null ) ? $_POST['run_id'] : '';
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Identifier/token is type-checked and validated by the existing digit/hex grammar and ownership/nonce gate before lookup or maintenance; keep exact identity.
				if ( ( $_POST['confirm_restore'] ?? '' ) !== '1' ) {
					throw new \RuntimeException( __( 'Explicit approval to restore legacy entry references is required.', 'leenkat-forms' ) );
				}
				Migration::rollback( $run, true );
			} elseif ( 'cleanup' === $operation ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing leenfo_-prefixed public hook is retained for backward compatibility; no unprefixed hook is introduced.
				do_action( 'leenfo_file_maintenance' );
				$run = '';
			} elseif ( 'index_private_media' === $operation ) {
				if ( ! PrivateMedia::indexLegacy() ) {
					throw new \RuntimeException( __( 'The private attachment index could not continue. Check the next attachment and retry.', 'leenkat-forms' ) );
				}
				$run = '';
			} else {
				throw new \RuntimeException( __( 'Invalid file maintenance operation.', 'leenkat-forms' ) );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=leenkat-forms-file-security&result=ok' . ( $run ? '&run=' . rawurlencode( $run ) : '' ) ) );
			exit;
		} catch ( \Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 409 ) );
		}
	}
	/**
	 * Begin an authorized migration operation.
	 *
	 * @param string $operation Operation for this operation.
	 * @param string $run       Run for this operation.
	 */
	private static function start( string $operation, string $run = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lkf-security-action">';
		wp_nonce_field( 'leenfo_file_maintenance' );
		echo '<input type="hidden" name="action" value="leenfo_file_maintenance"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="run_id" value="' . esc_attr( $run ) . '">';}
	/**
	 * Render private storage status and available maintenance controls.
	 */
	public static function render(): void {
		self::gate();
		global $wpdb;
		ManagementPage::start( __( 'Attachment Security', 'leenkat-forms' ) );
		echo '<div class="lkf-attachment-security"><p class="lkf-security-intro">' . esc_html__( 'New entry attachments appear in the Media Library and use protected downloads. Older public Media Library files keep their original visibility.', 'leenkat-forms' ) . '</p>';
		$ready = \LeenkatForms\Files\EncryptedStorage::available();
		echo '<div class="notice ' . ( $ready ? 'notice-success' : 'notice-error' ) . ' inline"><p>' . esc_html( $ready ? __( 'New private uploads are authenticated and encrypted before they are written to WordPress uploads. Downloading plaintext requires authorization.', 'leenkat-forms' ) : __( 'Private storage is unavailable. New private uploads are blocked until the server configuration is corrected.', 'leenkat-forms' ) ) . '</p></div>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
		if ( ( $_GET['result'] ?? '' ) === 'ok' ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'File maintenance completed. Review the migration state below.', 'leenkat-forms' ) . '</p></div>';
		}
		$inventory = Migration::inventory();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}leenfo_entry_files WHERE status IN ('cleanup_failed','deleting')" );
		echo '<section class="lkf-security-section" aria-labelledby="lkf-inventory-title"><h2 id="lkf-inventory-title">' . esc_html__( 'Inventory', 'leenkat-forms' ) . '</h2><table class="widefat striped lkf-list-table lkf-security-inventory"><caption class="screen-reader-text">' . esc_html__( 'Attachment inventory', 'leenkat-forms' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Attachment category', 'leenkat-forms' ) . '</th><th scope="col">' . esc_html__( 'Count', 'leenkat-forms' ) . '</th></tr></thead><tbody>';
		foreach ( array(
			__( 'Private registered attachments', 'leenkat-forms' )  => $inventory['private_registered'],
			__( 'Legacy private attachments with public sources', 'leenkat-forms' ) => $inventory['legacy_private'],
			__( 'Legacy Media Library references', 'leenkat-forms' ) => $inventory['media'],
			__( 'Missing or invalid legacy references', 'leenkat-forms' ) => $inventory['invalid_or_missing'],
			__( 'Other file records requiring review', 'leenkat-forms' ) => $inventory['other_file_records'],
			__( 'Pending or failed authorized cleanup', 'leenkat-forms' ) => $failed,
		) as $label => $count ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . (int) $count . '</td></tr>';
		}echo '</tbody></table>';
		self::start( 'index_private_media' );
		echo '<button class="button">' . esc_html__( 'Index Next Batch of Private Attachments in Media', 'leenkat-forms' ) . '</button></form>';
		if ( $inventory['invalid_or_missing'] ) {
			echo '<p class="description">' . esc_html__( 'Missing and invalid references are reported for review. They are never used to read or delete arbitrary filesystem paths.', 'leenkat-forms' ) . '</p>';
		}echo '</section>';
		echo '<section class="lkf-security-section" aria-labelledby="lkf-migration-title"><h2 id="lkf-migration-title">' . esc_html__( 'Legacy Attachment Migration', 'leenkat-forms' ) . '</h2><p>' . esc_html__( 'Check older uploaded files and create verified backups before moving them to private storage. Public copies are not deleted automatically.', 'leenkat-forms' ) . '</p><p class="description">' . esc_html__( 'Copying preserves the public source. Removing verified public copies is a separate approved operation. Media Library files are excluded.', 'leenkat-forms' ) . '</p>';
		self::start( 'plan' );
		echo '<button class="button button-secondary">' . esc_html__( 'Create Dry Run and Verified Backups', 'leenkat-forms' ) . '</button></form>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use. Identifier/token is type-checked and validated by the existing digit/hex grammar and ownership/nonce gate before lookup or maintenance; keep exact identity.
		$run = $_GET['run'] ?? get_option( 'leenfo_file_migration_latest', '' );
		if ( is_string( $run ) && '' !== $run ) {
			try {
				$report = Migration::report( Migration::read( $run ) );
				echo '<h3>' . esc_html( sprintf( /* translators: %s: migration run identifier. */ __( 'Migration %s', 'leenkat-forms' ), $run ) ) . '</h3><p>' . esc_html__( 'Verified file and database backups are stored privately for recovery.', 'leenkat-forms' ) . '</p><table class="widefat striped lkf-list-table lkf-security-report"><caption class="screen-reader-text">' . esc_html__( 'Legacy migration file status', 'leenkat-forms' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Entry', 'leenkat-forms' ) . '</th><th scope="col">' . esc_html__( 'Original filename', 'leenkat-forms' ) . '</th><th scope="col">' . esc_html__( 'Size', 'leenkat-forms' ) . '</th><th scope="col">' . esc_html__( 'State', 'leenkat-forms' ) . '</th></tr></thead><tbody>';
				foreach ( $report['files'] as $file ) {
					echo '<tr><td>' . (int) $file['entry_id'] . '</td><td>' . esc_html( $file['name'] ) . '</td><td>' . (int) $file['bytes'] . '</td><td>' . esc_html( $file['state'] . ( ! empty( $file['message'] ) ? ' — ' . $file['message'] : '' ) ) . '</td></tr>';
				}echo '</tbody></table>';
				self::start( 'copy', $run );
				echo '<button class="button">' . esc_html__( 'Copy and Update Private Associations', 'leenkat-forms' ) . '</button></form>';
				self::start( 'finalize', $run );
				echo '<p><label><input type="checkbox" name="confirm_remove" value="1" required> ' . esc_html__( 'I approve removing the legacy public copies after verifying their private destinations and backups.', 'leenkat-forms' ) . '</label></p><button class="button button-primary">' . esc_html__( 'Complete Approved Migration', 'leenkat-forms' ) . '</button></form>';
				self::start( 'rollback', $run );
				echo '<p><label><input type="checkbox" name="confirm_restore" value="1" required> ' . esc_html__( 'I approve restoring entry references to unchanged legacy files. Missing legacy files will not be recreated.', 'leenkat-forms' ) . '</label></p><button class="button">' . esc_html__( 'Restore Legacy References', 'leenkat-forms' ) . '</button></form>';
			} catch ( \Throwable ) {
				echo '<p>' . esc_html__( 'Migration information is unavailable. Create a new dry run or consult the private recovery backup.', 'leenkat-forms' ) . '</p>';}
		}
		echo '</section><section class="lkf-security-section" aria-labelledby="lkf-cleanup-title"><h2 id="lkf-cleanup-title">' . esc_html__( 'Authorized Cleanup', 'leenkat-forms' ) . '</h2><p>' . esc_html__( 'Review and retry file cleanup operations that could not be completed. Files required by active downloads or pending email deliveries remain protected.', 'leenkat-forms' ) . '</p><p class="description">' . esc_html__( 'Trash and restore preserve attachments. Permanent deletion waits for active downloads and deliveries, cancels pending deliveries safely, and records cleanup failures for retry.', 'leenkat-forms' ) . '</p>';
		self::start( 'cleanup' );
		echo '<button class="button">' . esc_html__( 'Retry Authorized Cleanup', 'leenkat-forms' ) . '</button></form></section><p class="description">' . esc_html__( 'Office document structure is checked with the ZIP reader bundled with WordPress. A missing file-info or private-storage capability causes validation to fail closed.', 'leenkat-forms' ) . '</p></div>';
		ManagementPage::end();
	}
}
