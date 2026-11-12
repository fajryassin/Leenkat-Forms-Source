<?php
declare(strict_types=1);
namespace LeenkatForms\Files;

/** Content detection supplements WordPress's extension allowlist. Archives are never extracted. */
final class MimePolicy {
    public static function valid(string $path,string $extension): bool {
        if(!class_exists('finfo')||!is_file($path))return false;$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $types=[
            'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'gif'=>['image/gif'],'webp'=>['image/webp'],'pdf'=>['application/pdf'],
            'txt'=>['text/plain'],'csv'=>['text/plain','text/csv','application/csv'],
            'doc'=>['application/msword','application/CDFV2','application/x-ole-storage'],'xls'=>['application/vnd.ms-excel','application/CDFV2','application/x-ole-storage'],'ppt'=>['application/vnd.ms-powerpoint','application/CDFV2','application/x-ole-storage'],
            'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],'pptx'=>['application/zip','application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'zip'=>['application/zip','application/x-zip','application/x-zip-compressed'],'mp3'=>['audio/mpeg','audio/mp3'],'mp4'=>['video/mp4','audio/mp4'],'ogg'=>['audio/ogg','application/ogg','video/ogg'],'wav'=>['audio/x-wav','audio/wav','audio/vnd.wave']
        ];
        if(!in_array($mime,$types[$extension] ?? [],true))return false;
        if(in_array($extension,['jpg','jpeg','png','gif','webp'],true)){ $image=@getimagesize($path);if(!$image||($image['mime'] ?? '')!==($extension==='jpg'?'image/jpeg':'image/'.$extension))return false; }
        if(in_array($extension,['docx','xlsx','pptx'],true)){
            // WordPress's bundled ZIP reader is used only after bounding its central directory.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
            $size=filesize($path);$in=@fopen($path,'rb');if(!$in)return false;$length=min($size,65557);fseek($in,$size-$length);$tail=fread($in,$length);fclose($in);$offset=strrpos($tail,"PK\x05\x06");if($offset===false||strlen($tail)-$offset<22)return false;
            $end=unpack('vdisk/vstart/vdisk_entries/ventries/Vbytes/Voffset/vcomment',substr($tail,$offset+4,18));if($end['disk']!==0||$end['start']!==0||$end['entries']<1||$end['entries']>5000||$end['disk_entries']!==$end['entries']||$end['bytes']>4194304||$end['offset']+$end['bytes']>$size-$length+$offset||$offset+22+$end['comment']!==strlen($tail))return false;
            require_once ABSPATH.'wp-admin/includes/class-pclzip.php';$zip=new \PclZip($path);$list=$zip->listContent();if(!is_array($list)||count($list)!==$end['entries'])return false;$names=[];
            foreach($list as $file){$name=$file['filename'] ?? '';if(!is_string($name)||strlen($name)>1000||str_contains(str_replace('\\','/',$name),'../')||preg_match('~(?:^/|^[A-Za-z]:|(?:^|/)vbaProject\.bin$)~i',$name))return false;$names[]=$name;}
            if(!in_array('[Content_Types].xml',$names,true)||!in_array(['docx'=>'word/document.xml','xlsx'=>'xl/workbook.xml','pptx'=>'ppt/presentation.xml'][$extension],$names,true))return false;
        }
        return true;
    }
}
