<?php
/**
 * Plugin activation, capability grant and deactivation.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);

namespace LeenkatForms\Bootstrap;

/**
 * Plugin activation, capability grant and deactivation.
 */
final class Lifecycle {
	/**
	 * Initialize the plugin for the current WordPress site.
	 */
	public static function activate(): void {
		if ( get_option( 'leenfo_uninstall_state', null ) !== null ) {
			wp_die( esc_html__( 'Uninstall cleanup is incomplete. Retry plugin deletion or restore an external backup before activating Leenkat Forms.', 'leenkat-forms' ) );
		}
		if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
			deactivate_plugins( plugin_basename( LEENKAT_FORMS_FILE ) );
			wp_die( esc_html__( 'Leenkat Forms requires PHP 8.3 or newer.', 'leenkat-forms' ) );
		}
		global $wp_version;
		if ( version_compare( $wp_version, '7.1', '<' ) ) {
			deactivate_plugins( plugin_basename( LEENKAT_FORMS_FILE ) );
			wp_die( esc_html__( 'Leenkat Forms requires WordPress 7.1 or newer.', 'leenkat-forms' ) );
		}
		if ( ! extension_loaded( 'sodium' ) || ! extension_loaded( 'fileinfo' ) || ! extension_loaded( 'mbstring' ) ) {
			deactivate_plugins( plugin_basename( LEENKAT_FORMS_FILE ) );
			wp_die( esc_html__( 'Leenkat Forms requires the PHP Sodium, Fileinfo and Mbstring extensions. Ask your hosting provider to enable them, then activate the plugin again.', 'leenkat-forms' ) );
		}
		if ( ! IdentifierUpgrade::run() || ! \LeenkatForms\Infrastructure\Database\Migrations::install() ) {
			update_option( 'leenfo_migration_error', (string) get_option( 'leenfo_migration_error', __( 'Migration could not complete.', 'leenkat-forms' ) ), false );
			deactivate_plugins( plugin_basename( LEENKAT_FORMS_FILE ) );
			wp_die( esc_html__( 'Leenkat Forms could not initialize its database tables. Existing data was preserved.', 'leenkat-forms' ) );
		}
		self::grantCapabilities();
		update_option( 'leenfo_installed_version', LEENKAT_FORMS_VERSION, false );
	}

	/**
	 * Grant the plugin management capabilities to the appropriate roles.
	 */
	public static function grantCapabilities(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return; }
		foreach ( array( 'leenfo_manage_forms', 'leenfo_view_entries', 'leenfo_manage_settings' ) as $cap ) {
			$role->add_cap( $cap ); }
	}

	/**
	 * Perform plugin deactivation cleanup.
	 */
	public static function deactivate(): void {
		// Never remove customer data on deactivation.
		wp_clear_scheduled_hook( 'leenfo_reconcile_deliveries' );
		wp_clear_scheduled_hook( 'leenfo_file_maintenance' );
	}
}
