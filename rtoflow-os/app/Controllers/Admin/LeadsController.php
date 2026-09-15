<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Services\LeadService;
use RTOFLOW\Services\PaymentService;
use RTOFLOW\Services\NotificationService;
use RTOFLOW\Services\FormEngineService;
use RTOFLOW\Services\LeadSummaryService;
use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Http\Middleware\RateLimiter;

if (!defined('ABSPATH')) exit;

class LeadsController
{
    private LeadService       $leads;
    private PaymentService    $payments;
    private FormEngineService $forms;

    public function __construct(LeadService $leads, PaymentService $payments, FormEngineService $forms)
    {
        $this->leads    = $leads;
        $this->payments = $payments;
        $this->forms    = $forms;
    }

    // ── List ──────────────────────────────────────────────────────────────
    // TRACE: admin navigates to /rto-admin/leads/ →
    //        filters sanitised from $_GET (status, search, date_from, date_to) →
    //        LeadService::getLeads(filters, page, 25) runs COUNT + paginated SELECT with JOINs →
    //        rto_view('admin.leads.index', compact data) renders table →
    //        preconditions: user is staff/admin →
    //        postconditions: HTML with leads table, pagination, bulk bar, smart date shortcuts →
    //        edge cases: empty filters = all leads; DB error = $leads=[]; no leads = empty state
    public function index(): void
    {
        if (!rto_is_staff()) wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);

        $filters = [
            // Known Limitations audit fix: status now accepts a
            // comma-separated list (e.g. ?status=docs_pending,on_hold) for a
            // real OR filter — LeadService::getLeads() validates every value
            // against the real transition table before it reaches SQL.
            'status'   => Sanitiser::text($_GET['status']   ?? ''),
            // Drill-down target for the Dashboard's "SLA Breached" KPI card.
            'sla_only' => !empty($_GET['sla_only']) ? '1' : '',
            'search'   => Sanitiser::text($_GET['search']   ?? ''),
            'date_from'=> Sanitiser::date($_GET['date_from']?? ''),
            'date_to'  => Sanitiser::date($_GET['date_to']  ?? ''),
            // Part 5.7: "Archived" view — the only way for staff to find
            // and restore an archived order (see LeadService::archiveLead()).
            // Anything other than the literal 'only' behaves exactly as
            // before (active orders only), so this is additive, not a
            // behaviour change for the default view.
            'archived' => Sanitiser::text($_GET['archived'] ?? '') === 'only' ? 'only' : '',
        ];
        $page   = Sanitiser::int($_GET['paged'] ?? 1, 1);
        $result = $this->leads->getLeads($filters, $page, 25);

        // ENTERPRISE GAP FIX (Phase 6, item — "no bulk vendor reassignment
        // ... on Leads"): populates the bulk-reassign dropdown. Deliberately
        // the same eligibility filter (active + KYC-verified) assignVendor()
        // itself enforces server-side, so an admin never sees a vendor in
        // the list that a bulk reassignment would then reject.
        global $wpdb;
        $reassignVendors = $wpdb->get_results(
            "SELECT id, full_name FROM {$wpdb->prefix}rto_vendors WHERE status='active' AND kyc_status='verified' ORDER BY full_name ASC",
            ARRAY_A
        ) ?: [];

        // ENTERPRISE GAP FIX (Phase 6, item — "no saved/named filter views
        // on any list screen"): see SavedFilterService.
        $savedFilters = \RTOFLOW\Services\SavedFilterService::list(get_current_user_id(), 'leads');

