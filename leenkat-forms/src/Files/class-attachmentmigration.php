<?php
/**
 * Resumable migration of verified private attachments and their journals.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Files;

use LeenkatForms\Infrastructure\Database\Transactions;
/** Explicit, resumable legacy migration. Inventory and planning never remove a source. */
final class AttachmentMigration {
	/**
	 * Require administrative access to private file maintenance.
	 *
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function gate(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			throw new \RuntimeException( esc_html__( 'File maintenance is unavailable.', 'leenkat-forms' ) );
		}}
	/**
	 * Check whether a path is associated with a WordPress media record.
	 *
	 * @param string $path Filesystem path to check.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function isMedia( string $path ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		global $wpdb;
		$base = realpath( wp_upload_dir()['basedir'] );
		if ( ! $base || ! PrivateStorage::inside( $path, $base ) ) {
			return true;
		}$relative = ltrim( str_replace( '\\', '/', substr( $path, strlen( $base ) ) ), '/' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE (meta_key='_wp_attached_file' AND meta_value=%s) OR (meta_key='_wp_attachment_metadata' AND meta_value LIKE %s) LIMIT 1", $relative, '%' . $wpdb->esc_like( basename( $path ) ) . '%' ) );
	}
	/**
	 * Inventory stored attachment references and identify migration candidates.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function inventory(): array {
		self::gate();
		global $wpdb;
		$result = array(
			'legacy_private'     => 0,
			'media'              => 0,
			'invalid_or_missing' => 0,
			'private_registered' => 0,
			'other_file_records' => 0,
			'candidates'         => array(),
			'issues'             => array(),
		);
		$last   = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,meta FROM {$wpdb->prefix}leenfo_entries WHERE id>%d ORDER BY id LIMIT 100", $last ) );
			foreach ( $rows as $entry ) {
				$last = (int) $entry->id;
				$meta = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $entry->meta );
				foreach ( $meta['uploads'] ?? array() as $index => $file ) {
					if ( ! is_array( $file ) ) {
						continue;
					}if ( ! empty( $file['file_id'] ) || ! empty( $file['storage_key'] ) ) {
						continue;
					}if ( ! empty( $file['attachment_id'] ) ) {
						++$result['media'];
						continue;
					}$path = FileRecords::legacyPath( $file );
					if ( ! $path || ! is_string( $file['name'] ?? null ) || ! is_string( $file['field_id'] ?? null ) ) {
						++$result['invalid_or_missing'];
						if ( count( $result['issues'] ) < 25 ) {
							$result['issues'][] = array(
								'entry_id' => $last,
								'index'    => $index,
								'issue'    => __( 'Missing file or invalid legacy reference', 'leenkat-forms' ),
							);
						}continue;}
					if ( self::isMedia( $path ) ) {
						++$result['media'];
						continue;
					}++$result['legacy_private'];
					if ( count( $result['candidates'] ) < 100 ) {
						$result['candidates'][] = array(
							'entry_id'       => $last,
							'index'          => (int) $index,
							'field_id'       => $file['field_id'],
							'name'           => sanitize_file_name( $file['name'] ),
							'bytes'          => filesize( $path ),
							'sha256'         => hash_file( 'sha256', $path ),
							'reference_hash' => hash( 'sha256', wp_json_encode( $file ) ),
						);
					}
				}
			}
			$full_page = 100 === count( $rows );
		} while ( $full_page );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$result['private_registered'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}leenfo_entry_files WHERE status='private'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$result['other_file_records'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}leenfo_entry_files WHERE status NOT IN ('private','media','deleting','cleanup_failed')" );
		return $result;
	}
	/**
	 * Convert an operation identifier into its legacy journal filename.
	 *
	 * @param string $run Run for this operation.
	 * @return string Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function name( string $run ): string {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $run ) ) {
			throw new \RuntimeException( esc_html__( 'Migration unavailable.', 'leenkat-forms' ) );
		}
		return 'migration-' . $run;
	}
	/**
	 * Locate the migration journal in the legacy storage location.
	 *
	 * @param string $run Run for this operation.
	 * @return string Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function legacy_doc( string $run ): string {
		$path = PrivateStorage::legacy_source_root() . '/' . self::name( $run ) . '.json';
		if ( ! PrivateStorage::noLinks( $path ) ) {
			throw new \RuntimeException( esc_html__( 'Migration unavailable.', 'leenkat-forms' ) );
		}
		return $path;
	}
	/**
	 * Check the owner marker on a legacy migration journal.
	 *
	 * @param string $owner Owner for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	private static function legacy_owner( string $owner ): bool {
		if ( hash_equals( hash( 'sha256', ABSPATH ), $owner ) ) {
			return true;
		}
		return defined( 'LEENKAT_FORMS_LEGACY_SITE_ROOT' ) && hash_equals( hash( 'sha256', (string) LEENKAT_FORMS_LEGACY_SITE_ROOT ), $owner );
	}
	public const JOURNAL_OPTION_PREFIX = 'leenfo_file_migration_journal_';

	/**
	 * Build the option name for a migration journal.
	 *
	 * @param string $run Run for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function journal_option( string $run ): string {
		self::name( $run );
		return self::JOURNAL_OPTION_PREFIX . $run;
	}

	/**
	 * Persist an authenticated migration journal.
	 *
	 * @param array $plan Plan for this operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function write( array $plan ): void {
		$option = self::journal_option( $plan['run_id'] );
		if ( empty( $plan['storage_version'] ) ) {
			if ( ! self::legacy_owner( (string) ( $plan['site'] ?? '' ) ) ) {
				throw new \RuntimeException( esc_html__( 'Migration unavailable.', 'leenkat-forms' ) );
			}
			$plan['uploads_base'] = defined( 'LEENKAT_FORMS_LEGACY_UPLOADS_DIR' )
				? wp_normalize_path( (string) LEENKAT_FORMS_LEGACY_UPLOADS_DIR )
				: wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
		}
		$plan['storage_version'] = 2;
		$plan['site']            = EncryptedStorage::identity( true );
		$json                    = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) > 33554432 ) {
			throw new \RuntimeException( esc_html__( 'Migration state exceeds the safe limit.', 'leenkat-forms' ) );
		}
		$sealed = EncryptedStorage::sealJournal( $option, $json );
		if ( ! update_option( $option, $sealed, false ) || self::databaseJournal( $plan['run_id'] ) !== $plan ) {
			throw new \RuntimeException( esc_html__( 'Migration state could not be saved.', 'leenkat-forms' ) );
		}
	}

	/**
	 * Read a migration journal from its WordPress option.
	 *
	 * @param string $run Run for this operation.
	 * @return array|null Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function databaseJournal( string $run ): ?array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$option = self::journal_option( $run );
		$sealed = get_option( $option, null );
		if ( null === $sealed ) {
			return null;
		}
		if ( ! is_string( $sealed ) ) {
			throw new \RuntimeException( esc_html__( 'Migration unavailable.', 'leenkat-forms' ) );
		}
		return self::decode_journal( EncryptedStorage::unsealJournal( $option, $sealed ), $run, true );
	}

	/**
	 * Decode and validate an authenticated migration journal.
	 *
	 * @param string $raw       Raw for this operation.
	 * @param string $run       Run for this operation.
	 * @param bool   $encrypted Encrypted for this operation.
	 * @return array Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function decode_journal( string $raw, string $run, bool $encrypted ): array {
		$plan  = json_decode( $raw, true, 64 );
		$owner = is_array( $plan ) && is_string( $plan['site'] ?? null ) ? $plan['site'] : '';
		if ( ! is_array( $plan ) || ( $plan['run_id'] ?? '' ) !== $run ||
			! is_array( $plan['files'] ?? null ) || ! is_array( $plan['entries'] ?? null ) ||
			( $encrypted ? ( ( $plan['storage_version'] ?? null ) !== 2 || ! hash_equals( EncryptedStorage::identity(), $owner ) ) :
				( isset( $plan['storage_version'] ) || ! self::legacy_owner( $owner ) ) ) ) {
			throw new \RuntimeException( esc_html__( 'Migration unavailable.', 'leenkat-forms' ) );
		}
		return $plan;
	}
	/**
	 * Authenticate both the journal name and its site; usable by WordPress uninstall.
	 *
	 * @param string $run       Run for this operation.
	 * @param bool   $encrypted Encrypted for this operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function stored( string $run, bool $encrypted ): array {
		$path = $encrypted ? EncryptedStorage::open( self::name( $run ) . '.lkf' ) : self::legacy_doc( $run );
		try {
			if ( ! $path || ( ! $encrypted && ( ! is_file( $path ) || filesize( $path ) > 33554432 ) ) ) {
				throw new \RuntimeException( esc_html__( 'Migration unavailable.', 'leenkat-forms' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bound the existing local legacy journal read to its validated byte limit plus one. WP_Filesystem get_contents does not expose a read length.
			$raw = $encrypted ? stream_get_contents( $path, 33554433 ) : file_get_contents( $path, false, null, 0, 33554433 );
			if ( ! is_string( $raw ) || strlen( $raw ) > 33554432 ) {
				throw new \RuntimeException( esc_html__( 'Migration unavailable.', 'leenkat-forms' ) );
			}
			return self::decode_journal( $raw, $run, $encrypted );
		} finally {
			if ( $encrypted && $path ) {
				EncryptedStorage::close( $path );
			}
		}
	}
	/**
	 * Read the journal for the requested migration run.
	 *
	 * @param string $run Run for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function read( string $run ): array {
		self::gate();
		$journal = self::databaseJournal( $run );
		if ( null !== $journal ) {
			return $journal;
		}
		$encrypted = null !== EncryptedStorage::ciphertext( self::name( $run ) . '.lkf' );
		return self::stored( $run, $encrypted );
	}
	/**
	 * Resolve the verified source path for a planned file.
	 *
	 * @param array $plan Plan for this operation.
	 * @param array $file File data for this operation.
	 * @return string Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function source( array $plan, array $file ): string {
		$path     = $file['source'] ?? '';
		$uploads  = wp_upload_dir( null, false );
		$base     = wp_normalize_path( $uploads['basedir'] );
		$previous = $plan['uploads_base'] ?? ( defined( 'LEENKAT_FORMS_LEGACY_UPLOADS_DIR' ) ? (string) LEENKAT_FORMS_LEGACY_UPLOADS_DIR : $base );
		$previous = is_string( $previous ) ? rtrim( wp_normalize_path( $previous ), '/' ) : '';
		$path     = is_string( $path ) ? wp_normalize_path( $path ) : '';
		if ( '' === $previous || ! PrivateStorage::inside( $path, $previous ) || strlen( $path ) <= strlen( $previous ) ||
			preg_match( '~(?:^|/)\.{1,2}(?:/|$)|[\x00-\x1f\x7f]~', $path ) ) {
			throw new \RuntimeException( esc_html__( 'A migration source is outside the expected uploads directory.', 'leenkat-forms' ) );
		}
		$dest = rtrim( $base, '/' ) . substr( $path, strlen( $previous ) );
		if ( ! PrivateStorage::noLinks( $dest ) ) {
			throw new \RuntimeException( esc_html__( 'A migration source is outside the expected uploads directory.', 'leenkat-forms' ) );
		}
		return $dest;
	}
	/**
	 * Summarize the recorded migration plan and its outcomes.
	 *
	 * @param array $plan Plan for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function report( array $plan ): array {
		$safe = array(
			'run_id'                           => $plan['run_id'],
			'created_gmt'                      => $plan['created_gmt'],
			'backup_verified'                  => true,
			'source_removal_requires_approval' => true,
			'files'                            => array(),
		);
		foreach ( $plan['files'] as $file ) {
			$safe['files'][] = array_intersect_key( $file, array_flip( array( 'entry_id', 'index', 'field_id', 'name', 'bytes', 'sha256', 'state', 'message' ) ) );
		}return $safe;
	}
	/**
	 * Build and persist a migration plan for the selected entries.
	 *
	 * @param array $entryIds Entry ids for this operation.
	 * @return array Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function plan( array $entryIds = array() ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryIds and its method binding.
		self::gate();
		global $wpdb;
		$inventory        = self::inventory();
		$candidates       = array_filter( $inventory['candidates'], static fn( $f )=> ! $entryIds || in_array( $f['entry_id'], $entryIds, true ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryIds and its method binding.
		$candidates       = array_slice( $candidates, 0, 50 );
		$run_id           = bin2hex( random_bytes( 16 ) );
		$site_identity    = EncryptedStorage::identity( true );
		$resolved_uploads = realpath( wp_upload_dir( null, false )['basedir'] );
		$plan             = array(
			'run_id'          => $run_id,
			'site'            => $site_identity,
			'storage_version' => 2,
			'uploads_base'    => wp_normalize_path( $resolved_uploads ? $resolved_uploads : wp_upload_dir( null, false )['basedir'] ),
			'created_gmt'     => gmdate( 'c' ),
			'entries'         => array(),
			'files'           => array(),
		);
		$required         = array_sum( array_column( $candidates, 'bytes' ) ) * 2 + 16777216;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The local filesystem may not report free space. Preserve the false-result path without emitting a filesystem warning.
		$free = ( is_dir( $plan['uploads_base'] ) ? @disk_free_space( $plan['uploads_base'] ) : false );
		if ( false !== $free && $free < $required ) {
			throw new \RuntimeException( esc_html__( 'Insufficient space for verified migration backups.', 'leenkat-forms' ) );
		}self::write( $plan );
		foreach ( $candidates as $candidate ) {
			$id = $candidate['entry_id'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			$entry  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}leenfo_entries WHERE id=%d", $id ), ARRAY_A );
			$meta   = json_decode( (string) $entry['meta'], true );
			$old    = $meta['uploads'][ $candidate['index'] ] ?? null;
			$source = is_array( $old ) ? FileRecords::legacyPath( $old ) : null;
			if ( ! $source || ! hash_equals( $candidate['reference_hash'], hash( 'sha256', wp_json_encode( $old ) ) ) || self::isMedia( $source ) ) {
				throw new \RuntimeException( esc_html__( 'A source changed. Generate a new dry run.', 'leenkat-forms' ) );
			}
			$backup = PrivateStorage::put( $source, strtolower( pathinfo( $source, PATHINFO_EXTENSION ) ), (int) $candidate['bytes'], $candidate['sha256'], (int) $entry['form_id'] );
			if ( ! PrivateStorage::matches( $backup, (int) $candidate['bytes'], $candidate['sha256'] ) ) {
				throw new \RuntimeException( esc_html__( 'Backup verification failed. Sources were retained.', 'leenkat-forms' ) );
			}
			if ( ! isset( $plan['entries'][ $id ] ) ) {
				$plan['entries'][ $id ] = array(
					'row'         => $entry,
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					'projections' => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}leenfo_entry_values WHERE entry_id=%d ORDER BY id", $id ), ARRAY_A ),
				);
			}
			$plan['files'][] = $candidate + array(
				'source'          => $source,
				'original_record' => $old,
				'backup_key'      => $backup,
				'destination_key' => null,
				'state'           => 'backed_up',
			);
			self::write( $plan );
			PrivateStorage::keep( $backup );
		}
		update_option( 'leenfo_file_migration_latest', $plan['run_id'], false );
		return self::report( $plan );
	}
	/**
	 * Acquire the maintenance lease for a migration run.
	 *
	 * @param string $run Run for this operation.
	 * @return string Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function lock( string $run = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Keep the original named parameter for existing callers. The lock remains site-wide across all migration runs.
		$key     = 'leenfo_file_migration_lock';
		$expires = (int) get_option( $key, 0 );
		global $wpdb;
		if ( $expires && $expires < time() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $key, (string) $expires ) );
			wp_cache_delete( $key, 'options' );
		}if ( ! add_option( $key, time() + 600, '', false ) ) {
			throw new \RuntimeException( esc_html__( 'File maintenance is already running.', 'leenkat-forms' ) );
		}return $key;
	}
	/**
	 * Apply the recorded migration plan while holding the maintenance lease.
	 *
	 * @param string $run                 Run for this operation.
	 * @param bool   $removePublicSources Remove public sources for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function apply( string $run, bool $removePublicSources = false ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $removePublicSources and its method binding.
		self::gate();
		$lock = self::lock( $run );try {
			return self::apply_locked( $run, $removePublicSources );} finally { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $removePublicSources and its method binding.
			delete_option( $lock );}
	}
	/**
	 * Apply a migration plan under the existing lease.
	 *
	 * @param string $run    Run for this operation.
	 * @param bool   $remove Remove for this operation.
	 * @return array Result for the requested operation.
	 */
	private static function apply_locked( string $run, bool $remove ): array {
		$plan   = self::read( $run );
		$leases = array();try {
			foreach ( array_keys( $plan['entries'] ) as $id ) {
				$leases[] = FileLease::acquire( (int) $id, true );
			}return self::apply_contents( $run, $remove );} finally {
			foreach ( $leases as $lease ) {
				FileLease::release( $lease );
			}
			}
	}
	/**
	 * Process the selected files and record their migration results.
	 *
	 * @param string $run    Run for this operation.
	 * @param bool   $remove Remove for this operation.
	 * @return array Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function apply_contents( string $run, bool $remove ): array {
		global $wpdb;
		$plan        = self::read( $run );
		$entries     = $wpdb->prefix . 'leenfo_entries';
		$files       = $wpdb->prefix . 'leenfo_entry_files';
		$deliveries  = $wpdb->prefix . 'leenfo_deliveries';
		$projections = $wpdb->prefix . 'leenfo_entry_values';
		if ( ! Transactions::supported( array( $entries, $files, $deliveries, $projections, $wpdb->posts, $wpdb->postmeta ) ) ) {
			throw new \RuntimeException( esc_html__( 'Transactional migration is unavailable.', 'leenkat-forms' ) );
		}
		foreach ( $plan['entries'] as $entry_id => &$snapshot ) {
			$indices = array();
			foreach ( $plan['files'] as $i => $file ) {
				if ( $file['entry_id'] === (int) $entry_id ) {
					$indices[] = $i;
				}
			}if ( ! $indices ) {
				continue;
			}
			foreach ( $indices as $i ) {
				$f =&$plan['files'][ $i ];
				if ( in_array( $f['state'], array( 'migrated_source_retained', 'source_removed' ), true ) ) {
					continue;
				}
				$source = self::source( $plan, $f );
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A checked file can disappear or become unreadable before hashing. Preserve the false result for the existing integrity check without disclosing the local path.
				if ( file_exists( $source ) && ( ! is_file( $source ) || ! hash_equals( $f['sha256'], (string) @hash_file( 'sha256', $source ) ) ) ) {
					throw new \RuntimeException( esc_html__( 'A public source changed after backup. Generate a new dry run.', 'leenkat-forms' ) );
				}
				$backup = PrivateStorage::verify( $f['backup_key'], (int) $f['bytes'], $f['sha256'] );
				if ( ! $backup ) {
					throw new \RuntimeException( esc_html__( 'A verified backup is unavailable. Sources were retained.', 'leenkat-forms' ) );
				}
				if ( ! $f['destination_key'] || ! PrivateStorage::matches( $f['destination_key'], (int) $f['bytes'], $f['sha256'] ) ) {
					$f['destination_key'] = PrivateStorage::put( $backup, strtolower( pathinfo( $f['source'], PATHINFO_EXTENSION ) ), (int) $f['bytes'], $f['sha256'], (int) $snapshot['row']['form_id'] );
					self::write( $plan );
				}PrivateStorage::close( $backup );
				if ( ! PrivateStorage::matches( $f['destination_key'], (int) $f['bytes'], $f['sha256'] ) ) {
					throw new \RuntimeException( esc_html__( 'Destination verification failed. Sources were retained.', 'leenkat-forms' ) );
				}unset( $f );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new \RuntimeException( esc_html__( 'Migration could not start.', 'leenkat-forms' ) );
			}$committed = false;
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$entries} WHERE id=%d FOR UPDATE", $entry_id ), ARRAY_A );
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				if ( ! $entry ) {
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					throw new \RuntimeException( __( 'An entry changed. Sources were retained.', 'leenkat-forms' ) );
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				}$meta = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( $entry['meta'] );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT id,status FROM {$deliveries} WHERE entry_id=%d FOR UPDATE", $entry_id ), ARRAY_A );
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				if ( $wpdb->last_error ) {
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					throw new \RuntimeException( __( 'Notification state unavailable.', 'leenkat-forms' ) );
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				}foreach ( $jobs as $job ) {
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					if ( 'processing' === $job['status'] ) {
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						throw new \RuntimeException( __( 'A notification is processing. Retry migration later.', 'leenkat-forms' ) );
					}
				}
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				$all_migrated = true;
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				foreach ( $indices as $i ) {
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					$f = $plan['files'][ $i ];
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					$record = $meta['uploads'][ $f['index'] ] ?? array();
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					if ( ( $record['storage_key'] ?? '' ) !== $f['destination_key'] ) {
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						$all_migrated = false;
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					} else {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						$registered = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$files} WHERE id=%d AND entry_id=%d AND storage_key=%s AND status='private'", (int) ( $record['file_id'] ?? 0 ), $entry_id, $f['destination_key'] ), ARRAY_A );
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						if ( ! $registered || ! FileRecords::resolve( $registered ) ) {
							// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
							throw new \RuntimeException( __( 'A migrated file association needs administrator attention. Sources were retained.', 'leenkat-forms' ) );
						}
					}
				}
				if ( ! $all_migrated ) {
					if ( hash( 'sha256', wp_json_encode( $entry ) ) !== hash( 'sha256', wp_json_encode( $snapshot['row'] ) ) ) {
						throw new \RuntimeException( __( 'An entry changed after backup. Generate a new dry run.', 'leenkat-forms' ) );
					}$payload = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( $entry['payload'] );
					foreach ( $indices as $i ) {
						$f       = $plan['files'][ $i ];
						$current = $meta['uploads'][ $f['index'] ] ?? array();
						if ( ! hash_equals( $f['reference_hash'], hash( 'sha256', wp_json_encode( $current ) ) ) ) {
							throw new \RuntimeException( __( 'A legacy reference changed. Sources were retained.', 'leenkat-forms' ) );
						}
						$stream = PrivateStorage::path( $f['destination_key'] );
						if ( ! is_resource( $stream ) ) {
							throw new \RuntimeException( esc_html__( 'A migration destination is unavailable.', 'leenkat-forms' ) );
						}
						$mime = ( new \finfo( FILEINFO_MIME_TYPE ) )->buffer( (string) stream_get_contents( $stream, 65536 ) );
						$mime = $mime ? $mime : 'application/octet-stream';
						PrivateStorage::close( $stream );
						$records = array(
							array(
								'storage_key'   => $f['destination_key'],
								'attachment_id' => 0,
								'kind'          => 'private',
								'field_id'      => $f['field_id'],
								'name'          => $f['name'],
								'mime'          => $mime,
								'size'          => $f['bytes'],
								'sha256'        => $f['sha256'],
							),
						);
						if ( ! FileRecords::register( (int) $entry_id, $records ) ) {
							throw new \RuntimeException( __( 'File association could not be saved.', 'leenkat-forms' ) );
						}$meta['uploads'][ $f['index'] ] = $records[0];
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					$version = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $wpdb->get_var( $wpdb->prepare( "SELECT definition FROM {$wpdb->prefix}leenfo_form_versions WHERE id=%d", $entry['form_version_id'] ) ) );
					$payload = FileRecords::safeValues( $version, $payload, $meta['uploads'] );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					if ( $wpdb->update(
						$entries,
						array(
							'meta'    => wp_json_encode( $meta ),
							'payload' => wp_json_encode( $payload ),
						),
						array( 'id' => $entry_id )
					) === false ) {
						throw new \RuntimeException( __( 'Entry association could not be saved.', 'leenkat-forms' ) );
					}
					foreach ( $indices as $i ) {
						$field              = $plan['files'][ $i ]['field_id'];
						$characters         = preg_split( '//u', (string) ( $payload[ $field ] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
						$visible_characters = array_slice( $characters ? $characters : array(), 0, 191 );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
						if ( $wpdb->update(
							$projections,
							array( 'value_text' => implode( '', $visible_characters ) ),
							array(
								'entry_id' => $entry_id,
								'field_id' => $field,
							)
						) === false ) {
							throw new \RuntimeException( __( 'Field association could not be saved.', 'leenkat-forms' ) );
						}
					}
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new \RuntimeException( __( 'Migration could not commit.', 'leenkat-forms' ) );
				}$committed = true;
			} finally {
				if ( ! $committed ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					$wpdb->query( 'ROLLBACK' );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			$snapshot['migrated_hash'] = hash( 'sha256', wp_json_encode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$entries} WHERE id=%d", $entry_id ), ARRAY_A ) ) );
			foreach ( $indices as $i ) {
				if ( 'source_removed' !== $plan['files'][ $i ]['state'] ) {
					$plan['files'][ $i ]['state'] = 'migrated_source_retained';
				}PrivateStorage::keep( $plan['files'][ $i ]['destination_key'] );
			}self::write( $plan );
		}unset( $snapshot );
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		if ( $remove ) {
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
			foreach ( $plan['files'] as &$file ) {
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				if ( 'source_removed' === $file['state'] ) {
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					continue;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				}$entry_meta = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$entries} WHERE id=%d", $file['entry_id'] ) ), true );
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				$associated = $entry_meta['uploads'][ $file['index'] ] ?? array();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				$registered = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$files} WHERE id=%d AND entry_id=%d AND field_id=%s AND storage_key=%s AND status='private'", (int) ( $associated['file_id'] ?? 0 ), $file['entry_id'], $file['field_id'], $file['destination_key'] ), ARRAY_A );
				if ( ! $registered || ( $associated['storage_key'] ?? '' ) !== $file['destination_key'] || ! FileRecords::resolve( $registered ) ) {
					throw new \RuntimeException( esc_html__( 'A migrated association changed. The public source was retained.', 'leenkat-forms' ) );
				}
				$candidate = self::source( $plan, $file );
				$source    = FileRecords::legacyPath( array( 'path' => $candidate ) );if ( ! $source ) {
					if ( file_exists( $candidate ) || is_link( $candidate ) ) {
						throw new \RuntimeException( esc_html__( 'A public source is unavailable for verification. It was retained.', 'leenkat-forms' ) );
					}$file['state'] = 'source_removed';
					self::write( $plan );
					continue;}
				if ( ! PrivateStorage::matches( $file['destination_key'], (int) $file['bytes'], $file['sha256'] ) || ! PrivateStorage::matches( $file['backup_key'], (int) $file['bytes'], $file['sha256'] ) || ! hash_equals( $file['sha256'], (string) hash_file( 'sha256', $source ) ) || self::isMedia( $source ) ) {
					throw new \RuntimeException( esc_html__( 'Source removal verification failed. The source was retained.', 'leenkat-forms' ) );
				}
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				$shared = false;
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				$last = 0;
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
				do {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,meta FROM {$entries} WHERE id>%d ORDER BY id LIMIT 100", $last ) );
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					if ( $wpdb->last_error || ! is_array( $rows ) ) {
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						throw new \RuntimeException( esc_html__( 'Shared file references could not be verified. Sources were retained.', 'leenkat-forms' ) );
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					}foreach ( $rows as $row ) {
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						$last = (int) $row->id;
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						$meta = \LeenkatForms\Infrastructure\JsonValue::decode_or_empty( (string) $row->meta );
						// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
						foreach ( $meta['uploads'] ?? array() as $record ) {
							// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
							if ( is_array( $record ) && FileRecords::legacyPath( $record ) === $source ) {
											// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
											$shared = true;
							}
						}
					}
				// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					$has_more = ! $shared && 100 === count( $rows );
				} while ( $has_more );
				if ( $shared ) {
					$file['message'] = __( 'Public copy retained because another entry still references it.', 'leenkat-forms' );
					self::write( $plan );
					continue;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees. The verified path may disappear between validation and cleanup. Preserve the existing boolean or best-effort result without disclosing the path.
				}if ( ! @unlink( $source ) ) {
					$file['message'] = __( 'Public source cleanup failed; retry is required.', 'leenkat-forms' );
					self::write( $plan );
					continue;
				}$file['state'] = 'source_removed';
				unset( $file['message'] );
				self::write( $plan );
			}
		}unset( $file );
		return self::report( $plan );
	}
	/**
	 * Restoring public source URLs requires an explicit operator decision.
	 *
	 * @param string $run                   Run for this operation.
	 * @param bool   $confirmPublicExposure Confirm public exposure for this operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	public static function rollback( string $run, bool $confirmPublicExposure = false ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $confirmPublicExposure and its method binding.
		self::gate();
		if ( ! $confirmPublicExposure ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $confirmPublicExposure and its method binding.
			throw new \RuntimeException( esc_html__( 'Rollback requires explicit approval to restore the former public files.', 'leenkat-forms' ) );
		}
		$lock = self::lock( $run );
		global $wpdb;
		$leases = array();
		try {
			$plan    = self::read( $run );
			$entries = $wpdb->prefix . 'leenfo_entries';
			$files   = $wpdb->prefix . 'leenfo_entry_files';
			$values  = $wpdb->prefix . 'leenfo_entry_values';
			$jobs    = $wpdb->prefix . 'leenfo_deliveries';
			if ( ! Transactions::supported( array( $entries, $files, $values, $jobs ) ) ) {
				throw new \RuntimeException( __( 'Transactional recovery is unavailable.', 'leenkat-forms' ) );
			}
			foreach ( array_keys( $plan['entries'] ) as $id ) {
				$leases[] = FileLease::acquire( (int) $id, true );
			}
			foreach ( $plan['entries'] as $entry_id => $snapshot ) {
				if ( ! isset( $snapshot['migrated_hash'] ) ) {
					continue;
				}$indices = array();
				foreach ( $plan['files'] as $i => $file ) {
					if ( $file['entry_id'] === (int) $entry_id ) {
						$indices[] = $i;
					}
				}
				if ( ! $indices ) {
					continue;
				}$committed = false;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
				if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
					throw new \RuntimeException( __( 'Recovery could not start.', 'leenkat-forms' ) );
				}
				try {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					$entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$entries} WHERE id=%d FOR UPDATE", $entry_id ), ARRAY_A );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
					$locked_jobs = $wpdb->get_results( $wpdb->prepare( "SELECT status FROM {$jobs} WHERE entry_id=%d FOR UPDATE", $entry_id ), ARRAY_A );
					if ( $wpdb->last_error ) {
						throw new \RuntimeException( __( 'Notification state unavailable.', 'leenkat-forms' ) );
					}foreach ( $locked_jobs as $job ) {
						if ( 'processing' === $job['status'] ) {
							throw new \RuntimeException( __( 'A notification is processing. Retry recovery later.', 'leenkat-forms' ) );
						}
					}
					$hash          = hash( 'sha256', wp_json_encode( $entry ) );
					$original_hash = hash( 'sha256', wp_json_encode( $snapshot['row'] ) );
					if ( ! $entry || ( ! hash_equals( $snapshot['migrated_hash'], $hash ) && ! hash_equals( $original_hash, $hash ) ) ) {
						throw new \RuntimeException( __( 'An entry changed after migration. Automatic rollback was refused.', 'leenkat-forms' ) );
					}
					foreach ( $indices as $i ) {
						$file   = $plan['files'][ $i ];
						$backup = PrivateStorage::verify( $file['backup_key'], (int) $file['bytes'], $file['sha256'] );
						if ( ! $backup ) {
							throw new \RuntimeException( __( 'A recovery backup is unavailable.', 'leenkat-forms' ) );
						}
						$source = self::source( $plan, $file );
						$parent = realpath( dirname( $source ) );
						$base   = realpath( wp_upload_dir()['basedir'] );
						if ( ! $parent || ! $base || ! PrivateStorage::inside( $parent, $base ) || ! PrivateStorage::noLinks( $source ) ) {
							throw new \RuntimeException( __( 'A recovery destination is unavailable.', 'leenkat-forms' ) );
						}
						if ( ! is_file( $source ) || ! hash_equals( $file['sha256'], (string) hash_file( 'sha256', $source ) ) ) {
							throw new \RuntimeException( __( 'Rollback requires the unchanged legacy source. The current private attachment and its verified backup were preserved.', 'leenkat-forms' ) );
						}
						PrivateStorage::close( $backup );
					}
					foreach ( $indices as $i ) {
						$key = $plan['files'][ $i ]['destination_key'];
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
						if ( $key && $wpdb->delete(
							$files,
							array(
								'storage_key' => $key,
								'entry_id'    => $entry_id,
							)
						) === false ) {
							throw new \RuntimeException( __( 'Recovery association failed.', 'leenkat-forms' ) );
						}
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					if ( false === $wpdb->update( $entries, $snapshot['row'], array( 'id' => $entry_id ) ) ) {
						throw new \RuntimeException( __( 'Recovery entry failed.', 'leenkat-forms' ) );
					}
					foreach ( $snapshot['projections'] as $projection ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
						if ( false === $wpdb->update( $values, $projection, array( 'id' => $projection['id'] ) ) ) {
							throw new \RuntimeException( __( 'Recovery values failed.', 'leenkat-forms' ) );
						}
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
					if ( false === $wpdb->query( 'COMMIT' ) ) {
						throw new \RuntimeException( __( 'Recovery could not commit.', 'leenkat-forms' ) );
					}$committed = true;
				} finally {
					if ( ! $committed ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Verified private/owned paths require native exclusive-create, bounded streams, leases and atomic cleanup; WP filesystem transports cannot preserve these guarantees.
						$wpdb->query( 'ROLLBACK' ); }
				}
				foreach ( $indices as $i ) {
					$file =&$plan['files'][ $i ];
					if ( $file['destination_key'] ) {
						FileRecords::discard( array( array( 'storage_key' => $file['destination_key'] ) ) );
					}$file['destination_key'] = null;
					$file['state']            = 'rolled_back';
					unset( $file );
				}self::write( $plan );
			}
			return self::report( $plan );
		} finally {
			foreach ( $leases as $lease ) {
				FileLease::release( $lease );
			}delete_option( $lock );}
	}
	/**
	 * List the migration journals available for maintenance.
	 *
	 * @return \Generator Result for the requested operation.
	 * @throws \RuntimeException Raised when a required operation cannot complete safely.
	 */
	private static function documents(): \Generator {
		$roots = array();
		if ( null !== get_option( EncryptedStorage::KEY_OPTION, null ) ) {
			$roots[ true ] = EncryptedStorage::root();
		}
		$roots[ false ] = PrivateStorage::legacy_source_root();
		$seen           = array();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no paginated option-name query. Read only the names of this plugin's non-autoloaded migration journals and authenticate each value separately.
		$options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::JOURNAL_OPTION_PREFIX ) . '%'
			)
		);
		if ( $wpdb->last_error || ! is_array( $options ) ) {
			throw new \RuntimeException( esc_html__( 'Migration records could not be read.', 'leenkat-forms' ) );
		}
		foreach ( $options as $option ) {
			$run = substr( $option, strlen( self::JOURNAL_OPTION_PREFIX ) );
			if ( ! preg_match( '/^[a-f0-9]{32}$/D', $run ) ) {
				continue;
			}
			$plan = self::databaseJournal( $run );
			if ( null !== $plan ) {
				$seen[ $run ] = true;
				yield $plan;
			}
		}
		foreach ( $roots as $encrypted => $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$suffix = $encrypted ? 'lkf' : 'json';
			foreach ( new \GlobIterator( $root . '/migration-*.' . $suffix, \FilesystemIterator::SKIP_DOTS ) as $file ) {
				if ( ! preg_match( '/^migration-([a-f0-9]{32})\.(?:json|lkf)$/D', $file->getFilename(), $match ) ) {
					continue;
				}
				if ( ! $file->isFile() || ! PrivateStorage::noLinks( $file->getPathname() ) ) {
					throw new \RuntimeException( esc_html__( 'A migration record needs administrator attention.', 'leenkat-forms' ) );
				}
				if ( isset( $seen[ $match[1] ] ) ) {
					continue;
				}
				$seen[ $match[1] ] = true;
				yield self::stored( $match[1], (bool) $encrypted );
			}
		}
	}
	/**
	 * Collect attachment paths referenced by migration journals.
	 *
	 * @param string $key Key for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function references( string $key ): bool {
		foreach ( self::documents() as $plan ) {
			foreach ( $plan['files'] as $file ) {
				if ( ( $file['backup_key'] ?? null ) === $key || ( $file['destination_key'] ?? null ) === $key ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Identify a public source left behind by an unfinished migration.
	 *
	 * @param int $entryId Entry identifier.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function pendingPublicSource( int $entryId ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing callable method name for PHP callers and callback compatibility. Preserve the existing PHP named parameter $entryId and its method binding.
		foreach ( self::documents() as $plan ) {
			foreach ( $plan['files'] as $file ) {
				if ( $file['entry_id'] === $entryId && 'source_removed' !== $file['state'] && FileRecords::legacyPath( array( 'path' => self::source( $plan, $file ) ) ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $entryId and its method binding.
								return true;
				}
			}
		}return false;
	}
	/**
	 * Called only after an authorized entry deletion; backups obey that deletion as well.
	 *
	 * @param int $entryId Entry identifier.
	 */
	public static function purgeEntryBackups( int $entryId ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing callable method name for PHP callers and callback compatibility. Preserve the existing PHP named parameter $entryId.
		$entry_id = $entryId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Bind the existing PHP named argument to a local variable.
		global $wpdb;
		try {
			foreach ( self::documents() as $plan ) {
				if ( ! isset( $plan['entries'][ $entry_id ] ) ) {
					continue;
				}$remaining = array();
				foreach ( $plan['files'] as $file ) {
					if ( $file['entry_id'] !== $entry_id ) {
						$remaining[] = $file;
						continue;
					}foreach ( array( $file['backup_key'], $file['destination_key'] ) as $key ) {
						if ( ! $key ) {
							continue;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
						}$refs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}leenfo_entry_files WHERE storage_key=%s", $key ) );
						if ( $wpdb->last_error ) {
							return false;
						}if ( ! $refs && ! PrivateStorage::remove( $key ) ) {
							return false;
						}
					}
				}$plan['files'] = $remaining;
				unset( $plan['entries'][ $entry_id ] );
				$plan['erased_entry_ids'][] = $entry_id;
				self::write( $plan );
			}return true;
		} catch ( \Throwable ) {
			return false;}
	}
}
