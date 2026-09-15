<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

// ENTERPRISE GAP FIX (gap-analysis Section 8 — "Operational/administrative
// gaps: no way to see a global audit trail"): AuditService::log() has
// written to rto_logs from ~20 different controllers and services since
// migration 1 (lead status changes, payments, refunds, city/vendor/user
// edits, complaint responses, SLA breaches, etc.) — every action, its
// before/after JSON, the acting user, IP, and user-agent were captured and
// then never readable anywhere. This screen is a thin, admin-only,
// read-only viewer over that existing data: filter by action, actor, lead,
// and date range; paginate; export. No new writes, no schema change — the
// gap was purely "the data exists but nothing shows it."
class AuditLogController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    // TRACE: admin visits /rto-admin/audit-log/?action=&user_id=&lead_id=&date_from=&date_to=&paged= →
    //        rto_is_admin() gate (audit trail access is deliberately admin-only,
    //        not rto_is_staff() — this can reveal other staff's/vendors' actions
    //        and PII touched in old_value/new_value) → builds filtered WHERE →
    //        paginates (50/page) → renders list, newest first →
    //        postconditions: read-only, no state mutation →
    //        edge cases: no filters → full log newest-first; malformed date → Sanitiser::text leaves it as opaque
    //        string, MySQL comparison against an invalid date string simply matches nothing (no crash)
    public function index(): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 403);

        $action   = Sanitiser::text($_GET['action']    ?? '');
        $userId   = Sanitiser::int($_GET['user_id']    ?? 0);
        $leadId   = Sanitiser::int($_GET['lead_id']    ?? 0);
        $dateFrom = Sanitiser::text($_GET['date_from'] ?? '');
        $dateTo   = Sanitiser::text($_GET['date_to']   ?? '');
        $page     = max(1, Sanitiser::int($_GET['paged'] ?? 1));
        $perPage  = 50;

        [$ws, $params] = $this->buildWhere($action, $userId, $leadId, $dateFrom, $dateTo);

        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->exportCsv($ws, $params);
            return;
        }

        $offset   = ($page - 1) * $perPage;
        $countSql = "SELECT COUNT(*) FROM {$this->p}rto_logs g WHERE {$ws}";
        $dataSql  = "SELECT g.*, u.display_name as user_name, l.lead_number
                     FROM {$this->p}rto_logs g
                     LEFT JOIN {$this->p}users u ON u.ID=g.user_id
                     LEFT JOIN {$this->p}rto_leads l ON l.id=g.lead_id
                     WHERE {$ws} ORDER BY g.created_at DESC LIMIT %d OFFSET %d";

        $total = $params ? (int)$this->db->get_var($this->db->prepare($countSql, $params)) : (int)$this->db->get_var($countSql);
        $logs  = $this->db->get_results($this->db->prepare($dataSql, array_merge($params, [$perPage, $offset])), ARRAY_A) ?: [];
        $pages = (int)ceil($total / $perPage);

        // Distinct action list for the filter dropdown — cheap enough (idx_action
        // makes this an index scan) and keeps the filter honest to real data
        // rather than a hardcoded, inevitably-stale list of action strings.
        $actions = $this->db->get_col("SELECT DISTINCT action FROM {$this->p}rto_logs ORDER BY action");

        rto_view('admin.audit.index', compact('logs', 'action', 'userId', 'leadId', 'dateFrom', 'dateTo', 'page', 'pages', 'total', 'actions'));
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function buildWhere(string $action, int $userId, int $leadId, string $dateFrom, string $dateTo): array
    {
        $where = ['1=1']; $params = [];
        if ($action)   { $where[] = 'g.action=%s';         $params[] = $action; }
        if ($userId)   { $where[] = 'g.user_id=%d';        $params[] = $userId; }
        if ($leadId)   { $where[] = 'g.lead_id=%d';        $params[] = $leadId; }
        if ($dateFrom) { $where[] = 'g.created_at >= %s';  $params[] = $dateFrom . ' 00:00:00'; }
        if ($dateTo)   { $where[] = 'g.created_at <= %s';  $params[] = $dateTo   . ' 23:59:59'; }
        return [implode(' AND ', $where), $params];
    }

    // ── CSV export (GET, ?export=csv on the audit log list) ──────────────────
    private function exportCsv(string $ws, array $params): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 403);

        $sql = "SELECT g.*, u.display_name as user_name, l.lead_number
                FROM {$this->p}rto_logs g
                LEFT JOIN {$this->p}users u ON u.ID=g.user_id
                LEFT JOIN {$this->p}rto_leads l ON l.id=g.lead_id
                WHERE {$ws} ORDER BY g.created_at DESC LIMIT 5000";
        $rows = $params
            ? $this->db->get_results($this->db->prepare($sql, $params), ARRAY_A)
            : $this->db->get_results($sql, ARRAY_A);
        $rows = $rows ?: [];

        $filename = 'rtoflow-audit-log-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Timestamp','Action','User','Order','Old Value','New Value','IP Address']);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['created_at'] ?? '',
                $r['action']     ?? '',
                $r['user_name']  ?? ($r['user_id'] ? ('User #' . $r['user_id']) : 'System'),
                $r['lead_number'] ?? '',
                $r['old_value']  ?? '',
                $r['new_value']  ?? '',
                $r['ip_address'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
}
