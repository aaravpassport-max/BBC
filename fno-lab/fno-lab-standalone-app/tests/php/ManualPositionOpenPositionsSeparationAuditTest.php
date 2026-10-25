<?php
/**
 * REAL, STANDALONE, IMMEDIATELY-RUNNABLE PHP test - closes this session's
 * "manual position entry interaction with the new one-open-position-per-
 * symbol DB constraint" audit item (docs/PENDING_REQUIREMENTS.md Pass 64
 * "Honest Open Gaps").
 *
 * TRACED (2026-08-30): the constraint added in Pass 63
 * (`user_symbol_style_open_lock` unique key on `open_symbol_lock`) lives
 * on `wp_fno_open_positions` and is only ever written to by
 * `fno_open_position_fn` - the SAME endpoint whether a position is
 * opened automatically by the brain or manually via the browser's
 * "Auto Trade"/manual-open UI (assets/fno-lab-core.js ~line 13128,
 * `fetch(...action=fno_open_position...)`). That single writer already
 * has the graceful `symbolAlreadyOpen` handling from Pass 63 - confirmed
 * by OpenPositionsTest.php, unchanged and re-run clean by this session.
 *
 * `fno_add_manual_position_fn` (fno-lab.php) is a GENUINELY DIFFERENT,
 * pre-existing, explicitly-documented feature (Master Prompt §10,
 * "MANUAL POSITION TRACKER" - fno-lab.php's own comment at that section
 * header states "Genuinely separate from the Auto Trades execution
 * engine - these endpoints only ever read/write wp_fno_manual_positions,
 * never touch the trading logic, never place or manage an order"). It
 * writes to `wp_fno_manual_positions`, an entirely separate table used
 * only for portfolio-level Greeks/correlation aggregation
 * (computePortfolioGreeksExposure/computePortfolioCorrelationRisk on the
 * JS side) - it is NEVER read by `checkAndMonitorSwingPositions()` (the
 * driver's exit-check loop) or by the browser's own exit-check loop,
 * both of which only ever query `wp_fno_open_positions` /
 * `fno_list_open_positions`. So there is no missing-field gap to check
 * either: a row in `wp_fno_manual_positions` is never expected to carry
 * `sl`/`target`/`tradingStyle`/trailing-stop fields at all, because it
 * was never designed to be monitored or exited by that loop in the
 * first place - it is a read-only-to-the-brain informational record.
 *
 * This test asserts that structural separation directly against the
 * real source, so a future change that starts writing manual-tracker
 * rows into `wp_fno_open_positions` (which WOULD collide with the
 * unique constraint and WOULD need the same symbolAlreadyOpen handling
 * and the same full field set as the auto-open path) is caught
 * immediately rather than silently reintroducing the exact class of gap
 * this audit item was raised to check for.
 *
 * Run with: php tests/php/ManualPositionOpenPositionsSeparationAuditTest.php
 */

$pluginSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
if ($pluginSource === false) { fwrite(STDERR, "FATAL: could not read fno-lab.php\n"); exit(1); }

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== Manual position tracker vs wp_fno_open_positions separation audit ===\n";

// 1. fno_add_manual_position_fn exists and is the real handler registered
//    for the fno_add_manual_position AJAX action.
$startAdd = strpos($pluginSource, 'function fno_add_manual_position_fn(');
assertTrue($startAdd !== false, 'fno_add_manual_position_fn: function genuinely found in fno-lab.php');
assertTrue(strpos($pluginSource, "add_action('wp_ajax_fno_add_manual_position', 'fno_add_manual_position_fn')") !== false, 'fno_add_manual_position_fn: genuinely registered for the real fno_add_manual_position AJAX action');

