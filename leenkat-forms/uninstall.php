<?php
/** Genuine WordPress uninstall only; updates and deactivation never enter here. */
declare(strict_types=1);
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
if (!defined('ABSPATH') || WP_UNINSTALL_PLUGIN !== plugin_basename(__DIR__.'/leenkat-forms.php')) { exit; }
if (!(defined('WP_CLI') && WP_CLI) && !current_user_can('delete_plugins')) {
    wp_die(esc_html__('You cannot uninstall this plugin.', 'leenkat-forms'), '', ['response'=>403]);
}
require_once __DIR__.'/src/Files/PrivateStorage.php';
require_once __DIR__.'/src/Files/EncryptedStorage.php';
require_once __DIR__.'/src/Files/FileRecords.php';
require_once __DIR__.'/src/Files/AttachmentMigration.php';
require_once __DIR__.'/src/Bootstrap/Uninstaller.php';
try {
    \LeenkatForms\Bootstrap\Uninstaller::run();
} catch (\Throwable $error) {
    // Abort before WordPress deletes the plugin files. Metadata/journal survive for retry.
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failure is logged with a fixed safe message, without entry values, credentials or private paths.
    error_log('Leenkat Forms uninstall incomplete. Review the WordPress administration notice.');
    wp_die(esc_html('Leenkat Forms removal is incomplete. '.$error->getMessage().' Plugin files were retained. Resolve the problem and retry deletion. Restore an external backup to recover already removed data.'),'Leenkat Forms uninstall',['response'=>500,'back_link'=>true]);
}
