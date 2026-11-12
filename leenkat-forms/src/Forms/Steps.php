<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Presentation only: answers are always validated by the existing full-form pipeline. */
final class Steps {
    public static function hasBreaks(array $definition): bool {
        foreach($definition['fields'] ?? [] as $field)if(($field['type'] ?? '')==='page_break')return true;
        return false;
    }
    /** Layout elements are adapted into the same page contract used since dev.47. */
    public static function layout(array $definition): array {
        if(self::hasBreaks($definition)){
            $ids=array_column($definition['fields'],'id');$first='page_start';while(in_array($first,$ids,true))$first.='_';
            $text=static function(string $value,string $fallback):string{$value=trim($value);return $value===''?$fallback:$value;};
            $title=$text($definition['settings']['general']['first_page_title'] ?? '',sprintf(/* translators: %d: step number. */ __('Step %d','leenkat-forms'),1));
            $result=[['id'=>$first,'title'=>$title,'fields'=>[]]];$index=0;
            foreach($definition['fields'] as $field){
                if($field['type']!=='page_break'){$result[$index]['fields'][]=$field;continue;}
                $s=$field['settings'] ?? [];
                $result[$index]['next_label']=$text($s['next_label'] ?? '',__('Next','leenkat-forms'));
                $index++;
                $result[]=['id'=>$field['id'],'title'=>$text($s['page_title'] ?? '',sprintf(/* translators: %d: step number. */ __('Step %d','leenkat-forms'),$index+1)),'previous_label'=>$text($s['previous_label'] ?? '',__('Previous','leenkat-forms')),'fields'=>[]];
            }
            return $result;
        }
        $steps=$definition['steps'] ?? [];
        if (!$steps) return [];
        $membership=[];
        foreach ($steps as $index=>$step) foreach ($step['field_ids'] as $id) $membership[$id]=$index;
        $result=[];
        foreach ($steps as $index=>$step) $result[]=['id'=>$step['id'],'title'=>trim($step['title'] ?? '') ?: sprintf(/* translators: %d: step number. */ __('Step %d','leenkat-forms'),$index+1),'fields'=>[]];
        foreach ($definition['fields'] as $field) $result[$membership[$field['id']] ?? 0]['fields'][]=$field;
        return $result;
    }
    public static function definitionErrors(array $definition): array {
        if(!self::hasBreaks($definition))return [];
        $errors=[];$fields=$definition['fields'];$previousBreak=true;$count=0;
        if(!empty($definition['steps']))$errors[]=__('Page Break fields cannot be combined with legacy steps. Convert the draft first.', 'leenkat-forms');
        foreach($fields as $field){
            if($field['type']!=='page_break'){$previousBreak=false;continue;}
            if($previousBreak)$errors[]=__('Page Breaks cannot lead the form or be consecutive.', 'leenkat-forms');
            $previousBreak=true;$count++;
            if(!empty($field['required'])||!empty($field['searchable']))$errors[]=__('Page Break is a layout element and cannot be required or searchable.', 'leenkat-forms');
            foreach(['page_title'=>500,'next_label'=>200,'previous_label'=>200] as $key=>$limit)if(array_key_exists($key,$field['settings'] ?? []) && (!is_string($field['settings'][$key]) || strlen($field['settings'][$key])>$limit || !preg_match('//u',$field['settings'][$key])))$errors[]=__('Invalid Page Break ', 'leenkat-forms').$key.'.';
        }
        if($previousBreak)$errors[]=__('A Page Break cannot end the form.', 'leenkat-forms');
        if($count>49)$errors[]=__('A form supports at most 50 pages.', 'leenkat-forms');
        return array_values(array_unique($errors));
    }
    public static function publicationErrors(array $definition): array {
        if (!self::hasBreaks($definition) && count($definition['steps'] ?? [])<2) return [];
        $positions=[];
        foreach (self::layout($definition) as $index=>$step) foreach ($step['fields'] as $field) $positions[$field['id']]=$index;
        foreach ($definition['rules'] ?? [] as $rule) foreach ($rule['groups'] ?? [[$rule]] as $group) foreach ($group as $condition) {
            if (($positions[$condition['source_field_id'] ?? ''] ?? -1)>($positions[$rule['field_id']] ?? PHP_INT_MAX)) return [__('A conditional source must be on the same or an earlier step than its dependent field.', 'leenkat-forms')];
        }
        return [];
    }
}
