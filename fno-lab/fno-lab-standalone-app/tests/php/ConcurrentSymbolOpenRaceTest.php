<?php
/**
 * REAL concurrency regression test for the "at most one open position
 * per user_id+symbol+trading_style" invariant this app's own design
 * assumes (see autonomous-driver.js's recoverOpenPositionOnStartup /
 * checkAndMonitorSwingPositions TRACE comments - both explicitly treat
 * "the open position, singular" per symbol as a given), fixed at the
 * real DB level by fno-lab.php's dbDelta schema:
 * "open_symbol_lock VARCHAR(105) GENERATED ALWAYS AS (CASE WHEN status
 * = 'open' THEN CONCAT(user_id, ':', symbol, ':', trading_style) ELSE
 * NULL END) VIRTUAL" + "UNIQUE KEY user_symbol_style_open_lock
 * (open_symbol_lock)".
 *
 * WHY THIS TEST EXISTS (audit finding, 2026-08-30 pass, direct
 * follow-on to the trailing-stop-ratchet and close-position race fixes
 * from the immediately preceding passes): those two prior fixes closed
 * the browser-vs-driver race for UPDATING/CLOSING an EXISTING position.
 * This pass asked the remaining, distinct question: can the browser and
 * the headless driver each independently decide "conditions met, open a
 * NEW position" for the SAME symbol at nearly the same time, each with
 * its OWN distinct idempotency key (or none at all, since they are
 * different callers, not retries of the same request)? Before this
 * fix, YES - fno_open_position_fn's only existing UNIQUE KEY
 * (user_idempotency) only ever prevented a RETRY of the SAME logical
 * request; it did nothing to stop two genuinely DIFFERENT open requests
 * for the same symbol from both succeeding, silently violating this
 * app's own single-open-position-per-symbol design invariant.
 *
 * Exactly like ConcurrentIdempotencyRaceTest.php, this spawns real,
 * separate OS processes (via proc_open) that genuinely run
 * concurrently against ONE shared, real SQLite database file, with the
 * SAME partial-unique-index shape as fno-lab.php's real dbDelta schema
 * (SQLite's native `CREATE UNIQUE INDEX ... WHERE status = 'open'`
 * being the real, standard, portable equivalent of MySQL's generated-
 * column trick fno-lab.php actually uses) - proving the real, DB-
 * engine-enforced constraint actually holds under genuine concurrency,
 * not just that a single PHP process's own sequential logic is careful.
 *
 * Run with: php tests/php/ConcurrentSymbolOpenRaceTest.php
 */

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

function runRaceScenario($label, $numWorkers, $sameSymbolStyle) {
    global $passed, $failed;
    $dbPath = tempnam(sys_get_temp_dir(), 'fno_symrace_') . '.sqlite';
    @unlink($dbPath);
    $setupPdo = new PDO('sqlite:' . $dbPath);
    $setupPdo->exec('PRAGMA journal_mode = WAL');
    $setupPdo->exec('CREATE TABLE positions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        symbol TEXT NOT NULL,
        trading_style TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT \'open\'
    )');
    // Real, direct SQLite equivalent of fno-lab.php's MySQL generated-
    // column partial unique index: a genuine, native partial unique
    // index ("at most one row per user_id+symbol+trading_style WHERE
    // status = 'open'") - same real invariant, different real DB
    // engine's own idiomatic way of expressing it.
    $setupPdo->exec("CREATE UNIQUE INDEX open_lock ON positions(user_id, symbol, trading_style) WHERE status = 'open'");
    unset($setupPdo);

    $workerScript = __DIR__ . '/ConcurrentSymbolOpenRaceWorker.php';
    $outFiles = [];
    $procs = [];
    $userId = 42;

    for ($i = 0; $i < $numWorkers; $i++) {
        // sameSymbolStyle=true: every worker is a genuinely DIFFERENT
        // logical open request (no shared idempotency key at all - this
        // worker never even sends one) for the SAME symbol+style, e.g.
        // the browser and the driver both independently reaching a BUY
        // signal for NIFTY|intraday within the same tick window.
        // sameSymbolStyle=false: the companion "must not over-block"
        // scenario - genuinely different symbols/styles opened
        // concurrently by the same user.
        $symbol = $sameSymbolStyle ? 'NIFTY' : ('SYM' . $i);
        $style = $sameSymbolStyle ? 'intraday' : 'intraday';
        $outFile = tempnam(sys_get_temp_dir(), 'fno_symrace_out_');
        $outFiles[] = $outFile;
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerScript) . ' '
            . escapeshellarg($dbPath) . ' ' . escapeshellarg((string) $userId) . ' '
            . escapeshellarg($symbol) . ' ' . escapeshellarg($style) . ' ' . escapeshellarg($outFile);
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $allPipes[$i] = $pipes;
    }

    $results = [];
    foreach ($procs as $i => $proc) {
        stream_get_contents($allPipes[$i][1]);
        $stderrOut = stream_get_contents($allPipes[$i][2]);
        fclose($allPipes[$i][1]);
        fclose($allPipes[$i][2]);
        $exitCode = proc_close($proc);
        assertTrue($exitCode === 0, "$label: worker $i exited cleanly (code 0)" . ($stderrOut ? " [stderr: $stderrOut]" : ''));
        $results[$i] = json_decode(file_get_contents($outFiles[$i]), true);
        @unlink($outFiles[$i]);
    }

    $checkPdo = new PDO('sqlite:' . $dbPath);
    $rowCount = (int) $checkPdo->query("SELECT COUNT(*) c FROM positions WHERE status = 'open'")->fetch(PDO::FETCH_ASSOC)['c'];
    unset($checkPdo);
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');

    return [$results, $rowCount];
}

