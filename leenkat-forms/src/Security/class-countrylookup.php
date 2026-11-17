<?php
/**
 * Country lookup for normalized visitor IP addresses.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

/** Local allocation lookup. Request headers alone never establish a visitor's country. */
final class CountryLookup {
	/**
	 * Check that the local IP allocation files are available.
	 *
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function ready(): bool {
		return is_readable( LEENKAT_FORMS_DIR . 'assets/data/country-v4.dat' ) && is_readable( LEENKAT_FORMS_DIR . 'assets/data/country-v6.dat' );}
	/**
	 * Find the country code for the supplied IP address.
	 *
	 * @param string $ip Ip for this operation.
	 * @return string|null Result for the requested operation.
	 */
	public static function country( string $ip ): ?string {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return null;
		}
		if ( 16 === strlen( $packed ) && substr( $packed, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff" ) {
			$packed = substr( $packed, 12 );
		}
		if ( ! filter_var( inet_ntop( $packed ), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return null;
		}
		$width = strlen( $packed );
		$file  = LEENKAT_FORMS_DIR . 'assets/data/country-v' . ( 4 === $width ? '4' : '6' ) . '.dat';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged -- Read-only fixed-width local country data is searched using bounded native stream reads. The verified local path can disappear or an exclusive create can lose a race. The existing branch handles false without leaking a private path.
		$handle = @fopen( $file, 'rb' );
		if ( ! $handle ) {
			return null;
		}
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read-only fixed-width local country data is searched using bounded native stream reads.
			$header = fread( $handle, 10 );
			if ( 10 !== strlen( $header ) || substr( $header, 0, 6 ) !== 'LKFC' . chr( 1 ) . chr( $width ) ) {
				return null;
			}
			$count = unpack( 'N', substr( $header, 6 ) )[1];
			$size  = 2 * $width + 2;
			if ( $count < 1 || filesize( $file ) !== 10 + $count * $size ) {
				return null;
			}$low = 0;
			$high = $count - 1;
			while ( $low <= $high ) {
				$mid = intdiv( $low + $high, 2 );
				if ( 0 !== fseek( $handle, 10 + $mid * $size ) ) {
					return null;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read-only fixed-width local country data is searched using bounded native stream reads.
				}$row = fread( $handle, $size );
				if ( strlen( $row ) !== $size ) {
					return null;
				}if ( strcmp( $packed, substr( $row, 0, $width ) ) < 0 ) {
					$high = $mid - 1;
				} elseif ( strcmp( $packed, substr( $row, $width, $width ) ) > 0 ) {
					$low = $mid + 1;
				} else {
					$code = substr( $row, -2 );
					return isset( \LeenkatForms\Forms\AddressField::data()['countries'][ $code ] ) ? $code : null;}
			}
			return null;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Read-only fixed-width local country data is searched using bounded native stream reads.
			fclose( $handle );}
	}
	/**
	 * Search the address allocation data for the packed address.
	 *
	 * @param string $ip   Ip for this operation.
	 * @param string $cidr Cidr for this operation.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function inRange( string $ip, string $cidr ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$pieces  = explode( '/', $cidr );
		$address = inet_pton( $pieces[0] );
		$value   = inet_pton( $ip );
		if ( false === $address || false === $value || strlen( $address ) !== strlen( $value ) ) {
			return false;
		}$bits = $pieces[1] ?? (string) ( strlen( $address ) * 8 );
		if ( ! ctype_digit( $bits ) || (int) $bits > strlen( $address ) * 8 ) {
			return false;
		}$bytes    = intdiv( (int) $bits, 8 );
		$remainder = (int) $bits % 8;
		return substr( $value, 0, $bytes ) === substr( $address, 0, $bytes ) && ( ! $remainder || ( ord( $value[ $bytes ] ) & ( 255 << ( 8 - $remainder ) ) ) === ( ord( $address[ $bytes ] ) & ( 255 << ( 8 - $remainder ) ) ) );
	}
	/**
	 * Resolve the visitor address from the request and proxy configuration.
	 *
	 * @param array $server Server for this operation.
	 * @return string Result for the requested operation.
	 */
	public static function clientIp( array $server ): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Preserve the existing callable method name for PHP callers and callback compatibility.
		$peer = is_string( $server['REMOTE_ADDR'] ?? null ) ? $server['REMOTE_ADDR'] : '';
		if ( ! filter_var( $peer, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$configured = defined( 'LEENKAT_FORMS_TRUSTED_PROXIES' ) ? LEENKAT_FORMS_TRUSTED_PROXIES
			: ( defined( 'LKF_TRUSTED_PROXIES' ) ? LKF_TRUSTED_PROXIES : array() );
		$trusted    = is_array( $configured ) ? $configured : array();
		$is_trusted = static fn( string $ip ): bool=> (bool) array_filter( $trusted, static fn( $cidr ): bool=>is_string( $cidr ) && self::inRange( $ip, $cidr ) );
		if ( ! $is_trusted( $peer ) ) {
			return $peer;
		}$forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
		if ( ! is_string( $forwarded ) || strlen( $forwarded ) > 2048 ) {
			return $peer;
		}
		$chain   = explode( ',', $forwarded );
		$chain[] = $peer;for ( $i = count( $chain ) - 1;$i >= 0;$i-- ) {
			$address = trim( $chain[ $i ] );
			if ( ! filter_var( $address, FILTER_VALIDATE_IP ) ) {
				return $peer;
			}if ( ! $is_trusted( $address ) ) {
				return $address;
			}
		}return $peer;
	}
}
