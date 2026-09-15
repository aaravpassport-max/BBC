<?php

namespace RTOFLOW\Http\Middleware;

use RTOFLOW\Config\Env;

if (!defined('ABSPATH')) exit;

/**
 * Rate Limiter
 *
 * Limits requests by IP address (and optionally user ID) using a sliding window.
 * Uses Redis when available, falls back to WordPress transients (database).
 *
 * Usage:
 *   // Check and increment — returns false if limit exceeded
 *   if (!RateLimiter::check('public', $ip)) {
 *       http_response_code(429);
 *       wp_send_json_error(['message' => 'Too many requests. Please wait.'], 429);
 *   }
 *
 *   // For payment endpoints (stricter):
 *   RateLimiter::check('payment', $ip, 10, 60); // 10 per minute
 */
final class RateLimiter
{
    // Pre-defined limit groups (requests, window_seconds)
    private const LIMITS = [
        'public'   => [60,  60],   // 60 req/min for public pages
        'auth'     => [300, 60],   // 300 req/min for authenticated users
        'submit'   => [5,   300],  // 5 form submissions per 5 minutes
        'payment'  => [10,  60],   // 10 payment attempts per minute
        'upload'   => [20,  300],  // 20 uploads per 5 minutes
        'api'      => [120, 60],   // 120 API calls per minute
        'login'    => [10,  300],  // 10 login attempts per 5 minutes
        'otp'      => [5,   600],  // 5 OTP requests per 10 minutes
        'admin'    => [500, 60],   // 500 req/min for admin
    ];

    // ── Main check ────────────────────────────────────────────────────────

    /**
     * Check rate limit. Returns true if request is allowed, false if blocked.
     * Increments the counter on each call.
     */
    public static function check(
        string $group,
        string $identifier = '',
        int $limit = 0,
        int $window = 0
    ): bool {
        [$defaultLimit, $defaultWindow] = self::LIMITS[$group] ?? [60, 60];
        $limit  = $limit  ?: (int)Env::int('RATE_LIMIT_' . strtoupper($group), $defaultLimit);
        $window = $window ?: $defaultWindow;

        $identifier = $identifier ?: self::clientIp();
        $key        = 'rl:' . $group . ':' . hash('xxh32', $identifier);

        // ENTERPRISE GAP FIX (Phase 9, item — "non-atomic rate-limiter
        // fallback path"): this used to be a "read the count, then branch
        // into set() or increment()" sequence — a read-modify-write race
        // on the non-Redis fallback that could let a request or two slip
        // past the limit under concurrent load, since two requests could
        // both read the same pre-increment count before either wrote back.
        // A single incrementAndGet() call now does the read, the
        // first-request initialization, and the increment as one atomic
        // operation on both backends (Redis INCR, or a single UPSERT
        // statement serialized by MySQL's row lock on the fallback path)
        // — see CacheBackend::incrementAndGet().
        $count = self::increment($key, $window);

        if ($count > $limit) {
            // Log excessive rate limiting (potential attack)
            if ($count === $limit + 1 || $count % 50 === 0) {
                error_log("RTOFLOW RateLimiter: Limit reached [{$group}] for [{$identifier}]: {$count}/{$limit}");
            }
            return false;
        }

        return true;
    }

    /**
     * Check without incrementing (for pre-flight checks)
     */
    public static function peek(string $group, string $identifier = ''): int
    {
        $identifier = $identifier ?: self::clientIp();
        $key        = 'rl:' . $group . ':' . hash('xxh32', $identifier);
        return self::get($key);
    }

    /**
     * Reset the counter for a specific key (after successful auth, etc.)
     */
    public static function reset(string $group, string $identifier = ''): void
    {
        $identifier = $identifier ?: self::clientIp();
        $key        = 'rl:' . $group . ':' . hash('xxh32', $identifier);
        self::delete($key);
    }

    /**
     * Send a 429 response and exit.
     */
    public static function abort(string $message = 'Too many requests. Please slow down.'): never
    {
        status_header(429);
        header('Retry-After: 60');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            wp_send_json_error(['message' => $message, 'code' => 'rate_limited'], 429);
        }

