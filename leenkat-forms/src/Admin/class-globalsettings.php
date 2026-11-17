<?php
/**
 * Administrative API and presentation for shared provider settings.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Admin;

use LeenkatForms\Security\CaptchaSettings;
use LeenkatForms\Security\CaptchaService;

/**
 * Administrative API and presentation for shared provider settings.
 */
final class GlobalSettings {
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation links only; no remote asset is loaded by this declaration.
	private const DOCS = array(
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation links only; no remote asset is loaded by this declaration.
		'hcaptcha'  => 'https://docs.hcaptcha.com/',
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation links only; no remote asset is loaded by this declaration.
		'recaptcha' => 'https://developers.google.com/recaptcha/intro',
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation links only; no remote asset is loaded by this declaration.
		'turnstile' => 'https://developers.cloudflare.com/turnstile/get-started/',
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation links only; no remote asset is loaded by this declaration.
	);
	/**
	 * Register the settings page actions and assets.
	 */
	public static function hooks(): void {
		add_action( 'admin_post_leenfo_save_settings', array( self::class, 'save' ) );
		SiteSettingsPage::hooks();}
	/**
	 * Validate and save the requested global settings.
	 */
	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', 403 );
		}
		check_admin_referer( 'leenfo_captcha_settings' );
		$revision  = is_string( $_POST['revision'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['revision'] ) ) : null;
		$provider  = is_string( $_POST['provider'] ?? null ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : null;
		$providers = array();
		foreach ( array_keys( CaptchaSettings::PROVIDERS ) as $slug ) {
			foreach ( array( 'site_key', 'secret_key', 'fail_message', 'type', 'threshold' ) as $key ) {
				$providers[ $slug ][ $key ] = is_string( $_POST['providers'][ $slug ][ $key ] ?? null )
					? sanitize_text_field( wp_unslash( $_POST['providers'][ $slug ][ $key ] ) ) : null;
			}
			$providers[ $slug ]['remove_secret'] = is_string( $_POST['providers'][ $slug ]['remove_secret'] ?? null ) &&
				'1' === sanitize_text_field( wp_unslash( $_POST['providers'][ $slug ]['remove_secret'] ) );
		}
		$result = CaptchaSettings::save(
			array(
				'revision'    => $revision,
				'provider'    => $provider,
				'no_conflict' => is_string( $_POST['no_conflict'] ?? null ) && '1' === sanitize_text_field( wp_unslash( $_POST['no_conflict'] ) ),
				'providers'   => $providers,
			)
		);
		if ( is_wp_error( $result ) ) {
			set_transient( 'leenfo_settings_notice_' . get_current_user_id(), $result->get_error_message(), 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=leenkat-forms-settings&view=captcha&saved=' . ( is_wp_error( $result ) ? '0' : '1' ) ) );
		exit;
	}
	/**
	 * Render the global settings screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'leenkat-forms' ), '', 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
		$view = is_string( $_GET['view'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'general';
		if ( ! in_array( $view, array( 'general', 'email', 'captcha' ), true ) ) {
			$view = 'general';
		}
		if ( 'captcha' !== $view ) {
			SiteSettingsPage::render( $view );
			return;}
		$s        = CaptchaSettings::get();
		$selected = $s['provider'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
		$requested = $_GET['provider'] ?? null;
		if ( is_string( $requested ) && isset( CaptchaSettings::PROVIDERS[ $requested ] ) ) {
			$selected = $requested;
		}
		wp_enqueue_style( 'leenfo-settings', plugins_url( 'assets/admin/settings.css', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/settings.css' ) );
		wp_enqueue_script( 'leenfo-settings', plugins_url( 'assets/admin/settings.js', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/settings.js' ), true );
		if ( CaptchaSettings::ready() ) {
			CaptchaService::assets();
		}
		self::header( 'captcha' );
		$notice = get_transient( 'leenfo_settings_notice_' . get_current_user_id() );if ( is_string( $notice ) ) {
			delete_transient( 'leenfo_settings_notice_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( $notice ) . '</p></div>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only routing/filter/presentation input; it changes no data, capabilities are enforced by the page and values are allowlisted/escaped before use. Values are exact-compared against a closed enum/literal or validated by the existing schema; sanitizing approval flags could turn a malformed value into approval.
		} elseif ( ( $_GET['saved'] ?? '' ) === '1' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved. Enable CAPTCHA for each form in Spam Protection and Security.', 'leenkat-forms' ) . '</p></div>';
		}
		echo ( '<h2>' . esc_html__( 'CAPTCHA', 'leenkat-forms' ) . '</h2><p>' ) . esc_html__( 'Choose a CAPTCHA service to help protect your forms from automated spam.', 'leenkat-forms' ) . '</p><form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" data-lkf-settings>';
		wp_nonce_field( 'leenfo_captcha_settings' );
		echo '<input type="hidden" name="action" value="leenfo_save_settings"><input type="hidden" name="revision" value="' . esc_attr( CaptchaSettings::revision() ) . '">';
		echo '<div class="lkf-provider-grid" role="radiogroup" aria-label="' . esc_attr__( 'CAPTCHA service', 'leenkat-forms' ) . '">';
		foreach ( array(
			'hcaptcha'  => 'hCaptcha',
			'recaptcha' => 'reCAPTCHA',
			'turnstile' => 'Turnstile',
			'none'      => __( 'None', 'leenkat-forms' ),
		) as $slug => $label ) {
			$image = match ( $slug ) {
				'turnstile'=>'cloudflare.svg', 'none'=>'none.svg', default=>$slug . '.svg'};
			echo '<label class="lkf-provider-tile"><input type="radio" name="provider" value="' . esc_attr( $slug ) . '" ' . checked( $selected, $slug, false ) . '><img src="' . esc_url( plugins_url( 'assets/images/anti-spam/' . $image, LEENKAT_FORMS_FILE ) ) . '" width="80" height="80" alt=""><span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</div><p class="lkf-provider-comparison">' . esc_html__( 'Choose one service for the site, then enable it on the forms you want to protect. None disables service CAPTCHA site-wide.', 'leenkat-forms' ) . '</p>';
		foreach ( CaptchaSettings::PROVIDERS as $slug => $label ) {
			$item   = $s['providers'][ $slug ];
			$prefix = 'providers[' . $slug . ']';
			$id     = 'lkf-' . $slug;
			echo '<section data-lkf-provider="' . esc_attr( $slug ) . '"' . ( $selected !== $slug ? ' hidden' : '' ) . ' aria-label="' . esc_attr( $label ) . '">';
			$description = match ( $slug ) {
				'hcaptcha'=>__( 'hCaptcha displays a visual challenge to help visitors confirm they are human.', 'leenkat-forms' ), 'recaptcha'=>__( 'Google reCAPTCHA supports a v2 checkbox, an invisible v2 challenge, or v3 scoring. Create keys for the type and domain you select.', 'leenkat-forms' ), default=>__( 'Cloudflare Turnstile verifies visitors with a CAPTCHA-like check. Auto, Light and Dark control the widget appearance.', 'leenkat-forms' )};
			echo '<div class="lkf-provider-info"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span><div><p>' . esc_html( $description ) . '</p><p><a href="' . esc_url( self::DOCS[ $slug ] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read the setup documentation →', 'leenkat-forms' ) . '</a></p></div></div><div class="lkf-settings-fields">';
			if ( 'recaptcha' === $slug ) {
				echo '<div class="lkf-settings-row"><span>' . esc_html__( 'Type', 'leenkat-forms' ) . '</span><fieldset><legend class="screen-reader-text">' . esc_html__( 'reCAPTCHA Type', 'leenkat-forms' ) . '</legend>';
				foreach ( array(
					'v2'        => __( 'Checkbox reCAPTCHA v2', 'leenkat-forms' ),
					'invisible' => __( 'Invisible reCAPTCHA v2', 'leenkat-forms' ),
					'v3'        => 'reCAPTCHA v3',
				) as $type => $caption ) {
					echo '<label class="lkf-radio-label"><input type="radio" name="' . esc_attr( $prefix . '[type]' ) . '" value="' . esc_attr( $type ) . '" ' . checked( $item['type'], $type, false ) . '>' . esc_html( $caption ) . '</label>';
				}
				echo '</fieldset></div>';
			}
			self::input( $id . '-site-key', __( 'Site Key', 'leenkat-forms' ), $prefix . '[site_key]', $item['site_key'], 'text' );
			echo '<div class="lkf-settings-row"><label for="' . esc_attr( $id . '-secret-key' ) . '">' . esc_html__( 'Secret Key', 'leenkat-forms' ) . '</label><div><input type="password" id="' . esc_attr( $id . '-secret-key' ) . '" name="' . esc_attr( $prefix . '[secret_key]' ) . '" value="" autocomplete="new-password" spellcheck="false" maxlength="1024" placeholder="' . esc_attr( '' !== $item['secret'] ? __( 'Saved — leave blank to keep it', 'leenkat-forms' ) : '' ) . '">';
			if ( '' !== $item['secret'] ) {
				echo '<label class="lkf-remove-secret"><input type="checkbox" name="' . esc_attr( $prefix . '[remove_secret]' ) . '" value="1">' . esc_html__( 'Remove saved secret key', 'leenkat-forms' ) . '</label>';
			}
			echo '</div></div>';
			self::input( $id . '-message', __( 'Fail Message', 'leenkat-forms' ), $prefix . '[fail_message]', $item['fail_message'], 'text', __( 'Displays to users who fail the verification process.', 'leenkat-forms' ) );
			if ( 'recaptcha' === $slug ) {
				echo '<div class="lkf-settings-row" data-lkf-score' . ( 'v3' !== $item['type'] ? ' hidden' : '' ) . '><label for="lkf-recaptcha-score">' . esc_html__( 'Score Threshold', 'leenkat-forms' ) . '</label><div><input id="lkf-recaptcha-score" type="number" name="' . esc_attr( $prefix . '[threshold]' ) . '" value="' . esc_attr( (string) $item['threshold'] ) . '" min="0" max="1" step="0.1"><p class="description">' . esc_html__( 'Scores at or below this threshold are rejected. Higher scores indicate a more likely human interaction.', 'leenkat-forms' ) . '</p></div></div>';
			} else {
				echo '<input type="hidden" name="' . esc_attr( $prefix . '[threshold]' ) . '" value="0.4">';
				if ( 'turnstile' === $slug ) {
					echo '<div class="lkf-settings-row"><label for="lkf-turnstile-type">' . esc_html__( 'Type', 'leenkat-forms' ) . '</label><select id="lkf-turnstile-type" name="' . esc_attr( $prefix . '[type]' ) . '">';
					foreach ( array(
						'auto'  => 'Auto',
						'light' => 'Light',
						'dark'  => 'Dark',
					) as $type => $caption ) {
						echo '<option value="' . esc_attr( $type ) . '" ' . selected( $item['type'], $type, false ) . '>' . esc_html( $caption ) . '</option>';
					}echo '</select></div>';
				} else {
					echo '<input type="hidden" name="' . esc_attr( $prefix . '[type]' ) . '" value="auto">';
				}
			}
			echo '</div></section>';
		}
		echo '<div data-lkf-service-options' . ( 'none' === $selected ? ' hidden' : '' ) . '><div class="lkf-settings-row"><label for="lkf-no-conflict">' . esc_html__( 'No-Conflict Mode', 'leenkat-forms' ) . '</label><div><label class="lkf-settings-switch"><input id="lkf-no-conflict" type="checkbox" name="no_conflict" value="1" ' . checked( $s['no_conflict'], true, false ) . '><span aria-hidden="true"></span><span data-lkf-switch-caption>' . ( $s['no_conflict'] ? esc_html__( 'ON', 'leenkat-forms' ) : esc_html__( 'OFF', 'leenkat-forms' ) ) . '</span></label><p class="description">' . esc_html__( 'Remove other queued CAPTCHA scripts on pages using Leenkat CAPTCHA to prevent conflicts. Enable only if your site has compatibility issues.', 'leenkat-forms' ) . '</p></div></div>';
		echo '<div class="lkf-settings-row"><span>' . esc_html__( 'Preview', 'leenkat-forms' ) . '</span><div data-lkf-saved-preview data-provider="' . esc_attr( $s['provider'] ) . '">';
		if ( CaptchaSettings::ready() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CAPTCHA markup is built by the existing provider allowlist renderer with escaped site-key and widget attributes; it contains no secret key.
			echo CaptchaService::markup( 0 ) . '<button type="button" class="button" data-lkf-test-captcha data-url="' . esc_url( rest_url( 'leenkat-forms/v1/admin/captcha-preview' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '">' . esc_html__( 'Test CAPTCHA', 'leenkat-forms' ) . '</button><p data-lkf-preview-result role="status" aria-live="polite"></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Save the service and keys to generate a preview here.', 'leenkat-forms' ) . '</p>';
		}
		echo '</div><p class="description" data-lkf-preview-save hidden>' . esc_html__( 'Save settings to update the CAPTCHA preview.', 'leenkat-forms' ) . '</p></div></div>';
		echo '<div class="lkf-settings-save">';
		submit_button( __( 'Save Settings', 'leenkat-forms' ), 'primary', 'submit', false );
		echo '</div></form></div>';
	}
	/**
	 * Render the shared settings header.
	 *
	 * @param string $view View for this operation.
	 */
	public static function header( string $view ): void {
		wp_enqueue_style( 'leenfo-settings', plugins_url( 'assets/admin/settings.css', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/admin/settings.css' ) );
		echo '<div class="wrap lkf-global-settings">';
		AdminHeader::settings( __( 'Leenkat Forms Settings', 'leenkat-forms' ) );
		echo '<nav class="lkf-settings-tabs" aria-label="' . esc_attr__( 'Settings sections', 'leenkat-forms' ) . '">';
		foreach ( array(
			'general' => __( 'General', 'leenkat-forms' ),
			'email'   => __( 'Email', 'leenkat-forms' ),
			'captcha' => __( 'CAPTCHA', 'leenkat-forms' ),
		) as $key => $label ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=leenkat-forms-settings&view=' . $key ) ) . '"' . ( $view === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}echo '</nav>';
	}
	/**
	 * Read and sanitize text from a submitted settings field.
	 *
	 * @param string $id    Record identifier.
	 * @param string $label Label for this operation.
	 * @param string $name  Name used to resolve the requested item.
	 * @param string $value Value being processed.
	 * @param string $type  Type for this operation.
	 * @param string $help  Help for this operation.
	 */
	private static function input( string $id, string $label, string $name, string $value, string $type, string $help = '' ): void {
		echo '<div class="lkf-settings-row"><label for="' . esc_attr( (string) $id ) . '">' . esc_html( $label ) . '</label><div><input id="' . esc_attr( (string) $id ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" maxlength="' . ( str_ends_with( $name, '[site_key]' ) ? '200' : '1000' ) . '" spellcheck="false">';
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}echo '</div></div>';
	}
}
