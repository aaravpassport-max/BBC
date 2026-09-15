<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Eligibility Rules Engine (P1 — was completely missing)
 *
 * BUILD ONCE, CONFIGURE AND REUSE: this is the single evaluator for every
 * RTO service's eligibility conditions. Adding a new rule for a new service
 * — "applicant must be 18+ for a New Driving Licence", "vehicle must be
 * under 15 years old for a Fitness Certificate renewal" — is an admin
 * action (a row in rto_eligibility_rules via EligibilityController), not a
 * new if/else block a developer writes into a controller.
 *
 * Deliberately mirrors the field/operator/value shape AutomationService
 * already uses for its own condition checks, so the codebase has one mental
 * model for "evaluate a condition against submitted data", not two.
 */
class EligibilityService
{
    /**
     * TRACE: called from Router::submitApply()/submitApplyV2() with the
     *        service being applied for and the raw form answers →
     *        loads active rules for this service (service-specific rules
     *        first, then rules that apply to every service) ordered by
     *        priority → evaluates each rule's operator against the matching
     *        answer key → splits failures into blocking vs warning →
     *        records the check (rto_eligibility_checks) for admin visibility
     *        into who was rejected and why →
     *        returns {eligible, blocking_failures, warnings}.
     *        Preconditions: $serviceId refers to an existing service.
     *        Postconditions: one row written to rto_eligibility_checks
     *        regardless of outcome (so a support agent can see every check,
     *        not only the ones that failed).
     *        Edge cases handled: no rules configured for a service (eligible
     *        by default — a service with no configured rules is not
     *        artificially blocked); answer key missing from submission
     *        (treated as null, most operators will correctly fail against it
     *        except not_equals/not_in, which is the same semantics
     *        AutomationService::check_conditions() already uses).
     *
     * CONTRACT (P1 fix): $answers is a field-VALUE map keyed by conceptual
     * ROLE name (e.g. 'city', 'vehicle_type', 'mobile') or by a static
     * form's own literal field key when that key already IS the role (the
     * static apply.php form's 'city'/'mobile'/etc. inputs are named exactly
     * that, so passing $_POST straight through, as submitApply()/
     * submitApplyV2() do, already satisfies this contract for those forms).
     * A rule's 'field_key' should be written against one of these role
     * names, not against a specific dynamic schema's arbitrary field key —
     * that way the SAME rule (e.g. field_key='city') fires identically
     * whether the submission came from the static form or from any Form
     * Builder v2 dynamic schema, no matter what that schema calls its city
     * field, PROVIDED the caller resolved roles into $answers before calling
     * evaluate(). Router::submitApplyDynamic() does this for dynamic
     * submissions via FormEngineService::resolveByRole() — it looks up the
     * field a schema explicitly declared with 'role' => 'city' first, and
     * only falls back to guessing from common key names when no field
     * declares the role, then aliases the resolved value into $answers
     * under the role's own name (e.g. $answers['city']) before calling this
     * method. evaluate() itself stays schema-agnostic on purpose — it only
     * ever reads $answers[$rule['field_key']], same as before; the fix is
     * entirely in what callers now put into $answers.
     */
    public function evaluate(int $serviceId, array $answers, ?int $clientId = null): array
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $rules = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}rto_eligibility_rules
             WHERE is_active = 1 AND (service_id = %d OR service_id IS NULL)
             ORDER BY (service_id IS NULL) ASC, priority ASC",
            $serviceId
        ), ARRAY_A) ?: [];

        $blocking = [];
        $warnings = [];

        foreach ($rules as $rule) {
            $value    = $answers[$rule['field_key']] ?? null;
            $expected = json_decode($rule['value_json'] ?? 'null', true);
            $passed   = $this->check($value, $rule['operator'], $expected);

            if (!$passed) {
                $entry = [
                    'rule_id'    => (int)$rule['id'],
                    'field_key'  => $rule['field_key'],
                    'field_label'=> $rule['field_label'],
                    'message'    => $rule['fail_message'],
                ];
                if ($rule['severity'] === 'block') {
                    $blocking[] = $entry;
                } else {
                    $warnings[] = $entry;
                }
            }
        }

        $result = [
            'eligible'          => empty($blocking),
            'blocking_failures' => $blocking,
            'warnings'          => $warnings,
        ];

        $wpdb->insert($p . 'rto_eligibility_checks', [
            'service_id'        => $serviceId,
            'client_id'         => $clientId ?: null,
            'answers_json'      => wp_json_encode($answers),
            'eligible'          => $result['eligible'] ? 1 : 0,
            'failed_rules_json' => wp_json_encode(array_merge($blocking, $warnings)),
            'checked_at'        => current_time('mysql'),
        ]);

        return $result;
    }

    private function check(mixed $value, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'equals'     => $this->normalize($value) == $this->normalize($expected),
            'not_equals' => $this->normalize($value) != $this->normalize($expected),
            'gt'         => is_numeric($value) && is_numeric($expected) && (float)$value > (float)$expected,
            'gte'        => is_numeric($value) && is_numeric($expected) && (float)$value >= (float)$expected,
            'lt'         => is_numeric($value) && is_numeric($expected) && (float)$value < (float)$expected,
            'lte'        => is_numeric($value) && is_numeric($expected) && (float)$value <= (float)$expected,
            'in'         => is_array($expected) && in_array($this->normalize($value), array_map([$this, 'normalize'], $expected), true),
            'not_in'     => is_array($expected) && !in_array($this->normalize($value), array_map([$this, 'normalize'], $expected), true),
            'not_empty'  => !empty($value),
            default      => true,
        };
    }

    private function normalize(mixed $v): mixed
    {
        if (is_string($v)) return strtolower(trim($v));
        return $v;
    }

    /**
     * Impact-preview-before-saving (Help Centre Phase 1 gap): before an
     * admin toggles a rule off (or on), show a REAL count of how many
     * recently-recorded applicants this rule actually affected — reusing
     * the exact same check() logic evaluate() already uses, replayed
     * against the real answers_json this codebase already stores in
     * rto_eligibility_checks for every submission (see evaluate() above),
     * never a fabricated estimate.
     *
     * Semantics: for a rule currently ACTIVE (about to be disabled),
     * "affected" counts recent checks whose failed_rules_json already
     * names this rule — i.e. real applicants who were actually blocked or
     * warned by it. For a rule currently INACTIVE (about to be enabled),
     * the rule wasn't evaluated at the time, so this re-runs the rule's
     * own operator/value against each recent check's stored answers_json
     * to see how many WOULD have failed it, going forward.
     *
     * @return array{sample_size:int, affected:int, severity:string, scope:string}
     */
    public function previewToggleImpact(int $ruleId): array
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $rule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$p}rto_eligibility_rules WHERE id=%d", $ruleId
        ), ARRAY_A);
        if (!$rule) return ['sample_size' => 0, 'affected' => 0, 'severity' => 'block', 'scope' => 'unknown'];

        $isActive  = (bool)$rule['is_active'];
        $serviceId = $rule['service_id'] !== null ? (int)$rule['service_id'] : null;

        // Same recency window as EligibilityController::index()'s "Recent
        // Eligibility Checks" card, so the number shown here is consistent
        // with what the admin can see on the same screen.
        $sql = $serviceId
            ? $wpdb->prepare(
                "SELECT answers_json, failed_rules_json FROM {$p}rto_eligibility_checks WHERE service_id=%d ORDER BY checked_at DESC LIMIT 200",
                $serviceId
              )
            : "SELECT answers_json, failed_rules_json FROM {$p}rto_eligibility_checks ORDER BY checked_at DESC LIMIT 200";
        $checks = $wpdb->get_results($sql, ARRAY_A) ?: [];

        $expected = json_decode($rule['value_json'] ?? 'null', true);
        $affected = 0;

        foreach ($checks as $chk) {
            if ($isActive) {
                // Rule already ran for this check — trust its own recorded
                // outcome rather than re-deriving it.
                $failed = json_decode($chk['failed_rules_json'] ?? '[]', true) ?: [];
                foreach ($failed as $f) {
                    if ((int)($f['rule_id'] ?? 0) === $ruleId) { $affected++; break; }
                }
            } else {
                $answers = json_decode($chk['answers_json'] ?? '[]', true) ?: [];
                $value   = $answers[$rule['field_key']] ?? null;
                if (!$this->check($value, $rule['operator'], $expected)) $affected++;
            }
        }

        return [
            'sample_size' => count($checks),
            'affected'    => $affected,
            'severity'    => $rule['severity'],
            'scope'       => $serviceId ? 'this service' : 'all services',
        ];
    }

    /**
     * List rules for a service, for the admin UI and for building a
     * client-side pre-check (e.g. disabling a submit button before the
     * server round-trip) without duplicating the evaluation logic.
     */
    public function rulesForService(int $serviceId): array
    {
        global $wpdb;
        $p = $wpdb->prefix;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$p}rto_eligibility_rules
             WHERE service_id = %d OR service_id IS NULL
             ORDER BY (service_id IS NULL) ASC, priority ASC",
            $serviceId
        ), ARRAY_A) ?: [];
    }
}
