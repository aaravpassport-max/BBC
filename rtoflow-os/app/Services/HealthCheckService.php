<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * ENTERPRISE GAP FIX (Phase 8, item — "duplicate health-check
 * implementation"):
 *
 * The DB-connectivity check used to be written out twice — once inline in
 * Router::routeHealth() (the custom /rto-health/ rewrite route) and again
 * inline in the /wp-json/rtoflow/v1/health REST callback in rtoflow-os.php
 * — same "SELECT 1" query, same status/db/version/timestamp shape, two
 * copies to keep in sync. Any future addition (e.g. the cron dead-man's
 * switch below) would otherwise need to be remembered in both places.
 *
 * Both routes now call status() and only differ in their own auth model
 * (the rewrite route additionally checks a shared-secret token; the REST
 * route is intentionally public, matching its pre-existing behaviour) and
 * output format (plain wp_json_encode vs WP_REST_Response).
 */
final class HealthCheckService
{
    /** @return array{status:string, db:string, cron:string, overdue_jobs:array, timestamp:string, version:string} */
    public static function status(): array
    {
        global $wpdb;
        $dbOk = (bool)$wpdb->get_var('SELECT 1');

        // ENTERPRISE GAP FIX (Phase 8, item — cron dead-man's-switch):
        // folded into the shared health payload so an external uptime
        // monitor polling either health endpoint sees "degraded" the
        // moment a background job goes silent, not only on a DB outage.
        $cronStatus = CronMonitor::status();
        $overdue    = array_values(array_map(
            fn($s) => $s['label'],
            array_filter($cronStatus, fn($s) => $s['overdue'])
        ));

        $healthy = $dbOk && empty($overdue);

        return [
            'status'       => $healthy ? 'healthy' : 'degraded',
            'db'           => $dbOk ? 'ok' : 'error',
            'cron'         => empty($overdue) ? 'ok' : 'stalled',
            'overdue_jobs' => $overdue,
            'timestamp'    => date('c'),
            'version'      => defined('RTOFLOW_VERSION') ? RTOFLOW_VERSION : '',
        ];
    }
}
