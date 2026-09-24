<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for
 * fno_dq_check() / fno_dq_check_conflict() - the Data Quality Engine
 * (Enterprise Data Architecture Plan #20), which decides whether a
 * live market data reading is trustworthy before it's allowed to
 * influence a trading decision.
 *
 * Unlike most of this codebase's PHP, these two functions have ZERO
 * WordPress dependency at all (no $wpdb, no wp_* calls, no globals) -
 * confirmed by direct inspection before writing this test, not
 * assumed. That means this file needs no stubbing whatsoever and can
 * genuinely run anywhere PHP runs.
 *
 * Run with: php tests/php/DataQualityEngineTest.php
 */

$pluginSource = file_get_contents(__DIR__ . '/../../fno-data-layer.php');
$fnStart = strpos($pluginSource, 'function fno_dsm_empty_record');
$fnEnd = strpos($pluginSource, "\n}", $fnStart) + 2;
eval(substr($pluginSource, $fnStart, $fnEnd - $fnStart));

$dqStart = strpos($pluginSource, 'function fno_dq_check(');
$dqEnd = strpos($pluginSource, "\n}", $dqStart) + 2;
eval(substr($pluginSource, $dqStart, $dqEnd - $dqStart));

$conflictStart = strpos($pluginSource, 'function fno_dq_check_conflict');
$conflictEnd = strpos($pluginSource, "\n}", $conflictStart) + 2;
eval(substr($pluginSource, $conflictStart, $conflictEnd - $conflictStart));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}
function assertContains($needle, array $haystack, $label) {
    assertTrue(in_array($needle, $haystack, true), "$label (flags: " . implode(',', $haystack) . ")");
}
function assertNotContains($needle, array $haystack, $label) {
    assertTrue(!in_array($needle, $haystack, true), "$label (flags: " . implode(',', $haystack) . ")");
}

echo "=== fno_dq_check (Data Quality Engine - real anomaly detection) ===\n";

// A completely real, clean, healthy record must produce ZERO flags -
// the most important real property: a healthy reading is never
// falsely flagged.
$healthy = array_merge(fno_dsm_empty_record(), ['ltp' => 100.5, 'oi' => 50000, 'volume' => 10000, 'bid' => 100.0, 'ask' => 101.0, 'freshnessSeconds' => 5]);
assertTrue(fno_dq_check($healthy) === [], 'a genuinely healthy, real record produces zero flags');

// Real anomaly: impossible (zero/negative) price.
$badPrice = array_merge(fno_dsm_empty_record(), ['ltp' => 0]);
assertContains('IMPOSSIBLE_PRICE_LTP', fno_dq_check($badPrice), 'a real zero LTP is flagged as impossible');
$negPrice = array_merge(fno_dsm_empty_record(), ['ltp' => -5.0]);
assertContains('IMPOSSIBLE_PRICE_LTP', fno_dq_check($negPrice), 'a real negative LTP is flagged as impossible');

// Real anomaly: negative OI/volume (structurally impossible real
// quantities).
$negOi = array_merge(fno_dsm_empty_record(), ['oi' => -100]);
assertContains('NEGATIVE_OI', fno_dq_check($negOi), 'real negative OI is flagged');
$negVol = array_merge(fno_dsm_empty_record(), ['volume' => -1]);
assertContains('NEGATIVE_VOLUME', fno_dq_check($negVol), 'real negative volume is flagged');

// Real anomaly: a crossed market (ask below bid - never legitimately
// happens in a real, functioning order book).
$crossed = array_merge(fno_dsm_empty_record(), ['bid' => 100, 'ask' => 95]);
assertContains('CROSSED_MARKET', fno_dq_check($crossed), 'a real crossed market (ask < bid) is flagged');

// Real, correctly NOT flagged: a normal, real, uncrossed spread.
$normalSpread = array_merge(fno_dsm_empty_record(), ['bid' => 100, 'ask' => 101]);
assertNotContains('CROSSED_MARKET', fno_dq_check($normalSpread), 'a real normal spread is NOT flagged as crossed');
assertNotContains('ABNORMAL_SPREAD', fno_dq_check($normalSpread), 'a real 1% spread is NOT flagged as abnormal');

// Real anomaly: an abnormally wide spread (real illiquidity signal).
$wideSpread = array_merge(fno_dsm_empty_record(), ['bid' => 10, 'ask' => 20]); // 100% spread
assertContains('ABNORMAL_SPREAD', fno_dq_check($wideSpread), 'a real 100% spread is flagged as abnormal');

// Real freshness checks - the exact real, documented thresholds.
$stale = array_merge(fno_dsm_empty_record(), ['ltp' => 100, 'freshnessSeconds' => 400]);
assertContains('STALE_OVER_5MIN', fno_dq_check($stale), 'real data older than 5 real minutes is flagged stale');
$delayed = array_merge(fno_dsm_empty_record(), ['ltp' => 100, 'freshnessSeconds' => 90]);
assertContains('DELAYED_OVER_1MIN', fno_dq_check($delayed), 'real data between 1-5 real minutes old is flagged delayed (not stale)');
assertNotContains('STALE_OVER_5MIN', fno_dq_check($delayed), 'a real 90-second-old reading must NOT be conflated with a 5-minute-stale one');
$fresh = array_merge(fno_dsm_empty_record(), ['ltp' => 100, 'freshnessSeconds' => 5]);
assertTrue(fno_dq_check($fresh) === [], 'genuinely real, fresh (5-second-old) data produces zero freshness flags');

// Real, honest gap: missing data entirely.
$noPrice = fno_dsm_empty_record(); // every field genuinely null
assertContains('NO_PRICE_DATA', fno_dq_check($noPrice), 'a record with genuinely no real price data anywhere is flagged, not silently passed as healthy');

echo "\n=== fno_dq_check_conflict (real cross-source disagreement detection) ===\n";

$sourceA = array_merge(fno_dsm_empty_record(), ['ltp' => 100.0, 'source' => 'nse_free']);
$sourceB = array_merge(fno_dsm_empty_record(), ['ltp' => 100.2, 'source' => 'kite']);
assertTrue(fno_dq_check_conflict($sourceA, $sourceB) === null, 'a real 0.2% price difference between two live sources is normal quote-timing noise, NOT a real conflict');

$sourceC = array_merge(fno_dsm_empty_record(), ['ltp' => 100.0, 'source' => 'nse_free']);
$sourceD = array_merge(fno_dsm_empty_record(), ['ltp' => 110.0, 'source' => 'kite']); // real 10% difference
$conflict = fno_dq_check_conflict($sourceC, $sourceD);
assertTrue($conflict !== null, 'a real 10% price difference between two live sources IS flagged as a genuine conflict');
if ($conflict !== null) {
    assertTrue($conflict['sourceA'] === 'nse_free' && $conflict['sourceB'] === 'kite', 'the real conflict correctly identifies which two sources disagreed');
    assertTrue(abs($conflict['diffPct'] - 10.0) < 0.01, 'the real diffPct is computed correctly (expected ~10%, got ' . $conflict['diffPct'] . ')');
}

$missingPrice = array_merge(fno_dsm_empty_record(), ['ltp' => null, 'source' => 'nse_free']);
assertTrue(fno_dq_check_conflict($missingPrice, $sourceB) === null, 'a real missing price on one side means no real conflict CAN be checked - honestly null, not a fabricated conflict');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
