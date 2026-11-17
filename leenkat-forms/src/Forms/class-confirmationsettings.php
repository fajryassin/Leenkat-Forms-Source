<?php
/**
 * Form confirmation validation, rendering and redirect settings.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

use LeenkatForms\Workflow\NotificationMessage;

/**
 * Form confirmation validation, rendering and redirect settings.
 */
final class ConfirmationSettings {
	/**
	 * Return the default confirmation configuration.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function defaults(): array {
		return array(
			'id'             => 'c_default',
			'name'           => __( 'Default Confirmation', 'leenkat-forms' ),
			'type'           => 'message',
			'message'        => __( 'Thank you. Your response was submitted.', 'leenkat-forms' ),
			'message_scroll' => true,
			'entry_preview'  => false,
			'page_id'        => 0,
			'redirect'       => '',
		); }
	/**
	 * Normalize the supplied confirmation settings.
	 *
	 * @param array $definition Form definition being processed.
	 * @return array Result for the requested operation.
	 */
	public static function normalize( array $definition ): array {
		if ( isset( $definition['settings']['confirmations'] ) ) {
			return $definition['settings']['confirmations'];
		}
		$item   = self::defaults();
		$legacy = $definition['settings']['confirmation']['message'] ?? '';
		if ( is_string( $legacy ) && '' !== $legacy ) {
			$item['message'] = esc_html( wp_strip_all_tags( $legacy ) );
		}
		return array( 'items' => array( $item ) );
	}
	/**
	 * Check whether a confirmation URL is allowed.
	 *
	 * @param string $url Url for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function validUrl( string $url ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$parts = wp_parse_url( $url );
		return ! preg_match( '/[\r\n\x00]|%0[ad]/i', $url ) && false !== filter_var( $url, FILTER_VALIDATE_URL ) && is_array( $parts ) && in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}
	/**
	 * Resolve the configured WordPress confirmation page.
	 *
	 * @param int $id Record identifier.
	 * @return string|null Result for the requested operation.
	 */
	public static function page( int $id ): ?string {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return null;
		}
		$url = get_permalink( $id );
		return is_string( $url ) && self::validUrl( $url ) ? $url : null;
	}
	/**
	 * Validate the normalized form confirmation settings.
	 *
	 * @param mixed $settings     Settings used by the current operation.
	 * @param array $fields       Fields handled by this operation.
	 * @param bool  $publication  Publication for this operation.
	 * @param bool  $resolvePages Resolve pages for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function validate( mixed $settings, array $fields, bool $publication, bool $resolvePages = true ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $resolvePages and its method binding.
		if ( ! is_array( $settings ) || ! is_array( $settings['items'] ?? null ) || ! array_is_list( $settings['items'] ) || ! $settings['items'] || count( $settings['items'] ) > 20 ) {
			return array( __( 'Add between one and twenty confirmations.', 'leenkat-forms' ) );
		}
		$errors = array();
		$ids    = array();
		foreach ( $settings['items'] as $index => $item ) {
			if ( ! is_array( $item ) ) {
				$errors[] = __( 'Invalid confirmation.', 'leenkat-forms' );
				continue;}
			$id = $item['id'] ?? null;
			if ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $id ) || isset( $ids[ $id ] ) ) {
				$errors[] = __( 'Invalid or duplicate confirmation identifier.', 'leenkat-forms' );
			}
			if ( is_string( $id ) ) {
				$ids[ $id ] = true;
			}
			foreach ( array(
				'name'     => 100,
				'message'  => 20000,
				'redirect' => 2048,
			) as $key => $limit ) {
				if ( isset( $item[ $key ] ) && ( ! is_string( $item[ $key ] ) || strlen( $item[ $key ] ) > $limit ) ) {
					$errors[] = __( 'Invalid confirmation ', 'leenkat-forms' ) . $key . '.';
				}
			}
			if ( ! is_string( $item['name'] ?? null ) || '' === trim( $item['name'] ) ) {
				$errors[] = __( 'Name every confirmation.', 'leenkat-forms' );
			}
			if ( ! in_array( $item['type'] ?? '', array( 'message', 'page', 'redirect' ), true ) ) {
				$errors[] = __( 'Invalid confirmation type.', 'leenkat-forms' );
			}
			foreach ( array( 'message_scroll', 'entry_preview' ) as $flag ) {
				if ( isset( $item[ $flag ] ) && ! is_bool( $item[ $flag ] ) ) {
					$errors[] = __( 'Invalid confirmation flag.', 'leenkat-forms' );
				}
			}
			if ( isset( $item['page_id'] ) && ( ! is_int( $item['page_id'] ) || $item['page_id'] < 0 ) ) {
				$errors[] = __( 'Invalid confirmation page.', 'leenkat-forms' );
			}
			if ( $publication ) {
				if ( ( $item['type'] ?? '' ) === 'message' && ( ! is_string( $item['message'] ?? null ) || '' === trim( wp_strip_all_tags( $item['message'] ) ) ) ) {
					$errors[] = __( 'Enter a confirmation message.', 'leenkat-forms' );
				}
				if ( ( $item['type'] ?? '' ) === 'page' && ( ! is_int( $item['page_id'] ?? null ) || $item['page_id'] <= 0 || ( $resolvePages && ! self::page( $item['page_id'] ) ) ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $resolvePages and its method binding.
					$errors[] = __( 'Select a published public confirmation page.', 'leenkat-forms' );
				}
				if ( ( $item['type'] ?? '' ) === 'redirect' && ( ! is_string( $item['redirect'] ?? null ) || ! self::validUrl( $item['redirect'] ) ) ) {
					$errors[] = __( 'Enter a complete http:// or https:// confirmation URL.', 'leenkat-forms' );
				}
			}
			if ( isset( $item['condition'] ) ) {
				// Use the same validated AND/OR grammar as notification delivery.
				$condition_errors = NotificationSettings::validate(
					array(
						'enabled' => true,
						'items'   => array(
							array(
								'id'        => 'c_rule',
								'name'      => 'Confirmation',
								'to'        => 'sample@example.test',
								'subject'   => 'Sample',
								'body'      => 'Sample',
								'condition' => $item['condition'],
							),
						),
					),
					$fields,
					$publication
				);
				foreach ( $condition_errors as $error ) {
					$errors[] = str_ireplace( 'notification', 'confirmation', $error );
				}
			}
		}
		return array_values( array_unique( $errors ) );
	}
	/**
	 * Build the confirmation response for the completed submission.
	 *
	 * @param array $definition Form definition being processed.
	 * @param array $values     Values from the current submission.
	 * @param array $context    Context for the requested operation.
	 * @return array Result for the requested operation.
	 */
	public static function response( array $definition, array $values = array(), array $context = array() ): array {
		$items             = self::normalize( $definition )['items'];
		$chosen            = null;
		$comparison_values = array_replace( $values, $context['field_choices'] ?? array() );
		// Additional confirmations retain newest-first priority. The default's
		// optional condition is evaluated only after none of those apply.
		for ( $i = count( $items ) - 1;$i > 0;$i-- ) {
			if ( NotificationSettings::shouldSend( array( 'condition' => $items[ $i ]['condition'] ?? null ), $comparison_values ) ) {
				$chosen = $items[ $i ];
				break;}
		}
		if ( null === $chosen && NotificationSettings::shouldSend( array( 'condition' => $items[0]['condition'] ?? null ), $comparison_values ) ) {
			$chosen = $items[0];
		}
		// A saved submission remains successful when all confirmations are gated
		// out. No configured Smart Tags, redirect or entry preview is evaluated.
		$chosen ??= self::defaults();
		$type     = $chosen['type'] ?? 'message';
		if ( 'page' === $type || 'redirect' === $type ) {
			$url = 'page' === $type ? self::page( (int) ( $chosen['page_id'] ?? 0 ) ) : ( $chosen['redirect'] ?? '' );
			if ( is_string( $url ) && self::validUrl( $url ) ) {
				return array(
					'message'      => __( 'Redirecting…', 'leenkat-forms' ),
					'confirmation' => array(
						'type' => 'redirect',
						'url'  => $url,
					),
					'unstored'     => ! empty( $definition['settings']['general']['disable_entries'] ),
				);
			}
			$chosen = self::defaults(); // Page was removed after publication: acknowledge the saved submission.
		}
		$html = wp_kses_post( NotificationMessage::render( wp_kses_post( $chosen['message'] ?? '' ), $definition, $values, $context, true ) );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			$html = esc_html( self::defaults()['message'] );
		}
		$preview = ! empty( $chosen['entry_preview'] ) ? wp_kses_post( NotificationMessage::render( '{all_fields}', $definition, $values, $context, true ) ) : '';
		return array(
			'message'      => wp_strip_all_tags( $html ),
			'confirmation' => array(
				'type'          => 'message',
				'html'          => $html,
				'scroll'        => ( $chosen['message_scroll'] ?? true ) !== false,
				'entry_preview' => ! empty( $chosen['entry_preview'] ),
				'preview_html'  => $preview,
			),
			'unstored'     => ! empty( $definition['settings']['general']['disable_entries'] ),
		);
	}
	/**
	 * Render the confirmed form-submission message.
	 *
	 * @param array $confirmation Confirmation for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function markup( array $confirmation ): string {
		$html = wp_kses_post( $confirmation['html'] ?? '' );
		if ( ! empty( $confirmation['preview_html'] ) ) {
			$html .= '<section class="lkf-entry-preview"><h3>' . esc_html__( 'Your submission', 'leenkat-forms' ) . '</h3>' . wp_kses_post( $confirmation['preview_html'] ) . '</section>';
		}
		return '<div class="lkf-confirmation" role="status" aria-live="polite" tabindex="-1" data-lkf-confirmation data-scroll="' . ( ! empty( $confirmation['scroll'] ) ? '1' : '0' ) . '">' . $html . '</div>';
	}
}
