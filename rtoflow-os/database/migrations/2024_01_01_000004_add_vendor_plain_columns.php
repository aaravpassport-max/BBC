<?php
/**
 * Migration 4: Add Missing Vendor Plain-Text Columns
 *
 * The original migration (000001) created encrypted columns (bank_details_enc,
 * pan_enc, aadhaar_hash) for sensitive vendor data, but the application code
 * in VendorsController, VendorService, and Repositories was written expecting
 * individual plain-text columns (pan, aadhaar, address, bank_name, bank_account,
 * bank_ifsc) and a plain-JSON bank_details column.
 *
 * This migration adds the missing plain-text columns so the application code works
 * correctly. The encrypted columns (bank_details_enc, pan_enc, aadhaar_hash) are
 * preserved for future use if encryption is enabled via the Encryption class.
 *
 * Also adds the `email` column to rto_vendors which is used by VendorsController
 * and the unique-vendor-email constraint referenced in PayoutsController.
 *
 * UPGRADE PATH: When encrypting sensitive data in production, migrate plain-text
 * values to the *_enc columns using Encryption::encrypt(), then drop the plain
 * columns. The Encryption class is already available as \RTOFLOW\Security\Encryption.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddVendorPlainColumns extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        // ── rto_vendors: PAN (plain) ──────────────────────────────────────
        // Stores the raw PAN string for TDS certificate display (PayoutsController).
        // In production, encrypt before storage using Encryption::encrypt().
        $this->addColumn($p . 'rto_vendors', 'pan',
            "VARCHAR(20) NULL AFTER full_name COMMENT 'Plain PAN — consider encrypting in production'");

        // ── rto_vendors: Aadhaar (plain) ──────────────────────────────────
        // Stored for KYC reference. Encrypt in production.
        $this->addColumn($p . 'rto_vendors', 'aadhaar',
            "VARCHAR(12) NULL AFTER pan COMMENT 'Plain Aadhaar — consider encrypting in production'");

        // ── rto_vendors: Address ──────────────────────────────────────────
        $this->addColumn($p . 'rto_vendors', 'address',
            'TEXT NULL AFTER aadhaar');

        // ── rto_vendors: bank_details (JSON) ─────────────────────────────
        // Stores bank_name, bank_account, bank_ifsc as a flat JSON object.
        // VendorRepository and VendorService read this column.
        // The encrypted counterpart is bank_details_enc (migration 1) — unused until
        // the Encryption upgrade path is implemented.
        $this->addColumn($p . 'rto_vendors', 'bank_details',
            "JSON NULL COMMENT 'Plain bank details JSON — encrypt to bank_details_enc in production'");

        // ── rto_vendors: email (unique per vendor) ────────────────────────
        // VendorsController::store() and PayoutsController query v.email.
        // Migration 1 added a UNIQUE KEY on email — add the column if somehow missing.
        $this->addColumn($p . 'rto_vendors', 'email',
            "VARCHAR(100) NOT NULL DEFAULT '' AFTER mobile");

        // Backfill email from wp_users for existing vendor rows that lack it
        // (safe to run multiple times — only updates rows where email is blank)
        $wpdb->query(
            "UPDATE {$p}rto_vendors v
             JOIN {$p}users u ON u.ID = v.user_id
             SET v.email = u.user_email
             WHERE v.email = '' OR v.email IS NULL"
        );
    }

    public function down(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        // Only drop if they exist to make the rollback idempotent
        foreach (['pan', 'aadhaar', 'address', 'bank_details'] as $col) {
            $exists = $wpdb->get_var("SHOW COLUMNS FROM `{$p}rto_vendors` LIKE '{$col}'");
            if ($exists) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE `{$p}rto_vendors` DROP COLUMN `{$col}`");
            }
        }
        // Note: `email` column is not dropped here because it is referenced by a UNIQUE KEY
        // in migration 1 and is needed by other parts of the application.
    }

    // ── Helper ────────────────────────────────────────────────────────────

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
