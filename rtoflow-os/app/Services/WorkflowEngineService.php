<?php

namespace RTOFLOW\Services;

use RTOFLOW\Repositories\LeadRepository;

if (!defined('ABSPATH')) exit;

/**
 * Workflow Engine Service
 *
 * Data-driven, per-service replacement for the single, global
 * WorkflowService::TRANSITIONS map. A service (rto_services.id) may have its
 * own active workflow definition in rto_workflow_definitions — if it does,
 * this service reads that definition's states/transitions instead. If it
 * does not, this service falls through to WorkflowService's existing
 * hard-coded transitions logic unchanged, so every lead created before this
 * feature existed (and every service that never gets a custom workflow
 * configured) behaves exactly as it always has.
 *
 * This class deliberately does not duplicate the default guard: it delegates
 * to WorkflowService::isDefaultTransitionAllowed() for the fallback case.
 */
class WorkflowEngineService
{
    /** @var array<int, array|null> per-request cache of resolved definition rows keyed by service_id */
    private array $definitionCache = [];

    public function __construct(
        private \wpdb $db,
        private string $prefix,
        private ?LeadRepository $leads = null,
        private ?NotificationService $notifications = null
    ) {}

    /**
     * Find the active custom workflow definition row for a service, or null
     * if the service should use the global default (hard-coded) workflow.
     */
    private function findActiveDefinition(int $serviceId): ?array
    {
        if (array_key_exists($serviceId, $this->definitionCache)) {
            return $this->definitionCache[$serviceId];
        }

        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->prefix}rto_workflow_definitions
                 WHERE service_id=%d AND is_active=1
                 ORDER BY id DESC LIMIT 1",
                $serviceId
            ),
            ARRAY_A
        );

        return $this->definitionCache[$serviceId] = ($row ?: null);
    }

    /**
     * @return array<int,array> states for a definition, ordered for display
     */
    private function statesFor(int $definitionId): array
    {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->prefix}rto_workflow_states
                 WHERE workflow_definition_id=%d
                 ORDER BY display_order, id",
                $definitionId
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @return array<int,array> transitions for a definition
     */
    private function transitionsFor(int $definitionId): array
    {
        return $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->prefix}rto_workflow_transitions
                 WHERE workflow_definition_id=%d",
                $definitionId
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Evaluate whether $from -> $to is a legal transition for this service,
     * optionally checking the acting user's role against requires_role.
     *
     * $userRole is a role slug ('admin'|'staff'|'vendor'|'client') resolved
     * by the caller (e.g. via rto_user_role()); it is accepted here as a
     * nullable value (not strictly an int, despite historically role IDs
     * being ints in some parts of this codebase) so callers can pass either
     * a role slug or null when no role check applies. Kept loosely typed to
     * avoid forcing a role-ID lookup on every caller.
     */
    /**
     * @param array $context Live field values for the record being
     *   transitioned (e.g. the lead row, plus any extra request data the
     *   caller wants a guard to be able to see) — evaluated against a
     *   transition's guard_condition_json, if one is set. Also passed
     *   through to side-effect execution (see runSideEffects()).
     */
    public function canTransition(int $serviceId, string $from, string $to, $userRole = null, array $context = []): bool
    {
        $definition = $this->findActiveDefinition($serviceId);

        if (!$definition) {
            return WorkflowService::isDefaultTransitionAllowed($from, $to);
        }

        foreach ($this->transitionsFor((int)$definition['id']) as $t) {
            if ($t['from_status'] !== $from || $t['to_status'] !== $to) continue;

            if (!empty($t['requires_role']) && $userRole !== null && (string)$t['requires_role'] !== (string)$userRole) {
                continue;
            }

            if (!empty($t['guard_condition_json']) && !$this->evaluateGuard($t['guard_condition_json'], $context)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Attempt a transition and report whether it is allowed. Returns a
     * result array rather than throwing so callers (controllers) can
     * surface a clear error to the user instead of a generic failure.
     *
     * ENTERPRISE GAP FIX (Section 7 — "workflow engine side-effect error
     * handling/rollback"): this method used to run side_effect_json's
     * actions itself, INSIDE the "is this allowed" check, before the caller
     * (LeadService::updateStatus() / AutomationService::apply_status_change())
     * had actually written the new status to rto_leads. That produced two
     * real bugs, not just a theoretical one:
     *   1. A 'set_status'/'set_field' side effect targeting the SAME lead
     *      row was silently clobbered a moment later by the caller's own
     *      unconditional `$wpdb->update(...['status' => $newStatus]...)` —
     *      the side effect's write never actually stuck.
     *   2. If a side effect threw (e.g. NotificationService::send() hitting
     *      a misconfigured provider) the exception propagated out of
     *      attemptTransition() before the caller's real status update ever
     *      ran — an admin clicking "Mark Completed" could get a fatal error
     *      and the lead would stay in its old status, with no rollback
     *      story for whatever the side effect already did (e.g. a partial
     *      DB write or an email already sent).
     * Root-cause fix: this method now ONLY evaluates the guard/role rules
     * and returns the matched transition's side_effect_json (if any) for
     * the caller to run via runSideEffects() AFTER its own status write has
     * actually committed — see LeadService::updateStatus() and
     * AutomationService::apply_status_change() for the corrected ordering.
     *
     * @return array{allowed:bool, reason:?string, side_effect_json:?string}
     */
    public function attemptTransition(int $serviceId, string $from, string $to, $userRole = null, array $context = []): array
    {
        $definition = $this->findActiveDefinition($serviceId);

        if (!$definition) {
            $allowed = WorkflowService::isDefaultTransitionAllowed($from, $to);
            return ['allowed' => $allowed, 'reason' => $allowed ? null : 'Transition not permitted by the default workflow.', 'side_effect_json' => null];
        }

        $matchedButBlocked = false;

        foreach ($this->transitionsFor((int)$definition['id']) as $t) {
            if ($t['from_status'] !== $from || $t['to_status'] !== $to) continue;

            if (!empty($t['requires_role']) && $userRole !== null && (string)$t['requires_role'] !== (string)$userRole) {
                $matchedButBlocked = true;
                continue;
            }

            if (!empty($t['guard_condition_json']) && !$this->evaluateGuard($t['guard_condition_json'], $context)) {
                $matchedButBlocked = true;
                continue;
            }

            return ['allowed' => true, 'reason' => null, 'side_effect_json' => $t['side_effect_json'] ?? null];
        }

        return [
            'allowed' => false,
            'reason'  => $matchedButBlocked
                ? 'Transition blocked: its guard condition or role requirement was not met.'
                : "No transition from \"{$from}\" to \"{$to}\" is defined for this workflow.",
            'side_effect_json' => null,
        ];
    }

    /**
     * Guard evaluator for guard_condition_json.
     *
     * FIX: previously only understood a flat JSON list of {field,op,value}
     * conditions, ANDed together with no way to express OR logic or nested
     * groups ("must match A, AND (either B or C)"). Now delegates to
     * ConditionGroupEvaluator, which supports a real nested
     * {"operator":"AND"|"OR","conditions":[...]} structure recursively,
     * while treating any existing flat list exactly as before — an implicit
     * top-level AND — so every guard_condition_json saved before this
     * change keeps evaluating identically. An empty/omitted guard always
     * passes.
     *
     * See ConditionGroupEvaluator's docblock for why this is NOT actually
     * shared with EligibilityService (a completely different, per-row DB
     * model, not a JSON condition tree) despite this method's old docblock
     * claiming "one mental model... not several" — that claim did not hold
     * in the code as written.
     */
    private function evaluateGuard(string $guardJson, array $context): bool
    {
        $conditions = json_decode($guardJson, true);
        if (!is_array($conditions) || empty($conditions)) return true;

        return ConditionGroupEvaluator::evaluate($conditions, $context);
    }

    /**
     * Executes side_effect_json, whose migration comment declares it "same
     * shape as AutomationService actions" — a JSON list of
     * {action, value|template|recipients, ...} entries. Supports the same
     * action types AutomationService::execute_actions() already implements
     * (set_status, set_priority, notify) so a transition's side effects and
     * an automation rule's actions behave identically wherever both exist.
     * When this service was constructed without a LeadRepository/
     * NotificationService (e.g. legacy call sites not yet updated), side
     * effects that need them are silently skipped rather than fataling —
     * evaluating the guard and allowing/blocking the transition itself must
     * never depend on optional collaborators being wired up.
     */
    /**
     * Runs side_effect_json's actions. Public and called by the transition
     * CALLER (LeadService::updateStatus(), AutomationService::
     * apply_status_change()) only AFTER their own real status write has
     * committed — see attemptTransition()'s docblock for why. Each action
     * runs independently inside its own try/catch: one action throwing
     * (e.g. a misconfigured notification provider) no longer aborts the
     * remaining actions in the list, and every action's own success/failure
     * is captured and returned instead of being silently discarded — the
     * "ghost success" a caller would otherwise have no way to detect.
     *
     * @return array<int,array{action:string,success:bool,error:?string}>
     */
    public function runSideEffects(string $sideEffectJson, array $context): array
    {
        $actions = json_decode($sideEffectJson, true);
        if (!is_array($actions)) return [];

        $leadId  = (int)($context['lead_id'] ?? $context['id'] ?? 0);
        $results = [];

        foreach ($actions as $a) {
            if (!is_array($a)) continue;
            $action = $a['action'] ?? '';
            try {
                switch ($action) {
                    case 'set_field':
                    case 'set_status':
                        if (!$leadId || !$this->leads) {
                            $results[] = ['action' => $action, 'success' => false, 'error' => 'No lead context or LeadRepository available.'];
                            break;
                        }
                        $field   = $a['field'] ?? 'status';
                        $updated = $this->leads->update($leadId, [$field => $a['value'] ?? '']);
                        $results[] = ['action' => $action, 'success' => $updated !== false, 'error' => $updated === false ? "Failed to set {$field}." : null];
                        break;
                    case 'set_priority':
                        if (!$leadId || !$this->leads) {
                            $results[] = ['action' => $action, 'success' => false, 'error' => 'No lead context or LeadRepository available.'];
                            break;
                        }
                        $updated = $this->leads->update($leadId, ['priority' => (int)($a['value'] ?? 2)]);
                        $results[] = ['action' => $action, 'success' => $updated !== false, 'error' => $updated === false ? 'Failed to set priority.' : null];
                        break;
                    case 'trigger_notification':
                    case 'notify':
                        if (!$leadId || !$this->notifications) {
                            $results[] = ['action' => $action, 'success' => false, 'error' => 'No lead context or NotificationService available.'];
                            break;
                        }
                        // NOTE: NotificationService::send() returns void, so
                        // "success" here means "did not throw" — it cannot
                        // detect a provider-level send failure (that already
                        // logs its own errors internally). The real win of
                        // this fix is the surrounding try/catch: one
                        // recipient's send() throwing no longer aborts the
                        // remaining recipients or the rest of the action list.
                        foreach ($a['recipients'] ?? [] as $recipientUserId) {
                            $this->notifications->send(
                                $a['template'] ?? '',
                                $context,
                                (int)$recipientUserId,
                                $a['channels'] ?? (isset($a['channel']) ? [$a['channel']] : ['email']),
                                $leadId
                            );
                        }
                        $results[] = ['action' => $action, 'success' => true, 'error' => null];
                        break;
                    default:
                        $results[] = ['action' => $action ?: '(empty)', 'success' => false, 'error' => 'Unknown side-effect action type.'];
                        break;
                }
            } catch (\Throwable $e) {
                error_log("RTOFLOW WorkflowEngineService: side-effect action '{$action}' threw for lead {$leadId}: " . $e->getMessage());
                $results[] = ['action' => $action, 'success' => false, 'error' => $e->getMessage()];
                // Deliberately no rethrow — one broken action must never
                // abort the rest of the side-effect list, and by the time
                // this runs the actual status transition has already
                // committed in the caller, so there is nothing left to
                // roll back to.
            }
        }

        return $results;
    }

    /**
     * @return array<int,array> the transitions available from $currentStatus
     *   for this service, in the same {slug,label,color} shape produced by
     *   WorkflowService::get_next() for backward-compatible rendering.
     */
    public function getAvailableTransitions(int $serviceId, string $currentStatus): array
    {
        $definition = $this->findActiveDefinition($serviceId);

        if (!$definition) {
            return array_values(array_filter(
                array_map(
                    fn($to) => WorkflowService::isDefaultTransitionAllowed($currentStatus, $to)
                        ? array_merge(['slug' => $to], LeadRepository::STATUSES[$to] ?? ['label' => $to, 'color' => '#6B7280'])
                        : null,
                    $this->defaultTargetsFrom($currentStatus)
                )
            ));
        }

        $states = $this->statesFor((int)$definition['id']);
        $labelsByKey = [];
        foreach ($states as $s) {
            $labelsByKey[$s['status_key']] = $s['label'];
        }

        $targets = [];
        foreach ($this->transitionsFor((int)$definition['id']) as $t) {
            if ($t['from_status'] !== $currentStatus) continue;
            $targets[] = [
                'slug'          => $t['to_status'],
                'label'         => $labelsByKey[$t['to_status']] ?? $t['to_status'],
                'color'         => LeadRepository::STATUSES[$t['to_status']]['color'] ?? '#6B7280',
                'requires_role' => $t['requires_role'] ?? null,
            ];
        }
        return $targets;
    }

    /** Reads WorkflowService::TRANSITIONS' target list for a status without duplicating the constant. */
    private function defaultTargetsFrom(string $status): array
    {
        $ref = new \ReflectionClassConstant(WorkflowService::class, 'TRANSITIONS');
        $map = $ref->getValue();
        return $map[$status] ?? [];
    }

    /**
     * Describe the resolved workflow for a service: whether it is custom or
     * the global default, plus its states and transitions in a shape the
     * admin UI and other callers can render directly.
     *
     * @return array{is_custom:bool, definition_id:?int, name:string, states:array, transitions:array}
     */
    public function getWorkflowForService(int $serviceId): array
    {
        $definition = $this->findActiveDefinition($serviceId);

        if (!$definition) {
            $states = [];
            foreach (LeadRepository::STATUSES as $key => $info) {
                $states[] = [
                    'status_key'  => $key,
                    'label'       => $info['label'] ?? $key,
                    'is_terminal' => $this->isTerminalDefault($key),
                ];
            }
            $transitions = [];
            $ref = new \ReflectionClassConstant(WorkflowService::class, 'TRANSITIONS');
            foreach ($ref->getValue() as $from => $tos) {
                foreach ($tos as $to) {
                    $transitions[] = ['from_status' => $from, 'to_status' => $to, 'requires_role' => null];
                }
            }
            return [
                'is_custom'     => false,
                'definition_id' => null,
                'name'          => 'Global default workflow',
                'states'        => $states,
                'transitions'   => $transitions,
            ];
        }

        return [
            'is_custom'     => true,
            'definition_id' => (int)$definition['id'],
            'name'          => $definition['name'],
            'states'        => $this->statesFor((int)$definition['id']),
            'transitions'   => $this->transitionsFor((int)$definition['id']),
        ];
    }

    private function isTerminalDefault(string $status): bool
    {
        $ref = new \ReflectionClassConstant(WorkflowService::class, 'TRANSITIONS');
        $map = $ref->getValue();
        return empty($map[$status] ?? []);
    }
}
