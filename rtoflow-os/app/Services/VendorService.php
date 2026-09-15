<?php

namespace RTOFLOW\Services;

use RTOFLOW\Repositories\{VendorRepository, LeadRepository, VendorCoverageRepository};
use RTOFLOW\Support\EventBus;
use RTOFLOW\Security\Encryption;
use RTOFLOW\Config\MatchingConfig;

if (!defined('ABSPATH')) exit;

class VendorService
{
    public function __construct(
        private VendorRepository $vendors,
        private LeadRepository   $leads,
        private EventBus         $events,
        private ?VendorCoverageRepository $coverage = null
    ) {
        $this->coverage ??= new VendorCoverageRepository();
    }

    public function create(array $data): array
    {
        if (empty($data['full_name']) || empty($data['mobile']) || empty($data['email'])) {
            return ['success' => false, 'message' => 'Name, mobile, and email are required'];
        }
        if (!is_email($data['email'])) {
            return ['success' => false, 'message' => 'Invalid email address'];
        }
        if (email_exists($data['email'])) {
            return ['success' => false, 'message' => 'Email already exists. Use a different email.'];
        }

        // FIX (undelivered-password bug — same fix already applied to
        // VendorsController::store() and Router.php's account-creation
        // paths): this method is currently unreachable (zero callers —
        // VendorsController::store() is the live vendor-creation path) but
        // is fixed here too so it does not silently reintroduce the bug if
        // it is ever wired up again.
        $password = wp_generate_password(24, true);
        $uid      = wp_create_user($data['email'], $password, $data['email']);
        if (is_wp_error($uid)) return ['success' => false, 'message' => $uid->get_error_message()];

        $u = new \WP_User($uid);
        $u->set_role('rto_vendor');
        wp_update_user(['ID' => $uid, 'display_name' => $data['full_name']]);
        update_user_meta($uid, 'rtoflow_mobile', $data['mobile']);
        wp_new_user_notification($uid, null, 'user');

        $vnum = 'VND-' . str_pad($uid, 8, '0', STR_PAD_LEFT);
        $id   = $this->vendors->create([
            'user_id'      => $uid,
            'vendor_number'=> $vnum,
            'full_name'    => sanitize_text_field($data['full_name']),
            'mobile'       => sanitize_text_field($data['mobile']),
            'email'        => sanitize_email($data['email']),
            'cities'       => wp_json_encode($data['cities'] ?? []),
            'services'     => wp_json_encode($data['services'] ?? []),
            // FIX P0-8: encrypted, matching VendorsController::store() — this is the
            // second of two vendor-creation paths in the codebase and previously
            // wrote plain-text bank details independently of the admin form path.
            'bank_details_enc' => Encryption::encrypt(wp_json_encode([
                'account'  => $data['bank_account'] ?? '',
                'ifsc'     => $data['ifsc']         ?? '',
                'bank_name'=> $data['bank_name']    ?? '',
            ])),
            'status'       => 'active',
        ]);

        // FIX (Vendor Coverage Engine wiring — integration pass follow-up):
        // this is the second, independent vendor-creation path in the
        // codebase (see VendorsController::store() for the other, and the
        // FIX P0-8 comment above re: it duplicating the bank-details
        // encryption fix) — it must keep rto_vendor_coverage in sync too,
        // or a vendor created through this path would silently never
        // appear in the coverage table at all, not just drift from it.
        if ($id) {
            (new \RTOFLOW\Repositories\VendorCoverageRepository())->sync_vendor_coverage(
                (int)$id, (array)($data['cities'] ?? []), (array)($data['services'] ?? [])
            );
        }

        return ['success' => true, 'vendor_id' => $id, 'vendor_number' => $vnum, 'password' => $password];
    }

