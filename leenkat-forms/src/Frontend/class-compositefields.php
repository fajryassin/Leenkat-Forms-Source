<?php
/**
 * Front-end rendering of fields with multiple input components.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Frontend;

use LeenkatForms\Forms\AddressField;
use LeenkatForms\Forms\DateTimeField;
use LeenkatForms\Submissions\FileUploads;

/**
 * Front-end rendering of fields with multiple input components.
 */
final class CompositeFields {
	/**
	 * Render the controls for a composite form field.
	 *
	 * @param array  $field         Field definition used by this operation.
	 * @param string $id            Record identifier.
	 * @param string $name          Name used to resolve the requested item.
	 * @param string $descriptionId Description id for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function render( array $field, string $id, string $name, string $descriptionId ): string { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $descriptionId and its method binding.
		$s        = $field['settings'] ?? array();
		$required = ! empty( $field['required'] );
		$caption  = esc_html( $field['label'] ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' );
		if ( 'section_divider' === $field['type'] ) {
			return '<h3 class="lkf-section-title">' . esc_html( $field['label'] ) . '</h3>'; }
		$described = ! empty( $s['description'] ) ? ' aria-describedby="' . esc_attr( $descriptionId ) . '"' : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $descriptionId and its method binding.
		$html      = '<fieldset class="lkf-composite-fieldset"><legend>' . $caption . '</legend>';
		if ( 'file_upload' === $field['type'] ) {
			$extensions = FileUploads::extensions( $s );
			$modern     = ( $s['upload_style'] ?? 'modern' ) === 'modern';
			$html      .= '<div class="lkf-upload' . ( $modern ? ' lkf-upload-drop' : '' ) . '"><label for="' . esc_attr( $id ) . '">' . ( $modern ? '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 14h5l2 3h4l2-3h5M3 14l3-9h12l3 9v6H3zM12 3v9m-3-3 3 3 3-3"/></svg><span>' . esc_html__( 'Click or drag a file to this area to upload.', 'leenkat-forms' ) . '</span>' : '<span class="lkf-visually-hidden">' . esc_html( $field['label'] ) . '</span>' );
			$html      .= '<input id="' . esc_attr( $id ) . '" type="file" name="upload_' . esc_attr( $field['id'] ) . '[]" data-lkf-upload data-max-size-label="' . esc_attr( FileUploads::sizeLabel( $s ) ) . '" data-max-files="' . (int) ( $s['max_files'] ?? 1 ) . '" data-max-bytes="' . FileUploads::maxBytes( $s ) . '" accept="' . esc_attr( implode( ',', array_map( static fn( string $ext ): string => '.' . $ext, $extensions ) ) ) . '"' . ( ( $s['max_files'] ?? 1 ) > 1 ? ' multiple' : '' ) . ( $required ? ' required' : '' ) . $described . '></label></div><p class="lkf-upload-status" aria-live="polite"></p>';
		} elseif ( 'datetime' === $field['type'] ) {
			$html .= '<div class="lkf-datetime-parts">';
			foreach ( DateTimeField::parts( $s ) as $part ) {
				if ( 'date' === $part && ( $s['date_type'] ?? '' ) === 'dropdown' ) {
					$prefilled = explode( '-', $s['prefill_date'] ?? '' );
					$html     .= '<div class="lkf-date-dropdowns" data-lkf-date-dropdown data-picker-settings="' . esc_attr( wp_json_encode( $s ) ) . '" data-today="' . esc_attr( current_datetime()->format( 'Y-m-d' ) ) . '"><input type="hidden" name="' . esc_attr( $name . '[date]' ) . '">';
					$order     = ( $s['date_format'] ?? 'm/d/Y' ) === 'd/m/Y' ? array( 'day', 'month', 'year' ) : ( ( $s['date_format'] ?? '' ) === 'Y-m-d' ? array( 'year', 'month', 'day' ) : array( 'month', 'day', 'year' ) );
					foreach ( $order as $piece ) {
						$caption = array(
							'month' => __( 'Month', 'leenkat-forms' ),
							'day'   => __( 'Day', 'leenkat-forms' ),
							'year'  => __( 'Year', 'leenkat-forms' ),
						)[ $piece ];
						$html   .= '<label for="' . esc_attr( $id . '-' . $piece ) . '"><select id="' . esc_attr( $id . '-' . $piece ) . '" data-date-piece="' . $piece . '"' . ( $required ? ' required' : '' ) . $described . '><option value="">' . esc_html( $caption ) . '</option>';
						$year    = (int) current_datetime()->format( 'Y' );
						foreach ( 'year' === $piece ? range( $year + 10, $year - 100 ) : range( 1, 'month' === $piece ? 12 : 31 ) as $number ) {
							$part_value = $prefilled[ array(
								'year'  => 0,
								'month' => 1,
								'day'   => 2,
							)[ $piece ] ] ?? '';
							$html      .= '<option value="' . $number . '"' . ( '' !== $part_value && (int) $part_value === $number ? ' selected' : '' ) . '>' . $number . '</option>'; }
						$html .= '</select><span' . ( ! empty( $s['hide_sublabels'] ) ? ' class="lkf-visually-hidden"' : '' ) . '>' . esc_html( $caption ) . '</span></label>';
					}
					$html .= '</div>';
					continue;
				}
				$html .= '<label for="' . esc_attr( $id . '-' . $part ) . '"><input id="' . esc_attr( $id . '-' . $part ) . '" name="' . esc_attr( $name . '[' . $part . ']' ) . '" type="' . $part . '" data-lkf-picker="' . $part . '" data-picker-settings="' . esc_attr( wp_json_encode( $s ) ) . '" data-today="' . esc_attr( current_datetime()->format( 'Y-m-d' ) ) . '" value="' . esc_attr( $s[ 'prefill_' . $part ] ?? '' ) . '" placeholder="' . esc_attr( $s[ $part . '_placeholder' ] ?? '' ) . '"' . ( 'time' === $part ? ' step="' . (int) ( ( $s['time_interval'] ?? 30 ) * 60 ) . '"' : '' ) . ( $required ? ' required' : '' ) . $described . '><span' . ( ! empty( $s['hide_sublabels'] ) ? ' class="lkf-visually-hidden"' : '' ) . '>' . esc_html( 'date' === $part ? __( 'Date', 'leenkat-forms' ) : __( 'Time', 'leenkat-forms' ) ) . '</span></label>';
			}
			$html .= '</div>';
		} else {
			$html        .= '<div class="lkf-address-parts">';
			$captions     = AddressField::captions( $s );
			$autocomplete = array(
				'address1' => 'address-line1',
				'address2' => 'address-line2',
				'city'     => 'address-level2',
				'state'    => 'address-level1',
				'postal'   => 'postal-code',
				'country'  => 'country',
			);
			foreach ( AddressField::parts( $s ) as $part ) {
				$part_settings = $s['address_parts'][ $part ] ?? array();
				$value         = $part_settings['default_value'] ?? '';
				$placeholder   = $part_settings['placeholder'] ?? '';
				$options       = AddressField::options( $part, $s );
				$attributes    = ' id="' . esc_attr( $id . '-' . $part ) . '" name="' . esc_attr( $name . '[' . $part . ']' ) . '" autocomplete="section-' . esc_attr( $id ) . ' ' . $autocomplete[ $part ] . '"' . ( $required && 'address2' !== $part ? ' required' : '' ) . $described;
				$html         .= '<label for="' . esc_attr( $id . '-' . $part ) . '"' . ( in_array( $part, array( 'address1', 'address2' ), true ) ? ' class="lkf-address-wide"' : '' ) . '>';
				if ( $options ) {
					$html .= '<select' . $attributes . '><option value="">' . esc_html( $placeholder ? $placeholder : ( 'country' === $part ? __( '--- Select Country ---', 'leenkat-forms' ) : __( '--- Select State ---', 'leenkat-forms' ) ) ) . '</option>';
					foreach ( $options as $key => $text ) {
						$html .= '<option value="' . esc_attr( $key ) . '"' . ( $value === $key ? ' selected' : '' ) . '>' . esc_html( $text ) . '</option>'; }
					$html .= '</select>';
				} else {
					$html .= '<input type="text"' . $attributes . ' maxlength="250" placeholder="' . esc_attr( $placeholder ) . '" value="' . esc_attr( $value ) . '">'; }
				$html .= '<span' . ( ! empty( $s['hide_sublabels'] ) ? ' class="lkf-visually-hidden"' : '' ) . '>' . esc_html( $captions[ $part ] ) . '</span></label>';
			}
			$html .= '</div>';
		}
		return $html . '</fieldset>';
	}
}
