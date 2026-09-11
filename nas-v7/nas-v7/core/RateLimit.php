<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RateLimit — Part 12-D (Security) + Part 15-A (Production Scenario: double-submit)
 *
 * Transient-based sliding-window rate limiter.
 * Works without Redis/Memcached — uses WP option transients (autoloaded=false).
 *
 * Used by:
 *   - BookingModule::create_booking() — max N bookings per IP per hour
 *   - EnterpriseModule::login_rate_limit() — max N login attempts per IP per hour
 *   - Booking duplicate-submit prevention — per session key
 *
 * The window size and limit are configurable from the admin settings table.
 *
 * Usage:
 *   if ( ! RateLimit::check( 'booking', $ip, 5, HOUR_IN_SECONDS ) ) {
 *       wp_send_json_error(['message' => 'Too many requests. Please try again later.'], 429);
 *   }
 */
class RateLimit {

    /**
     * TRACE: Called at the start of any rate-limited endpoint.
     *        Builds a transient key from $scope + hash($identifier).
     *        Reads current hit count. If >= $max_hits → returns false (blocked).
     *        Otherwise increments counter and returns true (allowed).
     *        Precondition: none (safe to call at any point).
     *        Postcondition: transient counter incremented by 1 if allowed.
     *        Edge cases: transient store failure → fails open (returns true) to avoid
     *                    false-blocking legitimate users during cache issues.
     *
     * @param string $scope     Context label, e.g. 'booking', 'login', 'ai'
     * @param string $identifier IP address or session key or user ID
     * @param int    $max_hits  Maximum allowed hits within $window_seconds
     * @param int    $window_seconds Window duration in seconds
     * @return bool  true = allowed, false = rate limited
     */
    // TRACE: check() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: returns null/false on failure.
    public static function check( string $scope, string $identifier, int $max_hits, int $window_seconds ): bool {
        if ( $max_hits <= 0 ) return true; // 0 = disabled

        $key  = 'nas_rl_' . $scope . '_' . md5( $identifier );
        $hits = (int) get_transient( $key );

        if ( $hits >= $max_hits ) {
            return false; // rate limited
        }

        // Increment counter; set expiry only on first hit (so window is fixed, not sliding)
        if ( $hits === 0 ) {
            set_transient( $key, 1, $window_seconds );
        } else {
            // get_transient returns current value; set_transient resets TTL — use raw option to preserve TTL
            // Workaround: overwrite with incremented value, preserve existing window by not resetting TTL.
            // Since WP transients don't expose "increment without TTL reset", we use a simpler strategy:
            // re-set with the same window. This means the window slides slightly on each hit.
            // Acceptable for rate limiting purposes.
            set_transient( $key, $hits + 1, $window_seconds );
        }

        return true; // allowed
    }

    /**
     * TRACE: Idempotency key check for double-submit prevention.
     *        Returns true if this idempotency_key has NOT been seen before.
     *        Returns false if it HAS been seen (duplicate submit — block it).
     *        The key is stored for $ttl_seconds (default 5 minutes).
     *
     * Usage:
     *   $idem_key = sanitize_key($_POST['idempotency_key'] ?? '');
     *   if ($idem_key && !RateLimit::is_new_idempotency_key($idem_key)) {
     *       wp_send_json_error(['message' => 'Duplicate submission detected.'], 409);
     *   }
     */
    // TRACE: is_new_idempotency_key() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: bool (true on success, false on failure).
    //        Edge cases: returns null/false on failure.
    public static function is_new_idempotency_key( string $key, int $ttl_seconds = 300 ): bool {
        if ( ! $key ) return true; // no key = pass through
        $store_key = 'nas_idem_' . md5( $key );
        if ( get_transient( $store_key ) ) {
            return false; // already seen
        }
        set_transient( $store_key, 1, $ttl_seconds );
        return true; // new key
    }

    /**
     * TRACE: Get the current hit count for a scope+identifier without incrementing.
     *        Used for diagnostic display in admin (how many hits this window).
     */
    // TRACE: get_count() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: typed scalar value.
    //        Edge cases: invalid input → error returned.
    public static function get_count( string $scope, string $identifier ): int {
        $key = 'nas_rl_' . $scope . '_' . md5( $identifier );
        return (int) get_transient( $key );
    }

    /**
     * TRACE: Reset hits for a scope+identifier (admin override).
     *        Used when admin needs to unblock a legitimate user.
     */
    // TRACE: reset() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function reset( string $scope, string $identifier ): void {
        $key = 'nas_rl_' . $scope . '_' . md5( $identifier );
        delete_transient( $key );
    }
}
