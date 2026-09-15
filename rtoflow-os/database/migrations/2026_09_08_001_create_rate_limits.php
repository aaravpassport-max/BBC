<?php
/**
 * Migration: Atomic rate-limit counters table
 *
 * ENTERPRISE GAP FIX (Phase 9, item — "non-atomic rate-limiter fallback
 * path"): CacheBackend::increment()'s non-Redis fallback used to do a
 * plain get_transient() → PHP increment → set_transient() round trip —
 * a classic read-modify-write race: two concurrent requests can both read
 * the same pre-increment count, both compute count+1, and both write it
 * back, silently losing one increment and letting a request or two slip
 * past a limit under concurrent load on any site without Redis. This
 * table backs a real atomic counter for that fallback path: a single
 * `INSERT ... ON DUPLICATE KEY UPDATE count = count + 1` statement is
 * serialized by MySQL's own row lock, the same atomicity guarantee
 * Redis's INCR already gave the Redis-available path — see
 * CacheBackend::incrementAndGet().
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateRateLimits extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run(
            "CREATE TABLE IF NOT EXISTS {$p}rto_rate_limits (
                cache_key  VARCHAR(191) NOT NULL PRIMARY KEY,
                counter    BIGINT UNSIGNED NOT NULL DEFAULT 0,
                expires_at DATETIME NOT NULL,
                INDEX idx_expires_at(expires_at)
            ) {$c}"
        );
    }

    public function down(): void { /* Deliberate no-op */ }
}
