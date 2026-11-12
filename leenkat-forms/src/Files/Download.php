<?php
declare(strict_types=1);
namespace LeenkatForms\Files;

final class Download {
    public static function hooks(): void {add_action('admin_post_lkf_download_file',[self::class,'serve']);add_action('admin_post_nopriv_lkf_download_file',[self::class,'unavailable']);}
    public static function unavailable(): never {nocache_headers();wp_die(esc_html__('File unavailable.','leenkat-forms'),'',['response'=>404]);}
    public static function allowed(int $entryId): bool {
        if(!is_user_logged_in()||!current_user_can('lkf_view_entries'))return false;global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $entry=$wpdb->get_row($wpdb->prepare("SELECT id,form_id,status FROM {$wpdb->prefix}lkf_entries WHERE id=%d",$entryId));
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Existing lkf_-prefixed public hook is retained for backward compatibility; no unprefixed hook is introduced.
        return $entry&&$entry->status!=='trashed'&&(bool)apply_filters('lkf_can_access_entry_file',true,$entry,get_current_user_id());
    }
    public static function url(int $fileId): string {return add_query_arg(['action'=>'lkf_download_file','file_id'=>$fileId,'_wpnonce'=>wp_create_nonce('lkf_file_'.$fileId)],admin_url('admin-post.php'));}
    public static function legacyUrl(int $entryId,int $index): string {return add_query_arg(['action'=>'lkf_download_file','entry_id'=>$entryId,'legacy_index'=>$index,'_wpnonce'=>wp_create_nonce('lkf_legacy_file_'.$entryId.'_'.$index)],admin_url('admin-post.php'));}
    public static function serve(): never {
        if(!is_user_logged_in()||!current_user_can('lkf_view_entries'))self::unavailable();global $wpdb;
        $nonce=is_string($_GET['_wpnonce']??null)?sanitize_text_field(wp_unslash($_GET['_wpnonce'])):'';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects the nonce action; ID must be digits and its nonce is verified before any lookup.
        $id=is_string($_GET['file_id']??null)?sanitize_text_field(wp_unslash($_GET['file_id'])):'';$path=null;$name='attachment';$lease=null;
        if(is_string($id)&&ctype_digit($id)&&strlen($id)<20&&(int)$id>0){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if(!wp_verify_nonce($nonce,'lkf_file_'.$id))self::unavailable();$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lkf_entry_files WHERE id=%d",(int)$id),ARRAY_A);
            if(!$row||!self::allowed((int)$row['entry_id']))self::unavailable();
            try{$lease=FileLease::acquire((int)$row['entry_id'],false);}catch(\Throwable){self::unavailable();}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lkf_entry_files WHERE id=%d",(int)$id),ARRAY_A);
            if(!$row||!self::allowed((int)$row['entry_id']))self::unavailable();$path=FileRecords::resolve($row);$name=$row['original_name'];
        }else{
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Identifier/token is type-checked and validated by the existing digit/hex grammar and ownership/nonce gate before lookup or maintenance; keep exact identity.
            $entry=$_GET['entry_id'] ?? '';$index=$_GET['legacy_index'] ?? '';
            if(!is_string($entry)||!ctype_digit($entry)||!is_string($index)||!ctype_digit($index)||strlen($entry)>19||strlen($index)>3||!wp_verify_nonce($nonce,'lkf_legacy_file_'.$entry.'_'.$index)||!self::allowed((int)$entry))self::unavailable();
            try{$lease=FileLease::acquire((int)$entry,false);}catch(\Throwable){self::unavailable();}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if(!self::allowed((int)$entry))self::unavailable();$meta=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT meta FROM {$wpdb->prefix}lkf_entries WHERE id=%d",(int)$entry)),true);$file=$meta['uploads'][(int)$index] ?? null;
            if(!is_array($file))self::unavailable();$path=FileRecords::legacyPath($file);$name=$file['name'] ?? 'attachment';
        }
        if(!$path)self::unavailable();self::stream($path,(string)$name,$lease);
    }
    /** Shared verified-file response; callers own authorization and the active lease. */
    public static function stream(string $path,string $name,$lease): never {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
        $stream=@fopen($path,'rb');if(!$stream)self::unavailable();$stat=fstat($stream);$name=sanitize_file_name(str_replace(["\r","\n","\0"],'',$name)) ?: 'attachment';$ascii=preg_replace('/[^A-Za-z0-9._-]/','_',$name) ?: 'attachment';
        header('Referrer-Policy: no-referrer');
        nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Content-Security-Policy: sandbox');header('X-Robots-Tag: noindex, nofollow');header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name));header('Content-Length: '.(string)$stat['size']);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.Security.EscapeOutput.OutputNotEscaped -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees. Response is an authorized leased attachment byte stream with download headers; HTML escaping would corrupt the file.
        while(!feof($stream)){ $chunk=fread($stream,65536);if($chunk===false)break;echo $chunk;if(connection_aborted())break;}fclose($stream);PrivateStorage::close($path);FileLease::release($lease);exit;
    }
}
