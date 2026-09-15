<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Shared nested AND/OR condition-group evaluator.
 *
 * Gap fix: the workflow guard evaluator (WorkflowEngineService::evaluateGuard)
 * only ever understood a FLAT, implicit-AND list of {field,op,value}
 * conditions — "AND of (a OR b) AND c" style rules could not be expressed at
 * all in guard_condition_json. This class adds a real nested structure:
 *
 *   {"operator": "AND"|"OR", "conditions": [ ...rule-or-group... ]}
 *
 * where each entry in "conditions" is EITHER a leaf rule
 * {"field":..., "op":..., "value":...} OR another nested group of the same
 * shape — recursively, to any depth.
 *
 * Backward compatibility (REQUIRED — do not break existing saved data):
 * every guard_condition_json ever saved before this change is a flat JSON
 * list, e.g. [{"field":"amount","op":"gt","value":100}, {...}], with NO
 * "operator"/"conditions" wrapper at all. That shape is treated as an
 * implicit top-level AND group — exactly the semantics evaluateGuard()
 * already had — so every existing saved guard keeps behaving identically.
 *
 * CONSOLIDATION STATUS (updated):
 * originally there were THREE separately-implemented condition evaluators.
 * This class now shares its recursive boolean-logic evaluation across two
 * of them, accepting BOTH their JSON shapes:
 *   1. FormEngineService::evaluateCondition() — form field visible_if /
 *      document visible_if, shape {"logic":"AND"|"OR","rules":[...],
 *      "groups":[...]} (rules and sub-groups kept in separate arrays, not
 *      one "conditions" list). normaliseFormEngineGroup() translates this
 *      shape into the canonical ['operator','conditions'] tree — a lossless
 *      container-shape translation only. Leaf comparison semantics are NOT
 *      merged into the workflow op set below (the two vocabularies share
 *      names like 'in'/'not_in' with different comparison semantics —
 *      string-cast vs loose == — and FormEngineService has ops
 *      (contains/filled/empty/greater_than/less_than) with no workflow
 *      equivalent), so form-engine leaves are tagged engine=>'formengine'
 *      and evaluated by evaluateFormEngineLeaf(), a byte-for-byte copy of
 *      the original FormEngineService::evaluateRule(). One evaluator, two
 *      accepted container shapes, zero duplicated AND/OR tree-walking code
 *      — the leaf comparison logic legitimately stays separate because it
 *      is semantically different, not because it was left unconsolidated.
 *   2. WorkflowEngineService::evaluateGuard() — the flat list extended to
 *      real nesting (the original purpose of this class).
 * Deliberately NOT touched:
 *   3. EligibilityService::evaluate()/check() — NOT JSON-tree-based at all.
 *      Each eligibility rule is its own ROW in rto_eligibility_rules
 *      (field_key/operator/value_json/severity columns), evaluated
 *      independently; there is no condition JSON blob to nest in the first
 *      place, so this format doesn't apply to it without a schema change
 *      (a real, separate piece of work — out of scope here to avoid
 *      papering over a data-model difference by force-fitting one format).
 */
class ConditionGroupEvaluator
{
    /**
     * @param mixed $raw   Decoded JSON: either a flat list of leaf rules
     *                      (legacy shape, implicit AND) or a nested
     *                      {"operator":"AND"|"OR","conditions":[...]} group.
     * @param array $context Field values to evaluate rules against.
     */
    public static function evaluate($raw, array $context): bool
    {
        if (empty($raw) || !is_array($raw)) return true; // no condition = always passes

        $group = self::normalise($raw);
        return self::evaluateGroup($group, $context);
    }

    /**
     * Normalise either shape into a canonical
     * ['operator' => 'AND'|'OR', 'conditions' => [leaf-or-group, ...]] tree.
     */
    private static function normalise(array $raw): array
    {
        // Nested-group shape: has an explicit operator/conditions wrapper.
        if (isset($raw['conditions']) && is_array($raw['conditions'])) {
            $operator = strtoupper((string)($raw['operator'] ?? 'AND'));
            if (!in_array($operator, ['AND', 'OR'], true)) $operator = 'AND';

            $conditions = [];
            foreach ($raw['conditions'] as $entry) {
                if (!is_array($entry)) continue;
                $conditions[] = self::isGroup($entry) ? self::normalise($entry) : self::normaliseLeaf($entry);
            }
            return ['operator' => $operator, 'conditions' => $conditions];
        }

        // FormEngineService shape: {"logic":"AND"|"OR","rules":[...],"groups":[...]}
        // — rules and nested sub-groups kept in two separate arrays instead of
        // one "conditions" list, and leaf rules use FormEngineService's OWN op
        // vocabulary (equals/not_equals/in/not_in/contains/filled/empty/
        // greater_than/less_than) with its own comparison semantics (string-
        // cast equality, [] treated as "empty", etc). Detected by the presence
        // of 'logic', 'rules' or 'groups' — none of which collide with the
        // {"operator","conditions"} or flat-list shapes above/below. Leaves
        // are tagged engine=>'formengine' so evaluateLeaf() reproduces
        // FormEngineService::evaluateRule()'s exact original semantics rather
        // than silently reinterpreting them under the workflow op set.
        if (isset($raw['logic']) || isset($raw['rules']) || isset($raw['groups'])) {
            return self::normaliseFormEngineGroup($raw);
        }

        // Legacy flat-list shape: a plain JSON array of leaf rules, no
        // operator/conditions wrapper at all — implicit top-level AND,
        // exactly as evaluateGuard() behaved before this change.
        $isList = array_keys($raw) === range(0, count($raw) - 1);
        if ($isList) {
            $conditions = [];
            foreach ($raw as $entry) {
                if (!is_array($entry)) continue;
                $conditions[] = self::isGroup($entry) ? self::normalise($entry) : self::normaliseLeaf($entry);
            }
            return ['operator' => 'AND', 'conditions' => $conditions];
        }

        // A single bare leaf rule (not wrapped in a list at all).
        return ['operator' => 'AND', 'conditions' => [self::normaliseLeaf($raw)]];
    }

