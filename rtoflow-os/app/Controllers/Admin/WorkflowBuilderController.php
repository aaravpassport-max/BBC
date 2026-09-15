<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

/**
 * Admin CRUD for the per-service Workflow Builder.
 *
 * A service with no rows here keeps using the global default (hard-coded)
 * lead lifecycle in WorkflowService::TRANSITIONS — see WorkflowEngineService.
 * Adding a definition here, plus its states and transitions, lets that one
 * service follow its own lifecycle without touching any code.
 */
class WorkflowBuilderController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    // ── Pages ────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $services = $this->db->get_results(
            "SELECT s.id, s.name, s.category,
                    d.id AS definition_id, d.name AS workflow_name, d.is_active
             FROM {$this->p}rto_services s
             LEFT JOIN {$this->p}rto_workflow_definitions d
                    ON d.service_id = s.id AND d.is_active = 1
             WHERE s.is_active = 1
             ORDER BY s.category, s.name",
            ARRAY_A
        ) ?: [];

        rto_view('admin.workflows.index', compact('services'));
    }

    public function edit(): void
    {
        $serviceId = Sanitiser::int($_GET['service_id'] ?? 0, 1);
        if (!$serviceId) { rto_view('admin.workflows.index', ['services' => []]); return; }

        $service = $this->db->get_row(
            $this->db->prepare("SELECT id, name, category FROM {$this->p}rto_services WHERE id=%d", $serviceId),
            ARRAY_A
        );

        $definition = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->p}rto_workflow_definitions WHERE service_id=%d ORDER BY id DESC LIMIT 1",
                $serviceId
            ),
            ARRAY_A
        );

        $states = $transitions = [];
        if ($definition) {
            $states = $this->db->get_results(
                $this->db->prepare(
                    "SELECT * FROM {$this->p}rto_workflow_states WHERE workflow_definition_id=%d ORDER BY display_order, id",
                    $definition['id']
                ),
                ARRAY_A
            ) ?: [];
            $transitions = $this->db->get_results(
                $this->db->prepare(
                    "SELECT * FROM {$this->p}rto_workflow_transitions WHERE workflow_definition_id=%d ORDER BY id",
                    $definition['id']
                ),
                ARRAY_A
            ) ?: [];
        }

        rto_view('admin.workflows.edit', compact('service', 'serviceId', 'definition', 'states', 'transitions'));
    }

    // ── AJAX: create/enable the custom workflow definition for a service ──────
    public function createDefinition(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $serviceId = Sanitiser::int($_POST['service_id'] ?? 0, 1);
        $name      = Sanitiser::text($_POST['name'] ?? '', 150);
        if (!$serviceId) rto_json_err('A service is required.');
        if (!$name) $name = 'Custom workflow';

        $existing = $this->db->get_row(
            $this->db->prepare("SELECT id FROM {$this->p}rto_workflow_definitions WHERE service_id=%d", $serviceId),
            ARRAY_A
        );
        if ($existing) {
            $reEnabled = $this->db->update($this->p . 'rto_workflow_definitions', ['is_active' => 1, 'name' => $name], ['id' => $existing['id']]);
            // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
            // throughout this codebase): the insert branch just below already
            // checks its result — this update branch did not.
            if ($reEnabled === false) rto_json_err('Could not re-enable the workflow. Please try again.', 500);
            $id = (int)$existing['id'];
        } else {
            $this->db->insert($this->p . 'rto_workflow_definitions', [
                'service_id' => $serviceId,
                'name'       => $name,
                'is_active'  => 1,
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ]);
            $id = (int)$this->db->insert_id;
            if (!$id) rto_json_err('Could not create the workflow. Please try again.');
        }

        \RTOFLOW\Services\AuditService::log('workflow_definition.created', 0, ['definition_id' => $id, 'service_id' => $serviceId]);
        rto_json_ok(['definition_id' => $id], 'Custom workflow enabled for this service.');
    }

    // ── AJAX: toggle a definition active/inactive (inactive = fall back to default) ──
    public function toggleDefinition(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['definition_id'] ?? 0, 1);
        $def = $this->db->get_row(
            $this->db->prepare("SELECT id, is_active FROM {$this->p}rto_workflow_definitions WHERE id=%d", $id),
            ARRAY_A
        );
        if (!$def) rto_json_err('Workflow not found.', 404);

        $newState = $def['is_active'] ? 0 : 1;
        $updated = $this->db->update($this->p . 'rto_workflow_definitions', ['is_active' => $newState], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported success back to
        // the admin regardless of whether the DB write actually happened.
        if ($updated === false) rto_json_err('Could not update the workflow. Please try again.', 500);
        \RTOFLOW\Services\AuditService::log('workflow_definition.toggled', 0, ['definition_id' => $id, 'is_active' => $newState]);
        rto_json_ok(['is_active' => $newState], $newState ? 'Custom workflow enabled.' : 'Reverted this service to the default workflow.');
    }

    // ── AJAX: add a state ───────────────────────────────────────────────────
    public function addState(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $definitionId = Sanitiser::int($_POST['workflow_definition_id'] ?? 0, 1);
        $statusKey    = Sanitiser::alphanumeric($_POST['status_key'] ?? '', 64);
        $label        = Sanitiser::text($_POST['label'] ?? '', 120);
        $isTerminal   = Sanitiser::int($_POST['is_terminal'] ?? 0, 0, 1);
        $order        = Sanitiser::int($_POST['display_order'] ?? 0, 0, 9999);

        if (!$definitionId) rto_json_err('A workflow is required.');
        if (!$statusKey || !$label) rto_json_err('Status key and label are required.');
        $statusKey = strtolower(str_replace(' ', '_', $statusKey));

        $inserted = $this->db->insert($this->p . 'rto_workflow_states', [
            'workflow_definition_id' => $definitionId,
            'status_key'             => $statusKey,
            'label'                  => $label,
            'is_terminal'            => $isTerminal,
            'display_order'          => $order,
        ]);
        if (!$inserted) rto_json_err('Could not save the state. Please try again.');

        $this->snapshotVersion($definitionId, 'State added: ' . $label);
        rto_json_ok(['state_id' => (int)$this->db->insert_id], 'State added.');
    }

    // ── AJAX: delete a state ────────────────────────────────────────────────
    public function deleteState(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['state_id'] ?? 0, 1);
        $state = $this->db->get_row($this->db->prepare("SELECT workflow_definition_id FROM {$this->p}rto_workflow_states WHERE id=%d", $id), ARRAY_A);
        $deleted = $this->db->delete($this->p . 'rto_workflow_states', ['id' => $id]);
        if (!$deleted) rto_json_err('State not found or already removed.', 404);

        if ($state) $this->snapshotVersion((int)$state['workflow_definition_id'], 'State #' . $id . ' deleted');
        rto_json_ok(null, 'State removed.');
    }

    // ── AJAX: add a transition ──────────────────────────────────────────────
    public function addTransition(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $definitionId = Sanitiser::int($_POST['workflow_definition_id'] ?? 0, 1);
        $from         = Sanitiser::alphanumeric($_POST['from_status'] ?? '', 64);
        $to           = Sanitiser::alphanumeric($_POST['to_status'] ?? '', 64);
        $role         = Sanitiser::text($_POST['requires_role'] ?? '', 32);

        if (!$definitionId) rto_json_err('A workflow is required.');
        if (!$from || !$to) rto_json_err('From and To states are required.');
        if ($from === $to) rto_json_err('From and To states must differ.');

        $validRoles = ['', 'admin', 'staff', 'vendor', 'client'];
        if (!in_array($role, $validRoles, true)) rto_json_err('Invalid role.');

        $guardJson = self::buildGuardJson($_POST);
        if ($guardJson === false) rto_json_err('Invalid guard condition — check the field/operator/value rows.');

        $inserted = $this->db->insert($this->p . 'rto_workflow_transitions', [
            'workflow_definition_id' => $definitionId,
            'from_status'            => $from,
            'to_status'              => $to,
            'requires_role'          => $role ?: null,
            'guard_condition_json'   => $guardJson,
        ]);
        if (!$inserted) rto_json_err('Could not save the transition. Please try again.');

        $this->snapshotVersion($definitionId, 'Transition added: ' . $from . ' → ' . $to);
        rto_json_ok(['transition_id' => (int)$this->db->insert_id], 'Transition added.');
    }

    // ── AJAX: set/replace a transition's guard condition ────────────────────
    //
    // FIX (Known Limitations — workflows: guard_condition_json was a
    // structurally-present, functionally inert placeholder): evaluateGuard()
    // in WorkflowEngineService already delegates to the real
    // ConditionGroupEvaluator (a flat AND'ed list of {field,op,value} rules
    // against the lead row) — that half was genuine and working. What was
    // missing was any admin UI to actually SET guard_condition_json, and
    // addTransition() never accepted it either (fixed above). This endpoint
    // is the missing piece: lets an admin attach or clear a guard on an
    // EXISTING transition without deleting and recreating it.
    public function updateTransitionGuard(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['transition_id'] ?? 0, 1);
        if (!$id) rto_json_err('A transition is required.');

        $guardJson = self::buildGuardJson($_POST);
        if ($guardJson === false) rto_json_err('Invalid guard condition — check the field/operator/value rows.');

        $updated = $this->db->update(
            $this->p . 'rto_workflow_transitions',
            ['guard_condition_json' => $guardJson],
            ['id' => $id]
        );
        if ($updated === false) rto_json_err('Could not save the guard condition. Please try again.');

        $transitionDefId = (int)$this->db->get_var($this->db->prepare("SELECT workflow_definition_id FROM {$this->p}rto_workflow_transitions WHERE id=%d", $id));
        if ($transitionDefId) $this->snapshotVersion($transitionDefId, 'Transition #' . $id . ' guard updated');

        rto_json_ok(['transition_id' => $id, 'guard_condition_json' => $guardJson],
            $guardJson === null ? 'Guard condition cleared.' : 'Guard condition saved.');
    }

    /**
     * Builds guard_condition_json from up to 3 field/op/value rows submitted
     * as guard_field[], guard_op[], guard_value[] — ANDed together (the same
     * flat-list shape ConditionGroupEvaluator/evaluateGuard() has always
     * treated as an implicit top-level AND). Blank rows (no field selected)
     * are skipped. Returns null when every row is blank (no guard — always
     * passes, same as today's default), a JSON string when at least one
     * valid rule was submitted, or false if a row is malformed (used field
     * name outside the known lead-column allowlist, or an operator outside
     * ConditionGroupEvaluator's known set) — the caller must treat false as
     * a validation failure and save nothing, never silently drop the bad row.
     *
     * @return string|null|false
     */
    private static function buildGuardJson(array $post)
    {
        $fields = (array)($post['guard_field'] ?? []);
        $ops    = (array)($post['guard_op']    ?? []);
        $values = (array)($post['guard_value'] ?? []);

        $validOps = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'not_empty'];
        // The lead-row columns a guard can realistically be evaluated against —
        // LeadService::updateStatus() passes the full $lead row (plus lead_id)
        // as $context, so any real rto_leads column works; this allowlist
        // exists only to keep the UI's dropdown meaningful and to reject
        // typos/garbage rather than silently saving a guard that can never
        // match anything.
        $validFields = [
            'status', 'priority', 'total_amount', 'city_id', 'service_id',
            'vendor_id', 'client_id', 'risk_score', 'source', 'lead_id',
        ];

        $conditions = [];
        $count = max(count($fields), count($ops), count($values));
        for ($i = 0; $i < $count; $i++) {
            $field = Sanitiser::text($fields[$i] ?? '', 64);
            if ($field === '') continue; // blank row, skip silently

            $op = Sanitiser::text($ops[$i] ?? 'equals', 32);
            if (!in_array($field, $validFields, true)) return false;
            if (!in_array($op, $validOps, true)) return false;

            $rawValue = $values[$i] ?? '';
            $value = ($op === 'not_empty') ? null : (is_numeric($rawValue) ? (float)$rawValue : Sanitiser::text((string)$rawValue, 255));

            $conditions[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }

        if (empty($conditions)) return null;
        return wp_json_encode($conditions);
    }

    // ── AJAX: delete a transition ───────────────────────────────────────────
    public function deleteTransition(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['transition_id'] ?? 0, 1);
        $transition = $this->db->get_row($this->db->prepare("SELECT workflow_definition_id FROM {$this->p}rto_workflow_transitions WHERE id=%d", $id), ARRAY_A);
        $deleted = $this->db->delete($this->p . 'rto_workflow_transitions', ['id' => $id]);
        if (!$deleted) rto_json_err('Transition not found or already removed.', 404);

        if ($transition) $this->snapshotVersion((int)$transition['workflow_definition_id'], 'Transition #' . $id . ' deleted');
        rto_json_ok(null, 'Transition removed.');
    }

    // ENTERPRISE GAP FIX (Phase 4, item 8 — "no versioning/rollback for
    // ... Workflow Definitions"): keyed per-definition-id, snapshotting
    // BOTH its states and transitions together as one payload — a
    // meaningful "version" of a workflow is the whole graph, not either
    // half alone, so a rollback must restore both in the same operation
    // or the two tables can disagree (a transition referencing a
    // status_key the restored state list no longer has).
    private function snapshotVersion(int $definitionId, string $note): void
    {
        if (!$definitionId) return;
        $states = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->p}rto_workflow_states WHERE workflow_definition_id=%d ORDER BY display_order, id", $definitionId
        ), ARRAY_A) ?: [];
        $transitions = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->p}rto_workflow_transitions WHERE workflow_definition_id=%d ORDER BY id", $definitionId
        ), ARRAY_A) ?: [];

        $versionId = \RTOFLOW\Services\ConfigVersionService::saveDraft(
            'workflow_definition_' . $definitionId,
            ['states' => $states, 'transitions' => $transitions],
            get_current_user_id() ?: 0,
            $note
        );
        if ($versionId) {
            \RTOFLOW\Services\ConfigVersionService::publish($versionId, false);
        }
    }

    // ── Rollback target: called from Bootstrap.php's
    // 'rtoflow_config_published' listener when config_key matches
    // 'workflow_definition_{id}'. Full-table restore for both states and
    // transitions of that one definition — deletes anything not in the
    // snapshot, same "restore, don't merge" semantics as every other
    // applyVersionedPayload() in this codebase.
    public function applyVersionedPayload(int $definitionId, array $payload): void
    {
        $statesTable = $this->p . 'rto_workflow_states';
        $transTable  = $this->p . 'rto_workflow_transitions';

        $this->db->delete($statesTable, ['workflow_definition_id' => $definitionId]);
        foreach (($payload['states'] ?? []) as $row) {
            if (!is_array($row)) continue;
            unset($row['id']);
            $row['workflow_definition_id'] = $definitionId;
            $this->db->insert($statesTable, $row);
        }

        $this->db->delete($transTable, ['workflow_definition_id' => $definitionId]);
        foreach (($payload['transitions'] ?? []) as $row) {
            if (!is_array($row)) continue;
            unset($row['id']);
            $row['workflow_definition_id'] = $definitionId;
            $this->db->insert($transTable, $row);
        }

        \RTOFLOW\Services\AuditService::log('workflow_definition.version_restored', 0, ['definition_id' => $definitionId]);
    }
}
