<?php

namespace RTOFLOW\Services;

use RTOFLOW\Repositories\FormRepository;

if (!defined('ABSPATH')) exit;

/**
 * Dynamic Form Engine v2 — Enterprise Form Builder backend
 *
 * ORIGIN: v1 of this engine (see git history / gap-fix-and-scalability-plan.md
 * Part 1.3) found rto_form_schemas + FormRepository already existed with zero
 * real callers, and built a flat field-list schema + admin CRUD around them.
 * v2 (this version) was commissioned to bring that up to enterprise
 * form-builder parity — multi-step layout, nested AND/OR conditional logic,
 * dependent dropdowns, document checklists, field-level validation, and
 * layout controls — while staying on the SAME underlying table
 * (rto_form_schemas.steps_json, still a LONGTEXT JSON blob) and the SAME
 * versioning mechanism (a new row per save, is_active flag flips, version
 * number increments) rather than introducing a second, disconnected schema
 * store. See docs/FORM_ENGINE_V2.md for the full schema reference and the
 * migration path for schemas saved under v1's flatter shape.
 *
 * SCHEMA SHAPE (decoded from steps_json):
 *   [
 *     'meta' => ['title' => string],
 *     'steps' => [
 *       ['key' => string, 'label' => string, 'fields' => [ <field>, ... ]],
 *       ...
 *     ],
 *     'documents' => [ <document requirement>, ... ],
 *   ]
 *
 * FIELD SHAPE:
 *   [
 *     'key'          => string,  unique across the WHOLE schema (not just
 *                                the step) — this is both the submitted
 *                                answer's array key and what visible_if /
 *                                dependent_source / document conditions
 *                                reference, so cross-step conditions work.
 *     'label'        => string,
 *     'type'         => one of FormEngineService::TYPES,
 *     'required'     => bool,
 *     'placeholder'  => string,
 *     'help_text'    => string,
 *     'width'        => 'full'|'half'|'third',
 *     'order'        => int (ascending, within its step),
 *     'options'      => array<string,string>  value => label
 *                       (select/radio/checkbox/multiselect only),
 *     'validation'   => ['min'=>?float,'max'=>?float,'min_length'=>?int,
 *                         'max_length'=>?int,'pattern'=>?string],
 *     'dependent_source' => null|['parent_field'=>string,'source'=>string]
 *                       — 'source' is a registered key in
 *                       DependentSourceRegistry (e.g. 'rto_offices_by_state');
 *                       the field's options are resolved at render time from
 *                       the current value of parent_field, not stored here.
 *     'visible_if'   => null|<condition group>
 *   ]
 *
 * CONDITION GROUP (nested AND/OR — powers "complex AND/OR rules, nested
 * conditions, multiple dependencies"):
 *   [
 *     'logic' => 'AND'|'OR',
 *     'rules'  => [ ['field'=>string,'op'=>string,'value'=>mixed], ... ],
 *     'groups' => [ <nested condition group>, ... ],   // recursive
 *   ]
 *   Ops: equals, not_equals, in, not_in, contains, filled, empty,
 *        greater_than, less_than.
 *   A bare legacy v1 shape (['field'=>..,'equals'=>..]) is still accepted
 *   and normalised into a single-rule AND group — existing v1 schemas keep
 *   working unmodified.
 *
 * DOCUMENT REQUIREMENT SHAPE — powers "conditional document uploads and
 * dynamically generated document checklists":
 *   [
 *     'doc_type_id' => int,     FK rto_doc_types.id (the shared document
 *                               catalog already used by the client
 *                               dashboard's document list, so a checklist
 *                               entry here and an uploaded document there
 *                               are the same real record, not a parallel
 *                               concept),
 *     'label'       => string,  override label for this form (falls back
 *                               to rto_doc_types.name if blank),
 *     'required'    => bool,
 *     'visible_if'  => null|<condition group>,
 *   ]
 */
class FormEngineService
{
    public const TYPES = [
        'text', 'textarea', 'number', 'tel', 'email', 'select', 'radio',
        'checkbox', 'multiselect', 'date', 'time', 'file', 'address',
        'dependent_select', 'consent', 'heading',
    ];

    /** Field types that render as a value-carrying input included in submitted answers. */
    public const ANSWER_TYPES = [
        'text', 'textarea', 'number', 'tel', 'email', 'select', 'radio',
        'checkbox', 'multiselect', 'date', 'time', 'file', 'address',
        'dependent_select', 'consent',
    ];

    public const OPTION_TYPES = ['select', 'radio', 'checkbox', 'multiselect'];
    public const VALID_OPS = ['equals', 'not_equals', 'in', 'not_in', 'contains', 'filled', 'empty', 'greater_than', 'less_than'];
    public const VALID_WIDTHS = ['full', 'half', 'third'];

