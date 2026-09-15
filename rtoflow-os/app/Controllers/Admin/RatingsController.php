<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

class RatingsController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    // ── Client submits a rating ───────────────────────────────────────────────

    public function store(): void
    {
        // FIX (10-workstream integration pass follow-up): 'vendor_ratings'
        // was defined in FeatureFlags but never checked — disabling it from
        // the admin Feature Flags screen had no effect on this endpoint.
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('vendor_ratings')) {
            rto_json_err('Vendor ratings are currently unavailable.', 404);
        }
        if (!is_user_logged_in()) rto_json_err('You must be logged in to rate.', 401);
        if (!check_ajax_referer('rto_client_action', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId  = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $score   = Sanitiser::int($_POST['score']   ?? 0);
        $comment = Sanitiser::text($_POST['comment'] ?? '', 500);

        if ($score < 1 || $score > 5) rto_json_err('Rating must be between 1 and 5 stars.');

        // Verify lead belongs to this client and is completed
        $lead = $this->db->get_row($this->db->prepare(
            "SELECT l.*, v.id as vid FROM {$this->p}rto_leads l
             LEFT JOIN {$this->p}rto_vendors v ON v.id=l.vendor_id
             WHERE l.id=%d AND l.client_id=%d AND l.status='completed' AND l.deleted_at IS NULL",
            $leadId, get_current_user_id()
        ), ARRAY_A);

        if (!$lead) rto_json_err('Order not found or not yet completed.');

        // Check already rated (a soft-deleted prior rating does not block a
        // fresh submission — see the Known Limitations audit's soft-delete fix).
        $existing = $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->p}rto_ratings WHERE lead_id=%d AND rated_by=%d AND deleted_at IS NULL",
            $leadId, get_current_user_id()
        ));
        if ($existing) rto_json_err('You have already rated this order.');

        // Insert rating
        $inserted = $this->db->insert($this->p . 'rto_ratings', [
            'lead_id'    => $leadId,
            'vendor_id'  => $lead['vendor_id'],
            'rated_by'   => get_current_user_id(),
            'rated_role' => 'vendor',
            'score'      => $score,
            'comment'    => $comment,
            'created_at' => current_time('mysql'),
        ]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase, caught while wiring the client rating
        // widget up to this endpoint this session): this always thanked the
        // client for their feedback regardless of whether the INSERT
        // actually happened.
        if (!$inserted) rto_json_err('Could not save your rating. Please try again.', 500);

        // Update vendor average rating
        if ($lead['vid']) {
            $this->recalcVendorAverage((int)$lead['vid']);
        }

        rto_json_ok(['score' => $score], 'Thank you for your feedback!');
    }

    // ── Shared: recalculate one vendor's average rating from non-deleted
    // ratings only (soft-deleted rows never count towards the live score
    // that feeds auto-assignment) ────────────────────────────────────────
    private function recalcVendorAverage(int $vendorId): float
    {
        $avg = (float)($this->db->get_var($this->db->prepare(
            "SELECT ROUND(AVG(score),2) FROM {$this->p}rto_ratings WHERE vendor_id=%d AND rated_role='vendor' AND deleted_at IS NULL",
            $vendorId
        )) ?: 0);
        $this->db->update($this->p . 'rto_vendors', ['rating' => $avg, 'updated_at' => current_time('mysql')], ['id' => $vendorId]);
        return $avg;
    }

    // ── Admin: view all ratings ───────────────────────────────────────────────

    public function index(): void
    {
        $vendorId  = Sanitiser::int($_GET['vendor_id'] ?? 0);
        $score     = Sanitiser::int($_GET['score']     ?? 0);
        // Known Limitations audit fix: deletion is now a soft-delete, so the
        // live list must exclude soft-deleted rows by default; a separate
        // ?trash=1 view lists exactly those rows with a Restore action —
        // "no undo" is fixed by making the deleted row itself recoverable,
        // not by a time-boxed grace period that would eventually re-hide it.
        $showTrash = !empty($_GET['trash']);
        $where     = [$showTrash ? 'r.deleted_at IS NOT NULL' : 'r.deleted_at IS NULL'];
        $params    = [];

        if ($vendorId) { $where[] = 'r.vendor_id=%d'; $params[] = $vendorId; }
        if ($score)    { $where[] = 'r.score=%d';     $params[] = $score; }
        $ws = implode(' AND ', $where);

        // CSV export: same filtered WHERE clause as the list below, but ALL
        // matching rows. 'ratings' routes unconditionally to index() so the
        // export is detected here rather than via a new Router.php dispatch arm.
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->exportCsv($ws, $params);
            return;
        }

        // FIX P0: real pagination — this previously hardcoded LIMIT 100 with
        // no page control, silently hiding ratings beyond the 100th row and
        // giving no way to reach them.
        $perPage  = 25;
        $page     = Sanitiser::int($_GET['paged'] ?? 1, 1);
        $countSql = "SELECT COUNT(*) FROM {$this->p}rto_ratings r WHERE {$ws}";
        $total    = $params ? (int)$this->db->get_var($this->db->prepare($countSql, $params)) : (int)$this->db->get_var($countSql);
        $lastPage = max(1, (int)ceil($total / $perPage));
        $page     = min($page, $lastPage);
        $offset   = ($page - 1) * $perPage;

        $listSql = "SELECT r.*, u.display_name as client_name, v.full_name as vendor_name, l.lead_number
                     FROM {$this->p}rto_ratings r
                     LEFT JOIN {$this->p}users u ON u.ID=r.rated_by
                     LEFT JOIN {$this->p}rto_vendors v ON v.id=r.vendor_id
                     LEFT JOIN {$this->p}rto_leads l ON l.id=r.lead_id
                     WHERE {$ws} ORDER BY r.created_at DESC LIMIT %d OFFSET %d";
        $ratings = $this->db->get_results($this->db->prepare($listSql, [...$params, $perPage, $offset]), ARRAY_A) ?: [];

        // FIX P0: the headline average shown at the top of this page must be
        // computed over the full filtered set, not just the current page's
        // rows — the view previously derived it via array_sum() on $ratings,
        // which paginating the fetch would have silently made page-scoped.
        $avgSql = "SELECT ROUND(AVG(r.score),1) FROM {$this->p}rto_ratings r WHERE {$ws}";
        $avg    = (float)($params ? $this->db->get_var($this->db->prepare($avgSql, $params)) : $this->db->get_var($avgSql));

        $vendors = $this->db->get_results(
            "SELECT id, full_name FROM {$this->p}rto_vendors ORDER BY full_name", ARRAY_A
        ) ?: [];

        // Count of currently soft-deleted rows, so the list view can link to
        // the trash view without a separate round trip.
        $trashCount = (int)$this->db->get_var("SELECT COUNT(*) FROM {$this->p}rto_ratings WHERE deleted_at IS NOT NULL");

        rto_view('admin.ratings.index', compact('ratings', 'vendors', 'vendorId', 'score', 'page', 'lastPage', 'total', 'avg', 'showTrash', 'trashCount'));
    }

    // ── Admin: delete a rating (abuse/spam) ───────────────────────────────────

    // FIX (bulk-ops build): extended to accept EITHER a single 'rating_id'
    // (existing per-row Remove button, unchanged behaviour) OR an array of
    // 'rating_ids[]' (new bulk-delete-selected bar on the list view). Both
    // paths delete each rating, recalc that vendor's average, and audit-log
    // per rating — same as the single-id path always did, just looped.
    //
    // Known Limitations audit fix: "Deletion is permanent with no undo, even
    // though it immediately affects a live-used scoring input." This is now
    // a soft-delete (deleted_at/deleted_by set, row excluded from the live
    // list and from the vendor-average calculation) instead of a hard
    // DELETE — see restore() below for the recovery path this enables.
    public function delete(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $ids = array_values(array_filter(array_map('intval', (array)($_POST['rating_ids'] ?? []))));
        if (empty($ids)) {
            $single = Sanitiser::int($_POST['rating_id'] ?? 0, 1);
            if ($single > 0) $ids = [$single];
        }
        if (empty($ids)) rto_json_err('No rating(s) selected.');

        $deleted = 0;
        $vendorsToRecalc = [];
        foreach ($ids as $id) {
            $rating = $this->db->get_row($this->db->prepare(
                "SELECT * FROM {$this->p}rto_ratings WHERE id=%d AND deleted_at IS NULL", $id
            ), ARRAY_A);
            if (!$rating) continue;

            $result = $this->db->update($this->p . 'rto_ratings', [
                'deleted_at' => current_time('mysql'),
                'deleted_by' => get_current_user_id(),
            ], ['id' => $id]);
            // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
            // throughout this codebase): $deleted previously counted every
            // row FOUND, not every row actually updated — a failed write
            // here would still report "N rating(s) removed" to the admin.
            if ($result === false) {
                error_log("RTOFLOW RatingsController::delete(): soft-delete failed for rating {$id}.");
                continue;
            }
            AuditService::log('rating.deleted', (int)$rating['lead_id'], [], $rating);
            $deleted++;

            if ($rating['vendor_id']) $vendorsToRecalc[(int)$rating['vendor_id']] = true;
        }

        if ($deleted === 0) rto_json_err('Rating not found.');

        foreach (array_keys($vendorsToRecalc) as $vendorId) {
            $this->recalcVendorAverage($vendorId);
        }

        rto_json_ok(['deleted' => $deleted], $deleted . ' rating(s) removed (recoverable from Trash) and vendor score(s) recalculated.');
    }

    // ── Admin: restore a soft-deleted rating ──────────────────────────────────
    // Known Limitations audit fix companion to delete() above — makes a
    // moderation mistake (wrong row selected, later found to be legitimate)
    // actually recoverable, closing the "no undo" gap for real rather than
    // adding a time-boxed grace period that would eventually make it
    // unrecoverable again anyway.
    public function restore(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['rating_id'] ?? 0, 1);
        $rating = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->p}rto_ratings WHERE id=%d AND deleted_at IS NOT NULL", $id
        ), ARRAY_A);
        if (!$rating) rto_json_err('Rating not found in Trash.');

        $restored = $this->db->update($this->p . 'rto_ratings', ['deleted_at' => null, 'deleted_by' => null], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported success back to
        // the admin regardless of whether the DB write actually happened.
        if ($restored === false) rto_json_err('Could not restore the rating. Please try again.', 500);
        AuditService::log('rating.restored', (int)$rating['lead_id'], $rating, []);

        if ($rating['vendor_id']) {
            $this->recalcVendorAverage((int)$rating['vendor_id']);
        }

        rto_json_ok(null, 'Rating restored and vendor score recalculated.');
    }

    // ── Admin: correct an obviously miskeyed score, preserving the comment ────
    // Known Limitations audit fix: "No edit action exists for correcting a
    // rating's score or comment ... the only option is to delete it
    // entirely, losing the (possibly legitimate) underlying feedback."
    // original_score is captured once, on the FIRST edit only, so the
    // client's true original submission is never lost even across multiple
    // corrections; every edit is separately audit-logged for accountability.
    public function updateScore(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id       = Sanitiser::int($_POST['rating_id'] ?? 0, 1);
        $newScore = Sanitiser::int($_POST['score'] ?? 0);
        if ($newScore < 1 || $newScore > 5) rto_json_err('Score must be between 1 and 5 stars.');

        $rating = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->p}rto_ratings WHERE id=%d AND deleted_at IS NULL", $id
        ), ARRAY_A);
        if (!$rating) rto_json_err('Rating not found.');

        $update = [
            'score'     => $newScore,
            'edited_at' => current_time('mysql'),
            'edited_by' => get_current_user_id(),
        ];
        if ($rating['original_score'] === null) {
            $update['original_score'] = (int)$rating['score'];
        }
        $this->db->update($this->p . 'rto_ratings', $update, ['id' => $id]);

        AuditService::log('rating.score_edited', (int)$rating['lead_id'], $update, ['score' => $rating['score']]);

        if ($rating['vendor_id']) {
            $this->recalcVendorAverage((int)$rating['vendor_id']);
        }

        rto_json_ok(['score' => $newScore], 'Score corrected to ' . $newScore . ' — comment preserved, edit logged, vendor score recalculated.');
    }

    // ── CSV export (GET, ?export=csv on the ratings list) ────────────────────
    // TRACE: admin visits /rto-admin/ratings/?export=csv&vendor_id=&score= →
    //        index() detects export=csv and calls this with the SAME $ws/$params
    //        it just built → fetches ALL matching rows (capped 5000) → streams CSV.
    private function exportCsv(string $ws, array $params): void
    {
        if (!rto_is_staff()) wp_die('Access denied.', 403);

        $sql = "SELECT r.*, u.display_name as client_name, v.full_name as vendor_name, l.lead_number
                 FROM {$this->p}rto_ratings r
                 LEFT JOIN {$this->p}users u ON u.ID=r.rated_by
                 LEFT JOIN {$this->p}rto_vendors v ON v.id=r.vendor_id
                 LEFT JOIN {$this->p}rto_leads l ON l.id=r.lead_id
                 WHERE {$ws} ORDER BY r.created_at DESC LIMIT 5000";
        $ratings = $params
            ? $this->db->get_results($this->db->prepare($sql, $params), ARRAY_A)
            : $this->db->get_results($sql, ARRAY_A);
        $ratings = $ratings ?: [];

        $filename = 'rtoflow-ratings-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Order #','Client','Vendor','Score','Comment','Created At']);

        foreach ($ratings as $r) {
            fputcsv($out, [
                $r['lead_number']  ?? '',
                $r['client_name']  ?? '',
                $r['vendor_name']  ?? '',
                $r['score']        ?? '',
                $r['comment']      ?? '',
                $r['created_at']   ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
}
