<?php
declare(strict_types=1);
namespace LeenkatForms\Localization;

/** Directory language packs use WordPress locale resolution, including per-user admin locales. */
final class Translations {
    public const SCRIPTS = [
        'lkf-block-editor'=>'assets/admin/block.js',
        'lkf-builder-model'=>'assets/admin/builder-model.js',
        'lkf-builder'=>'assets/admin/builder.js',
        'lkf-captcha-settings'=>'assets/admin/captcha.js',
        'lkf-confirmations'=>'assets/admin/confirmations.js',
        'lkf-notifications'=>'assets/admin/notifications.js',
        'lkf-security'=>'assets/admin/security.js',
        'lkf-settings'=>'assets/admin/settings.js',
        'lkf-site-settings'=>'assets/admin/site-settings.js',
        'lkf-tools'=>'assets/admin/tools.js',
        'lkf-captcha'=>'assets/frontend/captcha.js',
        'lkf-confirmation'=>'assets/frontend/confirmation.js',
        'lkf-form'=>'assets/frontend/form.js',
        'lkf-steps'=>'assets/frontend/multistep.js',
        'lkf-validation'=>'assets/frontend/validation.js',
        'lkf-widget-i18n'=>'assets/frontend/widget-i18n.js',
    ];

    public static function hooks(): void {
        // Resolve translations after late shortcode/admin registrations, before WordPress prints them.
        foreach (['wp_print_scripts','wp_print_footer_scripts','admin_print_scripts','admin_print_footer_scripts'] as $hook) {
            add_action($hook, [self::class, 'scripts'], 0);
        }
    }

    public static function scripts(): void {
        $scripts = wp_scripts();
        foreach (self::SCRIPTS as $handle=>$path) {
            if (!isset($scripts->registered[$handle])) { continue; }
            $registered = $scripts->registered[$handle];
            if (!in_array('wp-i18n', $registered->deps, true)) { $registered->deps[] = 'wp-i18n'; }
            wp_set_script_translations($handle, 'leenkat-forms');
        }
    }
}
