<?php
declare(strict_types=1);
namespace LeenkatForms\Workflow;

/** Attach only verified paths from this entry's upload records; CSV lives in a private temp file. */
final class MailAttachments {
    public const MAX_BYTES=20*1024*1024;
    public static function prepare(array $item,array $definition,array $values,array $context,array &$temporary): array|false {
        $attachments=[];$bytes=0;
        if(!empty($item['file_attachments']) && !empty($context['uploads'])){
            $entryId=(int)($context['entry_id'] ?? 0);$files=$entryId>0?\LeenkatForms\Files\FileRecords::notificationFiles($entryId):($context['uploads'] ?? []);
            $known=[];foreach($definition['fields'] ?? [] as $field)if(($field['type'] ?? '')==='file_upload')$known[]=$field['id'];
            foreach($files as $record){
                if(!in_array($record['field_id'] ?? '',$item['file_fields'] ?? [],true))continue;
                if(!in_array($record['field_id'],$known,true))return false;
                $real=\LeenkatForms\Files\FileRecords::mailPath($record,$entryId);if(!$real)return false;
                $temporary[]=$real;
                $bytes+=(int)filesize($real);if($bytes>self::MAX_BYTES)return false;
                $name=sanitize_file_name($record['name'] ?? basename($real));$stem=pathinfo($name,PATHINFO_FILENAME);$ext=pathinfo($name,PATHINFO_EXTENSION);$n=1;
                while(isset($attachments[$name]))$name=$stem.'-'.(++$n).($ext!==''?'.'.$ext:'');
                $attachments[$name]=$real;
            }
        }
        if(!empty($item['csv_attachment'])){
            $csv=self::csv($item,$definition,$values,$context);if(strlen($csv)+$bytes>self::MAX_BYTES)return false;
            $name=sanitize_file_name($item['csv_filename'] ?? 'entry-details');$name=preg_replace('/\.csv$/i','',$name) ?: 'entry-details';$name.='.csv';
            $stem=pathinfo($name,PATHINFO_FILENAME);$n=1;while(isset($attachments[$name]))$name=$stem.'-'.(++$n).'.csv';
            try{$path=\LeenkatForms\Files\PrivateStorage::temporary($csv);}catch(\Throwable){return false;}$temporary[]=$path;$attachments[$name]=$path;
        }
        return $attachments;
    }
    public static function csv(array $item,array $definition,array $values,array $context): string {
        $selection=$item['csv_fields'] ?? ['all_fields'];$labels=[];$cells=[];
        foreach($definition['fields'] ?? [] as $field){
            if(in_array($field['type'],['captcha','section_divider','page_break'],true) || ($values[$field['id']] ?? null)===null || !in_array('all_fields',$selection,true) && !in_array($field['id'],$selection,true))continue;
            $labels[]=$field['label'];$cells[]=NotificationMessage::value($values[$field['id']]);
        }
        foreach(['entry_id'=>'Entry ID','form_id'=>'Form ID','entry_date'=>'Entry Date'] as $key=>$label)if(in_array($key,$selection,true)){$labels[]=$label;$cells[]=(string)($context[$key] ?? '');}
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Native php://temp stream creates the CSV before moving it into verified private storage.
        $stream=fopen('php://temp','w+');fwrite($stream,"\xEF\xBB\xBF");
        $safe=static fn($value)=>preg_match('/^[\x00-\x20]*[=+@-]|^[\t\r\n]/u',(string)$value)?"'".$value:$value;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native php://temp stream creates the CSV before moving it into verified private storage.
        fputcsv($stream,array_map($safe,$labels),',','"','');fputcsv($stream,array_map($safe,$cells),',','"','');rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return $csv;
    }
    public static function cleanup(array $paths): void {foreach($paths as $path)\LeenkatForms\Files\PrivateStorage::close((string)$path);}
}
