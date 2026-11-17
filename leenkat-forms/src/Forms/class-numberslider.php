<?php
/**
 * Range and default values for slider fields.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/**
 * Range and default values for slider fields.
 */
final class NumberSlider {
	/**
	 * Check whether a submitted slider value matches its bounds and step.
	 *
	 * @param string $value    Value being processed.
	 * @param array  $settings Settings used by the current operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function validValue( string $value, array $settings ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) {
			return false; }
		$min  = (float) ( $settings['min'] ?? 0 );
		$max  = (float) ( $settings['max'] ?? 100 );
		$step = (float) ( $settings['step'] ?? 1 );
		if ( $step <= 0 || (float) $value < $min || (float) $value > $max ) {
			return false; }
		$steps = ( (float) $value - $min ) / $step;
		return abs( $steps - round( $steps ) ) < 0.000001;
	}
	/**
	 * Resolve the starting value for a slider field.
	 *
	 * @param array $settings Settings used by the current operation.
	 * @return string Result for the requested operation.
	 */
	public static function initial( array $settings ): string {
		$value = (string) ( $settings['default_value'] ?? $settings['min'] ?? 0 );
		return self::validValue( $value, $settings ) ? $value : (string) ( $settings['min'] ?? 0 );
	}
}
