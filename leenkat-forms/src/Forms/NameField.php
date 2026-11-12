<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

final class NameField {
    public static function parts(array $settings): array {
        return match ($settings['name_format'] ?? 'first_last') {
            'simple' => ['first'],
            'first_middle_last' => ['first','middle','last'],
            default => ['first','last'],
        };
    }
}
