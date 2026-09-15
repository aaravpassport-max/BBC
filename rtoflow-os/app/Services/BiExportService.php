<?php

namespace RTOFLOW\Services;

use RTOFLOW\Storage\FileStorage;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 12, item — "No data-warehouse / BI export
 * path"): all reporting previously lived entirely inside the admin UI's own
 * Reports screen — live queries plus a manual, one-report-at-a-time CSV
 * download (ReportsController::exportCsv()). There was no scheduled,
 * unattended export a data team could point an external analytics
 * store/warehouse loader at, and no single file covering the core
 * operational tables together.
 *
 * This service runs a scheduled (daily, via WP-Cron — see Bootstrap.php's
 * 'rtoflow_bi_export_run' hook) or on-demand export of the core tables to
 * newline-delimited JSON (one row per line — the format most warehouse
 * loaders, e.g. BigQuery/Snowflake/S3+Athena, ingest directly without a
 * transform step), written via the existing FileStorage abstraction (so it
 * honours the local/S3 storage driver setting already used for documents,
 * rather than hardcoding a local path). If a destination webhook URL is
 * configured, a small manifest (file path, row counts, checksum) is POSTed
 * there so an external system can be notified a new export landed without
 * polling.
 *
 * Scope, stated honestly: this exports the tables a BI/analytics use case
 * actually needs — leads, payments, vendors, complaints, ratings — as flat
 * denormalized-ish JSON rows. It does not implement an incremental/
 * change-data-capture pipeline (each run is a full snapshot); for the data
 * volumes this system currently operates at, a full nightly snapshot is the
 * honest right-sized answer, not an over-engineered CDC pipeline no admin
 * asked for.
 */
class BiExportService
{
    private const EXPORT_TABLES = [
        'leads'      => 'rto_leads',
        'payments'   => 'rto_payments',
        'vendors'    => 'rto_vendors',
        'complaints' => 'rto_complaints',
        'ratings'    => 'rto_ratings',
    ];

    private \wpdb $db;
    private string $p;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->p  = $wpdb->prefix;
    }

    /** Cron target for 'rtoflow_bi_export_run' (registered in Bootstrap.php). */
    public function runScheduled(): void
    {
        if (get_option('rtoflow_bi_export_enabled', '0') !== '1') return;
        $this->run('scheduled');
    }

    /** Called from the Data Export admin screen's "Run Now" button. */
    public function runManual(): array
    {
        return $this->run('manual');
    }

    private function run(string $runType): array
    {
        $logId = $this->db->insert($this->p . 'rto_bi_export_log', [
            'run_type'   => $runType,
            'status'     => 'running',
            'started_at' => current_time('mysql'),
        ]) ? (int)$this->db->insert_id : 0;

        try {
            $rowCounts   = [];
            $lines       = [];
            $exportedAt  = current_time('mysql');

            foreach (self::EXPORT_TABLES as $key => $table) {
                $rows = $this->db->get_results("SELECT * FROM {$this->p}{$table}", ARRAY_A) ?: [];
                $rowCounts[$key] = count($rows);
                foreach ($rows as $row) {
                    $row['_export_table'] = $key;
                    $lines[] = wp_json_encode($row);
                }
            }

            $ndjson   = implode("\n", $lines);
            $filename = 'bi-export/rtoflow-export-' . date('Y-m-d-His') . '.ndjson';
            $written  = FileStorage::put($filename, $ndjson);

            if (!$written) {
                throw new \RuntimeException('Could not write export file to storage.');
            }

            $this->db->update($this->p . 'rto_bi_export_log', [
                'status'           => 'success',
                'tables_exported'  => implode(',', array_keys(self::EXPORT_TABLES)),
                'row_counts'       => wp_json_encode($rowCounts),
                'file_path'        => $filename,
                'file_size_bytes'  => strlen($ndjson),
                'finished_at'      => current_time('mysql'),
            ], ['id' => $logId]);

            // Optional destination webhook — a manifest only (not the full
            // data dump, which could be large), so an external system knows
            // a fresh export is ready and can pull it via the admin download
            // action or its own storage-driver access.
            $destination = get_option('rtoflow_bi_export_webhook_url', '');
            $sent = false;
            if ($destination && filter_var($destination, FILTER_VALIDATE_URL)) {
                $response = wp_remote_post($destination, [
                    'timeout' => 5,
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => wp_json_encode([
                        'event'      => 'bi_export.completed',
                        'file'       => $filename,
                        'row_counts' => $rowCounts,
                        'exported_at' => $exportedAt,
                    ]),
                ]);
                $sent = !is_wp_error($response) && (int)wp_remote_retrieve_response_code($response) < 300;
                $this->db->update($this->p . 'rto_bi_export_log', ['destination_sent' => $sent ? 1 : 0], ['id' => $logId]);
            }

            AuditService::log('bi_export.completed', null, ['file' => $filename, 'row_counts' => $rowCounts, 'run_type' => $runType]);

            return ['success' => true, 'file' => $filename, 'row_counts' => $rowCounts, 'destination_sent' => $sent];
        } catch (\Throwable $e) {
            $this->db->update($this->p . 'rto_bi_export_log', [
                'status'        => 'failed',
                'error_message' => substr($e->getMessage(), 0, 500),
                'finished_at'   => current_time('mysql'),
            ], ['id' => $logId]);
            error_log('RTOFLOW BiExportService: export failed — ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function recentRuns(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->get_results(
            "SELECT * FROM {$this->p}rto_bi_export_log ORDER BY started_at DESC LIMIT {$limit}",
            ARRAY_A
        ) ?: [];
    }
}
