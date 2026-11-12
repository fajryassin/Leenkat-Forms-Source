<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Forms\DefinitionCodec;
use LeenkatForms\Forms\DefinitionValidator;
use LeenkatForms\Forms\Publisher;
use LeenkatForms\Infrastructure\Database\Transactions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Authenticated draft API. Published versions are written only by Publisher. */
final class BuilderApi {
    public static function hooks(): void {
        add_action('rest_api_init', static function (): void {
            register_rest_route('leenkat-forms/v1', '/admin/forms', [
                'methods'=>'POST', 'callback'=>[self::class,'create'],
                'permission_callback'=>[self::class,'canEdit'],
            ]);
            register_rest_route('leenkat-forms/v1', '/admin/forms/(?P<id>\d+)', [
                ['methods'=>'GET','callback'=>[self::class,'get'],'permission_callback'=>[self::class,'canEdit']],
                ['methods'=>'POST','callback'=>[self::class,'update'],'permission_callback'=>[self::class,'canEdit']],
            ]);
            register_rest_route('leenkat-forms/v1', '/admin/forms/(?P<id>\d+)/duplicate', [
                'methods'=>'POST','callback'=>[self::class,'duplicate'],'permission_callback'=>[self::class,'canEdit'],
            ]);
            register_rest_route('leenkat-forms/v1', '/admin/forms/(?P<id>\d+)/publish', [
                'methods'=>'POST','callback'=>[self::class,'publish'],'permission_callback'=>[self::class,'canEdit'],
            ]);
        });
    }
    public static function canEdit(): bool { return current_user_can('lkf_manage_forms'); }
    private static function table(): string { global $wpdb; return $wpdb->prefix . 'lkf_forms'; }
    private static function validated(WP_REST_Request $request): array|WP_Error {
        if (strlen($request->get_body()) > 131072) { return new WP_Error('lkf_too_large', __('Form definition is too large.', 'leenkat-forms'), ['status'=>413]); }
        $data = $request->get_json_params();
        $definition = is_array($data) ? ($data['definition'] ?? null) : null;
        if (!is_array($definition)) { return self::invalid(); }
        $errors = DefinitionValidator::validate($definition);
        if ($errors) { return new WP_Error('lkf_definition', implode(' ', $errors), ['status'=>400]); }
        return $definition;
    }
    private static function invalid(): WP_Error { return new WP_Error('lkf_invalid', __('Invalid form request.', 'leenkat-forms'), ['status'=>400]); }
    private static function missing(): WP_Error { return new WP_Error('lkf_missing', __('Form not found.', 'leenkat-forms'), ['status'=>404]); }
    private static function response(object $row): WP_REST_Response {
        global $wpdb;
        $definition = json_decode((string)$row->draft_definition, true);
        $publishedRevision = null;
        if ((int)($row->published_version_id ?? 0) > 0) {
            $versions = $wpdb->prefix . 'lkf_form_versions';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            $publishedRevision = $wpdb->get_var($wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
                "SELECT definition_hash FROM `{$versions}` WHERE id=%d AND form_id=%d",
                (int)$row->published_version_id,
                (int)$row->id
            ));
        }
        return new WP_REST_Response([
            'id'=>(int)$row->id, 'status'=>(string)$row->status,
            'definition'=>is_array($definition) ? $definition : null,
            'revision'=>hash('sha256', (string)$row->draft_definition),
            'published_revision'=>is_string($publishedRevision) ? $publishedRevision : null,
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            'has_entries'=>current_user_can('lkf_view_entries') && (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}lkf_entries WHERE form_id=%d AND status<>'trashed' LIMIT 1",(int)$row->id)),
        ]);
    }
    public static function get(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,status,draft_definition,published_version_id FROM `{$table}` WHERE id=%d", absint($request['id'])));
        return $row ? self::response($row) : self::missing();
    }
    public static function create(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $definition = self::validated($request);
        if (is_wp_error($definition)) { return $definition; }
        $encoded = DefinitionCodec::encode($definition);
        $now = current_time('mysql', true);
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
        $ok = $wpdb->insert($table, [
            'uuid'=>wp_generate_uuid4(),'author_id'=>get_current_user_id(),'status'=>'draft',
            'draft_definition'=>$encoded,'created_at_gmt'=>$now,'updated_at_gmt'=>$now,
        ], ['%s','%d','%s','%s','%s','%s']);
        if (!$ok) { return new WP_Error('lkf_storage', __('Could not create form.', 'leenkat-forms'), ['status'=>503]); }
        return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'status'=>'draft','definition'=>$definition,'revision'=>hash('sha256',$encoded)],201);
    }
    public static function update(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $definition = self::validated($request);
        if (is_wp_error($definition)) { return $definition; }
        $data = $request->get_json_params();
        $revision = $data['revision'] ?? null;
        if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) { return self::invalid(); }
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        if (!Transactions::supported([$table]) || $wpdb->query('START TRANSACTION') === false) {
            return new WP_Error('lkf_storage', __('Form storage is unavailable.', 'leenkat-forms'), ['status'=>503]);
        }
        $id = absint($request['id']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,status,draft_definition FROM `{$table}` WHERE id=%d FOR UPDATE", $id));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        if (!$row || $row->status === 'trashed') { $wpdb->query('ROLLBACK'); return self::missing(); }
        if (!hash_equals(hash('sha256',(string)$row->draft_definition), $revision)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            $wpdb->query('ROLLBACK');
            return new WP_Error('lkf_conflict', __('This form changed in another session. Reload before saving.', 'leenkat-forms'), ['status'=>409]);
        }
        $encoded = DefinitionCodec::encode($definition);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $ok = $wpdb->update($table, ['draft_definition'=>$encoded,'updated_at_gmt'=>current_time('mysql',true)], ['id'=>$id], ['%s','%s'], ['%d']);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        if ($ok === false || $wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('lkf_storage', __('Could not save form.', 'leenkat-forms'), ['status'=>503]);
        }
        return new WP_REST_Response(['id'=>$id,'status'=>(string)$row->status,'definition'=>$definition,'revision'=>hash('sha256',$encoded)]);
    }
    public static function duplicate(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $original = self::get($request);
        if (is_wp_error($original)) { return $original; }
        $data = $original->get_data();
        $definition = $data['definition'];
        if (!is_array($definition)) { return self::invalid(); }
        $definition['title'] = sprintf(/* translators: %s: original form title. */ __('%s (Copy)', 'leenkat-forms'), (string)($definition['title'] ?? __('Untitled Form', 'leenkat-forms')));
        if (DefinitionValidator::validate($definition) !== []) { return self::invalid(); }
        global $wpdb;
        $table = self::table();
        $now = current_time('mysql',true);
        $encoded = DefinitionCodec::encode($definition);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
        $ok = $wpdb->insert($table, ['uuid'=>wp_generate_uuid4(),'author_id'=>get_current_user_id(),'status'=>'draft','draft_definition'=>$encoded,'created_at_gmt'=>$now,'updated_at_gmt'=>$now], ['%s','%d','%s','%s','%s','%s']);
        if (!$ok) { return new WP_Error('lkf_storage', __('Could not duplicate form.', 'leenkat-forms'), ['status'=>503]); }
        return new WP_REST_Response(['id'=>(int)$wpdb->insert_id,'status'=>'draft','definition'=>$definition,'revision'=>hash('sha256',$encoded)],201);
    }
    public static function publish(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = absint($request['id']);
        $errors = [];
        if (!Publisher::publish($id, $errors)) { return new WP_Error('lkf_publish', $errors[0] ?? __('Could not publish form. Please try again later.', 'leenkat-forms'), ['status'=>400]); }
        return new WP_REST_Response(['published'=>true,'id'=>$id]);
    }
}
