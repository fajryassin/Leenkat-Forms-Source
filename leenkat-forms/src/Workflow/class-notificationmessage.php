<?php
/**
 * Rendering and address resolution for notification messages.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Workflow;

/** One nonrecursive Smart Tag renderer shared by queued and no-entry deliveries. */
final class NotificationMessage {
	/**
	 * Format a submitted field value for the notification.
	 *
	 * @param mixed $value Value being processed.
	 * @return string Result for the requested operation.
	 */
	public static function value( mixed $value ): string {
		return is_array( $value ) ? implode( ', ', array_map( array( self::class, 'value' ), $value ) ) : ( true === $value ? 'Yes' : ( false === $value ? 'No' : (string) $value ) ); }
	/**
	 * Render notification data into the supplied message template.
	 *
	 * @param string $template   Template for this operation.
	 * @param array  $definition Form definition being processed.
	 * @param array  $values     Values from the current submission.
	 * @param array  $context    Context for the requested operation.
	 * @param bool   $html       Html for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function render( string $template, array $definition, array $values, array $context = array(), bool $html = false ): string {
		$parts = array();
		foreach ( $definition['fields'] ?? array() as $field ) {
			if ( isset( $field['display_id'] ) ) {
				$values[ $field['display_id'] ] = $values[ $field['id'] ] ?? '';
			}
			if ( ( $field['type'] ?? '' ) === 'name' ) {
				foreach ( \LeenkatForms\Forms\NameField::parts( $field['settings'] ?? array() ) as $part ) {
					$parts[ $field['id'] ][ $part ] = $context['field_parts'][ $field['id'] ][ $part ] ?? '';
					if ( isset( $field['display_id'] ) ) {
						$parts[ $field['display_id'] ][ $part ] = $parts[ $field['id'] ][ $part ];
					}
				}
			}
		}
		return preg_replace_callback(
			'/\{(all_fields|admin_email|user_email|site_name|site_url|form_name|form_id|entry_id|entry_date|field:[A-Za-z][A-Za-z0-9_-]{0,63}(?::(?:first|middle|last))?|field_id="[A-Za-z][A-Za-z0-9_-]{0,63}")\}/',
			static function ( $m ) use ( $definition, $values, $parts, $context, $html ): string {
				$tag = $m[1];
				if ( 'all_fields' === $tag ) {
					return self::all_fields( $definition, $values, $html, $context );
				}
				$file_key = str_starts_with( $tag, 'field:' ) ? substr( $tag, 6 ) : ( str_starts_with( $tag, 'field_id=' ) ? substr( $tag, 10, -1 ) : '' );
				if ( isset( $context['email_file_links'][ $file_key ] ) ) {
					return self::file_value( $context['email_file_links'][ $file_key ], $html );
				}
				if ( str_starts_with( $tag, 'field:' ) ) {
					$field = explode( ':', substr( $tag, 6 ) );
					$value = self::value( isset( $field[1] ) ? ( $parts[ $field[0] ][ $field[1] ] ?? '' ) : ( $values[ $field[0] ] ?? '' ) );
				} elseif ( str_starts_with( $tag, 'field_id=' ) ) {
					$value = self::value( $values[ substr( $tag, 10, -1 ) ] ?? '' );
				} else {
					$value = match ( $tag ) {
						'admin_email'=> (string) get_option( 'admin_email', '' ),
						'user_email'=> (string) ( $context['user_email'] ?? '' ),
						'site_name'=> (string) get_bloginfo( 'name' ),
						'site_url'=>home_url( '/' ),
						'form_name'=> (string) ( $definition['title'] ?? '' ),
						'form_id'=> (string) ( $context['form_id'] ?? '' ),
						'entry_id'=> (string) ( $context['entry_id'] ?? '' ),
						'entry_date'=> (string) ( $context['entry_date'] ?? '' ),
						default=>'',
					};
				}
				$value = sanitize_textarea_field( $value );
				return $html ? esc_html( $value ) : $value;
			},
			$template
		);
	}
	/**
	 * Build the notification display value for an uploaded file.
	 *
	 * @param array $files Files for this operation.
	 * @param bool  $html  Html for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function file_value( array $files, bool $html ): string {
		return implode( $html ? '<br>' : "\n", array_map( static fn( $file )=>$html ? '<a href="' . esc_url( $file['url'] ) . '">' . esc_html( $file['name'] ) . '</a>' : $file['name'] . ': ' . $file['url'], $files ) );
	}
	/**
	 * Format the answers included in the all-fields token.
	 *
	 * @param array $definition Form definition being processed.
	 * @param array $values     Values from the current submission.
	 * @param bool  $html       Html for this operation.
	 * @param array $context    Context for the requested operation.
	 * @return string Result for the requested operation.
	 */
	private static function all_fields( array $definition, array $values, bool $html, array $context = array() ): string {
		$rows = array();
		foreach ( $definition['fields'] ?? array() as $field ) {
			if ( in_array( $field['type'], array( 'captcha', 'section_divider', 'page_break' ), true ) || ! array_key_exists( $field['id'], $values ) || null === $values[ $field['id'] ] ) {
				continue;
			}
			$label    = $field['label'];
			$value    = self::value( $values[ $field['id'] ] );
			$rendered = isset( $context['email_file_links'][ $field['id'] ] ) ? self::file_value( $context['email_file_links'][ $field['id'] ], $html ) : ( $html ? esc_html( $value ) : $value );
			$rows[]   = $html ? '<tr><th style="padding:12px;text-align:left;vertical-align:top;border-bottom:1px solid #ddd;width:35%">' . esc_html( $label ) . '</th><td style="padding:12px;border-bottom:1px solid #ddd;white-space:pre-wrap">' . $rendered . '</td></tr>' : $label . ': ' . $rendered;
		}
		return $html ? '<table role="presentation" style="border-collapse:collapse;width:100%">' . implode( '', $rows ) . '</table>' : implode( "\n", $rows );
	}
	/**
	 * Resolve the recipient addresses for the notification.
	 *
	 * @param string $template   Template for this operation.
	 * @param array  $definition Form definition being processed.
	 * @param array  $values     Values from the current submission.
	 * @param array  $context    Context for the requested operation.
	 * @param bool   $allowName  Allow name for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function addresses( string $template, array $definition, array $values, array $context = array(), bool $allowName = false ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $allowName and its method binding.
		$raw = self::render( $template, $definition, $values, $context );
		if ( preg_match( '/[\r\n\x00]/', $raw ) ) {
			return array();
		}
		$result = array();
		foreach ( explode( ',', $raw ) as $value ) {
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}$address = $value;
			if ( $allowName && preg_match( '/^([^<>]+)<([^<>]+)>$/D', $value, $match ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $allowName and its method binding.
				$address = trim( $match[2] );
				$value   = trim( preg_replace( '/[<>\r\n]/', '', wp_strip_all_tags( $match[1] ) ) ) . ' <' . $address . '>';}
			if ( ! is_email( $address ) ) {
				return array();
			} $result[ $value ] = $value;
		}
		return count( $result ) <= 10 ? array_values( $result ) : array();
	}
	/**
	 * Compose the notification message from the submission and settings.
	 *
	 * @param array $item       Item for this operation.
	 * @param array $definition Form definition being processed.
	 * @param array $values     Values from the current submission.
	 * @param array $context    Context for the requested operation.
	 * @return array|null Result for the requested operation.
	 */
	public static function compose( array $item, array $definition, array $values, array $context = array() ): ?array {
		$to = self::addresses( $item['to'] ?? '', $definition, $values, $context );
		if ( ! $to ) {
			return null; // Optional respondent field or logged-out user tag is empty.
		}
		$subject = trim( preg_replace( '/[\r\n\x00]+/', ' ', wp_strip_all_tags( self::render( $item['subject'] ?? '', $definition, $values, $context ) ) ) );
		if ( '' === $subject ) {
			return null;
		}
		$email    = $context['site_email'] ?? \LeenkatForms\Settings\SiteSettings::email();
		$selected = $item['template'] ?? 'default';
		$template = $context['force_template'] ?? ( 'default' === $selected ? $email['template'] : $selected );
		$plain    = 'plain' === $template;
		$headers  = array( 'Content-Type: ' . ( $plain ? 'text/plain' : 'text/html' ) . '; charset=UTF-8' );
		if ( ! empty( $item['from_email'] ) || ! empty( $item['from_name'] ) ) {
			$from_email = $item['from_email'] ?? '';
			$from       = self::addresses( $from_email ? $from_email : '{admin_email}', $definition, $values, $context );
			if ( 1 !== count( $from ) ) {
				return null;
			}
			$name      = trim( preg_replace( '/[<>\r\n\x00]+/', ' ', wp_strip_all_tags( self::render( $item['from_name'] ?? '', $definition, $values, $context ) ) ) );
			$headers[] = 'From: ' . ( '' !== $name ? $name . ' <' . $from[0] . '>' : $from[0] );
		}
		if ( ! empty( $item['reply_to'] ) ) {
			$reply = self::addresses( $item['reply_to'], $definition, $values, $context, true );
			if ( $reply ) {
				$headers[] = 'Reply-To: ' . implode( ', ', $reply );
			}
		}
		if ( ! empty( $email['carbon_copy'] ) && ! empty( $item['cc'] ) ) {
			$cc_raw = trim( self::render( $item['cc'], $definition, $values, $context ) );
			$cc     = self::addresses( $item['cc'], $definition, $values, $context );
			if ( '' !== $cc_raw && ! $cc ) {
				return null;
			}$to_lower = array_map( 'strtolower', $to );
			$cc        = array_values( array_filter( $cc, static fn( $address )=> ! in_array( strtolower( $address ), $to_lower, true ) ) );
			if ( $cc ) {
				$headers[] = 'Cc: ' . implode( ', ', $cc );
			}
		}
		$source = $item['body'] ?? '{all_fields}';
		// Download credentials belong only to the email body, never sender/recipient headers,
		// subjects, stored values, previews or confirmation rendering.
		$body_context                     = $context;
		$body_context['email_file_links'] = array();
		foreach ( $definition['fields'] ?? array() as $field ) {
			if ( ( $field['type'] ?? '' ) !== 'file_upload' || ! isset( $values[ $field['id'] ] ) ) {
				continue;
			}
			$links = array();foreach ( $context['uploads'] ?? array() as $file ) {
				if ( ( $file['field_id'] ?? '' ) !== $field['id'] ) {
					continue;
				}
				$url = \LeenkatForms\Files\EmailDownload::url( (int) ( $context['entry_id'] ?? 0 ), $file );
				if ( $url ) {
					$links[] = array(
						'name' => sanitize_file_name( (string) ( $file['name'] ?? 'attachment' ) ),
						'url'  => $url,
					);
				}
			}
			if ( $links ) {
				$body_context['email_file_links'][ $field['id'] ] = $links;
				if ( isset( $field['display_id'] ) ) {
					$body_context['email_file_links'][ $field['display_id'] ] = $links;
				}
			}
		}
		$body = $plain ? self::render( wp_strip_all_tags( $source ), $definition, $values, $body_context ) : self::render( wp_kses_post( $source ), $definition, $values, $body_context, true );
		if ( ! $plain ) {
			$body = EmailLayout::render( $body, $template, $definition['title'] ?? '', $email, $context['preview_mode'] ?? 'auto' );
		}
		return array(
			'to'      => $to,
			'subject' => $subject,
			'message' => $body,
			'headers' => $headers,
		);
	}
}
