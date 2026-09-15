<?php

namespace RTOFLOW\Services;

use RTOFLOW\Repositories\LeadRepository;
use RTOFLOW\Support\EventBus;

if (!defined('ABSPATH')) exit;

/**
 * Workflow Service
 *
 * Enforces valid status transitions and fires events on every change.
 *
 * FIX (Help Centre audit, Part 5.1 — real defect found and fixed, not just
 * documented): this class's own transition() method below has ZERO callers
 * anywhere in the codebase outside its own DI container binding — it has
 * never actually been reachable from any real admin/vendor/client action.
 * The ONLY place its TRANSITIONS constant is actually read is
 * WorkflowEngineService (via ReflectionClassConstant), which backs the
 * Workflow Builder admin screen's "default workflow" preview for a service
 * that has no custom workflow definition of its own.
 *
 * The REAL, live enforcement for every actual lead status change — the
 * Leads screen's status dropdown, bulk status updates, everything a staff
 * member can actually click — goes through LeadsController::updateStatus()
 * → LeadService::updateStatus() → LeadService::isValidTransition(), a
 * SEPARATE, hand-maintained transition table that had drifted out of sync
 * with this one (e.g. this table allowed created→assigned directly and
 * completed→cancelled; LeadService's real table does not allow either).
 * That drift meant an admin opening Workflow Builder for a service with no
 * custom definition would see a "default workflow" diagram that did NOT
 * match what clicking the real status dropdown on the Leads screen would
 * actually allow — a source-of-truth conflict (see the brief's Section 27)
 * that would have been actively misleading to document as if both were
 * simply "the workflow".
 *
 * FIX: this table is now kept identical to LeadService::isValidTransition()
 * — LeadService remains the single, authoritative source of truth for what
 * a lead can actually do; this constant exists only so WorkflowEngineService
 * can render an accurate default-workflow preview without duplicating logic
 * it doesn't own. If LeadService's transition rules are ever changed, this
 * table must be updated to match in the same change — see the Help Centre's
 * Leads and Workflow Builder articles, which both state this explicitly.
 */
class WorkflowService
{
    const TRANSITIONS = [
        'created'          => ['payment_pending', 'payment_received', 'cancelled'],
        'payment_pending'  => ['payment_received', 'cancelled'],
        'payment_received' => ['assigned', 'cancelled'],
        'assigned'         => ['in_progress', 'payment_received', 'cancelled'],
        'in_progress'      => ['docs_pending', 'rto_submitted', 'on_hold', 'cancelled'],
        'docs_pending'     => ['docs_verified', 'in_progress', 'cancelled'],
        'docs_verified'    => ['rto_submitted', 'cancelled'],
        'rto_submitted'    => ['rto_processing', 'completed', 'on_hold', 'cancelled'],
        'rto_processing'   => ['completed', 'on_hold', 'cancelled'],
        'on_hold'          => ['in_progress', 'assigned', 'cancelled'],
        'completed'        => [], // Terminal state — matches LeadService exactly.
        'cancelled'        => [], // Terminal state — matches LeadService exactly.
    ];

    public function __construct(private LeadRepository $leads, private EventBus $events) {}

    public function transition(int $lead_id, string $to, string $note = ''): array
    {
        $lead = $this->leads->find($lead_id);
        if (!$lead) return ['success' => false, 'message' => 'Lead not found'];

        $from = $lead['status'];
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return ['success' => false, 'message' => "Transition {$from}→{$to} not allowed"];
        }

        $upd = ['status' => $to, 'updated_at' => current_time('mysql')];
        if ($to === 'completed') $upd['completed_at'] = current_time('mysql');
        if ($to === 'cancelled') {
            $upd['cancelled_at']  = current_time('mysql');
            $upd['cancel_reason'] = $note;
        }

        $this->leads->update($lead_id, $upd);
        $this->leads->log($lead_id, 'status_changed', $from, $to);

        $this->events->fire('lead.status_changed', [
            'lead_id'    => $lead_id,
            'old_status' => $from,
            'new_status' => $to,
            'note'       => $note,
        ]);

        do_action('rtoflow_lead_status_changed', $lead_id, $from, $to);

        return ['success' => true, 'from' => $from, 'to' => $to];
    }

    /**
     * Additive helper (no behaviour change): exposes the hard-coded default
     * transition guard so WorkflowEngineService can delegate to it for
     * services with no custom workflow definition, instead of duplicating
     * this lookup.
     */
    public static function isDefaultTransitionAllowed(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function get_next(int $lead_id): array
    {
        $lead = $this->leads->find($lead_id);
        if (!$lead) return [];
        return array_map(
            fn($s) => array_merge(['slug' => $s], LeadRepository::STATUSES[$s] ?? ['label' => $s, 'color' => '#6B7280']),
            self::TRANSITIONS[$lead['status']] ?? []
        );
    }
}
