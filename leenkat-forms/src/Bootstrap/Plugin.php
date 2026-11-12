<?php
declare(strict_types=1);

namespace LeenkatForms\Bootstrap;

final class Plugin {
    private static bool $booted = false;

    public static function boot(): void {
        if (self::$booted) { return; }
        self::$booted = true;
        \LeenkatForms\Localization\Translations::hooks();
        if(get_option('lkf_uninstall_state',null)!==null){
            add_action('admin_notices',static function():void{if(current_user_can('manage_options'))echo '<div class="notice notice-error"><p>'.esc_html__('Leenkat Forms uninstall was interrupted. Form processing is paused. Resolve the reported cleanup problem and retry plugin deletion, or restore an external backup.','leenkat-forms').'</p></div>';});
            return;
        }
        if (is_admin() && current_user_can('manage_options')) {
            add_action('admin_init', [self::class, 'upgrade']);
        }
        if (is_admin()) {
            add_action('admin_enqueue_scripts', [self::class, 'brandingAssets']);
            add_action('elementor/editor/after_enqueue_styles', [self::class, 'brandingAssets']);
            add_action('admin_menu', [self::class, 'registerMenu']);
            \LeenkatForms\Admin\ManagementPage::hooks();
            \LeenkatForms\Admin\FormManager::hooks();
            \LeenkatForms\Admin\EntryManager::hooks();
            \LeenkatForms\Admin\GlobalSettings::hooks();
        }
        \LeenkatForms\Frontend\FormRenderer::hooks();
        \LeenkatForms\Frontend\FormAssets::hooks();
        \LeenkatForms\Settings\SiteSettings::hooks();
        \LeenkatForms\Admin\ToolsPage::hooks();
        \LeenkatForms\Files\Download::hooks();
        \LeenkatForms\Files\EmailDownload::hooks();
        \LeenkatForms\Admin\FileSecurityPage::hooks();
        \LeenkatForms\Files\EntryDeletion::hooks();
        \LeenkatForms\Submissions\PublicEndpoint::hooks();
        \LeenkatForms\Admin\BuilderApi::hooks();
        \LeenkatForms\Security\SecurityChallenge::hooks();
        \LeenkatForms\Security\SpamKeywords::hooks();
        \LeenkatForms\Security\CaptchaService::hooks();
        \LeenkatForms\Security\AkismetService::hooks();
        \LeenkatForms\Workflow\DeliveryWorker::hooks();
        \LeenkatForms\Interfaces\Editors::hooks();
        \LeenkatForms\Interfaces\PageBuilders::hooks();
    }

    public static function upgrade(): void {
        if ((int)get_option('lkf_db_version',0) < \LeenkatForms\Infrastructure\Database\Migrations::VERSION) {
            if (!\LeenkatForms\Infrastructure\Database\Migrations::install()) { return; }
        }
        \LeenkatForms\Bootstrap\Lifecycle::grantCapabilities();
        if (get_option('lkf_installed_version') !== LEENKAT_FORMS_VERSION) {
            update_option('lkf_installed_version', LEENKAT_FORMS_VERSION, false);
        }
    }

    public static function registerMenu(): void {
        add_menu_page(
            __('Leenkat Forms', 'leenkat-forms'),
            __('Leenkat Forms', 'leenkat-forms'),
            'lkf_manage_forms',
            'leenkat-forms',
            [self::class, 'renderDashboard'],
            plugins_url('assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE),
            58
        );
        add_submenu_page('leenkat-forms', __('Dashboard', 'leenkat-forms'), __('Dashboard', 'leenkat-forms'), 'lkf_manage_forms', 'leenkat-forms', [self::class, 'renderDashboard']);
        $listHook = add_submenu_page('leenkat-forms', __('All Forms', 'leenkat-forms'), __('All Forms', 'leenkat-forms'), 'lkf_manage_forms', 'leenkat-forms-all', [\LeenkatForms\Admin\FormManager::class, 'render']);
        if ($listHook) add_action('load-'.$listHook,[\LeenkatForms\Admin\ManagementPage::class,'load']);
        add_submenu_page('leenkat-forms', __('Add New', 'leenkat-forms'), __('Add New', 'leenkat-forms'), 'lkf_manage_forms', 'leenkat-forms-new', [\LeenkatForms\Admin\FormManager::class, 'renderNew']);
        $listHook = add_submenu_page('leenkat-forms', __('Entries', 'leenkat-forms'), __('Entries', 'leenkat-forms'), 'lkf_view_entries', 'leenkat-forms-entries', [\LeenkatForms\Admin\EntryManager::class, 'render']);
        if ($listHook) add_action('load-'.$listHook,[\LeenkatForms\Admin\ManagementPage::class,'load']);
        add_submenu_page('leenkat-forms', __('Settings', 'leenkat-forms'), __('Settings', 'leenkat-forms'), 'manage_options', 'leenkat-forms-settings', [\LeenkatForms\Admin\GlobalSettings::class, 'render']);
        add_submenu_page('leenkat-forms',__('Attachment Security','leenkat-forms'),__('Attachment Security','leenkat-forms'),'manage_options','leenkat-forms-file-security',[\LeenkatForms\Admin\FileSecurityPage::class,'render']);
        if(current_user_can('lkf_manage_forms')||current_user_can('lkf_view_entries'))add_submenu_page('leenkat-forms', __('Tools', 'leenkat-forms'), __('Tools', 'leenkat-forms'), 'read', 'leenkat-forms-tools', [\LeenkatForms\Admin\ToolsPage::class,'render']);
    }

    public static function brandingAssets(): void {
        wp_enqueue_style('lkf-branding', plugins_url('assets/admin/branding.css', LEENKAT_FORMS_FILE), [], LEENKAT_FORMS_VERSION);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
        $page=isset($_GET['page'])&&is_string($_GET['page'])?sanitize_text_field(wp_unslash($_GET['page'])):'';
        if(is_admin()&&in_array($page,['leenkat-forms','leenkat-forms-all','leenkat-forms-entries','leenkat-forms-settings','leenkat-forms-file-security','leenkat-forms-tools','leenkat-forms-new'],true)){
            wp_enqueue_style('lkf-admin-theme',plugins_url('assets/admin/admin-theme.css',LEENKAT_FORMS_FILE),['lkf-branding'],LEENKAT_FORMS_VERSION.'.'.filemtime(LEENKAT_FORMS_DIR.'assets/admin/admin-theme.css'));
        }
    }

    public static function renderDashboard(): void {
        \LeenkatForms\Admin\DashboardPage::render();
    }
}

