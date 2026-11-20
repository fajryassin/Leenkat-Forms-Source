<?php
/**
 * Opt-in Akismet checks for configured form submissions.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

/** Optional native Akismet adapter. Credentials and transport belong to Akismet. */
final class AkismetService {
	/**
	 * Register the Akismet-related plugin hooks.
	 */
	public static function hooks(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'leenkat-forms/v1',
					'/admin/akismet-state',
					array(
						'methods'             => 'GET',
						'permission_callback' => static fn()=>current_user_can( \LeenkatForms\Security\Capabilities::MANAGE_FORMS ),
						'callback'            => static function (): \WP_REST_Response {
							$r = new \WP_REST_Response( self::state() );
							$r->header( 'Cache-Control', 'private, no-store' );
							return $r;
						},
					)
				);
			}
		);
		add_action(
			'admin_init',
			static function (): void {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					wp_add_privacy_policy_content( __( 'Leenkat Forms', 'leenkat-forms' ), wpautop( esc_html__( 'Forms with Akismet protection enabled send the selected name, email and message, IP address, browser user agent and a query-free same-site referrer to Akismet for spam classification. Files and unrelated answers are excluded. Review Akismet’s privacy policy and your site’s lawful basis or consent requirements before enabling this optional service.', 'leenkat-forms' ) ) );
				}}
		);
	}
	/**
	 * Return the Akismet status used by the form builder.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function state(): array {
		$status    = 'absent';
		$installed = is_file( WP_PLUGIN_DIR . '/akismet/akismet.php' );
		if ( $installed ) {
			$active = in_array( 'akismet/akismet.php', (array) get_option( 'active_plugins', array() ), true ) || ( is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )['akismet/akismet.php'] ) );
			$status = $active ? 'unconfigured' : 'inactive';
			if ( $active ) {
				if ( ! is_callable( array( '\Akismet', 'get_api_key' ) ) || ! is_callable( array( '\Akismet', 'verify_key' ) ) || ! is_callable( array( '\Akismet', 'comment_check' ) ) ) {
					$status = 'unavailable';} else {
					$key = \Akismet::get_api_key();if ( is_string( $key ) && 12 === strlen( $key ) ) {
						$cache  = 'leenfo_akismet_key_' . hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
						$status = get_transient( $cache );
						if ( ! in_array( $status, array( 'ready', 'unconfigured', 'unavailable' ), true ) ) {
							$response = self::guarded( static fn()=>\Akismet::verify_key( $key ), 'verify-key' );
							$status   = 'valid' === $response ? 'ready' : ( 'invalid' === $response ? 'unconfigured' : 'unavailable' );
							set_transient( $cache, $status, 'unavailable' === $status ? 30 : 300 );}
					}
					}
			}
		}
		return array(
			'status'    => $status,
			'ready'     => 'ready' === $status,
			'setup_url' => $installed ? admin_url( 'inactive' === $status ? 'plugins.php' : 'options-general.php?page=akismet-key-config' ) : admin_url( 'plugin-install.php?s=akismet&tab=search&type=term' ),
		);
	}
	/**
	 * Check whether the site can use the Akismet service.
	 *
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function ready(): bool {
		return self::state()['ready'];}
	/**
	 * Explicit mappings only: no whole-form payload, uploads or hidden values.
	 *
	 * @param array $definition Form definition being processed.
	 * @param array $values     Values from the current submission.
	 * @param array $server     Server for this operation.
	 */
	public static function data( array $definition, array $values, array $server ): array {
		$data = array(
			'comment_type' => 'contact-form',
			'user_ip'      => CountryLookup::clientIp( $server ),
			'user_agent'   => self::text( is_string( $server['HTTP_USER_AGENT'] ?? null ) ? wp_unslash( $server['HTTP_USER_AGENT'] ) : '', 512 ),
		);
		$ref  = is_string( $server['HTTP_REFERER'] ?? null ) ? wp_unslash( $server['HTTP_REFERER'] ) : '';if ( is_string( $ref ) && strlen( $ref ) <= 4096 ) {
			$parts = wp_parse_url( sanitize_url( $ref, array( 'http', 'https' ) ) );
			$site  = wp_parse_url( home_url( '/' ) );
			if ( is_array( $parts ) && is_array( $site ) && strtolower( $parts['host'] ?? '' ) === strtolower( $site['host'] ?? '' ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) ) {
				$data['referrer'] = ( $site['scheme'] ?? 'https' ) . '://' . $site['host'] . ( isset( $site['port'] ) ? ':' . $site['port'] : '' ) . sanitize_url( substr( '/' . ltrim( $parts['path'] ?? '/', '/' ), 0, 1000 ), array( 'http', 'https' ) );
			}
		}
		$map = $definition['settings']['security']['akismet_fields'] ?? array();
		foreach ( array(
			'name'    => array( 'name', 'comment_author', 1000 ),
			'email'   => array( 'email', 'comment_author_email', 320 ),
			'message' => array( 'textarea', 'comment_content', 65536 ),
		) as $role => [$type, $target, $max] ) {
			$id = $map[ $role ] ?? '';
			if ( ! is_string( $id ) || '' === $id ) {
				continue;
			}foreach ( $definition['fields'] as $field ) {
				if ( $field['id'] === $id && $field['type'] === $type ) {
					$value = $values[ $id ] ?? null;
					if ( is_string( $value ) && '' !== $value ) {
						$data[ $target ] = self::text( $value, $max );
					}break;}
			}
		}
		return $data;
	}
	/**
	 * Normalize submitted text for the Akismet request.
	 *
	 * @param mixed $value Value being processed.
	 * @param int   $bytes Bytes for this operation.
	 * @return string Result for the requested operation.
	 */
	private static function text( mixed $value, int $bytes ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}$value     = wp_strip_all_tags( $value );
		$out        = '';
		$characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $characters ? $characters : array() as $char ) {
			if ( strlen( $out ) + strlen( $char ) > $bytes ) {
				break;
			}$out .= $char;
		}return $out;
	}
	/**
	 * Classify a submission as spam, legitimate, or rejected by policy.
	 *
	 * @param array  $definition Form definition being processed.
	 * @param array  $values     Values from the current submission.
	 * @param int    $form       Form for this operation.
	 * @param int    $version    Version for this operation.
	 * @param string $uuid       Uuid for this operation.
	 * @param string $hash       Hash for this operation.
	 * @param array  $server     Server for this operation.
	 */
	public static function classify( array $definition, array $values, int $form, int $version, string $uuid, string $hash, array $server ): bool|\WP_Error {
		if ( empty( $definition['settings']['security']['akismet_enabled'] ) ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT status,meta FROM {$wpdb->prefix}leenfo_entries WHERE form_id=%d AND submission_uuid=%s", $form, $uuid ) );
		if ( $existing ) {
			$meta = json_decode( (string) $existing->meta, true );
			if ( ! is_array( $meta ) || ! hash_equals( (string) ( $meta['request_hash'] ?? '' ), $hash ) ) {
				return new \WP_Error( 'leenfo_invalid', __( 'Please check the form values.', 'leenkat-forms' ), array( 'status' => 400 ) );
			}return 'spam' === $existing->status;}
		if ( ! self::ready() ) {
			return self::failure();
		}
		$key    = 'leenfo_akismet_check_' . hash_hmac( 'sha256', $form . '|' . $version . '|' . $uuid . '|' . $hash . '|' . \Akismet::get_api_key(), wp_salt( 'auth' ) );
		$cached = get_transient( $key );
		if ( 'spam' === $cached || 'ham' === $cached ) {
			return 'spam' === $cached;
		}
		$lock = $key . '_lock';if ( ! add_option( $lock, (string) ( time() + 45 ), '', false ) ) {
			$expiry = (int) get_option( $lock, 0 );
			if ( $expiry > 0 && $expiry < time() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $lock, (string) $expiry ) );
				wp_cache_delete( $lock, 'options' );}
			return new \WP_Error( 'leenfo_akismet_busy', __( 'Spam verification is in progress. Please try again.', 'leenkat-forms' ), array( 'status' => 409 ) );
		}
		try {
			$cached = get_transient( $key );
			if ( 'spam' === $cached || 'ham' === $cached ) {
				return 'spam' === $cached;
			}$data  = self::data( $definition, $values, $server );
			$result = self::guarded( static fn()=>\Akismet::comment_check( $data ), 'comment-check' );
			if ( ! is_object( $result ) || ! is_bool( $result->is_spam ?? null ) || isset( $result->error ) ) {
				return self::failure( true );
			}
			if ( ! set_transient( $key, $result->is_spam ? 'spam' : 'ham', 300 ) ) {
				return self::failure();
			}return $result->is_spam;
		} catch ( \Throwable $error ) {
			return self::failure( true );} finally {
			delete_option( $lock );}
	}
	/**
	 * Build a retryable response for an unavailable Akismet check.
	 *
	 * @param bool $service Service for this operation.
	 * @return \WP_Error Result for the requested operation.
	 */
	private static function failure( bool $service = false ): \WP_Error {
		if ( $service && is_callable( array( '\Akismet', 'get_api_key' ) ) ) {
			$key = \Akismet::get_api_key();
			if ( is_string( $key ) && '' !== $key ) {
				set_transient( 'leenfo_akismet_key_' . hash_hmac( 'sha256', $key, wp_salt( 'auth' ) ), 'unavailable', 30 );
			}
		}
		return new \WP_Error( 'leenfo_akismet_unavailable', __( 'Spam verification is temporarily unavailable. Please try again later.', 'leenkat-forms' ), array( 'status' => 503 ) );
	}
	/**
	 * Scope transport hardening to this adapter's synchronous supported API call.
	 *
	 * @param callable $call Call for this operation.
	 * @param string   $path Filesystem path to check.
	 */
	private static function guarded( callable $call, string $path ): mixed {
		$valid    = false;
		$matches  = static fn( $url )=>'rest.akismet.com' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) && wp_parse_url( $url, PHP_URL_PATH ) === '/1.1/' . $path;
		$inspect  = static function ( $response ) use ( &$valid, $path ) {
			$valid = ! is_wp_error( $response ) && is_array( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && in_array( wp_remote_retrieve_body( $response ), 'verify-key' === $path ? array( 'valid', 'invalid' ) : array( 'true', 'false' ), true ) && ! wp_remote_retrieve_header( $response, 'x-akismet-error' );
		};
		$pre      = static function ( $response, $args, $url ) use ( $matches, $inspect ) {
			if ( ! $matches( $url ) ) {
				return $response;
			}if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				return new \WP_Error( 'leenfo_akismet_tls', __( 'Secure verification unavailable.', 'leenkat-forms' ) );
			}if ( false !== $response ) {
				$inspect( $response );
			}return $response;
		};
		$response = static function ( $r, $args, $url ) use ( $matches, $inspect ) {
			if ( $matches( $url ) ) {
				$inspect( $r );
			}return $r;
		};
		$args     = static function ( $args, $url ) use ( $matches ) {
			if ( $matches( $url ) ) {
				$args = array_replace(
					$args,
					array(
						'timeout'             => 10,
						'redirection'         => 0,
						'sslverify'           => true,
						'limit_response_size' => 16384,
					)
				);
			}return $args;
		};
		$debug    = static fn()=>false;
		$request  = static function ( $data, $request_path ) use ( $path ) {
			return 'comment-check' === $path && $request_path === $path ? array_intersect_key( $data, array_flip( array( 'blog', 'blog_lang', 'blog_charset', 'user_ip', 'user_agent', 'referrer', 'comment_type', 'comment_author', 'comment_author_email', 'comment_content' ) ) ) : $data;
		};
		add_filter( 'pre_http_request', $pre, PHP_INT_MAX, 3 );
		add_filter( 'http_response', $response, PHP_INT_MAX, 3 );
		add_filter( 'http_request_args', $args, PHP_INT_MAX, 2 );
		add_filter( 'akismet_debug_log', $debug, PHP_INT_MAX );
		add_filter( 'akismet_request_args', $request, PHP_INT_MAX, 2 );
		try {
			$result = $call();
			return $valid ? $result : null;} catch ( \Throwable $e ) {
			return null;} finally {
				remove_filter( 'pre_http_request', $pre, PHP_INT_MAX );
				remove_filter( 'http_response', $response, PHP_INT_MAX );
				remove_filter( 'http_request_args', $args, PHP_INT_MAX );
				remove_filter( 'akismet_debug_log', $debug, PHP_INT_MAX );
				remove_filter( 'akismet_request_args', $request, PHP_INT_MAX );}
	}
	/**
	 * Render the Akismet privacy notice for a form.
	 *
	 * @return string Result for the requested operation.
	 */
	public static function privacyMarkup(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		return '<p class="lkf-akismet-privacy">' . esc_html__( 'This form sends selected contact details and message, your IP address, browser information and a same-site referrer to Akismet for spam checking.', 'leenkat-forms' ) . ' <a href="https://akismet.com/privacy/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn how Akismet processes this data', 'leenkat-forms' ) . '</a>.</p>';}
}