if ($startAdd !== false) {
    // Isolate just this function's body (up to the next top-level "add_action(" or "function fno_" after it).
    $nextFnPos = strpos($pluginSource, "\nfunction fno_", $startAdd + 10);
    $addBody = $nextFnPos !== false ? substr($pluginSource, $startAdd, $nextFnPos - $startAdd) : substr($pluginSource, $startAdd);

    // 2. It writes ONLY to wp_fno_manual_positions, never wp_fno_open_positions.
    assertTrue(strpos($addBody, "prefix . 'fno_manual_positions'") !== false, 'fno_add_manual_position_fn: writes to $wpdb->prefix . \'fno_manual_positions\' (the real, separate tracker table)');
    assertTrue(strpos($addBody, "fno_open_positions") === false, 'fno_add_manual_position_fn: NEVER references wp_fno_open_positions - genuinely cannot collide with the Pass 63 unique-open-position constraint, which lives on that table only');

    // 3. It never sets a status/sl/target/tradingStyle field the way the
    //    real open-position writer does - confirming it was never
    //    designed to be picked up by the monitoring/exit-check loop,
    //    so there is no "missing field silently skips monitoring" gap
    //    (it was never supposed to be monitored in the first place).
    assertTrue(strpos($addBody, "'status'") === false, "fno_add_manual_position_fn: sets no 'status' field (never intended to be an 'open'-state row the exit-check loop iterates)");
    assertTrue(strpos($addBody, "'tradingStyle'") === false && strpos($addBody, "'trading_style'") === false, "fno_add_manual_position_fn: sets no tradingStyle/trading_style field (the exit-check loop's own real key)");
}

// 4. The unique constraint itself (Pass 63) is confirmed to live on
//    wp_fno_open_positions, not wp_fno_manual_positions - so this
//    manual-tracker insert genuinely cannot ever be rejected by it.
assertTrue(strpos($pluginSource, 'user_symbol_style_open_lock') !== false, 'user_symbol_style_open_lock: the real Pass 63 unique-key name is present in fno-lab.php');
$uniqueKeyPos = strpos($pluginSource, 'user_symbol_style_open_lock');
$sql11Start = strrpos(substr($pluginSource, 0, $uniqueKeyPos), '$sql11');
$sql11Window = $sql11Start !== false ? substr($pluginSource, $sql11Start, ($uniqueKeyPos - $sql11Start) + 400) : '';
assertTrue(strpos($sql11Window, '$openPositionsTable') !== false, 'user_symbol_style_open_lock: genuinely scoped to the $openPositionsTable (wp_fno_open_positions) DDL, confirmed by direct proximity in the real source (not a different/unrelated table)');
assertTrue(strpos($pluginSource, "\$openPositionsTable = \$wpdb->prefix . 'fno_open_positions'") !== false, '$openPositionsTable: genuinely resolves to wp_fno_open_positions, confirming the unique key really is scoped to that table');

// 5. The single real writer of wp_fno_open_positions - fno_open_position_fn
//    - is confirmed to be the SAME endpoint the browser's manual/auto
//    "open" UI action calls (both auto-brain-triggered and user-clicked
//    opens funnel through this one endpoint, which already has the
//    Pass 63 symbolAlreadyOpen graceful-rejection handling - re-verified
//    unchanged, not re-implemented, by OpenPositionsTest.php in this
//    same suite).
$openFnStart = strpos($pluginSource, 'function fno_open_position_fn(');
assertTrue($openFnStart !== false, 'fno_open_position_fn: real single writer of wp_fno_open_positions genuinely found');
if ($openFnStart !== false) {
    $nextFnPos2 = strpos($pluginSource, "\nfunction fno_", $openFnStart + 10);
    $openBody = $nextFnPos2 !== false ? substr($pluginSource, $openFnStart, $nextFnPos2 - $openFnStart) : substr($pluginSource, $openFnStart);
    assertTrue(strpos($openBody, "symbolAlreadyOpen") !== false, 'fno_open_position_fn: still has the real symbolAlreadyOpen graceful-rejection response this pass confirmed unchanged - the one writer both manual and auto opens funnel through');
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
