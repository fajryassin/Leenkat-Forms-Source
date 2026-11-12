<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;
/** Schema v1 structural validation shared by drafts and published snapshots. */
final class DefinitionValidator {
    public const SCHEMA_VERSION = 1;
    public const MAX_FIELDS = 300;
    public const SUPPORTED_FIELDS = ['text','email','textarea','number','select','checkbox','checkboxes','phone','url','radio','date','time','consent','rating','name','number_slider','captcha','datetime','address','file_upload','section_divider','page_break'];
    public static function validate(array $definition, bool $forPublication = false,bool $resolvePages=true): array {
        $errors = [];
        if(isset($definition['settings']['security']))$errors=array_merge($errors,SecuritySettings::validate($definition['settings']['security'],$forPublication,$resolvePages));
        // Disabled mappings are inert legacy preferences. Keep their structural
        // validation above, but require live field references only when enabled.
        if(($definition['settings']['security']['akismet_enabled']??false)===true && is_array($definition['settings']['security']['akismet_fields']??null)&&is_array($definition['fields']??null))foreach($definition['settings']['security']['akismet_fields'] as $role=>$id){if($id==='')continue;$found=false;foreach(($definition['fields']??[]) as $f)if(($f['id']??null)===$id&&($f['type']??null)===(['name'=>'name','email'=>'email','message'=>'textarea'][$role]??null))$found=true;if(!$found)$errors[]=__('Akismet mappings must reference existing Name, Email or Paragraph Text fields.','leenkat-forms');}
        if (($definition['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = __('Unsupported form schema version.', 'leenkat-forms');
        }
        if (!is_string($definition['title'] ?? null) || trim($definition['title']) === '' || strlen($definition['title']) > 500) {
            $errors[] = __('Invalid form title.', 'leenkat-forms');
        }
        if (!isset($definition['fields']) || !is_array($definition['fields']) || !array_is_list($definition['fields'])) {
            $errors[] = __('Fields must be a list.', 'leenkat-forms');
            return $errors;
        }
        if (count($definition['fields']) > self::MAX_FIELDS) { $errors[] = __('Too many fields.', 'leenkat-forms'); }
        $seen = [];
        $displayIds = [];
        if (isset($definition['next_field_number']) && (!is_int($definition['next_field_number']) || $definition['next_field_number'] < 1 || $definition['next_field_number'] > 99999999)) { $errors[] = __('Invalid next field number.', 'leenkat-forms'); }
        foreach ($definition['fields'] as $index => $field) {
            if (!is_array($field)) { $errors[] = sprintf(/* translators: 1: index. */ __('Field %1$s must be an object.', 'leenkat-forms'), $index); continue; }
            $id = $field['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $id)) {
                $errors[] = sprintf(/* translators: 1: index. */ __('Invalid field identifier at %1$s.', 'leenkat-forms'), $index);
            } elseif (isset($seen[$id])) {
                $errors[] = sprintf(/* translators: 1: field identifier or index. */ __('Duplicate field identifier: %1$s.', 'leenkat-forms'), $id);
            } else { $seen[$id] = true; }
            if (isset($field['display_id'])) {
                $displayId = $field['display_id'];
                if (!is_string($displayId) || !preg_match('/^f_[0-9]{4,8}$/D', $displayId) || (int)substr($displayId, 2) < 1 || isset($displayIds[$displayId])) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid or duplicate display identifier at %1$s.', 'leenkat-forms'), $index); }
                else { $displayIds[$displayId] = $id; }
            }
            if (!is_string($field['type'] ?? null) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $field['type'])) {
                $errors[] = sprintf(/* translators: 1: index. */ __('Invalid field type at %1$s.', 'leenkat-forms'), $index);
            } elseif (!in_array($field['type'], self::SUPPORTED_FIELDS, true)) {
                $errors[] = sprintf(/* translators: 1: index. */ __('Unsupported field type at %1$s.', 'leenkat-forms'), $index);
            }
            if (isset($field['required']) && !is_bool($field['required'])) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid required flag at %1$s.', 'leenkat-forms'), $index); }
            if (($field['type'] ?? '') === 'captcha' && ($field['required'] ?? null) !== true) { $errors[] = sprintf(/* translators: 1: field identifier or index. */ __('CAPTCHA must be required at %1$s.', 'leenkat-forms'), $index); }
            if (!is_string($field['label'] ?? null) || trim($field['label']) === '' || strlen($field['label']) > 500) {
                $errors[] = sprintf(/* translators: 1: index. */ __('Invalid label at %1$s.', 'leenkat-forms'), $index);
            }
            if (isset($field['width']) && !in_array($field['width'], [50,100], true)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid width at %1$s.', 'leenkat-forms'), $index); }
            if (isset($field['searchable']) && !is_bool($field['searchable'])) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid searchable flag at %1$s.', 'leenkat-forms'), $index); }
            if (isset($field['settings']) && (!is_array($field['settings']) || array_is_list($field['settings']) && $field['settings'] !== [])) {
                $errors[] = sprintf(/* translators: 1: index. */ __('Invalid settings at %1$s.', 'leenkat-forms'), $index);
            }
            if (in_array(($field['type'] ?? ''), ['select','radio','checkboxes'], true)) {
                $options = $field['settings']['options'] ?? null;
                if (!is_array($options) || !array_is_list($options) || $options === [] || count($options) > 100) {
                    $errors[] = sprintf(/* translators: 1: index. */ __('Choice field %1$s requires options.', 'leenkat-forms'), $index);
                } else {
                    foreach ($options as $option) {
                        if (!is_string($option) || $option === '' || strlen($option) > 200) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid select option at %1$s.', 'leenkat-forms'), $index); break; }
                    }
                }
            }
            if (($field['type'] ?? '') === 'rating') {
                $max = $field['settings']['max'] ?? 5;
                if (!is_int($max) || $max < 2 || $max > 10) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid rating maximum at %1$s.', 'leenkat-forms'), $index); }
            }
            if (($field['type'] ?? '') === 'number_slider') {
                $min = $field['settings']['min'] ?? 0;
                $max = $field['settings']['max'] ?? 100;
                $step = $field['settings']['step'] ?? 1;
                if ((!is_int($min) && !is_float($min)) || (!is_int($max) && !is_float($max)) || (!is_int($step) && !is_float($step)) || !is_finite((float)$min) || !is_finite((float)$max) || !is_finite((float)$step) || $min < -100000 || $max > 100000 || $min >= $max || $step < 0.000001 || $step > $max - $min) {
                    $errors[] = sprintf(/* translators: 1: index. */ __('Invalid number slider range at %1$s.', 'leenkat-forms'), $index);
                }
                if (isset($field['settings']['default_value']) && (!is_string($field['settings']['default_value']) || !NumberSlider::validValue($field['settings']['default_value'], $field['settings']))) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid slider default at %1$s.', 'leenkat-forms'), $index); }
            }
            if (isset($field['settings']['placeholder']) && (!is_string($field['settings']['placeholder']) || strlen($field['settings']['placeholder']) > 200)) {
                $errors[] = sprintf(/* translators: 1: index. */ __('Invalid placeholder at %1$s.', 'leenkat-forms'), $index);
            }
            $settings = is_array($field['settings'] ?? null) ? $field['settings'] : [];
            $errors = array_merge($errors, CompositeSettings::validate($field, $index));
            foreach (['email_confirmation','multiple','randomize_choices','image_choices','icon_choices','terms_display'] as $flag) { if (isset($settings[$flag]) && !is_bool($settings[$flag])) { $errors[] = sprintf(/* translators: 1: flag; 2: index. */ __('Invalid %1$s at %2$s.', 'leenkat-forms'), $flag, $index); } }
            if (!empty($settings['image_choices']) && !empty($settings['icon_choices'])) { $errors[] = sprintf(/* translators: 1: field identifier or index. */ __('Choose images or icons at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['image_choice_style']) && !in_array($settings['image_choice_style'], ['modern','classic','none'], true)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid image choice style at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['choice_layout']) && !in_array($settings['choice_layout'], ['one','two','three','inline'], true)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid choice layout at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['choice_limit']) && (!is_int($settings['choice_limit']) || $settings['choice_limit'] < 0 || $settings['choice_limit'] > 100)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid choice limit at %1$s.', 'leenkat-forms'), $index); }
            foreach (['agreement'=>1000,'value_display'=>200] as $key=>$limit) { if (isset($settings[$key]) && (!is_string($settings[$key]) || strlen($settings[$key]) > $limit)) { $errors[] = sprintf(/* translators: 1: key; 2: index. */ __('Invalid %1$s at %2$s.', 'leenkat-forms'), $key, $index); } }
            foreach (['choice_images','choice_icons'] as $key) {
                if (!isset($settings[$key])) { continue; }
                if (!is_array($settings[$key]) || count($settings[$key]) > 100) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid choice media at %1$s.', 'leenkat-forms'), $index); continue; }
                foreach ($settings[$key] as $choice=>$media) {
                    if (!in_array((string)$choice, $settings['options'] ?? [], true) || !is_string($media) || ($key === 'choice_images' ? ($media !== '' && !ChoiceField::imageUrlValid($media)) : !isset(ChoiceField::icons()[$media]))) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid choice media at %1$s.', 'leenkat-forms'), $index); }
                }
            }
            if (isset($settings['confirmation_placeholder']) && (!is_string($settings['confirmation_placeholder']) || strlen($settings['confirmation_placeholder']) > 200)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid confirmation placeholder at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['email_filter']) && !in_array($settings['email_filter'], ['none','allow','deny'], true)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid email filter at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['email_patterns'])) {
                if (!is_array($settings['email_patterns']) || !array_is_list($settings['email_patterns']) || count($settings['email_patterns']) > 100) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid email patterns at %1$s.', 'leenkat-forms'), $index); }
                else foreach ($settings['email_patterns'] as $pattern) { if (!is_string($pattern) || strlen($pattern) > 254 || !preg_match('/^[A-Za-z0-9.!#$%&\x27+\/=?^_`{|}~*@-]+$/D', $pattern) || !str_contains($pattern, '@')) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid email pattern at %1$s.', 'leenkat-forms'), $index); } }
            }
            if (isset($settings['phone_format']) && !in_array($settings['phone_format'], ['smart','us','international'], true)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid phone format at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['select_style']) && !in_array($settings['select_style'], ['classic','modern'], true)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid dropdown style at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['dynamic_choices']) && !in_array($settings['dynamic_choices'], ['off','post_type','taxonomy'], true)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid dynamic choice mode at %1$s.', 'leenkat-forms'), $index); }
            if (in_array($settings['dynamic_choices'] ?? 'off', ['post_type','taxonomy'], true) && (!is_string($settings['dynamic_source'] ?? null) || !isset(ChoiceField::sources()[$settings['dynamic_choices']][$settings['dynamic_source']]))) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid dynamic choice source at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['default_options'])) {
                if (!is_array($settings['default_options']) || !array_is_list($settings['default_options']) || count($settings['default_options']) > 100 || (($field['type'] ?? '') !== 'checkboxes' && empty($settings['multiple']) && count($settings['default_options']) > 1) || (($field['type'] ?? '') === 'checkboxes' && !empty($settings['choice_limit']) && count($settings['default_options']) > $settings['choice_limit'])) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid default choices at %1$s.', 'leenkat-forms'), $index); }
                else foreach ($settings['default_options'] as $default) { if (!is_string($default) || !in_array($default, array_column(ChoiceField::items($settings), 'value'), true)) { $errors[] = sprintf(/* translators: 1: field identifier or index. */ __('Unknown default choice at %1$s.', 'leenkat-forms'), $index); } }
            }
            if (isset($settings['name_format']) && !in_array($settings['name_format'], ['simple','first_last','first_middle_last'], true)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid name format at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['hide_sublabels']) && !is_bool($settings['hide_sublabels'])) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid sublabel flag at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['name_parts'])) {
                if (!is_array($settings['name_parts']) || array_diff(array_keys($settings['name_parts']), ['first','middle','last'])) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid name parts at %1$s.', 'leenkat-forms'), $index); }
                else foreach ($settings['name_parts'] as $part) {
                    if (!is_array($part) || array_diff(array_keys($part), ['placeholder','default_value'])) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid name part settings at %1$s.', 'leenkat-forms'), $index); continue; }
                    foreach ($part as $value) { if (!is_string($value) || strlen($value) > 250) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid name part value at %1$s.', 'leenkat-forms'), $index); } }
                }
            }
            foreach (['description' => 1000, 'default_value' => ($field['type'] ?? '') === 'textarea' ? 5000 : 1000] as $key => $limit) {
                if (isset($settings[$key]) && (!is_string($settings[$key]) || ($key==='default_value' && in_array($field['type'] ?? '',['text','textarea'],true) ? !TextLength::valid($settings[$key],[], $limit) : strlen($settings[$key]) > $limit))) { $errors[] = sprintf(/* translators: 1: key; 2: index. */ __('Invalid %1$s at %2$s.', 'leenkat-forms'), $key, $index); }
            }
            if (isset($settings['field_size']) && !in_array($settings['field_size'], ['small','medium','large'], true)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid field size at %1$s.', 'leenkat-forms'), $index); }
            foreach (['limit_length_enabled','hide_label','read_only'] as $key) {
                if (isset($settings[$key]) && !is_bool($settings[$key])) { $errors[] = sprintf(/* translators: 1: key; 2: index. */ __('Invalid %1$s at %2$s.', 'leenkat-forms'), $key, $index); }
            }
            if (isset($settings['length_unit']) && !in_array($settings['length_unit'], ['characters','words'], true)) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid length unit at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['max_length']) && (!is_int($settings['max_length']) || $settings['max_length'] < 1 || $settings['max_length'] > (($field['type'] ?? '') === 'textarea' ? 5000 : 1000))) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid maximum length at %1$s.', 'leenkat-forms'), $index); }
            if (!empty($settings['limit_length_enabled']) && !isset($settings['max_length'])) { $errors[] = sprintf(/* translators: 1: index. */ __('Missing maximum length at %1$s.', 'leenkat-forms'), $index); }
            if (!empty($settings['limit_length_enabled']) && is_string($settings['default_value'] ?? null) && is_int($settings['max_length'] ?? null) && TextLength::count($settings['default_value'], (string)($settings['length_unit'] ?? 'characters')) > $settings['max_length']) { $errors[] = sprintf(/* translators: 1: index. */ __('Default value exceeds maximum length at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['input_mask']) && (!is_string($settings['input_mask']) || !InputMask::valid($settings['input_mask']))) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid input mask at %1$s.', 'leenkat-forms'), $index); }
            if (!empty($settings['input_mask']) && !empty($settings['limit_length_enabled']) && ($settings['length_unit'] ?? 'characters') === 'characters' && is_int($settings['max_length'] ?? null) && strlen($settings['input_mask']) > $settings['max_length']) { $errors[] = sprintf(/* translators: 1: index. */ __('Input mask exceeds maximum length at %1$s.', 'leenkat-forms'), $index); }
            if (!empty($settings['input_mask']) && is_string($settings['default_value'] ?? null) && $settings['default_value'] !== '' && !InputMask::matches($settings['input_mask'], $settings['default_value'])) { $errors[] = sprintf(/* translators: 1: index. */ __('Default value does not match input mask at %1$s.', 'leenkat-forms'), $index); }
            if (isset($settings['css_classes']) && (!is_string($settings['css_classes']) || strlen($settings['css_classes']) > 200 || !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_-]*[ ]*)*$/D', $settings['css_classes']))) { $errors[] = sprintf(/* translators: 1: index. */ __('Invalid CSS classes at %1$s.', 'leenkat-forms'), $index); }
            if (!empty($settings['read_only']) && !empty($field['required']) && ($settings['default_value'] ?? '') === '') { $errors[] = sprintf(/* translators: 1: index. */ __('Required read-only field needs a default value at %1$s.', 'leenkat-forms'), $index); }
        }
        foreach ($displayIds as $displayId => $storageId) {
            if ($displayId !== $storageId && isset($seen[$displayId])) { $errors[] = __('Display identifier conflicts with another field.', 'leenkat-forms'); }
            if (is_int($definition['next_field_number'] ?? null) && (int)substr($displayId, 2) >= $definition['next_field_number']) { $errors[] = __('Next field number must exceed assigned identifiers.', 'leenkat-forms'); }
        }
        if (isset($definition['rules'])) {
            if (!is_array($definition['rules']) || !array_is_list($definition['rules']) || count($definition['rules']) > self::MAX_FIELDS) { $errors[] = __('Invalid rules list.', 'leenkat-forms'); }
            else {
                $positions = [];
                foreach ($definition['fields'] as $position=>$field) { if (is_array($field) && is_string($field['id'] ?? null)) { $positions[$field['id']] = $position; } }
                $targets = [];
                $allSources = [];
                foreach ($definition['rules'] as $rule) {
                    if (!is_array($rule) || !is_string($rule['field_id'] ?? null) || !in_array(($rule['action'] ?? 'show'), ['show','hide'], true)) { $errors[] = __('Invalid visibility rule.', 'leenkat-forms'); continue; }
                    $target = $rule['field_id'];
                    if (!isset($positions[$target]) || isset($targets[$target])) { $errors[] = __('Visibility rules need one existing target field.', 'leenkat-forms'); }
                    if(isset($positions[$target]) && ($definition['fields'][$positions[$target]]['type'] ?? '')==='page_break')$errors[]=__('Page Break cannot be a conditional target.', 'leenkat-forms');
                    $targets[$target] = true;
                    $groups = isset($rule['groups']) ? $rule['groups'] : [[$rule]];
                    if (!is_array($groups) || !array_is_list($groups) || !$groups || count($groups) > 5) { $errors[] = __('Invalid visibility groups.', 'leenkat-forms'); continue; }
                    foreach ($groups as $group) {
                        if (!is_array($group) || !array_is_list($group) || !$group || count($group) > 5) { $errors[] = __('Invalid visibility condition group.', 'leenkat-forms'); continue; }
                        foreach ($group as $condition) {
                            if (!is_array($condition) || !is_string($condition['source_field_id'] ?? null) || !in_array(($condition['operator'] ?? ''), ['equals','not_equals','is_empty','not_empty','contains','not_contains','starts_with','ends_with','greater_than','less_than'], true)) { $errors[] = __('Invalid visibility condition.', 'leenkat-forms'); continue; }
                            if (isset($condition['value']) && (!is_string($condition['value']) || strlen($condition['value']) > 200)) { $errors[] = __('Invalid visibility comparison value.', 'leenkat-forms'); }
                            $source = $condition['source_field_id'];
                            if ($source === '') { if ($forPublication) { $errors[] = __('Choose a field for every conditional rule.', 'leenkat-forms'); } continue; }
                            $allSources[] = $source;
                            if (isset($positions[$source]) && !Rules::sourceAllowed($definition['fields'][$positions[$source]])) { $errors[] = __('This field cannot be a conditional source.', 'leenkat-forms'); }
                            if (!isset($positions[$source],$positions[$target]) || $positions[$source] >= $positions[$target]) { $errors[] = __('Visibility rules must reference an earlier field.', 'leenkat-forms'); }

                        }
                    }
                }
                foreach ($allSources as $source) { if (isset($targets[$source])) { $errors[] = __('Conditional source fields cannot themselves be conditional.', 'leenkat-forms'); break; } }
            }
        }
        if (isset($definition['settings']['general'])) { $errors = array_merge($errors, GeneralSettings::validate($definition['settings']['general'])); }
        if (isset($definition['settings'])) {
            if (!is_array($definition['settings']) || array_is_list($definition['settings']) && $definition['settings'] !== []) { $errors[] = __('Invalid form settings.', 'leenkat-forms'); }
            elseif (isset($definition['settings']['confirmation']['message']) && (!is_string($definition['settings']['confirmation']['message']) || strlen($definition['settings']['confirmation']['message']) > 1000)) { $errors[] = __('Invalid confirmation message.', 'leenkat-forms'); }
            if(isset($definition['settings']['confirmations']))$errors=array_merge($errors,ConfirmationSettings::validate($definition['settings']['confirmations'],$definition['fields'],$forPublication,$resolvePages));
            $notifications = $definition['settings']['notifications'] ?? [];
            if (!is_array($notifications)) { $errors[] = __('Invalid notification settings.', 'leenkat-forms'); }
            elseif(array_key_exists('items',$notifications)) { $errors=array_merge($errors,NotificationSettings::validate($notifications,$definition['fields'],$forPublication)); }
            else {
                foreach (['admin','user'] as $kind) {
                    $config = $notifications[$kind] ?? [];
                    if (!is_array($config)) { $errors[] = sprintf(/* translators: 1: kind. */ __('Invalid %1$s notification.', 'leenkat-forms'), $kind); continue; }
                    if (isset($config['enabled']) && !is_bool($config['enabled'])) { $errors[] = sprintf(/* translators: 1: kind. */ __('Invalid %1$s notification flag.', 'leenkat-forms'), $kind); }
                    if (empty($config['enabled'])) { continue; }
                    foreach (['subject','body'] as $key) {
                        if (!is_string($config[$key] ?? null) || trim($config[$key]) === '' || strlen($config[$key]) > ($key === 'body' ? 5000 : 200)) { $errors[] = sprintf(/* translators: 1: kind; 2: key. */ __('Invalid %1$s notification %2$s.', 'leenkat-forms'), $kind, $key); }
                    }
                    if ($kind === 'admin') {
                        $recipients = $config['recipients'] ?? null;
                        if (!is_array($recipients) || !array_is_list($recipients) || $recipients === [] || count($recipients) > 5) { $errors[] = __('Invalid admin recipients.', 'leenkat-forms'); }
                        else { foreach ($recipients as $email) { if (!is_string($email) || !is_email($email)) { $errors[] = __('Invalid admin recipient address.', 'leenkat-forms'); break; } } }
                    } else {
                        $emailField = $config['email_field'] ?? null;
                        $field = is_string($emailField) ? array_values(array_filter($definition['fields'], static fn($item): bool => is_array($item) && ($item['id'] ?? null) === $emailField && ($item['type'] ?? null) === 'email')) : [];
                        if (!$field) { $errors[] = __('User notification needs an email field.', 'leenkat-forms'); }
                    }
                }
            }
        }
        if (isset($definition['steps'])) {
            if (!is_array($definition['steps']) || !array_is_list($definition['steps'])) { $errors[] = __('Steps must be a list.', 'leenkat-forms'); }
            else {
                if(count($definition['steps'])>50)$errors[]=__('A form supports at most 50 steps.', 'leenkat-forms');
                $stepIds = []; $assigned = [];
                foreach ($definition['steps'] as $i => $step) {
                    if (!is_array($step) || !is_string($step['id'] ?? null) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $step['id'])) { $errors[] = sprintf(/* translators: 1: i. */ __('Invalid step identifier at %1$s.', 'leenkat-forms'), $i); continue; }
                    if (isset($stepIds[$step['id']])) { $errors[] = __('Duplicate step identifier.', 'leenkat-forms'); }
                    $stepIds[$step['id']] = true;
                    if(isset($step['title']) && (!is_string($step['title']) || strlen($step['title'])>500 || !preg_match('//u',$step['title'])))$errors[]=__('Invalid step title.', 'leenkat-forms');
                    if (!isset($step['field_ids']) || !is_array($step['field_ids']) || !array_is_list($step['field_ids'])) { $errors[] = sprintf(/* translators: 1: i. */ __('Invalid step fields at %1$s.', 'leenkat-forms'), $i); continue; }
                    foreach ($step['field_ids'] as $fid) {
                        if (!is_string($fid) || !isset($seen[$fid])) { $errors[] = __('Step references unknown field.', 'leenkat-forms'); continue; }
                        if (isset($assigned[$fid])) { $errors[] = __('Field assigned to multiple steps.', 'leenkat-forms'); }
                        $assigned[$fid] = true;
                    }
                }
            }
        }
        if(!$errors)$errors=array_merge($errors,Steps::definitionErrors($definition));
        if($forPublication && !$errors)$errors=array_merge($errors,Steps::publicationErrors($definition));
        return $errors;
    }
}
