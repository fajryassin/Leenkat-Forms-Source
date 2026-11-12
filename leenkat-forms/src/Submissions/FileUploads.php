<?php
declare(strict_types=1);
namespace LeenkatForms\Submissions;

/** Files enter through a validated published form; WordPress performs the final MIME checks. */
final class FileUploads {
    public const EXTENSIONS = ['jpg','jpeg','png','gif','webp','pdf','txt','csv','doc','docx','xls','xlsx','ppt','pptx','zip','mp3','mp4','ogg','wav'];
    public static function extensions(array $settings): array {
        $requested = preg_split('/[\s,]+/', strtolower((string)($settings['allowed_extensions'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        return $requested ? array_values(array_intersect(array_map(static fn(string $ext): string => ltrim($ext,'.'), $requested), self::EXTENSIONS)) : self::EXTENSIONS;
    }
    public static function maxBytes(array $settings): int {
        return min(wp_max_upload_size(), (int)((($settings['max_file_size'] ?? 0) ?: 50) * MB_IN_BYTES), 50 * MB_IN_BYTES);
    }
    /** Effective per-file bound, after the unchanged WordPress and plugin ceilings. */
    public static function sizeLabel(array $settings): string {
        $megabytes=rtrim(rtrim(number_format(self::maxBytes($settings)/MB_IN_BYTES,2,'.',''),'0'),'.');
        return sprintf(/* translators: %s: maximum upload size in megabytes. */ __('%s MB','leenkat-forms'),$megabytes);
    }
    public static function prepare(mixed $files, array $field): array|\WP_Error {
        $settings=$field['settings'] ?? [];
        if ($files === null) { return empty($field['required']) ? [] : self::error('required',$settings); }
        if (!is_array($files) || !is_array($files['name'] ?? null) || !array_is_list($files['name'])) { return self::error('failed',$settings); }
        if(count($files['name'])>10)return self::error('count',$settings);
        $selected=0;foreach($files['name'] as $i=>$name)if(($files['error'][$i]??-1)!==UPLOAD_ERR_NO_FILE)$selected++;
        if($selected>($settings['max_files']??1))return self::error('count',$settings);
        $prepared = [];
        foreach ($files['name'] as $i=>$name) {
            $uploadError=$files['error'][$i]??-1;
            if ($uploadError === UPLOAD_ERR_NO_FILE) { continue; }
            if(in_array($uploadError,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true))return self::error('size',$settings);
            $tmp = $files['tmp_name'][$i] ?? null;
            if (!is_string($name) || !is_string($tmp) || $uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) { return self::error('failed',$settings); }
            if(strpbrk($name,"/\\\r\n\0")!==false)return self::error('failed',$settings);
            if(isset($files['full_path'][$i])&&(!is_string($files['full_path'][$i])||strpbrk($files['full_path'][$i],"/\\:\r\n\0")!==false))return self::error('failed',$settings);
            if(preg_match('/\.(php[0-9]*|pht(?:ml)?|phar|html?|shtml|cgi|pl|py|rb|aspx?|jsp?|svg|exe|bat|cmd|ps1|com|scr|msi|dll)(?:\.|$)/i',$name))return self::error('type',$settings);
            $name = sanitize_file_name($name);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if($name===''||strlen($name)>200)return self::error('failed',$settings);
            if(!in_array($ext,self::extensions($settings),true))return self::error('type',$settings);
            $size = @filesize($tmp);
            if(!$size)return self::error('failed',$settings);
            if($size>self::maxBytes($settings))return self::error('size',$settings);
            $mime = wp_check_filetype_and_ext($tmp, $name, get_allowed_mime_types());
            if (!empty($mime['proper_filename']) || empty($mime['ext']) || empty($mime['type']) || !\LeenkatForms\Files\MimePolicy::valid($tmp,$ext)) { return self::error('mime',$settings); }
            $prepared[] = ['name'=>$name,'tmp_name'=>$tmp,'type'=>$mime['type'],'size'=>$size,'error'=>0,'sha256'=>hash_file('sha256',$tmp)];
        }
        if (!empty($field['required']) && !$prepared) { return self::error('required',$settings); }
        return $prepared;
    }
    public static function store(array $prepared, bool $mediaLibrary, array &$created): string|\WP_Error {
        if (!$prepared) { return ''; }
        $display = [];
        foreach ($prepared as $file) {
            $name=$file['name'];
            if(!$mediaLibrary){
                try{$key=\LeenkatForms\Files\PrivateStorage::put($file['tmp_name'],strtolower(pathinfo($name,PATHINFO_EXTENSION)),(int)$file['size'],$file['sha256']);}catch(\Throwable){return new \WP_Error('lkf_private_storage',__('The file could not be stored securely. Please try again later.','leenkat-forms'),['status'=>503]);}
                $created[]=['storage_key'=>$key,'kind'=>'private','attachment_id'=>0,'name'=>$name,'mime'=>$file['type'],'size'=>(int)$file['size'],'sha256'=>$file['sha256']];$display[]=$name;continue;
            }
            if(!function_exists('wp_handle_upload'))require_once ABSPATH . 'wp-admin/includes/file.php';
            $file['name']='lkf-'.bin2hex(random_bytes(24)).'.'.strtolower(pathinfo($name,PATHINFO_EXTENSION));unset($file['sha256']);
            $upload=wp_handle_upload($file,['test_form'=>false,'mimes'=>get_allowed_mime_types()]);if(isset($upload['error']))return self::error('storage',[],503);
            $record=['path'=>$upload['file'],'attachment_id'=>0,'name'=>$name,'url'=>$upload['url'],'kind'=>'media','size'=>filesize($upload['file']),'sha256'=>hash_file('sha256',$upload['file'])];$created[]=$record;
            $attachment=wp_insert_attachment(['post_title'=>pathinfo($name,PATHINFO_FILENAME),'post_mime_type'=>$upload['type'],'post_status'=>'inherit'],$upload['file'],0,true);if(is_wp_error($attachment))return self::error('storage',[],503);
            if(!function_exists('wp_generate_attachment_metadata'))require_once ABSPATH . 'wp-admin/includes/image.php';
            $index=array_key_last($created);$created[$index]['attachment_id']=(int)$attachment;$metadata=wp_generate_attachment_metadata((int)$attachment,$upload['file']);wp_update_attachment_metadata((int)$attachment,$metadata);
            $actual=get_attached_file((int)$attachment);if(!is_string($actual)||!is_file($actual))return self::error('storage',[],503);$lease=bin2hex(random_bytes(24));update_post_meta((int)$attachment,'_lkf_upload_lease',$lease);
            $created[$index]=array_replace($created[$index],['path'=>$actual,'mime'=>$upload['type'],'size'=>filesize($actual),'sha256'=>hash_file('sha256',$actual),'lease'=>$lease]);$display[]=$name.': '.esc_url_raw(wp_get_attachment_url((int)$attachment));
        }
        return implode("\n", $display);
    }
    public static function cleanup(array $created): void {
        \LeenkatForms\Files\FileRecords::discard($created);
    }
    private static function error(string $reason,array $settings=[],int $status=400): \WP_Error {
        $messages=\LeenkatForms\Frontend\FieldErrors::messages();
        $message=match($reason){
            'required'=>$messages['required'],
            'count'=>sprintf($messages['file_count'],(int)($settings['max_files']??1)),
            'size'=>sprintf($messages['file_size'],self::sizeLabel($settings)),
            'type'=>sprintf($messages['file_type'],implode(', ',self::extensions($settings))),
            'mime'=>$messages['file_mime'],
            'storage'=>$messages['file_storage'],
            default=>$messages['file_failed'],
        };
        return new \WP_Error('lkf_upload',$message,['status'=>$status,'upload_reason'=>$reason]);
    }
}

