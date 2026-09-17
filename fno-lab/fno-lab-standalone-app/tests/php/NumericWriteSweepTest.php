<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test covering the
 * remaining two genuinely-unguarded persisted-numeric-write endpoints
 * found by the definitive $_POST-numeric-write sweep of fno-lab.php
 * (the same fail-open (float)$_POST[...] pattern already fixed for
 * fno_journal_add_fn, fno_update_open_position_fn, fno_open_position_fn,
 * fno_log_rejection_fn, and fno_log_hypothesis_fn):
 *
 *  - fno_add_manual_position_fn() - entryIV had only an isset()/''
 *    check, no is_numeric() guard, before being persisted.
 *  - fno_ingest_microstructure_fn() - poc/flow_imbalance_pct/
 *    ticks_per_minute had the same gap.
 *
 * Run with: php tests/php/NumericWriteSweepTest.php
 */

class FakeWpdbSweep {
    public $prefix = 'wp_';
    public $insert_id = 1;
    public $last_error = '';
    public $inserted = [];
    public $replaced = [];
    public function insert($table, $data) { $this->inserted[] = $data; return true; }
    public function replace($table, $data) { $this->replaced[] = $data; return true; }
}
function get_current_user_id() { return 1; }
function is_user_logged_in() { return true; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim((string) $v); }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
function fno_cap_text($v) { return $v; }
function fno_get_daemon_secret() { return 'real-test-daemon-secret'; }
function current_time($type) { return date('Y-m-d H:i:s'); }
class TestWPDieExceptionSweep extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieExceptionSweep(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieExceptionSweep(['success' => false, 'data' => $data]); }
function check_ajax_referer($action, $key, $die = true) { return true; }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
// Real fix (server-side symbol-enum sweep regression coverage): the
// real fno_validate_symbol()/fno_valid_symbols() helpers this test's
// target function(s) now call must be eval'd in too, same as every
// other real helper dependency below - never reimplemented here.
foreach (['fno_valid_symbols', 'fno_validate_symbol'] as $fnoHelperFn) {
    $hStart = strpos($pluginSource, "function $fnoHelperFn(");
    $hEnd = strpos($pluginSource, "\n}", $hStart) + 2;
    eval(substr($pluginSource, $hStart, $hEnd - $hStart));
}
$verifyStart = strpos($pluginSource, 'function fno_verify_app_nonce(');
$verifyEnd = strpos($pluginSource, "\n}", $verifyStart) + 2;
eval(substr($pluginSource, $verifyStart, $verifyEnd - $verifyStart));
foreach (['fno_add_manual_position_fn', 'fno_ingest_microstructure_fn'] as $fn) {
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
function callFn($fn, $params) {
    $_POST = $params;
    try { $fn(); return null; }
    catch (TestWPDieExceptionSweep $e) { return $e->responseData; }
}

echo "=== fno_add_manual_position_fn entryIV write-boundary validation ===\n";

global $wpdb;
$wpdb = new FakeWpdbSweep();
$r = callFn('fno_add_manual_position_fn', ['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'qty' => '50', 'entryPrice' => '120', 'entryIV' => '18.5']);
assertTrue($r['success'] === true, 'a real, legitimate manual position with valid entryIV succeeds');
assertTrue(count($wpdb->inserted) === 1, 'exactly one real row is inserted');
assertTrue($wpdb->inserted[0]['entry_iv'] === 18.5, 'the real entryIV is stored correctly');

$wpdb = new FakeWpdbSweep();
$r = callFn('fno_add_manual_position_fn', ['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'qty' => '50', 'entryPrice' => '120', 'entryIV' => 'NaN']);
assertTrue($r['success'] === false, 'a genuinely non-numeric entryIV ("NaN") is honestly rejected, never silently stored as a fabricated 0 IV reading');
assertTrue(count($wpdb->inserted) === 0, 'no row is inserted when entryIV is genuinely non-numeric');

echo "\n=== fno_ingest_microstructure_fn poc/flow_imbalance_pct/ticks_per_minute write-boundary validation ===\n";

$_SERVER['HTTP_X_FNO_DAEMON_SECRET'] = 'real-test-daemon-secret';

$wpdb = new FakeWpdbSweep();
$r = callFn('fno_ingest_microstructure_fn', ['symbol' => 'NIFTY', 'poc' => '23150.5', 'flow_imbalance_pct' => '12.3', 'ticks_per_minute' => '45.2']);
assertTrue($r['success'] === true, 'a real, legitimate microstructure ingest with valid numeric fields succeeds');
assertTrue(count($wpdb->replaced) === 1, 'exactly one real row is upserted');
assertTrue($wpdb->replaced[0]['poc'] === 23150.5, 'the real poc is stored correctly');

$wpdb = new FakeWpdbSweep();
$r = callFn('fno_ingest_microstructure_fn', ['symbol' => 'NIFTY', 'poc' => 'garbage']);
assertTrue($r['success'] === false, 'a genuinely non-numeric poc ("garbage") is honestly rejected, never silently stored as a fabricated 0');
assertTrue(count($wpdb->replaced) === 0, 'no row is upserted when poc is genuinely non-numeric');

$wpdb = new FakeWpdbSweep();
$r = callFn('fno_ingest_microstructure_fn', ['symbol' => 'NIFTY', 'flow_imbalance_pct' => 'Infinity']);
assertTrue($r['success'] === false, 'a genuinely non-numeric flow_imbalance_pct ("Infinity", not is_numeric in PHP) is honestly rejected');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
