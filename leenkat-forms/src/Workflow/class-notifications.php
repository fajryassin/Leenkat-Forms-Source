<?php
/**
 * Creation and processing of notification outbox records.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Workflow;

/** Creates durable delivery intents inside the entry transaction. */
final class Notifications {
	/**
	 * Create delivery records for the notifications selected for an entry.
	 *
	 * @param int    $entryId    Entry identifier.
	 * @param array  $definition Form definition being processed.
	 * @param array  $values     Values from the current submission.
	 * @param string $now        Now for this operation.
	 * @return array|false Result for the requested operation.
	 */
	public static function createOutbox( int $entryId, array $definition, array $values, string $now ): array|false { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing callable method name for PHP callers and callback compatibility. Preserve the existing PHP named parameter $entryId and its method binding.
		global $wpdb;
		$table    = $wpdb->prefix . 'leenfo_deliveries';
		$settings = \LeenkatForms\Forms\NotificationSettings::normalize( $definition );
		$ids      = array();
		if ( empty( $settings['enabled'] ) ) {
			return array();
		}
		foreach ( $settings['items'] as $config ) {
			if ( ! \LeenkatForms\Forms\NotificationSettings::shouldSend( $config, $values ) ) {
				continue; }
			$key = hash( 'sha256', 'lkf-email|' . $entryId . '|' . $config['id'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
			$ok = $wpdb->insert(
				$table,
				array(
					'entry_id'            => $entryId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
					'kind'                => 'email_notification',
					'destination_key'     => $config['id'],
					'status'              => 'pending',
					'attempt_count'       => 0,
					'next_attempt_at_gmt' => $now,
					'idempotency_key'     => $key,
					'created_at_gmt'      => $now,
					'updated_at_gmt'      => $now,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( ! $ok ) {
				return false; }
			$ids[] = (int) $wpdb->insert_id;
		}
		return $ids;
	}
	/**
	 * Send the notifications applicable to the submission.
	 *
	 * @param array $config     Config for this operation.
	 * @param array $definition Form definition being processed.
	 * @param array $values     Values from the current submission.
	 * @param array $context    Context for the requested operation.
	 * @return string Result for the requested operation.
	 */
	public static function send( array $config, array $definition, array $values, array $context = array() ): string {
		if ( ! \LeenkatForms\Forms\NotificationSettings::shouldSend( $config, array_replace( $values, $context['field_choices'] ?? array() ) ) ) {
			return 'skipped';
		}
		$message = NotificationMessage::compose( $config, $definition, $values, $context );
		if ( ! $message ) {
			return 'skipped';
		}
		$attachments = MailAttachments::prepare( $config, $definition, $values, $context );
		return false !== $attachments && MailAttachments::send( $message, $attachments ) ? 'sent' : 'failed';
	}
}
