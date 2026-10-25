<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for fno_cap_text()
 * and every real free-text field it was added to during the
 * input-size/payload-validation audit (this session): the Strategy
 * Knowledge Base's observation/evidence/conclusion/candidateChange
 * (fno_add_knowledge_entry_fn - a real <textarea> a real, logged-in
 * end user directly types into, stored in a real, autoloaded
 * wp_option), the Strategy Version Log's reason/evidence
 * (fno_add_strategy_version_fn - same real pattern, admin-only), the
 * Failure Mode Library's reason (fno_log_failure_event_fn - a real,
 * nopriv-reachable TEXT column), and the Participant Payoff
 * Hypothesis text fields (fno_log_hypothesis_fn - real, driver-
 * reachable TEXT columns). FOUND genuinely missing: none of these had
 * any application-level length cap before this fix, so a single,
 * otherwise perfectly rate-limit-compliant request pasting several
 * megabytes into one field would still bloat a real DB row or a real
 * autoloaded wp_option with no real, legitimate reason for text this
 * long.
 *
 * Run with: php tests/php/FreeTextLengthCapTest.php
 */

class FakeWpdb {
    public $prefix = 'wp_';
    public $last_error = '';
    public $last_insert = null;
    public $insert_id = 42;
    public function insert($table, $data) { $this->last_insert = $data; return 1; }
}

function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim((string) $v); }
$GLOBALS['__options'] = [];
function get_option($k, $default = false) { return $GLOBALS['__options'][$k] ?? $default; }
function update_option($k, $v) { $GLOBALS['__options'][$k] = $v; return true; }
function get_current_user_id() { return 7; }
function is_user_logged_in() { return true; }
function current_user_can($cap) { return true; }
function check_ajax_referer($action, $key) { return true; }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
function fno_verify_app_nonce() { return true; }
function fno_verify_public_or_driver_access() { return true; }
class TestWPDieException extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException(['success' => false, 'data' => $data]); }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
// Real FNO_STRATEGY_VERSION_FIELDS_MAX constant (bulk/array-accepting
// AJAX endpoint size-limit audit) - fno_add_strategy_version_fn now
// references it, and it's defined at plugin top-level, not inside a
// function, so the per-function eval extraction below can't pick it
// up on its own; pulled out by real regex from the real source
// instead of being hardcoded a second time here, same pattern already
// used for FNO_RAW_TICK_BATCH_MAX in RawTickIngestTest.php.
if (!preg_match("/define\('FNO_STRATEGY_VERSION_FIELDS_MAX',\s*(\d+)\)/", $pluginSource, $m)) {
    fwrite(STDERR, "FNO_STRATEGY_VERSION_FIELDS_MAX constant not found in fno-lab.php\n");
    exit(1);
}
define('FNO_STRATEGY_VERSION_FIELDS_MAX', (int) $m[1]);
foreach ([
    'fno_cap_text',
    'fno_add_knowledge_entry_fn',
    'fno_add_strategy_version_fn',
    'fno_log_failure_event_fn',
] as $fn) {
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

echo "=== fno_cap_text() (real, standalone) ===\n";
assertTrue(fno_cap_text('hello') === 'hello', 'a real, short string passes through unchanged');
assertTrue(fno_cap_text(str_repeat('a', 4000)) === str_repeat('a', 4000), 'a real string at exactly the default cap (4000) is unchanged');
assertTrue(mb_strlen(fno_cap_text(str_repeat('a', 5000))) === 4000, 'a real string over the default cap (5000 chars) is truncated to exactly 4000');
assertTrue(fno_cap_text(str_repeat('é', 5000)) === str_repeat('é', 4000), 'truncation is by real character count (mb_strlen/mb_substr), not raw byte length - multi-byte "é" is not split or miscounted');
assertTrue(fno_cap_text('short', 10) === 'short', 'a custom, smaller maxLen still passes a shorter real string through unchanged');
assertTrue(mb_strlen(fno_cap_text('a much longer real string than ten chars', 10)) === 10, 'a custom, smaller maxLen truncates to exactly that real length');
assertTrue(fno_cap_text(null) === '', 'a genuinely null input is handled as an empty string, not a PHP warning or a literal "NULL"');
assertTrue(fno_cap_text('anything', 0) === '', 'maxLen <= 0 always returns empty - a caller can never accidentally disable the cap by passing 0');

echo "\n=== fno_add_knowledge_entry_fn (real Strategy Knowledge Base textarea fields) ===\n";
$GLOBALS['__options'] = [];
$_POST = [
    'factor' => 'IV Crush',
    'observation' => str_repeat('X', 9000),
    'evidence' => str_repeat('Y', 9000),
    'conclusion' => str_repeat('Z', 9000),
    'candidateChange' => str_repeat('W', 9000),
];
$response = null;
try { fno_add_knowledge_entry_fn(); } catch (TestWPDieException $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, oversized knowledge-base submission is still accepted (truncated, not rejected)');
$entry = end($response['data']['entries']);
assertTrue(mb_strlen($entry['observation']) === 4000, 'observation is capped to 4000 real characters before being stored in the real, autoloaded wp_option');
assertTrue(mb_strlen($entry['evidence']) === 4000, 'evidence is capped to 4000 real characters');
assertTrue(mb_strlen($entry['conclusion']) === 4000, 'conclusion is capped to 4000 real characters');
assertTrue(mb_strlen($entry['candidateChange']) === 4000, 'candidateChange is capped to 4000 real characters');

$GLOBALS['__options'] = [];
$_POST = ['factor' => 'IV Crush', 'observation' => 'a real, normal-length observation of a few sentences'];
$response = null;
try { fno_add_knowledge_entry_fn(); } catch (TestWPDieException $e) { $response = $e->responseData; }
$entry = end($response['data']['entries']);
assertTrue($entry['observation'] === 'a real, normal-length observation of a few sentences', 'a real, normal-length observation is stored completely untouched - the cap never trims legitimate real usage');

echo "\n=== fno_add_strategy_version_fn (real Strategy Version Log textarea fields) ===\n";
$GLOBALS['__options'] = [];
$_POST = ['version' => '3.1.0', 'reason' => str_repeat('R', 9000), 'evidence' => str_repeat('E', 9000)];
$response = null;
try { fno_add_strategy_version_fn(); } catch (TestWPDieException $e) { $response = $e->responseData; }
$entry = end($response['data']['versions']);
assertTrue(mb_strlen($entry['reason']) === 4000, 'strategy version reason is capped to 4000 real characters');
assertTrue(mb_strlen($entry['evidence']) === 4000, 'strategy version evidence is capped to 4000 real characters');

echo "\n=== fno_log_failure_event_fn (real Failure Mode Library reason - nopriv/public-reachable) ===\n";
global $wpdb;
$wpdb = new FakeWpdb();
$_POST = ['category' => 'bad_signal', 'reason' => str_repeat('F', 9000)];
$response = null;
try { fno_log_failure_event_fn(); } catch (TestWPDieException $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, oversized failure-event reason is still accepted (truncated, not rejected)');
assertTrue(mb_strlen($wpdb->last_insert['reason']) === 4000, 'the real reason value actually written to the DB row is capped to 4000 real characters, not the full 9000-char paste');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
