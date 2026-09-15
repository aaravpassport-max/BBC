<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_get_intraday_oi_history_fn() - the new endpoint closing the
 * user's founding vision document's "gradual position building
 * through smaller transactions... over time" question at INTRADAY
 * granularity, not just day-over-day.
 *
 * Run with: php tests/php/IntradayOIHistoryTest.php
 */

class FakeWpdb4 {
    public $prefix = 'wp_';
    private $rows;
    public function __construct($rows = []) { $this->rows = $rows; }
    public function prepare($query, ...$args) { return $query; }
    public function get_results($query, $output = null) { return $this->rows; }
}
function current_user_can($cap) { return true; }
function is_user_logged_in() { return true; }
function fno_verify_app_nonce() { return true; }
class TestWPDieException4 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException4(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException4(['success' => false, 'data' => $data]); }
function check_ajax_referer($action, $key, $die = true) { return true; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
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
$start = strpos($pluginSource, 'function fno_get_intraday_oi_history_fn(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_get_intraday_oi_history_fn (real intraday gradual position-building) ===\n";

global $wpdb;

// Real, valid request with real, bucketed intraday rows.
$wpdb = new FakeWpdb4([
    ['ts' => 1755400200000, 'oi' => 120000], // real ms timestamp
    ['ts' => 1755401100000, 'oi' => 125000],
    ['ts' => 1755402000000, 'oi' => 131000],
]);
$_GET = ['strike' => '23200', 'optionType' => 'CE', 'symbol' => 'NIFTY'];
$response = null;
try { fno_get_intraday_oi_history_fn(); } catch (TestWPDieException4 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, valid request succeeds');
assertTrue(count($response['data']['history']) === 3, 'real, all 3 bucketed rows are returned');
assertTrue($response['data']['history'][0]['oi'] === 120000, 'real OI value passed through correctly');
assertTrue(is_string($response['data']['history'][0]['date']), 'real bucket label is a real, human-readable time string');

// Real, missing strike must be rejected.
$_GET = ['optionType' => 'CE', 'symbol' => 'NIFTY'];
$response = null;
try { fno_get_intraday_oi_history_fn(); } catch (TestWPDieException4 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real request missing strike is genuinely rejected');

// Real, invalid optionType must be rejected.
$_GET = ['strike' => '23200', 'optionType' => 'XX', 'symbol' => 'NIFTY'];
$response = null;
try { fno_get_intraday_oi_history_fn(); } catch (TestWPDieException4 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, invalid optionType is genuinely rejected');

// Real, empty result set (no ticks yet today) returns a real, honest empty array, not an error.
$wpdb = new FakeWpdb4([]);
$_GET = ['strike' => '23200', 'optionType' => 'CE', 'symbol' => 'NIFTY'];
$response = null;
try { fno_get_intraday_oi_history_fn(); } catch (TestWPDieException4 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'genuinely no real ticks yet today is a real, honest success with an empty history, not an error');
assertTrue(count($response['data']['history']) === 0, 'real, honestly empty history array');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
