<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;
use RTOFLOW\Services\BiExportService;
use RTOFLOW\Storage\FileStorage;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 12, item — "No data-warehouse / BI export
 * path"): admin screen for the new scheduled export job (see
 * BiExportService). Lets an admin enable the nightly scheduled export,
 * optionally configure a destination webhook URL for a completion manifest,
 * trigger a run on demand, and download/view past runs.
 */
class DataExportController
{
    public function index(): void
    {
        $enabled    = get_option('rtoflow_bi_export_enabled', '0') === '1';
        $webhookUrl = get_option('rtoflow_bi_export_webhook_url', '');
        $runs       = (new BiExportService())->recentRuns(30);
        rto_view('admin.data-export.index', compact('enabled', 'webhookUrl', 'runs'));
    }

    public function saveSettings(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        $url     = Sanitiser::url($_POST['webhook_url'] ?? '');
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) rto_json_err('Destination URL is not valid.');

        update_option('rtoflow_bi_export_enabled', $enabled ? '1' : '0', false);
        update_option('rtoflow_bi_export_webhook_url', $url, false);

        AuditService::log('bi_export.settings_updated', null, ['enabled' => $enabled, 'webhook_url' => $url]);
        rto_json_ok(null, 'Data export settings saved.');
    }

    public function runNow(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $result = (new BiExportService())->runManual();
        if (!$result['success']) rto_json_err('Export failed: ' . ($result['message'] ?? 'unknown error'));
        rto_json_ok($result, 'Export completed — ' . array_sum($result['row_counts']) . ' rows across ' . count($result['row_counts']) . ' tables.');
    }

    /** Streams a past export file back to the admin's browser. */
    public function download(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) wp_die('Security check failed.', 'Forbidden', ['response' => 403]);
        if (!rto_is_admin()) wp_die('Access denied.', 'Forbidden', ['response' => 403]);

        $logId = Sanitiser::int($_GET['log_id'] ?? 0, 1);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT file_path FROM {$wpdb->prefix}rto_bi_export_log WHERE id=%d AND status='success'", $logId
        ), ARRAY_A);
        if (!$row || empty($row['file_path'])) wp_die('Export file not found.', 'Not Found', ['response' => 404]);

        $contents = FileStorage::get($row['file_path']);
        if ($contents === null) wp_die('Export file is missing from storage.', 'Not Found', ['response' => 404]);

        AuditService::log('bi_export.downloaded', null, ['log_id' => $logId, 'file' => $row['file_path']]);

        nocache_headers();
        header('Content-Type: application/x-ndjson');
        header('Content-Disposition: attachment; filename="' . basename($row['file_path']) . '"');
        header('Content-Length: ' . strlen($contents));
        echo $contents;
        exit;
    }
}