    /**
     * Recognised field ROLES (P1 fix — see resolveByRole()/apply-form
     * contract note in Router::submitApplyDynamic()).
     *
     * PROBLEM THIS SOLVES: before this, "which submitted field is the
     * applicant's city" (or vehicle type, mobile, email, name...) was
     * decided by GUESSING from the field's key/label text — a naming
     * heuristic, not a contract. A dynamic Form Builder schema is free to
     * name its city field anything ("location_x7", "seller_city",
     * "town_field") and the guess would silently miss it, leaving the lead
     * created with a blank/default city and eligibility rules referencing
     * 'city' never matching against a dynamic submission that never had a
     * literal 'city' key.
     *
     * FIX: a field definition can now declare an explicit, optional 'role'
     * — e.g. 'role' => 'city' — independent of its key/label. Resolution
     * (resolveByRole() below) looks for a field with the declared role
     * FIRST; only when no field in the schema declares that role does it
     * fall back to the old key-name heuristic, so every schema saved before
     * this change keeps behaving exactly as it did (backward compatible).
     *
     * This list is intentionally not enforced as exhaustive — normaliseField()
     * accepts any lowercase snake_case string as a role so an admin can
     * invent a new one for a rule they're configuring — but these are the
     * roles this codebase's own resolvers (Router::submitApplyDynamic(),
     * EligibilityService rule authors) know to look for by name.
     */
    public const KNOWN_ROLES = ['city', 'vehicle_type', 'mobile', 'email', 'full_name', 'rto_state', 'rto_office'];

    public function __construct(private FormRepository $forms) {}

