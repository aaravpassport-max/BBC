<?php

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

// ENTERPRISE GAP FIX (gap-analysis Section 7 — "Security/reliability/failure-
// management gaps: payouts have no maker-checker control"): PayoutsController
// ::markPaid() → PayoutService::markProcessed() already has a solid
// transactional, audited, ghost-success-guarded implementation (see that
// file's own docblock) — but it lets a SINGLE admin both decide a payout
// should be paid and confirm it was paid, with no second set of eyes. For a
// screen that moves real money to vendors, that is a real internal-fraud
// and fat-finger control gap, not a UX nicety. This migration adds the three
// columns PayoutsController's new two-step request/approve flow needs;
// nothing here changes existing payout rows' behavior (all three columns
// default to NULL/'none', so every payout already in the 'pending' or
// 'paid' state is completely unaffected).
class AddPayoutMakerChecker extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}rto_vendor_payouts";

        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!in_array('approval_status', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'none' AFTER status");
        }
        if (!in_array('requested_by', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN requested_by BIGINT UNSIGNED NULL AFTER approval_status");
        }
        if (!in_array('requested_at', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD COLUMN requested_at DATETIME NULL AFTER requested_by");
        }
    }

    public function down(): void
    {
        // Deliberate no-op — same convention as migration 23 (add_refund_rejection):
        // dropping columns on rollback risks destroying real approval-audit data
        // for payouts already processed under the new flow.
    }
}