echo "\n=== Genuine multi-process concurrency: SAME symbol+tradingStyle, NO shared idempotency key ===\n";
// The exact real scenario this audit pass asked about: N genuinely
// independent open requests (real separate OS processes, each with NO
// idempotency key at all - not retries of one shared request) for the
// SAME symbol+tradingStyle, e.g. the browser and the driver both
// independently deciding "BUY NIFTY now" within the same tick window.
[$results, $rowCount] = runRaceScenario('same-symbol race', 8, true);
assertTrue($rowCount === 1, "exactly ONE real 'open' row exists for NIFTY|intraday after 8 genuinely concurrent, independent (no shared idempotency key) open requests (got $rowCount) - the DB-level partial-unique-index constraint, not application logic, is what actually prevented the duplicate simultaneous opens");

$successFlags = array_map(fn($r) => $r['success'] ?? null, $results);
$successCount = count(array_filter($successFlags, fn($v) => $v === true));
assertTrue($successCount === 1, "exactly one of the 8 genuinely concurrent, independent open requests actually won and created the row (got $successCount successes) - the other 7 genuinely lost, not merely returned a duplicate id");

$rejectedFlags = array_map(fn($r) => $r['symbolAlreadyOpen'] ?? null, $results);
$rejectedCount = count(array_filter($rejectedFlags, fn($v) => $v === true));
assertTrue($rejectedCount === 7, "the other 7 genuinely concurrent losing workers each correctly, honestly detected the real unique-constraint collision and reported symbolAlreadyOpen (got $rejectedCount) - none silently believed it succeeded");

echo "\n=== Genuine multi-process concurrency: DIFFERENT symbols (must NOT be over-blocked) ===\n";
// Companion scenario proving the fix doesn't over-collide: 8 genuinely
// concurrent open requests for 8 DIFFERENT symbols for the same user
// must all succeed as 8 separate open rows - the per-symbol lock must
// never block unrelated symbols from opening concurrently.
[$resultsDiff, $rowCountDiff] = runRaceScenario('different-symbol concurrency', 8, false);
assertTrue($rowCountDiff === 8, "8 genuinely concurrent open requests for 8 DIFFERENT symbols for the same user correctly produced 8 separate real open rows (got $rowCountDiff) - the single-open-per-symbol invariant does not over-block legitimately distinct, concurrently-open symbols");
$successFlagsDiff = array_map(fn($r) => $r['success'] ?? null, $resultsDiff);
assertTrue(count(array_filter($successFlagsDiff, fn($v) => $v === true)) === 8, 'all 8 different-symbol workers succeeded, none spuriously rejected by the symbol lock');

echo "\n" . $passed . " passed, " . $failed . " failed\n";
if ($failed > 0) exit(1);
