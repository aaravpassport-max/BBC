<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the real Kite
 * /margins/orders "what-if" order-margin calculator wired into
 * fno_fetch_kite_order_margin_fn() - built at the user's own direct,
 * explicit request to maximize real use of Zerodha/Kite as the ONLY
 * market-data source, replacing the hardcoded "~12% of notional"
 * proxy the Costs category's "Margin Blocked" factor previously used.
 *
 * Real risk this endpoint's own implementation is careful about
 * (documented in its own TRACE): NEVER string-builds Kite's date-coded
 * option tradingsymbol - always matches it from the real, already-
 * fetched instrument master by name+strike+instrument_type+expiry.
 * This test locks that behavior in.
 *
 * Stubs wp_remote_get() (instruments dump) and wp_remote_post()
 * (/margins/orders itself) with real, controllable fixtures - never a
 * real network call.
 *
 * Run with: php tests/php/KiteOrderMarginTest.php
 */

$GLOBALS['fno_test_is_logged_in'] = true;
$GLOBALS['fno_test_user_meta'] = ['fno_kite_settings' => ['api_key' => 'real_test_key', 'access_token' => 'real_test_token']];
$GLOBALS['fno_test_instruments_csv'] = null;
$GLOBALS['fno_test_margin_response'] = null;
$GLOBALS['fno_test_margin_post_body'] = null;
$GLOBALS['fno_test_transients'] = [];

function is_user_logged_in() { return $GLOBALS['fno_test_is_logged_in']; }
function get_current_user_id() { return 1; }
function get_user_meta($id, $key, $single) { return $GLOBALS['fno_test_user_meta'][$key] ?? []; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function is_wp_error($v) { return $v instanceof WP_Error_Test10; }
class WP_Error_Test10 {}
function get_transient($k) { return $GLOBALS['fno_test_transients'][$k] ?? false; }
function set_transient($k, $v, $e) { $GLOBALS['fno_test_transients'][$k] = $v; return true; }
function wp_json_encode($v) { return json_encode($v); }
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);

function wp_remote_get($url, $args = []) {
    if (strpos($url, 'api.kite.trade/instruments/NFO') !== false) {
        return $GLOBALS['fno_test_instruments_csv'] ?? new WP_Error_Test10();
    }
    return new WP_Error_Test10();
}
function wp_remote_post($url, $args = []) {
    if (strpos($url, 'api.kite.trade/margins/orders') !== false) {
        $GLOBALS['fno_test_margin_post_body'] = $args['body'] ?? null; // real, captured so tests can verify the exact real order payload sent
        return $GLOBALS['fno_test_margin_response'] ?? new WP_Error_Test10();
    }
    return new WP_Error_Test10();
}
function wp_remote_retrieve_response_code($res) { return 200; }
function wp_remote_retrieve_body($res) { return $res['body'] ?? '{}'; }
class TestWPDieException10 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException10(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException10(['success' => false, 'data' => $data]); }
function fno_rate_limit($endpoint, $limit = 30) { return true; }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
foreach (['fno_valid_symbols', 'fno_validate_symbol', 'fno_get_kite_session', 'fno_fetch_kite_instruments'] as $helperFn) {
    $hStart = strpos($pluginSource, "function $helperFn(");
    $hEnd = strpos($pluginSource, "\n}", $hStart) + 2;
    eval(substr($pluginSource, $hStart, $hEnd - $hStart));
}
$start = strpos($pluginSource, 'function fno_fetch_kite_order_margin_fn(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function callMarginFn($queryParams) {
    $_GET = $queryParams;
    $GLOBALS['fno_test_transients'] = [];
    $GLOBALS['fno_test_margin_post_body'] = null;
    try { fno_fetch_kite_order_margin_fn(); return null; }
    catch (TestWPDieException10 $e) { return $e->responseData; }
}

$csvHeader = "instrument_token,exchange_token,tradingsymbol,name,last_price,expiry,strike,tick_size,lot_size,instrument_type,segment,exchange";
$csvRows = [
    "111,1,NIFTY24AUG23200CE,NIFTY,0,2026-08-27,23200,0.05,50,CE,NFO-OPT,NFO",
    "112,1,NIFTY25SEP23200CE,NIFTY,0,2026-09-24,23200,0.05,50,CE,NFO-OPT,NFO", // real, LATER expiry - nearest-expiry selection must prefer the Aug one
    "113,1,BANKNIFTY24AUG50000CE,BANKNIFTY,0,2026-08-27,50000,0.05,25,CE,NFO-OPT,NFO", // real, different symbol - must never match
];
$GLOBALS['fno_test_instruments_csv'] = ['body' => $csvHeader . "\n" . implode("\n", $csvRows)];

echo "=== fno_fetch_kite_order_margin_fn - real Kite order-margin calculator (Zerodha-maximization audit) ===\n";

