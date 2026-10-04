<?php
namespace S2NRI\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * DiagnosticStore — persists diagnostic findings to the database and handles exports.
 *
 * TRACE: Saves findings from DiagnosticEngine to s2nri_diagnostics_log.
 *        Provides history retrieval, trend data, and export methods (PDF/CSV/JSON/HTML).
 * Preconditions: s2nri_diagnostics_log table exists.
 * Postconditions: Findings persisted, available for history and download.
 */
class DiagnosticStore {

    public static function ensureTable(): void {
        global $wpdb;
        $p   = $wpdb->prefix;
        $col = $wpdb->get_charset_collate();
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}s2nri_diagnostics_log` (
                `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `run_id`      VARCHAR(36)     NOT NULL,
                `severity`    ENUM('critical','high','medium','low','info') NOT NULL DEFAULT 'info',
                `category`    VARCHAR(64)     NOT NULL,
                `title`       VARCHAR(255)    NOT NULL,
                `description` TEXT            NOT NULL,
                `evidence`    JSON            DEFAULT NULL,
                `fix`         TEXT            NOT NULL,
                `prevention`  TEXT            DEFAULT NULL,
                `impact`      TEXT            DEFAULT NULL,
            `repro_steps` JSON            DEFAULT NULL,
                `file_path`   VARCHAR(255)    DEFAULT NULL,
                `func_name`   VARCHAR(128)    DEFAULT NULL,
                `recorded_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `run_id` (`run_id`),
                KEY `severity` (`severity`),
                KEY `recorded_at` (`recorded_at`)
            ) {$col};"
        );

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}s2nri_diagnostic_runs` (
                `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `run_id`          VARCHAR(36)     NOT NULL UNIQUE,
                `health_score`    TINYINT         NOT NULL DEFAULT 100,
                `health_label`    VARCHAR(20)     NOT NULL,
                `total_findings`  INT             NOT NULL DEFAULT 0,
                `critical_count`  INT             NOT NULL DEFAULT 0,
                `high_count`      INT             NOT NULL DEFAULT 0,
                `medium_count`    INT             NOT NULL DEFAULT 0,
                `low_count`       INT             NOT NULL DEFAULT 0,
                `info_count`      INT             NOT NULL DEFAULT 0,
                `scan_duration_ms` INT            NOT NULL DEFAULT 0,
                `environment`     JSON            DEFAULT NULL,
                `run_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `run_at` (`run_at`)
            ) {$col};"
        );
    }

    public static function saveReport( array $report ): string {
        global $wpdb;
        $p      = $wpdb->prefix;
        $run_id = wp_generate_uuid4();

        $run_insert = $wpdb->insert( $p . 's2nri_diagnostic_runs', [
            'run_id'          => $run_id,
            'health_score'    => $report['health_score'],
            'health_label'    => $report['health_label'],
            'total_findings'  => $report['summary']['total'],
            'critical_count'  => $report['summary']['critical'],
            'high_count'      => $report['summary']['high'],
            'medium_count'    => $report['summary']['medium'],
            'low_count'       => $report['summary']['low'],
            'info_count'      => $report['summary']['info'],
            'scan_duration_ms'=> $report['scan_duration_ms'],
            'environment'     => wp_json_encode( $report['environment'] ),
            'run_at'          => current_time( 'mysql' ),
        ] );
        // CHECKED (was previously unchecked, both inserts below): lower
        // severity than most fixes this session since this is internal
        // diagnostic history, not customer-facing data — but fixed per
        // explicit instruction to address everything found, not just
        // high-severity items.
        if ( $run_insert === false ) {
            error_log( '[S2NRI Diagnostics] Failed to save run ' . $run_id . ': ' . $wpdb->last_error );
        }

        foreach ( $report['findings'] as $f ) {
            $finding_insert = $wpdb->insert( $p . 's2nri_diagnostics_log', [
                'run_id'      => $run_id,
                'severity'    => $f['severity'],
                'category'    => $f['category'],
                'title'       => $f['title'],
                'description' => $f['description'],
                'evidence'    => wp_json_encode( $f['evidence'] ),
                'fix'         => $f['fix'],
                'prevention'  => $f['prevention'] ?? '',
                'impact'      => $f['impact'] ?? '',
                'repro_steps' => wp_json_encode( $f['reproduction_steps'] ?? [] ),
                'file_path'   => $f['location']['file'] ?? '',
                'func_name'   => $f['location']['function'] ?? '',
                'recorded_at' => current_time( 'mysql' ),
            ] );
            if ( $finding_insert === false ) {
                error_log( '[S2NRI Diagnostics] Failed to save finding "' . ( $f['title'] ?? '' ) . '" for run ' . $run_id . ': ' . $wpdb->last_error );
            }
        }

        // Prune runs older than 90 days
        $wpdb->query(
            "DELETE r, l FROM {$p}s2nri_diagnostic_runs r
             JOIN {$p}s2nri_diagnostics_log l ON l.run_id = r.run_id
             WHERE r.run_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );

        return $run_id;
    }

    public static function getRuns( int $limit = 20 ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}s2nri_diagnostic_runs ORDER BY run_at DESC LIMIT %d",
                $limit
            ), ARRAY_A
        ) ?: [];
    }

    public static function getRunFindings( string $run_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $run = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$p}s2nri_diagnostic_runs WHERE run_id = %s", $run_id ),
            ARRAY_A
        );
        if ( ! $run ) return [];

        $findings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}s2nri_diagnostics_log WHERE run_id = %s ORDER BY
                 FIELD(severity,'critical','high','medium','low','info')",
                $run_id
            ), ARRAY_A
        ) ?: [];

        foreach ( $findings as &$f ) {
            $f['evidence'] = json_decode( $f['evidence'] ?? '{}', true ) ?: [];
        }

        $run['environment'] = json_decode( $run['environment'] ?? '{}', true ) ?: [];

        return [ 'run' => $run, 'findings' => $findings ];
    }

    public static function getTrends(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results(
            "SELECT DATE(run_at) as date, AVG(health_score) as avg_score,
                    SUM(critical_count) as critical, SUM(high_count) as high
             FROM {$p}s2nri_diagnostic_runs
             WHERE run_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(run_at) ORDER BY date ASC",
            ARRAY_A
        ) ?: [];
    }

    // ── EXPORT ───────────────────────────────────────────────────────────────

    public static function exportJson( array $data ): string {
        return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    public static function exportPdf( array $report, ?array $run = null ): string {
        // Pure PHP PDF generation — no external library required.
        // We generate a minimal valid PDF using raw PDF syntax.
        // For a production site, DomPDF or TCPDF would produce better output,
        // but this works without any installation.

        $html = self::exportHtml( $report, $run );

        // Check if DomPDF is available (via Composer or installed separately)
        if ( class_exists( '\\Dompdf\\Dompdf' ) ) {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml( $html );
            $dompdf->setPaper( 'A4', 'portrait' );
            $dompdf->render();
            return $dompdf->output();
        }

        // Fallback: return the HTML export with a PDF content-type note.
        // The browser will offer to save it and the user can print-to-PDF.
        // We add a print-trigger script so it auto-opens the print dialog.
        $pdf_hint = str_replace(
            '</body>',
            '<script>window.onload=function(){window.print();};</script></body>',
            $html
        );
        return $pdf_hint;
    }

    public static function exportCsv( array $findings ): string {
        $rows = [ [ 'Severity', 'Category', 'Title', 'Description', 'Fix', 'File', 'Detected At' ] ];
        foreach ( $findings as $f ) {
            $rows[] = [
                strtoupper( $f['severity'] ),
                $f['category'],
                $f['title'],
                $f['description'],
                $f['fix'],
                $f['file_path'] ?? '',
                $f['recorded_at'] ?? $f['detected_at'] ?? '',
            ];
        }
        $out = '';
        foreach ( $rows as $row ) {
            $out .= implode( ',', array_map( fn( $v ) => '"' . str_replace( '"', '""', $v ) . '"', $row ) ) . "\r\n";
        }
        return $out;
    }

    public static function exportHtml( array $report, ?array $run = null ): string {
        $score   = $run['health_score']  ?? $report['health_score']  ?? 0;
        $label   = $run['health_label']  ?? $report['health_label']  ?? '';
        $summary = $report['summary']    ?? [
            'total'    => $run['total_findings'] ?? 0,
            'critical' => $run['critical_count'] ?? 0,
            'high'     => $run['high_count'] ?? 0,
            'medium'   => $run['medium_count'] ?? 0,
            'low'      => $run['low_count'] ?? 0,
            'info'     => $run['info_count'] ?? 0,
        ];
        $findings = $report['findings'] ?? [];
        $env      = $report['environment'] ?? $run['environment'] ?? [];
        $gen_at   = $report['generated_at'] ?? $run['run_at'] ?? current_time('c');
        $site     = home_url();

        $color_map = [ 'critical'=>'#dc2626','high'=>'#ea580c','medium'=>'#d97706','low'=>'#2563eb','info'=>'#6b7280' ];
        $score_color = $score >= 90 ? '#16a34a' : ( $score >= 70 ? '#d97706' : '#dc2626' );

        $findings_html = '';
        foreach ( $findings as $f ) {
            $c   = $color_map[ $f['severity'] ] ?? '#6b7280';
            $ev  = is_array( $f['evidence'] ) ? htmlspecialchars( wp_json_encode( $f['evidence'], JSON_PRETTY_PRINT ) ) : '';
            $loc = ! empty( $f['file_path'] ) ? "<code>{$f['file_path']}</code>" : ( $f['location']['file'] ?? '' );
            $findings_html .= "
            <div class='finding' data-sev='{$f['severity']}'>
              <div class='f-header'>
                <span class='badge' style='background:{$c}'>" . strtoupper( $f['severity'] ) . "</span>
                <span class='f-cat'>{$f['category']}</span>
                <span class='f-title'>" . htmlspecialchars( $f['title'] ) . "</span>
              </div>
              <div class='f-body'>
                <p><strong>Issue:</strong> " . htmlspecialchars( $f['description'] ) . "</p>
  <p><strong>Fix:</strong> " . htmlspecialchars( $f['fix'] ) . "</p>
                " . ( ! empty( $f['repro_steps'] ) ? "<details style='margin-top:8px'><summary style='cursor:pointer;font-weight:600;color:#7c3aed'>🔁 Reproduction Steps</summary><ol style='margin:8px 0 0 16px;font-size:12px;line-height:1.8'>" . implode( '', array_map( fn($s) => '<li>' . htmlspecialchars($s) . '</li>', is_array($f['repro_steps']) ? $f['repro_steps'] : (json_decode($f['repro_steps'],true)??[]) ) ) . "</ol></details>" : '' ) . "
                " . ( $loc ? "<p><strong>Location:</strong> {$loc}</p>" : '' ) . "
                " . ( $ev ? "<details><summary>Evidence</summary><pre>{$ev}</pre></details>" : '' ) . "
              </div>
            </div>";
        }

        $env_rows = '';
        foreach ( (array) $env as $k => $v ) {
            $val = is_bool( $v ) ? ( $v ? 'true' : 'false' ) : ( is_array( $v ) ? count( $v ) . ' items' : htmlspecialchars( (string) $v ) );
            $env_rows .= "<tr><td>{$k}</td><td>{$val}</td></tr>";
        }

        return "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1'>
<title>S2NRI Diagnostic Report — " . date('Y-m-d', strtotime($gen_at)) . "</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f9fafb;color:#111827;padding:24px}
.report{max-width:1100px;margin:0 auto}
h1{font-size:26px;font-weight:800;color:#111827;margin-bottom:4px}
.meta{font-size:13px;color:#6b7280;margin-bottom:28px}
.score-card{background:#fff;border-radius:16px;padding:28px;display:flex;gap:32px;align-items:center;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:24px}
.score-circle{width:90px;height:90px;border-radius:50%;background:" . $score_color . ";color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:28px;font-weight:800;flex-shrink:0}
.score-circle span{font-size:12px;font-weight:600;margin-top:-2px}
.summary-grid{display:flex;gap:12px;flex-wrap:wrap}
.scard{background:#f9fafb;border-radius:10px;padding:12px 20px;text-align:center;min-width:80px}
.scard .num{font-size:22px;font-weight:800}
.scard .lbl{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase}
.section{background:#fff;border-radius:12px;padding:24px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
h2{font-size:17px;font-weight:700;margin-bottom:16px;color:#111827}
.finding{border:1px solid #e5e7eb;border-radius:10px;margin-bottom:12px;overflow:hidden}
.f-header{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#f9fafb;cursor:pointer}
.badge{padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;color:#fff}
.f-cat{font-size:12px;color:#6b7280;font-weight:600;background:#e5e7eb;padding:2px 8px;border-radius:4px}
.f-title{font-size:14px;font-weight:600;color:#111827}
.f-body{padding:14px 16px;border-top:1px solid #e5e7eb;font-size:13px;line-height:1.7;color:#374151}
.f-body p{margin-bottom:8px}
.f-body pre{background:#f3f4f6;padding:10px;border-radius:6px;font-size:11px;overflow-x:auto;white-space:pre-wrap}
.f-body code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12px}
details summary{cursor:pointer;font-weight:600;font-size:12px;color:#6b7280;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:13px}
table th{background:#f3f4f6;padding:10px;text-align:left;font-weight:700;font-size:12px;color:#374151}
table td{padding:10px;border-top:1px solid #f3f4f6}
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.filter-btn{padding:6px 14px;border-radius:99px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-size:12px;font-weight:600}
.filter-btn.active{background:#4A6FA5;color:#fff;border-color:#4A6FA5}
@media print{body{background:#fff;padding:0}.filter-bar{display:none}}
</style></head><body>
<div class='report'>
  <h1>🔍 Services2NRI Diagnostic Report</h1>
  <div class='meta'>{$site} &nbsp;·&nbsp; Generated: {$gen_at}</div>

  <div class='score-card'>
    <div class='score-circle'>{$score}<span>{$label}</span></div>
    <div>
      <div style='font-size:20px;font-weight:800;margin-bottom:12px'>System Health Score: {$score}/100</div>
      <div class='summary-grid'>
        <div class='scard'><div class='num' style='color:#dc2626'>{$summary['critical']}</div><div class='lbl'>Critical</div></div>
        <div class='scard'><div class='num' style='color:#ea580c'>{$summary['high']}</div><div class='lbl'>High</div></div>
        <div class='scard'><div class='num' style='color:#d97706'>{$summary['medium']}</div><div class='lbl'>Medium</div></div>
        <div class='scard'><div class='num' style='color:#2563eb'>{$summary['low']}</div><div class='lbl'>Low</div></div>
        <div class='scard'><div class='num' style='color:#6b7280'>{$summary['info']}</div><div class='lbl'>Info</div></div>
        <div class='scard'><div class='num'>{$summary['total']}</div><div class='lbl'>Total</div></div>
      </div>
    </div>
  </div>

  <div class='section'>
    <h2>🔎 Findings</h2>
    <div class='filter-bar'>
      <button class='filter-btn active' onclick='filterFindings(\"all\",this)'>All</button>
      <button class='filter-btn' onclick='filterFindings(\"critical\",this)' style='color:#dc2626'>Critical</button>
      <button class='filter-btn' onclick='filterFindings(\"high\",this)' style='color:#ea580c'>High</button>
      <button class='filter-btn' onclick='filterFindings(\"medium\",this)' style='color:#d97706'>Medium</button>
      <button class='filter-btn' onclick='filterFindings(\"low\",this)' style='color:#2563eb'>Low</button>
      <button class='filter-btn' onclick='filterFindings(\"info\",this)' style='color:#6b7280'>Info</button>
    </div>
    <div id='findings-list'>{$findings_html}</div>
  </div>

  <div class='section'>
    <h2>🖥️ Environment Snapshot</h2>
    <table><thead><tr><th>Setting</th><th>Value</th></tr></thead><tbody>{$env_rows}</tbody></table>
  </div>
</div>
<script>
function filterFindings(sev,btn){
  document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.finding').forEach(f=>{
    f.style.display=(sev==='all'||f.dataset.sev===sev)?'block':'none';
  });
}
</script>
</body></html>";
    }
}
