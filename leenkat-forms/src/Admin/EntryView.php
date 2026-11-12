<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

/** Read-only presentation of an existing entry and its immutable field labels. */
final class EntryView {
    public static function render(object $entry): void {
        if (!current_user_can('lkf_view_entries')) wp_die(esc_html__('Access denied.','leenkat-forms'),'',['response'=>403]);
        global $wpdb;
        $formId=(int)$entry->form_id;$entryId=(int)$entry->id;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $definition=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT definition FROM {$wpdb->prefix}lkf_form_versions WHERE id=%d AND form_id=%d",$entry->form_version_id,$formId)),true) ?: [];
        $meta=json_decode($entry->meta ?? '',true) ?: [];
        $values=\LeenkatForms\Files\FileRecords::safeValues($definition,json_decode($entry->payload,true) ?: [],$meta['uploads'] ?? []);
        $back=ManagementPage::url(['page'=>'leenkat-forms-entries','form_id'=>$formId,'status'=>$entry->status]);
        $tools='<a class="page-title-action lkf-back-button" href="'.esc_url($back).'"><span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span> '.esc_html__('Back to All Entries','leenkat-forms').'</a>'.self::navigation($entry);
        ManagementPage::start(__('View Entry','leenkat-forms'),false,$tools);
        echo '<div class="lkf-entry-view"><div class="lkf-entry-layout"><div class="lkf-entry-main"><section class="lkf-entry-card" aria-labelledby="lkf-entry-form-title"><h2 id="lkf-entry-form-title">'.esc_html(ManagementPage::title($definition)).'</h2><dl class="lkf-entry-values">';
        $files=self::files($entry,$meta);$shown=[];
        foreach ($definition['fields'] ?? [] as $field) {
            $id=(string)($field['id'] ?? '');
            if (in_array($field['type'] ?? '',['captcha','page_break','section_divider'],true)) { $shown[$id]=true;continue; }
            if (!array_key_exists($id,$values)&&empty($files[$id])) continue;
            $shown[$id]=true;
            $html=!empty($files[$id]) ? implode('<br>',$files[$id]) : self::value($values[$id] ?? '',(string)($field['type'] ?? ''));
            self::field((string)($field['label'] ?? $id),$html);
        }
        // Retain safe historical answers even when a legacy snapshot lacks a matching label.
        foreach ($values as $id=>$value) if (!isset($shown[$id])&&empty($files[$id])) self::field((string)$id,self::value($value,''));
        foreach ($files as $id=>$links) if (!isset($shown[$id])) self::field(__('Attachments','leenkat-forms'),implode('<br>',$links));
        if (!$values&&!$files) self::field(__('Entry','leenkat-forms'),esc_html__('No submitted values.','leenkat-forms'));
        echo '</dl></section>';
        self::notifications($entryId);
        echo '</div><aside class="lkf-entry-sidebar" aria-label="'.esc_attr__('Entry information and actions','leenkat-forms').'"><section class="lkf-entry-card"><h2>'.esc_html__('Entry Details','leenkat-forms').'</h2><div class="lkf-entry-meta">';
        self::detail('admin-network',__('Entry ID','leenkat-forms'),esc_html((string)$entryId));
        self::detail('calendar-alt',__('Submitted','leenkat-forms'),esc_html(ManagementPage::date($entry->submitted_at_gmt)));
        self::detail('calendar-alt',__('Modified','leenkat-forms'),esc_html(ManagementPage::date($entry->updated_at_gmt)));
        $user=!empty($entry->user_id)?get_userdata((int)$entry->user_id):false;
        self::detail('admin-users',__('User','leenkat-forms'),esc_html($user?$user->display_name:__('Guest','leenkat-forms')));
        $ip=$meta['ip_address'] ?? '';
        self::detail('location',__('User IP','leenkat-forms'),esc_html(is_string($ip)&&filter_var($ip,FILTER_VALIDATE_IP)?$ip:__('Not collected','leenkat-forms')));
        $status=$entry->status==='complete'?__('Complete','leenkat-forms'):($entry->status==='spam'?__('Spam','leenkat-forms'):__('Trash','leenkat-forms'));
        self::detail('info-outline',__('Status','leenkat-forms'),esc_html($status));
        if ($entry->status==='spam'&&!empty($meta['spam_reason'])) self::detail('shield',__('Spam reason','leenkat-forms'),esc_html(match ((string)$meta['spam_reason']) { 'Keyword Filter'=>__('Keyword Filter','leenkat-forms'), 'Country Filter'=>__('Country Filter','leenkat-forms'), 'Modern anti-spam'=>__('Modern anti-spam','leenkat-forms'), 'Honeypot'=>__('Honeypot','leenkat-forms'), default=>(string)$meta['spam_reason'] }));
        echo '</div><div class="lkf-entry-card-footer">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Private presentation helpers return forms/links whose URLs, names, IDs and captions are escaped at construction; post KSES would remove required form controls.
        echo self::action($entry,$entry->status==='trashed'?'restore':'trash',$entry->status==='trashed'?__('Restore Entry','leenkat-forms'):__('Trash Entry','leenkat-forms'),'trash');
        echo '</div></section><section class="lkf-entry-card lkf-entry-tools"><h2>'.esc_html__('Actions','leenkat-forms').'</h2><div class="lkf-entry-tool-list"><button type="button" class="button-link lkf-print-entry"><span class="dashicons dashicons-media-document" aria-hidden="true"></span> '.esc_html__('Print','leenkat-forms').'</button>';
        echo '<a href="'.esc_url(ManagementPage::url(['page'=>'leenkat-forms-tools','view'=>'export','form_id'=>$formId])).'"><span class="dashicons dashicons-download" aria-hidden="true"></span> '.esc_html__('Export Entries','leenkat-forms').'</a>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Private presentation helpers return forms/links whose URLs, names, IDs and captions are escaped at construction; post KSES would remove required form controls.
        echo self::action($entry,empty($meta['is_starred'])?'star':'unstar',empty($meta['is_starred'])?__('Star','leenkat-forms'):__('Unstar','leenkat-forms'),'star-filled');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Private presentation helpers return forms/links whose URLs, names, IDs and captions are escaped at construction; post KSES would remove required form controls.
        echo self::action($entry,empty($meta['is_read'])?'mark_read':'mark_unread',empty($meta['is_read'])?__('Mark as Read','leenkat-forms'):__('Mark as Unread','leenkat-forms'),'hidden');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Private presentation helpers return forms/links whose URLs, names, IDs and captions are escaped at construction; post KSES would remove required form controls.
        if ($entry->status!=='trashed') echo self::action($entry,$entry->status==='spam'?'unspam':'spam',$entry->status==='spam'?__('Not Spam','leenkat-forms'):__('Mark as Spam','leenkat-forms'),'shield');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Private presentation helpers return forms/links whose URLs, names, IDs and captions are escaped at construction; post KSES would remove required form controls.
        else echo self::action($entry,'delete',__('Delete Permanently','leenkat-forms'),'trash');
        echo '</div></section></aside></div></div>';ManagementPage::end();
    }
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Private presentation helpers return forms/links whose URLs, names, IDs and captions are escaped at construction; post KSES would remove required form controls.
    private static function field(string $label,string $html): void { echo '<dt>'.esc_html($label).'</dt><dd dir="auto">'.$html.'</dd>'; }
    public static function value($value,string $type): string {
        if (is_array($value)) {$flat=[];array_walk_recursive($value,static function($v)use(&$flat):void{if(is_scalar($v))$flat[]=(string)$v;});$value=implode(', ',$flat);}
        if (!is_scalar($value)||(string)$value==='') return '—';
        $text=(string)$value;
        if ($type==='email'&&is_email($text)) return '<a dir="ltr" href="'.esc_url('mailto:'.$text).'">'.esc_html($text).'</a>';
        if ($type==='url'&&in_array(wp_parse_url($text,PHP_URL_SCHEME),['http','https'],true)&&wp_parse_url($text,PHP_URL_HOST)) return '<a dir="ltr" href="'.esc_url($text,['http','https']).'" rel="noreferrer noopener" referrerpolicy="no-referrer">'.esc_html($text).'</a>';
        if ($type==='phone') return '<bdi dir="ltr">'.esc_html($text).'</bdi>';
        return nl2br(esc_html($text));
    }
    private static function files(object $entry,array $meta): array {
        $result=[];
        foreach (\LeenkatForms\Files\FileRecords::forEntry((int)$entry->id) as $file) {
            $name=esc_html($file['original_name']);
            if ($entry->status!=='trashed'&&in_array($file['status'],['private','media'],true)) $name='<a href="'.esc_url(\LeenkatForms\Files\Download::url((int)$file['id'])).'">'.$name.'</a>';
            $result[$file['field_id']][]='<span class="dashicons dashicons-media-default" aria-hidden="true"></span> '.$name;
        }
        foreach ($meta['uploads'] ?? [] as $index=>$upload) {
            if (!is_array($upload)||!empty($upload['file_id'])||!is_string($upload['name'] ?? null)) continue;
            $name=esc_html($upload['name']);
            if ($entry->status!=='trashed'&&\LeenkatForms\Files\FileRecords::legacyPath($upload)) $name='<a href="'.esc_url(\LeenkatForms\Files\Download::legacyUrl((int)$entry->id,(int)$index)).'">'.$name.'</a>';
            $result[(string)($upload['field_id'] ?? '')][]=$name;
        }
        return $result;
    }
    private static function detail(string $icon,string $label,string $value): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every private caller passes esc_html() text; preserve entities rather than double escaping them.
        echo '<p><span class="dashicons dashicons-'.esc_attr($icon).'" aria-hidden="true"></span><span>'.esc_html($label).': <strong>'.$value.'</strong></span></p>';
    }
    private static function action(object $entry,string $op,string $caption,string $icon): string {
        ob_start();echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('lkf_entry_action_'.$entry->id);
        echo '<input type="hidden" name="action" value="lkf_entry_action"><input type="hidden" name="entry_id" value="'.(int)$entry->id.'"><input type="hidden" name="return_view" value="entry"><button type="submit" class="button-link'.(in_array($op,['trash','delete'],true)?' submitdelete':'').'" name="operation" value="'.esc_attr($op).'"><span class="dashicons dashicons-'.esc_attr($icon).'" aria-hidden="true"></span> '.esc_html($caption).'</button></form>';
        return (string)ob_get_clean();
    }
    public static function navigation(object $entry): string {
        global $wpdb;$table=$wpdb->prefix.'lkf_entries';$where=$wpdb->prepare('form_id=%d AND status=%s',$entry->form_id,$entry->status);
        $newer=$wpdb->prepare('(submitted_at_gmt>%s OR (submitted_at_gmt=%s AND id>%d))',$entry->submitted_at_gmt,$entry->submitted_at_gmt,$entry->id);
        $older=$wpdb->prepare('(submitted_at_gmt<%s OR (submitted_at_gmt=%s AND id<%d))',$entry->submitted_at_gmt,$entry->submitted_at_gmt,$entry->id);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $row=$wpdb->get_row("SELECT COUNT(*) AS total,1+SUM({$newer}) AS position FROM {$table} WHERE {$where}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $row->previous_id=$wpdb->get_var("SELECT id FROM {$table} WHERE {$where} AND {$newer} ORDER BY submitted_at_gmt ASC,id ASC LIMIT 1");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $row->next_id=$wpdb->get_var("SELECT id FROM {$table} WHERE {$where} AND {$older} ORDER BY submitted_at_gmt DESC,id DESC LIMIT 1");
        $html='<nav class="lkf-entry-pager" aria-label="'.esc_attr__('Entry navigation','leenkat-forms').'"><span>'.esc_html(sprintf(/* translators: 1: current entry position, 2: total entries. */ __('Entry %1$d of %2$d','leenkat-forms'),(int)$row->position,(int)$row->total)).'</span>';
        foreach (['previous_id'=>[__('Previous entry','leenkat-forms'),'arrow-left-alt2'],'next_id'=>[__('Next entry','leenkat-forms'),'arrow-right-alt2']] as $key=>[$label,$icon]) {
            $id=(int)$row->$key;
            $content='<span class="dashicons dashicons-'.$icon.'" aria-hidden="true"></span><span class="screen-reader-text">'.esc_html($label).'</span>';
            $html.=$id?'<a class="button" href="'.esc_url(ManagementPage::url(['page'=>'leenkat-forms-entries','form_id'=>(int)$entry->form_id,'entry_id'=>$id])).'">'.$content.'</a>':'<span class="button disabled" aria-disabled="true">'.$content.'</span>';
        }
        return $html.'</nav>';
    }
    private static function notifications(int $id): void {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT kind,status,attempt_count,last_error_code FROM {$wpdb->prefix}lkf_deliveries WHERE entry_id=%d ORDER BY id",$id));
        if (!$rows) return;
        echo '<section class="lkf-entry-card"><h2>'.esc_html__('Notifications','leenkat-forms').'</h2><table class="widefat striped"><thead><tr><th>'.esc_html__('Kind','leenkat-forms').'</th><th>'.esc_html__('Status','leenkat-forms').'</th><th>'.esc_html__('Attempts','leenkat-forms').'</th><th>'.esc_html__('Error code','leenkat-forms').'</th></tr></thead><tbody>';
        $statuses=['pending'=>__('pending','leenkat-forms'),'retry'=>__('retry','leenkat-forms'),'sent'=>__('sent','leenkat-forms'),'failed'=>__('failed','leenkat-forms'),'processing'=>__('processing','leenkat-forms'),'suppressed'=>__('suppressed','leenkat-forms')];
        foreach ($rows as $row) echo '<tr><td>'.esc_html($row->kind==='email_notification'?__('Email notification','leenkat-forms'):$row->kind).'</td><td>'.esc_html($statuses[$row->status]??$row->status).'</td><td>'.(int)$row->attempt_count.'</td><td>'.esc_html((string)$row->last_error_code).'</td></tr>';
        echo '</tbody></table></section>';
    }
}
