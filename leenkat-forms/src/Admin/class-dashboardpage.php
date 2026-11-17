<?php
/**
 * Plugin dashboard and installation status.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

/** Lightweight landing page. Diagnostics belong to Tools and are never run here. */
final class DashboardPage {
	/**
	 * Build the label for the installed plugin version.
	 *
	 * @param string $version Version for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function versionLabel( string $version ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$label = sprintf( /* translators: %s: installed plugin version. */ __( 'Version %s', 'leenkat-forms' ), $version );
		return preg_match( '/(?:^|[.\-])dev(?:[.\-]|$)/i', $version ) ? $label . ' — ' . __( 'Development', 'leenkat-forms' ) : $label;
	}

	/**
	 * Render the main plugin dashboard.
	 */
	public static function render(): void {
		if ( ! current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'leenkat-forms' ), '', array( 'response' => 403 ) );
		}
		wp_enqueue_style( 'leenfo-settings', plugins_url( 'assets/admin/settings.css', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/settings.css' ) );
		wp_enqueue_style( 'leenfo-dashboard', plugins_url( 'assets/admin/dashboard.css', LEENKAT_FORMS_FILE ), array( 'leenfo-settings' ), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/dashboard.css' ) );
		echo '<div class="wrap lkf-global-settings lkf-dashboard">';
		AdminHeader::settings( __( 'Leenkat Forms', 'leenkat-forms' ) );
		echo '<p class="lkf-dashboard-subtitle">' . esc_html__( 'Manage your forms and submissions.', 'leenkat-forms' ) . '</p><nav class="lkf-dashboard-cards" aria-label="' . esc_attr__( 'Form management', 'leenkat-forms' ) . '">';
		$cards = array(
			array( 'leenkat-forms-all', __( 'All Forms', 'leenkat-forms' ), __( 'Manage existing forms.', 'leenkat-forms' ), 'feedback' ),
			array( 'leenkat-forms-new', __( 'Add New', 'leenkat-forms' ), __( 'Create a new form.', 'leenkat-forms' ), 'plus-alt2' ),
			array( 'leenkat-forms-entries', __( 'Entries', 'leenkat-forms' ), __( 'View form submissions.', 'leenkat-forms' ), 'email-alt' ),
			array( 'leenkat-forms-settings', __( 'Settings', 'leenkat-forms' ), __( 'Configure your forms.', 'leenkat-forms' ), 'admin-generic' ),
		);
		foreach ( $cards as [$page, $title, $description, $icon] ) {
			echo '<a class="lkf-dashboard-card" href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '"><span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $description ) . '</p><span class="lkf-dashboard-arrow" aria-hidden="true">→</span></a>';
		}
		echo '</nav><footer class="lkf-dashboard-footer"><p><bdi dir="auto">' . esc_html( self::versionLabel( LEENKAT_FORMS_VERSION ) ) . '</bdi></p><a href="' . esc_url( admin_url( 'admin.php?page=leenkat-forms-tools&view=system-status' ) ) . '">' . esc_html__( 'System Status', 'leenkat-forms' ) . '</a></footer></div>';
	}
}
