<?php
/**
 * Editable notification template preview definitions.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Forms\NotificationSettings;
use LeenkatForms\Workflow\NotificationMessage;

/** Fixed sample data, rendered by the same composer as delivered notifications. */
final class EmailTemplatePreview {
	/**
	 * Return the available templates for email previews.
	 *
	 * @param array|null $email        Email for this operation.
	 * @param bool       $forceDefault Force default for this operation.
	 * @param string     $mode         Mode for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function templates( ?array $email = null, bool $forceDefault = false, string $mode = 'light' ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $forceDefault and its method binding.
		$definition = array(
			'title'  => __( 'Contact Form', 'leenkat-forms' ),
			'fields' => array(
				array(
					'id'    => 'name',
					'type'  => 'text',
					'label' => __( 'Name', 'leenkat-forms' ),
				),
				array(
					'id'    => 'email',
					'type'  => 'email',
					'label' => __( 'Email', 'leenkat-forms' ),
				),
				array(
					'id'    => 'message',
					'type'  => 'textarea',
					'label' => __( 'Message', 'leenkat-forms' ),
				),
			),
		);
		$values     = array(
			'name'    => __( 'Fajr Yassin', 'leenkat-forms' ),
			'email'   => 'fajr@example.test',
			'message' => __( "Hello,\nI'd like to learn more about your services.\nThank you.", 'leenkat-forms' ),
		);
		$item       = array(
			'to'      => 'preview@example.test',
			'subject' => __( 'New Entry: Contact Form', 'leenkat-forms' ),
			'body'    => __( "A new entry was submitted.\n\n", 'leenkat-forms' ) . '{all_fields}',
		);
		$policy     = '<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; img-src http: https:; base-uri &#39;none&#39;; form-action &#39;none&#39;">';
		$previews   = array();
		foreach ( NotificationSettings::TEMPLATES as $template ) {
			$context = array( 'preview_mode' => $mode );
			if ( null !== $email ) {
				$context['site_email'] = $email;
			}if ( $forceDefault ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $forceDefault and its method binding.
				$context['force_template'] = $template;
			}
			$message               = NotificationMessage::compose( $item + array( 'template' => $template ), $definition, $values, $context );
			$plain                 = str_contains( $message['headers'][0], 'text/plain' );
			$document              = $plain ? '<html><head><meta charset="utf-8">' . $policy . '</head><body style="margin:0;padding:24px;background:#fff"><pre style="white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.7 monospace">' . esc_html( $message['message'] ) . '</pre></body></html>' : str_replace( '<head>', '<head>' . $policy, $message['message'] );
			$previews[ $template ] = array(
				'subject'  => $message['subject'],
				'document' => $document,
			);
		}
		return $previews;
	}
}
