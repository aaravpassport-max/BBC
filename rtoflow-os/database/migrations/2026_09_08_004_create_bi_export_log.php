<?php
/**
 * Migration: BI / data-warehouse export run log
 *
 * ENTERPRISE GAP FIX (Phase 12, item — "No data-warehouse / BI export
 * path"): all reporting previously lived inside the admin UI's own Reports
 * screen (live queries + one-off CSV download) — there was no scheduled
 * export a data team could point an external analytics store / warehouse
 * loader at. This table records each run of the new scheduled export job
 * (see app/Services/BiExportService.php): which tables were exported, how
 * many rows, where the file landed, and whether the optional destination
 * webhook POST (if configured) succeeded — so an admin can see export
 * history and download past runs from the new admin screen instead of the
 * job being an invisible cron with no audit trail.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateBiExportLog extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run(
            "CREATE TABLE IF NOT EXISTS {$p}rto_bi_export_log (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                run_type          VARCHAR(20) NOT NULL DEFAULT 'scheduled',
                status            VARCHAR(20) NOT NULL DEFAULT 'running',
                tables_exported   VARCHAR(500) NULL,
                row_counts        TEXT NULL,
                file_path         VARCHAR(500) NULL,
                file_size_bytes   BIGINT UNSIGNED NULL,
                destination_sent  TINYINT(1) NOT NULL DEFAULT 0,
                error_message     VARCHAR(500) NULL,
                started_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                finished_at       DATETIME NULL,
                INDEX idx_status(status),
                INDEX idx_started(started_at)
            ) {$c}"
        );
    }

    public function down(): void { /* Deliberate no-op */ }
}
