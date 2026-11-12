<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;
/** Canonical JSON encoding for deterministic hashes; not a persistence layer. */
final class DefinitionCodec {
    public static function encode(array $definition): string {
        $errors = DefinitionValidator::validate($definition);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception carries data to REST/JSON or an escaped HTML error boundary; HTML-escaping here would corrupt non-HTML messages.
        if ($errors !== []) { throw new \InvalidArgumentException(implode(' ', $errors)); }
        return json_encode(self::canonicalize($definition), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    public static function hash(array $definition): string { return hash('sha256', self::encode($definition)); }
    private static function canonicalize(array $value): array {
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as $key => $item) { if (is_array($item)) { $value[$key] = self::canonicalize($item); } }
        return $value;
    }
}
