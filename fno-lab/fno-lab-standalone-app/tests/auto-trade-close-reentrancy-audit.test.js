// REAL, STANDALONE test - auto-trade close/partial-close re-entrancy
// audit, a direct follow-up to the fnoRefreshGeneration finding
// documented at the top of assets/fno-lab-core.js and exercised by
// tests/multi-symbol-refresh-race-audit.test.js.
//
// WHAT THIS FOUND: closeAutoTrade() and closePartial() (assets/fno-lab-
// core.js) are both real `async function`s with a genuine
// `await journalAdd(entry)` BEFORE they clear/mutate the single-slot
// STORAGE.autoTrades open-position record and append to
// STORAGE.autoTrades+'_history'. Both are invoked from
// renderOpenTrades(), itself run synchronously inside every
// refreshBrain() call. refreshBrain() has multiple real call sites that
// are NOT mutually exclusive with each other (the Autonomous Mode
// setInterval loop, the manual Refresh/Save Daily buttons, the symbol
// dropdown's own change handler) - if a second refreshBrain() call
// reaches renderOpenTrades() for the SAME still-open position while an
// earlier closeAutoTrade()/closePartial() call is still awaiting
// journalAdd(), STORAGE.autoTrades has not been cleared/updated yet, so
// the same exit condition is still true and the second call would
// independently call closeAutoTrade()/closePartial() AGAIN for the same
// real position - a genuine duplicate journal entry, duplicate P&L
// record, and (for a full close) a duplicate fno_close_position POST to
// the server for one real trade.
//
// FIX: a single module-level in-progress flag, `fnoAutoTradeCloseInProgress`
// (assets/fno-lab-core.js, declared alongside fnoRefreshGeneration at the
// top of the file) - same fix shape as autonomous-driver.js's own
// `cycleInProgress` guard. A closeAutoTrade()/closePartial() call that
// finds one already in flight returns immediately, before doing any
// work, so there is no window left for the two calls' real awaits to
// interleave.
//
// This test drives the REAL closeAutoTrade() (extracted from
// assets/fno-lab-core.js by its own real function boundaries, the same
// way every other audit test in this directory extracts real functions)
// through a simulated double-invocation - the exact interleaving that
// reproduces the bug - using a controllable network mock so the FIRST
// call's journalAdd() genuinely has not resolved before the SECOND call
// starts.
//
// Run with: node tests/auto-trade-close-reentrancy-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

// ---------------------------------------------------------------
// Minimal DOM/localStorage/window stub, same pattern as every other
// audit test in this directory.
// ---------------------------------------------------------------
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

// isLoggedIn: true, and serverPositionId set, so journalAdd() and the
// server-side close POST both genuinely go through a real, controllable
// fetch() - the real async gap this test needs to widen and interleave
// two calls inside.
global.window = { FNO_AJAX: { url: 'http://test.invalid/wp-admin/admin-ajax.php', nonce: 'n', isLoggedIn: true } };

// Controllable fetch: every call gets its own deferred promise, so the
// test can resolve them in a deliberately chosen order to reproduce
// "call 1 is still in flight when call 2 starts".
let fetchCalls = [];
global.fetch = (url) => {
  let resolveFn;
  const p = new Promise((resolve) => { resolveFn = resolve; });
  fetchCalls.push({ url, resolveFn });
  return p.then(() => ({ json: () => Promise.resolve({ success: true, data: {} }) }));
};

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const renderStart = coreSource.indexOf('\nfunction render(){');
if (renderStart === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js.');
  process.exit(1);
}
const baseSlice = coreSource.slice(0, renderStart);

function extractFn(startMarker, endMarker) {
  const start = coreSource.indexOf(startMarker);
  if (start === -1) { console.error(`FATAL: could not locate "${startMarker}"`); process.exit(1); }
  const end = coreSource.indexOf(endMarker, start);
  if (end === -1) { console.error(`FATAL: could not locate closing marker after "${startMarker}"`); process.exit(1); }
  return coreSource.slice(start, end + endMarker.length)
    .split('\n').map(l => l.startsWith('  ') ? l.slice(2) : l).join('\n');
}

const closePartialSrc = extractFn(
  'async function closePartial(open, exitLeg, partialQty, sym) {',
  '} finally { fnoAutoTradeCloseInProgress = false; }\n  }'
);
const closeAutoTradeSrc = extractFn(
  'async function closeAutoTrade(open, exitLeg, exitReason, sym) {',
  '} finally { fnoAutoTradeCloseInProgress = false; }\n  }'
);

// curCtx/mode/loadPaperAccount/loadTradeLedger are real render()-scope
// variables/functions closeAutoTrade/closePartial genuinely depend on -
// stubbed here exactly like the other extraction tests in this
// directory stub render()-scope dependencies that don't affect the
// real property under test.
eval(
  baseSlice + '\n' +
  'let curCtx = null; let mode = "paper";\n' +
  'function loadPaperAccount(){} function loadTradeLedger(){}\n' +
  closePartialSrc + '\n' +
  closeAutoTradeSrc + '\n' +
  'function __getCloseInProgress(){ return fnoAutoTradeCloseInProgress; }\n' +
  'function __resetCloseInProgress(){ fnoAutoTradeCloseInProgress = false; }\n' +
  // STORAGE/save/load/loadObj are `const`/function declarations inside
  // this eval's own block scope (const does not leak out of even a
  // direct eval in sloppy mode) - explicitly exposed so the rest of
  // this test file (outside the eval) can drive/inspect them.
  'global.STORAGE = STORAGE; global.save = save; global.load = load; global.loadObj = loadObj; global.closeAutoTrade = closeAutoTrade; global.closePartial = closePartial;'
);

