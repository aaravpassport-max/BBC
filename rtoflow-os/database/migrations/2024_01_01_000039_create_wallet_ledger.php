<?php
/**
 * Migration 39: Wallet / Referral Ledger
 *
 * ENTERPRISE GAP FIX (Phase 5, item 2 — "client referral/loyalty program"):
 * no wallet, credit, or referral mechanism existed anywhere in this
 * codebase — the pre-existing "wallet" was only a Razorpay payment-method
 * label, never a real ledger. This table is a simple append-only credit/
 * debit ledger keyed to a WP user_id (works for both clients and, in
 * principle, any future use); balance is always SUM(amount) over a user's
 * rows, never a separately-stored counter, so it can never drift out of
 * sync with its own history. ref_type/ref_id let a credit be traced back
 * to the lead that earned it (dedup guard in ReferralService::creditReferrer()
 * relies on this — see that class for why double-crediting is impossible).
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateWalletLedger extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run(
            "CREATE TABLE IF NOT EXISTS {$p}rto_wallet_ledger (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                type VARCHAR(10) NOT NULL DEFAULT 'credit',
                reason VARCHAR(100) NOT NULL DEFAULT '',
                ref_type VARCHAR(30) NULL,
                ref_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user(user_id),
                INDEX idx_ref(ref_type, ref_id)
            ) {$c}"
        );
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
