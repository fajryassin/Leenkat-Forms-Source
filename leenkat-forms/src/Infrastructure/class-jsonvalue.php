<?php
/**
 * Read JSON values in the plugin's existing storage format.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);

namespace LeenkatForms\Infrastructure;

/**
 * Readers for JSON values stored in plugin tables.
 *
 * @internal
 */
final class JsonValue {
	/**
	 * Decode stored data with the historical empty fallback.
	 *
	 * Truthy scalars retain their existing value. Invalid JSON and falsy values
	 * return an empty array.
	 *
	 * @param mixed $encoded Stored JSON passed to the native decoder.
	 * @return mixed Decoded value or an empty array.
	 */
	public static function decode_or_empty( mixed $encoded ): mixed {
		$decoded = json_decode( $encoded, true );

		return $decoded ? $decoded : array();
	}
}
