<?php
/**
 * Real-execution harness for the field 'role' contract fix.
 *
 * Loads the ACTUAL source files (FormEngineService.php, EligibilityService.php)
 * via require — no reimplementation, no mocking of the classes under test.
 * Only WordPress/DB globals are faked, since this sandbox has no live MySQL.
 *
 * Proves:
 *  1. A schema with an explicit role ("role":"city") resolves correctly
 *     regardless of the field's actual key/label — even a field named
 *     "location_x7" with no "city" in its key or label at all.
 *  2. A legacy schema with NO explicit role still resolves via the old
 *     fallback heuristic (backward compatible).
 *  3. EligibilityService::evaluate() correctly evaluates a rule using the
 *     role-resolved value from a dynamic submission (i.e. once the caller
 *     has aliased the role into the answers map, exactly as
 *     Router::submitApplyDynamic() now does).
 */

define('ABSPATH', __DIR__ . '/');

// ── Minimal real WordPress function stubs (not the class under test) ──────
function wp_json_encode($v) { return json_encode($v); }
function current_time($fmt) { return date('Y-m-d H:i:s'); }
define('ARRAY_A', 'ARRAY_A');

// ── Fake $wpdb: real execution against an in-memory PHP array, not a mock
// of EligibilityService itself — EligibilityService's actual SQL-shaped
// calls (get_results/prepare/insert) run for real against this fake driver.
class FakeWpdb
{
    public string $prefix = 'wp_';
    public array $rules = [];
    public array $inserted = [];

    public function prepare($query, ...$args) { return [$query, $args]; }

    public function get_results($prepared, $output = null)
    {
        // Our only SELECT in EligibilityService::evaluate() is "rules for
        // this service, active, ordered" — return the configured rules.
        return $this->rules;
    }

    public function get_var($x) { return null; }

    public function insert($table, $data)
    {
        $this->inserted[] = ['table' => $table, 'data' => $data];
        return 1;
    }
}

require __DIR__ . '/../app/Repositories/FormRepository.php';
require __DIR__ . '/../app/Services/FormEngineService.php';
require __DIR__ . '/../app/Services/EligibilityService.php';

use RTOFLOW\Services\FormEngineService;
use RTOFLOW\Services\EligibilityService;

// FormEngineService's constructor takes a FormRepository we never call
// through in this harness (normaliseSchema/resolveByRole don't touch it),
// so a bare stdClass typed away via a throwaway subclass is enough.
$engine = new class(new class extends \RTOFLOW\Repositories\FormRepository {
    public function __construct() {}
}) extends FormEngineService {};

$fail = 0;
function check(string $name, bool $cond, $detail = null) {
    global $fail;
    if ($cond) {
        echo "PASS: $name\n";
    } else {
        $fail++;
        echo "FAIL: $name" . ($detail !== null ? ' -- ' . print_r($detail, true) : '') . "\n";
    }
}

// ═══════════════════════════════════════════════════════════════════════
// TEST 1: explicit role resolves correctly even with an unrelated field
// key/label ("location_x7" declares role => "city").
// ═══════════════════════════════════════════════════════════════════════
$schemaWithRole = [
    'meta' => ['title' => 'Roled Schema'],
    'steps' => [[
        'key' => 'details', 'label' => 'Details', 'order' => 0,
        'fields' => [
            ['key' => 'location_x7', 'label' => 'Enter a value', 'type' => 'text', 'role' => 'city'],
            ['key' => 'mobile_num', 'label' => 'Contact', 'type' => 'tel', 'role' => 'mobile'],
        ],
    ]],
    'documents' => [],
];
$normalised = $engine->normaliseSchema($schemaWithRole);
check('normaliseSchema() accepts and preserves an explicit role', $normalised['steps'][0]['fields'][0]['role'] === 'city', $normalised);

$allFields = $normalised['steps'][0]['fields'];
$answers = ['location_x7' => 'Jaipur', 'mobile_num' => '9876543210'];

$resolved = FormEngineService::resolveByRole($allFields, $answers, 'city', ['city']); // fallback key 'city' does NOT exist in $answers at all
check(
    'resolveByRole(): explicit role finds "location_x7" as the city field despite its name having nothing to do with "city"',
    $resolved['value'] === 'Jaipur' && $resolved['source'] === 'role' && $resolved['field_key'] === 'location_x7',
    $resolved
);

// ═══════════════════════════════════════════════════════════════════════
// TEST 2: legacy schema, NO explicit role anywhere -> falls back to the
// old key-name heuristic (backward compatible).
// ═══════════════════════════════════════════════════════════════════════
$legacySchema = [
    'meta' => ['title' => 'Legacy Schema'],
    'steps' => [[
        'key' => 'details', 'label' => 'Details', 'order' => 0,
        'fields' => [
            // No 'role' key at all -- exactly what every schema saved
            // before this fix looks like.
            ['key' => 'city', 'label' => 'City', 'type' => 'text'],
        ],
    ]],
    'documents' => [],
];
$legacyNormalised = $engine->normaliseSchema($legacySchema);
check('normaliseSchema(): a field with no role at all normalises to role=null', $legacyNormalised['steps'][0]['fields'][0]['role'] === null, $legacyNormalised);

