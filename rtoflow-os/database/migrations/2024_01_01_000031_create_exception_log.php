<?php
/**
 * Migration 31: Structured Exception/Error Monitoring
 *
 * ENTERPRISE GAP FIX (Phase 2, item 6 — "No structured exception
 * monitoring; failures are only visible via scattered error_log() calls
 * that require server file access to ever see"): dozens of catch blocks
 * across this codebase already call error_log() (PayoutService,
 * PaymentService, LeadService, BankVerificationService, etc.) — those
 * calls are genuinely correct, but error_log() writes to the PHP error log
 * file, which no one in the admin UI can ever see without SSH/hosting
 * panel access. That means every one of those existing error_log() calls
 * is effectively invisible in production. This table plus
 * ExceptionMonitor::capture() (called from a global exception/error/
 * shutdown handler wired in Bootstrap.php) gives admins an actual in-app
 * screen listing recent failures — same failures, now actually visible.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateExceptionLog extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_exception_log (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            level        VARCHAR(20) NOT NULL COMMENT 'fatal | exception | warning | notice',
            message      TEXT NOT NULL,
            file         VARCHAR(500) NULL,
            line         INT NULL,
            trace        TEXT NULL,
            context_json TEXT NULL COMMENT 'request area/action/url/user_id at time of failure',
            occurrences  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'de-dup counter for identical message+file+line within the window',
            first_seen   DATETIME NOT NULL,
            last_seen    DATETIME NOT NULL,
            resolved     TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_level (level),
            INDEX idx_resolved (resolved),
            INDEX idx_last_seen (last_seen)
        ) {$c}");
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
