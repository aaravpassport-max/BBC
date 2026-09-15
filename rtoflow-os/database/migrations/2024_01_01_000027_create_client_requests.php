<?php
/**
 * Migration 27: Client-Initiated Cancellation/Refund Requests
 *
 * ENTERPRISE GAP FIX (Phase 1, item 2 — "No client self-service cancellation
 * or refund request"): DashboardController (client) has no cancel/refund
 * action anywhere — every cancellation or refund previously had to go
 * through a support agent first raising it on the client's behalf via the
 * admin Payments screen (Router::initiateRefund()). This migration creates
 * a genuinely separate, lightweight table for the request itself rather
 * than writing directly into rto_refunds (which models a STAFF-COMPUTED
 * refund with a known payment_id and validated amount — a client does not
 * know or choose either of those; they are asking, not deciding). Staff
 * review a pending row here and, on approval, the actual mechanism already
 * built (LeadService::updateStatus() for cancellation, or a new
 * rto_refunds row for refund) is what actually executes the change —
 * this table only ever represents the client's ASK and its review outcome,
 * never the financial/status change itself.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateClientRequests extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_client_requests (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id       BIGINT UNSIGNED NOT NULL,
            client_id     BIGINT UNSIGNED NOT NULL,
            request_type  VARCHAR(20)  NOT NULL COMMENT 'cancellation | refund',
            reason        TEXT NOT NULL,
            status        VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | declined',
            staff_note    TEXT NULL,
            refund_id     BIGINT UNSIGNED NULL COMMENT 'set if approval created a real rto_refunds row',
            handled_by    BIGINT UNSIGNED NULL,
            handled_at    DATETIME NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lead    (lead_id),
            INDEX idx_client  (client_id),
            INDEX idx_status  (status),
            INDEX idx_type    (request_type)
        ) {$c}");
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention
        // of never hard-dropping tables that may hold real request/audit data.
    }
}
