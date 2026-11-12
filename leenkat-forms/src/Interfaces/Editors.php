<?php
declare(strict_types=1);
namespace LeenkatForms\Interfaces;

use LeenkatForms\Frontend\FormRenderer;

final class Editors {
    public static function hooks(): void {
        add_action('init', [self::class,'block']);
        // WordPress collects this hook separately for the editor content iframe.
        add_action('enqueue_block_assets', static function(): void {
            if (is_admin()) { \LeenkatForms\Frontend\FormAssets::enqueue([], true); }
        });
        if (is_admin()) {
            add_action('media_buttons', [self::class,'classicButton']);
            add_action('admin_footer', [self::class,'classicDialog']);
            add_action('admin_enqueue_scripts', [self::class,'classicAssets']);
        }
    }
    public static function choices(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lkf_forms';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $rows = $wpdb->get_results("SELECT id,draft_definition FROM `{$table}` WHERE status='published' ORDER BY id DESC LIMIT 500");
        $choices = [];
        foreach ($rows ?: [] as $row) {
            $definition = json_decode((string)$row->draft_definition,true);
            $choices[(int)$row->id] = is_array($definition) ? (string)($definition['title'] ?? ('#' . $row->id)) : ('#' . $row->id);
        }
        return $choices;
    }
    public static function block(): void {
        wp_register_script('lkf-block-editor', plugins_url('assets/admin/block.js', LEENKAT_FORMS_FILE), ['wp-blocks','wp-element','wp-components','wp-block-editor','wp-server-side-render','wp-i18n'], LEENKAT_FORMS_VERSION, true);
        wp_set_script_translations('lkf-block-editor', 'leenkat-forms');
        wp_add_inline_script('lkf-block-editor', 'window.lkfBlockLogo=' . wp_json_encode(plugins_url('assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE)) . ';', 'before');
        if (is_admin()) { wp_add_inline_script('lkf-block-editor', 'window.lkfBlockForms=' . wp_json_encode(self::choices(), JSON_HEX_TAG) . ';', 'before'); }
        register_block_type('leenkat-forms/form', [
            'api_version'=>3,
            'editor_script'=>'lkf-block-editor',
            'attributes'=>['formId'=>['type'=>'integer','default'=>0]],
            'render_callback'=>static fn(array $attributes): string => FormRenderer::shortcode(['id'=>(int)($attributes['formId'] ?? 0)]),
        ]);
    }
    public static function classicAssets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || !current_user_can('edit_posts')) { return; }
        wp_enqueue_script('leenkat-forms-classic-editor', plugins_url('assets/admin/classic-editor.js', LEENKAT_FORMS_FILE), [], LEENKAT_FORMS_VERSION, true);
    }
    public static function classicButton(): void {
        if (!current_user_can('edit_posts') || !self::choices()) { return; }
        echo '<button type="button" class="button" id="lkf-add-form"><img src="' . esc_url(plugins_url('assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE)) . '" width="20" height="20" alt="">' . esc_html__('Add Leenkat Form', 'leenkat-forms') . '</button>';
    }
    public static function classicDialog(): void {
        if (!current_user_can('edit_posts') || !self::choices()) { return; }
        echo '<dialog id="lkf-form-dialog"><form method="dialog"><label for="lkf-insert-id">' . esc_html__('Choose a form', 'leenkat-forms') . '</label> <select id="lkf-insert-id">';
        foreach (self::choices() as $id=>$title) { echo '<option value="' . esc_attr((string)$id) . '">' . esc_html($title) . '</option>'; }
        echo '</select> <button value="insert">' . esc_html__('Insert', 'leenkat-forms') . '</button> <button value="cancel">' . esc_html__('Cancel', 'leenkat-forms') . '</button></form></dialog>';
    }
}
