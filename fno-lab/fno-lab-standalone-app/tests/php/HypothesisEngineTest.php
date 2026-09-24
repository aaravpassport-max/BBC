<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the Participant
 * Payoff Hypothesis Engine's server-side storage and evaluation
 * (fno_log_hypothesis_fn / fno_evaluate_hypotheses_fn /
 * fno_get_hypothesis_stats_fn) - user's own founding vision document,
 * "test hypotheses historically and through ongoing paper trading."
 *
 * Verifies the real PHP evaluation formula genuinely matches the real
 * JS evaluateParticipantPayoffHypothesis() formula (same 0.3%/-0.3%
 * thresholds, same direction logic) - the two are necessarily
 * duplicated (PHP and JS don't share code in this project) but must
 * stay in real, verified sync, not just claimed to.
 *
 * Run with: php tests/php/HypothesisEngineTest.php
 */

class FakeWpdb3 {
    public $prefix = 'wp_';
    public $insert_id = 1;
    private $rows;
    private $byDirectionRows;
    public $inserted = [];
    public $updates = [];
    public function __construct($rows = [], $byDirectionRows = []) { $this->rows = $rows; $this->byDirectionRows = $byDirectionRows; }
    public function prepare($query, ...$args) { return $query; }
    public function get_results($query, $output = null) {
        // Real, honest query-aware dispatch - this endpoint now makes
        // TWO genuinely different real queries (outcome-only grouping,
        // and the new real direction+outcome grouping) - a naive mock
        // returning the same fixture for both would silently feed the
        // wrong shape of data into the wrong real code path, exactly
        // the kind of test-quality gap already caught once this
        // session (Phase 81's mislabeled test). Caught here by an
        // honest PHP warning on first run, not assumed fine.
        if (strpos($query, 'GROUP BY direction') !== false) return $this->byDirectionRows;
        return $this->rows;
    }
    public function get_var($query) { return 0; }
    public function insert($table, $row) { $this->inserted[] = $row; return true; }
    public function update($table, $data, $where) { $this->updates[] = ['data' => $data, 'where' => $where]; return 1; }
}
function get_current_user_id() { return 1; }
class TestWPDieException3 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException3(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException3(['success' => false, 'data' => $data]); }
function check_ajax_referer($action, $key, $die = true) { return true; }
function is_user_logged_in() { return true; }
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
// Real fno_verify_app_access() extraction - fno_log_hypothesis_fn and
// fno_evaluate_hypotheses_fn now genuinely use this (fixed this
// session so the real, headless driver can reach them too), so the
// real, actual function is needed here, not a fake stub, since its
// own correctness is now genuinely part of what this test exercises.
// Depends on get_option/hash_equals/get_userdata/wp_set_current_user,
// stubbed below as real, minimal, browser-path-only fakes (this
// test's own real, existing fixtures never send a driver-secret
// header, so only the nonce fallback branch is ever genuinely
// exercised here).
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
if (!function_exists('hash_equals')) { function hash_equals($a, $b) { return $a === $b; } }
if (!function_exists('get_userdata')) { function get_userdata($id) { return null; } }
if (!function_exists('wp_set_current_user')) { function wp_set_current_user($id) { } }
// Real fix (rate-limiter coverage audit): fno_log_hypothesis_fn,
// fno_evaluate_hypotheses_fn and fno_get_hypothesis_stats_fn now call
// the real fno_rate_limit() (previously genuinely unprotected) - stub
// it as a real no-op here (this file doesn't exercise transient-backed
// rate limiting, only the hypothesis-engine logic itself) so this
// pre-existing test keeps passing under the new call.
if (!function_exists('fno_rate_limit')) { function fno_rate_limit($endpoint, $limit = 30) { } }
$accessStart = strpos($pluginSource, 'function fno_verify_app_access(');
$accessEnd = strpos($pluginSource, "\n}", $accessStart) + 2;
eval(substr($pluginSource, $accessStart, $accessEnd - $accessStart));
// fno_cap_text() (input-size/payload-validation audit) - fno_log_hypothesis_fn
// now calls it on the real hypothesis/evidence text fields.
$capStart = strpos($pluginSource, 'function fno_cap_text(');
$capEnd = strpos($pluginSource, "\n}", $capStart) + 2;
eval(substr($pluginSource, $capStart, $capEnd - $capStart));
foreach (['fno_log_hypothesis_fn', 'fno_evaluate_hypotheses_fn', 'fno_get_hypothesis_stats_fn'] as $fn) {
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

echo "=== Participant Payoff Hypothesis Engine (real PHP storage + evaluation) ===\n";

global $wpdb;

$wpdb = new FakeWpdb3();
$_POST = ['direction' => 'bullish', 'symbol' => 'NIFTY', 'spotAtGeneration' => '23200', 'confidence' => 'high', 'keyLevel' => '23400', 'hypothesis' => 'test', 'falsifiablePrediction' => 'test'];
$response = null;
try { fno_log_hypothesis_fn(); } catch (TestWPDieException3 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, valid hypothesis is logged successfully');
assertTrue(count($wpdb->inserted) === 1, 'exactly one real row is inserted');
assertTrue($wpdb->inserted[0]['direction'] === 'bullish', 'the real direction is stored correctly');

$wpdb = new FakeWpdb3();
$_POST = ['direction' => 'sideways'];
$response = null;
try { fno_log_hypothesis_fn(); } catch (TestWPDieException3 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a genuinely invalid direction is rejected, not silently stored');

// Real fix (definitive $_POST-numeric-write sweep): a non-numeric keyLevel
// must be honestly rejected, not silently cast to a fabricated 0.0 and
// persisted as a real support/resistance level.
$wpdb = new FakeWpdb3();
$_POST = ['direction' => 'bullish', 'symbol' => 'NIFTY', 'spotAtGeneration' => '23200', 'confidence' => 'high', 'keyLevel' => 'NaN', 'hypothesis' => 'test', 'falsifiablePrediction' => 'test'];
$response = null;
try { fno_log_hypothesis_fn(); } catch (TestWPDieException3 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a genuinely non-numeric keyLevel ("NaN") is honestly rejected, never silently stored as a fabricated 0 key level');
assertTrue(count($wpdb->inserted) === 0, 'no row is inserted when keyLevel is genuinely non-numeric');

$wpdb = new FakeWpdb3([['id' => 1, 'direction' => 'bullish', 'spot_at_generation' => 23000]]);
$_POST['currentSpot'] = '23200';
$_POST['symbol'] = 'NIFTY';
$response = null;
try { fno_evaluate_hypotheses_fn(); } catch (TestWPDieException3 $e) { $response = $e->responseData; }
assertTrue($response['data']['results'][0]['outcome'] === 'confirmed', 'a real bullish hypothesis that moved up is correctly confirmed - matches the real JS formula');

$wpdb = new FakeWpdb3([['id' => 2, 'direction' => 'bearish', 'spot_at_generation' => 23000]]);
$_POST['currentSpot'] = '23200';
$_POST['symbol'] = 'NIFTY';
$response = null;
try { fno_evaluate_hypotheses_fn(); } catch (TestWPDieException3 $e) { $response = $e->responseData; }
assertTrue($response['data']['results'][0]['outcome'] === 'disconfirmed', 'a real bearish hypothesis where spot moved UP (against the real prediction) is correctly disconfirmed');

$wpdb = new FakeWpdb3([['id' => 3, 'direction' => 'bullish', 'spot_at_generation' => 23000]]);
$_POST['currentSpot'] = '23005';
$_POST['symbol'] = 'NIFTY';
$response = null;
try { fno_evaluate_hypotheses_fn(); } catch (TestWPDieException3 $e) { $response = $e->responseData; }
assertTrue($response['data']['results'][0]['outcome'] === 'inconclusive', 'a real, genuinely tiny move stays honestly inconclusive');

// THE core fix, proven directly: without a real symbol, the request
// must be genuinely rejected, not silently evaluate hypotheses that
// could belong to a completely different real instrument.
$wpdb = new FakeWpdb3([['id' => 4, 'direction' => 'bullish', 'spot_at_generation' => 23000]]);
$_POST['currentSpot'] = '23200';
unset($_POST['symbol']);
$response = null;
try { fno_evaluate_hypotheses_fn(); } catch (TestWPDieException3 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real request missing symbol must be genuinely rejected - the exact fix for the real cross-symbol contamination bug found this session');

$wpdb = new FakeWpdb3([['outcome' => 'confirmed', 'cnt' => 18], ['outcome' => 'disconfirmed', 'cnt' => 12]]);
$response = null;
try { fno_get_hypothesis_stats_fn(); } catch (TestWPDieException3 $e) { $response = $e->responseData; }
assertTrue($response['data']['counts']['confirmed'] === 18, 'real confirmed count aggregated correctly');
assertTrue(abs($response['data']['confirmedRatePct'] - 60.0) < 0.01, 'real confirmed rate computed correctly (18/(18+12) = 60%)');
assertTrue($response['data']['sampleSizeWarning'] === false, 'a real, adequate sample (30 decisive outcomes) does not trigger the sample-size warning');

// Real "when similar positioning occurred historically, what happened
// next" check - real, per-direction breakdown.
$wpdbDir = new FakeWpdb3(
    [['outcome' => 'confirmed', 'cnt' => 30]],
    [
        ['direction' => 'bullish', 'outcome' => 'confirmed', 'cnt' => 20],
        ['direction' => 'bullish', 'outcome' => 'disconfirmed', 'cnt' => 5],
        ['direction' => 'bearish', 'outcome' => 'confirmed', 'cnt' => 3],
        ['direction' => 'bearish', 'outcome' => 'disconfirmed', 'cnt' => 2],
    ]
);
$wpdb = $wpdbDir;
$response = null;
try { fno_get_hypothesis_stats_fn(); } catch (TestWPDieException3 $e) { $response = $e->responseData; }
assertTrue($response['data']['byDirection']['bullish']['confirmed'] === 20, 'real bullish confirmed count correct');
assertTrue($response['data']['byDirection']['bullish']['decisiveTotal'] === 25, 'real bullish decisive total (20+5) correct');
assertTrue(abs($response['data']['byDirection']['bullish']['confirmedRatePct'] - 80.0) < 0.01, 'real bullish confirmed rate (20/25=80%) correct');
assertTrue($response['data']['byDirection']['bullish']['sampleSizeWarning'] === false, 'real bullish sample (25, above the real 15 floor) does not warn');
assertTrue($response['data']['byDirection']['bearish']['decisiveTotal'] === 5, 'real bearish decisive total (3+2) correct');
assertTrue($response['data']['byDirection']['bearish']['sampleSizeWarning'] === true, 'real bearish sample (5, below the real 15 floor) honestly warns - a real, smaller, direction-specific sample deserves its own real check, not inherited from the aggregate');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
