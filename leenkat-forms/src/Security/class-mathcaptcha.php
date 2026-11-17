<?php
/**
 * Issuance and verification of local math challenges.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

/** Short-lived, signed arithmetic challenge for the public CAPTCHA field. */
final class MathCaptcha {
	private const LIFETIME = 600;

	/**
	 * Create a math challenge for a public form.
	 *
	 * @param int    $formId  Form identifier.
	 * @param string $fieldId Field id for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function issue( int $formId, string $fieldId ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fieldId and its method binding.
		$a         = random_int( 1, 9 );
		$b         = random_int( 1, 9 );
		$payload   = implode( '|', array( $formId, $fieldId, $a, $b, time() + self::LIFETIME, bin2hex( random_bytes( 8 ) ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fieldId and its method binding.
		$signature = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		return array(
			'first'  => $a,
			'second' => $b,
			'token'  => self::encode( $payload . '|' . $signature ),
		);
	}

	/**
	 * Verify an answer against its signed math challenge.
	 *
	 * @param int    $formId  Form identifier.
	 * @param string $fieldId Field id for this operation.
	 * @param mixed  $answer  Answer for this operation.
	 * @param mixed  $token   Token supplied for validation or encoding.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function verify( int $formId, string $fieldId, mixed $answer, mixed $token ): bool { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fieldId and its method binding.
		if ( ! is_string( $answer ) || ! is_string( $token ) || strlen( $token ) > 300 || ! preg_match( '/^\d{1,2}$/D', trim( $answer ) ) ) {
			return false; }
		$decoded = self::decode( $token );
		if ( false === $decoded ) {
			return false; }
		$parts = explode( '|', $decoded );
		if ( 7 !== count( $parts ) ) {
			return false; }
		[$stored_form, $stored_field, $a, $b, $expiry, $nonce, $signature] = $parts;
		if ( (string) $formId !== $stored_form || $fieldId !== $stored_field || ! ctype_digit( $a ) || ! ctype_digit( $b ) || ! ctype_digit( $expiry ) || ! preg_match( '/^[a-f0-9]{16}$/D', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fieldId and its method binding.
			return false; }
		if ( (int) $a < 1 || (int) $a > 9 || (int) $b < 1 || (int) $b > 9 || (int) $expiry < time() || (int) $expiry > time() + self::LIFETIME ) {
			return false; }
		$payload = implode( '|', array_slice( $parts, 0, 6 ) );
		return hash_equals( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ), $signature ) && (int) trim( $answer ) === (int) $a + (int) $b;
	}

	/**
	 * Encode the math challenge data for transport.
	 *
	 * @param string $value Value being processed.
	 * @return string Result for the requested operation.
	 */
	private static function encode( string $value ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64url transports the signed challenge and preserves verification of already-issued tokens.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); }
	/**
	 * Decode a math challenge payload.
	 *
	 * @param string $value Value being processed.
	 * @return string|false Result for the requested operation.
	 */
	private static function decode( string $value ): string|false {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) {
			return false; }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Base64url transports the signed challenge and preserves verification of already-issued tokens.
		return base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true );
	}
}
