<?php
/**
 * Rate limits for public form operations.
 *
 * @package LeenkatForms
 */

declare(strict_types=1);
namespace LeenkatForms\Security;

/** Serializes each transient counter with an option-name uniqueness lock. */
final class RateLimiter {
	/**
	 * Check and account for an operation against its rate limit.
	 *
	 * @param int    $formId  Form identifier.
	 * @param string $address Address for this operation.
	 * @param int    $limit   Maximum number or size to process.
	 * @return bool Whether the operation succeeds or the condition applies.
	 */
	public static function allow( int $formId, string $address, int $limit = 20 ): bool { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		global $wpdb;
		$digest  = hash_hmac( 'sha256', $formId . '|' . $address, wp_salt( 'auth' ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve the existing PHP named parameter $formId and its method binding.
		$counter = 'leenfo_rate_' . $digest;
		$lock    = 'leenfo_rate_lock_' . $digest;
		$owned   = false;
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			if ( add_option( $lock, (string) ( time() + 5 ), '', false ) ) {
				$owned = true;
				break; }
			$expires = (int) get_option( $lock, 0 );
			if ( $expires > 0 && $expires < time() ) {
				// The option-name unique key makes lock acquisition atomic across workers.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name=%s AND option_value=%s", $lock, (string) $expires ) );
				wp_cache_delete( $lock, 'options' );
			}
			usleep( 20000 );
		}
		if ( ! $owned ) {
			return false; }
		try {
			$count = (int) get_transient( $counter );
			if ( $count >= $limit ) {
				return false; }
			return set_transient( $counter, $count + 1, 10 * MINUTE_IN_SECONDS );
		} finally {
			delete_option( $lock );
		}
	}
}
