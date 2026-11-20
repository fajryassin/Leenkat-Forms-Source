<?php
/**
 * Handling for normal form submissions without JavaScript.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Submissions;

use WP_REST_Request;

/**
 * Handling for normal form submissions without JavaScript.
 */
final class NativeSubmission {
	/**
	 * Confirmation receipt available for this request.
	 *
	 * @var array|null
	 */
	private static ?array $memory_receipt = null;
	/**
	 * Register the native submission action.
	 */
	public static function hooks(): void {
		ConfirmationReceipt::hooks();
		add_action(
			'template_redirect',
			static function (): void {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Anonymous public form POST is validated by the existing authoritative endpoint, signed protection/CAPTCHA policy and idempotency checks; no administrative action is exposed.
				if ( isset( $_GET['leenfo_receipt'] ) ) {
					ConfirmationReceipt::privateHeaders();
				}
				// Reuse the authoritative endpoint; only the no-storage return rendering differs.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Valid method and length literals contain no slash-sensitive characters. Strict literal comparisons reject malformed metadata before use or mutation. Anonymous public form POST is validated by the existing authoritative endpoint, signed protection/CAPTCHA policy and idempotency checks; no administrative action is exposed.
				if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' && ( $_POST['action'] ?? '' ) === 'leenfo_submit' ) {
					self::process( true );
				}
			},
			0
		);
		add_action( 'admin_post_leenfo_submit', array( self::class, 'handle' ) );
		add_action( 'admin_post_nopriv_leenfo_submit', array( self::class, 'handle' ) );
	}
	/**
	 * Accept a verified request to the native submission endpoint.
	 */
	public static function handle(): void {
		self::process( false );}
	/**
	 * Process the submitted native form data.
	 *
	 * @param bool $same_page Same page for this operation.
	 */
	private static function process( bool $same_page ): void {
		$fields  = array( 'submission_uuid', 'fields', 'email_confirmations', 'captcha_tokens', 'website_confirmation', 'security_token', 'security_traps', 'service_captcha_token' );
		$allowed = array_fill_keys( array( ...$fields, 'form_id', 'form_instance', 'return_url' ), true );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Anonymous submissions use the published-schema, signed protection/CAPTCHA and replay checks in PublicEndpoint.
		$post    = wp_unslash( array_intersect_key( $_POST, $allowed ) );
		$form_id = $post['form_id'] ?? '';
		$id      = \LeenkatForms\Security\RequestInput::id( $form_id );
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', $id );
		$request->set_header( 'content-type', 'multipart/form-data' );
		$length = is_string( $_SERVER['CONTENT_LENGTH'] ?? null )
			? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : '';
		if ( isset( $_SERVER['CONTENT_LENGTH'] ) && ! ctype_digit( $length ) ) {
			wp_die( esc_html__( 'Invalid request length.', 'leenkat-forms' ), '', array( 'response' => 400 ) );
		}
		$request->set_header( 'content-length', '' === $length ? '0' : (string) (int) $length );
		// PublicEndpoint validates each payload value using the published field schema and checks temporary upload provenance.
		$request->set_param( 'payload', wp_json_encode( array_intersect_key( $post, array_fill_keys( $fields, true ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- PublicEndpoint checks the published file schema and FileUploads verifies PHP-upload provenance.
		$request->set_file_params( $_FILES );
		$result       = PublicEndpoint::submit( $request );
		$data         = is_wp_error( $result ) ? array() : $result->get_data();
		$confirmation = $data['confirmation'] ?? array();
		if ( ( $confirmation['type'] ?? '' ) === 'redirect' && \LeenkatForms\Forms\ConfirmationSettings::validUrl( $confirmation['url'] ?? '' ) ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Configured external URL confirmations intentionally allow navigation after ConfirmationSettings::validUrl rejects unsafe schemes and credentials.
			wp_redirect( $confirmation['url'], 303 );
			exit;}
		if ( ! empty( $confirmation['entry_preview'] ) ) {
			if ( ! empty( $data['unstored'] ) ) {
				ConfirmationReceipt::document( $confirmation );
			}
			wp_safe_redirect( $data['receipt_url'], 303 );
			exit;
		}
		$receipt = array(
			'form'         => $id,
			'instance'     => max( 1, min( 100, \LeenkatForms\Security\RequestInput::id( $post['form_instance'] ?? 1 ) ) ),
			'error'        => is_wp_error( $result ),
			'message'      => is_wp_error( $result ) ? $result->get_error_message() : $data['message'],
			'confirmation' => is_wp_error( $result ) ? null : $confirmation,
		);
		if ( is_wp_error( $result ) ) {
			$receipt['field_errors'] = $result->get_error_data()['field_errors'] ?? array();
		}
		if ( ! empty( $receipt['field_errors'] ) ) {
			if ( ! $same_page ) {
				wp_safe_redirect( self::return_url( $post ), 307 );
				exit;}
			// Rejected values remain in this request only; preserve conditional source
			// selections and valid controls without putting answers in an error receipt.
			$receipt['fields']              = is_array( $post['fields'] ?? null ) ? $post['fields'] : array();
			$receipt['email_confirmations'] = is_array( $post['email_confirmations'] ?? null ) ? $post['email_confirmations'] : array();
			ConfirmationReceipt::privateHeaders();
			status_header( max( 400, min( 599, (int) ( $result->get_error_data()['status'] ?? 400 ) ) ) );
			self::$memory_receipt = $receipt;
			return;
		}
		if ( ! empty( $data['unstored'] ) && $same_page ) {
			// No answers, including personalized Smart Tags, are persisted when
			// entry storage is disabled. Render within the original theme in memory.
			ConfirmationReceipt::privateHeaders();
			self::$memory_receipt = $receipt;
			return;
		}
		if ( ! empty( $data['unstored'] ) ) {
			// Forward the original POST, keeping the existing UUID. The authoritative
			// endpoint acknowledges this retry without sending notifications twice.
			// A 307 keeps answers out of persistent receipts, cookies and URLs.
			wp_safe_redirect( self::return_url( $post ), 307 );
			exit;
		}
		$token = wp_generate_password( 32, false, false );
		set_transient( 'leenfo_result_' . $token, $receipt, ConfirmationReceipt::TTL );
		$return = self::return_url( $post );
		$return = remove_query_arg( array( 'leenfo_receipt', 'leenfo_result_form' ), $return );
		wp_safe_redirect(
			add_query_arg(
				array(
					'leenfo_receipt'     => $token,
					'leenfo_result_form' => $id,
				),
				$return
			),
			303
		);
		exit;
	}
	/**
	 * Resolve the validated return URL for a native form request.
	 *
	 * @param array $post Post for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function return_url( array $post ): string {
		$return = wp_validate_redirect( is_string( $post['return_url'] ?? null ) ? $post['return_url'] : '', home_url( '/' ) );
		$target = wp_parse_url( $return );
		$site   = wp_parse_url( home_url( '/' ) );
		foreach ( array( 'scheme', 'host', 'port' ) as $key ) {
			if ( ( $target[ $key ] ?? null ) !== ( $site[ $key ] ?? null ) ) {
				return home_url( '/' );
			}
		}
		if ( isset( $target['user'] ) || isset( $target['pass'] ) || str_contains( $target['path'] ?? '', '/wp-admin/' ) ) {
			return home_url( '/' );
		}
		return $return;
	}
	/**
	 * Redirect to or render the confirmation for a native submission.
	 *
	 * @param int      $id       Record identifier.
	 * @param array    $query    Query for this operation.
	 * @param int|null $instance Instance for this operation.
	 * @return array|null Result for the requested operation.
	 */
	public static function receipt( int $id, array $query, ?int $instance = null ): ?array {
		if ( self::$memory_receipt ) {
			$receipt = self::$memory_receipt;} else {
			$raw   = $query['leenfo_receipt'] ?? null;
			$token = is_string( $raw ) ? sanitize_text_field( wp_unslash( $raw ) ) : null;
			if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{32}$/D', $token ) || \LeenkatForms\Security\RequestInput::id( is_string( $query['leenfo_result_form'] ?? null ) ? wp_unslash( $query['leenfo_result_form'] ) : null ) !== $id ) {
				return null;
			}
			$receipt = get_transient( 'leenfo_result_' . $token );
			}
			return is_array( $receipt ) && ( $receipt['form'] ?? 0 ) === $id && ( null === $instance || ( $receipt['instance'] ?? 1 ) === $instance ) ? $receipt : null;
	}
}