        // HTML response for browser visitors
        echo '<!DOCTYPE html><html><head><title>429 Too Many Requests</title></head><body>';
        echo '<h1>Too Many Requests</h1><p>' . esc_html($message) . '</p>';
        echo '<p>Please wait a moment before trying again.</p></body></html>';
        exit;
    }

    // ENTERPRISE GAP FIX (Phase 11, item — "No per-vendor or per-city
    // fairness in rate limiting"): every group above is a single global
    // bucket keyed only by the caller-supplied $identifier (typically an
    // IP, an API key, or a user id) — nothing stops one very high-volume
    // vendor or one very high-volume city's worth of traffic from
    // consuming the entire shared allowance and starving every other
    // vendor/city sharing the same endpoint, since they are not separate
    // buckets at all today. checkFair() adds a genuinely separate,
    // per-scope bucket ON TOP OF (not instead of) the existing global
    // check: a caller does both `check($group, ip)` for the normal global/
    // abuse ceiling AND `checkFair('vendor', $vendorId)` /
    // `checkFair('city', $cityId)` for a much higher per-entity ceiling
    // that exists purely to guarantee no single vendor or city can starve
    // the others — two independent buckets, both must pass.
    private const FAIR_SHARE_LIMITS = [
        // scope => [requests, window_seconds] per individual vendor/city
        'vendor' => [120, 60],   // one vendor: 120 actions/min
        'city'   => [300, 60],   // one city's worth of submissions: 300/min
    ];

    /**
     * Per-entity fair-share check, independent of and additional to the
     * global check($group, ...) bucket. $scope is 'vendor' or 'city';
     * $entityId is the vendor's or city's numeric id. Returns true if this
     * one entity is still within ITS OWN allowance — a busy vendor/city
     * hitting this limit does not touch or consume any other vendor's or
     * city's bucket, so it cannot starve them of shared capacity.
     */
    public static function checkFair(string $scope, int $entityId): bool
    {
        if ($entityId <= 0) return true; // nothing to scope by — caller falls back to the global check alone
        [$limit, $window] = self::FAIR_SHARE_LIMITS[$scope] ?? [120, 60];
        $key = 'rlfair:' . $scope . ':' . $entityId;
        return self::increment($key, $window) <= $limit;
    }

    // ── Client IP detection ───────────────────────────────────────────────

    public static function clientIp(): string
    {
        // Check trusted proxy headers first (for CDN / load balancer setups)
        $trustedProxies = Env::string('TRUSTED_PROXIES', '');
        $remoteAddr     = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($trustedProxies && self::isFromTrustedProxy($remoteAddr, $trustedProxies)) {
            // Check Cloudflare header first
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
            // X-Forwarded-For (first IP only)
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
                $ip  = $ips[0];
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $remoteAddr ?: '0.0.0.0';
    }

    private static function isFromTrustedProxy(string $ip, string $proxies): bool
    {
        foreach (array_map('trim', explode(',', $proxies)) as $proxy) {
            if ($ip === $proxy) return true;
            // Basic CIDR check
            if (str_contains($proxy, '/') && self::ipInCidr($ip, $proxy)) return true;
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) return false;
        $maskLong = -1 << (32 - (int)$mask);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    // ── Storage backend (Redis → transient fallback) ───────────────────────
    //
    // ENTERPRISE GAP FIX (Phase 8, item — "inconsistent cache backend
    // strategy"): this class used to carry its own private copy of the
    // Redis-first/transient-fallback logic. That logic is now shared with
    // the generic app-level Cache class via \RTOFLOW\Support\CacheBackend
    // (see its class docblock) — RateLimiter delegates to it here instead
    // of duplicating it, so there is exactly one implementation to keep
    // correct.

    // ENTERPRISE GAP FIX (Phase 9, item — "non-atomic rate-limiter fallback
    // path"): these three now delegate to CacheBackend's dedicated atomic
    // counter API (incrementAndGet/getCounter/deleteCounter) instead of
    // its general get/set/increment, which raced on the non-Redis
    // fallback — see CacheBackend's "Atomic counters" section.

    private static function get(string $key): int
    {
        return \RTOFLOW\Support\CacheBackend::getCounter($key);
    }

    private static function increment(string $key, int $ttl): int
    {
        return \RTOFLOW\Support\CacheBackend::incrementAndGet($key, $ttl);
    }

    private static function delete(string $key): void
    {
        \RTOFLOW\Support\CacheBackend::deleteCounter($key);
    }
}
