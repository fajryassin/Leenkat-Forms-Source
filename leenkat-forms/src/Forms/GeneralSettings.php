<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

/** General form settings are versioned with the definition, not global options. */
final class GeneralSettings {
    public static function validate(mixed $settings): array {
        if (!is_array($settings) || $settings !== [] && array_is_list($settings)) { return [__('Invalid general settings.', 'leenkat-forms')]; }
        $errors = [];
        foreach (['first_page_title'=>500,'description'=>5000,'submit_text'=>200,'processing_text'=>200,'form_class'=>500,'submit_class'=>500] as $key=>$limit) {
            if (array_key_exists($key,$settings) && (!is_string($settings[$key]) || strlen($settings[$key])>$limit)) { $errors[] = sprintf(/* translators: %s: general setting identifier. */ __('Invalid general %s.','leenkat-forms'),$key); }
        }
        foreach (['prefill','ajax','disable_entries','disable_ip'] as $key) { if (array_key_exists($key,$settings) && !is_bool($settings[$key])) { $errors[] = sprintf(/* translators: %s: general setting flag identifier. */ __('Invalid general %s flag.','leenkat-forms'),$key); } }
        foreach (['form_class','submit_class'] as $key) { if (is_string($settings[$key] ?? null) && $settings[$key]!=='' && !preg_match('/^[A-Za-z0-9_\-\s]+$/D',$settings[$key])) { $errors[] = __('Invalid CSS classes.', 'leenkat-forms'); } }
        if (isset($settings['tags'])) {
            if (!is_array($settings['tags']) || !array_is_list($settings['tags']) || count($settings['tags'])>20) { $errors[]=__('Invalid form tags.', 'leenkat-forms'); }
            else { $seen=[];foreach($settings['tags'] as $tag) { if (!is_string($tag) || trim($tag)==='' || strlen($tag)>100 || wp_strip_all_tags($tag)!==trim($tag) || preg_match('/[\x00-\x1F\x7F]/',$tag)) { $errors[]=__('Invalid form tag.', 'leenkat-forms');continue; } $key=strtolower(trim($tag));if(isset($seen[$key]))$errors[]=__('Duplicate form tag.', 'leenkat-forms');$seen[$key]=true; } }
        }
        if (array_key_exists('progress_indicator',$settings) && !in_array($settings['progress_indicator'],['bar','steps','none'],true)) $errors[]=__('Invalid progress indicator.', 'leenkat-forms');
        return $errors;
    }
    public static function details(array $settings, array $server): array {
        // Existing definitions did not collect visitor details. Only an explicit Off opts in.
        if (\LeenkatForms\Settings\SiteSettings::disableUserDetails() || ($settings['disable_ip'] ?? true)!==false) { return []; }
        $ip=(string)($server['REMOTE_ADDR'] ?? '');$agent=(string)($server['HTTP_USER_AGENT'] ?? '');
        return ['ip_address'=>filter_var($ip,FILTER_VALIDATE_IP)?$ip:'','user_agent'=>substr(sanitize_text_field($agent),0,500)];
    }
}
