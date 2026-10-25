#!/usr/bin/env node
/**
 * Real, permanent regression test for this session's exit-side
 * persistence retry queue (Decision Matrix / KB §7 "Driver's journal
 * write to WordPress fails... no retry mechanism" - previously
 * **[PARTIAL]**, honestly documented as an open gap with no retry at
 * all).
 *
 * Three things are checked:
 *
 * 1. A real, direct behavioral check of enqueueRetry/flushRetryQueue -
 *    extracted verbatim from the current driver source (never
 *    reimplemented) and run against a mocked postAuthenticated/log/fs,
 *    proving: a failed call gets queued, a subsequent success removes
 *    it, a subsequent failure increments attempts and stays queued,
 *    and RETRY_MAX_ATTEMPTS is genuinely honored (given up on, logged,
 *    removed - never retried forever, never silently dropped).
 * 2. A real, static check that flushRetryQueue() is genuinely called
 *    at the very start of every real cycle, before the market-hours
 *    gate.
 * 3. A real, static check that the two EXIT-side failure sites
 *    (journal write, fno_close_position) genuinely call enqueueRetry,
 *    while the OPEN-side failure site deliberately does NOT (the
 *    documented, deliberate asymmetry - a delayed retry of the open
 *    call could corrupt this driver's own live openPosition.id
 *    pointer, so it is honestly left unretried rather than adding an
 *    unsafe "fix").
 *
 * Run with: node test/test-retry-queue.js
 */
const fs = require('fs');
const path = require('path');

const DRIVER_PATH = path.join(__dirname, '../autonomous-driver.js');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log(`  PASS  ${label}`); }
  else { failed++; console.log(`  FAIL  ${label}`); }
}

console.log('=== Driver Exit-Side Retry Queue Regression Test (KB §7 / Decision Matrix) ===\n');

const driverSource = fs.readFileSync(DRIVER_PATH, 'utf8');

// --- Part 1: real, direct extraction and behavioral test ---
const startMarker = "const RETRY_QUEUE_FILE = path.join(__dirname, '.retry-queue.json');";
const endMarker = "// ============================================================\n// Real, in-memory open-position state";
const startIdx = driverSource.indexOf(startMarker);
const endIdx = driverSource.indexOf(endMarker);
check(startIdx > -1 && endIdx > -1 && endIdx > startIdx, 'the real retry-queue block (RETRY_QUEUE_FILE ... end of flushRetryQueue) was genuinely located in the current driver source');
const retryQueueSource = driverSource.slice(startIdx, endIdx);

// Mocked environment - no real disk/network I/O, but the REAL,
// unmodified retry-queue logic runs against it.
const mockLogs = [];
function log(msg) { mockLogs.push(msg); }
let mockDiskFile = null; // simulates the JSON file's on-disk content (null = doesn't exist yet)
const mockFs = {
  existsSync: (p) => mockDiskFile !== null,
  readFileSync: (p, enc) => mockDiskFile,
  writeFileSync: (p, content) => { mockDiskFile = content; },
};
const mockPath = { join: (...parts) => parts.join('/') };
let postAuthenticatedResults = []; // queue of {success, data} to return, one per call, in order
async function postAuthenticated(action, body) {
  if (postAuthenticatedResults.length === 0) return { success: true, data: {} };
  return postAuthenticatedResults.shift();
}

const sandboxFn = new Function('fs', 'path', 'log', 'postAuthenticated', '__dirname', `
  ${retryQueueSource}
  return { enqueueRetry, flushRetryQueue, getQueueLength: () => retryQueue.length, getQueue: () => retryQueue, RETRY_MAX_ATTEMPTS };
`);
const { enqueueRetry, flushRetryQueue, getQueueLength, getQueue, RETRY_MAX_ATTEMPTS } = sandboxFn(mockFs, mockPath, log, postAuthenticated, __dirname);

