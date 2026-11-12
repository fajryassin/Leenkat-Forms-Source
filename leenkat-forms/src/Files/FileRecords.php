<?php
declare(strict_types=1);
namespace LeenkatForms\Files;

final class FileRecords {
    public static function register(int $entryId,array &$files): bool {
        global $wpdb;
        foreach($files as &$file){
            $media=!empty($file['attachment_id']);$key=$media?'media:'.(int)$file['attachment_id']:($file['storage_key'] ?? '');
            if(!$media&&!PrivateStorage::validKey($key))return false;
            $row=['entry_id'=>$entryId,'field_id'=>$file['field_id'],'storage_key'=>$key,'original_name'=>$file['name'],'mime_detected'=>$file['mime'],'byte_size'=>$file['size'],'sha256'=>$file['sha256'],'status'=>$media?'media':'private'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
            if(!$wpdb->insert($wpdb->prefix.'lkf_entry_files',$row))return false;$file['file_id']=(int)$wpdb->insert_id;
            if($media){delete_post_meta((int)$file['attachment_id'],'_lkf_upload_lease');unset($file['lease']);}
            if(!$media)unset($file['path'],$file['url']);
        }unset($file);return true;
    }
    public static function committed(array $files): void {foreach($files as $file)if(isset($file['storage_key']))try{PrivateStorage::keep($file['storage_key']);}catch(\Throwable){update_option('lkf_file_cleanup_attention',true,false);}}
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
    public static function forEntry(int $id): array {global $wpdb;return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lkf_entry_files WHERE entry_id=%d ORDER BY id",$id),ARRAY_A) ?: [];}
    public static function legacyPath(array $file): ?string {
        $candidate=$file['path'] ?? '';if(!is_string($candidate)||$candidate===''||!PrivateStorage::noLinks($candidate))return null;
        $base=realpath(wp_upload_dir()['basedir']);
        if($base&&!PrivateStorage::inside($candidate,$base)&&defined('LEENKAT_FORMS_LEGACY_UPLOADS_DIR')){
            $old=rtrim(wp_normalize_path((string)LEENKAT_FORMS_LEGACY_UPLOADS_DIR),'/');
            $original=wp_normalize_path($candidate);
            if($old!==''&&PrivateStorage::inside($original,$old)&&strlen($original)>strlen($old)&&!preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$original))$candidate=$base.substr($original,strlen($old));
        }
        $real=realpath($candidate);
        return $real&&$base&&PrivateStorage::inside($real,$base)&&is_file($real)&&is_readable($real)&&PrivateStorage::noLinks($real)?$real:null;
    }
    public static function resolve(array $row): ?string {
        if(($row['status'] ?? '')==='private')return PrivateStorage::verify($row['storage_key'],(int)$row['byte_size'],$row['sha256']);
        if(($row['status'] ?? '')!=='media'||!preg_match('/^media:([1-9][0-9]*)$/D',$row['storage_key'] ?? '',$match))return null;
        $path=get_attached_file((int)$match[1]);$real=is_string($path)?self::legacyPath(['path'=>$path]):null;
        return $real&&filesize($real)===(int)$row['byte_size']&&hash_equals($row['sha256'],(string)@hash_file('sha256',$real))?$real:null;
    }
    public static function safeValues(array $definition,array $values,array $uploads=[]): array {
        foreach($definition['fields'] ?? [] as $field){if(($field['type'] ?? '')!=='file_upload'||!empty($field['settings']['media_library']))continue;$id=$field['id'];if(!isset($values[$id]))continue;$names=[];
            foreach($uploads as $file)if(($file['field_id'] ?? '')===$id&&is_string($file['name'] ?? null))$names[]=sanitize_file_name($file['name']);
            if(!$names){foreach(explode("\n",is_scalar($values[$id])?(string)$values[$id]:'') as $line){$line=preg_replace('~:\s*https?://.*$~i','',$line);$line=sanitize_file_name(wp_basename(str_replace('\\','/',$line)));if($line!=='')$names[]=$line;}}
            $values[$id]=implode("\n",$names);
        }return $values;
    }
    /** Backward-compatible reads only: metadata comes from the associated entry, never a request path. */
    public static function notificationFiles(int $entryId): array {
        global $wpdb;$rows=self::forEntry($entryId);$files=array_map(static fn($r)=>['file_id'=>(int)$r['id'],'field_id'=>$r['field_id'],'name'=>$r['original_name']],$rows);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $raw=$wpdb->get_var($wpdb->prepare("SELECT meta FROM {$wpdb->prefix}lkf_entries WHERE id=%d",$entryId));$meta=json_decode((string)$raw,true);
        foreach($meta['uploads'] ?? [] as $index=>$file){if(!is_array($file)||!empty($file['file_id']))continue;$files[]=['legacy_index'=>$index,'field_id'=>$file['field_id'] ?? '','name'=>$file['name'] ?? ''];}return $files;
    }
    public static function mailPath(array $file,int $entryId): ?string {
        global $wpdb;
        if($entryId>0){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if(isset($file['file_id'])){$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lkf_entry_files WHERE id=%d AND entry_id=%d AND field_id=%s",(int)$file['file_id'],$entryId,$file['field_id']),ARRAY_A);return $row?self::resolve($row):null;}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if(!isset($file['legacy_index']))return null;$meta=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT meta FROM {$wpdb->prefix}lkf_entries WHERE id=%d",$entryId)),true);$record=$meta['uploads'][$file['legacy_index']] ?? null;
            return is_array($record)&&($record['field_id'] ?? '')===$file['field_id']?self::legacyPath($record):null;
        }
        if(!empty($file['attachment_id'])&&is_string($file['lease'] ?? null)&&hash_equals((string)get_post_meta((int)$file['attachment_id'],'_lkf_upload_lease',true),$file['lease'])){$path=get_attached_file((int)$file['attachment_id']);return is_string($path)?self::legacyPath(['path'=>$path]):null;}
        $key=$file['storage_key'] ?? '';if(!is_string($key)||!PrivateStorage::pending($key))return null;
        return PrivateStorage::verify($key,(int)($file['size'] ?? 0),(string)($file['sha256'] ?? ''));
    }
    public static function discard(array $files): void {
        global $wpdb;
        foreach($files as $file){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if(isset($file['storage_key'])&&PrivateStorage::validKey($file['storage_key'])){$refs=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lkf_entry_files WHERE storage_key=%s",$file['storage_key']));if($wpdb->last_error){update_option('lkf_file_cleanup_attention',true,false);continue;}if(!$refs&&!PrivateStorage::remove($file['storage_key']))update_option('lkf_file_cleanup_attention',true,false);}
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
            elseif(($file['kind'] ?? '')==='media'&&empty($file['attachment_id'])){$path=self::legacyPath($file);if($path&&preg_match('/^lkf-[a-f0-9]{48}\.[a-z0-9]+$/D',basename($path))&&hash_equals((string)($file['sha256'] ?? ''),(string)@hash_file('sha256',$path))&&!@unlink($path))update_option('lkf_file_cleanup_attention',true,false);}
            elseif(!empty($file['attachment_id'])){$id=(int)$file['attachment_id'];$path=get_attached_file($id);if(is_string($path)&&self::legacyPath(['path'=>$path])&&($file['path'] ?? '')===$path){if(!wp_delete_attachment($id,true))update_option('lkf_file_cleanup_attention',true,false);}}
        }
    }
    /** Rows survive a failed filesystem deletion so retries are safe and visible. */
    public static function cleanup(): void {
        global $wpdb;$table=$wpdb->prefix.'lkf_entry_files';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        $rows=$wpdb->get_results("SELECT * FROM {$table} WHERE status IN ('deleting','cleanup_failed') ORDER BY id LIMIT 100",ARRAY_A);
        foreach($rows as $candidate){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if($wpdb->query('START TRANSACTION')===false)return;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            try{$refs=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE storage_key=%s ORDER BY id FOR UPDATE",$candidate['storage_key']),ARRAY_A);if($wpdb->last_error)throw new \RuntimeException(__('File references unavailable.', 'leenkat-forms'));$row=null;foreach($refs as $ref)if((int)$ref['id']===(int)$candidate['id'])$row=$ref;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                if(!$row){$wpdb->query('ROLLBACK');continue;}$exists=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}lkf_entries WHERE id=%d",$row['entry_id']));if($wpdb->last_error)throw new \RuntimeException(__('Entry reference unavailable.', 'leenkat-forms'));if($exists){$wpdb->query('ROLLBACK');continue;}
                try{$ok=count($refs)>1||str_starts_with($row['storage_key'],'media:')||PrivateStorage::remove($row['storage_key']);}catch(\Throwable){$ok=false;}
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                if($ok){if($wpdb->delete($table,['id'=>$row['id']])===false)throw new \RuntimeException(__('Cleanup association unavailable.', 'leenkat-forms'));}else {if($wpdb->update($table,['status'=>'cleanup_failed'],['id'=>$row['id']])===false)throw new \RuntimeException(__('Cleanup retry unavailable.', 'leenkat-forms'));update_option('lkf_file_cleanup_attention',true,false);}if($wpdb->query('COMMIT')===false)throw new \RuntimeException(__('Cleanup could not commit.', 'leenkat-forms'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            }catch(\Throwable){$wpdb->query('ROLLBACK');update_option('lkf_file_cleanup_attention',true,false);}
        }
    }
}
