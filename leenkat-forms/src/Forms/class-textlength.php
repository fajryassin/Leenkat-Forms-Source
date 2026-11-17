<?php
/**
 * Text-length settings and validation for form fields.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Unicode scalar values, with CRLF/CR canonicalized to LF; no grapheme/NFC folding. */
final class TextLength {
	/**
	 * Normalize the supplied text-length settings.
	 *
	 * @param string $value Value being processed.
	 * @return string Result for the requested operation.
	 */
	public static function normalize( string $value ): string {
		return trim( str_replace( array( "\r\n", "\r" ), "\n", $value ), " \t\n\r\0\x0B" ); }
	/**
	 * Count the length of a submitted text value.
	 *
	 * @param string $value Value being processed.
	 * @param string $unit  Unit for this operation.
	 * @return int Result for the requested operation.
	 */
	public static function count( string $value, string $unit = 'characters' ): int {
		$value = self::normalize( $value );
		if ( 'words' === $unit ) {
			$words = preg_split( '/[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY );
			return $words ? count( $words ) : 0;
		}
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value, 'UTF-8' );
		}
		$matches = preg_match_all( '/./us', $value );
		return $matches ? $matches : 0;
	}
	/**
	 * Check whether the supplied value meets the text-length policy.
	 *
	 * @param string $value    Value being processed.
	 * @param array  $settings Settings used by the current operation.
	 * @param int    $maximum  Maximum for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function valid( string $value, array $settings, int $maximum ): bool {
		if ( 1 !== preg_match( '//u', $value ) || strlen( $value ) > 4 * $maximum || self::count( $value ) > $maximum ) {
			return false;
		}
		return empty( $settings['limit_length_enabled'] ) || self::count( $value, (string) ( $settings['length_unit'] ?? 'characters' ) ) <= (int) ( $settings['max_length'] ?? 100 );
	}
	/**
	 * Build input attributes for the configured text-length limit.
	 *
	 * @param array $settings Settings used by the current operation.
	 * @param int   $maximum  Maximum for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function attributes( array $settings, int $maximum = 1000 ): string {
		$limit      = (int) ( $settings['max_length'] ?? 100 );
		$words      = ! empty( $settings['limit_length_enabled'] ) && ( $settings['length_unit'] ?? 'characters' ) === 'words';
		$characters = ! empty( $settings['limit_length_enabled'] ) && ! $words ? min( $maximum, $limit ) : $maximum;
		return ' data-lkf-max-chars="' . $characters . '" data-lkf-max-bytes="' . ( 4 * $maximum ) . '"' . ( $words ? ' data-lkf-max-words="' . $limit . '"' : '' );
	}
}
