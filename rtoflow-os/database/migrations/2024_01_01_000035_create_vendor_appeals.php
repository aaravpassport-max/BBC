<?php
/**
 * Migration 35: Vendor Rating/Suspension Appeals (rto_vendor_appeals)
 *
 * ENTERPRISE GAP FIX (Phase 4, item 5 — "no rating or suspension appeal
 * workflow for vendors"): ratings (rto_ratings) and suspensions
 * (rto_vendors.status='suspended') are entirely admin/client-driven today
 * with no vendor-initiated dispute channel — a vendor who believes a
 * rating was unfair or a suspension was a mistake has no in-app recourse
 * at all, only going outside the platform (phone/email to support).
 * subject_type distinguishes a rating appeal (subject_id = rto_ratings.id)
 * from a suspension appeal (subject_id = 0, there is only ever one active
 * suspension per vendor at a time).
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateVendorAppeals extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_vendor_appeals (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vendor_id      BIGINT UNSIGNED NOT NULL,
            subject_type   VARCHAR(20) NOT NULL,
            subject_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reason         TEXT NOT NULL,
            status         VARCHAR(20) NOT NULL DEFAULT 'pending',
            admin_response TEXT NULL,
            resolved_by    BIGINT UNSIGNED NULL,
            resolved_at    DATETIME NULL,
            created_at     DATETIME NOT NULL,
            KEY idx_vendor (vendor_id),
            KEY idx_status (status)
        ) {$c};");
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
