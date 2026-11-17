<?php
/**
 * Bootstrap wiring for plugin services and administration.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);

namespace LeenkatForms\Bootstrap;

/**
 * Bootstrap wiring for plugin services and administration.
 */
final class Plugin {
	/**
	 * Whether the runtime has registered its hooks.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Register the runtime hooks after checking identifier migration.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return; }
		self::$booted = true;
		\LeenkatForms\Localization\Translations::hooks();
		if ( get_option( 'leenfo_uninstall_state', null ) !== null ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'manage_options' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Leenkat Forms uninstall was interrupted. Form processing is paused. Resolve the reported cleanup problem and retry plugin deletion, or restore an external backup.', 'leenkat-forms' ) . '</p></div>';
					}}
			);
			return;
		}
		if ( ! IdentifierUpgrade::run() ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( current_user_can( 'manage_options' ) ) {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Leenkat Forms could not finish its identifier upgrade. Form processing is paused. Restore the verified backup or retry the upgrade.', 'leenkat-forms' ) . '</p></div>';
					}
				}
			);
			return;
		}
		if ( is_admin() && current_user_can( 'manage_options' ) ) {
			add_action( 'admin_init', array( self::class, 'upgrade' ) );
		}
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( self::class, 'brandingAssets' ) );
			add_action( 'elementor/editor/after_enqueue_styles', array( self::class, 'brandingAssets' ) );
			add_action( 'admin_menu', array( self::class, 'registerMenu' ) );
			\LeenkatForms\Admin\ManagementPage::hooks();
			\LeenkatForms\Admin\FormManager::hooks();
			\LeenkatForms\Admin\EntryManager::hooks();
			\LeenkatForms\Admin\GlobalSettings::hooks();
		}
		\LeenkatForms\Frontend\FormRenderer::hooks();
		\LeenkatForms\Frontend\FormAssets::hooks();
		\LeenkatForms\Settings\SiteSettings::hooks();
		\LeenkatForms\Admin\ToolsPage::hooks();
		\LeenkatForms\Files\Download::hooks();
		\LeenkatForms\Files\PrivateMedia::hooks();
		\LeenkatForms\Files\EmailDownload::hooks();
		\LeenkatForms\Admin\FileSecurityPage::hooks();
		\LeenkatForms\Files\EntryDeletion::hooks();
		\LeenkatForms\Submissions\PublicEndpoint::hooks();
		\LeenkatForms\Admin\BuilderApi::hooks();
		\LeenkatForms\Security\SecurityChallenge::hooks();
		\LeenkatForms\Security\SpamKeywords::hooks();
		\LeenkatForms\Security\CaptchaService::hooks();
		\LeenkatForms\Security\AkismetService::hooks();
		\LeenkatForms\Workflow\DeliveryWorker::hooks();
		\LeenkatForms\Interfaces\Editors::hooks();
		\LeenkatForms\Interfaces\PageBuilders::hooks();
	}

	/**
	 * Apply pending database upgrades for an authorized administrator.
	 */
	public static function upgrade(): void {
		if ( (int) get_option( 'leenfo_db_version', 0 ) < \LeenkatForms\Infrastructure\Database\Migrations::VERSION ) {
			if ( ! \LeenkatForms\Infrastructure\Database\Migrations::install() ) {
				return; }
		}
		\LeenkatForms\Bootstrap\Lifecycle::grantCapabilities();
		if ( get_option( 'leenfo_installed_version' ) !== LEENKAT_FORMS_VERSION ) {
			update_option( 'leenfo_installed_version', LEENKAT_FORMS_VERSION, false );
		}
	}

	/**
	 * Register the plugin menu and administration pages.
	 */
	public static function registerMenu(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		add_menu_page(
			__( 'Leenkat Forms', 'leenkat-forms' ),
			__( 'Leenkat Forms', 'leenkat-forms' ),
			\LeenkatForms\Security\Capabilities::MANAGE_FORMS,
			'leenkat-forms',
			array( self::class, 'renderDashboard' ),
			plugins_url( 'assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE ),
			58
		);
		add_submenu_page( 'leenkat-forms', __( 'Dashboard', 'leenkat-forms' ), __( 'Dashboard', 'leenkat-forms' ), \LeenkatForms\Security\Capabilities::MANAGE_FORMS, 'leenkat-forms', array( self::class, 'renderDashboard' ) );
		$list_hook = add_submenu_page( 'leenkat-forms', __( 'All Forms', 'leenkat-forms' ), __( 'All Forms', 'leenkat-forms' ), \LeenkatForms\Security\Capabilities::MANAGE_FORMS, 'leenkat-forms-all', array( \LeenkatForms\Admin\FormManager::class, 'render' ) );
		if ( $list_hook ) {
			add_action( 'load-' . $list_hook, array( \LeenkatForms\Admin\ManagementPage::class, 'load' ) );
		}
		add_submenu_page( 'leenkat-forms', __( 'Add New', 'leenkat-forms' ), __( 'Add New', 'leenkat-forms' ), \LeenkatForms\Security\Capabilities::MANAGE_FORMS, 'leenkat-forms-new', array( \LeenkatForms\Admin\FormManager::class, 'renderNew' ) );
		$list_hook = add_submenu_page( 'leenkat-forms', __( 'Entries', 'leenkat-forms' ), __( 'Entries', 'leenkat-forms' ), \LeenkatForms\Security\Capabilities::VIEW_ENTRIES, 'leenkat-forms-entries', array( \LeenkatForms\Admin\EntryManager::class, 'render' ) );
		if ( $list_hook ) {
			add_action( 'load-' . $list_hook, array( \LeenkatForms\Admin\ManagementPage::class, 'load' ) );
		}
		add_submenu_page( 'leenkat-forms', __( 'Settings', 'leenkat-forms' ), __( 'Settings', 'leenkat-forms' ), 'manage_options', 'leenkat-forms-settings', array( \LeenkatForms\Admin\GlobalSettings::class, 'render' ) );
		add_submenu_page( 'leenkat-forms', __( 'Attachment Security', 'leenkat-forms' ), __( 'Attachment Security', 'leenkat-forms' ), 'manage_options', 'leenkat-forms-file-security', array( \LeenkatForms\Admin\FileSecurityPage::class, 'render' ) );
		if ( current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ) || current_user_can( \LeenkatForms\Security\Capabilities::VIEW_ENTRIES ) ) {
			add_submenu_page( 'leenkat-forms', __( 'Tools', 'leenkat-forms' ), __( 'Tools', 'leenkat-forms' ), 'read', 'leenkat-forms-tools', array( \LeenkatForms\Admin\ToolsPage::class, 'render' ) );
		}
	}

	/**
	 * Load the plugin administration branding assets.
	 */
	public static function brandingAssets(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		wp_enqueue_style( 'leenfo-branding', plugins_url( 'assets/admin/branding.css', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( is_admin() && in_array( $page, array( 'leenkat-forms', 'leenkat-forms-all', 'leenkat-forms-entries', 'leenkat-forms-settings', 'leenkat-forms-file-security', 'leenkat-forms-tools', 'leenkat-forms-new' ), true ) ) {
			wp_enqueue_style( 'leenfo-admin-theme', plugins_url( 'assets/admin/admin-theme.css', LEENKAT_FORMS_FILE ), array( 'leenfo-branding' ), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/admin-theme.css' ) );
		}
	}

	/**
	 * Render the dashboard administration callback.
	 */
	public static function renderDashboard(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		\LeenkatForms\Admin\DashboardPage::render();
	}
}
