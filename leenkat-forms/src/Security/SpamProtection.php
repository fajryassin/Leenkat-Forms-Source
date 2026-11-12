<?php
declare(strict_types=1);
namespace LeenkatForms\Security;

final class SpamProtection {
    /** A reason means spam; a WP_Error means the submission must be rejected without storing. */
    public static function reason(array $definition,array $values,?array &$matchedKeywords=null): ?string {
        $s=\LeenkatForms\Forms\SecuritySettings::normalize($definition);
        $matchedKeywords=$s['keyword_enabled']?SpamKeywords::matches($definition,$values):[];
        if($matchedKeywords)return 'Keyword Filter';
        return null;
    }
    public static function countryCheck(array $settings,array $server): bool|\WP_Error {
        if(!$settings['country_enabled'])return true;$ip=CountryLookup::clientIp($server);if(in_array($ip,['127.0.0.1','::1'],true))return true;
        $country=CountryLookup::country($ip);if($country===null)return new \WP_Error('lkf_country_unavailable',__('We could not verify your country. Please try again later.','leenkat-forms'),['status'=>503]);
        $selected=in_array($country,$settings['country_codes'],true);return $settings['country_action']==='allow'?$selected:!$selected;
    }
    public static function error(array $settings,string $reason,array $matchedKeywords=[]): \WP_Error {
        $message=match($reason){'Country Filter'=>$settings['country_message'],'Keyword Filter'=>$settings['keyword_message'],default=>__('Anti-spam verification failed. Please try again later.','leenkat-forms')};
        if($reason==='Keyword Filter'){
            $words=[];foreach(array_slice($matchedKeywords,0,10) as $word)if(is_string($word)){ $word=sanitize_text_field($word);if($word!=='')$words[]='“'.$word.'”'; }
            if($words)$message.=' '.sprintf(/* translators: %s: matched prohibited words or phrases. */ __('Prohibited words or phrases: %s.','leenkat-forms'),implode(', ',$words));
        }
        return new \WP_Error('lkf_spam',wp_strip_all_tags($message),['status'=>400]);
    }
}
