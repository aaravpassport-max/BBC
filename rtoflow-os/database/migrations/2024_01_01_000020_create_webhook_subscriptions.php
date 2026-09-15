<?php
/**
 * Migration 20: Outbound Webhook Subscriptions + Delivery Log
 *
 * ROOT-CAUSE FINDING: "API/webhooks integration not started" — nothing in
 * this codebase currently lets an external system register for, or
 * receive, real lead lifecycle events. Events already exist internally
 * (do_action('rtoflow_lead_created', ...), do_action('rtoflow_lead_status_changed', ...),
 * the EventBus, AutomationService) but none of them ever leave the
 * process. This migration adds the storage half of a scoped outbound
 * webhook mechanism (see WebhookDispatchService for the dispatch half):
 *
 *   rto_webhook_subscriptions — one row per registered target:
 *     - event_type : the dot-namespaced event this subscription listens
 *                    for (e.g. 'lead.created', 'lead.status_changed') —
 *                    matches the same event-name convention
 *                    AutomationService/EventBus already use elsewhere in
 *                    this codebase (see AutomationController::VALID_EVENTS),
 *                    not a new taxonomy invented for this table.
 *     - target_url : the subscriber's HTTPS endpoint. Validated with
 *                    Sanitiser::url() at write time (WebhookController),
 *                    stored as-is.
 *     - secret     : shared HMAC signing secret, generated server-side
 *                    (random_bytes) at creation and never displayed again
 *                    after creation — same "generate once, show once"
 *                    posture as every other credential this codebase
 *                    handles. Used by WebhookDispatchService to compute a
 *                    per-delivery HMAC-SHA256 signature of the JSON body.
 *     - is_active  : soft on/off switch, so a subscription can be paused
 *                    without losing its secret/history.
 *
 *   rto_webhook_delivery_log — an append-only record of every delivery
 *   ATTEMPT (success or failure), one row per attempt, so a failed
 *   delivery is visible without needing external log access. This is
 *   deliberately a dedicated table rather than overloading rto_logs
 *   (AuditService's table): delivery attempts are high-volume,
 *   webhook-specific, operational telemetry (HTTP status code, response
 *   snippet, timing) — a different shape and a different retention
 *   concern than the audit trail's compliance-grade action log.
 *   WebhookDispatchService also mirrors a summary of each attempt into
 *   AuditService, matching how every other side-effecting action in this
 *   codebase is recorded there.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, matching every other table
 * migration in this set.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateWebhookSubscriptions extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_webhook_subscriptions (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_type   VARCHAR(100) NOT NULL,
            target_url   VARCHAR(500) NOT NULL,
            secret       VARCHAR(128) NOT NULL,
            is_active    TINYINT(1) NOT NULL DEFAULT 1,
            created_at   DATETIME NOT NULL,
            updated_at   DATETIME NULL,
            KEY idx_event_active (event_type, is_active)
        ) {$c};");

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_webhook_delivery_log (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            subscription_id BIGINT UNSIGNED NOT NULL,
            event_type      VARCHAR(100) NOT NULL,
            target_url      VARCHAR(500) NOT NULL,
            success         TINYINT(1) NOT NULL DEFAULT 0,
            response_code   INT NULL,
            error_message   VARCHAR(500) NULL,
            attempted_at    DATETIME NOT NULL,
            KEY idx_subscription (subscription_id),
            KEY idx_event_type (event_type)
        ) {$c};");
    }

    public function down(): void
    {
        // Deliberately a no-op — matches this codebase's convention of never
        // hard-dropping a table on rollback (see migration 18/19's docblocks).
    }
}
