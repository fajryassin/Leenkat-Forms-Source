<?php
/**
 * Input-mask validation and matching for text fields.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Small, independent input mask grammar: 9 digit, A ASCII letter, * ASCII alphanumeric. */
final class InputMask {
	/**
	 * Check whether the supplied input mask is supported.
	 *
	 * @param string $mask Mask for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function valid( string $mask ): bool {
		return strlen( $mask ) <= 100 && (bool) preg_match( '/^[9A* ()+.\-\/]*$/D', $mask );
	}

	/**
	 * Test the supplied value against its input mask.
	 *
	 * @param string $mask  Mask for this operation.
	 * @param string $value Value being processed.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function matches( string $mask, string $value ): bool {
		if ( ! self::valid( $mask ) ) {
			return false; }
		return (bool) preg_match( '/^' . self::pattern( $mask ) . '$/D', $value );
	}

	/**
	 * Build the match pattern for a supported input mask.
	 *
	 * @param string $mask Mask for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function pattern( string $mask ): string {
		$pattern = '';
		foreach ( str_split( $mask ) as $character ) {
			$pattern .= match ( $character ) {
				'9' => '[0-9]', 'A' => '[A-Za-z]', '*' => '[A-Za-z0-9]',
				default => preg_quote( $character, '/' ),
			};
		}
		return $pattern;
	}
}
