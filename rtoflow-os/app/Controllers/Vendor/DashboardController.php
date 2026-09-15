<?php

namespace RTOFLOW\Controllers\Vendor;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Security\FileUploadGuard;
use RTOFLOW\Services\AuditService;
use RTOFLOW\Services\VendorService;
use RTOFLOW\Config\MatchingConfig;

if (!defined('ABSPATH')) exit;

class DashboardController
{
    public function index(): void
    {
        if (!rto_is_vendor()) { wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]); }

        global $wpdb;
        $userId   = get_current_user_id();
        $p        = $wpdb->prefix;
        $vendor   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}rto_vendors WHERE user_id=%d",$userId), ARRAY_A);
        if (!$vendor) wp_die('Vendor profile not found.', 'Not Found', ['response' => 404, 'back_link' => true]);

        $vendorId  = (int)$vendor['id'];
        $month     = date('Y-m');
        $lastMonth = date('Y-m', strtotime('-1 month'));

        // P6-UX-013 FIX: use DB COUNT for KPI, not PHP array length
        $activeJobCount = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}rto_leads WHERE vendor_id=%d AND status NOT IN ('completed','cancelled') AND deleted_at IS NULL",
            $vendorId
        ));

        $activeJobs = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, s.name as service_name, c.name as city_name
             FROM {$p}rto_leads l
             JOIN {$p}rto_services s ON s.id=l.service_id
             JOIN {$p}rto_cities c ON c.id=l.city_id
             WHERE l.vendor_id=%d AND l.status NOT IN ('completed','cancelled') AND l.deleted_at IS NULL
             ORDER BY l.sla_deadline ASC LIMIT 10",
            $vendorId
        ), ARRAY_A) ?: [];

        $completedMonth = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}rto_leads WHERE vendor_id=%d AND status='completed' AND DATE_FORMAT(completed_at,'%%Y-%%m')=%s",
            $vendorId, $month
        ));

        $earningsMonth = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(net_amount),0) FROM {$p}rto_vendor_payouts WHERE vendor_id=%d AND period=%s AND status='paid'",
            $vendorId, $month
        ));

        $pendingPayout = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(net_amount),0) FROM {$p}rto_vendor_payouts WHERE vendor_id=%d AND status='pending'",
            $vendorId
        ));

        $recentJobs = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, s.name as service_name FROM {$p}rto_leads l JOIN {$p}rto_services s ON s.id=l.service_id
             WHERE l.vendor_id=%d AND l.deleted_at IS NULL ORDER BY l.updated_at DESC LIMIT 10",
            $vendorId
        ), ARRAY_A) ?: [];

        $pendingJobs = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, l.lead_number, s.name as service_name, c.name as city_name,
                    l.sla_deadline, l.total_amount, s.vendor_share
             FROM {$p}rto_assignments a
             JOIN {$p}rto_leads l ON l.id=a.lead_id
             JOIN {$p}rto_services s ON s.id=l.service_id
             JOIN {$p}rto_cities c ON c.id=l.city_id
             WHERE a.vendor_id=%d AND a.status='pending'",
            $vendorId
        ), ARRAY_A) ?: [];

        // ENTERPRISE GAP FIX (Phase 4, item 4 — "no vendor performance
        // scorecard visible to the vendor"): VendorService::scoreVendor()
        // already computes the exact composite score that drives this
        // vendor's own matching/assignment priority (VendorService::
        // auto_assign() calls the identical static method with the same
        // weights) — it just never reached the vendor's own screen. Reusing
        // the real formula and the real currently-configured weights (not a
        // re-derived approximation) so what the vendor sees is provably the
        // same number the matching engine actually uses, not a look-alike.
        $matchingWeights = [
            'rating_weight'     => MatchingConfig::get('rating_weight'),
            'completion_weight' => MatchingConfig::get('completion_weight'),
            'acceptance_weight' => MatchingConfig::get('acceptance_weight'),
            'load_weight'       => MatchingConfig::get('load_weight'),
        ];
        $maxActiveJobs   = max(1, (int)MatchingConfig::get('max_active_jobs'));
        $performanceScore = VendorService::scoreVendor($vendor, $activeJobCount, $maxActiveJobs, $matchingWeights);

        rto_view('vendor.dashboard.index', compact('vendor','activeJobs','activeJobCount','completedMonth','earningsMonth','accruedMonth','pendingPayout','recentJobs','pendingJobs','performanceScore','maxActiveJobs'));
    }

    // ── Accept / reject job (AJAX) ────────────────────────────────────────
    public function respondToAssignment(): void
    {
        if (!rto_is_vendor())       wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_vendor_job', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        global $wpdb;
        $userId       = get_current_user_id();

        // ENTERPRISE GAP FIX (Phase 11, item — "No per-vendor or per-city
        // fairness in rate limiting"): this handler is on the shared
        // assignment queue — a single vendor script hammering
        // accept/reject in a tight loop could previously consume an
        // unbounded share of DB/queue capacity with no per-vendor ceiling
        // at all (only the generic global rate-limit groups exist).
        // checkFair('vendor', ...) is a SEPARATE bucket keyed to this one
        // vendor id, so one vendor maxing out their own allowance never
        // touches another vendor's bucket.
        $vendorRow = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_vendors WHERE user_id=%d", $userId
        ), ARRAY_A);
        if ($vendorRow && !\RTOFLOW\Http\Middleware\RateLimiter::checkFair('vendor', (int)$vendorRow['id'])) {
            \RTOFLOW\Http\Middleware\RateLimiter::abort('Too many assignment actions — please slow down.');
        }

        $assignmentId = Sanitiser::int($_POST['assignment_id'] ?? 0, 1);
        $action       = Sanitiser::text($_POST['action_type'] ?? '');
        $reason       = Sanitiser::text($_POST['reason'] ?? '', 500);

        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, v.user_id FROM {$wpdb->prefix}rto_assignments a
             JOIN {$wpdb->prefix}rto_vendors v ON v.id = a.vendor_id
             WHERE a.id=%d AND a.status='pending'",
            $assignmentId
        ), ARRAY_A);

        if (!$assignment || (int)$assignment['user_id'] !== $userId) {
            rto_json_err('Assignment not found or not authorised.');
        }

        // BUGFIX (ghost-success sweep, same class of bug already fixed this
        // pass for the equivalent Router.php vendor.accept_job/reject_job
        // handlers and VendorService::auto_assign()): both branches ran two
        // independent, unchecked writes (the assignment status update and
        // the lead status update) with no transaction. A failure of either
        // could leave the assignment and its lead in disagreeing states —
        // e.g. an assignment marked 'accepted' with the lead still 'assigned'
        // — while the vendor was still told the action succeeded and an
        // audit entry / action hook still fired regardless.
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            if ($action === 'accept') {
                $assignmentUpdated = $wpdb->update($wpdb->prefix . 'rto_assignments', [
                    'status' => 'accepted', 'accepted_at' => current_time('mysql')
                ], ['id' => $assignmentId]);
                if ($assignmentUpdated === false) {
                    throw new \RuntimeException('assignment status update failed: ' . $wpdb->last_error);
                }

                $leadUpdated = $wpdb->update($wpdb->prefix . 'rto_leads', [
                    'status' => 'in_progress', 'updated_at' => current_time('mysql')
                ], ['id' => $assignment['lead_id']]);
                if ($leadUpdated === false) {
                    throw new \RuntimeException('lead status update failed: ' . $wpdb->last_error);
                }
            } else {
                $assignmentUpdated = $wpdb->update($wpdb->prefix . 'rto_assignments', [
                    'status' => 'rejected', 'rejected_at' => current_time('mysql'), 'reject_reason' => $reason
                ], ['id' => $assignmentId]);
                if ($assignmentUpdated === false) {
                    throw new \RuntimeException('assignment status update failed: ' . $wpdb->last_error);
                }

                $leadUpdated = $wpdb->update($wpdb->prefix . 'rto_leads', [
                    'vendor_id' => null, 'status' => 'payment_received', 'updated_at' => current_time('mysql')
                ], ['id' => $assignment['lead_id']]);
                if ($leadUpdated === false) {
                    throw new \RuntimeException('lead status update failed: ' . $wpdb->last_error);
                }
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('RTOFLOW respondToAssignment: ' . $e->getMessage());
            rto_json_err('Unable to process your response. Please try again.');
        }

        if ($action === 'accept') {
            AuditService::log('assignment.accepted', (int)$assignment['lead_id']);
            rto_json_ok(null, 'Job accepted. Please proceed with the service.');
        } else {
            AuditService::log('assignment.rejected', (int)$assignment['lead_id'], ['reason' => $reason]);
            do_action('rtoflow_vendor_rejected_job', (int)$assignment['lead_id']);
            rto_json_ok(null, 'Job rejected. Admin has been notified for reassignment.');
        }
    }

    // ENTERPRISE GAP FIX (Phase 4, item 1 — "no vendor or client in-app
    // messaging beyond client<->staff"): resources/views/vendor/jobs/show.php
    // already had a message box wired to vendorAjax('upload_doc', {message:
    // msg, send_message_only:'1'}) — but uploadDocument() above has no
    // send_message_only branch at all; it goes straight to `if
    // (empty($_FILES['file'])) rto_json_err('No file provided.')`, so every
    // vendor message attempt through that button has always failed with
    // "No file provided." This is a dedicated action instead of a branch
    // bolted onto uploadDocument() — same separation Client\DashboardController
    // uses for sendMessage() vs its document upload. Mirrors that method's
    // shape exactly (rate limit, ownership check, checked insert, ghost-
    // success guard) with the vendor-side ownership check swapped in.
    public function sendMessage(): void
    {
        if (!is_user_logged_in()) rto_json_err('Not authenticated.', 401);
        if (!rto_is_vendor()) rto_json_err('You do not have permission to do this.', 403);
        if (!check_ajax_referer('rto_vendor_nonce', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!\RTOFLOW\Http\Middleware\RateLimiter::check('submit')) \RTOFLOW\Http\Middleware\RateLimiter::abort();

        $leadId  = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $message = Sanitiser::text($_POST['message'] ?? '', 2000);
        if (!$message) rto_json_err('Message cannot be empty.');

        global $wpdb;
        $userId = get_current_user_id();
        $vendorId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_vendors WHERE user_id=%d", $userId
        ));
        if (!$vendorId) rto_json_err('Vendor profile not found.', 403);

        $isAssigned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_leads WHERE id=%d AND vendor_id=%d AND deleted_at IS NULL",
            $leadId, $vendorId
        ));
        if (!$isAssigned) rto_json_err('Job not found or not assigned to you.', 403);

        $msgInserted = $wpdb->insert($wpdb->prefix . 'rto_messages', [
            'lead_id'     => $leadId,
            'user_id'     => $userId,
            'message'     => $message,
            'sender_type' => 'vendor',
            'created_at'  => current_time('mysql'),
        ]);

        if (!$msgInserted) {
            error_log('RTOFLOW Vendor DashboardController: message insert failed for lead ' . $leadId . ' — ' . $wpdb->last_error);
            rto_json_err('Unable to send your message. Please try again.');
        }

        rto_json_ok(null, 'Message sent.');
    }

    // ENTERPRISE GAP FIX (Phase 4, item 1 follow-up): same view's
    // "Update Status" buttons (Mark In Progress / Request More Documents /
    // Submit to RTO / RTO Processing Started / Mark Completed) also call
    // vendorAjax('upload_doc', {status: ..., update_status_only:'1'}) —
    // equally unhandled by uploadDocument(), meaning the entire vendor
    // status-progression UI silently failed with "No file provided." too.
    // Enforces the same forward-only sequence the view itself renders
    // (statusFlow order) server-side, not just via the view's disabled
    // attribute — a vendor could otherwise POST an arbitrary status value
    // directly to this action.
    public function updateJobStatus(): void
    {
        if (!rto_is_vendor()) rto_json_err('You do not have permission to do this.', 403);
        if (!check_ajax_referer('rto_vendor_nonce', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId    = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $newStatus = Sanitiser::text($_POST['status'] ?? '', 30);

        $statusFlow      = ['assigned','in_progress','pending_docs','docs_submitted','rto_submitted','rto_processing','completed'];
        $allowedVendorStatuses = ['in_progress','pending_docs','docs_submitted','rto_submitted','completed'];
        if (!in_array($newStatus, $allowedVendorStatuses, true)) rto_json_err('Invalid status.');

        global $wpdb;
        $userId = get_current_user_id();
        $vendorId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_vendors WHERE user_id=%d", $userId
        ));
        if (!$vendorId) rto_json_err('Vendor profile not found.', 403);

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, lead_number, client_id FROM {$wpdb->prefix}rto_leads WHERE id=%d AND vendor_id=%d AND deleted_at IS NULL",
            $leadId, $vendorId
        ), ARRAY_A);
        if (!$lead) rto_json_err('Job not found or not assigned to you.', 403);

        $currentIdx = array_search($lead['status'], $statusFlow, true);
        $newIdx     = array_search($newStatus, $statusFlow, true);
        if ($currentIdx === false || $newIdx === false || $newIdx <= $currentIdx) {
            rto_json_err('That status change is not allowed from the current status.');
        }

        $updated = $wpdb->update($wpdb->prefix . 'rto_leads', [
            'status'       => $newStatus,
            'updated_at'   => current_time('mysql'),
            'completed_at' => $newStatus === 'completed' ? current_time('mysql') : null,
        ], ['id' => $leadId]);

        if ($updated === false) {
            error_log('RTOFLOW Vendor DashboardController: status update failed for lead ' . $leadId . ' — ' . $wpdb->last_error);
            rto_json_err('Unable to update status. Please try again.');
        }

        AuditService::log('lead.status_changed', $leadId, ['from' => $lead['status'], 'to' => $newStatus]);
        // Matches the arg shape every other caller of this hook uses
        // (LeadService::updateStatus(), Router::vendorAcceptJob()) — the 4th
        // arg is the full lead row, not a user id; onStatusChanged() reads
        // $lead['client_id'] and $lead['lead_number'] off it directly.
        $oldStatus = $lead['status'];
        $lead['status'] = $newStatus;
        do_action('rtoflow_lead_status_changed', $leadId, $oldStatus, $newStatus, $lead);

        rto_json_ok(null, 'Status updated.');
    }

    // ENTERPRISE GAP FIX (Phase 4, item 5 — "no rating or suspension appeal
    // workflow for vendors"): see VendorAppealService's docblock. Called from
    // the "Request Review" button on a rating (subject_type='rating',
    // subject_id=rating id) or on the account-suspended notice
    // (subject_type='suspension', subject_id=0) in vendor/profile/index.php.
    public function submitAppeal(): void
    {
        if (!rto_is_vendor()) rto_json_err('You do not have permission to do this.', 403);
        if (!check_ajax_referer('rto_vendor_nonce', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $subjectType = Sanitiser::text($_POST['subject_type'] ?? '', 20);
        $subjectId   = Sanitiser::int($_POST['subject_id'] ?? 0);
        $reason      = Sanitiser::text($_POST['reason'] ?? '', 1000);

        if (!in_array($subjectType, ['rating', 'suspension'], true)) rto_json_err('Invalid appeal type.');
        if (!$reason) rto_json_err('Please explain why you are appealing this.');

        global $wpdb;
        $userId   = get_current_user_id();
        $vendorId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rto_vendors WHERE user_id=%d", $userId));
        if (!$vendorId) rto_json_err('Vendor profile not found.', 403);

        if ($subjectType === 'rating') {
            $ownsRating = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rto_ratings WHERE id=%d AND vendor_id=%d", $subjectId, $vendorId
            ));
            if (!$ownsRating) rto_json_err('Rating not found.', 404);
        }

        if (\RTOFLOW\Services\VendorAppealService::hasPending($vendorId, $subjectType, $subjectId)) {
            rto_json_err('You already have a pending appeal for this — please wait for it to be reviewed.');
        }

        $id = \RTOFLOW\Services\VendorAppealService::submit($vendorId, $subjectType, $subjectId, $reason);
        if (!$id) rto_json_err('Unable to submit your appeal. Please try again.');

        AuditService::log('vendor.appeal_submitted', null, ['vendor_id' => $vendorId, 'subject_type' => $subjectType, 'subject_id' => $subjectId]);
        rto_json_ok(null, 'Your appeal has been submitted for review.');
    }

    // ENTERPRISE GAP FIX (Phase 5, item 5 — "rating response feature for
    // vendors"): a short, one-time public reply on a rating the vendor
    // received. One reply per rating (can't be edited/re-posted once set —
    // mirrors the immutability of the rating itself); ownership is verified
    // server-side, not just hidden in the UI.
    public function respondToRating(): void
    {
        if (!rto_is_vendor()) rto_json_err('You do not have permission to do this.', 403);
        if (!check_ajax_referer('rto_vendor_nonce', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $ratingId = Sanitiser::int($_POST['rating_id'] ?? 0);
        $response = Sanitiser::text($_POST['response'] ?? '', 500);
        if (!$response) rto_json_err('Please write a reply before posting.');

        global $wpdb;
        $userId   = get_current_user_id();
        $vendorId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rto_vendors WHERE user_id=%d", $userId));
        if (!$vendorId) rto_json_err('Vendor profile not found.', 403);

        $rating = $wpdb->get_row($wpdb->prepare(
            "SELECT id, vendor_response FROM {$wpdb->prefix}rto_ratings WHERE id=%d AND vendor_id=%d", $ratingId, $vendorId
        ), ARRAY_A);
        if (!$rating) rto_json_err('Rating not found.', 404);
        if (!empty($rating['vendor_response'])) rto_json_err('You have already replied to this rating.');

        $updated = $wpdb->update($wpdb->prefix . 'rto_ratings', [
            'vendor_response'    => $response,
            'vendor_response_at' => current_time('mysql'),
        ], ['id' => $ratingId]);
        if ($updated === false) rto_json_err('Could not save your reply. Please try again.', 500);

        AuditService::log('vendor.rating_response', null, ['vendor_id' => $vendorId, 'rating_id' => $ratingId]);
        rto_json_ok(null, 'Your reply has been posted.');
    }

    // ENTERPRISE GAP FIX (Phase 5, item 1 — "vendor leaderboard /
    // gamification"): ranks active vendors by the same score
    // VendorService::scoreVendor() already computes to drive auto-
    // assignment priority — no new scoring logic, purely a new view over
    // data that already exists server-side. Scoped to vendors sharing at
    // least one city with the viewing vendor (their real competitive set —
    // ranking a Delhi vendor against a Mumbai-only vendor would be
    // meaningless) and shows only first-name + last-initial for anyone
    // other than the viewing vendor's own row, out of a basic privacy
    // consideration (a vendor's full legal name is PII other vendors have
    // no operational need to see).
    // TRACE: routeVendor() 'leaderboard' arm → is_vendor check → loads the
    // viewing vendor's own cities → queries other active vendors sharing at
    // least one city → scores each via the identical VendorService::
    // scoreVendor() formula auto_assign() uses → sorts desc → renders →
    // preconditions: vendor profile exists → postconditions: read-only, no
    // writes → edge cases: no vendors share a city (single-city market with
    // only this vendor) → list of one; malformed/empty cities JSON → treated
    // as "no cities", vendor excluded from ranking rather than fataling.
    public function leaderboard(): void
    {
        if (!rto_is_vendor()) { wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]); }

        global $wpdb;
        $p      = $wpdb->prefix;
        $userId = get_current_user_id();
        $me     = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}rto_vendors WHERE user_id=%d", $userId), ARRAY_A);
        if (!$me) wp_die('Vendor profile not found.', 'Not Found', ['response' => 404, 'back_link' => true]);

        $myCities = json_decode($me['cities'] ?? '[]', true) ?: [];

        $candidates = $wpdb->get_results(
            "SELECT id, full_name, cities, rating, completion_rate, acceptance_rate FROM {$p}rto_vendors WHERE status='active'",
            ARRAY_A
        ) ?: [];

        $maxActiveJobs = (int)MatchingConfig::get('max_active_jobs');
        $weights = [
            'rating_weight'     => MatchingConfig::get('rating_weight'),
            'completion_weight' => MatchingConfig::get('completion_weight'),
            'acceptance_weight' => MatchingConfig::get('acceptance_weight'),
            'load_weight'       => MatchingConfig::get('load_weight'),
        ];

        $ranked = [];
        foreach ($candidates as $v) {
            $theirCities = json_decode($v['cities'] ?? '[]', true) ?: [];
            if (!array_intersect($myCities, $theirCities)) continue;

            $active = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}rto_leads WHERE vendor_id=%d AND status NOT IN ('completed','cancelled') AND deleted_at IS NULL",
                (int)$v['id']
            ));
            $score = VendorService::scoreVendor($v, $active, $maxActiveJobs, $weights);

            $nameParts = explode(' ', trim($v['full_name']));
            $displayName = (int)$v['id'] === (int)$me['id']
                ? $v['full_name']
                : trim(($nameParts[0] ?? 'Vendor') . ' ' . (isset($nameParts[1]) ? strtoupper(substr($nameParts[1], 0, 1)) . '.' : ''));

            $ranked[] = [
                'id'      => (int)$v['id'],
                'name'    => $displayName,
                'is_me'   => (int)$v['id'] === (int)$me['id'],
                'rating'  => (float)$v['rating'],
                'score'   => $score,
            ];
        }

        usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
        $myRank = null;
        foreach ($ranked as $i => $r) {
            if ($r['is_me']) { $myRank = $i + 1; break; }
        }

        rto_view('vendor.leaderboard.index', ['ranked' => $ranked, 'myRank' => $myRank]);
    }

    // ENTERPRISE GAP FIX (Phase 4, item 6 — "no notification preference
    // controls anywhere"): the vendor-side half of
    // NotificationPreferenceService — see its docblock for scope (channel
    // on/off only; quiet hours are saved but not yet enforced).
    public function savePreferences(): void
    {
        if (!rto_is_vendor()) rto_json_err('You do not have permission to do this.', 403);
        if (!check_ajax_referer('rto_vendor_nonce', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $ok = \RTOFLOW\Services\NotificationPreferenceService::save(get_current_user_id(), [
            'email_enabled'     => !empty($_POST['email_enabled']),
            'sms_enabled'       => !empty($_POST['sms_enabled']),
            'whatsapp_enabled'  => !empty($_POST['whatsapp_enabled']),
            'quiet_hours_start' => $_POST['quiet_hours_start'] ?? '',
            'quiet_hours_end'   => $_POST['quiet_hours_end']   ?? '',
        ]);
        if (!$ok) rto_json_err('Unable to save your preferences. Please try again.');
        rto_json_ok(null, 'Notification preferences saved.');
    }

    // ── Upload document (AJAX) ────────────────────────────────────────────
    public function uploadDocument(): void
    {
        if (!rto_is_vendor())       wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_vendor_nonce', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $leadId    = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $docTypeId = Sanitiser::int($_POST['doc_type_id'] ?? 0);

        // P2-SEC-007 FIX: verify the authenticated vendor is assigned to this lead
        global $wpdb;
        $userId = get_current_user_id();
        $vendorRecord = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_vendors WHERE user_id = %d", $userId
        ), ARRAY_A);
        if (!$vendorRecord) rto_json_err('Vendor profile not found.', 403);

        $isAssigned = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_leads
             WHERE id = %d AND vendor_id = %d AND deleted_at IS NULL",
            $leadId, (int)$vendorRecord['id']
        ));
        if (!$isAssigned) rto_json_err('Lead not found or not assigned to you.', 403);

        if (empty($_FILES['file'])) rto_json_err('No file provided.');

        $stored = FileUploadGuard::store($_FILES['file'], 'documents', $leadId);
        if (!$stored['success']) rto_json_err($stored['error']);

        global $wpdb;
        // BUGFIX (ghost-success sweep): this insert's return value was never
        // checked. The file was already physically written to disk by
        // FileUploadGuard::store() above, so a silently failed insert here
        // previously left an orphaned file on disk with no database record at
        // all, while the vendor was told "Document uploaded successfully"
        // with a doc_id taken from whatever $wpdb->insert_id happened to
        // still hold from an unrelated prior insert. Now the insert is
        // checked; on failure the orphaned file is cleaned up immediately and
        // a real error is returned instead of a false success.
        $docInserted = $wpdb->insert($wpdb->prefix . 'rto_documents', [
            'lead_id'     => $leadId,
            'doc_type_id' => $docTypeId,
            'file_path'   => $stored['path'],
            'file_name'   => $stored['name'],
            'file_size_kb'=> $stored['size_kb'],
            'mime_type'   => $_FILES['file']['type'],
            'status'      => 'pending',
            'uploaded_by' => get_current_user_id(),
            'created_at'  => current_time('mysql'),
        ]);

        if (!$docInserted) {
            error_log('RTOFLOW Vendor DashboardController: document row insert failed for lead ' . $leadId . ' — ' . $wpdb->last_error);
            \RTOFLOW\Security\FileUploadGuard::delete($stored['path']);
            rto_json_err('Unable to save the uploaded document. Please try again.');
        }

        AuditService::logDocumentAction($leadId, 'uploaded', (int)$wpdb->insert_id);
        rto_json_ok(['doc_id' => $wpdb->insert_id], 'Document uploaded successfully.');
    }

    // ENTERPRISE GAP FIX (gap-analysis Section 1 — "vendor KYC has no
    // document-review workflow"): the vendor-facing half. Lets a vendor
    // upload their own KYC proof (PAN, Aadhaar, bank proof, etc.) so there
    // is finally something for VendorsController::verifyKycDocument()/
    // rejectKycDocument() on the admin side to actually review — before
    // this, VendorsController::updateKyc() could mark a vendor "verified"
    // with zero documents ever collected.
    //
    // TRACE: vendor submits the KYC upload form on their profile page →
    //        rto_is_vendor() gate → resolves the vendor's own vendor_id from
    //        their user_id (never trusts a posted vendor_id — a vendor must
    //        only ever be able to upload against their own record) →
    //        FileUploadGuard::store() validates + writes the file →
    //        inserts a 'pending' rto_vendor_documents row →
    //        preconditions: logged in as a vendor with a vendor profile row →
    //        postconditions: one new rto_vendor_documents row, status
    //        'pending', file written under FileUploadGuard's protected
    //        storage root (not directly web-accessible — see
    //        VendorsController::serveKycDocument() for the only access path) →
    //        edge cases: insert fails after file already written → orphaned
    //        file is deleted immediately (same ghost-success guard pattern
    //        as uploadDocument() above)
    public function uploadKycDocument(): void
    {
        if (!rto_is_vendor()) wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        if (!check_ajax_referer('rto_vendor_nonce', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $label = Sanitiser::text($_POST['doc_label'] ?? '', 100);
        if (!$label) rto_json_err('Document type is required.');
        if (empty($_FILES['file'])) rto_json_err('No file provided.');

        global $wpdb;
        $userId = get_current_user_id();
        $vendorRecord = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_vendors WHERE user_id = %d", $userId
        ), ARRAY_A);
        if (!$vendorRecord) rto_json_err('Vendor profile not found.', 403);
        $vendorId = (int)$vendorRecord['id'];

        $stored = FileUploadGuard::store($_FILES['file'], 'vendor-kyc', $vendorId);
        if (!$stored['success']) rto_json_err($stored['error']);

        $inserted = $wpdb->insert($wpdb->prefix . 'rto_vendor_documents', [
            'vendor_id'    => $vendorId,
            'doc_label'    => $label,
            'file_path'    => $stored['path'],
            'file_name'    => $stored['name'],
            'file_size_kb' => $stored['size_kb'],
            'mime_type'    => $_FILES['file']['type'],
            'status'       => 'pending',
            'uploaded_by'  => $userId,
            'created_at'   => current_time('mysql'),
        ]);

        if (!$inserted) {
            error_log('RTOFLOW Vendor DashboardController: KYC document row insert failed for vendor ' . $vendorId . ' — ' . $wpdb->last_error);
            FileUploadGuard::delete($stored['path']);
            rto_json_err('Unable to save the uploaded document. Please try again.');
        }

        AuditService::log('vendor.kyc_document_uploaded', null, ['vendor_id' => $vendorId, 'doc_label' => $label, 'doc_id' => (int)$wpdb->insert_id]);
        rto_json_ok(['doc_id' => $wpdb->insert_id], 'Document uploaded — awaiting review.');
    }
}
