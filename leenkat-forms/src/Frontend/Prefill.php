<?php
declare(strict_types=1);
namespace LeenkatForms\Frontend;

/** Explicit form/field scoped query keys. Never prefill consent, CAPTCHA, files or read-only values. */
final class Prefill {
    public static function definition(array $definition,int $formId,array $query): array {
        if (empty($definition['settings']['general']['prefill'])) { return $definition; }
        foreach($definition['fields'] as &$field) {
            $s=$field['settings'] ?? []; $type=$field['type'];
            if (!empty($s['read_only']) || in_array($type,['consent','checkbox','captcha','file_upload','section_divider','page_break'],true))continue;
            $prefix='lkf'.$formId.'_'.$field['id'];
            $alias='lkf'.$formId.'_'.($field['display_id'] ?? $field['id']);
            $read=static function(string $key)use($query,$prefix,$alias,$type):?string {
                $value=$query[$key] ?? $query[$alias.substr($key,strlen($prefix))] ?? null;
                if(in_array($type,['select','radio','checkboxes'],true) && is_array($value) && array_is_list($value) && count($value)<=100 && count(array_filter($value,'is_string'))===count($value))$value=implode('|',$value);
                return is_string($value) && strlen($value)<=5000?sanitize_textarea_field($value):null;
            };
            $value=$read($prefix) ?? $read($prefix.'_primary');
            if(in_array($type,['select','radio','checkboxes'],true) && $value!==null) {
                $allowed=array_column(\LeenkatForms\Forms\ChoiceField::items($s),'value');$selected=array_values(array_intersect(explode('|',$value),$allowed));
                $s['default_options']=($type==='checkboxes' || !empty($s['multiple']))?array_values(array_unique($selected)):array_slice($selected,0,1);
            } elseif(in_array($type,['name','address','datetime'],true)) {
                $parts=$type==='name'?\LeenkatForms\Forms\NameField::parts($s):($type==='address'?\LeenkatForms\Forms\AddressField::parts($s):\LeenkatForms\Forms\DateTimeField::parts($s));
                foreach($parts as $part) { $partValue=$read($prefix.'_'.$part);if($partValue===null)continue;
                    if($type==='datetime') { if(\LeenkatForms\Forms\DateTimeField::valid($part,$partValue,$s))$s['prefill_'.$part]=$partValue; }
                    elseif($type==='name')$s['name_parts'][$part]['default_value']=substr($partValue,0,250);
                    else { $options=\LeenkatForms\Forms\AddressField::options($part,$s);if(!$options || isset($options[$partValue]))$s['address_parts'][$part]['default_value']=substr($partValue,0,250); }
                }
            } elseif($value!==null) {
                $valid=match($type) {
                    'email'=>$value==='' || is_email($value),
                    'url'=>$value==='' || filter_var($value,FILTER_VALIDATE_URL) && in_array(strtolower((string)wp_parse_url($value,PHP_URL_SCHEME)),['http','https'],true),
                    'number'=>is_numeric($value) && is_finite((float)$value),
                    'number_slider'=>\LeenkatForms\Forms\NumberSlider::validValue($value,$s),
                    'date'=>($date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value)) && $date->format('Y-m-d')===$value,
                    'time'=>(bool)preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value),
                    'rating'=>ctype_digit($value) && (int)$value>=1 && (int)$value<=(int)($s['max'] ?? 5),
                    default=>true,
                };
                if($valid)$s['default_value']=$value;
            }
            $field['settings']=$s;
        }
        unset($field);return $definition;
    }
}
