<?php

namespace RTOFLOW\Services;

use RTOFLOW\Repositories\LeadRepository;
use RTOFLOW\Support\EventBus;

if (!defined('ABSPATH')) exit;

class AutomationService
{
    public function __construct(
        private LeadRepository      $leads,
        private NotificationService $notifications,
        private EventBus            $events,
        private ?WorkflowEngineService $workflowEngine = null
    ) {}

    public function handle(string $event, array $payload): void
    {
        global $wpdb;
        $rules = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rto_automation_rules WHERE trigger_event=%s AND is_active=1 ORDER BY priority",
                $event
            ),
            ARRAY_A
        ) ?: [];

        foreach ($rules as $r) {
            if (!$this->check_conditions($r['conditions_json'] ?? '[]', $payload)) continue;
            $this->execute_actions($r['actions_json'], $payload);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}rto_automation_rules SET run_count=run_count+1, last_run=NOW() WHERE id=%d",
                $r['id']
            ));
        }
    }

    /**
     * ENTERPRISE GAP FIX (Section 5/backlog — "automation engine has no
     * OR/nested-conditions support"): this used to hand-roll its own
     * evaluator that only ever understood a flat list ANDed together — the
     * exact same limitation WorkflowEngineService::evaluateGuard() had
     * before ConditionGroupEvaluator was built to fix it there. Automation
     * rules never got the same fix, so "notify on overdue OR high-priority"
     * style rules were simply inexpressible in the Automation Rules builder.
     * Delegating to the shared evaluator gives this real nested AND/OR for
     * free — a rule's conditions_json can now be either the legacy flat
     * list (implicit AND, evaluated identically to before) or a nested
     * {"operator":"AND"|"OR","conditions":[...]} tree, recursively.
     * Bonus correctness fix along the way: 'gt'/'lt' now require both sides
     * to be numeric before comparing (the old bare $v > $c['value'] would
     * silently coerce strings in PHP's often-surprising loose-comparison
     * rules — e.g. "10" > "9abc" is affected by type juggling); 'not_equals',
     * 'gte'/'lte', and 'in'/'not_in' are also now available to rule authors,
     * where before only equals/gt/lt/not_empty existed.
     */
    private function check_conditions(?string $cj, array $p): bool
    {
        $conds = json_decode($cj ?? '[]', true);
        return ConditionGroupEvaluator::evaluate($conds, $p);
    }

    private function execute_actions(string $aj, array $p): void
    {
        $actions = json_decode($aj, true);
        if (!is_array($actions)) return;
        $lid = (int)($p['lead_id'] ?? 0);

        foreach ($actions as $a) {
            match($a['action'] ?? '') {
                'set_status'   => $lid && $this->apply_status_change($lid, (string)($a['value'] ?? ''), $p),
                'set_priority' => $lid && $this->leads->update($lid, ['priority' => (int)($a['value'] ?? 2)]),
                'notify'       => $this->do_notify($a, $p),
                default        => null,
            };
        }
    }

    /**
     * FIX: automation rules with a 'set_status' action previously wrote
     * straight to rto_leads via LeadRepository::update(), bypassing
     * WorkflowEngineService entirely — an automation rule could force a lead
     * through a transition a configured workflow's guard_condition_json
     * would otherwise block, or skip its side_effect_json entirely. Routed
     * through the same attemptTransition() gate LeadService::updateStatus()
     * now uses. When the lead's service has no active custom workflow, the
     * engine falls through to the legacy default-transition rules, so
     * automation rules for never-migrated services keep working exactly as
     * before. A blocked transition is simply skipped (not fatal) — an
     * automation rule firing on an event it can no longer legally apply to
     * is the same "no-op" outcome check_conditions() already produces for a
     * non-matching rule.
     */
    private function apply_status_change(int $leadId, string $newStatus, array $payload): void
    {
        if ($newStatus === '') return;

        if (!$this->workflowEngine) {
            $this->leads->update($leadId, ['status' => $newStatus]);
            return;
        }

        $lead = $this->leads->find($leadId);
        if (!$lead) return;

        $oldStatus = $lead['status'] ?? '';
        $context   = array_merge($lead, $payload, ['lead_id' => $leadId]);

        $result = $this->workflowEngine->attemptTransition(
            (int)($lead['service_id'] ?? 0),
            (string)$oldStatus,
            $newStatus,
            null,
            $context
        );

        if (!$result['allowed']) return;

        $updated = $this->leads->update($leadId, ['status' => $newStatus]);

        // ENTERPRISE GAP FIX (Section 7 — workflow engine side-effect error
        // handling/rollback, same root cause and fix as LeadService::
        // updateStatus() — see WorkflowEngineService::attemptTransition()'s
        // docblock): side effects run AFTER this update commits, not before,
        // and only if it actually succeeded — a failed status write must
        // never still fire a 'notify' side effect telling someone the
        // transition happened.
        if ($updated !== false && !empty($result['side_effect_json'] ?? null)) {
            $sideEffectResults = $this->workflowEngine->runSideEffects($result['side_effect_json'], $context);
            $failures = [];
            foreach ($sideEffectResults as $r) {
                if (!$r['success']) {
                    $failures[] = $r['action'] . ': ' . ($r['error'] ?? 'unknown error');
                    error_log("RTOFLOW AutomationService::apply_status_change(): side-effect '{$r['action']}' failed for lead {$leadId}: " . ($r['error'] ?? 'unknown error'));
                }
            }
            // ENTERPRISE GAP FIX (Phase 7, item 3 — "side effects incomplete"
            // flag): same fix as LeadService::updateStatus() — surface a
            // side-effect failure on the lead itself, not only the error log.
            if ($failures) {
                global $wpdb;
                $wpdb->update($wpdb->prefix . 'rto_leads', [
                    'side_effects_incomplete'   => 1,
                    'side_effects_failure_note' => implode("\n", $failures),
                ], ['id' => $leadId]);
            }
        } elseif ($updated === false) {
            error_log("RTOFLOW AutomationService::apply_status_change(): status update failed for lead {$leadId} — side effects skipped.");
        }
    }

    private function do_notify(array $a, array $p): void
    {
        $lid = (int)($p['lead_id'] ?? 0);
        if (!$lid) return;
        global $wpdb;

        foreach ($a['recipients'] ?? [] as $r) {
            $uids = match($r) {
                'client' => [(int)$wpdb->get_var($wpdb->prepare("SELECT client_id FROM {$wpdb->prefix}rto_leads WHERE id=%d", $lid))],
                'vendor' => [(int)$wpdb->get_var($wpdb->prepare("SELECT vendor_id FROM {$wpdb->prefix}rto_leads WHERE id=%d", $lid))],
                'admin'  => get_users(['role' => 'administrator', 'fields' => 'ID']),
                default  => [],
            };
            foreach ($uids as $uid) {
                // FIX (integration pass): NotificationService::send()'s real signature
                // is send(string $slug, array $context, int $userId, array $channels,
                // ?int $leadId) — this call previously passed (template, (int)$uid,
                // $p, channel-string), i.e. an int where an array was expected and an
                // array where an int was expected. PHP's non-strict-typed params would
                // coerce some of this silently wrong (e.g. $p cast to an int loses all
                // context data) rather than fatal, but $channels being a bare string
                // instead of an array would break the foreach ($channels as $channel)
                // inside send() with a TypeError the moment any automation rule with
                // a 'notify' action actually matched. Corrected to the real parameter
                // order/types; $a['channel'] is now wrapped in an array.
                if ($uid) $this->notifications->send(
                    $a['template'] ?? '',
                    $p,
                    (int)$uid,
                    [$a['channel'] ?? 'email'],
                    $lid
                );
            }
        }
    }

    public function run_scheduled(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        // Warn about leads approaching SLA deadline.
        // FIX P1: 'closed' removed from the exclusion list below — it is not a
        // value in the real lead-status vocabulary (WorkflowService::TRANSITIONS
        // and LeadRepository::STATUSES both define exactly 12 values, none of
        // them 'closed'); leaving it in was silent dead code that never matched
        // any row.
        $at_risk = $wpdb->get_col(
            "SELECT id FROM {$p}rto_leads
             WHERE sla_breached=0
             AND sla_deadline BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR)
             AND status NOT IN ('completed','cancelled')
             AND deleted_at IS NULL"
        ) ?: [];
        foreach ($at_risk as $id) {
            $this->events->fire('lead.sla_warning', ['lead_id' => (int)$id]);
        }
    }
}
