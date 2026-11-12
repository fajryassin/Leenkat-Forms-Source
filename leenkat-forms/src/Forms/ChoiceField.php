<?php
declare(strict_types=1);
namespace LeenkatForms\Forms;

final class ChoiceField {
    /** Original, fixed SVG paths. User-supplied SVG/HTML is never rendered. */
    public static function icons(): array {
        return [
            'star'=>'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9Z',
            'heart'=>'M12 20S3 14.5 3 8.5C3 3 10 2 12 7c2-5 9-4 9 1.5C21 14.5 12 20 12 20Z',
            'check'=>'M4 12l5 5L20 6',
            'circle'=>'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z',
            'home'=>'M3 11l9-8 9 8M5 10v11h14V10M10 21v-7h4v7',
            'user'=>'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM4 21v-2a8 6 0 0 1 16 0v2Z',
        ];
    }
    public static function imageUrlValid(string $url): bool {
        return strlen($url) <= 2048 && filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string)wp_parse_url($url, PHP_URL_SCHEME)), ['http','https'], true);
    }
    public static function decoration(array $settings, string $value): string {
        $url = $settings['choice_images'][$value] ?? '';
        if (!empty($settings['image_choices'])) {
            $src = is_string($url) && self::imageUrlValid($url) ? $url : plugins_url('assets/images/choice-placeholder.svg', LEENKAT_FORMS_FILE);
            return '<span class="lkf-choice-visual" aria-hidden="true"><img class="lkf-choice-image" src="' . esc_url($src) . '" alt="" loading="lazy"></span>';
        }
        $icon = $settings['choice_icons'][$value] ?? 'star';
        if (!empty($settings['icon_choices']) && is_string($icon) && isset(self::icons()[$icon])) {
            return '<svg class="lkf-choice-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="' . self::icons()[$icon] . '"></path></svg>';
        }
        return '';
    }
    public static function displayItems(array $settings): array {
        $items = self::items($settings);
        if (!empty($settings['randomize_choices'])) { shuffle($items); }
        return $items;
    }
    public static function sources(): array {
        $sources = ['post_type'=>[], 'taxonomy'=>[]];
        if (!function_exists('get_post_types')) { return $sources; }
        foreach (get_post_types(['public'=>true], 'objects') as $type) {
            if ($type->name !== 'attachment') { $sources['post_type'][$type->name] = $type->labels->singular_name; }
        }
        foreach (get_taxonomies(['public'=>true], 'objects') as $taxonomy) { $sources['taxonomy'][$taxonomy->name] = $taxonomy->labels->singular_name; }
        return $sources;
    }
    public static function items(array $settings): array {
        $mode = $settings['dynamic_choices'] ?? 'off';
        if ($mode === 'off') { return array_map(static fn(string $text): array => ['value'=>$text,'label'=>$text], $settings['options'] ?? []); }
        $source = $settings['dynamic_source'] ?? '';
        if (!isset(self::sources()[$mode][$source])) { return []; }
        if ($mode === 'post_type') {
            $posts = get_posts(['post_type'=>$source,'post_status'=>'publish','numberposts'=>100,'orderby'=>'title','order'=>'ASC','has_password'=>false]);
            return array_map(static fn($post): array => ['value'=>(string)$post->ID,'label'=>wp_strip_all_tags($post->post_title)], $posts);
        }
        $terms = get_terms(['taxonomy'=>$source,'hide_empty'=>false,'number'=>100,'orderby'=>'name','order'=>'ASC']);
        return is_wp_error($terms) ? [] : array_map(static fn($term): array => ['value'=>(string)$term->term_id,'label'=>wp_strip_all_tags($term->name)], $terms);
    }
}
