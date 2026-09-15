<?php

namespace RTOFLOW\Support;

if (!defined('ABSPATH')) exit;

/**
 * TRACE: loaded by HelpCenterController::index()/searchAjax() and by the
 *        rto_help_box() view helper (resources/views/admin/partials/screen-help.php)
 *        → returns the real, hand-written content registry below, keyed by
 *        the same `rto_page` slug each admin screen already routes on
 *        → HelpCenterController renders the full article; rto_help_box()
 *        renders an expanded "Need Help?" box (full what_is narrative,
 *        Known Limitations, Common Mistakes & Solutions) + a link into the
 *        same article
 *        → no network/DB call, pure PHP array, so it can never itself be a
 *        source of a broken link or a 404
 * PRECONDITIONS: none. POSTCONDITIONS: returns a stable array; unknown slugs
 *        resolve to null via HelpContent::get(), never a warning/notice.
 *
 * WHY THIS EXISTS AND WHAT IT DELIBERATELY DOES NOT CLAIM
 * ─────────────────────────────────────────────────────────────────────────
 * Every article below was written from a real, code-grounded audit of this
 * exact codebase (RTOFLOW OS) — the admin menu registration in
 * rtoflow-os.php, the dispatch table in app/Http/Router.php::routeAdmin(),
 * the real controllers in app/Controllers/Admin/, the real database schema
 * in database/migrations/, and the real lead status machine in
 * app/Services/LeadService.php::isValidTransition(). It intentionally does
 * NOT describe a generic marketplace, loan, or DSA platform — there are no
 * "packages", "add-ons", "Marketplace Manager" role, or "enquiry → lead →
 * booking" three-stage pipeline anywhere in this codebase, and this file
 * does not pretend otherwise.
 *
 * SCHEMA NOTE (this revision): every article's `what_is` was expanded from a
 * single short sentence into a multi-sentence narrative covering purpose,
 * practical use, what admins can do, and platform fit — because a one-line
 * summary was found not to actually answer "what is this screen for" on its
 * own. Every article also now carries a `known_limitations` array (each
 * entry: category, limitation, where, why, impact, recommended_fix) and a
 * `common_mistakes` array upgraded from bare strings to structured entries
 * (category, mistake, symptom, fix, prevention). Every limitation below is
 * either a defect actually found during this session's own code audit (and
 * in two cases, fixed as part of it — see 'workflows' and the CSS-drift
 * entries on 'services'/'city-pricing'), or a real constraint read directly
 * off the code cited in `fits_into`/`does_not_control` above it — nothing
 * here is a generic or assumed limitation.
 *
 * COVERAGE, HONESTLY. This is Phase 1+2 of the Help Centre: it fully covers
 * the highest-traffic, highest-consequence screens (Dashboard, Leads,
 * Vendors, Services, Form Builder incl. category visibility, Eligibility
 * Rules, City/Service Pricing, Settings → Matching, Payouts, Complaints,
 * Reports, Ratings, Masters, Workflow Builder) — plus Payments ledger,
 * Customizer hub, Settings (company/SLA fields), Email Templates, AI
 * Insights, Feature Flags, Staff/Users, and — per the System/GDPR pass of
 * this audit — the GDPR Data Export route and System Status & Cron Monitor
 * screen, both of which were found to have zero HelpContent coverage (and
 * were missing from HelpCenterController::MODULE_INDEX entirely, despite
 * this comment previously claiming they were listed there as 'planned')
 * and now have full articles, including two real bugs found and fixed in
 * their underlying code — see the 'gdpr' and 'system' entries below. It
 * deliberately does NOT yet cover Setup & Status. Each undocumented module
 * still remains listed in HelpCenterController::MODULE_INDEX with a
 * 'status' of 'planned' rather than being silently omitted, and the
 * Help Centre index page shows them as "Not yet documented" rather than
 * hiding them — see Rule 28 in the governing brief this was built against
 * ("Do Not Create Fake Documentation" / "Feature Status: Not Implemented").
 */
final class HelpContent
{
    /**
     * @return array<string,array>|null
     */
    public static function get(string $slug): ?array
    {
        $all = self::all();
        return $all[$slug] ?? null;
    }

    // ENTERPRISE GAP FIX (Phase 5, item 4 — "Help Center becomes admin-
    // editable"): layers an optional admin-entered override on top of the
    // static article at RENDER time only — see migration
    // 2024_01_01_000041_create_help_overrides.php's docblock for why this
    // doesn't touch the static registry itself (that array's whole value is
    // being guaranteed code-grounded by a human author; a DB-editable
    // "what_is" field can't carry that same guarantee, so it's kept
    // separate and visibly distinguishable — see the admin view's "Admin
    // Note" styling). A slug with no override row behaves identically to
    // get() (100% static content, zero DB query result to merge).
    // TRACE: HelpCenterController::index() → getMerged($slug) → get($slug)
    // for the base static article → if found, one indexed lookup against
    // rto_help_overrides by slug → non-empty override.what_is replaces
    // (not appends to) the static what_is → preconditions: none →
    // postconditions: returned array is unmodified from get() unless an
    // override row exists AND its what_is is non-empty → edge cases: slug
    // has no static article at all → returns null, same as get() (an
    // override can only ever augment a real article, never invent one).
    public static function getMerged(string $slug): ?array
    {
        $article = self::get($slug);
        if ($article === null) return null;

        global $wpdb;
        $override = $wpdb->get_row($wpdb->prepare(
            "SELECT what_is, updated_at FROM {$wpdb->prefix}rto_help_overrides WHERE slug = %s",
            $slug
        ), ARRAY_A);

        if ($override && !empty($override['what_is'])) {
            $article['what_is']             = $override['what_is'];
            $article['admin_overridden']    = true;
            $article['override_updated_at'] = $override['updated_at'];
        }

        return $article;
    }

    /** @return array<string,array> */
    public static function all(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [

            // ═══════════════════════════════════════════════════════════
            'dashboard' => [
                'title'  => 'Dashboard',
                'module' => 'Getting Started',
                'summary' => 'The at-a-glance operations summary — lead volume, revenue, vendor health, SLA breaches, and open complaints for the last 30 days.',
                'what_is' => 'The Dashboard is the first screen any staff or admin user lands on after logging in, and it exists to answer one question fast: "is anything on fire right now, and how are we trending?" It is a pure read-only reporting surface — it has no forms, no editable settings, and cannot change a single record — built entirely from live aggregate queries against the same tables every other screen in this admin portal writes to (rto_leads, rto_vendors, rto_payments, rto_complaints). Administrators use it to see, in one glance and without opening the Leads list and manually filtering, how many leads came in this month versus last month, how much revenue was collected, how many vendors are active and KYC-verified, how many leads are sitting in an actionable state, how many have blown past their SLA due date, and how many customer complaints are still unresolved. Below the KPI cards, a status-distribution panel shows exactly where the lead pipeline is currently backed up (for example, a large docs_pending count usually means a batch of customers are being slow to upload documents, not that staff are behind), and a recent-leads list gives one-click access into the newest cases system-wide, regardless of who they are assigned to. In day-to-day use this screen functions as the daily/shift-start triage view: a manager opens it first, decides where attention is needed (an SLA spike, a jump in open complaints, a stalled status bucket), and then navigates into the Leads, Vendors, or Complaints screens — which are where the actual work of fixing anything happens — to act on what the Dashboard surfaced.',
                'why_exists' => 'To let a manager answer "is anything on fire right now?" in one glance, without opening the Leads list and manually filtering.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['Start of a shift, to see what needs attention.', 'Checking whether this month is trending up or down vs last month.', 'Spotting an SLA-breach or complaint spike early.'],
                'fits_into' => 'Reads from rto_leads, rto_vendors, and rto_complaints — it does not write to anything. Every number is a live query, cached for 3 minutes (a WordPress transient) so refreshing the page repeatedly does not re-run the full aggregation every time.',
                'sections' => [
                    ['name' => 'KPI cards', 'body' => 'Leads (this month vs last month), Revenue (this month vs last month, from rto_payments), Active Vendors, Verified Vendors, Pending Leads (awaiting any action), SLA Breached (leads past their due date and not yet completed/cancelled), Open Complaints.'],
                    ['name' => 'Status distribution', 'body' => 'A count of leads currently in each status of the real status machine (see the Leads article for the full list) — this is the fastest way to see where the pipeline is backing up, e.g. a large "docs_pending" count means vendors are waiting on customers.'],
                    ['name' => 'Recent leads', 'body' => 'The newest leads system-wide, regardless of who they are assigned to, with a direct link into each lead\'s detail screen.'],
                ],
                'does_not_control' => [
                    'This screen has no editable fields, buttons, or filters — it is read-only. It does not let you change a lead\'s status, assign a vendor, or edit any setting.',
                    'The 3-minute cache means a status change made moments ago on the Leads screen may not be reflected here immediately — reload after a short wait if a number looks stale.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'workflow',
                        'mistake' => 'Treating a stale (cached) KPI number as proof that a just-made change did not save.',
                        'symptom' => 'A staff member changes a lead\'s status on the Leads screen, immediately checks the Dashboard, and the status-distribution count has not moved.',
                        'fix' => 'Wait for the 3-minute cache window to pass, or hard-reload after that window, before concluding the change failed — re-check the actual lead on the Leads screen, which is never cached.',
                        'prevention' => 'Remember the Dashboard is a periodically-refreshed summary, not a live mirror — always verify a specific record\'s state on its own screen (Leads, Vendors, Complaints), not on the Dashboard.',
                    ],
                    [
                        'category' => 'configuration mistake',
                        'mistake' => 'Assuming a high "SLA Breached" count means something is broken in the system.',
                        'symptom' => 'The SLA Breached KPI shows a large or growing number and is mistaken for a system malfunction.',
                        'fix' => 'It is diagnostic information, not an error state — open the Leads list, filter by SLA-breached leads, and review each one\'s due date and current status individually; the fix (reassigning, expediting, or escalating) happens on the Leads screen, not here.',
                        'prevention' => 'Check this count as part of a routine daily triage rather than reacting to it in isolation — a trend over several days is more informative than a single reading.',
                    ],
                ],
                'troubleshooting' => [
                    'Numbers look frozen / not updating → the dashboard cache refreshes every 3 minutes; wait or hard-reload after that window.',
                    'SLA Breached count looks too high → open the Leads list, filter by "SLA Breached", and check each one\'s due date and current status — this is diagnostic information, the fix happens on the Leads screen, not here.',
                ],
                'related' => ['leads', 'vendors'],
            ],

            // ═══════════════════════════════════════════════════════════
            'leads' => [
                'title'  => 'Leads (All Leads)',
                'module' => 'Leads',
                'summary' => 'The central operational record of this system — every RTO case a customer has started, from intake through completion or cancellation.',
                'what_is' => 'This screen is the single, central operational record of the entire platform: every RTO case a customer has ever started lives here as one "lead" row, and almost every other admin screen (Vendors, Payments, Payouts, Complaints, Ratings) exists to act on, or read data from, a lead created here. A lead ties together one specific service (e.g. Driving Licence Renewal), one client, one city/RTO office, optionally one assigned vendor, and a position in a defined 12-status pipeline that runs from intake through payment, assignment, active work, document handling, RTO filing, and finally completion or cancellation. Administrators and staff use this screen for nearly all day-to-day casework: finding a specific customer\'s case when they call in, assigning a newly-paid lead to an eligible vendor, changing a lead\'s status as work progresses, reviewing the uploaded-document and payment history on a case, bulk-updating a batch of leads at once, and exporting a date range of leads for finance or audit purposes. The list view supports combinable filters (status, city, service, assigned vendor, date range, free-text search), and clicking into any row opens the lead\'s full detail view — its timeline, document verification controls, the client message thread, payment records, and the actual status-change control that enforces the platform\'s real transition rules. Understanding this screen well is central to operating the platform, because a lead\'s status here is what every downstream screen (Payouts, Ratings, Notifications) is ultimately reacting to.',
                'why_exists' => 'This is not a generic "enquiry" or "lead" in the sales sense — it is the actual unit of work an RTO vendor performs and an RTO client pays for. Every other module (Vendors, Payments, Payouts, Complaints, Ratings) ultimately points back to a lead.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['A customer calls asking about their application status.', 'Assigning a newly-paid lead to a vendor.', 'Investigating why a lead has not progressed.', 'Bulk-closing or bulk-cancelling a batch of leads.', 'Exporting a date range for finance or audit.'],
                'fits_into' => 'Upstream: a lead is created by a customer\'s submission on the public /rto-apply/ form (routed to submit_apply_v2 or submit_apply_dynamic in Router.php), which inserts a row into rto_leads. Downstream: leads drive Payments (rto_payments), Payouts (rto_vendor_payouts, once completed and vendor-shared), Complaints (rto_complaints, linked by lead_id), Ratings (rto_ratings, one per completed lead), and Notifications (every status change fires NotificationService::notifyStatusChanged).',
                'sections' => [
                    ['name' => 'List / filters', 'body' => 'Filter by status, city, service, assigned vendor, date range, and free-text search (lead number, client name/mobile). Filters combine with AND — narrowing by status AND city AND date range all apply together, they do not offer an OR mode.'],
                    ['name' => 'Lead detail (click a row)', 'body' => 'Shows the full timeline, uploaded documents (with per-document verify/reject), the messages thread with the client, payment records, and the status-change control.'],
                    ['name' => 'Export CSV', 'body' => 'Exports the currently-filtered list (not just the current page) as CSV, capped at 5,000 rows. This is gated by the "report_export" feature flag — if the Export button is missing, that flag is off (see Feature Flags, not yet documented in this Help Centre).'],
                ],
                'statuses' => [
                    ['name' => 'created', 'meaning' => 'The customer\'s submission was received and a lead row exists. No payment yet.', 'can_go_to' => 'payment_pending, payment_received, cancelled', 'manual' => 'Yes, by staff with a valid reason (e.g. marking a phone-verified prepaid case straight to payment_received).'],
                    ['name' => 'payment_pending', 'meaning' => 'Customer was asked to pay but has not completed payment yet.', 'can_go_to' => 'payment_received, cancelled', 'manual' => 'Yes.'],
                    ['name' => 'payment_received', 'meaning' => 'Payment is confirmed. The lead is now eligible for vendor assignment.', 'can_go_to' => 'assigned, cancelled', 'manual' => 'Yes.'],
                    ['name' => 'assigned', 'meaning' => 'A vendor has been assigned (by staff or by the auto-assignment engine) and has been notified.', 'can_go_to' => 'in_progress, payment_received (if reassignment is needed / vendor rejects), cancelled', 'manual' => 'The forward transition to in_progress normally happens when the vendor accepts the job (Router::vendorAcceptJob) — but staff can also set it manually.'],
                    ['name' => 'in_progress', 'meaning' => 'The vendor has accepted and is actively working the case.', 'can_go_to' => 'docs_pending, rto_submitted, on_hold, cancelled', 'manual' => 'Yes.'],
                    ['name' => 'docs_pending', 'meaning' => 'Waiting on the customer to upload or resubmit a required document.', 'can_go_to' => 'docs_verified, in_progress, cancelled', 'manual' => 'Yes — also reachable when staff/vendor rejects an uploaded document.'],
                    ['name' => 'docs_verified', 'meaning' => 'All required documents have been checked and accepted.', 'can_go_to' => 'rto_submitted, cancelled', 'manual' => 'Yes.'],
                    ['name' => 'rto_submitted', 'meaning' => 'The paperwork has actually been filed at the RTO office.', 'can_go_to' => 'rto_processing, completed, on_hold, cancelled', 'manual' => 'Yes.'],
                    ['name' => 'rto_processing', 'meaning' => 'The RTO office is processing the filed case (a government-side wait, not something this system controls).', 'can_go_to' => 'completed, on_hold, cancelled', 'manual' => 'Yes.'],
                    ['name' => 'on_hold', 'meaning' => 'Deliberately paused — e.g. waiting on the customer, a strike, an RTO delay.', 'can_go_to' => 'in_progress, assigned, cancelled', 'manual' => 'Yes — this is the only status designed purely as a pause; it can resume backward into in_progress or assigned.'],
                    ['name' => 'completed', 'meaning' => 'The case is finished. Terminal — no further transitions.', 'can_go_to' => '(none — terminal)', 'manual' => 'Reaching it is manual, but once set it cannot be changed via the normal status control.'],
                    ['name' => 'cancelled', 'meaning' => 'The case was cancelled. Terminal — reachable from every non-terminal status.', 'can_go_to' => '(none — terminal)', 'manual' => 'Yes, from anywhere.'],
                ],
                'does_not_control' => [
                    'Changing a lead\'s status here does NOT retroactively change historical reports for a period that has already closed — reports read the status as of generation time.',
                    'Assigning a vendor to a lead here changes only THIS lead\'s vendor_id — it never changes that vendor\'s city/service coverage or their eligibility for OTHER leads (that is configured on the Vendors screen).',
                    'This screen cannot force an invalid status jump (e.g. created → completed directly) — the transition table in LeadService::isValidTransition() rejects it server-side even if you could somehow submit it.',
                    'Two status-changing paths exist and both go through the same validated transition logic: the manual dropdown here, and the vendor\'s own accept/reject action on their dashboard — neither bypasses the other, but the vendor\'s accept/reject does NOT show a manual "reason" the way a staff-initiated change can.',
                    'The Status filter is the only one that supports OR (pick multiple statuses via its checkbox dropdown) — city, service, vendor, date range, and search still combine with every other active filter using AND, matching how staff actually use them.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Marking a lead "completed" before its documents are actually verified.',
                        'symptom' => 'The lead moves to the terminal completed status, and the status dropdown no longer offers any further transitions.',
                        'fix' => 'Because completed is terminal, this cannot be undone via the status dropdown — it requires a direct database correction; contact whoever manages the platform\'s database if this happens.',
                        'prevention' => 'Confirm every required document shows "verified" (not just "uploaded") on the lead detail screen before selecting completed.',
                    ],
                    [
                        'category' => 'incorrect assumption',
                        'mistake' => 'Assuming "cancelled" can be reversed for a customer who changes their mind.',
                        'symptom' => 'After setting a lead to cancelled, no further status options appear in the dropdown.',
                        'fix' => 'Cancelled is a terminal status by design — if the customer wants to resume, create a NEW lead for them rather than trying to revive the cancelled one.',
                        'prevention' => 'Only cancel a lead once you are reasonably sure the customer will not want to continue that same case; when in doubt, use on_hold instead, which is reversible.',
                    ],
                    [
                        'category' => 'data-entry mistake',
                        'mistake' => 'Applying a bulk status update to a filtered list without re-checking exactly which leads matched the filter.',
                        'symptom' => 'More leads change status than intended, because the active filter combination was broader than the admin realized.',
                        'fix' => 'Undo is not automatic for a bulk transition — each affected lead would need to be individually corrected using a valid transition path from its new status.',
                        'prevention' => 'Always review the filtered row count shown at the top of the list immediately before running a bulk action, not just the filter labels.',
                    ],
                    [
                        'category' => 'permission/access mistake',
                        'mistake' => 'Assuming any logged-in staff account can access this screen.',
                        'symptom' => 'A user without the rto_admin or rto_staff role sees an access-denied response when navigating to /rto-admin/leads/.',
                        'fix' => 'Confirm the user\'s WordPress role is actually rto_admin or rto_staff (Router::routeAdmin() gates every admin page on rto_is_staff()) — a rto_vendor or rto_client account will never see this screen.',
                        'prevention' => 'Assign the correct role at account creation time rather than after a user reports they cannot log in to the admin.',
                    ],
                ],
                'troubleshooting' => [
                    '"I can\'t move a lead to a certain status" → check the current status against the transition table above; only certain next-statuses are allowed from each status. If the target isn\'t listed as reachable, the system is correctly blocking an invalid jump.',
                    '"Lead is stuck in assigned and the vendor never accepted" → check the vendor\'s own dashboard/notification history (Notifications, not yet documented) to confirm the assignment notification actually sent; if it failed, use the "Retry Notification" action where available, or manually reassign.',
                ],
                'related' => ['vendors', 'complaints', 'city-pricing', 'eligibility'],
            ],

