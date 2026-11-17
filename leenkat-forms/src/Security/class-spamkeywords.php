<?php
/**
 * Configured keywords used for submission spam checks.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

/**
 * Configured keywords used for submission spam checks.
 */
final class SpamKeywords {
	public const OPTION = 'leenfo_spam_keywords';
	/**
	 * Load the configured spam keywords.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function words(): array {
		$words = get_option( self::OPTION, array() );
		return is_array( $words ) ? $words : array(); }
	/**
	 * Normalize text before checking it for spam keywords.
	 *
	 * @param string $text Text for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function normalizeText( string $text ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $text, \Normalizer::FORM_C );
			$text       = $normalized ? $normalized : $text;
		}
		return preg_replace( '/\s+/u', ' ', preg_replace( '/\p{Cf}/u', '', $text ) ?? $text ) ?? $text;
	}
	/**
	 * Check whether the submitted text matches a blocked keyword.
	 *
	 * @param array      $definition Form definition being processed.
	 * @param array      $values     Values from the current submission.
	 * @param array|null $words      Words for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function blocked( array $definition, array $values, ?array $words = null ): bool {
		return array() !== self::matches( $definition, $values, $words, 1 );
	}
	/**
	 * Bounded, distinct excerpts actually matched in eligible submitted answers.
	 *
	 * @param array      $definition Form definition being processed.
	 * @param array      $values     Values from the current submission.
	 * @param array|null $words      Words for this operation.
	 * @param int        $limit      Maximum number or size to process.
	 */
	public static function matches( array $definition, array $values, ?array $words = null, int $limit = 10 ): array {
		$limit   = max( 1, min( 10, $limit ) );
		$words ??= self::words();
		if ( ! $words ) {
			return array();
		}$texts = array();
		foreach ( $definition['fields'] ?? array() as $field ) {
			if ( in_array( $field['type'], array( 'text', 'textarea', 'name', 'email', 'address', 'url' ), true ) && is_string( $values[ $field['id'] ] ?? null ) ) {
				$texts[] = self::normalizeText( $values[ $field['id'] ] );
			}
		}
		$needles = array();
		foreach ( $words as $word ) {
			if ( is_string( $word ) && '' !== trim( $word ) ) {
				$needles[] = preg_quote( self::normalizeText( trim( $word ) ), '/' );
			}
		}
		// Bound each literal alternation, and do not join phrases across separate answers.
		$text    = implode( "\0", $texts );
		$matches = array();
		foreach ( array_chunk( $needles, 100 ) as $chunk ) {
			while ( $chunk ) {
				$pattern = '/(?<![\p{L}\p{N}_])(?:' . implode( '|', $chunk ) . ')(?![\p{L}\p{N}_])/iu';
				if ( 1 !== preg_match( $pattern, $text, $hit ) ) {
					break;
				}
				$duplicate = false;
				foreach ( $matches as $found ) {
					if ( 1 === preg_match( '/\A' . preg_quote( $found, '/' ) . '\z/iu', $hit[0] ) ) {
						$duplicate = true;
									break;}
				}
				if ( ! $duplicate ) {
					$matches[] = $hit[0];
					if ( count( $matches ) >= $limit ) {
						return $matches;
					}
				}
				// Remove matched alternatives rather than collecting unbounded repeated hits.
				$chunk = array_values( array_filter( $chunk, static fn( $literal )=>1 !== preg_match( '/\A' . $literal . '\z/iu', $hit[0] ) ) );
			}
		}
		return $matches;
	}
	/**
	 * Register the administration hooks for keyword settings.
	 */
	public static function hooks(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/spam-keywords',
					array(
						array(
							'methods'             => 'GET',
							'permission_callback' => static fn(): bool=>current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ),
							'callback'            => static fn(): \WP_REST_Response=>new \WP_REST_Response( self::state() ),
						),
						array(
							'methods'             => 'POST',
							'permission_callback' => static fn(): bool=>current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) && current_user_can( 'manage_options' ),
							'callback'            => array( self::class, 'save' ),
						),
					)
				);
			}
		);
	}
	/**
	 * Return the spam keyword settings for the form builder.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function state(): array {
		$words = self::words();
		return array(
			'words'    => $words,
			'revision' => hash( 'sha256', wp_json_encode( $words ) ),
			'can_edit' => current_user_can( 'manage_options' ),
		);}
	/**
	 * Validate and persist the submitted keyword settings.
	 *
	 * @param \WP_REST_Request $request Incoming WordPress request.
	 * @return \WP_REST_Response|\WP_Error Result for the requested operation.
	 */
	public static function save( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( strlen( $request->get_body() ) > 65536 ) {
			return new \WP_Error( 'leenfo_too_large', __( 'Keyword list is too large.', 'leenkat-forms' ), array( 'status' => 413 ) );
		}
		$data  = $request->get_json_params();
		$words = $data['words'] ?? null;
		if ( ! is_array( $words ) || ! array_is_list( $words ) || count( $words ) > 1000 ) {
			return new \WP_Error( 'leenfo_keywords', __( 'Enter at most 1000 words or phrases.', 'leenkat-forms' ), array( 'status' => 400 ) );
		}
		$clean = array();
		foreach ( $words as $word ) {
			if ( ! is_string( $word ) || strlen( $word ) > 200 || str_contains( $word, "\n" ) || str_contains( $word, "\r" ) ) {
				return new \WP_Error( 'leenfo_keywords', __( 'Use one short word or phrase on each line.', 'leenkat-forms' ), array( 'status' => 400 ) );
			}$word = trim( self::normalizeText( sanitize_text_field( $word ) ) );
			if ( '' !== $word ) {
				$clean[ $word ] = $word;
			}
		}$clean = array_values( $clean );
		$lock   = 'leenfo_spam_keywords_lock';
		if ( ! add_option( $lock, (string) ( time() + 10 ), '', false ) ) {
			global $wpdb;
			$expiry = (int) get_option( $lock, 0 );
			if ( $expiry > 0 && $expiry < time() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock, (string) $expiry ) );
				wp_cache_delete( $lock, 'options' );
			}return new \WP_Error( 'leenfo_busy', __( 'The keyword list is being saved. Please try again.', 'leenkat-forms' ), array( 'status' => 409 ) );}
		try {
			if ( ! is_string( $data['revision'] ?? null ) || ! hash_equals( self::state()['revision'], $data['revision'] ) ) {
				return new \WP_Error( 'leenfo_conflict', __( 'The keyword list changed in another session. Reload it before saving.', 'leenkat-forms' ), array( 'status' => 409 ) );
			}if ( self::words() !== $clean && ! update_option( self::OPTION, $clean, false ) ) {
				return new \WP_Error( 'leenfo_storage', __( 'The keyword list could not be saved.', 'leenkat-forms' ), array( 'status' => 503 ) );
			}return new \WP_REST_Response( self::state() );
		} finally {
			delete_option( $lock );}
	}
}
