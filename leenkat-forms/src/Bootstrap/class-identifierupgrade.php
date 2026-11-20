<?php
/**
 * Persistent identifier upgrades for forms, entries and background jobs.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);

namespace LeenkatForms\Bootstrap;

/** One-time transition for identifiers used by the submitted 1.0.1 builds. */
final class IdentifierUpgrade {
	private const MARKER = 'leenfo_identifier_schema';
	private const OLD    = 'lkf_';
	private const NEW    = 'leenfo_';
	private const JOBS   = array( 'send_delivery', 'reconcile_deliveries', 'file_maintenance' );
	private const CAPS   = array( 'manage_forms', 'view_entries', 'manage_settings' );

	/**
	 * Return the legacy site settings option name for compatibility reads.
	 *
	 * @return string Legacy option name.
	 */
	public static function legacySettingsName(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return self::OLD . 'site_settings';
	}

	/**
	 * Upgrade identifiers while holding the database lock for this WordPress site.
	 *
	 * @return bool Whether the upgrade is complete.
	 */
	public static function run(): bool {
		if ( '2' === get_option( self::MARKER ) ) {
			return true;
		}
		global $wpdb;
		$lock = 'leenfo_upgrade_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix ), 0, 40 );
		if ( ! \LeenkatForms\Infrastructure\Database\MigrationLock::acquire( $lock ) ) {
			return false;
		}
		try {
			// Refresh option state after acquiring the migration lock rather than trusting an earlier request cache.
			wp_cache_delete( self::MARKER, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( '2' === get_option( self::MARKER ) ) {
				return true;
			}
			if ( null !== get_option( self::OLD . 'uninstall_state', null ) ) {
				return false;
			}
			foreach ( array( 'file_migration_lock', 'migration_lock', 'site_settings_lock', 'captcha_settings_lock' ) as $name ) {
				if ( (int) get_option( self::OLD . $name, 0 ) > time() ) {
					return false;
				}
			}
			self::require_idle_delivery_workers();
			self::post_jobs( false );
			self::tables();
			self::options();
			self::permissions();
			self::cron();
			self::jobs();
			self::post_jobs( true );
			return update_option( self::MARKER, '2', false ) || '2' === get_option( self::MARKER );
		} catch ( \Throwable ) {
			return false;
		} finally {
			\LeenkatForms\Infrastructure\Database\MigrationLock::release( $lock );
		}
	}

	/**
	 * Stop the upgrade when a required database operation fails.
	 *
	 * @param bool $condition Whether the operation succeeded.
	 * @throws \RuntimeException When the condition is false.
	 */
	private static function must( bool $condition ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( 'Leenkat Forms identifier migration stopped.' );
		}
	}

	/**
	 * Check the live MySQL schema for this exact table name.
	 *
	 * @param string $table Table name including the WordPress prefix.
	 * @return bool Whether the exact table exists.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no exact schema-existence API. Inspect current MySQL metadata because the migration renames tables during this request.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::must( ! $wpdb->last_error );
		return $found === $table;
	}

	/**
	 * Switch all existing legacy table names using one compound rename.
	 */
	private static function tables(): void {
		global $wpdb;
		$renames        = array();
		$sqlite_renames = array();
		foreach ( array( 'forms', 'form_versions', 'entries', 'entry_values', 'entry_files', 'deliveries' ) as $name ) {
			$old = $wpdb->prefix . self::OLD . $name;
			$new = $wpdb->prefix . self::NEW . $name;
			self::must( strlen( $new ) <= 64 );
			if ( self::table_exists( $old ) ) {
				self::must( ! self::table_exists( $new ) );
				$renames[]        = $wpdb->prepare( '%i TO %i', $old, $new );
				$sqlite_renames[] = $wpdb->prepare( 'ALTER TABLE %i RENAME TO %i', $old, $new );
			}
		}
		if ( $renames ) {
			if ( \LeenkatForms\Infrastructure\Database\MigrationLock::sqlite() ) {
				// The SQLite integration supports ALTER TABLE but not compound MySQL RENAME TABLE. The surrounding OS lease prevents competing upgrades; an interrupted rename is resumed by the exact old/new existence checks above.
				foreach ( $sqlite_renames as $statement ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Each statement was prepared with %i identifiers above. Schema renames cannot be cached or expressed through a WordPress object API.
					self::must( false !== $wpdb->query( $statement ) );
				}
				return;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Every pair is built with %i placeholders above from fixed plugin-owned identifiers. MySQL requires one compound RENAME to switch all tables atomically.
			self::must( $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ) !== false );
		}
	}

