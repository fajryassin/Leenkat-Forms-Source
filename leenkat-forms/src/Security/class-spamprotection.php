<?php
/**
 * Country and content policies for protecting public forms.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

/**
 * Country and content policies for protecting public forms.
 */
final class SpamProtection {
	/**
	 * A reason means spam; a WP_Error means the submission must be rejected without storing.
	 *
	 * @param array      $definition      Form definition being processed.
	 * @param array      $values          Values from the current submission.
	 * @param array|null $matchedKeywords Matched keywords for this operation.
	 */
	public static function reason( array $definition, array $values, ?array &$matchedKeywords = null ): ?string { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $matchedKeywords and its method binding.
		$s               = \LeenkatForms\Forms\SecuritySettings::normalize( $definition );
		$matchedKeywords = $s['keyword_enabled'] ? SpamKeywords::matches( $definition, $values ) : array(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $matchedKeywords and its method binding.
		if ( $matchedKeywords ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $matchedKeywords and its method binding.
			return 'Keyword Filter';
		}
		return null;
	}
	/**
	 * Evaluate the visitor address against the configured country policy.
	 *
	 * @param array $settings Settings used by the current operation.
	 * @param array $server   Server for this operation.
	 * @return bool|\WP_Error Result for the requested operation.
	 */
	public static function countryCheck( array $settings, array $server ): bool|\WP_Error { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! $settings['country_enabled'] ) {
			return true;
		}$ip = CountryLookup::clientIp( $server );
		if ( in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		$country = CountryLookup::country( $ip );
		if ( null === $country ) {
			return new \WP_Error( 'leenfo_country_unavailable', __( 'We could not verify your country. Please try again later.', 'leenkat-forms' ), array( 'status' => 503 ) );
		}
		$selected = in_array( $country, $settings['country_codes'], true );
		return 'allow' === $settings['country_action'] ? $selected : ! $selected;
	}
	/**
	 * Build a submission error for a blocked request.
	 *
	 * @param array  $settings        Settings used by the current operation.
	 * @param string $reason          Reason for this operation.
	 * @param array  $matchedKeywords Matched keywords for this operation.
	 * @return \WP_Error Result for the requested operation.
	 */
	public static function error( array $settings, string $reason, array $matchedKeywords = array() ): \WP_Error { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $matchedKeywords and its method binding.
		$message = match ( $reason ) {
			'Country Filter'=>$settings['country_message'], 'Keyword Filter'=>$settings['keyword_message'], default=>__( 'Anti-spam verification failed. Please try again later.', 'leenkat-forms' )};
		if ( 'Keyword Filter' === $reason ) {
			$words = array();
			foreach ( array_slice( $matchedKeywords, 0, 10 ) as $word ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $matchedKeywords and its method binding.
				if ( is_string( $word ) ) {
					$word = sanitize_text_field( $word );
					if ( '' !== $word ) {
						$words[] = '“' . $word . '”';
					}
				}
			}
			if ( $words ) {
				$message .= ' ' . sprintf( /* translators: %s: matched prohibited words or phrases. */ __( 'Prohibited words or phrases: %s.', 'leenkat-forms' ), implode( ', ', $words ) );
			}
		}
		return new \WP_Error( 'leenfo_spam', wp_strip_all_tags( $message ), array( 'status' => 400 ) );
	}
}
