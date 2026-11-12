<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

final class BuilderUi {
    public static function render(int $formId): void {
        if (!current_user_can('lkf_manage_forms')) { wp_die(esc_html__('Access denied.', 'leenkat-forms'), '', ['response'=>403]); }
        wp_enqueue_style('wp-components');
        wp_enqueue_media();
        wp_enqueue_editor();
        wp_enqueue_style('lkf-builder', plugins_url('assets/admin/builder.css', LEENKAT_FORMS_FILE), [], LEENKAT_FORMS_VERSION . '.' . (string)filemtime(LEENKAT_FORMS_DIR . 'assets/admin/builder.css'));
        wp_enqueue_script('lkf-pointer-sort', plugins_url('assets/admin/pointer-sort.js', LEENKAT_FORMS_FILE), [], LEENKAT_FORMS_VERSION . '.' . (string)filemtime(LEENKAT_FORMS_DIR . 'assets/admin/pointer-sort.js'), true);
        wp_enqueue_script('lkf-builder-model', plugins_url('assets/admin/builder-model.js', LEENKAT_FORMS_FILE), [], LEENKAT_FORMS_VERSION . '.' . (string)filemtime(LEENKAT_FORMS_DIR . 'assets/admin/builder-model.js'), true);
        wp_enqueue_script('lkf-notifications',plugins_url('assets/admin/notifications.js',LEENKAT_FORMS_FILE),['wp-element','wp-components','wp-i18n'],LEENKAT_FORMS_VERSION . '.' . (string)filemtime(LEENKAT_FORMS_DIR . 'assets/admin/notifications.js'),true);
        wp_enqueue_script('lkf-confirmations',plugins_url('assets/admin/confirmations.js',LEENKAT_FORMS_FILE),['wp-element','wp-components','wp-i18n','editor'],LEENKAT_FORMS_VERSION . '.' . (string)filemtime(LEENKAT_FORMS_DIR.'assets/admin/confirmations.js'),true);
        wp_enqueue_script('lkf-security',plugins_url('assets/admin/security.js',LEENKAT_FORMS_FILE),['wp-element','wp-components','wp-i18n'],LEENKAT_FORMS_VERSION . '.' . (string)filemtime(LEENKAT_FORMS_DIR.'assets/admin/security.js'),true);
        wp_enqueue_script('lkf-captcha-settings',plugins_url('assets/admin/captcha.js',LEENKAT_FORMS_FILE),['wp-element','wp-components','wp-i18n','lkf-security'],LEENKAT_FORMS_VERSION.'.'.filemtime(LEENKAT_FORMS_DIR.'assets/admin/captcha.js'),true);
        wp_enqueue_script('lkf-builder', plugins_url('assets/admin/builder.js', LEENKAT_FORMS_FILE), ['wp-element','wp-components','wp-i18n','lkf-builder-model','lkf-pointer-sort','lkf-notifications','lkf-confirmations','lkf-security','lkf-captcha-settings'], LEENKAT_FORMS_VERSION . '.' . (string)filemtime(LEENKAT_FORMS_DIR . 'assets/admin/builder.js'), true);
        wp_set_script_translations('lkf-builder', 'leenkat-forms');
        wp_set_script_translations('lkf-notifications','leenkat-forms');
        wp_set_script_translations('lkf-captcha-settings','leenkat-forms');
        $config = [
            'formId'=>$formId,
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
            'initialPreview'=>isset($_GET['preview']) && $_GET['preview']==='1',
            'siteName'=>get_bloginfo('name'),
            'transferApi'=>rest_url('leenkat-forms/v1/admin/transfer/'),
            'siteHost'=>(string)wp_parse_url(home_url('/'),PHP_URL_HOST),
            'adminEmail'=>get_option('admin_email',''),
            'senderTagValues'=>array_combine(['admin_email','site_name','site_url'],array_map(static fn($tag)=>\LeenkatForms\Workflow\NotificationMessage::render('{'.$tag.'}',[],[]),['admin_email','site_name','site_url'])),
            'emailTemplatePreviews'=>EmailTemplatePreview::templates(),
            'confirmationPages'=>array_map(static fn($page)=>['value'=>(int)$page->ID,'label'=>$page->post_title],get_posts(['post_type'=>'page','post_status'=>'publish','has_password'=>false,'numberposts'=>-1,'orderby'=>'title','order'=>'ASC'])),
            'logoUrl'=>plugins_url('assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE),
            'addressData'=>\LeenkatForms\Forms\AddressField::data(),
            'choiceSources'=>\LeenkatForms\Forms\ChoiceField::sources(),
            'choiceIcons'=>\LeenkatForms\Forms\ChoiceField::icons(),
            'choicePlaceholder'=>plugins_url('assets/images/choice-placeholder.svg', LEENKAT_FORMS_FILE),
            'api'=>rest_url('leenkat-forms/v1/admin/forms'),
            'nonce'=>wp_create_nonce('wp_rest'),
            'listUrl'=>admin_url('admin.php?page=leenkat-forms-all'),
            'editUrl'=>admin_url('admin.php?page=leenkat-forms-new&form_id='),
            'entriesUrl'=>admin_url('admin.php?page=leenkat-forms-entries&form_id='),
            'spamKeywordsApi'=>rest_url('leenkat-forms/v1/admin/spam-keywords'),
            'securityIconsUrl'=>plugins_url('assets/images/anti-spam/', LEENKAT_FORMS_FILE),
            'captchaSettingsUrl'=>admin_url('admin.php?page=leenkat-forms-settings&view=captcha'),
            'captcha'=>\LeenkatForms\Security\CaptchaSettings::builderState(),
            'akismet'=>\LeenkatForms\Security\AkismetService::state(),
            'akismetStateApi'=>rest_url('leenkat-forms/v1/admin/akismet-state'),
            'captchaStateApi'=>rest_url('leenkat-forms/v1/admin/captcha-state'),
        ];
        $config=array_replace($config,\LeenkatForms\Settings\SiteSettings::builderState());
        $config['siteStateApi']=rest_url('leenkat-forms/v1/admin/site-state');
        global $wpdb;
        $tags=[];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        foreach($wpdb->get_col("SELECT draft_definition FROM {$wpdb->prefix}lkf_forms WHERE status<>'trashed' ORDER BY id DESC LIMIT 500") as $encoded) {
            foreach(json_decode($encoded,true)['settings']['general']['tags'] ?? [] as $tag) { if(is_string($tag))$tags[$tag]=$tag; }
        }
        $config['tags']=array_values($tags);
        $config['choiceOptions'] = [];
        foreach ($config['choiceSources'] as $mode=>$sources) {
            foreach ($sources as $source=>$caption) { $config['choiceOptions'][$mode][$source] = \LeenkatForms\Forms\ChoiceField::items(['dynamic_choices'=>$mode,'dynamic_source'=>$source]); }
        }
        wp_add_inline_script('lkf-builder', 'window.lkfBuilderConfig=' . wp_json_encode($config, JSON_HEX_TAG) . ';', 'before');
        echo '<div class="wrap"><div id="lkf-builder-root"><p>' . esc_html__('Loading form builder…', 'leenkat-forms') . '</p></div></div>';
    }
}