	/**
	 * Rename owned legacy options while retaining values and autoload settings.
	 */
	private static function options(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core only reads options by name. Enumerate owned legacy keys through the indexed name and read fresh state under the migration lock.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( self::OLD ) . '%',
				$wpdb->esc_like( '_transient_' . self::OLD ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::OLD ) . '%'
			)
		);
		self::must( is_array( $rows ) && ! $wpdb->last_error );
		foreach ( $rows as $old ) {
			$new = preg_replace( '/^((?:_transient_(?:timeout_)?)?)' . self::OLD . '/', '$1' . self::NEW, $old );
			if ( ! Uninstaller::ownedOption( $new ) &&
				! in_array( $new, array( self::NEW . 'site_settings', self::NEW . 'uninstall_error' ), true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The unique option-name index rejects collisions atomically. Core has no rename API that preserves serialized bytes and autoload without a copy/delete window. Invalidate option caches below.
			self::must( $wpdb->update( $wpdb->options, array( 'option_name' => $new ), array( 'option_name' => $old ) ) === 1 );
			wp_cache_delete( $old, 'options' );
			wp_cache_delete( $new, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/**
	 * Move legacy role capabilities and user or upload metadata by row ID.
	 */
	private static function permissions(): void {
		global $wpdb;
		foreach ( wp_roles()->roles as $name => $role ) {
			foreach ( self::CAPS as $cap ) {
				if ( array_key_exists( self::OLD . $cap, $role['capabilities'] ) ) {
					$object = get_role( $name );
					$object->add_cap( self::NEW . $cap, (bool) $role['capabilities'][ self::OLD . $cap ] );
					$object->remove_cap( self::OLD . $cap );
				}
			}
		}
		$key = $wpdb->prefix . 'capabilities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Core metadata updates by row ID, but enumeration does not expose IDs for these scoped existing grants. Read the current rows and update through the metadata API below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT umeta_id, meta_value FROM %i WHERE meta_key = %s AND meta_value LIKE %s',
				$wpdb->usermeta,
				$key,
				'%' . $wpdb->esc_like( self::OLD ) . '%'
			)
		);
		self::must( is_array( $rows ) && ! $wpdb->last_error );
		foreach ( $rows as $row ) {
			$caps = maybe_unserialize( $row->meta_value );
			self::must( is_array( $caps ) );
			$before = $caps;
			foreach ( self::CAPS as $cap ) {
				if ( array_key_exists( self::OLD . $cap, $caps ) ) {
					$caps[ self::NEW . $cap ] = $caps[ self::OLD . $cap ];
					unset( $caps[ self::OLD . $cap ] );
				}
			}
			if ( $caps !== $before ) {
				self::must( (bool) update_metadata_by_mid( 'user', (int) $row->umeta_id, $caps ) );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Get exact metadata row IDs by the indexed keys, preserving duplicate rows and values. Core provides no key enumeration returning row IDs.
		$preferences = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT umeta_id, meta_key FROM %i WHERE meta_key IN (%s, %s)',
				$wpdb->usermeta,
				self::OLD . 'entries_per_page',
				self::OLD . 'forms_per_page'
			)
		);
		self::must( is_array( $preferences ) && ! $wpdb->last_error );
		foreach ( $preferences as $preference ) {
			$id   = (int) $preference->umeta_id;
			$meta = get_metadata_by_mid( 'user', $id );
			$new  = self::NEW . substr( $preference->meta_key, strlen( self::OLD ) );
			self::must( $meta && update_metadata_by_mid( 'user', $id, $meta->meta_value, $new ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the exact legacy lease metadata row IDs via the indexed key. Core performs each rename and invalidates metadata caches below.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE meta_key = %s', $wpdb->postmeta, '_' . self::OLD . 'upload_lease' ) );
		self::must( ! $wpdb->last_error );
		foreach ( $ids as $id ) {
			$meta = get_metadata_by_mid( 'post', (int) $id );
			self::must( $meta && update_metadata_by_mid( 'post', (int) $id, $meta->meta_value, '_' . self::NEW . 'upload_lease' ) );
		}
	}

	/**
	 * Rename existing WP-Cron hooks while retaining their timestamps and arguments.
	 */
	private static function cron(): void {
		$cron   = _get_cron_array();
		$before = $cron;
		foreach ( $cron as &$hooks ) {
			foreach ( self::JOBS as $job ) {
				$old = self::OLD . $job;
				$new = self::NEW . $job;
				if ( ! isset( $hooks[ $old ] ) ) {
					continue;
				}
				$hooks[ $new ] = ( $hooks[ $new ] ?? array() ) + $hooks[ $old ];
				unset( $hooks[ $old ] );
			}
		}
		unset( $hooks );
		if ( $before !== $cron ) {
			self::must( true === _set_cron_array( $cron, true ) );
		}
	}

	/**
	 * Confirm that legacy delivery work has no active runner or claim.
	 */
	private static function require_idle_delivery_workers(): void {
		global $wpdb;
		$deliveries = $wpdb->prefix . self::OLD . 'deliveries';
		if ( self::table_exists( $deliveries ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- An old worker can take a delivery concurrently. Check the freshly indexed status in the old custom table while migrating.
			$working = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s LIMIT 1', $deliveries, 'processing' ) );
			self::must( ! $wpdb->last_error && null === $working );
		}
		$jobs   = $wpdb->prefix . 'actionscheduler_actions';
		$groups = $wpdb->prefix . 'actionscheduler_groups';
		if ( self::table_exists( $jobs ) && self::table_exists( $groups ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live provider claim is mutable and must be inspected before renaming the hook. Action Scheduler may be inactive while its tables exist.
			$working = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT a.action_id FROM %i AS a JOIN %i AS g ON a.group_id = g.group_id WHERE a.hook = %s AND g.slug = %s AND (a.status = %s OR (a.status = %s AND a.claim_id <> 0)) LIMIT 1',
					$jobs,
					$groups,
					self::OLD . 'send_delivery',
					'leenkat-forms',
					'in-progress',
					'pending'
				)
			);
			self::must( ! $wpdb->last_error && null === $working );
		}
	}

	/**
	 * Inspect or migrate owned actions on older Action Scheduler post stores.
	 *
	 * @param bool $apply Whether to rename verified unclaimed actions.
	 */
	private static function post_jobs( bool $apply ): void {
		global $wpdb;
		$old = self::OLD . 'send_delivery';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect only actions owned by the plugin on the legacy scheduler backend before its independent store migration.
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT p.ID, p.post_status, p.post_password FROM %i p JOIN %i r ON r.object_id=p.ID
             JOIN %i x ON x.term_taxonomy_id=r.term_taxonomy_id JOIN %i t ON t.term_id=x.term_id
             WHERE p.post_type=%s AND p.post_title=%s AND x.taxonomy=%s AND t.slug=%s
             AND p.post_status IN (%s,%s) ORDER BY p.ID LIMIT 1001',
				$wpdb->posts,
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$wpdb->terms,
				'scheduled-action',
				$old,
				'action-group',
				'leenkat-forms',
				'pending',
				'in-progress'
			)
		);
		self::must( is_array( $jobs ) && ! $wpdb->last_error && count( $jobs ) < 1001 );
		foreach ( $jobs as $job ) {
			self::must( 'pending' === $job->post_status && '' === $job->post_password );
		}
		if ( ! $apply ) {
			return; }
		foreach ( $jobs as $job ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Preserve arguments, scheduling, group, claim state and date. Only the matched pending hook changes.
			$changed = $wpdb->update(
				$wpdb->posts,
				array( 'post_title' => self::NEW . 'send_delivery' ),
				array(
					'ID'            => (int) $job->ID,
					'post_type'     => 'scheduled-action',
					'post_title'    => $old,
					'post_status'   => 'pending',
					'post_password' => '',
				)
			);
			self::must( 1 === $changed );
			clean_post_cache( (int) $job->ID );
		}
	}

	/**
	 * Migrate pending table-backed jobs and recheck for concurrent legacy claims.
	 */
	private static function jobs(): void {
		global $wpdb;
		$jobs   = $wpdb->prefix . 'actionscheduler_actions';
		$groups = $wpdb->prefix . 'actionscheduler_groups';
		if ( ! self::table_exists( $jobs ) || ! self::table_exists( $groups ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Update only pending unclaimed owned jobs in place. The public scheduling APIs would cancel and recreate jobs and change their IDs or due/claim state.
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i AS a JOIN %i AS g ON a.group_id = g.group_id SET a.hook = %s WHERE a.hook = %s AND g.slug = %s AND a.status = %s AND a.claim_id = 0',
				$jobs,
				$groups,
				self::NEW . 'send_delivery',
				self::OLD . 'send_delivery',
				'leenkat-forms',
				'pending'
			)
		);
		self::must( false !== $result );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Refuse the completion marker if a worker claimed an old action between the preflight and the conditional update.
		$remaining = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT a.action_id FROM %i AS a JOIN %i AS g ON a.group_id = g.group_id WHERE a.hook = %s AND g.slug = %s AND a.status IN (%s, %s) LIMIT 1',
				$jobs,
				$groups,
				self::OLD . 'send_delivery',
				'leenkat-forms',
				'pending',
				'in-progress'
			)
		);
		self::must( ! $wpdb->last_error && null === $remaining );
	}
}
