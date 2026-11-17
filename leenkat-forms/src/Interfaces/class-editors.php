<?php
/**
 * Classic editor and block editor integration.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Interfaces;

use LeenkatForms\Frontend\FormRenderer;

/**
 * Classic editor and block editor integration.
 */
final class Editors {
	/**
	 * Register WordPress editor integration hooks.
	 */
	public static function hooks(): void {
		add_action( 'init', array( self::class, 'block' ) );
		// WordPress collects this hook separately for the editor content iframe.
		add_action(
			'enqueue_block_assets',
			static function (): void {
				if ( is_admin() ) {
					\LeenkatForms\Frontend\FormAssets::enqueue( array(), true ); }
			}
		);
		if ( is_admin() ) {
			add_action( 'media_buttons', array( self::class, 'classicButton' ) );
			add_action( 'admin_footer', array( self::class, 'classicDialog' ) );
			add_action( 'admin_enqueue_scripts', array( self::class, 'classicAssets' ) );
		}
	}
	/**
	 * Return the forms available for editor insertion.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function choices(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'leenfo_forms';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
		$rows    = $wpdb->get_results( "SELECT id,draft_definition FROM `{$table}` WHERE status='published' ORDER BY id DESC LIMIT 500" );
		$choices = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$definition                = json_decode( (string) $row->draft_definition, true );
			$choices[ (int) $row->id ] = is_array( $definition ) ? (string) ( $definition['title'] ?? ( '#' . $row->id ) ) : ( '#' . $row->id );
		}
		return $choices;
	}
	/**
	 * Register the WordPress editor block for forms.
	 */
	public static function block(): void {
		wp_register_script( 'leenfo-block-editor', plugins_url( 'assets/admin/block.js', LEENKAT_FORMS_FILE ), array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), LEENKAT_FORMS_VERSION, true );
		wp_set_script_translations( 'leenfo-block-editor', 'leenkat-forms' );
		wp_add_inline_script( 'leenfo-block-editor', 'window.leenfoBlockLogo=' . wp_json_encode( plugins_url( 'assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE ) ) . ';', 'before' );
		if ( is_admin() ) {
			wp_add_inline_script( 'leenfo-block-editor', 'window.leenfoBlockForms=' . wp_json_encode( self::choices(), JSON_HEX_TAG ) . ';', 'before' ); }
		register_block_type(
			'leenkat-forms/form',
			array(
				'api_version'     => 3,
				'editor_script'   => 'lkf-block-editor',
				'attributes'      => array(
					'formId' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
				'render_callback' => static fn( array $attributes ): string => FormRenderer::shortcode( array( 'id' => (int) ( $attributes['formId'] ?? 0 ) ) ),
			)
		);
	}
	/**
	 * Load the classic editor insertion assets.
	 *
	 * @param string $hook Hook for this operation.
	 */
	public static function classicAssets( string $hook ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! current_user_can( 'edit_posts' ) ) {
			return; }
		wp_enqueue_script( 'leenkat-forms-classic-editor', plugins_url( 'assets/admin/classic-editor.js', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION, true );
	}
	/**
	 * Render the form insertion button for the classic editor.
	 */
	public static function classicButton(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! current_user_can( 'edit_posts' ) || ! self::choices() ) {
			return; }
		echo '<button type="button" class="button" id="lkf-add-form"><img src="' . esc_url( plugins_url( 'assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE ) ) . '" width="20" height="20" alt="">' . esc_html__( 'Add Leenkat Form', 'leenkat-forms' ) . '</button>';
	}
	/**
	 * Render the form selection dialog for the classic editor.
	 */
	public static function classicDialog(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! current_user_can( 'edit_posts' ) || ! self::choices() ) {
			return; }
		echo '<dialog id="lkf-form-dialog"><form method="dialog"><label for="lkf-insert-id">' . esc_html__( 'Choose a form', 'leenkat-forms' ) . '</label> <select id="lkf-insert-id">';
		foreach ( self::choices() as $id => $title ) {
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $title ) . '</option>'; }
		echo '</select> <button value="insert">' . esc_html__( 'Insert', 'leenkat-forms' ) . '</button> <button value="cancel">' . esc_html__( 'Cancel', 'leenkat-forms' ) . '</button></form></dialog>';
	}
}
