<?php
declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Forms\NotificationSettings;
use LeenkatForms\Workflow\NotificationMessage;

/** Fixed sample data, rendered by the same composer as delivered notifications. */
final class EmailTemplatePreview {
    public static function templates(?array $email=null,bool $forceDefault=false,string $mode='light'): array {
        $definition=['title'=>__('Contact Form','leenkat-forms'),'fields'=>[
            ['id'=>'name','type'=>'text','label'=>__('Name','leenkat-forms')],
            ['id'=>'email','type'=>'email','label'=>__('Email','leenkat-forms')],
            ['id'=>'message','type'=>'textarea','label'=>__('Message','leenkat-forms')],
        ]];
        $values=['name'=>__('Fajr Yassin', 'leenkat-forms'),'email'=>'fajr@example.test','message'=>__("Hello,\nI'd like to learn more about your services.\nThank you.",'leenkat-forms')];
        $item=['to'=>'preview@example.test','subject'=>__('New Entry: Contact Form','leenkat-forms'),'body'=>__("A new entry was submitted.\n\n",'leenkat-forms').'{all_fields}'];
        $policy='<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; img-src http: https:; base-uri &#39;none&#39;; form-action &#39;none&#39;">';
        $previews=[];
        foreach(NotificationSettings::TEMPLATES as $template){
            $context=['preview_mode'=>$mode];if($email!==null)$context['site_email']=$email;if($forceDefault)$context['force_template']=$template;
            $message=NotificationMessage::compose($item+['template'=>$template],$definition,$values,$context);
            $plain=str_contains($message['headers'][0],'text/plain');
            $document=$plain?'<html><head><meta charset="utf-8">'.$policy.'</head><body style="margin:0;padding:24px;background:#fff"><pre style="white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.7 monospace">'.esc_html($message['message']).'</pre></body></html>':str_replace('<head>','<head>'.$policy,$message['message']);
            $previews[$template]=['subject'=>$message['subject'],'document'=>$document];
        }
        return $previews;
    }
}
