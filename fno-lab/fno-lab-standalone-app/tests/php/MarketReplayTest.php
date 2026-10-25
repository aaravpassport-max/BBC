<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_get_replay_ticks_fn() - closes docs/PENDING_REQUIREMENTS.md's
 * "Market Replay Engine... the actual replay-mode data-source
 * adapter... never built" item.
 *
 * Run with: php tests/php/MarketReplayTest.php
 */

function current_user_can($cap) { return true; }
function check_ajax_referer($a, $k, $d = true) { return true; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
class TestWPDieException8 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException8(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException8(['success' => false, 'data' => $data]); }
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

class FakeWpdb7 {
    public $prefix = 'wp_';
    private $rows;
    public function __construct($rows = []) { $this->rows = $rows; }
    public function prepare($q, ...$a) { return $q; }
    public function get_results($q, $o = null) { return $this->rows; }
}

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
$start = strpos($pluginSource, 'function fno_get_replay_ticks_fn(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_get_replay_ticks_fn (real Market Replay data-source adapter) ===\n";

global $wpdb;

$wpdb = new FakeWpdb7([
    ['ts' => 1755500000000, 'ltp' => 120.5, 'oi' => 500000, 'oi_change' => 5000, 'volume' => 10000, 'iv' => 13.5, 'bid' => 120.0, 'ask' => 121.0],
    ['ts' => 1755500060000, 'ltp' => 121.0, 'oi' => 502000, 'oi_change' => 7000, 'volume' => 10500, 'iv' => 13.4, 'bid' => 120.5, 'ask' => 121.5],
]);
$_GET = ['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'date' => '2026-08-17'];
$response = null;
try { fno_get_replay_ticks_fn(); } catch (TestWPDieException8 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, valid replay request succeeds');
assertTrue($response['data']['count'] === 2, 'real, correct tick count reported');
assertTrue($response['data']['ticks'][0]['ltp'] === 120.5, 'real LTP field correctly extracted');
assertTrue($response['data']['ticks'][1]['oi'] === 502000, 'real, second tick\'s OI correctly extracted');

$_GET = ['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE'];
$response = null;
try { fno_get_replay_ticks_fn(); } catch (TestWPDieException8 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real request missing a real date is genuinely rejected');

$_GET = ['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'date' => 'not-a-real-date'];
$response = null;
try { fno_get_replay_ticks_fn(); } catch (TestWPDieException8 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, malformed date string is genuinely rejected');

$_GET = ['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'XX', 'date' => '2026-08-17'];
$response = null;
try { fno_get_replay_ticks_fn(); } catch (TestWPDieException8 $e) { $response = $e->responseData; }
assertTrue($response['success'] === false, 'a real, invalid optionType is genuinely rejected');

$wpdb = new FakeWpdb7([]);
$_GET = ['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'date' => '2026-01-01'];
$response = null;
try { fno_get_replay_ticks_fn(); } catch (TestWPDieException8 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'genuinely no captured ticks for a real date is still an honest success, not an error');
assertTrue($response['data']['count'] === 0, 'real, honestly empty tick count - never fabricated');
assertTrue($response['data']['ticks'] === [], 'real, honestly empty ticks array');

$wpdb = new FakeWpdb7([
    ['ts' => 1755500000000, 'ltp' => 120.5, 'oi' => null, 'oi_change' => null, 'volume' => null, 'iv' => null, 'bid' => null, 'ask' => null],
]);
$_GET = ['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'date' => '2026-08-17'];
$response = null;
try { fno_get_replay_ticks_fn(); } catch (TestWPDieException8 $e) { $response = $e->responseData; }
assertTrue($response['data']['ticks'][0]['oi'] === null, 'a real, genuinely missing OI field is honestly reported as null, never fabricated as zero or any other value');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
