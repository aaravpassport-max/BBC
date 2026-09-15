<?php
/**
 * Migration 43: Side-Effect-Incomplete Flag on Leads
 *
 * ENTERPRISE GAP FIX (Phase 7, item 3 — "Workflow engine side effects have
 * no error handling or rollback"): WorkflowEngineService::runSideEffects()
 * already wraps each side effect in its own try/catch (see that method's
 * docblock) and returns a per-action success/failure array — but the only
 * caller that read that array, LeadService::updateStatus(), only wrote the
 * failure to the PHP error log. There was no record ANYWHERE in the app
 * itself that a transition's side effects were incomplete, so staff had no
 * way to discover — without tailing server logs — that (for example) a
 * status-change notification silently failed to send. Adds:
 *   - side_effects_incomplete : 1 when the most recent transition on this
 *                                lead had at least one failing side effect
 *   - side_effects_failure_note : which action(s) failed and why, for the
 *                                  staff member who clears the flag to see
 *                                  exactly what to follow up on manually
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddSideEffectsIncomplete extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}rto_leads";

        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!in_array('side_effects_incomplete', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN side_effects_incomplete TINYINT NOT NULL DEFAULT 0 AFTER sla_breached");
        }
        if (!in_array('side_effects_failure_note', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN side_effects_failure_note TEXT NULL AFTER side_effects_incomplete");
        }
    }

    public function down(): void
    {
        // Deliberate no-op — never hard-drop columns that may hold real
        // audit data, matching this codebase's established convention.
    }
}
