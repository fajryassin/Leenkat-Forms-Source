<?php
/**
 * Frontend rendering and preservation of field validation errors.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Frontend;

/** Shared translated messages and native error presentation; no submission policy. */
final class FieldErrors {
	/**
	 * Build the form-level validation messages.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function messages(): array {
		return array(
			'required'     => __( 'This field is required.', 'leenkat-forms' ),
			'invalid'      => __( 'Please enter a valid value.', 'leenkat-forms' ),
			'email'        => __( 'Please enter a valid email address.', 'leenkat-forms' ),
			'phone'        => __( 'Please enter a valid phone number.', 'leenkat-forms' ),
			'url'          => __( 'Please enter a valid URL.', 'leenkat-forms' ),
			'number'       => __( 'Please enter a valid number.', 'leenkat-forms' ),
			'choice'       => __( 'Please select an option.', 'leenkat-forms' ),
			'checkbox'     => __( 'Please check this box.', 'leenkat-forms' ),
			'choices'      => __( 'Please select at least one option.', 'leenkat-forms' ),
			'choice_limit' => /* translators: %d: maximum number of selected options. */ __( 'Please select no more than %d options.', 'leenkat-forms' ),
			'pattern'      => __( 'Please enter a value in the requested format.', 'leenkat-forms' ),
			'min'          => /* translators: %s: minimum allowed value. */ __( 'Please enter a value greater than or equal to %s.', 'leenkat-forms' ),
			'max'          => /* translators: %s: maximum allowed value. */ __( 'Please enter a value less than or equal to %s.', 'leenkat-forms' ),
			'unicode'      => __( 'Please enter valid Unicode text.', 'leenkat-forms' ),
			'characters'   => /* translators: %d: maximum Unicode character count. */ __( 'Please use no more than %d characters.', 'leenkat-forms' ),
			'words'        => /* translators: %d: maximum word count. */ __( 'Please use no more than %d words.', 'leenkat-forms' ),
			'bytes'        => __( 'This value exceeds the text byte-size limit.', 'leenkat-forms' ),
			'date'         => __( 'Please select a valid date within the allowed days.', 'leenkat-forms' ),
			'file_count'   => /* translators: %d: maximum file count. */ __( 'You can upload a maximum of %d file(s).', 'leenkat-forms' ),
			'file_size'    => /* translators: %s: formatted maximum file size. */ __( 'This file exceeds the maximum size of %s.', 'leenkat-forms' ),
			'file_type'    => /* translators: %s: allowed extension list. */ __( 'This file extension is not allowed. Allowed extensions: %s.', 'leenkat-forms' ),
			'file_mime'    => __( 'The file content does not match its extension or has an unsupported MIME type.', 'leenkat-forms' ),
			'file_failed'  => __( 'The file could not be uploaded. Please select it again and retry.', 'leenkat-forms' ),
			'file_storage' => __( 'The file could not be stored securely. Please try again later.', 'leenkat-forms' ),
			'email_match'  => __( 'Email addresses do not match.', 'leenkat-forms' ),
			'email_filter' => __( 'This email address is not allowed.', 'leenkat-forms' ),
			'summary'      => __( 'Please correct the highlighted fields.', 'leenkat-forms' ),
			'read_only'    => __( 'This field has changed. Please reload the form and try again.', 'leenkat-forms' ),
		);
	}
	/**
	 * Collect the field labels used in validation messages.
	 *
	 * @param array $field Field definition used by this operation.
	 * @return array Result for the requested operation.
	 */
	public static function labels( array $field ): array {
		// The simple Name uses the first key for storage, but has no visible subfield.
		if ( ( $field['type'] ?? '' ) === 'name' && ( $field['settings']['name_format'] ?? 'first_last' ) === 'simple' ) {
			return array(); }
		return ( $field['type'] ?? '' ) === 'address' ? \LeenkatForms\Forms\AddressField::captions( $field['settings'] ?? array() ) : array(
			'first'      => __( 'First', 'leenkat-forms' ),
			'middle'     => __( 'Middle', 'leenkat-forms' ),
			'last'       => __( 'Last', 'leenkat-forms' ),
			'date'       => __( 'Date', 'leenkat-forms' ),
			'time'       => __( 'Time', 'leenkat-forms' ),
			'email'      => __( 'Email', 'leenkat-forms' ),
			'confirm'    => __( 'Confirm Email', 'leenkat-forms' ),
			'date_month' => __( 'Month', 'leenkat-forms' ),
			'date_day'   => __( 'Day', 'leenkat-forms' ),
			'date_year'  => __( 'Year', 'leenkat-forms' ),
		);
	}
	/**
	 * Format the error for a text-length constraint.
	 *
	 * @param string $value    Value being processed.
	 * @param array  $settings Settings used by the current operation.
	 * @param int    $maximum  Maximum for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function lengthMessage( string $value, array $settings, int $maximum ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$messages = self::messages();
		if ( 1 !== preg_match( '//u', $value ) ) {
			return $messages['unicode'];
		}
		if ( \LeenkatForms\Forms\TextLength::count( $value ) > $maximum ) {
			return sprintf( $messages['characters'], $maximum );
		}
		if ( strlen( $value ) > 4 * $maximum ) {
			return $messages['bytes'];
		}
		return sprintf( $messages[ ( $settings['length_unit'] ?? 'characters' ) === 'words' ? 'words' : 'characters' ], (int) ( $settings['max_length'] ?? 100 ) );
	}
	/**
	 * Build field state for rendering after validation.
	 *
	 * @param array $definition Form definition being processed.
	 * @param array $raw        Raw for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function prefill( array $definition, array $raw ): array {
		$comparisons = array();
		foreach ( $definition['fields'] as &$field ) {
			$id       = $field['id'];
			$type     = $field['type'];
			$value    = $raw[ $id ] ?? null;
			$settings = $field['settings'] ?? array();
			if ( ! \LeenkatForms\Forms\Rules::visible( $id, $definition['rules'] ?? array(), $comparisons, $definition['fields'] ) ) {
				continue;
			}
			if ( \LeenkatForms\Forms\Rules::sourceAllowed( $field ) ) {
				$comparisons[ $id ] = \LeenkatForms\Forms\Rules::normalizeSource( $field, $value );
			}
			if ( ! empty( $settings['read_only'] ) || in_array( $type, array( 'consent', 'checkbox', 'captcha', 'file_upload', 'section_divider', 'page_break' ), true ) ) {
				continue;
			}
			if ( in_array( $type, array( 'select', 'radio', 'checkboxes', 'rating' ), true ) ) {
				$allowed                     = 'rating' === $type ? array_map( 'strval', range( 1, (int) ( $settings['max'] ?? 5 ) ) ) : array_column( \LeenkatForms\Forms\ChoiceField::items( $settings ), 'value' );
				$items                       = is_array( $value ) ? array_filter( $value, 'is_string' ) : ( is_string( $value ) ? array( $value ) : array() );
				$settings['default_options'] = array_values( array_intersect( $items, $allowed ) );
			} elseif ( in_array( $type, array( 'name', 'address', 'datetime' ), true ) && is_array( $value ) ) {
				$parts = 'name' === $type ? \LeenkatForms\Forms\NameField::parts( $settings ) : ( 'address' === $type ? \LeenkatForms\Forms\AddressField::parts( $settings ) : \LeenkatForms\Forms\DateTimeField::parts( $settings ) );
				foreach ( $parts as $part ) {
					if ( is_string( $value[ $part ] ?? null ) ) {
						$text = sanitize_textarea_field( $value[ $part ] );
						if ( 'name' === $type ) {
							$settings['name_parts'][ $part ]['default_value'] = $text;
						} elseif ( 'address' === $type ) {
							$settings['address_parts'][ $part ]['default_value'] = $text;
						} else {
							$settings[ 'prefill_' . $part ] = $text;
						}
					}
				}
			} elseif ( is_string( $value ) ) {
				$settings['default_value'] = sanitize_textarea_field( $value );
			}
			$field['settings'] = $settings;
		}
		unset( $field );
		return $definition;
	}
	/**
	 * Render an individual field error.
	 *
	 * @param string $id     Record identifier.
	 * @param array  $field  Field definition used by this operation.
	 * @param array  $errors Collected validation errors.
	 * @return string Result for the requested operation.
	 */
	public static function markup( string $id, array $field, array $errors ): string {
		if ( ! $errors ) {
			return '';
		}$labels = self::labels( $field );
		$html    = '<div class="lkf-field-errors" data-lkf-field-errors>';
		foreach ( $errors as $part => $message ) {
			if ( is_string( $message ) ) {
				$html .= '<p class="lkf-field-error" id="' . esc_attr( $id . '-error-' . ( $part ? $part : 'value' ) ) . '">' . esc_html( ( isset( $labels[ $part ] ) ? $labels[ $part ] . ': ' : '' ) . $message ) . '</p>';
			}
		}
		return $html . '</div>';
	}
	/**
	 * Attach validation presentation to a rendered field.
	 *
	 * @param string $html    Html for this operation.
	 * @param string $id      Record identifier.
	 * @param array  $errors  Collected validation errors.
	 * @param bool   $focused Focused for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function decorate( string $html, string $id, array $errors, bool &$focused = false ): string {
		if ( ! $errors ) {
			return $html;
		}
		return preg_replace_callback(
			'/<(input|select|textarea)\b[^>]*>/i',
			static function ( $matches ) use ( $id, $errors, &$focused ) {
				$tag  = $matches[0];
				$part = '';
				if ( preg_match( '/name="(?:fields\[[^\]]+\]\[([^\]]+)\]|email_confirmations\[[^\]]+\])"/', $tag, $name ) ) {
					$part = $name[1] ?? 'confirm';
				}
				if ( '' === $part && isset( $errors['email'] ) && str_contains( $tag, 'type="email"' ) ) {
					$part = 'email';
				}
				if ( str_contains( $tag, 'data-date-piece=' ) ) {
					$part = 'date';
				}
				if ( ! isset( $errors[ $part ] ) && ! isset( $errors[''] ) ) {
					return $tag;
				}
				if ( preg_match( '/type="(?:hidden|submit|button)"/', $tag ) ) {
					return $tag;
				}
				if ( ! $focused ) {
					$tag     = substr( $tag, 0, -1 ) . ' autofocus>';
					$focused = true;}
				// Inputs are emitted exclusively by the plugin renderer with quoted attributes.
				$error_id = $id . '-error-' . ( isset( $errors[ $part ] ) && '' !== $part ? $part : 'value' );
				if ( preg_match( '/aria-describedby="([^"]*)"/', $tag, $description ) ) {
					$tag = str_replace( $description[0], 'aria-describedby="' . $description[1] . ' ' . esc_attr( $error_id ) . '"', $tag );
				} else {
					$tag = substr( $tag, 0, -1 ) . ' aria-describedby="' . esc_attr( $error_id ) . '">';
				}
				return substr( $tag, 0, -1 ) . ' aria-invalid="true" aria-errormessage="' . esc_attr( $error_id ) . '" data-lkf-invalid>';
			},
			$html
		);
	}
}