    private static function isGroup(array $entry): bool
    {
        return isset($entry['conditions']) && is_array($entry['conditions']);
    }

    private static function normaliseLeaf(array $entry): array
    {
        return [
            'engine' => 'workflow',
            'field' => (string)($entry['field'] ?? ''),
            'op'    => (string)($entry['op'] ?? 'equals'),
            'value' => $entry['value'] ?? null,
        ];
    }

    /**
     * Translate a FormEngineService {"logic","rules","groups"} group into the
     * canonical ['operator','conditions'] tree, recursively. Lossless: every
     * rule and every nested group is preserved, only the container shape
     * changes (rules+groups -> one merged conditions list); leaf semantics
     * are preserved separately via the 'formengine' engine tag, not by
     * reinterpreting the op under the workflow evaluator's rules.
     */
    private static function normaliseFormEngineGroup(array $raw): array
    {
        $operator = strtoupper((string)($raw['logic'] ?? 'AND'));
        if (!in_array($operator, ['AND', 'OR'], true)) $operator = 'AND';

        $conditions = [];
        foreach ((array)($raw['rules'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $conditions[] = self::normaliseFormEngineLeaf($r);
        }
        foreach ((array)($raw['groups'] ?? []) as $g) {
            if (is_array($g)) $conditions[] = self::normaliseFormEngineGroup($g);
        }
        return ['operator' => $operator, 'conditions' => $conditions];
    }

    private static function normaliseFormEngineLeaf(array $entry): array
    {
        return [
            'engine' => 'formengine',
            'field'  => (string)($entry['field'] ?? ''),
            'op'     => (string)($entry['op'] ?? 'equals'),
            'value'  => $entry['value'] ?? null,
        ];
    }

    private static function evaluateGroup(array $group, array $context): bool
    {
        if (empty($group['conditions'])) return true;

        $results = [];
        foreach ($group['conditions'] as $entry) {
            $results[] = isset($entry['conditions'])
                ? self::evaluateGroup($entry, $context)
                : self::evaluateLeaf($entry, $context);
        }

        return $group['operator'] === 'OR'
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    private static function evaluateLeaf(array $rule, array $context): bool
    {
        if (($rule['engine'] ?? 'workflow') === 'formengine') {
            return self::evaluateFormEngineLeaf($rule, $context);
        }

        if ($rule['field'] === '') return true;

        $value    = $context[$rule['field']] ?? null;
        $operator = $rule['op'];
        $expected = $rule['value'];

        return match ($operator) {
            'equals', '='       => $value == $expected,
            'not_equals', '!='  => $value != $expected,
            'gt', '>'           => is_numeric($value) && is_numeric($expected) && (float)$value > (float)$expected,
            'gte', '>='         => is_numeric($value) && is_numeric($expected) && (float)$value >= (float)$expected,
            'lt', '<'           => is_numeric($value) && is_numeric($expected) && (float)$value < (float)$expected,
            'lte', '<='         => is_numeric($value) && is_numeric($expected) && (float)$value <= (float)$expected,
            'in'                => is_array($expected) && in_array($value, $expected),
            'not_in'            => is_array($expected) && !in_array($value, $expected),
            'not_empty'         => !empty($value),
            default             => true,
        };
    }

    /**
     * Byte-for-byte the same comparison semantics as the original
     * FormEngineService::evaluateRule() (string-cast (in)equality, [] counted
     * as "empty", its own op vocabulary) — kept as a separate branch rather
     * than folded into the workflow op set above, since the two vocabularies
     * overlap in name ('in'/'not_in') but not in comparison semantics
     * (string-cast vs loose ==), and FormEngineService has ops
     * (contains/filled/empty/greater_than/less_than) the workflow evaluator
     * has no equivalent for at all.
     */
    private static function evaluateFormEngineLeaf(array $rule, array $context): bool
    {
        $actual   = $context[$rule['field']] ?? null;
        $expected = $rule['value'];

        return match ($rule['op']) {
            'equals'       => (string)$actual === (string)$expected,
            'not_equals'   => (string)$actual !== (string)$expected,
            'in'           => in_array((string)$actual, array_map('strval', (array)$expected), true),
            'not_in'       => !in_array((string)$actual, array_map('strval', (array)$expected), true),
            'contains'     => str_contains((string)$actual, (string)$expected),
            'filled'       => $actual !== null && $actual !== '' && $actual !== [],
            'empty'        => $actual === null || $actual === '' || $actual === [],
            'greater_than' => is_numeric($actual) && (float)$actual > (float)$expected,
            'less_than'    => is_numeric($actual) && (float)$actual < (float)$expected,
            default        => false,
        };
    }
}
