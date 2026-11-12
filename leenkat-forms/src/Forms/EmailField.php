<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

final class EmailField {
    public static function allowed(string $email, array $settings): bool {
        $mode = $settings['email_filter'] ?? 'none';
        if ($mode === 'none' || $email === '') { return true; }
        $match = false;
        foreach (($settings['email_patterns'] ?? []) as $pattern) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/iD';
            if (preg_match($regex, $email) === 1) { $match = true; break; }
        }
        return $mode === 'allow' ? $match : !$match;
    }
}
