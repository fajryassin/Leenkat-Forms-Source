<?php
declare(strict_types=1);
namespace LeenkatForms\Security;
final class Capabilities {
    public const MANAGE_FORMS = 'lkf_manage_forms';
    public const VIEW_ENTRIES = 'lkf_view_entries';
    public const MANAGE_SETTINGS = 'lkf_manage_settings';
    public static function all(): array {
        return [self::MANAGE_FORMS, self::VIEW_ENTRIES, self::MANAGE_SETTINGS];
    }
    public static function allowed(string $capability): bool {
        return in_array($capability, self::all(), true) && current_user_can($capability);
    }
}
