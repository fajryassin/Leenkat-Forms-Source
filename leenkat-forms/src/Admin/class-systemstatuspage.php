<?php
/**
 * Diagnostics shown in the administration dashboard.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Infrastructure\Diagnostics\Health;

/** Presentation only. Reuses the existing read-only health checks without storing results. */
final class SystemStatusPage {
	/**
	 * Format a status value for display.
	 *
	 * @param string $value Value being processed.
	 * @return string Result for the requested operation.
	 */
	private static function text( string $value ): string {
		$labels = array(
			'Administrator has all Leenkat Forms capabilities' => __( 'Administrator has all Leenkat Forms capabilities', 'leenkat-forms' ),
			'Editor has no Leenkat Forms capabilities' => __( 'Editor has no Leenkat Forms capabilities', 'leenkat-forms' ),
			'Migration lock'                           => __( 'Migration lock', 'leenkat-forms' ),
			'Stored schema matches code'               => __( 'Stored schema matches code', 'leenkat-forms' ),
			'Registered Leenkat Forms REST routes'     => __( 'Registered Leenkat Forms REST routes', 'leenkat-forms' ),
			'Database engines'                         => __( 'Database engines', 'leenkat-forms' ),
			'JSON extension'                           => __( 'JSON extension', 'leenkat-forms' ),
			'OpenSSL or Sodium'                        => __( 'OpenSSL or Sodium', 'leenkat-forms' ),
			'MySQL connection'                         => __( 'MySQL connection', 'leenkat-forms' ),
			'valid minimal definition'                 => __( 'valid minimal definition', 'leenkat-forms' ),
			'reject duplicate IDs'                     => __( 'reject duplicate IDs', 'leenkat-forms' ),
			'reject unknown step field'                => __( 'reject unknown step field', 'leenkat-forms' ),
			'reject invalid schema version'            => __( 'reject invalid schema version', 'leenkat-forms' ),
			'reject non-list fields'                   => __( 'reject non-list fields', 'leenkat-forms' ),
			'reject malformed required'                => __( 'reject malformed required', 'leenkat-forms' ),
			'reject duplicate step assignment'         => __( 'reject duplicate step assignment', 'leenkat-forms' ),
			'stable canonical JSON hash'               => __( 'stable canonical JSON hash', 'leenkat-forms' ),
			'Unknown'                                  => __( 'Unknown', 'leenkat-forms' ),
			'Passed'                                   => __( 'Passed', 'leenkat-forms' ),
			'Failed'                                   => __( 'Failed', 'leenkat-forms' ),
			'Missing'                                  => __( 'Missing', 'leenkat-forms' ),
			'Check required'                           => __( 'Check required', 'leenkat-forms' ),
			'Not applicable'                           => __( 'Not applicable', 'leenkat-forms' ),
			'Not held'                                 => __( 'Not held', 'leenkat-forms' ),
			'Held (review required)'                   => __( 'Held (review required)', 'leenkat-forms' ),
			'No routes registered'                     => __( 'No routes registered', 'leenkat-forms' ),
			'pending'                                  => __( 'pending', 'leenkat-forms' ),
			'retry'                                    => __( 'retry', 'leenkat-forms' ),
			'sent'                                     => __( 'sent', 'leenkat-forms' ),
			'failed'                                   => __( 'failed', 'leenkat-forms' ),
			'processing'                               => __( 'processing', 'leenkat-forms' ),
			'suppressed'                               => __( 'suppressed', 'leenkat-forms' ),
		);
		if ( str_starts_with( $value, 'Review: ' ) ) {
			return __( 'Review: ', 'leenkat-forms' ) . substr( $value, 8 );
		}
		return $labels[ $value ] ?? $value;
	}
	/**
	 * Render a grouped set of status checks.
	 *
	 * @param string $title Title for this operation.
	 * @param array  $rows  Rows for this operation.
	 */
	private static function section( string $title, array $rows ): void {
		echo '<section class="lkf-system-section"><h3>' . esc_html( $title ) . '</h3><table class="widefat striped"><tbody>';
		foreach ( $rows as [$label, $value, $state] ) {
			echo '<tr><th scope="row">' . esc_html( self::text( $label ) ) . '</th><td><span' . ( '' !== $state ? ' class="lkf-diagnostic-state is-' . esc_attr( $state ) . '"' : '' ) . '><bdi dir="auto">' . esc_html( self::text( $value ) ) . '</bdi></span></td></tr>';
		}
		echo '</tbody></table></section>';
	}
	/**
	 * Build an individual system status result.
	 *
	 * @param string $label Label for this operation.
	 * @param bool   $ok    Ok for this operation.
	 * @param string $yes   Yes for this operation.
	 * @param string $no    No for this operation.
	 * @return array Result for the requested operation.
	 */
	private static function check( string $label, bool $ok, string $yes = 'Passed', string $no = 'Failed' ): array {
		$labels = array(
			'Passed'         => __( 'Passed', 'leenkat-forms' ),
			'Failed'         => __( 'Failed', 'leenkat-forms' ),
			'Installed'      => __( 'Installed', 'leenkat-forms' ),
			'Missing'        => __( 'Missing', 'leenkat-forms' ),
			'Verified'       => __( 'Verified', 'leenkat-forms' ),
			'Available'      => __( 'Available', 'leenkat-forms' ),
			'Check required' => __( 'Check required', 'leenkat-forms' ),
		);
		return array( $label, $labels[ $ok ? $yes : $no ] ?? ( $ok ? $yes : $no ), $ok ? 'success' : 'error' );
	}
	/**
	 * Render a warning about the current installation.
	 *
	 * @param string $message Message text for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function warning( string $message ): string {
		// Migrations currently record only a missing table. Never echo an arbitrary server error.
		return preg_match( '/^Missing table: [A-Za-z0-9_]+$/D', $message ) ? sprintf( /* translators: %s: verified database table name. */ __( 'Missing table: %s', 'leenkat-forms' ), substr( $message, 15 ) ) : __( 'A database migration warning is recorded. Review database setup before running diagnostics again.', 'leenkat-forms' );
	}
	/**
	 * Render the plugin system status page.
	 */
	public static function render(): void {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
		}
		$health = null;
		$failed = false;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Valid method and length literals contain no slash-sensitive characters. Strict literal comparisons reject malformed metadata before use or mutation.
		if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'POST' && '1' === \LeenkatForms\Security\RequestInput::control( 'leenfo_run_diagnostics', true ) ) {
			$nonce = is_string( $_POST['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'leenfo_run_diagnostics' ) ) {
				wp_die( esc_html__( 'Access denied or session expired. Reload and try again.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
			}
			try {
				$health = Health::snapshot();
			} catch ( \Throwable $e ) {
				$failed = true;}
		}
		echo '<section class="lkf-tools-panel lkf-system-status"><h2>' . esc_html__( 'System Status', 'leenkat-forms' ) . '</h2><p>' . esc_html__( 'View your environment and run the existing read-only diagnostic checks. Customer data is not changed.', 'leenkat-forms' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin.php?page=leenkat-forms-tools&view=system-status' ) ) . '"><input type="hidden" name="leenfo_run_diagnostics" value="1">';
		wp_nonce_field( 'leenfo_run_diagnostics' );
		echo '<button class="button button-primary">' . esc_html__( 'Run Diagnostics', 'leenkat-forms' ) . '</button></form>';
		if ( $failed ) {
			echo '<div class="notice notice-error inline" role="alert"><p>' . esc_html__( 'Diagnostics could not complete. Reload and try again.', 'leenkat-forms' ) . '</p></div>';
		} elseif ( null !== $health ) {
			echo '<p class="lkf-diagnostics-result" role="status">' . esc_html__( 'Diagnostics completed for this request. Review each result below.', 'leenkat-forms' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Diagnostics have not been run for this request. Use Run Diagnostics to check validation, lifecycle, tables, indexes and runtime availability.', 'leenkat-forms' ) . '</p>';
		}

		self::section(
			__( 'Environment', 'leenkat-forms' ),
			array(
				array( __( 'Plugin version', 'leenkat-forms' ), LEENKAT_FORMS_VERSION, '' ),
				array( __( 'PHP version', 'leenkat-forms' ), PHP_VERSION, '' ),
				array( __( 'WordPress version', 'leenkat-forms' ), get_bloginfo( 'version' ), '' ),
			)
		);
		$database = array( array( __( 'Database schema version', 'leenkat-forms' ), (string) get_option( 'leenfo_db_version', 0 ), '' ) );
		if ( null !== $health ) {
			foreach ( $health['tables'] as $table => $exists ) {
				$database[] = self::check( $table, $exists, 'Installed', 'Missing' );
			}
			foreach ( $health['table_indexes'] as $name => $ok ) {
				$database[] = self::check( sprintf( /* translators: %s: table suffix. */ __( 'Database indexes: %s', 'leenkat-forms' ), $name ), $ok, 'Verified', 'Missing' );
			}
		}
		$warning = (string) get_option( 'leenfo_migration_error', '' );
		if ( '' !== $warning ) {
			$database[] = array( __( 'Migration warning', 'leenkat-forms' ), self::warning( $warning ), 'warning' );
		}
		self::section( __( 'Database and Schema', 'leenkat-forms' ), $database );
		$contract = array( array( __( 'Form definition contract', 'leenkat-forms' ), __( 'Schema v', 'leenkat-forms' ) . \LeenkatForms\Forms\DefinitionValidator::SCHEMA_VERSION, '' ) );
		if ( null !== $health ) {
			$contract[] = self::check( __( 'Definition contract self-test', 'leenkat-forms' ), $health['definition_contract_tests'] );
			foreach ( $health['definition_test_cases'] as $name => $passed ) {
				$contract[] = self::check( sprintf( /* translators: %s: contract test name. */ __( 'Contract test: %s', 'leenkat-forms' ), self::text( $name ) ), $passed );
			}
		} else {
			$contract[] = array( __( 'Definition contract self-test', 'leenkat-forms' ), __( 'Not run', 'leenkat-forms' ), '' );
		}
		self::section( __( 'Form Definition Validation', 'leenkat-forms' ), $contract );
		$lifecycle   = array( array( __( 'Entry lifecycle contract', 'leenkat-forms' ), sprintf( /* translators: %d: number of lifecycle states. */ __( '%d states', 'leenkat-forms' ), count( \LeenkatForms\Submissions\EntryStatus::all() ) ), '' ), array( __( 'Public entry read endpoint', 'leenkat-forms' ), \LeenkatForms\Security\SubmissionPolicy::PUBLIC_READ_ALLOWED ? __( 'Enabled', 'leenkat-forms' ) : __( 'Disabled', 'leenkat-forms' ), '' ) );
		$lifecycle[] = null !== $health ? self::check( __( 'Entry lifecycle self-test', 'leenkat-forms' ), $health['lifecycle_test'] ) : array( __( 'Entry lifecycle self-test', 'leenkat-forms' ), __( 'Not run', 'leenkat-forms' ), '' );
		self::section( __( 'Entry Lifecycle', 'leenkat-forms' ), $lifecycle );
		if ( null !== $health ) {
			$other = array( array( __( 'Module contracts', 'leenkat-forms' ), implode( ', ', $health['contracts'] ), '' ) );
			foreach ( $health['runtime'] as $name => $passed ) {
				$other[] = self::check( sprintf( /* translators: %s: runtime capability name. */ __( 'Runtime: %s', 'leenkat-forms' ), self::text( $name ) ), $passed, __( 'Available', 'leenkat-forms' ), __( 'Check required', 'leenkat-forms' ) );
			}
			foreach ( $health['deliveries'] as $status => $count ) {
				$other[] = array( sprintf( /* translators: %s: delivery status. */ __( 'Deliveries: %s', 'leenkat-forms' ), self::text( $status ) ), (string) $count, '' );
			}
			self::section( __( 'Other Existing Diagnostics', 'leenkat-forms' ), $other );
			$audit = array();
			foreach ( $health['foundation_audit'] as $name => $value ) {
				if ( is_array( $value ) ) {
					foreach ( $value as $table => $engine ) {
						$audit[] = array( sprintf( /* translators: %s: database table name. */ __( 'Engine: %s', 'leenkat-forms' ), $table ), $engine, 'Missing' === $engine ? 'error' : '' );
					}
				} else {
					$audit[] = array( $name, (string) $value, 'Passed' === $value ? 'success' : ( str_contains( (string) $value, 'required' ) ? 'warning' : '' ) );
				}
			}
			self::section( __( 'M1.7 Foundation audit (read-only)', 'leenkat-forms' ), $audit );
		}
		echo '</section>';
	}
}
