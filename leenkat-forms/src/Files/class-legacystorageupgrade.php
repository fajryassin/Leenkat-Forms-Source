<?php
/**
 * Non-destructive relocation of historical private files into encrypted uploads.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Files;

use LeenkatForms\Infrastructure\Database\MigrationLock;

/** Bounded, resumable copies retain original files and persisted download identities. */
final class LegacyStorageUpgrade {

	/**
	 * Copy one batch of verified legacy files, retaining every original for rollback.
	 *
	 * @return bool Whether the batch completed safely.
	 */
	public static function run(): bool {
		if ( null !== get_option( 'leenfo_uninstall_state', null ) || ( ! current_user_can( 'manage_options' ) && ! wp_doing_cron() ) ) {
			return false;
		}
		global $wpdb;
		$name = 'leenfo_files_' . substr( hash( 'sha256', $wpdb->prefix . '|' . get_current_blog_id() ), 0, 40 );
		if ( ! MigrationLock::acquire( $name ) ) {
			return false;
		}
		try {
			$source = PrivateStorage::legacy_source_root();
			if ( null !== get_option( EncryptedStorage::KEY_OPTION, null ) ) {
				EncryptedStorage::root( true );
			}
			$parent = dirname( EncryptedStorage::formRoot( '1' ) );
			if ( is_dir( $parent ) ) {
				EncryptedStorage::protect( $parent );
			}
			if ( is_dir( $source ) && '' === get_option( 'leenfo_legacy_private_source', '' ) ) {
				add_option( 'leenfo_legacy_private_source', $source, '', false );
				if ( get_option( 'leenfo_legacy_private_source', '' ) !== $source ) {
					return false;
				}
			}
			$cursor = (int) get_option( 'leenfo_legacy_private_cursor', 0 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This bounded primary-key migration reads fresh custom-table ownership under the migration lock. WordPress has no API for these attachment records and stale cached state is unsafe.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,entry_id,storage_key,byte_size,sha256 FROM {$wpdb->prefix}leenfo_entry_files WHERE id>%d AND status='private' ORDER BY id LIMIT 100", $cursor ), ARRAY_A );
			if ( $wpdb->last_error || ! is_array( $rows ) ) {
				return false;
			}
			foreach ( $rows as $row ) {
				$key = $row['storage_key'];
				if ( PrivateStorage::encrypted( $key ) ) {
					EncryptedStorage::objectRoot( $key, true );
				} else {
					if ( ! PrivateStorage::validKey( $key ) ) {
						return false;
					}
					$lease = FileLease::acquire( (int) $row['entry_id'], true );
					try {
						$alias = PrivateStorage::legacy_alias( $key );
						if ( ! EncryptedStorage::ciphertext( $alias ) ) {
							$plain = PrivateStorage::verify( $key, (int) $row['byte_size'], $row['sha256'] );
							if ( ! is_string( $plain ) || ! PrivateStorage::noLinks( $plain ) ) {
								return false;
							}
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read the integrity-verified original directly into authenticated encryption. A memory stream avoids creating any plaintext copy in public uploads.
							$stream = fopen( $plain, 'rb' );
							if ( ! $stream ) {
								return false;
							}
							try {
								$partial = EncryptedStorage::objectRoot( $alias, true ) . '/' . $alias . '.partial';
								// A deterministic alias is owned by this verified database record and held entry lease. An interrupted ciphertext-only write may be discarded and retried; the original remains untouched.
								if ( file_exists( $partial ) && ( ! PrivateStorage::noLinks( $partial ) || ! is_file( $partial ) || ! wp_delete_file_from_directory( $partial, dirname( $partial ) ) ) ) {
									return false;
								}
								EncryptedStorage::write( $alias, $stream );
							} finally {
								EncryptedStorage::close( $stream );
							}
						}
						$verified = EncryptedStorage::open( $alias, (int) $row['byte_size'], $row['sha256'] );
						if ( ! $verified ) {
							return false;
						}
						EncryptedStorage::close( $verified );
					} finally {
						FileLease::release( $lease );
					}
				}
				$cursor = (int) $row['id'];
				update_option( 'leenfo_legacy_private_cursor', $cursor, false );
				if ( (int) get_option( 'leenfo_legacy_private_cursor', 0 ) !== $cursor ) {
					return false;
				}
			}
			return true;
		} catch ( \Throwable ) {
			update_option( 'leenfo_file_cleanup_attention', true, false );
			return false;
		} finally {
			MigrationLock::release( $name );
		}
	}
}
