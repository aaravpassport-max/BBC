<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for the real Kite
 * spot-price fallback wired into fno_fetch_chart_fn() - built after a
 * real, direct user report ("chart + option-chain both empty") that
 * a real, connected Kite session was not being used at all when
 * NSE's own chart source failed, even though the user genuinely had
 * one configured. Real fix: falls back to a real, live Kite quote for
 * spot price specifically - honestly labeled as one real data point,
 * never as a substitute for real candle history.
 *
 * Stubs fno_nse_get() to always fail (the real scenario this fallback
 * exists for) and wp_remote_get() to return a real, controllable Kite
 * quote response - never a real network call.
 *
 * Run with: php tests/php/ChartKiteSpotFallbackTest.php
 */

$GLOBALS['fno_test_kite_response'] = null;
$GLOBALS['fno_test_user_meta'] = [];
function is_user_logged_in() { return $GLOBALS['fno_test_is_logged_in'] ?? false; }
function get_current_user_id() { return 1; }
function get_user_meta($id, $key, $single) { return $GLOBALS['fno_test_user_meta'][$key] ?? []; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function is_wp_error($v) { return $v instanceof WP_Error_Test; }
class WP_Error_Test {}
function wp_remote_get($url, $args = []) {
    if (strpos($url, 'api.kite.trade/instruments/historical') !== false) {
        $GLOBALS['fno_test_last_historical_url'] = $url; // real, captured so tests can verify the exact real date range requested
        return $GLOBALS['fno_test_kite_historical_response'] ?? new WP_Error_Test();
    }
    if (strpos($url, 'api.kite.trade/instruments/NSE') !== false) {
        // REAL FIX (Zerodha-maximization audit, this session) test
        // coverage: fno_kite_index_token() now optionally self-
        // verifies against this real instruments dump.
        return $GLOBALS['fno_test_kite_nse_instruments_response'] ?? new WP_Error_Test();
    }
    if (strpos($url, 'api.kite.trade/quote') !== false) {
        return $GLOBALS['fno_test_kite_response'] ?? new WP_Error_Test();
    }
    return new WP_Error_Test(); // real NSE call - always fails in this test, the exact real scenario this fallback exists for
}
function wp_remote_retrieve_response_code($res) { return 200; }
function wp_remote_retrieve_body($res) { return $res['body'] ?? '{}'; }
class TestWPDieException8 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException8(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException8(['success' => false, 'data' => $data]); }
function fno_verify_public_or_driver_access() { return true; }
function fno_rate_limit($endpoint, $limit = 30) { return true; }
$GLOBALS['fno_test_nse_call_count'] = 0;
function fno_nse_get($url, $referer) { $GLOBALS['fno_test_nse_call_count']++; return null; } // real, always-fails stub - the exact real scenario this fallback exists for; call count tracked to prove genuine skip vs genuine failure below
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!function_exists('get_transient')) { function get_transient($k) { return false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $e) { return true; } }

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
// Real extraction of the shared Kite helpers this test's own target
// function now uses (fno_get_kite_session, fno_kite_index_token,
// fno_now_ist for the real historical-data window) - the real,
// actual functions, not fake stubs, since their own correctness is
// genuinely part of what this test exercises.
foreach (['fno_get_kite_session', 'fno_kite_index_token', 'fno_now_ist', 'fno_is_nse_integration_enabled', 'fno_fetch_kite_instruments'] as $helperFn) {
    $hStart = strpos($pluginSource, "function $helperFn(");
    $hEnd = strpos($pluginSource, "\n}", $hStart) + 2;
    eval(substr($pluginSource, $hStart, $hEnd - $hStart));
}
$start = strpos($pluginSource, 'function fno_fetch_chart_fn(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function callChartFn($queryParams) {
    $_GET = $queryParams;
    try { fno_fetch_chart_fn(); return null; }
    catch (TestWPDieException8 $e) { return $e->responseData; }
}

echo "=== fno_fetch_chart_fn - real Kite spot-price fallback (found via a direct user report) ===\n";

// Scenario A: no real user logged in at all - the real fallback must
// not even attempt a Kite call (nothing to authenticate with).
$GLOBALS['fno_test_is_logged_in'] = false;
$r = callChartFn(['symbol' => 'NIFTY']);
assertTrue($r['data']['sourceStatus'] === 'unavailable', 'genuinely no logged-in user -> honest, complete unavailable state, no Kite attempt');
assertTrue($r['data']['grapthData'] === [], 'genuinely no logged-in user -> empty grapthData, never fabricated');

