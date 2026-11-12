<?php
declare(strict_types=1);
namespace LeenkatForms\Submissions;

/** No answers, recipient addresses or visitor details are persisted in this path. */
final class UnstoredSubmission {
    public static function process(int $formId,string $uuid,string $hash,array $published,array $values,array $pending,array $fieldParts=[],array $fieldChoices=[]): bool {
        global $wpdb;
        $key='lkf_unstored_'.hash_hmac('sha256',$formId.'|'.$uuid,wp_salt('auth'));
        $lock=$key.'_lock';
        if(!add_option($lock,(string)(time()+120),'',false)) {
            $expires=(int)get_option($lock,0);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
            if($expires>0 && $expires<time()) { $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name=%s AND option_value=%s",$lock,(string)$expires));wp_cache_delete($lock,'options'); }
            return false;
        }
        $files=[];$retained=false;
        try {
            $receipt=get_transient($key);
            if(is_string($receipt))return hash_equals($receipt,$hash);
            // Recheck that the same version is still published before any processing.
            $current=\LeenkatForms\Forms\Publisher::published($formId);
            if(!$current || $current['version_id']!==$published['version_id'])return false;
            foreach($pending as $fieldId=>$upload) {
                // Unstored files are email attachments, then removed; explicit Media Library files remain there.
                $before=count($files);$stored=FileUploads::store($upload['files'],$upload['media_library'],$files);
                if(is_wp_error($stored))return false;
                for($i=$before;$i<count($files);$i++) { $files[$i]['retain']=!empty($upload['media_library']);$files[$i]['field_id']=$fieldId; }
                $values[$fieldId]=implode(', ',array_column(array_slice($files,$before),'name'));
            }
            $settings=\LeenkatForms\Forms\NotificationSettings::normalize($published['definition']);
            $user=wp_get_current_user();
            $context=['form_id'=>$formId,'entry_id'=>'','entry_date'=>current_time('mysql'),'user_email'=>$user->user_email ?? '','uploads'=>$files,'field_parts'=>$fieldParts,'field_choices'=>$fieldChoices];
            foreach(empty($settings['enabled'])?[]:$settings['items'] as $config) {
                $sentKey=$key.'_'.$config['id'];$sent=get_transient($sentKey);
                if(is_string($sent)) { if(!hash_equals($sent,$hash))return false;continue; }
                // Keep the old no-entry upload attachment behavior for original admin/user notifications.
                if(!isset($published['definition']['settings']['notifications']['items'])) { $config['file_attachments']=true;$config['file_fields']=array_keys($pending); }
                $result=\LeenkatForms\Workflow\Notifications::send($config,$published['definition'],$values,$context);
                if($result==='failed')return false;
                if(!set_transient($sentKey,$hash,24*60*MINUTE_IN_SECONDS))return false;
            }
            // Only a keyed digest is kept temporarily for retry deduplication; no submitted values.
            if(!set_transient($key,$hash,24*60*MINUTE_IN_SECONDS))return false;
            foreach($files as $file)if(!empty($file['attachment_id']))delete_post_meta((int)$file['attachment_id'],'_lkf_upload_lease');
            $retained=true;return true;
        } finally {
            FileUploads::cleanup(array_values(array_filter($files,static fn(array $file):bool=>!$retained || empty($file['retain']))));
            delete_option($lock);
        }
    }
}
