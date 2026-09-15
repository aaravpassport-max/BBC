<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

class ReportsController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    public function index(): void
    {
        $report  = Sanitiser::text($_GET['report']    ?? 'revenue');
        $from    = Sanitiser::date($_GET['date_from'] ?? date('Y-m-01'));
        $to      = Sanitiser::date($_GET['date_to']   ?? date('Y-m-d'));
        if (!$from) $from = date('Y-m-01');
        if (!$to)   $to   = date('Y-m-d');
        $fromDt  = $from . ' 00:00:00';
        $toDt    = $to   . ' 23:59:59';

        // ENTERPRISE GAP FIX (Phase 3, item 7 — "Form funnel/abandonment
        // event data is already captured ... but never surfaced as an
        // actual funnel report"): FormFunnelService::getAbandonmentSummary()/
        // getDropOffByStep()/getValidationFailuresByField() already existed
        // and were fully correct — they were simply never called from
        // anywhere in the admin UI. This tab is the first place any of that
        // data becomes visible to a human. Funnel category selectable via
        // ?funnel_category= (defaults to the first category in the seeder's
        // map); date range doesn't apply here (funnel events aren't
        // filtered by date in the underlying service) so this tab ignores
        // $fromDt/$toDt, matching FormFunnelService's own all-time scope.
        $funnelCategory = Sanitiser::text($_GET['funnel_category'] ?? 'dl');

        $data = match($report) {
            'revenue'  => $this->revenueReport($fromDt, $toDt),
            'leads'    => $this->leadsReport($fromDt, $toDt),
            'vendors'  => $this->vendorReport($fromDt, $toDt),
            'services' => $this->serviceReport($fromDt, $toDt),
            'funnel'   => $this->funnelReport($funnelCategory),
            // ENTERPRISE GAP FIX (Phase 9, item 4 — "No cohort, churn, or
            // customer-lifetime-value analysis ... no report or query
            // anywhere segments clients by acquisition cohort, computes
            // repeat-order rate, or flags at-risk/lapsed customers"): first
            // report of this kind. All-time scope like Funnel, for the same
            // reason — a cohort's repeat-rate isn't meaningful sliced to an
            // arbitrary date window.
            'cohort'   => $this->cohortReport(),
            default    => [],
        };

        // CSV export
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->exportCsv($report, $data, $from, $to);
            return;
        }

        // Known Limitations audit fix: show any snapshots already saved
        // for this exact report type + date range, so staff can see a
        // prior save exists rather than assuming this is the first time.
        $existingSnapshots = $this->db->get_results($this->db->prepare(
            "SELECT id, label, created_at FROM {$this->p}rto_report_snapshots
             WHERE report_type=%s AND date_from=%s AND date_to=%s ORDER BY created_at DESC",
            $report, $from, $to
        ), ARRAY_A) ?: [];

        $funnelCategories = \RTOFLOW\Database\Seeds\RealFormSchemaSeeder::categoryMap();

        rto_view('admin.reports.index', compact('report','from','to','data','existingSnapshots','funnelCategory','funnelCategories'));
    }

    // ENTERPRISE GAP FIX (Phase 3, item 7 — funnel report): builds the
    // report tab's data from the pre-existing, already-correct
    // FormFunnelService methods. $category must be one of
    // RealFormSchemaSeeder::categoryMap()'s keys; an unknown/blank category
    // returns FormFunnelService's own empty-array/zeroed defaults rather
    // than erroring, so a first-time admin with no funnel data yet just
    // sees an empty state, not a crash.
    private function funnelReport(string $category): array
    {
        $categoryMap = \RTOFLOW\Database\Seeds\RealFormSchemaSeeder::categoryMap();
        $serviceNames = $categoryMap[$category]['services'] ?? [];

        $funnel = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\FormFunnelService::class);
        return [
            'summary'             => $funnel->getAbandonmentSummary($category, $serviceNames),
            'drop_off_by_step'    => $funnel->getDropOffByStep($category, $serviceNames),
            'validation_failures' => $funnel->getValidationFailuresByField($category, $serviceNames),
        ];
    }

    // ENTERPRISE GAP FIX (Phase 9, item 4 — cohort/churn/CLV report): builds
    // client acquisition cohorts (grouped by the calendar month of each
    // client's first-ever lead), each cohort's repeat-order rate, and a
    // lapsed-customer list (clients with at least one order but nothing in
    // the last 90 days) ranked by lifetime value so staff can see who's
    // worth a win-back outreach first. All figures are computed directly
    // from rto_leads/rto_payments — no new tables needed since the raw
    // data (client_id, created_at, payment history) already existed.
    private function cohortReport(): array
    {
        $cohorts = $this->db->get_results(
            "SELECT DATE_FORMAT(t.first_order,'%Y-%m') as cohort_month,
                    COUNT(*) as cohort_size,
                    SUM(t.order_count > 1) as repeat_customers,
                    ROUND(SUM(t.order_count > 1) / COUNT(*) * 100, 1) as repeat_rate
             FROM (
                SELECT client_id, MIN(created_at) as first_order, COUNT(*) as order_count
                FROM {$this->p}rto_leads
                WHERE deleted_at IS NULL AND client_id > 0
                GROUP BY client_id
             ) t
             GROUP BY cohort_month ORDER BY cohort_month DESC LIMIT 12",
            ARRAY_A
        ) ?: [];

        // Lapsed/at-risk: last order over 90 days ago. Lifetime value is
        // sum of completed payments only (matches how revenue is counted
        // everywhere else in Reports) — an approximation of true CLV, not
        // a discounted/predictive figure.
        $lapsed = $this->db->get_results(
            "SELECT l.client_id, u.display_name as client_name,
                    COUNT(*) as total_orders,
                    MAX(l.created_at) as last_order,
                    COALESCE(SUM(py.amount),0) as lifetime_value
             FROM {$this->p}rto_leads l
             LEFT JOIN {$this->p}users u ON u.ID = l.client_id
             LEFT JOIN {$this->p}rto_payments py ON py.lead_id=l.id AND py.status='completed'
             WHERE l.deleted_at IS NULL AND l.client_id > 0
             GROUP BY l.client_id
             HAVING last_order < DATE_SUB(NOW(), INTERVAL 90 DAY)
             ORDER BY lifetime_value DESC LIMIT 25",
            ARRAY_A
        ) ?: [];

        $overall = $this->db->get_row(
            "SELECT COUNT(*) as total_clients,
                    SUM(order_count > 1) as repeat_clients,
                    ROUND(SUM(order_count > 1) / COUNT(*) * 100, 1) as overall_repeat_rate
             FROM (
                SELECT client_id, COUNT(*) as order_count
                FROM {$this->p}rto_leads WHERE deleted_at IS NULL AND client_id > 0
                GROUP BY client_id
             ) t",
            ARRAY_A
        ) ?: ['total_clients' => 0, 'repeat_clients' => 0, 'overall_repeat_rate' => 0];

        return ['cohorts' => $cohorts, 'lapsed' => $lapsed, 'overall' => $overall];
    }

    // ── Save a snapshot (Known Limitations audit fix) ───────────────────────
    // "Add an optional 'save this report as a named snapshot' action that
    // stores the computed figures (not just a live query) for later exact
    // reproduction." Recomputes the report server-side from the same
    // $_POST-supplied type/range (never trusts a client-submitted payload
    // as the thing that gets stored) so the saved figures are always a
    // genuine, freshly-computed value, not whatever the browser happened
    // to have in memory.
    public function saveSnapshot(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);

        $report = Sanitiser::text($_POST['report'] ?? 'revenue');
        $from   = Sanitiser::date($_POST['date_from'] ?? '') ?: date('Y-m-01');
        $to     = Sanitiser::date($_POST['date_to']   ?? '') ?: date('Y-m-d');
        $label  = Sanitiser::text($_POST['label'] ?? '', 150);

        $data = match($report) {
            'revenue'  => $this->revenueReport($from . ' 00:00:00', $to . ' 23:59:59'),
            'leads'    => $this->leadsReport($from . ' 00:00:00', $to . ' 23:59:59'),
            'vendors'  => $this->vendorReport($from . ' 00:00:00', $to . ' 23:59:59'),
            'services' => $this->serviceReport($from . ' 00:00:00', $to . ' 23:59:59'),
            default    => null,
        };
        if ($data === null) rto_json_err('Invalid report type.');

        $inserted = $this->db->insert($this->p . 'rto_report_snapshots', [
            'report_type'  => $report,
            'date_from'    => $from,
            'date_to'      => $to,
            'label'        => $label ?: null,
            'payload_json' => wp_json_encode($data),
            'created_by'   => get_current_user_id(),
            'created_at'   => current_time('mysql'),
        ]);
        if (!$inserted) rto_json_err('Failed to save snapshot.');

        \RTOFLOW\Services\AuditService::log('report.snapshot_saved', null, ['report_type' => $report, 'date_from' => $from, 'date_to' => $to]);
        rto_json_ok(['id' => $this->db->insert_id], 'Snapshot saved — this exact figure is now permanently retrievable even if the underlying data changes later.');
    }

    // ── View a saved snapshot (read-only, exact reproduction) ──────────────
    public function viewSnapshot(int $id): void
    {
        if (!rto_is_staff()) wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);

        $snap = $this->db->get_row($this->db->prepare(
            "SELECT s.*, u.display_name as created_by_name FROM {$this->p}rto_report_snapshots s
             LEFT JOIN {$this->p}users u ON u.ID = s.created_by WHERE s.id=%d", $id
        ), ARRAY_A);
        if (!$snap) wp_die('Snapshot not found.', 'Not Found', ['response' => 404, 'back_link' => true]);

        $report = $snap['report_type'];
        $from   = $snap['date_from'];
        $to     = $snap['date_to'];
        $data   = json_decode($snap['payload_json'], true) ?: [];

        rto_view('admin.reports.snapshot', compact('snap', 'report', 'from', 'to', 'data'));
    }

    // ── Revenue report ────────────────────────────────────────────────────────

    private function revenueReport(string $from, string $to): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT DATE_FORMAT(py.created_at,'%%Y-%%m-%%d') as date,
             COUNT(*)                   as payments,
             COALESCE(SUM(py.amount),0) as revenue,
             COALESCE(SUM(py.gst_amount),0) as gst
             FROM {$this->p}rto_payments py
             WHERE py.status='completed' AND py.created_at>=%s AND py.created_at<=%s
             GROUP BY DATE_FORMAT(py.created_at,'%%Y-%%m-%%d') ORDER BY date ASC",
            $from, $to
        ), ARRAY_A) ?: [];

        $totals = [
            'total_revenue'  => array_sum(array_column($rows, 'revenue')),
            'total_gst'      => array_sum(array_column($rows, 'gst')),
            'total_payments' => array_sum(array_column($rows, 'payments')),
        ];

        return ['rows' => $rows, 'totals' => $totals, 'columns' => ['Date','Payments','Revenue (₹)','GST (₹)']];
    }

    // ── Leads report ─────────────────────────────────────────────────────────

    private function leadsReport(string $from, string $to): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT s.name as service, s.category,
             COUNT(*) as total,
             SUM(l.status='completed') as completed,
             SUM(l.status='cancelled') as cancelled,
             SUM(l.sla_breached) as sla_breached,
             COALESCE(AVG(l.total_amount),0) as avg_value
             FROM {$this->p}rto_leads l
             JOIN {$this->p}rto_services s ON s.id=l.service_id
             WHERE l.created_at>=%s AND l.created_at<=%s AND l.deleted_at IS NULL
             GROUP BY s.id ORDER BY total DESC",
            $from, $to
        ), ARRAY_A) ?: [];

        $totals = [
            'total_leads'    => array_sum(array_column($rows, 'total')),
            'completed'      => array_sum(array_column($rows, 'completed')),
            'cancelled'      => array_sum(array_column($rows, 'cancelled')),
            'sla_breached'   => array_sum(array_column($rows, 'sla_breached')),
        ];

        return ['rows' => $rows, 'totals' => $totals, 'columns' => ['Service','Category','Total','Completed','Cancelled','SLA Breached','Avg. Value (₹)']];
    }

    // ── Vendor performance ────────────────────────────────────────────────────

    private function vendorReport(string $from, string $to): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT v.full_name as vendor, v.vendor_number,
             COUNT(*) as total,
             SUM(l.status='completed') as completed,
             SUM(l.status='cancelled') as cancelled,
             SUM(l.sla_breached) as sla_breached,
             ROUND(SUM(l.status='completed')/COUNT(*)*100,1) as completion_pct,
             COALESCE(AVG(r.score),0) as avg_rating
             FROM {$this->p}rto_leads l
             JOIN {$this->p}rto_vendors v ON v.id=l.vendor_id
             LEFT JOIN {$this->p}rto_ratings r ON r.lead_id=l.id AND r.rated_role='vendor' AND r.deleted_at IS NULL
             WHERE l.created_at>=%s AND l.created_at<=%s AND l.deleted_at IS NULL AND l.vendor_id IS NOT NULL
             GROUP BY v.id ORDER BY completed DESC",
            $from, $to
        ), ARRAY_A) ?: [];

        // ENTERPRISE GAP FIX (Phase 7, item 7 — "Vendor reports are
        // single-snapshot with no trendline ... no city-wise ... breakdown
        // exists despite the underlying columns being available"):
        // rto_leads.city_id has always been there — this is the first place
        // vendor volume is ever sliced by it. Deliberately capped to the
        // top 10 cities by volume so a nationwide operation's table doesn't
        // become one row per city with a long tail of 1s.
        $cityBreakdown = $this->db->get_results($this->db->prepare(
            "SELECT c.name as city, COUNT(*) as total,
             SUM(l.status='completed') as completed,
             COUNT(DISTINCT l.vendor_id) as vendors_active
             FROM {$this->p}rto_leads l
             JOIN {$this->p}rto_cities c ON c.id=l.city_id
             WHERE l.created_at>=%s AND l.created_at<=%s AND l.deleted_at IS NULL AND l.vendor_id IS NOT NULL
             GROUP BY c.id ORDER BY total DESC LIMIT 10",
            $from, $to
        ), ARRAY_A) ?: [];

        // Trendline: monthly completed-job volume for the last 6 months,
        // independent of the selected date-range filter (a trend needs its
        // own fixed window to be readable) — same 6-month window convention
        // DashboardController's revenue chart already uses.
        $trend = $this->db->get_results(
            "SELECT DATE_FORMAT(l.created_at,'%Y-%m') as month,
             COUNT(*) as total, SUM(l.status='completed') as completed
             FROM {$this->p}rto_leads l
             WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) AND l.deleted_at IS NULL AND l.vendor_id IS NOT NULL
             GROUP BY month ORDER BY month ASC",
            ARRAY_A
        ) ?: [];

        return [
            'rows' => $rows, 'totals' => [], 'columns' => ['Vendor','#','Total','Done','Cancelled','SLA Breached','Completion %','Avg Rating'],
            'city_breakdown' => $cityBreakdown, 'trend' => $trend,
        ];
    }

    // ── Service performance ───────────────────────────────────────────────────

    private function serviceReport(string $from, string $to): array
    {
        // ENTERPRISE GAP FIX (Phase 7, item 7 — "no ... margin/
        // profitability breakdown exists despite the underlying columns
        // being available"): rto_services.vendor_share (the % of revenue
        // paid out to the vendor, set per-service at Services setup) has
        // always existed but nothing ever multiplied it against actual
        // collected revenue. margin = revenue paid-in minus the vendor's
        // contractual share of it — an approximation (it does not net out
        // gateway fees or govt_fee pass-through) but the first real
        // profitability figure this report has ever shown, computed from
        // real per-service configuration rather than invented.
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT s.name, s.category, s.base_price, s.vendor_share,
             COUNT(*) as orders,
             COALESCE(SUM(py.amount),0) as revenue,
             ROUND(COALESCE(SUM(py.amount),0) * (100 - s.vendor_share) / 100, 2) as est_margin,
             SUM(l.status='completed') as completed,
             SUM(l.sla_breached) as sla_issues
             FROM {$this->p}rto_leads l
             JOIN {$this->p}rto_services s ON s.id=l.service_id
             LEFT JOIN {$this->p}rto_payments py ON py.lead_id=l.id AND py.status='completed'
             WHERE l.created_at>=%s AND l.created_at<=%s AND l.deleted_at IS NULL
             GROUP BY s.id ORDER BY revenue DESC",
            $from, $to
        ), ARRAY_A) ?: [];

        // City-wise breakdown for the same underlying order volume — same
        // top-10-by-volume cap as vendorReport()'s city breakdown, same reason.
        $cityBreakdown = $this->db->get_results($this->db->prepare(
            "SELECT c.name as city, COUNT(*) as orders,
             COALESCE(SUM(py.amount),0) as revenue
             FROM {$this->p}rto_leads l
             JOIN {$this->p}rto_cities c ON c.id=l.city_id
             LEFT JOIN {$this->p}rto_payments py ON py.lead_id=l.id AND py.status='completed'
             WHERE l.created_at>=%s AND l.created_at<=%s AND l.deleted_at IS NULL
             GROUP BY c.id ORDER BY orders DESC LIMIT 10",
            $from, $to
        ), ARRAY_A) ?: [];

        return [
            'rows' => $rows, 'totals' => [], 'columns' => ['Service','Category','Base Price','Vendor Share %','Orders','Revenue (₹)','Est. Margin (₹)','Completed','SLA Issues'],
            'city_breakdown' => $cityBreakdown,
        ];
    }

    // ── CSV export ────────────────────────────────────────────────────────────

    private function exportCsv(string $report, array $data, string $from, string $to): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 'Access Denied', ['response' => 403]);
        $filename = "rtoflow-{$report}-{$from}-to-{$to}.csv";
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
        if (!empty($data['columns'])) fputcsv($out, $data['columns']);
        foreach ($data['rows'] ?? [] as $row) fputcsv($out, array_values($row));
        fclose($out);
        exit;
    }
}
