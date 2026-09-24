<?php
/**
 * REAL concurrency regression test for the idempotency-key mechanism
 * used by fno_open_position_fn / fno_journal_add_fn (fno-lab.php).
 *
 * WHY THIS TEST EXISTS (audit finding, 2026-08-29 pass): the existing
 * OpenPositionsTest.php / JournalIntegrityTest.php idempotency
 * scenarios (Scenario F/G in each) only prove the logic is correct
 * under SEQUENTIAL calls within a single PHP process, against a
 * hand-rolled FakeWpdb that can never actually race with itself - two
 * sequential calls in one process can never expose a check-then-insert
 * TOCTOU gap. This test instead spawns real, separate OS processes
 * (via proc_open, one per "request") that genuinely run concurrently
 * against ONE shared, real SQLite database file with the SAME
 * UNIQUE KEY (user_id, idempotency_key) shape as fno-lab.php's real
 * dbDelta schema for wp_fno_open_positions (fno-lab.php:488-493,
 * "UNIQUE KEY user_idempotency (user_id, idempotency_key)") and
 * wp_fno_journal (fno-lab.php:162-166, "UNIQUE KEY
 * user_journal_idempotency (user_id, idempotency_key)") - proving the
 * real mechanism (a DB-engine-enforced UNIQUE constraint, not an
 * application-level SELECT-then-INSERT with no DB backing) actually
 * prevents duplicate rows under genuine concurrency, not just under
 * two polite, sequential test calls.
 *
 * Each worker (ConcurrentIdempotencyRaceWorker.php) runs the EXACT
 * same check-then-insert-then-catch-duplicate pattern fno-lab.php's
 * real handlers use (see fno-lab.php:4287-4318, :4506-4590) - a SELECT
 * check first (an optimization only), then INSERT, and on a genuine
 * unique-constraint violation, re-SELECT and return the winner's id.
 *
 * Run with: php tests/php/ConcurrentIdempotencyRaceTest.php
 */

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

function runRaceScenario($label, $numWorkers, $sameKey) {
    global $passed, $failed;
    $dbPath = tempnam(sys_get_temp_dir(), 'fno_race_') . '.sqlite';
    @unlink($dbPath);
    $setupPdo = new PDO('sqlite:' . $dbPath);
    $setupPdo->exec('PRAGMA journal_mode = WAL'); // real concurrent-writer-friendly mode, same reason wp's real MySQL tables support real concurrent INSERTs
    $setupPdo->exec('CREATE TABLE positions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        idempotency_key TEXT NOT NULL,
        UNIQUE (user_id, idempotency_key)
    )');
    unset($setupPdo); // release the handle before workers open their own connections

    $workerScript = __DIR__ . '/ConcurrentIdempotencyRaceWorker.php';
    $outFiles = [];
    $procs = [];
    $userId = 42;

    // Launch every worker back-to-back with NO waiting in between -
    // this is what makes them genuinely concurrent (proc_open returns
    // immediately; the worker itself adds a small random sleep so
    // their SELECT/INSERT windows actually overlap).
    for ($i = 0; $i < $numWorkers; $i++) {
        $key = $sameKey ? 'race-key-shared' : ('race-key-' . $i);
        $outFile = tempnam(sys_get_temp_dir(), 'fno_race_out_');
        $outFiles[] = $outFile;
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerScript) . ' '
            . escapeshellarg($dbPath) . ' ' . escapeshellarg((string) $userId) . ' '
            . escapeshellarg($key) . ' ' . escapeshellarg($outFile);
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $allPipes[$i] = $pipes;
    }

    // Now wait for all of them.
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
    $rowCount = (int) $checkPdo->query('SELECT COUNT(*) c FROM positions')->fetch(PDO::FETCH_ASSOC)['c'];
    unset($checkPdo);
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');

    return [$results, $rowCount];
}

echo "\n=== Genuine multi-process concurrency: SAME idempotency key ===\n";
// The actual scenario the audit brief asked about: N near-simultaneous
// real requests (real separate OS processes, not sequential calls)
// all carrying the SAME idempotency key - e.g. a client that retried
// an open-position request 5 times in a row because of a flaky
// connection, or a genuine double-click race.
[$results, $rowCount] = runRaceScenario('same-key race', 8, true);
assertTrue($rowCount === 1, "exactly ONE real row exists in the database after 8 genuinely concurrent same-key requests (got $rowCount) - the UNIQUE KEY constraint, not application logic, is what actually prevented the duplicate");

$ids = array_map(fn($r) => $r['id'] ?? null, $results);
$uniqueIds = array_unique($ids);
assertTrue(count($uniqueIds) === 1 && $ids[0] !== null, 'all 8 concurrent workers observed and returned the SAME winning id back to their caller (no worker silently lost track of the real row)');

$replayFlags = array_map(fn($r) => $r['idempotentReplay'] ?? null, $results);
$replayTrueCount = count(array_filter($replayFlags, fn($v) => $v === true));
$replayFalseCount = count(array_filter($replayFlags, fn($v) => $v === false));
assertTrue($replayFalseCount === 1, "exactly one of the 8 workers won the real INSERT race and reported idempotentReplay=false (got $replayFalseCount)");
assertTrue($replayTrueCount === 7, "the other 7 workers correctly detected the collision (via pre-check OR the real UNIQUE-constraint-violation catch path) and reported idempotentReplay=true (got $replayTrueCount)");

echo "\n=== Genuine multi-process concurrency: DIFFERENT idempotency keys (must NOT be over-blocked) ===\n";
// Companion scenario proving the fix doesn't over-collide: 8 genuinely
// concurrent requests with 8 DIFFERENT keys for the same user (8
// different real trades opened back-to-back) must all succeed as 8
// separate rows - concurrency alone must never merge unrelated trades.
[$resultsDiff, $rowCountDiff] = runRaceScenario('different-key concurrency', 8, false);
assertTrue($rowCountDiff === 8, "8 genuinely concurrent DIFFERENT-key requests for the same user correctly produced 8 separate real rows (got $rowCountDiff) - concurrency safety does not over-block legitimately distinct trades");
$idsDiff = array_map(fn($r) => $r['id'] ?? null, $resultsDiff);
assertTrue(count(array_unique($idsDiff)) === 8, 'all 8 different-key workers got 8 distinct ids, none colliding');

echo "\n" . $passed . " passed, " . $failed . " failed\n";
if ($failed > 0) exit(1);
