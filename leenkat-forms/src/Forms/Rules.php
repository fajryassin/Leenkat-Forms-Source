<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Restricted one-level visibility grammar; no dynamic code execution. */
final class Rules {
    public static function sourceAllowed(array $field): bool {
        return in_array($field['type'] ?? '', ['text','textarea','email','phone','url','number','number_slider','select','radio','rating','checkbox','checkboxes','consent','name','date','time'], true);
    }
    /** Comparison projection of submitted input, separate from output/storage sanitization. */
    public static function normalizeSource(array $field, mixed $value): mixed {
        if (!self::sourceAllowed($field)) { return null; }
        $type=$field['type'];
        if (in_array($type,['checkbox','consent'],true)) { return in_array($value,['1',1,true],true) ? '1' : ''; }
        if ($type==='checkboxes' || $type==='select' && !empty($field['settings']['multiple'])) { return is_array($value) ? array_values(array_filter($value,'is_string')) : []; }
        if (in_array($type,['select','radio','rating'],true)) { return is_string($value) ? $value : ''; }
        if ($type==='name') {
            $parts=[];foreach(NameField::parts($field['settings'] ?? []) as $part){$text=is_array($value)&&is_string($value[$part] ?? null)?TextLength::normalize($value[$part]):'';if($text!=='')$parts[]=$text;}return implode(' ',$parts);
        }
        return is_string($value) ? TextLength::normalize($value) : '';
    }
    private static function numeric(string $value): bool {
        return preg_match('/^[+-]?(?:[0-9]+\\.?[0-9]*|\\.[0-9]+)(?:e[+-]?[0-9]+)?$/iD',TextLength::normalize($value))===1 && is_finite((float)$value);
    }
    public static function condition(array $rule, array $values): bool {
        if (empty($rule['source_field_id'])) { return false; }
        $value = $values[$rule['source_field_id']] ?? null;
        if (is_array($value)) {
            if ($rule['operator'] === 'is_empty') { return $value === []; }
            if ($rule['operator'] === 'not_empty') { return $value !== []; }
            $positive = match ($rule['operator']) { 'not_equals'=>'equals','not_contains'=>'contains',default=>$rule['operator'] };
            $matches = array_filter($value, static fn($item): bool => self::condition(array_merge($rule,['operator'=>$positive]),[$rule['source_field_id']=>$item]));
            return in_array($rule['operator'], ['not_equals','not_contains'], true) ? !$matches : (bool)$matches;
        }
        $actual = $value === true ? '1' : ($value === false || $value === null ? '' : (string)$value);
        $expected = (string)($rule['value'] ?? '');
        return match ($rule['operator']) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'is_empty' => $actual === '',
            'not_empty' => $actual !== '',
            'contains' => str_contains($actual, $expected),
            'not_contains' => !str_contains($actual, $expected),
            'starts_with' => str_starts_with($actual, $expected),
            'ends_with' => str_ends_with($actual, $expected),
            'greater_than' => self::numeric($actual) && self::numeric($expected) && (float)$actual > (float)$expected,
            'less_than' => self::numeric($actual) && self::numeric($expected) && (float)$actual < (float)$expected,
            default => false,
        };
    }
    public static function visible(string $fieldId, array $rules, array $values, ?array $fields=null): bool {
        foreach ($rules as $rule) {
            if (($rule['field_id'] ?? null) !== $fieldId) { continue; }
            if($fields!==null){$allowed=[];foreach($fields as $field)if(self::sourceAllowed($field))$allowed[]=$field['id'];foreach($rule['groups'] ?? [[$rule]] as $group)foreach($group as $clause)if(!in_array($clause['source_field_id'] ?? '',$allowed,true))return false;}
            $matches = isset($rule['groups'])
                ? array_reduce($rule['groups'], static fn(bool $any, array $group): bool => $any || array_reduce($group, static fn(bool $all, array $condition): bool => $all && self::condition($condition, $values), true), false)
                : self::condition($rule, $values);
            if (($rule['action'] ?? 'show') === 'hide' ? $matches : !$matches) { return false; }
        }
        return true;
    }
}
