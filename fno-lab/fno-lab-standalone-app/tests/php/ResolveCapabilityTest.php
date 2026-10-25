<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_resolve_capability() - the single, real, load-bearing resolver
 * every premium capability in this app (VIX, FII/DII, News Sentiment,
 * Market Depth, Event Calendar, Results Calendar) goes through. A bug
 * here is genuinely consequential in two different real directions:
 * it could silently skip a premium provider the user is actually
 * paying for (wasted subscription, degraded data quality with no
 * visible reason), or it could call a premium API more often than
 * intended (real, unwanted cost). It's also the function this project
 * itself found and fixed a real bug in earlier this session (Phase 25
 * - the real cost-logging call had been wired to an orphaned sibling
 * function and never fired in practice) - exactly the kind of
 * function that benefits most from real, executed tests rather than
 * only code review.
 *
 * fno_resolve_capability() calls three other real functions BY NAME
 * (fno_get_premium_config, fno_fetch_generic_premium,
 * fno_dsm_log_paid_api_usage) rather than receiving them as
 * dependencies - this test takes advantage of that by defining real,
 * controllable STUB versions of those three specific functions first,
 * then loading the REAL fno_resolve_capability body. PHP resolves
 * function calls by name at call time, so fno_resolve_capability's
 * real code genuinely calls these stubs - this tests the REAL
 * branching logic (premium succeeds / premium configured but fails /
 * premium not configured / free also fails) without ever touching a
 * real network or a real database.
 *
 * Run with: php tests/php/ResolveCapabilityTest.php
 */

// --- Real, controllable stubs - each call is recorded so the test can
// verify not just the RETURN VALUE but WHICH real code path actually
// ran (e.g. was the free fallback genuinely skipped when premium
// succeeded, or genuinely tried when premium failed).
$GLOBALS['__fno_test_premium_config'] = null; // null = "not configured", or a real fake config array
$GLOBALS['__fno_test_premium_fetch_result'] = null; // what fno_fetch_generic_premium should return
$GLOBALS['__fno_test_paid_usage_log_calls'] = [];

function fno_get_premium_config($capability) {
    return $GLOBALS['__fno_test_premium_config'];
}
function fno_fetch_generic_premium($cfg, $replacements = []) {
    return $GLOBALS['__fno_test_premium_fetch_result'];
}
function fno_dsm_log_paid_api_usage($fieldName, $reason) {
    $GLOBALS['__fno_test_paid_usage_log_calls'][] = ['field' => $fieldName, 'reason' => $reason];
}

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$start = strpos($pluginSource, 'function fno_resolve_capability(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function resetStubs() {
    $GLOBALS['__fno_test_premium_config'] = null;
    $GLOBALS['__fno_test_premium_fetch_result'] = null;
    $GLOBALS['__fno_test_paid_usage_log_calls'] = [];
}

echo "=== fno_resolve_capability (real premium/free resolver + real cost-logging) ===\n";

// Case 1: premium genuinely NOT configured -> must go straight to the
// real free fallback, never even attempt a premium call.
resetStubs();
$freeCalled = false;
$result = fno_resolve_capability('vix', function() use (&$freeCalled) { $freeCalled = true; return 18.5; });
assertTrue($result['tier'] === 'free', 'premium not configured -> real tier is "free"');
assertTrue($result['value'] === 18.5, 'the real free fallback\'s value is returned correctly');
assertTrue($freeCalled === true, 'the real free fallback function was genuinely called');
assertTrue(count($GLOBALS['__fno_test_paid_usage_log_calls']) === 0, 'NO cost-usage log entry when premium was never configured/attempted - nothing paid happened');

// Case 2: premium configured AND succeeds -> real premium tier, free
// fallback must NOT be called at all, and real cost-logging MUST fire.
resetStubs();
$GLOBALS['__fno_test_premium_config'] = ['label' => 'Test Premium Provider', 'api_key' => 'fake'];
$GLOBALS['__fno_test_premium_fetch_result'] = 22.1;
$freeCalled = false;
$result = fno_resolve_capability('vix', function() use (&$freeCalled) { $freeCalled = true; return 18.5; });
assertTrue($result['tier'] === 'premium', 'premium configured and succeeds -> real tier is "premium"');
assertTrue($result['value'] === 22.1, 'the real premium value is returned, not the free fallback\'s');
assertTrue($result['source'] === 'Test Premium Provider', 'the real configured provider label is returned as the source');
assertTrue($freeCalled === false, 'the real free fallback is genuinely SKIPPED when premium succeeds - no wasted extra call');
assertTrue(count($GLOBALS['__fno_test_paid_usage_log_calls']) === 1, 'exactly ONE real cost-usage log entry is written when a paid call genuinely succeeds');
if (count($GLOBALS['__fno_test_paid_usage_log_calls']) === 1) {
    assertTrue($GLOBALS['__fno_test_paid_usage_log_calls'][0]['field'] === 'vix', 'the real cost-log entry correctly identifies which capability was paid for');
}

// Case 3: premium configured but genuinely FAILS this request -> must
// fall through to the real free fallback (not surface an error), and
// must NOT log a paid-usage entry (nothing was successfully paid for).
resetStubs();
$GLOBALS['__fno_test_premium_config'] = ['label' => 'Test Premium Provider', 'api_key' => 'fake'];
$GLOBALS['__fno_test_premium_fetch_result'] = null; // real premium failure
$freeCalled = false;
$result = fno_resolve_capability('vix', function() use (&$freeCalled) { $freeCalled = true; return 18.5; });
assertTrue($result['tier'] === 'free', 'premium configured but fails -> real, honest fallback to free tier');
assertTrue($result['value'] === 18.5, 'the real free fallback value is used when premium genuinely failed');
assertTrue($freeCalled === true, 'the real free fallback WAS genuinely called after premium failed');
assertTrue(count($GLOBALS['__fno_test_paid_usage_log_calls']) === 0, 'no paid-usage log entry when premium genuinely failed - nothing was successfully paid for');

// Case 4: premium not configured AND the real free fallback also
// fails -> real, honest "unavailable", never a fabricated value.
resetStubs();
$result = fno_resolve_capability('vix', function() { return null; });
assertTrue($result['tier'] === 'unavailable', 'both real tiers failing -> honest "unavailable", never fabricated');
assertTrue($result['value'] === null, 'value is genuinely null when both tiers failed');
assertTrue($result['source'] === null, 'source is genuinely null when both tiers failed');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
