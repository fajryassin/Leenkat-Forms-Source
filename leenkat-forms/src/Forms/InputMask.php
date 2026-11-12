<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Small, independent input mask grammar: 9 digit, A ASCII letter, * ASCII alphanumeric. */
final class InputMask {
    public static function valid(string $mask): bool {
        return strlen($mask) <= 100 && (bool)preg_match('/^[9A* ()+.\-\/]*$/D', $mask);
    }

    public static function matches(string $mask, string $value): bool {
        if (!self::valid($mask)) { return false; }
        return (bool)preg_match('/^' . self::pattern($mask) . '$/D', $value);
    }

    public static function pattern(string $mask): string {
        $pattern = '';
        foreach (str_split($mask) as $character) {
            $pattern .= match ($character) {
                '9' => '[0-9]', 'A' => '[A-Za-z]', '*' => '[A-Za-z0-9]',
                default => preg_quote($character, '/'),
            };
        }
        return $pattern;
    }
}
