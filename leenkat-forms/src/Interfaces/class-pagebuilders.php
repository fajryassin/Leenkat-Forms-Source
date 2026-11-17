<?php
/**
 * Registration for supported third-party page builders.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Interfaces;

/**
 * Registration for supported third-party page builders.
 */
final class PageBuilders {
	/**
	 * Register optional page builder integrations.
	 */
	public static function hooks(): void {
		add_action( 'elementor/widgets/register', array( self::class, 'elementor' ) );
		add_action( 'vc_before_init', array( self::class, 'wpBakery' ) );
		// Live previews insert forms after the initial document has loaded.
		// Keep the full preview asset set confined to the builder iframe.
		add_action( 'elementor/preview/enqueue_styles', array( self::class, 'previewAssets' ) );
		add_action( 'elementor/preview/enqueue_scripts', array( self::class, 'previewAssets' ) );
		add_action( 'vc_load_iframe_jscss', array( self::class, 'previewAssets' ) );
	}
	/**
	 * Load the form assets needed for the page builder preview.
	 */
	public static function previewAssets(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		\LeenkatForms\Frontend\FormAssets::enqueue( array(), true );
	}
	/**
	 * Register the form widget with Elementor.
	 *
	 * @param mixed $manager Manager for this operation.
	 */
	public static function elementor( $manager ): void {
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			return; }
		require_once LEENKAT_FORMS_DIR . 'src/Interfaces/class-elementorwidget.php';
		$manager->register( new ElementorWidget() );
	}
	/**
	 * Register the form integration with WPBakery.
	 */
	public static function wpBakery(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! function_exists( 'vc_map' ) ) {
			return; }
		$choices = array();
		foreach ( Editors::choices() as $id => $title ) {
			$choices[ $title . ' (#' . $id . ')' ] = (string) $id; }
		vc_map(
			array(
				'name'        => __( 'Leenkat Forms', 'leenkat-forms' ),
				'base'        => 'leenkat_form',
				'icon'        => plugins_url( 'assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE ),
				'category'    => __( 'Content', 'leenkat-forms' ),
				'description' => __( 'Display a published Leenkat form.', 'leenkat-forms' ),
				'params'      => array(
					array(
						'type'        => 'dropdown',
						'heading'     => __( 'Form', 'leenkat-forms' ),
						'param_name'  => 'id',
						'value'       => array( __( 'Select a form', 'leenkat-forms' ) => '' ) + $choices,
						'admin_label' => true,
					),
				),
			)
		);
	}
}