$legacyFields = $legacyNormalised['steps'][0]['fields'];
$legacyAnswers = ['city' => 'Mumbai'];
$legacyResolved = FormEngineService::resolveByRole($legacyFields, $legacyAnswers, 'city', ['city', 'town']);
check(
    'resolveByRole(): no field declares the role -> falls back to key-name heuristic and still finds it',
    $legacyResolved['value'] === 'Mumbai' && $legacyResolved['source'] === 'fallback' && $legacyResolved['field_key'] === 'city',
    $legacyResolved
);

// And a role nobody declares AND no matching fallback key -> resolves to
// nothing, rather than silently guessing wrong.
$noneResolved = FormEngineService::resolveByRole($legacyFields, $legacyAnswers, 'vehicle_type', ['vehicle_type', 'veh_type']);
check('resolveByRole(): no role, no fallback match -> source "none", value null', $noneResolved['value'] === null && $noneResolved['source'] === 'none', $noneResolved);

// Duplicate-role rejection: two fields declaring the same role in one
// schema must be rejected at save time (ambiguous contract), not silently
// accepted.
$dupSchema = [
    'meta' => ['title' => 'Dup role'],
    'steps' => [[
        'key' => 'details', 'label' => 'Details', 'order' => 0,
        'fields' => [
            ['key' => 'a_city', 'label' => 'A', 'type' => 'text', 'role' => 'city'],
            ['key' => 'b_city', 'label' => 'B', 'type' => 'text', 'role' => 'city'],
        ],
    ]],
    'documents' => [],
];
$dupRejected = false;
try {
    $engine->normaliseSchema($dupSchema);
} catch (\InvalidArgumentException $e) {
    $dupRejected = str_contains($e->getMessage(), 'Role "city"');
}
check('normaliseSchema(): rejects two fields declaring the same role', $dupRejected);

// Invalid role shape rejected same as an invalid key would be.
$badRoleSchema = [
    'meta' => ['title' => 'Bad role'],
    'steps' => [[
        'key' => 'details', 'label' => 'Details', 'order' => 0,
        'fields' => [
            ['key' => 'x', 'label' => 'X', 'type' => 'text', 'role' => 'Not Valid!'],
        ],
    ]],
    'documents' => [],
];
$badRoleRejected = false;
try {
    $engine->normaliseSchema($badRoleSchema);
} catch (\InvalidArgumentException $e) {
    $badRoleRejected = str_contains($e->getMessage(), 'invalid role');
}
check('normaliseSchema(): rejects a malformed role string', $badRoleRejected);

// ═══════════════════════════════════════════════════════════════════════
// TEST 3: EligibilityService::evaluate() correctly evaluates a rule using
// the role-resolved value from a DYNAMIC submission -- i.e. once the
// caller (mirroring Router::submitApplyDynamic()'s new alias step) has
// resolved the role into the answers map under its role name, exactly as
// production code now does, evaluate() (completely unmodified logic,
// running for real) fires the rule correctly even though the submission's
// real field key ("location_x7") never appears in the rule at all.
// ═══════════════════════════════════════════════════════════════════════
$wpdb = new FakeWpdb();
$wpdb->rules = [[
    'id' => 1, 'service_id' => 42, 'is_active' => 1, 'priority' => 1,
    'field_key' => 'city', 'operator' => 'equals', 'value_json' => json_encode('Jaipur'),
    'field_label' => 'City', 'fail_message' => 'Service not available in your city.',
    'severity' => 'block',
]];
global $wpdb; // EligibilityService::evaluate() reads `global $wpdb`

// Simulate exactly what Router::submitApplyDynamic() now does: resolve the
// 'city' role from the dynamic submission and alias it into $answers under
// 'city' (the rule's field_key) before calling evaluate() -- the field's
// REAL key ("location_x7") is left untouched alongside it.
$dynamicAnswers = ['location_x7' => 'Jaipur', 'mobile_num' => '9876543210'];
$cityResolved = FormEngineService::resolveByRole($allFields, $dynamicAnswers, 'city', ['city']);
if ($cityResolved['value'] !== null) {
    $dynamicAnswers['city'] = $cityResolved['value']; // <- the fix's alias step
}

$eligibility = new EligibilityService();
$result = $eligibility->evaluate(42, $dynamicAnswers, null);
check(
    'EligibilityService::evaluate(): rule field_key="city" matches the role-resolved value from a dynamic submission whose real field is "location_x7"',
    $result['eligible'] === true && empty($result['blocking_failures']),
    $result
);
check('EligibilityService::evaluate(): still records one audit row via $wpdb->insert()', count($wpdb->inserted) === 1);

// Negative control: WITHOUT the role-resolution/alias step (the old,
// broken behaviour), the raw dynamic answers have no 'city' key at all --
// evaluate() must correctly REJECT since the rule can't find the field
// under a hardcoded static name.
$wpdbBroken = new FakeWpdb();
$wpdbBroken->rules = $wpdb->rules;
$wpdb = $wpdbBroken;
$brokenResult = $eligibility->evaluate(42, ['location_x7' => 'Jaipur'], null); // NOT aliased -- reproduces the pre-fix bug
check(
    'Negative control: without role resolution, the same rule fails to find the value under "city" (reproduces the pre-fix bug)',
    $brokenResult['eligible'] === false && $brokenResult['blocking_failures'][0]['field_key'] === 'city',
    $brokenResult
);

echo "\n" . ($fail === 0 ? "ALL PASSED" : "$fail FAILURE(S)") . "\n";
exit($fail === 0 ? 0 : 1);
