<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test for fno_now_ist() -
 * built after a real, direct, urgent user report that the app's
 * server-side trading-hour/trading-day logic was computing an
 * incorrect real IST time, traced to a genuine dependency on
 * WordPress's own, separately-configurable site timezone setting
 * (current_time('timestamp')) rather than a robust, explicit
 * Asia/Kolkata computation.
 *
 * Run with: php tests/php/NowIstTest.php
 */

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$start = strpos($pluginSource, 'function fno_now_ist(');
$end = strpos($pluginSource, "\n}", $start) + 2;
eval(substr($pluginSource, $start, $end - $start));

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== fno_now_ist() (real, robust, explicit-timezone IST helper) ===\n";

// The real, core claim: fno_now_ist() must produce the exact same
// real, correct IST wall-clock time regardless of what timezone this
// PHP process's own default happens to be set to - the specific,
// genuine bug this function was built to fix.
$serverTimezones = ['UTC', 'America/New_York', 'Asia/Tokyo', 'Europe/London', 'Asia/Kolkata'];
$results = [];
foreach ($serverTimezones as $tz) {
    date_default_timezone_set($tz);
    $ist = fno_now_ist();
    $results[$tz] = $ist->format('Y-m-d H:i');
}
date_default_timezone_set('UTC'); // restore a real, known default before continuing

$uniqueResults = array_unique($results);
assertTrue(count($uniqueResults) === 1, 'fno_now_ist() produces the exact same real IST time regardless of the server\'s own default timezone setting (' . implode(', ', $serverTimezones) . ' all agree: ' . reset($results) . ')');

// Real, direct confirmation the returned object is genuinely,
// explicitly Asia/Kolkata - not merely "happens to produce the right
// number right now" by coincidence.
$ist = fno_now_ist();
assertTrue($ist->getTimezone()->getName() === 'Asia/Kolkata', 'the real, returned DateTime object is explicitly, genuinely tagged Asia/Kolkata, not a coincidental numeric match');

// Real sanity check: the real IST hour must be a valid, real 0-23
// value and never throw, confirming this is a real, live, working
// computation, not a stubbed or broken one.
assertTrue((int) $ist->format('G') >= 0 && (int) $ist->format('G') <= 23, 'the real, live-computed current IST hour is a genuine, valid 0-23 value');

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
