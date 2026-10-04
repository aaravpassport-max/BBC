<?php
namespace S2NRI\Api\Controllers\Admin;

defined( 'ABSPATH' ) || exit;

use S2NRI\Api\Controllers\BaseController;
use S2NRI\Api\Response;
use S2NRI\Api\Request;
use S2NRI\Diagnostics\DiagnosticEngine;
use S2NRI\Diagnostics\DiagnosticStore;

/**
 * DiagnosticController — API controller for the full-stack diagnostic system.
 *
 * TRACE: All endpoints require admin/staff auth via requireStaff().
 *        Endpoints: run scan, list runs, get run detail, download report, receive frontend errors.
 * Preconditions: Tables exist (ensured in activate() and on-the-fly in ensureTable()).
 * Postconditions: Diagnostic data persisted and returned as JSON.
 */
class DiagnosticController extends BaseController {


    // ── GET debug/diag-run — was public quick diagnostic (no auth) ─────────
    // SECURITY FIX (pre-launch): route registration REMOVED from
    // Dispatcher.php (see comment there). This guard is defense-in-depth
    // in case the route is ever accidentally re-added.

    public function quickRun( Request $req ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Response::json( [ 'error' => 'Not found.' ], 404 ); return;
        }
        $output = [];
        $output['step'] = 'starting';

        try {
            // Step 1: ensure tables exist
            DiagnosticStore::ensureTable();
            $output['step'] = 'table_ok';

            // Step 2: run a MINIMAL scan — no outbound HTTP, just DB + file checks
            global $wpdb;
            $p = $wpdb->prefix;
            $output['step'] = 'building_report';

            $findings = [];

            // Check PHP version
            $findings[] = [
                'id'                 => 'quick_php',
                'severity'           => 'info',
                'category'           => 'php_environment',
                'title'              => 'PHP ' . PHP_VERSION,
                'description'        => 'PHP version check.',
                'evidence'           => [ 'version' => PHP_VERSION ],
                'fix'                => '',
                'prevention'         => '',
                'impact'             => '',
                'reproduction_steps' => [],
                'location'           => [ 'file' => '', 'function' => 'quickRun' ],
                'detected_at'        => current_time( 'c' ),
            ];

            // Check portal JS file
            $dist_dir = S2NRI_DIR . 'dist/assets/';
            $portal_file = null;
            foreach ( (array) scandir( $dist_dir ) as $f ) {
                if ( str_starts_with( $f, 's2nri-portal.' ) && str_ends_with( $f, '.js' ) && strpos( $f, '-xlsx' ) === false ) {
                    $portal_file = $f;
                    break;
                }
            }
            $pjs_content = $portal_file ? file_get_contents( $dist_dir . $portal_file ) : '';
            $findings[] = [
                'id'                 => 'quick_portal_js',
                'severity'           => $portal_file ? 'info' : 'critical',
                'category'           => 'frontend',
                'title'              => $portal_file ? "Portal JS: {$portal_file}" : 'Portal JS file missing',
                'description'        => $portal_file ? 'Portal JS found.' : 'No s2nri-portal.*.js in dist/assets/',
                'evidence'           => [
                    'file'              => $portal_file,
                    'has_tab_fix'       => strpos( $pjs_content, 'onClick:()=>n(e.key??e.value??e)' ) !== false,
                    'has_email_fix'     => strpos( $pjs_content, 'Number(e.is_email_sent)===1&&' ) !== false,
                    'has_send_fix'      => strpos( $pjs_content, '[ql,qf]=(0,_.useState)(!1)' ) !== false,
                ],
                'fix'                => '',
                'prevention'         => '',
                'impact'             => '',
                'reproduction_steps' => [],
                'location'           => [ 'file' => 'dist/assets/', 'function' => 'quickRun' ],
                'detected_at'        => current_time( 'c' ),
            ];

            // Check app.js hero overlay
            $app_js = file_exists( S2NRI_DIR . 'assets/app.js' ) ? file_get_contents( S2NRI_DIR . 'assets/app.js' ) : '';
            $findings[] = [
                'id'                 => 'quick_app_js',
                'severity'           => strpos( $app_js, 'Trusted by NRIs' ) !== false ? 'high' : 'info',
                'category'           => 'frontend',
                'title'              => strpos( $app_js, 'Trusted by NRIs' ) !== false ? 'Hero overlay still in app.js' : 'app.js hero overlay removed',
                'description'        => 'Check for hero overlay text in compiled homepage bundle.',
                'evidence'           => [ 'hero_present' => strpos( $app_js, 'Trusted by NRIs' ) !== false ],
                'fix'                => 'Remove the overlay div from app.js.',
                'prevention'         => '',
                'impact'             => '',
                'reproduction_steps' => [],
                'location'           => [ 'file' => 'assets/app.js', 'function' => 'quickRun' ],
                'detected_at'        => current_time( 'c' ),
            ];

            // Check t.category bug in BookingController
            $bc = file_exists( S2NRI_DIR . 'src/Api/Controllers/BookingController.php' )
                ? file_get_contents( S2NRI_DIR . 'src/Api/Controllers/BookingController.php' )
                : '';
            $findings[] = [
                'id'                 => 'quick_t_category',
                'severity'           => strpos( $bc, 't.category' ) !== false ? 'critical' : 'info',
                'category'           => 'database',
                'title'              => strpos( $bc, 't.category' ) !== false
                    ? 'BookingController still queries t.category (blank tabs bug)'
                    : 'BookingController t.category bug fixed',
                'description'        => 'Check for invalid t.category in tickets SELECT.',
                'evidence'           => [ 'has_bug' => strpos( $bc, 't.category' ) !== false ],
                'fix'                => 'Remove t.category from the tickets SELECT in getBookingDetail().',
                'prevention'         => '',
                'impact'             => '',
                'reproduction_steps' => [],
                'location'           => [ 'file' => 'BookingController.php', 'function' => 'getBookingDetail' ],
                'detected_at'        => current_time( 'c' ),
            ];

            $output['step'] = 'saving';
            $report = [
                'generated_at'     => current_time( 'c' ),
                'scan_duration_ms' => 100,
                'health_score'     => 80,
                'health_label'     => 'Good',
                'summary'          => [
                    'total'    => count( $findings ),
                    'critical' => count( array_filter( $findings, fn($f) => $f['severity'] === 'critical' ) ),
                    'high'     => count( array_filter( $findings, fn($f) => $f['severity'] === 'high' ) ),
                    'medium'   => 0,
                    'low'      => 0,
                    'info'     => count( array_filter( $findings, fn($f) => $f['severity'] === 'info' ) ),
                ],
                'findings'    => $findings,
                'environment' => [ 'php_version' => PHP_VERSION, 'plugin_version' => S2NRI_VERSION ],
            ];

            $run_id = DiagnosticStore::saveReport( $report );
            $output['step']   = 'done';
            $output['run_id'] = $run_id;
            $output['report'] = $report;
            $output['db_error'] = $wpdb->last_error ?: null;

        } catch ( \Throwable $e ) {
            $output['error']   = get_class( $e ) . ': ' . $e->getMessage();
            $output['file']    = $e->getFile();
            $output['line']    = $e->getLine();
            $output['step_failed_at'] = $output['step'];
        }

