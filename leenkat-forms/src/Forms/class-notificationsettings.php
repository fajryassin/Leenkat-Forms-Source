<?php
/**
 * Validation and routing rules for form notifications.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/** Versioned notifications with a read adapter for the original admin/user pair. */
final class NotificationSettings {
	public const TEMPLATES = array( 'default', 'compact', 'modern', 'elegant', 'tech', 'plain' );
	public const OPERATORS = array( 'equals', 'not_equals', 'is_empty', 'not_empty', 'contains', 'not_contains', 'starts_with', 'ends_with', 'greater_than', 'less_than' );
	/**
	 * Normalize notification settings for use at runtime.
	 *
	 * @param array $definition Form definition being processed.
	 * @return array Result for the requested operation.
	 */
	public static function normalize( array $definition ): array {
		$saved = $definition['settings']['notifications'] ?? array();
		if ( array_key_exists( 'items', $saved ) ) {
			return $saved;
		}
		$items   = array();
		$enabled = false;
		foreach ( array( 'admin', 'user' ) as $kind ) {
			if ( ! isset( $saved[ $kind ] ) ) {
				continue;
			}
			$old     = $saved[ $kind ];
			$active  = ! empty( $old['enabled'] );
			$enabled = $enabled || $active;
			$items[] = array(
				'id'               => 'legacy_' . $kind,
				'name'             => 'admin' === $kind ? __( 'Administrator Notification', 'leenkat-forms' ) : __( 'Respondent Notification', 'leenkat-forms' ),
				'enabled'          => $active,
				'to'               => 'admin' === $kind ? implode( ', ', $old['recipients'] ?? array() ) : ( ! empty( $old['email_field'] ) ? '{field:' . $old['email_field'] . '}' : '' ),
				'subject'          => $old['subject'] ?? __( 'New form entry', 'leenkat-forms' ),
				'body'             => $old['body'] ?? __( 'A new entry was submitted.', 'leenkat-forms' ),
				'template'         => 'plain',
				'from_name'        => '',
				'from_email'       => '',
				'reply_to'         => '',
				'file_attachments' => false,
				'file_fields'      => array(),
				'csv_attachment'   => false,
				'csv_fields'       => array( 'all_fields' ),
				'csv_filename'     => 'entry-details',
			);
		}
		return array(
			'enabled' => $enabled,
			'items'   => $items,
		);
	}
	/**
	 * Validate the address template used by a notification.
	 *
	 * @param string $value     Value being processed.
	 * @param array  $fields    Fields handled by this operation.
	 * @param bool   $multiple  Multiple for this operation.
	 * @param bool   $allowName Allow name for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function addressTemplate( string $value, array $fields, bool $multiple = true, bool $allowName = false ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing callable method name for PHP callers and callback compatibility. Preserve the existing PHP named parameter $allowName and its method binding.
		if ( preg_match( '/[\r\n\x00]/', $value ) ) {
			return false;
		}
		$email_ids = array();
		foreach ( $fields as $field ) {
			if ( ( $field['type'] ?? '' ) === 'email' ) {
				$email_ids[] = $field['id'];
				if ( isset( $field['display_id'] ) ) {
					$email_ids[] = $field['display_id'];
				}
			}
		}
		$sample    = preg_replace_callback(
			'/\{([^{}]+)\}/',
			static function ( $m ) use ( $email_ids ) {
				if ( in_array( $m[1], array( 'admin_email', 'user_email' ), true ) ) {
					return 'tag@example.test';
				}
				if ( preg_match( '/^(?:field:([A-Za-z][A-Za-z0-9_-]{0,63})|field_id="([A-Za-z][A-Za-z0-9_-]{0,63})")$/D', $m[1], $match ) && in_array( $match[1] ? $match[1] : $match[2] ?? '', $email_ids, true ) ) {
					return 'field@example.test';
				}
				return 'INVALID_TAG';
			},
			$value
		);
		$addresses = array_map( 'trim', explode( ',', $sample ) );
		if ( ( ! $multiple && 1 !== count( $addresses ) ) || count( $addresses ) > 10 ) {
			return false;
		}
		foreach ( $addresses as $address ) {
			if ( $allowName && preg_match( '/^[^<>]+<([^<>]+)>$/D', $address, $m ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $allowName and its method binding.
				$address = trim( $m[1] );
			}if ( ! is_email( $address ) ) {
				return false;
			}
		}
		return true;
	}
	/**
	 * Validate the form notification configuration.
	 *
	 * @param array $saved       Saved for this operation.
	 * @param array $fields      Fields handled by this operation.
	 * @param bool  $publication Publication for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function validate( array $saved, array $fields, bool $publication ): array {
		if ( ! array_key_exists( 'items', $saved ) ) {
			return array(); // Legacy validation remains in DefinitionValidator.
		}
		$errors = array();
		if ( ! is_bool( $saved['enabled'] ?? null ) ) {
			$errors[] = __( 'Invalid notifications enable flag.', 'leenkat-forms' );
		}
		if ( ! is_array( $saved['items'] ) || ! array_is_list( $saved['items'] ) || count( $saved['items'] ) > 20 ) {
			return array( __( 'Invalid notifications list.', 'leenkat-forms' ) );
		}
		if ( $publication && ! empty( $saved['enabled'] ) && ! $saved['items'] ) {
			$errors[] = __( 'Add a notification before enabling notifications.', 'leenkat-forms' );
		}
		$known = array();
		$types = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && is_string( $field['id'] ?? null ) && is_string( $field['type'] ?? null ) ) {
				$types[ $field['id'] ] = $field['type'];
			}
		}
		foreach ( $saved['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				$errors[] = __( 'Invalid notification.', 'leenkat-forms' );
				continue;}
			$id = $item['id'] ?? null;
			if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $id ) || isset( $known[ $id ] ) ) {
				$errors[] = __( 'Invalid or duplicate notification identifier.', 'leenkat-forms' );
			}
			if ( is_string( $id ) ) {
				$known[ $id ] = true;
			}
			foreach ( array(
				'name'         => 100,
				'to'           => 2000,
				'subject'      => 500,
				'body'         => 20000,
				'from_name'    => 300,
				'from_email'   => 500,
				'reply_to'     => 1000,
				'csv_filename' => 200,
			) as $key => $limit ) {
				if ( isset( $item[ $key ] ) && ( ! is_string( $item[ $key ] ) || strlen( $item[ $key ] ) > $limit ) ) {
					$errors[] = __( 'Invalid notification ', 'leenkat-forms' ) . $key . '.';
				}
			}
			if ( ! is_string( $item['name'] ?? null ) || '' === trim( $item['name'] ) ) {
				$errors[] = __( 'Name every notification.', 'leenkat-forms' );
			}
			foreach ( array( 'enabled', 'file_attachments', 'csv_attachment' ) as $key ) {
				if ( array_key_exists( $key, $item ) && ! is_bool( $item[ $key ] ) ) {
					$errors[] = __( 'Invalid notification flag.', 'leenkat-forms' );
				}
			}
			if ( isset( $item['template'] ) && ! in_array( $item['template'], self::TEMPLATES, true ) ) {
				$errors[] = __( 'Invalid email template.', 'leenkat-forms' );
			}
			foreach ( array( 'to', 'subject', 'from_name', 'from_email', 'reply_to', 'cc' ) as $key ) {
				if ( is_string( $item[ $key ] ?? null ) && preg_match( '/[\r\n\x00]/', $item[ $key ] ) ) {
					$errors[] = __( 'Email headers cannot contain line breaks.', 'leenkat-forms' );
				}
			}
			if ( isset( $item['cc'] ) && ( ! is_string( $item['cc'] ) || strlen( $item['cc'] ) > 1000 || ( '' !== $item['cc'] && ! self::addressTemplate( $item['cc'], $fields ) ) ) ) {
				$errors[] = __( 'Enter valid CC addresses or email Smart Tags.', 'leenkat-forms' );
			}
			$active = $publication && ! empty( $saved['enabled'] ) && ( $item['enabled'] ?? true );
			if ( $active ) {
				foreach ( array( 'to', 'subject', 'body' ) as $key ) {
					if ( ! is_string( $item[ $key ] ?? null ) || '' === trim( $item[ $key ] ) ) {
						$errors[] = __( 'Complete notification ', 'leenkat-forms' ) . $key . '.';
					}
				}
				if ( is_string( $item['to'] ?? null ) && ! self::addressTemplate( $item['to'], $fields ) ) {
					$errors[] = __( 'Enter valid notification recipients or email field Smart Tags.', 'leenkat-forms' );
				}
				if ( ! empty( $item['from_email'] ) && is_string( $item['from_email'] ) && ! self::addressTemplate( $item['from_email'], $fields, false ) ) {
					$errors[] = __( 'Enter one valid From Email address.', 'leenkat-forms' );
				}
				if ( ! empty( $item['reply_to'] ) && is_string( $item['reply_to'] ) && ! self::addressTemplate( $item['reply_to'], $fields, true, true ) ) {
					$errors[] = __( 'Enter a valid Reply-To address.', 'leenkat-forms' );
				}
			}
			foreach ( array( 'file_fields', 'csv_fields' ) as $key ) {
				$selection = $item[ $key ] ?? array();
				if ( ! is_array( $selection ) || ! array_is_list( $selection ) || count( $selection ) > 300 ) {
					$errors[] = __( 'Invalid notification field selection.', 'leenkat-forms' );
					continue;}
				$seen = array();
				foreach ( $selection as $fid ) {
					if ( ! is_string( $fid ) || isset( $seen[ $fid ] ) ) {
						$errors[] = __( 'Invalid notification field selection.', 'leenkat-forms' );
						continue;
					}$seen[ $fid ] = true;
					if ( 'file_fields' === $key ? ( $types[ $fid ] ?? '' ) !== 'file_upload' : ! in_array( $fid, array( 'all_fields', 'entry_id', 'form_id', 'entry_date' ), true ) && ( ! isset( $types[ $fid ] ) || in_array( $types[ $fid ], array( 'captcha', 'section_divider', 'page_break' ), true ) ) ) {
						$errors[] = __( 'Notification references an unavailable field.', 'leenkat-forms' );
					}
				}
				if ( $active && ! empty( $item[ 'file_fields' === $key ? 'file_attachments' : 'csv_attachment' ] ) && ! $selection ) {
					$errors[] = __( 'Select fields for the notification attachment.', 'leenkat-forms' );
				}
			}
			$condition = $item['condition'] ?? null;
			if ( null !== $condition ) {
				if ( ! is_array( $condition ) || ! in_array( $condition['action'] ?? '', array( 'send', 'stop' ), true ) ) {
					$errors[] = __( 'Invalid notification condition.', 'leenkat-forms' );
					continue;}
				$groups = $condition['groups'] ?? null;
				if ( ! is_array( $groups ) || ! array_is_list( $groups ) || ! $groups || count( $groups ) > 5 ) {
					$errors[] = __( 'Invalid notification groups.', 'leenkat-forms' );
					continue;}
				foreach ( $groups as $group ) {
					if ( ! is_array( $group ) || ! array_is_list( $group ) || ! $group || count( $group ) > 5 ) {
						$errors[] = __( 'Invalid notification group.', 'leenkat-forms' );
						continue;}
					foreach ( $group as $clause ) {
						if ( ! is_array( $clause ) || ! is_string( $clause['source_field_id'] ?? null ) || ! in_array( $clause['operator'] ?? '', self::OPERATORS, true ) || ! is_string( $clause['value'] ?? '' ) || strlen( $clause['value'] ?? '' ) > 200 ) {
							$errors[] = __( 'Invalid notification condition clause.', 'leenkat-forms' );
							continue;}
						$source = $clause['source_field_id'];
						if ( '' === $source ) {
							if ( $active ) {
												$errors[] = __( 'Choose a field for every notification condition.', 'leenkat-forms' );
							}
						} elseif ( ! isset( $types[ $source ] ) || in_array( $types[ $source ], array( 'captcha', 'file_upload', 'section_divider', 'page_break' ), true ) ) {
										$errors[] = __( 'Notification condition references an unavailable field.', 'leenkat-forms' );
						}
					}
				}
			}
		}
		return array_values( array_unique( $errors ) );
	}
	/**
	 * Check whether the notification rules match the submitted values.
	 *
	 * @param array $item   Item for this operation.
	 * @param array $values Values from the current submission.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function shouldSend( array $item, array $values ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ( $item['enabled'] ?? true ) !== true ) {
			return false;
		}
		if ( empty( $item['condition'] ) ) {
			return true;
		}
		$condition = $item['condition'];
		$matches   = false;
		foreach ( $condition['groups'] as $group ) {
			$all = true;
			foreach ( $group as $clause ) {
				if ( empty( $clause['source_field_id'] ) ) {
					return false;
				}$all = $all && Rules::condition( $clause, $values );
			} $matches = $matches || $all;}
		return ( $condition['action'] ?? 'send' ) === 'stop' ? ! $matches : $matches;
	}
}
