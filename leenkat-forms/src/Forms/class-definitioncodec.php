<?php
/**
 * Stable encoding and hashing of form definitions.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Canonical JSON encoding for deterministic hashes; not a persistence layer. */
final class DefinitionCodec {
	/**
	 * Serialize the canonical form definition.
	 *
	 * @param array $definition Form definition being processed.
	 * @return string Result for the requested operation.
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function encode( array $definition ): string {
		$errors = DefinitionValidator::validate( $definition );
		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception carries data to REST/JSON or an escaped HTML error boundary; HTML-escaping here would corrupt non-HTML messages.
			throw new \InvalidArgumentException( implode( ' ', $errors ) ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Canonical draft and publication digests need the exact JSON bytes and exception behavior of the existing definition codec.
		return json_encode( self::canonicalize( $definition ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
	/**
	 * Compute the digest of a canonical form definition.
	 *
	 * @param array $definition Form definition being processed.
	 * @return string Result for the requested operation.
	 */
	public static function hash( array $definition ): string {
		return hash( 'sha256', self::encode( $definition ) ); }
	/**
	 * Normalize a form definition before encoding it.
	 *
	 * @param array $value Value being processed.
	 * @return array Result for the requested operation.
	 */
	private static function canonicalize( array $value ): array {
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING ); }
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = self::canonicalize( $item ); }
		}
		return $value;
	}
}