check(RETRY_MAX_ATTEMPTS >= 1 && RETRY_MAX_ATTEMPTS <= 10, `RETRY_MAX_ATTEMPTS is a real, sane, bounded value (found: ${RETRY_MAX_ATTEMPTS}) - never unbounded/infinite`);

enqueueRetry('fno_journal_add', { a: 1 }, 'test enqueue');
check(getQueueLength() === 1, 'enqueueRetry genuinely adds the item to the real, in-memory queue');
check(mockDiskFile !== null && JSON.parse(mockDiskFile).length === 1, 'enqueueRetry genuinely persists the queue to disk immediately, not only in memory');

(async () => {
  // A failed retry attempt: attempts increments, item stays queued.
  postAuthenticatedResults = [{ success: false, data: 'still down' }];
  await flushRetryQueue();
  check(getQueueLength() === 1, 'a failed retry attempt leaves the item genuinely still queued');
  check(getQueue()[0].attempts === 1, 'a failed retry attempt genuinely increments the real attempts counter');

  // A successful retry: item is genuinely removed.
  postAuthenticatedResults = [{ success: true, data: { id: 42 } }];
  await flushRetryQueue();
  check(getQueueLength() === 0, 'a successful retry genuinely removes the item from the queue - never retried again once it succeeds');

  // Give-up behavior: fails RETRY_MAX_ATTEMPTS times, then is honestly removed and logged, never retried forever.
  enqueueRetry('fno_close_position', { id: 99 }, 'test give-up');
  for (let i = 0; i < RETRY_MAX_ATTEMPTS; i++) {
    postAuthenticatedResults = [{ success: false, data: 'permanently down' }];
    await flushRetryQueue();
  }
  check(getQueueLength() === 0, `an item that has genuinely failed ${RETRY_MAX_ATTEMPTS} times is removed from the queue - never retried forever`);
  check(mockLogs.some(m => /WARNING.*giving up/i.test(m)), 'giving up on an item after RETRY_MAX_ATTEMPTS is genuinely, loudly logged - never silently dropped');

  // A genuinely empty queue is a real, fast no-op (no crash, no log noise).
  const logsBefore = mockLogs.length;
  await flushRetryQueue();
  check(mockLogs.length === logsBefore, 'flushing a genuinely empty queue is a real no-op - no log entries added');

  // --- Part 2: static check - flushRetryQueue() called at the very start of every real cycle ---
  const cycleBodyIdx = driverSource.indexOf('async function runCycleBody() {');
  check(cycleBodyIdx > -1, 'runCycleBody must still exist under this exact name in the current source');
  const cycleBodyStart = driverSource.slice(cycleBodyIdx, cycleBodyIdx + 600);
  const flushIdx = cycleBodyStart.indexOf('await flushRetryQueue();');
  const marketHoursIdx = cycleBodyStart.indexOf('const marketHours = isRealMarketHours();');
  check(flushIdx > -1 && marketHoursIdx > -1 && flushIdx < marketHoursIdx, 'flushRetryQueue() is genuinely called at the very start of every real cycle, BEFORE the market-hours gate - so a pending exit-side write can be retried even outside market hours');

  // --- Part 3: static check - exit-side sites enqueue, open-side site deliberately does not ---
  const exitBlockIdx = driverSource.indexOf("if (!result.success) {");
  const exitBlock = driverSource.slice(exitBlockIdx, exitBlockIdx + 300);
  check(/enqueueRetry\('fno_journal_add', journalBody,/.test(exitBlock), 'the real journal-write failure site genuinely calls enqueueRetry with the exact journalBody that was sent, so a retry replays the identical real payload');

  const closeBlockIdx = driverSource.indexOf("if (!closeResult.success) {");
  // Widened from 500: the close-failure branch now also distinguishes
  // the benign 'alreadyClosed' lost-race outcome first (see
  // test-close-position-lost-race.js for the dedicated regression test
  // of that split) before the genuine-failure branch that still calls
  // enqueueRetry - the genuine-failure enqueueRetry call now sits
  // further into the block than it used to.
  const closeBlock = driverSource.slice(closeBlockIdx, closeBlockIdx + 2200);
  check(/enqueueRetry\('fno_close_position', closeBody,/.test(closeBlock), 'the real fno_close_position failure site genuinely calls enqueueRetry with the exact closeBody that was sent, including the real position id');

  const openFailIdx = driverSource.indexOf('WARNING: real fno_open_position call failed');
  check(openFailIdx > -1, 'the real fno_open_position failure warning must still exist');
  const openFailBlock = driverSource.slice(openFailIdx - 50, openFailIdx + 400);
  check(!/enqueueRetry/.test(openFailBlock), "the OPEN-side failure site deliberately does NOT call enqueueRetry - a delayed retry there could corrupt this driver's own live openPosition.id pointer, a documented, deliberate asymmetry, not an oversight");

  // --- Part 4: real, on-disk regression check - .retry-queue.json is written owner-only (0600) ---
  // Post-session file-permissions audit: this file's queued items
  // carry real, unredacted position/pnl detail (see journalBody/
  // closeBody above), so it must never be left world-readable at the
  // default mode. Runs the REAL saveRetryQueue() (extracted from the
  // live source, not reimplemented) against a REAL temp file on real
  // disk (not the mockFs used in Part 1, which never touches a real
  // filesystem and so can't observe real permission bits), and checks
  // the actual stat() mode bits - twice, to also prove the fix holds
  // on a save to an ALREADY-EXISTING file, not just file creation
  // (writeFileSync's own `mode` option is silently ignored on an
  // existing file, which is exactly why the real fix also chmodSync's
  // explicitly on every save).
  if (process.platform !== 'win32') {
    const os = require('os');
    const realFs = require('fs');
    const tmpQueueFile = path.join(os.tmpdir(), `fno-retry-queue-test-${Date.now()}-${Math.random().toString(36).slice(2)}.json`);
    const saveBlockMarker = 'function saveRetryQueue() {';
    const saveStartIdx = driverSource.indexOf(saveBlockMarker);
    const saveEndIdx = driverSource.indexOf('\n}', saveStartIdx) + 2;
    const saveRetryQueueSource = driverSource.slice(saveStartIdx, saveEndIdx);
    try {
      const sandboxSaveFn = new Function('fs', 'RETRY_QUEUE_FILE', 'retryQueue', 'log', `
        ${saveRetryQueueSource}
        saveRetryQueue();
      `);
      sandboxSaveFn(realFs, tmpQueueFile, [{ action: 'fno_journal_add', body: { symbol: 'TEST' }, attempts: 0 }], log);
      let mode1 = realFs.statSync(tmpQueueFile).mode & 0o777;
      check(mode1 === 0o600, `saveRetryQueue() writes a NEW .retry-queue.json as owner-only 0600 (found: ${mode1.toString(8)})`);

      // Second save onto the SAME, already-existing file - proves the
      // explicit chmodSync, not just writeFileSync's own `mode`
      // option (which Node silently ignores for an existing file).
      sandboxSaveFn(realFs, tmpQueueFile, [{ action: 'fno_close_position', body: { id: 1 }, attempts: 0 }], log);
      let mode2 = realFs.statSync(tmpQueueFile).mode & 0o777;
      check(mode2 === 0o600, `saveRetryQueue() re-enforces owner-only 0600 on a save to an ALREADY-EXISTING .retry-queue.json too (found: ${mode2.toString(8)})`);
    } finally {
      try { realFs.unlinkSync(tmpQueueFile); } catch (e) { /* best-effort cleanup */ }
    }
  } else {
    console.log('  SKIP  file-mode checks skipped on win32 (POSIX permission bits do not apply)');
  }

  console.log(`\n${passed} passed, ${failed} failed`);
  process.exit(failed > 0 ? 1 : 0);
})();
