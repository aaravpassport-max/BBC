<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test - session-wide audit of
 * every wp_ajax_nopriv_-registered handler in fno-lab.php verifying that
 * its auth check (fno_verify_public_or_driver_access() / fno_verify_app_nonce()
 * / fno_verify_app_access()) runs BEFORE any real expensive/paid operation
 * in the function body - a real, external HTTP call (wp_remote_get/
 * wp_remote_post/fno_nse_get), a paid-provider resolution
 * (fno_resolve_capability, which can call a real, user-configured premium
 * data vendor - see fno_fetch_generic_premium), or a real DB write
 * ($wpdb->insert/replace/query).
 *
 * WHY THIS MATTERS: making a handler nopriv-reachable (required so the
 * headless Autonomous Driver, which by definition has no WordPress login
 * session, can call it) also makes it reachable by ANY anonymous caller who
 * knows the action name - not just the driver holding the real secret. If
 * the auth check ran AFTER an expensive/paid operation instead of before
 * it, an attacker with no valid secret at all could still repeatedly
 * trigger that expensive work (a resource-exhaustion / real-money-cost
 * amplification risk), even though they could never read/write the
 * resulting data (which the existing dual-auth checks already gate
 * correctly - this test targets a different, ordering-specific risk).
 *
 * This session's audit (see docs/PENDING_REQUIREMENTS.md) manually verified
 * every one of the 34 real wp_ajax_nopriv_-registered handlers and found
 * the auth check IS always the first real statement before any expensive
 * work in every one of them - this test encodes that finding as a
 * permanent regression guard, source-parsed directly against the real
 * fno-lab.php (no WordPress needed), so a future session that adds a new
 * nopriv handler or reorders an existing one can't silently reintroduce
 * this exact ordering bug.
 *
 * Also separately guards the highest-priority real-cost case flagged by
 * this session's directive: fno_generate_ai_narrative_fn (the one handler
 * that calls the real, paid OpenAI API directly) must NEVER be
 * nopriv-registered at all - it is deliberately logged-in-users-only,
 * exactly as docs/PENDING_REQUIREMENTS.md already documented.
 *
 * Run with: php tests/php/AuthOrderCostGuardTest.php
 */

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');

// Step 1: map nopriv-registered action -> handler function name.
preg_match_all(
    "/add_action\\(\\s*'wp_ajax_nopriv_([a-zA-Z0-9_]+)'\\s*,\\s*'([a-zA-Z0-9_]+)'\\s*\\)/",
    $pluginSource,
    $regMatches,
    PREG_SET_ORDER
);
$noprivHandlers = []; // fnName => actionName
foreach ($regMatches as $m) {
    $noprivHandlers[$m[2]] = $m[1];
}

// Step 2: locate every `function fno_..._fn(` definition and its body
// (up to the next top-level "\n}", the same approximation the sibling
// NoprivRegistrationAuditTest.php in this same suite already uses).
preg_match_all('/^function (fno_[a-zA-Z0-9_]+_fn)\s*\(/m', $pluginSource, $fnMatches, PREG_OFFSET_CAPTURE);
$fnBodies = [];
foreach ($fnMatches[1] as $fm) {
    $fnName = $fm[0];
    $start = $fm[1];
    $bodyEnd = strpos($pluginSource, "\n}", $start);
    if ($bodyEnd === false) { continue; }
    $fnBodies[$fnName] = substr($pluginSource, $start, $bodyEnd - $start);
}

$authCheckNames = ['fno_verify_public_or_driver_access', 'fno_verify_app_nonce', 'fno_verify_app_access', 'fno_get_daemon_secret'];
// fno_get_real_money_status_fn is a real, deliberate exception: it has NO
// auth check by design (its whole point is to be a public, unauthenticated
// real-money-kill-switch status read - see its own inline comment) and its
// only DB work is a single cheap, indexed COUNT(*) query gated behind
// is_user_logged_in(), never an expensive/paid operation - so it is
// excluded from the "must have an auth check" assertion below rather than
// producing a permanent false-positive failure.
$noAuthByDesign = ['fno_get_real_money_status_fn'];
// Real, expensive/paid/write operations that must never run before the
// auth check in a nopriv-reachable handler.
$expensiveOpPatterns = [
    'wp_remote_get(' => 'real external HTTP GET',
    'wp_remote_post(' => 'real external HTTP POST',
    'fno_nse_get(' => 'real NSE scrape round-trip',
    'fno_resolve_capability(' => 'real paid-premium-provider resolution path',
    '->insert(' => 'real DB write',
    '->replace(' => 'real DB write',
    '->query(' => 'real raw DB query',
];

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== Auth-check-before-expensive-work ordering audit (nopriv handlers, real fno-lab.php source scan) ===\n";
assertTrue(count($noprivHandlers) >= 30, 'found at least the 30+ real, known nopriv handlers (found ' . count($noprivHandlers) . ')');

foreach ($noprivHandlers as $fnName => $actionName) {
    if (!isset($fnBodies[$fnName])) {
        assertTrue(false, "$fnName: (action=$actionName) function body located for ordering scan");
        continue;
    }
    $body = $fnBodies[$fnName];

    if (in_array($fnName, $noAuthByDesign, true)) {
        echo "  SKIP  $fnName: (action=$actionName) intentionally has no auth check (see \$noAuthByDesign comment) - not scanned for ordering\n";
        continue;
    }

    // Find earliest auth-check call offset in this body.
    $authOffset = null;
    foreach ($authCheckNames as $authFn) {
        $pos = strpos($body, $authFn . '(');
        if ($pos !== false && ($authOffset === null || $pos < $authOffset)) {
            $authOffset = $pos;
        }
    }
    assertTrue($authOffset !== null, "$fnName: (action=$actionName) has a real auth check call somewhere in its body");
    if ($authOffset === null) { continue; }

    // Every expensive-op occurrence in the body must be AFTER the auth check.
    foreach ($expensiveOpPatterns as $needle => $label) {
        $searchFrom = 0;
        while (($pos = strpos($body, $needle, $searchFrom)) !== false) {
            assertTrue(
                $pos > $authOffset,
                "$fnName: (action=$actionName) $label at body offset $pos runs AFTER the auth check at offset $authOffset"
            );
            $searchFrom = $pos + 1;
        }
    }
}

// Highest-priority real-cost guard: the one handler that calls the real,
// paid OpenAI API directly must never be nopriv-reachable at all.
assertTrue(
    !isset($noprivHandlers['fno_generate_ai_narrative_fn']),
    'fno_generate_ai_narrative_fn (real, paid OpenAI call) is NOT nopriv-registered - stays logged-in-users-only'
);
assertTrue(
    strpos($pluginSource, "add_action('wp_ajax_fno_generate_ai_narrative'") !== false,
    'fno_generate_ai_narrative_fn still has its real, intentional logged-in-only wp_ajax_ registration'
);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
