<?php
/**
 * Name field component and presentation definitions.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/**
 * Name field component and presentation definitions.
 */
final class NameField {
	/**
	 * Return the components for the selected name field layout.
	 *
	 * @param array $settings Settings used by the current operation.
	 * @return array Result for the requested operation.
	 */
	public static function parts( array $settings ): array {
		return match ( $settings['name_format'] ?? 'first_last' ) {
			'simple' => array( 'first' ),
			'first_middle_last' => array( 'first', 'middle', 'last' ),
			default => array( 'first', 'last' ),
		};
	}
}
