// REAL, STANDALONE regression test - "lost the race" handling for the
// browser's fno_close_position call site (closeAutoTrade(), assets/
// fno-lab-core.js).
//
// CONTEXT: an earlier pass made fno_close_position_fn (fno-lab.php)
// atomic (UPDATE ... WHERE status='open', checked via rows_affected)
// to fix a genuine double-close race between the browser and the
// headless autonomous-driver. The caller that LOSES that race now gets
// wp_send_json_error(['message' => '...already closed by a concurrent
// request...', 'alreadyClosed' => true]) - the SAME established
// machine-readable-flag pattern fno_open_position_fn already uses for
// its own 'idempotentReplay' case.
//
// THIS TEST proves the browser's closeAutoTrade() genuinely
// distinguishes that specific, benign outcome from a real failure:
//   - alreadyClosed:true  -> calm console.info, resyncs via
//     loadPaperAccount()/loadTradeLedger() (server-side state is
//     authoritative), NEVER a console.warn/error.
//   - any other success:false -> console.warn (a real failure), and
//     does NOT call the resync functions a second, redundant time.
// It also proves the pre-existing local commit (journal write +
// STORAGE.autoTrades clear) happens exactly once regardless, since
// this call site is fire-and-forget by design (see the "best-effort"
// comment directly above it in the source) - so there is no duplicate
// local journal entry risk from this path either way.
//
// Run with: node tests/close-position-lost-race-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const elements = new Map();
function el(id) {
  if (!elements.has(id)) {
    elements.set(id, { value: '', textContent: '', innerHTML: '', checked: true, style: {} });
  }
  return elements.get(id);
}
global.document = { getElementById: (id) => el(id) };
global.localStorage = (function () {
  let store = {};
  return {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => { store[k] = String(v); },
    removeItem: (k) => { delete store[k]; },
    clear: () => { store = {}; },
  };
})();
global.window = { FNO_AJAX: { url: 'http://test.invalid/wp-admin/admin-ajax.php', nonce: 'n', isLoggedIn: true } };

// Each real fetch() call gets a queued canned response - the journalAdd
// call always succeeds instantly; the fno_close_position POST returns
// whatever this test currently has queued in closeResponseQueue.
let closeResponseQueue = [];
let fetchLog = [];
global.fetch = (url) => {
  fetchLog.push(url);
  if (String(url).includes('action=fno_close_position')) {
    const resp = closeResponseQueue.shift() || { success: true, data: {} };
    return Promise.resolve({ json: () => Promise.resolve(resp) });
  }
  // journalAdd's own internal fetch (fno_journal_add) - always succeeds.
  return Promise.resolve({ json: () => Promise.resolve({ success: true, data: { id: 1 } }) });
};

const { extractCoreFunction } = require('./lib/extract-core-fn');

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const renderStart = coreSource.indexOf('\nfunction render(){');
if (renderStart === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js.');
  process.exit(1);
}
const baseSlice = coreSource.slice(0, renderStart);

const closeAutoTradeSrc = extractCoreFunction(
  coreSource,
  'async function closeAutoTrade(open, exitLeg, exitReason, sym',
  '} finally { fnoAutoTradeCloseInProgress = false; }\n  }'
);

let resyncCalls = 0;
eval(
  baseSlice + '\n' +
  'let curCtx = null; let mode = "paper";\n' +
  'function loadPaperAccount(){ global.__resyncCalls = (global.__resyncCalls||0)+1; } function loadTradeLedger(){}\n' +
  closeAutoTradeSrc + '\n' +
  'global.STORAGE = STORAGE; global.save = save; global.load = load; global.loadObj = loadObj; global.closeAutoTrade = closeAutoTrade;'
);

function freshOpen() {
  return {
    id: 1, serverPositionId: 555, strike: 25000, optionType: 'CE',
    entryPrice: 100, qty: 75, target: 130, sl: 80,
    openedAt: Date.now(), tradingType: 'intraday',
    executionMode: 'theoretical', fillIsRealistic: true,
  };
}
const exitLeg = { lastPrice: 130, impliedVolatility: 18 };

async function drainMicrotasks() {
  // The server-close POST is fire-and-forget (no await in closeAutoTrade
  // itself) - give its .then() chain a real tick to run before asserting.
  await new Promise((r) => setTimeout(r, 0));
  await new Promise((r) => setTimeout(r, 0));
}

