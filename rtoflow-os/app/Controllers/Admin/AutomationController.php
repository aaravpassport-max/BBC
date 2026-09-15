<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;
use RTOFLOW\Services\AuditService;

if (!defined('ABSPATH')) exit;

/**
 * Admin CRUD for the Automation Engine (see AutomationService::handle()).
 *
 * The Automation admin screen was previously read-only: it rendered
 * rto_automation_rules but had no create/update/delete/toggle path, so an
 * admin could only look at rules seeded on activation and never author a
 * new one. This wires real CRUD onto the table AutomationService already
 * reads at runtime, following the same store/toggle/delete shape as
 * EligibilityController (the other "configure, don't code" rules engine
 * in this codebase) so the two admin surfaces behave consistently.
 *
 * Conditions are stored as conditions_json, an array of {field,op,value}
 * objects — this is not a new format invented for this screen, it is
 * exactly what AutomationService::check_conditions() already parses and
 * evaluates. We only add server-side validation of that same shape.
 */
class AutomationController
{
    private \wpdb $db;
    private string $p;

    // The real, exhaustive set of events actually fired via EventBus::fire()
    // in this codebase (grepped from app/**), matching what the read-only
    // "Available System Events" panel already lists. Anything outside this
    // set can never match a rule, so it is rejected rather than silently
    // stored as a rule that will never fire.
    private const VALID_EVENTS = [
        'lead.created', 'lead.status_changed', 'lead.completed',
        'lead.sla_breach', 'lead.sla_warning',
        'lead.vendor_assigned', 'lead.vendor_accepted', 'lead.vendor_rejected',
        'payment.received',
        'document.uploaded', 'document.verified', 'document.rejected',
        'complaint.created',
    ];

    // The real, exhaustive set of actions understood by
    // AutomationService::execute_actions().
    private const VALID_ACTIONS = ['set_status', 'set_priority', 'notify'];

    // ENTERPRISE GAP FIX (automation engine OR/nested-conditions): kept in
    // sync with the leaf ops ConditionGroupEvaluator::evaluateLeaf()
    // actually implements for its 'workflow'-engine leaves (the shape
    // conditions_json uses) — AutomationService::check_conditions() now
    // delegates to that same evaluator, so this whitelist must match it or
    // an admin could save a rule this validator accepts but the evaluator
    // treats as an unrecognised op (falls through to its own `default =>
    // true` — i.e. the condition would silently always pass, the exact
    // "phantom configurability" failure mode this whitelist exists to
    // prevent).
    private const VALID_CONDITION_OPS = [
        'equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'not_empty',
    ];

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    public function index(): void
    {
        $rules = $this->db->get_results(
            "SELECT * FROM {$this->p}rto_automation_rules ORDER BY priority, name", ARRAY_A
        ) ?: [];
        $validEvents  = self::VALID_EVENTS;
        $validActions = self::VALID_ACTIONS;
        rto_view('admin.automation.index', compact('rules', 'validEvents', 'validActions'));
    }

    // ── AJAX: create rule ────────────────────────────────────────────────────
    public function create(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $name    = Sanitiser::text($_POST['name'] ?? '', 200);
        $trigger = Sanitiser::text($_POST['trigger_event'] ?? '');
        $priority = Sanitiser::int($_POST['priority'] ?? 10, 0, 999);
        $conditions = $this->parseConditions($_POST['conditions_json'] ?? '[]');
        $actions    = $this->parseActions($_POST['actions_json'] ?? '[]');

        $error = $this->validate($name, $trigger, $conditions, $actions);
        if ($error) rto_json_err($error);

        // ENTERPRISE GAP FIX (Phase 7, item 2 — conflict detection): a new
        // rule is always created active, so it must be checked the same as
        // an activation. See detectConflicts()'s docblock.
        if (empty($_POST['confirm_conflicts'])) {
            $conflicts = $this->detectConflicts(0, $trigger, $actions);
            if ($conflicts) {
                rto_json_err(
                    'This rule conflicts with ' . count($conflicts) . ' other active rule(s) on the same event — resubmit to save anyway.',
                    409, ['conflicts' => $conflicts]
                );
            }
        }

        $inserted = $this->db->insert($this->p . 'rto_automation_rules', [
            'name'            => $name,
            'trigger_event'   => $trigger,
            'conditions_json' => wp_json_encode($conditions),
            'actions_json'    => wp_json_encode($actions),
            'priority'        => $priority,
            'is_active'       => 1,
            'run_count'       => 0,
            'fail_count'      => 0,
        ]);
        if (!$inserted) rto_json_err('Could not save the rule. Please try again.');

        $id = (int)$this->db->insert_id;
        AuditService::log('automation_rule.created', 0, [
            'rule_id' => $id, 'trigger_event' => $trigger, 'name' => $name,
        ]);
        $this->snapshotVersion('Rule created: ' . $name);
        rto_json_ok(['rule_id' => $id], 'Automation rule created.');
    }

