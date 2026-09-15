<?php
/**
 * Migration 42: Saved/Named Filter Views
 *
 * ENTERPRISE GAP FIX (Phase 6, item — "no saved/named filter views on any
 * list screen"): staff re-entered the same status/date/search filters every
 * session on every filterable list screen. Deliberately generic (screen +
 * raw query string, not per-field columns) so the same table and service
 * can back any list screen's filter bar without a schema change per screen
 * — see SavedFilterService. This build wires it into the Leads screen only
 * (the highest-traffic list screen); other screens can adopt the same
 * service/UI pattern later without any further schema work.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateSavedFilters extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run(
            "CREATE TABLE IF NOT EXISTS {$p}rto_saved_filters (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                screen VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                query_string VARCHAR(500) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_screen(user_id, screen)
            ) {$c}"
        );
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
