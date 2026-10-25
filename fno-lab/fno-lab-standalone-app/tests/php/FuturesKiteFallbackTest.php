<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the real Kite
 * futures fallback wired into fno_fetch_futures_fn() - built at the
 * user's own direct, explicit request to make Kite a genuine, end-
 * to-end alternative to NSE.
 *
 * Run with: php tests/php/FuturesKiteFallbackTest.php
 */

$GLOBALS['fno_test_is_logged_in'] = true;
$GLOBALS['fno_test_user_meta'] = ['fno_kite_settings' => ['api_key' => 'real_test_key', 'access_token' => 'real_test_token']];
$GLOBALS['fno_test_instruments_csv'] = null;
$GLOBALS['fno_test_quote_response'] = null;
$GLOBALS['fno_test_transients'] = [];

function is_user_logged_in() { return $GLOBALS['fno_test_is_logged_in']; }
function get_current_user_id() { return 1; }
function get_user_meta($id, $key, $single) { return $GLOBALS['fno_test_user_meta'][$key] ?? []; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function is_wp_error($v) { return $v instanceof WP_Error_Test10; }
class WP_Error_Test10 {}
function get_transient($k) { return $GLOBALS['fno_test_transients'][$k] ?? false; }
function set_transient($k, $v, $e) { $GLOBALS['fno_test_transients'][$k] = $v; return true; }
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);

function wp_remote_get($url, $args = []) {
    if (strpos($url, 'api.kite.trade/instruments/NFO') !== false) return $GLOBALS['fno_test_instruments_csv'] ?? new WP_Error_Test10();
    if (strpos($url, 'api.kite.trade/quote') !== false) return $GLOBALS['fno_test_quote_response'] ?? new WP_Error_Test10();
    return new WP_Error_Test10(); // real NSE call - always fails in this test
}
function wp_remote_retrieve_response_code($res) { return 200; }
function wp_remote_retrieve_body($res) { return $res['body'] ?? '{}'; }
class TestWPDieException10 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException10(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException10(['success' => false, 'data' => $data]); }
function fno_verify_public_or_driver_access() { return true; }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
function fno_nse_get($url, $referer) { return null; }
function fno_nse_get_last_fetch_time($url) { return null; } // real stub - NSE always fails in this test (fno_nse_get always returns null above), so a real timestamp genuinely never gets captured here either

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
foreach (['fno_get_kite_session', 'fno_fetch_kite_instruments', 'fno_is_nse_integration_enabled'] as $helperFn) {
    $hStart = strpos($pluginSource, "function $helperFn(");
    $hEnd = strpos($pluginSource, "\n}", $hStart) + 2;
    eval(substr($pluginSource, $hStart, $hEnd - $hStart));
}
$start = strpos($pluginSource, 'function fno_fetch_futures_fn(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function callFuturesFn($queryParams) {
    $_GET = $queryParams;
    $GLOBALS['fno_test_transients'] = [];
    try { fno_fetch_futures_fn(); return null; }
    catch (TestWPDieException10 $e) { return $e->responseData; }
}

$csvHeader = "instrument_token,exchange_token,tradingsymbol,name,last_price,expiry,strike,tick_size,lot_size,instrument_type,segment,exchange";
$csvRows = [
    "201,1,NIFTY24AUGFUT,NIFTY,0,2026-08-27,0,0.05,50,FUT,NFO-FUT,NFO",
    "202,1,NIFTY24SEPFUT,NIFTY,0,2026-09-24,0,0.05,50,FUT,NFO-FUT,NFO",
    "203,1,NIFTY24OCTFUT,NIFTY,0,2026-10-29,0,0.05,50,FUT,NFO-FUT,NFO",
    "204,1,BANKNIFTY24AUGFUT,BANKNIFTY,0,2026-08-27,0,0.05,25,FUT,NFO-FUT,NFO", // real, different symbol - must be excluded
];
$GLOBALS['fno_test_instruments_csv'] = ['body' => $csvHeader . "\n" . implode("\n", $csvRows)];

echo "=== fno_fetch_futures_fn - real Kite futures fallback ===\n";

$GLOBALS['fno_test_quote_response'] = ['body' => json_encode(['data' => [
    'NFO:NIFTY24AUGFUT' => ['last_price' => 23260.50, 'oi' => 8500000],
    'NFO:NIFTY24SEPFUT' => ['last_price' => 23310.25, 'oi' => 1200000],
    'NFO:NIFTY24OCTFUT' => ['last_price' => 23355.00, 'oi' => 350000],
]])];
$r = callFuturesFn(['symbol' => 'NIFTY']);
assertTrue($r['data']['sourceStatus'] === 'kite_live', 'a real, successful Kite futures round-trip correctly reports kite_live');
assertTrue(count($r['data']['allFutures']) === 3, 'all 3 real NIFTY contracts found, correctly excluding the different real symbol (BANKNIFTY)');
assertTrue($r['data']['futuresPrice'] === 23260.50, 'the real, nearest-expiry contract is correctly selected as the primary futuresPrice');
assertTrue($r['data']['allFutures'][0]['expiryDate'] === '2026-08-27', 'real contracts are correctly sorted by expiry, nearest first');
assertTrue($r['data']['allFutures'][0]['openInterest'] === 8500000, 'the real, exact OI value is correctly carried through');

$GLOBALS['fno_test_instruments_csv'] = new WP_Error_Test10();
$r2 = callFuturesFn(['symbol' => 'NIFTY']);
assertTrue($r2['data']['sourceStatus'] === 'unavailable', 'real instrument-master fetch genuinely fails -> honest, complete unavailable state');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
