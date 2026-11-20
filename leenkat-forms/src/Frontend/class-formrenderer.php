<?php
/**
 * Frontend shortcode and form markup rendering.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Frontend;

use LeenkatForms\Forms\Publisher;
use LeenkatForms\Security\MathCaptcha;

/**
 * Frontend shortcode and form markup rendering.
 */
final class FormRenderer {
	/**
	 * Number of rendered instances for each form.
	 *
	 * @var array
	 */
	private static array $instances = array();
	/**
	 * Register the form shortcode and rendering hooks.
	 */
	public static function hooks(): void {
		add_shortcode( 'leenkat_form', array( self::class, 'shortcode' ) );
		add_action(
			'template_redirect',
			static function (): void {
				$post = get_queried_object();
				if ( $post instanceof \WP_Post && ( has_shortcode( $post->post_content, 'leenkat_form' ) || has_block( 'leenkat-forms/form', $post ) ) ) {
					self::fresh_page();
				}
			},
			0
		);
	}
	/** Shared HTML must not supply the identity used by non-JavaScript POSTs. */
	private static function fresh_page(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache interoperability constant; a prefixed name would not be honored by cache plugins.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache interoperability constant; a prefixed name would not be honored by cache plugins.
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}
	/**
	 * Render a published form from its shortcode attributes.
	 *
	 * @param array|string $attributes Attributes for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function shortcode( array|string $attributes = array() ): string {
		$attributes = shortcode_atts(
			array(
				'id'          => 0,
				'title'       => false,
				'description' => false,
			),
			is_array( $attributes ) ? $attributes : array(),
			'leenkat_form'
		);
		$id         = absint( $attributes['id'] );
		$published  = $id ? Publisher::published( $id ) : null;
		if ( ! $published ) {
			return ''; }
		self::fresh_page();
		self::$instances[ $id ] = ( self::$instances[ $id ] ?? 0 ) + 1;
		$occurrence             = self::$instances[ $id ];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Input is consumed only by its existing explicit authorization/validation boundary, not as an unguarded state-changing request.
		$receipt  = \LeenkatForms\Submissions\NativeSubmission::receipt( $id, $_GET, $occurrence );
		$complete = $receipt && ! $receipt['error'];
		FormAssets::styles();
		wp_enqueue_script( 'leenfo-confirmation', plugins_url( 'assets/frontend/confirmation.js', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . (string) filemtime( LEENKAT_FORMS_DIR . 'assets/frontend/confirmation.js' ), true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Input is consumed only by its existing explicit authorization/validation boundary, not as an unguarded state-changing request.
		$definition = Prefill::definition( $published['definition'], $id, Prefill::request( $id ) );
		if ( $receipt && $receipt['error'] && isset( $receipt['fields'] ) ) {
			$definition = FieldErrors::prefill( $definition, $receipt['fields'] );
		}
		$general     = $definition['settings']['general'] ?? array();
		$security    = \LeenkatForms\Forms\SecuritySettings::normalize( $definition );
		$needs_proof = $security['modern'] || $security['min_time'];
		$ajax        = ( $general['ajax'] ?? true ) !== false;
		FormAssets::enqueue( $definition );
		$instance   = 'lkf-' . wp_generate_password( 8, false, false );
		$steps      = \LeenkatForms\Forms\Steps::layout( $definition );
		$multi      = count( $steps ) > 1;
		$return_url = get_permalink();
		$return_url = $return_url ? $return_url : home_url( '/' );
		$action     = ! empty( $general['disable_entries'] ) ? admin_url( 'admin-post.php' ) : $return_url;
		ob_start();
		$sources = array_map(
			static fn( $field )=>array(
				'id'       => $field['id'],
				'type'     => $field['type'],
				'settings' => array_intersect_key( $field['settings'] ?? array(), array_flip( array( 'multiple', 'name_format' ) ) ),
			),
			$definition['fields']
		);
		// Without JS the browser cannot determine conditional requirements; final server
		// validation remains authoritative. Enhanced steps explicitly report validity.
		echo '<form novalidate class="' . esc_attr( trim( 'lkf-form ' . ( $general['form_class'] ?? '' ) ) ) . '" data-lkf-form data-lkf-validation-messages="' . esc_attr( wp_json_encode( FieldErrors::messages() ) ) . '" data-lkf-endpoint="' . esc_url( rest_url( 'leenkat-forms/v1/forms/' . $id . '/submit' ) ) . '" data-lkf-sources="' . esc_attr( wp_json_encode( $sources ) ) . '" data-lkf-ajax="' . ( $ajax ? '1' : '0' ) . '" data-processing-text="' . esc_attr( $general['processing_text'] ?? __( 'Sending...', 'leenkat-forms' ) ) . '" action="' . esc_url( $action ) . '" method="post" enctype="multipart/form-data" aria-label="' . esc_attr( (string) ( $definition['title'] ?? __( 'Form', 'leenkat-forms' ) ) ) . '">';
		if ( $needs_proof ) {
			$proof = \LeenkatForms\Security\SecurityChallenge::issue( $id, $published['version_id'] );
			echo '<input type="hidden" name="security_token" value="' . esc_attr( $proof['token'] ) . '" data-lkf-security-url="' . esc_url( rest_url( 'leenkat-forms/v1/forms/' . $id . '/security-token' ) ) . '">';
			echo '<div class="lkf-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'leenkat-forms' ) . '<input type="text" data-lkf-security-trap name="security_traps[' . esc_attr( $proof['honeypot'] ) . ']" tabindex="-1" autocomplete="off"></label></div>';
			if ( $security['modern'] ) {
				echo '<noscript><p>' . esc_html__( 'Please enable JavaScript to submit this protected form.', 'leenkat-forms' ) . '</p></noscript>';
			}
		}
		echo '<input type="hidden" name="action" value="leenfo_submit"><input type="hidden" name="form_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="form_instance" value="' . esc_attr( (string) $occurrence ) . '"><input type="hidden" name="submission_uuid" value="' . esc_attr( wp_generate_uuid4() ) . '"><input type="hidden" name="return_url" value="' . esc_url( $return_url ) . '">';
		if ( $complete ) {
			echo '<input type="hidden" data-lkf-completed value="1">';
		}
		if ( filter_var( $attributes['title'], FILTER_VALIDATE_BOOLEAN ) ) {
			echo '<h2 class="lkf-form-title">' . esc_html( $definition['title'] ) . '</h2>';
		}
		if ( filter_var( $attributes['description'], FILTER_VALIDATE_BOOLEAN ) && ! empty( $general['description'] ) ) {
			echo '<p class="lkf-form-description">' . nl2br( esc_html( $general['description'] ) ) . '</p>';
		}
		echo '<div class="lkf-errors" role="alert" aria-live="assertive" tabindex="-1"' . ( $receipt && $receipt['error'] ? '' : ' hidden' ) . '>' . ( $receipt && $receipt['error'] ? esc_html( $receipt['message'] ) : '' ) . '</div>';
		echo '<div class="lkf-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this field empty', 'leenkat-forms' ) . '<input type="text" name="website_confirmation" tabindex="-1" autocomplete="off"></label></div>';
		if ( $multi ) {
			$mode = $general['progress_indicator'] ?? 'bar';
			echo '<p data-lkf-step-status class="lkf-step-status" role="status" aria-live="polite" aria-atomic="true" data-pattern="' . /* translators: 1: current step, 2: total applicable steps. */ esc_attr__( 'Step %1$d of %2$d', 'leenkat-forms' ) . '" data-empty="' . esc_attr__( 'Ready to submit', 'leenkat-forms' ) . '"></p>';
			if ( 'bar' === $mode ) {
				echo '<progress data-lkf-progress max="' . count( $steps ) . '" value="1" aria-label="' . esc_attr__( 'Form progress', 'leenkat-forms' ) . '" hidden></progress>';
			}
			if ( 'steps' === $mode ) {
				echo '<ol data-lkf-step-list class="lkf-step-list" aria-label="' . esc_attr__( 'Form progress', 'leenkat-forms' ) . '">';
				foreach ( $steps as $step ) {
					echo '<li>' . esc_html( $step['title'] ) . '</li>';
				}echo '</ol>';}
		}
		$native_error_focus = false;
		$groups             = $multi ? $steps : array( array( 'fields' => $definition['fields'] ) );
		foreach ( $groups as $step_index => $step ) {
			if ( $multi ) {
				echo '<section data-lkf-step="' . esc_attr( $step['id'] ) . '" data-next-label="' . esc_attr( $step['next_label'] ?? __( 'Next', 'leenkat-forms' ) ) . '" data-previous-label="' . esc_attr( $step['previous_label'] ?? __( 'Previous', 'leenkat-forms' ) ) . '" aria-labelledby="' . esc_attr( $instance . '-step-' . $step_index ) . '"><h3 id="' . esc_attr( $instance . '-step-' . $step_index ) . '" data-lkf-step-heading tabindex="-1">' . esc_html( $step['title'] ) . '</h3>';
			}
			echo '<div class="lkf-grid">';
			foreach ( $step['fields'] as $field ) {
				$fid          = $instance . '-' . $field['id'];
				$field_errors = $receipt['field_errors'][ $field['id'] ] ?? array();
				ob_start();
				$name             = 'fields[' . $field['id'] . ']';
				$label            = (string) ( $field['label'] ?? $field['id'] );
				$required         = ! empty( $field['required'] );
				$retained_checked = $receipt && $receipt['error'] && in_array( $receipt['fields'][ $field['id'] ] ?? null, array( '1', 1, true ), true );
				$type             = $field['type'];
				$settings         = $field['settings'] ?? array();
				$description      = (string) ( $settings['description'] ?? '' );
				$description_id   = $fid . '-description';
				$described_by     = '' !== $description ? ' aria-describedby="' . esc_attr( $description_id ) . '"' : '';
				$extra_classes    = trim( (string) ( $settings['css_classes'] ?? '' ) );
				$classes          = 'lkf-field lkf-full';
				$classes         .= ' lkf-type-' . $type;
				$classes         .= ' lkf-size-' . esc_attr( (string) ( $settings['field_size'] ?? 'large' ) );
				if ( ! empty( $settings['hide_label'] ) && ! in_array( $type, array( 'checkbox', 'captcha' ), true ) ) {
					$classes .= ' lkf-hide-label'; }
				if ( '' !== $extra_classes ) {
					$classes .= ' ' . $extra_classes; }
				$rule = null;
				foreach ( ( $definition['rules'] ?? array() ) as $candidate ) {
					if ( ( $candidate['field_id'] ?? '' ) === $field['id'] ) {
						$rule = $candidate;
						break; }
				}
				$rule_attribute = $rule ? ' data-lkf-rule="' . esc_attr( wp_json_encode( $rule ) ) . '"' : '';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
				echo '<div class="' . esc_attr( $classes ) . '" data-lkf-field-id="' . esc_attr( $field['id'] ) . '" data-lkf-error-prefix="' . esc_attr( $fid ) . '" data-lkf-part-labels="' . esc_attr( wp_json_encode( FieldErrors::labels( $field ) ) ) . '"' . ( $field_errors ? ' data-lkf-server-errors="' . esc_attr( wp_json_encode( $field_errors ) ) . '"' : '' ) . $rule_attribute . '>';
				if ( in_array( $type, array( 'datetime', 'address', 'file_upload', 'section_divider' ), true ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
					echo CompositeFields::render( $field, $fid, $name, $description_id );
				} elseif ( 'consent' === $type ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
					echo '<fieldset class="lkf-consent-fieldset"><legend>' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</legend><label><input id="' . esc_attr( $fid ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . $described_by . ( $required ? ' required' : '' ) . ( $retained_checked ? ' checked' : '' ) . '> ' . esc_html( (string) ( $settings['agreement'] ?? __( 'I consent to having this website store my submitted information so they can respond to my inquiry.', 'leenkat-forms' ) ) ) . '</label></fieldset>';
				} elseif ( 'checkbox' === $type ) {
					echo '<label><input id="' . esc_attr( $fid ) . '" type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . ( $required ? ' required' : '' ) . ( $retained_checked ? ' checked' : '' ) . '> ' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>';
				} elseif ( 'radio' === $type || 'checkboxes' === $type ) {
					echo '<fieldset data-lkf-choice-group data-required="' . ( $required ? '1' : '0' ) . '" data-choice-limit="' . (int) ( $settings['choice_limit'] ?? 0 ) . '"><legend>' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</legend><div class="lkf-choice-options lkf-choice-layout-' . esc_attr( $settings['choice_layout'] ?? 'one' ) . ( ! empty( $settings['image_choices'] ) || ! empty( $settings['icon_choices'] ) ? ' lkf-visual-choices' : '' ) . ( ! empty( $settings['image_choices'] ) ? ' lkf-image-choices lkf-image-style-' . esc_attr( $settings['image_choice_style'] ?? 'modern' ) : '' ) . '">';
					foreach ( \LeenkatForms\Forms\ChoiceField::displayItems( $settings ) as $index => $item ) {
						$option_id = $fid . '-' . $index;
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
						echo '<label for="' . esc_attr( $option_id ) . '"><input id="' . esc_attr( $option_id ) . '" type="' . ( 'radio' === $type ? 'radio' : 'checkbox' ) . '" name="' . esc_attr( $name . ( 'checkboxes' === $type ? '[]' : '' ) ) . '" value="' . esc_attr( $item['value'] ) . '"' . ( in_array( $item['value'], $settings['default_options'] ?? array(), true ) ? ' checked' : '' ) . $described_by . ( $required && 'radio' === $type ? ' required' : '' ) . '> ' . \LeenkatForms\Forms\ChoiceField::decoration( $settings, $item['value'] ) . '<span>' . esc_html( $item['label'] ) . '</span></label>';
					}
					echo '</div></fieldset>';
				} elseif ( 'email' === $type && ! empty( $settings['email_confirmation'] ) ) {
					echo '<fieldset class="lkf-email-fieldset"><legend>' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</legend><div class="lkf-email-parts">';
					foreach ( array(
						'email'   => __( 'Email', 'leenkat-forms' ),
						'confirm' => __( 'Confirm Email', 'leenkat-forms' ),
					) as $part => $caption ) {
						$part_name   = 'email' === $part ? $name : 'email_confirmations[' . $field['id'] . ']';
						$placeholder = (string) ( $settings[ 'email' === $part ? 'placeholder' : 'confirmation_placeholder' ] ?? '' );
						$extra       = 'email' === $part ? ' data-lkf-email-filter="' . esc_attr(
							wp_json_encode(
								array(
									'mode'     => $settings['email_filter'] ?? 'none',
									'patterns' => $settings['email_patterns'] ?? array(),
								)
							)
						) . '" value="' . esc_attr( (string) ( $settings['default_value'] ?? '' ) ) . '"' . ( ! empty( $settings['read_only'] ) ? ' readonly' : '' ) : ' data-lkf-confirm-for="' . esc_attr( $fid . '-email' ) . '" value="' . esc_attr( is_string( $receipt['email_confirmations'][ $field['id'] ] ?? null ) ? $receipt['email_confirmations'][ $field['id'] ] : '' ) . '"';
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
						echo '<label for="' . esc_attr( $fid . '-' . $part ) . '"><input type="email" maxlength="254" id="' . esc_attr( $fid . '-' . $part ) . '" name="' . esc_attr( $part_name ) . '" placeholder="' . esc_attr( $placeholder ) . '"' . $extra . $described_by . ( $required ? ' required' : '' ) . '><span' . ( ! empty( $settings['hide_sublabels'] ) ? ' class="lkf-visually-hidden"' : '' ) . '>' . esc_html( $caption ) . '</span></label>';
					}
					echo '</div></fieldset>';
				} elseif ( 'name' === $type ) {
					$format = $settings['name_format'] ?? 'first_last';
					echo '<fieldset class="lkf-name-fieldset"><legend>' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</legend><div class="lkf-name-parts lkf-name-' . esc_attr( $format ) . '">';
					foreach ( \LeenkatForms\Forms\NameField::parts( $settings ) as $part ) {
						$caption       = 'simple' === $format ? $label : array(
							'first'  => __( 'First', 'leenkat-forms' ),
							'middle' => __( 'Middle', 'leenkat-forms' ),
							'last'   => __( 'Last', 'leenkat-forms' ),
						)[ $part ];
						$part_settings = $settings['name_parts'][ $part ] ?? array();
						$hide          = 'simple' === $format || ! empty( $settings['hide_sublabels'] );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
						echo '<label for="' . esc_attr( $fid . '-' . $part ) . '"><input id="' . esc_attr( $fid . '-' . $part ) . '" type="text" name="' . esc_attr( $name . '[' . $part . ']' ) . '" maxlength="250" placeholder="' . esc_attr( (string) ( $part_settings['placeholder'] ?? '' ) ) . '" value="' . esc_attr( (string) ( $part_settings['default_value'] ?? '' ) ) . '"' . $described_by . ( $required && 'middle' !== $part ? ' required' : '' ) . '><span' . ( $hide ? ' class="lkf-visually-hidden"' : '' ) . '>' . esc_html( $caption ) . '</span></label>';
					}
					echo '</div></fieldset>';
				} elseif ( 'captcha' === $type ) {
					$challenge = MathCaptcha::issue( $id, $field['id'] );
					echo '<label for="' . esc_attr( $fid ) . '">' . esc_html( $label ) . ': ' . esc_html( (string) $challenge['first'] ) . ' + ' . esc_html( (string) $challenge['second'] ) . ' = ?</label>';
					echo '<input id="' . esc_attr( $fid ) . '" type="number" inputmode="numeric" min="2" max="18" name="' . esc_attr( $name ) . '" required>';
					echo '<input type="hidden" name="' . esc_attr( 'captcha_tokens[' . $field['id'] . ']' ) . '" value="' . esc_attr( $challenge['token'] ) . '">';
				} else {
					echo '<label for="' . esc_attr( $fid ) . '">' . esc_html( $label ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label>';
					if ( 'textarea' === $type ) {
						$max_length = \LeenkatForms\Forms\TextLength::attributes( $settings, 5000 );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
						echo '<textarea id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( (string) ( $settings['placeholder'] ?? '' ) ) . '"' . $max_length . $described_by . ( ! empty( $settings['read_only'] ) ? ' readonly' : '' ) . ( $required ? ' required' : '' ) . '>' . esc_textarea( (string) ( $settings['default_value'] ?? '' ) ) . '</textarea>';
					} elseif ( 'select' === $type || 'rating' === $type ) {
						$prompt   = 'select' === $type ? (string) ( $settings['placeholder'] ?? __( 'Select an option', 'leenkat-forms' ) ) : __( 'Select an option', 'leenkat-forms' );
						$multiple = 'select' === $type && ! empty( $settings['multiple'] );
						$defaults = $settings['default_options'] ?? array();
						$modern   = 'select' === $type && ( $settings['select_style'] ?? 'classic' ) === 'modern';
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
						echo '<select id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name . ( $multiple ? '[]' : '' ) ) . '"' . ( $multiple ? ' multiple' : '' ) . ( $modern ? ' data-lkf-modern data-placeholder="' . esc_attr( $prompt ) . '"' : '' ) . $described_by . ( $required ? ' required' : '' ) . '>';
						if ( ! $multiple && ( 'rating' === $type || '' !== $prompt || ! array_key_exists( 'options', $settings ) ) ) {
							echo '<option value="">' . esc_html( $prompt ) . '</option>'; }
						$items = 'rating' === $type ? array_map(
							static fn( $value ): array => array(
								'value' => (string) $value,
								'label' => (string) $value,
							),
							range( 1, (int) ( $settings['max'] ?? 5 ) )
						) : \LeenkatForms\Forms\ChoiceField::items( $settings );
						foreach ( $items as $item ) {
							echo '<option value="' . esc_attr( $item['value'] ) . '"' . ( in_array( $item['value'], $defaults, true ) ? ' selected' : '' ) . '>' . esc_html( $item['label'] ) . '</option>'; }
						echo '</select>';
					} elseif ( 'number_slider' === $type ) {
						$initial = \LeenkatForms\Forms\NumberSlider::initial( $settings );
						$display = (string) ( $settings['value_display'] ?? __( 'Selected Value: {value}', 'leenkat-forms' ) );
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
						echo '<input id="' . esc_attr( $fid ) . '" type="range" name="' . esc_attr( $name ) . '" min="' . esc_attr( (string) ( $settings['min'] ?? 0 ) ) . '" max="' . esc_attr( (string) ( $settings['max'] ?? 100 ) ) . '" step="' . esc_attr( (string) ( $settings['step'] ?? 1 ) ) . '" value="' . esc_attr( $initial ) . '"' . $described_by . '><output for="' . esc_attr( $fid ) . '" data-lkf-value-display="' . esc_attr( $display ) . '">' . esc_html( str_replace( '{value}', $initial, $display ) ) . '</output>';
					} else {
						$html_type     = 'phone' === $type ? 'tel' : $type;
						$placeholder   = isset( $field['settings']['placeholder'] ) ? ' placeholder="' . esc_attr( (string) $field['settings']['placeholder'] ) . '"' : '';
						$default_value = isset( $settings['default_value'] ) ? ' value="' . esc_attr( (string) $settings['default_value'] ) . '"' : '';
						$max_length    = 'text' === $type ? \LeenkatForms\Forms\TextLength::attributes( $settings ) : '';
						$input_mask    = 'text' === $type && ! empty( $settings['input_mask'] ) ? ' data-lkf-input-mask="' . esc_attr( (string) $settings['input_mask'] ) . '" pattern="' . esc_attr( \LeenkatForms\Forms\InputMask::pattern( (string) $settings['input_mask'] ) ) . '" title="' . esc_attr__( 'Please follow the required input format.', 'leenkat-forms' ) . '"' : '';
						if ( 'email' === $type ) {
							$input_mask .= ' maxlength="254" data-lkf-email-filter="' . esc_attr(
								wp_json_encode(
									array(
										'mode'     => $settings['email_filter'] ?? 'none',
										'patterns' => $settings['email_patterns'] ?? array(),
									)
								)
							) . '"'; }
						if ( 'phone' === $type && isset( $settings['phone_format'] ) ) {
							$input_mask .= ' data-lkf-phone="' . esc_attr( $settings['phone_format'] ) . '" maxlength="40"'; }
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
						echo '<input id="' . esc_attr( $fid ) . '" type="' . esc_attr( $html_type ) . '" name="' . esc_attr( $name ) . '"' . $placeholder . $default_value . $max_length . $input_mask . $described_by . ( ! empty( $settings['read_only'] ) ? ' readonly' : '' ) . ( $required ? ' required' : '' ) . '>';
					}
				}
				if ( '' !== $description ) {
					echo '<p class="lkf-description' . ( 'checkboxes' === $type && ! empty( $settings['terms_display'] ) ? ' lkf-disclaimer' : '' ) . '"' . ( 'checkboxes' === $type && ! empty( $settings['terms_display'] ) ? ' tabindex="0"' : '' ) . ' id="' . esc_attr( $description_id ) . '">' . esc_html( $description ) . '</p>'; }
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
				echo FieldErrors::markup( $fid, $field, $field_errors );
				echo '</div>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
				echo FieldErrors::decorate( (string) ob_get_clean(), $fid, $field_errors, $native_error_focus );
			}
			echo '</div>';
			if ( $multi ) {
				echo '</section>';
			}
		}
		if ( $multi ) {
			echo '<div class="lkf-step-navigation" data-lkf-step-nav hidden><button type="button" data-lkf-previous>' . esc_html__( 'Previous', 'leenkat-forms' ) . '</button><button type="button" data-lkf-next>' . esc_html__( 'Next', 'leenkat-forms' ) . '</button></div>';
		}
		if ( ! empty( $security['captcha_enabled'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
			echo \LeenkatForms\Security\CaptchaService::markup( $id );
		}
		if ( ! empty( $security['akismet_enabled'] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
			echo \LeenkatForms\Security\AkismetService::privacyMarkup();
		}
		$submit_text = trim( $general['submit_text'] ?? '' );
		echo '<button type="submit"' . ( $complete ? ' disabled' : '' ) . ' class="' . esc_attr( $general['submit_class'] ?? '' ) . '">' . esc_html( $submit_text ? $submit_text : __( 'Submit', 'leenkat-forms' ) ) . '</button>';
		echo '</form>';
		if ( $complete ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer/attribute helpers escape every dynamic component at construction; these are complete form controls, SVG choices or safe confirmation markup, not raw submitted HTML.
			echo \LeenkatForms\Forms\ConfirmationSettings::markup( $receipt['confirmation'] ?? array( 'html' => esc_html( $receipt['message'] ) ) );
		}
		return (string) ob_get_clean();
	}
}
