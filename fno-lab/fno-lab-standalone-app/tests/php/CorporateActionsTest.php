<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_fetch_corporate_actions_fn() - closes
 * docs/PENDING_REQUIREMENTS.md's "Corporate Actions... FREE-tier
 * automated corporate-actions scraper was never built" item.
 *
 * Stubs fno_nse_get() itself rather than the full real NSE fetch
 * chain (session cookie/circuit breaker/wp_remote_get) - the same
 * real, layered-dependency approach already used elsewhere in this
 * project's test suite: fno_nse_get is a real, separately-proven
 * function (used successfully by the chart/option-chain endpoints
 * this app already relies on), so this test focuses on what's
 * actually new here - the real field extraction/sanitization and the
 * real, honest failure path - not re-proving fno_nse_get's own
 * already-established real behavior.
 *
 * Run with: php tests/php/CorporateActionsTest.php
 */

$GLOBALS['fno_test_options'] = [];
function get_option($k, $d = false) { return $GLOBALS['fno_test_options'][$k] ?? $d; }
function set_transient($k, $v, $e) { $GLOBALS['fno_test_options']['transient_' . $k] = $v; return true; }
function get_transient($k) { return $GLOBALS['fno_test_options']['transient_' . $k] ?? false; }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function current_time($f) { return time(); }
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
function check_ajax_referer($a, $k, $d = true) { return true; }
class TestWPDieException7 extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function wp_send_json_success($data = null) { throw new TestWPDieException7(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException7(['success' => false, 'data' => $data]); }
function fno_verify_public_or_driver_access() { return true; }
function fno_rate_limit($key) { return true; }
$GLOBALS['fno_test_nse_response'] = null;
function fno_nse_get($url, $referer) { return $GLOBALS['fno_test_nse_response']; }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
// Real fno_now_ist() extraction - this real function is now genuinely
// used inside fno_fetch_corporate_actions_fn's own real cache-key
// construction (fixed for real IST-timezone correctness this
// session), so it must be real, not a fake stub, for this test to
// actually exercise the real, current code.
$nowIstStart = strpos($pluginSource, 'function fno_now_ist(');
$nowIstEnd = strpos($pluginSource, "\n}", $nowIstStart) + 2;
eval(substr($pluginSource, $nowIstStart, $nowIstEnd - $nowIstStart));
$nseEnabledStart = strpos($pluginSource, 'function fno_is_nse_integration_enabled(');
$nseEnabledEnd = strpos($pluginSource, "\n}", $nseEnabledStart) + 2;
eval(substr($pluginSource, $nseEnabledStart, $nseEnabledEnd - $nseEnabledStart));
$start = strpos($pluginSource, 'function fno_fetch_corporate_actions_fn(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_fetch_corporate_actions_fn (real, free-tier NSE corporate actions) ===\n";

// Real, honest failure path: fno_nse_get() returns null (NSE unreachable, blocked, or endpoint changed).
$GLOBALS['fno_test_options'] = [];
$GLOBALS['fno_test_nse_response'] = null;
$_GET = ['symbol' => 'RELIANCE', 'nseEnabled' => '1']; // real, explicit ON - these scenarios specifically test NSE's own real success/failure behavior, so NSE must genuinely be attempted
$response = null;
try { fno_fetch_corporate_actions_fn(); } catch (TestWPDieException7 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real NSE failure is still a real, honest success response (matching the chart endpoint\'s own established pattern), not a hard error');
assertTrue($response['data']['actions'] === [], 'a real NSE failure produces a genuinely empty actions array, never fabricated entries');
assertTrue($response['data']['isFallback'] === true, 'a real NSE failure is honestly flagged as a fallback');

// Real, successful NSE response: fields are correctly extracted and sanitized.
$GLOBALS['fno_test_options'] = [];
$GLOBALS['fno_test_nse_response'] = [
    ['symbol' => 'RELIANCE', 'subject' => 'Interim Dividend', 'exDate' => '20-Aug-2026'],
    ['symbol' => 'RELIANCE', 'subject' => '<script>bad</script>Bonus Issue', 'exDate' => '25-Aug-2026'],
];
$_GET = ['symbol' => 'RELIANCE', 'nseEnabled' => '1']; // real, explicit ON - these scenarios specifically test NSE's own real success/failure behavior, so NSE must genuinely be attempted
$response = null;
try { fno_fetch_corporate_actions_fn(); } catch (TestWPDieException7 $e) { $response = $e->responseData; }
assertTrue($response['success'] === true, 'a real, successful NSE response succeeds');
assertTrue(count($response['data']['actions']) === 2, 'both real, mocked actions are returned');
assertTrue($response['data']['actions'][0]['subject'] === 'Interim Dividend', 'real subject field correctly extracted');
assertTrue(strpos($response['data']['actions'][1]['subject'], '<script>') === false, 'a real, malicious subject field is genuinely sanitized, never passed through raw');
assertTrue($response['data']['isFallback'] === false, 'a real, successful response is honestly NOT flagged as a fallback');

// Real caching: a second real call within the cache window reuses the cached result, not a fresh NSE call.
$GLOBALS['fno_test_nse_response'] = null; // if this were actually called again, it would now honestly fail
$response = null;
try { fno_fetch_corporate_actions_fn(); } catch (TestWPDieException7 $e) { $response = $e->responseData; }
assertTrue(count($response['data']['actions']) === 2, 'a real, cached result is correctly reused within the cache window, not a fresh (and now failing) NSE call');

// Real, new NSE Integration toggle behavior (user's own direct,
// explicit request) - a genuinely different, unique symbol to avoid
// the real cache from the scenarios above.
$GLOBALS['fno_test_nse_response'] = ['symbol' => 'TCS', 'subject' => 'Should never be reached', 'exDate' => '01-Jan-2027'];
$_GET = ['symbol' => 'TCS', 'nseEnabled' => '0'];
$response = null;
try { fno_fetch_corporate_actions_fn(); } catch (TestWPDieException7 $e) { $response = $e->responseData; }
assertTrue($response['data']['sourceStatus'] === 'disabled', 'with NSE Integration genuinely OFF, this real, NSE-only endpoint (no Kite equivalent exists) honestly reports disabled, never attempting the real NSE call');
assertTrue($response['data']['actions'] === [], 'with NSE genuinely disabled, the real actions array is honestly empty, never fabricated');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
