#!/usr/bin/env node
/**
 * Real, permanent regression test - "lost the race" handling for the
 * driver's fno_close_position call sites.
 *
 * CONTEXT: fno_close_position_fn (fno-lab.php) now returns
 * wp_send_json_error(['message' => '...', 'alreadyClosed' => true])
 * when a concurrent caller (browser tab, or this driver's own retry
 * queue) already closed the position first - the same established
 * machine-readable-flag pattern fno_open_position_fn already uses for
 * its own 'idempotentReplay' case. Before this fix, the driver treated
 * ANY success:false from fno_close_position identically: log a
 * WARNING and enqueueRetry() it. For a lost-race response that is
 * wrong - retrying only ever re-hits rows_affected=0, and eventually
 * exhausts RETRY_MAX_ATTEMPTS, logging a false "genuinely, permanently
 * lost" alarm for a position that was, correctly, closed the whole
 * time.
 *
 * This test proves, against the REAL, unmodified source (extracted by
 * its own real function/marker boundaries, never reimplemented):
 *   1. flushRetryQueue() drops a queued fno_close_position retry item
 *      calmly (no attempts increment, no WARNING) when the server
 *      reports alreadyClosed:true - treated like a genuine success.
 *   2. flushRetryQueue() still treats a GENUINE fno_close_position
 *      failure (no alreadyClosed flag) exactly as before - queued,
 *      attempts incremented, eventual give-up WARNING.
 *   3. The intraday close call site (inside processExit) does NOT
 *      call enqueueRetry when the response carries alreadyClosed:true
 *      (static source check - the real, unmodified branch), but DOES
 *      call enqueueRetry for a genuine failure.
 *   4. The swing close call site (checkAndMonitorSwingPositions) has
 *      the same alreadyClosed distinction wired in (static source
 *      check) - previously it did not even check the result at all.
 *
 * Run with: node test/test-close-position-lost-race.js
 */
const fs = require('fs');
const path = require('path');

const DRIVER_PATH = path.join(__dirname, '../autonomous-driver.js');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log(`  PASS  ${label}`); }
  else { failed++; console.log(`  FAIL  ${label}`); }
}

console.log('=== Driver fno_close_position Lost-Race Handling Regression Test ===\n');

const driverSource = fs.readFileSync(DRIVER_PATH, 'utf8');

// --- Part 1/2: real, direct behavioral test of flushRetryQueue() ---
const startMarker = "const RETRY_QUEUE_FILE = path.join(__dirname, '.retry-queue.json');";
const endMarker = "// ============================================================\n// Real, in-memory open-position state";
const startIdx = driverSource.indexOf(startMarker);
const endIdx = driverSource.indexOf(endMarker);
check(startIdx > -1 && endIdx > -1 && endIdx > startIdx, 'the real retry-queue block (RETRY_QUEUE_FILE ... end of flushRetryQueue) was genuinely located in the current driver source');
const retryQueueSource = driverSource.slice(startIdx, endIdx);

const mockLogs = [];
function log(msg) { mockLogs.push(msg); }
let mockDiskFile = null;
const mockFs = {
  existsSync: (p) => mockDiskFile !== null,
  readFileSync: (p, enc) => mockDiskFile,
  writeFileSync: (p, content) => { mockDiskFile = content; },
  chmodSync: (p, mode) => {},
};
const mockPath = { join: (...parts) => parts.join('/') };
let postAuthenticatedResults = [];
async function postAuthenticated(action, body) {
  if (postAuthenticatedResults.length === 0) return { success: true, data: {} };
  return postAuthenticatedResults.shift();
}

const sandboxFn = new Function('fs', 'path', 'log', 'postAuthenticated', '__dirname', `
  ${retryQueueSource}
  return { enqueueRetry, flushRetryQueue, getQueueLength: () => retryQueue.length, getQueue: () => retryQueue, RETRY_MAX_ATTEMPTS };
`);
const { enqueueRetry, flushRetryQueue, getQueueLength, getQueue, RETRY_MAX_ATTEMPTS } = sandboxFn(mockFs, mockPath, log, postAuthenticated, __dirname);

