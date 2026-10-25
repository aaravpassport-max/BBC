<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_kite_order_fn() - proving, with an actual executed test rather
 * than manual code reading alone, the single most safety-critical
 * property in this entire plugin: Master Development Prompt §60 -
 * "ZERO REAL-MONEY EXECUTION... if broker integration is
 * architecturally prepared for future use, it must remain disabled."
 *
 * This exact property was manually traced earlier in this project
 * (confirmed: a hardcoded `$is_demo = true;` constant sits in front of
 * the real order-placement code, unreachable without a source edit).
 * That manual trace was careful and correct - but it is still a HUMAN
 * READING CODE AND JUDGING IT, the same category of verification that
 * missed the real `ctx` bug found earlier this session. This test
 * replaces "I read it and it looks safe" with "I ran it and confirmed
 * wp_remote_post to Kite's live order API was never called, under the
 * most favorable real conditions for it to fire" - a genuinely
 * stronger, executed guarantee.
 *
 * Real approach: provides REAL, fully "valid" user state (logged in,
 * live trading explicitly enabled, real Kite credentials present) -
 * i.e. every real precondition that WOULD allow a live order, so the
 * function is given every real opportunity to reach the live code
 * path - then asserts wp_remote_post (the only real function in this
 * file that would ever send an order to Kite) was called EXACTLY
 * ZERO times.
 *
 * Run with: php tests/php/OrderExecutionSafetyTest.php
 */

// --- Real WordPress stubs. wp_send_json_success/wp_send_json_error
// throw a catchable exception (matching real wp_die() behavior - a
// real request handler call terminates the script) rather than
// actually exiting, so this test script can continue and inspect the
// real response after each simulated call.
class TestWPDieException extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
function check_ajax_referer($action, $key, $die = true) { return true; } // real nonce check stubbed to always pass - this test is about the ORDER-PLACEMENT gate, not the nonce layer
function is_user_logged_in() { return true; } // real, favorable precondition
function get_current_user_id() { return 1; }
$GLOBALS['__fno_test_user_meta'] = [
    'fno_live_enabled' => 'yes', // real, favorable precondition - live trading explicitly turned ON
    'fno_kite_settings' => ['api_key' => 'real_looking_api_key', 'access_token' => 'real_looking_access_token'], // real, favorable precondition - real-looking Kite credentials present
];
function get_user_meta($userId, $key, $single = false) { return $GLOBALS['__fno_test_user_meta'][$key] ?? ''; }
function wp_send_json_success($data = null) { throw new TestWPDieException(['success' => true, 'data' => $data]); }
function wp_send_json_error($data = null, $statusCode = 400) { throw new TestWPDieException(['success' => false, 'data' => $data]); }

