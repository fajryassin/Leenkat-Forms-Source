<?php
/**
 * Read-only form field prefill processing.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Frontend;

/** Explicit form/field scoped query keys. Never prefill consent, CAPTCHA, files or read-only values. */
final class Prefill {
	/**
	 * Read only this form's prefill namespace, normalizing bounded scalar leaves.
	 *
	 * @param int $form_id Published form ID.
	 * @return array Unslashed, sanitized prefill values.
	 */
	public static function request( int $form_id ): array {
		$query = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only prefill has no nonce. Keys and each bounded scalar leaf are validated and sanitized below, then definition() applies the published field's semantic schema.
		foreach ( $_GET as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'lkf' . $form_id . '_' ) || ! preg_match( '/^[A-Za-z0-9_-]{1,160}$/D', $key ) ) {
				continue;
			}
			$read = static fn( $item ) => is_string( $item ) && strlen( $item ) <= 5000 ? sanitize_textarea_field( wp_unslash( $item ) ) : null;
			if ( is_array( $value ) ) {
				if ( ! array_is_list( $value ) || count( $value ) > 100 ) {
					continue;
				}
				$items = array_map( $read, $value );
				if ( in_array( null, $items, true ) ) {
					continue;
				}
				$query[ $key ] = $items;
			} else {
				$query[ $key ] = $read( $value );
			}
		}
		return $query;
	}

	/**
	 * Apply the supported prefill data to a form definition.
	 *
	 * @param array $definition Form definition being processed.
	 * @param int   $formId     Form identifier.
	 * @param array $query      Query for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function definition( array $definition, int $formId, array $query ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		if ( empty( $definition['settings']['general']['prefill'] ) ) {
			return $definition; }
		foreach ( $definition['fields'] as &$field ) {
			$s    = $field['settings'] ?? array();
			$type = $field['type'];
			if ( ! empty( $s['read_only'] ) || in_array( $type, array( 'consent', 'checkbox', 'captcha', 'file_upload', 'section_divider', 'page_break' ), true ) ) {
				continue;
			}
			$prefix = 'lkf' . $formId . '_' . $field['id']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
			$alias  = 'lkf' . $formId . '_' . ( $field['display_id'] ?? $field['id'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
			$read   = static function ( string $key ) use ( $query, $prefix, $alias, $type ): ?string {
				$value = $query[ $key ] ?? $query[ $alias . substr( $key, strlen( $prefix ) ) ] ?? null;
				if ( in_array( $type, array( 'select', 'radio', 'checkboxes' ), true ) && is_array( $value ) && array_is_list( $value ) && count( $value ) <= 100 && count( array_filter( $value, 'is_string' ) ) === count( $value ) ) {
					$value = implode( '|', $value );
				}
				return is_string( $value ) && strlen( $value ) <= 5000 ? sanitize_textarea_field( $value ) : null;
			};
			$value  = $read( $prefix ) ?? $read( $prefix . '_primary' );
			if ( in_array( $type, array( 'select', 'radio', 'checkboxes' ), true ) && null !== $value ) {
				$allowed              = array_column( \LeenkatForms\Forms\ChoiceField::items( $s ), 'value' );
				$selected             = array_values( array_intersect( explode( '|', $value ), $allowed ) );
				$s['default_options'] = ( 'checkboxes' === $type || ! empty( $s['multiple'] ) ) ? array_values( array_unique( $selected ) ) : array_slice( $selected, 0, 1 );
			} elseif ( in_array( $type, array( 'name', 'address', 'datetime' ), true ) ) {
				$parts = 'name' === $type ? \LeenkatForms\Forms\NameField::parts( $s ) : ( 'address' === $type ? \LeenkatForms\Forms\AddressField::parts( $s ) : \LeenkatForms\Forms\DateTimeField::parts( $s ) );
				foreach ( $parts as $part ) {
					$part_value = $read( $prefix . '_' . $part );
					if ( null === $part_value ) {
						continue;
					}
					if ( 'datetime' === $type ) {
						if ( \LeenkatForms\Forms\DateTimeField::valid( $part, $part_value, $s ) ) {
							$s[ 'prefill_' . $part ] = $part_value;
						}
					} elseif ( 'name' === $type ) {
						$s['name_parts'][ $part ]['default_value'] = substr( $part_value, 0, 250 );
					} else {
						$options = \LeenkatForms\Forms\AddressField::options( $part, $s );
						if ( ! $options || isset( $options[ $part_value ] ) ) {
							$s['address_parts'][ $part ]['default_value'] = substr( $part_value, 0, 250 );
						}
					}
				}
			} elseif ( null !== $value ) {
				$valid = match ( $type ) {
					'email'=>'' === $value || is_email( $value ),
					'url'=>'' === $value || ( filter_var( $value, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ),
					'number'=>is_numeric( $value ) && is_finite( (float) $value ),
					'number_slider'=>\LeenkatForms\Forms\NumberSlider::validValue( $value, $s ),
					'date'=>self::valid_date( $value ),
					'time'=> (bool) preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value ),
					'rating'=>ctype_digit( $value ) && (int) $value >= 1 && (int) $value <= (int) ( $s['max'] ?? 5 ),
					default=>true,
				};
				if ( $valid ) {
					$s['default_value'] = $value;
				}
			}
			$field['settings'] = $s;
		}
		unset( $field );
		return $definition;
	}

	/**
	 * Check an exact calendar date in the prefill format.
	 *
	 * @param string $value Prefill value.
	 * @return bool Whether the date round-trips unchanged.
	 */
	private static function valid_date( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		return $date && $value === $date->format( 'Y-m-d' );
	}
}