    /**
     * The schema currently active for a service, decoded and normalised
     * (v1 flat-field-list schemas are transparently upgraded to the v2
     * {meta,steps,documents} shape on read — see migrateV1Shape()). Returns
     * null if the service has no custom schema yet, OR if the form_builder
     * feature flag is off — callers must fall back to their static/legacy
     * field list in either case.
     */
    public function getForService(int $serviceId): ?array
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('form_builder')) {
            return null;
        }
        $row = $this->forms->find_by_service($serviceId);
        if (!$row) return null;
        return $this->decorate($row);
    }

    /**
     * Same lookup as getForService(), but WITHOUT the form_builder flag gate.
     *
     * BUG FOUND AND FIXED this pass: FormBuilderController::edit() (the admin
     * builder screen) was calling getForService() — meaning an admin with the
     * form_builder flag turned off could not even open the builder to see or
     * edit an already-saved schema, since it would silently look empty
     * (getForService() returning null is indistinguishable from "no schema
     * exists yet"). An admin building or revising a form is a purely
     * internal action and must never depend on the flag that only controls
     * whether the PUBLIC apply-form renderer is switched on — otherwise
     * turning the flag off to pause the public rollout would also lock the
     * admin out of their own draft. Use this method for anything admin-
     * facing (edit screen, history, restore); keep getForService() only for
     * code that decides whether to show something to a customer.
     */
    public function getForServiceForAdmin(int $serviceId): ?array
    {
        $row = $this->forms->find_by_service($serviceId);
        if (!$row) return null;
        return $this->decorate($row);
    }

    public function get(int $id): ?array
    {
        $row = $this->forms->find($id);
        if (!$row) return null;
        return $this->decorate($row);
    }

    // ── Category-level API (Part 4.10) ──────────────────────────────────
    // Replaces "one schema per real service" with "one schema per real
    // category" (Driving License, RC Services, HP/Hypothecation, NOC,
    // Vehicle Services, Commercial Vehicle, Other Services — the same 7
    // categories apply.php's own step-1 category buttons already use).
    // A category schema's fields always include one designated
    // "service picker" field (see CATEGORY_PICKER_KEY convention in
    // RealFormSchemaSeeder) plus every field for every real service in
    // that category, each non-picker field carrying a visible_if rule
    // keyed on the picker field's value — exactly the reference plugin's
    // "one form, N services, cond-gated fields" shape. The service_id-based
    // methods above are kept for backward compatibility with anything
    // still reading old per-service rows; nothing new writes through them.

    public function getForCategory(string $category): ?array
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('form_builder')) {
            return null;
        }
        $row = $this->forms->find_by_category($category);
        if (!$row) return null;
        return $this->decorate($row);
    }

    /** Same as getForCategory() but without the flag gate — for admin-facing screens (edit/history/restore), same reasoning as getForServiceForAdmin(). */
    public function getForCategoryForAdmin(string $category): ?array
    {
        $row = $this->forms->find_by_category($category);
        if (!$row) return null;
        return $this->decorate($row);
    }

    public function historyForCategory(string $category): array
    {
        return array_map([$this, 'decorate'], $this->forms->all_for_category($category));
    }

    public function allCategoriesWithSchemas(): array
    {
        return $this->forms->all_categories_with_schemas();
    }

    /** All schemas (any service, any version), newest first — for the admin list. */
    public function all(): array
    {
        return array_map([$this, 'decorate'], $this->forms->all());
    }

    /** Every prior version (active or archived) for one service, newest first — for the version-history panel. */
    public function historyForService(int $serviceId): array
    {
        return array_map([$this, 'decorate'], $this->forms->all_for_service($serviceId));
    }

    private function decorate(array $row): array
    {
        $decoded = json_decode($row['steps_json'] ?? '[]', true);
        if (!is_array($decoded)) $decoded = [];
        $schema = $this->isV1Shape($decoded) ? $this->migrateV1Shape($decoded) : $decoded;

        $row['meta']      = $schema['meta'] ?? ['title' => $row['name'] ?? ''];
        $row['steps']     = $schema['steps'] ?? [];
        $row['documents'] = $schema['documents'] ?? [];
        // Flat convenience list — every field across every step, in step
        // order then field order — for callers that don't care about steps
        // (e.g. EligibilityService-style "does this schema have field X").
        $row['all_fields'] = [];
        foreach ($row['steps'] as $step) {
            foreach ($step['fields'] ?? [] as $f) $row['all_fields'][] = $f;
        }
        return $row;
    }

    /** A v1 schema decoded from steps_json is a plain list of field arrays (no 'steps' key at the top level). */
    private function isV1Shape(array $decoded): bool
    {
        if (isset($decoded['steps']) || isset($decoded['meta'])) return false;
        // Empty array or a numerically-indexed list of field-shaped arrays.
        return $decoded === [] || array_is_list($decoded);
    }

    /**
     * Upgrade a v1 (flat field list) schema into the v2 shape at read time,
     * so every existing v1 schema keeps rendering and editing correctly
     * without a forced re-save. All v1 fields land in a single synthetic
     * step so the multi-step UI still has something to show.
     */
    private function migrateV1Shape(array $v1Fields): array
    {
        $fields = array_map(function ($f) {
            $f['width'] ??= 'full';
            $f['placeholder'] ??= '';
            $f['validation'] ??= [];
            $f['dependent_source'] ??= null;
            if (isset($f['visible_if']) && is_array($f['visible_if']) && isset($f['visible_if']['field']) && !isset($f['visible_if']['logic'])) {
                $f['visible_if'] = $this->legacyConditionToGroup($f['visible_if']);
            }
            return $f;
        }, $v1Fields);

        return [
            'meta'      => ['title' => ''],
            'steps'     => $fields ? [['key' => 'default', 'label' => 'Details', 'fields' => $fields]] : [],
            'documents' => [],
        ];
    }

    private function legacyConditionToGroup(array $legacy): array
    {
        return [
            'logic'  => 'AND',
            'rules'  => [['field' => $legacy['field'], 'op' => 'equals', 'value' => $legacy['equals'] ?? '']],
            'groups' => [],
        ];
    }

    // ── Validation / normalisation ──────────────────────────────────────────

    /**
     * Validate + normalise an admin-submitted schema (steps + documents)
     * into the canonical v2 shape, ready to be json-encoded into
     * steps_json. Throws \InvalidArgumentException with a human-readable
     * message on the first problem found, so the controller can surface it
     * via rto_json_err instead of silently saving something broken.
     */
    public function normaliseSchema(array $rawSchema): array
    {
        $rawSteps = $rawSchema['steps'] ?? [];
        if (!is_array($rawSteps) || empty($rawSteps)) {
            throw new \InvalidArgumentException('A form needs at least one step.');
        }

        $allKeys = [];
        $rolesSeen = [];
        $steps = [];
        $stepKeysSeen = [];

        foreach (array_values($rawSteps) as $si => $rawStep) {
            $stepKey = trim((string)($rawStep['key'] ?? ''));
            $stepLabel = trim((string)($rawStep['label'] ?? ''));
            if ($stepKey === '') throw new \InvalidArgumentException('Step #' . ($si + 1) . ' is missing a key.');
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $stepKey)) {
                throw new \InvalidArgumentException("Step key \"{$stepKey}\" must be lowercase letters, numbers and underscores, starting with a letter.");
            }
            if (isset($stepKeysSeen[$stepKey])) throw new \InvalidArgumentException("Step key \"{$stepKey}\" is used more than once.");
            $stepKeysSeen[$stepKey] = true;
            if ($stepLabel === '') throw new \InvalidArgumentException("Step \"{$stepKey}\" is missing a label.");

            $fields = [];
            foreach (array_values((array)($rawStep['fields'] ?? [])) as $fi => $rawField) {
                $field = $this->normaliseField($rawField, $fi);
                if (in_array($field['key'], $allKeys, true)) {
                    throw new \InvalidArgumentException("Field key \"{$field['key']}\" is used more than once across the whole form (keys must be unique form-wide, not just per step).");
                }
                if ($field['role'] !== null) {
                    if (isset($rolesSeen[$field['role']])) {
                        throw new \InvalidArgumentException("Role \"{$field['role']}\" is declared on both \"{$rolesSeen[$field['role']]}\" and \"{$field['key']}\" — a role must resolve to exactly one field per form.");
                    }
                    $rolesSeen[$field['role']] = $field['key'];
                }
                $allKeys[] = $field['key'];
                $fields[] = $field;
            }

            $steps[] = ['key' => $stepKey, 'label' => $stepLabel, 'order' => (int)($rawStep['order'] ?? $si * 10), 'fields' => $fields];
        }
        usort($steps, fn($a, $b) => $a['order'] <=> $b['order']);

        // Second pass: every visible_if / dependent_source reference must
        // point at a field key that actually exists somewhere in the form.
        foreach ($steps as $step) {
            foreach ($step['fields'] as $f) {
                $this->assertConditionFieldsExist($f['visible_if'] ?? null, $allKeys, $f['key']);
                if ($f['dependent_source'] && !in_array($f['dependent_source']['parent_field'], $allKeys, true)) {
                    throw new \InvalidArgumentException("Field \"{$f['key']}\" depends on unknown field \"{$f['dependent_source']['parent_field']}\".");
                }
            }
        }

        $documents = [];
        foreach (array_values((array)($rawSchema['documents'] ?? [])) as $di => $rawDoc) {
            $docTypeId = (int)($rawDoc['doc_type_id'] ?? 0);
            if ($docTypeId < 1) throw new \InvalidArgumentException('Document requirement #' . ($di + 1) . ' has no document type selected.');
            $visibleIf = $this->normaliseCondition($rawDoc['visible_if'] ?? null, $allKeys, "document requirement #" . ($di + 1));
            $documents[] = [
                'doc_type_id' => $docTypeId,
                'label'       => trim((string)($rawDoc['label'] ?? '')),
                'required'    => !empty($rawDoc['required']),
                'visible_if'  => $visibleIf,
            ];
        }

        return [
            'meta'      => ['title' => trim((string)($rawSchema['meta']['title'] ?? ''))],
            'steps'     => $steps,
            'documents' => $documents,
        ];
    }

    private function normaliseField(array $f, int $i): array
    {
        $key   = trim((string)($f['key'] ?? ''));
        $label = trim((string)($f['label'] ?? ''));
        $type  = (string)($f['type'] ?? 'text');

        if ($key === '') throw new \InvalidArgumentException('Field #' . ($i + 1) . ' is missing a key.');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException("Field key \"{$key}\" must be lowercase letters, numbers and underscores, starting with a letter.");
        }
        if ($type !== 'heading' && $label === '') throw new \InvalidArgumentException("Field \"{$key}\" is missing a label.");
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Field \"{$key}\" has an invalid type \"{$type}\".");
        }

        // Optional explicit ROLE (P1 fix — see KNOWN_ROLES docblock above).
        // Same slug shape as a field key; empty/absent is the normal case
        // and simply means "no declared role, resolvers fall back to their
        // key-name heuristic for this field".
        $role = trim((string)($f['role'] ?? ''));
        if ($role !== '' && !preg_match('/^[a-z][a-z0-9_]*$/', $role)) {
            throw new \InvalidArgumentException("Field \"{$key}\" has an invalid role \"{$role}\" (must be lowercase letters, numbers and underscores, starting with a letter).");
        }
        $role = $role !== '' ? $role : null;

        $width = (string)($f['width'] ?? 'full');
        if (!in_array($width, self::VALID_WIDTHS, true)) $width = 'full';

        $options = [];
        if (in_array($type, self::OPTION_TYPES, true)) {
            foreach ((array)($f['options'] ?? []) as $ov => $ol) {
                if (is_array($ol)) {
                    $v = trim((string)($ol['value'] ?? ''));
                    $l = trim((string)($ol['label'] ?? $v));
                } else {
                    $v = trim((string)$ov);
                    $l = trim((string)$ol);
                }
                if ($v !== '') $options[$v] = $l !== '' ? $l : $v;
            }
            if (empty($options)) {
                throw new \InvalidArgumentException("Field \"{$key}\" ({$type}) needs at least one option.");
            }
        }

        $dependentSource = null;
        if ($type === 'dependent_select') {
            $parent = trim((string)($f['dependent_source']['parent_field'] ?? ''));
            $source = trim((string)($f['dependent_source']['source'] ?? ''));
            if ($parent === '' || $source === '') {
                throw new \InvalidArgumentException("Field \"{$key}\" is a dependent dropdown but has no parent field / data source configured.");
            }
            if (!\RTOFLOW\Support\DependentSourceRegistry::has($source)) {
                throw new \InvalidArgumentException("Field \"{$key}\" references unknown dependent-dropdown source \"{$source}\".");
            }
            $dependentSource = ['parent_field' => $parent, 'source' => $source];
        }

        $rawValidation = (array)($f['validation'] ?? []);
        $validation = [
            'min'        => isset($rawValidation['min']) && $rawValidation['min'] !== '' ? (float)$rawValidation['min'] : null,
            'max'        => isset($rawValidation['max']) && $rawValidation['max'] !== '' ? (float)$rawValidation['max'] : null,
            'min_length' => isset($rawValidation['min_length']) && $rawValidation['min_length'] !== '' ? max(0, (int)$rawValidation['min_length']) : null,
            'max_length' => isset($rawValidation['max_length']) && $rawValidation['max_length'] !== '' ? max(0, (int)$rawValidation['max_length']) : null,
            'pattern'    => trim((string)($rawValidation['pattern'] ?? '')) ?: null,
        ];
        if ($validation['pattern'] !== null) {
            // Reject a pattern that isn't valid PCRE now, at save time, rather
            // than failing every single render/validate call later.
            set_error_handler(fn() => true);
            $ok = @preg_match('/' . str_replace('/', '\/', $validation['pattern']) . '/', '');
            restore_error_handler();
            if ($ok === false) {
                throw new \InvalidArgumentException("Field \"{$key}\" has an invalid validation pattern.");
            }
        }

        return [
            'key'              => $key,
            'label'            => $label,
            'type'             => $type,
            'required'         => !empty($f['required']),
            'placeholder'      => trim((string)($f['placeholder'] ?? '')),
            'help_text'        => trim((string)($f['help_text'] ?? '')),
            'width'            => $width,
            'order'            => (int)($f['order'] ?? $i * 10),
            'options'          => $options,
            'validation'       => $validation,
            'dependent_source' => $dependentSource,
            // FIX (Part 4.13 — matching the reference plugin's Edit Field
            // modal, which has both of these as explicit checkboxes):
            // 'active' lets an admin hide a field from every renderer
            // without deleting it (past submissions that used this key stay
            // readable/exportable even though the field is no longer
            // asked). 'show_label' renders the field's input with no
            // visible <label> (still submitted under its key) — for a case
            // like a single checkbox whose option text already reads as the
            // label. Both default to true so every existing schema (none of
            // which set these) behaves exactly as before this change.
            'active'           => !array_key_exists('active', $f) || (bool)$f['active'],
            'show_label'       => !array_key_exists('show_label', $f) || (bool)$f['show_label'],
            'role'             => $role,
            // visible_if is validated for referenced-field-existence in a
            // second pass (assertConditionFieldsExist) once every field key
            // in the whole form is known — a field can legally depend on a
            // field defined later in the list or in a later step.
            'visible_if'       => $this->normaliseConditionShapeOnly($f['visible_if'] ?? null),
        ];
    }

    /** First pass: normalise shape/ops only — field-existence is checked once all keys are known. */
    private function normaliseConditionShapeOnly($raw): ?array
    {
        if (empty($raw) || !is_array($raw)) return null;
        if (isset($raw['field']) && !isset($raw['logic'])) {
            $raw = $this->legacyConditionToGroup($raw);
        }
        return $this->normaliseGroupShape($raw);
    }

    private function normaliseGroupShape(array $group): array
    {
        $logic = strtoupper((string)($group['logic'] ?? 'AND'));
        if (!in_array($logic, ['AND', 'OR'], true)) $logic = 'AND';

        $rules = [];
        foreach ((array)($group['rules'] ?? []) as $r) {
            $field = trim((string)($r['field'] ?? ''));
            $op    = (string)($r['op'] ?? 'equals');
            if ($field === '') continue;
            if (!in_array($op, self::VALID_OPS, true)) $op = 'equals';
            $rules[] = ['field' => $field, 'op' => $op, 'value' => $r['value'] ?? ''];
        }

        $groups = [];
        foreach ((array)($group['groups'] ?? []) as $g) {
            if (is_array($g)) $groups[] = $this->normaliseGroupShape($g);
        }

        return ['logic' => $logic, 'rules' => $rules, 'groups' => $groups];
    }

    /** Second pass: recursively confirm every rule's 'field' exists in $allKeys. */
    private function assertConditionFieldsExist(?array $group, array $allKeys, string $ownerKey): void
    {
        if (!$group) return;
        foreach ($group['rules'] as $r) {
            if (!in_array($r['field'], $allKeys, true)) {
                throw new \InvalidArgumentException("Field \"{$ownerKey}\" has a visibility rule referencing unknown field \"{$r['field']}\".");
            }
        }
        foreach ($group['groups'] as $g) {
            $this->assertConditionFieldsExist($g, $allKeys, $ownerKey);
        }
    }

    /** Used for document requirements, which are validated against $allKeys immediately (documents come after all fields). */
    private function normaliseCondition($raw, array $allKeys, string $ownerLabel): ?array
    {
        $group = $this->normaliseConditionShapeOnly($raw);
        $this->assertConditionFieldsExist($group, $allKeys, $ownerLabel);
        return $group;
    }

    // ── Runtime evaluation (shared by the public renderer and server-side re-validation) ──

    /**
     * Evaluate a condition group against a flat answers array (submitted
     * form data, or the current in-progress values on the client). Used
     * both by the public JS renderer's server-mirrored logic (so the exact
     * same rule shape drives client-side show/hide AND a server-side
     * double-check) and, in the future, by any admin preview.
     */
    /**
     * Resolve the answer for a conceptual ROLE (e.g. 'city', 'vehicle_type')
     * against a set of submitted $answers, given the schema's flattened
     * field list ($allFields — e.g. $schema['all_fields'] from decorate()).
     *
     * REAL CONTRACT FIRST: if any field in $allFields declares
     * 'role' === $role, its submitted value (keyed by that field's actual
     * 'key') wins — this is correct regardless of what the field is named,
     * because the schema author told us explicitly what it is.
     *
     * FALLBACK ONLY WHEN NO FIELD DECLARES THE ROLE: for schemas saved
     * before roles existed (or where an admin hasn't bothered declaring one
     * for a role nothing depends on), fall through to the OLD heuristic —
     * try each of $fallbackKeys in order against $answers, same behaviour
     * this codebase already had. This keeps every existing schema working
     * unchanged; it only stops being used the moment a schema opts in by
     * declaring the role explicitly.
     *
     * @param array<int,array> $allFields   flattened field defs (with 'role','key')
     * @param array            $answers     submitted answers, keyed by field key
     * @param string           $role        the role being resolved, e.g. 'city'
     * @param array<int,string> $fallbackKeys legacy key names to guess from, in priority order
     * @return array{value:mixed,source:string,field_key:?string} source is 'role', 'fallback', or 'none'
     */
    public static function resolveByRole(array $allFields, array $answers, string $role, array $fallbackKeys = []): array
    {
        foreach ($allFields as $f) {
            if (($f['role'] ?? null) === $role) {
                return [
                    'value'     => $answers[$f['key']] ?? null,
                    'source'    => 'role',
                    'field_key' => $f['key'],
                ];
            }
        }
        foreach ($fallbackKeys as $k) {
            if (isset($answers[$k]) && $answers[$k] !== '') {
                return ['value' => $answers[$k], 'source' => 'fallback', 'field_key' => $k];
            }
        }
        return ['value' => null, 'source' => 'none', 'field_key' => null];
    }

    /**
     * Delegates to the shared ConditionGroupEvaluator (see that class's
     * docblock for the full consolidation writeup). FormEngineService's own
     * {"logic","rules","groups"} shape is translated by
     * ConditionGroupEvaluator::normaliseFormEngineGroup() into the canonical
     * ['operator','conditions'] tree and evaluated with FormEngineService's
     * exact original op semantics (evaluateFormEngineLeaf(), a byte-for-byte
     * copy of the AND/OR loop and match() arms formerly kept here) — no
     * duplicate boolean-logic evaluation loop lives in this class anymore.
     */
    public static function evaluateCondition(?array $group, array $answers): bool
    {
        return ConditionGroupEvaluator::evaluate($group, $answers);
    }

    /**
     * The document checklist that applies given a set of submitted/current
     * answers — every configured requirement whose visible_if evaluates
     * true, resolved against the shared rto_doc_types catalog for
     * display name/format/size-limit metadata.
     */
    public function resolveDocumentChecklist(array $schema, array $answers): array
    {
        global $wpdb;
        $docTypeIds = array_column($schema['documents'] ?? [], 'doc_type_id');
        if (empty($docTypeIds)) return [];

        $placeholders = implode(',', array_fill(0, count($docTypeIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_doc_types WHERE id IN ({$placeholders})",
            $docTypeIds
        ), ARRAY_A) ?: [];
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r;

        $checklist = [];
        foreach ($schema['documents'] as $doc) {
            if (!self::evaluateCondition($doc['visible_if'], $answers)) continue;
            $catalog = $byId[$doc['doc_type_id']] ?? null;
            $checklist[] = [
                'doc_type_id' => $doc['doc_type_id'],
                'label'       => $doc['label'] !== '' ? $doc['label'] : ($catalog['name'] ?? 'Document'),
                'required'    => $doc['required'],
                'formats'     => $catalog['formats'] ?? 'pdf,jpg,png',
                'max_size_mb' => (int)($catalog['max_size_mb'] ?? 5),
            ];
        }
        return $checklist;
    }

    // ── Write ────────────────────────────────────────────────────────────

    /**
     * Create a new version of a service's schema and make it active,
     * deactivating whatever was active before (rows are never deleted, so
     * this doubles as the version history the admin UI's "restore a
     * previous version" control reads from — see FormBuilderController::
     * restoreVersion()). Also records a ConfigVersionService entry and an
     * AuditService log line, matching the pattern already proven for
     * Matching/Feature Flags/City Pricing (see gap-fix-and-scalability-plan.md
     * Part 1.3's "Config Versioning wiring" follow-ups) — a form schema is
     * exactly the same kind of "admin-configured, needs history + rollback"
     * surface those are.
     */
    public function save(int $serviceId, string $name, array $rawSchema, ?int $userId = null, string $versionNote = ''): int
    {
        $schema = $this->normaliseSchema($rawSchema);

        $existing = $this->forms->find_by_service($serviceId);
        $nextVersion = $existing ? ((int)$existing['version'] + 1) : 1;

        if ($existing) {
            global $wpdb;
            // Common-mistakes audit fix: this deactivation's result was
            // discarded. find_by_service() (Repositories.php) does a plain
            // WHERE service_id=? AND is_active=1 with no ORDER BY and takes
            // first() — if this write silently failed, the new row inserted
            // below would leave TWO active schemas for the same service,
            // and which one a customer actually gets served on /rto-apply/
            // would be undefined DB row-order behavior, not necessarily the
            // new one. Fail loudly instead of risking that.
            $deactivated = $wpdb->update($wpdb->prefix . 'rto_form_schemas', ['is_active' => 0], ['id' => (int)$existing['id']]);
            if ($deactivated === false) {
                throw new \RuntimeException('Could not deactivate the previous form version: ' . $wpdb->last_error);
            }
        }

        $id = $this->forms->create([
            'service_id' => $serviceId,
            'name'       => $name,
            'steps_json' => wp_json_encode($schema),
            'version'    => $nextVersion,
            'is_active'  => 1,
            'created_by' => $userId,
            'created_at' => current_time('mysql'),
        ]);

        AuditService::log('form_schema.saved', null, [
            'service_id' => $serviceId, 'schema_id' => $id, 'version' => $nextVersion, 'name' => $name,
        ]);

        $versionId = ConfigVersionService::saveDraft(
            'form_schema_' . $serviceId,
            ['schema_id' => $id, 'name' => $name, 'schema' => $schema],
            $userId ?: 0,
            $versionNote ?: ('Saved via Form Builder (v' . $nextVersion . ')')
        );
        if ($versionId) {
            ConfigVersionService::publish($versionId, false);
        }

        return $id;
    }

    public function toggleActive(int $serviceId, int $schemaId, bool $active): bool
    {
        global $wpdb;
        if ($active) {
            // Only one active schema per service — deactivate the rest first.
            // Common-mistakes audit fix: same ghost-success risk as save()
            // above — a silently-failed deactivation here would leave two
            // active rows for this service.
            $deactivated = $wpdb->update($wpdb->prefix . 'rto_form_schemas', ['is_active' => 0], ['service_id' => $serviceId]);
            if ($deactivated === false) {
                throw new \RuntimeException('Could not deactivate the other schema versions for this service: ' . $wpdb->last_error);
            }
        }
        $ok = (bool)$wpdb->update($wpdb->prefix . 'rto_form_schemas', ['is_active' => $active ? 1 : 0], ['id' => $schemaId]);
        AuditService::log($active ? 'form_schema.activated' : 'form_schema.deactivated', null, [
            'service_id' => $serviceId, 'schema_id' => $schemaId,
        ]);
        return $ok;
    }

    /**
     * Restore an older version: clones that version's schema into a brand
     * new, active version (never rewrites history), matching the same
     * "rollback = new version, not a mutation" contract ConfigVersionService
     * itself uses for its own history table.
     */
    public function restoreVersion(int $serviceId, int $schemaId, ?int $userId = null): ?int
    {
        $old = $this->forms->find($schemaId);
        if (!$old || (int)$old['service_id'] !== $serviceId) return null;
        $decoded = json_decode($old['steps_json'] ?? '[]', true);
        $schema = is_array($decoded) && $this->isV1Shape($decoded) ? $this->migrateV1Shape($decoded) : $decoded;
        return $this->save($serviceId, $old['name'], $schema, $userId, 'Restored from version ' . $old['version']);
    }

    // ── Category-level write API (Part 4.10) ────────────────────────────
    // Same versioning/audit contract as save()/restoreVersion() above,
    // keyed by `category` instead of `service_id`. This is the path the
    // Form Builder now actually uses — see RealFormSchemaSeeder for the
    // migrated content and FormBuilderController for the admin screens.

    public function saveForCategory(string $category, string $name, array $rawSchema, ?int $userId = null, string $versionNote = ''): int
    {
        $schema = $this->normaliseSchema($rawSchema);

        $existing = $this->forms->find_by_category($category);
        $nextVersion = $existing ? ((int)$existing['version'] + 1) : 1;

        if ($existing) {
            global $wpdb;
            // Common-mistakes audit fix: same ghost-success risk documented
            // on save() above, and the one that actually matters most —
            // this is the category-keyed path FormBuilderController::save()
            // actually calls. find_by_category() takes first() with no
            // ORDER BY, so two active rows here means an undefined (not
            // necessarily the newest) form version is served on /rto-apply/.
            $deactivated = $wpdb->update($wpdb->prefix . 'rto_form_schemas', ['is_active' => 0], ['id' => (int)$existing['id']]);
            if ($deactivated === false) {
                throw new \RuntimeException('Could not deactivate the previous form version: ' . $wpdb->last_error);
            }
        }

        $id = $this->forms->create([
            'category'   => $category,
            'service_id' => null,
            'name'       => $name,
            'steps_json' => wp_json_encode($schema),
            'version'    => $nextVersion,
            'is_active'  => 1,
            'created_by' => $userId,
            'created_at' => current_time('mysql'),
        ]);

        AuditService::log('form_schema.saved', null, [
            'category' => $category, 'schema_id' => $id, 'version' => $nextVersion, 'name' => $name,
        ]);

        $versionId = ConfigVersionService::saveDraft(
            'form_schema_category_' . sanitize_title($category),
            ['schema_id' => $id, 'name' => $name, 'schema' => $schema],
            $userId ?: 0,
            $versionNote ?: ('Saved via Form Builder (v' . $nextVersion . ')')
        );
        if ($versionId) {
            ConfigVersionService::publish($versionId, false);
        }

        return $id;
    }

    public function toggleActiveForCategory(string $category, int $schemaId, bool $active): bool
    {
        global $wpdb;
        if ($active) {
            // Common-mistakes audit fix: same ghost-success risk as above.
            $deactivated = $wpdb->update($wpdb->prefix . 'rto_form_schemas', ['is_active' => 0], ['category' => $category]);
            if ($deactivated === false) {
                throw new \RuntimeException('Could not deactivate the other form versions for this category: ' . $wpdb->last_error);
            }
        }
        $ok = (bool)$wpdb->update($wpdb->prefix . 'rto_form_schemas', ['is_active' => $active ? 1 : 0], ['id' => $schemaId]);
        AuditService::log($active ? 'form_schema.activated' : 'form_schema.deactivated', null, [
            'category' => $category, 'schema_id' => $schemaId,
        ]);
        return $ok;
    }

    public function restoreVersionForCategory(string $category, int $schemaId, ?int $userId = null): ?int
    {
        $old = $this->forms->find($schemaId);
        if (!$old || (string)($old['category'] ?? '') !== $category) return null;
        $decoded = json_decode($old['steps_json'] ?? '[]', true);
        $schema = is_array($decoded) && $this->isV1Shape($decoded) ? $this->migrateV1Shape($decoded) : $decoded;
        return $this->saveForCategory($category, $old['name'], $schema, $userId, 'Restored from version ' . $old['version']);
    }

    // ── Delete a single version (gap fix — see migration 18) ────────────────
    // Distinct from delete()/toggleActiveForCategory()'s "deactivate the
    // WHOLE category" behavior: this soft-deletes exactly ONE version row,
    // the one specific action the version-history panel never offered.
    // The currently-active version cannot be deleted directly — an admin
    // must activate a different version (or category delete) first, the
    // same "don't leave the category with no active schema and no signal
    // why" discipline toggleActiveForCategory() already enforces elsewhere.
    // Returns a result array rather than bool so the controller can surface
    // a specific reason.
    public function deleteVersionForCategory(string $category, int $schemaId): array
    {
        $row = $this->forms->find($schemaId);
        if (!$row || (string)($row['category'] ?? '') !== $category) {
            return ['success' => false, 'message' => 'That version could not be found.'];
        }
        if (!empty($row['deleted_at'])) {
            return ['success' => false, 'message' => 'That version was already deleted.'];
        }
        if (!empty($row['is_active'])) {
            return ['success' => false, 'message' => 'This is the active version — activate a different version before deleting it.'];
        }

        $ok = $this->forms->soft_delete($schemaId);
        if (!$ok) return ['success' => false, 'message' => 'Failed to delete that version.'];

        AuditService::log('form_schema.version_deleted', null, [
            'category' => $category, 'schema_id' => $schemaId, 'version' => $row['version'] ?? null,
        ]);
        return ['success' => true, 'message' => 'Version ' . ($row['version'] ?? $schemaId) . ' deleted.'];
    }
}
