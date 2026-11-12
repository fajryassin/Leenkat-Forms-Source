<?php
declare(strict_types=1);

namespace LeenkatForms\Bootstrap;

final class Lifecycle {
    public static function activate(): void {
        if(get_option('lkf_uninstall_state',null)!==null)wp_die(esc_html__('Uninstall cleanup is incomplete. Retry plugin deletion or restore an external backup before activating Leenkat Forms.','leenkat-forms'));
        if (version_compare(PHP_VERSION, '8.3', '<')) {
            deactivate_plugins(plugin_basename(LEENKAT_FORMS_FILE));
            wp_die(esc_html__('Leenkat Forms requires PHP 8.3 or newer.', 'leenkat-forms'));
        }
        if (!\LeenkatForms\Infrastructure\Database\Migrations::install()) {
            update_option('lkf_migration_error', (string) get_option('lkf_migration_error', __('Migration could not complete.', 'leenkat-forms')), false);
            deactivate_plugins(plugin_basename(LEENKAT_FORMS_FILE));
            wp_die(esc_html__('Leenkat Forms could not initialize its database tables. Existing data was preserved.', 'leenkat-forms'));
        }
        self::grantCapabilities();
        update_option('lkf_installed_version', LEENKAT_FORMS_VERSION, false);
    }

    public static function grantCapabilities(): void {
        $role = get_role('administrator');
        if (!$role) { return; }
        foreach (['lkf_manage_forms','lkf_view_entries','lkf_manage_settings'] as $cap) { $role->add_cap($cap); }
    }

    public static function deactivate(): void {
        // Never remove customer data on deactivation.
        wp_clear_scheduled_hook('lkf_reconcile_deliveries');
        wp_clear_scheduled_hook('lkf_file_maintenance');
    }
}