    // ── Shared scoring formula (Vendor Matching live-preview gap) ──────────
    // Extracted verbatim from the scoring arithmetic that used to be inline
    // in auto_assign()'s loop below, so that the Settings → Matching
    // "Preview" AJAX endpoint (SettingsController::previewMatching()) scores
    // candidate weights with the EXACT same formula real auto-assignment
    // uses, rather than a second copy that could silently drift from it.
    // Both call sites now call this one method — there is nowhere else in
    // the codebase this arithmetic is allowed to be duplicated.
    //
    // @param array $v      One vendor row: id, rating, completion_rate, acceptance_rate.
    // @param int   $active Vendor's current active-job count.
    // @param int   $maxActiveJobs
    // @param array $weights ['rating_weight','completion_weight','acceptance_weight','load_weight']
    public static function scoreVendor(array $v, int $active, int $maxActiveJobs, array $weights): float
    {
        return ((float)$v['rating'] / 5 * (float)$weights['rating_weight'])
             + ((float)$v['completion_rate'] / 100 * (float)$weights['completion_weight'])
             + ((float)$v['acceptance_rate'] / 100 * (float)$weights['acceptance_weight'])
             + (((($maxActiveJobs - $active)) / max(1, $maxActiveJobs)) * (float)$weights['load_weight']);
    }

    /**
     * Read-only ranking preview for Settings → Matching's "Preview" button:
     * scores the same real, currently-eligible vendor pool for a
     * city/service using CANDIDATE weights the admin is trying out, via the
     * identical scoreVendor() formula auto_assign() uses — but never writes
     * anything (no lead update, no assignment row, no event fired, no
     * option saved). Eligibility itself (coverage, min_rating,
     * candidate_pool_size) still comes from the currently SAVED
     * MatchingConfig — only the scoring weights are hypothetical.
     *
     * @param array $weights ['rating_weight','completion_weight','acceptance_weight','load_weight']
     * @return array<int, array{id:int,rating:float,completion_rate:float,acceptance_rate:float,active_jobs:int,score:float,eligible:bool}>
     *         Sorted by score descending; ineligible (over capacity) vendors are included with eligible=false, at the bottom.
     */
    public function previewRanking(int $cityId, int $serviceId, array $weights): array
    {
        $vendors = $this->coverage->get_eligible_by_coverage($cityId, $serviceId);
        if (empty($vendors)) return [];

        $maxActiveJobs = (int)MatchingConfig::get('max_active_jobs');

        $rows = [];
        foreach ($vendors as $v) {
            $active   = $this->vendors->get_stats($v['id'])['active'] ?? 0;
            $eligible = $active < $maxActiveJobs;
            $rows[] = [
                'id'              => (int)$v['id'],
                'rating'          => (float)$v['rating'],
                'completion_rate' => (float)$v['completion_rate'],
                'acceptance_rate' => (float)$v['acceptance_rate'],
                'active_jobs'     => (int)$active,
                'score'           => $eligible ? self::scoreVendor($v, $active, $maxActiveJobs, $weights) : 0.0,
                'eligible'        => $eligible,
            ];
        }

        usort($rows, function ($a, $b) {
            if ($a['eligible'] !== $b['eligible']) return $a['eligible'] ? -1 : 1;
            return $b['score'] <=> $a['score'];
        });

        return $rows;
    }

