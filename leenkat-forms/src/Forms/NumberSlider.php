<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

final class NumberSlider {
    public static function validValue(string $value, array $settings): bool {
        if (!is_numeric($value) || !is_finite((float)$value)) { return false; }
        $min = (float)($settings['min'] ?? 0);
        $max = (float)($settings['max'] ?? 100);
        $step = (float)($settings['step'] ?? 1);
        if ($step <= 0 || (float)$value < $min || (float)$value > $max) { return false; }
        $steps = ((float)$value - $min) / $step;
        return abs($steps - round($steps)) < 0.000001;
    }
    public static function initial(array $settings): string {
        $value = (string)($settings['default_value'] ?? $settings['min'] ?? 0);
        return self::validValue($value, $settings) ? $value : (string)($settings['min'] ?? 0);
    }
}
