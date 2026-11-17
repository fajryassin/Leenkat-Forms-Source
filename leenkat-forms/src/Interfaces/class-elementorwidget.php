<?php
/**
 * Elementor widget for embedding published forms.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Interfaces;

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return; }

/**
 * Elementor widget for embedding published forms.
 */
final class ElementorWidget extends \Elementor\Widget_Base {
	/**
	 * Return the widget identifier.
	 *
	 * @return string Result for the requested operation.
	 */
	public function get_name(): string {
		return 'leenkat_forms'; }
	/**
	 * Return the translated widget title.
	 *
	 * @return string Result for the requested operation.
	 */
	public function get_title(): string {
		return __( 'Leenkat Forms', 'leenkat-forms' ); }
	/**
	 * Return the icon identifier for the editor.
	 *
	 * @return string Result for the requested operation.
	 */
	public function get_icon(): string {
		return 'lkf-elementor-logo'; }
	/**
	 * Return the Elementor categories for this widget.
	 *
	 * @return array Result for the requested operation.
	 */
	public function get_categories(): array {
		return array( 'general' ); }
	/**
	 * Register the form-selection controls for the widget.
	 */
	protected function register_controls(): void {
		$choices = array( '0' => __( 'Select a form', 'leenkat-forms' ) );
		foreach ( Editors::choices() as $id => $title ) {
			$choices[ (string) $id ] = $title; }
		$this->start_controls_section( 'leenfo_content', array( 'label' => __( 'Form', 'leenkat-forms' ) ) );
		$this->add_control(
			'form_id',
			array(
				'label'   => __( 'Published form', 'leenkat-forms' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $choices,
				'default' => '0',
			)
		);
		$this->end_controls_section();
	}
	/**
	 * Render the selected form in the widget.
	 */
	protected function render(): void {
		$id = absint( $this->get_settings_for_display( 'form_id' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The canonical shortcode renderer escapes field values and attributes; KSES would strip required form controls.
		echo \LeenkatForms\Frontend\FormRenderer::shortcode( array( 'id' => $id ) ); // Renderer escapes output.
	}
}
