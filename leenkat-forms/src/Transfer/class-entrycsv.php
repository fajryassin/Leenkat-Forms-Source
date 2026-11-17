<?php
/**
 * Entry CSV export query and field configuration.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Transfer;

/** Selected public answers only; no entry meta, paths, tokens or delivery records. */
final class EntryCsv {
	public const BATCH = 100;
	/**
	 * Build the CSV field list for the selected form.
	 *
	 * @param int $formId Form identifier.
	 * @return array Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function fields( int $formId ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			throw new \RuntimeException( esc_html__( 'Access denied.', 'leenkat-forms' ) );
		}global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Preserve the existing PHP named parameter $formId and its method binding.
		$draft = $wpdb->get_var( $wpdb->prepare( "SELECT draft_definition FROM {$wpdb->prefix}leenfo_forms WHERE id=%d", $formId ) );
		if ( ! is_string( $draft ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Choose an existing form.', 'leenkat-forms' ) );
		}$fields = array();
		$collect = static function ( string $json ) use ( &$fields ): void {
			$d = json_decode( $json, true );
			foreach ( $d['fields'] ?? array() as $f ) {
				if ( is_array( $f ) && is_string( $f['id'] ?? null ) && ! in_array( $f['type'] ?? '', array( 'captcha', 'section_divider', 'page_break' ), true ) && ! isset( $fields[ $f['id'] ] ) && count( $fields ) < 1000 ) {
					$fields[ $f['id'] ] = (string) ( $f['label'] ?? $f['id'] );
				}
			}};
		$collect( $draft );
		$last = PHP_INT_MAX;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Preserve the existing PHP named parameter $formId and its method binding.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,definition FROM {$wpdb->prefix}leenfo_form_versions WHERE form_id=%d AND id<%d ORDER BY id DESC LIMIT 10", $formId, $last ) );
			foreach ( $rows as $row ) {
				$last = (int) $row->id;
				$collect( $row->definition );
			}
			$has_more = 10 === count( $rows ) && count( $fields ) < 1000;
		} while ( $has_more );
		return $fields;
	}
	/**
	 * Parse the supported entry export filters.
	 *
	 * @param int    $formId Form identifier.
	 * @param array  $ids    Ids for this operation.
	 * @param string $status Status of the current record.
	 * @param string $start  Start for this operation.
	 * @param string $end    End for this operation.
	 * @return array Result for the requested operation.
	 * @throws \InvalidArgumentException Raised when an input value or stored definition fails validation.
	 */
	public static function filters( int $formId, array $ids, string $status, string $start, string $end ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		$fields = self::fields( $formId ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		if ( ! $ids || count( $ids ) > 300 || count( array_unique( $ids, SORT_REGULAR ) ) !== count( $ids ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Select between 1 and 300 distinct fields.', 'leenkat-forms' ) );
		}foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! isset( $fields[ $id ] ) ) {
				throw new \InvalidArgumentException( esc_html__( 'An export field is not available for this form.', 'leenkat-forms' ) );
			}
		}
		if ( ! in_array( $status, array( 'all', 'complete', 'spam', 'trashed' ), true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Invalid entry status.', 'leenkat-forms' ) );
		}
		foreach ( array( $start, $end ) as $date ) {
			if ( '' !== $date ) {
				$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
				if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
					throw new \InvalidArgumentException( esc_html__( 'Enter valid dates in YYYY-MM-DD format.', 'leenkat-forms' ) );
				}
			}
		}
		if ( '' !== $start && '' !== $end && $start > $end ) {
			throw new \InvalidArgumentException( esc_html__( 'The end date must be on or after the start date.', 'leenkat-forms' ) );
		}
		return array(
			'form_id' => $formId, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
			'fields'  => $ids,
			'labels'  => array_intersect_key( $fields, array_flip( $ids ) ),
			'status'  => $status,
			'start'   => '' === $start ? '' : $start . ' 00:00:00',
			'end'     => '' === $end ? '' : ( new \DateTimeImmutable( $end, new \DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00:00',
		);
	}
	/**
	 * Build the prepared query conditions for the export.
	 *
	 * @param array $f F for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function where( array $f ): string {
		global $wpdb;
		$where = $wpdb->prepare( 'form_id=%d', $f['form_id'] );
		if ( 'all' !== $f['status'] ) {
			$where .= $wpdb->prepare( ' AND status=%s', $f['status'] );
		}if ( '' !== $f['start'] ) {
			$where .= $wpdb->prepare( ' AND submitted_at_gmt>=%s', $f['start'] );
		}if ( '' !== $f['end'] ) {
			$where .= $wpdb->prepare( ' AND submitted_at_gmt<%s', $f['end'] );
		}return $where;
	}
	/**
	 * Streams at most BATCH decoded entries per iteration, using a fixed upper ID.
	 *
	 * @param mixed $output  Output for this operation.
	 * @param array $filters Filters for this operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function write( $output, array $filters ): array {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			throw new \RuntimeException( esc_html__( 'Access denied.', 'leenkat-forms' ) );
		}
		// Revalidate callers, rather than trusting a previously generated filter object.
		$end = '' === $filters['end'] ? '' : ( new \DateTimeImmutable( $filters['end'], new \DateTimeZone( 'UTC' ) ) )->modify( '-1 day' )->format( 'Y-m-d' );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$f = self::filters( (int) $filters['form_id'], $filters['fields'], $filters['status'], substr( $filters['start'], 0, 10 ), $end );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		global $wpdb;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$where = self::where( $f );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$max = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}leenfo_entries WHERE {$where}" );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$last = 0;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$count = 0;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$peak = 0;
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$definitions = array();
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( esc_html__( 'Could not read entries for export.', 'leenkat-forms' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Native output stream writes the bounded CSV response; filesystem transports do not apply.
		fwrite( $output, "\xEF\xBB\xBF" );
		$headers = array( 'Entry ID', 'Form ID', 'Status', 'Submitted UTC' );
		foreach ( $f['fields'] as $id ) {
			$headers[] = $f['labels'][ $id ] . ' [' . $id . ']';
		}fputcsv( $output, array_map( array( \LeenkatForms\Admin\EntryManager::class, 'csvCell' ), $headers ), ',', '"', '' );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,form_id,form_version_id,status,submitted_at_gmt,payload,meta FROM {$wpdb->prefix}leenfo_entries WHERE {$where} AND id>%d AND id<=%d ORDER BY id LIMIT %d", $last, $max, self::BATCH ) );
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$peak = max( $peak, count( $rows ) );
			if ( $wpdb->last_error ) {
				throw new \RuntimeException( esc_html__( 'Entry export was interrupted by a storage error.', 'leenkat-forms' ) );
			}
			foreach ( $rows as $row ) {
				$last    = (int) $row->id;
				$payload = json_decode( $row->payload, true );
				$version = (int) $row->form_version_id;
				if ( ! isset( $definitions[ $version ] ) ) {
					if ( count( $definitions ) >= 10 ) {
						$definitions = array();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					}$definitions[ $version ] = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $wpdb->get_var( $wpdb->prepare( "SELECT definition FROM {$wpdb->prefix}leenfo_form_versions WHERE id=%d", $version ) ) );
				}$meta   = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $row->meta );
				$payload = \LeenkatForms\Files\FileRecords::safeValues( $definitions[ $version ], is_array( $payload ) ? $payload : array(), $meta['uploads'] ?? array() );
				$line    = array( (string) $row->id, (string) $row->form_id, $row->status, $row->submitted_at_gmt );
				foreach ( $f['fields'] as $id ) {
					$v      = is_array( $payload ) ? ( $payload[ $id ] ?? '' ) : '';
					$line[] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
				}fputcsv( $output, array_map( array( \LeenkatForms\Admin\EntryManager::class, 'csvCell' ), $line ), ',', '"', '' );
				++$count;
			}fflush( $output );
			$full_page = self::BATCH === count( $rows );
		} while ( $full_page );
		return array(
			'rows'          => $count,
			'largest_batch' => $peak,
			'upper_id'      => $max,
		);
	}
}
