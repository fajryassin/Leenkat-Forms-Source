<?php
/**
 * Administration for form display and email defaults.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Settings\SiteSettings as S;

/**
 * Administration for form display and email defaults.
 */
final class SiteSettingsPage {
	/**
	 * Build labels for the settings tabs.
	 *
	 * @return array Result for the requested operation.
	 */
	private static function labels(): array {
		return array(
			'default' => __( 'Default Template', 'leenkat-forms' ),
			'compact' => __( 'Compact', 'leenkat-forms' ),
			'modern'  => __( 'Modern', 'leenkat-forms' ),
			'elegant' => __( 'Elegant', 'leenkat-forms' ),
			'tech'    => __( 'Tech', 'leenkat-forms' ),
			'plain'   => __( 'Plain Text', 'leenkat-forms' ),
		); }
	/**
	 * Register save and preview handlers for site settings.
	 */
	public static function hooks(): void {
		add_action( 'admin_post_leenfo_save_site_settings', array( self::class, 'save' ) );}
	/**
	 * Handle the authorized save of plugin site settings.
	 */
	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The allowlisted section only selects the nonce action. No settings are read or written before that check.
		$section = ManagementPage::text( $_POST, 'section' );
		if ( ! in_array( $section, array( 'general', 'email' ), true ) ) {
			wp_die( esc_html__( 'Invalid settings section.', 'leenkat-forms' ), '', 400 );
		}
		check_admin_referer( 'leenfo_site_settings_' . $section );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The typed payload is parsed below and must pass SiteSettings::validate, which allowlists values before any update.
		$raw      = isset( $_POST[ $section ] ) && is_array( $_POST[ $section ] ) ? wp_unslash( $_POST[ $section ] ) : array();
		$value    = self::posted( $section, $raw );
		$revision = ManagementPage::text( $_POST, 'revision' );
		$result   = S::save( $section, $value, $revision );
		if ( '1' === ManagementPage::text( $_POST, 'leenfo_json' ) ) {
			if ( is_wp_error( $result ) ) {
				wp_send_json( array( 'message' => $result->get_error_message() ), $result->get_error_data()['status'] ?? 400 );
			}
			wp_send_json(
				array(
					'saved'    => true,
					'revision' => S::revision( $section ),
					'message'  => __( 'Settings saved.', 'leenkat-forms' ),
				)
			);
		}
		if ( is_wp_error( $result ) ) {
			set_transient( 'leenfo_site_notice_' . get_current_user_id(), $result->get_error_message(), 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=leenkat-forms-settings&view=' . $section . '&saved=' . ( is_wp_error( $result ) ? '0' : '1' ) ) );
		exit;
	}
	/**
	 * Read the allowed values from the settings form.
	 *
	 * @param string $section Section for this operation.
	 * @param mixed  $raw     Raw for this operation.
	 * @return array Result for the requested operation.
	 */
	private static function posted( string $section, mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}$bool = static fn( $key )=> ! isset( $raw[ $key ] ) ? false : ( '1' === $raw[ $key ] ? true : $raw[ $key ] );
		if ( 'general' === $section ) {
			return array(
				'styling'                      => $raw['styling'] ?? null,
				'assets_global'                => $bool( 'assets_global' ),
				'gdpr_enhancements'            => $bool( 'gdpr_enhancements' ),
				'disable_user_details'         => $bool( 'disable_user_details' ),
				'delete_all_data_on_uninstall' => $bool( 'delete_all_data_on_uninstall' ),
			);
		}
		$value = array(
			'template'         => $raw['template'] ?? null,
			'optimize_sending' => $bool( 'optimize_sending' ),
			'carbon_copy'      => $bool( 'carbon_copy' ),
		);
		foreach ( array( 'light', 'dark' ) as $mode ) {
			$value[ $mode ] = is_array( $raw[ $mode ] ?? null ) ? $raw[ $mode ] : array();
			$id             = $value[ $mode ]['header_image'] ?? '';
			if ( is_string( $id ) && ctype_digit( $id ) ) {
				$value[ $mode ]['header_image'] = (int) $id;
			}
		}
		return $value;
	}
	/**
	 * Render an authorized email settings preview.
	 *
	 * @param \WP_REST_Request $request Incoming WordPress request.
	 * @return \WP_REST_Response|\WP_Error Result for the requested operation.
	 */
	public static function preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'leenfo_forbidden', __( 'Access denied.', 'leenkat-forms' ), array( 'status' => 403 ) );
		}
		if ( strlen( $request->get_body() ) > 16384 ) {
			return new \WP_Error( 'leenfo_preview_large', __( 'Preview settings are too large.', 'leenkat-forms' ), array( 'status' => 413 ) );
		}
		$data   = $request->get_json_params();
		$email  = is_array( $data ) ? ( $data['email'] ?? null ) : null;
		$mode   = $data['mode'] ?? 'light';
		$errors = S::validate( 'email', $email );
		if ( $errors || ! in_array( $mode, array( 'light', 'dark' ), true ) ) {
			$error_text = implode( ' ', $errors );
			return new \WP_Error( 'leenfo_preview_settings', $error_text ? $error_text : __( 'Choose a valid appearance.', 'leenkat-forms' ), array( 'status' => 400 ) );
		}
		$response = new \WP_REST_Response( array( 'templates' => EmailTemplatePreview::templates( $email, true, $mode ) ) );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
	/**
	 * Render the plugin site settings page.
	 *
	 * @param string $section Section for this operation.
	 */
	public static function render( string $section ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', 403 );
		}
		wp_enqueue_script( 'leenfo-site-settings', plugins_url( 'assets/admin/site-settings.js', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/site-settings.js' ), true );
		if ( 'email' === $section ) {
			wp_enqueue_media();
			$config = array(
				'previews'   => EmailTemplatePreview::templates( null, true ),
				'previewApi' => rest_url( 'leenkat-forms/v1/admin/email-preview' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
			);
			wp_add_inline_script( 'leenfo-site-settings', 'window.leenfoSiteSettings=' . wp_json_encode( $config ) . ';', 'before' );}
		GlobalSettings::header( $section );
		$notice = get_transient( 'leenfo_site_notice_' . get_current_user_id() );
		echo '<div data-lkf-save-notice role="status" aria-live="polite">';
		if ( is_string( $notice ) ) {
			delete_transient( 'leenfo_site_notice_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( $notice ) . '</p></div>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
		} elseif ( ( $_GET['saved'] ?? '' ) === '1' ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'leenkat-forms' ) . '</p></div>';
		}echo '</div>';
		echo '<h2>' . ( 'general' === $section ? esc_html__( 'General', 'leenkat-forms' ) : esc_html__( 'Email', 'leenkat-forms' ) ) . '</h2><p>' . esc_html( 'general' === $section ? __( 'Manage form styling, compatibility and privacy for this site.', 'leenkat-forms' ) : __( 'Customize the default email template and appearance used by form notifications.', 'leenkat-forms' ) ) . '</p><form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" novalidate data-lkf-site-settings="' . esc_attr( $section ) . '">';
		wp_nonce_field( 'leenfo_site_settings_' . $section );
		echo '<input type="hidden" name="action" value="leenfo_save_site_settings"><input type="hidden" name="section" value="' . esc_attr( $section ) . '"><input type="hidden" name="revision" value="' . esc_attr( S::revision( $section ) ) . '">';
		if ( 'general' === $section ) {
			self::general();
		} else {
			self::email();
		}
		echo '<div class="lkf-settings-save">';
		submit_button( __( 'Save Settings', 'leenkat-forms' ), 'primary', 'submit', false );
		echo '<span data-lkf-save-progress role="status"></span></div></form>';
		if ( 'email' === $section ) {
			echo '<dialog class="lkf-email-dialog" data-lkf-email-dialog aria-labelledby="lkf-email-dialog-title"><header><h2 id="lkf-email-dialog-title">' . esc_html__( 'Email Preview', 'leenkat-forms' ) . '</h2><button type="button" data-lkf-close-preview aria-label="' . esc_attr__( 'Close email preview', 'leenkat-forms' ) . '">×</button></header><iframe title="' . esc_attr__( 'Email template preview', 'leenkat-forms' ) . '" sandbox="" referrerpolicy="no-referrer"></iframe></dialog>';
		}
		echo '</div>';
	}
	/**
	 * Render the general settings tab.
	 */
	private static function general(): void {
		$s = S::general();
		echo '<div class="lkf-site-section"><div class="lkf-settings-row"><label for="lkf-form-styling">' . esc_html__( 'Include Form Styling', 'leenkat-forms' ) . '</label><div><select id="lkf-form-styling" name="general[styling]">';
		foreach ( array(
			'full' => __( 'Base and form theme styling', 'leenkat-forms' ),
			'base' => __( 'Base styling only', 'leenkat-forms' ),
			'none' => __( 'No styling', 'leenkat-forms' ),
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $s['styling'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}echo '</select><p class="description">' . esc_html__( 'Full styling uses the Leenkat appearance. Base styling keeps the layout and inherits your site’s appearance. No styling leaves appearance to your theme; essential visibility rules remain active.', 'leenkat-forms' ) . '</p></div></div>';
		self::toggle( 'general[assets_global]', 'lkf-assets-global', __( 'Load Assets Globally', 'leenkat-forms' ), $s['assets_global'], __( 'Load local form scripts and styles on every frontend page for compatibility. Leave off for normal use. CAPTCHA services still load only on protected forms.', 'leenkat-forms' ) );
		echo '</div><section class="lkf-site-section"><h3>' . esc_html__( 'GDPR', 'leenkat-forms' ) . '</h3>';
		self::toggle( 'general[gdpr_enhancements]', 'lkf-gdpr-enhancements', __( 'GDPR Enhancements', 'leenkat-forms' ), $s['gdpr_enhancements'], __( 'Show the privacy options below. Turning this off pauses their global effect and keeps your choices saved.', 'leenkat-forms' ), 'lkf-gdpr-options' );
		echo '<div id="lkf-gdpr-options" data-lkf-gdpr-options' . ( ! $s['gdpr_enhancements'] ? ' hidden' : '' ) . '>';
		self::toggle( 'general[disable_user_details]', 'lkf-disable-user-details', __( 'Disable User Details', 'leenkat-forms' ), $s['disable_user_details'], __( 'Prevent saving IP addresses and user agents in new entries for every form. This global setting takes priority over each form’s General settings. Existing entries are retained.', 'leenkat-forms' ) );
		echo '</div></section>';
		echo '<section class="lkf-site-section"><h3>' . esc_html__( 'Data Removal', 'leenkat-forms' ) . '</h3>';
		self::toggle( 'general[delete_all_data_on_uninstall]', 'lkf-delete-all-data', __( 'Delete All Data on Uninstall', 'leenkat-forms' ), true === $s['delete_all_data_on_uninstall'], __( 'When enabled, deleting Leenkat Forms from WordPress permanently removes its forms, entries, settings, and private attachments. Deactivating or updating the plugin does not delete data.', 'leenkat-forms' ), 'lkf-uninstall-warning' );
		echo '<div id="lkf-uninstall-warning" class="notice notice-warning inline" role="alert" data-lkf-uninstall-warning' . ( true === $s['delete_all_data_on_uninstall'] ? '' : ' hidden' ) . '><p><strong>' . esc_html__( 'Warning: this cannot be undone.', 'leenkat-forms' ) . '</strong> ' . esc_html__( 'Deleting the plugin will permanently erase its data, including private attachment migration backups. Keep an external backup before deleting the plugin. Media Library items are retained.', 'leenkat-forms' ) . '</p></div></section>';
	}
	/**
	 * Render the email defaults settings tab.
	 */
	private static function email(): void {
		$s = S::email();
		echo '<div class="lkf-site-section"><div class="lkf-settings-row lkf-email-template-row"><span>' . esc_html__( 'Template', 'leenkat-forms' ) . '</span><fieldset class="lkf-email-template-grid"><legend class="screen-reader-text">' . esc_html__( 'Default email template', 'leenkat-forms' ) . '</legend>';
		foreach ( self::labels() as $value => $label ) {
			echo '<label class="lkf-email-template-tile"><input type="radio" name="email[template]" aria-label="' . esc_attr( $label ) . '" value="' . esc_attr( (string) $value ) . '" ' . checked( $s['template'], $value, false ) . '><span class="lkf-email-mini-wrap"><iframe aria-hidden="true" data-lkf-template="' . esc_attr( (string) $value ) . '" title="' . esc_attr( sprintf( /* translators: %s: email template name. */ __( 'Preview: %s', 'leenkat-forms' ), $label ) ) . '" sandbox="" referrerpolicy="no-referrer" tabindex="-1" scrolling="no"></iframe></span><span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</fieldset></div><p class="description" data-lkf-plain-note hidden>' . esc_html__( 'Plain Text has no header image, colors or typography. These appearance settings remain available to notifications using an HTML template.', 'leenkat-forms' ) . '</p><div data-lkf-formatting><div class="lkf-settings-row"><span>' . esc_html__( 'Appearance', 'leenkat-forms' ) . '</span><div><fieldset><legend class="screen-reader-text">' . esc_html__( 'Edit email appearance', 'leenkat-forms' ) . '</legend>';
		foreach ( array(
			'light' => __( 'Light', 'leenkat-forms' ),
			'dark'  => __( 'Dark', 'leenkat-forms' ),
		) as $mode => $label ) {
			echo '<label class="lkf-radio-label"><input type="radio" name="appearance" value="' . esc_attr( $mode ) . '" ' . checked( $mode, 'light', false ) . '>' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Edit Light and Dark appearances independently. Emails include both; supported clients use Dark automatically, with Light as the fallback. The preview shows the appearance selected here.', 'leenkat-forms' ) . '</p></div></div>';
		foreach ( array( 'light', 'dark' ) as $mode ) {
			$style  = $s[ $mode ];
			$prefix = 'email[' . esc_attr( $mode ) . ']';
			$image  = S::image( $style['header_image'] );
			echo '<div data-lkf-appearance="' . esc_attr( $mode ) . '"' . ( 'dark' === $mode ? ' hidden' : '' ) . '>';
			echo '<div class="lkf-settings-row"><span>' . esc_html__( 'Header Image', 'leenkat-forms' ) . '</span><div class="lkf-header-image" data-lkf-image-control><input type="hidden" name="' . esc_attr( $prefix . '[header_image]' ) . '" value="' . esc_attr( (string) $style['header_image'] ) . '"><div class="lkf-header-image-preview" data-lkf-image-preview data-size="' . esc_attr( $style['header_image_size'] ) . '"' . ( ! $image ? ' hidden' : '' ) . '><img alt="' . esc_attr__( 'Email header image', 'leenkat-forms' ) . '"' . ( $image ? ' src="' . esc_url( $image['url'] ) . '"' : '' ) . '></div><div class="lkf-header-image-actions"><button type="button" class="button lkf-settings-upload" data-lkf-choose-image' . ( $image ? ' hidden' : '' ) . '>' . esc_html__( 'Upload Image', 'leenkat-forms' ) . '</button><select data-lkf-image-size name="' . esc_attr( $prefix . '[header_image_size]' ) . '" aria-label="' . esc_attr( sprintf( /* translators: Appearance name, followed by the color name where present. */ __( '%s Header Image Size', 'leenkat-forms' ), ( 'dark' === $mode ? __( 'Dark', 'leenkat-forms' ) : __( 'Light', 'leenkat-forms' ) ) ) ) . '"' . ( ! $image ? ' hidden' : '' ) . '>';
			foreach ( array(
				'small'  => __( 'Small', 'leenkat-forms' ),
				'medium' => __( 'Medium', 'leenkat-forms' ),
				'large'  => __( 'Large', 'leenkat-forms' ),
			) as $value => $label ) {
				echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $style['header_image_size'], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select><button type="button" class="button lkf-settings-remove" data-lkf-remove-image' . ( ! $image ? ' hidden' : '' ) . '>' . esc_html__( 'Remove Image', 'leenkat-forms' ) . '</button></div><p class="description">' . esc_html( 'dark' === $mode ? __( 'Select a logo for Dark appearance, or leave empty to use the Light logo.', 'leenkat-forms' ) : __( 'Select or upload a logo to appear above the content of notification emails.', 'leenkat-forms' ) ) . '</p></div></div>';
			echo '<div class="lkf-settings-row"><span>' . esc_html__( 'Color Scheme', 'leenkat-forms' ) . '</span><div class="lkf-email-colors">';
			foreach ( array(
				'background' => __( 'Background', 'leenkat-forms' ),
				'body'       => __( 'Body', 'leenkat-forms' ),
				'text'       => __( 'Text', 'leenkat-forms' ),
				'links'      => __( 'Links', 'leenkat-forms' ),
			) as $key => $label ) {
				echo '<label class="lkf-email-color"><span class="lkf-email-color-input"><input type="color" value="' . esc_attr( $style[ $key ] ) . '" data-lkf-color-swatch aria-label="' . esc_attr( sprintf( /* translators: Appearance name, followed by the color name where present. */ __( '%1$s %2$s color picker', 'leenkat-forms' ), ( 'dark' === $mode ? __( 'Dark', 'leenkat-forms' ) : __( 'Light', 'leenkat-forms' ) ), $label ) ) . '"><input type="text" name="' . esc_attr( $prefix . '[' . $key . ']' ) . '" value="' . esc_attr( $style[ $key ] ) . '" pattern="#[a-fA-F0-9]{6}" maxlength="7" required data-lkf-color-value aria-label="' . esc_attr( sprintf( /* translators: Appearance name, followed by the color name where present. */ __( '%1$s %2$s color', 'leenkat-forms' ), ( 'dark' === $mode ? __( 'Dark', 'leenkat-forms' ) : __( 'Light', 'leenkat-forms' ) ), $label ) ) . '"></span><span>' . esc_html( $label ) . '</span></label>';
			}echo '</div></div>';
			echo '<div class="lkf-settings-row"><label for="lkf-font-' . esc_attr( $mode ) . '">' . esc_html__( 'Typography', 'leenkat-forms' ) . '</label><div><select id="lkf-font-' . esc_attr( $mode ) . '" name="' . esc_attr( $prefix . '[typography]' ) . '" aria-describedby="lkf-font-help-' . esc_attr( $mode ) . '">';
			foreach ( array(
				'template' => __( 'Template Default', 'leenkat-forms' ),
				'sans'     => __( 'Sans Serif', 'leenkat-forms' ),
				'serif'    => __( 'Serif', 'leenkat-forms' ),
				'mono'     => __( 'Monospace', 'leenkat-forms' ),
			) as $value => $label ) {
				echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $style['typography'], $value, false ) . '>' . esc_html( $label ) . '</option>';
			}echo '</select><p class="description" id="lkf-font-help-' . esc_attr( $mode ) . '">' . esc_html__( 'Choose the style that’s applied to all text in email notifications.', 'leenkat-forms' ) . '</p></div></div></div>';
		}
		echo '</div><div class="lkf-settings-row"><span></span><div><button type="button" class="button-link" data-lkf-open-preview>' . esc_html__( 'Preview Email Template', 'leenkat-forms' ) . '</button><p class="description" data-lkf-preview-status role="status">' . esc_html__( 'Preview uses sample content and the actual email renderer. No email is sent.', 'leenkat-forms' ) . '</p></div></div></div><section class="lkf-site-section"><h3>' . esc_html__( 'Sending', 'leenkat-forms' ) . '</h3>';
		self::toggle( 'email[optimize_sending]', 'lkf-optimize-email', __( 'Optimize Email Sending', 'leenkat-forms' ), $s['optimize_sending'], __( 'Send notifications for saved entries in the background. When off, try delivery immediately after saving the entry. Forms that do not store entries always send directly. Failed saved-entry deliveries retain automatic retries.', 'leenkat-forms' ) );
		self::toggle( 'email[carbon_copy]', 'lkf-carbon-copy', __( 'Carbon Copy', 'leenkat-forms' ), $s['carbon_copy'], __( 'Make the CC field available in notification settings. Turn off to stop including saved CC recipients.', 'leenkat-forms' ) );
		echo '</section>';
	}
	/**
	 * Render a settings checkbox with its label.
	 *
	 * @param string $name     Name used to resolve the requested item.
	 * @param string $id       Record identifier.
	 * @param string $label    Label for this operation.
	 * @param bool   $checked  Checked for this operation.
	 * @param string $help     Help for this operation.
	 * @param string $controls Controls for this operation.
	 */
	private static function toggle( string $name, string $id, string $label, bool $checked, string $help, string $controls = '' ): void {
		echo '<div class="lkf-settings-row"><label for="' . esc_attr( (string) $id ) . '">' . esc_html( $label ) . '</label><div><label class="lkf-settings-switch"><input type="checkbox" id="' . esc_attr( (string) $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . ( '' !== $controls ? ' aria-controls="' . esc_attr( $controls ) . '" aria-expanded="' . ( $checked ? 'true' : 'false' ) . '"' : '' ) . '><span aria-hidden="true"></span><span data-lkf-switch-caption>' . ( $checked ? esc_html__( 'ON', 'leenkat-forms' ) : esc_html__( 'OFF', 'leenkat-forms' ) ) . '</span></label><p class="description">' . esc_html( $help ) . '</p></div></div>';
	}
}