async function testAlreadyClosed() {
  localStorage.clear();
  global.__resyncCalls = 0;
  let warnCalls = [], infoCalls = [];
  const origWarn = console.warn, origInfo = console.info;
  console.warn = (...a) => { warnCalls.push(a); origWarn.apply(console, a); };
  console.info = (...a) => { infoCalls.push(a); origInfo.apply(console, a); };
  try {
    closeResponseQueue = [{ success: false, data: { message: 'Real position was already closed by a concurrent request (browser/driver race)', alreadyClosed: true } }];
    save(STORAGE.autoTrades, freshOpen());
    await closeAutoTrade(freshOpen(), exitLeg, 'target', 'NIFTY');
    await drainMicrotasks();

    const history = load(STORAGE.autoTrades + '_history');
    check(history.length === 1, `exactly one local journal/history entry was written for this one closeAutoTrade() call (got ${history.length}) - no duplicate write triggered by the lost-race response`);
    check(warnCalls.length === 0, 'an alreadyClosed:true lost-race response does NOT produce a console.warn (not treated as an alarming failure)');
    check(infoCalls.length === 1, 'an alreadyClosed:true lost-race response produces exactly one calm console.info note');
    // closeAutoTrade() already unconditionally calls loadPaperAccount()
    // once at the end of every invocation (its own normal post-close
    // refresh) - the alreadyClosed branch adds one MORE, since its
    // fetch().then() resolves asynchronously, after that unconditional
    // call already ran.
    check(global.__resyncCalls === 2, `an alreadyClosed:true lost-race response triggers one EXTRA resync (loadPaperAccount()) beyond the function's own unconditional end-of-call refresh, so this tab's view reflects the authoritative server-side close (got ${global.__resyncCalls})`);
    const finalOpen = loadObj(STORAGE.autoTrades);
    check(!finalOpen.id, 'local open-position state is still correctly cleared (this browser tab\'s own local close already fully committed, independent of the server response)');
  } finally {
    console.warn = origWarn; console.info = origInfo;
  }
}

async function testGenuineFailure() {
  localStorage.clear();
  global.__resyncCalls = 0;
  let warnCalls = [], infoCalls = [];
  const origWarn = console.warn, origInfo = console.info;
  console.warn = (...a) => { warnCalls.push(a); origWarn.apply(console, a); };
  console.info = (...a) => { infoCalls.push(a); origInfo.apply(console, a); };
  try {
    closeResponseQueue = [{ success: false, data: { message: 'Real database update failed: some genuine DB error' } }];
    save(STORAGE.autoTrades, freshOpen());
    await closeAutoTrade(freshOpen(), exitLeg, 'target', 'NIFTY');
    await drainMicrotasks();

    check(warnCalls.length === 1, 'a genuine (non-alreadyClosed) failure DOES produce a console.warn - real failures are still surfaced, not silently swallowed');
    check(infoCalls.length === 0, 'a genuine failure does not produce the calm alreadyClosed info note');
    check(global.__resyncCalls === 1, `a genuine failure does not trigger the EXTRA alreadyClosed-specific resync - only closeAutoTrade()'s own normal unconditional end-of-call refresh runs (got ${global.__resyncCalls})`);
  } finally {
    console.warn = origWarn; console.info = origInfo;
  }
}

async function testGenuineSuccess() {
  localStorage.clear();
  global.__resyncCalls = 0;
  closeResponseQueue = [{ success: true, data: { position: {}, exitPrice: 130, exitReason: 'AUTO_TARGET_EXIT' } }];
  save(STORAGE.autoTrades, freshOpen());
  await closeAutoTrade(freshOpen(), exitLeg, 'target', 'NIFTY');
  await drainMicrotasks();
  const history = load(STORAGE.autoTrades + '_history');
  check(history.length === 1, 'a genuine server-side success still results in exactly one journal/history entry (no regression to the normal win-the-race path)');
}

(async () => {
  await testAlreadyClosed();
  await testGenuineFailure();
  await testGenuineSuccess();
  console.log(`\n${passed} passed, ${failed} failed (close-position-lost-race-audit.test.js)`);
  process.exit(failed > 0 ? 1 : 0);
})();
