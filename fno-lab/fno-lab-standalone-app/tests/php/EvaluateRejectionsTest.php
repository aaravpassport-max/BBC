<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_evaluate_rejections_fn() - the function deciding whether a real
 * rejected trade "would likely have profited" or "would likely have
 * lost" once enough time has passed to check.
 *
 * Chosen specifically because a careful trace found a real, genuine
 * bug: the outcome classification always assumed ANY upward spot move
 * meant a rejected opportunity "would likely have profited" - correct
 * only when the real hypothetical trade was bullish. A NO_TRADE
 * rejection can come from a hard risk/regulatory block, which is
 * genuinely independent of whether the underlying signal was bullish
 * or bearish - a blocked bearish (put-like) setup that then moved DOWN
 * would have been misclassified as "would_likely_lose" under the old
 * logic, exactly backwards. Fixed this session by finally using
 * target_hypothetical - a real, already-selected-but-unused column -
 * to infer the real hypothetical direction.
 *
 * Run with: php tests/php/EvaluateRejectionsTest.php
 */

class FakeWpdb2 {
    public $prefix = 'wp_';
    public $insert_id = 1;
    public $last_error = '';
    private $rows;
    public $updates = [];
    public $inserted = [];
    public function __construct($rows = []) { $this->rows = $rows; }
    public function prepare($query, ...$args) { return $query; } // real values are read directly from $this->rows below, not re-parsed from SQL
    public function get_results($query, $output = null) { return $this->rows; }
    public function update($table, $data, $where) {
        $this->updates[] = ['data' => $data, 'where' => $where];
        return 1;
    }
    public function insert($table, $data) {
        $this->inserted[] = $data;
        return true;
    }
}
function get_current_user_id() { return 1; }
class TestWPDieException2 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException2(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException2(['success' => false, 'data' => $data]); }
function check_ajax_referer($action, $key, $die = true) { return true; }
function is_user_logged_in() { return true; }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v) { return trim((string) $v); }
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

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
$accessStart = strpos($pluginSource, 'function fno_verify_app_access(');
$accessEnd = strpos($pluginSource, "\n}", $accessStart) + 2;
eval(substr($pluginSource, $accessStart, $accessEnd - $accessStart));
foreach (['fno_evaluate_rejections_fn', 'fno_log_rejection_fn'] as $fn) {
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

echo "=== fno_evaluate_rejections_fn (real hypothetical-direction fix) ===\n";

global $wpdb;
$wpdb = new FakeWpdb2([[
    'id' => 1, 'decision' => 'NO_TRADE', 'spot_at_rejection' => 23000,
    'target_hypothetical' => 23200, 'sl_hypothetical' => 22900,
]]);
$_POST['currentSpot'] = '23100';
$response = null;
try { fno_evaluate_rejections_fn(); } catch (TestWPDieException2 $e) { $response = $e->responseData; }
assertTrue($response['data']['results'][0]['outcome'] === 'would_likely_profit', 'a real bullish hypothetical setup that moved up is correctly classified as would_likely_profit');

$wpdb = new FakeWpdb2([[
    'id' => 2, 'decision' => 'NO_TRADE', 'spot_at_rejection' => 23000,
    'target_hypothetical' => 22800, 'sl_hypothetical' => 23100,
]]);
$_POST['currentSpot'] = '23100';
$response = null;
try { fno_evaluate_rejections_fn(); } catch (TestWPDieException2 $e) { $response = $e->responseData; }
assertTrue($response['data']['results'][0]['outcome'] === 'would_likely_lose', 'a real bearish hypothetical setup where spot moved UP (away from its real target) is correctly classified as would_likely_lose - the exact case the old logic had backwards, since it only ever checked raw up/down without the real hypothetical direction');

$wpdb = new FakeWpdb2([[
    'id' => 3, 'decision' => 'NO_TRADE', 'spot_at_rejection' => 23000,
    'target_hypothetical' => 22800, 'sl_hypothetical' => 23100,
]]);
$_POST['currentSpot'] = '22850';
$response = null;
try { fno_evaluate_rejections_fn(); } catch (TestWPDieException2 $e) { $response = $e->responseData; }
assertTrue($response['data']['results'][0]['outcome'] === 'would_likely_profit', 'the same real bearish setup where spot genuinely moved toward its real target is correctly would_likely_profit');

$wpdb = new FakeWpdb2([[
    'id' => 4, 'decision' => 'WAIT', 'spot_at_rejection' => 23000,
    'target_hypothetical' => null, 'sl_hypothetical' => null,
]]);
$_POST['currentSpot'] = '23100';
$response = null;
try { fno_evaluate_rejections_fn(); } catch (TestWPDieException2 $e) { $response = $e->responseData; }
assertTrue($response['data']['results'][0]['outcome'] === 'would_likely_profit', 'with no real hypothetical target recorded, honestly falls back to the original real proxy (movePct alone) rather than guessing a direction');

$wpdb = new FakeWpdb2([[
    'id' => 5, 'decision' => 'NO_TRADE', 'spot_at_rejection' => 23000,
    'target_hypothetical' => 23200, 'sl_hypothetical' => 22900,
]]);
$_POST['currentSpot'] = '23005';
$response = null;
try { fno_evaluate_rejections_fn(); } catch (TestWPDieException2 $e) { $response = $e->responseData; }
assertTrue($response['data']['results'][0]['outcome'] === 'inconclusive_small_move', 'a real, genuinely tiny move stays honestly inconclusive, not forced into a real profit/loss call');

echo "\n=== fno_log_rejection_fn write-boundary validation (definitive \$_POST-numeric-write sweep) ===\n";

// A real, legitimate rejection log still succeeds exactly as before.
$wpdb = new FakeWpdb2();
$_POST = ['ts' => 1700000000000, 'symbol' => 'NIFTY', 'decision' => 'NO_TRADE', 'spot' => '23000', 'target' => '23200', 'sl' => '22900', 'probability' => '0.4'];
$response = null;
try { fno_log_rejection_fn(); } catch (TestWPDieException2 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, legitimate rejection log with valid numeric fields still succeeds');
assertTrue(count($wpdb->inserted) === 1, 'exactly one real row is inserted');
assertTrue($wpdb->inserted[0]['spot_at_rejection'] === 23000.0, 'the real spot is stored correctly');

// A non-numeric / NaN-producing spot is honestly rejected, not silently cast to 0.
$wpdb = new FakeWpdb2();
$_POST = ['ts' => 1700000000000, 'symbol' => 'NIFTY', 'decision' => 'NO_TRADE', 'spot' => 'NaN', 'target' => '23200', 'sl' => '22900'];
$response = null;
try { fno_log_rejection_fn(); } catch (TestWPDieException2 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a genuinely non-numeric spot ("NaN") is honestly rejected, never silently stored as a fabricated 0');
assertTrue(count($wpdb->inserted) === 0, 'no row is inserted when spot is genuinely non-numeric');

// A non-numeric probability is honestly rejected too.
$wpdb = new FakeWpdb2();
$_POST = ['ts' => 1700000000000, 'symbol' => 'NIFTY', 'decision' => 'NO_TRADE', 'spot' => '23000', 'target' => '23200', 'sl' => '22900', 'probability' => 'garbage'];
$response = null;
try { fno_log_rejection_fn(); } catch (TestWPDieException2 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a genuinely non-numeric probability ("garbage") is honestly rejected');
assertTrue(count($wpdb->inserted) === 0, 'no row is inserted when probability is genuinely non-numeric');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
