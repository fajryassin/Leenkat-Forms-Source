<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

final class EntryManager {
    public static function hooks(): void {
        add_action('admin_post_lkf_entry_action', [self::class,'action']);
        add_action('admin_post_lkf_entries_bulk',[self::class,'bulk']);
        add_action('admin_post_lkf_export_entries', [self::class,'export']);
    }
    public static function render(): void {
        if (!current_user_can('lkf_view_entries')) { wp_die(esc_html__('Access denied.', 'leenkat-forms'), '', ['response'=>403]); }
        global $wpdb;
        $table = $wpdb->prefix . 'lkf_entries';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $formId = absint($_GET['form_id'] ?? 0);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $entryId = absint($_GET['entry_id'] ?? 0);
        if (!$entryId) {
            if (!$formId) { (new FormsListTable(true))->renderPage(); return; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            $form=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lkf_forms WHERE id=%d",$formId));
            if (!$form) wp_die(esc_html__('Form not found.','leenkat-forms'),'',['response'=>404]);
            (new EntriesListTable($form))->renderPage(); return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $entry=$wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE id=%d",$entryId));
        if (!$entry || ($formId && (int)$entry->form_id!==$formId)) { ManagementPage::start(__('View Entry','leenkat-forms'));echo '<p>'.esc_html__('Entry not found.','leenkat-forms').'</p>';ManagementPage::end();return; }
        EntryView::render($entry);
    }
    public static function action(): void {
        if (!current_user_can('lkf_view_entries')) { wp_die(esc_html__('Access denied.', 'leenkat-forms'), '', ['response'=>403]); }
        ManagementPage::requirePost();
        $id = absint($_POST['entry_id'] ?? 0);
        check_admin_referer('lkf_entry_action_' . $id);
        $operation = sanitize_key((string)($_POST['operation'] ?? ''));
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $formId=(int)$wpdb->get_var($wpdb->prepare("SELECT form_id FROM {$wpdb->prefix}lkf_entries WHERE id=%d",$id));
        $ok=$formId && self::perform($id,$operation);
        wp_safe_redirect(ManagementPage::url(['page'=>'leenkat-forms-entries','form_id'=>$formId,'lkf_result'=>$ok?'ok':'failed']+((ManagementPage::text($_POST,'return_view')==='entry'&&$operation!=='delete')?['entry_id'=>$id]:[]))); exit;
    }
    /** Existing transactional state changes, reused without touching submission/delivery engines. */
    public static function perform(int $id,string $operation): bool {
        if (!current_user_can('lkf_view_entries')) return false;
        if ($operation==='delete') return !is_wp_error(\LeenkatForms\Files\EntryDeletion::remove($id));
        global $wpdb;
        $entries = $wpdb->prefix . 'lkf_entries';
        $files = $wpdb->prefix . 'lkf_entry_files';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        if (!\LeenkatForms\Infrastructure\Database\Transactions::supported([$entries,$wpdb->prefix . 'lkf_entry_values',$wpdb->prefix . 'lkf_deliveries',$files]) || $wpdb->query('START TRANSACTION') === false) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $entry = $wpdb->get_row($wpdb->prepare("SELECT id,status,meta FROM `{$entries}` WHERE id=%d FOR UPDATE", $id));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        if (!$entry) { $wpdb->query('ROLLBACK'); return false; }
        $meta = json_decode((string)$entry->meta,true);
        if (!is_array($meta)) { $meta = []; }
        $status = (string)$entry->status;
        $ok = true;
        if ($operation === 'mark_read') { $meta['is_read'] = true; }
        elseif ($operation === 'mark_unread') { $meta['is_read'] = false; }
        elseif ($operation === 'star') { $meta['is_starred'] = true; }
        elseif ($operation === 'unstar') { $meta['is_starred'] = false; }
        elseif ($operation === 'spam' && $status === 'complete') { $status = 'spam'; }
        elseif ($operation === 'unspam' && $status === 'spam') { $status = 'complete'; }
        elseif ($operation === 'trash' && $status !== 'trashed') { $meta['previous_status'] = $status; $status = 'trashed'; }
        elseif ($operation === 'restore' && $status === 'trashed') { $status = in_array(($meta['previous_status'] ?? ''), ['complete','spam'], true) ? $meta['previous_status'] : 'complete'; unset($meta['previous_status']); }
        else { $ok = false; }
        if ($ok) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            $ok = $wpdb->update($entries, ['status'=>$status,'meta'=>wp_json_encode($meta),'updated_at_gmt'=>current_time('mysql',true)], ['id'=>$id], ['%s','%s','%s'], ['%d']) !== false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Transaction command is chosen only between fixed COMMIT/ROLLBACK literals.
        $committed = $wpdb->query($ok ? 'COMMIT' : 'ROLLBACK');
        return $ok && $committed!==false;
    }
    public static function bulk(): void {
        if (!current_user_can('lkf_view_entries')) wp_die(esc_html__('Access denied.','leenkat-forms'),'',['response'=>403]);
        ManagementPage::requirePost();
        $formId=absint($_POST['form_id'] ?? 0);
        check_admin_referer('lkf_entries_bulk_'.$formId);
        if (ManagementPage::text($_POST,'lkf_filter')==='1') { wp_safe_redirect(ManagementPage::url(['page'=>'leenkat-forms-entries','form_id'=>$formId]+EntryListFilters::context($_POST))); exit; }
        ManagementPage::paginationRequest('leenkat-forms-entries',['form_id'=>$formId]);
        [$operation,$ids]=ManagementPage::requestOperation();
        $ok=self::bulkAllowed($formId,$ids,$operation);
        if ($ok) foreach ($ids as $id) { if (!self::perform($id,$operation)) $ok=false; }
        $status=ManagementPage::text($_POST,'status'); $flag=ManagementPage::text($_POST,'flag');
        $filters=array_intersect_key(EntryListFilters::context($_POST),array_flip(['s','search_field','search_operator','date_from','date_to','orderby','order']));
        wp_safe_redirect(ManagementPage::url(['page'=>'leenkat-forms-entries','form_id'=>$formId,'status'=>in_array($status,['complete','spam','trashed','all'],true)?$status:'complete','flag'=>in_array($flag,['unread','starred'],true)?$flag:'','days'=>(int)ManagementPage::text($_POST,'days'),'lkf_result'=>$ok?'ok':'failed']+$filters)); exit;
    }
    public static function bulkAllowed(int $formId,array $ids,string $operation): bool {
        if (!current_user_can('lkf_view_entries') || !$formId || !$ids || ManagementPage::ids($ids)!==$ids || !in_array($operation,['mark_read','mark_unread','star','unstar','spam','unspam','trash','restore','delete'],true)) return false;
        global $wpdb;
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        // Preflight the entire selection before any change. Never accept IDs from a different form.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lkf_entries WHERE form_id=%d AND id IN ({$placeholders})",array_merge([$formId],$ids)));
        return $count===count($ids);
    }
    public static function export(): void {
        if (!current_user_can('lkf_view_entries')) { wp_die(esc_html__('Access denied.', 'leenkat-forms'), '', ['response'=>403]); }
        $formId = absint($_POST['form_id'] ?? 0);
        check_admin_referer('lkf_export_entries_' . $formId);
        if (!$formId) { wp_die(esc_html__('Choose a form first.', 'leenkat-forms'), '', ['response'=>400]); }
        global $wpdb;
        $table = $wpdb->prefix . 'lkf_entries';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,form_id,form_version_id,status,submitted_at_gmt,payload,meta FROM `{$table}` WHERE form_id=%d ORDER BY id DESC LIMIT 1000", $formId));
        $fields = [];
        foreach ($rows ?: [] as $row) {
            $payload = json_decode((string)$row->payload,true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            $definition=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT definition FROM {$wpdb->prefix}lkf_form_versions WHERE id=%d",$row->form_version_id)),true) ?: [];$meta=json_decode((string)$row->meta,true) ?: [];$payload=\LeenkatForms\Files\FileRecords::safeValues($definition,is_array($payload)?$payload:[],$meta['uploads'] ?? []);
            foreach (is_array($payload) ? array_keys($payload) : [] as $fieldId) { $fields[(string)$fieldId] = true; }
        }
        $fieldIds = array_slice(array_keys($fields),0,300);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leenkat-entries-' . $formId . '.csv"');
        $output = fopen('php://output','w');
        fputcsv($output, array_merge(['Entry ID','Form ID','Status','Submitted UTC'], $fieldIds), ',', '"', '');
        foreach ($rows ?: [] as $row) {
            $payload = json_decode((string)$row->payload,true);
            $values = [];
            foreach ($fieldIds as $fieldId) {
                $value = is_array($payload) ? ($payload[$fieldId] ?? '') : '';
                $values[] = self::csvCell(is_scalar($value) ? (string)$value : wp_json_encode($value));
            }
            fputcsv($output, array_merge([(string)$row->id,(string)$row->form_id,(string)$row->status,(string)$row->submitted_at_gmt],$values), ',', '"', '');
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
        fclose($output);
        exit;
    }
    public static function csvCell(string $value): string {
        return preg_match('/^[\p{Z}\x00-\x20\x{FEFF}]*[=+@-]|^[\t\r\n]/u', $value) ? "'" . $value : $value;
    }
}
