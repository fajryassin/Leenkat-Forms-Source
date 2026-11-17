<?php
/**
 * Validation of form-specific security controls.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/**
 * Validation of form-specific security controls.
 */
final class SecuritySettings {
	/**
	 * Return the default security settings for a form.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function defaults(): array {
		return array(
			'modern'          => true,
			'store_spam'      => true,
			'min_time'        => true,
			'seconds'         => 2,
			'captcha_enabled' => false,
			'akismet_enabled' => false,
			'akismet_fields'  => array(),
			'country_enabled' => false,
			'country_action'  => 'allow',
			'country_codes'   => array(),
			'country_message' => __( 'Sorry, this form does not accept submissions from your country.', 'leenkat-forms' ),
			'keyword_enabled' => false,
			'keyword_message' => __( "Sorry, your message can't be submitted because it contains prohibited words.", 'leenkat-forms' ),
		);}
	/**
	 * Normalize the supplied form security settings.
	 *
	 * @param array $definition Form definition being processed.
	 * @return array Result for the requested operation.
	 */
	public static function normalize( array $definition ): array {
		$defaults = self::defaults();
		if ( ! isset( $definition['settings']['security'] ) ) {
			$defaults['modern']     = false;
			$defaults['min_time']   = false;
			$defaults['store_spam'] = false;
		}return array_replace( $defaults, $definition['settings']['security'] ?? array() );
	}
	/**
	 * Validate the configured form security controls.
	 *
	 * @param mixed $value               Value being processed.
	 * @param bool  $publication         Publication for this operation.
	 * @param bool  $resolveDependencies Resolve dependencies for this operation.
	 * @return array Result for the requested operation.
	 */
	public static function validate( mixed $value, bool $publication = false, bool $resolveDependencies = true ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $resolveDependencies.
		$resolve_dependencies = $resolveDependencies; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Bind the existing PHP named argument to a local variable.
		if ( ! is_array( $value ) || ( array_is_list( $value ) && array() !== $value ) ) {
			return array( __( 'Invalid spam protection settings.', 'leenkat-forms' ) );
		}$errors = array();
		$s       = array_replace( self::defaults(), $value );
		foreach ( array( 'modern', 'store_spam', 'min_time', 'captcha_enabled', 'akismet_enabled', 'country_enabled', 'keyword_enabled' ) as $key ) {
			if ( ! is_bool( $s[ $key ] ) ) {
				$errors[] = __( 'Invalid spam protection switch.', 'leenkat-forms' );
			}
		}
		if ( $publication && $resolve_dependencies && true === $s['captcha_enabled'] && ! \LeenkatForms\Security\CaptchaSettings::ready() ) {
			$errors[] = __( 'Configure CAPTCHA in Leenkat Forms Settings before enabling it for this form.', 'leenkat-forms' );
		}
		if ( $publication && $resolve_dependencies && true === $s['akismet_enabled'] && ! \LeenkatForms\Security\AkismetService::ready() ) {
			$errors[] = __( 'Configure and activate Akismet before enabling protection for this form.', 'leenkat-forms' );
		}
		$map = $s['akismet_fields'];
		if ( ! is_array( $map ) || ( array_is_list( $map ) && array() !== $map ) || array_diff( array_keys( $map ), array( 'name', 'email', 'message' ) ) ) {
			$errors[] = __( 'Invalid Akismet field mapping.', 'leenkat-forms' );
		} else {
			foreach ( $map as $id ) {
				if ( ! is_string( $id ) || strlen( $id ) > 64 ) {
					$errors[] = __( 'Invalid Akismet field mapping.', 'leenkat-forms' );
				}
			}
		}
		if ( ! is_int( $s['seconds'] ) || $s['seconds'] < 1 || $s['seconds'] > 3600 ) {
			$errors[] = __( 'Minimum time must be between 1 and 3600 seconds.', 'leenkat-forms' );
		}
		if ( ! in_array( $s['country_action'], array( 'allow', 'deny' ), true ) ) {
			$errors[] = __( 'Invalid country filter action.', 'leenkat-forms' );
		}
		$codes = $s['country_codes'];
		if ( ! is_array( $codes ) || ! array_is_list( $codes ) || count( $codes ) > 250 ) {
			$errors[] = __( 'Invalid country selection.', 'leenkat-forms' );
		} else {
			foreach ( $codes as $code ) {
				if ( ! is_string( $code ) || ! isset( AddressField::data()['countries'][ $code ] ) ) {
					$errors[] = __( 'Invalid country selection.', 'leenkat-forms' );
					break;
				}
			}if ( count( array_unique( $codes, SORT_REGULAR ) ) !== count( $codes ) ) {
				$errors[] = __( 'Duplicate country selection.', 'leenkat-forms' );
			}
		}
		foreach ( array( 'country_message', 'keyword_message' ) as $key ) {
			if ( ! is_string( $s[ $key ] ) || strlen( $s[ $key ] ) > 1000 || '' === trim( $s[ $key ] ) ) {
				$errors[] = __( 'Enter a valid spam filter message.', 'leenkat-forms' );
			}
		}
		if ( $publication && true === $s['country_enabled'] ) {
			if ( ! $codes ) {
				$errors[] = __( 'Select at least one country.', 'leenkat-forms' );
			}if ( $resolve_dependencies && ! \LeenkatForms\Security\CountryLookup::ready() ) {
				$errors[] = __( 'The local country database is unavailable.', 'leenkat-forms' );
			}
		}
		return array_values( array_unique( $errors ) );
	}
}
