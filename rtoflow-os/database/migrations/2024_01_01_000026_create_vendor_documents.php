<?php

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (gap-analysis Section 1 — "critical missing
 * functionality: vendor KYC has no document-review workflow").
 *
 * ROOT CAUSE: VendorsController::updateKyc() lets an admin set
 * rto_vendors.kyc_status to 'verified' with nothing more than a free-text
 * note — there was no actual identity/address/bank proof document ever
 * collected or reviewable anywhere in the codebase. Migration 3 added a
 * kyc_document_path column to rto_vendors, but grepping the entire
 * application layer shows it was never read or written by anything — dead
 * schema. A vendor's KYC could be marked "verified" with a real admin
 * clicking a real dropdown, with zero actual evidence ever inspected.
 *
 * A single VARCHAR path column on rto_vendors could never have supported
 * this properly anyway — real KYC needs multiple documents per vendor
 * (PAN, Aadhaar, bank proof, etc.), each independently reviewable with its
 * own status, so this is a dedicated table rather than reviving that dead
 * column. Deliberately separate from the existing lead-scoped rto_documents
 * table (NOT NULL lead_id, no vendor_id) rather than widening that table's
 * contract — this keeps every existing lead-document query's assumptions
 * intact and mirrors that table's shape closely enough that the same
 * "pending → verified/rejected, with a reviewer, timestamp, and reason"
 * pattern is instantly familiar to anyone who already knows the lead-docs
 * flow.
 */
class CreateVendorDocuments extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_vendor_documents (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vendor_id     BIGINT UNSIGNED NOT NULL,
            doc_label     VARCHAR(100) NOT NULL,
            file_path     VARCHAR(500) NOT NULL,
            file_name     VARCHAR(255) NOT NULL,
            file_size_kb  INT UNSIGNED NOT NULL DEFAULT 0,
            mime_type     VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
            status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
            verified_by   BIGINT UNSIGNED NULL,
            verified_at   DATETIME NULL,
            reject_reason TEXT NULL,
            uploaded_by   BIGINT UNSIGNED NOT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_vendor_status (vendor_id, status)
        ) {$c}");
    }

    public function down(): void
    {
        // Deliberate no-op — matches this codebase's established convention
        // of never destroying a table that may hold real KYC review history.
    }
}
