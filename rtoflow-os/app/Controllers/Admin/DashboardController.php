<?php

namespace RTOFLOW\Controllers\Admin;


if (!defined('ABSPATH')) exit;

class DashboardController
{
    public function index(): void
    {
        if (!rto_is_staff()) { wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]); }

        // Known Limitations audit fix: "KPI numbers can lag reality by up
        // to 3 minutes ... staff have to guess whether a number is live or
        // cached." A manual refresh action (?refresh=1) busts the same
        // transient getMetrics() reads, gated to staff only (already
        // enforced above).
        if (!empty($_GET['refresh'])) {
            delete_transient('rtoflow_dashboard_v2');
        }

        $data = $this->getMetrics();
        rto_view('admin.dashboard.index', $data);
    }

    // Known Limitations audit fix: real AJAX cache-bust + recompute, called
    // from the Dashboard's "Refresh now" button (rto_action=dashboard_refresh)
    // via the standard admin AJAX dispatch table in Router.php. Busts the
    // exact same transient getMetrics() reads/writes, so the numbers and
    // "as of" timestamp the JS then paints in place are guaranteed to come
    // from a freshly-computed snapshot, not the current wall-clock time.
    public function ajaxRefresh(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);

        delete_transient('rtoflow_dashboard_v2');
        $data = $this->getMetrics();

        rto_json_ok([
            'kpi'               => $data['kpi'],
            'status_distribution' => $data['status_distribution'],
            'computed_at'       => $data['computed_at'],
            'computed_at_label' => rto_date($data['computed_at'], 'd M, g:i A'),
            // ENTERPRISE GAP FIX (Phase 9, item 5 — dashboard anomaly
            // detection): included so the AJAX refresh path also repaints
            // the anomaly banner, not just the KPI numbers.
            'anomalies'         => $data['anomalies'],
        ], 'Dashboard refreshed.');
    }

    private function getMetrics(): array
    {
        // P5-DB-008 FIX: cache dashboard metrics for 3 minutes
        $cached = get_transient('rtoflow_dashboard_v2');
        // Known Limitations audit fix: expose exactly when this cached
        // snapshot was actually computed, so the view can show a real
        // "as of HH:MM" timestamp instead of leaving staff to guess whether
        // a number is live. Stored inside the same cached payload (not a
        // second transient) so it can never drift out of sync with the
        // data it describes.
        if ($cached !== false && is_array($cached)) return $cached;

        global $wpdb;
        $p = $wpdb->prefix;
        // P5-DB-003 FIX: range queries use idx_created index (DATE_FORMAT does not)
        $monthStart     = date('Y-m-01 00:00:00');
        $monthEnd       = date('Y-m-t 23:59:59');
        $lastMonthStart = date('Y-m-01 00:00:00', strtotime('-1 month'));
        $lastMonthEnd   = date('Y-m-t 23:59:59', strtotime('-1 month'));

        // ── KPI cards ──────────────────────────────────────────────────────
        $totalLeads     = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_leads WHERE deleted_at IS NULL");
        $leadsThisMonth = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}rto_leads WHERE created_at>=%s AND created_at<=%s AND deleted_at IS NULL", $monthStart, $monthEnd
        ));
        $leadsLastMonth = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}rto_leads WHERE created_at>=%s AND created_at<=%s AND deleted_at IS NULL", $lastMonthStart, $lastMonthEnd
        ));

        $revenueMonth = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$p}rto_payments WHERE created_at>=%s AND created_at<=%s AND status='completed'", $monthStart, $monthEnd
        ));
        $revenueLastMonth = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$p}rto_payments WHERE created_at>=%s AND created_at<=%s AND status='completed'", $lastMonthStart, $lastMonthEnd
        ));

        $activeVendors  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_vendors WHERE status='active' AND kyc_status='verified'");
        $pendingLeads   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_leads WHERE status IN ('created','payment_received') AND deleted_at IS NULL");
        $slaBreached    = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_leads WHERE sla_breached=1 AND status NOT IN ('completed','cancelled') AND deleted_at IS NULL");
        $openComplaints = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_complaints WHERE status='open'");

        // ENTERPRISE GAP FIX (Phase 7, item 5 — "Complaints module has no
        // SLA tracking despite promising one ... there is no way for a
        // manager to see complaints past their promised window"): SLA
        // fields, breach flagging (Bootstrap's cron), CSV export and the
        // internal notes thread were already built (see ComplaintsController
        // and its migration) — the one piece still missing was any
        // dashboard-visible signal, mirroring the existing lead SLA tile.
        $complaintsSlaBreached = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}rto_complaints WHERE sla_breached=1 AND status NOT IN ('resolved','closed')"
        );

        // ── Status distribution ────────────────────────────────────────────
        $statusDist = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$p}rto_leads WHERE deleted_at IS NULL GROUP BY status ORDER BY count DESC",
            ARRAY_A
        ) ?: [];

        // ── Recent leads ───────────────────────────────────────────────────
        $recentLeads = $wpdb->get_results(
            "SELECT l.*, s.name as service_name, c.name as city_name, u.display_name as client_name
             FROM {$p}rto_leads l
             LEFT JOIN {$p}rto_services s ON s.id = l.service_id
             LEFT JOIN {$p}rto_cities c ON c.id = l.city_id
             LEFT JOIN {$p}users u ON u.ID = l.client_id
             WHERE l.deleted_at IS NULL
             ORDER BY l.created_at DESC LIMIT 10",
            ARRAY_A
        ) ?: [];

        // ── Revenue chart (last 6 months) ──────────────────────────────────
        $revenueChart = $wpdb->get_results(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') as month,
                    COALESCE(SUM(amount),0) as revenue
             FROM {$p}rto_payments
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
             AND status = 'completed'
             GROUP BY month ORDER BY month ASC",
            ARRAY_A
        ) ?: [];

        // ── Top services ───────────────────────────────────────────────────
        $topServices = $wpdb->get_results(
            "SELECT s.name, COUNT(l.id) as count, COALESCE(SUM(l.total_amount),0) as revenue
             FROM {$p}rto_leads l
             JOIN {$p}rto_services s ON s.id = l.service_id
             WHERE l.deleted_at IS NULL
             GROUP BY s.id ORDER BY count DESC LIMIT 5",
            ARRAY_A
        ) ?: [];

        // ── SLA-breached leads requiring action ────────────────────────────
        $slaLeads = $wpdb->get_results(
            "SELECT l.id, l.lead_number, l.status, l.sla_deadline,
                    s.name as service_name, u.display_name as client_name,
                    v.full_name as vendor_name
             FROM {$p}rto_leads l
             LEFT JOIN {$p}rto_services s ON s.id = l.service_id
             LEFT JOIN {$p}users u ON u.ID = l.client_id
             LEFT JOIN {$p}rto_vendors v ON v.id = l.vendor_id
             WHERE l.sla_breached=1 AND l.status NOT IN ('completed','cancelled')
             AND l.deleted_at IS NULL
             ORDER BY l.sla_deadline ASC LIMIT 10",
            ARRAY_A
        ) ?: [];

        $result = [
            'kpi' => [
                'total_leads'       => $totalLeads,
                'leads_month'       => $leadsThisMonth,
                'leads_growth'      => $leadsLastMonth > 0 ? round(($leadsThisMonth - $leadsLastMonth) / $leadsLastMonth * 100, 1) : 0,
                'revenue_month'     => $revenueMonth,
                'revenue_growth'    => $revenueLastMonth > 0 ? round(($revenueMonth - $revenueLastMonth) / $revenueLastMonth * 100, 1) : 0,
                'active_vendors'    => $activeVendors,
                'pending_leads'     => $pendingLeads,
                'sla_breached'      => $slaBreached,
                'open_complaints'   => $openComplaints,
                // ENTERPRISE GAP FIX (Phase 7, item 5 — complaints SLA
                // breach dashboard tile): see docblock above where computed.
                'complaints_sla_breached' => $complaintsSlaBreached,
            ],
            'status_distribution' => $statusDist,
            'recent_leads'        => $recentLeads,
            'revenue_chart'       => $revenueChart,
            'top_services'        => $topServices,
            'sla_leads'           => $slaLeads,
            // Known Limitations audit fix — real "as of" evidence for the view.
            'computed_at'         => current_time('mysql'),
        ];

        // ENTERPRISE GAP FIX (Phase 9, item 5 — "No anomaly detection on the
        // main dashboard ... KPI cards are purely descriptive with no
        // threshold-based alerting for a revenue drop, an unusual lead
        // spike, or a vendor whose acceptance rate has fallen off a
        // cliff"): simple, transparent threshold checks against the same
        // month-over-month figures already computed above, plus a
        // recent-vs-baseline acceptance-rate comparison per vendor. Rule
        // thresholds only — no forecasting model — surfaced as a plain
        // list so staff see *why* each one fired.
        $result['anomalies'] = $this->detectAnomalies($p, $wpdb, $result['kpi']);

        // P5-DB-008 FIX: cache dashboard metrics to reduce 14 queries per load
        set_transient('rtoflow_dashboard_v2', $result, 180);
        return $result;
    }

    // ENTERPRISE GAP FIX (Phase 9, item 5 — dashboard anomaly detection):
    // rule-based only (explicitly not a model — same disclosure spirit as
    // the Risk Score banner). Each check has a documented fixed threshold;
    // returns an empty array when nothing crosses it, so the view can stay
    // silent rather than manufacture a false alert on a quiet day.
    private function detectAnomalies(string $p, \wpdb $wpdb, array $kpi): array
    {
        $alerts = [];

        // Revenue drop: >=20% down on last month, only once last month had
        // enough revenue to make the comparison meaningful (avoids a noisy
        // 100% "drop" from a near-zero baseline).
        $revenueLastMonth = (float)$wpdb->get_var(
            "SELECT COALESCE(SUM(amount),0) FROM {$p}rto_payments WHERE created_at>=DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 1 MONTH) AND created_at<DATE_FORMAT(NOW(),'%Y-%m-01') AND status='completed'"
        );
        if ($revenueLastMonth >= 5000 && $kpi['revenue_growth'] <= -20) {
            $alerts[] = [
                'type'     => 'revenue_drop',
                'severity' => $kpi['revenue_growth'] <= -40 ? 'high' : 'medium',
                'message'  => "Revenue is down {$kpi['revenue_growth']}% vs last month (₹" . number_format($revenueLastMonth) . ' → ₹' . number_format($kpi['revenue_month']) . ').',
            ];
        }

        // Lead spike: unusually high month-over-month growth in volume —
        // could be a marketing win or a bot/spam flood, either way worth a
        // human look.
        if ($kpi['leads_growth'] >= 75) {
            $alerts[] = [
                'type'     => 'lead_spike',
                'severity' => $kpi['leads_growth'] >= 150 ? 'high' : 'medium',
                'message'  => "Lead volume is up {$kpi['leads_growth']}% vs last month ({$kpi['leads_month']} leads this month).",
            ];
        }

        // Vendor acceptance cliff: compare each active vendor's stored
        // acceptance_rate baseline against their acceptance rate on just
        // the last 30 days of assignments. Requires at least 5 recent
        // assignments so one bad week for a low-volume vendor doesn't
        // trigger a false alarm.
        $vendorRows = $wpdb->get_results(
            "SELECT v.id, v.full_name, v.vendor_number, v.acceptance_rate as baseline,
                    COUNT(a.id) as recent_total,
                    SUM(a.status='accepted') as recent_accepted
             FROM {$p}rto_vendors v
             JOIN {$p}rto_assignments a ON a.vendor_id=v.id AND a.assigned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             WHERE v.status='active'
             GROUP BY v.id HAVING recent_total >= 5",
            ARRAY_A
        ) ?: [];
        foreach ($vendorRows as $vr) {
            $recentRate = round(((int)$vr['recent_accepted'] / (int)$vr['recent_total']) * 100, 1);
            $drop = (float)$vr['baseline'] - $recentRate;
            if ($drop >= 25) {
                $alerts[] = [
                    'type'     => 'vendor_acceptance_cliff',
                    'severity' => $drop >= 50 ? 'high' : 'medium',
                    'message'  => "{$vr['full_name']} ({$vr['vendor_number']}) acceptance rate has fallen to {$recentRate}% over the last 30 days, down from a {$vr['baseline']}% baseline.",
                ];
            }
        }

        return $alerts;
    }
}
