<?php
declare(strict_types=1);
namespace LeenkatForms\Frontend;

final class FormAssets {
    public static function hooks(): void {add_action('wp_enqueue_scripts',static function():void{if(\LeenkatForms\Settings\SiteSettings::general()['assets_global'])self::enqueue([],true);});}
    public static function stylePaths(): array {
        $style=\LeenkatForms\Settings\SiteSettings::general()['styling'];
        return array_merge(['assets/frontend/form-core.css'],$style==='none'?[]:[$style==='base'?'assets/frontend/form-base.css':'assets/frontend/form.css']);
    }
    public static function styles(): void {foreach(self::stylePaths() as $index=>$path)wp_enqueue_style($index===0?'lkf-form-core':'lkf-form',plugins_url($path,LEENKAT_FORMS_FILE),$index===0?[]:['lkf-form-core'],LEENKAT_FORMS_VERSION.'.'.filemtime(LEENKAT_FORMS_DIR.$path));}
    public static function enqueue(array $definition=[],bool $all=false): void {
        wp_enqueue_script('lkf-widget-i18n',plugins_url('assets/frontend/widget-i18n.js',LEENKAT_FORMS_FILE),['wp-i18n'],LEENKAT_FORMS_VERSION,true);
        $locale=function_exists('determine_locale')?determine_locale():'en_US';
        $localeConfig=['locale'=>$locale];
        if(preg_match('/^ar(?:[_-]|$)/i',$locale))$localeConfig['countries']=\LeenkatForms\Forms\AddressField::data()['countries'];
        wp_add_inline_script('lkf-widget-i18n','window.lkfLocale='.wp_json_encode($localeConfig).';','before');
        self::styles();wp_enqueue_script('lkf-confirmation',plugins_url('assets/frontend/confirmation.js',LEENKAT_FORMS_FILE),[],LEENKAT_FORMS_VERSION.'.'.filemtime(LEENKAT_FORMS_DIR.'assets/frontend/confirmation.js'),true);
        wp_enqueue_script('lkf-rules',plugins_url('assets/frontend/rules.js',LEENKAT_FORMS_FILE),[],LEENKAT_FORMS_VERSION.'.'.filemtime(LEENKAT_FORMS_DIR.'assets/frontend/rules.js'),true);$dependencies=['lkf-rules','lkf-confirmation'];
        wp_enqueue_script('lkf-validation',plugins_url('assets/frontend/validation.js',LEENKAT_FORMS_FILE),[],LEENKAT_FORMS_VERSION.'.'.filemtime(LEENKAT_FORMS_DIR.'assets/frontend/validation.js'),true);$dependencies[]='lkf-validation';
        wp_enqueue_script('lkf-steps',plugins_url('assets/frontend/multistep.js',LEENKAT_FORMS_FILE),['lkf-validation'],LEENKAT_FORMS_VERSION.'.'.filemtime(LEENKAT_FORMS_DIR.'assets/frontend/multistep.js'),true);$dependencies[]='lkf-steps';
        if(!empty($definition['settings']['security']['captcha_enabled'])){\LeenkatForms\Security\CaptchaService::assets();$dependencies[]='lkf-captcha';}
        $fields=$definition['fields'] ?? [];
        foreach(['datetime'=>['lkf-flatpickr','assets/vendor/flatpickr/flatpickr.min.css','assets/vendor/flatpickr/flatpickr.min.js','4.6.9'],'phone'=>['lkf-phone','assets/vendor/intl-tel-input/build/css/intlTelInput.css','assets/vendor/intl-tel-input/build/js/intlTelInputWithUtils.js','25.12.6-lkf.1'],'select'=>['lkf-choices','assets/vendor/choices/public/assets/styles/choices.css','assets/vendor/choices/public/assets/scripts/choices.js','11.1.0']] as $type=>[$handle,$css,$js,$version]){
            $needed=$all||array_filter($fields,static fn(array $f):bool=>$f['type']===$type&&($type==='phone'?($f['settings']['phone_format'] ?? '')==='smart':($type==='select'?($f['settings']['select_style'] ?? '')==='modern':true)));
            if($needed){wp_enqueue_style($handle,plugins_url($css,LEENKAT_FORMS_FILE),[],$version);wp_enqueue_script($handle,plugins_url($js,LEENKAT_FORMS_FILE),[],$version,true);$dependencies[]=$handle;}
        }
        $dependencies[]='lkf-widget-i18n';
        wp_enqueue_script('lkf-form',plugins_url('assets/frontend/form.js',LEENKAT_FORMS_FILE),$dependencies,LEENKAT_FORMS_VERSION.'.'.filemtime(LEENKAT_FORMS_DIR.'assets/frontend/form.js'),true);
    }
    public static function nativeStyles(): string {
        self::styles();
        $handles=array_map(static fn($index)=>$index===0?'lkf-form-core':'lkf-form',array_keys(self::stylePaths()));
        ob_start();wp_print_styles($handles);return (string)ob_get_clean();
    }
}
