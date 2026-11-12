<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Bounds apply equally to imported definitions, drafts and publication. */
final class CompositeSettings {
    public static function validate(array $field, int $index): array {
        $s = is_array($field['settings'] ?? null) ? $field['settings'] : [];
        $errors = [];
        $bad = static function (string $name) use (&$errors, $index): void { $errors[] = sprintf(/* translators: 1: name; 2: index. */ __('Invalid %1$s at %2$s.', 'leenkat-forms'), $name, $index); };
        foreach (['datetime_format'=>['date_time','date','time'],'date_type'=>['datepicker','dropdown'],'date_format'=>['m/d/Y','d/m/Y','Y-m-d','F j, Y'],'time_format'=>['12','24'],'address_scheme'=>['us','international'],'upload_style'=>['modern','classic']] as $key=>$allowed) {
            if (isset($s[$key]) && !in_array($s[$key], $allowed, true)) { $bad($key); }
        }
        foreach (['disable_past_dates','limit_days','limit_hours','media_library'] as $key) { if (isset($s[$key]) && !is_bool($s[$key])) { $bad($key); } }
        foreach (['date_placeholder','time_placeholder'] as $key) { if (isset($s[$key]) && (!is_string($s[$key]) || strlen($s[$key]) > 200)) { $bad($key); } }
        if (isset($s['time_interval']) && !in_array($s['time_interval'], [5,10,15,30,60], true)) { $bad(__('time interval','leenkat-forms')); }
        if (isset($s['allowed_days']) && (!is_array($s['allowed_days']) || !array_is_list($s['allowed_days']) || count($s['allowed_days']) > 7 || count(array_unique($s['allowed_days'], SORT_REGULAR)) !== count($s['allowed_days']) || array_filter($s['allowed_days'], static fn($day): bool => !is_int($day) || $day < 0 || $day > 6))) { $bad(__('allowed days','leenkat-forms')); }
        if (!empty($s['limit_days']) && isset($s['allowed_days']) && $s['allowed_days'] === []) { $bad(__('allowed days','leenkat-forms')); }
        foreach (['time_min','time_max'] as $key) { if (isset($s[$key]) && (!is_string($s[$key]) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $s[$key]))) { $bad($key); } }
        if (!empty($s['limit_hours']) && is_string($s['time_min'] ?? '09:00') && is_string($s['time_max'] ?? '17:00') && ($s['time_min'] ?? '09:00') > ($s['time_max'] ?? '17:00')) { $bad(__('hour range','leenkat-forms')); }
        if (isset($s['address_parts'])) {
            if (!is_array($s['address_parts']) || array_diff(array_keys($s['address_parts']), ['address1','address2','city','state','postal','country'])) { $bad(__('address parts','leenkat-forms')); }
            else foreach ($s['address_parts'] as $part=>$config) {
                if (!is_array($config) || array_diff(array_keys($config), ['placeholder','default_value','hidden'])) { $bad(__('address part','leenkat-forms')); continue; }
                foreach (['placeholder','default_value'] as $key) { if (isset($config[$key]) && (!is_string($config[$key]) || strlen($config[$key]) > 250)) { $bad('address '.$key); } }
                if (isset($config['hidden']) && (!is_bool($config['hidden']) || !in_array($part, ['address2','postal','country'], true))) { $bad(__('address visibility','leenkat-forms')); }
                $options = AddressField::options((string)$part, $s);
                if ($options && !empty($config['default_value']) && (!is_string($config['default_value']) || !isset($options[$config['default_value']]))) { $bad(__('address default','leenkat-forms')); }
            }
        }
        if (isset($s['max_files']) && (!is_int($s['max_files']) || $s['max_files'] < 1 || $s['max_files'] > 10)) { $bad(__('maximum files','leenkat-forms')); }
        if (isset($s['max_file_size']) && ((!is_float($s['max_file_size']) && !is_int($s['max_file_size'])) || !is_finite((float)$s['max_file_size']) || $s['max_file_size'] < 0 || $s['max_file_size'] > 50)) { $bad(__('maximum file size','leenkat-forms')); }
        if (isset($s['allowed_extensions']) && (!is_string($s['allowed_extensions']) || strlen($s['allowed_extensions']) > 300 || !preg_match('/^(?:\s*\.?[a-zA-Z0-9]{1,10}\s*,?\s*)*$/D', $s['allowed_extensions']))) { $bad(__('file extensions','leenkat-forms')); }
        elseif (!empty($s['allowed_extensions'])) {
            $extensions = preg_split('/[\s,]+/', strtolower($s['allowed_extensions']), -1, PREG_SPLIT_NO_EMPTY);
            if (array_diff(array_map(static fn(string $ext): string => ltrim($ext, '.'), $extensions), \LeenkatForms\Submissions\FileUploads::EXTENSIONS)) { $bad(__('unsupported file extensions','leenkat-forms')); }
        }
        if (($field['type'] ?? '') === 'section_divider' && (!empty($field['required']) || !empty($field['searchable']))) { $bad(__('section divider flags','leenkat-forms')); }
        return $errors;
    }
}
