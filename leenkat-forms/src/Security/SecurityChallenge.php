<?php
declare(strict_types=1);
namespace LeenkatForms\Security;

use LeenkatForms\Forms\Publisher;
use LeenkatForms\Forms\SecuritySettings;

final class SecurityChallenge {
    public const LIFETIME=86400;
    public static function hooks(): void {
        add_action('rest_api_init',static function():void{register_rest_route('leenkat-forms/v1','/forms/(?P<id>\d+)/security-token',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>[self::class,'endpoint']]);});
    }
    public static function issue(int $formId,int $versionId,bool $browser=false,?int $now=null): array {
        $data=['f'=>$formId,'v'=>$versionId,'t'=>$now ?? time(),'n'=>bin2hex(random_bytes(16)),'h'=>'contact_'.bin2hex(random_bytes(6)),'b'=>$browser];
        $encoded=rtrim(strtr(base64_encode(wp_json_encode($data)),'+/','-_'),'=');
        return ['token'=>$encoded.'.'.hash_hmac('sha256','lkf-security|'.$encoded,wp_salt('auth')),'honeypot'=>$data['h']];
    }
    public static function verify(mixed $token,int $formId,int $versionId,?int $now=null): ?array {
        if(!is_string($token)||strlen($token)>600||!preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$token,$match)||!hash_equals(hash_hmac('sha256','lkf-security|'.$match[1],wp_salt('auth')),$match[2]))return null;
        $json=base64_decode(strtr($match[1],'-_','+/'),true);$data=is_string($json)?json_decode($json,true):null;$now??=time();
        if(!is_array($data)||($data['f'] ?? null)!==$formId||($data['v'] ?? null)!==$versionId||!is_int($data['t'] ?? null)||$data['t']>$now||$now-$data['t']>self::LIFETIME||!is_bool($data['b'] ?? null)||!is_string($data['h'] ?? null)||!preg_match('/^contact_[a-f0-9]{12}$/D',$data['h'])||!is_string($data['n'] ?? null)||!preg_match('/^[a-f0-9]{32}$/D',$data['n']))return null;
        return $data;
    }
    public static function check(array $settings,array $data,int $formId,int $versionId,?int $now=null): array|\WP_Error {
        if(!$settings['modern']&&!$settings['min_time'])return ['trap'=>false];
        $proof=self::verify($data['security_token'] ?? null,$formId,$versionId,$now);
        if(!$proof||$settings['modern']&&!$proof['b'])return new \WP_Error('lkf_security',__('Security verification failed. Reload the form and try again.','leenkat-forms'),['status'=>400]);
        if($settings['min_time']&&($now ?? time())-$proof['t']<$settings['seconds'])return new \WP_Error('lkf_too_fast',__('Please wait a little longer before submitting. We are running a quick security check.','leenkat-forms'),['status'=>400]);
        $traps=$data['security_traps'] ?? [];
        if(!is_array($traps)||count($traps)!==1||!array_key_exists($proof['h'],$traps)||!is_string($traps[$proof['h']])||strlen($traps[$proof['h']])>1000)return new \WP_Error('lkf_security',__('Security verification failed. Reload the form and try again.','leenkat-forms'),['status'=>400]);
        return ['trap'=>$settings['modern']&&$traps[$proof['h']]!==''];
    }
    public static function endpoint(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $id=absint($request['id']);$published=$id?Publisher::published($id):null;
        if(!$published)return new \WP_Error('lkf_unavailable',__('Form is unavailable.','leenkat-forms'),['status'=>404]);
        if(!RateLimiter::allow(-$id,CountryLookup::clientIp($_SERVER),40))return new \WP_Error('lkf_rate_limit',__('Please wait before trying again.','leenkat-forms'),['status'=>429]);
        $settings=SecuritySettings::normalize($published['definition']);
        $response=new \WP_REST_Response(self::issue($id,$published['version_id'],true)+['minimum'=>$settings['min_time']?$settings['seconds']:0]);$response->header('Cache-Control','no-store, private, max-age=0');$response->header('X-Content-Type-Options','nosniff');return $response;
    }
}
