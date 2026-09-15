<?php
/**
 * Migration 5: Fix Complaints and Vendor Payout Column Mismatches
 *
 * ComplaintsController::store() inserts into 'category' and 'body' but the
 * schema has 'complaint_type' and 'description'. ComplaintsController::respond()
 * updates 'responded_by' and 'updated_at' which don't exist.
 *
 * PayoutsController::markPaid() updates 'payment_ref', 'payment_method',
 * 'paid_by' which don't exist in rto_vendor_payouts (schema only has 'utr_number').
 *
 * This migration adds the missing columns AND updates ComplaintsController
 * references (handled in PHP fixes). The schema is extended rather than
 * renamed because existing data uses the original column names.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class FixComplaintsAndPayoutColumns extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        // ── rto_complaints: add missing operational columns ───────────────

        // 'category' alias: ComplaintsController uses 'category' but schema has
        // 'complaint_type'. Add category column — both are kept so old data is safe.
        $this->addColumn($p . 'rto_complaints', 'category',
            "VARCHAR(100) NULL DEFAULT 'general' COMMENT 'Alias for complaint_type used by store()'");

        // 'body' alias: ComplaintsController uses 'body' but schema has 'description'.
        // Add body column; backfill from description for existing rows.
        $this->addColumn($p . 'rto_complaints', 'body',
            'LONGTEXT NULL COMMENT "Alias for description used by client store()"');

        // Backfill body from description for existing rows
        $wpdb->query("UPDATE {$p}rto_complaints SET body = description WHERE body IS NULL AND description != ''");

        // 'responded_by': staff user who responded (used by respond())
        $this->addColumn($p . 'rto_complaints', 'responded_by',
            'BIGINT UNSIGNED NULL AFTER responded_at');

        // 'updated_at': standard mutation timestamp
        $this->addColumn($p . 'rto_complaints', 'updated_at',
            'DATETIME NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

        // ── rto_vendor_payouts: add missing payout tracking columns ───────

        // 'payment_ref': PayoutsController uses 'payment_ref'; schema only has 'utr_number'.
        // Keep both: payment_ref is the admin-entered reference; utr_number is the bank UTR.
        $this->addColumn($p . 'rto_vendor_payouts', 'payment_ref',
            "VARCHAR(100) NULL COMMENT 'Admin-entered payment reference / UTR' AFTER utr_number");

        // Backfill payment_ref from utr_number for existing paid rows
        $wpdb->query("UPDATE {$p}rto_vendor_payouts SET payment_ref = utr_number WHERE payment_ref IS NULL AND utr_number IS NOT NULL");

        // 'payment_method': how the payout was made (bank_transfer, upi, cheque, etc.)
        $this->addColumn($p . 'rto_vendor_payouts', 'payment_method',
            "VARCHAR(50) NULL DEFAULT 'bank_transfer' AFTER payment_ref");

        // 'paid_by': admin user who marked as paid
        $this->addColumn($p . 'rto_vendor_payouts', 'paid_by',
            'BIGINT UNSIGNED NULL AFTER paid_at');

        // 'tds_rate': payout-level TDS rate (already added to rto_payments in migration 3,
        // add here for payouts so each payout stores the rate used at generation time)
        $this->addColumn($p . 'rto_vendor_payouts', 'tds_rate',
            'DECIMAL(5,2) NOT NULL DEFAULT 1.00 AFTER tds_amount');

        update_option('rtoflow_migration_fix_complaints_payouts', '1.0');
    }

    public function down(): void
    {
        // Columns intentionally not dropped on rollback to preserve data.
    }

    protected function addColumn(string $table, string $col, string $definition): void
    {
        global $wpdb;
        $exists = $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
        if (!$exists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$definition}");
        }
    }
}
