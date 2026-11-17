<?php
/**
 * Frontend resources and form-specific style selection.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Frontend;

/**
 * Frontend resources and form-specific style selection.
 */
final class FormAssets {
	/**
	 * Register frontend form asset hooks.
	 */
	public static function hooks(): void {
		add_action(
			'wp_enqueue_scripts',
			static function (): void {
				if ( \LeenkatForms\Settings\SiteSettings::general()['assets_global'] ) {
					self::enqueue( array(), true );
				}}
		);}
	/**
	 * Return the paths of the selected form stylesheets.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function stylePaths(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$style = \LeenkatForms\Settings\SiteSettings::general()['styling'];
		return array_merge( array( 'assets/frontend/form-core.css' ), 'none' === $style ? array() : array( 'base' === $style ? 'assets/frontend/form-base.css' : 'assets/frontend/form.css' ) );
	}
	/**
	 * Return the form style configuration.
	 */
	public static function styles(): void {
		foreach ( self::stylePaths() as $index => $path ) {
			wp_enqueue_style( 0 === $index ? 'leenfo-form-core' : 'leenfo-form', plugins_url( $path, LEENKAT_FORMS_FILE ), 0 === $index ? array() : array( 'leenfo-form-core' ), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . $path ) );
		}}
	/**
	 * Load the frontend resources needed by the form.
	 *
	 * @param array $definition Form definition being processed.
	 * @param bool  $all        All for this operation.
	 */
	public static function enqueue( array $definition = array(), bool $all = false ): void {
		wp_enqueue_script( 'leenfo-widget-i18n', plugins_url( 'assets/frontend/widget-i18n.js', LEENKAT_FORMS_FILE ), array( 'wp-i18n' ), LEENKAT_FORMS_VERSION, true );
		$locale        = function_exists( 'determine_locale' ) ? determine_locale() : 'en_US';
		$locale_config = array( 'locale' => $locale );
		if ( preg_match( '/^ar(?:[_-]|$)/i', $locale ) ) {
			$locale_config['countries'] = \LeenkatForms\Forms\AddressField::data()['countries'];
		}
		wp_add_inline_script( 'leenfo-widget-i18n', 'window.leenfoLocale=' . wp_json_encode( $locale_config ) . ';', 'before' );
		self::styles();
		wp_enqueue_script( 'leenfo-confirmation', plugins_url( 'assets/frontend/confirmation.js', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/frontend/confirmation.js' ), true );
		wp_enqueue_script( 'leenfo-rules', plugins_url( 'assets/frontend/rules.js', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/frontend/rules.js' ), true );
		$dependencies = array( 'leenfo-rules', 'leenfo-confirmation' );
		wp_enqueue_script( 'leenfo-validation', plugins_url( 'assets/frontend/validation.js', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/frontend/validation.js' ), true );
		$dependencies[] = 'leenfo-validation';
		wp_enqueue_script( 'leenfo-steps', plugins_url( 'assets/frontend/multistep.js', LEENKAT_FORMS_FILE ), array( 'leenfo-validation' ), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/frontend/multistep.js' ), true );
		$dependencies[] = 'leenfo-steps';
		if ( ! empty( $definition['settings']['security']['captcha_enabled'] ) ) {
			\LeenkatForms\Security\CaptchaService::assets();
			$dependencies[] = 'leenfo-captcha';}
		$fields = $definition['fields'] ?? array();
		foreach ( array(
			'datetime' => array( 'leenfo-flatpickr', 'assets/vendor/flatpickr/flatpickr.min.css', 'assets/vendor/flatpickr/flatpickr.min.js', '4.6.9' ),
			'phone'    => array( 'leenfo-phone', 'assets/vendor/intl-tel-input/build/css/intlTelInput.css', 'assets/vendor/intl-tel-input/build/js/intlTelInputWithUtils.js', '25.12.6-lkf.1' ),
			'select'   => array( 'leenfo-choices', 'assets/vendor/choices/public/assets/styles/choices.css', 'assets/vendor/choices/public/assets/scripts/choices.js', '11.1.0' ),
		) as $type => [$handle, $css, $js, $version] ) {
			$needed = $all || array_filter( $fields, static fn( array $f ): bool=>$f['type'] === $type && ( 'phone' === $type ? ( $f['settings']['phone_format'] ?? '' ) === 'smart' : ( 'select' === $type ? ( $f['settings']['select_style'] ?? '' ) === 'modern' : true ) ) );
			if ( $needed ) {
				wp_enqueue_style( $handle, plugins_url( $css, LEENKAT_FORMS_FILE ), array(), $version );
				wp_enqueue_script( $handle, plugins_url( $js, LEENKAT_FORMS_FILE ), array(), $version, true );
				$dependencies[] = $handle;}
		}
		$dependencies[] = 'leenfo-widget-i18n';
		wp_enqueue_script( 'leenfo-form', plugins_url( 'assets/frontend/form.js', LEENKAT_FORMS_FILE ), $dependencies, LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/frontend/form.js' ), true );
	}
	/**
	 * Print the styles required by the native confirmation document.
	 *
	 * @return string Result for the requested operation.
	 */
	public static function nativeStyles(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		self::styles();
		$handles = array_map( static fn( $index )=>0 === $index ? 'leenfo-form-core' : 'leenfo-form', array_keys( self::stylePaths() ) );
		ob_start();
		wp_print_styles( $handles );
		return (string) ob_get_clean();
	}
}
