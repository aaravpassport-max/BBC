<?php
/**
 * Worker process for ConcurrentSymbolOpenRaceTest.php - NOT a standalone
 * test, invoked as a child process (php -f ... $dbPath $userId $symbol
 * $tradingStyle $outFile) so several of these genuinely run IN PARALLEL,
 * at close to the same wall-clock time, against ONE shared real SQLite
 * database file - real OS-level process concurrency, not a sequential
 * in-process loop.
 *
 * Unlike ConcurrentIdempotencyRaceWorker.php (which proves the
 * user_idempotency UNIQUE KEY correctly dedupes RETRIES of the SAME
 * logical request, i.e. all workers share one idempotency key), this
 * worker deliberately sends NO idempotency key at all - each worker is
 * a genuinely DIFFERENT logical open request (exactly like the browser
 * and the headless driver each independently deciding "BUY now" for the
 * same symbol from their own local/stale state, with no shared retry
 * key between them). The real safety net under test here is the DB-level
 * partial-unique-index equivalent (a generated "open_symbol_lock" column
 * that is NULL unless status='open', wrapped in a UNIQUE index - see
 * fno-lab.php's dbDelta schema, "user_symbol_style_open_lock"); this
 * worker uses SQLite's native partial index syntax
 * (`WHERE status = 'open'`) as the real, standard, portable equivalent
 * of that same DB-enforced invariant, proven under genuine concurrency.
 */
[, $dbPath, $userId, $symbol, $tradingStyle, $outFile] = $argv;

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA busy_timeout = 5000');

// Deliberately spin briefly so several worker processes launched back to
// back actually overlap their INSERT window instead of the OS just
// running them one at a time.
usleep(random_int(0, 15000));

try {
    $ins = $pdo->prepare("INSERT INTO positions (user_id, symbol, trading_style, status) VALUES (?, ?, ?, 'open')");
    $ins->execute([$userId, $symbol, $tradingStyle]);
    $id = (int) $pdo->lastInsertId();
    file_put_contents($outFile, json_encode(['id' => $id, 'success' => true]));
} catch (PDOException $e) {
    // Real unique-constraint violation - SQLite raises this as "UNIQUE
    // constraint failed", MySQL/MariaDB raises "Duplicate entry ... for
    // key 'user_symbol_style_open_lock'" (see fno-lab.php's own
    // strpos() check in fno_open_position_fn) - different wording, same
    // real mechanism this test is verifying: the DB engine itself, not
    // application logic, is what actually prevented the second row.
    if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
        file_put_contents($outFile, json_encode(['success' => false, 'symbolAlreadyOpen' => true]));
    } else {
        file_put_contents($outFile, json_encode(['success' => false, 'error' => $e->getMessage()]));
    }
}
