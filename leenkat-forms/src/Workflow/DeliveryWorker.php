<?php
declare(strict_types=1);
namespace LeenkatForms\Workflow;

/** At-least-once email delivery. External mail transport cannot guarantee exactly once. */
final class DeliveryWorker {
    public static function hooks(): void {
        add_action('lkf_send_delivery', [self::class,'process'], 10, 1);
        add_action('lkf_reconcile_deliveries', [self::class,'reconcile']);
        add_action('init', [self::class,'ensureReconciler'], 20);
    }
    public static function ensureReconciler(): void {
        if (!wp_next_scheduled('lkf_reconcile_deliveries')) {
            wp_schedule_event(time() + 300, 'hourly', 'lkf_reconcile_deliveries');
        }
    }
    public static function schedule(int $id, int $timestamp = 0): bool {
        $timestamp = $timestamp ?: time() + 5;
        $args = [$id];
        if (function_exists('as_schedule_single_action') && class_exists('ActionScheduler') && \ActionScheduler::is_initialized()) {
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('lkf_send_delivery', $args, 'leenkat-forms')) { return true; }
            return (int)as_schedule_single_action($timestamp, 'lkf_send_delivery', $args, 'leenkat-forms', true) > 0;
        }
        if (wp_next_scheduled('lkf_send_delivery', $args)) { return true; }
        return wp_schedule_single_event($timestamp, 'lkf_send_delivery', $args) === true;
    }
    public static function process(int $id): void {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        global $wpdb;$entryId=(int)$wpdb->get_var($wpdb->prepare("SELECT entry_id FROM {$wpdb->prefix}lkf_deliveries WHERE id=%d",$id));$lease=null;
        try{if($entryId&&\LeenkatForms\Files\FileRecords::notificationFiles($entryId))$lease=\LeenkatForms\Files\FileLease::acquire($entryId);self::processWithLease($id);}catch(\Throwable){/* Pending jobs retain their trusted references for reconciliation. */}finally{\LeenkatForms\Files\FileLease::release($lease);}
    }
    private static function processWithLease(int $id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lkf_deliveries';
        $now = current_time('mysql',true);
        // The conditional UPDATE is the claim; only one worker can send this row.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $claimed = $wpdb->query($wpdb->prepare("UPDATE `{$table}` SET status='processing',attempt_count=attempt_count+1,updated_at_gmt=%s WHERE id=%d AND status IN ('pending','retry') AND (next_attempt_at_gmt IS NULL OR next_attempt_at_gmt<=%s)", $now, $id, $now));
        if ($claimed !== 1) { return; }
        $entries = $wpdb->prefix . 'lkf_entries';
        $versions = $wpdb->prefix . 'lkf_form_versions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $row = $wpdb->get_row($wpdb->prepare("SELECT d.id,d.kind,d.destination_key,d.attempt_count,e.id AS entry_id,e.form_id,e.user_id,e.submitted_at_gmt,e.meta,e.status AS entry_status,e.payload,v.definition FROM `{$table}` d INNER JOIN `{$entries}` e ON e.id=d.entry_id INNER JOIN `{$versions}` v ON v.id=e.form_version_id WHERE d.id=%d", $id));
        if (!$row || $row->entry_status !== 'complete') { self::finish($id, 'cancelled', 'entry_unavailable'); return; }
        $values = json_decode((string)$row->payload,true);
        $definition = json_decode((string)$row->definition,true);
        if(!is_array($values) || !is_array($definition)) { self::finish($id,'failed','invalid_definition');return; }
        $settings=\LeenkatForms\Forms\NotificationSettings::normalize($definition);
        $notificationId=$row->kind==='email_admin'?'legacy_admin':($row->kind==='email_user'?'legacy_user':$row->destination_key);
        $config=null;foreach($settings['items'] as $item)if($item['id']===$notificationId)$config=$item;
        if(empty($settings['enabled']) || !$config) { self::finish($id,'cancelled','notification_unavailable');return; }
        $meta=json_decode((string)$row->meta,true) ?: [];
        $user=$row->user_id?get_userdata((int)$row->user_id):null;
        $values=\LeenkatForms\Files\FileRecords::safeValues($definition,$values,$meta['uploads'] ?? []);
        $context=['entry_id'=>(int)$row->entry_id,'form_id'=>(int)$row->form_id,'entry_date'=>get_date_from_gmt($row->submitted_at_gmt,'Y-m-d H:i:s'),'user_email'=>$user?$user->user_email:'','uploads'=>\LeenkatForms\Files\FileRecords::notificationFiles((int)$row->entry_id),'field_parts'=>$meta['field_parts'] ?? []];
        $context['field_choices']=$meta['field_choices'] ?? [];
        $result=Notifications::send($config,$definition,$values,$context);
        if($result==='skipped') { self::finish($id,'cancelled','condition_or_recipient_empty');return; }
        $sent=$result==='sent';
        if ($sent) { self::finish($id, 'sent', null); return; }
        $attempts = (int)$row->attempt_count;
        if ($attempts >= 4) { self::finish($id, 'failed', 'mail_failed'); return; }
        $delay = min(3600, 60 * (2 ** $attempts));
        $next = gmdate('Y-m-d H:i:s', time() + $delay);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $wpdb->update($table, ['status'=>'retry','next_attempt_at_gmt'=>$next,'last_error_code'=>'mail_failed','updated_at_gmt'=>current_time('mysql',true)], ['id'=>$id], ['%s','%s','%s','%s'], ['%d']);
        self::schedule($id, time() + $delay);
    }
    public static function renderTemplate(string $template, array $definition, array $values): string {
        return NotificationMessage::render($template,$definition,$values);
    }

    private static function finish(int $id, string $status, ?string $error): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $wpdb->update($wpdb->prefix . 'lkf_deliveries', ['status'=>$status,'last_error_code'=>$error,'updated_at_gmt'=>current_time('mysql',true)], ['id'=>$id], ['%s','%s','%s'], ['%d']);
    }
    public static function reconcile(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lkf_deliveries';
        $now = current_time('mysql',true);
        $stale = gmdate('Y-m-d H:i:s', time() - 900);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $wpdb->query($wpdb->prepare("UPDATE `{$table}` SET status='retry',next_attempt_at_gmt=%s,last_error_code='interrupted',updated_at_gmt=%s WHERE status='processing' AND updated_at_gmt<%s", $now,$now,$stale));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM `{$table}` WHERE status IN ('pending','retry') AND (next_attempt_at_gmt IS NULL OR next_attempt_at_gmt<=%s) ORDER BY id ASC LIMIT 50", $now));
        foreach ($ids ?: [] as $id) { self::schedule((int)$id); }
    }
}
