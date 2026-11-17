<?php
/**
 * Loading of the plugin translations and script messages.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Localization;

/** Directory language packs use WordPress locale resolution, including per-user admin locales. */
final class Translations {
	public const SCRIPTS = array(
		'leenfo-block-editor'     => 'assets/admin/block.js',
		'leenfo-builder-model'    => 'assets/admin/builder-model.js',
		'leenfo-builder'          => 'assets/admin/builder.js',
		'leenfo-captcha-settings' => 'assets/admin/captcha.js',
		'leenfo-confirmations'    => 'assets/admin/confirmations.js',
		'leenfo-notifications'    => 'assets/admin/notifications.js',
		'leenfo-security'         => 'assets/admin/security.js',
		'leenfo-settings'         => 'assets/admin/settings.js',
		'leenfo-site-settings'    => 'assets/admin/site-settings.js',
		'leenfo-tools'            => 'assets/admin/tools.js',
		'leenfo-captcha'          => 'assets/frontend/captcha.js',
		'leenfo-confirmation'     => 'assets/frontend/confirmation.js',
		'leenfo-form'             => 'assets/frontend/form.js',
		'leenfo-steps'            => 'assets/frontend/multistep.js',
		'leenfo-validation'       => 'assets/frontend/validation.js',
		'leenfo-widget-i18n'      => 'assets/frontend/widget-i18n.js',
	);

	/**
	 * Register the plugin translation hooks.
	 */
	public static function hooks(): void {
		// Resolve translations after late shortcode/admin registrations, before WordPress prints them.
		foreach ( array( 'wp_print_scripts', 'wp_print_footer_scripts', 'admin_print_scripts', 'admin_print_footer_scripts' ) as $hook ) {
			add_action( $hook, array( self::class, 'scripts' ), 0 );
		}
	}

	/**
	 * Load translation data for the registered scripts.
	 */
	public static function scripts(): void {
		$scripts = wp_scripts();
		foreach ( self::SCRIPTS as $handle => $path ) {
			if ( ! isset( $scripts->registered[ $handle ] ) ) {
				continue; }
			$registered = $scripts->registered[ $handle ];
			if ( ! in_array( 'wp-i18n', $registered->deps, true ) ) {
				$registered->deps[] = 'wp-i18n'; }
			wp_set_script_translations( $handle, 'leenkat-forms' );
		}
	}
}
