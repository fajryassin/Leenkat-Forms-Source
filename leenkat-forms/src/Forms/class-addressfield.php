<?php
/**
 * Address field parts, options and labels.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Forms;

/**
 * Address field parts, options and labels.
 */
final class AddressField {
	/**
	 * Load the bundled address field lookup data.
	 *
	 * @return array Result for the requested operation.
	 */
	public static function data(): array {
		return require __DIR__ . '/address-data.php'; }
	/**
	 * Return the address components for the chosen configuration.
	 *
	 * @param array $settings Settings used by the current operation.
	 * @return array Result for the requested operation.
	 */
	public static function parts( array $settings ): array {
		$parts = array( 'address1', 'address2', 'city', 'state', 'postal' );
		if ( ( $settings['address_scheme'] ?? 'us' ) === 'international' ) {
			$parts[] = 'country'; }
		return array_values( array_filter( $parts, static fn( string $part ): bool => ! in_array( $part, array( 'address2', 'postal', 'country' ), true ) || empty( $settings['address_parts'][ $part ]['hidden'] ) ) );
	}
	/**
	 * Build the display labels for the address components.
	 *
	 * @param array $settings Settings used by the current operation.
	 * @return array Result for the requested operation.
	 */
	public static function captions( array $settings ): array {
		$us = ( $settings['address_scheme'] ?? 'us' ) === 'us';
		return array(
			'address1' => __( 'Address Line 1', 'leenkat-forms' ),
			'address2' => __( 'Address Line 2', 'leenkat-forms' ),
			'city'     => __( 'City', 'leenkat-forms' ),
			'state'    => $us ? __( 'State', 'leenkat-forms' ) : __( 'State / Province / Region', 'leenkat-forms' ),
			'postal'   => $us ? __( 'Zip Code', 'leenkat-forms' ) : __( 'Postal Code', 'leenkat-forms' ),
			'country'  => __( 'Country', 'leenkat-forms' ),
		);
	}
	/**
	 * Return the available address field configuration options.
	 *
	 * @param string $part     Part for this operation.
	 * @param array  $settings Settings used by the current operation.
	 * @return array Result for the requested operation.
	 */
	public static function options( string $part, array $settings ): array {
		$data = self::data();
		return 'country' === $part ? $data['countries'] : ( 'state' === $part && ( $settings['address_scheme'] ?? 'us' ) === 'us' ? $data['states'] : array() );
	}
}
