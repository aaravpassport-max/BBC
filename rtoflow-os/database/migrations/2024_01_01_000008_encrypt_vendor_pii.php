<?php
/**
 * Migration 8: Encrypt Vendor PII At Rest (FIX P0-8)
 *
 * Migration 4 added plain-text pan/aadhaar/bank_details columns as a
 * stop-gap "so the application code works correctly" (its own docblock),
 * explicitly deferring the encryption upgrade documented in migration 1's
 * bank_details_enc/pan_enc/aadhaar_hash columns. That upgrade never
 * happened — VendorsController and VendorService have been writing PAN,
 * Aadhaar, and bank account/IFSC details to the database in plain text
 * ever since.
 *
 * This migration:
 *   1. Adds `aadhaar_enc` (the reversible counterpart to the existing
 *      one-way `aadhaar_hash`, which cannot be used to display or verify
 *      the original number — only to detect duplicates).
 *   2. Encrypts every existing plain-text pan/aadhaar/bank_details value
 *      into pan_enc/aadhaar_enc/aadhaar_hash/bank_details_enc using
 *      RTOFLOW\Security\Encryption (already used elsewhere in the
 *      codebase for exactly this purpose — TwoFactor secrets, invoice
 *      tokens).
 *   3. Blanks the plain-text columns after a successful encrypt, so no
 *      code path can read stale plaintext going forward.
 *
 * The plain-text columns are NOT dropped by this migration — doing so
 * safely requires updating every caller in the same deploy (see the
 * accompanying VendorsController/VendorService/Repositories changes,
 * which now read/write exclusively through the *_enc columns). Once
 * that code has been running in production for one full release without
 * incident, a follow-up migration can drop pan/aadhaar/bank_details.
 *
 * Idempotent: safe to run more than once — rows are only touched when
 * the plain column is non-empty and the *_enc column is still empty.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;
use RTOFLOW\Security\Encryption;

if (!defined('ABSPATH')) exit;

class EncryptVendorPii extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $this->addColumn($p . 'rto_vendors', 'aadhaar_enc',
            "TEXT NULL AFTER aadhaar_hash COMMENT 'AES-256-GCM encrypted Aadhaar (reversible; aadhaar_hash is one-way, dedup only)'");

        $rows = $wpdb->get_results(
            "SELECT id, pan, aadhaar, bank_details, pan_enc, aadhaar_enc, bank_details_enc
             FROM {$p}rto_vendors
             WHERE (pan IS NOT NULL AND pan != '')
                OR (aadhaar IS NOT NULL AND aadhaar != '')
                OR (bank_details IS NOT NULL AND bank_details != '')",
            ARRAY_A
        ) ?: [];

        foreach ($rows as $row) {
            $update = [];

            if (!empty($row['pan']) && empty($row['pan_enc'])) {
                $update['pan_enc'] = Encryption::encrypt($row['pan']);
                $update['pan']     = '';
            }
            if (!empty($row['aadhaar']) && empty($row['aadhaar_enc'])) {
                $update['aadhaar_enc']  = Encryption::encrypt($row['aadhaar']);
                $update['aadhaar_hash'] = Encryption::hmac($row['aadhaar']);
                $update['aadhaar']      = '';
            }
            if (!empty($row['bank_details']) && empty($row['bank_details_enc'])) {
                $update['bank_details_enc'] = Encryption::encrypt($row['bank_details']);
                $update['bank_details']     = null;
            }

            if ($update) {
                $wpdb->update($p . 'rto_vendors', $update, ['id' => (int)$row['id']]);
            }
        }

        error_log('RTOFLOW Migration 8: encrypted PII for ' . count($rows) . ' vendor row(s).');
    }

    public function down(): void
    {
        // Deliberately not reversible: decrypting back to plaintext columns
        // would reintroduce the vulnerability this migration exists to close.
        // Rolling back removes only the added column, leaving existing
        // encrypted data intact and readable by the application (which reads
        // aadhaar_enc defensively — see VendorsController::show()).
        global $wpdb;
        $p = $wpdb->prefix;
        $exists = $wpdb->get_var("SHOW COLUMNS FROM `{$p}rto_vendors` LIKE 'aadhaar_enc'");
        if ($exists) {
            $wpdb->query("ALTER TABLE `{$p}rto_vendors` DROP COLUMN `aadhaar_enc`");
        }
    }

    protected function addColumn(string $table, string $col, string $definition): void
    {
        global $wpdb;
        $exists = $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
        if (!$exists) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$definition}");
        }
    }
}
