<?php
declare(strict_types=1);
namespace LeenkatForms\Interfaces;

if (!class_exists('\\Elementor\\Widget_Base')) { return; }

final class ElementorWidget extends \Elementor\Widget_Base {
    public function get_name(): string { return 'leenkat_forms'; }
    public function get_title(): string { return __('Leenkat Forms', 'leenkat-forms'); }
    public function get_icon(): string { return 'lkf-elementor-logo'; }
    public function get_categories(): array { return ['general']; }
    protected function register_controls(): void {
        $choices = ['0'=>__('Select a form', 'leenkat-forms')];
        foreach (Editors::choices() as $id=>$title) { $choices[(string)$id] = $title; }
        $this->start_controls_section('lkf_content', ['label'=>__('Form', 'leenkat-forms')]);
        $this->add_control('form_id', ['label'=>__('Published form', 'leenkat-forms'),'type'=>\Elementor\Controls_Manager::SELECT,'options'=>$choices,'default'=>'0']);
        $this->end_controls_section();
    }
    protected function render(): void {
        $id = absint($this->get_settings_for_display('form_id'));
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The canonical shortcode renderer escapes field values and attributes; KSES would strip required form controls.
        echo \LeenkatForms\Frontend\FormRenderer::shortcode(['id'=>$id]); // Renderer escapes output.
    }
}