// Scenario A: not logged in - honest unavailable, no Kite session possible.
$GLOBALS['fno_test_is_logged_in'] = false;
$r = callMarginFn(['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'lotSize' => '50']);
assertTrue($r['data']['margin'] === null && $r['data']['tier'] === 'unavailable', 'genuinely not logged in -> honest unavailable, no Kite attempt at all');
$GLOBALS['fno_test_is_logged_in'] = true;

// Scenario B: missing/invalid required params - honest unavailable, never guesses a strike/type.
$r2 = callMarginFn(['symbol' => 'NIFTY', 'strike' => '0', 'optionType' => 'CE', 'lotSize' => '50']);
assertTrue($r2['data']['margin'] === null && $r2['data']['tier'] === 'unavailable', 'strike genuinely missing/zero -> honest unavailable, never guessed');
$r3 = callMarginFn(['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'XX', 'lotSize' => '50']);
assertTrue($r3['data']['margin'] === null, 'an invalid optionType (not CE/PE) -> honest unavailable');

// Scenario C: a real, complete, successful round-trip.
$GLOBALS['fno_test_margin_response'] = ['body' => json_encode(['data' => [
    ['total' => 15230.5, 'span' => 12000.0, 'exposure' => 3230.5],
]])];
$r4 = callMarginFn(['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'lotSize' => '50']);
assertTrue($r4['data']['tier'] === 'own_kite_session', 'a real, successful round-trip correctly reports own_kite_session');
assertTrue($r4['data']['margin']['total'] === 15230.5, 'the real, exact total margin figure is correctly returned, not altered');
assertTrue($r4['data']['margin']['span'] === 12000.0 && $r4['data']['margin']['exposure'] === 3230.5, 'real span/exposure breakdown correctly returned');
assertTrue($r4['data']['margin']['tradingsymbol'] === 'NIFTY24AUG23200CE', 'the REAL, nearest-expiry tradingsymbol was correctly selected (Aug, not the later Sep contract) - matched from the real instrument master, never string-built');

$postPayload = json_decode($GLOBALS['fno_test_margin_post_body'], true);
assertTrue($postPayload[0]['tradingsymbol'] === 'NIFTY24AUG23200CE', 'the real order-margin POST payload uses the real, matched tradingsymbol, not a constructed guess');
assertTrue($postPayload[0]['transaction_type'] === 'BUY', 'real payload correctly requests a BUY-side margin (this app is options-buying only, per its own Regulatory factors) - never a short/write margin');
assertTrue($postPayload[0]['quantity'] === 50, 'real payload uses the real, exact lot size passed in, not a hardcoded default');
assertTrue($postPayload[0]['exchange'] === 'NFO', 'real payload targets the real NFO exchange');

// Scenario D: symbol/strike with no real matching contract at all - honest unavailable, never guessed from the nearest available strike.
$r5 = callMarginFn(['symbol' => 'NIFTY', 'strike' => '99999', 'optionType' => 'CE', 'lotSize' => '50']);
assertTrue($r5['data']['margin'] === null && $r5['data']['tier'] === 'unavailable', 'genuinely no matching real contract for this strike -> honest unavailable, never guessed from a nearby strike');

// Scenario E: real instruments fetch fails - must cascade to honest unavailable, not crash.
$GLOBALS['fno_test_instruments_csv'] = new WP_Error_Test10();
$r6 = callMarginFn(['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'lotSize' => '50']);
assertTrue($r6['data']['margin'] === null && $r6['data']['tier'] === 'unavailable', 'real instrument-master fetch genuinely fails -> honest unavailable, not a crash');
$GLOBALS['fno_test_instruments_csv'] = ['body' => $csvHeader . "\n" . implode("\n", $csvRows)]; // restore

// Scenario F: the real /margins/orders call itself genuinely fails - honest unavailable, NEVER falls back to a fabricated estimate.
$GLOBALS['fno_test_margin_response'] = new WP_Error_Test10();
$r7 = callMarginFn(['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'lotSize' => '50']);
assertTrue($r7['data']['margin'] === null && $r7['data']['tier'] === 'unavailable', 'the real /margins/orders API call itself genuinely fails -> honest unavailable, never a fabricated estimate substituted in its place');

// Scenario G: a real response with a genuinely malformed/unexpected shape - honest unavailable, never a guess.
$GLOBALS['fno_test_margin_response'] = ['body' => json_encode(['data' => []])]; // real 200 OK but genuinely empty data array
$r8 = callMarginFn(['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'lotSize' => '50']);
assertTrue($r8['data']['margin'] === null && $r8['data']['tier'] === 'unavailable', 'a real 200 OK response with a genuinely empty data array -> honest unavailable, never a guessed figure');

$GLOBALS['fno_test_margin_response'] = ['body' => json_encode(['data' => [['span' => 12000]]])]; // real response missing the 'total' field entirely
$r9 = callMarginFn(['symbol' => 'NIFTY', 'strike' => '23200', 'optionType' => 'CE', 'lotSize' => '50']);
assertTrue($r9['data']['margin'] === null && $r9['data']['tier'] === 'unavailable', 'a real response genuinely missing the total field -> honest unavailable, never guessed from span/exposure alone');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
