<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * FNO_STRATEGY_VERSION_FIELDS_MAX - the bulk/array-accepting AJAX
 * endpoint size-limit audit (this session) found two genuine gaps
 * that FNO_RAW_TICK_BATCH_MAX / the journal-import 1000-row cap did
 * NOT already cover:
 *
 *   (a) fno_add_strategy_version_fn() decoded changedFields/oldValues/
 *       newValues straight from $_POST with no cap on element count -
 *       admin-only + rate-limited (20/window) bounds REPEATED abuse,
 *       but not a single oversized request.
 *   (b) fno_journal_add_fn()'s Layer B dual-write loop iterated
 *       $decoded['factors'] (from the client-supplied factor_snapshot
 *       JSON) with no cap, each entry becoming its own real DB insert -
 *       reachable by any logged-in user, not admin-only.
 *
 * Both now share FNO_STRATEGY_VERSION_FIELDS_MAX (300 - a deliberate
 * margin above the real 193-factor registry, the genuine ceiling on
 * how many distinct factor fields a legitimate payload could ever
 * contain). This test proves, against the REAL, unmodified function
 * bodies, that a payload at or under the cap is unaffected and a
 * payload over the cap is rejected (strategy version) or safely
 * truncated (journal factor dual-write - additive/non-fatal by design,
 * matching this codebase's existing "the JSON column is already the
 * authoritative record" reasoning for that block).
 *
 * Run with: php tests/php/BulkArrayEndpointSizeCapsTest.php
 */

class FakeWpdbCaps {
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 42;
    public $insert_calls = 0;
    private $nextId = 100;
    public function insert($table, $data) {
        $this->insert_calls++;
        $this->insert_id = $this->nextId++;
        return true;
    }
}

function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function fno_cap_text($v, $max = 4000) { return mb_substr((string) $v, 0, $max); }
function wp_json_encode($v) { return json_encode($v); }
$GLOBALS['__options'] = [];
function get_option($k, $default = false) { return $GLOBALS['__options'][$k] ?? $default; }
function update_option($k, $v, $autoload = null) { $GLOBALS['__options'][$k] = $v; return true; }
function get_current_user_id() { return 7; }
function is_user_logged_in() { return true; }
function current_user_can($cap) { return true; }
function check_ajax_referer($action, $key) { return true; }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
function fno_verify_app_nonce() { return true; }
function fno_verify_app_access() { return true; }
function fno_validate_symbol($s) { return in_array($s, ['NIFTY', 'BANKNIFTY', 'FINNIFTY'], true) ? $s : null; }
function fno_valid_symbols() { return ['NIFTY', 'BANKNIFTY', 'FINNIFTY']; }

class TestWPDieExceptionCaps extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieExceptionCaps(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieExceptionCaps(['success' => false, 'data' => $data]); }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
// Real FNO_STRATEGY_VERSION_FIELDS_MAX constant, defined at plugin
// top-level - pulled out by real regex from the real source (never
// hardcoded a second time here), same pattern RawTickIngestTest.php
// already uses for FNO_RAW_TICK_BATCH_MAX.
if (!preg_match("/define\('FNO_STRATEGY_VERSION_FIELDS_MAX',\s*(\d+)\)/", $pluginSource, $m)) {
    fwrite(STDERR, "FNO_STRATEGY_VERSION_FIELDS_MAX constant not found in fno-lab.php\n");
    exit(1);
}
define('FNO_STRATEGY_VERSION_FIELDS_MAX', (int) $m[1]);
foreach (['fno_add_strategy_version_fn', 'fno_journal_add_fn'] as $fn) {
    $start = strpos($pluginSource, "function $fn(");
    $end = strpos($pluginSource, "\n}", $start) + 2;
    eval(substr($pluginSource, $start, $end - $start));
}

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_add_strategy_version_fn (FNO_STRATEGY_VERSION_FIELDS_MAX on changedFields/oldValues/newValues) ===\n";

// At-limit changedFields: accepted.
$GLOBALS['__options'] = [];
$_POST = [
    'version' => 'v-at-limit',
    'changedFields' => json_encode(array_map(fn($i) => "factor_$i", range(1, FNO_STRATEGY_VERSION_FIELDS_MAX))),
    'oldValues' => '{}', 'newValues' => '{}',
];
$response = null;
try { fno_add_strategy_version_fn(); } catch (TestWPDieExceptionCaps $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'changedFields at exactly FNO_STRATEGY_VERSION_FIELDS_MAX (' . FNO_STRATEGY_VERSION_FIELDS_MAX . ', a generous margin above the real 193-factor registry) is accepted, not wrongly rejected');

// Under-limit: accepted.
$GLOBALS['__options'] = [];
$_POST = [
    'version' => 'v-under-limit',
    'changedFields' => json_encode(['rsi_regime', 'iv_percentile']),
    'oldValues' => json_encode(['rsi_regime' => 'neutral']), 'newValues' => json_encode(['rsi_regime' => 'bullish']),
];
$response = null;
try { fno_add_strategy_version_fn(); } catch (TestWPDieExceptionCaps $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, small, legitimate changedFields/oldValues/newValues payload is accepted');
assertTrue($response['data']['versions'][0]['changedFields'] === ['rsi_regime', 'iv_percentile'], 'the real changedFields values are stored correctly, unmodified by the cap');

// Over-limit changedFields: rejected, nothing persisted.
$GLOBALS['__options'] = [];
$_POST = [
    'version' => 'v-over-limit',
    'changedFields' => json_encode(array_map(fn($i) => "factor_$i", range(1, FNO_STRATEGY_VERSION_FIELDS_MAX + 1))),
    'oldValues' => '{}', 'newValues' => '{}',
];
$response = null;
try { fno_add_strategy_version_fn(); assertTrue(false, 'must throw via the real error path for an oversized changedFields array'); }
catch (TestWPDieExceptionCaps $e) {
    $response = $e->responseData;
    assertTrue($response['success'] === false, 'a changedFields array of FNO_STRATEGY_VERSION_FIELDS_MAX + 1 entries (bigger than the real 193-factor registry could ever legitimately produce) is rejected, not silently accepted or truncated');
}
assertTrue(($GLOBALS['__options']['fno_strategy_versions'] ?? []) === [], 'no real version entry is persisted when the oversized array is rejected');

// Over-limit oldValues (an object, not a list): also rejected.
$GLOBALS['__options'] = [];
$overLimitObj = [];
for ($i = 0; $i < FNO_STRATEGY_VERSION_FIELDS_MAX + 1; $i++) { $overLimitObj["k$i"] = $i; }
$_POST = [
    'version' => 'v-over-limit-oldvalues',
    'changedFields' => '[]', 'oldValues' => json_encode($overLimitObj), 'newValues' => '{}',
];
$response = null;
try { fno_add_strategy_version_fn(); assertTrue(false, 'must throw via the real error path for an oversized oldValues object'); }
catch (TestWPDieExceptionCaps $e) { $response = $e->responseData; assertTrue($response['success'] === false, 'an oversized oldValues object is rejected too, not just changedFields'); }

echo "\n=== fno_journal_add_fn Layer B dual-write (FNO_STRATEGY_VERSION_FIELDS_MAX on decoded[factors]) ===\n";

// At-limit factors: every entry dual-written.
global $wpdb;
$wpdb = new FakeWpdbCaps();
$factors = [];
for ($i = 0; $i < FNO_STRATEGY_VERSION_FIELDS_MAX; $i++) { $factors["f$i"] = ['status' => 'COMPUTED', 'pass' => true, 'score' => 1.0]; }
$_POST = [
    'symbol' => 'NIFTY', 'action' => 'BUY', 'pnl' => '100',
    'factor_snapshot' => json_encode(['factors' => $factors]),
];
$response = null;
try { fno_journal_add_fn(); } catch (TestWPDieExceptionCaps $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a journal write with exactly FNO_STRATEGY_VERSION_FIELDS_MAX factors succeeds');
// 1 insert for the journal row itself + 1 per factor.
assertTrue($wpdb->insert_calls === FNO_STRATEGY_VERSION_FIELDS_MAX + 1, 'exactly ' . FNO_STRATEGY_VERSION_FIELDS_MAX . ' real factor_values rows are dual-written at the cap, none dropped');

// Over-limit factors: journal write still succeeds (non-fatal, additive
// dual-write per this block's existing design), but the loop is capped
// at FNO_STRATEGY_VERSION_FIELDS_MAX inserts, not one per oversized entry.
$wpdb = new FakeWpdbCaps();
$factors = [];
for ($i = 0; $i < FNO_STRATEGY_VERSION_FIELDS_MAX + 50; $i++) { $factors["f$i"] = ['status' => 'COMPUTED', 'pass' => true, 'score' => 1.0]; }
$_POST = [
    'symbol' => 'NIFTY', 'action' => 'BUY', 'pnl' => '100',
    'factor_snapshot' => json_encode(['factors' => $factors]),
];
$response = null;
try { fno_journal_add_fn(); } catch (TestWPDieExceptionCaps $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a journal write with an oversized factors object still succeeds (the JSON column is still the authoritative record - non-fatal by design)');
assertTrue($wpdb->insert_calls === FNO_STRATEGY_VERSION_FIELDS_MAX + 1, 'the dual-write loop is capped at FNO_STRATEGY_VERSION_FIELDS_MAX (' . FNO_STRATEGY_VERSION_FIELDS_MAX . ') factor_values inserts, not one per oversized (' . ($i) . ') entry - closes the unbounded-insert-loop gap');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
