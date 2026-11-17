<?php
/**
 * Validated transfer and import of form definitions.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Transfer;

use LeenkatForms\Forms\DefinitionCodec;
use LeenkatForms\Forms\DefinitionValidator;
use LeenkatForms\Infrastructure\Database\Transactions;

/** One portable, allowlisted definition contract for Tools and the builder. */
final class FormTransfer {
	public const MAX_BYTES       = 2097152;
	public const MAX_FORMS       = 50;
	public const MAX_DEFINITION  = 131072;
	private const FIELD_SETTINGS = 'options max min step placeholder description default_value field_size limit_length_enabled length_unit max_length input_mask css_classes hide_label read_only email_confirmation confirmation_placeholder email_filter email_patterns phone_format select_style multiple dynamic_choices dynamic_source default_options randomize_choices image_choices icon_choices choice_images choice_icons image_choice_style choice_layout choice_limit terms_display agreement value_display name_format hide_sublabels name_parts datetime_format date_type date_format time_format address_scheme upload_style disable_past_dates limit_days limit_hours media_library date_placeholder time_placeholder time_interval allowed_days time_min time_max address_parts max_files max_file_size allowed_extensions page_title next_label previous_label';
	/**
	 * Decode a transferred value as an import object.
	 *
	 * @param mixed  $value    Value being processed.
	 * @param string $keys     Keys for this operation.
	 * @param string $path     Filesystem path to check.
	 * @param bool   $strict   Strict for this operation.
	 * @param array  $warnings Warnings for this operation.
	 * @return array Result for the requested operation.
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	private static function object( mixed $value, string $keys, string $path, bool $strict, array &$warnings ): array {
		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception carries data to REST/JSON or an escaped HTML error boundary; HTML-escaping here would corrupt non-HTML messages.
			throw new \InvalidArgumentException( __( 'Expected an object at ', 'leenkat-forms' ) . $path . '.' );
		}
		$allowed = explode( ' ', $keys );
		$unknown = array_diff( array_keys( $value ), $allowed );
		if ( $unknown ) {
			if ( $strict ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception carries data to REST/JSON or an escaped HTML error boundary; HTML-escaping here would corrupt non-HTML messages.
				throw new \InvalidArgumentException( __( 'Unsupported or private setting at ', 'leenkat-forms' ) . $path . '.' );
			}$warnings[] = __( 'Unsupported/private settings were excluded from ', 'leenkat-forms' ) . $path . '.';}
		return array_intersect_key( $value, array_flip( $allowed ) );
	}
	/**
	 * Normalize a condition in a transferred definition.
	 *
	 * @param array $value    Value being processed.
	 * @param bool  $strict   Strict for this operation.
	 * @param array $warnings Warnings for this operation.
	 * @return array Result for the requested operation.
	 */
	private static function condition( array $value, bool $strict, array &$warnings ): array {
		$value = self::object( $value, 'action groups', 'condition', $strict, $warnings );
		if ( isset( $value['groups'] ) && is_array( $value['groups'] ) ) {
			foreach ( $value['groups'] as &$group ) {
				if ( is_array( $group ) ) {
					foreach ( $group as &$clause ) {
						if ( is_array( $clause ) ) {
							$clause = self::object( $clause, 'source_field_id operator value', 'condition clause', $strict, $warnings );
						}
					}
				}
			}
		}
		return $value;
	}
	/**
	 * Prepare one form definition for a transfer bundle.
	 *
	 * @param array $definition Form definition being processed.
	 * @param bool  $strict     Strict for this operation.
	 * @param bool  $import     Import for this operation.
	 * @return array Result for the requested operation.
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function definition( array $definition, bool $strict = true, bool $import = false ): array {
		array_walk_recursive(
			$definition,
			static function ( $value ): void {
				if ( is_string( $value ) && preg_match( '~https?://[^/?#\s]*@~iu', $value ) ) {
					throw new \InvalidArgumentException( esc_html__( 'URLs containing credentials are not transferable.', 'leenkat-forms' ) );
				}}
		);
		$warnings = array();
		$d        = self::object( $definition, 'schema_version title fields settings rules steps next_field_number', 'definition', $strict, $warnings );
		// Check the original export hash before normalization (decode()), then store plain text only.
		if ( $import && is_string( $d['title'] ?? null ) ) {
			$d['title'] = sanitize_text_field( $d['title'] );
		}
		if ( is_array( $d['fields'] ?? null ) ) {
			foreach ( $d['fields'] as &$field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}$field = self::object( $field, 'id display_id type label required width searchable settings', 'field', $strict, $warnings );
				if ( isset( $field['settings'] ) ) {
					$s = self::object( $field['settings'], self::FIELD_SETTINGS, 'field settings', $strict, $warnings );
					foreach ( $s as $key => $value ) {
						if ( ! in_array( $key, array( 'options', 'default_options', 'email_patterns', 'choice_images', 'choice_icons', 'name_parts', 'address_parts', 'allowed_days' ), true ) && ! is_scalar( $value ) ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception carries data to REST/JSON or an escaped HTML error boundary; HTML-escaping here would corrupt non-HTML messages.
							throw new \InvalidArgumentException( __( 'Invalid scalar field setting: ', 'leenkat-forms' ) . $key . '.' );
						}
					}
					foreach ( array( 'options', 'default_options', 'email_patterns' ) as $key ) {
						if ( isset( $s[ $key ] ) && ( ! is_array( $s[ $key ] ) || ! array_is_list( $s[ $key ] ) || count( $s[ $key ] ) > 100 || array_filter( $s[ $key ], static fn( $value )=> ! is_string( $value ) ) ) ) {
							throw new \InvalidArgumentException( esc_html__( 'Invalid field option list.', 'leenkat-forms' ) );
						}
					}
					foreach ( array( 'name_parts', 'address_parts' ) as $key ) {
						if ( isset( $s[ $key ] ) ) {
												$parts = 'name_parts' === $key ? 'first middle last' : 'address1 address2 city state postal country';
							$s[ $key ]                 = self::object( $s[ $key ], $parts, $key, $strict, $warnings );
							foreach ( $s[ $key ] as &$part ) {
								$part = self::object( $part, 'name_parts' === $key ? 'placeholder default_value' : 'placeholder default_value hidden', $key . ' part', $strict, $warnings );
							}unset( $part );}
					}
					$field['settings'] = $s;}
			}
		}unset( $field );
		if ( isset( $d['rules'] ) && is_array( $d['rules'] ) ) {
			foreach ( $d['rules'] as &$rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}$rule = self::object( $rule, 'field_id action source_field_id operator value groups', 'visibility rule', $strict, $warnings );
				if ( isset( $rule['groups'] ) ) {
					$rule['groups'] = self::condition( array( 'groups' => $rule['groups'] ), $strict, $warnings )['groups'];
				}
			}
		}unset( $rule );
		if ( isset( $d['steps'] ) && is_array( $d['steps'] ) ) {
			foreach ( $d['steps'] as &$step ) {
				if ( is_array( $step ) ) {
								$step = self::object( $step, 'id title field_ids', 'step', $strict, $warnings );
					if ( isset( $step['title'] ) && ( ! is_string( $step['title'] ) || strlen( $step['title'] ) > 500 ) ) {
						throw new \InvalidArgumentException( esc_html__( 'Invalid step title.', 'leenkat-forms' ) );
					}
				}
			}
		}unset( $step );
		if ( isset( $d['settings'] ) ) {
			$s = self::object( $d['settings'], 'general security notifications confirmation confirmations', 'form settings', $strict, $warnings );
			if ( isset( $s['general'] ) ) {
				$s['general'] = self::object( $s['general'], 'description submit_text processing_text form_class submit_class tags prefill ajax disable_entries disable_ip progress_indicator first_page_title', 'general', $strict, $warnings );
			}
			if ( isset( $s['security'] ) ) {
				$s['security'] = self::object( $s['security'], 'modern store_spam min_time seconds captcha_enabled akismet_enabled akismet_fields country_enabled country_action country_codes country_message keyword_enabled keyword_message', 'security', $strict, $warnings );
				if ( isset( $s['security']['akismet_fields'] ) ) {
					$s['security']['akismet_fields'] = self::object( $s['security']['akismet_fields'], 'name email message', __( 'Akismet mapping', 'leenkat-forms' ), $strict, $warnings );
				}if ( ! empty( $s['security']['akismet_enabled'] ) ) {
					$warnings[] = __( 'Akismet activation and field mappings are transferred, but its installation, credentials and service availability must be reviewed on the destination site.', 'leenkat-forms' );
				}if ( ! empty( $s['security']['captcha_enabled'] ) || ! empty( $s['security']['keyword_enabled'] ) ) {
					$warnings[] = __( 'CAPTCHA credentials and the shared keyword list are not transferred. Review protection on the destination site.', 'leenkat-forms' );
				}
			}
			if ( isset( $s['confirmation'] ) ) {
				$s['confirmation'] = self::object( $s['confirmation'], 'message', 'legacy confirmation', $strict, $warnings );
			}
			if ( isset( $s['notifications'] ) ) {
				$n = self::object( $s['notifications'], 'enabled items admin user', 'notifications', $strict, $warnings );
				if ( array_key_exists( 'enabled', $n ) && ! is_bool( $n['enabled'] ) ) {
					throw new \InvalidArgumentException( esc_html__( 'Invalid notifications enable flag.', 'leenkat-forms' ) );
				}
				foreach ( array( 'admin', 'user' ) as $key ) {
					if ( isset( $n[ $key ] ) ) {
						$n[ $key ] = self::object( $n[ $key ], 'enabled recipients email_field subject body', 'legacy notification', $strict, $warnings );
						foreach ( array(
							'subject'     => 500,
							'body'        => 20000,
							'email_field' => 64,
						) as $text => $limit ) {
							if ( isset( $n[ $key ][ $text ] ) && ( ! is_string( $n[ $key ][ $text ] ) || strlen( $n[ $key ][ $text ] ) > $limit ) ) {
								throw new \InvalidArgumentException( esc_html__( 'Invalid legacy notification text.', 'leenkat-forms' ) );
							}
						}if ( isset( $n[ $key ]['recipients'] ) && ( ! is_array( $n[ $key ]['recipients'] ) || ! array_is_list( $n[ $key ]['recipients'] ) || count( $n[ $key ]['recipients'] ) > 5 || array_filter( $n[ $key ]['recipients'], static fn( $email )=> ! is_string( $email ) || ! is_email( $email ) ) ) ) {
							throw new \InvalidArgumentException( esc_html__( 'Invalid legacy notification recipients.', 'leenkat-forms' ) );
						}
					}
				}
				if ( isset( $n['items'] ) && is_array( $n['items'] ) ) {
					foreach ( $n['items'] as &$item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}$item = self::object( $item, 'id name enabled to cc subject body template from_name from_email reply_to file_attachments file_fields csv_attachment csv_fields csv_filename condition', 'notification', $strict, $warnings );
						if ( isset( $item['condition'] ) ) {
							$item['condition'] = self::condition( $item['condition'], $strict, $warnings );
						}
					}
				}unset( $item );
				$s['notifications'] = $n;}
			if ( isset( $s['confirmations'] ) ) {
				$c = self::object( $s['confirmations'], 'items', 'confirmations', $strict, $warnings );if ( isset( $c['items'] ) && is_array( $c['items'] ) ) {
					foreach ( $c['items'] as &$item ) {
						if ( ! is_array( $item ) ) {
							continue;
						}$item = self::object( $item, 'id name type message message_scroll entry_preview page_id redirect condition', 'confirmation', $strict, $warnings );
						if ( isset( $item['condition'] ) ) {
							$item['condition'] = self::condition( $item['condition'], $strict, $warnings );
						}if ( $import && ! empty( $item['page_id'] ) ) {
							$item['page_id'] = 0;
							$warnings[]      = __( 'Confirmation page IDs were cleared. Select a local page before publishing.', 'leenkat-forms' );}
					}
				}unset( $item );
				$s['confirmations'] = $c;}
			$d['settings'] = $s;}
		$errors = DefinitionValidator::validate( $d, false, false );
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception carries data to REST/JSON or an escaped HTML error boundary; HTML-escaping here would corrupt non-HTML messages.
			throw new \InvalidArgumentException( implode( ' ', array_slice( $errors, 0, 8 ) ) );
		}
		$encoded = DefinitionCodec::encode( $d );
		if ( strlen( wp_json_encode( array( 'definition' => $d ) ) ) > self::MAX_DEFINITION ) {
			throw new \InvalidArgumentException( esc_html__( 'A form exceeds the 128 KiB definition request limit.', 'leenkat-forms' ) );
		}
		return array(
			'definition' => $d,
			'warnings'   => array_values( array_unique( $warnings ) ),
		);
	}
	/**
	 * Build the transfer bundle for the selected forms.
	 *
	 * @param array $items Items for this operation.
	 * @return array Result for the requested operation.
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function bundle( array $items ): array {
		if ( ! $items || count( $items ) > self::MAX_FORMS ) {
			throw new \InvalidArgumentException( esc_html__( 'Choose between 1 and 50 forms.', 'leenkat-forms' ) );
		}$forms = array();
		foreach ( $items as $item ) {
			$clean   = self::definition( $item['definition'], false );
			$forms[] = array(
				'source_id'       => $item['source_id'] ?? null,
				'source_status'   => $item['source_status'] ?? 'draft',
				'definition_hash' => DefinitionCodec::hash( $clean['definition'] ),
				'definition'      => $clean['definition'],
			);}
		$bundle = array(
			'format'          => 'leenkat-forms',
			'format_version'  => 1,
			'exported_at_gmt' => gmdate( 'c' ),
			'plugin_version'  => LEENKAT_FORMS_VERSION,
			'forms'           => $forms,
		);
		if ( strlen( wp_json_encode( $bundle, JSON_PRETTY_PRINT ) ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( esc_html__( 'The export exceeds 2 MiB. Select fewer forms.', 'leenkat-forms' ) );
		}return $bundle;
	}
	/**
	 * Validate and decode an incoming form transfer bundle.
	 *
	 * @param string $json Json for this operation.
	 * @return array Result for the requested operation.
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function decode( string $json ): array {
		if ( strlen( $json ) > self::MAX_BYTES || '' === $json ) {
			throw new \InvalidArgumentException( esc_html__( 'Choose a nonempty JSON file no larger than 2 MiB.', 'leenkat-forms' ) );
		}
		try {
			$value = json_decode( str_starts_with( $json, "\xEF\xBB\xBF" ) ? substr( $json, 3 ) : $json, true, 64, JSON_THROW_ON_ERROR );} catch ( \JsonException ) {
			throw new \InvalidArgumentException( esc_html__( 'The file does not contain valid UTF-8 JSON.', 'leenkat-forms' ) );}
			if ( ! is_array( $value ) || array_is_list( $value ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Unsupported form file.', 'leenkat-forms' ) );
			}
			if ( isset( $value['schema_version'] ) ) {
				$items = array( array( 'definition' => $value ) );} else {
				if ( ( $value['format'] ?? '' ) !== 'leenkat-forms' || ( $value['format_version'] ?? null ) !== 1 ) {
					throw new \InvalidArgumentException( esc_html__( 'Unsupported Leenkat Forms export format version.', 'leenkat-forms' ) );
				}
				$unused = array();
				self::object( $value, 'format format_version exported_at_gmt plugin_version forms', 'export', true, $unused );
				foreach ( array( 'exported_at_gmt', 'plugin_version' ) as $key ) {
					if ( isset( $value[ $key ] ) && ( ! is_string( $value[ $key ] ) || strlen( $value[ $key ] ) > 100 ) ) {
						throw new \InvalidArgumentException( esc_html__( 'Invalid export metadata.', 'leenkat-forms' ) );
					}
				}$items = $value['forms'] ?? null;
				}
				if ( ! is_array( $items ) || ! array_is_list( $items ) || ! $items || count( $items ) > self::MAX_FORMS ) {
					throw new \InvalidArgumentException( esc_html__( 'The file must contain between 1 and 50 forms.', 'leenkat-forms' ) );
				}
				$result = array();
				foreach ( $items as $index => $item ) {
					$unused = array();
					$item   = self::object( $item, 'source_id source_status definition_hash definition', 'exported form', true, $unused );
					if ( ! is_array( $item['definition'] ?? null ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception carries data to REST/JSON or an escaped HTML error boundary; HTML-escaping here would corrupt non-HTML messages.
						throw new \InvalidArgumentException( __( 'Missing form definition at position ', 'leenkat-forms' ) . ( $index + 1 ) . '.' );
					}
					if ( isset( $item['source_id'] ) && ( ! is_int( $item['source_id'] ) || $item['source_id'] < 1 ) ) {
						throw new \InvalidArgumentException( esc_html__( 'Invalid source form ID.', 'leenkat-forms' ) );
					}
					if ( isset( $item['source_status'] ) && ( ! is_string( $item['source_status'] ) || ! in_array( $item['source_status'], array( 'draft', 'published', 'trashed' ), true ) ) ) {
						throw new \InvalidArgumentException( esc_html__( 'Invalid source form status.', 'leenkat-forms' ) );
					}
					$source = self::definition( $item['definition'], true, false );
					$hash   = DefinitionCodec::hash( $source['definition'] );
					if ( isset( $item['definition_hash'] ) && ( ! is_string( $item['definition_hash'] ) || ! hash_equals( $hash, $item['definition_hash'] ) ) ) {
						throw new \InvalidArgumentException( esc_html__( 'A form definition hash does not match its contents.', 'leenkat-forms' ) );
					}
					$clean    = self::definition( $source['definition'], true, true );
					$result[] = $clean + array(
						'source_id'   => $item['source_id'] ?? null,
						'source_hash' => $hash,
					);
				}return $result;
	}
	/**
	 * Collect the form definitions selected for export.
	 *
	 * @param array $ids Ids for this operation.
	 * @return array Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function selected( array $ids ): array {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) ) {
			throw new \RuntimeException( esc_html__( 'Access denied.', 'leenkat-forms' ) );
		}
		if ( ! $ids || count( $ids ) > self::MAX_FORMS ) {
			throw new \InvalidArgumentException( esc_html__( 'Choose between 1 and 50 forms.', 'leenkat-forms' ) );
		}global $wpdb;
		$items = array();
		foreach ( array_unique( $ids ) as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new \InvalidArgumentException( esc_html__( 'Invalid form ID.', 'leenkat-forms' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			}$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,status,draft_definition FROM {$wpdb->prefix}leenfo_forms WHERE id=%d AND status<>'trashed'", $id ) );
			if ( ! $row ) {
				throw new \InvalidArgumentException( esc_html__( 'A selected form is no longer available.', 'leenkat-forms' ) );
			}$items[] = array(
				'source_id'     => (int) $row->id,
				'source_status' => $row->status,
				'definition'    => json_decode( $row->draft_definition, true, 64, JSON_THROW_ON_ERROR ),
			);
		}return self::bundle( $items );
	}
	/**
	 * Prepare an import preview from the validated bundle.
	 *
	 * @param string $json Json for this operation.
	 * @return array Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function preview( string $json ): array {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) ) {
			throw new \RuntimeException( esc_html__( 'Access denied.', 'leenkat-forms' ) );
		}$items = self::decode( $json );
		global $wpdb;
		$hashes = array();
		$last   = 0;
		$prefix = 'leenfo_import_done_' . get_current_user_id() . '_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id LIMIT 25", $wpdb->esc_like( $prefix ) . '%' ) ) as $receipt ) {
			$done = json_decode( $receipt->option_value, true );
			if ( (int) ( $done['expires'] ?? 0 ) < time() ) {
				delete_option( $receipt->option_name );
			}
		}
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,draft_definition FROM {$wpdb->prefix}leenfo_forms WHERE id>%d AND status<>'trashed' ORDER BY id LIMIT 25", $last ) );
			foreach ( $rows as $row ) {
				$last = (int) $row->id;
				try {
					$clean = self::definition( json_decode( $row->draft_definition, true, 64, JSON_THROW_ON_ERROR ), false );
					$hashes[ DefinitionCodec::hash( $clean['definition'] ) ][] = $last;
				} catch ( \Throwable ) {
					// Skip an unreadable historical definition when indexing duplicates.
					continue;
				}
			}
			$full_page = 25 === count( $rows );
		} while ( $full_page );
		$seen    = array();
		$summary = array();
		foreach ( $items as $i => &$item ) {
			$matches                      = $hashes[ $item['source_hash'] ] ?? array();
			$duplicate                    = isset( $seen[ $item['source_hash'] ] );
			$seen[ $item['source_hash'] ] = true;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$conflict                  = $item['source_id'] ? (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}leenfo_forms WHERE id=%d", $item['source_id'] ) ) : false;
			$item['duplicate']         = (bool) $matches || $duplicate;
			$item['id_conflict']       = $conflict;
			$item['matching_ids']      = array_slice( $matches, 0, 10 );
			$item['duplicate_in_file'] = $duplicate;
			$summary[]                 = array(
				'index'             => $i,
				'title'             => $item['definition']['title'],
				'field_count'       => count( $item['definition']['fields'] ),
				'source_id'         => $item['source_id'],
				'id_conflict'       => $conflict,
				'matching_ids'      => $item['matching_ids'],
				'duplicate_in_file' => $duplicate,
				'duplicate'         => $item['duplicate'],
				'warnings'          => $item['warnings'],
			);
		}unset( $item );
		$token = bin2hex( random_bytes( 24 ) );
		set_transient( 'leenfo_import_' . get_current_user_id() . '_' . $token, wp_json_encode( $items ), 15 * MINUTE_IN_SECONDS );
		return array(
			'token'      => $token,
			'forms'      => $summary,
			'expires_in' => 900,
		);
	}
	/**
	 * Persist the selected definitions from the verified preview.
	 *
	 * @param string $token           Token supplied for validation or encoding.
	 * @param array  $selection       Selection for this operation.
	 * @param bool   $allowDuplicates Allow duplicates for this operation.
	 * @return array Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function import( string $token, array $selection, bool $allowDuplicates ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $allowDuplicates and its method binding.
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) ) {
			throw new \RuntimeException( esc_html__( 'Access denied.', 'leenkat-forms' ) );
		}
		if ( ! preg_match( '/^[a-f0-9]{48}$/D', $token ) || ! $selection || count( $selection ) > self::MAX_FORMS || count( array_unique( $selection, SORT_REGULAR ) ) !== count( $selection ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Invalid import selection.', 'leenkat-forms' ) );
		}
		foreach ( $selection as $i ) {
			if ( ! is_int( $i ) || $i < 0 ) {
				throw new \InvalidArgumentException( esc_html__( 'Invalid import selection.', 'leenkat-forms' ) );
			}
		}sort( $selection );
		$signature = hash( 'sha256', wp_json_encode( $selection ) );
		global $wpdb;
		$receipt = 'leenfo_import_done_' . get_current_user_id() . '_' . $token;
		$lock    = 'leenfo_import_lock_' . get_current_user_id() . '_' . $token;
		$expires = (int) get_option( $lock, 0 );
		if ( $expires > 0 && $expires < time() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock, (string) $expires ) );
			wp_cache_delete( $lock, 'options' );}
		if ( ! add_option( $lock, time() + 60, '', false ) ) {
			throw new \RuntimeException( esc_html__( 'This import is already running. Try again shortly.', 'leenkat-forms' ) );
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $receipt ) );
			if ( $old ) {
				$done = json_decode( $old, true );
				if ( ( $done['selection'] ?? '' ) !== $signature ) {
					throw new \InvalidArgumentException( __( 'This preview was already imported with a different selection. Upload the file again.', 'leenkat-forms' ) );
				}return $done['result'];}
			$raw = get_transient( 'leenfo_import_' . get_current_user_id() . '_' . $token );
			if ( ! is_string( $raw ) ) {
				throw new \InvalidArgumentException( __( 'The import preview expired. Upload the file again.', 'leenkat-forms' ) );
			}$items = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
			foreach ( $selection as $i ) {
				if ( ! isset( $items[ $i ] ) || ( $items[ $i ]['duplicate'] && ! $allowDuplicates ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $allowDuplicates and its method binding.
					throw new \InvalidArgumentException( __( 'Confirm duplicate copies or change the selected forms.', 'leenkat-forms' ) );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
			if ( ! Transactions::supported( array( $wpdb->prefix . 'leenfo_forms', $wpdb->options ) ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( __( 'Transactional form storage is unavailable. Nothing was imported.', 'leenkat-forms' ) );
			}
			$created = array();try {
				foreach ( $selection as $i ) {
					$definition = self::definition( $items[ $i ]['definition'], true, true )['definition'];
					$request    = new \WP_REST_Request( 'POST' );
					$request->set_body( wp_json_encode( array( 'definition' => $definition ) ) );
					$request->set_header( 'content-type', 'application/json' );
					$response = \LeenkatForms\Admin\BuilderApi::create( $request );
					if ( is_wp_error( $response ) ) {
						throw new \RuntimeException( $response->get_error_message() );
					}$id       = (int) $response->get_data()['id'];
					$created[] = array(
						'index'    => $i,
						'id'       => $id,
						'title'    => $definition['title'],
						'status'   => 'draft',
						'edit_url' => admin_url( 'admin.php?page=leenkat-forms-new&form_id=' . $id ),
					);}
				$result        = array(
					'imported' => $created,
					'message'  => sprintf( /* translators: %d: number of imported forms. */ __( '%d forms imported as new unpublished drafts.', 'leenkat-forms' ), count( $created ) ),
				);
				$receipt_value = wp_json_encode(
					array(
						'selection' => $signature,
						'result'    => $result,
						'expires'   => time() + DAY_IN_SECONDS,
					)
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
				if ( ! $wpdb->insert(
					$wpdb->options,
					array(
						'option_name'  => $receipt,
						'option_value' => $receipt_value,
						'autoload'     => 'off',
					)
				) || false === $wpdb->query( 'COMMIT' ) ) {
					throw new \RuntimeException( __( 'Could not commit import. Nothing was imported.', 'leenkat-forms' ) );
				}
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
				$wpdb->query( 'ROLLBACK' );
				throw new \RuntimeException( __( 'No selected forms were imported. ', 'leenkat-forms' ) . $e->getMessage(), 0, $e );
			}delete_transient( 'leenfo_import_' . get_current_user_id() . '_' . $token );
			return $result;
		} finally {
			delete_option( $lock );}
	}
}
