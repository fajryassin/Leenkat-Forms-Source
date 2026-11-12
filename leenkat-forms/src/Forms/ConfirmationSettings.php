<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

use LeenkatForms\Workflow\NotificationMessage;

final class ConfirmationSettings {
    public static function defaults(): array { return ['id'=>'c_default','name'=>__('Default Confirmation', 'leenkat-forms'),'type'=>'message','message'=>__('Thank you. Your response was submitted.','leenkat-forms'),'message_scroll'=>true,'entry_preview'=>false,'page_id'=>0,'redirect'=>'']; }
    public static function normalize(array $definition): array {
        if(isset($definition['settings']['confirmations']))return $definition['settings']['confirmations'];
        $item=self::defaults();
        $legacy=$definition['settings']['confirmation']['message'] ?? '';
        if(is_string($legacy) && $legacy!=='')$item['message']=esc_html(wp_strip_all_tags($legacy));
        return ['items'=>[$item]];
    }
    public static function validUrl(string $url): bool {
        $parts=wp_parse_url($url);
        return !preg_match('/[\r\n\x00]|%0[ad]/i',$url) && filter_var($url,FILTER_VALIDATE_URL)!==false && is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''),['http','https'],true) && !isset($parts['user']) && !isset($parts['pass']);
    }
    public static function page(int $id): ?string {
        $post=$id>0?get_post($id):null;
        if(!$post || $post->post_type!=='page' || $post->post_status!=='publish' || !empty($post->post_password))return null;
        $url=get_permalink($id);return is_string($url) && self::validUrl($url)?$url:null;
    }
    public static function validate(mixed $settings,array $fields,bool $publication,bool $resolvePages=true): array {
        if(!is_array($settings) || !is_array($settings['items'] ?? null) || !array_is_list($settings['items']) || !$settings['items'] || count($settings['items'])>20)return [__('Add between one and twenty confirmations.', 'leenkat-forms')];
        $errors=[];$ids=[];
        foreach($settings['items'] as $index=>$item){
            if(!is_array($item)){$errors[]=__('Invalid confirmation.', 'leenkat-forms');continue;}
            $id=$item['id'] ?? null;
            if(!is_string($id) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D',$id) || isset($ids[$id]))$errors[]=__('Invalid or duplicate confirmation identifier.', 'leenkat-forms');
            if(is_string($id))$ids[$id]=true;
            foreach(['name'=>100,'message'=>20000,'redirect'=>2048] as $key=>$limit)if(isset($item[$key]) && (!is_string($item[$key]) || strlen($item[$key])>$limit))$errors[]=__('Invalid confirmation ', 'leenkat-forms').$key.'.';
            if(!is_string($item['name'] ?? null) || trim($item['name'])==='')$errors[]=__('Name every confirmation.', 'leenkat-forms');
            if(!in_array($item['type'] ?? '',['message','page','redirect'],true))$errors[]=__('Invalid confirmation type.', 'leenkat-forms');
            foreach(['message_scroll','entry_preview'] as $flag)if(isset($item[$flag]) && !is_bool($item[$flag]))$errors[]=__('Invalid confirmation flag.', 'leenkat-forms');
            if(isset($item['page_id']) && (!is_int($item['page_id']) || $item['page_id']<0))$errors[]=__('Invalid confirmation page.', 'leenkat-forms');
            if($publication){
                if(($item['type'] ?? '')==='message' && (!is_string($item['message'] ?? null) || trim(wp_strip_all_tags($item['message']))===''))$errors[]=__('Enter a confirmation message.', 'leenkat-forms');
                if(($item['type'] ?? '')==='page' && (!is_int($item['page_id'] ?? null) || $item['page_id']<=0 || $resolvePages && !self::page($item['page_id'])))$errors[]=__('Select a published public confirmation page.', 'leenkat-forms');
                if(($item['type'] ?? '')==='redirect' && (!is_string($item['redirect'] ?? null) || !self::validUrl($item['redirect'])))$errors[]=__('Enter a complete http:// or https:// confirmation URL.', 'leenkat-forms');
            }
            if(isset($item['condition'])){
                // Use the same validated AND/OR grammar as notification delivery.
                $conditionErrors=NotificationSettings::validate(['enabled'=>true,'items'=>[['id'=>'c_rule','name'=>'Confirmation','to'=>'sample@example.test','subject'=>'Sample','body'=>'Sample','condition'=>$item['condition']]]],$fields,$publication);
                foreach($conditionErrors as $error)$errors[]=str_ireplace('notification','confirmation',$error);
            }
        }
        return array_values(array_unique($errors));
    }
    public static function response(array $definition,array $values=[],array $context=[]): array {
        $items=self::normalize($definition)['items'];$chosen=null;
        $comparisonValues=array_replace($values,$context['field_choices'] ?? []);
        // Additional confirmations retain newest-first priority. The default's
        // optional condition is evaluated only after none of those apply.
        for($i=count($items)-1;$i>0;$i--)if(NotificationSettings::shouldSend(['condition'=>$items[$i]['condition'] ?? null],$comparisonValues)){$chosen=$items[$i];break;}
        if($chosen===null && NotificationSettings::shouldSend(['condition'=>$items[0]['condition'] ?? null],$comparisonValues))$chosen=$items[0];
        // A saved submission remains successful when all confirmations are gated
        // out. No configured Smart Tags, redirect or entry preview is evaluated.
        $chosen??=self::defaults();
        $type=$chosen['type'] ?? 'message';
        if($type==='page' || $type==='redirect'){
            $url=$type==='page'?self::page((int)($chosen['page_id'] ?? 0)):($chosen['redirect'] ?? '');
            if(is_string($url) && self::validUrl($url))return ['message'=>__('Redirecting…','leenkat-forms'),'confirmation'=>['type'=>'redirect','url'=>$url],'unstored'=>!empty($definition['settings']['general']['disable_entries'])];
            $chosen=self::defaults(); // Page was removed after publication: acknowledge the saved submission.
        }
        $html=wp_kses_post(NotificationMessage::render(wp_kses_post($chosen['message'] ?? ''),$definition,$values,$context,true));
        if(trim(wp_strip_all_tags($html))==='')$html=esc_html(self::defaults()['message']);
        $preview=!empty($chosen['entry_preview'])?wp_kses_post(NotificationMessage::render('{all_fields}',$definition,$values,$context,true)):'';
        return ['message'=>wp_strip_all_tags($html),'confirmation'=>['type'=>'message','html'=>$html,'scroll'=>($chosen['message_scroll'] ?? true)!==false,'entry_preview'=>!empty($chosen['entry_preview']),'preview_html'=>$preview],'unstored'=>!empty($definition['settings']['general']['disable_entries'])];
    }
    public static function markup(array $confirmation): string {
        $html=wp_kses_post($confirmation['html'] ?? '');
        if(!empty($confirmation['preview_html']))$html.='<section class="lkf-entry-preview"><h3>'.esc_html__('Your submission','leenkat-forms').'</h3>'.wp_kses_post($confirmation['preview_html']).'</section>';
        return '<div class="lkf-confirmation" role="status" aria-live="polite" tabindex="-1" data-lkf-confirmation data-scroll="'.(!empty($confirmation['scroll'])?'1':'0').'">'.$html.'</div>';
    }
}
