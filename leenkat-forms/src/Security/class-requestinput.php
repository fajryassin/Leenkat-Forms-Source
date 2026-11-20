<?php
/**
 * Typed boundaries for WordPress-slash-encoded administration input.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

/** Reject unexpected shapes before coercion or use by an administration handler. */
final class RequestInput {
	/**
	 * Normalize an administration nonce before WordPress can coerce its type.
	 *
	 * Calling handlers still verify the original action with check_admin_referer().
	 *
	 * @return void
	 */
	public static function nonce_request(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Parse the nonce type before WordPress's verifier casts it to a string. The caller immediately performs its action-specific check_admin_referer(), and this parser changes no protected data.
		if ( ! is_string( $_REQUEST['_wpnonce'] ?? null ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Retain the unslashed original only for equality with the sanitized nonce on the next line. It is never used for authorization or output.
		$original = wp_unslash( $_REQUEST['_wpnonce'] );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce normalization precedes the owning handler's action-specific check_admin_referer() and performs no protected action.
		$clean = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
		if ( $original !== $clean ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
		}
		$_REQUEST['_wpnonce'] = $clean;
	}

	/**
	 * Read plain text from a normalized, unslashed input map.
	 *
	 * @param array  $input Normalized input.
	 * @param string $key Input name.
	 * @return string Sanitized scalar text, or an empty string.
	 */
	public static function text( array $input, string $key ): string {
		return is_string( $input[ $key ] ?? null ) ? sanitize_text_field( $input[ $key ] ) : '';
	}

	/**
	 * Read and unslash a scalar query value exactly once.
	 *
	 * @param string $key Query parameter.
	 * @return string Sanitized text.
	 */
	public static function get( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameters select views and filters. Mutating handlers independently check capabilities and their action-specific nonces.
		return is_string( $_GET[ $key ] ?? null ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Read and unslash a scalar form value exactly once.
	 *
	 * @param string $key Form parameter.
	 * @return string Sanitized text.
	 */
	public static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This parser grants no authorization. Each calling administrative action enforces its dedicated nonce and capability before mutation.
		return is_string( $_POST[ $key ] ?? null ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Read an exact scalar control value without turning malformed input into approval.
	 *
	 * @param string $key Input name.
	 * @param bool   $post Whether to read POST instead of GET.
	 * @return string Sanitized input only when sanitization leaves it unchanged.
	 */
	public static function control( string $key, bool $post = false ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This typed boundary unslashes and sanitizes the scalar below, preserving the original for an exact comparison that rejects input changed by sanitization. Parsing cannot authorize mutations. The owning handler enforces its capability and action-specific nonce.
		$value = $post ? ( $_POST[ $key ] ?? null ) : ( $_GET[ $key ] ?? null );
		if ( null === $value ) {
			return '';
		}
		if ( ! is_string( $value ) ) {
			return '!invalid';
		}
		$plain = wp_unslash( $value );
		$clean = sanitize_text_field( $plain );
		return $plain === $clean ? $clean : '!invalid';
	}

	/**
	 * Validate a positive integer without coercing arrays, floats or overflow.
	 *
	 * @param mixed $value Unslashed scalar input.
	 * @return int Valid identifier, or zero.
	 */
	public static function id( mixed $value ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{1,19}$/D', $value ) || sanitize_text_field( $value ) !== $value ) {
			return 0;
		}
		$normalized = ltrim( $value, '0' );
		return '' !== $normalized && ( strlen( $normalized ) < strlen( (string) PHP_INT_MAX ) || ( strlen( $normalized ) === strlen( (string) PHP_INT_MAX ) && strcmp( $normalized, (string) PHP_INT_MAX ) <= 0 ) ) ? (int) $normalized : 0;
	}

	/**
	 * Read an administration identifier from the query or POST body.
	 *
	 * @param string $key Parameter name.
	 * @param bool   $post Whether to read POST instead of GET.
	 * @return int Valid identifier, or zero.
	 */
	public static function identifier( string $key, bool $post = false ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This typed boundary unslashes and sanitizes the scalar below, preserving the original for an exact comparison that rejects input changed by sanitization. Parsing is side-effect free. The owning handler verifies authorization and the action nonce. Scalar text is unslashed, sanitized and strictly range-validated by id().
		$value = $post ? ( $_POST[ $key ] ?? null ) : ( $_GET[ $key ] ?? null );
		return self::id( is_string( $value ) ? wp_unslash( $value ) : null );
	}

	/**
	 * Read a bounded list of decimal form identifiers or selection indices.
	 *
	 * @param string $key POST parameter.
	 * @param bool   $zero Whether a zero-based import index is allowed.
	 * @return array Valid integers, or an empty array for malformed input.
	 */
	public static function ids( string $key, bool $zero = false ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The calling import, export or bulk handler enforces its capability and action-specific nonce. Every list element is type-checked and validated by id(), which rejects changed text, malformed decimals and overflow before use.
		$input = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
		if ( ! is_array( $input ) || ! array_is_list( $input ) || count( $input ) > ( $zero ? 50 : 100 ) ) {
			return array();
		}
		$output = array();
		foreach ( $input as $value ) {
			$id = self::id( $value );
			if ( ! $id && ! ( $zero && '0' === $value ) ) {
				return array();
			}
			$output[] = $id;
		}
		return $zero ? $output : array_values( array_unique( $output ) );
	}

	/**
	 * Collect only supported entry-filter parameters as unslashed text.
	 *
	 * @param bool $post Whether to read POST instead of GET.
	 * @return array Normalized filters, validated against field and date schemas by EntryListFilters.
	 */
	public static function filters( bool $post = false ): array {
		$output = array();
		foreach ( array( 's', 'search_field', 'search_operator', 'date_from', 'date_to', 'days', 'status', 'flag', 'orderby', 'order' ) as $key ) {
			$output[ $key ] = 's' === $key ? ( $post ? self::post( $key ) : self::get( $key ) ) : self::control( $key, $post );
		}
		return $output;
	}
}
