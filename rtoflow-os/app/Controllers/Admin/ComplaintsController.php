<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

class ComplaintsController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    // ── Admin: list all complaints ────────────────────────────────────────────

    public function index(): void
    {
        $status  = Sanitiser::text($_GET['status'] ?? '');
        $search  = Sanitiser::text($_GET['search'] ?? '');
        $page    = max(1, Sanitiser::int($_GET['paged'] ?? 1));
        $perPage = 25;
        $where   = ['1=1']; $params = [];

        if ($status) { $where[] = 'c.status=%s'; $params[] = $status; }
        if ($search) {
            $s = '%' . $this->db->esc_like($search) . '%';
            $where[] = '(c.complaint_number LIKE %s OR c.subject LIKE %s OR u.display_name LIKE %s)';
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        $ws     = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        // ENTERPRISE GAP FIX (Section 8): every sibling list screen already
        // has a CSV export; Complaints didn't. Mirrors MastersController's
        // exportCitiesCsv() pattern — same filtered WHERE, all matching rows.
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->exportComplaintsCsv($ws, $params);
            return;
        }

        $countSql = "SELECT COUNT(*) FROM {$this->p}rto_complaints c
                     LEFT JOIN {$this->p}users u ON u.ID=c.client_id WHERE {$ws}";
        $dataSql  = "SELECT c.*, u.display_name as client_name, l.lead_number
                     FROM {$this->p}rto_complaints c
                     LEFT JOIN {$this->p}users u ON u.ID=c.client_id
                     LEFT JOIN {$this->p}rto_leads l ON l.id=c.lead_id
                     WHERE {$ws} ORDER BY c.created_at DESC LIMIT %d OFFSET %d";

        if (empty($params)) {
            $total      = (int)$this->db->get_var($countSql);
            $complaints = $this->db->get_results($this->db->prepare($dataSql, $perPage, $offset), ARRAY_A) ?: [];
        } else {
            $total      = (int)$this->db->get_var($this->db->prepare($countSql, $params));
            $complaints = $this->db->get_results($this->db->prepare($dataSql, array_merge($params, [$perPage, $offset])), ARRAY_A) ?: [];
        }

        $pages = (int)ceil($total / $perPage);
        rto_view('admin.complaints.index', compact('complaints', 'status', 'search', 'page', 'pages', 'total'));
    }

    // ── CSV export (GET, ?export=csv on the complaints list) ─────────────────
    // TRACE: admin visits /rto-admin/complaints/?status=&search=&export=csv →
    //        index() detects export=csv and calls this with the SAME $ws/$params
    //        it just built → fetches ALL matching rows (capped 5000) → streams CSV.
    private function exportComplaintsCsv(string $ws, array $params): void
    {
        if (!rto_is_staff()) wp_die('Access denied.', 403);

        $sql = "SELECT c.*, u.display_name as client_name, l.lead_number
                FROM {$this->p}rto_complaints c
                LEFT JOIN {$this->p}users u ON u.ID=c.client_id
                LEFT JOIN {$this->p}rto_leads l ON l.id=c.lead_id
                WHERE {$ws} ORDER BY c.created_at DESC LIMIT 5000";
        $rows = $params
            ? $this->db->get_results($this->db->prepare($sql, $params), ARRAY_A)
            : $this->db->get_results($sql, ARRAY_A);
        $rows = $rows ?: [];

        $filename = 'rtoflow-complaints-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Complaint #','Client','Order','Subject','Category','Status','SLA Deadline','SLA Breached','Filed At','Resolved At']);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['complaint_number'] ?? '',
                $r['client_name']      ?? '',
                $r['lead_number']      ?? '',
                $r['subject']          ?? '',
                $r['category']         ?? $r['complaint_type'] ?? '',
                $r['status']           ?? '',
                $r['sla_deadline']     ?? '',
                !empty($r['sla_breached']) ? 'Yes' : 'No',
                $r['created_at']       ?? '',
                $r['resolved_at']      ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    // ── Admin: view & respond to a complaint ──────────────────────────────────

    public function show(int $id): void
    {
        $complaint = $this->db->get_row(
            $this->db->prepare(
                "SELECT c.*, u.display_name as client_name, u.user_email,
                 l.lead_number, s.name as service_name
                 FROM {$this->p}rto_complaints c
                 LEFT JOIN {$this->p}users u ON u.ID=c.client_id
                 LEFT JOIN {$this->p}rto_leads l ON l.id=c.lead_id
                 LEFT JOIN {$this->p}rto_services s ON s.id=l.service_id
                 WHERE c.id=%d", $id
            ), ARRAY_A
        );
        if (!$complaint) wp_die('Complaint not found.', 'Not Found', ['response' => 404, 'back_link' => true]);

        // Mark as read
        if ($complaint['status'] === 'open') {
            $this->db->update($this->p . 'rto_complaints', ['status' => 'under_review', 'updated_at' => current_time('mysql')], ['id' => $id]);
            $complaint['status'] = 'under_review';
        }

        // Known Limitations audit fix: "No automatic linkage suggestion
        // between a newly-filed complaint and the lead it most likely
        // concerns ... a complaint filed without an explicit lead reference
        // requires manual cross-referencing ... recommended fix: suggest
        // the customer's most recent non-terminal lead as a likely match
        // for staff to confirm." Deliberately a suggestion only — lead_id
        // is never auto-set here, staff must explicitly confirm via
        // linkSuggestedLead() below, since a wrong auto-link would be worse
        // than no link.
        //
        // FOLLOW-UP FIX (closing the remaining gap noted in this audit): a
        // client with several concurrent open orders previously only ever
        // saw the single most recent one suggested, silently hiding any
        // other open order that might actually be the one the complaint
        // concerns. All open (non-terminal) orders are now fetched — the
        // most recent still highlighted as the primary suggestion via
        // $suggestedLead for the existing "Link this order" one-click flow
        // (linkSuggestedLead() is unchanged and still only ever links this
        // one) — with any additional open orders passed separately as
        // $otherOpenLeads so the view can list them for manual selection
        // instead of the admin needing to leave this screen to cross-check.
        $suggestedLead = null;
        $otherOpenLeads = [];
        if (empty($complaint['lead_id']) && !empty($complaint['client_id'])) {
            $openLeads = $this->db->get_results($this->db->prepare(
                "SELECT l.id, l.lead_number, l.status, s.name as service_name
                 FROM {$this->p}rto_leads l
                 LEFT JOIN {$this->p}rto_services s ON s.id = l.service_id
                 WHERE l.client_id = %d AND l.status NOT IN ('completed','cancelled') AND l.deleted_at IS NULL
                 ORDER BY l.created_at DESC",
                (int)$complaint['client_id']
            ), ARRAY_A) ?: [];
            if ($openLeads) {
                $suggestedLead  = $openLeads[0];
                $otherOpenLeads = array_slice($openLeads, 1);
            }
        }

        // ENTERPRISE GAP FIX (Phase 3, item 5 — complaint internal notes
        // thread): see ComplaintNoteService's docblock. $notes is separate
        // from $complaint['resolution_note'] (the client-facing response) —
        // notes here are never shown to the complainant.
        $notes = (new \RTOFLOW\Services\ComplaintNoteService())->getNotes($id);

        rto_view('admin.complaints.show', compact('complaint', 'suggestedLead', 'otherOpenLeads', 'notes'));
    }

    // ── Add an internal (staff-only) note to a complaint's thread ────────────
    // TRACE: staff types a note and clicks "Add Note" on the complaint detail
    //        screen → nonce+staff role verified → ComplaintNoteService::
    //        addNote() inserts (never updates) a row → precondition:
    //        complaint exists, note non-empty and under the length cap →
    //        postcondition: one new rto_complaint_notes row, audit-logged →
    //        edge cases: empty/over-length note rejected with a specific
    //        message, complaint not found → 404.
    public function addComplaintNote(): void
    {
        if (!rto_is_staff()) wp_die('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $complaintId = Sanitiser::int($_POST['complaint_id'] ?? 0, 1);
        $note = Sanitiser::text($_POST['note'] ?? '', \RTOFLOW\Services\ComplaintNoteService::MAX_LENGTH);

        $complaint = $this->db->get_row($this->db->prepare("SELECT id FROM {$this->p}rto_complaints WHERE id=%d", $complaintId), ARRAY_A);
        if (!$complaint) rto_json_err('Complaint not found.', 404);

        $result = (new \RTOFLOW\Services\ComplaintNoteService())->addNote($complaintId, get_current_user_id(), $note);
        if (!$result['success']) rto_json_err($result['message']);

        AuditService::log('complaint.note_added', null, ['complaint_id' => $complaintId]);
        rto_json_ok(['note' => $result['note']], $result['message']);
    }

    // ── Admin: confirm the suggested lead linkage ──────────────────────────
    // Companion to the suggestion above — staff-confirmed only, never automatic.
    public function linkSuggestedLead(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id     = Sanitiser::int($_POST['complaint_id'] ?? 0, 1);
        $leadId = Sanitiser::int($_POST['lead_id']       ?? 0, 1);

        $exists = (int)$this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->p}rto_leads WHERE id=%d", $leadId));
        if (!$exists) rto_json_err('Order not found.');

        $updated = $this->db->update($this->p . 'rto_complaints', ['lead_id' => $leadId, 'updated_at' => current_time('mysql')], ['id' => $id]);
        if ($updated === false) rto_json_err('Failed to link order.');

        \RTOFLOW\Services\AuditService::log('complaint.lead_linked', $leadId, ['complaint_id' => $id]);
        rto_json_ok(null, 'Order linked to this complaint.');
    }

    // ── Admin: update status + add response ───────────────────────────────────

    public function respond(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id       = Sanitiser::int($_POST['complaint_id'] ?? 0, 1);
        $status   = Sanitiser::text($_POST['status']      ?? '');
        $response = Sanitiser::text($_POST['response']    ?? '', 2000);

        if (!in_array($status, ['open', 'under_review', 'resolved', 'closed', 'rejected'], true)) {
            rto_json_err('Invalid status.');
        }

        $updated = $this->db->update($this->p . 'rto_complaints', [
            'status'           => $status,
            'admin_response'   => $response,       // migration 3 column
            'resolution_note'  => $response,       // canonical column alias
            'responded_by'     => get_current_user_id(), // migration 5 column
            'responded_at'     => current_time('mysql'), // migration 3 column
            'updated_at'       => current_time('mysql'), // migration 5 column
            'resolved_at'      => in_array($status, ['resolved','closed'], true) ? current_time('mysql') : null,
        ], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported success back to
        // the admin regardless of whether the DB write actually happened.
        if ($updated === false) rto_json_err('Could not update the complaint. Please try again.', 500);

        AuditService::log('complaint.status_changed', (int)($this->db->get_var($this->db->prepare(
            "SELECT lead_id FROM {$this->p}rto_complaints WHERE id=%d", $id
        )) ?: 0), ['complaint_id' => $id, 'status' => $status, 'response' => $response]);

        // Notify client
        $complaint = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_complaints WHERE id=%d", $id), ARRAY_A);
        if ($complaint && $response) {
            \RTOFLOW\Services\NotificationService::send('complaint_responded', [
                'complaint_number' => $complaint['complaint_number'],
                'response'         => $response,
                'status'           => ucfirst($status),
            ], (int)$complaint['client_id']);
        }

        rto_json_ok(['status' => $status], 'Complaint updated and client notified.');
    }

    // ── Client: file a complaint ──────────────────────────────────────────────

    public function store(): void
    {
        // FIX (10-workstream integration pass follow-up): 'grievance_portal'
        // was defined in FeatureFlags but never checked anywhere — an admin
        // disabling it had zero effect since the client-facing submit
        // endpoint (client.file_complaint) ignored it entirely.
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('grievance_portal')) {
            rto_json_err('The complaints/grievance portal is currently unavailable.', 404);
        }
        if (!is_user_logged_in()) rto_json_err('You must be logged in to file a complaint.', 401);
        if (!check_ajax_referer('rto_client_action', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId   = Sanitiser::int($_POST['lead_id']   ?? 0);
        $subject  = Sanitiser::text($_POST['subject']  ?? '', 200);
        $body     = Sanitiser::text($_POST['body']      ?? '', 2000);
        $category = Sanitiser::text($_POST['category'] ?? 'general');

        if (!$subject) rto_json_err('Subject is required.');
        if (!$body)    rto_json_err('Please describe your complaint.');

        // Verify lead belongs to this client
        if ($leadId) {
            $lead = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->p}rto_leads WHERE id=%d AND client_id=%d AND deleted_at IS NULL",
                $leadId, get_current_user_id()
            ));
            if (!$lead) rto_json_err('Order not found.');
        }

        // Rate limit: 3 complaints per 24h
        $recent = (int)$this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->p}rto_complaints WHERE client_id=%d AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            get_current_user_id()
        ));
        if ($recent >= 3) rto_json_err('Maximum 3 complaints can be filed per day.');

        $num = 'CPL-' . date('Y') . '-' . str_pad(
            (int)$this->db->get_var("SELECT COUNT(*)+1 FROM {$this->p}rto_complaints"), 5, '0', STR_PAD_LEFT
        );

        // ENTERPRISE GAP FIX (Section 8 — "Complaints have no SLA tracking"):
        // rto_complaints.sla_deadline/sla_breached have existed in the schema
        // since migration 1, and GrievanceRepository::SLA already defines
        // per-category response-time hours (fraud=6h, payment=24h, delay=48h,
        // general=72h) — but this live client-filing path (client.file_complaint,
        // the one actually wired to the client Complaints form) never read
        // either, so every complaint's sla_deadline stayed NULL forever and
        // sla_breached never had anything to compare against. The parallel
        // GrievanceService::create() path DID set sla_deadline, but that path
        // is dead code (its 'grievance' admin route 301-redirects to this
        // Complaints screen, and its own admin view was never linked to).
        // Single source of truth: GrievanceRepository::SLA, same constant
        // used everywhere else category-based SLA hours are needed.
        $slaHours = \RTOFLOW\Repositories\GrievanceRepository::SLA[$category] ?? \RTOFLOW\Repositories\GrievanceRepository::SLA['general'];
        $slaDeadline = date('Y-m-d H:i:s', strtotime("+{$slaHours} hours"));

        // NOTE: Migration 5 adds 'body' and 'category' alias columns to rto_complaints
        // so both the schema names (description/complaint_type) AND the alias names
        // (body/category) now exist. We insert into both for full compatibility.
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this insert()'s result was previously
        // discarded, and the confirmation below always echoed back $num
        // (generated client-request-side before the write) regardless of
        // whether the complaint row actually made it into the database —
        // a customer could be told their complaint number was filed while
        // no such row existed for staff to ever see or respond to.
        $inserted = $this->db->insert($this->p . 'rto_complaints', [
            'complaint_number' => $num,
            'complainant_id'   => get_current_user_id(),
            'client_id'        => get_current_user_id(), // alias col from migration 3
            'lead_id'          => $leadId ?: null,
            'complaint_type'   => $category,   // canonical schema column
            'category'         => $category,   // alias column (migration 5)
            'subject'          => $subject,
            'description'      => $body,       // canonical schema column
            'body'             => $body,        // alias column (migration 5)
            'status'           => 'open',
            'priority'         => match($category) {
                'fraud', 'misconduct' => 5,
                'payment', 'refund'   => 4,
                default               => 2,
            },
            'sla_deadline'     => $slaDeadline,
            'created_at'       => current_time('mysql'),
            'updated_at'       => current_time('mysql'), // migration 5 adds this
        ]);
        if (!$inserted) rto_json_err('Could not file your complaint. Please try again.', 500);

        // Notify admin
        $adminId = (int)get_option('rtoflow_admin_user_id', 0);
        if ($adminId) {
            \RTOFLOW\Services\NotificationService::send('complaint_filed', [
                'complaint_number' => $num,
                'subject'          => $subject,
            ], $adminId);
        }

        rto_json_ok(['complaint_number' => $num], "Complaint {$num} filed successfully. We will respond within 48 hours.");
    }
}
