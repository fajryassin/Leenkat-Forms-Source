<?php
/**
 * Verified removal of plugin data when retention settings permit it.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Bootstrap;

use LeenkatForms\Files\PrivateStorage;
use LeenkatForms\Files\EncryptedStorage;
use LeenkatForms\Files\AttachmentMigration;
use LeenkatForms\Files\PrivateMedia;
use LeenkatForms\Files\FileLease;

/** Bounded ownership inventory, durable file-first journal, then exact-name DB cleanup. */
final class Uninstaller {
	private const TABLES = array( 'entry_values', 'entry_files', 'deliveries', 'entries', 'form_versions', 'forms' );
	private const HOOKS  = array( 'leenfo_send_delivery', 'leenfo_reconcile_deliveries', 'leenfo_file_maintenance' );
	private const CAPS   = array( 'leenfo_manage_forms', 'leenfo_view_entries', 'leenfo_manage_settings' );
	private const STATE  = 'leenfo_uninstall_state';
	private const ERROR  = 'leenfo_uninstall_error';

	/**
	 * Run the guarded WordPress uninstall sequence.
	 *
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 * @throws \Throwable Raised when the underlying operation fails.
	 */
	public static function run(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || WP_UNINSTALL_PLUGIN !== plugin_basename( dirname( __DIR__, 2 ) . '/leenkat-forms.php' ) ) {
			throw new \RuntimeException( esc_html__( 'A WordPress uninstall request is required.', 'leenkat-forms' ) );
		}
		$settings = get_option( 'leenfo_site_settings', get_option( IdentifierUpgrade::legacySettingsName(), array() ) );
		if ( ! is_array( $settings ) || ( $settings['general']['delete_all_data_on_uninstall'] ?? null ) !== true ) {
			return;
		}
		// Per-site storage and shared user grants need a separate network-wide contract.
		if ( is_multisite() ) {
			throw new \RuntimeException( esc_html__( 'Network uninstall is not supported. No data was removed.', 'leenkat-forms' ) );
		}
		if ( ! IdentifierUpgrade::run() ) {
			throw new \RuntimeException( esc_html__( 'Complete the Leenkat Forms identifier upgrade before uninstalling.', 'leenkat-forms' ) );
		}
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->prefix ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid table prefix.', 'leenkat-forms' ) );
		}
		$lock = 'leenfo_uninstall_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 );
		if ( ! \LeenkatForms\Infrastructure\Database\MigrationLock::acquire( $lock ) ) {
			throw new \RuntimeException( esc_html__( 'Another uninstall is in progress.', 'leenkat-forms' ) );
		}
		try {
			$state = get_option( self::STATE, null );
			$roots = self::storage_roots();
			foreach ( is_array( $state['roots'] ?? null ) ? array_keys( $state['roots'] ) : array() as $kind ) {
				if ( is_string( $kind ) && str_starts_with( $kind, 'form:' ) ) {
					$roots[ $kind ] = EncryptedStorage::formRoot( substr( $kind, 5 ) );
				}
			}
			ksort( $roots );
			if ( null !== $state && ( ! is_array( $state ) || ! hash_equals( self::signature( $state ), (string) ( $state['signature'] ?? '' ) ) || ( $state['site'] ?? '' ) !== self::site() || ( $state['roots'] ?? '' ) !== $roots || ! in_array( $state['phase'] ?? '', array( 'files', 'metadata' ), true ) || ! is_array( $state['inventories'] ?? null ) ) ) {
				throw new \RuntimeException( __( 'The uninstall journal or storage configuration changed. Restore the original configuration before retrying.', 'leenkat-forms' ) );
			}
			self::busy_check();
			if ( null === $state ) {
				$state = array(
					'site'        => self::site(),
					'roots'       => $roots,
					'phase'       => 'files',
					'inventories' => self::inventory( $roots ),
					'started'     => gmdate( 'c' ),
				);
				self::persist( $state );}
			foreach ( $roots as $kind => $root ) {
				if ( is_dir( $root ) && $state['inventories'][ $kind ] ) {
					self::remove_files(
						array(
							'root'  => $root,
							'files' => $state['inventories'][ $kind ],
						)
					);
				}
			}
			if ( 'files' === $state['phase'] ) {
				$state['phase'] = 'metadata';
				self::persist( $state );}
			foreach ( $state['inventories'] as $inventory ) {
				foreach ( array_keys( $inventory ) as $key ) {
					if ( PrivateStorage::validKey( $key ) && ! PrivateMedia::forget( $key ) ) {
						throw new \RuntimeException( esc_html__( 'A private Media Library record could not be removed.', 'leenkat-forms' ) );
					}
				}
			}
			self::jobs();
			self::capabilities();
			self::metadata();
			foreach ( self::TABLES as $suffix ) {
				$table = $wpdb->prefix . 'leenfo_' . $suffix;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted. Only the explicit versioned schema migration/uninstall path changes owned plugin tables.
				if ( false === $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ) ) {
					throw new \RuntimeException( __( 'An owned table could not be removed. Retry deletion.', 'leenkat-forms' ) );
				}
			}
			// The opt-in and journal are deliberately last, after every destructive phase.
			self::delete_option( self::ERROR );
			self::delete_option( self::STATE );
			self::delete_option( EncryptedStorage::KEY_OPTION );
			self::delete_option( 'leenfo_site_settings' );
		} catch ( \Throwable $error ) {
			update_option(
				self::ERROR,
				array(
					'time'    => gmdate( 'c' ),
					'message' => $error->getMessage(),
				),
				false
			);
			throw $error;
		} finally {
			\LeenkatForms\Infrastructure\Database\MigrationLock::release( $lock );
		}
	}
	/**
	 * Uninstall plugin data for the current site.
	 *
	 * @return string Result for the requested operation.
	 */
	private static function site(): string {
		global $wpdb;
		return null !== get_option( EncryptedStorage::KEY_OPTION, null ) ? EncryptedStorage::identity() : hash( 'sha256', ABSPATH . '|' . get_current_blog_id() . '|' . $wpdb->prefix );}
	/**
	 * Sign the uninstall state for later verification.
	 *
	 * @param array $state State for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function signature( array $state ): string {
		unset( $state['signature'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The uninstall plan signature covers this deterministic serialization of internal state and must remain valid across upgrade retries.
		return hash_hmac( 'sha256', serialize( $state ), wp_salt( 'auth' ) );}
	/**
	 * Persist and verify resumable uninstall state.
	 *
	 * @param array $state State for this operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function persist( array $state ): void {
		$state['signature'] = self::signature( $state );
		if ( get_option( self::STATE, null ) !== $state && ! update_option( self::STATE, $state, false ) ) {
			throw new \RuntimeException( esc_html__( 'The uninstall journal could not be saved.', 'leenkat-forms' ) );
		}
	}
	/**
	 * Check whether a plugin table still exists.
	 *
	 * @param string $suffix Suffix for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function exists( string $suffix ): bool {
		global $wpdb;
		$name = $wpdb->prefix . 'leenfo_' . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( esc_html__( 'Table ownership could not be checked.', 'leenkat-forms' ) );
		}return $found === $name;
	}
	/**
	 * Load records needed to build the uninstall inventory.
	 *
	 * @param string $sql Sql for this operation.
	 * @return array Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function rows( string $sql ): array {
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
		if ( ! is_array( $rows ) || $wpdb->last_error ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
			throw new \RuntimeException( esc_html__( 'Ownership metadata could not be read.', 'leenkat-forms' ) );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
		}return $rows;
	}
	/**
	 * Reject uninstall while an incompatible plugin operation is active.
	 *
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function busy_check(): void {
		global $wpdb;
		foreach ( array( 'leenfo_file_migration_lock', 'leenfo_site_settings_lock', 'leenfo_captcha_settings_lock', 'leenfo_migration_lock' ) as $name ) {
			if ( (int) get_option( $name, 0 ) > time() ) {
				throw new \RuntimeException( esc_html__( 'Plugin maintenance is in progress. Retry after it finishes.', 'leenkat-forms' ) );
			}
		}
		if ( self::exists( 'deliveries' ) && self::rows( "SELECT id FROM {$wpdb->prefix}leenfo_deliveries WHERE status='processing' LIMIT 1" ) ) {
			throw new \RuntimeException( esc_html__( 'A delivery is processing. Wait for it to finish before retrying.', 'leenkat-forms' ) );
		}
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( esc_html__( 'Scheduled actions could not be checked.', 'leenkat-forms' ) );
		}
		if ( $found === $actions && self::rows( "SELECT a.action_id FROM `{$actions}` a JOIN `{$groups}` g ON a.group_id=g.group_id WHERE a.hook='leenfo_send_delivery' AND g.slug='leenkat-forms' AND a.status='in-progress' LIMIT 1" ) ) {
			throw new \RuntimeException( esc_html__( 'A scheduled delivery is running. Retry later.', 'leenkat-forms' ) );
		}
	}
	/**
	 * Does not create storage. Reject links, traversal, ancestor roots and public/shared roots.
	 *
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function storage_roots(): array {
		$roots = array( 'legacy' => PrivateStorage::legacy_source_root() );
		if ( null !== get_option( EncryptedStorage::KEY_OPTION, null ) ) {
			$roots['encrypted'] = EncryptedStorage::root();
		}
		if ( isset( $roots['encrypted'] ) && ( PrivateStorage::inside( $roots['legacy'], $roots['encrypted'] ) || PrivateStorage::inside( $roots['encrypted'], $roots['legacy'] ) ) ) {
			throw new \RuntimeException( esc_html__( 'Private storage paths overlap.', 'leenkat-forms' ) );
		}
		$roots['locks'] = FileLease::root();
		return $roots + EncryptedStorage::formRoots();
	}
	/**
	 * Build the digest for a verified uninstall inventory.
	 *
	 * @param string $path Filesystem path to check.
	 * @return array Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function digest( string $path ): array {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || ! PrivateStorage::noLinks( $path ) ) {
			throw new \RuntimeException( esc_html__( 'A private object is not a regular file.', 'leenkat-forms' ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A checked file can disappear or become unreadable before hashing. Preserve the false result for the existing integrity check without disclosing the local path.
		$hash = @hash_file( 'sha256', $path );
		$size = filesize( $path );
		if ( ! is_string( $hash ) || false === $size ) {
			throw new \RuntimeException( esc_html__( 'A private object could not be verified. Check permissions and active file leases.', 'leenkat-forms' ) );
		}return array(
			'bytes'  => $size,
			'sha256' => $hash,
		);
	}
	/**
	 * Collect the site records and files owned by the plugin.
	 *
	 * @param array $roots Roots for this operation.
	 * @return array Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function inventory( array $roots ): array {
		global $wpdb;
		$owned    = array();
		$actual   = array();
		$proof    = array();
		$journals = array();
		$add      = static function ( string $key, int $bytes, string $hash ) use ( &$owned ): void {
			if ( ! PrivateStorage::validKey( $key ) || ! preg_match( '/^[a-f0-9]{64}$/D', $hash ) || $bytes < 0 ) {
				throw new \RuntimeException( esc_html__( 'A private file association is invalid.', 'leenkat-forms' ) );
			}
			$item = array(
				'bytes'  => $bytes,
				'sha256' => $hash,
			);
			if ( isset( $owned[ $key ] ) && $owned[ $key ] !== $item ) {
				throw new \RuntimeException( esc_html__( 'Conflicting private file associations.', 'leenkat-forms' ) );
			}$owned[ $key ] = $item;
		};
		if ( self::exists( 'entry_files' ) ) {
			foreach ( self::rows( "SELECT storage_key,byte_size,sha256,status FROM {$wpdb->prefix}leenfo_entry_files" ) as $row ) {
				if ( str_starts_with( $row['storage_key'], 'media:' ) ) {
					continue;
				}$add( $row['storage_key'], (int) $row['byte_size'], $row['sha256'] );
				if ( ! PrivateStorage::encrypted( $row['storage_key'] ) ) {
					$alias = PrivateStorage::legacy_alias( $row['storage_key'] );
					if ( EncryptedStorage::ciphertext( $alias ) ) {
						$add( $alias, (int) $row['byte_size'], $row['sha256'] );
					}
				}
				$kind = PrivateStorage::encrypted( $row['storage_key'] ) ? 'encrypted' : 'legacy';
				if ( preg_match( '/^p3-([1-9][0-9]{0,18})-/', $row['storage_key'], $match ) ) {
					$kind = 'form:' . $match[1];
				}
				if ( 'private' === $row['status'] && ( ! isset( $roots[ $kind ] ) || ! is_dir( $roots[ $kind ] ) ) && ( 'legacy' !== $kind || ! PrivateStorage::matches( $row['storage_key'], (int) $row['byte_size'], $row['sha256'] ) ) ) {
					throw new \RuntimeException( esc_html__( 'Restore the private storage directory before uninstalling.', 'leenkat-forms' ) );
				}
			}
		}
		// Legacy public sources must first pass the existing migration workflow. Media stays.
		if ( self::exists( 'entries' ) ) {
			foreach ( self::rows( "SELECT meta FROM {$wpdb->prefix}leenfo_entries" ) as $row ) {
				$meta = json_decode( $row['meta'], true );
				if ( ! is_array( $meta ) ) {
					throw new \RuntimeException( esc_html__( 'An entry file reference is invalid.', 'leenkat-forms' ) );
				}foreach ( $meta['uploads'] ?? array() as $file ) {
					if ( is_array( $file ) && empty( $file['attachment_id'] ) && empty( $file['file_id'] ) && empty( $file['storage_key'] ) && ! empty( $file['path'] ) && ( file_exists( $file['path'] ) || is_link( $file['path'] ) || null !== \LeenkatForms\Files\FileRecords::legacyPath( $file ) ) ) {
						throw new \RuntimeException( esc_html__( 'Legacy public attachments require migration before uninstall.', 'leenkat-forms' ) );
					}
				}
			}
		}
		$journal_options = self::rows(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( AttachmentMigration::JOURNAL_OPTION_PREFIX ) . '%'
			)
		);
		foreach ( $journal_options as $journal_option ) {
			$run  = substr( $journal_option['option_name'], strlen( AttachmentMigration::JOURNAL_OPTION_PREFIX ) );
			$plan = AttachmentMigration::databaseJournal( $run );
			if ( null === $plan ) {
				throw new \RuntimeException( esc_html__( 'The migration journal changed during uninstall.', 'leenkat-forms' ) );
			}
			foreach ( $plan['files'] as $item ) {
				foreach ( array( 'backup_key', 'destination_key' ) as $key ) {
					if ( ! empty( $item[ $key ] ) ) {
						$add( $item[ $key ], (int) ( $item['bytes'] ?? -1 ), (string) ( $item['sha256'] ?? '' ) );
					}
				}
			}
		}
		foreach ( $roots as $kind => $root ) {
			$actual[ $kind ]   = array();
			$journals[ $kind ] = array();
			$proof[ $kind ]    = in_array( $kind, array( 'encrypted', 'locks' ), true ) || str_starts_with( $kind, 'form:' ) || wp_normalize_path( PrivateStorage::legacyDefault() ) === $root;
			if ( ! is_dir( $root ) ) {
				continue;
			}
			foreach ( new \DirectoryIterator( $root ) as $file ) {
				if ( $file->isDot() ) {
					continue;
				}
				if ( count( $actual[ $kind ] ) >= 100000 || ! $file->isFile() || ! PrivateStorage::noLinks( $file->getPathname() ) ) {
					throw new \RuntimeException( esc_html__( 'Storage contains an unverified directory, link or too many files.', 'leenkat-forms' ) );
				}
				if ( self::retained_protection( $root, $file->getPathname() ) ) {
					continue;
				}
				$actual[ $kind ][ $file->getFilename() ] = self::digest( $file->getPathname() );
			}
			$suffix = 'encrypted' === $kind ? 'lkf' : 'json';
			foreach ( $actual[ $kind ] as $name => $digest ) {
				if ( ! preg_match( '/^migration-([a-f0-9]{32})\.' . $suffix . '$/D', $name, $match ) ) {
					continue;
				}
				$plan = AttachmentMigration::stored( $match[1], 'encrypted' === $kind );
				foreach ( $plan['files'] as $item ) {
					foreach ( array( 'backup_key', 'destination_key' ) as $key ) {
						if ( ! empty( $item[ $key ] ) ) {
												$add( $item[ $key ], (int) ( $item['bytes'] ?? -1 ), (string) ( $item['sha256'] ?? '' ) );
						}
					}
				}
				$journals[ $kind ][ $name ] = $digest;
				$proof[ $kind ]             = true;
			}
		}
		$result = array();
		foreach ( $roots as $kind => $root ) {
			$result[ $kind ] = array();
			foreach ( $actual[ $kind ] as $name => $digest ) {
				if ( isset( $journals[ $kind ][ $name ] ) ) {
					$result[ $kind ][ $name ] = $digest;
					continue;}
				if ( isset( $owned[ $name ] ) ) {
					if ( 'legacy' !== $kind && PrivateStorage::encrypted( $name ) && EncryptedStorage::objectRoot( $name ) === $root ) {
						$path = EncryptedStorage::open( $name, $owned[ $name ]['bytes'], $owned[ $name ]['sha256'] );
						if ( ! $path ) {
							throw new \RuntimeException( esc_html__( 'Encrypted private file verification failed.', 'leenkat-forms' ) );
						}
						EncryptedStorage::close( $path );
					} elseif ( 'legacy' !== $kind || PrivateStorage::encrypted( $name ) || $owned[ $name ] !== $digest ) {
						throw new \RuntimeException( esc_html__( 'A private file changed; ownership verification failed.', 'leenkat-forms' ) );
					}
					$proof[ $kind ]           = true;
					$result[ $kind ][ $name ] = $digest;
					continue;
				}
				if ( '.htaccess' === $name && hash_equals( hash( 'sha256', EncryptedStorage::DENY_RULES ), $digest['sha256'] ) ) {
					continue;
				}
				$lock_pattern = in_array( $kind, array( 'encrypted', 'locks' ), true ) ? '/^entry-[a-f0-9]{64}\.lock$/D' : '/^entry-[1-9][0-9]*\.lock$/D';
				if ( 'locks' === $kind && preg_match( '/^database-[a-f0-9]{64}\.lock$/D', $name ) && 0 === $digest['bytes'] ) {
					continue;
				}
				if ( preg_match( $lock_pattern, $name ) && 0 === $digest['bytes'] ) {
					$result[ $kind ][ $name ] = $digest;
					continue;}
				$key     = str_ends_with( $name, '.pending' ) || str_ends_with( $name, '.partial' ) ? substr( $name, 0, -8 ) : $name;
				$pending = $root . '/' . $key . '.pending';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect only an owned local intent marker whose size and path were checked above. No network or credentialed filesystem transport is used during uninstall.
				if ( 'locks' !== $kind && PrivateStorage::validKey( $key ) && PrivateStorage::encrypted( $key ) === ( 'legacy' !== $kind ) && ( ! PrivateStorage::encrypted( $key ) || EncryptedStorage::objectRoot( $key ) === $root ) && isset( $actual[ $kind ][ $key . '.pending' ] ) && $actual[ $kind ][ $key . '.pending' ]['bytes'] <= 20 && ctype_digit( (string) file_get_contents( $pending ) ) ) {
					$result[ $kind ][ $name ] = $digest;
					continue;}
				throw new \RuntimeException( esc_html__( 'Storage contains an unverified file. No ownership metadata was removed.', 'leenkat-forms' ) );
			}
			if ( $actual[ $kind ] && ! $proof[ $kind ] ) {
				throw new \RuntimeException( esc_html__( 'Custom private storage ownership cannot be verified.', 'leenkat-forms' ) );
			}
		}
		return $result;
	}
	/**
	 * Validate an empty synchronization inode or fixed access protection that must remain.
	 *
	 * @param string $root Owned storage root.
	 * @param string $path Candidate retained file.
	 * @return bool Whether the file is an exact, non-sensitive protection artifact.
	 */
	private static function retained_protection( string $root, string $path ): bool {
		if ( ! PrivateStorage::noLinks( $root ) || ! PrivateStorage::noLinks( $path ) || ! is_file( $path ) ) {
			return false;
		}
		$name = basename( $path );
		// Windows cannot reopen an inode held with an exclusive SQLite lease. Retain only exact, empty database-lock names; no customer content or deletion authority is inferred from a filename.
		clearstatcache( true, $path );
		if ( wp_normalize_path( $root ) === wp_normalize_path( FileLease::root() ) && preg_match( '/^database-[a-f0-9]{64}\\.lock$/D', $name ) && 0 === filesize( $path ) ) {
			return true;
		}
		if ( '.htaccess' !== $name ) {
			return false;
		}
		$digest = self::digest( $path );
		return hash_equals( hash( 'sha256', EncryptedStorage::DENY_RULES ), $digest['sha256'] );
	}

	/**
	 * Remove the verified files selected for uninstall.
	 *
	 * @param array $state State for this operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function remove_files( array $state ): void {
		$root = $state['root'];
		if ( ! is_dir( $root ) ) {
			return;
		}$files = $state['files'];
		$leases = array();
		try {
			foreach ( new \DirectoryIterator( $root ) as $file ) {
				if ( $file->isDot() ) {
					continue;
				}
				$name = $file->getFilename();
				if ( self::retained_protection( $root, $file->getPathname() ) ) {
					continue;
				}
				if ( ! isset( $files[ $name ] ) || basename( $name ) !== $name || ! PrivateStorage::noLinks( $file->getPathname() ) || self::digest( $file->getPathname() ) !== $files[ $name ] ) {
					throw new \RuntimeException( __( 'Storage changed after the uninstall inventory. Retry only after resolving ownership.', 'leenkat-forms' ) );
				}
				if ( str_ends_with( $name, '.lock' ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
					$handle = fopen( $file->getPathname(), 'r+' );
					if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
						if ( is_resource( $handle ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
							fclose( $handle );
						}throw new \RuntimeException( __( 'Private files are in use. Retry later.', 'leenkat-forms' ) );
					}$leases[ $name ] = $handle;}
			}
			foreach ( $files as $name => $digest ) {
				if ( basename( $name ) !== $name || str_contains( $name, '\\' ) ) {
					throw new \RuntimeException( __( 'Invalid uninstall inventory.', 'leenkat-forms' ) );
				}$path = $root . '/' . $name;
				if ( ! file_exists( $path ) && ! is_link( $path ) ) {
					continue;
				}
				// Recheck immediately before unlink; never follow a substituted link.
				// Windows locks are mandatory: inspect our existing handle instead of reopening it.
				$current = isset( $leases[ $name ] ) ? array(
					'bytes'  => ( fstat( $leases[ $name ] )['size'] ?? -1 ),
					'sha256' => hash( 'sha256', '' ),
				) : self::digest( $path );
				if ( ! PrivateStorage::noLinks( $root ) || ! PrivateStorage::noLinks( $path ) || $current !== $digest ) {
					throw new \RuntimeException( __( 'A private file changed before removal.', 'leenkat-forms' ) );
				}
				if ( isset( $leases[ $name ] ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
					fclose( $leases[ $name ] );
					unset( $leases[ $name ] );}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
				if ( ! unlink( $path ) ) {
					throw new \RuntimeException( __( 'A private file could not be removed. Retry deletion after checking permissions.', 'leenkat-forms' ) );
				}
			}
			$retained = false;
			foreach ( new \DirectoryIterator( $root ) as $file ) {
				if ( $file->isDot() ) {
					continue;
				}
				if ( ! self::retained_protection( $root, $file->getPathname() ) ) {
					throw new \RuntimeException( esc_html__( 'Private storage changed during removal.', 'leenkat-forms' ) );
				}
				$retained = true;
			}
			// Keep fixed deny rules and live database-lock inodes. They contain no user data and must not be deleted while another SQLite process can be waiting.
			if ( $retained ) {
				return;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
			if ( ! PrivateStorage::noLinks( $root ) || ! rmdir( $root ) ) {
				throw new \RuntimeException( __( 'Private storage is not empty or could not be removed.', 'leenkat-forms' ) );
			}
		} finally {
			foreach ( $leases as $handle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
				fclose( $handle );
			}
		}
	}
	/**
	 * Remove the scheduled jobs owned by this plugin.
	 *
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function jobs(): void {
		foreach ( self::HOOKS as $hook ) {
			$result = wp_unschedule_hook( $hook, true );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( esc_html__( 'A plugin cron job could not be removed.', 'leenkat-forms' ) );
			}
		}
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( esc_html__( 'Scheduled actions could not be checked.', 'leenkat-forms' ) );
		}if ( $found !== $table ) {
			return;
		}
		$group = $wpdb->prefix . 'actionscheduler_groups';
		// Use a loaded provider's API, or exact verified IDs if its plugin is inactive.
		// Do not boot an unrelated plugin/library during uninstall.
		$store = class_exists( 'ActionScheduler' ) ? \ActionScheduler::store() : null;
		$logs  = $wpdb->prefix . 'actionscheduler_logs';
		do {
			$rows = self::rows( "SELECT a.action_id,a.status,a.claim_id FROM `{$table}` a INNER JOIN `{$group}` g ON a.group_id=g.group_id WHERE a.hook='leenfo_send_delivery' AND g.slug='leenkat-forms' LIMIT 100" );foreach ( $rows as $row ) {
				$id = (int) $row['action_id'];
				if ( 'in-progress' === $row['status'] ) {
					throw new \RuntimeException( esc_html__( 'A scheduled delivery is running. Retry later.', 'leenkat-forms' ) );
				}
				// Logs first: a failed callback must not orphan sensitive job history on retry.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				if ( false === $wpdb->delete( $logs, array( 'action_id' => $id ) ) ) {
					throw new \RuntimeException( esc_html__( 'A scheduled action log could not be removed.', 'leenkat-forms' ) );
				}
				if ( $store ) {
					$store->delete_action( $id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				} elseif ( $wpdb->delete(
					$table,
					array(
						'action_id' => $id,
						'hook'      => 'leenfo_send_delivery',
						'status'    => $row['status'],
					)
				) !== 1 ) {
					throw new \RuntimeException( esc_html__( 'A scheduled action could not be removed.', 'leenkat-forms' ) );
				}
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
				$claim = (int) $row['claim_id'];
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
				if ( $claim ) {
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
					$claims = $wpdb->prefix . 'actionscheduler_claims';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
					if ( false === $wpdb->query( $wpdb->prepare( "DELETE c FROM `{$claims}` c LEFT JOIN `{$table}` a ON c.claim_id=a.claim_id WHERE c.claim_id=%d AND a.action_id IS NULL", $claim ) ) ) {
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
						throw new \RuntimeException( esc_html__( 'An unused plugin job claim could not be removed.', 'leenkat-forms' ) );
					}
				}
			}
		} while ( $rows );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` a INNER JOIN `{$group}` g ON a.group_id=g.group_id WHERE a.hook='leenfo_send_delivery' AND g.slug='leenkat-forms'" );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
		if ( $wpdb->last_error || $remaining ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
			throw new \RuntimeException( esc_html__( 'Scheduled action cleanup is incomplete.', 'leenkat-forms' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
		if ( false === $wpdb->query( "DELETE g FROM `{$group}` g LEFT JOIN `{$table}` a ON a.group_id=g.group_id WHERE g.slug='leenkat-forms' AND a.action_id IS NULL" ) ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Private helper accepts only internal queries using fixed owned table names and prepared values; no request SQL is accepted.
			throw new \RuntimeException( esc_html__( 'The empty plugin job group could not be removed.', 'leenkat-forms' ) );
		}
	}
	/**
	 * Exact schema-owned capability keys; preserve every other role/user grant.
	 *
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function capabilities(): void {
		global $wpdb;foreach ( wp_roles()->roles as $name => $role ) {
			$object = get_role( $name );
			foreach ( self::CAPS as $cap ) {
				if ( array_key_exists( $cap, $role['capabilities'] ) ) {
					$object->remove_cap( $cap );
				}
			}
		}
		$key  = $wpdb->prefix . 'capabilities';
		$rows = self::rows( $wpdb->prepare( "SELECT user_id,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%s", $key ) );
		foreach ( $rows as $row ) {
			$caps = maybe_unserialize( $row['meta_value'] );
			if ( ! is_array( $caps ) ) {
				throw new \RuntimeException( esc_html__( 'A user capability record could not be verified.', 'leenkat-forms' ) );
			}$clean = array_diff_key( $caps, array_flip( self::CAPS ) );if ( $clean !== $caps ) {
				update_user_meta( (int) $row['user_id'], $key, $clean );
				if ( get_user_meta( (int) $row['user_id'], $key, true ) !== $clean ) {
					throw new \RuntimeException( esc_html__( 'A plugin user grant could not be removed.', 'leenkat-forms' ) );
				}
			}
		}
		// No standalone plugin usermeta exists in dev.49. Media is retained; its lease is ours.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Cleanup targets only plugin-owned media metadata and must verify ownership before deletion.
		if ( false === $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_leenfo_upload_lease' ) ) ) {
			throw new \RuntimeException( esc_html__( 'Upload lease metadata could not be removed.', 'leenkat-forms' ) );
		}
	}
	/**
	 * Check whether an option name is in the uninstall allowlist.
	 *
	 * @param string $name Name used to resolve the requested item.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function ownedOption( string $name ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( preg_match( '/^' . AttachmentMigration::JOURNAL_OPTION_PREFIX . '[a-f0-9]{32}$/D', $name ) ) {
			return true; }
		if ( in_array( $name, array( PrivateMedia::INDEX_CURSOR, 'leenfo_legacy_private_source', 'leenfo_legacy_private_cursor', 'leenfo_identifier_schema', 'leenfo_db_version', 'leenfo_installed_version', 'leenfo_migration_lock', 'leenfo_migration_error', 'leenfo_captcha_settings', 'leenfo_captcha_settings_lock', 'leenfo_spam_keywords', 'leenfo_spam_keywords_lock', 'leenfo_site_settings_lock', 'leenfo_file_migration_latest', 'leenfo_file_migration_lock', 'leenfo_backup_cleanup_pending', 'leenfo_file_cleanup_attention' ), true ) ) {
			return true;
		}
		if ( preg_match( '/^leenfo_(?:import_(?:done|lock)_[1-9][0-9]*_[a-f0-9]{48}|rate_lock_[a-f0-9]{64}|captcha_pass_[a-f0-9]{64}_lock|akismet_check_[a-f0-9]{64}_lock|unstored_[a-f0-9]{64}_lock)$/D', $name ) ) {
			return true;
		}
		return (bool) preg_match( '/^_transient_(?:timeout_)?leenfo_(?:rate_[a-f0-9]{64}|captcha_pass_[a-f0-9]{64}|akismet_(?:key|check)_[a-f0-9]{64}|unstored_[a-f0-9]{64}(?:_[A-Za-z][A-Za-z0-9_-]{0,63})?|import_[1-9][0-9]*_[a-f0-9]{48}|(?:site_notice|settings_notice)_[1-9][0-9]*|erase_cursor_[1-9][0-9]*_[a-f0-9]{64}|result_[A-Za-z0-9]{32})$/D', $name );
	}
	/**
	 * Remove a verified plugin option.
	 *
	 * @param string $name Name used to resolve the requested item.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function delete_option( string $name ): void {
		global $wpdb;
		delete_option( $name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name=%s", $name ) );
		if ( $wpdb->last_error || null !== $exists ) {
			throw new \RuntimeException( esc_html__( 'An owned option could not be removed.', 'leenkat-forms' ) );
		}
	}
	/**
	 * Remove plugin metadata during the uninstall sequence.
	 */
	private static function metadata(): void {
		global $wpdb;
		$rows = self::rows( "SELECT option_name FROM {$wpdb->options}" );
		foreach ( $rows as $row ) {
			$name = $row['option_name'];
			if ( ! self::ownedOption( $name ) ) {
				continue;
			}
			if ( str_starts_with( $name, '_transient_' ) ) {
				$key = preg_replace( '/^_transient_(?:timeout_)?/', '', $name );
				delete_transient( $key );
			}self::delete_option( $name );
		}
	}
}
