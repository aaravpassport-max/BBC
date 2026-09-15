<?php
/**
 * Migration 41: Help Center Content Overrides
 *
 * ENTERPRISE GAP FIX (Phase 5, item 4 — "Help Center becomes admin-
 * editable"): HelpContent.php is deliberately hand-curated PHP (see its own
 * docblock — a defensible choice made to avoid link-rot / drift from the
 * real codebase). Rather than replace that static registry (which would
 * lose the "every article is code-grounded" guarantee its author cared
 * about), this table lets an admin store a per-slug OVERRIDE that layers on
 * top of the static article at render time — see HelpContent::getMerged().
 * A slug with no row here behaves exactly as before (100% static content).
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateHelpOverrides extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run(
            "CREATE TABLE IF NOT EXISTS {$p}rto_help_overrides (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(100) NOT NULL,
                what_is LONGTEXT NULL,
                updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY slug_uk(slug)
            ) {$c}"
        );
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