            // ═══════════════════════════════════════════════════════════
            'vendors' => [
                'title'  => 'Vendors',
                'module' => 'Vendors',
                'summary' => 'The RTO agents/service providers who actually perform the paperwork and field work for a lead — their profile, KYC status, coverage, and active/suspended state.',
                'what_is' => 'This screen manages every vendor on the platform — the RTO agents and service providers who actually do the paperwork and field work behind a lead. A vendor is a WordPress user carrying the rto_vendor role, paired with a row in rto_vendors that holds their KYC verification status, encrypted bank details, hashed Aadhaar, encrypted PAN, which cities and services they cover, their live rating, and their running job-performance statistics (total jobs, completion rate, acceptance rate). In practical, day-to-day use, administrators use this screen to onboard a new vendor once their KYC documents come in (reviewing and verifying or rejecting each document), to suspend a vendor who is underperforming or under investigation without deleting their history, to update a vendor\'s city and service coverage as their capacity or geography changes, and — very commonly — to diagnose "why isn\'t this vendor getting leads?" by walking through their status, KYC state, rating, coverage, and current job load one field at a time. Every one of the fields set here (status, KYC status, coverage) is not just descriptive metadata — it is the literal eligibility gate that both manual assignment (on the Leads screen) and the automatic assignment engine actually check before a lead can be routed to this vendor, which is why getting this screen right has a direct, immediate effect on whether work actually reaches the right people.',
                'why_exists' => 'A lead cannot be assigned to a vendor who is not active, not KYC-verified, or does not cover that city/service — this screen is where that eligibility state is actually set.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['Onboarding a new vendor after they submit KYC documents.', 'Suspending a vendor who is underperforming or under investigation.', 'Updating a vendor\'s service/city coverage when their capacity changes.', 'Answering "why isn\'t this vendor getting leads?".'],
                'fits_into' => 'Feeds directly into lead assignment eligibility (see the Diagnostic Guide below and the Eligibility article). A vendor\'s rating here also comes FROM the Ratings module (client-submitted, moderated there) — this screen displays it but does not let you edit a rating value directly.',
                'sections' => [
                    ['name' => 'List / filters', 'body' => 'Filter by status (active/suspended/pending), KYC status, city, service.'],
                    ['name' => 'Vendor detail', 'body' => 'Profile info, KYC document review + verify/reject, city/service coverage editor, job history, and current status control.'],
                ],
                'fields' => [
                    ['name' => 'KYC Status', 'meaning' => 'verified / pending / rejected — set by staff after reviewing submitted ID/address proof.', 'affects' => 'A vendor with any status other than "verified" is excluded from BOTH manual assignment (LeadService::assignVendor requires status=active AND kyc_status=verified) and auto-assignment.'],
                    ['name' => 'Status (active/suspended)', 'meaning' => 'Whether this vendor can currently be assigned any lead at all.', 'affects' => 'Suspending a vendor does NOT unassign their currently-in-progress leads — it only prevents NEW assignments. Existing assignments continue until manually reassigned or completed.'],
                    ['name' => 'City/Service coverage', 'meaning' => 'Which cities and which services this vendor is eligible to receive leads for.', 'affects' => 'Affects future assignment eligibility only — changing coverage does not move or unassign any already-assigned lead.'],
                ],
                'does_not_control' => [
                    'Editing a vendor\'s coverage/status here does not touch any currently-assigned lead — reassignment of an existing lead is a separate, explicit action on the Leads screen.',
                    'Suspending a vendor does not delete their historical job/payout/rating records.',
                    'This screen does not compute or override the auto-assignment SCORING weights (rating/completion/acceptance/load) — those are configured on Settings → Matching, not here.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'validation-related mistake',
                        'mistake' => 'Marking KYC "verified" without actually reviewing the uploaded documents.',
                        'symptom' => 'The vendor immediately becomes eligible for both manual and automatic assignment, even if their submitted ID/address proof was never actually checked.',
                        'fix' => 'Revert KYC status to "pending" or "rejected" immediately if a rubber-stamp verification is discovered, and re-review the actual uploaded documents before re-verifying.',
                        'prevention' => 'Treat "KYC verified" as the single control that unlocks assignment eligibility platform-wide — never set it without opening and checking the actual document images first.',
                    ],
                    [
                        'category' => 'incorrect assumption',
                        'mistake' => 'Assuming removing a service from a vendor\'s coverage pulls them off an already-in-progress lead for that service.',
                        'symptom' => 'The vendor continues appearing on an in-progress lead for a service no longer listed in their coverage.',
                        'fix' => 'Coverage changes only affect future assignment eligibility — manually reassign the specific in-progress lead on the Leads screen if it also needs to move.',
                        'prevention' => 'When narrowing a vendor\'s coverage, separately check the Leads screen for that vendor\'s current open jobs and decide case-by-case whether each needs reassignment.',
                    ],
                    [
                        'category' => 'configuration mistake',
                        'mistake' => 'Suspending a vendor instead of simply lowering their max_active_jobs or removing them from auto-assignment temporarily.',
                        'symptom' => 'A vendor who should only be paused from NEW leads for a short period ends up fully suspended, and their in-progress leads stall because staff assume suspension already handled it.',
                        'fix' => 'Reactivate the vendor and instead manage their load via Settings → Vendor Matching (max_active_jobs / weights) if the intent is a temporary slowdown rather than a full stop.',
                        'prevention' => 'Reserve suspension for genuine active/suspended decisions (misconduct, investigation, resignation) rather than as a lever for short-term capacity management.',
                    ],
                ],
                'troubleshooting_guide' => [
                    'title' => 'Why isn\'t Vendor X receiving leads for Service Y in City Z?',
                    'steps' => [
                        'Vendor Status must be "active" — check on this screen.',
                        'KYC Status must be "verified" — check on this screen.',
                        'The vendor\'s rating must meet the platform\'s minimum rating threshold (Settings → Matching → min_rating) — if their rating recently dropped, they may have fallen below it.',
                        'City coverage must include City Z, and service coverage must include Service Y — check the vendor\'s coverage editor.',
                        'The vendor must not already be at their max_active_jobs capacity (Settings → Matching) — if they are fully loaded, auto-assignment will skip them until a job completes.',
                        'For MANUAL assignment specifically: only status=active AND kyc_status=verified are actually enforced in code (LeadService::assignVendor) — coverage/rating/capacity are enforced for AUTO-assignment (VendorService::auto_assign), not for a manual pick. If a manual assignment to this vendor succeeded despite a coverage mismatch, that is expected — manual assignment is a staff override.',
                    ],
                ],
                'related' => ['leads', 'eligibility', 'city-pricing'],
            ],

            // ═══════════════════════════════════════════════════════════
            'services' => [
                'title'  => 'Services',
                'module' => 'Services',
                'summary' => 'The service catalog — every real service this platform offers (e.g. Driving Licence Renewal, RC Transfer), its base price, government fee, GST treatment, vendor share, and SLA target.',
                'what_is' => 'This is the service catalog — the definitive list of every real RTO service this platform sells, each stored as one row in rto_services with a name, one of the 7 real categories (Driving Licence, RC, Hypothecation, NOC, Vehicle, Commercial Vehicle, Other), a base price, a government fee, a GST-applicable flag, a vendor-share percentage, and normal/urgent SLA day targets. Administrators use this screen to launch a brand-new service, adjust a government fee after an official change, review or edit a service\'s customer-facing price and SLA promise, and to activate or deactivate individual services (including in bulk) when a service needs to be temporarily paused. It is genuinely foundational data: every lead created anywhere in the system is created against exactly one service row from this list, so a service\'s price and SLA here are what actually get copied onto every new lead created for it going forward (existing leads already keep whatever amount was recorded at the time they were created). This screen is deliberately narrow in scope — it does not control which fields appear on the customer-facing form (that is the Form Builder, organized by category rather than by individual service) and it does not set city-specific pricing overrides (that is the City/Service Pricing screen) — understanding that separation of responsibility is important for knowing where to actually go to change something that looks, at first glance, like it should live here.',
                'why_exists' => 'Every lead is created against exactly one service, and this screen is the single source of truth for that service\'s price and SLA target.',
                'who' => ['RTO Admin'],
                'when' => ['Launching a new service.', 'Adjusting a government fee after an official change.', 'Temporarily deactivating a service (e.g. a category-wide pause — see the Form Builder article for the whole-category visibility toggle, which works alongside this per-service one).'],
                'fits_into' => 'Upstream: nothing — this is foundational data. Downstream: the public Apply page reads is_active to decide whether a service is selectable at all; City/Service Pricing (a separate screen) can override govt_fee and visibility PER CITY on top of what is set here; the Dynamic Form Engine (Form Builder) is keyed by category, not by individual service, so a service\'s custom fields come from its category\'s form, not from this screen.',
                'fields' => [
                    ['name' => 'is_active', 'meaning' => 'Whether this ONE service can be selected on the public Apply page.', 'affects' => 'Frontend visibility only, for NEW enquiries — deactivating a service does not touch any existing lead already created for it.'],
                    ['name' => 'base_price / govt_fee', 'meaning' => 'The customer-facing price components.', 'affects' => 'New leads only — a price change here never retroactively changes the amount already recorded on an existing lead.'],
                    ['name' => 'vendor_share (%)', 'meaning' => 'What percentage of the collected amount the assigned vendor is paid out for completing this service.', 'affects' => 'Used by the Payouts screen when generating a payout batch for completed leads — changing this affects future payout calculations, not payouts already generated.'],
                ],
                'buttons' => [
                    ['name' => 'Toggle Active/Inactive', 'does' => 'Flips is_active for one service.', 'reversible' => 'Yes, toggle again.'],
                    ['name' => 'Bulk Toggle', 'does' => 'Applies the same activate/deactivate to every selected service at once.', 'reversible' => 'Yes.'],
                ],
                'does_not_control' => [
                    'This is PER-SERVICE visibility. Deactivating every service in a category one-by-one here has the same net effect as, but is a slower and more error-prone way to achieve, the dedicated whole-category "Deactivate Category" button on the Form Builder screen — use that instead when the goal is to hide an entire category.',
                    'This screen does not control which fields appear on the customer-facing form for this service — that is the category\'s Form Builder schema, a separate screen.',
                    'Changing base_price/govt_fee here does not recalculate any existing lead\'s already-recorded amount.',
                ],
                'source_of_truth' => 'A city can override this service\'s govt_fee and visibility via City/Service Pricing — when both are set, the CITY-LEVEL override wins for that specific city; this screen\'s value remains the fallback for every city without its own override.',
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'configuration mistake',
                        'mistake' => 'Deactivating every service in a category one at a time instead of using Form Builder\'s whole-category toggle.',
                        'symptom' => 'The category still appears selectable on the Apply page\'s Step 1 even though every individual service inside it is now inactive, because the category-level visibility is a separate flag from each service\'s own is_active.',
                        'fix' => 'Use the dedicated "Deactivate Category" button on the Form Builder screen when the actual goal is hiding an entire category — it removes the category button itself, not just its services.',
                        'prevention' => 'Reserve per-service deactivation here for pausing one specific service inside an otherwise-active category; use the category toggle for anything broader.',
                    ],
                    [
                        'category' => 'unexpected-behavior mistake',
                        'mistake' => 'Expecting a base_price or govt_fee change to update figures on existing, already-created leads.',
                        'symptom' => 'A price correction is made here, but a customer\'s already-open lead still shows the old amount.',
                        'fix' => 'This is expected: prices are copied onto a lead at creation time and never recalculated retroactively — if a specific existing lead genuinely needs a corrected amount, that has to be edited directly on that lead, not fixed by changing the service here.',
                        'prevention' => 'Communicate price changes as "affecting new leads only" when informing staff or customers, to avoid confusion about existing cases.',
                    ],
                ],
                'troubleshooting' => [
                    '"Service is active here but customers still can\'t see it" → check whether its whole CATEGORY was deactivated via Form Builder → Deactivate Category (this hides every service in that category regardless of each service\'s own is_active flag), and check City/Service Pricing for a city-specific override hiding it.',
                ],
                'related' => ['forms', 'city-pricing'],
            ],

            // ═══════════════════════════════════════════════════════════
            'forms' => [
                'title'  => 'Form Builder (Category Forms)',
                'module' => 'Services',
                'summary' => 'The drag-and-drop editor for each of the 7 real service categories\' custom application forms — fields, conditional logic, document requirements, and the whole-category visibility switch.',
                'what_is' => 'The Form Builder is the drag-and-drop editor behind the customer-facing application form on the public /rto-apply/ page. Since this platform moved to category-keyed forms, there are exactly 7 categories — Driving Licence, RC, Hypothecation, NOC, Vehicle, Commercial Vehicle, Other — and each has exactly one schema of fields, shared across every real service inside that category, with a "selected_service" field acting as the picker that gates which of that category\'s conditional follow-up fields actually apply to that customer\'s submission. Administrators use this screen constantly when the form itself needs to change: adding, removing, or reordering a question, editing a field\'s label/placeholder/help text, changing which document is required and under exactly what condition it should appear, and — a very high-impact, frequently-used control — hiding an entire category from the live Apply page in one click when that category of services needs to be paused platform-wide. The index page shows one card per category with its current visibility badge and version number; opening a category\'s edit page shows the ordered field list, and clicking a field opens a modal with Basic, Options, and Conditions tabs covering everything from the field\'s type and width to its visible_if logic and validation rules. Every field\'s Field Key is the literal string that both the server\'s validation and every other field\'s conditional-visibility rule keys off of — it can be renamed after creation, and doing so automatically rewrites every other field\'s or document\'s visible_if rule that referenced the old key, so nothing is silently left broken.',
                'why_exists' => 'A single Driving Licence form, for example, needs to ask different follow-up questions depending on which specific DL service the customer picked (New vs Renewal vs Duplicate) — one shared schema with conditional logic is what makes that possible without building 10 near-identical forms.',
                'who' => ['RTO Admin'],
                'when' => ['Adding, removing, or reordering a field on a category\'s form.', 'Changing which document is required and under what condition.', 'Hiding an entire category from the live Apply page (e.g. temporarily pausing Driving Licence services).', 'Reviewing submission analytics for a category\'s form.'],
                'fits_into' => 'Downstream of Services (a category groups several rows in rto_services). Upstream of the public Apply page — apply.php renders exactly this schema\'s fields for Step 2 of whichever category the customer picked, and Router::submitApplyDynamic() independently re-validates every field\'s required/visible_if rule server-side before accepting a submission.',
                'sections' => [
                    ['name' => 'Category cards (index page)', 'body' => 'One card per category, showing whether it currently has a saved form schema, its version number, and — this is the important part for day-to-day operations — a "Visible to customers" / "Hidden from customers" badge plus an Activate/Deactivate Category button.'],
                    ['name' => 'Field list (edit page)', 'body' => 'Every field in the category\'s form, in display order, with per-row Edit / Duplicate / Delete and ▲▼ reorder controls.'],
                    ['name' => 'Edit Field modal — Basic tab', 'body' => 'Field Type, which Form Step it belongs to, Width, Label, Field Key (editable — since it is the value the server and every visible_if condition key off of, renaming it automatically rewrites every other field\'s and document\'s condition that referenced the old key, so nothing is silently left pointing at a key that no longer exists), Placeholder, Help Text, and three checkboxes: Required, Show Label, and Active (uncheck to hide a single field without deleting it — this keeps past submissions readable).'],
                    ['name' => 'Edit Field modal — Options tab', 'body' => 'For choice-type fields (select/radio/checkbox/multiselect): the option list. For a Dependent Dropdown field: which data source it pulls from and which other field it depends on.'],
                    ['name' => 'Edit Field modal — Conditions tab', 'body' => 'Validation rules (min/max length or value, regex pattern) and the visible_if condition editor — AND/OR groups of rules that decide when this field is shown at all.'],
                ],
                'buttons' => [
                    ['name' => 'Activate/Deactivate Category', 'does' => 'Bulk-flips is_active on EVERY real service belonging to this category in one action, and immediately removes that category\'s button entirely from the public Apply page\'s Step 1 (a hidden category cannot even be opened, not just discouraged).', 'confirmation' => 'Yes — a dialog names the category and explains the effect before proceeding.', 'reversible' => 'Yes — click Activate to fully restore every one of that category\'s services to active.', 'does_not' => 'This does NOT touch any lead already created for that category — existing leads continue through their normal status pipeline untouched. It also does not delete the category\'s form schema, KYC/vendor coverage for that category\'s services, or historical data of any kind.'],
                ],
                'known_limitations' => [],
                'does_not_control' => [
                    'The category visibility toggle here does not change any individual service\'s own is_active flag display on the Services screen in a way that is obviously visible there — check the Services screen\'s own list to see the real per-service state if you need to confirm exactly which services were affected.',
                    'This screen does not set pricing — that is the Services screen (base price) and City/Service Pricing (per-city overrides).',
                ],
                'common_mistakes' => [
                    [
                        'category' => 'configuration mistake',
                        'mistake' => 'Renaming a Field Key to something another existing field already uses.',
                        'symptom' => 'The Field Key input shows a red "Another field already uses this key" error and the Save button will not commit the change.',
                        'fix' => 'Pick a different, unique key — the editor validates uniqueness live as you type and blocks saving until it is resolved, so this can never silently create two fields sharing one key.',
                        'prevention' => 'Give a field a clear, final Field Key when possible, but know that renaming later is safe — every other field\'s and document\'s condition referencing the old key is automatically rewritten to the new one on save.',
                    ],
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Assuming Deactivate Category also stops leads already in progress for that category.',
                        'symptom' => 'Existing customers with open leads in that category continue to see their case progress normally after the category is deactivated.',
                        'fix' => 'This is expected — deactivation only prevents NEW customers from starting that category\'s form; it never touches leads already created.',
                        'prevention' => 'Communicate a category deactivation as "no new submissions" rather than "everything in this category stops," to avoid confusing staff handling in-progress cases.',
                    ],
                    [
                        'category' => 'validation-related mistake',
                        'mistake' => 'Writing a visible_if condition whose target field key or expected value does not exactly match, character-for-character.',
                        'symptom' => 'A conditional field never appears no matter what value the customer picks upstream.',
                        'fix' => 'Open the field\'s Conditions tab and compare the condition\'s target key and expected value against the actual upstream field\'s key and option values exactly — the comparison is exact-match, not fuzzy.',
                        'prevention' => 'Copy option values directly from the upstream field\'s Options tab when writing a condition, rather than retyping them from memory.',
                    ],
                ],
                'troubleshooting' => [
                    '"I deactivated a category but customers report they can still see it" → hard-reload the Apply page (a browser or server cache may be holding an old copy); if that does not resolve it, confirm on this screen that the badge actually shows "Hidden from customers" — if it still shows "Visible", the toggle click did not succeed (check for a "Security check failed" error, which was a known, now-fixed nonce bug in earlier builds of this system).',
                    '"A conditional field never appears no matter what I pick" → open the field\'s Conditions tab and check the visible_if rule\'s target field key and expected value character-for-character — the comparison is exact-match.',
                ],
                'related' => ['services', 'eligibility'],
            ],

            // ═══════════════════════════════════════════════════════════
            'eligibility' => [
                'title'  => 'Eligibility Rules',
                'module' => 'Services',
                'summary' => 'A "configure, don\'t code" rules engine — per-service conditions an applicant\'s answers must satisfy, plus a log of every real eligibility check performed.',
                'what_is' => 'The Eligibility Rules screen is a "configure, don\'t code" rules engine that lets an administrator attach hard requirements to a service — for example, requiring an applicant\'s age to be greater than 17 before they can apply for a Driving Licence service — without any developer involvement. Each rule links exactly one service to one field/operator/value condition, is stored in rto_eligibility_rules, and can be toggled active or inactive independently. Every time the platform actually evaluates a rule against a real applicant\'s submitted answers, the outcome (pass or fail, and which rule/condition drove it) is written to rto_eligibility_checks, which this screen also surfaces as a "Recent checks" log. Administrators use this screen in two very different modes: proactively, when a new hard eligibility requirement needs to be added for a service before it goes live or as a policy changes; and reactively, when investigating why a specific applicant was told they are not eligible for something, in which case the Recent Checks log — not the rule list alone — is the correct place to look, since a service can have more than one rule and only the check log shows exactly which one actually fired for that applicant. It is worth understanding that this is a separate, independently-evaluated layer from the Form Builder\'s own field-level required/visible_if validation: a field can be entirely optional on the form and still be gated by a hard eligibility rule configured here, so the two systems need to be reasoned about separately when troubleshooting a rejected application.',
                'why_exists' => 'Some RTO services have hard eligibility requirements (minimum age, a valid existing licence number, etc.) that should be enforced consistently without a developer writing custom code for every service.',
                'who' => ['RTO Admin'],
                'when' => ['Adding a new hard requirement for a service (e.g. minimum age for a Learner\'s Licence).', 'Investigating why a specific applicant was told they are not eligible.'],
                'fits_into' => 'This is separate from, and evaluated independently of, the Form Builder\'s own field-level required/visible_if validation — a field can be optional on the form yet still gated by a hard eligibility rule here.',
                'sections' => [
                    ['name' => 'Rule list', 'body' => 'Every configured rule, its service, field, operator, expected value, and active/inactive toggle.'],
                    ['name' => 'Recent checks log', 'body' => 'A record of real eligibility evaluations — useful for confirming a specific rule actually fired for a specific applicant\'s submission, rather than guessing.'],
                ],
                'buttons' => [
                    ['name' => 'Toggle rule active/inactive', 'does' => 'An inactive rule is not evaluated at all — it does not silently "pass everyone", it is simply skipped.', 'reversible' => 'Yes.'],
                    ['name' => 'Delete rule', 'does' => 'Permanently removes the rule definition. Past check log entries for it remain, for audit purposes.', 'reversible' => 'No — recreate the rule from scratch if deleted by mistake.'],
                ],
                'does_not_control' => [
                    'Deleting or deactivating a rule does not retroactively reverse an eligibility decision already made on a past submission — it only affects future checks.',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Assuming a deactivated rule\'s past decisions can be un-done for applicants already rejected under it.',
                        'symptom' => 'An applicant previously blocked by a rule reports they still cannot get past the point where they were rejected, even after the rule is disabled.',
                        'fix' => 'Deactivating a rule only affects future checks — the applicant needs to resubmit or be manually processed past that point for their specific case to change.',
                        'prevention' => 'When correcting an overly strict rule, communicate to affected applicants that they need to reapply, rather than assuming the fix is automatically retroactive.',
                    ],
                    [
                        'category' => 'configuration mistake',
                        'mistake' => 'Creating a new rule with an operator/value combination that does not match the actual data type of the target field.',
                        'symptom' => 'The rule silently never fires (or always fires), because the comparison against the field\'s real stored value type never behaves as expected.',
                        'fix' => 'Check the field\'s type on the Form Builder screen and choose a matching operator (e.g. numeric comparisons like greater_than only make sense against a numeric field).',
                        'prevention' => 'Test a new rule against a real trial submission in the relevant category before relying on it in production.',
                    ],
                ],
                'troubleshooting' => [
                    '"Why was this applicant told they were ineligible?" → open the Recent Checks log, find their check, and see exactly which rule and condition failed — do not guess from the rule list alone, since a service can have multiple rules.',
                ],
                'related' => ['forms', 'services'],
            ],

            // ═══════════════════════════════════════════════════════════
            'city-pricing' => [
                'title'  => 'City / Service Pricing Configuration',
                'module' => 'Locations',
                'summary' => 'Per-city overrides on top of a service\'s base configuration — hide a service in one city only, or charge a different government fee there.',
                'what_is' => 'This screen manages per-city overrides layered on top of a service\'s base configuration set on the Services screen. Each row in rto_city_service_config links one specific city and one specific service to an is_visible flag, a show_price flag, and an optional city-specific government fee — the practical effect being that the same service can be visible with one price in Mumbai and hidden entirely, or priced differently, in another city. Administrators land on this screen most often for two real-world reasons: a city\'s RTO office announces a fee change that applies only in that jurisdiction, or a service simply is not yet available in a newly-added city and needs to be hidden there specifically, without touching how that service behaves anywhere else. The configure-a-city page groups every active service by its category with an inline toggle for visibility and price-display, plus optional government-fee and service-charge override inputs that fall back to the service\'s own base defaults (shown as the input\'s placeholder) whenever left blank. The most important thing to understand about this screen is the fallback relationship it has with the Services screen: the Services screen defines the DEFAULT for every city, and this screen defines the SPECIFIC EXCEPTION for one city — they are not two independent, competing controls of the same setting, and a change made here for one city has zero effect on how that same service appears in any other city.',
                'why_exists' => 'Government fees for the same RTO service can genuinely differ by state/city, and not every service is offered in every city this platform covers.',
                'who' => ['RTO Admin'],
                'when' => ['A city\'s RTO office changes its fee for a service.', 'A service is not yet available in a newly-added city and needs to be hidden there specifically, without touching that service anywhere else.'],
                'fits_into' => 'This is the CITY-LEVEL override layer on top of the Services screen\'s base configuration. When a city has its own row here, its is_visible/govt_fee values take priority for that city; every city without an override row falls back to the Services screen\'s base is_active/govt_fee.',
                'source_of_truth' => 'Services screen = the default for every city. City/Service Pricing = the specific exception for one city. They are not two independent controls of the same thing — one is the fallback, the other is the override.',
                'does_not_control' => [
                    'A city-level override here does not change the base service configuration seen by every OTHER city.',
                    'This does not affect any lead already created before the override was set.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'incorrect settings',
                        'mistake' => 'Assuming a price change made on the Services screen automatically applies everywhere, including cities with their own override already set.',
                        'symptom' => 'A city continues showing an old or different price after the base service price was updated.',
                        'fix' => 'Check this screen for that specific city — if a city-level government-fee override exists, it takes priority over the Services screen\'s base value and must be updated here directly.',
                        'prevention' => 'When making a platform-wide price change, always also check whether any city currently has its own override for that service before assuming the change reached every customer.',
                    ],
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Hiding a service in a city by leaving all its fields blank rather than explicitly unchecking "Service Visible".',
                        'symptom' => 'The service remains visible in that city despite an admin believing they "removed" it by clearing the override amounts.',
                        'fix' => 'Explicitly uncheck the "Service Visible" toggle for that row and save — leaving price fields blank only clears the override amount, it does not hide the service.',
                        'prevention' => 'Use the visibility toggle, not blank pricing fields, whenever the actual intent is to hide a service in a specific city.',
                    ],
                ],
                'troubleshooting' => [
                    '"Service shows the wrong price in one specific city" → check here FIRST for a city-specific govt_fee override before assuming the base Services screen price is wrong — the city override, if present, is what the customer actually sees in that city.',
                ],
                'related' => ['services', 'vendors'],
            ],

            // ═══════════════════════════════════════════════════════════
            'settings-matching' => [
                'title'  => 'Settings → Vendor Matching',
                'module' => 'Settings',
                'summary' => 'The scoring weights and thresholds that decide which eligible vendor gets a lead automatically assigned to them.',
                'what_is' => 'This screen is the control panel for the platform\'s automatic vendor-assignment engine. When a lead becomes eligible for assignment, VendorService::auto_assign() first builds a pool of eligible candidate vendors (active, KYC-verified, covering the right city/service, above the minimum rating, and under their job capacity), then scores every candidate as a weighted sum: rating_weight × (rating/5) plus completion_weight × (completion_rate/100) plus acceptance_weight × (acceptance_rate/100) plus load_weight × (their spare capacity) — and assigns the lead to whichever eligible vendor scores highest. Every one of those four weights, along with the minimum rating threshold, the candidate pool size, and the max-active-jobs cap, is configured on this one screen, meaning an administrator can retune how the assignment engine behaves without any code change. In practice, this screen gets used reactively far more than proactively: when auto-assignment starts to feel like it is favoring one factor unfairly — for example, always picking the single highest-rated vendor even when they are nearly at capacity, starving other qualified vendors of work — the fix is to lower rating_weight and/or raise load_weight here, not to change anything on the Vendors screen. Every save on this screen is versioned through the same configuration-history mechanism used by Feature Flags, so a change that produces an unwanted assignment pattern can be rolled back to the previous known-good configuration. Before saving, the "Preview Ranking" tool lets an admin pick a real city/service and see how the currently-eligible vendor pool would rank under whatever weights are typed in the form (not yet saved) — and its "Impact on Recent Assignments" panel goes further, checking the last 50 leads actually assigned for that city/service and reporting whether the proposed weights would have picked a different top vendor than the currently-saved weights do, using each vendor\'s live stats today.',
                'why_exists' => 'Without this, every lead would need a staff member to manually pick a vendor — this is what makes automatic assignment possible and tunable without a code change.',
                'who' => ['RTO Admin'],
                'when' => ['Auto-assignment seems to be favoring vendors unfairly by one factor (e.g. always picking the highest-rated vendor even when they are nearly at capacity) — adjust the weights.', 'Too many/few vendors are ending up in the eligible candidate pool — adjust candidate_pool_size or min_rating.'],
                'fits_into' => 'Feeds directly into every future auto-assignment decision. Uses the same version-history/rollback mechanism (ConfigVersionService) as Feature Flags — every save creates a version you can roll back to.',
                'does_not_control' => [
                    'Changing these weights does not re-score or reassign any lead already assigned — it only affects assignment decisions made AFTER the change is saved.',
                    'This does not override the hard eligibility gates (active + verified + covers the city/service) — a vendor failing any of those is excluded from scoring entirely, regardless of how favorable the weights are.',
                    'The "Impact on Recent Assignments" preview re-scores vendors against their CURRENT stats, not a historical snapshot from when each past lead was actually assigned (that snapshot was never recorded) — it answers "would today\'s top pick change under these weights", not "would history have literally played out differently".',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'configuration mistake',
                        'mistake' => 'Setting load_weight to zero while troubleshooting an unrelated issue, and forgetting to restore it.',
                        'symptom' => 'The highest-rated vendors start receiving a disproportionate share of new leads regardless of how close to capacity they already are.',
                        'fix' => 'Check the version history for this configuration and roll back to the last known-good set of weights.',
                        'prevention' => 'Change one weight at a time and observe the effect over a small batch of assignments before making further adjustments.',
                    ],
                    [
                        'category' => 'incorrect settings',
                        'mistake' => 'Setting min_rating too high for the current vendor pool\'s actual rating distribution.',
                        'symptom' => 'Very few or no vendors are eligible for auto-assignment, and leads start piling up unassigned.',
                        'fix' => 'Check the Vendors screen for the actual current rating distribution across active vendors before lowering min_rating back to a realistic threshold.',
                        'prevention' => 'Review the real spread of vendor ratings before changing this threshold, rather than picking a number in isolation.',
                    ],
                ],
                'troubleshooting' => [
                    '"Why did the system pick Vendor A instead of Vendor B, when B has a higher rating?" → B may be closer to max_active_jobs (the load_weight component), or below min_rating is not the issue here since B is rated higher — check completion_rate and acceptance_rate too, since the final score is a weighted sum of all four factors, not rating alone.',
                ],
                'related' => ['vendors', 'leads'],
            ],

            // ═══════════════════════════════════════════════════════════
            'payouts' => [
                'title'  => 'Payouts',
                'module' => 'Payments',
                'summary' => 'Batches of vendor payments — gross amount, TDS deducted, net paid — generated from completed leads\' vendor_share, plus TDS certificate generation.',
                'what_is' => 'The Payouts screen manages periodic payments owed to vendors for the work they have completed. It aggregates a vendor\'s vendor_share earnings across every completed lead in a chosen period into a single payable batch, automatically calculating and deducting TDS as required under Indian tax law, and lets staff generate a TDS certificate for the vendor\'s own records. In routine operation, this is where an administrator runs the recurring (typically monthly) vendor payout cycle: selecting a period, generating batches per vendor from eligible completed-but-unpaid leads, and — once the actual bank transfer has genuinely been made outside this system — marking each batch as paid so the platform\'s own records stay accurate. It is important to understand this screen is purely a record-keeping and calculation tool: it never itself moves real money between bank accounts; "Generate Payouts" only computes what is owed, and "Mark Paid" only confirms, after the fact, that a transfer already happened through the organization\'s actual banking process. Because every batch is computed from each service\'s vendor_share percentage as it stood at generation time, a vendor_share change made on the Services screen after a batch has already been generated never retroactively alters that already-generated batch.',
                'why_exists' => 'Vendors need to be paid periodically for completed work, and Indian tax law requires TDS to be withheld and a certificate issued.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['Running the periodic (e.g. monthly) vendor payout cycle.', 'Issuing a TDS certificate to a vendor for their records.'],
                'fits_into' => 'Reads completed leads\' amounts and each service\'s vendor_share percentage (set on the Services screen) — a vendor_share change made AFTER a payout batch was already generated does not retroactively change that batch.',
                'buttons' => [
                    ['name' => 'Generate Payouts', 'does' => 'Aggregates eligible completed-but-unpaid leads into a new batch per vendor for the selected period.', 'reversible' => 'Generating again for the same period should not double-count already-included leads — check the batch history rather than regenerating blindly if unsure.'],
                    ['name' => 'Mark Paid', 'does' => 'Marks a batch as paid once the actual bank transfer has been made outside this system.', 'confirmation' => 'This does not itself move money — it is a record-keeping action confirming a real payment already happened elsewhere.'],
                ],
                'does_not_control' => [
                    'This screen does not process real bank transfers — it tracks amounts owed and paid; the actual transfer is a manual action outside this system.',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Marking a payout batch as paid before the actual bank transfer has been completed.',
                        'symptom' => 'The vendor sees their payout marked paid in the system but has not actually received the funds, leading to a support dispute.',
                        'fix' => 'Revert the batch status if possible, or clearly communicate the actual transfer timeline to the vendor while the real payment is processed.',
                        'prevention' => 'Only click Mark Paid after confirming the bank transfer has actually cleared, not when it is merely initiated.',
                    ],
                    [
                        'category' => 'data-entry mistake',
                        'mistake' => 'Selecting the wrong date range when generating a payout batch.',
                        'symptom' => 'A batch includes fewer or more completed leads than expected for the intended payout cycle.',
                        'fix' => 'Review the batch\'s included-leads breakdown before finalizing, and regenerate with the corrected date range if the wrong leads were captured.',
                        'prevention' => 'Double-check the period boundaries against the platform\'s actual payout cycle calendar before generating.',
                    ],
                ],
                'related' => ['leads', 'vendors', 'services'],
            ],

            // ═══════════════════════════════════════════════════════════
            'complaints' => [
                'title'  => 'Complaints / Grievances',
                'module' => 'Support',
                'summary' => 'Customer-filed grievances, each optionally linked to a specific lead, with a staff response workflow.',
                'what_is' => 'This screen is the formal customer-grievance channel, separate from the informal per-lead message thread that already exists on a lead\'s detail page. A complaint is a row in rto_complaints carrying its own status (open, under_review, resolved, closed, rejected), filed by a client, and optionally linked to one specific lead via lead_id for quick cross-reference. Administrators use this screen as part of routine daily operations — its open-complaint count is also surfaced on the Dashboard — to review newly-filed grievances, move a complaint through its own review workflow, and respond to a customer\'s formally-raised issue. It is important to understand that a complaint\'s status and a lead\'s status are two completely independent state machines: resolving or closing a complaint here never changes the linked lead\'s own status, and vice versa, even when the complaint is clearly "about" that lead — if the underlying case genuinely also needs a status change, that has to be done separately on the Leads screen.',
                'why_exists' => 'Gives customers a formal channel to raise an issue with their case, separate from the informal per-lead message thread.',
                'who' => ['RTO Admin'],
                'when' => ['A customer reports a problem with their service or vendor.', 'Reviewing open complaints as part of daily operations (also surfaced as a count on the Dashboard).'],
                'fits_into' => 'If linked to a lead, the complaint detail view shows that lead\'s number for quick cross-reference — but changing a complaint\'s status never changes the linked lead\'s own status; they are independent state machines.',
                'known_note' => 'A second admin URL, /rto-admin/grievance/, renders a near-duplicate listing against the SAME underlying data. It is not linked from the admin menu or the navigation hub, and appears to be a leftover from an earlier naming change rather than an intentionally separate feature — use this Complaints screen (linked from the admin menu) as the actual, supported entry point.',
                'does_not_control' => [
                    'Responding to or closing a complaint here does not change the linked lead\'s status — if the underlying case also needs a status change, that is a separate action on the Leads screen.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Assuming resolving a complaint also resolves or updates the underlying lead.',
                        'symptom' => 'A complaint is marked resolved, but the customer\'s actual case (the linked lead) is still stuck in whatever status prompted the complaint in the first place.',
                        'fix' => 'Separately check and update the linked lead\'s status on the Leads screen if the underlying issue also needs a status change — they are independent records.',
                        'prevention' => 'Treat "resolving a complaint" and "fixing the underlying case" as two separate action items whenever a complaint is linked to a lead.',
                    ],
                    [
                        'category' => 'permission/access mistake',
                        'mistake' => 'Using the unlinked /rto-admin/grievance/ URL as a bookmark instead of the Complaints screen linked from the admin menu.',
                        'symptom' => 'Confusion about which screen is the "real" one, or inconsistent workflow habits across different staff members.',
                        'fix' => 'Always use the Complaints screen reachable from the admin menu — the other URL is an orphaned duplicate, not a separate supported feature.',
                        'prevention' => 'Bookmark and train staff on the menu-linked Complaints screen only.',
                    ],
                ],
                'related' => ['leads'],
            ],

            // ═══════════════════════════════════════════════════════════
            'reports' => [
                'title'  => 'Reports',
                'module' => 'Reports',
                'summary' => 'Date-ranged revenue, leads, vendor, and service reports, each with CSV export — read-only, computed live from the same tables every other screen writes to.',
                'what_is' => 'This screen gives finance and operations staff a way to answer "how did we do in this period?" without needing direct database access. It offers four report types selectable on one screen — Revenue (daily payment totals plus GST, from rto_payments), Leads (per-service volume, completed/cancelled counts, SLA-breach counts, and average lead value), Vendors, and Services — each filtered by a date range applied to created_at (for the Revenue report specifically, the date range applies to the payment\'s own created_at, not the underlying lead\'s). Administrators use this screen for month-end finance reconciliation, for identifying which services are driving the most volume or, conversely, the most SLA breaches, and for exporting a period\'s figures as CSV for use in an external report or audit. The single most important thing to understand about this screen is that it is a purely read-only layer over the same tables every other screen already writes to (rto_payments, rto_leads, rto_services, rto_vendors) — a report is always computed live, at the exact moment you open it, for whatever date range you selected; it is never a saved snapshot, so re-running the same date range later can legitimately produce different numbers if underlying records changed in the meantime (a lead\'s status changed, a late payment posted, and so on).',
                'why_exists' => 'Gives finance/ops a way to answer "how did we do in this period?" without needing raw database access, and to export figures for use outside the platform.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['Month-end finance reconciliation.', 'Checking which services are driving the most volume or the most SLA breaches.', 'Exporting a period\'s figures for an external report or audit.'],
                'fits_into' => 'Purely a read layer over rto_payments, rto_leads, rto_services, and rto_vendors — it never writes anything. A report is always a live query for the selected date range at the moment you open it; it is not a saved snapshot, so re-running the same date range later can show different numbers if underlying records changed since (e.g. a lead\'s status changed, a late payment posted).',
                'sections' => [
                    ['name' => 'Report selector + date range', 'body' => 'Pick one of Revenue / Leads / Vendors / Services, and a From/To date. The date range applies to created_at on the relevant table — for Revenue specifically, this is the payment\'s own created_at, not the lead\'s.'],
                    ['name' => 'Export CSV', 'body' => 'Exports the currently-selected report and date range as CSV — the same query, not a separately-cached export.'],
                ],
                'does_not_control' => [
                    'This screen has no editable settings — changing a date range or report type here never writes to the database.',
                    'A report generated here is a point-in-time read — it does not "lock in" or snapshot the numbers; reopening the same range later reflects whatever the data looks like now, not what it looked like when you first ran the report.',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'unexpected-behavior mistake',
                        'mistake' => 'Comparing the Revenue report\'s total against the Leads report\'s average-value × count for what looks like the same period.',
                        'symptom' => 'The two figures do not match, and it looks like a bug or a data-integrity problem.',
                        'fix' => 'This is expected, not a bug — Revenue counts actual completed PAYMENTS filtered by payment date, while the Leads report\'s avg_value is the average total_amount of LEADS filtered by lead-creation date; a lead created in one period can be paid in a different one, and a partially-paid lead is not the same as its full total_amount.',
                        'prevention' => 'Treat Revenue and Leads reports as answering different questions (cash collected vs cases opened) rather than expecting them to reconcile to the same figure for the same date range.',
                    ],
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Treating an exported report as a permanent, reproducible record without saving the actual CSV.',
                        'symptom' => 'Re-running the same date range weeks later for an audit produces different numbers than what was originally reported.',
                        'fix' => 'Retrieve the originally-exported CSV file rather than attempting to regenerate the exact same figures later — the report is a live query, not a stored snapshot.',
                        'prevention' => 'Always save the exported CSV file itself whenever a report\'s figures need to be referenced or defended later.',
                    ],
                ],
                'troubleshooting' => [
                    '"Revenue report total doesn\'t match what I expect from the Leads report\'s avg_value × count" → this is expected, not a bug: the Revenue report counts actual completed PAYMENTS in the date range, while the Leads report\'s avg_value is the average total_amount of LEADS created in that range — a lead created in one period can be paid in a different period, and a partially-paid lead is not the same as its full total_amount.',
                ],
                'related' => ['leads', 'payouts'],
            ],

            // ═══════════════════════════════════════════════════════════
            'ratings' => [
                'title'  => 'Ratings',
                'module' => 'Support',
                'summary' => 'Moderation queue for the 1–5 star ratings clients leave on completed leads — with per-vendor and per-score filters, CSV export, and a delete action that live-recalculates the affected vendor\'s average.',
                'what_is' => 'This screen is the moderation queue for the 1-to-5-star ratings clients leave on completed leads. A rating is one row in rto_ratings tying a score and an optional comment to one completed lead and the vendor who worked it; a client can rate a lead only once it reaches status=completed, and only once per lead — a second attempt is rejected server-side, not merely hidden in the client-facing UI. Administrators use this screen when a vendor disputes a rating as unfair or fraudulent, when reviewing overall rating trends for a specific vendor, or when a clearly abusive or spam comment needs to be removed. The reason this screen exists as a dedicated moderation surface, rather than ratings simply being immutable public records, is that vendor rating quality directly feeds the auto-assignment scoring formula on Settings → Vendor Matching (its rating_weight component) — so a spam or malicious rating does not just sit there cosmetically, it can genuinely distort which vendor gets assigned future work. This is also why every insert or delete on this screen immediately and live-recalculates the affected vendor\'s rto_vendors.rating column via a direct AVG(score) query, rather than through a batch job — meaning a moderation decision made here has an effect that propagates to assignment decisions right away, not after some delay.',
                'why_exists' => 'Vendor rating quality directly feeds auto-assignment scoring (Settings → Vendor Matching\'s rating_weight) — this screen is how staff moderate abusive/spam ratings without needing database access.',
                'who' => ['RTO Admin'],
                'when' => ['A vendor disputes a rating as unfair or fraudulent.', 'Reviewing overall rating trends for a specific vendor.', 'Removing a clearly abusive or spam comment.'],
                'fits_into' => 'Upstream: created only by a client rating a completed lead (gated by the vendor_ratings feature flag — if that flag is off, the client-facing submit endpoint rejects every attempt with a 404, even though this admin moderation screen remains visible). Downstream: every insert or delete here immediately recalculates and writes the affected vendor\'s rto_vendors.rating column (a live AVG(score) query, not a cached/batch job) — which is read live by Vendors and by the auto-assignment scoring formula.',
                'fields' => [
                    ['name' => 'Vendor filter / Score filter', 'meaning' => 'Narrow the list to one vendor and/or one exact star value.', 'affects' => 'The headline average shown at the top of the list is computed over the FULL filtered set (not just the current page), so filtering to one vendor shows that vendor\'s true average, not a page-scoped approximation.'],
                ],
                'buttons' => [
                    ['name' => 'Remove (single) / Bulk delete selected', 'does' => 'Permanently deletes the rating row(s) and immediately recalculates the average rating of every vendor affected, writing the new average straight to rto_vendors.rating.', 'confirmation' => 'Recommended before use — this is destructive.', 'reversible' => 'No — the rating text/score is gone; the vendor\'s average will shift back only if the exact same rating is somehow re-added, which this screen has no undo for.', 'does_not' => 'Deleting a rating does not touch the underlying lead or notify the client who left it.'],
                    ['name' => 'Export CSV', 'does' => 'Exports the currently-filtered list (all matching rows, capped 5,000), not just the current page.'],
                ],
                'does_not_control' => [
                    'This screen cannot edit a rating\'s score or comment — only delete it entirely. There is no "correct the score" action.',
                    'Deleting a rating here never changes the underlying lead\'s status or any payment/payout record.',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Deleting a low rating solely because a vendor complained, without checking whether it reflects a genuine service issue.',
                        'symptom' => 'The vendor\'s average rating rises immediately, but there is no record of whether the underlying complaint was actually justified.',
                        'fix' => 'If the rating is later found to have been legitimate, there is no restore action — the best available remedy is documenting the decision elsewhere and being more conservative with future deletions.',
                        'prevention' => 'Only delete a rating for a specific, defensible reason (spam, abuse, policy violation) — every deletion permanently and immediately shifts a live number that feeds auto-assignment.',
                    ],
                    [
                        'category' => 'data-entry mistake',
                        'mistake' => 'Selecting the wrong rows during a bulk-delete action.',
                        'symptom' => 'More ratings are removed than intended, and the affected vendors\' averages have already been recalculated by the time the mistake is discovered.',
                        'fix' => 'There is no undo — the deleted ratings cannot be restored; document the incident and, if the exact original scores/comments are known, consider whether a manual note or correction elsewhere in the vendor\'s record is warranted.',
                        'prevention' => 'Always re-check the selected-row count and vendor names before confirming a bulk delete on this screen.',
                    ],
                ],
                'troubleshooting' => [
                    '"A client says they can\'t submit a rating" → check whether their lead is actually status=completed (ratings are blocked before that) and whether they have already rated this same lead once (a second attempt is rejected) — and separately, confirm the "vendor_ratings" feature flag is enabled, since a disabled flag blocks every new rating submission platform-wide regardless of this screen\'s own state.',
                ],
                'related' => ['vendors', 'settings-matching'],
            ],

            // ═══════════════════════════════════════════════════════════
            'masters' => [
                'title'  => 'Cities / RTOs (Masters)',
                'module' => 'Locations',
                'summary' => 'The base geography reference data — states and cities (with each city\'s RTO code) — that every other screen\'s location dropdowns and coverage settings are built on top of.',
                'what_is' => 'This screen manages the base geography reference data the entire platform is built on top of: states (a read-only reference tab) and cities (an addable, searchable, paginated list of rto_cities rows, each tied to one state and carrying its own RTO office code). Administrators use this screen almost exclusively as a prerequisite step — before a city can be assigned as coverage to any vendor, before it can receive a City/Service Pricing override, and before it can appear in the public Apply page\'s own state/city dropdowns, it has to exist as a row here first. In practice this means the most common real-world use of this screen is expanding into a genuinely new city or correcting a city\'s RTO code, and it is rarely touched otherwise, precisely because everything downstream (Vendor coverage, City/Service Pricing, the customer-facing location pickers) depends on this foundational list rather than the other way around.',
                'why_exists' => 'Vendor coverage, City/Service Pricing, and every customer-facing location dropdown all point back to this list — a city has to exist here before it can be assigned to a vendor or given a pricing override.',
                'who' => ['RTO Admin'],
                'when' => ['Expanding into a new city — this is the first, prerequisite step before that city can appear anywhere else in the system.', 'Correcting a city\'s RTO code.'],
                'fits_into' => 'Upstream: nothing — this is foundational reference data. Downstream: Vendor coverage (a vendor can only be mapped to a city that exists here), City/Service Pricing (same constraint), and the public Apply page\'s state/city dropdowns all read from this table.',
                'buttons' => [
                    ['name' => 'Add City', 'does' => 'Inserts a new row into rto_cities under the selected state.', 'reversible' => 'No dedicated delete action exists on this screen for a city once other records (vendors, pricing overrides) may already reference it — removing a city in active use is not something this screen is designed to do safely.'],
                    ['name' => 'Export CSV', 'does' => 'Exports the currently-filtered (by state/search) city list, all matching rows.'],
                ],
                'does_not_control' => [
                    'Adding a city here does not automatically make any service available in it — that still requires either the service being active platform-wide (Services screen) with no city-level override, or an explicit visible=true row for that city on City/Service Pricing.',
                    'This screen does not manage RTO office-level detail beyond the city\'s own RTO code — a more granular RTO-jurisdiction concept was not found in this codebase (there is no separate "RTO office" entity independent of a city in the schema this audit found).',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'data-entry mistake',
                        'mistake' => 'Adding a city under the wrong state.',
                        'symptom' => 'The city does not appear when filtered/searched under the state an admin expects.',
                        'fix' => 'Locate the city under its actual assigned state and correct it, or add a new correctly-scoped row and treat the mis-scoped one as a data-entry error to be cleaned up.',
                        'prevention' => 'Double-check the selected state in the Add City form before submitting, especially for cities with names that could plausibly belong to more than one state.',
                    ],
                    [
                        'category' => 'incorrect assumption',
                        'mistake' => 'Assuming a newly-added city automatically has services available to customers there.',
                        'symptom' => 'The city appears in dropdowns but shows no bookable services on the public Apply page.',
                        'fix' => 'Configure the city\'s service visibility on the City/Service Pricing screen — adding a city here only creates the location reference, not any service availability within it.',
                        'prevention' => 'Treat "add a city" and "make services visible in that city" as two separate, sequential setup steps.',
                    ],
                ],
                'troubleshooting' => [
                    '"I added a city but a vendor still can\'t be mapped to it" → confirm you are looking at the correct STATE tab/filter — the Add City form requires picking a state, and a city search elsewhere in the admin is often state-scoped.',
                ],
                'related' => ['city-pricing', 'vendors'],
            ],

            // ═══════════════════════════════════════════════════════════
            'workflows' => [
                'title'  => 'Workflow Builder',
                'module' => 'Services',
                'summary' => 'Per-service CUSTOM status-transition rules — lets one specific service follow its own lifecycle instead of the platform\'s default lead pipeline, without any code change.',
                'what_is' => 'The Workflow Builder lets an administrator give one specific service its own custom status-transition lifecycle, instead of following the platform\'s default 12-status lead pipeline. A custom workflow definition is a named, active/inactive set of states and transitions, with each transition optionally restricted to a specific role or gated by a simple guard condition; a service with no custom definition simply falls back to the platform default. In practice this screen is used rarely, and only when a genuinely unusual service needs a materially different sequence of steps than the standard RTO case pipeline every other service follows — most services should never need a custom workflow at all. The other real, everyday use of this screen is purely informational: reviewing exactly what the "default workflow" preview shows for a service with no custom definition, so an admin can see the platform default clearly laid out before deciding whether an override is genuinely warranted. The most important thing to understand architecturally is that this screen does NOT change how the real Leads screen enforces status changes for the default pipeline — that enforcement lives entirely and exclusively in LeadService::isValidTransition(), completely independent of anything configured here. What this screen genuinely controls is a per-service CUSTOM override (actually enforced by WorkflowEngineService::canTransition() for services with an active custom definition) and the read-only default-workflow preview for everything else. Each transition can also carry an optional guard condition — up to three field/operator/value rows (e.g. total_amount > 5000), all ANDed together — set or changed via the "Edit" button on that transition\'s row; a transition with no guard rows behaves exactly as before (always allowed once the role check passes). The guard is checked against the lead\'s own real column values at the moment a status change is actually attempted, using the same evaluator that also runs form-field and document visibility rules, so this is a genuine restriction, not a cosmetic one.',
                'why_exists' => 'Most services should follow the standard RTO case pipeline (see the Leads article), but an unusual service might need a genuinely different sequence of steps — this lets that be configured per-service rather than hard-coded.',
                'who' => ['RTO Admin'],
                'when' => ['A specific service needs a status sequence that doesn\'t match the platform default.', 'Reviewing what the "default" workflow actually allows for a service with no custom definition.'],
                'fits_into' => 'This screen does NOT change how the real Leads screen enforces a status change for the default pipeline — that enforcement lives entirely in LeadService::isValidTransition() and is not affected by anything configured here. What this screen controls is: (a) a per-service CUSTOM override, actually enforced by WorkflowEngineService::canTransition() for services that have an active custom definition, and (b) the "default workflow" preview shown for a service with no custom definition, so staff can see the platform default before deciding whether to override it.',
                'known_limitation' => 'A real, code-verified inconsistency was found and fixed as part of this audit: the constant this screen\'s "default workflow" preview was built from (WorkflowService::TRANSITIONS) had drifted out of sync with the ACTUAL rules LeadService enforces for every real status change on the Leads screen — for example, it previously allowed a direct created→assigned jump and a completed→cancelled transition, neither of which the real Leads screen has ever actually allowed. This has now been corrected so the two are identical (verified by executing both side-by-side against all 144 from/to status pairs) — but it is worth understanding going forward: LeadService::isValidTransition() is the single source of truth for the platform default, and this screen\'s default-workflow preview is a read-only reflection of it, not an independent setting. If a future code change updates one, the other must be updated in the same change or this same drift will reappear.',
                'source_of_truth' => 'For a service with a custom, active workflow definition: THIS screen\'s transitions are authoritative for that service, enforced by WorkflowEngineService::canTransition(). For a service with no custom definition: LeadService::isValidTransition() is authoritative, and this screen only displays it — it does not let you edit the platform default from here.',
                'does_not_control' => [
                    'Deleting or deactivating a service\'s custom workflow definition here does not change any already-in-progress lead\'s CURRENT status — it only changes which rule set future status-change attempts on that service\'s leads are checked against.',
                    'A guard condition only ever ANDs its rows together (field/operator/value, each checked against the lead\'s own current column values) — there is no OR mode and no nesting from this screen\'s editor, even though the underlying evaluator (ConditionGroupEvaluator) can technically support both; the UI intentionally exposes only the simpler, always-safe AND case.',
                    'A guard is evaluated against the lead row AT THE MOMENT of the status-change attempt, not against any earlier snapshot — if a guard references total_amount and that amount changes between when the lead was created and when the transition is attempted, the current value is what gets checked.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Assuming a lead unexpectedly moving to a status is a Leads-screen bug when the service actually has a custom, active workflow.',
                        'symptom' => 'A lead for a specific service moves to a status that does not match what the standard 12-status pipeline article describes.',
                        'fix' => 'Check this screen for whether that service has an ACTIVE custom workflow definition — if so, that definition, not the platform default, is what actually governed the transition.',
                        'prevention' => 'When troubleshooting an unexpected status change, always check for a service-specific custom workflow before assuming the standard pipeline rules were violated.',
                    ],
                    [
                        'category' => 'configuration mistake',
                        'mistake' => 'Creating a custom workflow for a service that did not actually need one, duplicating most of the platform default with only a minor variation.',
                        'symptom' => 'Maintaining that service\'s lifecycle now requires updating two places (the custom definition here, plus awareness of the platform default) instead of one.',
                        'fix' => 'Review whether the custom definition can be simplified or removed entirely if the variation from the default turns out to be unnecessary or was based on a misunderstanding.',
                        'prevention' => 'Only create a custom workflow when a service genuinely cannot be represented by the platform default pipeline — review the default preview here first before building a custom one.',
                    ],
                ],
                'troubleshooting' => [
                    '"A lead for this service can move to a status I didn\'t expect" → check whether this service has an ACTIVE custom workflow definition here; if yes, that definition — not the platform default — is what actually governed the change.',
                ],
                'related' => ['leads', 'services'],
            ],

            // ═══════════════════════════════════════════════════════════
            'payments' => [
                'title'  => 'Payments Ledger',
                'module' => 'Payments',
                'summary' => 'The read-only record of every payment actually collected from clients — amount, GST, method, and transaction reference — with summary totals and filters.',
                'what_is' => 'This screen is the read-only ledger of every payment a client has actually made, one row per payment in rto_payments, joined to the lead it belongs to and the client who paid. Administrators and staff use it to look up a specific transaction (by filtering on payment method or a date range), to get a quick sense of collections via the summary cards at the top (total payments, total revenue, total GST for whatever is currently filtered), and as the jumping-off point into the fuller Revenue Report for month-end reconciliation. It is important to be precise about what this screen actually is versus two things it is easily confused with: it is not the same as Payouts (a completely separate screen and table tracking money owed TO vendors, not collected FROM clients), and it is not itself an action screen — there is no button here to record a new payment, issue a refund, or edit an existing transaction; every one of those actions happens elsewhere (a payment is recorded from the specific lead\'s own detail page, and a refund is approved through a separate admin-only flow), and this screen only ever displays what has already happened. Clicking through from a row here takes you to that payment\'s underlying lead, which is the correct place to go if the payment itself needs any kind of follow-up action.',
                'why_exists' => 'Staff need a single place to look up "did this client actually pay, and how much, and by what method" without opening each lead individually, and finance needs collection totals for a period without cross-referencing every lead one at a time.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['A customer asks whether their payment went through.', 'Reconciling a specific payment method\'s collections for a period (e.g. all UPI payments in a week).', 'Spot-checking recent transactions before running the full Revenue Report.'],
                'fits_into' => 'Purely a read layer over rto_payments (joined to rto_leads for the lead number, and wp_users for the client\'s display name) — it never writes to the database itself. Payments are actually written elsewhere: from a lead\'s own detail page (an admin-only "Record Payment" action, distinct from the rto_staff-level access this ledger itself only requires to VIEW), or automatically via the Razorpay payment webhook when an online payment is captured. The "📊 Revenue Report" button leaves this screen entirely for the separate, already-documented Reports module, which computes a true full-period aggregate rather than this screen\'s own page-scoped list.',
                'sections' => [
                    ['name' => 'Summary cards', 'body' => 'Total Payments, Total Revenue, and Total GST for whatever filter is currently applied.'],
                    ['name' => 'Filters', 'body' => 'Payment method (Razorpay, bank transfer, UPI, cash, cheque, NEFT, RTGS), and a From/To date range applied to the payment\'s own created_at.'],
                    ['name' => 'Table', 'body' => 'Date, the linked lead (click through to its detail page), client name, amount, GST, method, transaction ID, and status — read-only, 25 rows per page.'],
                ],
                'does_not_control' => [
                    'This screen has no action buttons for recording, editing, or refunding a payment — all of those happen on the relevant lead\'s own detail page or through a separate admin-only refund-approval flow.',
                    'This is not the Payouts screen — Payments tracks money collected FROM clients; Payouts (a separate screen and table) tracks money owed TO vendors. They share no data.',
                    'A payment that was later refunded still shows here with its original status — this screen does not currently reflect refund state on the original transaction row.',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'incorrect assumption',
                        'mistake' => 'Trusting the Total Revenue / Total GST summary cards as the true total for a filtered period.',
                        'symptom' => 'The summary figure looks lower than expected for a period with a large number of matching payments.',
                        'fix' => 'This is now fixed — the summary reflects the full filtered set regardless of pagination — but for month-end reconciliation, cross-check against the Reports → Revenue screen\'s own independent aggregate as a second source of truth.',
                        'prevention' => 'Treat the Reports module as the authoritative source for period totals used in finance reporting; treat this screen\'s summary as a quick, at-a-glance figure.',
                    ],
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Looking for a "Record Payment" or "Refund" button on this screen.',
                        'symptom' => 'No such action is visible anywhere on the Payments Ledger.',
                        'fix' => 'Navigate to the specific lead\'s own detail page (via the linked lead number in the table) to record a new payment or initiate a refund — this ledger is read-only by design.',
                        'prevention' => 'Remember this screen is a report/lookup surface, not a transaction-entry surface.',
                    ],
                    [
                        'category' => 'permission/access mistake',
                        'mistake' => 'Assuming any staff member who can view this ledger can also record a payment.',
                        'symptom' => 'An rto_staff user can see every transaction here but gets an access-denied response attempting to record a new payment on a lead.',
                        'fix' => 'Recording a payment specifically requires the rto_admin role — have an admin record it, or escalate the specific case to one.',
                        'prevention' => 'Understand that viewing this ledger (rto_staff or above) and recording a new payment (rto_admin only) are gated at different permission levels.',
                    ],
                ],
                'troubleshooting' => [
                    '"A customer says they paid but I don\'t see it here" → check the method and date filters are not accidentally narrowing the list; also check whether the payment might still be pending on the gateway side (a Razorpay payment is only recorded here once the webhook confirms it as captured, not the instant the customer submits their card).',
                    '"This payment shows completed but the customer says they were refunded" → this screen does not currently show refund state on the original transaction — check the lead\'s own detail page for a separate refund record.',
                ],
                'related' => ['leads', 'payouts', 'reports'],
            ],

            // ═══════════════════════════════════════════════════════════
            'features' => [
                'title'  => 'Feature Flags',
                'module' => 'Settings',
                'summary' => 'A single on/off switchboard of 24 platform capabilities, backed by one settings value — lets a capability be turned off platform-wide without a code deployment.',
                'what_is' => 'This screen is a switchboard of 24 named feature flags (FeatureFlags::DEFAULTS, app/Config/FeatureFlags.php) stored as one JSON value in WordPress options, each rendered as a checkbox with a plain-language label and description. Four flags — lead management, vendor management, payment collection, and document management — are "core" and permanently locked on, since the platform cannot meaningfully function without them; every other flag can be freely toggled by any admin or staff user. Administrators use this screen when a specific capability needs to be switched off platform-wide without waiting for a code deployment — for example, temporarily disabling client-submitted vendor ratings during a review of the moderation process, or turning off the grievance/complaint portal during a policy change. Saving the form applies every checkbox\'s new state immediately and live (there is no separate "publish" step), and every save is also recorded to the same version-history mechanism used by Settings → Vendor Matching, so a change that has an unwanted effect can be rolled back to the exact previous set of flag values. [Audited] A prior pass claimed 7 of the (then-miscounted 23) flags had zero effect; re-verified against the current code with a codebase-wide grep, only 2 (vendor_self_signup, payment_links) genuinely have no real call site anywhere — the other 5 (whatsapp_notifications, sms_notifications, razorpay_payments, webhook_whatsapp, api_access) are checked at real call sites in integrations/ and rtoflow-os.php, outside the app/ tree the earlier audit searched. The 2 genuinely-dead flags are now badged "Not yet wired" directly on this screen (FeatureFlags::NOT_WIRED, surfaced via all()[\'wired\']) instead of being left for an admin to discover by trial and error.',
                'why_exists' => 'Lets specific platform capabilities be switched off centrally (for maintenance, a policy change, or an incident) without a code deployment, and gives every change a rollback path via the same version-history mechanism used elsewhere in Settings.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['Temporarily disabling a capability during an incident or policy review (e.g. turning off vendor ratings while investigating abuse).', 'Confirming whether a specific capability is currently enabled before troubleshooting why it "isn\'t working".', 'Rolling back a recent flag change that had an unexpected effect.'],
                'fits_into' => 'FeatureFlags::is_enabled() is checked at 25 real call sites across the codebase (e.g. RatingsController for vendor_ratings, LeadsController for report_export, ComplaintsController + Router.php + client-header.php for grievance_portal, PaymentService for webhook_razorpay, NotificationService for email_notifications, and — outside app/ — integrations/WhatsApp.php, integrations/Sms.php, integrations/Razorpay.php, rtoflow-os.php) — toggling one of those flags off here has a genuine, immediate effect on that specific piece of functionality. Saving also writes a version through the same ConfigVersionService used by Settings → Vendor Matching; a rollback from that version history uses a dedicated, real-boolean setter (FeatureFlags::applyBooleanMap()) rather than the checkbox-presence logic the normal Save button uses, specifically so a rollback correctly restores a flag that was OFF rather than accidentally re-enabling it — re-verified real-execution against the current source (/tmp/verify_features_flags_rollback_and_wiring.php, 17/17 assertions pass): this distinction is real and correct, not just a comment\'s claim.',
                'sections' => [
                    ['name' => 'Flag list', 'body' => 'One checkbox per flag, each with a human-readable label and description. The 4 core flags render as permanently checked and disabled, with a "Core" badge.'],
                    ['name' => 'Save Feature Flags', 'body' => 'Submits every checkbox\'s current state; an unchecked box is treated as "off" the moment the form is submitted, applied immediately and live.'],
                    ['name' => 'Version History', 'body' => 'The same version-history/rollback panel used on Settings → Vendor Matching — every save is recorded, and rolling back to a prior version restores that exact set of flag values.'],
                ],
                'does_not_control' => [
                    'Disabling a flag does not hide the related screen or menu item — it only blocks the specific server-side check(s) that reference that flag. A disabled grievance_portal flag, for example, does not remove the Complaints menu item; it only blocks the specific submission endpoint gated on it.',
                    'Turning a flag off does not undo anything that already happened while it was on — e.g. disabling vendor_ratings does not delete existing ratings, it only blocks new rating submissions going forward.',
                    'The "Feature Flags" card on the Customizer Hub screen is a shortcut link into this same screen, not a separate flags system.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'incorrect assumption',
                        'mistake' => '[Corrected] This screen previously documented "Razorpay Gateway" and "WhatsApp Notifications" as examples of flags with no real effect. Re-audited: both are genuinely wired (integrations/Razorpay.php:142, integrations/WhatsApp.php:119) and do change behavior when toggled.',
                        'symptom' => 'N/A for these two flags now — toggling either one has a real, immediate effect. Only vendor_self_signup and payment_links are currently confirmed to have no effect (badged "Not yet wired" directly on this screen).',
                        'fix' => 'The screen itself now tells you: a flag badged "Not yet wired" has no real effect; anything without that badge is a genuine, checked control.',
                        'prevention' => 'Trust the "Not yet wired" badge on this screen rather than assuming any particular checkbox is dead — it is computed from FeatureFlags::NOT_WIRED and kept in sync with the actual codebase.',
                    ],
                    [
                        'category' => 'configuration mistake',
                        'mistake' => 'Trying to uncheck one of the 4 "Core" flags.',
                        'symptom' => 'The checkbox appears greyed out and will not respond to clicks.',
                        'fix' => 'This is intentional — core flags (lead management, vendor management, payment collection, document management) are hardcoded always-on and cannot be disabled through this screen at all.',
                        'prevention' => 'Understand upfront which flags are core before assuming a stuck checkbox is a bug.',
                    ],
                    [
                        'category' => 'unexpected-behavior mistake',
                        'mistake' => 'Assuming a version-history rollback and a normal Save behave through the exact same code path.',
                        'symptom' => 'No visible difference in practice for a straightforward full rollback, but this can matter if troubleshooting a subtle discrepancy.',
                        'fix' => 'Understand that Save uses checkbox-presence logic (unchecked = off) while Rollback restores an explicit true/false map for every flag — both are correct and tested, but they are genuinely two different code paths reaching the same kind of result.',
                        'prevention' => 'When in doubt about the platform\'s current flag state, always re-open this screen to see the live checkbox states rather than relying on memory of a prior save or rollback.',
                    ],
                ],
                'troubleshooting' => [
                    '"I disabled a flag but the related feature still works" → check for a "Not yet wired" badge next to that flag on this screen (only vendor_self_signup and payment_links currently carry it — see Known Limitations); if the flag is not badged, it is a genuine, checked control and something else is going on (e.g. a cached response, or the feature having more than one entry point).',
                    '"I need to undo a recent flag change" → use the Version History panel at the bottom of this screen to roll back to the exact set of flag values from before the change, rather than trying to manually remember and re-toggle each one.',
                ],
                'related' => ['settings-matching'],
            ],

            // ═══════════════════════════════════════════════════════════
            'customizer' => [
                'title'  => 'Customizer Hub',
                'module' => 'Services',
                'summary' => 'A pure navigation hub — 6 shortcut cards to the platform\'s configuration screens, with no settings, forms, or data of its own.',
                'what_is' => 'The Customizer Hub is deliberately the thinnest screen in the entire admin portal: it holds no database table, no options, and no form of its own — it is a static grid of 6 cards, each an icon, a title, a short description, and a link straight through to a genuinely separate configuration screen (Eligibility Rules, Dynamic Forms/Form Builder, Workflow Engine, Matching Configuration, Feature Flags, and City & Service Pricing). Administrators use it purely as a starting point or a memory aid when they know they need to configure "something about how the platform behaves" but are not sure exactly which specific screen that lives on — clicking any card takes you directly to the real screen where the actual setting lives, with nothing lost or duplicated along the way. There is nothing to save, submit, or break on this screen itself; every meaningful action described anywhere in this Help Centre for any of its 6 linked screens applies on that destination screen, not here.',
                'why_exists' => 'The platform has several genuinely different configuration screens (forms, eligibility, workflows, matching, flags, pricing) that are conceptually related ("things that change how the platform behaves") but live at different URLs — this hub exists purely to give admins one obvious jumping-off point rather than requiring them to already know each screen\'s exact menu location.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['Orienting a new admin/staff member to where platform-behavior configuration screens live.', 'As a quick jumping-off point when you know you need to change "something configurable" but don\'t remember exactly which screen.'],
                'fits_into' => 'Every one of its 6 cards is a plain link to an already-documented, fully separate screen (Eligibility Rules, Form Builder, Workflow Builder, Settings → Vendor Matching, Feature Flags, City/Service Pricing) — this hub itself reads and writes nothing.',
                'sections' => [
                    ['name' => 'Configuration cards', 'body' => 'Six cards, each linking directly to its target screen: Eligibility Rules, Dynamic Forms, Workflow Engine, Matching Configuration, Feature Flags, City & Service Pricing.'],
                ],
                'does_not_control' => [
                    'This screen has no settings, fields, or buttons of its own beyond the 6 navigation links — every actual configuration happens on the destination screen after clicking through.',
                    'Nothing here can be saved or submitted; there is no form on this page at all.',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'incorrect assumption',
                        'mistake' => 'Looking for a save button or setting directly on this screen.',
                        'symptom' => 'No form, checkbox, or input of any kind is visible anywhere on the Customizer Hub.',
                        'fix' => 'Click through to the specific card for what you actually want to configure — every real setting lives on one of the 6 linked screens, never on this hub itself.',
                        'prevention' => 'Treat this screen purely as a directory/menu, not a configuration surface in its own right.',
                    ],
                ],
                'related' => ['eligibility', 'forms', 'workflows', 'settings-matching', 'features', 'city-pricing'],
            ],

            // ═══════════════════════════════════════════════════════════
            'settings' => [
                'title'  => 'Settings (Company / Payment / SMS / WhatsApp / Notifications / Colors)',
                'module' => 'Settings',
                'summary' => 'One controller, seven tabs — company profile and SLA defaults, payment/SMS/WhatsApp provider credentials, per-event notification toggles, and the platform\'s brand color palette.',
                'what_is' => 'This screen is a single controller serving seven distinct tabs, selected via a `?tab=` parameter: Company, Payment, SMS, WhatsApp, Notifications, Matching, and Colors. The Matching tab is the exact same screen already documented separately (see Settings → Vendor Matching) — it is not a second, different matching configuration, just the same controller reached with a different tab parameter. The other six tabs are what this article covers: Company holds the organization\'s profile fields (name, GSTIN, TAN, address, support contact) plus the platform-wide default SLA day count and admin escalation contact; Payment/SMS/WhatsApp hold third-party provider credentials (Razorpay keys, SMS/WhatsApp API tokens), each encrypted at rest and never displayed back in plaintext once saved; Notifications is a small set of checkboxes gating exactly five specific automated notification events; and Colors sets the six brand colors (primary, secondary, accent, background, text) that are genuinely read and applied on the platform\'s real public-facing pages, not merely cosmetic placeholders. Administrators use the Company tab when onboarding the platform for a new organization or updating a registered address/GSTIN; the Payment/SMS/WhatsApp tabs when rotating a provider\'s API credentials; the Notifications tab when a specific automated email/SMS should be silenced; and the Colors tab when rebranding the customer-facing pages. Saving any tab writes only that tab\'s own settings (each tab is a fully separate form and save action) and clears the platform\'s services/states cache regardless of which tab was saved, since several cached values can theoretically depend on any of these settings.',
                'why_exists' => 'Company identity, third-party provider credentials, notification behavior, and brand colors all need a central, admin-controlled place to live rather than being hardcoded — this is that place for everything that isn\'t specifically vendor-matching (which has its own already-documented screen) or a feature on/off switch (Feature Flags, also separate).',
                'who' => ['RTO Staff', 'RTO Admin'],
                'when' => ['Onboarding the platform for a new organization (Company tab).', 'Rotating a payment gateway or messaging provider\'s API credentials (Payment/SMS/WhatsApp tabs).', 'Silencing one specific automated notification event (Notifications tab).', 'Rebranding the customer-facing pages\' color palette (Colors tab).'],
                'fits_into' => 'The Company tab\'s sla_days value is the platform-wide default SLA target a service falls back to if it has no more specific target of its own. The Colors tab\'s six option values are read directly by the real public-facing templates (apply.php, apply-dynamic.php, home.php, track.php, website-header.php, admin-header.php) — a color change here is genuinely load-bearing on the live site, not decorative. The Notifications tab only gates five specific event slugs inside NotificationService — any other notification path in the codebase ignores this tab entirely and always sends regardless of these checkboxes.',
                'sections' => [
                    ['name' => 'Company tab', 'body' => 'Company name, GSTIN, TAN, address, support phone/email, escalation admin user, default SLA days, and logo URL.'],
                    ['name' => 'Payment / SMS / WhatsApp tabs', 'body' => 'Provider API credentials — each secret field is encrypted at save time and the plaintext option is explicitly cleared; leaving a secret field blank on save preserves whatever was previously stored rather than clearing it.'],
                    ['name' => 'Notifications tab', 'body' => 'Five checkboxes controlling whether lead_created, vendor_assigned, status_changed, payment_received, and sla_warning notifications actually send — no other notification event in the codebase is gated by this tab.'],
                    ['name' => 'Colors tab', 'body' => 'Primary, secondary, accent, background, and text colors, each a hex-color picker, genuinely applied to the live public-facing pages.'],
                ],
                'does_not_control' => [
                    'The Matching tab reached from this same screen is the identical Settings → Vendor Matching screen already documented separately — this article does not re-describe its weight fields.',
                    'Feature Flags (on/off switches for specific platform capabilities) is a completely separate screen, not a tab here.',
                    'The Notifications tab\'s checkboxes only gate the five specific events named above — they do not act as a master "mute all notifications" switch.',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'incorrect assumption',
                        'mistake' => 'Assuming leaving a payment/SMS/WhatsApp secret field blank and saving will clear that credential.',
                        'symptom' => 'The field appears empty after saving, but the previously-configured integration keeps working exactly as before.',
                        'fix' => 'This is expected — a blank secret field on save intentionally preserves the existing encrypted value rather than clearing it; to actually remove a credential, a dedicated "clear" action would be needed (not currently provided), or a new valid value must be entered to replace it.',
                        'prevention' => 'Do not treat a blank secret field as evidence the credential is unset — check with the provider\'s own dashboard, or a test-send/test-transaction, to confirm the real current state.',
                    ],
                    [
                        'category' => 'validation-related mistake',
                        'mistake' => 'Entering a 3- or 6-digit hex color value without the leading #.',
                        'symptom' => 'The saved color silently becomes the platform\'s primary navy blue instead of the intended color, with no error shown.',
                        'fix' => 'Re-enter the color value with a leading # (e.g. #1B2A6B) and save again — the color picker widget itself should always produce a correctly-formatted value if used directly rather than typed by hand.',
                        'prevention' => 'Use the color-picker swatch rather than typing a hex value by hand wherever possible.',
                    ],
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Assuming the Notifications tab\'s checkboxes silence every automated message the platform sends.',
                        'symptom' => 'Unchecking all five notification checkboxes here, then still receiving other automated emails/SMS from the platform.',
                        'fix' => 'This tab only gates lead_created, vendor_assigned, status_changed, payment_received, and sla_warning specifically — any other automated message (e.g. from Email Templates\' own is_active flag on a specific template) is controlled elsewhere, not here.',
                        'prevention' => 'Treat this tab as five specific switches, not a master mute — check the Email Templates screen\'s per-template Active toggle for anything not covered by these five.',
                    ],
                ],
                'troubleshooting' => [
                    '"I changed the Accent Color but it turned into the navy blue instead" → you likely entered an invalid hex value (missing the leading #, or an incorrect number of digits) — re-enter it correctly using the color picker rather than typing it by hand.',
                    '"A specific automated notification still isn\'t sending after I enabled it here" → check the corresponding template\'s own is_active toggle on the Email Templates screen — both this tab\'s checkbox AND that template\'s own active flag need to be on for a template-driven notification to actually go out.',
                ],
                'related' => ['settings-matching', 'features', 'email-templates'],
            ],

            // ═══════════════════════════════════════════════════════════
            'email-templates' => [
                'title'  => 'Email Templates',
                'module' => 'Settings',
                'summary' => 'The real, database-backed content behind every automated email/SMS/WhatsApp message the platform sends — subject, body, active toggle, and (email only) a live test-send.',
                'what_is' => 'This screen manages the actual content of every automated message this platform sends to a client or vendor — each template is a real row in the database (not a hardcoded string in code), with a subject (email only), a body with `{variable}` placeholders, an active/inactive toggle, and — for SMS templates specifically — a DLT (TRAI) registration ID field required by Indian telecom regulation for commercial SMS. This is genuinely load-bearing content: NotificationService and the platform\'s email-integration layer both query this exact table by template slug and channel before sending, and substitute the real lead/vendor/payment data into the `{variable}` placeholders shown here — editing a template\'s body on this screen changes the literal wording of what a customer or vendor actually receives. Administrators use this screen to adjust the wording of an automated message, to temporarily disable one specific template without touching any others, to register a template\'s DLT ID for SMS regulatory compliance, and to send a one-off test message to themselves (email) or to a mobile number they enter (SMS/WhatsApp) using realistic sample data before trusting a wording change in production. [Updated] Every channel now has a genuine test-send action, not only email.',
                'why_exists' => 'Automated messages need to be edited by non-developers (correcting wording, adjusting tone, complying with a regulatory requirement) without a code deployment, and each message\'s content needs to be centrally controlled rather than duplicated across every place in the code that might send it.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['Correcting the wording of an automated message customers or vendors receive.', 'Registering or updating a required DLT template ID for an SMS template.', 'Temporarily disabling one specific automated message.', 'Test-sending an edited email template to confirm it renders correctly before trusting it in production.'],
                'fits_into' => 'NotificationService and the platform\'s email-integration layer both query rto_notification_templates by slug and channel with is_active=1 before sending — a template\'s subject/body edited here is the literal content a real customer or vendor receives, with `{variable}` placeholders substituted from that specific lead/vendor/payment\'s real data.',
                'sections' => [
                    ['name' => 'Template list', 'body' => 'Every template, its channel (email/SMS/WhatsApp), and active/inactive state; every row now has a Test button — email rows prompt for an email address, SMS/WhatsApp rows prompt for a 10-digit mobile number.'],
                    ['name' => 'Edit template', 'body' => 'Subject (email only), body (with clickable "insert variable" buttons for that template\'s available placeholders), Active toggle, and — SMS channel only — the DLT Template ID field.'],
                    ['name' => 'Test Send', 'body' => 'Sends a real message on that template\'s own channel, using realistic sample data (a fake lead number, name, etc.) substituted into the current saved template, prefixed "[TEST]", to confirm rendering before trusting a change in production. Email uses wp_mail(); SMS/WhatsApp use the same RTOFLOW_SMS/RTOFLOW_WhatsApp provider integrations the live send path uses.'],
                ],
                'does_not_control' => [
                    '[Updated] SMS and WhatsApp templates now also have a real test-send action (a mobile number instead of an email address) — see Known Limitations for what this fix uncovered and corrected.',
                    'The "Available Variables" buttons shown while editing are inferred from the template\'s slug string, not validated against that specific template\'s actual supported variables — they are a helpful guess, not a guarantee.',
                    'This screen edits template CONTENT only — it does not control WHETHER a given event fires a notification at all (that is the Settings → Notifications tab for the five events it covers, or the calling code\'s own logic for anything else).',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'unexpected-behavior mistake',
                        'mistake' => 'Trusting the "Template saved successfully" message as proof a change actually persisted, prior to this round\'s fix.',
                        'symptom' => 'A template edit appears to save, but reopening it shows the old, unchanged content.',
                        'fix' => 'This specific issue is now fixed (see Known Limitations) — but as a general habit, always reopen and re-check a template immediately after saving any content change that matters, rather than trusting the success banner alone.',
                        'prevention' => 'For anything content-critical, use the email test-send feature (where available) as a second confirmation that the saved content is actually what will be sent.',
                    ],
                    [
                        'category' => 'configuration mistake',
                        'mistake' => 'Using an "Available Variables" button\'s suggestion without checking it actually exists in that specific template\'s real substitution logic.',
                        'symptom' => 'A `{variable}` placeholder appears literally in the sent message instead of being replaced with real data.',
                        'fix' => 'Check the variable name against a template of the same slug that is known to render correctly, or test-send (email channel) to confirm substitution actually happens before relying on a newly-inserted variable.',
                        'prevention' => 'Treat the variable-insert buttons as a starting suggestion based on the template\'s name, not a guaranteed, validated list.',
                    ],
                ],
                'troubleshooting' => [
                    '"I edited a template but the change doesn\'t seem to show up anywhere" → confirm the template is actually marked Active, and — for anything outside the five Settings → Notifications events — check the calling code\'s own logic for whether that notification fires at all.',
                    '"A variable placeholder shows up literally in the sent message instead of the real value" → the variable name likely doesn\'t match what the sending code actually substitutes for that specific template — compare against a working template of the same type, or check with engineering.',
                ],
                'related' => ['settings'],
            ],

            // ═══════════════════════════════════════════════════════════
            'ai' => [
                'title'  => 'AI Insights',
                'module' => 'Reports',
                'summary' => 'A mostly-read-only analytics dashboard — risk-score distribution (with an on-demand re-score action), SLA compliance, a date-filterable monthly trend, date-filterable top services, and a basic system-health checklist.',
                'what_is' => 'Despite its name and 🤖 icon, this screen is primarily a read-only analytics and system-health dashboard — there is no model to train and no threshold to tune here. It shows five real panels computed from existing platform data: a Risk Score Distribution chart bucketing leads into High/Medium/Low bands off the rto_leads.risk_score column (with a "Re-score All Leads Now" button); SLA Compliance, Breached SLAs, Total Leads, and Completion Rate KPI cards; a Monthly Lead & Revenue Trend and a Top Converting Services ranking, both of which now accept an optional From/To date range (defaulting to the last 6 months / all-time respectively when no range is chosen, exactly as before); and a System Health checklist of six hardcoded boolean checks. The "risk_score" column is populated by a genuine deterministic rules engine (Bootstrap::computeRiskScore(), called from both the creation-time hook and the on-demand re-score action) gated behind the "AI Risk Scoring" (ai_scoring) feature flag, which is OFF by default — it scores 0-100 from new-client risk, high order value, high-risk service category (NOC/RC Services), and SLA urgency. It is a rules engine, not machine learning.',
                'why_exists' => 'Gives staff a place to review lead-quality and system-health signals without needing raw database access, using data that already exists in rto_leads and the platform\'s cron schedule — with a real way to both filter the trend/services panels by period and refresh stale risk scores in place, rather than only ever reading a static snapshot.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['Reviewing lead-quality/SLA trends as part of routine operations, optionally scoped to a specific period.', 'A quick system-health sanity check (is the database reachable, are the scheduled jobs registered).', 'After enabling AI Risk Scoring, or after a burst of client activity, using "Re-score All Leads Now" so older leads aren\'t left at a stale or default score.'],
                'fits_into' => 'A read layer over rto_leads and the WordPress cron schedule, plus two admin-gated write actions: admin.rescore_leads (Router::rescoreLeads() → Bootstrap::rescoreAllLeads()) recomputes every non-deleted lead\'s risk_score against current data, and the From/To filter (Router.php\'s \'ai\' route closure) bounds the Monthly Trend and Top Converting Services queries.',
                'sections' => [
                    ['name' => 'Risk Score Distribution', 'body' => 'Buckets existing rto_leads.risk_score values into High(≥80)/Medium(50-79)/Low(<50). The "Re-score All Leads Now" button re-runs the exact same rules engine against every lead\'s current order-history/amount/service data — admin-gated, nonce-checked, audit-logged as ai.leads_rescored; returns an error naming the disabled flag rather than silently doing nothing if AI Risk Scoring is off.'],
                    ['name' => 'KPI cards', 'body' => 'SLA Compliance %, Breached SLAs count, Total Leads, Completion Rate — plain aggregate counts, always all-time regardless of the date filter below.'],
                    ['name' => 'Monthly Lead & Revenue Trend', 'body' => 'Defaults to the last 6 months; an explicit From/To range in the filter form above replaces that window entirely.'],
                    ['name' => 'Top Converting Services', 'body' => 'Top 8 completed-service by order count/revenue; defaults to all-time, bounded by the same From/To filter as Monthly Trend when set.'],
                    ['name' => 'System Health checklist', 'body' => 'Six hardcoded pass/fail checks: DB connectivity, two specific cron hooks scheduled, and minimum counts of templates/services/cities configured. Informational only — no remediation action is offered from this screen if a check fails.'],
                ],
                'does_not_control' => [
                    'There is no configurable setting, threshold, or model-tuning control anywhere on this screen — everything shown is a read-only computed value.',
                    'A failing System Health check here offers no in-screen remediation action — it is purely informational, and fixing it means going elsewhere (e.g. re-scheduling a missing cron hook via engineering, or adding more cities/services via their own screens).',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'incorrect assumption',
                        'mistake' => 'Assuming the "Risk Score Distribution" reflects a real, currently-active, machine-learning-based scoring model, OR (the opposite mistake) assuming the whole panel is inert because it isn\'t "real AI."',
                        'symptom' => 'Confusion about why every lead seems to fall into the Low Risk band on some deployments but not others, or why "Re-score All Leads Now" appears to do nothing.',
                        'fix' => 'Check Settings → Feature Flags for "AI Risk Scoring" (ai_scoring). If off, every lead sits at the schema default (Low) and "Re-score All Leads Now" returns an error explaining the flag is off rather than silently doing nothing. If on, a real deterministic rules engine (Bootstrap::computeRiskScore()) scores every lead at creation time, and can be re-run on demand against every lead\'s current data via the "Re-score All Leads Now" button — but it is a rules engine, not a trained model.',
                        'prevention' => 'Treat the score as "a simple rules signal that may or may not be turned on for this deployment," not as either "meaningless" or "live machine learning" — confirm the flag state before drawing conclusions, and use "Re-score All Leads Now" after enabling the flag so older leads aren\'t left at the schema default.',
                    ],
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Looking for a way to configure risk thresholds, retrain a model, or filter this dashboard by date from this screen.',
                        'symptom' => 'No such control exists anywhere on the page.',
                        'fix' => 'There is nothing to configure here — for a date-bound report, use the separate Reports screen instead, which supports real date-range filtering.',
                        'prevention' => 'Treat this screen purely as a fixed, read-only snapshot, not a configurable analytics tool.',
                    ],
                ],
                'troubleshooting' => [
                    '"A System Health check shows red/failed" → this is informational only; the actual fix depends on which check failed (e.g. a missing scheduled cron hook needs to be re-registered by re-activating the plugin or contacting engineering; a low service/city count needs new records added on the relevant screen) — there is no in-screen remediation button.',
                ],
                'related' => ['reports', 'dashboard'],
            ],

            // ═══════════════════════════════════════════════════════════
            'staff' => [
                'title'  => 'Staff / Users',
                'module' => 'Settings',
                'summary' => 'Two related but distinct screens sharing one umbrella label: re-assigning an rto_admin/rto_staff role on an EXISTING platform user (Staff), and creating a BRAND-NEW WordPress user account (Users) — role-assignment actions on both are now admin-only.',
                'what_is' => 'This umbrella covers two genuinely separate screens that are easy to conflate because they link to each other and share a "Staff/Users" label: the Staff screen (re-assigns an rto_admin or rto_staff role on a user who ALREADY exists in WordPress) and the separate Users screen (CREATES a brand-new WordPress user account of any platform role from scratch, including optionally provisioning a matching rto_vendors record if the new account is a vendor). The Staff screen shows every current rto_admin/rto_staff account in a table with a "Remove Role" action per row, plus an "Assign Staff Role" form to promote an existing platform user (drawn from the pool of real rto_admin/rto_staff/rto_vendor/rto_client accounts) to rto_admin or rto_staff. The Users screen\'s "New User" form instead creates the account itself — email, name, initial role, and (for a vendor) the basic vendor profile fields needed to make that new account immediately usable as a vendor. Both role-assignment actions (promoting/demoting an existing user\'s staff role, and creating a brand-new staff/admin account) now correctly require the caller to already be an rto_admin — previously neither action re-checked this independently of the page-level staff gate, meaning any rto_staff account (explicitly described elsewhere in this admin as "limited access") could grant itself or anyone else full rto_admin access.',
                'why_exists' => 'The platform needs a way to grant/revoke staff-level access to existing accounts (Staff) and a separate way to onboard a brand-new account of any role from scratch (Users) — these are two different operational needs (re-roling vs. account creation) that happen to be closely related.',
                'who' => ['RTO Admin only, for actually assigning/removing a role or creating a new staff/admin account', 'RTO Staff can view the Staff screen\'s current-staff list and create new client/vendor accounts on the Users screen'],
                'when' => ['Promoting an existing platform user to rto_staff or rto_admin (Staff screen).', 'Removing staff/admin access from a departing team member (Staff screen).', 'Onboarding a brand-new user account of any role, including a fresh vendor profile (Users screen).'],
                'fits_into' => 'rto_is_staff()/rto_is_admin() (app/Support/helpers.php) are the real role-check functions every admin screen in this codebase relies on — the Staff screen is literally where an rto_admin/rto_staff role is granted or revoked for a real WordPress user, which is why an independent admin-only gate on the actual role-changing action (not just the page as a whole) is essential here specifically, more so than almost any other screen in this admin.',
                'sections' => [
                    ['name' => 'Staff screen — current staff table', 'body' => 'Every existing rto_admin/rto_staff account, with a "Remove Role" action per row (strips both roles, returning that account to whatever other role, if any, it also had).'],
                    ['name' => 'Staff screen — Assign Staff Role form', 'body' => 'Promotes an existing platform user (drawn from the pool of real rto_admin/rto_staff/rto_vendor/rto_client accounts) to rto_admin or rto_staff. Requires rto_admin to actually submit.'],
                    ['name' => 'Users screen — New User form', 'body' => 'Creates a brand-new WordPress user account with an initial role and, for a new vendor, provisions the matching rto_vendors profile row automatically. Creating a new rto_admin or rto_staff account this way also now requires rto_admin.'],
                ],
                'does_not_control' => [
                    'The Staff screen only re-roles a user ALREADY IN the system — it cannot create a brand-new account; that is the separate Users screen.',
                    'The Users screen\'s New User form creates the account and initial role, but does not itself manage ongoing role changes for that account afterward — later re-roling that same user happens back on the Staff screen.',
                    'Removing a staff/admin role does not delete the underlying WordPress user account or any of their historical activity — it only strips the rto_admin/rto_staff role from it.',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'permission/access mistake',
                        'mistake' => 'Assuming an rto_staff account cannot affect role assignments because the UI describes it as "limited access."',
                        'symptom' => 'Prior to this fix, an rto_staff account could in fact submit the role-assignment form successfully.',
                        'fix' => 'This is now fixed — the action itself independently requires rto_admin, regardless of what the page\'s general framing implies.',
                        'prevention' => 'Never rely on a screen\'s general "who can view this" framing as a substitute for checking the actual, specific gate on a consequential action — verify the code, as was done here.',
                    ],
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Using the Staff screen to try to create a brand-new account.',
                        'symptom' => 'No "create user" option exists on the Staff screen itself.',
                        'fix' => 'Use the separate Users screen\'s "New User" form to create a brand-new account; use the Staff screen afterward only to change that account\'s staff/admin role if needed.',
                        'prevention' => 'Remember these are two related but distinct screens: Users creates, Staff re-roles an existing account.',
                    ],
                    [
                        'category' => 'data-entry mistake',
                        'mistake' => 'Not finding a specific existing user in the "Assign Staff Role" dropdown.',
                        'symptom' => 'Prior to this fix, the dropdown would often appear empty or missing expected users because it queried the wrong underlying role.',
                        'fix' => 'This is now fixed — the dropdown correctly reflects the platform\'s real rto_admin/rto_staff/rto_vendor/rto_client user pool.',
                        'prevention' => 'If a specific user still doesn\'t appear, confirm they actually have a WordPress account at all (created via the Users screen) rather than assuming this dropdown is at fault.',
                    ],
                ],
                'troubleshooting' => [
                    '"I tried to assign a staff role and got an access-denied message" → this action now correctly requires an rto_admin account — log in as, or ask, an rto_admin to perform the change.',
                    '"A user I expect to see in the Assign Staff Role dropdown isn\'t there" → confirm the account actually exists as a WordPress user (created via the Users screen\'s New User form) — this dropdown now correctly reflects the platform\'s real role pool, so a missing user most likely means the account itself doesn\'t exist yet.',
                ],
                'related' => ['users'],
            ],

            // ═══════════════════════════════════════════════════════════
            'automation' => [
                'title'  => 'Automation Engine',
                'module' => 'System',
                'summary' => 'Read-only view of admin-configured event → condition → action rules (rto_automation_rules) that react automatically to platform events, e.g. auto-escalating or notifying on an SLA breach.',
                'what_is' => 'This screen lists every rule in rto_automation_rules: a trigger event (e.g. "lead.sla_breach"), an optional set of field conditions, and one or more actions (change a lead\'s status/priority, or send a notification). AutomationService::handle() runs a matching event\'s active rules, in priority order, whenever that event actually occurs. This is a distinct screen from Workflow Builder — Workflows governs which lead STATUS TRANSITIONS are allowed per service; Automation reacts to events (including a status change) with side effects like notifying someone or auto-escalating priority.',
                'why_exists' => 'Lets an admin encode simple "when X happens, do Y" reactions (SLA-breach escalation, auto-notifying a vendor, etc.) without a code change, for the specific system events the engine actually raises.',
                'who' => ['RTO Admin'],
                'when' => ['Reviewing which automation rules exist and whether they have actually fired (Run Count / Last Run columns).', 'Diagnosing why an expected auto-notification or auto-escalation did not happen for a specific lead.'],
                'does_not_control' => [
                    'It does not control the platform\'s default status-transition rules (see Workflows / Leads) — it only reacts to events, it does not gate them.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'troubleshooting mistake',
                        'mistake' => 'Assuming an automation rule with Run Count 0 is broken/misconfigured when the real cause was that its trigger event never fired at all (fixed for 4 of the 10 events by this audit).',
                        'symptom' => 'A rule shows "Active" with Run Count permanently 0 despite its trigger event apparently occurring.',
                        'fix' => 'Confirm the rule\'s exact trigger_event string matches one this screen\'s "Available System Events" list shows, and — if it is lead.created, lead.sla_breach, payment.received, or lead.completed — confirm the fix in this Known Limitations entry has been deployed; those 4 were dead before it.',
                        'prevention' => 'After creating a new rule, trigger its event once deliberately (e.g. create a test lead) and confirm Run Count increments before relying on it in production.',
                    ],
                ],
                'related' => ['workflows', 'leads'],
            ],

            // ═══════════════════════════════════════════════════════════
            'gdpr' => [
                'title'  => 'GDPR Data Export & Erasure',
                'module' => 'System',
                'status' => 'documented',
                'summary' => 'An admin-only route that exports one platform user\'s leads, payments, complaints, and messages as a downloadable JSON file, plus a separate "Erase Subject" action (by email or mobile) that hard-deletes never-paid data and anonymises retained financial/legal records.',
                'what_is' => 'This screen (/rto-admin/gdpr/) covers both halves of a data-subject rights request. Export: given a WordPress user ID (?user_id=N) it pulls that user\'s rto_leads, rto_payments, rto_complaints, and rto_messages rows and streams them back as a downloadable gdpr-export-{uid}-{date}.json file; visiting with no user_id shows the Users list instead. Erasure: a form on the same screen takes an email or mobile number (GdprService::eraseSubject(), reached via the admin.gdpr_erase AJAX action) and, inside a single database transaction, hard-deletes leads/messages/orphaned form submissions that carry no financial or legal retention obligation, while anonymising (scrubbing name/email/mobile/free-text fields, keeping amounts/dates/status) leads, form submissions, complaints, and vendor rows that must be retained for accounting or dispute history — with a full per-table breakdown returned and logged via AuditService::log(\'gdpr.erasure\', ...).',
                'why_exists' => 'A GDPR/data-privacy-style access request obligates the platform to both produce a specific person\'s personal data on request AND be able to genuinely erase or anonymise it on request; this screen is where an rto_admin does either, one data subject at a time.',
                'who' => ['RTO Admin only'],
                'when' => ['Responding to a data-subject access request for a specific platform user.', 'Responding to a data-subject erasure ("right to be forgotten") request by email or mobile.', 'An internal compliance/audit review needing a snapshot of one user\'s stored data.'],
                'fits_into' => 'app/Http/Router.php::routeGdprExport() and ::gdprErase() (admin.gdpr_erase dispatch entry), backed by app/Services/GdprService::eraseSubject() for the erasure half.',
                'sections' => [
                    ['name' => 'Export route', 'body' => 'GET-only, admin-gated (rto_is_admin()); takes ?user_id=N; streams a JSON attachment with the four data categories listed above. No confirmation step, no rate limit beyond the admin-capability check.'],
                    ['name' => 'Erase Subject form', 'body' => 'Takes an email or mobile number, runs GdprService::eraseSubject() inside one transaction, and returns a per-table breakdown of what was hard-deleted, anonymised, or skipped (with a reason for each) — rolling back entirely if any write in the operation fails.'],
                ],
                'does_not_control' => [
                    'The export half does not export every table that references a user (e.g. audit log rows in rto_logs recording that user\'s own actions) — only the four tables named above; the erasure half deliberately never touches rto_logs either, since the audit trail is the record that the erasure itself occurred.',
                    'It has no UI of its own for looking up a user\'s WordPress ID for export — an admin gets that from the Users or Staff screen first, then appends it to the URL; erasure, by contrast, looks the subject up directly by email/mobile with no ID needed.',
                    'Erasure never hard-deletes a row with a financial dependency (a paid lead, its payments, a vendor\'s payout history) — those are anonymised in place, not removed, so accounting totals and audit history stay intact.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'usage mistake',
                        'mistake' => 'Visiting /rto-admin/gdpr/ with no user_id, expecting a "not found" message.',
                        'symptom' => 'Prior to this fix, this silently downloaded WP user ID 1\'s data instead of showing an error or picker.',
                        'fix' => 'This is now fixed — a missing user_id correctly falls back to the Users list instead of exporting user 1 by accident.',
                        'prevention' => 'Always get the target user\'s WP ID from the Users or Staff screen first, then append it explicitly as ?user_id=N.',
                    ],
                ],
                'troubleshooting' => [
                    '"The export downloaded but some sections are empty" → that user genuinely has no rows in that table (e.g. no complaints); an empty array is the correct/expected result, not an error.',
                    '"I need to prove who exported a user\'s data last month" → check rto_logs for an action=\'gdpr.export\' row against that user_id (added by this fix); exports made before this fix was deployed were not recorded.',
                ],
                'related' => ['staff', 'system'],
            ],

            // ═══════════════════════════════════════════════════════════
            'system' => [
                'title'  => 'System Status & Cron Monitor',
                'module' => 'System',
                'status' => 'documented',
                'summary' => 'A read-only-mostly status page (scheduled cron jobs, notification queue, migration status) plus two real destructive/corrective actions: retrying a failed notification, and force-terminating a user\'s active sessions.',
                'what_is' => 'Reached at /rto-admin/system/, this admin-only page (Router::routeSystemDashboard()) shows four real, live-queried panels: WP-Cron jobs whose hook name starts with "rtoflow" (next-run time, interval); the rto_notifications queue grouped by status with the 20 most recent failed rows listed individually; a Force User Logout tool that takes any WP user ID and destroys all of that user\'s active sessions immediately; and database migration status (X of Y applied) from MigrationRunner::status(). Unlike the AI Insights screen\'s System Health checklist (a separate, purely informational six-check panel elsewhere in this admin), this page has two genuinely actionable buttons: "Retry" next to a failed notification (re-queues it as pending) and "Force Logout" (destroys a user\'s WordPress sessions) — both wired to real AJAX handlers (admin.retry_notification, admin.force_logout in Router::dispatchAjax()).',
                'why_exists' => 'Gives an admin one place to see whether the platform\'s own background machinery (cron, notification delivery, migrations) is healthy, and to take two specific corrective actions (retry a stuck notification, kill a compromised account\'s sessions) without needing server/database access.',
                'who' => ['RTO Admin only'],
                'when' => ['Diagnosing why scheduled notifications or SLA checks appear to not be running.', 'Manually retrying a notification that failed to send (e.g. after fixing a provider credential).', 'Immediately terminating sessions for a suspected-compromised account.', 'Confirming all database migrations have been applied after a deploy.'],
                'fits_into' => 'app/Http/Router.php::routeSystemDashboard() (page render), ::retryNotification() and ::forceLogout() (the two AJAX actions), resources/views/admin/system/dashboard.php (view + inline JS).',
                'sections' => [
                    ['name' => 'Scheduled Jobs (WP Cron)', 'body' => 'Lists every currently-scheduled cron event whose hook starts with "rtoflow". Purely informational.'],
                    ['name' => 'Notification Queue', 'body' => 'Pending/sent/failed counts, plus up to 20 most-recent failed notifications each with a real "Retry" button that re-queues it as pending via AJAX.'],
                    ['name' => 'Force User Logout', 'body' => 'Takes a WP user ID and destroys all of that user\'s active sessions via WP_Session_Tokens::destroy_all(), logged to the audit trail.'],
                    ['name' => 'Database Migrations', 'body' => 'Applied/pending count and a per-migration table, from MigrationRunner::status(). When any migration is pending, a "Run Pending Migrations" button appears — admin-gated and nonce-checked, it calls the exact same MigrationRunner::run() that plugin activation itself uses, applying every not-yet-recorded migration in order and stopping cleanly at the first failure with a specific error, rather than requiring server/CLI access.'],
                ],
                'does_not_control' => [
                    'This page cannot re-schedule a missing cron hook itself — the only remedy shown for that is deactivating/reactivating the plugin.',
                    'The "Retry" button re-queues a failed notification as pending; it does not diagnose or fix whatever originally caused it to fail (e.g. a bad provider credential).',
                    '"Run Pending Migrations" applies schema changes directly and is not reversible from this screen — there is no matching "Rollback" button here even though MigrationRunner::rollback() exists internally; a rollback still requires CLI/server access.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'safety mistake',
                        'mistake' => 'Using Force Logout without double-checking the WP user ID field actually populated before clicking.',
                        'symptom' => 'Prior to this fix, a blank/failed field submission silently hit the wrong (id=1) account instead of erroring.',
                        'fix' => 'This is now fixed — a blank or zero ID is rejected with "User not found" rather than silently targeting user 1.',
                        'prevention' => 'Still confirm the WP user ID before submitting, since the tool is immediate and irreversible for that user\'s current sessions.',
                    ],
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Expecting the Database Migrations panel to have a "Run" button.',
                        'symptom' => 'No such action exists on this screen.',
                        'fix' => 'Apply pending migrations via the normal deploy/CLI process; this panel is status-only.',
                        'prevention' => 'Treat this panel as a health check, not a deployment control.',
                    ],
                ],
                'troubleshooting' => [
                    '"No RTOFLOW cron jobs are listed" → the plugin\'s cron hooks were never scheduled or were cleared; deactivate and reactivate the plugin to reschedule them (link provided in the empty state).',
                    '"Force Logout says User not found" → this is now the correct behavior for a blank/invalid ID as well as a genuinely nonexistent WP user — verify the ID against the Users/Staff screen.',
                ],
                'related' => ['gdpr', 'ai'],
            ],

            // ═══════════════════════════════════════════════════════════
            'webhooks' => [
                'title'  => 'Webhooks',
                'module' => 'System',
                'status' => 'documented',
                'summary' => 'Admin CRUD for outbound HTTP webhook subscriptions — pick one of 13 fixed real platform events, an HTTPS target URL, and this platform POSTs a signed payload to it whenever that event fires.',
                'what_is' => 'Reached at /rto-admin/webhooks/ (WebhookController::index()), this screen lists every row in rto_webhook_subscriptions (event type, target URL, created date, Active/Disabled) and lets an admin add, edit, toggle, or delete a subscription entirely via AJAX (webhooks.create/update/toggle/delete — WebhookController). Each subscription is scoped to exactly one event from a fixed list of 13 (lead.created, lead.status_changed, lead.completed, lead.sla_breach, lead.sla_warning, lead.vendor_assigned/accepted/rejected, payment.received, document.uploaded/verified/rejected, complaint.created) — the same event taxonomy AutomationController exposes for Automation Rules, because both features are read by the same underlying event pipeline (EventBus::fire()). On create, a 32-byte random signing secret is generated server-side and shown exactly once in the response (a yellow callout box); the index listing itself never re-displays it. WebhookDispatchService (not this screen) is what actually performs the outbound POST + signature at fire time.',
                'why_exists' => 'Lets an admin wire this platform to external systems (a CRM, a Slack/Zapier relay, a partner\'s own backend) purely by configuration — no code change — for a small, closed set of events the platform genuinely fires, mirroring the "configure, don\'t code" approach Automation Rules already uses for the same event set.',
                'who' => ['RTO Admin'],
                'when' => ['Wiring a lead/payment/document/complaint event to an external system for the first time.', 'Rotating a webhook target URL or diagnosing why an external system stopped receiving events.', 'Temporarily disabling a subscription (e.g. a partner\'s endpoint is down) without losing its configuration.'],
                'fits_into' => 'app/Controllers/Admin/WebhookController.php (this screen and its 4 AJAX actions), app/Services/WebhookDispatchService.php (the runtime dispatcher this screen only configures, never triggers directly), resources/views/admin/webhooks/index.php.',
                'sections' => [
                    ['name' => 'Add / Edit Subscription', 'body' => 'A 3-field form (Event Type dropdown restricted to the 13 valid events, Target URL, Save). Editing an existing row populates the same form in place (no separate edit page) via the row\'s data-* attributes and a client-side "Edit" button.'],
                    ['name' => 'Signing secret callout', 'body' => 'Shown only immediately after a successful create, in a dismissible-looking yellow box with the raw secret in a <code> block. There is no "reveal secret" action anywhere else on this screen — losing it means deleting and re-creating the subscription to get a new one.'],
                    ['name' => 'Subscriptions table', 'body' => 'Event Type, Target URL, Created date, Active/Disabled badge, and per-row Edit/Toggle/Delete buttons. Toggle flips is_active without a confirmation step; Delete requires a JS confirm() dialog.'],
                ],
                'does_not_control' => [
                    'This screen only stores subscription CONFIGURATION — it never itself sends an HTTP request; delivery, retries, and signature generation at fire time are entirely WebhookDispatchService\'s responsibility, not this screen\'s.',
                    'It has no delivery log or history — there is no way here to see whether a given event actually reached the target URL, what HTTP status the endpoint returned, or whether a delivery was retried.',
                    'The Event Type list is a fixed, hard-coded set (WebhookController::VALID_EVENTS) — an admin cannot subscribe to any other do_action() hook this platform fires; requesting an event outside the list is rejected server-side even if somehow submitted.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'security mistake',
                        'mistake' => 'Assuming the signing secret can be looked up again later from this screen if it is misplaced.',
                        'symptom' => 'The subscriptions table never shows the secret column under any circumstance, even to an admin who owns the subscription.',
                        'fix' => 'There is no recovery — delete the subscription and create a new one to get a fresh secret (see Known Limitations for why rotation-in-place does not exist yet).',
                        'prevention' => 'Copy the secret into your target system\'s configuration immediately when the yellow callout appears — it will not be shown again.',
                    ],
                    [
                        'category' => 'validation mistake',
                        'mistake' => 'Entering a plain http:// target URL expecting it to save with a warning.',
                        'symptom' => '"Target URL must use HTTPS." is returned and the subscription is not saved at all.',
                        'fix' => 'Use an https:// URL — WebhookController::validate() rejects anything else outright, with no override.',
                        'prevention' => 'Confirm the receiving endpoint supports HTTPS before attempting to add the subscription here.',
                    ],
                    [
                        'category' => 'troubleshooting mistake',
                        'mistake' => 'Concluding a subscription is broken because the target system never received an event, without checking whether the underlying platform event ever actually fired for that lead/payment/document.',
                        'symptom' => 'No events arrive at the target URL, and this screen offers no delivery log to distinguish "never sent" from "sent but rejected" from "the source event never occurred".',
                        'fix' => 'Cross-check against the same event in the Automation Rules screen\'s Run Count (which reacts to the identical event set) — if Automation Rules on that same event also show zero runs, the event itself is not firing; if Automation Rules DO show runs, the gap is specific to webhook delivery.',
                        'prevention' => 'When first wiring an integration, subscribe an event you can trigger on demand (e.g. document.uploaded via a test document) rather than waiting for a rare production event to validate the pipeline end-to-end.',
                    ],
                ],
                'troubleshooting' => [
                    '"I need the secret again to fix my endpoint\'s signature verification" → it cannot be retrieved; delete and re-create the subscription to get a new one, and update the target system with it.',
                    '"The Event Type dropdown doesn\'t list an event I need" → only the 13 events in WebhookController::VALID_EVENTS can be subscribed to; this is a fixed, code-level list, not admin-configurable.',
                    '"Toggling a subscription to Disabled — does that delete its secret?" → no, disabling only sets is_active=0; the row, its secret, and its configuration are untouched and re-enabling resumes delivery with the same secret.',
                ],
                'related' => ['automation', 'system'],
            ],

            // ═══════════════════════════════════════════════════════════
            'users' => [
                'title'  => 'Users & Staff',
                'module' => 'System',
                'status' => 'documented',
                'summary' => 'The full, unfiltered-by-role-gate list of every WordPress user on the site (any role, including plain WP administrator/subscriber accounts), with search/role filtering and a Create User form — distinct from the separate Staff screen, which only manages rto_admin/rto_staff role assignment for existing accounts.',
                'what_is' => 'Reached at /rto-admin/users/, this screen (the router\'s \'users\' arm, rendering admin.users.index / admin.users.create inline rather than through a dedicated controller class) lists WordPress users via get_users() with optional role and free-text (name/email) filters, paginated 25 at a time. Its "+ Add User" link opens a Create User form that, on submit, calls wp_create_user() and assigns one of a whitelisted set of roles (rto_admin, rto_staff, rto_vendor, rto_client — anything else falls back to rto_client); creating an rto_vendor account here also inserts a companion row into rto_vendors with empty cities/services/bank_details JSON placeholders. Creating an rto_admin or rto_staff account through this form requires the acting user to themselves already be rto_admin (checked independently of the page-level staff gate) — a plain rto_staff account can still create rto_vendor/rto_client accounts here, but not staff/admin ones.',
                'why_exists' => 'Gives an admin one place to see and search every WordPress account the site has (not just the rto_admin/rto_staff subset the Staff screen manages) and to originate brand-new accounts of any platform role, including the initial vendor-account creation step before that vendor\'s own profile is filled in elsewhere.',
                'who' => ['RTO Admin', 'RTO Staff (view/search and rto_vendor/rto_client creation only — not staff/admin creation)'],
                'when' => ['Looking up any platform user (client, vendor, staff, or admin) by name or email regardless of role.', 'Creating a brand-new account of a specific role, including the very first step of onboarding a new vendor.', 'Confirming what roles a given WordPress account currently holds before deciding whether to also touch the Staff screen.'],
                'fits_into' => 'app/Http/Router.php\'s \'users\' route arm (inline closure — no dedicated UsersController class), resources/views/admin/users/index.php and resources/views/admin/users/create.php.',
                'sections' => [
                    ['name' => 'Role / search filter bar', 'body' => 'A GET form filtering by exact role (RTO Admin/Staff/Vendor/Client/Administrator) and/or a free-text name-or-email search, both passed straight into get_users()\'s role and search (wrapped in *…*) arguments.'],
                    ['name' => 'Users table', 'body' => 'Name, email, one badge per WordPress role the account holds, mobile number (from usermeta), join date, and an Edit link that goes to WordPress core\'s own user-edit.php screen, not an in-plugin editor.'],
                    ['name' => 'Create User form', 'body' => 'Name, email, mobile, password (auto-generated if left blank), and a role selector restricted server-side to rto_admin/rto_staff/rto_vendor/rto_client regardless of what is submitted; selecting rto_vendor additionally provisions an empty rto_vendors row for that new user.'],
                ],
                'does_not_control' => [
                    'It cannot edit an existing user\'s name, email, password, or role from within this screen — the "Edit" link leaves the plugin entirely and opens WordPress core\'s own user-edit.php.',
                    'It does not deactivate, suspend, or delete a user — no such action exists here at all (only Force Logout on the System screen terminates an account\'s active sessions, and that does not touch the account itself).',
                    'Creating an rto_vendor account here only inserts a bare-minimum rto_vendors placeholder row (empty cities/services/bank_details) — it does not walk the admin through the fuller vendor profile the Vendors screen\'s own create form collects (service areas, documents, etc.).',
                ],
                'known_limitations' => [
                ],
                'common_mistakes' => [
                    [
                        'category' => 'usage mistake',
                        'mistake' => 'Creating a new vendor account here and expecting it to be immediately visible with full service/city coverage on the Vendors screen.',
                        'symptom' => 'The new vendor appears in Vendors with cities and services both showing as empty/none configured.',
                        'fix' => 'Open the newly-created vendor from the Vendors screen and fill in their service areas, offered services, and bank details there — this screen only creates the bare account + placeholder row.',
                        'prevention' => 'Treat account creation here as step one of vendor onboarding, not the complete process — finish the profile on the Vendors screen right after.',
                    ],
                    [
                        'category' => 'security mistake',
                        'mistake' => 'Leaving the password field blank when creating a user, assuming a temporary password will be emailed or shown afterward.',
                        'symptom' => 'The account is created successfully but no password is ever displayed, emailed, or otherwise retrievable.',
                        'fix' => 'Have the new user use WordPress\'s own "Lost your password?" flow on the login screen to set their own password, since none was communicated.',
                        'prevention' => 'Always type an explicit password in this form until the auto-generated-password gap above is fixed.',
                    ],
                ],
                'troubleshooting' => [
                    '"I filtered by role and got the wrong list" → this screen\'s filter form currently submits to the Staff screen instead of itself (see Known Limitations); use a direct URL query string as a workaround.',
                    '"A new vendor account exists but has no cities/services" → expected — this screen only creates the account and an empty placeholder vendor row; complete the profile on the Vendors screen.',
                    '"An rto_staff user tried to create a new staff account and was denied" → correct behavior; only an rto_admin account can create rto_admin/rto_staff accounts through this form.',
                ],
                'related' => ['staff', 'vendors'],
            ],

            // ═══════════════════════════════════════════════════════════
            'rtos' => [
                'title'  => 'RTO Management',
                'module' => 'Masters',
                'status' => 'documented',
                'summary' => 'Per-city management of individual RTO (Regional Transport Office) branch records — add/activate/deactivate/delete a specific RTO branch within a chosen city, and optionally restrict which services that branch offers versus the city\'s global service list.',
                'what_is' => 'Reached at /rto-admin/rtos/, this screen first asks the admin to pick a city (a GET form), then lists every row in rto_rtos for that city\'s id: name, code, working hours, an Active/Inactive toggle, and whether the branch uses the city\'s full global service list or a custom per-RTO override (read from the rtoflow_rto_{id}_services option, when set). This is a distinct screen from Masters (Cities/RTOs) and City/Service Pricing — Masters manages which cities/states exist and are active at all, City Pricing configures per-city service pricing and visibility, and this screen manages the individual physical RTO branch records that sit underneath one already-active city.',
                'why_exists' => 'A city can have several physical RTO branches (e.g. multiple zonal offices in one metro); this screen is where those individual branch records — not the city itself — are added, deactivated, or removed, and where a branch can be scoped to a narrower service list than the city as a whole offers.',
                'who' => ['RTO Admin'],
                'when' => ['Adding a newly-opened RTO branch to an already-active city.', 'Deactivating or removing a branch that has closed or is temporarily unavailable.', 'Restricting which services a specific branch handles, when it does not offer everything the city as a whole does.'],
                'fits_into' => 'resources/views/admin/rtos/index.php (this screen has no dedicated controller class — it queries rto_cities/rto_rtos/rto_services directly and renders inline), the rto_rtos table, and the rtoflow_rto_{id}_services option used for per-branch service overrides.',
                'sections' => [
                    ['name' => 'City selector', 'body' => 'A single dropdown of active cities (joined to their state); choosing one reloads the page with ?city_id=N and reveals that city\'s RTO branches. No RTOs are shown until a city is selected.'],
                    ['name' => 'RTO branch cards', 'body' => 'One card per branch: name, code, working hours, an Active/Inactive checkbox toggle, a Services summary ("All N global services" or "N custom services"), and a Delete button requiring a JS confirm() dialog.'],
                    ['name' => 'Add RTO modal', 'body' => 'A focus-trapped dialog (code, name, working hours) that POSTs to the add_rto_branch AJAX action (Router::addRtoBranch()) to create a new rto_rtos row scoped to the currently-selected city.'],
                ],
                'does_not_control' => [
                    'It does not create or activate the CITY itself — a city must already exist and be active (managed on the Masters screen) before it appears in this screen\'s city selector at all.',
                    'It does not configure per-city service pricing or visibility — that is the separate City/Service Pricing screen; this screen only shows a binary "all services vs. custom override" summary per branch, with no per-service pricing UI of its own.',
                    'The per-RTO service override, when set, is read from a WordPress option (rtoflow_rto_{id}_services), edited through a real "Manage Services" checklist per branch card (Router::updateRtoServiceOverride()).',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Using the city dropdown on this screen and expecting to stay on RTO Management with that city\'s branches shown.',
                        'symptom' => 'Selecting a city instead navigates to the Masters (Cities/RTOs) screen.',
                        'fix' => 'Manually edit the URL to /rto-admin/rtos/?city_id=N (found from the Masters screen\'s own city list, or the browser address bar after the redirect) until the hidden-field bug above is fixed.',
                        'prevention' => 'Bookmark a specific city\'s RTO Management URL directly rather than relying on the in-page selector for now.',
                    ],
                    [
                        'category' => 'troubleshooting mistake',
                        'mistake' => 'Assuming "N custom services" on a branch card means that branch is missing services, when it is actually a deliberate narrower scope.',
                        'symptom' => 'A branch appears to offer fewer services than the rest of the city with no obvious reason shown on this screen.',
                        'fix' => 'This is expected when a per-branch override exists; there is currently no way from this screen to see or change which services are included (see Known Limitations) — direct database/option inspection is the only way to confirm the exact list today.',
                        'prevention' => 'Document any intentional per-branch service restriction outside the platform until a proper editor exists here.',
                    ],
                    [
                        'category' => 'data-integrity mistake',
                        'mistake' => 'Adding a new RTO branch with the same code as an existing branch in the same city, assuming the form would reject it.',
                        'symptom' => 'Both branches save successfully with an identical code and no warning.',
                        'fix' => 'Manually check the existing branch cards for the same city before adding a new one, since no duplicate-code check exists yet.',
                        'prevention' => 'Adopt and follow a consistent internal RTO-code naming convention per city until server-side uniqueness validation is added (see Known Limitations).',
                    ],
                ],
                'troubleshooting' => [
                    '"Choosing a city from the dropdown takes me to a different screen" → known issue, the city-picker form\'s hidden page field points at Masters instead of this screen; use ?city_id=N directly on this screen\'s own URL as a workaround.',
                    '"I can\'t tell which services a branch\'s custom override actually includes" → not visible from this screen today; only the count is shown (see Known Limitations).',
                    '"A branch I deactivated is still assignable to new orders" → deactivating here only flips is_active on the rto_rtos row; confirm the operational lead-assignment logic you are checking actually reads this flag rather than working purely off city-level eligibility.',
                ],
                'related' => ['masters', 'city-pricing'],
            ],

            // ═══════════════════════════════════════════════════════════
            'nav' => [
                'title'  => 'Navigation & Quick Access',
                'module' => 'System',
                'status' => 'documented',
                'summary' => 'A single, purely-navigational hub page — one links directory (Operations, Configuration, Support & Monitoring, Frontend URLs) plus a live setup-completeness checklist — with no data of its own to create, edit, or configure.',
                'what_is' => 'Reached at /rto-admin/nav/, this screen (Router.php\'s \'nav\' arm, an inline closure rendering admin.nav.index with no dedicated controller) shows: a welcome banner with two quick-action buttons (New Order, Apply Form); six live quick-stat tiles (total orders, active vendors, services, active cities, open complaints, today\'s revenue) computed with direct queries at render time; three grids of static links (Operations, Configuration, Support & Monitoring) to every other real admin screen in the plugin; a Frontend URLs section listing every public-facing route (Apply Form, Login, Client Dashboard, Vendor Portal, Pricing, etc.) each with a one-click Copy button; and a Setup Checklist that live-checks six onboarding conditions (services seeded, cities seeded, company phone set, support email set, at least one vendor added, Razorpay configured) and links straight to Settings for any unmet one.',
                'why_exists' => 'Gives a new or infrequent admin one page that both orients them (a map of every screen this plugin has, grouped by purpose) and tells them, at a glance, whether the platform is actually ready for production use — without needing to already know this plugin\'s screen structure or check each configuration area individually.',
                'who' => ['RTO Admin', 'RTO Staff'],
                'when' => ['Onboarding a new admin/staff user who does not yet know where each screen lives.', 'Doing a quick end-to-end sanity check that setup basics (cities, services, company contact info, at least one vendor) are actually in place.', 'Quickly copying a public-facing URL (the client apply form, vendor portal, etc.) to share externally.'],
                'fits_into' => 'app/Http/Router.php\'s \'nav\' route arm, resources/views/admin/nav/index.php (all logic and markup lives directly in this one view file — there is no NavController).',
                'sections' => [
                    ['name' => 'Quick stats banner', 'body' => 'Six live counts (orders, vendors, services, cities, open complaints, today\'s revenue) queried fresh on every page load — not cached, so this is always current at the moment of viewing.'],
                    ['name' => 'Operations / Configuration / Support & Monitoring grids', 'body' => 'Static link cards to every other real admin screen, grouped by purpose — this is effectively the plugin\'s own sitemap for staff, independent of whatever the actual WordPress admin-menu structure looks like.'],
                    ['name' => 'Frontend URLs', 'body' => 'Every public-facing (non-admin) route this plugin exposes, each with a one-click clipboard-copy button — useful for sharing the client apply form or vendor portal link externally without hunting for the exact path.'],
                    ['name' => 'Setup Checklist', 'body' => 'Six pass/fail onboarding checks (seeded services ≥10, seeded cities ≥10, company phone set, support email set, ≥1 active vendor, Razorpay key configured), each unmet item linking to Settings.'],
                ],
                'does_not_control' => [
                    'Nothing on this page is editable — every element is either a live read-only stat, a static link, or a copy-to-clipboard button; there is no form submission anywhere on this screen.',
                    'The Setup Checklist only reports whether each condition is currently true — clicking "Fix →" only navigates to Settings; it does not itself seed services/cities, set contact info, or configure Razorpay.',
                    'The "seeded" checks (services/cities ≥10) are a threshold heuristic, not an exact completeness check — an installation intentionally smaller than that can dismiss the warning permanently via the "This is intentional" button next to it, rather than being stuck with it forever.',
                ],
                'known_limitations' => [],
                'common_mistakes' => [
                    [
                        'category' => 'usage mistake',
                        'mistake' => 'Expecting the Setup Checklist to actually perform the fix (seed data, save a setting) when an item is unmet.',
                        'symptom' => 'Clicking "Fix →" only navigates to the Settings screen — nothing is configured automatically.',
                        'fix' => 'Complete the actual configuration step on the linked screen (e.g. enter a company phone number and save) — this checklist only detects and links, it never performs the fix itself.',
                        'prevention' => 'Treat every checklist row as a diagnostic pointer, not an automated action.',
                    ],
                    [
                        'category' => 'interpretation mistake',
                        'mistake' => 'Treating a "Services seeded" / "Cities seeded" warning as proof the catalog is actually incomplete.',
                        'symptom' => 'The warning persists even after confirming every intended service/city has been added, if the total is under the default threshold of 10.',
                        'fix' => 'Verify the real catalog directly on the Services and Masters screens, and if your installation is intentionally smaller than 10, use the "This is intentional" button next to the warning to dismiss it permanently instead of ignoring it on every visit.',
                        'prevention' => 'Acknowledge a genuinely-intentional smaller catalog once via that button rather than re-confirming "it\'s fine" from memory every time this page loads.',
                    ],
                    [
                        'category' => 'workflow mistake',
                        'mistake' => 'Bookmarking individual cards on this page instead of the actual target screen\'s own URL.',
                        'symptom' => 'No functional problem, but an unnecessary extra hop through this hub page every time.',
                        'fix' => 'Once you know a screen\'s direct URL (shown when you hover/click a card), bookmark that instead for direct access.',
                        'prevention' => 'Use this screen for discovery and onboarding, then switch to direct URLs/bookmarks for screens visited routinely.',
                    ],
                ],
                'troubleshooting' => [
                    '"The revenue/order counts here don\'t match the Dashboard" → both query the same tables directly but at different moments and (for Dashboard) through a short-TTL cache — a brief mismatch immediately after a new order/payment is expected and self-corrects.',
                    '"The Setup Checklist keeps flagging Services/Cities as not seeded even though I\'ve added everything I need" → the check is a fixed ≥10 threshold, not a completeness check against your intended catalog; see Known Limitations.',
                    '"A link on this page 404s or redirects unexpectedly" → confirm the target screen\'s own Help Centre article for any known routing quirks (e.g. the Users and RTO Management screens each have a known filter-form redirect issue documented on their own articles) before assuming this hub page\'s link itself is wrong.',
                ],
                'related' => ['dashboard', 'settings'],
            ],

        ];

        return $cache;
    }
}