    // ── AJAX: update rule ────────────────────────────────────────────────────
    public function update(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['rule_id'] ?? 0, 1);
        $before = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->p}rto_automation_rules WHERE id=%d", $id
        ), ARRAY_A);
        if (!$before) rto_json_err('Rule not found.', 404);

        $name    = Sanitiser::text($_POST['name'] ?? '', 200);
        $trigger = Sanitiser::text($_POST['trigger_event'] ?? '');
        $priority = Sanitiser::int($_POST['priority'] ?? 10, 0, 999);
        $conditions = $this->parseConditions($_POST['conditions_json'] ?? '[]');
        $actions    = $this->parseActions($_POST['actions_json'] ?? '[]');

        $error = $this->validate($name, $trigger, $conditions, $actions);
        if ($error) rto_json_err($error);

        // ENTERPRISE GAP FIX (Phase 7, item 2 — conflict detection): only
        // worth checking when the rule being edited is (or stays) active —
        // an inactive rule's actions can't conflict with anything at runtime.
        if (empty($_POST['confirm_conflicts']) && !empty($before['is_active'])) {
            $conflicts = $this->detectConflicts($id, $trigger, $actions);
            if ($conflicts) {
                rto_json_err(
                    'This rule conflicts with ' . count($conflicts) . ' other active rule(s) on the same event — resubmit to save anyway.',
                    409, ['conflicts' => $conflicts]
                );
            }
        }

        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): the update() result was discarded here
        // — an admin editing a rule's trigger/conditions/actions would be
        // told "Automation rule updated." even if the write failed and the
        // rule kept running its old (possibly now-incorrect) logic.
        $updated = $this->db->update($this->p . 'rto_automation_rules', [
            'name'            => $name,
            'trigger_event'   => $trigger,
            'conditions_json' => wp_json_encode($conditions),
            'actions_json'    => wp_json_encode($actions),
            'priority'        => $priority,
        ], ['id' => $id]);
        if ($updated === false) rto_json_err('Could not update the automation rule. Please try again.', 500);

        AuditService::log('automation_rule.updated', 0, [
            'rule_id' => $id, 'trigger_event' => $trigger, 'name' => $name,
        ], $before);
        $this->snapshotVersion('Rule updated: ' . $name);
        rto_json_ok(['rule_id' => $id], 'Automation rule updated.');
    }

    // ── AJAX: delete rule ─────────────────────────────────────────────────────
    public function delete(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['rule_id'] ?? 0, 1);
        $deleted = $this->db->delete($this->p . 'rto_automation_rules', ['id' => $id]);
        if (!$deleted) rto_json_err('Rule not found or already removed.', 404);

        AuditService::log('automation_rule.deleted', 0, ['rule_id' => $id]);
        $this->snapshotVersion('Rule #' . $id . ' deleted');
        rto_json_ok(null, 'Automation rule removed.');
    }

    // ── AJAX: toggle active/inactive ─────────────────────────────────────────
    public function toggleActive(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['rule_id'] ?? 0, 1);
        $rule = $this->db->get_row($this->db->prepare(
            "SELECT id, trigger_event, actions_json, is_active FROM {$this->p}rto_automation_rules WHERE id=%d", $id
        ), ARRAY_A);
        if (!$rule) rto_json_err('Rule not found.', 404);

        $newState = $rule['is_active'] ? 0 : 1;

        // ENTERPRISE GAP FIX (Phase 7, item 2 — conflict detection): this is
        // the exact scenario the gap called out — "two active rules with
        // contradictory actions on the same event can both fire silently";
        // only relevant when this toggle is turning the rule ON.
        if ($newState === 1 && empty($_POST['confirm_conflicts'])) {
            $myActions = json_decode($rule['actions_json'] ?? '[]', true);
            $conflicts = is_array($myActions) ? $this->detectConflicts($id, $rule['trigger_event'], $myActions) : [];
            if ($conflicts) {
                rto_json_err(
                    'Enabling this rule conflicts with ' . count($conflicts) . ' other active rule(s) on the same event — resubmit to enable anyway.',
                    409, ['conflicts' => $conflicts]
                );
            }
        }
        $updated = $this->db->update($this->p . 'rto_automation_rules', ['is_active' => $newState], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported success back to
        // the admin regardless of whether the DB write actually happened.
        if ($updated === false) rto_json_err('Could not update the rule. Please try again.', 500);
        AuditService::log('automation_rule.toggled', 0, ['rule_id' => $id, 'is_active' => $newState]);
        $this->snapshotVersion('Rule #' . $id . ' ' . ($newState ? 'enabled' : 'disabled'));
        rto_json_ok(['is_active' => $newState], $newState ? 'Rule enabled.' : 'Rule disabled.');
    }

    // ENTERPRISE GAP FIX (Phase 4, item 8 — "no versioning/rollback for
    // Automation rules, Email Templates, Webhooks, or Workflow
    // Definitions"): ConfigVersionService is a real, working draft/publish/
    // rollback primitive already wired into Eligibility/Matching/Feature
    // Flags/City Pricing — this wires it into Automation the same way
    // EligibilityController does (see that class's snapshotVersion()/
    // applyVersionedPayload(), which this mirrors exactly): every mutation
    // records a full-table snapshot version; the History screen can roll
    // back to any of them.
    private function snapshotVersion(string $note): void
    {
        $allRules = $this->db->get_results("SELECT * FROM {$this->p}rto_automation_rules ORDER BY id", ARRAY_A) ?: [];
        $versionId = \RTOFLOW\Services\ConfigVersionService::saveDraft('automation_rules', $allRules, get_current_user_id() ?: 0, $note);
        if ($versionId) {
            \RTOFLOW\Services\ConfigVersionService::publish($versionId, false);
        }
    }

    // ── Rollback target: called ONLY from Bootstrap.php's
    // 'rtoflow_config_published' listener when config_key ===
    // 'automation_rules' and $fireApply was true (the "activate an older
    // version" path). Mirrors EligibilityController::applyVersionedPayload()
    // exactly: update-in-place for a row whose id still exists, insert for
    // one that doesn't, delete anything left over that the snapshot doesn't
    // mention (a full-table restore, not a merge).
    public function applyVersionedPayload(array $payload): void
    {
        $table = $this->p . 'rto_automation_rules';
        $existingIds = array_map('intval', $this->db->get_col("SELECT id FROM {$table}"));
        $payloadIds = [];

        foreach ($payload as $row) {
            if (!is_array($row)) continue;
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            unset($row['id']);
            $row['priority']   = isset($row['priority']) ? (int)$row['priority'] : 10;
            $row['is_active']  = isset($row['is_active']) ? (int)$row['is_active'] : 1;
            $row['run_count']  = isset($row['run_count']) ? (int)$row['run_count'] : 0;

            if ($id > 0 && in_array($id, $existingIds, true)) {
                $this->db->update($table, $row, ['id' => $id]);
                $payloadIds[] = $id;
            } else {
                $this->db->insert($table, $row);
                $payloadIds[] = (int)$this->db->insert_id;
            }
        }

        $toDelete = array_diff($existingIds, $payloadIds);
        foreach ($toDelete as $deleteId) {
            $this->db->delete($table, ['id' => $deleteId]);
        }

        \RTOFLOW\Services\AuditService::log('automation_rules.version_restored', 0, ['restored_count' => count($payloadIds)]);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /** Returns an error message string, or '' when valid. */
    private function validate(string $name, string $trigger, ?array $conditions, ?array $actions): string
    {
        if (!$name) return 'Rule name is required.';
        if (!in_array($trigger, self::VALID_EVENTS, true)) return 'Unknown trigger event.';
        if ($conditions === null) return 'Conditions are malformed — expected a list of {field, op, value}.';
        if ($actions === null || empty($actions)) return 'At least one valid action is required.';
        return '';
    }

    // ENTERPRISE GAP FIX (Phase 7, item 2 — "Automation rule engine has no
    // OR/nested conditions and no conflict detection"): the OR/nested part
    // was already fixed (see check_conditions()'s ConditionGroupEvaluator
    // delegation above) but nothing ever detected two ACTIVE rules on the
    // SAME trigger_event issuing contradictory terminal actions (e.g. rule
    // A sets a lead to 'completed' on lead.status_changed while rule B sets
    // it to 'cancelled' on the same event) — both would silently fire, and
    // whichever ran last (undefined priority-tie ordering) would win with
    // no record either admin ever saw a warning. This is a warning, not a
    // hard block: some "conflicts" (e.g. two different notify actions) are
    // legitimate by design, so only same-action-type/different-terminal-
    // value pairs are flagged, and the admin can proceed anyway by
    // resubmitting with confirm_conflicts=1 once they've seen the warning.
    //
    // @param int   $excludeId  the rule being saved (0 for a brand-new rule
    //                          that has no id yet) — never flag against itself.
    // @param array $actions    the fully-parsed actions array being saved.
    // @return array            list of {rule_id, rule_name, action, this_value, other_value}
    //                          conflict descriptors; empty when none found.
    private function detectConflicts(int $excludeId, string $trigger, array $actions): array
    {
        $others = $this->db->get_results($this->db->prepare(
            "SELECT id, name, actions_json FROM {$this->p}rto_automation_rules
             WHERE trigger_event=%s AND is_active=1 AND id != %d", $trigger, $excludeId
        ), ARRAY_A) ?: [];
        if (!$others) return [];

        // Only these action types have a single terminal "value" that two
        // rules can meaningfully contradict each other on; 'notify' fanning
        // out to multiple channels/recipients is additive, not contradictory.
        $comparable = ['set_status', 'set_priority'];
        $mine = [];
        foreach ($actions as $a) {
            if (in_array($a['action'], $comparable, true)) $mine[$a['action']] = $a['value'];
        }
        if (!$mine) return [];

        $conflicts = [];
        foreach ($others as $other) {
            $otherActions = json_decode($other['actions_json'] ?? '[]', true);
            if (!is_array($otherActions)) continue;
            foreach ($otherActions as $oa) {
                if (!is_array($oa) || empty($oa['action'])) continue;
                if (!array_key_exists($oa['action'], $mine)) continue;
                if ((string)$oa['value'] !== (string)$mine[$oa['action']]) {
                    $conflicts[] = [
                        'rule_id'     => (int)$other['id'],
                        'rule_name'   => $other['name'],
                        'action'      => $oa['action'],
                        'this_value'  => $mine[$oa['action']],
                        'other_value' => $oa['value'],
                    ];
                }
            }
        }
        return $conflicts;
    }

    /**
     * Parses and validates the conditions payload against the exact
     * {field,op,value} shape AutomationService::check_conditions() expects.
     * Returns null (rejected) rather than an empty array on malformed input,
     * so malformed JSON isn't silently coerced into "no conditions".
     */
    private function parseConditions(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '' ) return [];
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) return null;

        // ENTERPRISE GAP FIX (automation engine OR/nested-conditions):
        // AutomationService::check_conditions() now accepts either the
        // legacy flat leaf-list (validated the same as before) or a nested
        // {"operator":"AND"|"OR","conditions":[...]} tree — this validator
        // must accept both shapes too, recursively, or the new engine
        // capability would be unreachable from the admin UI (the exact kind
        // of gap this whole fix exists to close).
        return $this->parseConditionNode($decoded, true);
    }

    /**
     * @param array $node       Either a flat list of leaves (top level only,
     *                          $isTopLevel=true), a {"operator","conditions"}
     *                          group, or a single leaf {"field","op","value"}.
     * @param bool  $isTopLevel Only the outermost call may be a bare flat
     *                          list with no operator/conditions wrapper —
     *                          matches ConditionGroupEvaluator::normalise().
     * @return array|null       The validated/sanitised node, or null if any
     *                          part of it is malformed.
     */
    private function parseConditionNode(array $node, bool $isTopLevel = false): ?array
    {
        // Nested-group shape: {"operator":"AND"|"OR","conditions":[...]}
        if (isset($node['conditions'])) {
            if (!is_array($node['conditions'])) return null;
            $operator = strtoupper((string)($node['operator'] ?? 'AND'));
            if (!in_array($operator, ['AND', 'OR'], true)) return null;

            $out = [];
            foreach ($node['conditions'] as $entry) {
                if (!is_array($entry)) return null;
                $parsed = $this->parseConditionNode($entry);
                if ($parsed === null) return null;
                $out[] = $parsed;
            }
            return ['operator' => $operator, 'conditions' => $out];
        }

        // Top-level bare flat list (legacy shape, no operator/conditions
        // wrapper) — a plain JSON array of leaf rules. Also covers an
        // explicit empty list ([] === no conditions, always matches) —
        // count()===0 is checked separately since range(0,-1) does not
        // produce an empty array in PHP and would otherwise misclassify it.
        if ($isTopLevel && (empty($node) || array_keys($node) === range(0, count($node) - 1))) {
            $out = [];
            foreach ($node as $entry) {
                if (!is_array($entry)) return null;
                $parsed = $this->parseConditionNode($entry);
                if ($parsed === null) return null;
                $out[] = $parsed;
            }
            return $out;
        }

        // Leaf rule: {"field":..., "op":..., "value":...}
        if (empty($node['field']) || !is_string($node['field'])) return null;
        $op = $node['op'] ?? 'equals';
        if (!in_array($op, self::VALID_CONDITION_OPS, true)) return null;
        return ['field' => Sanitiser::text($node['field'], 100), 'op' => $op, 'value' => $node['value'] ?? null];
    }

    /**
     * Parses and validates the actions payload against
     * AutomationService::execute_actions()'s known action shapes.
     */
    private function parseActions(string $raw): ?array
    {
        $raw = trim($raw);
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || empty($decoded)) return null;

        $out = [];
        foreach ($decoded as $a) {
            if (!is_array($a) || empty($a['action'])) return null;
            if (!in_array($a['action'], self::VALID_ACTIONS, true)) return null;

            $entry = ['action' => $a['action']];
            switch ($a['action']) {
                case 'set_status':
                    if (empty($a['value']) || !is_string($a['value'])) return null;
                    $entry['value'] = Sanitiser::text($a['value'], 50);
                    break;
                case 'set_priority':
                    $entry['value'] = Sanitiser::int($a['value'] ?? 2, 0, 10);
                    break;
                case 'notify':
                    $recipients = $a['recipients'] ?? [];
                    if (!is_array($recipients) || empty($recipients)) return null;
                    foreach ($recipients as $r) {
                        if (!in_array($r, ['client', 'vendor', 'admin'], true)) return null;
                    }
                    $entry['recipients'] = array_values($recipients);
                    $entry['template']   = Sanitiser::text($a['template'] ?? '', 100);
                    $entry['channel']    = in_array($a['channel'] ?? 'email', ['email', 'sms', 'whatsapp'], true)
                        ? $a['channel'] : 'email';
                    break;
            }
            $out[] = $entry;
        }
        return $out;
    }
}
