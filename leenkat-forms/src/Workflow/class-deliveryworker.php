<?php
/**
 * Scheduling and retry processing for notification email deliveries.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Workflow;

/** At-least-once email delivery. External mail transport cannot guarantee exactly once. */
final class DeliveryWorker {
	/**
	 * Register delivery and reconciliation hooks.
	 */
	public static function hooks(): void {
		add_action( 'leenfo_send_delivery', array( self::class, 'process' ), 10, 1 );
		add_action( 'leenfo_reconcile_deliveries', array( self::class, 'reconcile' ) );
		add_action( 'init', array( self::class, 'ensureReconciler' ), 20 );
	}
	/**
	 * Ensure the notification reconciliation job is scheduled.
	 */
	public static function ensureReconciler(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! wp_next_scheduled( 'leenfo_reconcile_deliveries' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'leenfo_reconcile_deliveries' );
		}
	}
	/**
	 * Schedule a delivery worker for the specified job.
	 *
	 * @param int $id        Record identifier.
	 * @param int $timestamp Timestamp for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function schedule( int $id, int $timestamp = 0 ): bool {
		$timestamp = $timestamp ? $timestamp : time() + 5;
		$args      = array( $id );
		if ( function_exists( 'as_schedule_single_action' ) && class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized() ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'leenfo_send_delivery', $args, 'leenkat-forms' ) ) {
				return true; }
			return (int) as_schedule_single_action( $timestamp, 'leenfo_send_delivery', $args, 'leenkat-forms', true ) > 0;
		}
		if ( wp_next_scheduled( 'leenfo_send_delivery', $args ) ) {
			return true; }
		return true === wp_schedule_single_event( $timestamp, 'leenfo_send_delivery', $args );
	}
	/**
	 * Claim and process the notification delivery request.
	 *
	 * @param int $id Record identifier.
	 */
	public static function process( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$entry_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM {$wpdb->prefix}leenfo_deliveries WHERE id=%d", $id ) );
		$lease    = null;
		try {
			if ( $entry_id && \LeenkatForms\Files\FileRecords::notificationFiles( $entry_id ) ) {
				$lease = \LeenkatForms\Files\FileLease::acquire( $entry_id );
			}self::process_with_lease( $id );} catch ( \Throwable ) {
			// Pending jobs retain their trusted references for reconciliation.
			return;
			} finally {
				\LeenkatForms\Files\FileLease::release( $lease );}
	}
	/**
	 * Process a claimed delivery while holding its lease.
	 *
	 * @param int $id Record identifier.
	 */
	private static function process_with_lease( int $id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'leenfo_deliveries';
		$now   = current_time( 'mysql', true );
		// The conditional UPDATE is the claim; only one worker can send this row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status='processing',attempt_count=attempt_count+1,updated_at_gmt=%s WHERE id=%d AND status IN ('pending','retry') AND (next_attempt_at_gmt IS NULL OR next_attempt_at_gmt<=%s)", $now, $id, $now ) );
		if ( 1 !== $claimed ) {
			return; }
		$entries  = $wpdb->prefix . 'leenfo_entries';
		$versions = $wpdb->prefix . 'leenfo_form_versions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT d.id,d.kind,d.destination_key,d.attempt_count,e.id AS entry_id,e.form_id,e.user_id,e.submitted_at_gmt,e.meta,e.status AS entry_status,e.payload,v.definition FROM `{$table}` d INNER JOIN `{$entries}` e ON e.id=d.entry_id INNER JOIN `{$versions}` v ON v.id=e.form_version_id WHERE d.id=%d", $id ) );
		if ( ! $row || 'complete' !== $row->entry_status ) {
			self::finish( $id, 'cancelled', 'entry_unavailable' );
			return; }
		$values     = json_decode( (string) $row->payload, true );
		$definition = json_decode( (string) $row->definition, true );
		if ( ! is_array( $values ) || ! is_array( $definition ) ) {
			self::finish( $id, 'failed', 'invalid_definition' );
			return; }
		$settings        = \LeenkatForms\Forms\NotificationSettings::normalize( $definition );
		$notification_id = 'email_admin' === $row->kind ? 'legacy_admin' : ( 'email_user' === $row->kind ? 'legacy_user' : $row->destination_key );
		$config          = null;
		foreach ( $settings['items'] as $item ) {
			if ( $item['id'] === $notification_id ) {
				$config = $item;
			}
		}
		if ( empty( $settings['enabled'] ) || ! $config ) {
			self::finish( $id, 'cancelled', 'notification_unavailable' );
			return; }
		$meta                     = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $row->meta );
		$user                     = $row->user_id ? get_userdata( (int) $row->user_id ) : null;
		$values                   = \LeenkatForms\Files\FileRecords::safeValues( $definition, $values, $meta['uploads'] ?? array() );
		$context                  = array(
			'entry_id'    => (int) $row->entry_id,
			'form_id'     => (int) $row->form_id,
			'entry_date'  => get_date_from_gmt( $row->submitted_at_gmt, 'Y-m-d H:i:s' ),
			'user_email'  => $user ? $user->user_email : '',
			'uploads'     => \LeenkatForms\Files\FileRecords::notificationFiles( (int) $row->entry_id ),
			'field_parts' => $meta['field_parts'] ?? array(),
		);
		$context['field_choices'] = $meta['field_choices'] ?? array();
		$result                   = Notifications::send( $config, $definition, $values, $context );
		if ( 'skipped' === $result ) {
			self::finish( $id, 'cancelled', 'condition_or_recipient_empty' );
			return; }
		$sent = 'sent' === $result;
		if ( $sent ) {
			self::finish( $id, 'sent', null );
			return; }
		$attempts = (int) $row->attempt_count;
		if ( $attempts >= 4 ) {
			self::finish( $id, 'failed', 'mail_failed' );
			return; }
		$delay = min( 3600, 60 * ( 2 ** $attempts ) );
		$next  = gmdate( 'Y-m-d H:i:s', time() + $delay );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$wpdb->update(
			$table,
			array(
				'status'              => 'retry',
				'next_attempt_at_gmt' => $next,
				'last_error_code'     => 'mail_failed',
				'updated_at_gmt'      => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		self::schedule( $id, time() + $delay );
	}
	/**
	 * Render the message body using its configured email template.
	 *
	 * @param string $template   Template for this operation.
	 * @param array  $definition Form definition being processed.
	 * @param array  $values     Values from the current submission.
	 * @return string Result for the requested operation.
	 */
	public static function renderTemplate( string $template, array $definition, array $values ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return NotificationMessage::render( $template, $definition, $values );
	}

	/**
	 * Persist the outcome of the current delivery attempt.
	 *
	 * @param int         $id     Record identifier.
	 * @param string      $status Status of the current record.
	 * @param string|null $error  Error for this operation.
	 */
	private static function finish( int $id, string $status, ?string $error ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$wpdb->update(
			$wpdb->prefix . 'leenfo_deliveries',
			array(
				'status'          => $status,
				'last_error_code' => $error,
				'updated_at_gmt'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}
	/**
	 * Find and reschedule notification deliveries that still need processing.
	 */
	public static function reconcile(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'leenfo_deliveries';
		$now   = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - 900 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET status='retry',next_attempt_at_gmt=%s,last_error_code='interrupted',updated_at_gmt=%s WHERE status='processing' AND updated_at_gmt<%s", $now, $now, $stale ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE status IN ('pending','retry') AND (next_attempt_at_gmt IS NULL OR next_attempt_at_gmt<=%s) ORDER BY id ASC LIMIT 50", $now ) );
		foreach ( $ids ? $ids : array() as $id ) {
			self::schedule( (int) $id ); }
	}
}
