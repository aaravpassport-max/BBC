<?php
/**
 * Migration 36: Per-User Notification Preferences (rto_notification_preferences)
 *
 * ENTERPRISE GAP FIX (Phase 4, item 6 — "no notification preference
 * controls anywhere"): every notification uses whichever channels
 * NotificationService::send() is called with — vendors and clients have no
 * way to opt out of a channel (e.g. SMS) even if they'd rather only get
 * email. One row per user, created lazily on first save (missing row =
 * all channels enabled, matching today's default behavior exactly, so
 * existing users are unaffected until they explicitly change something).
 * Quiet hours are stored but intentionally NOT enforced by this build —
 * see NotificationPreferenceService's docblock for why that's called out
 * honestly rather than silently half-implemented.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateNotificationPreferences extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_notification_preferences (
            user_id         BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            email_enabled   TINYINT NOT NULL DEFAULT 1,
            sms_enabled     TINYINT NOT NULL DEFAULT 1,
            whatsapp_enabled TINYINT NOT NULL DEFAULT 1,
            quiet_hours_start TINYINT NULL,
            quiet_hours_end   TINYINT NULL,
            updated_at      DATETIME NOT NULL
        ) {$c};");
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
