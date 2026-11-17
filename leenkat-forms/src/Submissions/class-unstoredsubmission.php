<?php
/**
 * Processing for form submissions configured without entry storage.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Submissions;

/** No answers, recipient addresses or visitor details are persisted in this path. */
final class UnstoredSubmission {
	/**
	 * Process a submission without creating an entry record.
	 *
	 * @param int    $formId       Form identifier.
	 * @param string $uuid         Uuid for this operation.
	 * @param string $hash         Hash for this operation.
	 * @param array  $published    Published for this operation.
	 * @param array  $values       Values from the current submission.
	 * @param array  $pending      Pending for this operation.
	 * @param array  $fieldParts   Field parts for this operation.
	 * @param array  $fieldChoices Field choices for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function process( int $formId, string $uuid, string $hash, array $published, array $values, array $pending, array $fieldParts = array(), array $fieldChoices = array() ): bool { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fieldChoices and its method binding.
		$form_id = $formId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Bind the existing PHP named argument to a local variable.
		global $wpdb;
		$key  = 'leenfo_unstored_' . hash_hmac( 'sha256', $form_id . '|' . $uuid, wp_salt( 'auth' ) );
		$lock = $key . '_lock';
		if ( ! add_option( $lock, (string) ( time() + 120 ), '', false ) ) {
			$expires = (int) get_option( $lock, 0 );
			if ( $expires > 0 && $expires < time() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name=%s AND option_value=%s", $lock, (string) $expires ) );
				wp_cache_delete( $lock, 'options' ); }
			return false;
		}
		$files = array();
		try {
			$receipt = get_transient( $key );
			if ( is_string( $receipt ) ) {
				return hash_equals( $receipt, $hash );
			}
			// Recheck that the same version is still published before any processing.
			$current = \LeenkatForms\Forms\Publisher::published( $form_id );
			if ( ! $current || $current['version_id'] !== $published['version_id'] ) {
				return false;
			}
			foreach ( $pending as $field_id => $upload ) {
				// Unstored files are temporary notification attachments and never receive a Media Library record.
				$before = count( $files );
				$stored = FileUploads::store( $upload['files'], $form_id, $files );
				if ( is_wp_error( $stored ) ) {
					return false;
				}
				$file_count = count( $files );
				for ( $i = $before; $i < $file_count; ++$i ) {
					$files[ $i ]['field_id'] = $field_id; }
				$values[ $field_id ] = implode( ', ', array_column( array_slice( $files, $before ), 'name' ) );
			}
			$settings = \LeenkatForms\Forms\NotificationSettings::normalize( $published['definition'] );
			$user     = wp_get_current_user();
			$context  = array(
				'form_id'       => $form_id,
				'entry_id'      => '',
				'entry_date'    => current_time( 'mysql' ),
				'user_email'    => $user->user_email ?? '',
				'uploads'       => $files,
				'field_parts'   => $fieldParts, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fieldParts and its method binding.
				'field_choices' => $fieldChoices, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $fieldChoices and its method binding.
			);
			foreach ( empty( $settings['enabled'] ) ? array() : $settings['items'] as $config ) {
				$sent_key = $key . '_' . $config['id'];
				$sent     = get_transient( $sent_key );
				if ( is_string( $sent ) ) {
					if ( ! hash_equals( $sent, $hash ) ) {
						return false;
					}continue; }
				// Keep the old no-entry upload attachment behavior for original admin/user notifications.
				if ( ! isset( $published['definition']['settings']['notifications']['items'] ) ) {
					$config['file_attachments'] = true;
					$config['file_fields']      = array_keys( $pending ); }
				$result = \LeenkatForms\Workflow\Notifications::send( $config, $published['definition'], $values, $context );
				if ( 'failed' === $result ) {
					return false;
				}
				if ( ! set_transient( $sent_key, $hash, 24 * 60 * MINUTE_IN_SECONDS ) ) {
					return false;
				}
			}
			// Only a keyed digest is kept temporarily for retry deduplication; no submitted values.
			if ( ! set_transient( $key, $hash, 24 * 60 * MINUTE_IN_SECONDS ) ) {
				return false;
			}
			return true;
		} finally {
			FileUploads::cleanup( $files );
			delete_option( $lock );
		}
	}
}
