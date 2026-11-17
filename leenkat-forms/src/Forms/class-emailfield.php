<?php
/**
 * Address validation for form email values.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/**
 * Address validation for form email values.
 */
final class EmailField {
	/**
	 * Check whether the supplied email address satisfies the field policy.
	 *
	 * @param string $email    Email for this operation.
	 * @param array  $settings Settings used by the current operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function allowed( string $email, array $settings ): bool {
		$mode = $settings['email_filter'] ?? 'none';
		if ( 'none' === $mode || '' === $email ) {
			return true; }
		$match = false;
		foreach ( ( $settings['email_patterns'] ?? array() ) as $pattern ) {
			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/iD';
			if ( 1 === preg_match( $regex, $email ) ) {
				$match = true;
				break; }
		}
		return 'allow' === $mode ? $match : ! $match;
	}
}
