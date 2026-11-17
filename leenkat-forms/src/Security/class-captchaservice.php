<?php
/**
 * Provider assets and CAPTCHA verification for public forms.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

/**
 * Provider assets and CAPTCHA verification for public forms.
 */
final class CaptchaService {
	/**
	 * Whether a CAPTCHA field requests provider assets.
	 *
	 * @var bool
	 */
	private static bool $used = false;
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Optional configured CAPTCHA provider endpoint/script; required for provider verification, disclosed in readme, and loaded only when explicitly enabled.
	private const VERIFY = array(
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Optional configured CAPTCHA provider endpoint/script; required for provider verification, disclosed in readme, and loaded only when explicitly enabled.
		'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Optional configured CAPTCHA provider endpoint/script; required for provider verification, disclosed in readme, and loaded only when explicitly enabled.
		'hcaptcha'  => 'https://api.hcaptcha.com/siteverify',
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Optional configured CAPTCHA provider endpoint/script; required for provider verification, disclosed in readme, and loaded only when explicitly enabled.
		'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Optional configured CAPTCHA provider endpoint/script; required for provider verification, disclosed in readme, and loaded only when explicitly enabled.
	);
	/**
	 * Register the CAPTCHA provider hooks.
	 */
	public static function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'primeNoConflict' ), 9999 );
		add_action( 'wp_print_footer_scripts', array( self::class, 'noConflict' ), 1 );
		add_action( 'admin_print_footer_scripts', array( self::class, 'noConflict' ), 1 );
		add_filter( 'script_loader_tag', array( self::class, 'scriptTag' ), 10, 3 );
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/captcha-state',
					array(
						'methods'             => 'GET',
						'permission_callback' => static fn()=>current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ),
						'callback'            => static function (): \WP_REST_Response {
							$response = new \WP_REST_Response( CaptchaSettings::builderState() );
							$response->header( 'Cache-Control', 'private, no-store' );
							return $response;
						},
					)
				);
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/captcha-preview',
					array(
						'methods'             => 'POST',
						'permission_callback' => static fn()=>current_user_can( 'manage_options' ),
						'callback'            => array( self::class, 'preview' ),
					)
				);
			}
		);
	}
	/**
	 * Load the frontend CAPTCHA resources.
	 */
	public static function assets(): void {
		self::$used = true;
		wp_enqueue_script( 'leenfo-captcha', plugins_url( 'assets/frontend/captcha.js', LEENKAT_FORMS_FILE ), array(), LEENKAT_FORMS_VERSION . '.' . filemtime( LEENKAT_FORMS_DIR . 'assets/frontend/captcha.js' ), true );
	}
	/**
	 * Render the CAPTCHA container for the selected form.
	 *
	 * @param int $formId Form identifier.
	 * @return string Result for the requested operation.
	 */
	public static function markup( int $formId ): string { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		$config = CaptchaSettings::publicConfig( $formId ) ?? array( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
			'provider' => 'none',
			'ready'    => false,
			'message'  => __( 'CAPTCHA is not configured. Please contact the site administrator.', 'leenkat-forms' ),
		);
		self::assets();
		return '<div class="lkf-service-captcha" data-lkf-captcha="' . esc_attr( wp_json_encode( $config ) ) . '"><div data-lkf-captcha-widget></div><input type="hidden" name="service_captcha_token" value=""><p data-lkf-captcha-status role="status" aria-live="polite"></p><noscript>' . esc_html__( 'Please enable JavaScript to complete CAPTCHA verification.', 'leenkat-forms' ) . '</noscript></div>';
	}
	/** Only opt-in pages containing our widget are affected; registration is preserved. */
	public static function primeNoConflict(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( empty( CaptchaSettings::get()['no_conflict'] ) || ! CaptchaSettings::ready() ) {
			return;
		}global $post;
		if ( ! $post instanceof \WP_Post || ! has_shortcode( $post->post_content, 'leenkat_form' ) ) {
			return;
		}
		if ( preg_match_all( '/' . get_shortcode_regex( array( 'leenkat_form' ) ) . '/s', $post->post_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				// Escaped [[shortcodes]] do not render a form.
				if ( '[' === $match[1] && ']' === $match[6] ) {
					continue;
				}$atts = shortcode_parse_atts( $match[3] );
				$id    = is_array( $atts ) ? absint( $atts['id'] ?? 0 ) : 0;
				$form  = $id ? \LeenkatForms\Forms\Publisher::published( $id ) : null;
				if ( $form && ! empty( $form['definition']['settings']['security']['captcha_enabled'] ) ) {
					self::$used = true;
					break;}
			}
		}
		self::noConflict();
	}
	/**
	 * Apply the configured CAPTCHA script compatibility behavior.
	 */
	public static function noConflict(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		if ( ! self::$used || empty( CaptchaSettings::get()['no_conflict'] ) ) {
			return;
		}$scripts = wp_scripts();
		foreach ( $scripts->queue as $handle ) {
			if ( str_starts_with( $handle, 'lkf-' ) ) {
				continue;
			}$src = $scripts->registered[ $handle ]->src ?? '';
			if ( self::is_provider_script( $src ) ) {
				wp_dequeue_script( $handle );
			}
		}
	}
	/**
	 * Process the script tag for a recognized CAPTCHA provider.
	 *
	 * @param string $tag    Tag for this operation.
	 * @param string $handle Handle for this operation.
	 * @param string $src    Src for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function scriptTag( string $tag, string $handle, string $src ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return self::$used && ! empty( CaptchaSettings::get()['no_conflict'] ) && ! str_starts_with( $handle, 'lkf-' ) && self::is_provider_script( $src ) ? '' : $tag;
	}
	/**
	 * Check whether a script belongs to a supported CAPTCHA provider.
	 *
	 * @param string $src Src for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	private static function is_provider_script( string $src ): bool {
		$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Optional configured CAPTCHA provider endpoint/script; required for provider verification, disclosed in readme, and loaded only when explicitly enabled.
		return ( in_array( $host, array( 'www.google.com', 'www.recaptcha.net', 'recaptcha.net' ), true ) && '/recaptcha/api.js' === $path ) || ( in_array( $host, array( 'js.hcaptcha.com', 'hcaptcha.com' ), true ) && '/1/api.js' === $path ) || ( 'challenges.cloudflare.com' === $host && '/turnstile/v0/api.js' === $path );
	}
	/**
	 * Verify a submitted CAPTCHA result for the form.
	 *
	 * @param array  $definition  Form definition being processed.
	 * @param int    $formId      Form identifier.
	 * @param int    $version     Version for this operation.
	 * @param string $uuid        Uuid for this operation.
	 * @param string $requestHash Request hash for this operation.
	 * @param mixed  $token       Token supplied for validation or encoding.
	 * @return true|\WP_Error Result for the requested operation.
	 */
	public static function verifyForm( array $definition, int $formId, int $version, string $uuid, string $requestHash, mixed $token ): true|\WP_Error { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing callable method name for PHP callers and callback compatibility. Preserve the existing PHP named parameter $requestHash and its method binding.
		if ( empty( $definition['settings']['security']['captcha_enabled'] ) ) {
			return true;
		}
		return self::verify( $token, CaptchaSettings::action( $formId ), $formId . '|' . $version . '|' . $uuid . '|' . $requestHash ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $requestHash and its method binding.
	}
	/**
	 * Build the CAPTCHA preview response.
	 *
	 * @param \WP_REST_Request $request Incoming WordPress request.
	 * @return \WP_REST_Response|\WP_Error Result for the requested operation.
	 */
	public static function preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'leenfo_forbidden', __( 'Access denied.', 'leenkat-forms' ), array( 'status' => 403 ) );
		}
		if ( ! RateLimiter::allow( -900000 - get_current_user_id(), CountryLookup::clientIp( $_SERVER ), 20 ) ) {
			return new \WP_Error( 'leenfo_rate_limit', __( 'Please wait before testing again.', 'leenkat-forms' ), array( 'status' => 429 ) );
		}
		$data   = $request->get_json_params();
		$result = self::verify( is_array( $data ) ? ( $data['token'] ?? null ) : null, CaptchaSettings::action( 0 ), 'preview|' . get_current_user_id() . '|' . wp_generate_uuid4() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}$response = new \WP_REST_Response(
			array(
				'verified' => true,
				'message'  => __( 'CAPTCHA verification succeeded.', 'leenkat-forms' ),
			)
		);
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
	/**
	 * Cache only a keyed token digest bound to this exact request, for safe transport retries.
	 *
	 * @param mixed  $token  Token supplied for validation or encoding.
	 * @param string $action Action for this operation.
	 * @param string $scope  Scope for this operation.
	 */
	public static function verify( mixed $token, string $action, string $scope ): true|\WP_Error {
		$s    = CaptchaSettings::get();
		$p    = $s['provider'];
		$item = $s['providers'][ $p ] ?? array();
		if ( ! CaptchaSettings::ready( $s ) ) {
			return new \WP_Error( 'leenfo_captcha_unavailable', __( 'CAPTCHA is not configured. Please contact the site administrator.', 'leenkat-forms' ), array( 'status' => 503 ) );
		}
		$fail = static fn()=>new \WP_Error( 'leenfo_captcha_failed', $item['fail_message'], array( 'status' => 400 ) );
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 8192 || ( 'turnstile' === $p && strlen( $token ) > 2048 ) || preg_match( '/[\x00-\x20\x7f]/', $token ) ) {
			return $fail();
		}
		$key     = 'leenfo_captcha_pass_' . hash_hmac( 'sha256', $p . '|' . $item['site_key'] . '|' . $token, wp_salt( 'auth' ) );
		$binding = hash_hmac( 'sha256', CaptchaSettings::revision() . '|' . $action . '|' . $scope, wp_salt( 'auth' ) );
		$cached  = get_transient( $key );
		if ( is_string( $cached ) ) {
			return hash_equals( $cached, $binding ) ? true : $fail();
		}
		$lock = $key . '_lock';if ( ! add_option( $lock, (string) ( time() + 30 ), '', false ) ) {
			global $wpdb;
			$expires = (int) get_option( $lock, 0 );
			if ( $expires > 0 && $expires < time() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock, (string) $expires ) );
				wp_cache_delete( $lock, 'options' );}
			return new \WP_Error( 'leenfo_captcha_busy', __( 'Verification is in progress. Please try again.', 'leenkat-forms' ), array( 'status' => 409 ) );
		}
		try {
			$cached = get_transient( $key );
			if ( is_string( $cached ) ) {
				return hash_equals( $cached, $binding ) ? true : $fail();
			}
			$body = array(
				'secret'   => CaptchaSettings::secret( $item['secret'] ),
				'response' => $token,
			);
			if ( 'hcaptcha' === $p ) {
				$body['sitekey'] = $item['site_key'];
			}
			$response = wp_remote_post(
				self::VERIFY[ $p ],
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 16384,
					'body'                => $body,
				)
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return new \WP_Error( 'leenfo_captcha_unavailable', $item['fail_message'], array( 'status' => 503 ) );
			}
			$result = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $result ) || ( $result['success'] ?? null ) !== true ) {
				return $fail();
			}
			// hCaptcha authenticates the requested sitekey; its hostname is informational only.
			if ( 'hcaptcha' !== $p ) {
				$expected = strtolower( rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), '.' ) );
				$host     = $result['hostname'] ?? null;
				if ( ! is_string( $host ) || strtolower( rtrim( $host, '.' ) ) !== $expected ) {
					return $fail();
				}
			}
			if ( 'turnstile' === $p || ( 'recaptcha' === $p && 'v3' === $item['type'] ) ) {
				if ( ( $result['action'] ?? null ) !== $action ) {
					return $fail();
				}
			}
			if ( 'recaptcha' === $p && 'v3' === $item['type'] ) {
				$score = $result['score'] ?? null;
				if ( ( ! is_int( $score ) && ! is_float( $score ) ) || ! is_finite( (float) $score ) || $score < 0 || $score > 1 || $score <= $item['threshold'] ) {
					return $fail();
				}
			}
			if ( isset( $result['challenge_ts'] ) ) {
				$timestamp = is_string( $result['challenge_ts'] ) ? strtotime( $result['challenge_ts'] ) : false;
				if ( false === $timestamp || $timestamp > time() + 60 || $timestamp < time() - 360 ) {
					return $fail();
				}
			}
			if ( ! set_transient( $key, $binding, 5 * MINUTE_IN_SECONDS ) ) {
				return new \WP_Error( 'leenfo_captcha_unavailable', $item['fail_message'], array( 'status' => 503 ) );
			}
			return true;
		} finally {
			delete_option( $lock );}
	}
}
