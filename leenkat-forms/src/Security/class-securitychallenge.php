<?php
/**
 * Short-lived challenge endpoints and submission verification.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

use LeenkatForms\Forms\Publisher;
use LeenkatForms\Forms\SecuritySettings;

/**
 * Short-lived challenge endpoints and submission verification.
 */
final class SecurityChallenge {
	public const LIFETIME = 86400;
	/**
	 * Register the challenge REST endpoints.
	 */
	public static function hooks(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'leenkat-forms/v1',
					'/forms/(?P<id>\d+)/security-token',
					array(
						'methods'             => 'GET',
						'permission_callback' => '__return_true',
						'callback'            => array( self::class, 'endpoint' ),
					)
				);
			}
		);
	}
	/**
	 * Issue a challenge for an eligible public form request.
	 *
	 * @param int      $formId    Form identifier.
	 * @param int      $versionId Version id for this operation.
	 * @param bool     $browser   Browser for this operation.
	 * @param int|null $now       Now for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function issue( int $formId, int $versionId, bool $browser = false, ?int $now = null ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $versionId and its method binding.
		$data = array(
			'f' => $formId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
			'v' => $versionId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $versionId and its method binding.
			't' => $now ?? time(),
			'n' => bin2hex( random_bytes( 16 ) ),
			'h' => 'contact_' . bin2hex( random_bytes( 6 ) ),
			'b' => $browser,
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64url transports the signed challenge and preserves the existing token format.
		$encoded = rtrim( strtr( base64_encode( wp_json_encode( $data ) ), '+/', '-_' ), '=' );
		return array(
			'token'    => $encoded . '.' . hash_hmac( 'sha256', 'lkf-security|' . $encoded, wp_salt( 'auth' ) ),
			'honeypot' => $data['h'],
		);
	}
	/**
	 * Verify the submitted challenge before processing the form.
	 *
	 * @param mixed    $token     Token supplied for validation or encoding.
	 * @param int      $formId    Form identifier.
	 * @param int      $versionId Version id for this operation.
	 * @param int|null $now       Now for this operation.
	 * @return array|null Result for the requested operation.
	 */
	public static function verify( mixed $token, int $formId, int $versionId, ?int $now = null ): ?array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $versionId and its method binding.
		if ( ! is_string( $token ) || strlen( $token ) > 600 || ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $match ) || ! hash_equals( hash_hmac( 'sha256', 'lkf-security|' . $match[1], wp_salt( 'auth' ) ), $match[2] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Base64url transports the signed challenge and preserves the existing token format.
		$json  = base64_decode( strtr( $match[1], '-_', '+/' ), true );
		$data  = is_string( $json ) ? json_decode( $json, true ) : null;
		$now ??= time();
		if ( ! is_array( $data ) || ( $data['f'] ?? null ) !== $formId || ( $data['v'] ?? null ) !== $versionId || ! is_int( $data['t'] ?? null ) || $data['t'] > $now || $now - $data['t'] > self::LIFETIME || ! is_bool( $data['b'] ?? null ) || ! is_string( $data['h'] ?? null ) || ! preg_match( '/^contact_[a-f0-9]{12}$/D', $data['h'] ) || ! is_string( $data['n'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $data['n'] ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $versionId and its method binding.
			return null;
		}
		return $data;
	}
	/**
	 * Validate the supplied challenge against its expected context.
	 *
	 * @param array    $settings  Settings used by the current operation.
	 * @param array    $data      Input data for this operation.
	 * @param int      $formId    Form identifier.
	 * @param int      $versionId Version id for this operation.
	 * @param int|null $now       Now for this operation.
	 * @return array|\WP_Error Result for the requested operation.
	 */
	public static function check( array $settings, array $data, int $formId, int $versionId, ?int $now = null ): array|\WP_Error { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $versionId and its method binding.
		if ( ! $settings['modern'] && ! $settings['min_time'] ) {
			return array( 'trap' => false );
		}
		$proof = self::verify( $data['security_token'] ?? null, $formId, $versionId, $now ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $versionId and its method binding.
		if ( ! $proof || ( $settings['modern'] && ! $proof['b'] ) ) {
			return new \WP_Error( 'leenfo_security', __( 'Security verification failed. Reload the form and try again.', 'leenkat-forms' ), array( 'status' => 400 ) );
		}
		if ( $settings['min_time'] && ( $now ?? time() ) - $proof['t'] < $settings['seconds'] ) {
			return new \WP_Error( 'leenfo_too_fast', __( 'Please wait a little longer before submitting. We are running a quick security check.', 'leenkat-forms' ), array( 'status' => 400 ) );
		}
		$traps = $data['security_traps'] ?? array();
		if ( ! is_array( $traps ) || 1 !== count( $traps ) || ! array_key_exists( $proof['h'], $traps ) || ! is_string( $traps[ $proof['h'] ] ) || strlen( $traps[ $proof['h'] ] ) > 1000 ) {
			return new \WP_Error( 'leenfo_security', __( 'Security verification failed. Reload the form and try again.', 'leenkat-forms' ), array( 'status' => 400 ) );
		}
		return array( 'trap' => $settings['modern'] && '' !== $traps[ $proof['h'] ] );
	}
	/**
	 * Return the challenge endpoint URL.
	 *
	 * @param \WP_REST_Request $request Incoming WordPress request.
	 * @return \WP_REST_Response|\WP_Error Result for the requested operation.
	 */
	public static function endpoint( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id        = absint( $request['id'] );
		$published = $id ? Publisher::published( $id ) : null;
		if ( ! $published ) {
			return new \WP_Error( 'leenfo_unavailable', __( 'Form is unavailable.', 'leenkat-forms' ), array( 'status' => 404 ) );
		}
		if ( ! RateLimiter::allow( -$id, CountryLookup::clientIp( $_SERVER ), 40 ) ) {
			return new \WP_Error( 'leenfo_rate_limit', __( 'Please wait before trying again.', 'leenkat-forms' ), array( 'status' => 429 ) );
		}
		$settings = SecuritySettings::normalize( $published['definition'] );
		$response = new \WP_REST_Response( self::issue( $id, $published['version_id'], true ) + array( 'minimum' => $settings['min_time'] ? $settings['seconds'] : 0 ) );
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}
}
