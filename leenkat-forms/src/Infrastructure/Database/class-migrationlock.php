<?php
/**
 * Connection-scoped serialization of database upgrades.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);

namespace LeenkatForms\Infrastructure\Database;

/** Serialize upgrades without calling MySQL advisory functions on SQLite. */
final class MigrationLock {
	/**
	 * SQLite operation locks, released automatically when PHP closes its streams.
	 *
	 * @var array<string, resource>
	 */
	private static array $sqlite_handles = array();

	/**
	 * Determine whether WordPress uses its SQLite integration.
	 *
	 * @return bool Whether the current database is SQLite.
	 */
	public static function sqlite(): bool {
		global $wpdb;
		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) ||
			( defined( 'DATABASE_TYPE' ) && 'sqlite' === DATABASE_TYPE ) ||
			is_a( $wpdb, 'WP_SQLite_DB' );
	}

	/**
	 * Acquire the upgrade lock for the current connection.
	 *
	 * @param string $name Site-specific lock name.
	 * @return bool Whether this connection acquired the lock.
	 */
	public static function acquire( string $name ): bool {
		global $wpdb;
		if ( self::sqlite() ) {
			if ( isset( self::$sqlite_handles[ $name ] ) ) {
				return false;
			}
			try {
				$root = \LeenkatForms\Files\FileLease::root( true );
			} catch ( \Throwable ) {
				return false;
			}
			$path = $root . '/database-' . hash( 'sha256', $name ) . '.lock';
			if ( ! \LeenkatForms\Files\PrivateStorage::noLinks( $path ) ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- A local flock serializes SQLite schema and durable file-journal operations without relying on MySQL functions or an expiring lock. False means the operation must retry.
			$handle = @fopen( $path, 'c' );
			if ( ! $handle ) {
				return false;
			}
			if ( ! \LeenkatForms\Files\PrivateStorage::noLinks( $path ) || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native SQLite operation lease on failure.
				fclose( $handle );
				return false;
			}
			self::$sqlite_handles[ $name ] = $handle;
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locking is connection state and cannot be obtained through a cached object API.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
	}

	/**
	 * Release an operation lock owned by this PHP request.
	 *
	 * @param string $name Lock acquired by this connection.
	 * @return bool Whether the lock was released.
	 */
	public static function release( string $name ): bool {
		global $wpdb;
		if ( self::sqlite() ) {
			$handle = self::$sqlite_handles[ $name ] ?? null;
			if ( ! is_resource( $handle ) ) {
				return false;
			}
			$result = flock( $handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the OS-owned SQLite operation lease without deleting its inode while another process may wait on it.
			fclose( $handle );
			unset( self::$sqlite_handles[ $name ] );
			return $result;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the MySQL advisory lock owned by this connection.
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}
