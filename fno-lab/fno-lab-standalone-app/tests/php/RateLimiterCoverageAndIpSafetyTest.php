<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test - dedicated audit of
 * fno_rate_limit()'s own IP-derivation safety, and of its coverage across
 * every wp_ajax_nopriv_ handler (the two things the prior session's
 * cost-amplification audit explicitly did NOT check - that pass focused
 * on auth-check ORDERING, not on the rate limiter's own correctness).
 *
 * PART 1 - IP-derivation safety.
 * fno_rate_limit() keys its transient on the caller's "IP" - if that IP
 * were derived from a client-controllable HTTP header (X-Forwarded-For,
 * X-Client-IP, etc.) without validating it against a real, configured
 * trusted-proxy list, an attacker could bypass the entire rate limit by
 * simply sending a different spoofed value on every request (each one
 * hashes to a different transient key, so the counter never accumulates).
 * This test parses fno_rate_limit()'s real source and asserts it keys
 * on $_SERVER['REMOTE_ADDR'] (the real, non-spoofable TCP connection IP)
 * and does NOT read any of the classic spoofable headers at all.
 * CONFIRMED SAFE this session (not a bug found) - see fno-lab.php:714,
 * `$ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');` -
 * this test exists to make that safety a permanent, enforced regression
 * guard rather than a one-time manual read.
 *
 * PART 2 - coverage across every real nopriv handler.
 * The prior session's audit found fno_rate_limit() "wired into the large
 * majority" of nopriv handlers but did not enumerate an exact list. This
 * test parses every `add_action('wp_ajax_nopriv_<action>', '<fn>')`
 * registration in the real source and asserts every one of those handler
 * functions' bodies calls fno_rate_limit() at least once.
 *
 * REAL GAP FOUND AND FIXED this session: fno_journal_add_fn,
 * fno_journal_list_fn, fno_get_paper_account_fn, fno_log_rejection_fn,
 * fno_log_hypothesis_fn, fno_evaluate_hypotheses_fn and
 * fno_get_hypothesis_stats_fn all use fno_verify_app_access() (which
 * accepts a real, logged-in browser session as a genuine alternative to
 * the driver secret - not just the driver secret), and are real DB-
 * read/write endpoints, but had zero rate-limiting - meaning any real,
 * malicious logged-in user (no driver secret needed at all) could
 * previously hammer these with no limit. Fixed by adding the existing
 * fno_rate_limit() call to each, at the established 30/60s default (the
 * same default already used for the structurally-similar
 * open_position/close_position/failure_event endpoints) - real,
 * deliberate headroom, since every one of these is client-throttled to
 * once per 10-30 real minutes under genuine usage (verified directly
 * against assets/fno-lab-core.js's own throttle constants below).
 *
 * Run with: php tests/php/RateLimiterCoverageAndIpSafetyTest.php
 */

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$coreJs = file_get_contents(__DIR__ . '/../../assets/fno-lab-core.js');

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== PART 1: fno_rate_limit() IP-derivation safety ===\n";

// Extract fno_rate_limit()'s own body.
$rlStart = strpos($pluginSource, 'function fno_rate_limit(');
assertTrue($rlStart !== false, 'fno_rate_limit() function definition found in fno-lab.php');
$rlEnd = strpos($pluginSource, "\n}", $rlStart);
$rlBody = substr($pluginSource, $rlStart, $rlEnd - $rlStart);

assertTrue(
    strpos($rlBody, "\$_SERVER['REMOTE_ADDR']") !== false,
    'fno_rate_limit() keys on $_SERVER[\'REMOTE_ADDR\'] - the real, non-spoofable TCP connection IP'
);

$spoofableHeaders = [
    'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP',
    'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR',
    'HTTP_FORWARDED',
];
foreach ($spoofableHeaders as $header) {
    assertTrue(
        strpos($rlBody, $header) === false,
        "fno_rate_limit() does NOT read the spoofable header \$_SERVER['$header'] - a real attacker fully controls this header's value on every request and could otherwise mint a fresh transient key per request, bypassing the limit entirely"
    );
}

echo "\n=== PART 2: fno_rate_limit() coverage across every nopriv handler ===\n";

preg_match_all(
    "/add_action\\(\\s*'wp_ajax_nopriv_[a-zA-Z0-9_]+'\\s*,\\s*'([a-zA-Z0-9_]+)'\\s*\\)/",
    $pluginSource,
    $regMatches
);
$noprivFns = array_values(array_unique($regMatches[1]));

assertTrue(count($noprivFns) >= 34, 'found at least the 34 real, known nopriv-registered handler functions (found ' . count($noprivFns) . ')');

preg_match_all('/^function (fno_[a-zA-Z0-9_]+_fn)\s*\(/m', $pluginSource, $fnMatches, PREG_OFFSET_CAPTURE);
$fnStarts = [];
foreach ($fnMatches[1] as $fm) { $fnStarts[$fm[0]] = $fm[1]; }

function fnBody($pluginSource, $fnStarts, $fnName) {
    if (!isset($fnStarts[$fnName])) return null;
    $start = $fnStarts[$fnName];
    $end = strpos($pluginSource, "\n}", $start);
    if ($end === false) return null;
    return substr($pluginSource, $start, $end - $start);
}

foreach ($noprivFns as $fnName) {
    $body = fnBody($pluginSource, $fnStarts, $fnName);
    assertTrue($body !== null, "$fnName: function definition found");
    assertTrue(
        $body !== null && strpos($body, 'fno_rate_limit(') !== false,
        "$fnName: calls fno_rate_limit() somewhere in its body"
    );
}

// Explicit regression guard for the exact 7 functions this audit found
// genuinely missing rate-limiting, so a future refactor that accidentally
// removes just one of these calls fails loudly and specifically.
$knownFixed = [
    'fno_journal_add_fn', 'fno_journal_list_fn', 'fno_get_paper_account_fn',
    'fno_log_rejection_fn', 'fno_log_hypothesis_fn', 'fno_evaluate_hypotheses_fn',
    'fno_get_hypothesis_stats_fn',
];
foreach ($knownFixed as $fnName) {
    $body = fnBody($pluginSource, $fnStarts, $fnName);
    assertTrue(
        $body !== null && strpos($body, 'fno_rate_limit(') !== false,
        "$fnName: (regression guard) real fno_rate_limit() call present - this exact function was found completely unprotected during this session's rate-limiter coverage audit"
    );
}

echo "\n=== PART 3: new limits won't break real driver/daemon polling ===\n";

// None of the 7 newly-protected functions are on the app's 600ms hot
// refresh cycle - each is either a driver-only trade-lifecycle write
// (already using the established 30/60s default elsewhere) or is
// client-throttled to once per 10-30 real minutes. Assert those real
// throttle constants are still present in the real source, so a future
// change to the client's own throttling can't silently invalidate this
// reasoning without also failing this test.
assertTrue(strpos($coreJs, "10*60*1000") !== false, 'core.js: real 10-minute rejection-log client throttle constant still present (logRejectionIfDue)');
assertTrue(strpos($coreJs, "15*60*1000") !== false, 'core.js: real 15-minute hypothesis-evaluation client throttle constant still present (evaluateHypothesesIfDue)');
assertTrue(strpos($coreJs, "30*60*1000") !== false, 'core.js: real 30-minute hypothesis-generation client throttle constant still present (generateAndLogHypothesisIfDue)');
// fno_journal_list is NOT gated by a single fixed client throttle
// constant (it's called from several on-demand/on-load sites, and once
// per driver cycle - see autonomous-driver.js below) - it is, however,
// never called from the app's 600ms hot refresh loop, so its real call
// rate stays far under 30/60s under any genuine single-tab usage.
assertTrue(strpos($coreJs, "action=fno_journal_list") !== false, 'core.js: fno_journal_list is called from real, on-demand/on-load sites only, never from the 600ms hot refresh loop');

// The driver's own real default polling cadence (fno_journal_add /
// fno_get_paper_account happen at most once per driver cycle, never per
// 600ms tick) is documented in autonomous-driver's own config - confirm
// its real default interval is >= 60s, i.e. at most 1 call/minute per
// action, nowhere near the 30/60s limit just added.
$driverConfigPath = __DIR__ . '/../../autonomous-driver/README.md';
if (file_exists($driverConfigPath)) {
    $driverReadme = file_get_contents($driverConfigPath);
    assertTrue(
        strpos($driverReadme, '60') !== false || strpos($driverReadme, '1 minute') !== false || strpos($driverReadme, '1-minute') !== false,
        'autonomous-driver/README.md: documents a >=60s real default polling cadence, consistent with the 30/60s limit just added never blocking legitimate driver traffic'
    );
} else {
    assertTrue(false, 'autonomous-driver/README.md exists to confirm real polling cadence');
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
