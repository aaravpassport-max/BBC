<?php
/**
 * Migration 23: Refund Rejection Support
 *
 * ROOT-CAUSE FINDING (enterprise gap audit, Section 3 — "Payments ledger has
 * no ... refund-reject path"): Router::approveRefund() could only ever move
 * a refund from 'pending' to 'approved' — there was no reject action anywhere
 * in the codebase, so a refund request admin staff decided NOT to honor had
 * no formal end state. It stayed 'pending' forever, permanently cluttering
 * every future "pending refunds" view with a request that was actually
 * already decided, and leaving the client with no notification that their
 * request was reviewed and declined.
 *
 * `rto_refunds.status` is a free VARCHAR(20) (migration 1), so no schema
 * change is needed to introduce a 'rejected' status value itself — but a
 * rejection is not decision-complete without a reason the client can be
 * told and staff can audit later, and `approved_by`/`approved_at` are named
 * for the approve path specifically. Adds:
 *   - rejection_reason : required text explaining why the refund was declined
 *   - resolved_by      : the admin who made the final call (approve OR reject)
 *   - resolved_at       : when that final call was made
 *
 * `approved_by`/`approved_at` are left in place unchanged (still populated
 * on approval, for backward compatibility with any existing report/query
 * that already reads them) — resolved_by/resolved_at are the new
 * decision-outcome-agnostic pair every future status (approved, rejected,
 * or any later addition) writes to consistently.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddRefundRejection extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}rto_refunds";

        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!in_array('rejection_reason', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN rejection_reason TEXT NULL AFTER reason");
        }
        if (!in_array('resolved_by', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN resolved_by BIGINT UNSIGNED NULL AFTER approved_at");
        }
        if (!in_array('resolved_at', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN resolved_at DATETIME NULL AFTER resolved_by");
        }
    }

    public function down(): void
    {
        // Deliberately a no-op — never hard-drop columns that may hold real
        // audit data, matching this codebase's established convention.
    }
}
