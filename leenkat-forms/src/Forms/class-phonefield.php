<?php
/**
 * Validation for supported phone field formats.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/**
 * Validation for supported phone field formats.
 */
final class PhoneField {
	/**
	 * Check whether the supplied phone value satisfies the field settings.
	 *
	 * @param string $value    Value being processed.
	 * @param array  $settings Settings used by the current operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function valid( string $value, array $settings ): bool {
		if ( '' === $value ) {
			return true; }
		if ( ! preg_match( '/^[+0-9().\s-]{3,40}$/D', $value ) ) {
			return false; }
		$format = $settings['phone_format'] ?? 'legacy';
		$digits = preg_replace( '/\D/', '', $value );
		if ( 'us' === $format ) {
			return (bool) preg_match( '/^(?:1)?[2-9][0-9]{2}[2-9][0-9]{6}$/D', $digits ); }
		if ( in_array( $format, array( 'smart', 'international' ), true ) ) {
			return (bool) preg_match( '/^\+[1-9][0-9]{6,14}$/D', preg_replace( '/[().\s-]/', '', $value ) ); }
		return true;
	}
}
