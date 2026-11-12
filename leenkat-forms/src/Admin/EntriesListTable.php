<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

/** Every query is scoped to a single existing form. */
final class EntriesListTable extends ManagementTable {
    private object $form;
    private array $fields=[];
    public string $status;
    public string $flag;
    public int $days;
    public function __construct(object $form) {
        $this->form=$form;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $this->status=ManagementPage::text($_GET,'status');
        if (!in_array($this->status,['complete','spam','trashed','all'],true)) $this->status='complete';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $this->flag=ManagementPage::text($_GET,'flag');
        if (!in_array($this->flag,['unread','starred'],true)) $this->flag='';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $this->days=isset($_GET['days']) ? ManagementPage::days() : 0;
        $d=json_decode($form->draft_definition,true) ?: [];
        foreach ($d['fields'] ?? [] as $field) {
            // File fields and structural/security controls never become payload excerpts.
            if (!is_array($field) || !in_array($field['type'] ?? '',['name','text','textarea','email','phone','select','radio','checkboxes','number','number_slider','url','address','datetime'],true)) continue;
            $id=$field['id'] ?? '';
            if (!is_string($id) || $id==='') continue;
            $this->fields[$id]=(string)($field['label'] ?? $id);
        }
        parent::__construct(['singular'=>'entry','plural'=>'entries','ajax'=>false]);
    }
    public function get_columns() {
        $settings='<button type="button" class="lkf-columns-toggle lkf-inbox-columns" aria-label="'.esc_attr__('Manage columns','leenkat-forms').'" aria-controls="screen-options-wrap" aria-expanded="false"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span></button>';
        return ['cb'=>'<input type="checkbox">','name'=>__('Name','leenkat-forms'),'email'=>__('Email','leenkat-forms'),'date'=>__('Date','leenkat-forms'),'actions'=>__('Actions','leenkat-forms').' '.$settings];
    }
    public function defaultHiddenColumns(): array { return []; }
    protected function get_sortable_columns() { return ['date'=>['date',true]]; }
    protected function get_table_classes() { return array_merge(parent::get_table_classes(),['lkf-inbox-table']); }
    public function column_cb($item) {
        $meta=json_decode($item->meta,true) ?: [];
        $starred=!empty($meta['is_starred']);$read=!empty($meta['is_read']);$id=(int)$item->id;
        $html='<span class="lkf-entry-select">'.parent::column_cb($item);
        foreach ([[$starred?'unstar':'star',$starred?'star-filled':'star-empty',$starred],[$read?'mark_unread':'mark_read',$read?'marker':'marker',!$read]] as [$op,$icon,$active]) {
            $label=sprintf(/* translators: 1: action name, 2: entry ID. */ __('%1$s entry #%2$d','leenkat-forms'),ManagementPage::operationLabel($op),$id);
            $html.='<button type="submit" class="lkf-entry-icon'.($active?' is-active':'').'" name="lkf_row_action" value="'.esc_attr($op.':'.$id).'" aria-label="'.esc_attr($label).'" title="'.esc_attr($label).'"><span class="dashicons dashicons-'.esc_attr($icon).'" aria-hidden="true"></span></button>';
        }
        return $html.'</span>';
    }
    public function column_name($item) {
        return '<span class="lkf-contact-name">'.esc_html(self::contact($item,'name')).'</span><button type="button" class="toggle-row"><span class="screen-reader-text">'.esc_html__('Show more details','leenkat-forms').'</span></button>';
    }
    public function column_email($item) { return esc_html(self::contact($item,'email')); }
    /** Resolve contacts from each immutable snapshot, including renamed/deleted historical fields. */
    public static function contact(object $item,string $type): string {
        $definition=json_decode($item->definition ?? '',true) ?: [];
        $meta=json_decode($item->meta ?? '',true) ?: [];
        $values=\LeenkatForms\Files\FileRecords::safeValues($definition,json_decode($item->payload ?? '',true) ?: [],$meta['uploads'] ?? []);
        $fallback=null;
        foreach ($definition['fields'] ?? [] as $field) {
            if (($field['type'] ?? '')===$type) return self::excerpt($values[$field['id']] ?? '');
            if ($type==='name' && ($field['type'] ?? '')==='text' && in_array(mb_strtolower(trim((string)($field['label'] ?? ''))),['name','full name','الاسم','الاسم الكامل'],true)) $fallback=$field['id'];
        }
        return $fallback!==null ? self::excerpt($values[$fallback] ?? '') : '—';
    }
    public function column_actions($item) {
        $url=ManagementPage::url(['page'=>'leenkat-forms-entries','form_id'=>(int)$item->form_id,'entry_id'=>(int)$item->id]);
        $parts=['<a href="'.esc_url($url).'" aria-label="'.esc_attr(sprintf(/* translators: %d: entry ID. */ __('View entry #%d','leenkat-forms'),$item->id)).'">'.esc_html__('View','leenkat-forms').'</a>'];
        foreach ($item->status==='trashed'?['restore','delete']:[$item->status==='spam'?'unspam':'spam','trash'] as $op) {
            $label=$op==='spam'?__('Spam','leenkat-forms'):($op==='trash'?__('Trash','leenkat-forms'):ManagementPage::operationLabel($op));
            $parts[]='<button type="submit" class="button-link'.(in_array($op,['trash','delete'],true)?' submitdelete':'').'" name="lkf_row_action" value="'.esc_attr($op.':'.$item->id).'">'.esc_html($label).'</button>';
        }
        return '<div class="lkf-entry-actions">'.implode('<span aria-hidden="true"> | </span>',$parts).'</div>';
    }
    protected function get_bulk_actions() {
        $ops=$this->status==='trashed' ? ['restore','delete'] : ['mark_read','mark_unread','star','unstar',$this->status==='spam'?'unspam':'spam','trash'];
        return array_combine($ops,array_map([ManagementPage::class,'operationLabel'],$ops));
    }
    public function get_views() {
        global $wpdb;
        $counts=['complete'=>0,'spam'=>0,'trashed'=>0];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        foreach ($wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) AS n FROM {$wpdb->prefix}lkf_entries WHERE form_id=%d GROUP BY status",$this->form->id)) as $row) $counts[$row->status]=(int)$row->n;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $flags=$wpdb->get_row($wpdb->prepare("SELECT SUM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.is_read')),'false') NOT IN ('true','1')) AS unread,SUM(JSON_UNQUOTE(JSON_EXTRACT(meta,'$.is_starred')) IN ('true','1')) AS starred FROM {$wpdb->prefix}lkf_entries WHERE form_id=%d AND status='complete'",$this->form->id));
        $views=[];
        foreach (['complete'=>__('All','leenkat-forms'),'unread'=>__('Unread','leenkat-forms'),'starred'=>__('Starred','leenkat-forms'),'spam'=>__('Spam','leenkat-forms'),'trashed'=>__('Trash','leenkat-forms')] as $key=>$label) {
            $flag=in_array($key,['unread','starred'],true);
            $count=$flag ? (int)($flags->$key ?? 0) : $counts[$key];
            $args=['page'=>'leenkat-forms-entries','form_id'=>(int)$this->form->id,'status'=>$flag?'complete':$key];
            if ($flag) $args['flag']=$key;
            $current=$flag ? $this->flag===$key && $this->status==='complete' : $this->flag==='' && $this->status===$key;
            $views[$key]='<a href="'.esc_url(ManagementPage::url($args)).'"'.($current?' class="current" aria-current="page"':'').'>'.esc_html($label).' <span class="count">('.number_format_i18n($count).')</span></a>';
        }
        return $views;
    }
    public function prepare_items() {
        global $wpdb;
        $where=$wpdb->prepare('e.form_id=%d',$this->form->id);
        $where.=$this->status==='all' ? " AND e.status<>'trashed'" : $wpdb->prepare(' AND e.status=%s',$this->status);
        if ($this->flag==='unread') $where.=" AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(e.meta,'$.is_read')),'false') NOT IN ('true','1')";
        if ($this->flag==='starred') $where.=" AND JSON_UNQUOTE(JSON_EXTRACT(e.meta,'$.is_starred')) IN ('true','1')";
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $range=EntryListFilters::dates($_GET);
        if ($range===false) $where.=' AND 1=0';
        elseif ($range) {
            if ($range[0]!=='') $where.=$wpdb->prepare(' AND e.submitted_at_gmt >= %s',$range[0]);
            if ($range[1]!=='') $where.=$wpdb->prepare(' AND e.submitted_at_gmt < %s',$range[1]);
        } else {
            $range=ManagementPage::period($this->days);
            if ($range) $where.=$wpdb->prepare(' AND e.submitted_at_gmt >= %s AND e.submitted_at_gmt < %s',$range[0],$range[1]);
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $searchId=absint($_GET['search_id'] ?? 0);
        if ($searchId) $where.=$wpdb->prepare(' AND e.id=%d',$searchId);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $where.=EntryListFilters::search($this->fields,$_GET);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lkf_entries e WHERE {$where}");
        $per=$this->perPage('lkf_entries_per_page');
        $page=min(max(1,$this->get_pagenum()),max(1,(int)ceil($total/$per)));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $sort=ManagementPage::text($_GET,'orderby')==='id' ? 'e.id' : 'e.submitted_at_gmt';
        $direction=$this->sortDirection();
        // Join each immutable snapshot once; never render uploaded paths from a raw payload.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $this->items=$wpdb->get_results($wpdb->prepare("SELECT e.id,e.form_id,e.status,e.payload,e.meta,e.submitted_at_gmt,v.definition FROM {$wpdb->prefix}lkf_entries e LEFT JOIN {$wpdb->prefix}lkf_form_versions v ON v.id=e.form_version_id AND v.form_id=e.form_id WHERE {$where} ORDER BY {$sort} {$direction},e.id {$direction} LIMIT %d OFFSET %d",$per,($page-1)*$per));
        $this->configure($this->get_sortable_columns(),'name');
        $this->set_pagination_args(['total_items'=>$total,'per_page'=>$per,'total_pages'=>(int)ceil($total/$per)]);
    }
    public function column_default($item,$column_name) {
        if ($column_name==='date') return esc_html(ManagementPage::date($item->submitted_at_gmt));
        if ($column_name==='status') return esc_html($item->status==='complete'?__('Complete','leenkat-forms'):($item->status==='spam'?__('Spam','leenkat-forms'):__('Trash','leenkat-forms')));
        if (str_starts_with($column_name,'field_')) {
            $id=substr($column_name,6);
            $definition=json_decode($item->definition ?? '',true) ?: [];
            $meta=json_decode($item->meta,true) ?: [];
            // Fail closed if a historical ID was a file/password/security field in this snapshot.
            $field=null;
            foreach ($definition['fields'] ?? [] as $candidate) if (($candidate['id'] ?? '')===$id) { $field=$candidate; break; }
            if (!$field || !in_array($field['type'] ?? '',['name','text','textarea','email','phone','select','radio','checkboxes','number','number_slider','url','address','datetime'],true)) return '—';
            $values=\LeenkatForms\Files\FileRecords::safeValues($definition,json_decode($item->payload,true) ?: [],$meta['uploads'] ?? []);
            return esc_html(($field['type'] ?? '')==='textarea' ? self::excerpt($values[$id] ?? '',10,80) : self::excerpt($values[$id] ?? ''));
        }
        return '—';
    }
    public static function excerpt($value,int $maxWords=0,int $maxCharacters=120): string {
        if (is_array($value)) { $flat=[]; array_walk_recursive($value,static function($v) use (&$flat):void { if (is_scalar($v)) $flat[]=(string)$v; }); $value=implode(', ',$flat); }
        if (!is_scalar($value) || trim((string)$value)==='') return '—';
        $text=trim(preg_replace('/[\s\p{Z}]+/u',' ',wp_strip_all_tags((string)$value)) ?? '');
        if ($text==='') return '—';
        $short=$text;
        if ($maxWords>0) $short=implode(' ',array_slice(explode(' ',$short),0,$maxWords));
        if (mb_strlen($short)>$maxCharacters) $short=rtrim(mb_substr($short,0,$maxCharacters));
        return $short.($short!==$text?'…':'');
    }
    public function no_items() { esc_html_e('No entries found for this form.','leenkat-forms'); }
    protected function extra_tablenav($which) {
        if ($which!=='top') return;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $from=ManagementPage::text($_GET,'date_from');$to=ManagementPage::text($_GET,'date_to');
        echo '<div class="alignleft actions lkf-date-actions"><details class="lkf-date-range"><summary>'.esc_html($from||$to?($from?:'…').' – '.($to?:'…'):($this->days?sprintf(/* translators: %d: number of days. */ __('Last %d days','leenkat-forms'),$this->days):__('Select a date range','leenkat-forms'))).'</summary><div class="lkf-date-popover"><label>'.esc_html__('From','leenkat-forms').'<input type="date" name="date_from" value="'.esc_attr($from).'"></label><label>'.esc_html__('To','leenkat-forms').'<input type="date" name="date_to" value="'.esc_attr($to).'"></label></div></details><button class="button" name="lkf_filter" value="1">'.esc_html__('Filter','leenkat-forms').'</button></div>';
    }
    public function renderPage(): void {
        $this->prepare_items();global $wpdb;
        ManagementPage::start(__('Entries','leenkat-forms'));
        echo '<div class="lkf-entry-inbox"><div class="lkf-inbox-heading"><form method="get" class="lkf-form-picker"><input type="hidden" name="page" value="leenkat-forms-entries"><label for="lkf-entry-form-picker">'.esc_html__('Select Form','leenkat-forms').'</label><select id="lkf-entry-form-picker" name="form_id">';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $forms=$wpdb->get_results($wpdb->prepare("SELECT id,draft_definition FROM {$wpdb->prefix}lkf_forms WHERE status<>'trashed' OR id=%d ORDER BY id LIMIT 500",$this->form->id));
        if (!array_filter($forms,fn($f)=>(int)$f->id===(int)$this->form->id)) $forms[]=$this->form;
        foreach ($forms as $form) echo '<option value="'.(int)$form->id.'"'.selected((int)$this->form->id,(int)$form->id,false).'>'.esc_html(ManagementPage::title(json_decode($form->draft_definition,true) ?: [])).'</option>';
        echo '</select><button class="button hide-if-js">'.esc_html__('Switch','leenkat-forms').'</button></form><div class="lkf-inbox-tools">';
        if (current_user_can('lkf_manage_forms')) {
            foreach ([['edit',__('Edit This Form','leenkat-forms'),['page'=>'leenkat-forms-new','form_id'=>(int)$this->form->id]],['visibility',__('Preview Form','leenkat-forms'),['page'=>'leenkat-forms-new','form_id'=>(int)$this->form->id,'preview'=>'1']]] as [$icon,$caption,$args]) echo '<a href="'.esc_url(ManagementPage::url($args)).'"><span class="dashicons dashicons-'.esc_attr($icon).'" aria-hidden="true"></span> '.esc_html($caption).'</a>';
        }
        echo '<a href="'.esc_url(ManagementPage::url(['page'=>'leenkat-forms-tools','view'=>'export','form_id'=>(int)$this->form->id])).'"><span class="dashicons dashicons-download" aria-hidden="true"></span> '.esc_html__('Export Entries','leenkat-forms').'</a></div></div>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        if (EntryListFilters::dates($_GET)===false) echo '<div class="notice notice-error"><p>'.esc_html__('Enter a valid date range.','leenkat-forms').'</p></div>';
        echo '<div class="lkf-list-toolbar lkf-inbox-toolbar">';$this->views();
        echo '<form method="get" class="lkf-list-search"><input type="hidden" name="page" value="leenkat-forms-entries"><input type="hidden" name="form_id" value="'.(int)$this->form->id.'"><input type="hidden" name="status" value="'.esc_attr($this->status).'"><input type="hidden" name="flag" value="'.esc_attr($this->flag).'">';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        foreach (['date_from','date_to'] as $key) echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr(ManagementPage::text($_GET,$key)).'">';
        echo '<input type="hidden" name="days" value="'.esc_attr((string)$this->days).'"><label for="lkf-search-field" class="screen-reader-text">'.esc_html__('Search field','leenkat-forms').'</label><select id="lkf-search-field" name="search_field">';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        foreach (['all'=>__('Any form field','leenkat-forms'),'entry_id'=>__('Entry ID','leenkat-forms')]+$this->fields as $key=>$label) echo '<option value="'.esc_attr($key).'"'.selected(ManagementPage::text($_GET,'search_field') ?: 'all',$key,false).'>'.esc_html($label).'</option>';
        echo '</select><label for="lkf-search-operator" class="screen-reader-text">'.esc_html__('Search comparison','leenkat-forms').'</label><select id="lkf-search-operator" name="search_operator">';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        foreach (['contains'=>__('contains','leenkat-forms'),'not_contains'=>__('does not contain','leenkat-forms'),'equals'=>__('is','leenkat-forms'),'not_equals'=>__('is not','leenkat-forms')] as $key=>$label) echo '<option value="'.esc_attr($key).'"'.selected(ManagementPage::text($_GET,'search_operator') ?: 'contains',$key,false).'>'.esc_html($label).'</option>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        echo '</select><label for="lkf-search" class="screen-reader-text">'.esc_html__('Search Entries','leenkat-forms').'</label><input type="search" id="lkf-search" name="s" value="'.esc_attr(ManagementPage::text($_GET,'s')).'"><button class="button">'.esc_html__('Search','leenkat-forms').'</button></form></div>';
        $context=['form_id'=>(int)$this->form->id,'status'=>$this->status,'flag'=>$this->flag,'days'=>$this->days];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        foreach (['search_field','search_operator'] as $key) $context[$key]=ManagementPage::text($_GET,$key);
        $this->pageForm('lkf_entries_bulk','lkf_entries_bulk_'.(int)$this->form->id,$context);
        echo '</div>';ManagementPage::end();
    }
}