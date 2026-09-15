<?php
/**
 * Migration 28: SLA Breach Auto-Escalation
 *
 * ENTERPRISE GAP FIX (Phase 1, item 3 — "No automatic reassignment or
 * escalation when a vendor breaches SLA"): SLA breaches were already
 * computed and displayed (rto_leads.sla_breached, dashboards, reports) but
 * nothing ACTED on one — a breached job sat with the same vendor
 * indefinitely unless a human manually reassigned it. Bootstrap::
 * onSlaBreached() now bumps the lead's priority immediately, and a new
 * hourly check (Bootstrap::runSlaEscalationCheck(), same cron as the
 * existing SLA check) reassigns the lead to a different eligible vendor
 * after a configurable grace period. Two new columns:
 *   - escalated_at   : when the breach-triggered escalation actually fired
 *                      (not the same moment as sla_deadline — this is set
 *                      by onSlaBreached(), which itself fires from the
 *                      hourly cron, so it can trail sla_deadline by up to
 *                      an hour; the grace-period countdown starts from
 *                      THIS timestamp, not from sla_deadline, so it always
 *                      reflects a real elapsed grace window regardless of
 *                      cron timing)
 *   - sla_reassigned : set once auto-reassignment has actually run for this
 *                      breach, so the same lead is never auto-reassigned
 *                      twice for the same original breach — a SECOND
 *                      escalation (e.g. the replacement vendor also
 *                      breaches) is a fresh, separate case a human should
 *                      look at, not something this mechanism loops on
 *                      forever.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddSlaEscalation extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}rto_leads";

        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!in_array('escalated_at', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN escalated_at DATETIME NULL AFTER sla_warned");
        }
        if (!in_array('sla_reassigned', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN sla_reassigned TINYINT(1) NOT NULL DEFAULT 0 AFTER escalated_at");
        }
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
