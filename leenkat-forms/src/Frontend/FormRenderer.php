<?php
declare(strict_types=1);
namespace LeenkatForms\Frontend;

use LeenkatForms\Forms\Publisher;
use LeenkatForms\Security\MathCaptcha;

final class FormRenderer {
    private static array $instances=[];
    public static function hooks(): void {
        add_shortcode('leenkat_form', [self::class, 'shortcode']);
        add_action('template_redirect',static function():void{
            $post=get_queried_object();
            if($post instanceof \WP_Post && (has_shortcode($post->post_content,'leenkat_form') || has_block('leenkat-forms/form',$post)))self::freshPage();
        },0);
    }
    /** Shared HTML must not supply the identity used by non-JavaScript POSTs. */
    private static function freshPage(): void {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache interoperability constant; a prefixed name would not be honored by cache plugins.
        if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
        if(!headers_sent())nocache_headers();
    }
    public static function shortcode(array|string $attributes = []): string {
        $attributes = shortcode_atts(['id'=>0,'title'=>false,'description'=>false], is_array($attributes) ? $attributes : [], 'leenkat_form');
        $id = absint($attributes['id']);
        $published = $id ? Publisher::published($id) : null;
        if (!$published) { return ''; }
        self::freshPage();
        $occurrence=self::$instances[$id]=(self::$instances[$id] ?? 0)+1;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Input is consumed only by its existing explicit authorization/validation boundary, not as an unguarded state-changing request.
        $receipt=\LeenkatForms\Submissions\NativeSubmission::receipt($id,$_GET,$occurrence);
        $complete=$receipt&&!$receipt['error'];
        FormAssets::styles();
        wp_enqueue_script('lkf-confirmation',plugins_url('assets/frontend/confirmation.js',LEENKAT_FORMS_FILE),[],LEENKAT_FORMS_VERSION . '.' . (string)filemtime(LEENKAT_FORMS_DIR.'assets/frontend/confirmation.js'),true);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Input is consumed only by its existing explicit authorization/validation boundary, not as an unguarded state-changing request.
        $definition = Prefill::definition($published['definition'],$id,wp_unslash($_GET));
        if($receipt&&$receipt['error']&&isset($receipt['fields']))$definition=FieldErrors::prefill($definition,$receipt['fields']);
        $general=$definition['settings']['general'] ?? [];
        $security=\LeenkatForms\Forms\SecuritySettings::normalize($definition);
        $needsProof=$security['modern']||$security['min_time'];
        $ajax=($general['ajax'] ?? true)!==false;
        FormAssets::enqueue($definition);
        $instance = 'lkf-' . wp_generate_password(8, false, false);
        $steps=\LeenkatForms\Forms\Steps::layout($definition);
        $multi=count($steps)>1;
        $returnUrl = get_permalink() ?: home_url('/');
        $action = !empty($general['disable_entries'])?admin_url('admin-post.php'):$returnUrl;
        ob_start();
        $sources=array_map(static fn($field)=>['id'=>$field['id'],'type'=>$field['type'],'settings'=>array_intersect_key($field['settings'] ?? [],array_flip(['multiple','name_format']))],$definition['fields']);
        // Without JS the browser cannot determine conditional requirements; final server
        // validation remains authoritative. Enhanced steps explicitly report validity.
        echo '<form novalidate'.' class="' . esc_attr(trim('lkf-form '.($general['form_class'] ?? ''))) . '" data-lkf-form data-lkf-validation-messages="' . esc_attr(wp_json_encode(FieldErrors::messages())) . '" data-lkf-endpoint="' . esc_url(rest_url('leenkat-forms/v1/forms/'.$id.'/submit')) . '" data-lkf-sources="'.esc_attr(wp_json_encode($sources)).'" data-lkf-ajax="'.($ajax?'1':'0').'" data-processing-text="'.esc_attr($general['processing_text'] ?? __('Sending...','leenkat-forms')).'" action="' . esc_url($action) . '" method="post" enctype="multipart/form-data" aria-label="' . esc_attr((string)($definition['title'] ?? __('Form', 'leenkat-forms'))) . '">';
        if($needsProof){
            $proof=\LeenkatForms\Security\SecurityChallenge::issue($id,$published['version_id']);
            echo '<input type="hidden" name="security_token" value="'.esc_attr($proof['token']).'" data-lkf-security-url="'.esc_url(rest_url('leenkat-forms/v1/forms/'.$id.'/security-token')).'">';
            echo '<div class="lkf-hp" aria-hidden="true"><label>'.esc_html__('Leave this field empty','leenkat-forms').'<input type="text" data-lkf-security-trap name="security_traps['.esc_attr($proof['honeypot']).']" tabindex="-1" autocomplete="off"></label></div>';
            if($security['modern'])echo '<noscript><p>'.esc_html__('Please enable JavaScript to submit this protected form.','leenkat-forms').'</p></noscript>';
        }
        echo '<input type="hidden" name="action" value="lkf_submit"><input type="hidden" name="form_id" value="'.esc_attr((string)$id).'"><input type="hidden" name="form_instance" value="'.esc_attr((string)$occurrence).'"><input type="hidden" name="submission_uuid" value="'.esc_attr(wp_generate_uuid4()).'"><input type="hidden" name="return_url" value="'.esc_url($returnUrl).'">';
        if($complete)echo '<input type="hidden" data-lkf-completed value="1">';
        if(filter_var($attributes['title'],FILTER_VALIDATE_BOOLEAN))echo '<h2 class="lkf-form-title">'.esc_html($definition['title']).'</h2>';
        if(filter_var($attributes['description'],FILTER_VALIDATE_BOOLEAN) && !empty($general['description']))echo '<p class="lkf-form-description">'.nl2br(esc_html($general['description'])).'</p>';
        echo '<div class="lkf-errors" role="alert" aria-live="assertive" tabindex="-1"'.($receipt&&$receipt['error']?'':' hidden').'>'.($receipt&&$receipt['error']?esc_html($receipt['message']):'').'</div>';
        echo '<div class="lkf-hp" aria-hidden="true"><label>' . esc_html__('Leave this field empty', 'leenkat-forms') . '<input type="text" name="website_confirmation" tabindex="-1" autocomplete="off"></label></div>';
        if($multi){
            $mode=$general['progress_indicator'] ?? 'bar';
            echo '<p data-lkf-step-status class="lkf-step-status" role="status" aria-live="polite" aria-atomic="true" data-pattern="'./* translators: 1: current step, 2: total applicable steps. */ esc_attr__('Step %1$d of %2$d','leenkat-forms').'" data-empty="'.esc_attr__('Ready to submit','leenkat-forms').'"></p>';
            if($mode==='bar')echo '<progress data-lkf-progress max="'.count($steps).'" value="1" aria-label="'.esc_attr__('Form progress','leenkat-forms').'" hidden></progress>';
            if($mode==='steps'){echo '<ol data-lkf-step-list class="lkf-step-list" aria-label="'.esc_attr__('Form progress','leenkat-forms').'">';foreach($steps as $step)echo '<li>'.esc_html($step['title']).'</li>';echo '</ol>';}
        }
        $nativeErrorFocus=false;
        $groups=$multi?$steps:[['fields'=>$definition['fields']]];
        foreach($groups as $stepIndex=>$step){
            if($multi)echo '<section data-lkf-step="'.esc_attr($step['id']).'" data-next-label="'.esc_attr($step['next_label'] ?? __('Next','leenkat-forms')).'" data-previous-label="'.esc_attr($step['previous_label'] ?? __('Previous','leenkat-forms')).'" aria-labelledby="'.esc_attr($instance.'-step-'.$stepIndex).'"><h3 id="'.esc_attr($instance.'-step-'.$stepIndex).'" data-lkf-step-heading tabindex="-1">'.esc_html($step['title']).'</h3>';
            echo '<div class="lkf-grid">';
        foreach ($step['fields'] as $field) {
            $fid = $instance . '-' . $field['id'];
            $fieldErrors=$receipt['field_errors'][$field['id']] ?? [];
            ob_start();
            $name = 'fields[' . $field['id'] . ']';
            $label = (string)($field['label'] ?? $field['id']);
            $required = !empty($field['required']);
            $retainedChecked=$receipt&&$receipt['error']&&in_array($receipt['fields'][$field['id']] ?? null,['1',1,true],true);
            $type = $field['type'];
            $settings = $field['settings'] ?? [];
            $description = (string)($settings['description'] ?? '');
            $descriptionId = $fid . '-description';
            $describedBy = $description !== '' ? ' aria-describedby="' . esc_attr($descriptionId) . '"' : '';
            $extraClasses = trim((string)($settings['css_classes'] ?? ''));
            $classes = 'lkf-field lkf-full';
            $classes .= ' lkf-type-' . $type;
            $classes .= ' lkf-size-' . esc_attr((string)($settings['field_size'] ?? 'large'));
            if (!empty($settings['hide_label']) && !in_array($type, ['checkbox','captcha'], true)) { $classes .= ' lkf-hide-label'; }
            if ($extraClasses !== '') { $classes .= ' ' . $extraClasses; }
            $rule = null;
            foreach (($definition['rules'] ?? []) as $candidate) { if (($candidate['field_id'] ?? '') === $field['id']) { $rule = $candidate; break; } }
            $ruleAttribute = $rule ? ' data-lkf-rule="' . esc_attr(wp_json_encode($rule)) . '"' : '';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
            echo '<div class="' . esc_attr($classes) . '" data-lkf-field-id="'.esc_attr($field['id']).'" data-lkf-error-prefix="'.esc_attr($fid).'" data-lkf-part-labels="'.esc_attr(wp_json_encode(FieldErrors::labels($field))).'"'.($fieldErrors?' data-lkf-server-errors="'.esc_attr(wp_json_encode($fieldErrors)).'"':'') . $ruleAttribute . '>';
            if (in_array($type, ['datetime','address','file_upload','section_divider'], true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
                echo CompositeFields::render($field, $fid, $name, $descriptionId);
            } elseif ($type === 'consent') {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
                echo '<fieldset class="lkf-consent-fieldset"><legend>' . esc_html($label) . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</legend><label><input id="' . esc_attr($fid) . '" type="checkbox" name="' . esc_attr($name) . '" value="1"' . $describedBy . ($required ? ' required' : '') . ($retainedChecked?' checked':'').'> ' . esc_html((string)($settings['agreement'] ?? __('I consent to having this website store my submitted information so they can respond to my inquiry.', 'leenkat-forms'))) . '</label></fieldset>';
            } elseif ($type === 'checkbox') {
                echo '<label><input id="' . esc_attr($fid) . '" type="checkbox" name="' . esc_attr($name) . '" value="1"' . ($required ? ' required' : '') . ($retainedChecked?' checked':'').'> ' . esc_html($label) . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</label>';
            } elseif ($type === 'radio' || $type === 'checkboxes') {
                echo '<fieldset data-lkf-choice-group data-required="' . ($required ? '1' : '0') . '" data-choice-limit="' . (int)($settings['choice_limit'] ?? 0) . '"><legend>' . esc_html($label) . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</legend><div class="lkf-choice-options lkf-choice-layout-' . esc_attr($settings['choice_layout'] ?? 'one') . (!empty($settings['image_choices']) || !empty($settings['icon_choices']) ? ' lkf-visual-choices' : '') . (!empty($settings['image_choices']) ? ' lkf-image-choices lkf-image-style-' . esc_attr($settings['image_choice_style'] ?? 'modern') : '') . '">';
                foreach (\LeenkatForms\Forms\ChoiceField::displayItems($settings) as $index=>$item) {
                    $optionId = $fid . '-' . $index;
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
                    echo '<label for="' . esc_attr($optionId) . '"><input id="' . esc_attr($optionId) . '" type="' . ($type === 'radio' ? 'radio' : 'checkbox') . '" name="' . esc_attr($name . ($type === 'checkboxes' ? '[]' : '')) . '" value="' . esc_attr($item['value']) . '"' . (in_array($item['value'], $settings['default_options'] ?? [], true) ? ' checked' : '') . $describedBy . ($required && $type === 'radio' ? ' required' : '') . '> ' . \LeenkatForms\Forms\ChoiceField::decoration($settings, $item['value']) . '<span>' . esc_html($item['label']) . '</span></label>';
                }
                echo '</div></fieldset>';
            } elseif ($type === 'email' && !empty($settings['email_confirmation'])) {
                echo '<fieldset class="lkf-email-fieldset"><legend>' . esc_html($label) . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</legend><div class="lkf-email-parts">';
                foreach (['email'=>__('Email','leenkat-forms'),'confirm'=>__('Confirm Email','leenkat-forms')] as $part=>$caption) {
                    $partName = $part === 'email' ? $name : 'email_confirmations[' . $field['id'] . ']';
                    $placeholder = (string)($settings[$part === 'email' ? 'placeholder' : 'confirmation_placeholder'] ?? '');
                    $extra = $part === 'email' ? ' data-lkf-email-filter="' . esc_attr(wp_json_encode(['mode'=>$settings['email_filter'] ?? 'none','patterns'=>$settings['email_patterns'] ?? []])) . '" value="' . esc_attr((string)($settings['default_value'] ?? '')) . '"' . (!empty($settings['read_only']) ? ' readonly' : '') : ' data-lkf-confirm-for="' . esc_attr($fid . '-email') . '" value="'.esc_attr(is_string($receipt['email_confirmations'][$field['id']] ?? null)?$receipt['email_confirmations'][$field['id']]:'').'"';
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
                    echo '<label for="' . esc_attr($fid . '-' . $part) . '"><input type="email" maxlength="254" id="' . esc_attr($fid . '-' . $part) . '" name="' . esc_attr($partName) . '" placeholder="' . esc_attr($placeholder) . '"' . $extra . $describedBy . ($required ? ' required' : '') . '><span' . (!empty($settings['hide_sublabels']) ? ' class="lkf-visually-hidden"' : '') . '>' . esc_html($caption) . '</span></label>';
                }
                echo '</div></fieldset>';
            } elseif ($type === 'name') {
                $format = $settings['name_format'] ?? 'first_last';
                echo '<fieldset class="lkf-name-fieldset"><legend>' . esc_html($label) . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</legend><div class="lkf-name-parts lkf-name-' . esc_attr($format) . '">';
                foreach (\LeenkatForms\Forms\NameField::parts($settings) as $part) {
                    $caption = $format === 'simple' ? $label : ['first'=>__('First', 'leenkat-forms'),'middle'=>__('Middle', 'leenkat-forms'),'last'=>__('Last', 'leenkat-forms')][$part];
                    $partSettings = $settings['name_parts'][$part] ?? [];
                    $hide = $format === 'simple' || !empty($settings['hide_sublabels']);
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
                    echo '<label for="' . esc_attr($fid . '-' . $part) . '"><input id="' . esc_attr($fid . '-' . $part) . '" type="text" name="' . esc_attr($name . '[' . $part . ']') . '" maxlength="250" placeholder="' . esc_attr((string)($partSettings['placeholder'] ?? '')) . '" value="' . esc_attr((string)($partSettings['default_value'] ?? '')) . '"' . $describedBy . ($required && $part !== 'middle' ? ' required' : '') . '><span' . ($hide ? ' class="lkf-visually-hidden"' : '') . '>' . esc_html($caption) . '</span></label>';
                }
                echo '</div></fieldset>';
            } elseif ($type === 'captcha') {
                $challenge = MathCaptcha::issue($id, $field['id']);
                echo '<label for="' . esc_attr($fid) . '">' . esc_html($label) . ': ' . esc_html((string)$challenge['first']) . ' + ' . esc_html((string)$challenge['second']) . ' = ?</label>';
                echo '<input id="' . esc_attr($fid) . '" type="number" inputmode="numeric" min="2" max="18" name="' . esc_attr($name) . '" required>';
                echo '<input type="hidden" name="' . esc_attr('captcha_tokens[' . $field['id'] . ']') . '" value="' . esc_attr($challenge['token']) . '">';
            } else {
                echo '<label for="' . esc_attr($fid) . '">' . esc_html($label) . ($required ? ' <span aria-hidden="true">*</span>' : '') . '</label>';
                if ($type === 'textarea') {
                    $maxLength = \LeenkatForms\Forms\TextLength::attributes($settings,5000);
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
                    echo '<textarea id="' . esc_attr($fid) . '" name="' . esc_attr($name) . '" placeholder="' . esc_attr((string)($settings['placeholder'] ?? '')) . '"' . $maxLength . $describedBy . (!empty($settings['read_only']) ? ' readonly' : '') . ($required ? ' required' : '') . '>' . esc_textarea((string)($settings['default_value'] ?? '')) . '</textarea>';
                } elseif ($type === 'select' || $type === 'rating') {
                    $prompt = $type === 'select' ? (string)($settings['placeholder'] ?? __('Select an option', 'leenkat-forms')) : __('Select an option', 'leenkat-forms');
                    $multiple = $type === 'select' && !empty($settings['multiple']);
                    $defaults = $settings['default_options'] ?? [];
                    $modern = $type === 'select' && ($settings['select_style'] ?? 'classic') === 'modern';
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
                    echo '<select id="' . esc_attr($fid) . '" name="' . esc_attr($name . ($multiple ? '[]' : '')) . '"' . ($multiple ? ' multiple' : '') . ($modern ? ' data-lkf-modern data-placeholder="' . esc_attr($prompt) . '"' : '') . $describedBy . ($required ? ' required' : '') . '>';
                    if (!$multiple && ($type === 'rating' || $prompt !== '' || !array_key_exists('options', $settings))) { echo '<option value="">' . esc_html($prompt) . '</option>'; }
                    $items = $type === 'rating' ? array_map(static fn($value): array => ['value'=>(string)$value,'label'=>(string)$value], range(1, (int)($settings['max'] ?? 5))) : \LeenkatForms\Forms\ChoiceField::items($settings);
                    foreach ($items as $item) { echo '<option value="' . esc_attr($item['value']) . '"' . (in_array($item['value'], $defaults, true) ? ' selected' : '') . '>' . esc_html($item['label']) . '</option>'; }
                    echo '</select>';
                } elseif ($type === 'number_slider') {
                    $initial = \LeenkatForms\Forms\NumberSlider::initial($settings);
                    $display = (string)($settings['value_display'] ?? __('Selected Value: {value}', 'leenkat-forms'));
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
                    echo '<input id="' . esc_attr($fid) . '" type="range" name="' . esc_attr($name) . '" min="' . esc_attr((string)($settings['min'] ?? 0)) . '" max="' . esc_attr((string)($settings['max'] ?? 100)) . '" step="' . esc_attr((string)($settings['step'] ?? 1)) . '" value="' . esc_attr($initial) . '"' . $describedBy . '><output for="' . esc_attr($fid) . '" data-lkf-value-display="' . esc_attr($display) . '">' . esc_html(str_replace('{value}', $initial, $display)) . '</output>';
                } else {
                    $htmlType = $type === 'phone' ? 'tel' : $type;
                    $placeholder = isset($field['settings']['placeholder']) ? ' placeholder="' . esc_attr((string)$field['settings']['placeholder']) . '"' : '';
                    $defaultValue = isset($settings['default_value']) ? ' value="' . esc_attr((string)$settings['default_value']) . '"' : '';
                    $maxLength = $type === 'text' ? \LeenkatForms\Forms\TextLength::attributes($settings) : '';
                    $inputMask = $type === 'text' && !empty($settings['input_mask']) ? ' data-lkf-input-mask="' . esc_attr((string)$settings['input_mask']) . '" pattern="' . esc_attr(\LeenkatForms\Forms\InputMask::pattern((string)$settings['input_mask'])) . '" title="' . esc_attr__('Please follow the required input format.', 'leenkat-forms') . '"' : '';
                    if ($type === 'email') { $inputMask .= ' maxlength="254" data-lkf-email-filter="' . esc_attr(wp_json_encode(['mode'=>$settings['email_filter'] ?? 'none','patterns'=>$settings['email_patterns'] ?? []])) . '"'; }
                    if ($type === 'phone' && isset($settings['phone_format'])) { $inputMask .= ' data-lkf-phone="' . esc_attr($settings['phone_format']) . '" maxlength="40"'; }
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
                    echo '<input id="' . esc_attr($fid) . '" type="' . esc_attr($htmlType) . '" name="' . esc_attr($name) . '"' . $placeholder . $defaultValue . $maxLength . $inputMask . $describedBy . (!empty($settings['read_only']) ? ' readonly' : '') . ($required ? ' required' : '') . '>';
                }
            }
            if ($description !== '') { echo '<p class="lkf-description' . ($type === 'checkboxes' && !empty($settings['terms_display']) ? ' lkf-disclaimer' : '') . '"' . ($type === 'checkboxes' && !empty($settings['terms_display']) ? ' tabindex="0"' : '') . ' id="' . esc_attr($descriptionId) . '">' . esc_html($description) . '</p>'; }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
            echo FieldErrors::markup($fid,$field,$fieldErrors);
            echo '</div>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
            echo FieldErrors::decorate((string)ob_get_clean(),$fid,$fieldErrors,$nativeErrorFocus);
        }
        echo '</div>';
        if($multi)echo '</section>';
        }
        if($multi)echo '<div class="lkf-step-navigation" data-lkf-step-nav hidden><button type="button" data-lkf-previous>'.esc_html__('Previous','leenkat-forms').'</button><button type="button" data-lkf-next>'.esc_html__('Next','leenkat-forms').'</button></div>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
        if(!empty($security['captcha_enabled']))echo \LeenkatForms\Security\CaptchaService::markup($id);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
        if(!empty($security['akismet_enabled']))echo \LeenkatForms\Security\AkismetService::privacyMarkup();
        echo '<button type="submit"'.($complete?' disabled':'').' class="'.esc_attr($general['submit_class'] ?? '').'">' . esc_html(trim($general['submit_text'] ?? '') ?: __('Submit','leenkat-forms')) . '</button>';
        echo '</form>';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
        if($complete)echo \LeenkatForms\Forms\ConfirmationSettings::markup($receipt['confirmation'] ?? ['html'=>esc_html($receipt['message'])]);
        return (string)ob_get_clean();
    }
}

