<?php
/**
 * Canonical WordPress capabilities for Leenkat Forms.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

/**
 * Defines the capabilities granted during installation and upgrade.
 */
final class Capabilities {
	public const MANAGE_FORMS    = 'leenfo_manage_forms';
	public const VIEW_ENTRIES    = 'leenfo_view_entries';
	public const MANAGE_SETTINGS = 'leenfo_manage_settings';
	/**
	 * List the capabilities registered by the plugin lifecycle.
	 *
	 * @return string[] Leenkat Forms capability names.
	 */
	public static function all(): array {
		return array( self::MANAGE_FORMS, self::VIEW_ENTRIES, self::MANAGE_SETTINGS );
	}
	/**
	 * Check a registered Leenkat Forms capability for the current user.
	 *
	 * @param string $capability The capability to check.
	 * @return bool Whether the current user has the capability.
	 */
	public static function allowed( string $capability ): bool {
		return in_array( $capability, self::all(), true ) && current_user_can( $capability );
	}
}