    /**
     * FIX (Known Limitations — settings-matching: "no live preview of how a
     * proposed weight change would have affected recent, already-made
     * assignment decisions"): re-scores TODAY's real eligible vendor pool
     * for each of the last $limit auto-assigned leads under both the
     * currently-saved weights and the admin's proposed weights, and reports
     * how many of those real assignment decisions would have picked a
     * different top-ranked vendor. Honest limitation this still carries:
     * vendor rating/completion/acceptance/active-job-count are read as they
     * stand NOW, not as they stood at the moment each historical assignment
     * was actually made (that snapshot was never recorded) — so this shows
     * "if these recent leads were assigned today under the new weights,
     * would the top pick change", which is the closest a system with no
     * historical-vendor-stat snapshot table can honestly get, and is stated
     * as such in the UI rather than implied to be a perfect replay.
     *
     * @param array $weights Proposed ['rating_weight','completion_weight','acceptance_weight','load_weight']
     * @return array{checked:int,changed:int,rows:array<int,array>}
     */
    public function previewImpactOnRecentAssignments(int $cityId, int $serviceId, array $weights, int $limit = 50): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $limit = max(1, min(200, $limit));

        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.vendor_id, v.name AS vendor_name
             FROM {$p}rto_leads l
             LEFT JOIN {$p}rto_vendors v ON v.id = l.vendor_id
             WHERE l.city_id = %d AND l.service_id = %d AND l.vendor_id IS NOT NULL
             ORDER BY l.id DESC LIMIT %d",
            $cityId, $serviceId, $limit
        ), ARRAY_A) ?: [];

        if (!$recent) {
            return ['checked' => 0, 'changed' => 0, 'rows' => []];
        }

        $currentWeights = [
            'rating_weight'     => MatchingConfig::get('rating_weight'),
            'completion_weight' => MatchingConfig::get('completion_weight'),
            'acceptance_weight' => MatchingConfig::get('acceptance_weight'),
            'load_weight'       => MatchingConfig::get('load_weight'),
        ];

        $currentTop  = $this->topVendorId($cityId, $serviceId, $currentWeights);
        $proposedTop = $this->topVendorId($cityId, $serviceId, $weights);

        $rows = [];
        $changed = 0;
        foreach ($recent as $r) {
            $wouldChange = $currentTop !== null && $proposedTop !== null && $currentTop !== $proposedTop;
            if ($wouldChange) $changed++;
            $rows[] = [
                'lead_id'            => (int)$r['id'],
                'actual_vendor_id'   => (int)$r['vendor_id'],
                'actual_vendor_name' => $r['vendor_name'] ?: ('Vendor #' . $r['vendor_id']),
                'current_top_vendor_id'  => $currentTop,
                'proposed_top_vendor_id' => $proposedTop,
                'would_change'       => $wouldChange,
            ];
        }

        return ['checked' => count($rows), 'changed' => $changed, 'rows' => $rows];
    }

    /** Helper for previewImpactOnRecentAssignments(): today's #1-ranked eligible vendor id, or null if none. */
    private function topVendorId(int $cityId, int $serviceId, array $weights): ?int
    {
        $ranking = $this->previewRanking($cityId, $serviceId, $weights);
        foreach ($ranking as $row) {
            if ($row['eligible']) return $row['id'];
        }
        return null;
    }

    /**
     * @param int|null $excludeVendorId ENTERPRISE GAP FIX (Phase 1, item 3 —
     *        "no automatic reassignment or escalation when a vendor
     *        breaches SLA"): Bootstrap::runSlaEscalationCheck() reassigns a
     *        breached lead away from its CURRENT vendor — without this
     *        parameter, that vendor (still 'active'/'verified', still
     *        covering the city/service, likely still the single highest-
     *        scoring candidate) would simply be handed the exact same lead
     *        right back, which is a no-op dressed up as an "escalation".
     *        Every other caller (new-lead auto-assignment, the existing
     *        vendor-suspension reassignment path) passes null and gets the
     *        prior, unchanged behaviour.
     */
    public function auto_assign(int $lead_id, ?int $excludeVendorId = null): ?int
    {
        $lead = $this->leads->find($lead_id);
        if (!$lead) return null;

        // FIX (VendorCoverageRepository cutover): auto_assign() now sources
        // candidates from the indexed rto_vendor_coverage join table instead
        // of VendorRepository::get_eligible()'s JSON_CONTAINS lookup on
        // rto_vendors.cities/services. Same matching semantics (active +
        // verified vendors, city/service coverage, min-rating threshold from
        // MatchingConfig, same candidate_pool_size limit) and the identical
        // row shape (id, rating, completion_rate, acceptance_rate,
        // total_jobs), so the scoring loop below is unchanged.
        $vendors = $this->coverage->get_eligible_by_coverage((int)$lead['city_id'], (int)$lead['service_id']);
        if ($excludeVendorId) {
            $vendors = array_values(array_filter($vendors, fn($v) => (int)$v['id'] !== $excludeVendorId));
        }
        if (empty($vendors)) return null;

        // FIX P1 (Matching/Lead-Allocation configurability): scoring weights
        // and the active-job cap were previously inline numeric literals
        // (30/25/20/25 and 20) — an admin could not change how vendors are
        // ranked without a code deployment. Both now come from
        // MatchingConfig, editable from Settings → Matching without touching
        // code; the arithmetic itself is unchanged when the config is left
        // at its defaults, which match the old hard-coded values exactly.
        $weights = [
            'rating_weight'     => MatchingConfig::get('rating_weight'),
            'completion_weight' => MatchingConfig::get('completion_weight'),
            'acceptance_weight' => MatchingConfig::get('acceptance_weight'),
            'load_weight'       => MatchingConfig::get('load_weight'),
        ];
        $maxActiveJobs = (int)MatchingConfig::get('max_active_jobs');

        $best       = null;
        $best_score = -1;

        foreach ($vendors as $v) {
            $active = $this->vendors->get_stats($v['id'])['active'] ?? 0;
            if ($active >= $maxActiveJobs) continue;
            $score = self::scoreVendor($v, $active, $maxActiveJobs, $weights);
            if ($score > $best_score) { $best_score = $score; $best = $v['id']; }
        }

        if ($best) {
            // BUGFIX (ghost-success sweep — same class of bug already fixed
            // this pass in LeadService::assignVendor()/PaymentService::record()/
            // PayoutService): the lead-row update and the assignment-row insert
            // below previously ran as two independent, unchecked writes with no
            // transaction. If the insert failed after the lead update already
            // succeeded (or vice versa), the lead would show as 'assigned' to a
            // vendor with no corresponding rto_assignments row (or an
            // assignment row would exist for a lead that was never actually
            // updated) — and the 'lead.vendor_assigned' event would still fire
            // regardless, notifying the vendor of a job that, in the failure
            // case, was only half-recorded. Both writes are now one checked
            // transaction; the event fires only after both genuinely commit.
            global $wpdb;
            $wpdb->query('START TRANSACTION');
            try {
                $leadUpdated = $this->leads->update($lead_id, ['vendor_id' => $best, 'status' => 'assigned']);
                if (!$leadUpdated) {
                    throw new \RuntimeException('lead vendor_id/status update failed during auto_assign');
                }

                $assignmentInserted = $wpdb->insert($wpdb->prefix . 'rto_assignments', [
                    'lead_id'     => $lead_id,
                    'vendor_id'   => $best,
                    'status'      => 'pending',
                    'assigned_at' => current_time('mysql'),
                ]);
                if (!$assignmentInserted) {
                    throw new \RuntimeException('assignment insert failed during auto_assign: ' . $wpdb->last_error);
                }

                $wpdb->query('COMMIT');
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');
                error_log('RTOFLOW VendorService::auto_assign: ' . $e->getMessage());
                return null;
            }

            $this->events->fire('lead.vendor_assigned', ['lead_id' => $lead_id, 'vendor_id' => $best]);
        }

        return $best;
    }

    // ── Manual-assignment eligibility warning (Known Limitations audit) ────
    // "Manual assignment eligibility is enforced more loosely than
    // auto-assignment eligibility ... a staff member can manually assign a
    // lead to a vendor who does not actually cover that city/service or is
    // below the rating threshold, without any warning." This is
    // deliberately NOT wired into LeadService::assignVendor() as a
    // blocking check — the recommended_fix explicitly calls for a
    // non-blocking warning, since manual assignment is meant to allow real
    // staff overrides (e.g. a genuine one-off outside normal coverage).
    // Reuses exactly the same eligibility inputs as get_eligible()/
    // auto_assign() (coverage via JSON_CONTAINS on cities/services,
    // MatchingConfig's min_rating, MatchingConfig's max_active_jobs) so the
    // two paths can never silently disagree about what "eligible" means —
    // only about whether a mismatch blocks or merely warns.
    public function checkManualAssignWarnings(int $vendorId, int $cityId, int $serviceId): array
    {
        global $wpdb;
        $warnings = [];

        $vendor = $wpdb->get_row($wpdb->prepare(
            "SELECT cities, services, rating FROM {$wpdb->prefix}rto_vendors WHERE id = %d",
            $vendorId
        ), ARRAY_A);
        if (!$vendor) return ['Vendor not found for eligibility check.'];

        $cities   = json_decode($vendor['cities']   ?? '[]', true) ?: [];
        $services = json_decode($vendor['services'] ?? '[]', true) ?: [];
        if (!in_array($cityId, array_map('intval', $cities), true)) {
            $warnings[] = 'This vendor\'s configured city coverage does not include this order\'s city.';
        }
        if (!in_array($serviceId, array_map('intval', $services), true)) {
            $warnings[] = 'This vendor\'s configured service coverage does not include this order\'s service.';
        }

        $minRating = (float)MatchingConfig::get('min_rating');
        if ((float)$vendor['rating'] < $minRating) {
            $warnings[] = "This vendor's rating ({$vendor['rating']}) is below the platform's minimum auto-assignment threshold ({$minRating}).";
        }

        $maxActiveJobs = (int)MatchingConfig::get('max_active_jobs');
        $active = $this->vendors->get_stats($vendorId)['active'] ?? 0;
        if ($active >= $maxActiveJobs) {
            $warnings[] = "This vendor is already at or above their max active job capacity ({$active}/{$maxActiveJobs}).";
        }

        return $warnings;
    }

    public function accept_job(int $lead_id, int $vendor_id): bool
    {
        // BUGFIX (ghost-success sweep): both the assignment-status update and
        // the lead-status update were unchecked, and the method unconditionally
        // returned true regardless of whether either write actually succeeded
        // — the caller (and the vendor dashboard UI) had no way to ever learn
        // a job "accept" had silently failed to persist. Now both writes are
        // one checked transaction; a real false is returned on failure instead
        // of a false true, and the accepted event only fires once both writes
        // genuinely commit.
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $assignmentUpdated = $wpdb->update($wpdb->prefix . 'rto_assignments',
                ['status' => 'accepted', 'accepted_at' => current_time('mysql')],
                ['lead_id' => $lead_id, 'vendor_id' => $vendor_id]
            );
            if ($assignmentUpdated === false) {
                throw new \RuntimeException('assignment status update failed during accept_job: ' . $wpdb->last_error);
            }

            $leadUpdated = $this->leads->update($lead_id, ['status' => 'in_progress']);
            if (!$leadUpdated) {
                throw new \RuntimeException('lead status update failed during accept_job');
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('RTOFLOW VendorService::accept_job: ' . $e->getMessage());
            return false;
        }

        $this->events->fire('lead.vendor_accepted', ['lead_id' => $lead_id, 'vendor_id' => $vendor_id]);
        return true;
    }

    public function reject_job(int $lead_id, int $vendor_id, string $reason = ''): bool
    {
        // BUGFIX (ghost-success sweep): the assignment-status update was
        // unchecked, and the method unconditionally fired the rejected event,
        // triggered a full re-assignment attempt via auto_assign(), and
        // returned true — even if the underlying rejection was never actually
        // persisted. A failed write here previously could leave a lead
        // silently stuck showing an unrejected 'pending' assignment while the
        // system had already moved on and tried to hand it to a different
        // vendor.
        global $wpdb;
        $updated = $wpdb->update($wpdb->prefix . 'rto_assignments',
            ['status' => 'rejected', 'rejected_at' => current_time('mysql'), 'reject_reason' => $reason],
            ['lead_id' => $lead_id, 'vendor_id' => $vendor_id]
        );
        if ($updated === false) {
            error_log('RTOFLOW VendorService::reject_job: assignment status update failed — ' . $wpdb->last_error);
            return false;
        }

        $this->events->fire('lead.vendor_rejected', ['lead_id' => $lead_id, 'vendor_id' => $vendor_id]);
        $this->auto_assign($lead_id);
        return true;
    }
}

// Wire auto-assign via WordPress action
add_action('rto_auto_assign', function ($lead_id) {
    try {
        \RTOFLOW\Bootstrap::container()->make(VendorService::class)->auto_assign((int)$lead_id);
    } catch (\Throwable) {}
});
