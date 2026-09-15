<?php
/**
 * Migration 40: Vendor Response to Ratings
 *
 * ENTERPRISE GAP FIX (Phase 5, item 5 — "rating response feature for
 * vendors"): vendors could see their own ratings (added in Phase 4, item 4 —
 * see the "My Ratings" card on resources/views/vendor/profile/index.php)
 * but had no way to publicly reply to one, unlike common marketplace UX.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddRatingVendorResponse extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}rto_ratings";

        $col = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'vendor_response'");
        if (!$col) {
            $this->run("ALTER TABLE {$table} ADD COLUMN vendor_response TEXT NULL AFTER review");
        }
        $col2 = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'vendor_response_at'");
        if (!$col2) {
            $this->run("ALTER TABLE {$table} ADD COLUMN vendor_response_at DATETIME NULL AFTER vendor_response");
        }
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
