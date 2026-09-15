<?php

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (gap-analysis Section 7 — "Security/reliability/
 * failure-management gaps: webhook delivery has no retry queue").
 *
 * WebhookDispatchService's own docblock already named this exact gap as a
 * deliberate, documented scope exclusion: "no retry/backoff queue — a
 * failed delivery is logged, not retried." For a subscriber's endpoint
 * having one bad minute (a deploy, a transient DNS blip, a 502 from their
 * load balancer), that means the event is simply lost forever — the
 * subscriber's system silently falls out of sync with RTOFLOW with no way
 * to recover except a manual replay, which doesn't exist either.
 *
 * Adds the three columns a real retry queue needs directly to the existing
 * rto_webhook_delivery_log table (rather than a new table) — a retry is
 * conceptually still "the record of one delivery attempt", just one that
 * isn't finished yet; reusing the table keeps the full attempt history
 * (including every retry) queryable in one place.
 */
class AddWebhookRetryQueue extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}rto_webhook_delivery_log";

        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!in_array('retry_count', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER error_message");
        }
        if (!in_array('next_retry_at', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN next_retry_at DATETIME NULL AFTER retry_count");
        }
        if (!in_array('queue_status', $existing, true)) {
            // 'final' = success, or a failure with no more retries scheduled
            // (either exhausted or was never retryable to begin with —
            // matches every existing row after this migration runs, via the
            // default). 'pending_retry' = will be re-attempted by the cron
            // below. 'exhausted' = retried the max number of times and still
            // failing — needs a human, surfaced in the Webhooks admin screen.
            $this->run("ALTER TABLE {$table} ADD COLUMN queue_status VARCHAR(20) NOT NULL DEFAULT 'final' AFTER next_retry_at");
        }
        if (!in_array('payload', $existing, true)) {
            // A retry must resend the EXACT original request body, not a
            // freshly recomputed one — the underlying lead/vendor/payment
            // row this event describes may have changed state again by the
            // time the retry fires, and the subscriber must receive the
            // event as it was at the moment it actually happened.
            $this->run("ALTER TABLE {$table} ADD COLUMN payload LONGTEXT NULL AFTER queue_status");
        }

        // Every row that predates this migration is, by definition, already
        // resolved (there was no retry mechanism when it was written) — the
        // DEFAULT 'final' above already gives them the correct value with no
        // backfill UPDATE needed.
    }

    public function down(): void
    {
        // Deliberate no-op — matches this codebase's established convention
        // (migrations 23/24) of never hard-dropping columns that may hold
        // real operational history.
    }
}
