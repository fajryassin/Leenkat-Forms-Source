<?php
declare(strict_types=1);
namespace LeenkatForms\Files;

use LeenkatForms\Infrastructure\Database\Transactions;
final class EntryDeletion {
    public static function remove(int $id,bool $privacy=false): true|\WP_Error {
        if($privacy?(!current_user_can('erase_others_personal_data')||!current_user_can('delete_users')):!current_user_can('lkf_view_entries'))return new \WP_Error('lkf_file_access',__('Entry unavailable.', 'leenkat-forms'));
        try{$lock=AttachmentMigration::lock();}catch(\Throwable){return new \WP_Error('lkf_file_busy',__('File maintenance is busy. Retry later.', 'leenkat-forms'));}
        $lease=null;try{$lease=FileLease::acquire($id,true);return self::removeLocked($id,$privacy);}catch(\Throwable){return new \WP_Error('lkf_file_busy',__('File maintenance is unavailable or entry files are in use. Retry later.', 'leenkat-forms'));}finally{FileLease::release($lease);delete_option($lock);}
    }
    private static function removeLocked(int $id,bool $privacy=false): true|\WP_Error {
        global $wpdb;
        if($privacy?(!current_user_can('erase_others_personal_data')||!current_user_can('delete_users')):!current_user_can('lkf_view_entries'))return new \WP_Error('lkf_file_access',__('Entry unavailable.', 'leenkat-forms'));
        $entries=$wpdb->prefix.'lkf_entries';$files=$wpdb->prefix.'lkf_entry_files';$deliveries=$wpdb->prefix.'lkf_deliveries';$values=$wpdb->prefix.'lkf_entry_values';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        if(!Transactions::supported([$entries,$files,$deliveries,$values])||$wpdb->query('START TRANSACTION')===false)return new \WP_Error('lkf_storage',__('Entry storage is unavailable.', 'leenkat-forms'));
        $committed=false;
        try{
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            $entry=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$entries} WHERE id=%d FOR UPDATE",$id));
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing lkf_-prefixed public hook is retained for backward compatibility; no unprefixed hook is introduced.
            if(!$entry||(!$privacy&&$entry->status!=='trashed')||!apply_filters('lkf_can_access_entry_file',true,$entry,get_current_user_id()))return new \WP_Error('lkf_file_access',__('Entry unavailable.', 'leenkat-forms'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            $jobs=$wpdb->get_results($wpdb->prepare("SELECT id,status FROM {$deliveries} WHERE entry_id=%d FOR UPDATE",$id),ARRAY_A);
            if($wpdb->last_error)return new \WP_Error('lkf_storage',__('Notification state is unavailable.', 'leenkat-forms'));
            foreach($jobs as $job)if($job['status']==='processing')return new \WP_Error('lkf_file_busy',__('A notification is being processed. Retry deletion after it finishes.', 'leenkat-forms'));
            if(AttachmentMigration::pendingPublicSource($id))return new \WP_Error('lkf_legacy_files',__('Legacy public sources require approved migration before deletion.', 'leenkat-forms'));
            $meta=json_decode((string)$entry->meta,true) ?: [];
            foreach($meta['uploads'] ?? [] as $file)if(is_array($file)&&empty($file['attachment_id'])&&empty($file['file_id'])&&empty($file['storage_key'])&&FileRecords::legacyPath($file))return new \WP_Error('lkf_legacy_files',__('Private legacy attachments must be migrated before permanent deletion.', 'leenkat-forms'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            if($wpdb->query($wpdb->prepare("UPDATE {$deliveries} SET status='cancelled',last_error_code='entry_removed' WHERE entry_id=%d AND status IN ('pending','retry')",$id))===false)return new \WP_Error('lkf_storage',__('Entry could not be removed.', 'leenkat-forms'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if($wpdb->update($files,['status'=>'deleting'],['entry_id'=>$id])===false||$wpdb->delete($values,['entry_id'=>$id])===false||$wpdb->delete($deliveries,['entry_id'=>$id])===false||$wpdb->delete($entries,['id'=>$id])===false||$wpdb->query('COMMIT')===false)return new \WP_Error('lkf_storage',__('Entry could not be removed.', 'leenkat-forms'));
            $committed=true;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        }finally{if(!$committed)$wpdb->query('ROLLBACK');}
        FileRecords::cleanup();if(!AttachmentMigration::purgeEntryBackups($id)){$pending=get_option('lkf_backup_cleanup_pending',[]);$pending[]=$id;update_option('lkf_backup_cleanup_pending',array_values(array_unique($pending)),false);update_option('lkf_file_cleanup_attention',true,false);}return true;
    }
    public static function hooks(): void {
        add_action('lkf_file_maintenance',static function():void{FileRecords::cleanup();PrivateStorage::reap();try{$lock=AttachmentMigration::lock();}catch(\Throwable){return;}$pending=get_option('lkf_backup_cleanup_pending',[]);foreach(array_slice($pending,0,100) as $id)if(AttachmentMigration::purgeEntryBackups((int)$id))$pending=array_values(array_diff($pending,[$id]));update_option('lkf_backup_cleanup_pending',$pending,false);delete_option($lock);});
        add_action('init',static function():void{if(!wp_next_scheduled('lkf_file_maintenance'))wp_schedule_event(time()+HOUR_IN_SECONDS,'hourly','lkf_file_maintenance');});
        add_filter('wp_privacy_personal_data_erasers',static function($erasers){$erasers['leenkat-form-files']=['eraser_friendly_name'=>__('Leenkat Forms entries and attachments', 'leenkat-forms'),'callback'=>[self::class,'erase']];return $erasers;});
    }
    public static function erase(string $email,int $page=1): array {
        $result=['items_removed'=>false,'items_retained'=>false,'messages'=>[],'done'=>true];
        if(!is_email($email)||!current_user_can('erase_others_personal_data')||!current_user_can('delete_users')){$result['items_retained']=true;$result['messages'][]=__('Entry erasure is unavailable.', 'leenkat-forms');return $result;}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        global $wpdb;$key='lkf_erase_cursor_'.get_current_user_id().'_'.hash_hmac('sha256',strtolower($email),wp_salt('auth'));$cursor=$page===1?['last'=>0,'max'=>(int)$wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}lkf_entries")]:get_transient($key);
        if($wpdb->last_error){$result['items_retained']=true;$result['messages'][]=__('Entry storage is unavailable. Restart the erasure request later.', 'leenkat-forms');return $result;}
        if(!is_array($cursor)){$result['items_retained']=true;$result['messages'][]=__('The erasure session expired. Restart the request.', 'leenkat-forms');return $result;}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,user_id,form_version_id,payload FROM {$wpdb->prefix}lkf_entries WHERE id>%d AND id<=%d ORDER BY id LIMIT 100",$cursor['last'],$cursor['max']));
        if(!is_array($rows)||$wpdb->last_error){$result['items_retained']=true;$result['messages'][]=__('Entries could not be verified. Restart the erasure request later.', 'leenkat-forms');return $result;}
        $user=get_user_by('email',$email);$versions=[];
        if($wpdb->last_error){$result['items_retained']=true;$result['messages'][]=__('Entry ownership could not be verified. Restart the erasure request later.', 'leenkat-forms');return $result;}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        foreach($rows as $row){$cursor['last']=(int)$row->id;$match=$user&&(int)$row->user_id===(int)$user->ID;$id=(int)$row->form_version_id;if(!isset($versions[$id]))$versions[$id]=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT definition FROM {$wpdb->prefix}lkf_form_versions WHERE id=%d",$id)),true) ?: [];$payload=json_decode((string)$row->payload,true) ?: [];
            if($wpdb->last_error||!$versions[$id]){$result['items_retained']=true;$result['messages'][]=__('An entry definition could not be verified.', 'leenkat-forms');continue;}
            foreach($versions[$id]['fields'] ?? [] as $field)if(($field['type'] ?? '')==='email'&&is_string($payload[$field['id']] ?? null)&&strcasecmp(trim($payload[$field['id']]),$email)===0)$match=true;
            if(!$match)continue;$removed=self::remove((int)$row->id,true);if(is_wp_error($removed)){$result['items_retained']=true;$result['messages'][]=$removed->get_error_message();}else $result['items_removed']=true;
        }
        $result['done']=count($rows)<100;if($result['done'])delete_transient($key);else set_transient($key,$cursor,HOUR_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        if(get_option('lkf_backup_cleanup_pending',[])||(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lkf_entry_files WHERE status='cleanup_failed'")){$result['items_retained']=true;$result['messages'][]=__('Attachment cleanup needs administrator attention.', 'leenkat-forms');}$result['messages']=array_values(array_unique($result['messages']));return $result;
    }
}
