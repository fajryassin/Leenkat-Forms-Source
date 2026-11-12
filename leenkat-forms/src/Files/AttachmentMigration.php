<?php
declare(strict_types=1);
namespace LeenkatForms\Files;

use LeenkatForms\Infrastructure\Database\Transactions;
/** Explicit, resumable legacy migration. Inventory and planning never remove a source. */
final class AttachmentMigration {
    private static function gate(): void {if(!current_user_can('manage_options')||!current_user_can('lkf_view_entries'))throw new \RuntimeException(esc_html__('File maintenance is unavailable.', 'leenkat-forms'));}
    public static function isMedia(string $path): bool {
        global $wpdb;$base=realpath(wp_upload_dir()['basedir']);if(!$base||!PrivateStorage::inside($path,$base))return true;$relative=ltrim(str_replace('\\','/',substr($path,strlen($base))),'/');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE (meta_key='_wp_attached_file' AND meta_value=%s) OR (meta_key='_wp_attachment_metadata' AND meta_value LIKE %s) LIMIT 1",$relative,'%'.$wpdb->esc_like(basename($path)).'%'));
    }
    public static function inventory(): array {
        self::gate();global $wpdb;$result=['legacy_private'=>0,'media'=>0,'invalid_or_missing'=>0,'private_registered'=>0,'other_file_records'=>0,'candidates'=>[],'issues'=>[]];$last=0;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        do{$rows=$wpdb->get_results($wpdb->prepare("SELECT id,meta FROM {$wpdb->prefix}lkf_entries WHERE id>%d ORDER BY id LIMIT 100",$last));foreach($rows as $entry){$last=(int)$entry->id;$meta=json_decode((string)$entry->meta,true) ?: [];
            foreach($meta['uploads'] ?? [] as $index=>$file){if(!is_array($file))continue;if(!empty($file['file_id'])||!empty($file['storage_key']))continue;if(!empty($file['attachment_id'])){$result['media']++;continue;}$path=FileRecords::legacyPath($file);
                if(!$path||!is_string($file['name'] ?? null)||!is_string($file['field_id'] ?? null)){$result['invalid_or_missing']++;if(count($result['issues'])<25)$result['issues'][]=['entry_id'=>$last,'index'=>$index,'issue'=>__('Missing file or invalid legacy reference', 'leenkat-forms')];continue;}
                if(self::isMedia($path)){$result['media']++;continue;}$result['legacy_private']++;if(count($result['candidates'])<100)$result['candidates'][]=['entry_id'=>$last,'index'=>(int)$index,'field_id'=>$file['field_id'],'name'=>sanitize_file_name($file['name']),'bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'reference_hash'=>hash('sha256',wp_json_encode($file))];
            }
        }}while(count($rows)===100);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $result['private_registered']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lkf_entry_files WHERE status='private'");$result['other_file_records']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lkf_entry_files WHERE status NOT IN ('private','media','deleting','cleanup_failed')");return $result;
    }
    private static function name(string $run): string {
        if(!preg_match('/^[a-f0-9]{32}$/D',$run))throw new \RuntimeException(esc_html__('Migration unavailable.', 'leenkat-forms'));
        return 'migration-'.$run;
    }
    private static function legacyDoc(string $run): string {
        $path=PrivateStorage::legacyRoot().'/'.self::name($run).'.json';
        if(!PrivateStorage::noLinks($path))throw new \RuntimeException(esc_html__('Migration unavailable.', 'leenkat-forms'));
        return $path;
    }
    private static function legacyOwner(string $owner): bool {
        if(hash_equals(hash('sha256',ABSPATH),$owner))return true;
        return defined('LEENKAT_FORMS_LEGACY_SITE_ROOT')&&hash_equals(hash('sha256',(string)LEENKAT_FORMS_LEGACY_SITE_ROOT),$owner);
    }
    private static function write(array $plan): void {
        $run=$plan['run_id'];$name=self::name($run);
        $json=wp_json_encode($plan,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($json)||strlen($json)>33554432)throw new \RuntimeException(esc_html__('Migration state exceeds the safe limit.', 'leenkat-forms'));
        if(($plan['storage_version']??null)===2){
            $stream=EncryptedStorage::temporary($json);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The verified private migration journal requires an exclusive local stream, fsync and same-directory atomic replacement.
            try{$in=fopen($stream,'rb');if(!$in)throw new \RuntimeException(esc_html__('Migration unavailable.', 'leenkat-forms'));
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The verified private migration journal requires an exclusive local stream, fsync and same-directory atomic replacement.
                try{EncryptedStorage::write($name.'.lkf',$in,true);}finally{fclose($in);}
            }finally{EncryptedStorage::close($stream);}
            return;
        }
        // Continue old runs in their original non-public location; never move the original journal.
        $path=self::legacyDoc($run);
        if(!is_file($path)||!self::legacyOwner((string)($plan['site']??'')))throw new \RuntimeException(esc_html__('Migration unavailable.', 'leenkat-forms'));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The verified private migration journal requires an exclusive local stream, fsync and same-directory atomic replacement.
        $temp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';$out=@fopen($temp,'xb');
        if(!$out)throw new \RuntimeException(esc_html__('Migration state could not be saved.', 'leenkat-forms'));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_fclose,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The verified private migration journal requires an exclusive local stream, fsync and same-directory atomic replacement.
        @chmod($temp,0600);$ok=fwrite($out,$json)===strlen($json)&&fflush($out)&&fsync($out);fclose($out);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.unlink_unlink -- The verified private migration journal requires an exclusive local stream, fsync and same-directory atomic replacement.
        if(!$ok||!@rename($temp,$path)){@unlink($temp);throw new \RuntimeException(esc_html__('Migration state could not be saved.', 'leenkat-forms'));}
    }
    /** Authenticate both the journal name and its site; usable by WordPress uninstall. */
    public static function stored(string $run,bool $encrypted): array {
        $path=$encrypted?EncryptedStorage::open(self::name($run).'.lkf'):self::legacyDoc($run);
        try {
            if(!$path||!is_file($path)||filesize($path)>33554432)throw new \RuntimeException(esc_html__('Migration unavailable.', 'leenkat-forms'));
            $raw=@file_get_contents($path);$plan=is_string($raw)?json_decode($raw,true,64):null;
            $owner=is_array($plan)&&is_string($plan['site']??null)?$plan['site']:'';
            if(!is_array($plan)||($plan['run_id']??'')!==$run||!is_array($plan['files']??null)||!is_array($plan['entries']??null)||
                ($encrypted ? (($plan['storage_version']??null)!==2||!hash_equals(EncryptedStorage::identity(),$owner)) :
                    (isset($plan['storage_version'])||!self::legacyOwner($owner))))throw new \RuntimeException(esc_html__('Migration unavailable.', 'leenkat-forms'));
            return $plan;
        }finally{if($encrypted&&$path)EncryptedStorage::close($path);}
    }
    public static function read(string $run): array {
        self::gate();
        $encrypted=EncryptedStorage::ciphertext(self::name($run).'.lkf')!==null;
        return self::stored($run,$encrypted);
    }
    private static function source(array $plan,array $file): string {
        $path=$file['source']??'';
        $uploads=wp_upload_dir(null,false);
        $base=wp_normalize_path($uploads['basedir']);
        $previous=$plan['uploads_base']??(defined('LEENKAT_FORMS_LEGACY_UPLOADS_DIR')?(string)LEENKAT_FORMS_LEGACY_UPLOADS_DIR:$base);
        $previous=is_string($previous)?rtrim(wp_normalize_path($previous),'/'):'';
        $path=is_string($path)?wp_normalize_path($path):'';
        if($previous===''||!PrivateStorage::inside($path,$previous)||strlen($path)<=strlen($previous)||
            preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f\x7f]~',$path))throw new \RuntimeException(esc_html__('A migration source is outside the expected uploads directory.', 'leenkat-forms'));
        $dest=rtrim($base,'/').substr($path,strlen($previous));
        if(!PrivateStorage::noLinks($dest))throw new \RuntimeException(esc_html__('A migration source is outside the expected uploads directory.', 'leenkat-forms'));
        return $dest;
    }
    public static function report(array $plan): array {
        $safe=['run_id'=>$plan['run_id'],'created_gmt'=>$plan['created_gmt'],'backup_verified'=>true,'source_removal_requires_approval'=>true,'files'=>[]];
        foreach($plan['files'] as $file)$safe['files'][]=array_intersect_key($file,array_flip(['entry_id','index','field_id','name','bytes','sha256','state','message']));return $safe;
    }
    public static function plan(array $entryIds=[]): array {
        self::gate();global $wpdb;$inventory=self::inventory();$candidates=array_filter($inventory['candidates'],static fn($f)=>!$entryIds||in_array($f['entry_id'],$entryIds,true));$candidates=array_slice($candidates,0,50);
        $plan=['run_id'=>bin2hex(random_bytes(16)),'site'=>EncryptedStorage::identity(true),'storage_version'=>2,'uploads_base'=>wp_normalize_path(realpath(wp_upload_dir(null,false)['basedir']) ?: wp_upload_dir(null,false)['basedir']),'created_gmt'=>gmdate('c'),'entries'=>[],'files'=>[]];$required=array_sum(array_column($candidates,'bytes'))*2+16777216;$free=@disk_free_space(PrivateStorage::root());if($free!==false&&$free<$required)throw new \RuntimeException(esc_html__('Insufficient space for verified migration backups.', 'leenkat-forms'));self::write($plan);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        foreach($candidates as $candidate){$id=$candidate['entry_id'];$entry=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lkf_entries WHERE id=%d",$id),ARRAY_A);$meta=json_decode((string)$entry['meta'],true);$old=$meta['uploads'][$candidate['index']] ?? null;$source=is_array($old)?FileRecords::legacyPath($old):null;
            if(!$source||!hash_equals($candidate['reference_hash'],hash('sha256',wp_json_encode($old)))||self::isMedia($source))throw new \RuntimeException(esc_html__('A source changed. Generate a new dry run.', 'leenkat-forms'));
            $backup=PrivateStorage::put($source,strtolower(pathinfo($source,PATHINFO_EXTENSION)),(int)$candidate['bytes'],$candidate['sha256']);if(!PrivateStorage::matches($backup,(int)$candidate['bytes'],$candidate['sha256']))throw new \RuntimeException(esc_html__('Backup verification failed. Sources were retained.', 'leenkat-forms'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if(!isset($plan['entries'][$id]))$plan['entries'][$id]=['row'=>$entry,'projections'=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lkf_entry_values WHERE entry_id=%d ORDER BY id",$id),ARRAY_A)];
            $plan['files'][]=$candidate+['source'=>$source,'original_record'=>$old,'backup_key'=>$backup,'destination_key'=>null,'state'=>'backed_up'];self::write($plan);PrivateStorage::keep($backup);
        }
        update_option('lkf_file_migration_latest',$plan['run_id'],false);return self::report($plan);
    }
    public static function lock(string $run=''): string {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
        $key='lkf_file_migration_lock';$expires=(int)get_option($key,0);global $wpdb;if($expires&&$expires<time()){$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$key,(string)$expires));wp_cache_delete($key,'options');}if(!add_option($key,time()+600,'',false))throw new \RuntimeException(esc_html__('File maintenance is already running.', 'leenkat-forms'));return $key;
    }
    public static function apply(string $run,bool $removePublicSources=false): array {
        self::gate();$lock=self::lock($run);try{return self::applyLocked($run,$removePublicSources);}finally{delete_option($lock);}
    }
    private static function applyLocked(string $run,bool $remove): array {
        $plan=self::read($run);$leases=[];try{foreach(array_keys($plan['entries']) as $id)$leases[]=FileLease::acquire((int)$id,true);return self::applyContents($run,$remove);}finally{foreach($leases as $lease)FileLease::release($lease);}
    }
    private static function applyContents(string $run,bool $remove): array {
        global $wpdb;$plan=self::read($run);$entries=$wpdb->prefix.'lkf_entries';$files=$wpdb->prefix.'lkf_entry_files';$deliveries=$wpdb->prefix.'lkf_deliveries';$projections=$wpdb->prefix.'lkf_entry_values';
        if(!Transactions::supported([$entries,$files,$deliveries,$projections]))throw new \RuntimeException(esc_html__('Transactional migration is unavailable.', 'leenkat-forms'));
        foreach($plan['entries'] as $entryId=>&$snapshot){$indices=[];foreach($plan['files'] as $i=>$file)if($file['entry_id']===(int)$entryId)$indices[]=$i;if(!$indices)continue;
            foreach($indices as $i){$f=&$plan['files'][$i];if(in_array($f['state'],['migrated_source_retained','source_removed'],true))continue;
                if(file_exists($source=self::source($plan,$f))&&(!is_file($source)||!hash_equals($f['sha256'],(string)@hash_file('sha256',$source))))throw new \RuntimeException(esc_html__('A public source changed after backup. Generate a new dry run.', 'leenkat-forms'));
                if(!($backup=PrivateStorage::verify($f['backup_key'],(int)$f['bytes'],$f['sha256'])))throw new \RuntimeException(esc_html__('A verified backup is unavailable. Sources were retained.', 'leenkat-forms'));
                if(!$f['destination_key']||!PrivateStorage::matches($f['destination_key'],(int)$f['bytes'],$f['sha256'])){$f['destination_key']=PrivateStorage::put($backup,strtolower(pathinfo($f['source'],PATHINFO_EXTENSION)),(int)$f['bytes'],$f['sha256']);self::write($plan);}PrivateStorage::close($backup);if(!PrivateStorage::matches($f['destination_key'],(int)$f['bytes'],$f['sha256']))throw new \RuntimeException(esc_html__('Destination verification failed. Sources were retained.', 'leenkat-forms'));unset($f);
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException(esc_html__('Migration could not start.', 'leenkat-forms'));$committed=false;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            try{$entry=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$entries} WHERE id=%d FOR UPDATE",$entryId),ARRAY_A);if(!$entry)throw new \RuntimeException(__('An entry changed. Sources were retained.', 'leenkat-forms'));$meta=json_decode($entry['meta'],true) ?: [];$jobs=$wpdb->get_results($wpdb->prepare("SELECT id,status FROM {$deliveries} WHERE entry_id=%d FOR UPDATE",$entryId),ARRAY_A);if($wpdb->last_error)throw new \RuntimeException(__('Notification state unavailable.', 'leenkat-forms'));foreach($jobs as $job)if($job['status']==='processing')throw new \RuntimeException(__('A notification is processing. Retry migration later.', 'leenkat-forms'));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
                $allMigrated=true;foreach($indices as $i){$f=$plan['files'][$i];$record=$meta['uploads'][$f['index']] ?? [];if(($record['storage_key'] ?? '')!==$f['destination_key'])$allMigrated=false;else{$registered=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$files} WHERE id=%d AND entry_id=%d AND storage_key=%s AND status='private'",(int)($record['file_id'] ?? 0),$entryId,$f['destination_key']),ARRAY_A);if(!$registered||!FileRecords::resolve($registered))throw new \RuntimeException(__('A migrated file association needs administrator attention. Sources were retained.', 'leenkat-forms'));}}
                if(!$allMigrated){if(hash('sha256',wp_json_encode($entry))!==hash('sha256',wp_json_encode($snapshot['row'])))throw new \RuntimeException(__('An entry changed after backup. Generate a new dry run.', 'leenkat-forms'));$payload=json_decode($entry['payload'],true) ?: [];
                    foreach($indices as $i){$f=$plan['files'][$i];$current=$meta['uploads'][$f['index']] ?? [];if(!hash_equals($f['reference_hash'],hash('sha256',wp_json_encode($current))))throw new \RuntimeException(__('A legacy reference changed. Sources were retained.', 'leenkat-forms'));
                        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file(PrivateStorage::path($f['destination_key']));$records=[['storage_key'=>$f['destination_key'],'attachment_id'=>0,'kind'=>'private','field_id'=>$f['field_id'],'name'=>$f['name'],'mime'=>$mime,'size'=>$f['bytes'],'sha256'=>$f['sha256']]];
                        if(!FileRecords::register((int)$entryId,$records))throw new \RuntimeException(__('File association could not be saved.', 'leenkat-forms'));$meta['uploads'][$f['index']]=$records[0];
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                    $version=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT definition FROM {$wpdb->prefix}lkf_form_versions WHERE id=%d",$entry['form_version_id'])),true) ?: [];$payload=FileRecords::safeValues($version,$payload,$meta['uploads']);
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                    if($wpdb->update($entries,['meta'=>wp_json_encode($meta),'payload'=>wp_json_encode($payload)],['id'=>$entryId])===false)throw new \RuntimeException(__('Entry association could not be saved.', 'leenkat-forms'));
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                    foreach($indices as $i){$field=$plan['files'][$i]['field_id'];if($wpdb->update($projections,['value_text'=>implode('',array_slice(preg_split('//u',(string)($payload[$field] ?? ''),-1,PREG_SPLIT_NO_EMPTY) ?: [],0,191))],['entry_id'=>$entryId,'field_id'=>$field])===false)throw new \RuntimeException(__('Field association could not be saved.', 'leenkat-forms'));}
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                if($wpdb->query('COMMIT')===false)throw new \RuntimeException(__('Migration could not commit.', 'leenkat-forms'));$committed=true;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            }finally{if(!$committed)$wpdb->query('ROLLBACK');}
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            $snapshot['migrated_hash']=hash('sha256',wp_json_encode($wpdb->get_row($wpdb->prepare("SELECT * FROM {$entries} WHERE id=%d",$entryId),ARRAY_A)));
            foreach($indices as $i){if($plan['files'][$i]['state']!=='source_removed')$plan['files'][$i]['state']='migrated_source_retained';PrivateStorage::keep($plan['files'][$i]['destination_key']);}self::write($plan);
        }unset($snapshot);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
        if($remove)foreach($plan['files'] as &$file){if($file['state']==='source_removed')continue;$entryMeta=json_decode((string)$wpdb->get_var($wpdb->prepare("SELECT meta FROM {$entries} WHERE id=%d",$file['entry_id'])),true);$associated=$entryMeta['uploads'][$file['index']] ?? [];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            $registered=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$files} WHERE id=%d AND entry_id=%d AND field_id=%s AND storage_key=%s AND status='private'",(int)($associated['file_id'] ?? 0),$file['entry_id'],$file['field_id'],$file['destination_key']),ARRAY_A);
            if(!$registered||($associated['storage_key'] ?? '')!==$file['destination_key']||!FileRecords::resolve($registered))throw new \RuntimeException(esc_html__('A migrated association changed. The public source was retained.', 'leenkat-forms'));
            $candidate=self::source($plan,$file);$source=FileRecords::legacyPath(['path'=>$candidate]);if(!$source){if(file_exists($candidate)||is_link($candidate))throw new \RuntimeException(esc_html__('A public source is unavailable for verification. It was retained.', 'leenkat-forms'));$file['state']='source_removed';self::write($plan);continue;}
            if(!PrivateStorage::matches($file['destination_key'],(int)$file['bytes'],$file['sha256'])||!PrivateStorage::matches($file['backup_key'],(int)$file['bytes'],$file['sha256'])||!hash_equals($file['sha256'],(string)hash_file('sha256',$source))||self::isMedia($source))throw new \RuntimeException(esc_html__('Source removal verification failed. The source was retained.', 'leenkat-forms'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            $shared=false;$last=0;do{$rows=$wpdb->get_results($wpdb->prepare("SELECT id,meta FROM {$entries} WHERE id>%d ORDER BY id LIMIT 100",$last));if($wpdb->last_error||!is_array($rows))throw new \RuntimeException(esc_html__('Shared file references could not be verified. Sources were retained.', 'leenkat-forms'));foreach($rows as $row){$last=(int)$row->id;$meta=json_decode((string)$row->meta,true) ?: [];foreach($meta['uploads'] ?? [] as $record)if(is_array($record)&&FileRecords::legacyPath($record)===$source)$shared=true;}}while(!$shared&&count($rows)===100);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
            if($shared){$file['message']=__('Public copy retained because another entry still references it.', 'leenkat-forms');self::write($plan);continue;}if(!@unlink($source)){$file['message']=__('Public source cleanup failed; retry is required.', 'leenkat-forms');self::write($plan);continue;}$file['state']='source_removed';unset($file['message']);self::write($plan);
        }unset($file);return self::report($plan);
    }
    /** Restoring public source URLs requires an explicit operator decision. */
    public static function rollback(string $run,bool $confirmPublicExposure=false): array {
        self::gate();if(!$confirmPublicExposure)throw new \RuntimeException(esc_html__('Rollback requires explicit approval to restore the former public files.', 'leenkat-forms'));
        $lock=self::lock($run);global $wpdb;$leases=[];
        try{
            $plan=self::read($run);$entries=$wpdb->prefix.'lkf_entries';$files=$wpdb->prefix.'lkf_entry_files';$values=$wpdb->prefix.'lkf_entry_values';$jobs=$wpdb->prefix.'lkf_deliveries';
            if(!Transactions::supported([$entries,$files,$values,$jobs]))throw new \RuntimeException(__('Transactional recovery is unavailable.', 'leenkat-forms'));
            foreach(array_keys($plan['entries']) as $id)$leases[]=FileLease::acquire((int)$id,true);
            foreach($plan['entries'] as $entryId=>$snapshot){
                if(!isset($snapshot['migrated_hash']))continue;$indices=[];foreach($plan['files'] as $i=>$file)if($file['entry_id']===(int)$entryId)$indices[]=$i;
                if(!$indices)continue;$restored=[];$committed=false;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                if($wpdb->query('START TRANSACTION')===false)throw new \RuntimeException(__('Recovery could not start.', 'leenkat-forms'));
                try{
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
                    $entry=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$entries} WHERE id=%d FOR UPDATE",$entryId),ARRAY_A);
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
                    $lockedJobs=$wpdb->get_results($wpdb->prepare("SELECT status FROM {$jobs} WHERE entry_id=%d FOR UPDATE",$entryId),ARRAY_A);
                    if($wpdb->last_error)throw new \RuntimeException(__('Notification state unavailable.', 'leenkat-forms'));foreach($lockedJobs as $job)if($job['status']==='processing')throw new \RuntimeException(__('A notification is processing. Retry recovery later.', 'leenkat-forms'));
                    $hash=hash('sha256',wp_json_encode($entry));$originalHash=hash('sha256',wp_json_encode($snapshot['row']));
                    if(!$entry||(!hash_equals($snapshot['migrated_hash'],$hash)&&!hash_equals($originalHash,$hash)))throw new \RuntimeException(__('An entry changed after migration. Automatic rollback was refused.', 'leenkat-forms'));
                    foreach($indices as $i){
                        $file=$plan['files'][$i];$backup=PrivateStorage::verify($file['backup_key'],(int)$file['bytes'],$file['sha256']);if(!$backup)throw new \RuntimeException(__('A recovery backup is unavailable.', 'leenkat-forms'));
                        $source=self::source($plan,$file);$parent=realpath(dirname($source));$base=realpath(wp_upload_dir()['basedir']);
                        if(!$parent||!$base||!PrivateStorage::inside($parent,$base)||!PrivateStorage::noLinks($source))throw new \RuntimeException(__('A recovery destination is unavailable.', 'leenkat-forms'));
                        if(file_exists($source)){if(!is_file($source)||!hash_equals($file['sha256'],(string)hash_file('sha256',$source)))throw new \RuntimeException(__('An existing source changed. Recovery will not overwrite it.', 'leenkat-forms'));}
                        else{
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
                            $in=@fopen($backup,'rb');if(!$in)throw new \RuntimeException(__('Source recovery failed.', 'leenkat-forms'));$out=@fopen($source,'xb');if(!$out){fclose($in);throw new \RuntimeException(__('Source recovery failed.', 'leenkat-forms'));}$restored[]=$source;
                            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
                            $bytes=stream_copy_to_stream($in,$out,(int)$file['bytes']+1);fflush($out);if(function_exists('fsync'))fsync($out);fclose($in);fclose($out);
                            if($bytes!==(int)$file['bytes']||!hash_equals($file['sha256'],(string)hash_file('sha256',$source)))throw new \RuntimeException(__('Source recovery verification failed.', 'leenkat-forms'));
                        }
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                    foreach($indices as $i){$key=$plan['files'][$i]['destination_key'];if($key&&$wpdb->delete($files,['storage_key'=>$key,'entry_id'=>$entryId])===false)throw new \RuntimeException(__('Recovery association failed.', 'leenkat-forms'));}
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                    if($wpdb->update($entries,$snapshot['row'],['id'=>$entryId])===false)throw new \RuntimeException(__('Recovery entry failed.', 'leenkat-forms'));
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                    foreach($snapshot['projections'] as $projection)if($wpdb->update($values,$projection,['id'=>$projection['id']])===false)throw new \RuntimeException(__('Recovery values failed.', 'leenkat-forms'));
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
                    if($wpdb->query('COMMIT')===false)throw new \RuntimeException(__('Recovery could not commit.', 'leenkat-forms'));$committed=true;
                }finally{
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.WP.AlternativeFunctions.unlink_unlink -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
                    if(!$committed){$wpdb->query('ROLLBACK');foreach($restored as $source)@unlink($source);}
                }
                foreach($indices as $i){$file=&$plan['files'][$i];if($file['destination_key'])FileRecords::discard([['storage_key'=>$file['destination_key']]]);$file['destination_key']=null;$file['state']='rolled_back';unset($file);}self::write($plan);
            }
            return self::report($plan);
        }finally{foreach($leases as $lease)FileLease::release($lease);delete_option($lock);}
    }
    private static function documents(): \Generator {
        $roots=[false=>PrivateStorage::legacyRoot()];
        if(get_option(EncryptedStorage::KEY_OPTION,null)!==null)$roots[true]=EncryptedStorage::root();
        foreach($roots as $encrypted=>$root){
            if(!is_dir($root))continue;
            $suffix=$encrypted?'lkf':'json';
            foreach(new \GlobIterator($root.'/migration-*.'.$suffix,\FilesystemIterator::SKIP_DOTS) as $file){
                if(!preg_match('/^migration-([a-f0-9]{32})\.(?:json|lkf)$/D',$file->getFilename(),$match))continue;
                if(!$file->isFile()||!PrivateStorage::noLinks($file->getPathname()))throw new \RuntimeException(esc_html__('A migration record needs administrator attention.', 'leenkat-forms'));
                yield self::stored($match[1],(bool)$encrypted);
            }
        }
    }
    public static function pendingPublicSource(int $entryId): bool {
        foreach(self::documents() as $plan)foreach($plan['files'] as $file)if($file['entry_id']===$entryId&&$file['state']!=='source_removed'&&FileRecords::legacyPath(['path'=>self::source($plan,$file)]))return true;return false;
    }
    /** Called only after an authorized entry deletion; backups obey that deletion as well. */
    public static function purgeEntryBackups(int $entryId): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        global $wpdb;try{foreach(self::documents() as $plan){if(!isset($plan['entries'][$entryId]))continue;$remaining=[];foreach($plan['files'] as $file){if($file['entry_id']!==$entryId){$remaining[]=$file;continue;}foreach([$file['backup_key'],$file['destination_key']] as $key){if(!$key)continue;$refs=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}lkf_entry_files WHERE storage_key=%s",$key));if($wpdb->last_error)return false;if(!$refs&&!PrivateStorage::remove($key))return false;}}$plan['files']=$remaining;unset($plan['entries'][$entryId]);$plan['erased_entry_ids'][]=$entryId;self::write($plan);}return true;}catch(\Throwable){return false;}
    }

}
