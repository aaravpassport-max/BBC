<?php
/**
 * Migration 33: Partner REST API
 *
 * ENTERPRISE GAP FIX (Phase 2, item 4 — "No partner/integration REST API —
 * every integration must go through the admin UI or direct database
 * access"): rto_api_keys holds one row per partner integration, key stored
 * only as a sha256 hash (the raw key is shown exactly once at creation,
 * same "generate once, show once" posture as webhook subscription secrets
 * — see WebhookController::create()). rto_leads.api_key_id links a lead
 * back to the partner key that created it via the API, both for the
 * "check my own submitted lead's status" endpoint (a partner must only
 * ever see leads THEY submitted) and for basic usage accounting.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateApiKeys extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_api_keys (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            partner_name VARCHAR(150) NOT NULL,
            key_prefix   VARCHAR(12) NOT NULL COMMENT 'first chars of the raw key, for identification without exposing the full value',
            key_hash     VARCHAR(64) NOT NULL COMMENT 'sha256 of the raw key — the raw key itself is never stored',
            is_active    TINYINT(1) NOT NULL DEFAULT 1,
            created_by   BIGINT UNSIGNED NOT NULL,
            created_at   DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            revoked_at   DATETIME NULL,
            UNIQUE KEY uq_key_hash (key_hash),
            INDEX idx_active (is_active)
        ) {$c}");

        $leadsTable = "{$p}rto_leads";
        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$leadsTable}");
        if (!in_array('api_key_id', $existing, true)) {
            $this->run("ALTER TABLE {$leadsTable} ADD COLUMN api_key_id BIGINT UNSIGNED NULL AFTER source");
            $this->run("ALTER TABLE {$leadsTable} ADD INDEX idx_api_key (api_key_id)");
        }
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
