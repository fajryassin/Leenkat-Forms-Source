<?php
/**
 * Allowed entry status values and transitions.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Submissions;

/** State transitions contract; not wired to public submissions yet. */
final class EntryStatus {
	public const DRAFT             = 'draft';
	public const PENDING_PAYMENT   = 'pending_payment';
	public const COMPLETE          = 'complete';
	public const SPAM              = 'spam';
	public const TRASHED           = 'trashed';
	public const PROCESSING_FAILED = 'processing_failed';
	private const TRANSITIONS      = array(
		self::DRAFT             => array( self::PENDING_PAYMENT, self::COMPLETE, self::SPAM, self::TRASHED ),
		self::PENDING_PAYMENT   => array( self::COMPLETE, self::PROCESSING_FAILED, self::TRASHED ),
		self::COMPLETE          => array( self::SPAM, self::TRASHED ),
		self::SPAM              => array( self::COMPLETE, self::TRASHED ),
		self::PROCESSING_FAILED => array( self::PENDING_PAYMENT, self::COMPLETE, self::TRASHED ),
		self::TRASHED           => array( self::COMPLETE, self::SPAM ),
	);
	/**
	 * Return the available entry status values.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function all(): array {
		return array_keys( self::TRANSITIONS ); }
	/**
	 * Check whether the requested entry status change is allowed.
	 *
	 * @param string $from From for this operation.
	 * @param string $to   To for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function canTransition( string $from, string $to ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}
}
