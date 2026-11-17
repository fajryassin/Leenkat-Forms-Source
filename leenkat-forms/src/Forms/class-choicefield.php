<?php
/**
 * Options and display settings for choice fields.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/**
 * Options and display settings for choice fields.
 */
final class ChoiceField {
	/** Original, fixed SVG paths. User-supplied SVG/HTML is never rendered. */
	public static function icons(): array {
		return array(
			'star'   => 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9Z',
			'heart'  => 'M12 20S3 14.5 3 8.5C3 3 10 2 12 7c2-5 9-4 9 1.5C21 14.5 12 20 12 20Z',
			'check'  => 'M4 12l5 5L20 6',
			'circle' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z',
			'home'   => 'M3 11l9-8 9 8M5 10v11h14V10M10 21v-7h4v7',
			'user'   => 'M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM4 21v-2a8 6 0 0 1 16 0v2Z',
		);
	}
	/**
	 * Validate the URL for a choice image.
	 *
	 * @param string $url Url for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function imageUrlValid( string $url ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return strlen( $url ) <= 2048 && false !== filter_var( $url, FILTER_VALIDATE_URL ) && in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true );
	}
	/**
	 * Build display data for a choice decoration.
	 *
	 * @param array  $settings Settings used by the current operation.
	 * @param string $value    Value being processed.
	 * @return string Result for the requested operation.
	 */
	public static function decoration( array $settings, string $value ): string {
		$url = $settings['choice_images'][ $value ] ?? '';
		if ( ! empty( $settings['image_choices'] ) ) {
			$src = is_string( $url ) && self::imageUrlValid( $url ) ? $url : plugins_url( 'assets/images/choice-placeholder.svg', LEENKAT_FORMS_FILE );
			return '<span class="lkf-choice-visual" aria-hidden="true"><img class="lkf-choice-image" src="' . esc_url( $src ) . '" alt="" loading="lazy"></span>';
		}
		$icon = $settings['choice_icons'][ $value ] ?? 'star';
		if ( ! empty( $settings['icon_choices'] ) && is_string( $icon ) && isset( self::icons()[ $icon ] ) ) {
			return '<svg class="lkf-choice-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="' . self::icons()[ $icon ] . '"></path></svg>';
		}
		return '';
	}
	/**
	 * Build the list of choice items for display.
	 *
	 * @param array $settings Settings used by the current operation.
	 * @return array Result for the requested operation.
	 */
	public static function displayItems( array $settings ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$items = self::items( $settings );
		if ( ! empty( $settings['randomize_choices'] ) ) {
			shuffle( $items ); }
		return $items;
	}
	/**
	 * Return the available sources for choice values.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function sources(): array {
		$sources = array(
			'post_type' => array(),
			'taxonomy'  => array(),
		);
		if ( ! function_exists( 'get_post_types' ) ) {
			return $sources; }
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$sources['post_type'][ $type->name ] = $type->labels->singular_name; }
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$sources['taxonomy'][ $taxonomy->name ] = $taxonomy->labels->singular_name; }
		return $sources;
	}
	/**
	 * Return the configured items for a choice field.
	 *
	 * @param array $settings Settings used by the current operation.
	 * @return array Result for the requested operation.
	 */
	public static function items( array $settings ): array {
		$mode = $settings['dynamic_choices'] ?? 'off';
		if ( 'off' === $mode ) {
			return array_map(
				static fn( string $text ): array => array(
					'value' => $text,
					'label' => $text,
				),
				$settings['options'] ?? array()
			); }
		$source = $settings['dynamic_source'] ?? '';
		if ( ! isset( self::sources()[ $mode ][ $source ] ) ) {
			return array(); }
		if ( 'post_type' === $mode ) {
			$posts = get_posts(
				array(
					'post_type'    => $source,
					'post_status'  => 'publish',
					'numberposts'  => 100,
					'orderby'      => 'title',
					'order'        => 'ASC',
					'has_password' => false,
				)
			);
			return array_map(
				static fn( $post ): array => array(
					'value' => (string) $post->ID,
					'label' => wp_strip_all_tags( $post->post_title ),
				),
				$posts
			);
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $source,
				'hide_empty' => false,
				'number'     => 100,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		return is_wp_error( $terms ) ? array() : array_map(
			static fn( $term ): array => array(
				'value' => (string) $term->term_id,
				'label' => wp_strip_all_tags( $term->name ),
			),
			$terms
		);
	}
}