        Response::json( $output );
    }

    // ── GET admin/diagnostics/live-errors — last 50 browser errors for live stream ──

    public function liveErrors( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p = $wpdb->prefix;

        DiagnosticStore::ensureTable();

        $errors = $wpdb->get_results(
            "SELECT id, severity, category, title, description, recorded_at
             FROM {$p}s2nri_diagnostics_log
             WHERE category = 'frontend'
             AND recorded_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
             ORDER BY recorded_at DESC LIMIT 50",
            ARRAY_A
        ) ?: [];

        Response::json( [ 'errors' => $errors ] );
    }

    // ── GET admin/diagnostics — list recent runs + trends ───────────────────

    public function index( Request $req ): void {
        $this->requireStaff();
        DiagnosticStore::ensureTable();

        $runs   = DiagnosticStore::getRuns( 30 );
        $trends = DiagnosticStore::getTrends();

        Response::json( compact( 'runs', 'trends' ) );
    }

    // ── POST admin/diagnostics/run — run a full scan ─────────────────────────

    public function runScan( Request $req ): void {
        $this->requireStaff();
        DiagnosticStore::ensureTable();

        // Give the scan enough time — some checks make outbound HTTP calls
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        $engine = new DiagnosticEngine();
        try {
            $report = $engine->runFullScan();
        } catch ( \Throwable $e ) {
            // Scan crashed — build a minimal report so we can still save + return
            $report = [
                'generated_at'     => current_time( 'c' ),
                'scan_duration_ms' => 0,
                'health_score'     => 0,
                'health_label'     => 'Error',
                'summary'          => [ 'total'=>1,'critical'=>1,'high'=>0,'medium'=>0,'low'=>0,'info'=>0 ],
                'findings'         => [ [
                    'id'                 => 's2nri_scan_crash',
                    'severity'           => 'critical',
                    'category'           => 'php_environment',
                    'title'              => 'Diagnostic scan crashed: ' . get_class( $e ),
                    'description'        => $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                    'evidence'           => [ 'class' => get_class($e), 'file' => $e->getFile(), 'line' => $e->getLine() ],
                    'fix'                => 'Check PHP error log. Enable WP_DEBUG_LOG=true in wp-config.php.',
                    'prevention'         => '',
                    'impact'             => 'Diagnostic scan could not complete.',
                    'reproduction_steps' => [],
                    'location'           => [ 'file' => $e->getFile(), 'function' => 'runFullScan' ],
                    'detected_at'        => current_time( 'c' ),
                ] ],
                'environment' => [ 'php_version' => PHP_VERSION, 'plugin_version' => S2NRI_VERSION ],
            ];
        }
        $run_id = DiagnosticStore::saveReport( $report );

        $report['run_id'] = $run_id;
        Response::json( $report, 201 );
    }

    // ── GET admin/diagnostics/{run_id} — get single run detail ──────────────

    public function show( Request $req ): void {
        $this->requireStaff();
        DiagnosticStore::ensureTable();

        $run_id = sanitize_text_field( $req->param( 'run_id' ) );
        $data   = DiagnosticStore::getRunFindings( $run_id );

        if ( empty( $data ) ) {
            Response::json( [ 'error' => 'Diagnostic run not found.' ], 404 );
            return;
        }

        Response::json( $data );
    }

    // ── GET admin/diagnostics/{run_id}/download — download report ────────────

    public function download( Request $req ): void {
        $this->requireStaff();
        DiagnosticStore::ensureTable();

        $run_id = sanitize_text_field( $req->param( 'run_id' ) );
        $format = sanitize_key( $req->query( 'format', 'json' ) ); // json | csv | html
        $data   = DiagnosticStore::getRunFindings( $run_id );

        if ( empty( $data ) ) {
            Response::json( [ 'error' => 'Run not found.' ], 404 );
            return;
        }

        $run      = $data['run'];
        $findings = $data['findings'];
        $date     = date( 'Y-m-d', strtotime( $run['run_at'] ) );
        $filename = "s2nri-diagnostic-{$date}-{$run_id}";

        switch ( $format ) {
            case 'pdf':
                $pdf_content = DiagnosticStore::exportPdf( [
                    'health_score'    => (int) $run['health_score'],
                    'health_label'    => $run['health_label'],
                    'generated_at'    => $run['run_at'],
                    'summary'         => [
                        'total'    => (int) $run['total_findings'],
                        'critical' => (int) $run['critical_count'],
                        'high'     => (int) $run['high_count'],
                        'medium'   => (int) $run['medium_count'],
                        'low'      => (int) $run['low_count'],
                        'info'     => (int) $run['info_count'],
                    ],
                    'findings'    => $findings,
                    'environment' => $run['environment'],
                ], $run );
                // If DomPDF is present, this is a real PDF; otherwise send HTML with print trigger
                $mime = class_exists( '\\Dompdf\\Dompdf' ) ? 'application/pdf' : 'text/html';
                $ext  = class_exists( '\\Dompdf\\Dompdf' ) ? 'pdf' : 'html';
                header( "Content-Type: {$mime}; charset=utf-8" );
                header( "Content-Disposition: attachment; filename=\"{$filename}.{$ext}\"" );
                header( 'Cache-Control: no-store, no-cache, must-revalidate' );
                echo $pdf_content;
                exit;

            case 'csv':
                header( 'Content-Type: text/csv; charset=utf-8' );
                header( "Content-Disposition: attachment; filename=\"{$filename}.csv\"" );
                header( 'Cache-Control: no-store, no-cache, must-revalidate' );
                echo DiagnosticStore::exportCsv( $findings );
                exit;

            case 'html':
                header( 'Content-Type: text/html; charset=utf-8' );
                header( "Content-Disposition: attachment; filename=\"{$filename}.html\"" );
                header( 'Cache-Control: no-store, no-cache, must-revalidate' );
                echo DiagnosticStore::exportHtml( [
                    'health_score'    => (int) $run['health_score'],
                    'health_label'    => $run['health_label'],
                    'generated_at'    => $run['run_at'],
                    'summary'         => [
                        'total'    => (int) $run['total_findings'],
                        'critical' => (int) $run['critical_count'],
                        'high'     => (int) $run['high_count'],
                        'medium'   => (int) $run['medium_count'],
                        'low'      => (int) $run['low_count'],
                        'info'     => (int) $run['info_count'],
                    ],
                    'findings'    => $findings,
                    'environment' => $run['environment'],
                ], $run );
                exit;

            default: // json
                header( 'Content-Type: application/json; charset=utf-8' );
                header( "Content-Disposition: attachment; filename=\"{$filename}.json\"" );
                header( 'Cache-Control: no-store, no-cache, must-revalidate' );
                echo DiagnosticStore::exportJson( [
                    'run'      => $run,
                    'findings' => $findings,
                ] );
                exit;
        }
    }

    // ── DELETE admin/diagnostics/{run_id} — delete a single run ─────────────

    public function deleteRun( Request $req ): void {
        $this->requireStaff();
        global $wpdb;
        $p      = $wpdb->prefix;
        $run_id = sanitize_text_field( $req->param( 'run_id' ) );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}s2nri_diagnostics_log WHERE run_id = %s", $run_id
        ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$p}s2nri_diagnostic_runs WHERE run_id = %s", $run_id
        ) );

        Response::json( [ 'success' => true ] );
    }

    // ── POST admin/diagnostics/frontend-errors — receive browser errors ──────
    // auth=false so it can be called from public pages before login

    public function receiveFrontendErrors( Request $req ): void {
        global $wpdb;
        $p      = $wpdb->prefix;
        $errors = $req->input( 'errors', [] );

        if ( ! is_array( $errors ) || empty( $errors ) ) {
            Response::json( [ 'received' => 0 ] );
            return;
        }

        DiagnosticStore::ensureTable();

        // Map frontend error types to severity
        $sev_map = [
            'js_error'              => 'high',
            'promise_rejection'     => 'high',
            'react_error'           => 'critical',
            'fetch_error'           => 'medium',
            'network_error'         => 'high',
            'console_error'         => 'medium',
            'console_warn'          => 'low',
            'resource_load_failure' => 'medium',
            'slow_request'          => 'low',
            'slow_page_load'        => 'low',
            'performance_lcp'       => 'medium',
            'performance_cls'       => 'medium',
            'performance_fid'       => 'medium',
            'memory_leak_suspected' => 'high',
        ];

        // Use a shared "browser session" run_id (same minute = same run)
        $run_id  = 'fe_' . date( 'Ymd_Hi' );
        $saved   = 0;

        foreach ( array_slice( $errors, 0, 50 ) as $err ) {
            // Deduplicate by checking if same error in last 5 minutes
            $title = sanitize_text_field( $err['type'] ?? 'unknown' )
                     . ': ' . substr( sanitize_text_field( wp_json_encode( $err['data'] ?? [] ) ), 0, 100 );

            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}s2nri_diagnostics_log
                 WHERE title = %s AND recorded_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                 LIMIT 1",
                $title
            ) );
            if ( $existing ) continue;

            $severity = $sev_map[ $err['type'] ?? '' ] ?? 'info';
            if ( $wpdb->insert( $p . 's2nri_diagnostics_log', [
                'run_id'      => $run_id,
                'severity'    => $severity,
                'category'    => 'frontend',
                'title'       => $title,
                'description' => wp_json_encode( $err['data'] ?? [] ),
                'evidence'    => wp_json_encode( [
                    'url' => sanitize_url( $err['url'] ?? '' ),
                    'ua'  => sanitize_text_field( substr( $err['ua'] ?? '', 0, 200 ) ),
                    'ts'  => sanitize_text_field( $err['ts'] ?? '' ),
                ] ),
                'fix'         => 'See browser error data. Check console for more details.',
                'recorded_at' => current_time( 'mysql' ),
            ] ) === false ) {
                error_log( '[S2NRI] Frontend error log insert failed: ' . $wpdb->last_error );
                continue;
            }
            $saved++;
        }

        Response::json( [ 'received' => $saved ] );
    }
}
