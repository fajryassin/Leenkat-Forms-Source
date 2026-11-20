<?php
/**
 * Leases coordinating operations on private entry attachments.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Files;

/** OS locks protect attachments even while a stalled delivery is being reconciled. */
final class FileLease {
	/**
	 * Resolve the directory used for private attachment leases.
	 *
	 * @param bool $create Create for this operation.
	 * @return string Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function root( bool $create = false ): string {
		$root = dirname( EncryptedStorage::formRoot( '1' ) ) . '/locks';
		if ( ! PrivateStorage::noLinks( $root ) || ( $create && ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) ) {
			throw new \RuntimeException( esc_html__( 'Entry files are unavailable.', 'leenkat-forms' ) );
		}
		if ( $create ) {
			EncryptedStorage::protect( dirname( $root ) );
			EncryptedStorage::protect( $root );
		}
		return $root;
	}

	/**
	 * Acquire a lease for the specified entry file operation.
	 *
	 * @param int  $entryId   Entry identifier.
	 * @param bool $exclusive Exclusive for this operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function acquire( int $entryId, bool $exclusive = false ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
		if ( $entryId < 1 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
			throw new \RuntimeException( esc_html__( 'Entry files are unavailable.', 'leenkat-forms' ) );
		}
		$root = self::root( true );
		$path = $root . '/entry-' . hash_hmac( 'sha256', (string) $entryId, EncryptedStorage::identity( true ) ) . '.lock'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
		if ( ! PrivateStorage::noLinks( $path ) ) {
			throw new \RuntimeException( esc_html__( 'Entry files are unavailable.', 'leenkat-forms' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- The local exclusive lease requires a native stream handle and flock; remote WP_Filesystem transports do not provide advisory locking. The verified local path can disappear or an exclusive create can lose a race. The existing branch handles false without leaking a private path.
		}$handle = @fopen( $path, 'c' );
		if ( ! $handle ) {
			throw new \RuntimeException( esc_html__( 'Entry files are unavailable.', 'leenkat-forms' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- The local exclusive lease requires a native stream handle and flock; remote WP_Filesystem transports do not provide advisory locking. Apply private permissions on the owned local file on a best-effort basis. Warning output can disclose the local path on unsupported filesystems.
		}@chmod( $path, 0600 );
		if ( ! flock( $handle, ( $exclusive ? LOCK_EX : LOCK_SH ) | LOCK_NB ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
			fclose( $handle );
			throw new \RuntimeException( esc_html__( 'Entry files are in use. Retry after delivery or download finishes.', 'leenkat-forms' ) );
		}return $handle;
	}
	/**
	 * Release the lock and close an open lease stream.
	 *
	 * @param resource|mixed $handle Lease handle returned by acquire.
	 */
	public static function release( $handle ): void {
		if ( is_resource( $handle ) ) {
			flock( $handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
			fclose( $handle );}}
}