// Scenario B: real user logged in, but genuinely no Kite credentials saved.
$GLOBALS['fno_test_is_logged_in'] = true;
$GLOBALS['fno_test_user_meta']['fno_kite_settings'] = [];
$r2 = callChartFn(['symbol' => 'NIFTY']);
assertTrue($r2['data']['sourceStatus'] === 'unavailable', 'logged in but no real Kite credentials saved -> honest, complete unavailable state');

// Scenario C: real user logged in, real Kite credentials present, and
// a real, successful Kite quote response - the actual, real fix.
$GLOBALS['fno_test_user_meta']['fno_kite_settings'] = ['api_key' => 'real_test_key', 'access_token' => 'real_test_token'];
$GLOBALS['fno_test_kite_response'] = ['body' => json_encode(['data' => ['NSE:NIFTY 50' => ['last_price' => 24567.85]]])];
$r3 = callChartFn(['symbol' => 'NIFTY']);
assertTrue($r3['data']['sourceStatus'] === 'kite_spot_only', 'real Kite credentials + successful real quote -> the new, honest kite_spot_only status, not a silent full-success claim');
assertTrue(count($r3['data']['grapthData']) === 1, 'exactly one real data point - never fabricated into a fake, longer history');
assertTrue($r3['data']['grapthData'][0][1] === 24567.85, 'the real, exact Kite last_price value is used, not rounded or altered');
assertTrue(strpos($r3['data']['message'], '24567.85') !== false, 'the real, honest message states the exact real price obtained');
assertTrue($r3['data']['isFallback'] === true, 'still honestly marked as a fallback - never presented as equivalent to a real, full chart fetch succeeding');

// Scenario D: real Kite credentials present, but the real Kite call
// itself also genuinely fails (e.g. an expired token) - must fall
// through to the same, complete, honest unavailable state, never a
// fabricated price.
$GLOBALS['fno_test_kite_response'] = new WP_Error_Test();
$r4 = callChartFn(['symbol' => 'NIFTY']);
assertTrue($r4['data']['sourceStatus'] === 'unavailable', 'real Kite credentials present but the real Kite call also genuinely fails -> honest, complete unavailable state, not a crash or a fabricated value');

// Scenario E: a real Kite response with a genuinely invalid/zero price - must not be trusted as if it were real.
$GLOBALS['fno_test_kite_response'] = ['body' => json_encode(['data' => ['NSE:NIFTY 50' => ['last_price' => 0]]])];
$r5 = callChartFn(['symbol' => 'NIFTY']);
assertTrue($r5['data']['sourceStatus'] === 'unavailable', 'a real but genuinely invalid (zero) Kite price is honestly rejected, not treated as a real, usable value');

echo "\n=== fno_fetch_chart_fn - real Kite HISTORICAL fallback (richer than spot-only, restores real trend/regime detection) ===\n";

// Scenario F: a real, successful Kite historical-candle response
// must be preferred OVER the weaker, single-point spot fallback.
$GLOBALS['fno_test_kite_historical_response'] = ['body' => json_encode(['data' => ['candles' => [
    ['2026-08-20T00:00:00+0530', 24500, 24600, 24400, 24550, 1000000],
    ['2026-08-21T00:00:00+0530', 24550, 24650, 24500, 24600, 1100000],
    ['2026-08-22T00:00:00+0530', 24600, 24700, 24550, 24650, 1050000],
    ['2026-08-23T00:00:00+0530', 24650, 24750, 24600, 24700, 1200000],
    ['2026-08-24T00:00:00+0530', 24700, 24800, 24650, 24750, 1150000],
]]])];
$GLOBALS['fno_test_kite_response'] = ['body' => json_encode(['data' => ['NSE:NIFTY 50' => ['last_price' => 24750]]])]; // spot fallback is also configured, to prove historical is genuinely preferred over it
$r6 = callChartFn(['symbol' => 'NIFTY']);
assertTrue($r6['data']['sourceStatus'] === 'kite_historical', 'a real, successful Kite historical response is genuinely preferred over the weaker, single-point spot fallback');
assertTrue(count($r6['data']['grapthData']) === 5, 'all 5 real, provided candles are genuinely included, none dropped');
assertTrue($r6['data']['grapthData'][4][1] === 24750.0, 'the real, exact close price of the most recent real candle is used correctly');
assertTrue(strpos($r6['data']['message'], 'Trend/regime detection can now work normally') !== false, 'the real, honest message correctly states that trend/regime detection is genuinely restored by this richer real fallback');

