<?php
/**
 * Private receipt pages for native form submissions.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Submissions;

use LeenkatForms\Forms\ConfirmationSettings;
use LeenkatForms\Frontend\FormAssets;

/** Display adapter only. This endpoint cannot create entries or notification intents. */
final class ConfirmationReceipt {
	public const TTL              = 600;
	public const MAX_TICKET_BYTES = 16777216;

	/**
	 * Register callbacks for native confirmation receipts.
	 */
	public static function hooks(): void {
		add_action( 'admin_post_leenfo_receipt', array( self::class, 'show' ) );
		add_action( 'admin_post_nopriv_leenfo_receipt', array( self::class, 'show' ) );
	}
	/**
	 * Send private cache and browser headers for the receipt.
	 */
	public static function privateHeaders(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is the established cache-plugin interoperability constant for private receipts, not a plugin-owned global.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is the established cache-plugin interoperability constant for private receipts, not a plugin-owned global.
			define( 'DONOTCACHEPAGE', true );
		}
	}
	/**
	 * Create a receipt for the completed form submission.
	 *
	 * @param array $result Result for this operation.
	 * @param int   $form   Form for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function create( array $result, int $form ): array {
		if ( empty( $result['confirmation']['entry_preview'] ) ) {
			return $result;
		}
		$receipt               = array(
			'form'         => $form,
			'error'        => false,
			'message'      => $result['message'],
			'confirmation' => $result['confirmation'],
		);
		$result['receipt_url'] = add_query_arg( 'action', 'leenfo_receipt', admin_url( 'admin-post.php' ) );
		if ( ! empty( $result['unstored'] ) ) {
			// Stateless, authenticated POST transport: no answers in URLs or server storage.
			$payload = wp_json_encode(
				array(
					'expires' => time() + self::TTL,
					'receipt' => $receipt,
				)
			);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64url transports signed receipt data and preserves verification of issued links.
			$encoded                  = base64_encode( $payload );
			$result['receipt_ticket'] = $encoded . '.' . hash_hmac( 'sha256', 'lkf_confirmation_receipt|' . $encoded, wp_salt( 'auth' ) );
		} else {
			$token = wp_generate_password( 32, false, false );
			set_transient( 'leenfo_result_' . $token, $receipt, self::TTL );
			$result['receipt_url'] = add_query_arg(
				array(
					'leenfo_receipt'     => $token,
					'leenfo_result_form' => $form,
				),
				$result['receipt_url']
			);
		}
		return $result;
	}
	/**
	 * Validate the ticket used to retrieve a receipt.
	 *
	 * @param mixed $ticket Ticket for this operation.
	 * @return array|null Result for the requested operation.
	 */
	public static function ticket( mixed $ticket ): ?array {
		if ( ! is_string( $ticket ) || strlen( $ticket ) > self::MAX_TICKET_BYTES || ! preg_match( '/^([A-Za-z0-9+\/=]+)\.([a-f0-9]{64})$/D', $ticket, $parts ) ) {
			return null;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', 'lkf_confirmation_receipt|' . $parts[1], wp_salt( 'auth' ) ), $parts[2] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Base64url transports signed receipt data and preserves verification of issued links.
		$decoded = base64_decode( $parts[1], true );
		$payload = json_decode( $decoded ? $decoded : '', true );
		if ( ! is_array( $payload ) || ! is_int( $payload['expires'] ?? null ) || $payload['expires'] < time() || $payload['expires'] > time() + self::TTL ) {
			return null;
		}
		$receipt = $payload['receipt'] ?? null;
		return is_array( $receipt ) && ! empty( $receipt['confirmation']['entry_preview'] ) ? $receipt : null;
	}
	/**
	 * Serve the receipt for a verified request.
	 */
	public static function show(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server method/length metadata is compared with exact literals or cast and bounded; it is not WordPress-slash-encoded form content or HTML output.
		$receipt = ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST'
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Public receipt/download access uses validated scoped bearer claims or an entry-specific capability/nonce gate; a generic logged-in WordPress nonce is not the public credential. Receipt/download credential is strictly parsed, scoped and cryptographically verified by claims()/ticket(); sanitizing opaque credentials would change their identity.
			? self::ticket( wp_unslash( $_POST['receipt_ticket'] ?? null ) )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public receipt/download access uses validated scoped bearer claims or an entry-specific capability/nonce gate; a generic logged-in WordPress nonce is not the public credential.
			: NativeSubmission::receipt( absint( $_GET['leenfo_result_form'] ?? 0 ), $_GET );
		if ( ! $receipt || empty( $receipt['confirmation']['entry_preview'] ) ) {
			self::privateHeaders();
			status_header( 410 );
			wp_die( esc_html__( 'This receipt has expired or is unavailable.', 'leenkat-forms' ), esc_html__( 'Receipt unavailable', 'leenkat-forms' ), array( 'response' => 410 ) );
		}
		self::document( $receipt['confirmation'] );
	}
	/**
	 * Render the standalone native form confirmation document.
	 *
	 * @param array $confirmation Confirmation for this operation.
	 */
	public static function document( array $confirmation ): never {
		self::privateHeaders();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core language attributes, WordPress-printed styles and server-sanitized confirmation markup are complete trusted markup, not unescaped visitor values.
		echo '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . esc_html__( 'Submission received', 'leenkat-forms' ) . '</title>' . FormAssets::nativeStyles() . '</head><body class="lkf-receipt-page"><main class="lkf-receipt" aria-label="' . esc_attr__( 'Submission receipt', 'leenkat-forms' ) . '">' . ConfirmationSettings::markup( $confirmation ) . '</main></body></html>';
		exit;
	}
}
