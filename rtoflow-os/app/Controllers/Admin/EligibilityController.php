<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

/**
 * Admin CRUD for the Eligibility Rules Engine (see EligibilityService).
 *
 * This is the "configure, don't code" surface requested for the platform:
 * an admin adds a row here (service + field + operator + value + message)
 * and every applicant for that service is evaluated against it immediately,
 * with no developer involvement and no deployment.
 */
class EligibilityController
{
    private \wpdb $db;
    private string $p;

    public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix; }

    public function index(): void
    {
        $serviceId = Sanitiser::int($_GET['service_id'] ?? 0);

        $where = ['1=1']; $params = [];
        if ($serviceId) { $where[] = 'r.service_id=%d'; $params[] = $serviceId; }
        $ws = implode(' AND ', $where);

        $sql = "SELECT r.*, s.name AS service_name
                FROM {$this->p}rto_eligibility_rules r
                LEFT JOIN {$this->p}rto_services s ON s.id = r.service_id
                WHERE {$ws}
                ORDER BY r.service_id IS NULL, s.name, r.priority";
        $rules = empty($params)
            ? ($this->db->get_results($sql, ARRAY_A) ?: [])
            : ($this->db->get_results($this->db->prepare($sql, $params), ARRAY_A) ?: []);

        $services = $this->db->get_results(
            "SELECT id, name, category FROM {$this->p}rto_services WHERE is_active=1 ORDER BY category, name",
            ARRAY_A
        ) ?: [];

        // Recent eligibility checks — gives an admin visibility into who was
        // rejected and why, without needing a support ticket first.
        $recentChecks = $this->db->get_results(
            "SELECT ec.*, s.name AS service_name
             FROM {$this->p}rto_eligibility_checks ec
             LEFT JOIN {$this->p}rto_services s ON s.id = ec.service_id
             ORDER BY ec.checked_at DESC LIMIT 25",
            ARRAY_A
        ) ?: [];

        rto_view('admin.eligibility.index', compact('rules', 'services', 'serviceId', 'recentChecks'));
    }

    // ── AJAX: create rule ────────────────────────────────────────────────────
    public function store(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $serviceId  = Sanitiser::int($_POST['service_id'] ?? 0);
        $fieldKey   = Sanitiser::text($_POST['field_key']   ?? '');
        $fieldLabel = Sanitiser::text($_POST['field_label'] ?? '');
        $operator   = Sanitiser::text($_POST['operator']    ?? 'equals');
        $rawValue   = Sanitiser::text($_POST['value']       ?? '');
        $severity   = Sanitiser::text($_POST['severity']    ?? 'block');
        $message    = Sanitiser::text($_POST['fail_message']?? '', 500);
        $priority   = Sanitiser::int($_POST['priority'] ?? 10, 0, 999);

        $validOperators = ['equals','not_equals','gt','gte','lt','lte','in','not_in','not_empty'];
        if (!$fieldKey || !$fieldLabel) rto_json_err('Field key and label are required.');
        if (!in_array($operator, $validOperators, true)) rto_json_err('Invalid operator.');
        if (!in_array($severity, ['block', 'warn'], true)) rto_json_err('Invalid severity.');
        if (!$message) rto_json_err('Failure message is required — applicants must be told why they were blocked.');

        // 'in'/'not_in' take a comma-separated list; everything else is a
        // single scalar. Numeric-looking values are stored as numbers so
        // gt/gte/lt/lte comparisons work without extra casting at eval time.
        $valueJson = in_array($operator, ['in', 'not_in'], true)
            ? wp_json_encode(array_map('trim', explode(',', $rawValue)))
            : wp_json_encode(is_numeric($rawValue) ? $rawValue + 0 : $rawValue);

        $inserted = $this->db->insert($this->p . 'rto_eligibility_rules', [
            'service_id'   => $serviceId ?: null,
            'field_key'    => $fieldKey,
            'field_label'  => $fieldLabel,
            'operator'     => $operator,
            'value_json'   => $valueJson,
            'severity'     => $severity,
            'fail_message' => $message,
            'priority'     => $priority,
            'is_active'    => 1,
            'created_by'   => get_current_user_id(),
            'created_at'   => current_time('mysql'),
        ]);

        if (!$inserted) rto_json_err('Could not save the rule. Please try again.');

        \RTOFLOW\Services\AuditService::log('eligibility_rule.created', 0, [
            'rule_id' => (int)$this->db->insert_id, 'service_id' => $serviceId ?: null, 'field_key' => $fieldKey,
        ]);
        $this->snapshotVersion('Rule added: ' . $fieldLabel);
        rto_json_ok(['rule_id' => (int)$this->db->insert_id], 'Eligibility rule added.');
    }

    // ── AJAX: toggle active/inactive ─────────────────────────────────────────
    public function toggle(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['rule_id'] ?? 0, 1);
        $rule = $this->db->get_row($this->db->prepare(
            "SELECT id, is_active FROM {$this->p}rto_eligibility_rules WHERE id=%d", $id
        ), ARRAY_A);
        if (!$rule) rto_json_err('Rule not found.', 404);

        $newState = $rule['is_active'] ? 0 : 1;
        $updated = $this->db->update($this->p . 'rto_eligibility_rules', ['is_active' => $newState], ['id' => $id]);
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): this always reported success back to
        // the admin regardless of whether the DB write actually happened.
        if ($updated === false) rto_json_err('Could not update the rule. Please try again.', 500);
        \RTOFLOW\Services\AuditService::log('eligibility_rule.toggled', 0, ['rule_id' => $id, 'is_active' => $newState]);
        $this->snapshotVersion('Rule #' . $id . ' ' . ($newState ? 'enabled' : 'disabled'));
        rto_json_ok(['is_active' => $newState], $newState ? 'Rule enabled.' : 'Rule disabled.');
    }

    // ── AJAX: impact preview before toggling (Help Centre Phase 1 gap:
    // "impact preview before saving") — real count, computed from the
    // exact same recorded check data the "Recent Eligibility Checks" card
    // below already displays, never a fabricated estimate. Writes nothing.
    public function previewToggle(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['rule_id'] ?? 0, 1);
        $impact = (new \RTOFLOW\Services\EligibilityService())->previewToggleImpact($id);
        rto_json_ok($impact);
    }

    // ── AJAX: delete rule ─────────────────────────────────────────────────────
    public function delete(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $id = Sanitiser::int($_POST['rule_id'] ?? 0, 1);
        $deleted = $this->db->delete($this->p . 'rto_eligibility_rules', ['id' => $id]);
        if (!$deleted) rto_json_err('Rule not found or already removed.', 404);

        \RTOFLOW\Services\AuditService::log('eligibility_rule.deleted', 0, ['rule_id' => $id]);
        $this->snapshotVersion('Rule #' . $id . ' deleted');
        rto_json_ok(null, 'Rule removed.');
    }

    // ── AJAX: bulk enable/disable every rule for one service ─────────────────
    // Known Limitations audit fix: "No bulk enable/disable for rules
    // belonging to the same service ... temporarily suspending all
    // eligibility checks for one service (e.g. during a policy review)
    // requires toggling each of that service's rules off individually."
    // service_id=0 targets the "All services" (service_id IS NULL) rules —
    // matching how the rule list itself groups them.
    public function bulkToggleForService(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $serviceId = Sanitiser::int($_POST['service_id'] ?? 0);
        $active    = !empty($_POST['active']) ? 1 : 0;

        $where = $serviceId > 0 ? ['service_id' => $serviceId] : ['service_id' => null];
        // $wpdb->update() cannot express "IS NULL" via its $where array, so
        // build that one case directly.
        if ($serviceId > 0) {
            $affected = $this->db->update($this->p . 'rto_eligibility_rules', ['is_active' => $active], $where);
        } else {
            $affected = $this->db->query($this->db->prepare(
                "UPDATE {$this->p}rto_eligibility_rules SET is_active=%d WHERE service_id IS NULL", $active
            ));
        }

        \RTOFLOW\Services\AuditService::log('eligibility_rule.bulk_toggled', 0, [
            'service_id' => $serviceId ?: null, 'is_active' => $active, 'affected' => $affected,
        ]);
        $this->snapshotVersion(($active ? 'Enabled' : 'Disabled') . ' all rules for ' . ($serviceId > 0 ? "service #{$serviceId}" : 'All services'));

        rto_json_ok(['affected' => (int)$affected], ($active ? 'Enabled' : 'Disabled') . ' ' . (int)$affected . ' rule(s).');
    }

    // ── Config Versioning wiring (Part 5.5) ─────────────────────────────────
    // FIX (Config Versioning wiring, Eligibility Rules): every mutation
    // (create/toggle/delete) previously wrote directly to
    // rto_eligibility_rules with no version history at all — unlike
    // MatchingConfig, Feature Flags, and City/Service Pricing, an admin had
    // no way to see "who changed this rule and when" beyond the generic
    // audit log, and no way to undo a bad rule change without manually
    // re-entering the old values. This mirrors the same pattern those three
    // already use: after every mutation, snapshot the FULL current rule set
    // (not a per-row diff — ConfigVersionService only understands one JSON
    // blob per config_key/version, see its docblock) as a new version under
    // config_key 'eligibility_rules', then publish(..., false) since the
    // table write above is already the live state — this call only records
    // history, exactly like MatchingConfig::save()'s trailing block.
    private function snapshotVersion(string $note): void
    {
        $allRules = $this->db->get_results(
            "SELECT * FROM {$this->p}rto_eligibility_rules ORDER BY id",
            ARRAY_A
        ) ?: [];

        $versionId = \RTOFLOW\Services\ConfigVersionService::saveDraft(
            'eligibility_rules', $allRules, get_current_user_id() ?: 0, $note
        );
        if ($versionId) {
            \RTOFLOW\Services\ConfigVersionService::publish($versionId, false);
        }
    }

    // ── Rollback target: re-applies a full past rule-set snapshot ──────────
    // Called ONLY from Bootstrap.php's 'rtoflow_config_published' listener
    // when config_key === 'eligibility_rules' and $fireApply was true (i.e.
    // the "activate an older version" / rollback path from the Version
    // History screen — see ConfigVersionController::rollback()). Mirrors
    // CityServiceConfigController::applyVersionedPayload(): replaces the
    // entire live table with the snapshot's exact rows (matched by id where
    // the snapshot's row still has one, inserted fresh otherwise) and
    // removes any row that exists live but not in the target snapshot, so a
    // rollback reproduces the older state exactly rather than only
    // overlaying it on top of whatever is live now.
    public function applyVersionedPayload(array $payload): void
    {
        $table = $this->p . 'rto_eligibility_rules';

        $existingIds = array_map('intval', $this->db->get_col("SELECT id FROM {$table}"));
        $payloadIds  = [];

        foreach ($payload as $row) {
            if (!is_array($row)) continue;
            $id = isset($row['id']) ? (int)$row['id'] : 0;
            unset($row['id']);
            $row['service_id'] = $row['service_id'] !== '' && $row['service_id'] !== null ? (int)$row['service_id'] : null;
            $row['priority']   = isset($row['priority']) ? (int)$row['priority'] : 10;
            $row['is_active']  = isset($row['is_active']) ? (int)$row['is_active'] : 1;
            $row['created_by'] = isset($row['created_by']) ? (int)$row['created_by'] : 0;

            if ($id > 0 && in_array($id, $existingIds, true)) {
                $this->db->update($table, $row, ['id' => $id]);
                $payloadIds[] = $id;
            } else {
                $this->db->insert($table, $row);
                $payloadIds[] = (int)$this->db->insert_id;
            }
        }

        foreach (array_diff($existingIds, $payloadIds) as $staleId) {
            $this->db->delete($table, ['id' => $staleId]);
        }
    }
}
