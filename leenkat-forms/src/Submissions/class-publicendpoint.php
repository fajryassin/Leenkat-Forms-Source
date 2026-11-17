<?php
/**
 * REST submission routing and response construction.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Submissions;

use LeenkatForms\Forms\Publisher;
use LeenkatForms\Security\SubmissionPolicy;
use LeenkatForms\Security\RateLimiter;
use LeenkatForms\Security\MathCaptcha;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/** Public write-only transport. All values are checked against the published snapshot. */
final class PublicEndpoint {
	/**
	 * Register the public form submission endpoints.
	 */
	public static function hooks(): void {
		NativeSubmission::hooks();
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'leenkat-forms/v1',
					'/forms/(?P<id>\d+)/submit',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'submit' ),
						'permission_callback' => '__return_true',
						'args'                => array(
							'id' => array(
								'required'          => true,
								'validate_callback' => static fn( $value ): bool => absint( $value ) > 0,
							),
						),
					)
				);
			}
		);
	}

	/**
	 * Process a public form submission under its configured controls.
	 *
	 * @param WP_REST_Request $request Incoming WordPress request.
	 * @return WP_REST_Response|WP_Error Result for the requested operation.
	 * @throws FieldValidationException Raised when a submitted field fails validation.
	 */
	public static function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		$content_type = (string) $request->get_header( 'content-type' );
		$multipart    = (bool) preg_match( '/^multipart\/form-data(?:\s*;|\s*$)/i', $content_type );
		if ( $multipart ) {
			$payload = $request->get_param( 'payload' );
			if ( ! is_string( $payload ) || strlen( $payload ) > SubmissionPolicy::MAX_REQUEST_BYTES || (int) $request->get_header( 'content-length' ) > min( wp_max_upload_size(), 50 * MB_IN_BYTES ) + SubmissionPolicy::MAX_REQUEST_BYTES ) {
				return self::invalid(); }
			$data = json_decode( $payload, true );
		} else {
			if ( ! preg_match( '/^\s*application\/json(?:\s*;|\s*$)/i', $content_type ) ) {
				return new WP_Error( 'leenfo_content_type', __( 'JSON or multipart form data is required.', 'leenkat-forms' ), array( 'status' => 415 ) ); }
			if ( (int) $request->get_header( 'content-length' ) > SubmissionPolicy::MAX_REQUEST_BYTES || strlen( $request->get_body() ) > SubmissionPolicy::MAX_REQUEST_BYTES ) {
				return new WP_Error( 'leenfo_too_large', __( 'Request is too large.', 'leenkat-forms' ), array( 'status' => 413 ) ); }
			$data = $request->get_json_params();
		}
		if ( ! is_array( $data ) ) {
			return self::invalid(); }
		$form_id   = absint( $request['id'] );
		$published = Publisher::published( $form_id );
		if ( ! $published ) {
			return new WP_Error( 'leenfo_unavailable', __( 'Form is unavailable.', 'leenkat-forms' ), array( 'status' => 404 ) ); }
		if ( ! RateLimiter::allow( $form_id, \LeenkatForms\Security\CountryLookup::clientIp( $_SERVER ) ) ) {
			return new WP_Error( 'leenfo_rate_limit', __( 'Please wait before submitting again.', 'leenkat-forms' ), array( 'status' => 429 ) );
		}
		$security    = \LeenkatForms\Forms\SecuritySettings::normalize( $published['definition'] );
		$legacy_trap = ! empty( $data['website_confirmation'] );
		if ( $legacy_trap && ! isset( $published['definition']['settings']['security'] ) ) {
			return self::success();
		}
		if ( isset( $data['website_confirmation'] ) && ( ! is_string( $data['website_confirmation'] ) || strlen( $data['website_confirmation'] ) > 1000 ) ) {
			return self::invalid();
		}
		$proof = \LeenkatForms\Security\SecurityChallenge::check( $security, $data, $form_id, $published['version_id'] );
		if ( is_wp_error( $proof ) ) {
			return $proof;
		}
		$country = \LeenkatForms\Security\SpamProtection::countryCheck( $security, $_SERVER );
		if ( is_wp_error( $country ) ) {
			return $country;
		}
		$submission_uuid = $data['submission_uuid'] ?? null;
		if ( ! is_string( $submission_uuid ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $submission_uuid ) ) {
			return self::invalid(); }
		$raw = $data['fields'] ?? null;
		if ( ! is_array( $raw ) || ( array_is_list( $raw ) && array() !== $raw ) || count( $raw ) > SubmissionPolicy::MAX_FIELDS ) {
			return self::invalid(); }
		$captcha_tokens = $data['captcha_tokens'] ?? array();
		$confirmations  = $data['email_confirmations'] ?? array();
		if ( ! is_array( $confirmations ) || count( $confirmations ) > SubmissionPolicy::MAX_FIELDS ) {
			return self::invalid(); }
		if ( ! is_array( $captcha_tokens ) || ( array_is_list( $captcha_tokens ) && array() !== $captcha_tokens ) ) {
			return self::invalid(); }
		$incoming_files = $request->get_file_params();
		if ( ! is_array( $incoming_files ) || count( $incoming_files ) > 10 ) {
			return self::invalid(); }
		$upload_keys         = array();
		$pending_uploads     = array();
		$upload_bytes        = 0;
		$upload_count        = 0;
		$known               = array();
		$values              = array();
		$comparison_values   = array();
		$field_parts         = array();
		$field_choices       = array();
		$field_errors        = array();
		$field_error_code    = 'leenfo_invalid';
		$field_error_message = __( 'Please check the form values.', 'leenkat-forms' );
		$rules               = $published['definition']['rules'] ?? array();
		foreach ( $published['definition']['fields'] as $field ) {
			$id           = $field['id'];
			$known[ $id ] = true;
			if ( 'file_upload' === $field['type'] ) {
				$upload_keys[] = 'upload_' . $id; }
			if ( ! \LeenkatForms\Forms\Rules::visible( $id, $rules, $comparison_values, $published['definition']['fields'] ) ) {
				$values[ $id ] = null;
				continue; }
			try {
				$messages = \LeenkatForms\Frontend\FieldErrors::messages();
				$reject   = static function ( ?string $message = null, string $part = '' ) use ( $messages ): never {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception carries data to REST/JSON or an escaped HTML error boundary; HTML-escaping here would corrupt non-HTML messages.
					throw new FieldValidationException( array( $part => $message ?? $messages['invalid'] ) );
				};
				$value    = $raw[ $id ] ?? null;
				$required = ! empty( $field['required'] );
				if ( in_array( $field['type'], array( 'section_divider', 'page_break' ), true ) ) {
					if ( null !== $value ) {
						$reject();
					} continue; }
				if ( in_array( $field['type'], array( 'datetime', 'address' ), true ) ) {
					if ( null !== $value && ( ! is_array( $value ) || array_is_list( $value ) ) ) {
						$reject(); }
					$settings = $field['settings'] ?? array();
					$parts    = 'datetime' === $field['type'] ? \LeenkatForms\Forms\DateTimeField::parts( $settings ) : \LeenkatForms\Forms\AddressField::parts( $settings );
					if ( array_diff( array_keys( $value ? $value : array() ), $parts ) ) {
						$reject(); }
					$normalized  = array();
					$part_errors = array();
					foreach ( $parts as $part ) {
						$part_value = $value[ $part ] ?? '';
						if ( ! is_string( $part_value ) || strlen( $part_value ) > 250 ) {
							$part_errors[ $part ] = $messages['invalid'];
							continue; }
						$part_value = sanitize_text_field( trim( $part_value ) );
						if ( $required && 'address2' !== $part && '' === $part_value ) {
							$part_errors[ $part ] = $messages['required'];
							continue; }
						if ( 'datetime' === $field['type'] ) {
							if ( ! \LeenkatForms\Forms\DateTimeField::valid( $part, $part_value, $settings ) ) {
								$part_errors[ $part ] = $messages[ 'date' === $part ? 'date' : 'invalid' ];
								continue; }
						} else {
							$options = \LeenkatForms\Forms\AddressField::options( $part, $settings ); if ( '' !== $part_value && $options && ! isset( $options[ $part_value ] ) ) {
								$part_errors[ $part ] = $messages['invalid'];
								continue; }
						}
						if ( '' !== $part_value ) {
							$normalized[] = $part_value; }
					}
					if ( $part_errors ) {
						throw new FieldValidationException( $part_errors );
					}
					$value = implode( 'datetime' === $field['type'] ? ' ' : ', ', $normalized );
				} elseif ( 'file_upload' === $field['type'] ) {
					if ( null !== $value ) {
						$reject(); }
					$files = FileUploads::prepare( $incoming_files[ 'upload_' . $id ] ?? null, $field );
					if ( is_wp_error( $files ) ) {
						throw new FieldValidationException( array( '' => $files->get_error_message() ), $files ); }
					$pending_uploads[ $id ] = array( 'files' => $files );
					$upload_bytes          += array_sum( array_column( $files, 'size' ) );
					$upload_count          += count( $files );
					if ( $upload_count > 10 || $upload_bytes > min( wp_max_upload_size(), 50 * MB_IN_BYTES ) ) {
						$reject(); }
					$value = implode( ' ', array_map( static fn( array $file ): string => $file['name'] . ':' . $file['size'] . ':' . $file['sha256'], $files ) );
				} elseif ( 'captcha' === $field['type'] ) {
					if ( ! MathCaptcha::verify( $form_id, $id, $value, $captcha_tokens[ $id ] ?? null ) ) {
						$reject(); }
					$value = __( 'Passed', 'leenkat-forms' );
				} elseif ( 'name' === $field['type'] ) {
					if ( null !== $value && ( ! is_array( $value ) || array_is_list( $value ) ) ) {
						$reject(); }
					$parts = \LeenkatForms\Forms\NameField::parts( $field['settings'] ?? array() );
					if ( array_diff( array_keys( $value ? $value : array() ), $parts ) ) {
						$reject(); }
					$names       = array();
					$part_errors = array();
					foreach ( $parts as $part ) {
						$part_value = $value[ $part ] ?? '';
						if ( ! is_string( $part_value ) || strlen( $part_value ) > 250 ) {
							$part_errors[ $part ] = $messages['invalid'];
							continue; }
						$part_value = sanitize_text_field( trim( $part_value ) );
						if ( $required && 'middle' !== $part && '' === $part_value ) {
							$part_errors[ $part ] = $messages['required'];
							continue; }
						$field_parts[ $id ][ $part ] = $part_value;
						if ( '' !== $part_value ) {
							$names[] = $part_value; }
					}
					if ( $part_errors ) {
						throw new FieldValidationException( $part_errors );
					}
					$value = implode( ' ', $names );
				} elseif ( 'select' === $field['type'] && ! empty( $field['settings']['multiple'] ) ) {
					$allowed = array_column( \LeenkatForms\Forms\ChoiceField::items( $field['settings'] ), 'value' );
					if ( null === $value ) {
						$value = array(); }
					if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 || ( $required && ! $value ) ) {
						$reject(); }
					foreach ( $value as $choice ) {
						if ( ! is_string( $choice ) || ! in_array( $choice, $allowed, true ) ) {
							$reject(); }
					}
					if ( count( array_unique( $value ) ) !== count( $value ) ) {
						$reject(); }
				} elseif ( 'checkboxes' === $field['type'] ) {
					$allowed = array_column( \LeenkatForms\Forms\ChoiceField::items( $field['settings'] ), 'value' );
					if ( null !== $value && ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > count( $allowed ) ) ) {
						$reject(); }
					$choices = $value ?? array();
					if ( $required && ! $choices ) {
						$reject( $messages['choices'] ); }
					if ( ! empty( $field['settings']['choice_limit'] ) && count( $choices ) > $field['settings']['choice_limit'] ) {
						$reject(); }
					foreach ( $choices as $choice ) {
						if ( ! is_string( $choice ) || ! in_array( $choice, $allowed, true ) ) {
							$reject(); }
					}
					if ( count( array_unique( $choices ) ) !== count( $choices ) ) {
						$reject(); }
					$field_choices[ $id ] = $choices;
					$value                = implode( ', ', $choices );
					if ( strlen( $value ) > 5000 ) {
						$reject(); }
				} elseif ( in_array( $field['type'], array( 'checkbox', 'consent' ), true ) ) {
					if ( null !== $value && ! in_array( $value, array( '1', 1, true ), true ) ) {
						$reject(); }
					$value = null === $value ? false : true;
					if ( $required && ! $value ) {
						$reject( $messages['checkbox'] ); }
				} else {
					if ( null !== $value && ! is_string( $value ) ) {
						$reject(); }
					$value = in_array( $field['type'], array( 'select', 'radio' ), true ) ? (string) $value : \LeenkatForms\Forms\TextLength::normalize( (string) $value );
					if ( $required && '' === $value ) {
						if ( 'email' === $field['type'] && ! empty( $field['settings']['email_confirmation'] ) ) {
							throw new FieldValidationException(
								array(
									'email'   => $messages['required'],
									'confirm' => $messages[ is_string( $confirmations[ $id ] ?? null ) && '' !== trim( $confirmations[ $id ] ) ? 'email_match' : 'required' ],
								)
							);
						}
						$reject( $messages[ in_array( $field['type'], array( 'select', 'radio', 'rating' ), true ) ? 'choice' : 'required' ] );
					}
					$limit = match ( $field['type'] ) {
						'email' => 254, 'phone' => 40, 'number', 'number_slider' => 100,
						'url' => 2048, 'select', 'radio', 'rating', 'date', 'time' => 200,
						'textarea' => 5000, default => 1000,
					};
					if ( in_array( $field['type'], array( 'text', 'textarea' ), true ) ? ! \LeenkatForms\Forms\TextLength::valid( $value, $field['settings'] ?? array(), $limit ) : strlen( $value ) > $limit ) {
						$reject( in_array( $field['type'], array( 'text', 'textarea' ), true ) ? \LeenkatForms\Frontend\FieldErrors::lengthMessage( $value, $field['settings'] ?? array(), $limit ) : $messages['invalid'] ); }
					if ( in_array( $field['type'], array( 'text', 'textarea', 'phone' ), true ) ) {
						$value = sanitize_textarea_field( $value ); }
					$settings = $field['settings'] ?? array();
					if ( in_array( $field['type'], array( 'text', 'textarea' ), true ) && ! empty( $settings['limit_length_enabled'] ) && \LeenkatForms\Forms\TextLength::count( $value, (string) ( $settings['length_unit'] ?? 'characters' ) ) > (int) ( $settings['max_length'] ?? 100 ) ) {
						$reject(); }
					if ( 'text' === $field['type'] && '' !== $value && ! empty( $settings['input_mask'] ) && ! \LeenkatForms\Forms\InputMask::matches( (string) $settings['input_mask'], $value ) ) {
						$reject( $messages['pattern'] ); }
					if ( in_array( $field['type'], array( 'text', 'textarea', 'email', 'phone', 'url', 'number' ), true ) && ! empty( $settings['read_only'] ) && trim( (string) ( $settings['default_value'] ?? '' ) ) !== $value ) {
						$reject( $messages['read_only'] ); }
					if ( 'email' === $field['type'] && '' !== $value && ! is_email( $value ) ) {
						if ( ! empty( $settings['email_confirmation'] ) ) {
							$email_errors = array( 'email' => $messages['email'] );
							$confirmed    = $confirmations[ $id ] ?? '';
							if ( ! is_string( $confirmed ) || trim( $confirmed ) !== $value ) {
								$email_errors['confirm'] = $messages[ is_string( $confirmed ) && '' === trim( $confirmed ) ? 'required' : 'email_match' ];
							}
							throw new FieldValidationException( $email_errors );
						}
						$reject( $messages['email'] );
					}
					if ( 'email' === $field['type'] ) {
						if ( ! \LeenkatForms\Forms\EmailField::allowed( $value, $settings ) ) {
							$reject( $messages['email_filter'] ); }
						if ( ! empty( $settings['email_confirmation'] ) && ( ! is_string( $confirmations[ $id ] ?? '' ) || trim( $confirmations[ $id ] ?? '' ) !== $value ) ) {
							$reject( $messages['email_match'], 'confirm' ); }
					}
					if ( in_array( $field['type'], array( 'number', 'number_slider' ), true ) && '' !== $value && ( ! is_numeric( $value ) || ! is_finite( (float) $value ) ) ) {
						$reject(); }
					if ( 'number_slider' === $field['type'] && '' !== $value ) {
						if ( ! \LeenkatForms\Forms\NumberSlider::validValue( $value, $field['settings'] ) ) {
							$reject(); }
					}
					if ( 'phone' === $field['type'] && ! \LeenkatForms\Forms\PhoneField::valid( $value, $settings ) ) {
						$reject( $messages['phone'] ); }
					if ( 'url' === $field['type'] && '' !== $value && ( ! filter_var( $value, FILTER_VALIDATE_URL ) || ! in_array( strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) ) {
						$reject(); }
					if ( 'select' === $field['type'] && '' !== $value && ! in_array( $value, array_column( \LeenkatForms\Forms\ChoiceField::items( $settings ), 'value' ), true ) ) {
						$reject(); }
					if ( 'radio' === $field['type'] && '' !== $value && ! in_array( $value, array_column( \LeenkatForms\Forms\ChoiceField::items( $settings ), 'value' ), true ) ) {
						$reject(); }
					if ( 'date' === $field['type'] && '' !== $value ) {
						$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
						if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
							$reject(); }
					}
					if ( 'time' === $field['type'] && '' !== $value && ! preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $value ) ) {
						$reject(); }
					if ( 'rating' === $field['type'] && '' !== $value && ( ! ctype_digit( $value ) || (int) $value < 1 || (int) $value > (int) ( $field['settings']['max'] ?? 5 ) ) ) {
						$reject(); }
				}
				$values[ $id ] = $value;
				if ( \LeenkatForms\Forms\Rules::sourceAllowed( $field ) ) {
					$comparison_values[ $id ] = \LeenkatForms\Forms\Rules::normalizeSource( $field, $raw[ $id ] ?? null );
				}
			} catch ( FieldValidationException $failure ) {
				if ( ! $field_errors && $failure->original ) {
					$field_error_code    = $failure->original->get_error_code();
					$field_error_message = $failure->original->get_error_message();}
				$field_errors[ $id ] = $failure->errors;
			}
		}
		if ( array_diff( array_keys( $incoming_files ), $upload_keys ) || array_diff( array_keys( $raw ), array_keys( $known ) ) ) {
			return self::invalid(); }
		if ( $field_errors ) {
			return new WP_Error(
				$field_error_code,
				$field_error_message,
				array(
					'status'       => 400,
					'field_errors' => $field_errors,
				)
			);
		}
		$matched_keywords = array();
		$spam_reason      = $legacy_trap ? 'Honeypot' : ( $proof['trap'] ? 'Modern anti-spam' : ( false === $country ? 'Country Filter' : \LeenkatForms\Security\SpamProtection::reason( $published['definition'], $values, $matched_keywords ) ) );
		if ( $spam_reason && ( ! $security['store_spam'] || ! empty( $published['definition']['settings']['general']['disable_entries'] ) ) ) {
			return ( $legacy_trap || $proof['trap'] ) ? self::success() : \LeenkatForms\Security\SpamProtection::error( $security, $spam_reason, $matched_keywords );
		}
		// Retain exact selections in the retry digest; display text can contain commas.
		$request_values = $field_choices ? array(
			'values'        => $values,
			'field_choices' => $field_choices,
		) : $values;
		$captcha_check  = \LeenkatForms\Security\CaptchaService::verifyForm( $published['definition'], $form_id, $published['version_id'], $submission_uuid, hash( 'sha256', wp_json_encode( $request_values ) ), $data['service_captcha_token'] ?? null );
		if ( is_wp_error( $captcha_check ) ) {
			return $captcha_check;
		}
		if ( ! $spam_reason && ! empty( $security['akismet_enabled'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Keep the stored payload and request digest bytes stable and reject malformed values through JSON_THROW_ON_ERROR.
			$verdict = \LeenkatForms\Security\AkismetService::classify( $published['definition'], $values, $form_id, $published['version_id'], $submission_uuid, hash( 'sha256', json_encode( $request_values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ) ), $_SERVER );
			if ( is_wp_error( $verdict ) ) {
				return $verdict;
			}
			if ( $verdict ) {
				$spam_reason = 'Akismet';
				if ( ! $security['store_spam'] || ! empty( $published['definition']['settings']['general']['disable_entries'] ) ) {
					return \LeenkatForms\Security\SpamProtection::error( $security, $spam_reason );
				}
			}
		}
		$entries = $wpdb->prefix . 'leenfo_entries';
		if ( ! empty( $published['definition']['settings']['general']['disable_entries'] ) ) {
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Keep the stored payload and request digest bytes stable and reject malformed values through JSON_THROW_ON_ERROR.
				$hash = hash_hmac( 'sha256', json_encode( $request_values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ), wp_salt( 'auth' ) ); } catch ( \JsonException $error ) {
				return self::invalid(); }
				$display_values = $values;
				foreach ( $pending_uploads as $fid => $upload ) {
					$display_values[ $fid ] = implode( ', ', array_column( $upload['files'], 'name' ) );
				}
				return UnstoredSubmission::process( $form_id, $submission_uuid, $hash, $published, $values, $pending_uploads, $field_parts, $field_choices ) ? self::success(
					$published['definition'],
					$display_values,
					array(
						'form_id'       => $form_id,
						'field_parts'   => $field_parts,
						'field_choices' => $field_choices,
					)
				) : self::failure();
		}
		$projections = $wpdb->prefix . 'leenfo_entry_values';
		if ( ! \LeenkatForms\Infrastructure\Database\Transactions::supported( array( $wpdb->prefix . 'leenfo_forms', $entries, $projections, $wpdb->prefix . 'leenfo_deliveries', $wpdb->prefix . 'leenfo_entry_files' ) ) ) {
			return self::failure(); }
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Keep the stored payload and request digest bytes stable and reject malformed values through JSON_THROW_ON_ERROR.
			$json = json_encode( $values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ); } catch ( \JsonException $error ) {
			return self::invalid(); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Keep the stored payload and request digest bytes stable and reject malformed values through JSON_THROW_ON_ERROR.
			$hash = hash( 'sha256', json_encode( $request_values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id,form_id,form_version_id,payload,meta,submitted_at_gmt FROM `{$entries}` WHERE form_id = %d AND submission_uuid = %s", $form_id, $submission_uuid ) );
			if ( $existing ) {
				$meta = json_decode( (string) $existing->meta, true );
				return is_array( $meta ) && hash_equals( (string) ( $meta['request_hash'] ?? '' ), $hash ) ? self::stored_success( $existing, $published['definition'] ) : self::invalid();
			}
			$created_files = array();
			$committed     = false;
			try {
				foreach ( $pending_uploads as $field_id => $upload ) {
					if ( $spam_reason ) {
						$values[ $field_id ] = implode( ', ', array_column( $upload['files'], 'name' ) );
						continue;}
					$before = count( $created_files );
					$stored = FileUploads::store( $upload['files'], $form_id, $created_files );
					if ( is_wp_error( $stored ) ) {
						// Storage failures retain their safe message/status and field identity.
						$error_data = $stored->get_error_data();
						$stored->add_data( ( is_array( $error_data ) ? $error_data : array() ) + array( 'field_errors' => array( $field_id => array( '' => $stored->get_error_message() ) ) ) );
						return $stored;
					}
					$file_count = count( $created_files );
					for ( $i = $before; $i < $file_count; ++$i ) {
						$created_files[ $i ]['field_id'] = $field_id; }
					$values[ $field_id ] = $stored;
				}
				if ( $created_files && ! \LeenkatForms\Infrastructure\Database\Transactions::supported( array( $wpdb->posts, $wpdb->postmeta ) ) ) {
					return self::failure();
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Keep the stored payload and request digest bytes stable and reject malformed values through JSON_THROW_ON_ERROR.
				$json = json_encode( $values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
					return self::failure(); }
				$forms = $wpdb->prefix . 'leenfo_forms';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				$locked_form = $wpdb->get_row( $wpdb->prepare( "SELECT status,published_version_id FROM `{$forms}` WHERE id=%d FOR UPDATE", $form_id ) );
				if ( ! $locked_form || 'published' !== $locked_form->status || (int) $locked_form->published_version_id !== $published['version_id'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					$wpdb->query( 'ROLLBACK' );
					return new WP_Error( 'leenfo_changed', __( 'Form changed. Please reload and try again.', 'leenkat-forms' ), array( 'status' => 409 ) );
				}
				$now        = current_time( 'mysql', true );
				$entry_uuid = wp_generate_uuid4();
				$user_id    = get_current_user_id();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
				$ok = $wpdb->insert(
					$entries,
					array(
						'uuid'             => $entry_uuid,
						'form_id'          => $form_id,
						'form_version_id'  => $published['version_id'],
						'status'           => $spam_reason ? 'spam' : 'complete',
						'submission_uuid'  => $submission_uuid,
						'user_id'          => $user_id ? $user_id : null,
						'payload'          => $json,
						'meta'             => wp_json_encode(
							array(
								'request_hash'  => $hash,
								'uploads'       => $created_files,
								'field_parts'   => $field_parts,
								'field_choices' => $field_choices,
							) + ( $spam_reason ? array( 'spam_reason' => $spam_reason ) : array() ) + \LeenkatForms\Forms\GeneralSettings::details( $published['definition']['settings']['general'] ?? array(), $_SERVER )
						),
						'submitted_at_gmt' => $now,
						'updated_at_gmt'   => $now,
					),
					array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( ! $ok ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					$wpdb->query( 'ROLLBACK' );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					$duplicate = $wpdb->get_row( $wpdb->prepare( "SELECT id,form_id,form_version_id,payload,meta,submitted_at_gmt FROM `{$entries}` WHERE form_id = %d AND submission_uuid = %s", $form_id, $submission_uuid ) );
					$meta      = $duplicate ? json_decode( (string) $duplicate->meta, true ) : null;
					return is_array( $meta ) && hash_equals( (string) ( $meta['request_hash'] ?? '' ), $hash ) ? self::stored_success( $duplicate, $published['definition'] ) : self::failure();
				}
				$entry_id = (int) $wpdb->insert_id;
				if ( ! \LeenkatForms\Files\FileRecords::register( $entry_id, $created_files ) ) {
					return self::failure();
				}
				$registered_meta = array(
					'request_hash'  => $hash,
					'uploads'       => $created_files,
					'field_parts'   => $field_parts,
					'field_choices' => $field_choices,
				) + ( $spam_reason ? array( 'spam_reason' => $spam_reason ) : array() ) + \LeenkatForms\Forms\GeneralSettings::details( $published['definition']['settings']['general'] ?? array(), $_SERVER );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				if ( false === $wpdb->update( $entries, array( 'meta' => wp_json_encode( $registered_meta ) ), array( 'id' => $entry_id ) ) ) {
					return self::failure();
				}
				foreach ( $published['definition']['fields'] as $field ) {
					if ( $spam_reason ) {
						continue;
					}
					if ( empty( $field['searchable'] ) || '' === $values[ $field['id'] ] || false === $values[ $field['id'] ] || null === $values[ $field['id'] ] ) {
						continue; }
					$value      = is_array( $values[ $field['id'] ] ) ? implode( ', ', $values[ $field['id'] ] ) : (string) $values[ $field['id'] ];
					$characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
					$row        = array(
						'entry_id'   => $entry_id,
						'form_id'    => $form_id,
						'field_id'   => $field['id'],
						'row_index'  => 0,
						'value_text' => implode( '', array_slice( $characters ? $characters : array(), 0, 191 ) ),
					);
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
					if ( ! $wpdb->insert( $projections, $row, array( '%d', '%d', '%s', '%d', '%s' ) ) ) {
						$wpdb->query( 'ROLLBACK' );
						return self::failure(); }
				}
				$delivery_ids = $spam_reason ? array() : \LeenkatForms\Workflow\Notifications::createOutbox( $entry_id, $published['definition'], array_replace( $values, $field_choices ), $now );
				if ( false === $delivery_ids ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					$wpdb->query( 'ROLLBACK' );
					return self::failure(); }
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					$wpdb->query( 'ROLLBACK' );
					return self::failure(); }
				$committed = true;
			} finally {
				if ( ! $committed ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					$wpdb->query( 'ROLLBACK' );
					FileUploads::cleanup( $created_files ); }
			}
			\LeenkatForms\Files\FileRecords::committed( $created_files );
			foreach ( $delivery_ids as $delivery_id ) {
				if ( \LeenkatForms\Settings\SiteSettings::email()['optimize_sending'] ) {
					\LeenkatForms\Workflow\DeliveryWorker::schedule( $delivery_id );
				} else {
					\LeenkatForms\Workflow\DeliveryWorker::process( $delivery_id );
				}
			}
			// Retention is an administrative choice, not acceptance of blocked content.
			if ( in_array( $spam_reason, array( 'Keyword Filter', 'Country Filter' ), true ) ) {
				return \LeenkatForms\Security\SpamProtection::error( $security, $spam_reason, $matched_keywords );
			}
			return self::success(
				$published['definition'],
				$values,
				array(
					'form_id'       => $form_id,
					'entry_id'      => $entry_id,
					'field_parts'   => $field_parts,
					'field_choices' => $field_choices,
				)
			);
	}
	/**
	 * Build an invalid-request response for the public endpoint.
	 *
	 * @return WP_Error Result for the requested operation.
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'leenfo_invalid', __( 'Please check the form values.', 'leenkat-forms' ), array( 'status' => 400 ) ); }
	/**
	 * Build the error response for an unsuccessful submission.
	 *
	 * @return WP_Error Result for the requested operation.
	 */
	private static function failure(): WP_Error {
		return new WP_Error( 'leenfo_storage', __( 'Submission could not be saved. Please try again.', 'leenkat-forms' ), array( 'status' => 503 ) ); }
	/**
	 * Build the success response after storing an entry.
	 *
	 * @param object $entry    Entry data for this operation.
	 * @param array  $fallback Fallback for this operation.
	 * @return WP_REST_Response|WP_Error Result for the requested operation.
	 */
	private static function stored_success( object $entry, array $fallback ): WP_REST_Response|WP_Error {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$snapshot = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT definition FROM {$wpdb->prefix}leenfo_form_versions WHERE id=%d", $entry->form_version_id ) ), true );
		$meta     = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( $entry->meta );
		if ( ( $meta['spam_reason'] ?? null ) === 'Country Filter' ) {
			return \LeenkatForms\Security\SpamProtection::error( \LeenkatForms\Forms\SecuritySettings::normalize( is_array( $snapshot ) ? $snapshot : $fallback ), 'Country Filter' );
		}
		// UUID retries must not turn an already blocked submission into success.
		if ( ( $meta['spam_reason'] ?? null ) === 'Keyword Filter' ) {
			return \LeenkatForms\Security\SpamProtection::error( \LeenkatForms\Forms\SecuritySettings::normalize( is_array( $snapshot ) ? $snapshot : $fallback ), 'Keyword Filter', \LeenkatForms\Security\SpamKeywords::matches( is_array( $snapshot ) ? $snapshot : $fallback, \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( $entry->payload ?? '{}' ) ) );
		}
		return self::success(
			is_array( $snapshot ) ? $snapshot : $fallback,
			\LeenkatForms\Files\FileRecords::safeValues( is_array( $snapshot ) ? $snapshot : $fallback, \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( $entry->payload ), $meta['uploads'] ?? array() ),
			array(
				'form_id'       => (int) $entry->form_id,
				'entry_id'      => (int) $entry->id,
				'entry_date'    => get_date_from_gmt( $entry->submitted_at_gmt ),
				'field_parts'   => $meta['field_parts'] ?? array(),
				'field_choices' => $meta['field_choices'] ?? array(),
			)
		);
	}
	/**
	 * Build the confirmation response for a successful submission.
	 *
	 * @param array $definition Form definition being processed.
	 * @param array $values     Values from the current submission.
	 * @param array $context    Context for the requested operation.
	 * @return WP_REST_Response Result for the requested operation.
	 */
	private static function success( array $definition = array(), array $values = array(), array $context = array() ): WP_REST_Response {
		$context += array(
			'entry_date' => current_time( 'mysql' ),
			'user_email' => wp_get_current_user()->user_email ?? '',
		);
		$values   = \LeenkatForms\Files\FileRecords::safeValues( $definition, $values );
		return new WP_REST_Response( ConfirmationReceipt::create( \LeenkatForms\Forms\ConfirmationSettings::response( $definition, $values, $context ), (int) ( $context['form_id'] ?? 0 ) ), 200 );
	}
}
