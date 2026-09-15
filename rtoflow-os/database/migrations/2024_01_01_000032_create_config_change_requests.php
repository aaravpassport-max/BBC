<?php
/**
 * Migration 32: Config-Change Approval Workflow
 *
 * ENTERPRISE GAP FIX (Phase 2, item 2 — "Settings changes take effect
 * immediately with no approval/staging step, even for the highest-risk
 * tabs (Razorpay keys, SMS/WhatsApp provider credentials)"): a single
 * compromised or careless rto_admin account could silently repoint
 * payment collection to different Razorpay credentials, or swap the
 * SMS/WhatsApp provider token used to reach every client and vendor, with
 * the change live the instant the form was submitted — no second set of
 * eyes, matching the exact gap the maker-checker control already closed
 * for payouts (see PayoutsController's approval_status column) but never
 * extended to configuration itself.
 *
 * This table holds a pending change (the tab + the full posted payload) —
 * SettingsController::index() now creates a row here instead of writing
 * directly for the tabs in SettingsController::REQUIRES_APPROVAL_TABS. A
 * DIFFERENT admin must approve it via the new Config Approvals screen
 * before ConfigApprovalService::approve() replays the payload through the
 * real save logic. Rejecting a request discards the payload — it never
 * takes effect.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateConfigChangeRequests extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_config_change_requests (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tab          VARCHAR(50) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            status       VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | rejected',
            requested_by BIGINT UNSIGNED NOT NULL,
            requested_at DATETIME NOT NULL,
            decided_by   BIGINT UNSIGNED NULL,
            decided_at   DATETIME NULL,
            decision_note TEXT NULL,
            INDEX idx_status (status),
            INDEX idx_tab (tab)
        ) {$c}");
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