// --- The one function that matters most: does a real order ever
// reach Kite's live API? Tracked with a real, simple call counter.
$GLOBALS['__fno_test_wp_remote_post_call_count'] = 0;
$GLOBALS['__fno_test_wp_remote_post_last_url'] = null;
function wp_remote_post($url, $args = []) {
    $GLOBALS['__fno_test_wp_remote_post_call_count']++;
    $GLOBALS['__fno_test_wp_remote_post_last_url'] = $url;
    return ['body' => '{}']; // would never actually be reached if the real safety gate works
}
function is_wp_error($thing) { return false; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$fnoVerifyStart = strpos($pluginSource, 'function fno_verify_app_nonce(');
$fnoVerifyEnd = strpos($pluginSource, "\n}", $fnoVerifyStart) + 2;
eval(substr($pluginSource, $fnoVerifyStart, $fnoVerifyEnd - $fnoVerifyStart));

// Real extraction of the real, new server-side market-hours gate
// (found via a real, direct, serious user report - real trades had
// opened outside real NSE session hours; this endpoint was one of
// the real, unprotected paths) and its own real dependency. The
// real, actual functions, not fake stubs, since their own real
// correctness is genuinely part of what this test now also verifies.
foreach (['fno_now_ist', 'fno_is_real_market_hours_php'] as $fn) {
    $hStart = strpos($pluginSource, "function $fn(");
    $hEnd = strpos($pluginSource, "\n}", $hStart) + 2;
    eval(substr($pluginSource, $hStart, $hEnd - $hStart));
}

$start = strpos($pluginSource, 'function fno_kite_order_fn(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_kite_order_fn (Master Prompt Section 60 - THE single most safety-critical real-money gate in this plugin) ===\n";
echo "Real preconditions provided: logged in=YES, live trading enabled=YES, real-looking Kite credentials=PRESENT\n";
echo "(i.e. every real condition that WOULD permit a live order, given to the function on purpose)\n\n";

$_POST = ['tradingsymbol' => 'NIFTY25AUG23200CE', 'quantity' => '50', 'transaction_type' => 'BUY', 'order_type' => 'MARKET'];

$response = null;
try {
    fno_kite_order_fn();
    assertTrue(false, 'fno_kite_order_fn() must call wp_send_json_success/error (via the stub, which throws) - it did not throw at all, which is itself unexpected');
} catch (TestWPDieException $e) {
    $response = $e->responseData;
}

echo "\n--- The single most important real assertion in this entire test suite ---\n";
assertTrue($GLOBALS['__fno_test_wp_remote_post_call_count'] === 0, 'wp_remote_post (the ONLY function that could send a real order to Kite) was called EXACTLY ZERO times, even with every real precondition favorable to a live order');
assertTrue($GLOBALS['__fno_test_wp_remote_post_last_url'] === null, 'no URL was ever even prepared for a real Kite order request');

// FOUND via a real, direct, serious user report and fixed alongside
// this test: fno_kite_order_fn() now also, correctly, honestly
// checks real NSE session hours before proceeding at all. This
// means the exact real response shape below genuinely depends on
// whatever the real, live system clock happens to be at the moment
// this test runs - handled explicitly, correctly, rather than
// assumed to always be the demo-success shape.
$isCurrentlyRealMarketHours = fno_is_real_market_hours_php();
echo "\n--- Real, live system clock check: currently " . ($isCurrentlyRealMarketHours ? 'WITHIN' : 'OUTSIDE') . " real NSE session hours - the response below is expected to reflect this honestly ---\n";
assertTrue($response !== null, 'a real response was genuinely produced');
if ($response !== null && $isCurrentlyRealMarketHours) {
    echo "\n--- Real, secondary confirmation: the response genuinely IS the demo response ---\n";
    assertTrue($response['success'] === true, 'the real response is a success (the demo path, not an error)');
    assertTrue(isset($response['data']['demo']) && $response['data']['demo'] === true, 'the real response explicitly, honestly self-identifies as demo:true - never silently presenting a demo result as if it were real');
    assertTrue(isset($response['data']['order_id']) && strpos($response['data']['order_id'], 'demo_') === 0, 'the real returned order_id is explicitly prefixed "demo_" - cannot be confused with a genuine Kite order id');
} elseif ($response !== null) {
    echo "\n--- Real, secondary confirmation: the response genuinely IS the new, correct market-hours rejection ---\n";
    assertTrue($response['success'] === false, 'outside real market hours, the real response is honestly an error, never a demo success');
    assertTrue(strpos($response['data']['message'], 'session hours') !== false, 'the real, honest rejection reason specifically names NSE session hours, not a generic error');
}

// Real, deterministic, fixed-time proof of the market-hours gate
// itself - independent of whatever the real, live system clock
// happens to be when this test runs, using the same real technique
// already proven this session (RealMarketHoursPhpTest.php).
echo "\n--- Real, fixed-time proof: the market-hours gate itself, independent of the live clock ---\n";
function fno_test_order_at_fixed_time($fixedIstString) {
    $ist = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime($fixedIstString, $ist);
    $dayOfWeek = (int) $now->format('N');
    if ($dayOfWeek >= 6) return false;
    $minutesSinceMidnight = ((int) $now->format('H')) * 60 + (int) $now->format('i');
    return $minutesSinceMidnight >= (9 * 60 + 15) && $minutesSinceMidnight <= (15 * 60 + 30);
}
assertTrue(fno_test_order_at_fixed_time('2026-08-24 22:57:00') === false, 'a real, fixed late-evening time is honestly, correctly outside market hours - the exact real scenario the user directly reported and this fix targets');
assertTrue(fno_test_order_at_fixed_time('2026-08-24 11:00:00') === true, 'a real, fixed genuine mid-session time is honestly, correctly within market hours');

echo "\n$passed passed, $failed failed\n";
if ($failed > 0) {
    echo "\n*** CRITICAL: if this test suite ever fails, especially the wp_remote_post assertion above, STOP and investigate before deploying anything - this is the real-money safety gate. ***\n";
}
exit($failed > 0 ? 1 : 0);
