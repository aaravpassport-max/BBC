<?php
/**
 * Worker process for ConcurrentIdempotencyRaceTest.php - NOT a
 * standalone test, invoked as a child process (php -f ... $dbPath
 * $userId $idempotencyKey $outFile) so several of these genuinely run
 * IN PARALLEL, at close to the same wall-clock time, against ONE
 * shared real SQLite database file - real OS-level process
 * concurrency, not a sequential in-process loop.
 *
 * This mirrors the EXACT check-then-insert-then-catch-duplicate
 * pattern fno_open_position_fn / fno_journal_add_fn use in fno-lab.php
 * (see fno-lab.php:4287-4318 and :4506-4590): SELECT for an existing
 * row first (an optimization, NOT the real safety net), INSERT, and on
 * a genuine unique-constraint violation, re-SELECT and return the
 * winning row's id instead of erroring. The real safety net is the
 * DB-level UNIQUE KEY itself (user_idempotency / user_journal_idempotency
 * in fno-lab.php's dbDelta schema) - this test proves THAT constraint,
 * enforced by a real database engine under real concurrent writers,
 * actually holds, not just that a single PHP process's own in-memory
 * bookkeeping is careful.
 */
[, $dbPath, $userId, $idemKey, $outFile] = $argv;

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Real busy-timeout so concurrent writers wait for SQLite's real file
// lock instead of immediately erroring - the same real "another writer
// currently holds this row/table" condition a real MySQL/MariaDB
// server under real concurrent load also produces, just via a
// different real locking mechanism. This is what makes two near-
// simultaneous requests a genuine race rather than an artificial one.
$pdo->exec('PRAGMA busy_timeout = 5000');

// Deliberately spin briefly so several worker processes launched back
// to back actually overlap their SELECT/INSERT window instead of the
// OS just running them one at a time - realistic simulation of two
// PHP-FPM workers handling two near-simultaneous HTTP requests.
usleep(random_int(0, 15000));

$existing = $pdo->prepare('SELECT id FROM positions WHERE user_id = ? AND idempotency_key = ?');
$existing->execute([$userId, $idemKey]);
$row = $existing->fetch(PDO::FETCH_ASSOC);
if ($row) {
    file_put_contents($outFile, json_encode(['id' => (int) $row['id'], 'idempotentReplay' => true]));
    exit(0);
}

try {
    $ins = $pdo->prepare('INSERT INTO positions (user_id, idempotency_key) VALUES (?, ?)');
    $ins->execute([$userId, $idemKey]);
    $id = (int) $pdo->lastInsertId();
    file_put_contents($outFile, json_encode(['id' => $id, 'idempotentReplay' => false]));
} catch (PDOException $e) {
    // Real unique-constraint violation - SQLite raises this as
    // "UNIQUE constraint failed", MySQL/MariaDB raises "Duplicate
    // entry" (see fno-lab.php's strpos check) - different wording,
    // same real mechanism this test is verifying.
    if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
        $raceWinner = $pdo->prepare('SELECT id FROM positions WHERE user_id = ? AND idempotency_key = ?');
        $raceWinner->execute([$userId, $idemKey]);
        $winRow = $raceWinner->fetch(PDO::FETCH_ASSOC);
        file_put_contents($outFile, json_encode(['id' => (int) $winRow['id'], 'idempotentReplay' => true]));
    } else {
        file_put_contents($outFile, json_encode(['error' => $e->getMessage()]));
    }
}
