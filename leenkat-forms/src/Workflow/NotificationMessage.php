<?php
declare(strict_types=1);
namespace LeenkatForms\Workflow;

/** One nonrecursive Smart Tag renderer shared by queued and no-entry deliveries. */
final class NotificationMessage {
    public static function value(mixed $value): string { return is_array($value)?implode(', ',array_map([self::class,'value'],$value)):($value===true?'Yes':($value===false?'No':(string)$value)); }
    public static function render(string $template,array $definition,array $values,array $context=[],bool $html=false): string {
        $parts=[];
        foreach($definition['fields'] ?? [] as $field){
            if(isset($field['display_id']))$values[$field['display_id']]=$values[$field['id']] ?? '';
            if(($field['type'] ?? '')==='name')foreach(\LeenkatForms\Forms\NameField::parts($field['settings'] ?? []) as $part){
                $parts[$field['id']][$part]=$context['field_parts'][$field['id']][$part] ?? '';
                if(isset($field['display_id']))$parts[$field['display_id']][$part]=$parts[$field['id']][$part];
            }
        }
        return preg_replace_callback('/\{(all_fields|admin_email|user_email|site_name|site_url|form_name|form_id|entry_id|entry_date|field:[A-Za-z][A-Za-z0-9_-]{0,63}(?::(?:first|middle|last))?|field_id="[A-Za-z][A-Za-z0-9_-]{0,63}")\}/',static function($m)use($definition,$values,$parts,$context,$html):string {
            $tag=$m[1];
            if($tag==='all_fields')return self::allFields($definition,$values,$html,$context);
            $fileKey=str_starts_with($tag,'field:')?substr($tag,6):(str_starts_with($tag,'field_id=')?substr($tag,10,-1):'');
            if(isset($context['email_file_links'][$fileKey]))return self::fileValue($context['email_file_links'][$fileKey],$html);
            if(str_starts_with($tag,'field:')){ $field=explode(':',substr($tag,6));$value=self::value(isset($field[1])?($parts[$field[0]][$field[1]] ?? ''):($values[$field[0]] ?? '')); }
            elseif(str_starts_with($tag,'field_id='))$value=self::value($values[substr($tag,10,-1)] ?? '');
            else $value=match($tag){
                'admin_email'=>(string)get_option('admin_email',''),
                'user_email'=>(string)($context['user_email'] ?? ''),
                'site_name'=>(string)get_bloginfo('name'),
                'site_url'=>home_url('/'),
                'form_name'=>(string)($definition['title'] ?? ''),
                'form_id'=>(string)($context['form_id'] ?? ''),
                'entry_id'=>(string)($context['entry_id'] ?? ''),
                'entry_date'=>(string)($context['entry_date'] ?? ''),
                default=>'',
            };
            $value=sanitize_textarea_field($value);return $html?esc_html($value):$value;
        },$template);
    }
    private static function fileValue(array $files,bool $html): string {
        return implode($html?'<br>':"\n",array_map(static fn($file)=>$html?'<a href="'.esc_url($file['url']).'">'.esc_html($file['name']).'</a>':$file['name'].': '.$file['url'],$files));
    }
    private static function allFields(array $definition,array $values,bool $html,array $context=[]): string {
        $rows=[];
        foreach($definition['fields'] ?? [] as $field){
            if(in_array($field['type'],['captcha','section_divider','page_break'],true) || !array_key_exists($field['id'],$values) || $values[$field['id']]===null)continue;
            $label=$field['label'];$value=self::value($values[$field['id']]);
            $rendered=isset($context['email_file_links'][$field['id']])?self::fileValue($context['email_file_links'][$field['id']],$html):($html?esc_html($value):$value);
            $rows[]=$html?'<tr><th style="padding:12px;text-align:left;vertical-align:top;border-bottom:1px solid #ddd;width:35%">'.esc_html($label).'</th><td style="padding:12px;border-bottom:1px solid #ddd;white-space:pre-wrap">'.$rendered.'</td></tr>':$label.': '.$rendered;
        }
        return $html?'<table role="presentation" style="border-collapse:collapse;width:100%">'.implode('',$rows).'</table>':implode("\n",$rows);
    }
    public static function addresses(string $template,array $definition,array $values,array $context=[],bool $allowName=false): array {
        $raw=self::render($template,$definition,$values,$context);
        if(preg_match('/[\r\n\x00]/',$raw))return [];
        $result=[];
        foreach(explode(',',$raw) as $value){$value=trim($value);if($value==='')continue;$address=$value;
            if($allowName && preg_match('/^([^<>]+)<([^<>]+)>$/D',$value,$match)){$address=trim($match[2]);$value=trim(preg_replace('/[<>\r\n]/','',wp_strip_all_tags($match[1]))).' <'.$address.'>';}
            if(!is_email($address))return []; $result[$value]=$value;
        }
        return count($result)<=10?array_values($result):[];
    }
    public static function compose(array $item,array $definition,array $values,array $context=[]): ?array {
        $to=self::addresses($item['to'] ?? '',$definition,$values,$context);
        if(!$to)return null; // Optional respondent field or logged-out user tag is empty.
        $subject=trim(preg_replace('/[\r\n\x00]+/',' ',wp_strip_all_tags(self::render($item['subject'] ?? '',$definition,$values,$context))));
        if($subject==='')return null;
        $email=$context['site_email'] ?? \LeenkatForms\Settings\SiteSettings::email();
        $selected=$item['template'] ?? 'default';$template=$context['force_template'] ?? ($selected==='default'?$email['template']:$selected);
        $plain=$template==='plain';
        $headers=['Content-Type: '.($plain?'text/plain':'text/html').'; charset=UTF-8'];
        if(!empty($item['from_email']) || !empty($item['from_name'])){
            $from=self::addresses(($item['from_email'] ?? '') ?: '{admin_email}',$definition,$values,$context);
            if(count($from)!==1)return null;
            $name=trim(preg_replace('/[<>\r\n\x00]+/',' ',wp_strip_all_tags(self::render($item['from_name'] ?? '',$definition,$values,$context))));
            $headers[]='From: '.($name!==''?$name.' <'.$from[0].'>':$from[0]);
        }
        if(!empty($item['reply_to'])){$reply=self::addresses($item['reply_to'],$definition,$values,$context,true);if($reply)$headers[]='Reply-To: '.implode(', ',$reply);}
        if(!empty($email['carbon_copy'])&&!empty($item['cc'])){
            $ccRaw=trim(self::render($item['cc'],$definition,$values,$context));$cc=self::addresses($item['cc'],$definition,$values,$context);
            if($ccRaw!==''&&!$cc)return null;$toLower=array_map('strtolower',$to);$cc=array_values(array_filter($cc,static fn($address)=>!in_array(strtolower($address),$toLower,true)));
            if($cc)$headers[]='Cc: '.implode(', ',$cc);
        }
        $source=$item['body'] ?? '{all_fields}';
        // Download credentials belong only to the email body, never sender/recipient headers,
        // subjects, stored values, previews or confirmation rendering.
        $bodyContext=$context;$bodyContext['email_file_links']=[];
        foreach($definition['fields']??[] as $field){
            if(($field['type']??'')!=='file_upload'||!isset($values[$field['id']]))continue;
            $links=[];foreach($context['uploads']??[] as $file){
                if(($file['field_id']??'')!==$field['id'])continue;
                $url=\LeenkatForms\Files\EmailDownload::url((int)($context['entry_id']??0),$file);
                if($url)$links[]=['name'=>sanitize_file_name((string)($file['name']??'attachment')),'url'=>$url];
            }
            if($links){$bodyContext['email_file_links'][$field['id']]=$links;if(isset($field['display_id']))$bodyContext['email_file_links'][$field['display_id']]=$links;}
        }
        $body=$plain?self::render(wp_strip_all_tags($source),$definition,$values,$bodyContext):self::render(wp_kses_post($source),$definition,$values,$bodyContext,true);
        if(!$plain)$body=EmailLayout::render($body,$template,$definition['title'] ?? '',$email,$context['preview_mode'] ?? 'auto');
        return ['to'=>$to,'subject'=>$subject,'message'=>$body,'headers'=>$headers];
    }
}