        rto_view('admin.leads.index', ['leads' => $result, 'filters' => $filters, 'reassignVendors' => $reassignVendors, 'savedFilters' => $savedFilters]);
    }

    // ── Detail ────────────────────────────────────────────────────────────
    // TRACE: admin navigates to /rto-admin/leads/{id}/ →
    //        lead loaded via LeadService::getLead($leadId) with service+city+client JOINs →
    //        payments, documents, vendors (split matched/other), messages, auditLog loaded →
    //        rto_view('admin.leads.show', compact data) renders detail page →
    //        preconditions: lead exists; user is staff/admin →
    //        postconditions: full lead detail page with status select, vendor optgroups, payment form →
    //        edge cases: lead not found → wp_die 404; vendor arrays may be empty (renders empty optgroup)
    public function show(int $leadId): void
    {
        if (!rto_is_staff()) wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);

        // Part 5.7 FIX: use the admin-only lookup (no deleted_at filter) so
        // an archived order can still be opened and restored instead of
        // 404ing the moment it's archived — see LeadService::getLeadForAdmin().
        $lead = $this->leads->getLeadForAdmin($leadId);
        if (!$lead) wp_die('The requested order could not be found.', 'Not Found', ['response' => 404, 'back_link' => true]);

        global $wpdb;
        $p = $wpdb->prefix;

        $payments  = $this->payments->getPaymentsForLead($leadId);
        $documents = $wpdb->get_results($wpdb->prepare(
            "SELECT d.*, dt.name as doc_type_name FROM {$p}rto_documents d
             LEFT JOIN {$p}rto_doc_types dt ON dt.id = d.doc_type_id
             WHERE d.lead_id = %d ORDER BY d.created_at DESC",
            $leadId
        ), ARRAY_A) ?: [];

        // P6-UX-009 FIX: load vendors with coverage data, split by match
        $allVendors = $wpdb->get_results(
            "SELECT id, vendor_number, full_name, mobile, email, rating, completion_rate, cities, services
             FROM {$p}rto_vendors WHERE status='active' AND kyc_status='verified'
             ORDER BY rating DESC LIMIT 100",
            ARRAY_A
        ) ?: [];
        $leadCityId = (int)($lead['city_id']??0);
        $leadSvcId  = (int)($lead['service_id']??0);
        $matchedVendors = $otherVendors = [];
        foreach ($allVendors as $v) {
            $cities   = json_decode($v['cities']??'[]',true)?:[];
            $services = json_decode($v['services']??'[]',true)?:[];
            if ((empty($cities)||in_array($leadCityId,$cities,true))
                && (empty($services)||in_array($leadSvcId,$services,true))) {
                $matchedVendors[] = $v;
            } else {
                $otherVendors[] = $v;
            }
        }
        $vendors = ['matched'=>$matchedVendors,'other'=>$otherVendors];

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.display_name FROM {$p}rto_messages m
             LEFT JOIN {$p}users u ON u.ID = m.user_id
             WHERE m.lead_id = %d ORDER BY m.created_at ASC",
            $leadId
        ), ARRAY_A) ?: [];

        $auditLog = \RTOFLOW\Services\AuditService::forLead($leadId);

        // Lead Notes thread (replaces the old single-textarea internal_notes
        // overwrite path — see LeadNoteService's docblock). Pre-loaded here,
        // same as $messages above, so the thread renders server-side on
        // first paint; new notes are appended client-side via AJAX without
        // a full reload, mirroring the Communication Thread's pattern.
        $leadNotes = (new \RTOFLOW\Services\LeadNoteService())->getNotes($leadId);

        // ── Full submitted form-answers (P360-1 FIX) ────────────────────
        // The dynamic Form Engine already captures every custom field the
        // client answered — verbatim, as JSON — in TWO places: the
        // rto_leads.form_data column itself (every apply path writes it)
        // and a fuller snapshot row in rto_form_submissions (category,
        // sub_service, form_data, raw_post — see Router::submitApplyV2()/
        // submitApplyDynamic()). Neither was ever read here. We now load
        // both, decode them, and — where the service still has an active
        // v2 schema — resolve each answer key to its real field label/type
        // via FormEngineService::getForServiceForAdmin() (admin-facing, so
        // it works even with the public form_builder flag off) instead of
        // rendering raw meta_key/meta_value pairs.
        [$formSubmission, $formAnswersAll] = $this->resolveFormAnswers($lead);
        $formAnswers      = array_values(array_filter($formAnswersAll, fn($a) => !self::isBlankAnswer($a)));
        $hiddenBlankCount = count($formAnswersAll) - count($formAnswers);

        // ── Document metadata enrichment (P360-2 FIX) ────────────────────
        // file_size_kb and mime_type have been on rto_documents since the
        // core schema (see database/migrations/2024_01_01_000001_create_
        // core_tables.php) but the view never read either — only file_name
        // and the verification status badge. Attach a human-readable size
        // and a real download/preview URL (routed through
        // DocumentAccessController::serve(), which already enforces the
        // same rto_is_staff()/owner/vendor authorisation as everything
        // else — see app/Controllers/DocumentAccessController.php) so the
        // Documents panel can offer real Preview/Download actions instead
        // of a bare filename.
        foreach ($documents as &$doc) {
            $kb = (int)($doc['file_size_kb'] ?? 0);
            $doc['file_size_human'] = $kb >= 1024 ? round($kb / 1024, 1) . ' MB' : $kb . ' KB';
            $doc['view_url']        = home_url('/rto-documents/' . (int)$doc['id'] . '/');
            $doc['download_url']    = home_url('/rto-documents/' . (int)$doc['id'] . '/?dl=1');
        }
        unset($doc);

        // ── Status workflow (P360-3 FIX) ─────────────────────────────────
        // Real transitions LeadService::updateStatus() will actually
        // accept from the CURRENT status — not the full static status list
        // the view previously hardcoded (which let an admin "select" a
        // transition updateStatus() would then reject with a 400 that only
        // showed up after clicking Update Status).
        $validTransitions = empty($lead['deleted_at'])
            ? $this->leads->validTransitionsFrom((string)($lead['status'] ?? ''))
            : []; // Part 5.7: an archived order is frozen — no status transitions until restored.

        // Cities list for the (admin-only) core-fields Edit modal — see
        // updateLead() below for what's actually mutable and why.
        $cities = $wpdb->get_results("SELECT id, name FROM {$p}rto_cities ORDER BY name ASC", ARRAY_A) ?: [];

        // ── Lead Summary (Part 5.8) ───────────────────────────────────────
        // Computed server-side, from the exact same declutered $formAnswers
        // the "Submitted Application Details" card renders — never
        // recomputed differently, so the prose can never contradict what's
        // on screen below it. See LeadSummaryService's own docblock for the
        // slot-classification architecture and its honestly-scoped limits.
        $leadSummary = (new LeadSummaryService())->summarise($lead, $formAnswers);

        rto_view('admin.leads.show', compact(
            'lead', 'payments', 'documents', 'vendors', 'messages', 'auditLog', 'leadNotes',
            'formSubmission', 'formAnswers', 'formAnswersAll', 'hiddenBlankCount',
            'validTransitions', 'cities', 'leadSummary'
        ));
    }

    // ── Add lead note (AJAX) ────────────────────────────────────────────────
    // TRACE: admin types in the Internal Notes compose box on the order
    //        detail screen → AJAX POST admin.add_lead_note → nonce + admin
    //        role verified (same gate the old single-textarea updateNotes()
    //        used — notes are an internal/sensitive channel, same level as
    //        recordPayment()) → LeadNoteService::addNote() validates
    //        non-empty/length and INSERTs a new row into rto_lead_notes
    //        (never an UPDATE — a prior staff member's note is never
    //        touched) → AuditService::log('lead.note_added') →
    //        preconditions: lead exists, user is admin, note non-empty →
    //        postconditions: new row in rto_lead_notes, audit logged →
    //        edge cases: lead not found → 404; empty/whitespace-only note →
    //        rejected by LeadNoteService before any INSERT is attempted.
    //
    // REPLACES the old updateNotes() (removed — see Router.php's dispatch
    // table, which no longer has an 'admin.update_notes' entry): that
    // endpoint did a plain $wpdb->update(...) that overwrote the entire
    // rto_leads.internal_notes column on every save, silently destroying
    // any other staff member's text. Leaving both endpoints reachable would
    // have let a stale client (old page load, cached JS) still call the
    // overwrite path and destroy history that the new thread had already
    // built up, so the old path was deleted outright rather than left dead.
    public function addLeadNote(): void
    {
        if (!rto_is_admin())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $note   = Sanitiser::text($_POST['note'] ?? '', \RTOFLOW\Services\LeadNoteService::MAX_LENGTH);

        $lead = $this->leads->getLead($leadId);
        if (!$lead) rto_json_err('Order not found.', 404);

        $result = (new \RTOFLOW\Services\LeadNoteService())->addNote($leadId, get_current_user_id(), $note);
        if (!$result['success']) rto_json_err($result['message']);

        \RTOFLOW\Services\AuditService::log('lead.note_added', $leadId);
        rto_json_ok(['note' => $result['note']], $result['message']);
    }

    // ── Update status (AJAX) ──────────────────────────────────────────────
    // TRACE: admin/staff clicks Update Status on lead detail →
    //        nonce 'rto_admin_lead' verified; rto_staff role enforced →
    //        lock_version from POST checked against DB (optimistic concurrency — Part 12-B/15-A build) →
    //        LeadService::updateStatus() validates transition, performs UPDATE, increments lock_version →
    //        if 0 rows updated AND version mismatch → 409 Conflict returned to caller →
    //        NotificationService::notifyStatusChanged() fires if success →
    //        preconditions: lead exists, staff role, valid nonce →
    //        postconditions: lead.status updated, lock_version incremented, notification sent →
    //        edge cases: invalid transition → 400; version conflict → 409; lead not found → 404
    public function updateStatus(): void
    {
        if (!rto_is_staff())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId      = Sanitiser::int($_POST['lead_id']      ?? 0, 1);
        $newStatus   = Sanitiser::text($_POST['status']      ?? '');
        $note        = Sanitiser::text($_POST['note']        ?? '', 500);
        $lockVersion = Sanitiser::int($_POST['lock_version'] ?? 0); // Part 12-B/15-A optimistic lock

        // Optimistic lock check: if caller sent a version, verify it matches current DB version
        if ($lockVersion > 0) {
            global $wpdb;
            $currentVersion = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT lock_version FROM {$wpdb->prefix}rto_leads WHERE id = %d",
                $leadId
            ));
            if ($currentVersion && $currentVersion !== $lockVersion) {
                rto_json_err(
                    'This lead was updated by another user while you were viewing it. Please refresh and try again.',
                    409
                );
            }
        }

        $result = $this->leads->updateStatus($leadId, $newStatus, ['note' => $note]);

        if ($result['success']) {
            $lead = $this->leads->getLead($leadId);
            if ($lead) {
                NotificationService::notifyStatusChanged($lead, (int)$lead['client_id'], $newStatus);
            }
        }

        $result['success'] ? rto_json_ok(null, $result['message']) : rto_json_err($result['message']);
    }

    // ENTERPRISE GAP FIX (Phase 7, item 3 — "side effects incomplete" flag):
    // staff-facing acknowledgement once they've manually followed up on the
    // failure noted in side_effects_failure_note (e.g. resent a notification
    // by hand). Never auto-cleared — the whole point is a human confirms the
    // gap was actually closed, not that the flag simply aged out.
    public function acknowledgeSideEffects(): void
    {
        if (!rto_is_staff()) wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        global $wpdb;
        $updated = $wpdb->update($wpdb->prefix . 'rto_leads', [
            'side_effects_incomplete'   => 0,
            'side_effects_failure_note' => null,
        ], ['id' => $leadId]);
        if ($updated === false) rto_json_err('Could not clear the flag. Please try again.', 500);

        \RTOFLOW\Services\AuditService::log('lead.side_effects_acknowledged', $leadId);
        rto_json_ok(null, 'Side-effect failure acknowledged and cleared.');
    }

    // ── Assign vendor (AJAX) ──────────────────────────────────────────────
    // TRACE: admin clicks Assign Vendor → AJAX POST admin.assign_vendor →
    //        nonce + staff role verified →
    //        UPDATE rto_leads SET vendor_id=N, status='assigned', updated_at=NOW() →
    //        NotificationService::send('vendor_assigned') queued →
    //        AuditService::log('lead.vendor_assigned') →
    //        rto_json_ok({message:'Vendor assigned!'}) →
    //        preconditions: lead exists; vendor is active+verified; user is staff →
    //        postconditions: lead.vendor_id=N, status='assigned'; vendor notified; audit logged →
    //        edge cases: vendor_id=0 → 400; lead not found → 404; vendor not active → 400
    public function assignVendor(): void
    {
        if (!rto_is_staff())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId   = Sanitiser::int($_POST['lead_id']   ?? 0, 1);
        $vendorId = Sanitiser::int($_POST['vendor_id'] ?? 0, 1);

        $result = $this->leads->assignVendor($leadId, $vendorId);

        // Known Limitations audit fix: "Manual assignment eligibility is
        // enforced more loosely than auto-assignment eligibility ... show a
        // non-blocking warning banner ... so the override is a conscious
        // choice rather than an invisible one." Computed only on success —
        // this never blocks the assignment itself, only surfaces what an
        // admin should know about the choice they just made.
        if ($result['success']) {
            $lead = $this->leads->getLead($leadId);
            if ($lead) {
                // FIX: VendorRepository requires real (QueryBuilder, Cache)
                // collaborators — `new VendorRepository()` with no args
                // fatal-errored (ArgumentCountError) on every successful
                // manual assignment, so this whole non-blocking-warning
                // feature could never actually run in production.
                global $wpdb;
                $qb = new \RTOFLOW\Database\QueryBuilder($wpdb);
                $warnings = (new \RTOFLOW\Services\VendorService(
                    new \RTOFLOW\Repositories\VendorRepository($qb, new \RTOFLOW\Support\Cache()),
                    new \RTOFLOW\Repositories\LeadRepository($qb, new \RTOFLOW\Support\Cache()),
                    new \RTOFLOW\Support\EventBus()
                ))->checkManualAssignWarnings($vendorId, (int)$lead['city_id'], (int)$lead['service_id']);
                if (!empty($warnings)) {
                    rto_json_ok(['warnings' => $warnings], $result['message']);
                    return;
                }
            }
        }

        $result['success'] ? rto_json_ok(null, $result['message']) : rto_json_err($result['message']);
    }

    // ── Record manual payment (AJAX) ──────────────────────────────────────
    public function recordPayment(): void
    {
        if (!rto_is_admin())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!RateLimiter::check('payment', RateLimiter::clientIp())) RateLimiter::abort();

        $result = $this->payments->record([
            'lead_id'    => Sanitiser::int($_POST['lead_id'] ?? 0),
            'amount'     => Sanitiser::amount($_POST['amount'] ?? 0),
            'method'     => Sanitiser::text($_POST['method']  ?? 'cash'),
            'txn_id'     => Sanitiser::text($_POST['txn_id']  ?? ''),
            'notes'      => Sanitiser::text($_POST['notes']   ?? ''),
            'type'       => 'manual',
        ]);

        $result['success'] ? rto_json_ok($result, $result['message']) : rto_json_err($result['message']);
    }

    // ── Add message (AJAX) ────────────────────────────────────────────────
    public function addMessage(): void
    {
        if (!rto_is_staff())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        global $wpdb;
        $leadId  = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $message = Sanitiser::text($_POST['message'] ?? '', 2000);

        if (!$message) rto_json_err('Message cannot be empty.');

        // BUGFIX (ghost-success sweep, same class of bug fixed this pass in
        // the client-side and vendor-side message/document handlers): a
        // silently failed insert previously still returned the message text
        // as if it had been saved, so staff would see their own reply appear
        // to send successfully while the client never actually received it.
        $msgInserted = $wpdb->insert($wpdb->prefix . 'rto_messages', [
            'lead_id'     => $leadId,
            'user_id'     => get_current_user_id(),
            'message'     => $message,
            'sender_type' => 'staff',
            'created_at'  => current_time('mysql'),
        ]);

        if (!$msgInserted) {
            error_log('RTOFLOW Admin LeadsController: message insert failed for lead ' . $leadId . ' — ' . $wpdb->last_error);
            rto_json_err('Unable to send message. Please try again.');
        }

        rto_json_ok(['message' => $message, 'sender' => wp_get_current_user()->display_name, 'time' => current_time('mysql')]);
    }

    // FIX P0 (newly discovered during implementation — not caught by the prior
    // audit pass, which read this file's methods individually without
    // linting the whole file): a stray closing brace here after addMessage()
    // ended the class early. Every method below — bulkUpdateStatus() and
    // exportCsv(), both real, load-bearing admin features — was being
    // declared OUTSIDE the class body, which is a PHP fatal parse error.
    // `php -l` on this file failed with "unexpected token 'public'" before
    // this fix: the entire file failed to parse, meaning any request that
    // loaded LeadsController (the admin Leads list — one of the most-used
    // screens in the platform) would fatal-error the whole request, not just
    // degrade gracefully.
    // ── Bulk status update (AJAX) ─────────────────────────────────────────
    // TRACE: admin selects multiple leads on list, picks status, submits →
    //        verifies nonce+role, sanitises lead IDs and status →
    //        calls updateStatus() on each, counts successes →
    //        returns {updated: N, message: "..."} JSON →
    //        precondition: user is staff, nonce valid, lead_ids is non-empty array of ints →
    //        postcondition: each eligible lead has new status, audit log entry per lead →
    //        edge cases: invalid transition (skipped), lead not found (skipped), empty array
    public function bulkUpdateStatus(): void
    {
        if (!rto_is_staff())        wp_die('Access denied.', 'Access Denied', ['response' => 403]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadIds   = array_map('intval', (array)($_POST['lead_ids'] ?? []));
        $newStatus = Sanitiser::text($_POST['status'] ?? '');
        $note      = Sanitiser::text($_POST['note']   ?? '', 200);

        if (empty($leadIds) || !$newStatus) rto_json_err('No leads selected or status missing.');

        $updated = 0;
        $skipped = 0;
        foreach ($leadIds as $id) {
            if ($id < 1) continue;
            $result = $this->leads->updateStatus($id, $newStatus, ['note' => $note]);
            $result['success'] ? $updated++ : $skipped++;
        }

        rto_json_ok(['updated' => $updated, 'skipped' => $skipped],
            "{$updated} lead(s) updated to " . rto_status_label($newStatus)
            . ($skipped ? ". {$skipped} skipped (invalid transition)." : '.'));
    }

    // ENTERPRISE GAP FIX (Phase 6, item — "no bulk vendor reassignment or
    // bulk tagging on Leads"): bulk actions on this screen were limited to
    // status changes and archive/restore — reassigning a batch of leads to
    // a different vendor (e.g. a vendor going on leave, or a city-wide
    // rebalance) required doing it one lead at a time. Reuses
    // LeadService::assignVendor() per lead — same eligibility checks
    // (active + KYC-verified), same previous-vendor notification — rather
    // than duplicating that logic here.
    // TRACE: admin selects leads + a target vendor on the bulk bar, submits →
    //        verifies nonce+role, sanitises lead IDs + vendor ID →
    //        calls assignVendor() per lead, counts successes/failures →
    //        precondition: staff role, non-empty lead_ids, valid vendor_id →
    //        postcondition: each eligible lead's vendor_id updated, audit +
    //        notification per lead (both already inside assignVendor()) →
    //        edge cases: vendor not active/KYC-verified → every lead skipped
    //        with a clear count (not a silent partial success); lead not
    //        found → skipped, not fatal.
    public function bulkReassignVendor(): void
    {
        if (!rto_is_staff())        wp_die('Access denied.', 'Access Denied', ['response' => 403]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadIds  = array_map('intval', (array)($_POST['lead_ids'] ?? []));
        $vendorId = Sanitiser::int($_POST['vendor_id'] ?? 0);

        if (empty($leadIds) || !$vendorId) rto_json_err('No leads selected or vendor missing.');

        $reassigned = 0;
        $skipped    = 0;
        foreach ($leadIds as $id) {
            if ($id < 1) continue;
            $result = $this->leads->assignVendor($id, $vendorId);
            $result['success'] ? $reassigned++ : $skipped++;
        }

        rto_json_ok(['reassigned' => $reassigned, 'skipped' => $skipped],
            "{$reassigned} lead(s) reassigned."
            . ($skipped ? " {$skipped} skipped (not eligible or already assigned)." : ''));
    }

    // ── CSV export (GET, triggered from leads list URL) ───────────────────
    // TRACE: admin visits /rto-admin/leads/?export=csv&status=&search= →
    //        routeAdmin passes to LeadsController::index() which detects export param →
    //        exportCsvChunk() called repeatedly with a keyset cursor (id > last seen id) →
    //        each chunk written to the CSV stream and flushed immediately →
    //        postcondition: browser downloads rtoflow-leads-YYYY-MM-DD.csv →
    //        edge cases: hard safety cap at MAX_EXPORT_ROWS total, empty result = headers only
    //
    // ENTERPRISE GAP FIX (Phase 11, item — "Unbounded/near-unbounded export
    // queries" / "Offset-based pagination on large tables"): this used to
    // fetch up to 5,000 rows in a single getLeads(1, 5000) call — one big
    // LIMIT/OFFSET query held entirely in PHP memory as an array before any
    // output was written, and prone to PHP's memory_limit / max_execution_time
    // on a large, wide result set. It now streams the export in bounded
    // chunks via LeadService::exportCsvChunk()'s keyset ("cursor")
    // pagination (WHERE id > $afterId ORDER BY id LIMIT $chunkSize — an
    // indexed range scan, not an OFFSET scan that degrades with table
    // size), writing and flushing each chunk to the browser as it is
    // fetched so memory use stays flat regardless of how many rows match,
    // while MAX_EXPORT_ROWS remains as a hard backstop against a truly
    // runaway export request.
    private const EXPORT_CHUNK_SIZE = 500;
    private const MAX_EXPORT_ROWS   = 50000;

    public function exportCsv(): void
    {
        if (!rto_is_staff()) wp_die('Access denied.', 403);
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('report_export')) {
            wp_die('CSV export is currently disabled.', 403);
        }

        $filters = [
            'status'    => Sanitiser::text($_GET['status']    ?? ''),
            'search'    => Sanitiser::text($_GET['search']    ?? ''),
            'date_from' => Sanitiser::date($_GET['date_from'] ?? ''),
            'date_to'   => Sanitiser::date($_GET['date_to']   ?? ''),
        ];

        $filename = 'rtoflow-leads-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('X-Accel-Buffering: no'); // don't let a reverse proxy buffer the whole streamed body either

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Lead Number','Status','Service','City','Client','Total (₹)','Paid (₹)','Payment Status','SLA Deadline','Created At']);

        $afterId    = 0;
        $totalRows  = 0;
        while (true) {
            $chunk = $this->leads->exportCsvChunk($filters, $afterId, self::EXPORT_CHUNK_SIZE);
            if (empty($chunk)) break;

            foreach ($chunk as $lead) {
                fputcsv($out, [
                    $lead['lead_number'],
                    rto_status_label($lead['status'] ?? ''),
                    $lead['service_name'] ?? '',
                    $lead['city_name']    ?? '',
                    $lead['client_name']  ?? '',
                    $lead['total_amount'] ?? '0.00',
                    $lead['paid_amount']  ?? '0.00',
                    $lead['payment_status'] ?? '',
                    $lead['sla_deadline'] ?? '',
                    $lead['created_at']   ?? '',
                ]);
                $afterId = (int)$lead['id'];
                $totalRows++;
            }
            // Flush this chunk to the client immediately instead of letting
            // it accumulate — combined with the DB-side cursor above, PHP
            // never holds more than one chunk's worth of rows at a time.
            if (function_exists('ob_flush')) { @ob_flush(); }
            flush();

            if (count($chunk) < self::EXPORT_CHUNK_SIZE) break; // last chunk
            if ($totalRows >= self::MAX_EXPORT_ROWS) break;      // hard safety backstop
        }
        fclose($out);
        exit;
    }

    // ── Shared form-answer resolution (Part 5.7) ────────────────────────────
    // Extracted out of show() so the single-lead CSV/PDF exports below build
    // their line items from the exact same real resolution logic (including
    // the category-schema fix) instead of re-deriving a second, possibly
    // divergent, copy of it.
    private function resolveFormAnswers(array $lead): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $leadId = (int)$lead['id'];

        $formSubmission = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}rto_form_submissions WHERE lead_id = %d ORDER BY id DESC LIMIT 1",
            $leadId
        ), ARRAY_A) ?: null;

        $rawAnswers = [];
        if ($formSubmission && !empty($formSubmission['form_data'])) {
            $decoded = json_decode($formSubmission['form_data'], true);
            if (is_array($decoded)) $rawAnswers = $decoded;
        }
        if (!$rawAnswers && !empty($lead['form_data'])) {
            $decoded = json_decode($lead['form_data'], true);
            if (is_array($decoded)) $rawAnswers = $decoded;
        }

        $leadMeta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$p}rto_lead_meta WHERE lead_id = %d ORDER BY id ASC",
            $leadId
        ), ARRAY_A) ?: [];
        foreach ($leadMeta as $m) {
            if (!array_key_exists($m['meta_key'], $rawAnswers)) {
                $rawAnswers[$m['meta_key']] = $m['meta_value'];
            }
        }

        // Part 5.7 FIX — see show() for full root-cause explanation: schemas
        // are saved by CATEGORY (FormRepository::find_by_category(),
        // saveForCategory() with service_id=NULL), not by service_id, since
        // Part 4.10. Resolve the same way.
        $category = $formSubmission['category'] ?? $lead['service_category'] ?? '';
        $schema   = $category !== '' ? $this->forms->getForCategoryForAdmin((string)$category) : null;

        $formAnswersAll = [];
        $seenKeys       = [];
        if ($schema) {
            foreach ($schema['steps'] as $step) {
                foreach ($step['fields'] ?? [] as $field) {
                    if (!in_array($field['type'], FormEngineService::ANSWER_TYPES, true)) continue;
                    $key = $field['key'];
                    if (!array_key_exists($key, $rawAnswers)) continue;
                    $seenKeys[$key] = true;
                    $formAnswersAll[] = [
                        'step'    => $step['label'] ?? '',
                        'label'   => $field['label'] ?: $key,
                        'type'    => $field['type'],
                        'value'   => $rawAnswers[$key],
                        // Part 5.8 (LeadSummaryService): 'key' and 'options'
                        // added additively — every existing reader of this
                        // array (the view's rendering loop, exportLeadCsv/Pdf)
                        // only ever destructures the keys it already knew
                        // about, so this changes nothing for them. The raw
                        // schema field key and its option value=>label map
                        // are what LeadSummaryService needs to classify an
                        // answer into a semantic "slot" and to resolve a
                        // stored option VALUE (e.g. "Lost") to its real
                        // display LABEL, without re-deriving either here.
                        'key'     => $key,
                        'options' => $field['options'] ?? [],
                    ];
                }
            }
        }
        // FIX (Lead Summary completeness audit): leftover keys the active
        // schema doesn't define — the live public intake form submits
        // several universal contact/identity fields (first_name, last_name,
        // mobile, email, address_line1, city, pincode, consent) that are
        // NOT part of any category's RealFormSchemaSeeder schema at all —
        // previously rendered with the raw stored key as the label verbatim
        // ("FIRST_NAME", "MOBILE"), both here and, critically, fed into
        // LeadSummaryService with that same raw-key "label", which is a
        // real part of why the summary was unable to use them naturally: a
        // classifier matching on human-looking labels/keys still works on
        // "first_name"/"mobile" (they follow this codebase's own key-naming
        // convention), but a raw uppercase dump gave a worse label to every
        // downstream consumer of $formAnswersAll for no reason. Humanized
        // once, here, benefiting the Submitted Application Details card AND
        // LeadSummaryService identically, rather than special-casing either.
        foreach ($rawAnswers as $key => $value) {
            if (isset($seenKeys[$key])) continue;
            $keyStr = (string)$key;
            $label  = ($keyStr === strtoupper($keyStr))
                ? ucwords(str_replace('_', ' ', strtolower($keyStr)))
                : $keyStr;
            $formAnswersAll[] = ['step' => '', 'label' => $label, 'type' => 'text', 'value' => $value, 'key' => $keyStr, 'options' => []];
        }

        return [$formSubmission, $formAnswersAll];
    }

    private static function isBlankAnswer(array $ans): bool
    {
        $v = $ans['value'];
        if ($ans['type'] === 'file') return $v === '' || $v === null;
        if (is_array($v)) return empty($v);
        return $v === '' || $v === null;
    }

    private static function answerValueToString($ans): string
    {
        $v = $ans['value'];
        if (is_array($v)) return implode(', ', array_map('strval', $v));
        return $v !== '' && $v !== null ? (string)$v : '';
    }

    // ── Edit core lead fields (AJAX) ────────────────────────────────────────
    // TRACE: admin opens the Edit modal on the order detail screen, changes
    //        city and/or extends the SLA deadline → AJAX POST
    //        admin.update_lead → nonce + ADMIN role verified (same gate as
    //        recordPayment()/updateNotes() — these are sensitive order
    //        fields, not a staff-level action) → LeadService::updateCoreFields()
    //        validates + persists + audit logs →
    //        preconditions: lead exists, not archived, user is admin →
    //        postconditions: rto_leads.city_id/sla_deadline updated, audit logged →
    //        edge cases: archived lead → rejected; invalid city → rejected;
    //        no recognised field in payload → rejected
    public function updateLead(): void
    {
        if (!rto_is_admin())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $data   = [];
        if (isset($_POST['city_id']))      $data['city_id']      = Sanitiser::int($_POST['city_id'], 0);
        if (isset($_POST['sla_deadline']) && $_POST['sla_deadline'] !== '') {
            $data['sla_deadline'] = Sanitiser::text($_POST['sla_deadline']);
        }

        $result = $this->leads->updateCoreFields($leadId, $data);
        $result['success'] ? rto_json_ok(null, $result['message']) : rto_json_err($result['message']);
    }

    // ── Archive / restore (AJAX) ────────────────────────────────────────────
    // Part 5.7: rto_leads.deleted_at has existed since the core schema and
    // every read path already excludes it, but nothing ever wrote to it —
    // there was no way to remove an order from the working list at all.
    // Soft-delete only, same "historical records preserved" discipline this
    // codebase already uses for vendors — never a hard DELETE.
    public function archiveLead(): void
    {
        if (!rto_is_admin())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $result = $this->leads->archiveLead($leadId);
        $result['success'] ? rto_json_ok(null, $result['message']) : rto_json_err($result['message']);
    }

    public function restoreLead(): void
    {
        if (!rto_is_admin())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $result = $this->leads->restoreLead($leadId);
        $result['success'] ? rto_json_ok(null, $result['message']) : rto_json_err($result['message']);
    }

    // ── Bulk archive / restore from the Leads list (AJAX) ───────────────────
    // Same admin gate + nonce as the single-row archiveLead()/restoreLead()
    // above; delegates to LeadService::bulkArchive()/bulkRestore() which do
    // the whole selection in one `WHERE id IN (...)` UPDATE, not a loop.
    public function bulkArchiveLeads(): void
    {
        if (!rto_is_admin())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadIds = array_map('intval', (array)($_POST['lead_ids'] ?? []));
        $result  = $this->leads->bulkArchive($leadIds);
        $result['success'] ? rto_json_ok(['affected' => $result['affected'] ?? 0], $result['message']) : rto_json_err($result['message']);
    }

    public function bulkRestoreLeads(): void
    {
        if (!rto_is_admin())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadIds = array_map('intval', (array)($_POST['lead_ids'] ?? []));
        $result  = $this->leads->bulkRestore($leadIds);
        $result['success'] ? rto_json_ok(['affected' => $result['affected'] ?? 0], $result['message']) : rto_json_err($result['message']);
    }

    // ── Reopen a terminal order (AJAX) — Known Limitations audit fix ───────
    // "Terminal statuses (completed, cancelled) cannot be reversed from the
    // UI ... requires a direct database correction, outside the scope of
    // this admin UI." Deliberately admin-only (not rto_staff, unlike the
    // normal status dropdown) and requires a mandatory reason, mirroring
    // how archive/restore are already admin-gated in this same controller —
    // reopening a closed, already-reconciled financial/reporting record is
    // a materially bigger action than a normal in-flight status change.
    public function reopenLead(): void
    {
        if (!rto_is_admin())        wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $reason = Sanitiser::text($_POST['reason'] ?? '', 500);
        $result = $this->leads->reopenLead($leadId, $reason);
        $result['success'] ? rto_json_ok(null, $result['message']) : rto_json_err($result['message']);
    }

    // ── Single-order CSV export (GET) ───────────────────────────────────────
    // TRACE: admin clicks "Export CSV" on the order detail screen →
    //        /rto-admin/leads/{id}/?export=csv → rto_is_staff() gated →
    //        full order fields + every real submitted form answer (using the
    //        SAME resolution as the on-screen card, category-schema fix
    //        included) written as one flat key/value CSV →
    //        postcondition: browser downloads rtoflow-order-{lead_number}.csv
    public function exportLeadCsv(int $leadId): void
    {
        if (!rto_is_staff()) wp_die('Access denied.', 403);

        $lead = $this->leads->getLeadForAdmin($leadId);
        if (!$lead) wp_die('The requested order could not be found.', 404);

        [, $formAnswersAll] = $this->resolveFormAnswers($lead);

        $filename = 'rtoflow-order-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$lead['lead_number']) . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Field', 'Value']);
        fputcsv($out, ['Lead Number', $lead['lead_number'] ?? '']);
        fputcsv($out, ['Status', rto_status_label($lead['status'] ?? '')]);
        fputcsv($out, ['Service', $lead['service_name'] ?? '']);
        fputcsv($out, ['City', $lead['city_name'] ?? '']);
        fputcsv($out, ['Client', $lead['client_name'] ?? '']);
        fputcsv($out, ['Client Email', $lead['client_email'] ?? '']);
        fputcsv($out, ['Client Mobile', $lead['client_mobile'] ?? '']);
        fputcsv($out, ['Total Amount', $lead['total_amount'] ?? '0.00']);
        fputcsv($out, ['GST Amount', $lead['gst_amount'] ?? '0.00']);
        fputcsv($out, ['Paid Amount', $lead['paid_amount'] ?? '0.00']);
        fputcsv($out, ['Payment Status', $lead['payment_status'] ?? '']);
        fputcsv($out, ['SLA Deadline', $lead['sla_deadline'] ?? '']);
        fputcsv($out, ['Created At', $lead['created_at'] ?? '']);
        fputcsv($out, []);
        fputcsv($out, ['— Submitted Application Details —', '']);
        foreach ($formAnswersAll as $ans) {
            fputcsv($out, [$ans['label'], self::answerValueToString($ans)]);
        }
        fclose($out);
        exit;
    }

    // ── Single-order PDF export (GET) ───────────────────────────────────────
    // TRACE: admin clicks "Export PDF" → /rto-admin/leads/{id}/?export=pdf →
    //        rto_is_staff() gated → real FPDF-generated summary (the same
    //        library InvoiceService::generateFpdf() already uses for tax
    //        invoices, setasign/fpdf per composer.json — genuinely
    //        installed in vendor/, not a stub) built from the real order +
    //        real submitted answers → streamed as application/pdf.
    public function exportLeadPdf(int $leadId): void
    {
        if (!rto_is_staff()) wp_die('Access denied.', 403);

        $lead = $this->leads->getLeadForAdmin($leadId);
        if (!$lead) wp_die('The requested order could not be found.', 404);

        if (!class_exists('\FPDF')) {
            wp_die('PDF export is unavailable on this server (FPDF library not loaded). Use "Export CSV" or the Print view instead.', 500);
        }

        [, $formAnswersAll] = $this->resolveFormAnswers($lead);

        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->SetMargins(15, 15, 15);

        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetTextColor(30, 58, 95);
        $pdf->Cell(0, 10, 'Order Summary: ' . (string)($lead['lead_number'] ?? ''), 0, 1, 'L');

        $pdf->SetDrawColor(30, 58, 95);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(15, $pdf->GetY() + 2, 195, $pdf->GetY() + 2);
        $pdf->Ln(6);

        $rows = [
            ['Status', rto_status_label($lead['status'] ?? '')],
            ['Service', (string)($lead['service_name'] ?? '')],
            ['City', (string)($lead['city_name'] ?? '')],
            ['Client', (string)($lead['client_name'] ?? '')],
            ['Client Email', (string)($lead['client_email'] ?? '')],
            ['Client Mobile', (string)($lead['client_mobile'] ?? '')],
            ['Total Amount', rto_format_inr((float)($lead['total_amount'] ?? 0))],
            ['Paid Amount', rto_format_inr((float)($lead['paid_amount'] ?? 0))],
            ['Payment Status', ucfirst((string)($lead['payment_status'] ?? 'unpaid'))],
            ['Created', rto_date($lead['created_at'] ?? '', 'd M Y H:i')],
        ];
        $pdf->SetFont('Helvetica', '', 10);
        foreach ($rows as [$k, $v]) {
            $pdf->SetTextColor(80, 80, 80);
            $pdf->Cell(50, 6, $k, 0, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(0, 6, $v, 0, 1, 'L');
        }

        $pdf->Ln(4);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(30, 58, 95);
        $pdf->Cell(0, 8, 'Submitted Application Details', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 9);

        $curStep = null;
        foreach ($formAnswersAll as $ans) {
            if ($ans['step'] !== $curStep && $ans['step'] !== '') {
                $curStep = $ans['step'];
                $pdf->Ln(2);
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->SetTextColor(30, 58, 95);
                $pdf->Cell(0, 6, $curStep, 0, 1, 'L');
                $pdf->SetFont('Helvetica', '', 9);
            }
            $value = self::answerValueToString($ans);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->Cell(60, 6, $ans['label'], 0, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell(0, 6, $value !== '' ? $value : '-');
        }

        $filename = 'rtoflow-order-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$lead['lead_number']) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $pdf->Output('S');
        exit;
    }
}
