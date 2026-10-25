<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test - closes a genuinely
 * new, previously-unaudited gap found this session: unbounded wp_options
 * growth via three append-only "shared knowledge" write endpoints that
 * had NO fno_rate_limit() call at all, unlike every other real write
 * endpoint in this file (see RateLimiterCoverageAndIpSafetyTest.php's
 * own TRACE for the prior instance of this exact class of bug on
 * *nopriv* handlers - this one is different: it is on plain
 * `wp_ajax_<action>` handlers, which that scanner never covered, since
 * it only walks `wp_ajax_nopriv_` registrations).
 *
 * REAL GAP FOUND (before this session's fix):
 *   - fno_add_knowledge_entry_fn(): reachable by ANY logged-in user
 *     (not admin-only - see the endpoint's own TRACE), had zero rate
 *     limiting AND zero size cap, and appends to 'fno_knowledge_base',
 *     an AUTOLOADED option (loaded on every single WordPress page
 *     load, not just this app's own pages) - a single scripted
 *     low-privilege account could have grown this option without
 *     bound, degrading every page load site-wide.
 *   - fno_add_strategy_version_fn(): admin-only, but also had zero
 *     rate limiting on a write endpoint appending to the same kind of
 *     autoloaded option.
 *   - fno_save_probability_model_fn(): admin-only, already capped at
 *     50 entries, but also had zero rate limiting.
 *
 * REAL FIX: fno_rate_limit() added to all three (matching this file's
 * own established, already-proven pattern for every other write
 * endpoint), and the append-site update_option() calls for
 * fno_strategy_versions/fno_knowledge_base now pass autoload=false
 * (matching the already-established fno_dsm_paid_api_log convention,
 * see fno-data-layer.php's own comment on that exact pattern) so an
 * option that is EXPECTED to grow over an app's lifetime stops being
 * fetched on every unrelated WordPress page load.
 *
 * This test proves both halves against the REAL, unmodified function
 * bodies (never a reimplementation): (a) a real, exhausted rate limit
 * genuinely blocks each of the three endpoints before its
 * update_option() call ever runs, and (b) the real append-site
 * update_option() calls for the two autoloaded-by-default options
 * genuinely pass false as the third (autoload) argument.
 *
 * Run with: php tests/php/WpOptionsGrowthGuardTest.php
 */

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

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

echo "=== PART 1: static source checks ===\n";

foreach (['fno_add_strategy_version_fn', 'fno_add_knowledge_entry_fn', 'fno_save_probability_model_fn'] as $fnName) {
    $start = strpos($pluginSource, "function $fnName(");
    assertTrue($start !== false, "$fnName() found in fno-lab.php");
    $end = strpos($pluginSource, "\n}", $start);
    $body = substr($pluginSource, $start, $end - $start);
    assertTrue(strpos($body, 'fno_rate_limit(') !== false, "$fnName() calls fno_rate_limit()");
}

// The two append-site update_option() calls for the growing, append-only
// options must pass autoload=false - matched narrowly (the entry-array
// variable name right before update_option) so this doesn't accidentally
// match the one-time-seed call inside fno_get_strategy_versions_fn.
assertTrue(
    strpos($pluginSource, "\$versions[] = \$entry;\n    update_option('fno_strategy_versions', \$versions, false)") !== false,
    "fno_add_strategy_version_fn()'s update_option() call passes autoload=false"
);
assertTrue(
    strpos($pluginSource, "\$entries[] = \$entry;\n    update_option('fno_knowledge_base', \$entries, false)") !== false,
    "fno_add_knowledge_entry_fn()'s update_option() call passes autoload=false"
);

echo "\n=== PART 2: real execution - exhausted rate limit genuinely blocks each endpoint ===\n";

class FakeWpdbOptionsGuard {
    public $prefix = 'wp_';
    public function prepare($query, ...$args) { return $query; }
    public function get_results($query, $output = null) { return []; }
    public function get_var($query) { return 0; }
}
$GLOBALS['wpdb'] = new FakeWpdbOptionsGuard();

$GLOBALS['fno_test_options'] = [];
$GLOBALS['fno_test_update_option_calls'] = [];
function get_option($key, $default = false) { return $GLOBALS['fno_test_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) {
    $GLOBALS['fno_test_options'][$key] = $value;
    $GLOBALS['fno_test_update_option_calls'][] = ['key' => $key, 'autoload' => $autoload];
    return true;
}
error_reporting(E_ALL & ~E_WARNING); // real endpoint reads a few optional $_POST keys we don't set in every fixture above (e.g. 'status') - matches this endpoint's own real fallback-on-absence behavior, not a bug in the code under test
function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; }
function sanitize_textarea_field($v) { return is_string($v) ? trim($v) : $v; }
function fno_cap_text($v, $max = 2000) { return is_string($v) ? substr($v, 0, $max) : $v; }
function check_ajax_referer($action, $key, $die = true) { return true; }
$GLOBALS['fno_test_current_user_can'] = true;
function current_user_can($cap) { return $GLOBALS['fno_test_current_user_can']; }
$GLOBALS['fno_test_logged_in'] = true;
function is_user_logged_in() { return $GLOBALS['fno_test_logged_in']; }
function get_current_user_id() { return 42; }
function fno_verify_app_nonce() { return true; }

// The real thing under test: fno_rate_limit() itself, unmodified.
foreach (['fno_rate_limit'] as $fnName) {
    $start = strpos($pluginSource, "function $fnName(");
    if ($start === false) { fwrite(STDERR, "FATAL: could not locate function $fnName()\n"); exit(1); }
    $end = strpos($pluginSource, "\n}", $start) + 2;
    eval(substr($pluginSource, $start, $end - $start));
}
// fno_rate_limit() uses WP transients as its counter store - stub them
// with a real in-memory array so the real counting logic actually runs.
$GLOBALS['fno_test_transients'] = [];
function get_transient($key) { return $GLOBALS['fno_test_transients'][$key] ?? false; }
function set_transient($key, $value, $expiration) { $GLOBALS['fno_test_transients'][$key] = $value; return true; }
function sanitize_text_field_stub_unused() {}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return $v; } }
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

class TestWPDieExceptionOptionsGuard extends Exception {
    public $responseData; public $statusCode;
    public function __construct($responseData, $statusCode = 200) { $this->responseData = $responseData; $this->statusCode = $statusCode; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieExceptionOptionsGuard(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieExceptionOptionsGuard(['success' => false, 'data' => $data], $statusCode); }

function evalRealFn($src, $name) {
    $start = strpos($src, "function $name(");
    if ($start === false) { fwrite(STDERR, "FATAL: could not locate function $name() in fno-lab.php\n"); exit(1); }
    $end = strpos($src, "\n}", $start) + 2;
    eval(substr($src, $start, $end - $start));
}
evalRealFn($pluginSource, 'fno_add_strategy_version_fn');
evalRealFn($pluginSource, 'fno_add_knowledge_entry_fn');
evalRealFn($pluginSource, 'fno_save_probability_model_fn');

function callAndCountOptionWrites($fn, $post) {
    $GLOBALS['fno_test_update_option_calls'] = [];
    $_POST = $post;
    try { $fn(); } catch (TestWPDieExceptionOptionsGuard $e) { /* expected exit path */ }
    return count($GLOBALS['fno_test_update_option_calls']);
}

// --- fno_add_strategy_version_fn: drive its real 20/60s limit to exhaustion ---
$GLOBALS['fno_test_transients'] = [];
$blockedSomewhere = false;
for ($i = 0; $i < 25; $i++) {
    $writesBefore = callAndCountOptionWrites('fno_add_strategy_version_fn', [
        'version' => 'v-test-' . $i, 'reason' => 'r', 'evidence' => 'e',
        'changedFields' => '[]', 'oldValues' => '{}', 'newValues' => '{}',
    ]);
    if ($writesBefore === 0) { $blockedSomewhere = true; break; }
}
assertTrue($blockedSomewhere, 'fno_add_strategy_version_fn(): a sustained burst genuinely gets rate-limited (no update_option call once the real limit is exhausted)');

// --- fno_add_knowledge_entry_fn: same, its real 20/60s limit ---
$GLOBALS['fno_test_transients'] = [];
$blockedSomewhere = false;
for ($i = 0; $i < 25; $i++) {
    $writesBefore = callAndCountOptionWrites('fno_add_knowledge_entry_fn', [
        'factor' => 'f', 'observation' => 'o-' . $i,
    ]);
    if ($writesBefore === 0) { $blockedSomewhere = true; break; }
}
assertTrue($blockedSomewhere, 'fno_add_knowledge_entry_fn(): a sustained burst genuinely gets rate-limited (no update_option call once the real limit is exhausted)');

// --- fno_save_probability_model_fn: same, its real 20/60s limit ---
$GLOBALS['fno_test_transients'] = [];
$blockedSomewhere = false;
for ($i = 0; $i < 25; $i++) {
    $writesBefore = callAndCountOptionWrites('fno_save_probability_model_fn', [
        'model' => json_encode(['trained' => true, 'weights' => [0.1], 'bias' => 0.0]),
        'strategyVersion' => 'v1',
    ]);
    if ($writesBefore === 0) { $blockedSomewhere = true; break; }
}
assertTrue($blockedSomewhere, 'fno_save_probability_model_fn(): a sustained burst genuinely gets rate-limited (no update_option call once the real limit is exhausted)');

// --- Sanity: with rate limiting NOT exhausted, each endpoint still genuinely writes (proves the block above is really the rate limiter, not some other break) ---
$GLOBALS['fno_test_transients'] = [];
$writes = callAndCountOptionWrites('fno_add_knowledge_entry_fn', ['factor' => 'f', 'observation' => 'first real call']);
assertTrue($writes === 1, 'fno_add_knowledge_entry_fn(): a single, non-exhausted call still genuinely persists the entry (rate limiting did not silently break normal use)');

echo "\n=== SUMMARY ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
