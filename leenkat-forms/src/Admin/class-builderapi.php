<?php
/**
 * REST handlers for the administrative form builder.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Forms\DefinitionCodec;
use LeenkatForms\Forms\DefinitionValidator;
use LeenkatForms\Forms\Publisher;
use LeenkatForms\Infrastructure\Database\Transactions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Authenticated draft API. Published versions are written only by Publisher. */
final class BuilderApi {
	/**
	 * Register the form builder REST endpoints.
	 */
	public static function hooks(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/forms',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'create' ),
						'permission_callback' => array( self::class, 'canEdit' ),
					)
				);
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/forms/(?P<id>\d+)',
					array(
						array(
							'methods'             => 'GET',
							'callback'            => array( self::class, 'get' ),
							'permission_callback' => array( self::class, 'canEdit' ),
						),
						array(
							'methods'             => 'POST',
							'callback'            => array( self::class, 'update' ),
							'permission_callback' => array( self::class, 'canEdit' ),
						),
					)
				);
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/forms/(?P<id>\d+)/duplicate',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'duplicate' ),
						'permission_callback' => array( self::class, 'canEdit' ),
					)
				);
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/forms/(?P<id>\d+)/publish',
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'publish' ),
						'permission_callback' => array( self::class, 'canEdit' ),
					)
				);
			}
		);
	}
	/**
	 * Check whether the current user may manage forms.
	 *
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function canEdit(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ); }
	/**
	 * Build the site-specific forms table name.
	 *
	 * @return string Result for the requested operation.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'leenfo_forms'; }
	/**
	 * Validate a submitted builder definition before writing it.
	 *
	 * @param WP_REST_Request $request Incoming WordPress request.
	 * @return array|WP_Error Result for the requested operation.
	 */
	private static function validated( WP_REST_Request $request ): array|WP_Error {
		if ( strlen( $request->get_body() ) > 131072 ) {
			return new WP_Error( 'leenfo_too_large', __( 'Form definition is too large.', 'leenkat-forms' ), array( 'status' => 413 ) ); }
		$data       = $request->get_json_params();
		$definition = is_array( $data ) ? ( $data['definition'] ?? null ) : null;
		if ( ! is_array( $definition ) ) {
			return self::invalid(); }
		$errors = DefinitionValidator::validate( $definition );
		if ( $errors ) {
			return new WP_Error( 'leenfo_definition', implode( ' ', $errors ), array( 'status' => 400 ) ); }
		return $definition;
	}
	/**
	 * Build the invalid form definition response.
	 *
	 * @return WP_Error Result for the requested operation.
	 */
	private static function invalid(): WP_Error {
		return new WP_Error( 'leenfo_invalid', __( 'Invalid form request.', 'leenkat-forms' ), array( 'status' => 400 ) ); }
	/**
	 * Build the response for a form that cannot be found.
	 *
	 * @return WP_Error Result for the requested operation.
	 */
	private static function missing(): WP_Error {
		return new WP_Error( 'leenfo_missing', __( 'Form not found.', 'leenkat-forms' ), array( 'status' => 404 ) ); }
	/**
	 * Build the builder response from the stored form row.
	 *
	 * @param object $row Stored record for this operation.
	 * @return WP_REST_Response Result for the requested operation.
	 */
	private static function response( object $row ): WP_REST_Response {
		global $wpdb;
		$definition         = json_decode( (string) $row->draft_definition, true );
		$published_revision = null;
		if ( (int) ( $row->published_version_id ?? 0 ) > 0 ) {
			$versions = $wpdb->prefix . 'leenfo_form_versions';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$published_revision = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					"SELECT definition_hash FROM `{$versions}` WHERE id=%d AND form_id=%d",
					(int) $row->published_version_id,
					(int) $row->id
				)
			);
		}
		return new WP_REST_Response(
			array(
				'id'                 => (int) $row->id,
				'status'             => (string) $row->status,
				'definition'         => is_array( $definition ) ? $definition : null,
				'revision'           => hash( 'sha256', (string) $row->draft_definition ),
				'published_revision' => is_string( $published_revision ) ? $published_revision : null,
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				'has_entries'        => current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}leenfo_entries WHERE form_id=%d AND status<>'trashed' LIMIT 1", (int) $row->id ) ),
			)
		);
	}
	/**
	 * Read the current builder state for a form.
	 *
	 * @param WP_REST_Request $request Incoming WordPress request.
	 * @return WP_REST_Response|WP_Error Result for the requested operation.
	 */
	public static function get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,status,draft_definition,published_version_id FROM `{$table}` WHERE id=%d", absint( $request['id'] ) ) );
		return $row ? self::response( $row ) : self::missing();
	}
	/**
	 * Create a new form for the builder.
	 *
	 * @param WP_REST_Request $request Incoming WordPress request.
	 * @return WP_REST_Response|WP_Error Result for the requested operation.
	 */
	public static function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		$definition = self::validated( $request );
		if ( is_wp_error( $definition ) ) {
			return $definition; }
		$encoded = DefinitionCodec::encode( $definition );
		$now     = current_time( 'mysql', true );
		$table   = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
		$ok = $wpdb->insert(
			$table,
			array(
				'uuid'             => wp_generate_uuid4(),
				'author_id'        => get_current_user_id(),
				'status'           => 'draft',
				'draft_definition' => $encoded,
				'created_at_gmt'   => $now,
				'updated_at_gmt'   => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'leenfo_storage', __( 'Could not create form.', 'leenkat-forms' ), array( 'status' => 503 ) ); }
		return new WP_REST_Response(
			array(
				'id'         => (int) $wpdb->insert_id,
				'status'     => 'draft',
				'definition' => $definition,
				'revision'   => hash( 'sha256', $encoded ),
			),
			201
		);
	}
	/**
	 * Persist an updated builder draft.
	 *
	 * @param WP_REST_Request $request Incoming WordPress request.
	 * @return WP_REST_Response|WP_Error Result for the requested operation.
	 */
	public static function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;
		$definition = self::validated( $request );
		if ( is_wp_error( $definition ) ) {
			return $definition; }
		$data     = $request->get_json_params();
		$revision = $data['revision'] ?? null;
		if ( ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) ) {
			return self::invalid(); }
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		if ( ! Transactions::supported( array( $table ) ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new WP_Error( 'leenfo_storage', __( 'Form storage is unavailable.', 'leenkat-forms' ), array( 'status' => 503 ) );
		}
		$id = absint( $request['id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,status,draft_definition FROM `{$table}` WHERE id=%d FOR UPDATE", $id ) );
		if ( ! $row || 'trashed' === $row->status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$wpdb->query( 'ROLLBACK' );
			return self::missing(); }
		if ( ! hash_equals( hash( 'sha256', (string) $row->draft_definition ), $revision ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'leenfo_conflict', __( 'This form changed in another session. Reload before saving.', 'leenkat-forms' ), array( 'status' => 409 ) );
		}
		$encoded = DefinitionCodec::encode( $definition );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$ok = $wpdb->update(
			$table,
			array(
				'draft_definition' => $encoded,
				'updated_at_gmt'   => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		if ( false === $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'leenfo_storage', __( 'Could not save form.', 'leenkat-forms' ), array( 'status' => 503 ) );
		}
		return new WP_REST_Response(
			array(
				'id'         => $id,
				'status'     => (string) $row->status,
				'definition' => $definition,
				'revision'   => hash( 'sha256', $encoded ),
			)
		);
	}
	/**
	 * Duplicate a stored form for editing.
	 *
	 * @param WP_REST_Request $request Incoming WordPress request.
	 * @return WP_REST_Response|WP_Error Result for the requested operation.
	 */
	public static function duplicate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$original = self::get( $request );
		if ( is_wp_error( $original ) ) {
			return $original; }
		$data       = $original->get_data();
		$definition = $data['definition'];
		if ( ! is_array( $definition ) ) {
			return self::invalid(); }
		$definition['title'] = sprintf( /* translators: %s: original form title. */ __( '%s (Copy)', 'leenkat-forms' ), (string) ( $definition['title'] ?? __( 'Untitled Form', 'leenkat-forms' ) ) );
		if ( DefinitionValidator::validate( $definition ) !== array() ) {
			return self::invalid(); }
		global $wpdb;
		$table   = self::table();
		$now     = current_time( 'mysql', true );
		$encoded = DefinitionCodec::encode( $definition );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
		$ok = $wpdb->insert(
			$table,
			array(
				'uuid'             => wp_generate_uuid4(),
				'author_id'        => get_current_user_id(),
				'status'           => 'draft',
				'draft_definition' => $encoded,
				'created_at_gmt'   => $now,
				'updated_at_gmt'   => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'leenfo_storage', __( 'Could not duplicate form.', 'leenkat-forms' ), array( 'status' => 503 ) ); }
		return new WP_REST_Response(
			array(
				'id'         => (int) $wpdb->insert_id,
				'status'     => 'draft',
				'definition' => $definition,
				'revision'   => hash( 'sha256', $encoded ),
			),
			201
		);
	}
	/**
	 * Publish a validated form draft.
	 *
	 * @param WP_REST_Request $request Incoming WordPress request.
	 * @return WP_REST_Response|WP_Error Result for the requested operation.
	 */
	public static function publish( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = absint( $request['id'] );
		$errors = array();
		if ( ! Publisher::publish( $id, $errors ) ) {
			return new WP_Error( 'leenfo_publish', $errors[0] ?? __( 'Could not publish form. Please try again later.', 'leenkat-forms' ), array( 'status' => 400 ) ); }
		return new WP_REST_Response(
			array(
				'published' => true,
				'id'        => $id,
			)
		);
	}
}
