<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

/** Shared presentation for the two administration list screens. No entry writes on GET. */
final class ManagementPage {
    public static function hooks(): void {
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('set-screen-option', [self::class, 'savePerPage'], 10, 3);
    }
    public static function load(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $entries = self::text($_GET, 'page') === 'leenkat-forms-entries';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        if ($entries && absint($_GET['entry_id'] ?? 0)) return;
        $option = $entries ? 'lkf_entries_per_page' : 'lkf_forms_per_page';
        add_screen_option('per_page', ['label'=>__('Items per page', 'leenkat-forms'), 'default'=>20, 'option'=>$option]);
        $screen=get_current_screen();
        if ($screen) {
            add_filter('manage_'.$screen->id.'_columns',static function() use ($entries):array {
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
                if ($entries && absint($_GET['entry_id'] ?? 0)) return [];
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
                if ($entries && ($id=absint($_GET['form_id'] ?? 0))) {
                    global $wpdb;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                    $form=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lkf_forms WHERE id=%d",$id));
                    return $form ? (new EntriesListTable($form))->get_columns() : [];
                }
                return (new FormsListTable($entries))->get_columns();
            });
            add_filter('default_hidden_columns',static function(array $hidden,$current) use ($screen,$entries):array {
                if ($current->id!==$screen->id) return $hidden;
                if (!$entries) return $hidden;
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
                if ($id=absint($_GET['form_id'] ?? 0)) {
                    global $wpdb;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                    $form=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lkf_forms WHERE id=%d",$id));
                    if ($form) return array_merge($hidden,(new EntriesListTable($form))->defaultHiddenColumns());
                }
                return $hidden;
            },10,2);
        }
    }
    public static function savePerPage($status, string $option, $value) {
        if (!in_array($option, ['lkf_entries_per_page','lkf_forms_per_page'], true)) return $status;
        if (!current_user_can($option === 'lkf_forms_per_page' ? 'lkf_manage_forms' : 'lkf_view_entries')) return false;
        return max(1, min(100, (int)$value));
    }
    public static function assets(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        if (!in_array(self::text($_GET, 'page'), ['leenkat-forms-all','leenkat-forms-entries','leenkat-forms-file-security'], true)) return;
        wp_enqueue_style('lkf-management', plugins_url('assets/admin/management.css', LEENKAT_FORMS_FILE), [], LEENKAT_FORMS_VERSION.'.'.filemtime(LEENKAT_FORMS_DIR.'assets/admin/management.css'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        if (self::text($_GET, 'page')==='leenkat-forms-file-security') return;
        wp_enqueue_script('lkf-management', plugins_url('assets/admin/management.js', LEENKAT_FORMS_FILE), [], LEENKAT_FORMS_VERSION, true);
        wp_localize_script('lkf-management', 'lkfManagement', ['deleteWarning'=>__('Permanently delete the selected items? This cannot be undone.', 'leenkat-forms')]);
    }
    public static function text(array $request, string $key): string {
        return isset($request[$key]) && is_string($request[$key]) ? sanitize_text_field(wp_unslash($request[$key])) : '';
    }
    public static function url(array $args = []): string { return add_query_arg($args, admin_url('admin.php')); }
    public static function title(array $definition): string {
        return is_string($definition['title'] ?? null) && trim($definition['title']) !== '' ? $definition['title'] : __('Untitled Form','leenkat-forms');
    }
    public static function date(?string $utc, bool $time = true): string {
        if (!$utc || str_starts_with($utc, '0000-')) return '—';
        return get_date_from_gmt($utc, get_option('date_format') . ($time ? ' ' . get_option('time_format') : ''));
    }
    /** Local calendar days, including today; converted to UTC for stored timestamps. */
    public static function period(int $days): array {
        if (!in_array($days, [7,30,90], true)) return [];
        $today = new \DateTimeImmutable('today', wp_timezone());
        $utc = new \DateTimeZone('UTC');
        return [$today->modify('-'.($days-1).' days')->setTimezone($utc)->format('Y-m-d H:i:s'), $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
    public static function days(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        $days = (int)self::text($_GET, 'days');
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        return in_array($days, [7,30,90], true) ? $days : (self::text($_GET,'days') === '0' ? 0 : 30);
    }
    public static function start(string $title, bool $add = false, string $headingTools = ''): void {
        echo '<div class="wrap lkf-management"><div class="lkf-management-brand"><img src="'.esc_url(plugins_url('assets/images/leenkat-forms-logo.png',LEENKAT_FORMS_FILE)).'" width="64" height="64" alt=""><span>'.esc_html__('Leenkat Forms','leenkat-forms').'</span></div><div class="lkf-management-heading"><h1>'.esc_html($title).'</h1>';
        if ($add) echo '<a class="page-title-action" href="'.esc_url(self::url(['page'=>'leenkat-forms-new'])).'"><span aria-hidden="true">＋</span> '.esc_html__('Add New','leenkat-forms').'</a>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Only private internal callers supply constructed, escaped header controls; KSES would remove required controls.
        echo $headingTools; // Only internal callers provide escaped, constructed header controls.
        echo '</div><div class="lkf-management-content">';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
        if (isset($_GET['lkf_result'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use.
            $ok = self::text($_GET, 'lkf_result') === 'ok';
            echo '<div class="notice '.($ok ? 'notice-success' : 'notice-error').' is-dismissible"><p>'.esc_html($ok ? __('Action completed.','leenkat-forms') : __('Some items could not be updated. Forms with entries cannot be permanently deleted.','leenkat-forms')).'</p></div>';
        }
    }
    public static function end(): void { echo '</div></div>'; }
    public static function periodSelect(int $selected): void {
        echo '<label class="screen-reader-text" for="lkf-period">'.esc_html__('Date range','leenkat-forms').'</label><select id="lkf-period" name="days">';
        foreach ([7=>__('Last 7 days','leenkat-forms'),30=>__('Last 30 days','leenkat-forms'),90=>__('Last 90 days','leenkat-forms'),0=>__('All time','leenkat-forms')] as $value=>$caption) echo '<option value="'.esc_attr((string)$value).'"'.selected($selected,$value,false).'>'.esc_html($caption).'</option>';
        echo '</select> <button class="button">'.esc_html__('Filter','leenkat-forms').'</button>';
    }
    public static function columnsButton(): void {
        echo '<button type="button" class="button lkf-columns-toggle" aria-label="'.esc_attr__('Manage columns','leenkat-forms').'" aria-controls="screen-options-wrap" aria-expanded="false"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span></button>';
    }
    /** Reject malformed or unbounded bulk input; never coerce negative IDs into real records. */
    public static function ids($raw): array {
        if (!is_array($raw) || !$raw || count($raw)>100) return [];
        $ids=[];
        foreach ($raw as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id) || (int)$id<1) return [];
            $ids[]=(int)$id;
        }
        return array_values(array_unique($ids));
    }
    public static function requestOperation(): array {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
        $row=self::text($_POST,'lkf_row_action');
        if ($row!=='' && preg_match('/^([a-z_]+):([1-9][0-9]{0,17})$/D',$row,$m)) return [$m[1],[(int)$m[2]]];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
        $key=self::text($_POST,'lkf_apply')==='bottom' ? 'operation2' : 'operation';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations. Selection is validated element-by-element by ids(); text() unslashes and sanitizes its scalar argument before use.
        return [self::text($_POST,$key),self::ids($_POST['item_ids'] ?? null)];
    }
    public static function operationLabel(string $operation): string {
        $labels=['publish'=>__('Publish','leenkat-forms'),'unpublish'=>__('Unpublish','leenkat-forms'),'duplicate'=>__('Duplicate','leenkat-forms'),'trash'=>__('Move to Trash','leenkat-forms'),'restore'=>__('Restore','leenkat-forms'),'delete'=>__('Delete Permanently','leenkat-forms'),'mark_read'=>__('Mark Read','leenkat-forms'),'mark_unread'=>__('Mark Unread','leenkat-forms'),'star'=>__('Star','leenkat-forms'),'unstar'=>__('Unstar','leenkat-forms'),'spam'=>__('Mark Spam','leenkat-forms'),'unspam'=>__('Not Spam','leenkat-forms')];
        return $labels[$operation] ?? '';
    }
    public static function requirePost(): void {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server method/length metadata is compared with exact literals or cast and bounded; it is not WordPress-slash-encoded form content or HTML output.
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') wp_die(esc_html__('Use a POST request for this action.','leenkat-forms'),'',['response'=>405]);
    }
    public static function paginationRequest(string $page,array $context=[]): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
        if (self::text($_POST,'lkf_row_action')!=='' || !isset($_POST['paged']) || !is_scalar($_POST['paged'])) return;
        // Enter in the current-page input may implicitly submit the first Apply button.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
        $key=self::text($_POST,'lkf_apply')==='bottom'?'operation2':'operation';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
        if (!empty($_POST['item_ids']) || !in_array(self::text($_POST,$key),['','-1'],true)) return;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
        $args=['page'=>$page,'paged'=>max(1,min(1000000,(int)$_POST['paged']))];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The enclosing admin handler enforces its capability and dedicated nonce through gate(), requirePost(), check_admin_referer() or wp_verify_nonce(); flags/type checks before that gate do not perform mutations.
        foreach (['s','orderby','order','status','flag','days','search_field','search_operator','date_from','date_to'] as $key) { $value=self::text($_POST,$key); if ($value!=='') $args[$key]=$value; }
        wp_safe_redirect(self::url(array_merge($args,$context))); exit;
    }
    public static function rowButton(string $operation, int $id): string {
        return '<button type="submit" class="button-link'.(in_array($operation,['trash','delete'],true) ? ' submitdelete' : '').'" name="lkf_row_action" value="'.esc_attr($operation.':'.$id).'">'.esc_html(self::operationLabel($operation)).'</button>';
    }
}
