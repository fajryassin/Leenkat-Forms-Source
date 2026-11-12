<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;
if (!defined('ABSPATH')) { exit; }
if (!class_exists('WP_List_Table')) require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';

abstract class ManagementTable extends \WP_List_Table {
    /** WordPress uses action/action2 by default; reserve action for the admin-post route. */
    protected function bulk_actions($which = '') {
        $actions=$this->get_bulk_actions();
        if (!$actions) return;
        $suffix=$which==='bottom' ? '2' : '';
        echo '<label for="lkf-bulk-'.esc_attr($which).'" class="screen-reader-text">'.esc_html__('Select bulk action','leenkat-forms').'</label><select name="operation'.esc_attr($suffix).'" id="lkf-bulk-'.esc_attr($which).'"><option value="-1">'.esc_html__('Bulk actions','leenkat-forms').'</option>';
        foreach ($actions as $value=>$caption) echo '<option value="'.esc_attr((string)$value).'">'.esc_html($caption).'</option>';
        echo '</select><button class="button action" name="lkf_apply" value="'.esc_attr($which).'">'.esc_html__('Apply','leenkat-forms').'</button>';
    }
    protected function display_tablenav($which) {
        // Core's additional bulk nonce is unnecessary: each list form has its own route nonce.
        echo '<div class="tablenav '.esc_attr($which).'">';
        if ($this->has_items()) { echo '<div class="alignleft actions bulkactions">'; $this->bulk_actions($which); echo '</div>'; }
        $this->extra_tablenav($which); $this->pagination($which);
        echo '<br class="clear"></div>';
    }
    protected function get_table_classes() { return ['widefat','fixed','striped','table-view-list','lkf-list-table']; }
    // row_actions() already supplies the responsive toggle on our primary cells.
    protected function handle_row_actions($item,$column_name,$primary) { return ''; }
    public function no_items() { esc_html_e('No items found.','leenkat-forms'); }
    protected function configure(array $sortable, string $primary): void {
        $columns=$this->get_columns();
        $this->_column_headers=[$columns,get_hidden_columns($this->screen),$sortable,$primary];
    }
    protected function perPage(string $option): int { return max(1,min(100,$this->get_items_per_page($option,20))); }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
    protected function sortDirection(): string { return strtolower(ManagementPage::text($_GET,'order'))==='asc' ? 'ASC' : 'DESC'; }
    public function column_cb($item) { return '<input type="checkbox" name="item_ids[]" value="'.(int)$item->id.'" aria-label="'.esc_attr(sprintf(/* translators: %d: item ID. */ __('Select item #%d','leenkat-forms'),$item->id)).'">'; }
    public function pageForm(string $route, string $nonce, array $context=[]): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="lkf-list-form">';
        wp_nonce_field($nonce);
        echo '<input type="hidden" name="action" value="'.esc_attr($route).'">';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        foreach (['s','orderby','order'] as $name) $context[$name]=ManagementPage::text($_GET,$name);
        foreach ($context as $name=>$value) echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'">';
        $this->display(); echo '</form>';
    }
}
