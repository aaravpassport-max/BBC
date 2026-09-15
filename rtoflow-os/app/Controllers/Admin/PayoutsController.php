<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\PayoutService;
use RTOFLOW\Services\TdsService;

if (!defined('ABSPATH')) exit;

class PayoutsController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    // ── List ──────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $period   = Sanitiser::text($_GET['period']    ?? date('Y-m'));
        $status   = Sanitiser::text($_GET['status']    ?? '');
        $vendorId = Sanitiser::int($_GET['vendor_id']  ?? 0);

        $where = ['1=1']; $params = [];
        if ($period)   { $where[] = 'po.period=%s';     $params[] = $period; }
        if ($status)   { $where[] = 'po.status=%s';     $params[] = $status; }
        if ($vendorId) { $where[] = 'po.vendor_id=%d';  $params[] = $vendorId; }

        $ws = implode(' AND ', $where);

        // ENTERPRISE GAP FIX (Section 8 — "No CSV export on Payouts,
        // Complaints, or Staff screens"): every sibling admin list screen
        // (Leads, Vendors, Ratings, Reports, Payments, Masters) already has
        // a CSV export; Payouts — the one screen finance most needs to pull
        // into a spreadsheet for reconciliation/TDS filing — did not.
        // Mirrors MastersController::exportCitiesCsv()'s exact pattern: same
        // filtered WHERE clause as the list below, all matching rows.
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->exportPayoutsCsv($ws, $params);
            return;
        }

        // FIX P0: summary totals are computed from an unpaginated aggregate
        // query over the full filtered set — computing them from array_sum()
        // on the (now paginated) $payouts rows would have silently made the
        // dashboard totals reflect only the current page once pagination
        // was added below.
        $summarySql = "SELECT
                COALESCE(SUM(po.gross_amount),0) AS total_gross,
                COALESCE(SUM(po.tds_amount),0)   AS total_tds,
                COALESCE(SUM(po.net_amount),0)   AS total_net,
                SUM(po.status='pending')         AS pending_count
             FROM {$this->p}rto_vendor_payouts po WHERE {$ws}";
        $summaryRow = $params
            ? $this->db->get_row($this->db->prepare($summarySql, $params), ARRAY_A)
            : $this->db->get_row($summarySql, ARRAY_A);
        $summary = [
            'total_gross'   => (float)($summaryRow['total_gross'] ?? 0),
            'total_tds'     => (float)($summaryRow['total_tds'] ?? 0),
            'total_net'     => (float)($summaryRow['total_net'] ?? 0),
            'pending_count' => (int)($summaryRow['pending_count'] ?? 0),
        ];

        // FIX P0: real pagination — this previously loaded every payout row
        // matching the filters, unbounded.
        $perPage  = 25;
        $page     = Sanitiser::int($_GET['paged'] ?? 1, 1);
        $countSql = "SELECT COUNT(*) FROM {$this->p}rto_vendor_payouts po WHERE {$ws}";
        $total    = $params ? (int)$this->db->get_var($this->db->prepare($countSql, $params)) : (int)$this->db->get_var($countSql);
        $lastPage = max(1, (int)ceil($total / $perPage));
        $page     = min($page, $lastPage);
        $offset   = ($page - 1) * $perPage;

        // FIX P0-8: v.pan is now blank for every vendor onboarded after the PII
        // encryption fix (see migration 8) — the plain column is never written to
        // anymore. Select pan_enc instead and mask it for this list view; the
        // full value is only ever decrypted where a real business need exists
        // (tdsCertificate() below, a genuine statutory export).
        // Maker-checker: join the requester's name so the list/UI can show
        // "Requested by X, awaiting a different admin's approval" without a
        // second query per row.
        $listSql = "SELECT po.*, v.full_name as vendor_name, v.vendor_number, v.pan_enc,
                     ru.display_name as requested_by_name
                     FROM {$this->p}rto_vendor_payouts po
                     LEFT JOIN {$this->p}rto_vendors v ON v.id=po.vendor_id
                     LEFT JOIN {$this->p}users ru ON ru.ID=po.requested_by
                     WHERE {$ws} ORDER BY po.created_at DESC LIMIT %d OFFSET %d";
        $payouts = $this->db->get_results($this->db->prepare($listSql, [...$params, $perPage, $offset]), ARRAY_A) ?: [];
        foreach ($payouts as &$po) {
            $panPlain  = \RTOFLOW\Security\Encryption::decryptSafe($po['pan_enc'] ?? '');
            $po['pan'] = $panPlain ? \RTOFLOW\Security\Encryption::maskPan($panPlain) : '';
        }
        unset($po);

        $vendors = $this->db->get_results("SELECT id, full_name, vendor_number FROM {$this->p}rto_vendors ORDER BY full_name", ARRAY_A) ?: [];

        $currentUserId = get_current_user_id();
        rto_view('admin.payouts.index', compact('payouts','period','status','vendorId','summary','vendors','page','lastPage','total','currentUserId'));
    }

    // ── CSV export (GET, ?export=csv) — ENTERPRISE GAP FIX, Section 8 ──────────
    // PAN is deliberately NOT included here (unlike tdsCertificate(), a real,
    // narrowly-scoped statutory export) — a general-purpose spreadsheet pull
    // is exactly the kind of artifact that ends up emailed around or left in
    // a Downloads folder, so it gets the same masked value the list screen
    // shows, not the decrypted PAN.
    private function exportPayoutsCsv(string $ws, array $params): void
    {
        if (!rto_is_staff()) wp_die('Access denied.', 403);
        // ENTERPRISE GAP FIX (Phase 1, item 6 — granular RBAC): this was
        // reachable by ANY rto_staff account regardless of role — a
        // support-only staff member could pull a full payouts CSV. Now
        // gated on the 'payouts' module's export permission specifically
        // (rto_admin is unaffected — Permissions::can() is unconditional
        // true for admins, see Permissions::can() docblock).
        if (!\RTOFLOW\Security\Permissions::can(get_current_user_id(), 'payouts', 'export')) {
            wp_die('Access denied. Your staff role does not have export access to Payouts.', 403);
        }

        $sql = "SELECT po.*, v.full_name as vendor_name, v.vendor_number
                FROM {$this->p}rto_vendor_payouts po LEFT JOIN {$this->p}rto_vendors v ON v.id=po.vendor_id
                WHERE {$ws} ORDER BY po.created_at DESC LIMIT 5000";
        $payouts = $params
            ? $this->db->get_results($this->db->prepare($sql, $params), ARRAY_A)
            : $this->db->get_results($sql, ARRAY_A);
        $payouts = $payouts ?: [];

        $filename = 'rtoflow-payouts-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Vendor','Vendor No.','Period','Gross Amount','TDS Amount','Net Amount','Status','Paid At','Created At']);

        foreach ($payouts as $po) {
            fputcsv($out, [
                $po['vendor_name']   ?? '',
                $po['vendor_number'] ?? '',
                $po['period']        ?? '',
                $po['gross_amount']  ?? 0,
                $po['tds_amount']    ?? 0,
                $po['net_amount']    ?? 0,
                $po['status']        ?? '',
                $po['paid_at']       ?? '',
                $po['created_at']    ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    // ── Generate payouts for a period ─────────────────────────────────────────

    public function generate(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $period = Sanitiser::text($_POST['period'] ?? date('Y-m', strtotime('-1 month')));
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) rto_json_err('Invalid period format (YYYY-MM).');

        $svc     = new PayoutService(new TdsService());
        $vendors = $this->db->get_col("SELECT id, full_name FROM {$this->p}rto_vendors WHERE status='active'");
        $count   = 0;
        // Known Limitations audit fix: "No built-in warning before
        // regenerating payouts for a period that may already have a
        // batch ... staff may run generation more than once ... relying on
        // the underlying logic's correctness rather than an explicit
        // confirmation." PayoutService::generatePayout() already REJECTS a
        // duplicate per vendor/period server-side (data integrity was
        // never actually at risk) — but the caller here only ever counted
        // successes and threw away every rejection reason, so staff had no
        // visibility into which vendors were skipped or why, which is the
        // actual admin-facing gap the limitation describes. Now surfaced
        // explicitly instead of silently discarded.
        $alreadyExists = [];
        $noEligible    = [];

        foreach ($vendors as $vendorId) {
            $vendorRow = $this->db->get_row($this->db->prepare("SELECT full_name FROM {$this->p}rto_vendors WHERE id=%d", $vendorId), ARRAY_A);
            $result = $svc->generatePayout((int)$vendorId, $period);
            if ($result['success'] && ($result['payout_id'] ?? 0)) {
                $count++;
            } elseif (!empty($result['message']) && stripos($result['message'], 'already exists') !== false) {
                $alreadyExists[] = $vendorRow['full_name'] ?? "Vendor #{$vendorId}";
            } elseif (!$result['success']) {
                $noEligible[] = $vendorRow['full_name'] ?? "Vendor #{$vendorId}";
            }
        }

        $message = "{$count} payout record(s) generated for {$period}.";
        if (!empty($alreadyExists)) {
            $message .= ' Skipped (batch already exists for this period): ' . implode(', ', $alreadyExists) . '.';
        }

        rto_json_ok([
            'count'           => $count,
            'already_exists'  => $alreadyExists,
            'no_eligible'     => $noEligible,
        ], $message);
    }

    // ── Mark payout as paid ───────────────────────────────────────────────────

    // ENTERPRISE GAP FIX (Section 7 — "payouts have no maker-checker
    // control"): a single admin could both decide a payout should be paid
    // and confirm it was paid — no second set of eyes on money actually
    // leaving to a vendor. This is now a required two-step flow: the first
    // admin to act "requests" the payout (approval_status: none → pending),
    // no money-movement state changes yet; a DIFFERENT admin must then
    // approve, which is the only path that actually calls
    // PayoutService::markProcessed(). The same admin cannot do both steps —
    // enforced server-side (requested_by !== current user), not just hidden
    // in the UI, since a client-side-only check is not a control at all.
    //
    // TRACE: admin clicks "Mark Paid" → this fires once per click →
    //        no existing request (approval_status='none') → sets
    //        approval_status='pending', requested_by/at → returns
    //        "awaiting second admin" message, no PayoutService call yet →
    //        a DIFFERENT admin clicks the now-relabelled "Approve & Pay" →
    //        approval_status='pending' AND requested_by !== current user →
    //        calls PayoutService::markProcessed() → sets approval_status='approved' →
    //        preconditions: rto_is_admin(); valid nonce →
    //        postconditions: first call mutates only approval_status/requested_by/at
    //        (payout.status stays 'pending', no vendor notification, no balance
    //        change); second call additionally does everything markProcessed() does →
    //        edge cases: same admin tries both steps → rejected with an explicit
    //        message naming the requesting admin; payout already paid → rejected
    //        by markProcessed()'s own guard
    public function markPaid(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id      = Sanitiser::int($_POST['payout_id'] ?? 0, 1);
        $ref     = Sanitiser::text($_POST['payment_ref'] ?? '');
        $method  = Sanitiser::text($_POST['method'] ?? 'bank_transfer');
        $uid     = get_current_user_id();

        $payout = $this->db->get_row($this->db->prepare(
            "SELECT id, status, approval_status, requested_by FROM {$this->p}rto_vendor_payouts WHERE id=%d", $id
        ), ARRAY_A);
        if (!$payout) rto_json_err('Payout not found.', 404);
        if ($payout['status'] === 'paid') rto_json_err('This payout is already marked as paid.');

        if (($payout['approval_status'] ?? 'none') !== 'pending') {
            // Step 1: request. No money has moved yet, so no payment
            // reference exists yet either — do NOT require $ref here (it is
            // only required for step 2, the actual approve-and-pay call).
            $updated = $this->db->update($this->p . 'rto_vendor_payouts', [
                'approval_status' => 'pending',
                'requested_by'    => $uid,
                'requested_at'    => current_time('mysql'),
            ], ['id' => $id]);
            if ($updated === false) {
                error_log('RTOFLOW: payout approval-request DB update failed for payout_id=' . $id . ' — ' . $this->db->last_error);
                rto_json_err('Failed to request payout approval. Please try again.', 500);
            }
            \RTOFLOW\Services\AuditService::log('payout.approval_requested', null, ['payout_id' => $id]);
            rto_json_ok(['payout_id' => $id, 'stage' => 'requested'],
                'Payment requested. A different admin must approve this payout before it is marked paid.');
            return;
        }

        if (!$ref) rto_json_err('Payment reference is required to approve and pay.');

        // Step 2: approve. Must be a different admin than the one who requested it.
        if ((int)$payout['requested_by'] === $uid) {
            $requester = get_userdata((int)$payout['requested_by']);
            rto_json_err('You requested this payment yourself — a different admin ('
                . ($requester ? 'not ' . $requester->display_name : 'someone else')
                . ') must approve it before it can be marked paid.');
        }

        // FIX P0-5: this controller used to reimplement PayoutService::markProcessed()
        // inline (its own status/utr/balance update, no AuditService::log call, no
        // vendor notification) — a second, divergent "mark paid" code path from the
        // one PayoutService itself exposes. There is now exactly one place a payout
        // transitions to 'paid': PayoutService::markProcessed(), so every payout gets
        // an audit entry and a vendor notification regardless of which screen
        // triggered it.
        $svc    = new PayoutService(new TdsService());
        $result = $svc->markProcessed($id, $ref, $method, $uid);
        if (!$result['success']) {
            rto_json_err($result['message'] ?? 'Could not mark payout as paid.');
        }

        $approvalUpdated = $this->db->update($this->p . 'rto_vendor_payouts', ['approval_status' => 'approved'], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase, and especially important here since this
        // is a payments/money path): PayoutService::markProcessed() above
        // already succeeded and the payout is genuinely marked paid at this
        // point, so this is logged rather than surfaced as a hard failure to
        // the admin — but silently discarding it would have meant
        // approval_status could drift out of sync with the real paid state
        // with no record anywhere that it happened.
        if ($approvalUpdated === false) {
            error_log("RTOFLOW PayoutsController::approve(): payout {$id} was marked paid but approval_status update failed.");
        }
        \RTOFLOW\Services\AuditService::log('payout.approved', null, ['payout_id' => $id, 'requested_by' => (int)$payout['requested_by'], 'approved_by' => $uid]);

        rto_json_ok(['payout_id' => $id, 'stage' => 'approved'], 'Payout approved and marked as paid.');
    }

    // ── Download TDS certificate ──────────────────────────────────────────────

    public function tdsCertificate(int $id): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 'Access Denied', ['response' => 403]);

        $payout = $this->db->get_row($this->db->prepare(
            "SELECT po.*, v.full_name, v.vendor_number, v.pan_enc, v.address
             FROM {$this->p}rto_vendor_payouts po
             LEFT JOIN {$this->p}rto_vendors v ON v.id=po.vendor_id
             WHERE po.id=%d", $id
        ), ARRAY_A);

        if (!$payout || $payout['tds_amount'] <= 0) {
            wp_die('TDS certificate not available.', 'Not Found', ['response' => 404]);
        }

        // FIX P0-8: full PAN decrypted only here — this is the one screen with a
        // genuine statutory need for it (Form 16A-style certificate under Section
        // 194C). Access is already gated by rto_is_admin() above.
        $payout['pan'] = \RTOFLOW\Security\Encryption::decryptSafe($payout['pan_enc'] ?? '') ?: '';
        \RTOFLOW\Services\AuditService::log('vendor.pan_revealed', null, [
            'payout_id' => $id, 'vendor_id' => (int)$payout['vendor_id'], 'context' => 'tds_certificate',
        ]);

        $company = get_option('rtoflow_company_name', 'RTOFLOW Solutions');
        $gstin   = get_option('rtoflow_company_gstin', '');
        $tan     = get_option('rtoflow_company_tan', '');
        $period  = $payout['period'];

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>TDS Certificate — ' . esc_html($payout['vendor_number']) . '</title>
        <style>
          body { font-family: Arial, sans-serif; font-size: 13px; color: #333; margin: 40px; }
          h1 { text-align: center; font-size: 18px; border-bottom: 2px solid #333; padding-bottom: 8px; }
          table { width: 100%; border-collapse: collapse; margin: 16px 0; }
          th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
          th { background: #f5f5f5; font-weight: 600; }
          .total { font-weight: 700; background: #e8f4e8; }
          .footer { margin-top: 40px; font-size: 11px; color: #777; text-align: center; }
        </style></head><body>
        <h1>CERTIFICATE OF TAX DEDUCTED AT SOURCE</h1>
        <p style="text-align:center">Under Section 194C of Income Tax Act, 1961</p>
        <table>
          <tr><th>Deductor Name</th><td>' . esc_html($company) . '</td><th>TAN</th><td>' . esc_html($tan ?: 'Not Configured') . '</td></tr>
          <tr><th>GSTIN</th><td>' . esc_html($gstin) . '</td><th>Period</th><td>' . esc_html($period) . '</td></tr>
        </table>
        <table>
          <tr><th>Deductee Name</th><td>' . esc_html($payout['full_name']) . '</td><th>Vendor No.</th><td>' . esc_html($payout['vendor_number']) . '</td></tr>
          <tr><th>PAN</th><td>' . esc_html($payout['pan'] ?: 'Not Provided') . '</td><th>Certificate No.</th><td>TDS-' . esc_html($payout['vendor_number']) . '-' . esc_html($period) . '</td></tr>
        </table>
        <table>
          <tr><th>Nature of Payment</th><th>Gross Amount (₹)</th><th>TDS Rate</th><th>TDS Amount (₹)</th><th>Net Paid (₹)</th></tr>
          <tr class="total">
            <td>Service Charges (194C)</td>
            <td>' . number_format((float)$payout['gross_amount'], 2) . '</td>
            <td>' . number_format((float)$payout['tds_rate'], 2) . '%</td>
            <td>' . number_format((float)$payout['tds_amount'], 2) . '</td>
            <td>' . number_format((float)$payout['net_amount'], 2) . '</td>
          </tr>
        </table>
        <p>I/We certify that a sum of <strong>Rs. ' . number_format((float)$payout['tds_amount'], 2) . '/-</strong> has been deducted at source and shall be deposited to the credit of the Central Government.</p>
        <p>Payment Reference: <strong>' . esc_html($payout['payment_ref'] ?? 'Pending') . '</strong></p>
        <div class="footer">Generated by RTOFLOW OS · ' . current_time('d-m-Y') . '</div>
        </body></html>';

        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Disposition: attachment; filename="TDS-' . $payout['vendor_number'] . '-' . $period . '.html"');
        echo $html;
        exit;
    }
}
