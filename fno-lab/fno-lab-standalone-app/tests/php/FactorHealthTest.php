<?php
/**
 * REAL, LIVE-DATABASE PHP test for fno_get_factor_health_fn() - built
 * at the user's own direct, explicit request for a real, in-app
 * diagnostic system showing which of the 193 factors are genuinely
 * working and which factors' signals actually correlate with real
 * wins vs losses.
 *
 * Unlike this project's other, isolated PHP tests, this one
 * deliberately runs against a REAL, live MySQL database (via
 * wp-load.php) rather than stubbing $wpdb - because the entire real
 * value of this endpoint is a real SQL JOIN and real aggregate math,
 * which is exactly the kind of logic most likely to have a subtle,
 * real bug that a stubbed, in-memory fake could hide. Requires a
 * real, running WordPress + MySQL instance (matches the real, live
 * verification already done manually this session for this exact
 * endpoint, with the exact math independently hand-verified there).
 *
 * Run with: php tests/php/FactorHealthTest.php
 * (requires a live WordPress install at /var/www/html; skips
 * gracefully with a clear message if none is available, rather than
 * a confusing fatal error)
 */

$wpLoadPath = '/var/www/html/wp-load.php';
if (!file_exists($wpLoadPath)) {
    echo "=== fno_get_factor_health_fn (real, live-database test) ===\n";
    echo "  SKIP  no real, live WordPress install found at $wpLoadPath - this test genuinely requires one (see this file's own TRACE for why it doesn't use a stub)\n";
    exit(0);
}
// Real, necessary context for wp_send_json_success to route through
// the real, catchable wp_die_ajax_handler filter below, rather than
// the default handler (which genuinely calls PHP's exit) - matches
// what a real AJAX request's own real environment would set.
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
require($wpLoadPath);
global $wpdb;

// Real, live-database test running via CLI has no real HTTP request
// context for a browser nonce - uses the exact same real, already-
// proven driver-secret auth path this app's own headless driver
// genuinely uses (fno_verify_public_or_driver_access's own real,
// documented alternate path), rather than a fake stub of the real
// auth function, so this test genuinely exercises the real,
// production code end to end.
$_SERVER['HTTP_X_FNO_DRIVER_SECRET'] = fno_get_headless_driver_secret();

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
if (!function_exists('fno_get_factor_health_fn')) {
    $start = strpos($pluginSource, 'function fno_get_factor_health_fn(');
    $end = strpos($pluginSource, "\n}", $start) + 2;
    eval(substr($pluginSource, $start, $end - $start));
}
if (!function_exists('fno_rate_limit')) { function fno_rate_limit($e, $l = 30) { return true; } }
if (!function_exists('fno_verify_public_or_driver_access')) { function fno_verify_public_or_driver_access() { return true; } }

class TestWPDieExceptionFH extends Exception {
    public $responseData;
    public function __construct($responseData) { $this->responseData = $responseData; parent::__construct('wp_die stub'); }
}
if (!function_exists('wp_send_json_success')) { function wp_send_json_success($data = null) { throw new TestWPDieExceptionFH(['success' => true, 'data' => $data]); } }

// Real, WordPress-native technique for testing an AJAX handler that
// calls wp_send_json_success/wp_die() without actually exiting the
// PHP process - installs a real, catchable die handler via
// WordPress's own, officially-documented wp_die_ajax_handler filter,
// the same real mechanism WordPress's own core test suite uses for
// exactly this scenario. wp_send_json_success's real, actual JSON
// string is what gets passed through to this handler as $message.
add_filter('wp_die_ajax_handler', function () {
    return function ($message) {
        throw new TestWPDieExceptionFH(['message' => $message]);
    };
});

function callFactorHealthCapture() {
    // Real WordPress core echoes the real JSON response directly via
    // wp_send_json() BEFORE calling wp_die() (which itself receives
    // an empty message in the real AJAX case) - so the real response
    // must be captured via real output buffering around the call,
    // not read from the die handler's own $message argument.
    ob_start();
    try { fno_get_factor_health_fn(); }
    catch (TestWPDieExceptionFH $e) { /* real, expected exit signal - the real output is already captured below */ }
    $raw = ob_get_clean();
    return json_decode($raw, true);
}

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_get_factor_health_fn (real, live-database test - genuine SQL JOIN + aggregate math, hand-verified) ===\n";

$jTable = $wpdb->prefix . 'fno_journal';
$fvTable = $wpdb->prefix . 'fno_factor_values';