function freshOpen() {
  return {
    id: 1, serverPositionId: 555, strike: 25000, optionType: 'CE',
    entryPrice: 100, qty: 50, target: 130, sl: 80,
    openedAt: Date.now(), tradingType: 'intraday',
    executionMode: 'theoretical', fillIsRealistic: true,
  };
}
const exitLeg = { lastPrice: 130, impliedVolatility: 18 };

// ---------------------------------------------------------------
// (a) Two overlapping closeAutoTrade() calls for the SAME still-open
// position: call 1 starts, awaits journalAdd()'s fetch (deliberately
// held open); call 2 starts before call 1's fetch resolves. Only ONE
// should ever actually run to completion - the guard must make the
// second call a genuine, immediate no-op.
// ---------------------------------------------------------------
async function testA() {
  localStorage.clear();
  fetchCalls = [];
  __resetCloseInProgress();
  save(STORAGE.autoTrades, freshOpen());

  const open1 = freshOpen();
  const p1 = closeAutoTrade(open1, exitLeg, 'target', 'NIFTY');
  // call 1 is now genuinely mid-await (its journalAdd() fetch is
  // outstanding, not yet resolved) - this is the exact real window the
  // bug lived in.
  check(fetchCalls.length === 1, 'call 1 genuinely reached its await point (1 outstanding fetch) before call 2 starts');
  check(__getCloseInProgress() === true, 'the in-progress guard is set while call 1 is still awaiting journalAdd()');

  // call 2: same still-open position (STORAGE.autoTrades not cleared
  // yet, since call 1 has not resolved) - reproduces renderOpenTrades()
  // re-evaluating the same exit condition on the very next tick.
  const open2 = loadObj(STORAGE.autoTrades);
  const p2 = closeAutoTrade(open2, exitLeg, 'target', 'NIFTY');

  check(fetchCalls.length === 1, 'call 2 returned immediately without ever calling fetch() (the guard fired, not a second real journalAdd)');

  // now let call 1's fetch resolve and let both promises settle.
  fetchCalls[0].resolveFn();
  await p1;
  await p2;

  const history = load(STORAGE.autoTrades + '_history');
  check(history.length === 1, `exactly ONE closed-trade history entry was written, not two (got ${history.length})`);
  check(fetchCalls.length === 2, `exactly TWO real network calls total (journalAdd's fetch + the fno_close_position POST from the ONE call that actually ran), not four (got ${fetchCalls.length})`);
  check(__getCloseInProgress() === false, 'the guard is correctly released after the real close completes');
  const finalOpen = loadObj(STORAGE.autoTrades);
  check(!finalOpen.id, 'the open position was cleared exactly once, by the one call that actually ran');
}

// ---------------------------------------------------------------
// (b) A closeAutoTrade() call that starts AFTER a previous one has
// fully finished (guard released) is NOT blocked - the guard only
// blocks genuine overlap, never a legitimate later real close (e.g. the
// next trade opened after the first one closed).
// ---------------------------------------------------------------
async function testB() {
  localStorage.clear();
  fetchCalls = [];
  __resetCloseInProgress();
  save(STORAGE.autoTrades, freshOpen());
  const open1 = freshOpen();
  const p1 = closeAutoTrade(open1, exitLeg, 'target', 'NIFTY');
  fetchCalls[0].resolveFn();
  await p1;
  fetchCalls[1] && fetchCalls[1].resolveFn();
  // drain microtasks so the server-close POST (fire-and-forget) settles
  await new Promise(r => setTimeout(r, 0));

  check(__getCloseInProgress() === false, 'guard correctly released once call 1 genuinely finished');

  // A second, GENUINELY new position opens and closes later - must not
  // be silently swallowed by a stuck guard.
  save(STORAGE.autoTrades, freshOpen());
  const open2 = freshOpen();
  const beforeCalls = fetchCalls.length;
  const p2 = closeAutoTrade(open2, exitLeg, 'sl', 'BANKNIFTY');
  check(fetchCalls.length === beforeCalls + 1, 'a genuinely later, non-overlapping close is NOT blocked by the guard (real fetch was made)');
  fetchCalls[fetchCalls.length - 1].resolveFn();
  await p2;
  const history = load(STORAGE.autoTrades + '_history');
  check(history.length === 2, `both the first AND the later, non-overlapping close were journaled (got ${history.length})`);
}

(async () => {
  await testA();
  await testB();
  console.log(`\n${passed} passed, ${failed} failed (auto-trade-close-reentrancy-audit.test.js)`);
  process.exit(failed > 0 ? 1 : 0);
})();
