<?php

namespace RTOFLOW\Support;

use RTOFLOW\Config\Env;

if (!defined('ABSPATH') && !defined('RTOFLOW_TESTING')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 8, item — "inconsistent cache backend
 * strategy"):
 *
 * RateLimiter::hasRedis()/get()/set()/increment()/delete() already had a
 * correct Redis-first, transient-fallback implementation, but it was
 * private to that one class. The generic app-level Cache class (used by
 * the dashboard and other services) had its own, separate implementation
 * that only ever wrote to wp_options via WordPress transients — meaning
 * under real load, dashboard/service caching silently missed the Redis
 * object cache entirely and instead added extra load to the very database
 * transients are meant to take pressure off, unless a site happened to
 * also have a WP Redis Object Cache drop-in installed (which transients
 * ride on transparently, but plenty of managed-hosting configs don't).
 *
 * This class extracts that Redis-first / transient-fallback logic into
 * one shared implementation. RateLimiter and Cache both now delegate to
 * it, so there is exactly one place that decides how a value is actually
 * stored, and any future backend (e.g. an APCu tier) only needs adding
 * once.
 */
final class CacheBackend
{
    public static function get(string $key): mixed
    {
        if (self::hasRedis()) {
            $v = self::redis()->get(self::redisKey($key));
            return $v === false ? false : self::unwrap($v);
        }
        return get_transient('rtofl_' . md5($key));
    }

    public static function set(string $key, mixed $value, int $ttl): void
    {
        if (self::hasRedis()) {
            self::redis()->set(self::redisKey($key), self::wrap($value), ['EX' => max(1, $ttl)]);
            return;
        }
        set_transient('rtofl_' . md5($key), $value, $ttl);
    }

    /** Atomic increment for integer counters (rate limiting). Returns the new count. */
    public static function increment(string $key, int $ttl): int
    {
        if (self::hasRedis()) {
            $rkey  = self::redisKey($key);
            $redis = self::redis();
            $count = $redis->incr($rkey);
            if ($count === 1) $redis->expire($rkey, $ttl);
            return (int)$count;
        }
        $current = (int)get_transient('rtofl_' . md5($key));
        $current++;
        set_transient('rtofl_' . md5($key), $current, $ttl);
        return $current;
    }

    public static function delete(string $key): void
    {
        if (self::hasRedis()) {
            self::redis()->del(self::redisKey($key));
            return;
        }
        delete_transient('rtofl_' . md5($key));
    }

    // ── Atomic counters (rate limiting) ─────────────────────────────────
    //
    // ENTERPRISE GAP FIX (Phase 9, item — "non-atomic rate-limiter fallback
    // path"): increment() above (kept for other, non-rate-limit callers of
    // this class) is a plain get_transient()/set_transient() read-modify-
    // write on its non-Redis fallback — two concurrent requests can both
    // read the same count, both compute +1, and both write it back,
    // silently losing an increment. RateLimiter now calls
    // incrementAndGet()/getCounter()/deleteCounter() instead, which are
    // atomic on BOTH backends: Redis's own INCR (already atomic) on the
    // Redis path, and a single `INSERT ... ON DUPLICATE KEY UPDATE
    // counter = counter + 1` on the fallback path — serialized by MySQL's
    // row lock on rto_rate_limits, so no increment can be lost under
    // concurrent load even without Redis. This lives in its own dedicated
    // table rather than wp_options/transients specifically so the
    // increment can be a single atomic SQL statement instead of a
    // read-then-write round trip.

    public static function incrementAndGet(string $key, int $ttl): int
    {
        if (self::hasRedis()) {
            $rkey  = self::redisKey($key);
            $redis = self::redis();
            $count = $redis->incr($rkey);
            if ($count === 1) $redis->expire($rkey, $ttl);
            return (int)$count;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rto_rate_limits';
        $now   = current_time('mysql');
        $exp   = gmdate('Y-m-d H:i:s', time() + max(1, $ttl));

        // Atomic upsert: a brand-new key (or one whose window already
        // expired) starts a fresh window at 1; an existing, still-live
        // window increments in place. Both branches are decided and
        // applied by MySQL in the same statement — no PHP-side
        // read-then-write gap for a race to land in.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (cache_key, counter, expires_at)
             VALUES (%s, 1, %s)
             ON DUPLICATE KEY UPDATE
                counter = IF(expires_at <= %s, 1, counter + 1),
                expires_at = IF(expires_at <= %s, VALUES(expires_at), expires_at)",
            $key,
            $exp,
            $now,
            $now
        ));

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT counter FROM {$table} WHERE cache_key = %s",
            $key
        ));
        return (int)$count;
    }

    public static function getCounter(string $key): int
    {
        if (self::hasRedis()) {
            $v = self::redis()->get(self::redisKey($key));
            return $v === false ? 0 : (int)$v;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'rto_rate_limits';
        $now   = current_time('mysql');
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT counter FROM {$table} WHERE cache_key = %s AND expires_at > %s",
            $key,
            $now
        ));
        return (int)$count;
    }

    public static function deleteCounter(string $key): void
    {
        if (self::hasRedis()) {
            self::redis()->del(self::redisKey($key));
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'rto_rate_limits';
        $wpdb->delete($table, ['cache_key' => $key]);
    }

    /** Prefix-flush is a transient/wp_options-only concept — falls through to Redis SCAN when active. */
    public static function flushPrefix(string $prefix): void
    {
        if (self::hasRedis()) {
            $redis = self::redis();
            $pattern = self::redisKey($prefix) . '*';
            $it = null;
            while (($keys = $redis->scan($it, $pattern, 100)) !== false) {
                if ($keys) $redis->del($keys);
                if ($it === 0 || $it === null) break;
            }
            return;
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_rto_' . $prefix . '%',
            '_transient_timeout_rto_' . $prefix . '%'
        ));
    }

    // Plain JSON, no envelope: wp_json_encode(5) === "5", which is also a
    // valid Redis INCR-compatible integer string — so a value written via
    // set() and later touched via increment() (RateLimiter's exact usage
    // pattern) stays a single consistent representation instead of two
    // incompatible ones.
    private static function wrap(mixed $v): string { return wp_json_encode($v); }
    private static function unwrap(string $raw): mixed { return json_decode($raw, true); }

    private static function redisKey(string $key): string
    {
        return Env::string('REDIS_PREFIX', 'rtoflow:') . $key;
    }

    private static ?object $redisInstance  = null;
    private static ?bool   $redisAvailable = null;

    public static function hasRedis(): bool
    {
        if (self::$redisAvailable !== null) return self::$redisAvailable;

        global $wp_object_cache;
        if (method_exists($wp_object_cache ?? new \stdClass, 'get_redis_client')) {
            self::$redisAvailable = true;
            return true;
        }

        if (class_exists('\Redis') && Env::string('REDIS_HOST', '')) {
            try {
                self::$redisInstance = new \Redis();
                self::$redisInstance->connect(
                    Env::string('REDIS_HOST', '127.0.0.1'),
                    Env::int('REDIS_PORT', 6379),
                    2.0
                );
                $password = Env::string('REDIS_PASSWORD', '');
                if ($password) self::$redisInstance->auth($password);
                self::$redisInstance->select(Env::int('REDIS_DB', 0));
                self::$redisAvailable = true;
                return true;
            } catch (\Throwable) {
                self::$redisAvailable = false;
                return false;
            }
        }

        self::$redisAvailable = false;
        return false;
    }

    private static function redis(): \Redis
    {
        if (self::$redisInstance) return self::$redisInstance;
        throw new \RuntimeException('Redis not available');
    }
}
