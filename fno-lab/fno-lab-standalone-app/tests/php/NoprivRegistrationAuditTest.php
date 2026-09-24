<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test - session-wide security
 * re-audit of every AJAX handler that uses either dual-auth pattern
 * (fno_verify_public_or_driver_access() or fno_verify_app_access()).
 *
 * REAL BUG FOUND AND FIXED by this audit: fno_open_position_fn,
 * fno_list_open_positions_fn, fno_update_open_position_fn,
 * fno_close_position_fn, fno_journal_add_fn, fno_log_rejection_fn,
 * fno_log_hypothesis_fn and fno_evaluate_hypotheses_fn were all switched
 * to fno_verify_app_access() (which accepts a headless driver secret with
 * NO WordPress session) this session, but only their
 * `add_action('wp_ajax_<action>', ...)` registration was present - the
 * matching `add_action('wp_ajax_nopriv_<action>', ...)` was missing.
 * WordPress core's admin-ajax.php routes a request with no valid login
 * session to the `wp_ajax_nopriv_<action>` hook ONLY - if that hook does
 * not exist, the request never reaches the handler at all (WP responds
 * with the default "no such action" failure), regardless of how good the
 * driver-secret check inside the function is. A genuinely headless
 * Autonomous Driver process - which by definition has no browser/login
 * session - would therefore have silently failed on every single one of
 * these calls (every real trade open, every real trade close, every real
 * journal write, every real rejection/hypothesis log) despite the PHP-
 * level auth logic itself being correct. Confirmed directly against
 * autonomous-driver/autonomous-driver.js, which does call all eight of
 * these actions via postAuthenticated()/fetchJson() against
 * wp-admin/admin-ajax.php exactly like a real logged-out request.
 *
 * This test parses the real fno-lab.php source directly (no WordPress
 * needed) and asserts, for every function body that calls
 * fno_verify_app_access() or fno_verify_public_or_driver_access(), that
 * BOTH a wp_ajax_<action> AND a wp_ajax_nopriv_<action> registration
 * exist somewhere in the file pointing at that same function - so a
 * future session that swaps a handler to dual-auth but forgets the
 * nopriv hook (exactly this bug) fails this test immediately.
 *
 * Run with: php tests/php/NoprivRegistrationAuditTest.php
 */

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');

// Step 1: find every `add_action('wp_ajax_<action>', '<fn>');` (and the
// nopriv variant) registration in the real source, mapping action name
// <-> function name in both directions.
preg_match_all(
    "/add_action\\(\\s*'wp_ajax_(nopriv_)?([a-zA-Z0-9_]+)'\\s*,\\s*'([a-zA-Z0-9_]+)'\\s*\\)/",
    $pluginSource,
    $regMatches,
    PREG_SET_ORDER
);
$privRegistered = [];   // fn name => true (has wp_ajax_ registration)
$noprivRegistered = []; // fn name => true (has wp_ajax_nopriv_ registration)
foreach ($regMatches as $m) {
    $isNopriv = $m[1] === 'nopriv_';
    $fnName = $m[3];
    if ($isNopriv) { $noprivRegistered[$fnName] = true; }
    else { $privRegistered[$fnName] = true; }
}

// Step 2: find every function definition, then check whether its body
// (up to the matching top-level closing brace, approximated the same
// way the other real tests in this suite do it - up to the next "\n}")
// calls either dual-auth entry point.
preg_match_all('/^function (fno_[a-zA-Z0-9_]+_fn)\s*\(/m', $pluginSource, $fnMatches, PREG_OFFSET_CAPTURE);

$dualAuthFns = [];
foreach ($fnMatches[1] as $fm) {
    $fnName = $fm[0];
    $start = $fm[1];
    $bodyEnd = strpos($pluginSource, "\n}", $start);
    if ($bodyEnd === false) { continue; }
    $body = substr($pluginSource, $start, $bodyEnd - $start);
    if (strpos($body, 'fno_verify_app_access()') !== false || strpos($body, 'fno_verify_public_or_driver_access()') !== false) {
        $dualAuthFns[] = $fnName;
    }
}

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== Dual-auth endpoint nopriv-registration audit (real fno-lab.php source scan) ===\n";
assertTrue(count($dualAuthFns) >= 24, 'found at least the 24 real, known dual-auth functions this session touched (found ' . count($dualAuthFns) . ')');

foreach ($dualAuthFns as $fnName) {
    assertTrue(isset($privRegistered[$fnName]), "$fnName: has a real wp_ajax_ registration");
    assertTrue(isset($noprivRegistered[$fnName]), "$fnName: has a real wp_ajax_nopriv_ registration (the exact bug class this test guards against - a session-less driver request is routed here ONLY via the nopriv hook)");
}

// Explicit regression guard for the exact 8 functions this audit found
// broken, so a future refactor that accidentally removes just one of
// these nopriv lines fails loudly and specifically.
$knownFixed = [
    'fno_journal_add_fn', 'fno_open_position_fn', 'fno_list_open_positions_fn',
    'fno_update_open_position_fn', 'fno_close_position_fn', 'fno_log_rejection_fn',
    'fno_log_hypothesis_fn', 'fno_evaluate_hypotheses_fn',
];
foreach ($knownFixed as $fnName) {
    assertTrue(isset($noprivRegistered[$fnName]), "$fnName: (regression guard) real nopriv registration present - this exact function was found MISSING it during this session's security re-audit");
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
