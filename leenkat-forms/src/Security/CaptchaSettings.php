<?php
declare(strict_types=1);
namespace LeenkatForms\Security;

/** Site-wide credentials stay outside form definitions, exports and public configuration. */
final class CaptchaSettings {
    public const OPTION='lkf_captcha_settings';
    public const PROVIDERS=['hcaptcha'=>'hCaptcha','recaptcha'=>'reCAPTCHA','turnstile'=>'Cloudflare Turnstile'];
    public static function defaults(): array {
        $providers=[];
        foreach(self::PROVIDERS as $slug=>$label)$providers[$slug]=['site_key'=>'','secret'=>'','fail_message'=>sprintf(/* translators: %s: CAPTCHA provider name. */ __('%s verification failed, please try again later.','leenkat-forms'),$slug==='recaptcha'?__('Google reCAPTCHA', 'leenkat-forms'):$label),'type'=>$slug==='recaptcha'?'v2':'auto','threshold'=>0.4];
        return ['provider'=>'none','no_conflict'=>false,'providers'=>$providers];
    }
    public static function get(): array {
        $stored=get_option(self::OPTION,[]);return is_array($stored)?array_replace_recursive(self::defaults(),$stored):self::defaults();
    }
    public static function revision(): string {return hash('sha256',wp_json_encode(self::get()));}
    public static function ready(?array $settings=null): bool {
        $s=$settings ?? self::get();$p=$s['provider']??null;
        if(!is_string($p)||!isset(self::PROVIDERS[$p]))return false;$item=$s['providers'][$p]??null;
        if(!is_array($item)||!is_string($item['site_key']??null)||!preg_match('/^[A-Za-z0-9_-]{3,200}$/D',$item['site_key'])||!is_string($item['secret']??null))return false;
        if(!in_array($item['type']??'', $p==='recaptcha'?['v2','invisible','v3']:['auto','light','dark'],true))return false;
        return (bool)preg_match('/^[A-Za-z0-9_-]{3,1024}$/D',self::secret($item['secret']));
    }
    public static function builderState(): array {
        $s=self::get();$p=$s['provider'];$type=$s['providers'][$p]['type']??'';
        $label=$p==='recaptcha'?match($type){'v2'=>__('Google Checkbox v2 reCAPTCHA','leenkat-forms'),'invisible'=>__('Google Invisible v2 reCAPTCHA','leenkat-forms'),'v3'=>__('Google v3 reCAPTCHA','leenkat-forms'),default=>'reCAPTCHA'}:(self::PROVIDERS[$p]??'CAPTCHA');
        return ['provider'=>$p,'type'=>$type,'label'=>$label,'ready'=>self::ready($s)];
    }
    public static function publicConfig(int $formId): ?array {
        $s=self::get();$p=$s['provider'];if($p==='none')return null;$item=$s['providers'][$p] ?? [];
        return ['provider'=>$p,'siteKey'=>$item['site_key'] ?? '','type'=>$item['type'] ?? 'auto','action'=>self::action($formId),'message'=>$item['fail_message'] ?? __('CAPTCHA verification failed. Please try again.','leenkat-forms'),'completeMessage'=>__('Please complete the CAPTCHA verification.','leenkat-forms'),'ready'=>self::ready($s)];
    }
    public static function action(int $formId): string {return $formId>0?'lkf_form_'.$formId:'lkf_preview';}
    private static function seal(string $plain): string {
        if(!function_exists('openssl_encrypt'))throw new \RuntimeException(esc_html__('Credential encryption is unavailable.', 'leenkat-forms'));
        $iv=random_bytes(12);$tag='';$encrypted=openssl_encrypt($plain,'aes-256-gcm',hash('sha256',wp_salt('auth').'|lkf-captcha',true),OPENSSL_RAW_DATA,$iv,$tag,'lkf-captcha-v1');
        if($encrypted===false)throw new \RuntimeException(esc_html__('Credential encryption is unavailable.', 'leenkat-forms'));
        return 'v1:'.base64_encode($iv.$tag.$encrypted);
    }
    public static function secret(string $sealed): string {
        if(!str_starts_with($sealed,'v1:')||!function_exists('openssl_decrypt'))return '';$raw=base64_decode(substr($sealed,3),true);
        if($raw===false||strlen($raw)<29)return '';
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',wp_salt('auth').'|lkf-captcha',true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'lkf-captcha-v1');
        return is_string($plain)?$plain:'';
    }
    /** Blank secret fields retain existing ciphertext; removal is explicit. */
    public static function save(array $input): true|\WP_Error {
        if(!current_user_can('manage_options'))return new \WP_Error('lkf_forbidden',__('Access denied.','leenkat-forms'),['status'=>403]);
        if(!add_option('lkf_captcha_settings_lock',(string)(time()+30),'',false)){
            global $wpdb;$expires=(int)get_option('lkf_captcha_settings_lock',0);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
            if($expires>0&&$expires<time()){$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",'lkf_captcha_settings_lock',(string)$expires));wp_cache_delete('lkf_captcha_settings_lock','options');}
            return new \WP_Error('lkf_settings_busy',__('Settings are being saved. Please try again.','leenkat-forms'),['status'=>409]);
        }
        try {
            if(!is_string($input['revision'] ?? null)||!hash_equals(self::revision(),$input['revision']))return new \WP_Error('lkf_settings_changed',__('Settings changed in another tab. Reload this page before saving again.','leenkat-forms'),['status'=>409]);
            $next=self::get();$provider=$input['provider'] ?? null;
            if(!is_string($provider)||!in_array($provider,['none',...array_keys(self::PROVIDERS)],true))return self::invalid();
            if(!is_bool($input['no_conflict'] ?? null)||!is_array($input['providers'] ?? null))return self::invalid();
            $next['provider']=$provider;$next['no_conflict']=$input['no_conflict'];
            foreach(self::PROVIDERS as $slug=>$label){
                $item=$input['providers'][$slug] ?? null;if(!is_array($item))return self::invalid();
                foreach(['site_key','secret_key','fail_message','type'] as $key)if(!is_string($item[$key] ?? null))return self::invalid();
                $site=trim($item['site_key']);$secret=trim($item['secret_key']);$message=trim($item['fail_message']);
                if($site!==''&&!preg_match('/^[A-Za-z0-9_-]{3,200}$/D',$site))return self::invalid();
                if($secret!==''&&!preg_match('/^[A-Za-z0-9_-]{3,1024}$/D',$secret))return self::invalid();
                if($message===''||strlen($message)>1000||wp_strip_all_tags($message)!==$message)return self::invalid();
                if(!in_array($item['type'],$slug==='recaptcha'?['v2','invisible','v3']:['auto','light','dark'],true))return self::invalid();
                $score=$item['threshold'] ?? 0.4;if(!is_numeric($score)||!is_finite((float)$score)||(float)$score<0||(float)$score>1)return self::invalid();
                if(isset($item['remove_secret'])&&!is_bool($item['remove_secret']))return self::invalid();
                $next['providers'][$slug]=array_replace($next['providers'][$slug],['site_key'=>$site,'fail_message'=>$message,'type'=>$item['type'],'threshold'=>(float)$score]);
                if(!empty($item['remove_secret']))$next['providers'][$slug]['secret']='';
                if($secret!=='')$next['providers'][$slug]['secret']=self::seal($secret);
            }
            if($next!==self::get()&&!update_option(self::OPTION,$next,false))return new \WP_Error('lkf_settings_save',__('Settings could not be saved. Please try again.','leenkat-forms'),['status'=>500]);
            return true;
        } catch(\Throwable $error){return new \WP_Error('lkf_settings_save',__('Secure settings storage is unavailable. Please try again.','leenkat-forms'),['status'=>503]);}
        finally {delete_option('lkf_captcha_settings_lock');}
    }
    private static function invalid(): \WP_Error {return new \WP_Error('lkf_settings_invalid',__('Check the CAPTCHA keys, message, type and score threshold.','leenkat-forms'),['status'=>400]);}
}
