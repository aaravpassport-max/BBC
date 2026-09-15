<?php
/**
 * Migration 29: Vendor Bank Account Verification (Penny-Drop)
 *
 * ENTERPRISE GAP FIX (Phase 1, item 5 — "No penny-drop / bank account
 * verification for vendors"): vendor bank account + IFSC were accepted and
 * encrypted (VendorsController::store(), Encryption) but never validated
 * against a real account before payouts were sent to it — a typo or fraud
 * risk sent real money to the wrong account with no verification gate.
 * BankVerificationService now integrates Razorpay's real Fund Account
 * Validation API (the standard "penny-drop" mechanism: a small real
 * transfer + reversal, or an equivalent instant name-match check,
 * confirming the account exists and is in the vendor's name) at the point
 * a vendor's bank details are added, and PayoutService blocks payout
 * eligibility until bank_verification_status = 'verified'.
 *
 * Verification with a real bank is inherently ASYNCHRONOUS — Razorpay
 * itself confirms a validation via a webhook event
 * (fund_account.validation.completed/.failed), not a synchronous API
 * response — hence 'pending' as a real, expected in-between state, not
 * just an error state.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddVendorBankVerification extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}rto_vendors";

        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        $columns = [
            'bank_verification_status' => "VARCHAR(20) NOT NULL DEFAULT 'unverified' COMMENT 'unverified | pending | verified | failed'",
            'bank_verified_at'         => "DATETIME NULL",
            'bank_verification_note'   => "TEXT NULL",
            'razorpay_contact_id'      => "VARCHAR(100) NULL",
            'razorpay_fund_account_id' => "VARCHAR(100) NULL",
        ];
        $after = 'bank_details_enc';
        foreach ($columns as $col => $def) {
            if (!in_array($col, $existing, true)) {
                $this->run("ALTER TABLE {$table} ADD COLUMN {$col} {$def} AFTER {$after}");
                $after = $col;
            }
        }

        if (!in_array('bank_verification_status', $existing, true)) {
            $this->run("ALTER TABLE {$table} ADD INDEX idx_bank_verification (bank_verification_status)");
        }
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
