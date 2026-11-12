<?php
declare(strict_types=1);
namespace LeenkatForms\Security;

final class CaptchaService {
    private static bool $used=false;
    // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Optional configured CAPTCHA provider endpoint/script; required for provider verification, disclosed in readme, and loaded only when explicitly enabled.
    private const VERIFY=['recaptcha'=>'https://www.google.com/recaptcha/api/siteverify','hcaptcha'=>'https://api.hcaptcha.com/siteverify','turnstile'=>'https://challenges.cloudflare.com/turnstile/v0/siteverify'];
    public static function hooks(): void {
        add_action('wp_enqueue_scripts',[self::class,'primeNoConflict'],9999);
        add_action('wp_print_footer_scripts',[self::class,'noConflict'],1);
        add_action('admin_print_footer_scripts',[self::class,'noConflict'],1);
        add_filter('script_loader_tag',[self::class,'scriptTag'],10,3);
        add_action('rest_api_init',static function():void{
            register_rest_route('leenkat-forms/v1','/admin/captcha-state',['methods'=>'GET','permission_callback'=>static fn()=>current_user_can('lkf_manage_forms'),'callback'=>static function():\WP_REST_Response{$response=new \WP_REST_Response(CaptchaSettings::builderState());$response->header('Cache-Control','private, no-store');return $response;}]);
            register_rest_route('leenkat-forms/v1','/admin/captcha-preview',['methods'=>'POST','permission_callback'=>static fn()=>current_user_can('manage_options'),'callback'=>[self::class,'preview']]);
        });
    }
    public static function assets(): void {
        self::$used=true;wp_enqueue_script('lkf-captcha',plugins_url('assets/frontend/captcha.js',LEENKAT_FORMS_FILE),[],LEENKAT_FORMS_VERSION.'.'.filemtime(LEENKAT_FORMS_DIR.'assets/frontend/captcha.js'),true);
    }
    public static function markup(int $formId): string {
        $config=CaptchaSettings::publicConfig($formId)??['provider'=>'none','ready'=>false,'message'=>__('CAPTCHA is not configured. Please contact the site administrator.','leenkat-forms')];
        self::assets();
        return '<div class="lkf-service-captcha" data-lkf-captcha="'.esc_attr(wp_json_encode($config)).'"><div data-lkf-captcha-widget></div><input type="hidden" name="service_captcha_token" value=""><p data-lkf-captcha-status role="status" aria-live="polite"></p><noscript>'.esc_html__('Please enable JavaScript to complete CAPTCHA verification.','leenkat-forms').'</noscript></div>';
    }
    /** Only opt-in pages containing our widget are affected; registration is preserved. */
    public static function primeNoConflict(): void {
        if(empty(CaptchaSettings::get()['no_conflict'])||!CaptchaSettings::ready())return;global $post;
        if(!$post instanceof \WP_Post||!has_shortcode($post->post_content,'leenkat_form'))return;
        if(preg_match_all('/'.get_shortcode_regex(['leenkat_form']).'/s',$post->post_content,$matches,PREG_SET_ORDER))foreach($matches as $match){
            // Escaped [[shortcodes]] do not render a form.
            if($match[1]==='['&&$match[6]===']')continue;$atts=shortcode_parse_atts($match[3]);$id=is_array($atts)?absint($atts['id'] ?? 0):0;
            $form=$id?\LeenkatForms\Forms\Publisher::published($id):null;
            if($form&&!empty($form['definition']['settings']['security']['captcha_enabled'])){self::$used=true;break;}
        }
        self::noConflict();
    }
    public static function noConflict(): void {
        if(!self::$used||empty(CaptchaSettings::get()['no_conflict']))return;$scripts=wp_scripts();
        foreach($scripts->queue as $handle){
            if(str_starts_with($handle,'lkf-'))continue;$src=$scripts->registered[$handle]->src ?? '';
            if(self::isProviderScript($src))wp_dequeue_script($handle);
        }
    }
    public static function scriptTag(string $tag,string $handle,string $src): string {
        return self::$used&&!empty(CaptchaSettings::get()['no_conflict'])&&!str_starts_with($handle,'lkf-')&&self::isProviderScript($src)?'':$tag;
    }
    private static function isProviderScript(string $src): bool {
        $host=strtolower((string)wp_parse_url($src,PHP_URL_HOST));$path=(string)wp_parse_url($src,PHP_URL_PATH);
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Optional configured CAPTCHA provider endpoint/script; required for provider verification, disclosed in readme, and loaded only when explicitly enabled.
        return (in_array($host,['www.google.com','www.recaptcha.net','recaptcha.net'],true)&&$path==='/recaptcha/api.js')||(in_array($host,['js.hcaptcha.com','hcaptcha.com'],true)&&$path==='/1/api.js')||($host==='challenges.cloudflare.com'&&$path==='/turnstile/v0/api.js');
    }
    public static function verifyForm(array $definition,int $formId,int $version,string $uuid,string $requestHash,mixed $token): true|\WP_Error {
        if(empty($definition['settings']['security']['captcha_enabled']))return true;
        return self::verify($token,CaptchaSettings::action($formId),$formId.'|'.$version.'|'.$uuid.'|'.$requestHash);
    }
    public static function preview(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        if(!current_user_can('manage_options'))return new \WP_Error('lkf_forbidden',__('Access denied.','leenkat-forms'),['status'=>403]);
        if(!RateLimiter::allow(-900000-get_current_user_id(),CountryLookup::clientIp($_SERVER),20))return new \WP_Error('lkf_rate_limit',__('Please wait before testing again.','leenkat-forms'),['status'=>429]);
        $data=$request->get_json_params();$result=self::verify(is_array($data)?($data['token'] ?? null):null,CaptchaSettings::action(0),'preview|'.get_current_user_id().'|'.wp_generate_uuid4());
        if(is_wp_error($result))return $result;$response=new \WP_REST_Response(['verified'=>true,'message'=>__('CAPTCHA verification succeeded.','leenkat-forms')]);$response->header('Cache-Control','private, no-store');return $response;
    }
    /** Cache only a keyed token digest bound to this exact request, for safe transport retries. */
    public static function verify(mixed $token,string $action,string $scope): true|\WP_Error {
        $s=CaptchaSettings::get();$p=$s['provider'];$item=$s['providers'][$p] ?? [];
        if(!CaptchaSettings::ready($s))return new \WP_Error('lkf_captcha_unavailable',__('CAPTCHA is not configured. Please contact the site administrator.','leenkat-forms'),['status'=>503]);
        $fail=static fn()=>new \WP_Error('lkf_captcha_failed',$item['fail_message'],['status'=>400]);
        if(!is_string($token)||$token===''||strlen($token)>8192||($p==='turnstile'&&strlen($token)>2048)||preg_match('/[\x00-\x20\x7f]/',$token))return $fail();
        $key='lkf_captcha_pass_'.hash_hmac('sha256',$p.'|'.$item['site_key'].'|'.$token,wp_salt('auth'));
        $binding=hash_hmac('sha256',CaptchaSettings::revision().'|'.$action.'|'.$scope,wp_salt('auth'));
        $cached=get_transient($key);if(is_string($cached))return hash_equals($cached,$binding)?true:$fail();
        $lock=$key.'_lock';if(!add_option($lock,(string)(time()+30),'',false)){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
            global $wpdb;$expires=(int)get_option($lock,0);if($expires>0&&$expires<time()){$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$lock,(string)$expires));wp_cache_delete($lock,'options');}
            return new \WP_Error('lkf_captcha_busy',__('Verification is in progress. Please try again.','leenkat-forms'),['status'=>409]);
        }
        try {
            $cached=get_transient($key);if(is_string($cached))return hash_equals($cached,$binding)?true:$fail();
            $body=['secret'=>CaptchaSettings::secret($item['secret']),'response'=>$token];if($p==='hcaptcha')$body['sitekey']=$item['site_key'];
            $response=wp_remote_post(self::VERIFY[$p],['timeout'=>10,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>16384,'body'=>$body]);
            if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return new \WP_Error('lkf_captcha_unavailable',$item['fail_message'],['status'=>503]);
            $result=json_decode(wp_remote_retrieve_body($response),true);
            if(!is_array($result)||($result['success'] ?? null)!==true)return $fail();
            // hCaptcha authenticates the requested sitekey; its hostname is informational only.
            if($p!=='hcaptcha'){
                $expected=strtolower(rtrim((string)wp_parse_url(home_url('/'),PHP_URL_HOST),'.'));
                $host=$result['hostname'] ?? null;if(!is_string($host)||strtolower(rtrim($host,'.'))!==$expected)return $fail();
            }
            if($p==='turnstile'||($p==='recaptcha'&&$item['type']==='v3'))if(($result['action'] ?? null)!==$action)return $fail();
            if($p==='recaptcha'&&$item['type']==='v3'){
                $score=$result['score'] ?? null;if((!is_int($score)&&!is_float($score))||!is_finite((float)$score)||$score<0||$score>1||$score<=$item['threshold'])return $fail();
            }
            if(isset($result['challenge_ts'])){$timestamp=is_string($result['challenge_ts'])?strtotime($result['challenge_ts']):false;if($timestamp===false||$timestamp>time()+60||$timestamp<time()-360)return $fail();}
            if(!set_transient($key,$binding,5*MINUTE_IN_SECONDS))return new \WP_Error('lkf_captcha_unavailable',$item['fail_message'],['status'=>503]);
            return true;
        } finally {delete_option($lock);}
    }
}
