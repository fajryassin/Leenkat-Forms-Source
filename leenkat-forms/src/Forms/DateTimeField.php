<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Transport uses ISO dates and 24-hour times regardless of the displayed format. */
final class DateTimeField {
    public static function parts(array $settings): array {
        return match ($settings['datetime_format'] ?? 'date_time') { 'date'=>['date'], 'time'=>['time'], default=>['date','time'] };
    }
    public static function valid(string $part, string $value, array $settings): bool {
        if ($value === '') { return true; }
        if ($part === 'date') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$date || $date->format('Y-m-d') !== $value) { return false; }
            if (!empty($settings['disable_past_dates']) && $value < (function_exists('current_datetime') ? current_datetime()->format('Y-m-d') : gmdate('Y-m-d'))) { return false; }
            return empty($settings['limit_days']) || in_array((int)$date->format('w'), $settings['allowed_days'] ?? [1,2,3,4,5], true);
        }
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value)) { return false; }
        [$hour,$minute] = array_map('intval', explode(':', $value));
        if (($hour * 60 + $minute) % (int)($settings['time_interval'] ?? 30) !== 0) { return false; }
        return empty($settings['limit_hours']) || ($value >= ($settings['time_min'] ?? '09:00') && $value <= ($settings['time_max'] ?? '17:00'));
    }
}
