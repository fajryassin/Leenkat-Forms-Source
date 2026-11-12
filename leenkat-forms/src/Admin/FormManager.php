<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Forms\Publisher;
use LeenkatForms\Infrastructure\Database\Transactions;

final class FormManager {
    public static function hooks(): void { add_action('admin_post_lkf_form_action', [self::class,'formAction']); add_action('admin_post_lkf_forms_bulk',[self::class,'bulk']); }
    private static function table(): string { global $wpdb; return $wpdb->prefix . 'lkf_forms'; }
    public static function render(): void {
        if (!current_user_can('lkf_manage_forms')) wp_die(esc_html__('Access denied.','leenkat-forms'),'',['response'=>403]);
        (new FormsListTable())->renderPage();
    }
    public static function renderNew(): void {
        if (!current_user_can('lkf_manage_forms')) { wp_die(esc_html__('Access denied.', 'leenkat-forms'), '', ['response'=>403]); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $id = absint($_GET['form_id'] ?? 0);
        if ($id) {
            global $wpdb;
            $table = self::table();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE id=%d",$id))) { wp_die(esc_html__('Form not found.', 'leenkat-forms'), '', ['response'=>404]); }
        }
        BuilderUi::render($id);
    }
    public static function formAction(): void {
        if (!current_user_can('lkf_manage_forms')) { wp_die(esc_html__('Access denied.', 'leenkat-forms'), '', ['response'=>403]); }
        ManagementPage::requirePost();
        $id = absint($_POST['form_id'] ?? 0);
        check_admin_referer('lkf_form_action_' . $id);
        $operation = sanitize_key((string)($_POST['operation'] ?? ''));
        $ok=self::perform($id,$operation);
        wp_safe_redirect(add_query_arg(['page'=>'leenkat-forms-all','lkf_result'=>$ok ? 'ok' : 'failed'],admin_url('admin.php')));
        exit;
    }
    /** Shared by the existing single-record route and the new list-table route. */
    public static function perform(int $id,string $operation): bool {
        if (!current_user_can('lkf_manage_forms')) return false;
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $form = $wpdb->get_row($wpdb->prepare("SELECT id,status FROM `{$table}` WHERE id=%d",$id));
        if (!$form) return false;
        $ok = false;
        if ($operation === 'duplicate' && $form->status !== 'trashed') { $request=new \WP_REST_Request('POST'); $request->set_param('id',$id); return !is_wp_error(BuilderApi::duplicate($request)); }
        if ($operation === 'publish' && $form->status !== 'trashed') { $ok = Publisher::publish($id); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        elseif ($operation === 'unpublish' && $form->status === 'published') { $ok = $wpdb->update($table,['status'=>'draft','updated_at_gmt'=>current_time('mysql',true)],['id'=>$id],['%s','%s'],['%d']) !== false; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        elseif ($operation === 'trash' && $form->status !== 'trashed') { $ok = $wpdb->update($table,['status'=>'trashed','updated_at_gmt'=>current_time('mysql',true)],['id'=>$id],['%s','%s'],['%d']) !== false; }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        elseif ($operation === 'restore' && $form->status === 'trashed') { $ok = $wpdb->update($table,['status'=>'draft','updated_at_gmt'=>current_time('mysql',true)],['id'=>$id],['%s','%s'],['%d']) !== false; }
        elseif ($operation === 'delete' && $form->status === 'trashed') {
            $versions = $wpdb->prefix . 'lkf_form_versions'; $entries = $wpdb->prefix . 'lkf_entries';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if (Transactions::supported([$table,$versions,$entries]) && $wpdb->query('START TRANSACTION') !== false) {
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
                $locked = $wpdb->get_row($wpdb->prepare("SELECT status FROM `{$table}` WHERE id=%d FOR UPDATE",$id));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
                $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$entries}` WHERE form_id=%d",$id));
                if ($wpdb->last_error === '' && $locked && $locked->status === 'trashed' && !$count) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                    $ok = $wpdb->delete($versions,['form_id'=>$id],['%d']) !== false && $wpdb->delete($table,['id'=>$id],['%d']) !== false;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Transaction command is chosen only between fixed COMMIT/ROLLBACK literals.
                $wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
            }
        }
        return $ok;
    }
    public static function bulk(): void {
        if (!current_user_can('lkf_manage_forms')) wp_die(esc_html__('Access denied.','leenkat-forms'),'',['response'=>403]);
        ManagementPage::requirePost();
        check_admin_referer('lkf_forms_bulk');
        ManagementPage::paginationRequest('leenkat-forms-all');
        [$operation,$ids]=ManagementPage::requestOperation();
        $ok=$ids && in_array($operation,['publish','unpublish','duplicate','trash','restore','delete'],true);
        if ($ok) foreach ($ids as $id) { if (!self::perform($id,$operation)) $ok=false; }
        $status=ManagementPage::text($_POST,'status');
        wp_safe_redirect(ManagementPage::url(['page'=>'leenkat-forms-all','status'=>in_array($status,['draft','published','trashed'],true)?$status:'','lkf_result'=>$ok?'ok':'failed']));
        exit;
    }
}
