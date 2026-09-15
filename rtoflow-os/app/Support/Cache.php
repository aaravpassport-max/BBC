<?php

namespace RTOFLOW\Support;

if (!defined('ABSPATH')) exit;

/**
 * Cache Layer
 *
 * Two-level cache: in-memory local array (zero DB hits for same request)
 * backed by CacheBackend for cross-request persistence.
 *
 * ENTERPRISE GAP FIX (Phase 8, item — "inconsistent cache backend
 * strategy"): this used to always call get_transient()/set_transient()
 * directly — i.e. always wp_options/DB-backed — while RateLimiter had its
 * own separate Redis-first logic. Both now go through the same
 * CacheBackend, so this class gets the Redis object cache automatically
 * whenever one is configured/available, with the exact same transient
 * fallback as before when it isn't. See CacheBackend's class docblock.
 */
class Cache
{
    private array $local = [];

    public function remember(string $key, int $ttl, callable $cb): mixed
    {
        if (isset($this->local[$key])) return $this->local[$key];

        $v = CacheBackend::get('rto_' . $key);
        if ($v !== false) {
            $this->local[$key] = $v;
            return $v;
        }

        $v = $cb();
        $this->set($key, $v, $ttl);
        return $v;
    }

    public function set(string $key, mixed $v, int $ttl = 300): void
    {
        $this->local[$key] = $v;
        CacheBackend::set('rto_' . $key, $v, $ttl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset($this->local[$key])) return $this->local[$key];
        $v = CacheBackend::get('rto_' . $key);
        if ($v !== false) {
            $this->local[$key] = $v;
            return $v;
        }
        return $default;
    }

    public function forget(string $key): void
    {
        unset($this->local[$key]);
        CacheBackend::delete('rto_' . $key);
    }

    public function flush_prefix(string $prefix): void
    {
        CacheBackend::flushPrefix('rto_' . $prefix);
        foreach (array_keys($this->local) as $k) {
            if (str_starts_with($k, $prefix)) unset($this->local[$k]);
        }
    }
}
