<?php
declare(strict_types=1);
namespace LeenkatForms\Security;

/** Serializes each transient counter with an option-name uniqueness lock. */
final class RateLimiter {
    public static function allow(int $formId, string $address, int $limit = 20): bool {
        global $wpdb;
        $digest = hash_hmac('sha256', $formId . '|' . $address, wp_salt('auth'));
        $counter = 'lkf_rate_' . $digest;
        $lock = 'lkf_rate_lock_' . $digest;
        $owned = false;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if (add_option($lock, (string)(time() + 5), '', false)) { $owned = true; break; }
            $expires = (int)get_option($lock, 0);
            if ($expires > 0 && $expires < time()) {
                // The option-name unique key makes lock acquisition atomic across workers.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller.
                $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name=%s AND option_value=%s", $lock, (string)$expires));
                wp_cache_delete($lock, 'options');
            }
            usleep(20000);
        }
        if (!$owned) { return false; }
        try {
            $count = (int)get_transient($counter);
            if ($count >= $limit) { return false; }
            return set_transient($counter, $count + 1, 10 * MINUTE_IN_SECONDS);
        } finally {
            delete_option($lock);
        }
    }
}