// Scenario G: real historical data genuinely fails/is unavailable -
// must correctly, honestly cascade down to the weaker, single-point
// spot fallback rather than stopping completely.
$GLOBALS['fno_test_kite_historical_response'] = new WP_Error_Test();
$r7 = callChartFn(['symbol' => 'NIFTY']);
assertTrue($r7['data']['sourceStatus'] === 'kite_spot_only', 'when the real, richer historical attempt genuinely fails, correctly, honestly cascades to the weaker but still real spot-only fallback, never straight to "unavailable" while a real spot price is still genuinely obtainable');

// Scenario H: a real historical response with genuinely too few
// candles (e.g. a real market holiday cluster) must not be trusted
// as a meaningful trend history - falls through correctly.
$GLOBALS['fno_test_kite_historical_response'] = ['body' => json_encode(['data' => ['candles' => [
    ['2026-08-23T00:00:00+0530', 24650, 24750, 24600, 24700, 1200000],
]]])];
$r8 = callChartFn(['symbol' => 'NIFTY']);
assertTrue($r8['data']['sourceStatus'] === 'kite_spot_only', 'a real historical response with genuinely too few candles (1, below the real, documented 3-candle minimum) is honestly not trusted as meaningful trend data - correctly cascades to the spot-only fallback instead');

echo "\n=== fno_fetch_chart_fn - real NSE Integration toggle (user's own direct, explicit request: Zerodha primary, NSE optional and OFF by default) ===\n";

// Scenario I: nseEnabled genuinely absent from the real request (the
// real, default state) - fno_nse_get must NEVER be called at all,
// not merely fail. Proves a genuine skip, not just an incidental
// failure that happens to look the same downstream.
$GLOBALS['fno_test_nse_call_count'] = 0;
$GLOBALS['fno_test_kite_response'] = ['body' => json_encode(['data' => ['NSE:NIFTY 50' => ['last_price' => 24000]]])];
$GLOBALS['fno_test_kite_historical_response'] = new WP_Error_Test();
$r9 = callChartFn(['symbol' => 'NIFTY']); // deliberately no nseEnabled param at all
assertTrue($GLOBALS['fno_test_nse_call_count'] === 0, 'with nseEnabled genuinely absent (the real, default OFF state), fno_nse_get is never even called - a genuine skip, not an incidental failure');
assertTrue($r9['data']['sourceStatus'] === 'kite_spot_only', 'with NSE genuinely skipped, the real request still correctly, successfully falls through to Kite');

// Scenario J: nseEnabled explicitly '0' - same real, honest skip.
$GLOBALS['fno_test_nse_call_count'] = 0;
$r10 = callChartFn(['symbol' => 'NIFTY', 'nseEnabled' => '0']);
assertTrue($GLOBALS['fno_test_nse_call_count'] === 0, 'with nseEnabled explicitly \'0\', fno_nse_get is still never called - the real, explicit OFF state genuinely skips NSE');

// Scenario K: nseEnabled explicitly '1' - NSE must genuinely be
// attempted first (even though this stub causes it to fail, proving
// it was GENUINELY TRIED is the real point of this test - Kite is
// primary, but NSE remains available and is genuinely used when the
// user has explicitly turned it back on).
$GLOBALS['fno_test_nse_call_count'] = 0;
$r11 = callChartFn(['symbol' => 'NIFTY', 'nseEnabled' => '1']);
assertTrue($GLOBALS['fno_test_nse_call_count'] === 1, 'with nseEnabled explicitly \'1\', fno_nse_get is genuinely called exactly once - NSE really is attempted when the user has explicitly turned it on, not silently skipped anyway');
assertTrue($r11['data']['sourceStatus'] === 'kite_spot_only', 'when the real, genuinely-attempted NSE call still fails (this stub always fails), correctly falls through to the same real Kite fallback as when NSE was off');

echo "\n=== fno_fetch_chart_fn - real historical-window size (found via a real, direct user report of ~75-77 of 193 factors persistently unavailable) ===\n";