(async () => {
  // 1. A queued fno_close_position retry that comes back alreadyClosed
  // is dropped calmly - no attempts increment, no WARNING logged.
  enqueueRetry('fno_close_position', { id: 77 }, 'test lost-race drop');
  const logsBefore = mockLogs.length;
  postAuthenticatedResults = [{ success: false, data: { message: 'already closed by a concurrent request', alreadyClosed: true } }];
  await flushRetryQueue();
  check(getQueueLength() === 0, 'a queued fno_close_position retry that comes back alreadyClosed:true is dropped from the queue, not kept for another attempt');
  const newLogs = mockLogs.slice(logsBefore);
  check(newLogs.some(m => /already closed by a concurrent caller/i.test(m)), 'a calm, non-alarming log line is written for the dropped alreadyClosed retry item');
  check(!newLogs.some(m => /WARNING/i.test(m)), 'no WARNING is logged for the benign alreadyClosed retry outcome');

  // 2. A GENUINE fno_close_position retry failure (no alreadyClosed
  // flag) still behaves exactly as before: queued, attempts
  // incremented, eventual give-up WARNING after RETRY_MAX_ATTEMPTS.
  enqueueRetry('fno_close_position', { id: 88 }, 'test genuine failure still retries');
  postAuthenticatedResults = [{ success: false, data: 'genuine DB error, no alreadyClosed flag' }];
  await flushRetryQueue();
  check(getQueueLength() === 1, 'a GENUINE (non-alreadyClosed) fno_close_position failure is still kept queued for retry - the fix is scoped only to the alreadyClosed case');
  check(getQueue()[0].attempts === 1, 'a genuine failure still increments the real attempts counter, unaffected by the alreadyClosed fix');
  for (let i = 0; i < RETRY_MAX_ATTEMPTS - 1; i++) {
    postAuthenticatedResults = [{ success: false, data: 'genuine DB error, no alreadyClosed flag' }];
    await flushRetryQueue();
  }
  check(getQueueLength() === 0, `a genuinely, permanently failing fno_close_position retry is still given up on after ${RETRY_MAX_ATTEMPTS} real attempts, exactly as before`);
  check(mockLogs.some(m => /WARNING.*giving up/i.test(m) && /id.*88/.test(m) === false ? true : /WARNING.*giving up/i.test(m)), 'the genuine give-up WARNING is still logged for a real, non-alreadyClosed permanent failure');

  // --- Part 3: static check - intraday close call site (processExit) ---
  const intradayCloseIdx = driverSource.indexOf("const closeResult = await postAuthenticated('fno_close_position', closeBody);");
  check(intradayCloseIdx > -1, "the real intraday fno_close_position call site was genuinely located");
  const intradayBlock = driverSource.slice(intradayCloseIdx, intradayCloseIdx + 2200);
  check(/closeResult\.data\s*&&\s*closeResult\.data\.alreadyClosed/.test(intradayBlock), 'the intraday close call site genuinely checks closeResult.data.alreadyClosed');
  const alreadyClosedBranch = intradayBlock.slice(intradayBlock.indexOf('if (closeResult.data && closeResult.data.alreadyClosed)'), intradayBlock.indexOf('} else {'));
  check(!/enqueueRetry/.test(alreadyClosedBranch), 'the alreadyClosed branch at the intraday close site does NOT call enqueueRetry (no futile, alarming retry for an already-closed position)');
  const genuineFailureBranch = intradayBlock.slice(intradayBlock.indexOf('} else {'), intradayBlock.indexOf('} else {') + 400);
  check(/enqueueRetry\('fno_close_position', closeBody,/.test(genuineFailureBranch), 'the genuine-failure branch at the intraday close site still calls enqueueRetry with the exact closeBody - unaffected by the alreadyClosed fix');

  // --- Part 4: static check - swing close call site (checkAndMonitorSwingPositions) ---
  const swingCloseIdx = driverSource.indexOf("const swingCloseResult = await postAuthenticated('fno_close_position',");
  check(swingCloseIdx > -1, 'the real swing fno_close_position call site was genuinely located');
  const swingBlock = driverSource.slice(swingCloseIdx, swingCloseIdx + 1200);
  check(/swingCloseResult\.data\s*&&\s*swingCloseResult\.data\.alreadyClosed/.test(swingBlock), 'the swing close call site genuinely checks swingCloseResult.data.alreadyClosed (previously the swing site did not check the result at all)');

  console.log(`\n${passed} passed, ${failed} failed`);
  process.exit(failed > 0 ? 1 : 0);
})();
