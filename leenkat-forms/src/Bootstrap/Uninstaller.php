<?php
declare(strict_types=1);
namespace LeenkatForms\Bootstrap;

use LeenkatForms\Files\PrivateStorage;
use LeenkatForms\Files\EncryptedStorage;
use LeenkatForms\Files\AttachmentMigration;

/** Bounded ownership inventory, durable file-first journal, then exact-name DB cleanup. */
final class Uninstaller {
    private const TABLES=['entry_values','entry_files','deliveries','entries','form_versions','forms'];
    private const HOOKS=['lkf_send_delivery','lkf_reconcile_deliveries','lkf_file_maintenance'];
    private const CAPS=['lkf_manage_forms','lkf_view_entries','lkf_manage_settings'];
    private const STATE='lkf_uninstall_state';
    private const ERROR='lkf_uninstall_error';

    public static function run(): void {
        if(!defined('WP_UNINSTALL_PLUGIN')||WP_UNINSTALL_PLUGIN!==plugin_basename(dirname(__DIR__,2).'/leenkat-forms.php'))throw new \RuntimeException(esc_html__('A WordPress uninstall request is required.', 'leenkat-forms'));
        $settings=get_option('lkf_site_settings',[]);
        if(!is_array($settings)||($settings['general']['delete_all_data_on_uninstall'] ?? null)!==true)return;
        // Per-site storage and shared user grants need a separate network-wide contract.
        if(is_multisite())throw new \RuntimeException(esc_html__('Network uninstall is not supported. No data was removed.', 'leenkat-forms'));
        global $wpdb;
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$wpdb->prefix))throw new \RuntimeException(esc_html__('Invalid table prefix.', 'leenkat-forms'));
        $lock='lkf_uninstall_'.substr(hash('sha256',DB_NAME.'|'.$wpdb->prefix),0,40);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')throw new \RuntimeException(esc_html__('Another uninstall is in progress.', 'leenkat-forms'));
        try {
            $roots=self::storageRoots();$state=get_option(self::STATE,null);
            if($state!==null&&(!is_array($state)||!hash_equals(self::signature($state),(string)($state['signature'] ?? ''))||($state['site'] ?? '')!==self::site()||($state['roots'] ?? '')!==$roots||!in_array($state['phase'] ?? '',['files','metadata'],true)||!is_array($state['inventories'] ?? null)))throw new \RuntimeException(__('The uninstall journal or storage configuration changed. Restore the original configuration before retrying.', 'leenkat-forms'));
            self::busyCheck();
            if($state===null){$state=['site'=>self::site(),'roots'=>$roots,'phase'=>'files','inventories'=>self::inventory($roots),'started'=>gmdate('c')];self::persist($state);}
            foreach($roots as $kind=>$root){
                if(is_dir($root))self::removeFiles(['root'=>$root,'files'=>$state['inventories'][$kind]]);
            }
            if($state['phase']==='files'){$state['phase']='metadata';self::persist($state);}
            self::jobs();self::capabilities();self::metadata();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted. Only the explicit versioned schema migration/uninstall path changes owned plugin tables.
            foreach(self::TABLES as $suffix){$table=$wpdb->prefix.'lkf_'.$suffix;if($wpdb->query("DROP TABLE IF EXISTS `{$table}`")===false)throw new \RuntimeException(__('An owned table could not be removed. Retry deletion.', 'leenkat-forms'));}
            // The opt-in and journal are deliberately last, after every destructive phase.
            self::deleteOption(self::ERROR);self::deleteOption(self::STATE);self::deleteOption(EncryptedStorage::KEY_OPTION);self::deleteOption('lkf_site_settings');
        } catch(\Throwable $error) {
            update_option(self::ERROR,['time'=>gmdate('c'),'message'=>$error->getMessage()],false);
            throw $error;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        } finally {$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }
    private static function site(): string {global $wpdb;return get_option(EncryptedStorage::KEY_OPTION,null)!==null?EncryptedStorage::identity():hash('sha256',ABSPATH.'|'.get_current_blog_id().'|'.$wpdb->prefix);}
    private static function signature(array $state): string {unset($state['signature']);return hash_hmac('sha256',serialize($state),wp_salt('auth'));}
    private static function persist(array $state): void {
        $state['signature']=self::signature($state);
        if(get_option(self::STATE,null)!==$state&&!update_option(self::STATE,$state,false))throw new \RuntimeException(esc_html__('The uninstall journal could not be saved.', 'leenkat-forms'));
    }
    private static function exists(string $suffix): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        global $wpdb;$name=$wpdb->prefix.'lkf_'.$suffix;$found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($name)));
        if($wpdb->last_error)throw new \RuntimeException(esc_html__('Table ownership could not be checked.', 'leenkat-forms'));return $found===$name;
    }
    private static function rows(string $sql): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
        global $wpdb;$rows=$wpdb->get_results($sql,ARRAY_A);if(!is_array($rows)||$wpdb->last_error)throw new \RuntimeException(esc_html__('Ownership metadata could not be read.', 'leenkat-forms'));return $rows;
    }
    private static function busyCheck(): void {
        global $wpdb;
        foreach(['lkf_file_migration_lock','lkf_site_settings_lock','lkf_captcha_settings_lock','lkf_migration_lock'] as $name)if((int)get_option($name,0)>time())throw new \RuntimeException(esc_html__('Plugin maintenance is in progress. Retry after it finishes.', 'leenkat-forms'));
        if(self::exists('deliveries')&&self::rows("SELECT id FROM {$wpdb->prefix}lkf_deliveries WHERE status='processing' LIMIT 1"))throw new \RuntimeException(esc_html__('A delivery is processing. Wait for it to finish before retrying.', 'leenkat-forms'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $actions=$wpdb->prefix.'actionscheduler_actions';$groups=$wpdb->prefix.'actionscheduler_groups';$found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($actions)));
        if($wpdb->last_error)throw new \RuntimeException(esc_html__('Scheduled actions could not be checked.', 'leenkat-forms'));
        if($found===$actions&&self::rows("SELECT a.action_id FROM `{$actions}` a JOIN `{$groups}` g ON a.group_id=g.group_id WHERE a.hook='lkf_send_delivery' AND g.slug='leenkat-forms' AND a.status='in-progress' LIMIT 1"))throw new \RuntimeException(esc_html__('A scheduled delivery is running. Retry later.', 'leenkat-forms'));
    }
    /** Does not create storage. Reject links, traversal, ancestor roots and public/shared roots. */
    private static function storageRoots(): array {
        $roots=['legacy'=>PrivateStorage::legacyRoot()];
        if(get_option(EncryptedStorage::KEY_OPTION,null)!==null)$roots['encrypted']=EncryptedStorage::root();
        if(isset($roots['encrypted'])&&(PrivateStorage::inside($roots['legacy'],$roots['encrypted'])||PrivateStorage::inside($roots['encrypted'],$roots['legacy'])))throw new \RuntimeException(esc_html__('Private storage paths overlap.', 'leenkat-forms'));
        return $roots;
    }
    private static function digest(string $path): array {
        clearstatcache(true,$path);if(!is_file($path)||!PrivateStorage::noLinks($path))throw new \RuntimeException(esc_html__('A private object is not a regular file.', 'leenkat-forms'));
        $hash=@hash_file('sha256',$path);$size=filesize($path);if(!is_string($hash)||$size===false)throw new \RuntimeException(esc_html__('A private object could not be verified. Check permissions and active file leases.', 'leenkat-forms'));return ['bytes'=>$size,'sha256'=>$hash];
    }
    private static function inventory(array $roots): array {
        global $wpdb;$owned=[];$actual=[];$proof=[];$journals=[];
        $add=static function(string $key,int $bytes,string $hash)use(&$owned):void{
            if(!PrivateStorage::validKey($key)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||$bytes<0)throw new \RuntimeException(esc_html__('A private file association is invalid.', 'leenkat-forms'));
            $item=['bytes'=>$bytes,'sha256'=>$hash];if(isset($owned[$key])&&$owned[$key]!==$item)throw new \RuntimeException(esc_html__('Conflicting private file associations.', 'leenkat-forms'));$owned[$key]=$item;
        };
        if(self::exists('entry_files'))foreach(self::rows("SELECT storage_key,byte_size,sha256,status FROM {$wpdb->prefix}lkf_entry_files") as $row){if(str_starts_with($row['storage_key'],'media:'))continue;$add($row['storage_key'],(int)$row['byte_size'],$row['sha256']);
            $kind=PrivateStorage::encrypted($row['storage_key'])?'encrypted':'legacy';
            if($row['status']==='private'&&(!isset($roots[$kind])||!is_dir($roots[$kind])))throw new \RuntimeException(esc_html__('Restore the private storage directory before uninstalling.', 'leenkat-forms'));
        }
        // Legacy public sources must first pass the existing migration workflow. Media stays.
        if(self::exists('entries'))foreach(self::rows("SELECT meta FROM {$wpdb->prefix}lkf_entries") as $row){$meta=json_decode($row['meta'],true);if(!is_array($meta))throw new \RuntimeException(esc_html__('An entry file reference is invalid.', 'leenkat-forms'));foreach($meta['uploads'] ?? [] as $file)if(is_array($file)&&empty($file['attachment_id'])&&empty($file['file_id'])&&empty($file['storage_key'])&&!empty($file['path'])&&(file_exists($file['path'])||is_link($file['path'])||\LeenkatForms\Files\FileRecords::legacyPath($file)!==null))throw new \RuntimeException(esc_html__('Legacy public attachments require migration before uninstall.', 'leenkat-forms'));}
        foreach($roots as $kind=>$root){
            $actual[$kind]=[];$journals[$kind]=[];$proof[$kind]=$kind==='encrypted'||$root===wp_normalize_path(PrivateStorage::legacyDefault());
            if(!is_dir($root))continue;
            foreach(new \DirectoryIterator($root) as $file){
                if($file->isDot())continue;
                if(count($actual[$kind])>=100000||!$file->isFile()||!PrivateStorage::noLinks($file->getPathname()))throw new \RuntimeException(esc_html__('Storage contains an unverified directory, link or too many files.', 'leenkat-forms'));
                $actual[$kind][$file->getFilename()]=self::digest($file->getPathname());
            }
            $suffix=$kind==='encrypted'?'lkf':'json';
            foreach($actual[$kind] as $name=>$digest){
                if(!preg_match('/^migration-([a-f0-9]{32})\.'.$suffix.'$/D',$name,$match))continue;
                $plan=AttachmentMigration::stored($match[1],$kind==='encrypted');
                foreach($plan['files'] as $item)foreach(['backup_key','destination_key'] as $key)if(!empty($item[$key]))$add($item[$key],(int)($item['bytes'] ?? -1),(string)($item['sha256'] ?? ''));
                $journals[$kind][$name]=$digest;$proof[$kind]=true;
            }
        }
        $result=[];
        foreach($roots as $kind=>$root){
            $result[$kind]=[];
            foreach($actual[$kind] as $name=>$digest){
                if(isset($journals[$kind][$name])){$result[$kind][$name]=$digest;continue;}
                if(isset($owned[$name])){
                    if($kind==='encrypted'&&PrivateStorage::encrypted($name)){
                        $path=EncryptedStorage::open($name,$owned[$name]['bytes'],$owned[$name]['sha256']);
                        if(!$path)throw new \RuntimeException(esc_html__('Encrypted private file verification failed.', 'leenkat-forms'));
                        EncryptedStorage::close($path);
                    }elseif($kind!=='legacy'||PrivateStorage::encrypted($name)||$owned[$name]!==$digest){
                        throw new \RuntimeException(esc_html__('A private file changed; ownership verification failed.', 'leenkat-forms'));
                    }
                    $proof[$kind]=true;$result[$kind][$name]=$digest;continue;
                }
                $lockPattern=$kind==='encrypted'?'/^entry-[a-f0-9]{64}\.lock$/D':'/^entry-[1-9][0-9]*\.lock$/D';
                if(preg_match($lockPattern,$name)&&$digest['bytes']===0){$result[$kind][$name]=$digest;continue;}
                $key=str_ends_with($name,'.pending')?substr($name,0,-8):$name;$pending=$root.'/'.$key.'.pending';
                if(PrivateStorage::validKey($key)&&PrivateStorage::encrypted($key)===($kind==='encrypted')&&isset($actual[$kind][$key.'.pending'])&&$actual[$kind][$key.'.pending']['bytes']<=20&&ctype_digit((string)file_get_contents($pending))){$result[$kind][$name]=$digest;continue;}
                throw new \RuntimeException(esc_html__('Storage contains an unverified file. No ownership metadata was removed.', 'leenkat-forms'));
            }
            if(is_dir($root)&&!$proof[$kind])throw new \RuntimeException(esc_html__('Custom private storage ownership cannot be verified.', 'leenkat-forms'));
        }
        return $result;
    }
    private static function removeFiles(array $state): void {
        $root=$state['root'];if(!is_dir($root))return;$files=$state['files'];$leases=[];
        try {
            foreach(new \DirectoryIterator($root) as $file){if($file->isDot())continue;$name=$file->getFilename();
                if(!isset($files[$name])||basename($name)!==$name||!PrivateStorage::noLinks($file->getPathname())||self::digest($file->getPathname())!==$files[$name])throw new \RuntimeException(__('Storage changed after the uninstall inventory. Retry only after resolving ownership.', 'leenkat-forms'));
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
                if(str_ends_with($name,'.lock')){$handle=fopen($file->getPathname(),'r+');if(!$handle||!flock($handle,LOCK_EX|LOCK_NB)){if(is_resource($handle))fclose($handle);throw new \RuntimeException(__('Private files are in use. Retry later.', 'leenkat-forms'));}$leases[$name]=$handle;}
            }
            foreach($files as $name=>$digest){if(basename($name)!==$name||str_contains($name,'\\'))throw new \RuntimeException(__('Invalid uninstall inventory.', 'leenkat-forms'));$path=$root.'/'.$name;if(!file_exists($path)&&!is_link($path))continue;
                // Recheck immediately before unlink; never follow a substituted link.
                // Windows locks are mandatory: inspect our existing handle instead of reopening it.
                $current=isset($leases[$name])?['bytes'=>(fstat($leases[$name])['size'] ?? -1),'sha256'=>hash('sha256','')]:self::digest($path);
                if(!PrivateStorage::noLinks($root)||!PrivateStorage::noLinks($path)||$current!==$digest)throw new \RuntimeException(__('A private file changed before removal.', 'leenkat-forms'));
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
                if(isset($leases[$name])){fclose($leases[$name]);unset($leases[$name]);}
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
                if(!unlink($path))throw new \RuntimeException(__('A private file could not be removed. Retry deletion after checking permissions.', 'leenkat-forms'));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
            if(!PrivateStorage::noLinks($root)||!rmdir($root))throw new \RuntimeException(__('Private storage is not empty or could not be removed.', 'leenkat-forms'));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
        } finally {foreach($leases as $handle)fclose($handle);}
    }
    private static function jobs(): void {
        foreach(self::HOOKS as $hook){$result=wp_unschedule_hook($hook,true);if(is_wp_error($result))throw new \RuntimeException(esc_html__('A plugin cron job could not be removed.', 'leenkat-forms'));}
        global $wpdb;$table=$wpdb->prefix.'actionscheduler_actions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));if($wpdb->last_error)throw new \RuntimeException(esc_html__('Scheduled actions could not be checked.', 'leenkat-forms'));if($found!==$table)return;
        $group=$wpdb->prefix.'actionscheduler_groups';
        // Use a loaded provider's API, or exact verified IDs if its plugin is inactive.
        // Do not boot an unrelated plugin/library during uninstall.
        $store=class_exists('ActionScheduler')?\ActionScheduler::store():null;$logs=$wpdb->prefix.'actionscheduler_logs';
        do{$rows=self::rows("SELECT a.action_id,a.status,a.claim_id FROM `{$table}` a INNER JOIN `{$group}` g ON a.group_id=g.group_id WHERE a.hook='lkf_send_delivery' AND g.slug='leenkat-forms' LIMIT 100");foreach($rows as $row){$id=(int)$row['action_id'];if($row['status']==='in-progress')throw new \RuntimeException(esc_html__('A scheduled delivery is running. Retry later.', 'leenkat-forms'));
            // Logs first: a failed callback must not orphan sensitive job history on retry.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            if($wpdb->delete($logs,['action_id'=>$id])===false)throw new \RuntimeException(esc_html__('A scheduled action log could not be removed.', 'leenkat-forms'));
            if($store)$store->delete_action($id);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            elseif($wpdb->delete($table,['action_id'=>$id,'hook'=>'lkf_send_delivery','status'=>$row['status']])!==1)throw new \RuntimeException(esc_html__('A scheduled action could not be removed.', 'leenkat-forms'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
            $claim=(int)$row['claim_id'];if($claim){$claims=$wpdb->prefix.'actionscheduler_claims';if($wpdb->query($wpdb->prepare("DELETE c FROM `{$claims}` c LEFT JOIN `{$table}` a ON c.claim_id=a.claim_id WHERE c.claim_id=%d AND a.action_id IS NULL",$claim))===false)throw new \RuntimeException(esc_html__('An unused plugin job claim could not be removed.', 'leenkat-forms'));}
        }}while($rows);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
        $remaining=(int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}` a INNER JOIN `{$group}` g ON a.group_id=g.group_id WHERE a.hook='lkf_send_delivery' AND g.slug='leenkat-forms'");if($wpdb->last_error||$remaining)throw new \RuntimeException(esc_html__('Scheduled action cleanup is incomplete.', 'leenkat-forms'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
        if($wpdb->query("DELETE g FROM `{$group}` g LEFT JOIN `{$table}` a ON a.group_id=g.group_id WHERE g.slug='leenkat-forms' AND a.action_id IS NULL")===false)throw new \RuntimeException(esc_html__('The empty plugin job group could not be removed.', 'leenkat-forms'));
    }
    /** Exact schema-owned capability keys; preserve every other role/user grant. */
    private static function capabilities(): void {
        global $wpdb;foreach(wp_roles()->roles as $name=>$role){$object=get_role($name);foreach(self::CAPS as $cap)if(array_key_exists($cap,$role['capabilities']))$object->remove_cap($cap);}
        $key=$wpdb->prefix.'capabilities';$rows=self::rows($wpdb->prepare("SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%s",$key));
        foreach($rows as $row){$caps=maybe_unserialize($row['meta_value']);if(!is_array($caps))throw new \RuntimeException(esc_html__('A user capability record could not be verified.', 'leenkat-forms'));$clean=array_diff_key($caps,array_flip(self::CAPS));if($clean!==$caps){update_user_meta((int)$row['user_id'],$key,$clean);if(get_user_meta((int)$row['user_id'],$key,true)!==$clean)throw new \RuntimeException(esc_html__('A plugin user grant could not be removed.', 'leenkat-forms'));}}
        // No standalone plugin usermeta exists in dev.49. Media is retained; its lease is ours.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Cleanup targets only plugin-owned media metadata and must verify ownership before deletion.
        if($wpdb->delete($wpdb->postmeta,['meta_key'=>'_lkf_upload_lease'])===false)throw new \RuntimeException(esc_html__('Upload lease metadata could not be removed.', 'leenkat-forms'));
    }
    public static function ownedOption(string $name): bool {
        if(in_array($name,['lkf_db_version','lkf_installed_version','lkf_migration_lock','lkf_migration_error','lkf_captcha_settings','lkf_captcha_settings_lock','lkf_spam_keywords','lkf_spam_keywords_lock','lkf_site_settings_lock','lkf_file_migration_latest','lkf_file_migration_lock','lkf_backup_cleanup_pending','lkf_file_cleanup_attention'],true))return true;
        if(preg_match('/^lkf_(?:import_(?:done|lock)_[1-9][0-9]*_[a-f0-9]{48}|rate_lock_[a-f0-9]{64}|captcha_pass_[a-f0-9]{64}_lock|akismet_check_[a-f0-9]{64}_lock|unstored_[a-f0-9]{64}_lock)$/D',$name))return true;
        return (bool)preg_match('/^_transient_(?:timeout_)?lkf_(?:rate_[a-f0-9]{64}|captcha_pass_[a-f0-9]{64}|akismet_(?:key|check)_[a-f0-9]{64}|unstored_[a-f0-9]{64}(?:_[A-Za-z][A-Za-z0-9_-]{0,63})?|import_[1-9][0-9]*_[a-f0-9]{48}|(?:site_notice|settings_notice)_[1-9][0-9]*|erase_cursor_[1-9][0-9]*_[a-f0-9]{64}|result_[A-Za-z0-9]{32})$/D',$name);
    }
    private static function deleteOption(string $name): void {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
        global $wpdb;delete_option($name);$exists=$wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name=%s",$name));if($wpdb->last_error||$exists!==null)throw new \RuntimeException(esc_html__('An owned option could not be removed.', 'leenkat-forms'));
    }
    private static function metadata(): void {
        global $wpdb;
        $rows=self::rows("SELECT option_name FROM {$wpdb->options}");
        foreach($rows as $row){$name=$row['option_name'];if(!self::ownedOption($name))continue;
            if(str_starts_with($name,'_transient_')){$key=preg_replace('/^_transient_(?:timeout_)?/','',$name);delete_transient($key);}self::deleteOption($name);
        }
    }
}