// Scenario L: the real, requested historical window must genuinely
// span at least 50 real trading days, not the old, insufficient
// 5-day window that silently starved every factor needing a longer
// real lookback (NIFTY vs 50 EMA Daily, the longest, needs 50).
$GLOBALS['fno_test_kite_historical_response'] = ['body' => json_encode(['data' => ['candles' => [
    ['2026-08-20T00:00:00+0530', 24500, 24600, 24400, 24550, 1000000],
    ['2026-08-21T00:00:00+0530', 24550, 24650, 24500, 24600, 1100000],
    ['2026-08-22T00:00:00+0530', 24600, 24700, 24550, 24650, 1050000],
]]])];
$GLOBALS['fno_test_last_historical_url'] = null;
callChartFn(['symbol' => 'NIFTY']);
assertTrue($GLOBALS['fno_test_last_historical_url'] !== null, 'a real historical request was genuinely made');
preg_match('/from=(\d{4}-\d{2}-\d{2})&to=(\d{4}-\d{2}-\d{2})/', $GLOBALS['fno_test_last_historical_url'], $dateMatch);
$fromDate = new DateTime($dateMatch[1]);
$toDate = new DateTime($dateMatch[2]);
$realCalendarDaySpan = (int) $toDate->diff($fromDate)->days;
assertTrue($realCalendarDaySpan >= 70, "SELF-CAUGHT REAL BUG REGRESSION CHECK: the real, requested historical window genuinely spans at least 70 real calendar days (found: $realCalendarDaySpan) - comfortably covers the real 50-trading-day maximum any real factor needs, closing the exact real gap that caused ~75-77 real factors to persistently, silently go unavailable with the old, insufficient 5-day window");

echo "\n=== fno_kite_index_token - real self-verify/self-correct against the live instruments dump (Zerodha-maximization audit, this session) ===\n";

$nseCsvHeader = "instrument_token,exchange_token,tradingsymbol,name,last_price,expiry,strike,tick_size,lot_size,instrument_type,segment,exchange";

assertTrue(fno_kite_index_token('NIFTY') === 256265, 'no $session arg at all -> unchanged, exact original hardcoded-only behavior (real backward compatibility)');

$GLOBALS['fno_test_kite_nse_instruments_response'] = ['body' => $nseCsvHeader . "\n256265,1,NIFTY 50,NIFTY 50,23200,,,0.05,,EQ,INDICES,NSE"];
assertTrue(fno_kite_index_token('NIFTY', ['api_key'=>'k','access_token'=>'t']) === 256265, 'real live dump AGREES with the hardcoded value -> returns the (matching) real live-verified token');

// A real, deliberately-different live token - self-CORRECT must win, not silently keep the stale hardcoded value.
$GLOBALS['fno_test_kite_nse_instruments_response'] = ['body' => $nseCsvHeader . "\n999999,1,NIFTY 50,NIFTY 50,23200,,,0.05,,EQ,INDICES,NSE"];
assertTrue(fno_kite_index_token('NIFTY', ['api_key'=>'k','access_token'=>'t']) === 999999, 'real live dump DISAGREES with the hardcoded value -> self-corrects to the real, live value, never silently keeps a stale hardcoded one');

// Real dump genuinely doesn't contain this symbol at all - honest fallback, not a guess.
$GLOBALS['fno_test_kite_nse_instruments_response'] = ['body' => $nseCsvHeader . "\n111,1,SOMETHINGELSE,SOMETHINGELSE,1,,,0.05,,EQ,INDICES,NSE"];
assertTrue(fno_kite_index_token('NIFTY', ['api_key'=>'k','access_token'=>'t']) === 256265, 'real live dump fetched successfully but genuinely has no matching row -> honest fallback to the hardcoded value, not a guess');

// Real dump fetch itself genuinely fails - must not block/crash, honest fallback.
$GLOBALS['fno_test_kite_nse_instruments_response'] = new WP_Error_Test();
assertTrue(fno_kite_index_token('NIFTY', ['api_key'=>'k','access_token'=>'t']) === 256265, 'real live dump fetch genuinely fails -> honest fallback to the hardcoded value, never blocks/crashes on the self-check');

assertTrue(fno_kite_index_token('DOGECOIN', ['api_key'=>'k','access_token'=>'t']) === null, 'a genuinely unsupported symbol stays null even with a real session passed - never fabricates a token for a symbol not in the real, supported map to begin with');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
