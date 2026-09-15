<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

// ENTERPRISE GAP FIX (Phase 1, item 2 — "No client self-service cancellation
// or refund request"): the client-facing half lives in
// Client\DashboardController::requestCancellationOrRefund(); this is the
// staff-facing review queue for what clients submit there. Admin-only,
// read-heavy, thin — mirrors AuditLogController's structure (a filtered,
// paginated list over one table) since that pattern already fits this
// screen's needs exactly.
class ClientRequestsController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    // TRACE: admin visits /rto-admin/client-requests/?status=&type=&paged= →
    //        rto_is_admin() gate → builds filtered WHERE (defaults to
    //        status='pending' so the screen opens on what actually needs
    //        action, not a wall of already-handled history) → joins lead
    //        number and client display name for a readable list → paginates
    //        (30/page) → precondition: none → postcondition: read-only →
    //        edge case: lead or client deleted since the request was filed
    //        → LEFT JOINs so the row still renders (with a placeholder)
    //        instead of vanishing from the queue.
    public function index(): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 403);

        $status = Sanitiser::text($_GET['status'] ?? 'pending', 20);
        $type   = Sanitiser::text($_GET['type']   ?? '', 20);
        $page   = max(1, (int)($_GET['paged'] ?? 1));
        $perPage = 30;

        $where  = ['1=1'];
        $params = [];
        if ($status !== '' && $status !== 'all') { $where[] = 'cr.status = %s'; $params[] = $status; }
        if (in_array($type, ['cancellation', 'refund'], true)) { $where[] = 'cr.request_type = %s'; $params[] = $type; }
        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM {$this->p}rto_client_requests cr WHERE {$whereSql}";
        $total = (int)($params ? $this->db->get_var($this->db->prepare($countSql, $params)) : $this->db->get_var($countSql));

        $offset = ($page - 1) * $perPage;
        $listSql = "SELECT cr.*, l.lead_number, l.status AS lead_status, u.display_name AS client_name
                    FROM {$this->p}rto_client_requests cr
                    LEFT JOIN {$this->p}rto_leads l ON l.id = cr.lead_id
                    LEFT JOIN {$this->p}users u ON u.ID = cr.client_id
                    WHERE {$whereSql}
                    ORDER BY cr.created_at DESC LIMIT %d OFFSET %d";
        $listParams = array_merge($params, [$perPage, $offset]);
        $requests = $this->db->get_results($this->db->prepare($listSql, $listParams), ARRAY_A) ?: [];

        $pendingCount = (int)$this->db->get_var(
            "SELECT COUNT(*) FROM {$this->p}rto_client_requests WHERE status='pending'"
        );

        rto_view('admin.client-requests.index', compact('requests', 'status', 'type', 'page', 'perPage', 'total', 'pendingCount'));
    }
}
