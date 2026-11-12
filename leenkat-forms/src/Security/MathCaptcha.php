<?php
declare(strict_types=1);
namespace LeenkatForms\Security;

/** Short-lived, signed arithmetic challenge for the public CAPTCHA field. */
final class MathCaptcha {
    private const LIFETIME = 600;

    public static function issue(int $formId, string $fieldId): array {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $payload = implode('|', [$formId, $fieldId, $a, $b, time() + self::LIFETIME, bin2hex(random_bytes(8))]);
        $signature = hash_hmac('sha256', $payload, wp_salt('auth'));
        return ['first' => $a, 'second' => $b, 'token' => self::encode($payload . '|' . $signature)];
    }

    public static function verify(int $formId, string $fieldId, mixed $answer, mixed $token): bool {
        if (!is_string($answer) || !is_string($token) || strlen($token) > 300 || !preg_match('/^\d{1,2}$/D', trim($answer))) { return false; }
        $decoded = self::decode($token);
        if ($decoded === false) { return false; }
        $parts = explode('|', $decoded);
        if (count($parts) !== 7) { return false; }
        [$storedForm, $storedField, $a, $b, $expiry, $nonce, $signature] = $parts;
        if ((string)$formId !== $storedForm || $fieldId !== $storedField || !ctype_digit($a) || !ctype_digit($b) || !ctype_digit($expiry) || !preg_match('/^[a-f0-9]{16}$/D', $nonce) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) { return false; }
        if ((int)$a < 1 || (int)$a > 9 || (int)$b < 1 || (int)$b > 9 || (int)$expiry < time() || (int)$expiry > time() + self::LIFETIME) { return false; }
        $payload = implode('|', array_slice($parts, 0, 6));
        return hash_equals(hash_hmac('sha256', $payload, wp_salt('auth')), $signature) && (int)trim($answer) === (int)$a + (int)$b;
    }

    private static function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private static function decode(string $value): string|false {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) { return false; }
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
