<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

/** One form per row, for both Forms Overview and the Entries landing screen. */
final class FormsListTable extends ManagementTable {
    private bool $overview;
    public string $status;
    public int $days;
    public function __construct(bool $overview=false) {
        $this->overview=$overview;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $this->status=ManagementPage::text($_GET,'status');
        if (!in_array($this->status,['draft','published','trashed'],true)) $this->status='';
        $this->days=ManagementPage::days();
        parent::__construct(['singular'=>'form','plural'=>'forms','ajax'=>false]);
    }
    public function get_columns() {
        if ($this->overview) return ['name'=>__('Form Name','leenkat-forms'),'created'=>__('Created','leenkat-forms'),'last_entry'=>__('Last Entry','leenkat-forms'),'all_time'=>__('All Time','leenkat-forms'),'period'=>$this->days ? sprintf(/* translators: %d: number of days. */ __('Last %d Days','leenkat-forms'),$this->days) : __('All Time','leenkat-forms')];
        return ['cb'=>'<input type="checkbox">','name'=>__('Name','leenkat-forms'),'tags'=>__('Tags','leenkat-forms'),'author'=>__('Author','leenkat-forms'),'date'=>__('Date','leenkat-forms'),'entries'=>__('Entries','leenkat-forms'),'shortcode'=>__('Shortcode','leenkat-forms')];
    }
    protected function get_sortable_columns() {
        return $this->overview ? ['name'=>['name',false],'created'=>['created',true],'last_entry'=>['last_entry',true],'all_time'=>['all_time',true],'period'=>['period',true]] : ['name'=>['name',false],'author'=>['author',false],'date'=>['date',true],'entries'=>['entries',true]];
    }
    protected function get_bulk_actions() {
        if ($this->overview) return [];
        $operations=$this->status==='trashed' ? ['restore','delete'] : ['publish','unpublish','duplicate','trash'];
        return array_combine($operations,array_map([ManagementPage::class,'operationLabel'],$operations));
    }
    public function get_views() {
        global $wpdb;
        $counts=['draft'=>0,'published'=>0,'trashed'=>0];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        foreach ($wpdb->get_results("SELECT status,COUNT(*) AS n FROM {$wpdb->prefix}lkf_forms GROUP BY status") as $row) $counts[$row->status]=(int)$row->n;
        $views=[];
        foreach ([''=>__('All','leenkat-forms'),'published'=>__('Published','leenkat-forms'),'draft'=>__('Drafts','leenkat-forms'),'trashed'=>__('Trash','leenkat-forms')] as $key=>$caption) {
            $count=$key==='' ? array_sum($counts)-$counts['trashed'] : $counts[$key];
            if ($key!=='' && !$count && $key!==$this->status) continue;
            $url=ManagementPage::url(['page'=>$this->overview ? 'leenkat-forms-entries' : 'leenkat-forms-all','status'=>$key,'days'=>$this->days]);
            $views[$key?:'all']='<a href="'.esc_url($url).'"'.($this->status===$key ? ' class="current" aria-current="page"' : '').'>'.esc_html($caption).' <span class="count">('.number_format_i18n($count).')</span></a>';
        }
        return $views;
    }
    public function prepare_items() {
        global $wpdb;
        $range=ManagementPage::period($this->days);
        $period=$range ? $wpdb->prepare('submitted_at_gmt >= %s AND submitted_at_gmt < %s',$range[0],$range[1]) : '1=1';
        // A single grouped read, irrespective of the number of forms/entries displayed.
        $stats="SELECT form_id,SUM(status='complete') AS all_time,SUM(status='complete' AND ({$period})) AS period,MAX(CASE WHEN status='complete' THEN submitted_at_gmt END) AS last_entry,SUM(status='spam') AS spam FROM {$wpdb->prefix}lkf_entries GROUP BY form_id";
        $where=$this->status ? $wpdb->prepare('f.status=%s',$this->status) : "f.status<>'trashed'";
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $search=mb_substr(ManagementPage::text($_GET,'s'),0,100);
        // Search the title, not serialized settings or credentials in definitions.
        $title="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(f.draft_definition,'$.title')),'')";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        if ($search!=='') $where.=$wpdb->prepare(" AND ({$title} LIKE %s OR f.id=%d)",'%'.$wpdb->esc_like($search).'%',ctype_digit($search)?(int)$search:0);
        $sorts=['name'=>'form_title','author'=>'author_name','date'=>'f.updated_at_gmt','created'=>'f.created_at_gmt','entries'=>'all_time','all_time'=>'all_time','period'=>'period','last_entry'=>'last_entry'];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $orderby=$sorts[ManagementPage::text($_GET,'orderby')] ?? ($this->overview ? 'f.created_at_gmt' : 'f.updated_at_gmt');
        $direction=$this->sortDirection();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lkf_forms f WHERE {$where}");
        $per=$this->perPage($this->overview?'lkf_entries_per_page':'lkf_forms_per_page');
        $page=min(max(1,$this->get_pagenum()),max(1,(int)ceil($total/$per)));
        $sql="SELECT f.*,{$title} AS form_title,u.display_name AS author_name,COALESCE(e.all_time,0) AS all_time,COALESCE(e.period,0) AS period,e.last_entry,COALESCE(e.spam,0) AS spam FROM {$wpdb->prefix}lkf_forms f LEFT JOIN {$wpdb->users} u ON u.ID=f.author_id LEFT JOIN ({$stats}) e ON e.form_id=f.id WHERE {$where} ORDER BY {$orderby} {$direction},f.id {$direction} LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $this->items=$wpdb->get_results($wpdb->prepare($sql,$per,($page-1)*$per));
        $this->configure($this->get_sortable_columns(),'name');
        $this->set_pagination_args(['total_items'=>$total,'per_page'=>$per,'total_pages'=>(int)ceil($total/$per)]);
    }
    public function column_name($item) {
        $id=(int)$item->id;
        $definition=json_decode($item->draft_definition,true) ?: [];
        $title=ManagementPage::title($definition);
        $edit=ManagementPage::url(['page'=>'leenkat-forms-new','form_id'=>$id]);
        $entries=ManagementPage::url(['page'=>'leenkat-forms-entries','form_id'=>$id]);
        $url=$this->overview ? $entries : $edit;
        $html='<strong><a class="row-title" href="'.esc_url($url).'">'.esc_html($title).'</a></strong>';
        $caption=$item->status==='published' ? __('Published','leenkat-forms') : ($item->status==='trashed' ? __('Trash','leenkat-forms') : __('Draft','leenkat-forms'));
        $html.=' <span class="lkf-form-state">— '.esc_html($caption).'</span>';
        $actions=[];
        if ($this->overview) {
            $actions['entries']='<a href="'.esc_url($entries).'">'.esc_html__('View Entries','leenkat-forms').'</a>';
            if ((int)$item->spam) $actions['spam']='<a href="'.esc_url(add_query_arg('status','spam',$entries)).'">'.esc_html(sprintf(/* translators: %d: number of spam entries. */ __('Spam (%d)','leenkat-forms'),$item->spam)).'</a>';
            if (current_user_can('lkf_manage_forms')) $actions['edit']='<a href="'.esc_url($edit).'">'.esc_html__('Edit','leenkat-forms').'</a>';
        } else {
            if ($item->status!=='trashed') {
                $actions['edit']='<a href="'.esc_url($edit).'">'.esc_html__('Edit','leenkat-forms').'</a>';
                if (current_user_can('lkf_view_entries')) $actions['entries']='<a href="'.esc_url($entries).'">'.esc_html__('Entries','leenkat-forms').'</a>';
                $actions['lkf-preview-form']='<a href="'.esc_url(add_query_arg('preview','1',$edit)).'">'.esc_html__('Preview','leenkat-forms').'</a>';
            }
            foreach ($item->status==='trashed' ? ['restore','delete'] : ['duplicate',$item->status==='published'?'unpublish':'publish','trash'] as $operation) $actions[$operation]=ManagementPage::rowButton($operation,$id);
        }
        $html.=$this->row_actions($actions);
        return $html;
    }
    public function column_default($item,$column_name) {
        if ($column_name==='tags') {
            $d=json_decode($item->draft_definition,true) ?: [];
            $tags=$d['settings']['general']['tags'] ?? [];
            return is_array($tags) && $tags ? esc_html(implode(', ',array_filter($tags,'is_string'))) : '—';
        }
        if ($column_name==='author') return esc_html($item->author_name ?: '—');
        if ($column_name==='date') return esc_html($item->created_at_gmt===$item->updated_at_gmt ? __('Created','leenkat-forms') : __('Last Modified','leenkat-forms')).'<br>'.esc_html(ManagementPage::date($item->updated_at_gmt));
        if ($column_name==='created') return esc_html(ManagementPage::date($item->created_at_gmt,false));
        if ($column_name==='last_entry') return esc_html(ManagementPage::date($item->last_entry));
        if ($column_name==='shortcode') return '<code>[leenkat_form id="'.(int)$item->id.'"]</code>';
        if (in_array($column_name,['entries','all_time','period'],true)) {
            $count=(int)($column_name==='period' ? $item->period : $item->all_time);
            $args=['page'=>'leenkat-forms-entries','form_id'=>(int)$item->id,'status'=>'complete'];
            if ($column_name==='period') $args['days']=$this->days;
            $text=number_format_i18n($count);
            return current_user_can('lkf_view_entries') ? '<a href="'.esc_url(ManagementPage::url($args)).'" aria-label="'.esc_attr(sprintf(/* translators: %d: number of entries. */ __('View %d entries for this form','leenkat-forms'),$count)).'">'.$text.'</a>' : $text;
        }
        return '—';
    }
    public function no_items() { esc_html_e('No forms found.','leenkat-forms'); }
    public function renderPage(): void {
        $this->prepare_items();
        $page=$this->overview?'leenkat-forms-entries':'leenkat-forms-all';
        ManagementPage::start($this->overview?__('Entries','leenkat-forms'):__('Forms Overview','leenkat-forms'),!$this->overview);
        if ($this->overview) echo '<h2 class="lkf-section-title">'.esc_html__('All Forms','leenkat-forms').'</h2>';
        echo '<div class="lkf-list-toolbar">'; $this->views();
        echo '<form method="get" class="lkf-list-search"><input type="hidden" name="page" value="'.esc_attr($page).'"><input type="hidden" name="status" value="'.esc_attr($this->status).'">';
        if ($this->overview) ManagementPage::periodSelect($this->days);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        echo '<label class="screen-reader-text" for="lkf-search">'.esc_html__('Search Forms','leenkat-forms').'</label><input type="search" id="lkf-search" name="s" value="'.esc_attr(ManagementPage::text($_GET,'s')).'"><button class="button">'.esc_html__('Search Forms','leenkat-forms').'</button>';
        ManagementPage::columnsButton(); echo '</form></div>';
        if ($this->overview) {
            echo '<form method="get"><input type="hidden" name="page" value="leenkat-forms-entries"><input type="hidden" name="status" value="'.esc_attr($this->status).'"><input type="hidden" name="days" value="'.esc_attr((string)$this->days).'">';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
            foreach (['s','orderby','order'] as $key) echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr(ManagementPage::text($_GET,$key)).'">';
            $this->display(); echo '</form><p class="description">'.esc_html__('Entry totals include completed entries. Spam and Trash are available inside each form.','leenkat-forms').'</p>';
        }
        else $this->pageForm('lkf_forms_bulk','lkf_forms_bulk',['status'=>$this->status]);
        ManagementPage::end();
    }
}