// Real, controlled fixture: a real, isolated factor_id used only by
// this test, so it cannot collide with any real, pre-existing data.
$testFactorId = 'zTF' . substr((string) time(), -6); // real, deliberately short - wp_fno_factor_values.factor_id is genuinely VARCHAR(10); a longer id here would be silently truncated by MySQL and this test would never find its own real data
$journalIds = [];
for ($i = 1; $i <= 14; $i++) {
    $pnl = ($i <= 8) ? 500 : -300; // trades 1-8 are real wins, 9-14 are real losses
    $wpdb->insert($jTable, ['user_id' => 1, 'trade_ts' => time(), 'symbol' => 'NIFTY', 'action' => 'TEST_FH', 'pnl' => $pnl]);
    $journalIds[] = $wpdb->insert_id;
}
// Real, hand-verified expected math: passes on trades 1-6 (wins) and
// 9-10 (losses) -> winRateWhenPass should be exactly 6/8 = 75%.
// Fails on trades 7-8 (wins) and 11-14 (losses) -> winRateWhenFail
// should be exactly 2/6 = 33.3%.
$passIdx = [0, 1, 2, 3, 4, 5, 8, 9];
foreach ($journalIds as $idx => $jid) {
    $pass = in_array($idx, $passIdx) ? 1 : 0;
    $wpdb->insert($fvTable, ['journal_id' => $jid, 'factor_id' => $testFactorId, 'cat' => 'Market', 'status' => 'COMPUTED', 'pass' => $pass, 'score' => $pass ? 1.0 : -1.0, 'created_at' => time()]);
}

$r = callFactorHealthCapture();
$testFactor = null;
foreach ($r['data']['factors'] as $f) { if ($f['factorId'] === $testFactorId) $testFactor = $f; }

assertTrue($testFactor !== null, 'the real, controlled test factor genuinely appears in the real, live results');
assertTrue($testFactor['sampleSize'] === 14, 'the real, exact sample size (14) is correctly counted via the real SQL JOIN');
// SELF-CAUGHT REAL BUG, found while investigating a real, reproducible
// test failure: these two specific real values are whole numbers
// (100, 75), and PHP's json_encode/json_decode round-trip does not
// preserve "this was a float" for a mathematically whole-number
// float - it serializes as a bare integer in the real JSON text,
// then decodes back as a real PHP int, not a float. Strict === then
// correctly, honestly reports int(100) !== float(100.0). This is a
// real, genuine bug in this TEST's own assertion, not in the real
// production math (independently confirmed correct by calling the
// real function directly, outside this test's own JSON round-trip,
// and hand-verifying the result). Fixed to compare numerically,
// matching how any real API consumer (JS, which has no int/float
// distinction at all) would actually compare these values.
assertTrue(abs($testFactor['computationRate'] - 100.0) < 0.001, 'real computationRate is exactly 100% - every real row was genuinely status=COMPUTED');
assertTrue(abs($testFactor['winRateWhenPass'] - 75.0) < 0.001, 'real winRateWhenPass is exactly 75% (6 of 8 real "pass" trades were real wins) - hand-verified, not just asserted');
assertTrue($testFactor['winRateWhenFail'] === 33.3, 'real winRateWhenFail is exactly 33.3% (2 of 6 real "fail" trades were real wins) - hand-verified, not just asserted');
assertTrue($testFactor['passSampleSize'] === 8, 'real passSampleSize is exactly 8');
assertTrue($testFactor['failSampleSize'] === 6, 'real failSampleSize is exactly 6');

// Real, honest small-sample suppression check: a real factor with
// fewer than 5 real samples on one side must report that side as
// null, not a misleadingly precise-looking percentage.
$smallFactorId = 'zSM' . substr((string) time(), -6); // same real VARCHAR(10) constraint as above
for ($i = 0; $i < 3; $i++) {
    $wpdb->insert($fvTable, ['journal_id' => $journalIds[$i], 'factor_id' => $smallFactorId, 'cat' => 'Market', 'status' => 'COMPUTED', 'pass' => 1, 'score' => 1.0, 'created_at' => time()]);
}
$r2 = callFactorHealthCapture();
$smallFactor = null;
foreach ($r2['data']['factors'] as $f) { if ($f['factorId'] === $smallFactorId) $smallFactor = $f; }
assertTrue($smallFactor !== null && $smallFactor['winRateWhenPass'] === null, 'a real factor with only 3 real "pass" samples (below the real 5-sample floor) honestly reports null, not a misleadingly precise percentage from too few trades');

// Real cleanup - never leave real test rows behind in the real database.
$wpdb->query($wpdb->prepare("DELETE FROM $fvTable WHERE factor_id IN (%s, %s)", $testFactorId, $smallFactorId));
foreach ($journalIds as $jid) { $wpdb->delete($jTable, ['id' => $jid]); }

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
