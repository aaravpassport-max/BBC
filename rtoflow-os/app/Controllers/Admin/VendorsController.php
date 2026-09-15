<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\NotificationService;

if (!defined('ABSPATH')) exit;

class VendorsController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    // ── List ──────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $search = Sanitiser::text($_GET['search'] ?? '');
        $status = Sanitiser::text($_GET['status'] ?? '');
        $kyc    = Sanitiser::text($_GET['kyc']    ?? '');

        $where  = ['1=1']; $params = [];
        if ($search) {
            $s = '%' . $this->db->esc_like($search) . '%';
            $where[] = '(v.full_name LIKE %s OR v.vendor_number LIKE %s OR v.mobile LIKE %s OR u.user_email LIKE %s)';
            $params  = array_merge($params, [$s,$s,$s,$s]);
        }
        if ($status) { $where[] = 'v.status=%s';     $params[] = $status; }
        if ($kyc)    { $where[] = 'v.kyc_status=%s'; $params[] = $kyc; }

        // FIX P0: real pagination — this previously loaded the entire
        // rto_vendors table on every request regardless of table size.
        $ws       = implode(' AND ', $where);

        // CSV export: same filter WHERE clause as the paginated list, but ALL
        // matching rows. 'vendors' routes unconditionally to index() so the
        // export is detected here rather than via a new Router.php dispatch arm.
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->exportCsv($ws, $params);
            return;
        }

        $perPage  = 25;
        $page     = Sanitiser::int($_GET['paged'] ?? 1, 1);
        $countSql = "SELECT COUNT(*) FROM {$this->p}rto_vendors v LEFT JOIN {$this->p}users u ON u.ID=v.user_id WHERE {$ws}";
        $total    = $params ? (int)$this->db->get_var($this->db->prepare($countSql, $params)) : (int)$this->db->get_var($countSql);
        $lastPage = max(1, (int)ceil($total / $perPage));
        $page     = min($page, $lastPage);
        $offset   = ($page - 1) * $perPage;

        $sql     = "SELECT v.*, u.user_email FROM {$this->p}rto_vendors v LEFT JOIN {$this->p}users u ON u.ID=v.user_id WHERE {$ws} ORDER BY v.created_at DESC LIMIT %d OFFSET %d";
        $vendors = $this->db->get_results($this->db->prepare($sql, [...$params, $perPage, $offset]), ARRAY_A) ?: [];

        rto_view('admin.vendors.index', compact('vendors','search','status','kyc','page','lastPage','total'));
    }

    // ── Create form ───────────────────────────────────────────────────────────

    public function create(): void
    {
        $services = $this->db->get_results("SELECT id,name,category FROM {$this->p}rto_services WHERE is_active=1 ORDER BY category,name", ARRAY_A) ?: [];
        $states   = $this->db->get_results("SELECT id,name FROM {$this->p}rto_states ORDER BY name", ARRAY_A) ?: [];
        $cities   = $this->db->get_results("SELECT c.id,c.name,c.rto_code,s.name as state_name FROM {$this->p}rto_cities c JOIN {$this->p}rto_states s ON s.id=c.state_id ORDER BY s.name,c.name", ARRAY_A) ?: [];
        rto_view('admin.vendors.form', ['vendor'=>null,'services'=>$services,'states'=>$states,'cities'=>$cities,'mode'=>'create','errors'=>[]]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(): void
    {
        if (!check_admin_referer('rtoflow_vendor_save','rtoflow_nonce')) wp_die('Security check failed.','Error',['response'=>403]);

        $data = $this->extractFormData();
        $errs = $this->validateVendor($data);

        if ($errs) {
            $services = $this->db->get_results("SELECT id,name,category FROM {$this->p}rto_services WHERE is_active=1 ORDER BY category,name", ARRAY_A) ?: [];
            $states   = $this->db->get_results("SELECT id,name FROM {$this->p}rto_states ORDER BY name", ARRAY_A) ?: [];
            $cities   = $this->db->get_results("SELECT c.id,c.name,c.rto_code,s.name as state_name FROM {$this->p}rto_cities c JOIN {$this->p}rto_states s ON s.id=c.state_id ORDER BY s.name,c.name", ARRAY_A) ?: [];
            rto_view('admin.vendors.form', compact('services','states','cities','errs') + ['vendor'=>$data,'mode'=>'create','errors'=>$errs]);
            return;
        }

        // Create WP user if email provided without existing account
        $userId = (int)($data['user_id'] ?? 0);
        if (!$userId && $data['email']) {
            if (email_exists($data['email'])) {
                $u = get_user_by('email', $data['email']);
                $userId = $u->ID;
            } else {
                // ENTERPRISE GAP FIX: this generated a real password and then
                // never showed or emailed it to anyone — a fix already made
                // once for admin.users.create() (see Router.php's own
                // extensive comment on this exact pattern) but never applied
                // here, this form's sibling vendor-creation path. Every
                // vendor onboarded through "Add Vendor" got an account whose
                // password only WordPress's database knew, reachable only by
                // guessing to use "Forgot Password" with no invitation ever
                // telling them the account existed. Same fix: an unusable
                // random secret plus wp_new_user_notification()'s real
                // "set your password" email, so the account is actually
                // usable the moment this call succeeds.
                $pw     = wp_generate_password(24, true);
                $userId = wp_create_user($data['email'], $pw, $data['email']);
                if (is_wp_error($userId)) wp_die($userId->get_error_message());
                wp_update_user(['ID'=>$userId,'display_name'=>$data['full_name']]);
                wp_new_user_notification($userId, null, 'user');
            }
        }
        $u = new \WP_User($userId);
        $u->add_role('rto_vendor');

        $vendorNum = 'VND-' . strtoupper(date('Y')) . '-' . str_pad($this->db->get_var("SELECT COUNT(*)+1 FROM {$this->p}rto_vendors"), 4, '0', STR_PAD_LEFT);

        // FIX P0-8: PAN, Aadhaar, and bank account/IFSC are now written ONLY to the
        // AES-256-GCM encrypted columns (pan_enc, aadhaar_enc, bank_details_enc) via
        // RTOFLOW\Security\Encryption — the same class already used for TwoFactor
        // secrets and signed download tokens elsewhere in this codebase. The plain
        // pan/aadhaar/bank_details columns are left blank on every new vendor; see
        // migration 2024_01_01_000008_encrypt_vendor_pii for the one-time backfill of
        // vendors created before this fix. aadhaar_hash (one-way HMAC) is kept for
        // duplicate-Aadhaar detection without ever storing or comparing plaintext.
        $bankDetailsEnc = $data['bank_account'] || $data['bank_ifsc'] || $data['bank_name']
            ? \RTOFLOW\Security\Encryption::encrypt(wp_json_encode([
                'bank_name'    => $data['bank_name'],
                'bank_account' => $data['bank_account'],
                'bank_ifsc'    => $data['bank_ifsc'],
            ]))
            : null;

        $inserted = $this->db->insert($this->p . 'rto_vendors', [
            'user_id'          => $userId,
            'vendor_number'    => $vendorNum,
            'full_name'        => $data['full_name'],
            'mobile'           => $data['mobile'],
            'email'            => $data['email'],
            'pan_enc'          => $data['pan'] ? \RTOFLOW\Security\Encryption::encrypt($data['pan']) : null,
            'aadhaar_enc'      => $data['aadhaar'] ? \RTOFLOW\Security\Encryption::encrypt($data['aadhaar']) : null,
            'aadhaar_hash'     => $data['aadhaar'] ? \RTOFLOW\Security\Encryption::hmac($data['aadhaar']) : null,
            'address'          => $data['address'],
            'bank_details_enc' => $bankDetailsEnc,
            'cities'           => wp_json_encode(array_map('intval', (array)($_POST['city_ids'] ?? []))),
            'services'         => wp_json_encode(array_map('intval', (array)($_POST['service_ids'] ?? []))),
            'status'           => 'active',
            'kyc_status'       => 'pending',
            'rating'           => 0,
            'created_at'       => current_time('mysql'),
        ]);

        if (!$inserted) {
            error_log('RTOFLOW VendorsController: vendor insert failed — ' . $this->db->last_error);
            wp_die('Could not save vendor. Please try again.', 'Error', ['response' => 500, 'back_link' => true]);
        }

        // FIX (integration pass, Reporting/Audit consistency gap): vendor
        // creation had no audit trail at all — flagged as a gap by the
        // Auditable-Controller-Convention pass but left unfixed there since
        // this file was excluded from that pass to avoid a parallel-edit
        // conflict with the vendor-suspension/reassignment work also in
        // flight on this file at the time.
        \RTOFLOW\Services\AuditService::log('vendor.created', null, [
            'id' => (int)$this->db->insert_id, 'vendor_number' => $vendorNum, 'full_name' => $data['full_name'],
        ]);

        // FIX (Vendor Coverage Engine wiring — integration pass follow-up):
        // rto_vendor_coverage (built by the Location/Serviceability
        // workstream, see the plan doc's Part 1.3) was additive-only —
        // nothing kept it in sync with the actual JSON cities/services
        // columns written here and in updateCoverage() below, so any vendor
        // created or edited after the one-time backfill would silently
        // drift from the new table. auto_assign() still reads the old
        // JSON-based VendorRepository::get_eligible() (that cutover is
        // separate future work, not done here), but keeping the coverage
        // table itself accurate now — rather than only at backfill time —
        // is a prerequisite for that cutover ever being safe to do later.
        (new \RTOFLOW\Repositories\VendorCoverageRepository())->sync_vendor_coverage(
            (int)$this->db->insert_id,
            (array)($_POST['city_ids'] ?? []),
            (array)($_POST['service_ids'] ?? [])
        );

        wp_redirect(home_url('/rto-admin/vendors/?saved=1'));
        exit;
    }

    // ── Show / Edit ───────────────────────────────────────────────────────────

    public function show(int $id): void
    {
        // ENTERPRISE GAP FIX (Phase 1, item 6 — granular RBAC): the vendor
        // detail screen is reachable by any rto_staff account via the
        // page-level gate in Router::routeAdmin() — this is the real PII
        // surface (masked PAN/Aadhaar/bank details below), so a staff role
        // without 'vendors_pii' view access is stopped here specifically,
        // separate from the general vendors list/show gate.
        if (!\RTOFLOW\Security\Permissions::can(get_current_user_id(), 'vendors_pii', 'view')) {
            wp_die('Access denied. Your staff role does not have access to vendor PII details.', 403);
        }

        $vendor = $this->db->get_row($this->db->prepare(
            "SELECT v.*, u.user_email FROM {$this->p}rto_vendors v LEFT JOIN {$this->p}users u ON u.ID=v.user_id WHERE v.id=%d", $id
        ), ARRAY_A);
        if (!$vendor) wp_die('Vendor not found.','Not Found',['response'=>404,'back_link'=>true]);

        $leads = $this->db->get_results($this->db->prepare(
            "SELECT l.id,l.lead_number,l.status,l.created_at,l.total_amount,s.name as service_name,c.name as city_name
             FROM {$this->p}rto_leads l
             LEFT JOIN {$this->p}rto_services s ON s.id=l.service_id
             LEFT JOIN {$this->p}rto_cities c ON c.id=l.city_id
             WHERE l.vendor_id=%d AND l.deleted_at IS NULL
             ORDER BY l.created_at DESC LIMIT 20", $id
        ), ARRAY_A) ?: [];

        $services   = $this->db->get_results("SELECT id,name,category FROM {$this->p}rto_services WHERE is_active=1 ORDER BY category,name", ARRAY_A) ?: [];
        $allCities  = $this->db->get_results("SELECT c.id,c.name,c.rto_code,s.name as state_name FROM {$this->p}rto_cities c JOIN {$this->p}rto_states s ON s.id=c.state_id ORDER BY s.name,c.name", ARRAY_A) ?: [];
        $vendorCities   = json_decode($vendor['cities']   ?? '[]', true) ?: [];
        $vendorServices = json_decode($vendor['services'] ?? '[]', true) ?: [];

        // FIX P0-8: decrypt for display, but only into masked form — the admin
        // detail screen has never needed the full PAN/Aadhaar/account number,
        // only enough to confirm which record they're looking at. Full values
        // are decrypted on demand only where a real business need exists
        // (the TDS certificate export in PayoutsController::tdsCertificate()).
        $panPlain     = \RTOFLOW\Security\Encryption::decryptSafe($vendor['pan_enc'] ?? '');
        $aadhaarPlain = \RTOFLOW\Security\Encryption::decryptSafe($vendor['aadhaar_enc'] ?? '');
        $bankPlain    = json_decode(\RTOFLOW\Security\Encryption::decryptSafe($vendor['bank_details_enc'] ?? ''), true) ?: [];

        $vendor['pan']          = $panPlain ? \RTOFLOW\Security\Encryption::maskPan($panPlain) : '';
        $vendor['aadhaar']      = $aadhaarPlain ? \RTOFLOW\Security\Encryption::maskAadhaar($aadhaarPlain) : '';
        $vendor['bank_name']    = $bankPlain['bank_name'] ?? '';
        $vendor['bank_account'] = $bankPlain['bank_account'] ?? '';
        $vendor['bank_ifsc']    = $bankPlain['bank_ifsc'] ?? '';

        // Performance stats
        // Known Limitations audit fix (global consistency pass, root-cause
        // discovery while fixing Ratings): this query referenced a column
        // named 'rating_score', which has never existed on either rto_leads
        // or rto_ratings (the real column is rto_ratings.score) — a raw
        // $wpdb query against an unknown column returns false/null, so
        // $stats (and every field this view reads from it) has always been
        // silently empty here. Also now excludes soft-deleted ratings so a
        // moderated-out rating does not skew this screen's own average.
        $stats = $this->db->get_row($this->db->prepare(
            "SELECT COUNT(*) as total, SUM(l.status='completed') as completed, SUM(l.status='cancelled') as cancelled,
             COALESCE(AVG(r.score),0) as avg_rating
             FROM {$this->p}rto_leads l
             LEFT JOIN {$this->p}rto_ratings r ON r.lead_id=l.id AND r.rated_role='vendor' AND r.deleted_at IS NULL
             WHERE l.vendor_id=%d AND l.deleted_at IS NULL", $id
        ), ARRAY_A);

        // ENTERPRISE GAP FIX (Section 1 — vendor KYC document-review workflow)
        $kycDocuments = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->p}rto_vendor_documents WHERE vendor_id=%d ORDER BY created_at DESC", $id
        ), ARRAY_A) ?: [];

        rto_view('admin.vendors.show', compact('vendor','leads','services','allCities','vendorCities','vendorServices','stats','kycDocuments'));
    }

    // ── AJAX actions ──────────────────────────────────────────────────────────

    public function updateKyc(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id     = Sanitiser::int($_POST['vendor_id'] ?? 0, 1);
        $status = Sanitiser::text($_POST['kyc_status'] ?? '');
        $note   = Sanitiser::text($_POST['note'] ?? '', 500);

        if (!in_array($status, ['pending','verified','rejected'], true)) rto_json_err('Invalid status.');

        // Known Limitations audit fix ("Marking KYC 'verified' without
        // actually reviewing the uploaded documents"): this cannot force an
        // admin to actually open and read the documents, but it can — and
        // now does — require a real reviewer note before the platform-wide
        // assignment-eligibility gate can be flipped on, so "verified" can
        // no longer be a single accidental click with zero record of what
        // was checked. Rejecting is left unrestricted — flagging a concern
        // should never be harder than clearing one.
        if ($status === 'verified' && $note === '') {
            rto_json_err('A review note is required to mark KYC verified — briefly note what you checked (e.g. "ID + address proof match, PAN verified").');
        }

        $updated = $this->db->update($this->p . 'rto_vendors', [
            'kyc_status'  => $status,
            'kyc_note'    => $note,
            'kyc_date'    => current_time('mysql'),
            'updated_at'  => current_time('mysql'),
        ], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported success back to
        // the admin regardless of whether the DB write actually happened —
        // an especially bad place for it given this gates auto-assignment
        // eligibility (see the audit-trail note just below).
        if ($updated === false) rto_json_err('Could not update KYC status. Please try again.', 500);

        // FIX (integration pass): KYC status changes gate whether a vendor
        // can receive auto-assignments at all (VendorRepository::get_eligible()
        // requires kyc_status='verified') — a support-relevant decision with
        // no audit trail before this fix.
        \RTOFLOW\Services\AuditService::log('vendor.kyc_status_changed', null, ['vendor_id' => $id, 'kyc_status' => $status, 'note' => $note]);

        rto_json_ok(['kyc_status' => $status], 'KYC status updated.');
    }

    // ENTERPRISE GAP FIX (gap-analysis Section 1 — "vendor KYC has no
    // document-review workflow"): the admin-facing half — an admin can now
    // actually mark one specific uploaded document as verified, distinct
    // from the vendor's overall kyc_status (a vendor typically uploads
    // several documents; each is independently reviewable).
    public function verifyKycDocument(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $docId = Sanitiser::int($_POST['doc_id'] ?? 0, 1);
        $doc   = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_vendor_documents WHERE id=%d", $docId), ARRAY_A);
        if (!$doc) rto_json_err('Document not found.', 404);

        $updated = $this->db->update($this->p . 'rto_vendor_documents', [
            'status'      => 'verified',
            'verified_by' => get_current_user_id(),
            'verified_at' => current_time('mysql'),
        ], ['id' => $docId]);
        if ($updated === false) rto_json_err('Failed to update document status.', 500);

        \RTOFLOW\Services\AuditService::log('vendor.kyc_document_verified', null, ['vendor_id' => (int)$doc['vendor_id'], 'doc_id' => $docId, 'doc_label' => $doc['doc_label']]);
        rto_json_ok(['doc_id' => $docId], 'Document marked as verified.');
    }

    public function rejectKycDocument(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $docId  = Sanitiser::int($_POST['doc_id'] ?? 0, 1);
        $reason = Sanitiser::text($_POST['reason'] ?? '', 500);
        if (!$reason) rto_json_err('A reason is required to reject a document.');

        $doc = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_vendor_documents WHERE id=%d", $docId), ARRAY_A);
        if (!$doc) rto_json_err('Document not found.', 404);

        $updated = $this->db->update($this->p . 'rto_vendor_documents', [
            'status'        => 'rejected',
            'reject_reason' => $reason,
            'verified_by'   => get_current_user_id(),
            'verified_at'   => current_time('mysql'),
        ], ['id' => $docId]);
        if ($updated === false) rto_json_err('Failed to update document status.', 500);

        \RTOFLOW\Services\AuditService::log('vendor.kyc_document_rejected', null, ['vendor_id' => (int)$doc['vendor_id'], 'doc_id' => $docId, 'doc_label' => $doc['doc_label'], 'reason' => $reason]);

        // Notify the vendor so they know to re-upload — a rejected document
        // with no notification is a dead end the vendor has no way to
        // discover except by checking their profile page unprompted.
        $vendor = $this->db->get_row($this->db->prepare("SELECT user_id FROM {$this->p}rto_vendors WHERE id=%d", (int)$doc['vendor_id']), ARRAY_A);
        if ($vendor) {
            NotificationService::send('vendor_kyc_document_rejected', [
                'doc_label' => $doc['doc_label'], 'reason' => $reason,
            ], (int)$vendor['user_id']);
        }

        rto_json_ok(['doc_id' => $docId], 'Document rejected. Vendor has been notified.');
    }

    // ── Secure file access for uploaded KYC documents ─────────────────────────
    // TRACE: admin clicks "View" on a KYC document row →
    //        GET /rto-admin/vendors/kyc-document/{id}/ →
    //        rto_is_admin() gate → resolves the stored path via
    //        FileUploadGuard's protected storage root → streams the file →
    //        preconditions: rto_is_admin() →
    //        postconditions: none (read-only) →
    //        edge cases: doc not found / file missing on disk → 404, never a
    //        raw filesystem error leaked to the browser
    public function serveKycDocument(int $docId): void
    {
        // ENTERPRISE GAP FIX (Section 1 follow-up): this was admin-only, but
        // the vendor-facing "View" link on their own uploaded documents
        // (resources/views/vendor/profile/index.php) needs to work too —
        // scoped strictly to the vendor who actually owns the document, not
        // any vendor, to avoid a horizontal privilege violation.
        $doc = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}rto_vendor_documents WHERE id=%d", $docId), ARRAY_A);
        if (!$doc) wp_die('Document not found.', 'Not Found', ['response' => 404, 'back_link' => true]);

        $isOwner = false;
        if (rto_is_vendor()) {
            $ownVendorId = $this->db->get_var($this->db->prepare(
                "SELECT id FROM {$this->p}rto_vendors WHERE user_id=%d", get_current_user_id()
            ));
            $isOwner = $ownVendorId && (int)$ownVendorId === (int)$doc['vendor_id'];
        }
        if (!rto_is_admin() && !$isOwner) wp_die('Access denied.', 'Access Denied', ['response' => 403, 'back_link' => true]);

        \RTOFLOW\Services\AuditService::log('vendor.kyc_document_viewed', null, ['vendor_id' => (int)$doc['vendor_id'], 'doc_id' => $docId, 'viewed_by_role' => rto_is_admin() ? 'admin' : 'vendor']);

        // FileUploadGuard::serve() already does path-traversal-safe
        // resolution against its own protected storage root, real MIME
        // detection, and a 404 (never a raw filesystem error) if the file
        // is missing — reused as-is rather than re-implemented here.
        \RTOFLOW\Security\FileUploadGuard::serve((string)$doc['file_path'], (string)$doc['file_name']);
    }

    public function updateCoverage(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id       = Sanitiser::int($_POST['vendor_id'] ?? 0, 1);
        $cityIds  = array_map('intval', (array)json_decode(stripslashes($_POST['city_ids'] ?? '[]'), true));
        $svcIds   = array_map('intval', (array)json_decode(stripslashes($_POST['service_ids'] ?? '[]'), true));
        $cityIds  = array_values(array_filter($cityIds));
        $svcIds   = array_values(array_filter($svcIds));

        $updated = $this->db->update($this->p . 'rto_vendors', [
            'cities'     => wp_json_encode($cityIds),
            'services'   => wp_json_encode($svcIds),
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported success back to
        // the admin regardless of whether the DB write actually happened.
        if ($updated === false) rto_json_err('Could not update coverage. Please try again.', 500);

        // FIX (integration pass): coverage changes directly control which
        // orders a vendor can be auto-assigned — previously unaudited.
        \RTOFLOW\Services\AuditService::log('vendor.coverage_updated', null, ['vendor_id' => $id, 'city_ids' => $cityIds, 'service_ids' => $svcIds]);

        // FIX (Vendor Coverage Engine wiring — see store() above for the
        // full rationale): this is the primary write path for coverage
        // changes on an existing vendor, so keeping rto_vendor_coverage in
        // sync here matters even more than at creation time.
        (new \RTOFLOW\Repositories\VendorCoverageRepository())->sync_vendor_coverage($id, $cityIds, $svcIds);

        rto_json_ok(null, 'Coverage updated successfully.');
    }

    // TRACE: admin POSTs admin.vendor_status via AJAX with vendor_id + status + reason →
    //        nonce 'rto_admin_lead' verified; rto_admin role enforced →
    //        status validated against ['active','inactive','suspended']; suspension requires non-empty reason →
    //        UPDATE rto_vendors SET status, [suspend_reason if suspended], updated_at WHERE id →
    //        vendor fetched with user JOIN → NotificationService::send() fires vendor_suspended or vendor_status_changed →
    //        AuditService::log('vendor.status_changed') written to rto_logs →
    //        rto_json_ok({status}) returned →
    //        preconditions: vendor exists, user is admin, nonce valid →
    //        postconditions: rto_vendors.status updated; vendor notified via email/SMS; audit entry created →
    //        edge cases: vendor not found (404), invalid status (400), suspension without reason (400),
    //                    vendor has no WP user (notification skipped silently)
    public function updateStatus(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id     = Sanitiser::int($_POST['vendor_id'] ?? 0, 1);
        $status = Sanitiser::text($_POST['status']   ?? '');
        $reason = Sanitiser::text($_POST['reason']   ?? '', 500);

        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            rto_json_err('Invalid status.');
        }

        // FIX P2: the two branches of this guard used to run the identical
        // UPDATE regardless of whether the vendor had active leads — the
        // "active leads" check computed a real count and then did nothing
        // different with it, which meant a vendor could be suspended mid-job
        // with their in-flight leads silently stranded (still pointing at a
        // now-suspended vendor, invisible to reassignment). The two paths now
        // actually differ: suspending a vendor with active leads requires an
        // explicit confirm-and-reassign step so those leads don't disappear
        // from operational visibility (audit rule 7 in the platform brief:
        // "a registered DSA must not disappear from operational mapping
        // simply because one mapping table or screen failed to update" — the
        // same principle applies in reverse when a vendor is removed).
        if ($status === 'suspended') {
            if (!$reason) rto_json_err('Suspension reason is required.');
            $activeLeadIds = $this->db->get_col($this->db->prepare(
                "SELECT id FROM {$this->p}rto_leads
                 WHERE vendor_id=%d AND status NOT IN ('completed','cancelled') AND deleted_at IS NULL",
                $id
            )) ?: [];

            $forceReassign = !empty($_POST['force_reassign']);
            if (!empty($activeLeadIds) && !$forceReassign) {
                rto_json_err(
                    'This vendor has ' . count($activeLeadIds) . ' active lead(s). '
                    . 'Resubmit with force_reassign=1 to suspend and automatically '
                    . 'reassign those leads to another eligible vendor.',
                    409
                );
            }

            $statusUpdated = $this->db->update($this->p . 'rto_vendors', [
                'status'         => 'suspended',
                'suspend_reason' => $reason,
                'updated_at'     => current_time('mysql'),
            ], ['id' => $id]);
            // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
            // throughout this codebase, and especially important here since
            // this gates whether the lead-reassignment logic below even
            // makes sense to run): if the vendor was never actually marked
            // suspended, don't reassign its leads or tell the admin it worked.
            if ($statusUpdated === false) rto_json_err('Could not suspend the vendor. Please try again.', 500);

            if (!empty($activeLeadIds)) {
                // FIX: VendorRepository/LeadRepository both require real
                // (QueryBuilder, Cache) collaborators — `new VendorRepository()`
                // with no args fatal-errored (ArgumentCountError) the instant
                // a suspension with active leads actually reached this branch,
                // so the confirm-and-reassign flow this whole block exists for
                // could never actually run in production.
                $qb = new \RTOFLOW\Database\QueryBuilder($this->db);
                $vendorSvc = new \RTOFLOW\Services\VendorService(
                    new \RTOFLOW\Repositories\VendorRepository($qb, new \RTOFLOW\Support\Cache()),
                    new \RTOFLOW\Repositories\LeadRepository($qb, new \RTOFLOW\Support\Cache()),
                    new \RTOFLOW\Support\EventBus()
                );
                foreach ($activeLeadIds as $leadId) {
                    // Un-assign so the lead is no longer attributed to a
                    // suspended vendor, then attempt automatic reassignment
                    // through the same scoring path new leads use.
                    $this->db->update($this->p . 'rto_leads', ['vendor_id' => null], ['id' => (int)$leadId]);
                    $this->db->update($this->p . 'rto_assignments', ['status' => 'superseded'], [
                        'lead_id'   => (int)$leadId,
                        'vendor_id' => $id,
                    ]);
                    $newVendorId = $vendorSvc->auto_assign((int)$leadId);
                    \RTOFLOW\Services\AuditService::log('lead.reassigned_on_suspension', (int)$leadId, [
                        'from_vendor_id' => $id,
                        'to_vendor_id'   => $newVendorId,
                        'reason'         => 'vendor_suspended',
                    ]);
                }
            }
        } else {
            $statusUpdated = $this->db->update($this->p . 'rto_vendors', [
                'status'     => $status,
                'updated_at' => current_time('mysql'),
            ], ['id' => $id]);
            // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
            // throughout this codebase): this always reported success back
            // to the admin regardless of whether the DB write actually
            // happened.
            if ($statusUpdated === false) rto_json_err('Could not update vendor status. Please try again.', 500);
        }

        // Notify vendor of status change (Part 11-E automation gap build)
        $vendor = $this->db->get_row($this->db->prepare(
            "SELECT v.*, u.user_email FROM {$this->p}rto_vendors v
             LEFT JOIN {$this->p}users u ON u.ID=v.user_id WHERE v.id=%d", $id
        ), ARRAY_A);

        if ($vendor) {
            $templateSlug = $status === 'suspended' ? 'vendor_suspended' : 'vendor_status_changed';
            \RTOFLOW\Services\NotificationService::send($templateSlug, [
                'vendor_name'   => $vendor['full_name'],
                'new_status'    => ucfirst($status),
                'reason'        => $reason ?: 'No reason provided.',
                'admin_contact' => get_option('rtoflow_support_email', get_option('admin_email')),
            ], (int)$vendor['user_id']);

            \RTOFLOW\Services\AuditService::log('vendor.status_changed', 0, [
                'vendor_id' => $id,
                'status'    => $status,
                'reason'    => $reason,
            ]);
        }

        rto_json_ok(['status' => $status], 'Vendor status updated and vendor notified.');
    }

    // ── Bulk activate/deactivate (AJAX) ─────────────────────────────────────
    // TRACE: admin selects multiple vendors on list, picks Activate/Deactivate,
    //        submits → check_ajax_referer('rto_admin_lead',...) + rto_is_admin() →
    //        loops UPDATE v.status per id (simple bulk toggle only — suspension
    //        with active-lead reassignment stays single-vendor via updateStatus()) →
    //        rto_json_ok({updated}).
    public function bulkStatus(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $ids    = array_values(array_filter(array_map('intval', (array)($_POST['vendor_ids'] ?? []))));
        $status = Sanitiser::text($_POST['status'] ?? '');

        if (empty($ids)) rto_json_err('No vendors selected.');
        if (!in_array($status, ['active', 'inactive'], true)) rto_json_err('Invalid status for bulk update.');

        $updated = 0;
        foreach ($ids as $id) {
            if ($id < 1) continue;
            $ok = $this->db->update($this->p . 'rto_vendors', [
                'status'     => $status,
                'updated_at' => current_time('mysql'),
            ], ['id' => $id]);
            if ($ok !== false) {
                $updated++;
                \RTOFLOW\Services\AuditService::log('vendor.status_changed', 0, ['vendor_id' => $id, 'status' => $status, 'bulk' => true]);
            }
        }

        rto_json_ok(['updated' => $updated], "{$updated} vendor(s) set to " . ucfirst($status) . '.');
    }

    // ENTERPRISE GAP FIX (Phase 4, item 5 — "no rating or suspension appeal
    // workflow for vendors"): admin-side queue, mirroring the existing
    // 'config-approvals' page's simple pending/history shape.
    public function appeals(): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 403);
        rto_view('admin.vendor-appeals.index', [
            'pending'  => \RTOFLOW\Services\VendorAppealService::pending(),
            'resolved' => \RTOFLOW\Services\VendorAppealService::resolved(),
        ]);
    }

    public function resolveAppeal(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id       = Sanitiser::int($_POST['appeal_id'] ?? 0, 1);
        $decision = Sanitiser::text($_POST['decision'] ?? '');
        $response = Sanitiser::text($_POST['response'] ?? '', 1000);

        if (!in_array($decision, ['upheld', 'rejected'], true)) rto_json_err('Invalid decision.');

        $ok = \RTOFLOW\Services\VendorAppealService::resolve($id, $decision, $response, get_current_user_id());
        if (!$ok) rto_json_err('Unable to resolve — this appeal may already have been handled.');

        \RTOFLOW\Services\AuditService::log('vendor.appeal_resolved', null, ['appeal_id' => $id, 'decision' => $decision]);
        rto_json_ok(null, 'Appeal resolved.');
    }

    // ── CSV export (GET, ?export=csv on the vendors list) ────────────────────
    // TRACE: admin visits /rto-admin/vendors/?export=csv&search=&status=&kyc= →
    //        index() detects export=csv and calls this with the SAME $ws/$params
    //        it just built → fetches ALL matching rows (capped 5000) → streams CSV.
    private function exportCsv(string $ws, array $params): void
    {
        if (!rto_is_staff()) wp_die('Access denied.', 403);
        // ENTERPRISE GAP FIX (Phase 1, item 6 — granular RBAC): any staff
        // account could pull the full vendor list before this. Gated on the
        // 'vendors' module's export permission now (rto_admin unaffected).
        if (!\RTOFLOW\Security\Permissions::can(get_current_user_id(), 'vendors', 'export')) {
            wp_die('Access denied. Your staff role does not have export access to Vendors.', 403);
        }

        $sql     = "SELECT v.*, u.user_email FROM {$this->p}rto_vendors v LEFT JOIN {$this->p}users u ON u.ID=v.user_id WHERE {$ws} ORDER BY v.created_at DESC LIMIT 5000";
        $vendors = $params
            ? $this->db->get_results($this->db->prepare($sql, $params), ARRAY_A)
            : $this->db->get_results($sql, ARRAY_A);
        $vendors = $vendors ?: [];

        $filename = 'rtoflow-vendors-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Vendor Number','Full Name','Email','Mobile','Status','KYC Status','Rating','Created At']);

        foreach ($vendors as $v) {
            fputcsv($out, [
                $v['vendor_number'] ?? '',
                $v['full_name']     ?? '',
                $v['user_email']    ?? '',
                $v['mobile']        ?? '',
                $v['status']        ?? '',
                $v['kyc_status']    ?? '',
                $v['rating']        ?? '0.0',
                $v['created_at']    ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    // TRACE: admin uploads a CSV via the Import CSV panel on the Vendors list →
    //        nonce + admin role verified → file validated (present, uploaded,
    //        <=2MB, .csv extension) → BOM-aware header row parsed and matched
    //        case-insensitively against this controller's own exportCsv()
    //        header names (Full Name, Email, Mobile) so an export→edit→
    //        re-import round-trips cleanly → each row creates or reuses a WP
    //        user exactly like store() does (same password-delivery fix: an
    //        unusable secret + wp_new_user_notification()), then inserts the
    //        matching rto_vendors row →
    //        preconditions: admin role, valid CSV, Full Name/Email/Mobile
    //        columns present →
    //        postconditions: one rto_vendors row (+ WP user, new or reused)
    //        per valid row; per-row failures collected and reported, capped
    //        at 50 in the response, without aborting the rest of the file →
    //        edge cases handled: missing file, oversized file, wrong
    //        extension, empty file, blank lines, missing required columns,
    //        duplicate email within the same file, existing email (reuses
    //        the account rather than erroring), row count capped at 500
    //        (lower than Cities' 2000 — each row here does a WP user lookup/
    //        create, materially more expensive per row), per-row DB/user
    //        creation errors.
    public function importCsv(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);

        if (empty($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'] ?? '')) {
            rto_json_err('Please choose a CSV file to upload.');
        }
        $file = $_FILES['csv_file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            rto_json_err('Upload failed (error code ' . (int)($file['error'] ?? -1) . '). Please try again.');
        }
        if ((int)$file['size'] > 2 * 1024 * 1024) {
            rto_json_err('File is too large. Maximum size is 2MB.');
        }
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            rto_json_err('Please upload a .csv file.');
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) rto_json_err('Could not read the uploaded file.');

        // Skip a UTF-8 BOM if present — exportCsv() above writes one, so a
        // round-tripped file needs this to not corrupt the first header.
        $bom = fread($handle, 3);
        if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) rewind($handle);

        $header = fgetcsv($handle);
        if (!$header) { fclose($handle); rto_json_err('The file is empty or not a valid CSV.'); }
        $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);

        $findCol = static function (array $header, array $names) {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false) return $i;
            }
            return false;
        };
        $colName   = $findCol($header, ['full name', 'name']);
        $colEmail  = $findCol($header, ['email']);
        $colMobile = $findCol($header, ['mobile', 'phone']);

        if ($colName === false || $colMobile === false) {
            fclose($handle);
            rto_json_err('The CSV must have a "Full Name" column and a "Mobile" column (matching the Export CSV format). Found columns: ' . implode(', ', $header));
        }

        // Preload existing vendor mobiles once, same reasoning as Cities'
        // import: a per-row query for a large file is wasted round-trips.
        $existingMobiles = [];
        foreach ($this->db->get_results("SELECT mobile FROM {$this->p}rto_vendors", ARRAY_A) ?: [] as $r) {
            if (!empty($r['mobile'])) $existingMobiles[$r['mobile']] = true;
        }

        $imported = 0; $skipped = 0; $errors = [];
        $rowNum   = 1; // header consumed above counts as row 1
        $maxRows  = 500;
        $seenInFile = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if ($rowNum - 1 > $maxRows) {
                $errors[] = "Row {$rowNum}: import capped at {$maxRows} rows — split the file and re-upload the rest.";
                break;
            }
            if (count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) continue; // blank line

            $fullName = Sanitiser::text(trim((string)($row[$colName] ?? '')));
            $email    = $colEmail !== false ? Sanitiser::email(trim((string)($row[$colEmail] ?? ''))) : '';
            $mobile   = Sanitiser::mobile(trim((string)($row[$colMobile] ?? '')));

            if ($fullName === '' || $mobile === '' || strlen($mobile) !== 10) {
                $errors[] = "Row {$rowNum}: a full name and a valid 10-digit mobile number are both required — skipped.";
                $skipped++;
                continue;
            }
            if (isset($existingMobiles[$mobile]) || isset($seenInFile[$mobile])) {
                $errors[] = "Row {$rowNum}: mobile \"{$mobile}\" already exists as a vendor — skipped.";
                $skipped++;
                continue;
            }

            // Same account-creation contract as store(): reuse an existing
            // WP user by email, or create one with an unusable secret and
            // let wp_new_user_notification() deliver the real "set your
            // password" link — never a silently-generated, undelivered one.
            $userId = 0;
            if ($email) {
                if (email_exists($email)) {
                    $u = get_user_by('email', $email);
                    $userId = $u ? $u->ID : 0;
                } else {
                    $pw = wp_generate_password(24, true);
                    $userId = wp_create_user($email, $pw, $email);
                    if (is_wp_error($userId)) {
                        $errors[] = "Row {$rowNum}: could not create account for \"{$email}\" — " . $userId->get_error_message() . ' — skipped.';
                        $skipped++;
                        continue;
                    }
                    wp_update_user(['ID' => $userId, 'display_name' => $fullName]);
                    wp_new_user_notification($userId, null, 'user');
                }
                $u = new \WP_User($userId);
                $u->add_role('rto_vendor');
            }

            $vendorNum = 'VND-' . strtoupper(date('Y')) . '-' . str_pad((int)$this->db->get_var("SELECT COUNT(*)+1 FROM {$this->p}rto_vendors"), 4, '0', STR_PAD_LEFT);
            $inserted = $this->db->insert($this->p . 'rto_vendors', [
                'user_id'       => $userId ?: null,
                'vendor_number' => $vendorNum,
                'full_name'     => $fullName,
                'mobile'        => $mobile,
                'email'         => $email,
                'cities'        => wp_json_encode([]),
                'services'      => wp_json_encode([]),
                'status'        => 'active',
                'kyc_status'    => 'pending',
                'rating'        => 0,
                'created_at'    => current_time('mysql'),
            ]);
            if (!$inserted) {
                $errors[] = "Row {$rowNum}: database error saving \"{$fullName}\" — skipped.";
                $skipped++;
                continue;
            }

            $existingMobiles[$mobile] = true;
            $seenInFile[$mobile] = true;
            $imported++;
        }
        fclose($handle);

        if ($imported > 0) {
            \RTOFLOW\Services\AuditService::log('vendor.bulk_imported', null, [
                'imported' => $imported, 'skipped' => $skipped, 'total_rows' => $rowNum - 1,
            ]);
        }

        rto_json_ok([
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => array_slice($errors, 0, 50),
        ], "Import complete: {$imported} added, {$skipped} skipped." . ($errors ? ' See details below.' : '') . ($imported > 0 ? ' New vendors with an email address were sent a "set your password" email.' : ''));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractFormData(): array
    {
        return [
            'full_name'    => Sanitiser::text($_POST['full_name']    ?? ''),
            'email'        => Sanitiser::email($_POST['email']       ?? ''),
            'mobile'       => Sanitiser::mobile($_POST['mobile']     ?? ''),
            'pan'          => strtoupper(Sanitiser::text($_POST['pan'] ?? '')),
            'aadhaar'      => preg_replace('/\D/', '', $_POST['aadhaar'] ?? ''),
            'address'      => Sanitiser::text($_POST['address']      ?? '', 500),
            'bank_name'    => Sanitiser::text($_POST['bank_name']    ?? ''),
            'bank_account' => Sanitiser::text($_POST['bank_account'] ?? ''),
            'bank_ifsc'    => strtoupper(Sanitiser::text($_POST['bank_ifsc'] ?? '')),
            'user_id'      => Sanitiser::int($_POST['user_id']       ?? 0),
        ];
    }

    private function validateVendor(array $d): array
    {
        $e = [];
        if (!$d['full_name']) $e['full_name'] = 'Full name is required.';
        if (!$d['mobile'])    $e['mobile']    = 'Mobile number is required.';
        if (!$d['email'] && !$d['user_id']) $e['email'] = 'Email or existing user ID is required.';
        return $e;
    }
}
