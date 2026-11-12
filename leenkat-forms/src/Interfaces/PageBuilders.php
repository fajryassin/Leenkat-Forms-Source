<?php
declare(strict_types=1);
namespace LeenkatForms\Interfaces;

final class PageBuilders {
    public static function hooks(): void {
        add_action('elementor/widgets/register', [self::class,'elementor']);
        add_action('vc_before_init', [self::class,'wpBakery']);
        // Live previews insert forms after the initial document has loaded.
        // Keep the full preview asset set confined to the builder iframe.
        add_action('elementor/preview/enqueue_styles', [self::class,'previewAssets']);
        add_action('elementor/preview/enqueue_scripts', [self::class,'previewAssets']);
        add_action('vc_load_iframe_jscss', [self::class,'previewAssets']);
    }
    public static function previewAssets(): void {
        \LeenkatForms\Frontend\FormAssets::enqueue([], true);
    }
    public static function elementor($manager): void {
        if (!class_exists('\\Elementor\\Widget_Base')) { return; }
        require_once LEENKAT_FORMS_DIR . 'src/Interfaces/ElementorWidget.php';
        $manager->register(new ElementorWidget());
    }
    public static function wpBakery(): void {
        if (!function_exists('vc_map')) { return; }
        $choices = [];
        foreach (Editors::choices() as $id=>$title) { $choices[$title . ' (#' . $id . ')'] = (string)$id; }
        vc_map([
            'name'=>__('Leenkat Forms', 'leenkat-forms'),
            'base'=>'leenkat_form',
            'icon'=>plugins_url('assets/images/leenkat-forms-logo.png', LEENKAT_FORMS_FILE),
            'category'=>__('Content', 'leenkat-forms'),
            'description'=>__('Display a published Leenkat form.', 'leenkat-forms'),
            'params'=>[[
                'type'=>'dropdown','heading'=>__('Form', 'leenkat-forms'),'param_name'=>'id',
                'value'=>[__('Select a form','leenkat-forms')=>''] + $choices,'admin_label'=>true,
            ]],
        ]);
    }
}
