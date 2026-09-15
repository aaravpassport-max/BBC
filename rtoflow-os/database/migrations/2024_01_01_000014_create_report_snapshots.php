<?php
/**
 * Migration 14: Report Snapshots (Known Limitations audit fix)
 *
 * "Reports are never snapshotted — the same date range can return
 * different numbers on a later run ... a report exported for an audit or
 * finance close can no longer be reproduced identically later if any
 * underlying record changed after the export — the exported CSV itself is
 * the only durable record of that exact figure." Recommended fix: "Add an
 * optional 'save this report as a named snapshot' action that stores the
 * computed figures (not just a live query) for later exact reproduction."
 *
 * This table stores exactly that: the fully-computed report payload
 * (whatever ReportsController's report methods returned, as JSON) at the
 * moment "Save Snapshot" was clicked, tied to its report type, date range,
 * and who saved it — never a re-runnable query, always a durable copy of
 * the figures as they stood at save time.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateReportSnapshots extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}rto_report_snapshots (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            report_type  VARCHAR(32)  NOT NULL COMMENT 'revenue, leads, vendors, services',
            date_from    DATE         NOT NULL,
            date_to      DATE         NOT NULL,
            label        VARCHAR(150) NULL COMMENT 'Optional staff-entered name, e.g. \"March 2026 finance close\"',
            payload_json LONGTEXT     NOT NULL COMMENT 'The exact computed report data at save time',
            created_by   BIGINT UNSIGNED NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_report_type (report_type),
            INDEX idx_created_at  (created_at)
        ) {$c}");
    }

    public function down(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$p}rto_report_snapshots");
    }
}
