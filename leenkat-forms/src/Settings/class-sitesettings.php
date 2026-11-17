<?php
/**
 * Persistence and validation of site-wide plugin settings.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Settings;

/**
 * Persistence and validation of site-wide plugin settings.
 */
final class SiteSettings {
	public const OPTION = 'leenfo_site_settings';
	/**
	 * Return defaults for the plugin site settings.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function defaults(): array {
		return array(
			'general' => array(
				'styling'                      => 'full',
				'assets_global'                => false,
				'gdpr_enhancements'            => false,
				'disable_user_details'         => false,
				'delete_all_data_on_uninstall' => false,
			),
			'email'   => array(
				'template'         => 'default',
				'optimize_sending' => true,
				'carbon_copy'      => false,
				'light'            => array(
					'header_image'      => 0,
					'header_image_size' => 'medium',
					'background'        => '#f4f4f4',
					'body'              => '#ffffff',
					'text'              => '#333333',
					'links'             => '#087e84',
					'typography'        => 'template',
				),
				'dark'             => array(
					'header_image'      => 0,
					'header_image_size' => 'medium',
					'background'        => '#15191d',
					'body'              => '#242b31',
					'text'              => '#f1f4f5',
					'links'             => '#7ed7db',
					'typography'        => 'template',
				),
			),
		);
	}
	/**
	 * Load the normalized plugin site settings.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		// Retain an existing site-wide privacy override when upgrading from dev.39/40.
		if ( ! array_key_exists( 'gdpr_enhancements', $saved['general'] ?? array() ) && ( $saved['general']['disable_user_details'] ?? false ) === true ) {
			$saved['general']['gdpr_enhancements'] = true;
		}
		return array_replace_recursive( self::defaults(), $saved );
	}
	/**
	 * Return the general site settings used by forms.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function general(): array {
		return self::get()['general'];}
	/**
	 * Check whether storage of visitor details is disabled.
	 *
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function disableUserDetails(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$general = self::general();
		return $general['gdpr_enhancements'] && $general['disable_user_details'];}
	/**
	 * Return the site defaults used for email delivery.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function email(): array {
		return self::get()['email'];}
	/**
	 * Return the site settings revision for update checks.
	 *
	 * @param string $section Section for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function revision( string $section ): string {
		return hash( 'sha256', wp_json_encode( self::get()[ $section ] ) );}
	/**
	 * Register site settings handlers used at runtime.
	 */
	public static function hooks(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/site-state',
					array(
						'methods'             => 'GET',
						'permission_callback' => static fn()=>current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ),
						'callback'            => static function (): \WP_REST_Response {
							$r = new \WP_REST_Response( self::builderState() );
							$r->header( 'Cache-Control', 'private, no-store' );
							return $r;
						},
					)
				);
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/email-preview',
					array(
						'methods'             => 'POST',
						'permission_callback' => static fn()=>current_user_can( 'manage_options' ),
						'callback'            => array( \LeenkatForms\Admin\SiteSettingsPage::class, 'preview' ),
					)
				);
			}
		);
	}
	/**
	 * Return the site settings required by the form builder.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function builderState(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return array(
			'globalDisableUserDetails' => self::disableUserDetails(),
			'emailCarbonCopy'          => self::email()['carbon_copy'],
			'emailTemplatePreviews'    => \LeenkatForms\Admin\EmailTemplatePreview::templates(),
		);}
	/**
	 * Validate a proposed site settings update.
	 *
	 * @param string $section Section for this operation.
	 * @param mixed  $value   Value being processed.
	 * @return array Result for the requested operation.
	 */
	public static function validate( string $section, mixed $value ): array {
		if ( ! is_array( $value ) || ( array_is_list( $value ) && array() !== $value ) ) {
			return array( __( 'Invalid settings.', 'leenkat-forms' ) );
		}$errors = array();
		if ( 'general' === $section ) {
			if ( ! in_array( $value['styling'] ?? null, array( 'full', 'base', 'none' ), true ) ) {
				$errors[] = __( 'Choose a valid form styling option.', 'leenkat-forms' );
			}
			foreach ( array( 'assets_global', 'disable_user_details' ) as $key ) {
				if ( ! is_bool( $value[ $key ] ?? null ) ) {
					$errors[] = __( 'Invalid General switch.', 'leenkat-forms' );
				}
			}
			if ( array_key_exists( 'gdpr_enhancements', $value ) && ! is_bool( $value['gdpr_enhancements'] ) ) {
				$errors[] = __( 'Invalid GDPR Enhancements switch.', 'leenkat-forms' );
			}
			if ( array_key_exists( 'delete_all_data_on_uninstall', $value ) && ! is_bool( $value['delete_all_data_on_uninstall'] ) ) {
				$errors[] = __( 'Invalid uninstall switch.', 'leenkat-forms' );
			}
		} elseif ( 'email' === $section ) {
			if ( ! in_array( $value['template'] ?? null, \LeenkatForms\Forms\NotificationSettings::TEMPLATES, true ) ) {
				$errors[] = __( 'Choose a valid email template.', 'leenkat-forms' );
			}
			foreach ( array( 'optimize_sending', 'carbon_copy' ) as $key ) {
				if ( ! is_bool( $value[ $key ] ?? null ) ) {
					$errors[] = __( 'Invalid Email switch.', 'leenkat-forms' );
				}
			}
			foreach ( array( 'light', 'dark' ) as $mode ) {
				$style = $value[ $mode ] ?? null;if ( ! is_array( $style ) ) {
					$errors[] = __( 'Invalid appearance settings.', 'leenkat-forms' );
					continue;}
				foreach ( array( 'background', 'body', 'text', 'links' ) as $key ) {
					if ( ! is_string( $style[ $key ] ?? null ) || ! preg_match( '/^#[a-fA-F0-9]{6}$/D', $style[ $key ] ) ) {
						$errors[] = __( 'Enter valid six-digit color values.', 'leenkat-forms' );
					}
				}
				if ( ! in_array( $style['typography'] ?? null, array( 'template', 'sans', 'serif', 'mono' ), true ) ) {
					$errors[] = __( 'Choose a valid typography option.', 'leenkat-forms' );
				}
				if ( array_key_exists( 'header_image_size', $style ) && ! in_array( $style['header_image_size'], array( 'small', 'medium', 'large' ), true ) ) {
					$errors[] = __( 'Choose a valid header image size.', 'leenkat-forms' );
				}
				$image = $style['header_image'] ?? null;
				if ( ! is_int( $image ) || $image < 0 || ( $image > 0 && null === self::image( $image ) ) ) {
					$errors[] = __( 'Choose a PNG, JPEG, GIF or WebP image from the Media Library.', 'leenkat-forms' );
				}
			}
		} else {
			$errors[] = __( 'Invalid settings section.', 'leenkat-forms' );
		}
		return array_values( array_unique( $errors ) );
	}
	/**
	 * Resolve only approved image attachments, never fetch a supplied URL on the server.
	 *
	 * @param int $id Record identifier.
	 */
	public static function image( int $id ): ?array {
		$attachment = $id > 0 ? get_post( $id ) : null;
		if ( ! $attachment || 'attachment' !== $attachment->post_type || 'inherit' !== $attachment->post_status || ! in_array( $attachment->post_mime_type, array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ), true ) ) {
			return null;
		}
		$image = wp_get_attachment_image_src( $id, 'large' );
		if ( ! $image || ! in_array( wp_parse_url( $image[0], PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		return array(
			'url'    => $image[0],
			'width'  => (int) $image[1],
			'height' => (int) $image[2],
			'alt'    => sanitize_text_field( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
		);
	}
	/**
	 * Persist verified site settings and update their revision.
	 *
	 * @param string $section  Section for this operation.
	 * @param array  $value    Value being processed.
	 * @param string $revision Revision for this operation.
	 * @return true|\WP_Error Result for the requested operation.
	 */
	public static function save( string $section, array $value, string $revision ): true|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'leenfo_forbidden', __( 'Access denied.', 'leenkat-forms' ), array( 'status' => 403 ) );
		}
		$errors = self::validate( $section, $value );
		if ( $errors ) {
			return new \WP_Error( 'leenfo_site_settings', implode( ' ', $errors ), array( 'status' => 400 ) );
		}
		$lock = 'leenfo_site_settings_lock';if ( ! add_option( $lock, (string) ( time() + 30 ), '', false ) ) {
			global $wpdb;
			$expires = (int) get_option( $lock, 0 );
			if ( $expires > 0 && $expires < time() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock, (string) $expires ) );
				wp_cache_delete( $lock, 'options' );}
			return new \WP_Error( 'leenfo_settings_busy', __( 'Settings are being saved. Please try again.', 'leenkat-forms' ), array( 'status' => 409 ) );
		}
		try {
			if ( ! hash_equals( self::revision( $section ), $revision ) ) {
				return new \WP_Error( 'leenfo_settings_changed', __( 'Settings changed in another tab. Reload this page before saving again.', 'leenkat-forms' ), array( 'status' => 409 ) );
			}
			$next  = self::get();
			$clean = array_intersect_key( $value, self::defaults()[ $section ] );
			if ( 'general' === $section ) {
				$clean['gdpr_enhancements'] = $clean['gdpr_enhancements'] ?? ( $clean['disable_user_details'] || $next['general']['gdpr_enhancements'] );
			}
			// Older callers do not silently change the explicit uninstall choice.
			if ( 'general' === $section ) {
				$clean['delete_all_data_on_uninstall'] = $clean['delete_all_data_on_uninstall'] ?? $next['general']['delete_all_data_on_uninstall'];
			}
			if ( 'email' === $section ) {
				foreach ( array( 'light', 'dark' ) as $mode ) {
					$clean[ $mode ]                      = array_intersect_key( $clean[ $mode ], self::defaults()['email'][ $mode ] );
					$clean[ $mode ]['header_image_size'] = $clean[ $mode ]['header_image_size'] ?? 'medium';
					foreach ( array( 'background', 'body', 'text', 'links' ) as $key ) {
						$clean[ $mode ][ $key ] = strtolower( $clean[ $mode ][ $key ] );
					}
				}
			}
			$next[ $section ] = $clean;
			if ( self::get() !== $next && ! update_option( self::OPTION, $next, false ) ) {
				return new \WP_Error( 'leenfo_settings_save', __( 'Settings could not be saved.', 'leenkat-forms' ), array( 'status' => 500 ) );
			}return true;
		} finally {
			delete_option( $lock );}
	}
}
