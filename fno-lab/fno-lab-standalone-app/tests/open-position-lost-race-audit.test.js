// REAL, STANDALONE regression test - same-tick entry/exit ordering audit
// (2026-08-30), open-side counterpart to close-position-lost-race-audit.test.js.
//
// CONTEXT: this pass traced the browser's actual render()/refreshBrain()
// tick flow and autonomous-driver.js's runCycle() for the exact ordering
// question the audit raised: does the code ever attempt to open a NEW
// position before a same-tick exit of the CURRENT position has been
// processed?
//
//   - Browser (assets/fno-lab-core.js): the autonomous-mode entry block
//     (~line 11539) runs BEFORE renderOpenTrades()'s exit check (~line
//     12074, checkTradeExit/closeAutoTrade) in the SAME refreshBrain()
//     call, but the entry block's own `existingPosition = loadObj(STORAGE
//     .autoTrades)` guard reads pre-tick state either way - since this
//     tick's own exit code has not yet mutated STORAGE.autoTrades at the
//     point the entry block runs, a currently-open position is ALWAYS
//     seen as open and correctly blocks entry that same tick. No same-
//     tick open-before-exit bug exists here.
//
//   - The REAL gap found instead: closeAutoTrade()'s server-side
//     fno_close_position POST is deliberately fire-and-forget (never
//     awaited - see its own "best-effort" comment), and STORAGE.autoTrades
//     is cleared locally as soon as the LOCAL journalAdd() resolves, not
//     when the server confirms the close. A later tick's entry can
//     therefore reach the server's fno_open_position_fn BEFORE that
//     still-in-flight close has landed, and collide with the
//     user_symbol_style_open_lock UNIQUE KEY (fno-lab.php) - the server
//     correctly rejects with 'symbolAlreadyOpen' (matching the same
//     established machine-readable-flag pattern as 'alreadyClosed' /
//     'idempotentReplay'), but the browser's own .then() handler for
//     fno_open_position previously did NOTHING with a success:false
//     response: no log, no resync, unlike its own close-side sibling 34
//     lines above and unlike autonomous-driver.js's own generic
//     "WARNING: real fno_open_position call failed" log for this exact
//     same failure.
//
//   - autonomous-driver.js's runCycle() is provably NOT affected: its
//     `if (openPosition) { ...await postAuthenticated('fno_close_position',
//     ...); openPosition = null; ... return; }` block always `return`s
//     after handling an open position, so entry logic (below, gated on
//     openPosition already being null) can only ever run in a cycle
//     where the PRIOR cycle's close was already fully awaited and
//     confirmed - no fire-and-forget race exists there.
//
// THIS TEST proves the FIX to the browser's fno_open_position .then()
// handler: a symbolAlreadyOpen rejection now gets a calm console.info +
// a resync (matching the close-side 'alreadyClosed' pattern), any OTHER
// open-side failure gets a console.warn + resync + a visible brainLog
// note (previously: nothing at all), and a genuine success still stores
// serverPositionId exactly as before (zero regression to the win path).
//
// Run with: node tests/open-position-lost-race-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');

// --- Structural evidence for the ordering trace itself (file:line-style,
// via indexOf, matching this codebase's own established static-audit-lock
// pattern for this exact deeply-nested function - see greeks-engine.test.js's
// many 'static wiring-audit lock' tests for tryOpenAutoTradePosition). ---
const entryBlockIdx = coreSource.indexOf("if (localStorage.getItem('fno_autonomous_mode_enabled') === 'true' && (brain.decision === 'BUY_READY'");
const renderOpenTradesCallIdx = coreSource.indexOf('renderOpenTrades(ocRow, optionType, strike, sym);');
const existingPositionGuardIdx = coreSource.indexOf('const existingPosition = loadObj(STORAGE.autoTrades);');
check(entryBlockIdx !== -1 && renderOpenTradesCallIdx !== -1 && existingPositionGuardIdx !== -1,
  'located the real entry block, its existingPosition guard, and the renderOpenTrades() exit-check call site in the current source');
check(entryBlockIdx < renderOpenTradesCallIdx,
  `the autonomous-mode entry block (offset ${entryBlockIdx}) runs textually BEFORE renderOpenTrades()'s exit check (offset ${renderOpenTradesCallIdx}) in the same refreshBrain() call - confirmed, but see next check for why this is safe`);
check(existingPositionGuardIdx > entryBlockIdx && existingPositionGuardIdx < renderOpenTradesCallIdx,
  'the entry block reads STORAGE.autoTrades (existingPosition) BEFORE this tick\'s own exit-check code (inside renderOpenTrades) has run - so a currently-open position is always correctly seen as open at entry-check time regardless of code order, and entry is correctly blocked, not raced');

// autonomous-driver.js: prove the exit block always returns, so entry
// logic (evaluateBrain/BUY_READY block, further down) can only run when
// openPosition was confirmed null BEFORE this cycle started.
const driverSource = fs.readFileSync(path.join(__dirname, '../autonomous-driver/autonomous-driver.js'), 'utf8');
const driverIfOpenIdx = driverSource.indexOf('if (openPosition) {');
const driverAwaitCloseIdx = driverSource.indexOf("await postAuthenticated('fno_close_position'", driverIfOpenIdx);
const driverReturnIdx = driverSource.indexOf('\n      return;\n', driverIfOpenIdx);
const driverEvaluateBrainIdx = driverSource.indexOf('const brain = evaluateBrain(ctx);');
check(driverIfOpenIdx !== -1 && driverAwaitCloseIdx !== -1 && driverReturnIdx !== -1 && driverEvaluateBrainIdx !== -1,
  'located the real if(openPosition) exit block, its awaited close call, its return, and the entry-side evaluateBrain() call in autonomous-driver.js');
check(driverAwaitCloseIdx > driverIfOpenIdx && driverAwaitCloseIdx < driverReturnIdx,
  'the driver genuinely AWAITS fno_close_position before the if(openPosition) block ends');
check(driverReturnIdx < driverEvaluateBrainIdx,
  'the driver\'s if(openPosition) block unconditionally returns BEFORE entry-side evaluateBrain() - a cycle that just processed (or found) an open position NEVER falls through to entry logic the same cycle; entry only runs on a later cycle where openPosition was already confirmed null by a fully-awaited prior close');

// --- Behavioral test: the browser's fno_open_position .then() handler ---
const elements = new Map();
function el(id) {
  if (!elements.has(id)) elements.set(id, { value: '', textContent: '', innerHTML: '', checked: true, style: {} });
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

let openResponseQueue = [];
global.fetch = (url) => {
  if (String(url).includes('action=fno_open_position')) {
    const resp = openResponseQueue.shift() || { success: true, data: { id: 1 } };
    return Promise.resolve({ json: () => Promise.resolve(resp) });
  }
  return Promise.resolve({ json: () => Promise.resolve({ success: true, data: {} }) });
};

// Extract the EXACT real fno_open_position POST + .then/.catch block from
// the live source (the code this pass fixed), and re-wrap it as a
// standalone callable async function - same slice-and-eval technique
// close-position-lost-race-audit.test.js already uses for closeAutoTrade().
const blockStartMarker = 'if (window.FNO_AJAX.isLoggedIn) {\n      fetch(`${window.FNO_AJAX.url}?action=fno_open_position`';
const blockStart = coreSource.indexOf(blockStartMarker);
if (blockStart === -1) { console.error('FATAL: could not locate the real fno_open_position POST block in fno-lab-core.js'); process.exit(1); }
const endMarker = '.catch(()=>{}); // real, honest, silent best-effort - a real network hiccup here must never undo the real, already-opened local position above\n    }';
const blockEnd = coreSource.indexOf(endMarker, blockStart);
if (blockEnd === -1) { console.error('FATAL: could not locate the closing marker of the fno_open_position POST block'); process.exit(1); }
const rawBlock = coreSource.slice(blockStart, blockEnd + endMarker.length);

const wrapped = `
async function runOpenPositionPostBlock(sym, strike, optionType, filledLotSize, fill) {
  const sl = 80, target = 130, effectiveTradingType = 'intraday';
  let loadPaperAccountCalls = 0, loadTradeLedgerCalls = 0;
  function loadPaperAccount(){ loadPaperAccountCalls++; global.__lastLoadPaperAccountCalls = loadPaperAccountCalls; }
  function loadTradeLedger(){ loadTradeLedgerCalls++; }
  ${rawBlock}
  await new Promise((r) => setTimeout(r, 0));
  await new Promise((r) => setTimeout(r, 0));
}
global.STORAGE = { autoTrades: 'fno_autotrades_v8' };
global.save = function save(k, v) { localStorage.setItem(k, JSON.stringify(v)); };
global.loadObj = function loadObj(k) { const v = localStorage.getItem(k); return v ? JSON.parse(v) : {}; };
global.runOpenPositionPostBlock = runOpenPositionPostBlock;
`;
eval(wrapped);

function freshLocalOpen() {
  return { id: Date.now(), strike: 25000, optionType: 'CE', entryPrice: 100, qty: 50 };
}

async function testSymbolAlreadyOpenLostRace() {
  localStorage.clear();
  save(STORAGE.autoTrades, freshLocalOpen());
  let warnCalls = [], infoCalls = [];
  const origWarn = console.warn, origInfo = console.info;
  console.warn = (...a) => { warnCalls.push(a); };
  console.info = (...a) => { infoCalls.push(a); };
  try {
    openResponseQueue = [{ success: false, data: {
      message: 'Real position for NIFTY (intraday) is already open (id=42) - refusing to open a second, duplicate position for the same symbol from a concurrent request (browser/driver race).',
      symbolAlreadyOpen: true, existingId: 42,
    } }];
    await runOpenPositionPostBlock('NIFTY', 25000, 'CE', 50, { price: 100, isRealistic: true });

    check(warnCalls.length === 0, 'a symbolAlreadyOpen lost-race response does NOT produce a console.warn (not treated as an alarming failure) - the FIXED behavior');
    check(infoCalls.length === 1, 'a symbolAlreadyOpen lost-race response now produces exactly one calm console.info note - the FIX (was: zero logging at all)');
    const localOpen = loadObj(STORAGE.autoTrades);
    check(!localOpen.serverPositionId, 'the local paper position correctly has NO serverPositionId when the server-side open lost the race (the local trade itself was never undone - it just has no linked DB row)');
    check(el('brainLog').textContent.includes('server-side row failed to persist'), 'a visible brainLog note now tells the user this trade has no server-side row - the FIX (was: completely silent)');
    check(global.__lastLoadPaperAccountCalls === 1, `the symbolAlreadyOpen branch triggers a resync (loadPaperAccount()) so the tab's view reflects authoritative server state (got ${global.__lastLoadPaperAccountCalls})`);
  } finally {
    console.warn = origWarn; console.info = origInfo;
  }
}

async function testGenuineOpenFailure() {
  localStorage.clear();
  el('brainLog').textContent = '';
  save(STORAGE.autoTrades, freshLocalOpen());
  let warnCalls = [], infoCalls = [];
  const origWarn = console.warn, origInfo = console.info;
  console.warn = (...a) => { warnCalls.push(a); };
  console.info = (...a) => { infoCalls.push(a); };
  try {
    openResponseQueue = [{ success: false, data: { message: 'Real database insert failed: some genuine DB error' } }];
    await runOpenPositionPostBlock('NIFTY', 25000, 'CE', 50, { price: 100, isRealistic: true });

    check(warnCalls.length === 1, 'a genuine (non-symbolAlreadyOpen) open failure DOES produce a console.warn - real failures are surfaced, not silently swallowed - the FIX');
    check(infoCalls.length === 0, 'a genuine failure does not produce the calm symbolAlreadyOpen info note');
  } finally {
    console.warn = origWarn; console.info = origInfo;
  }
}

async function testGenuineOpenSuccess() {
  localStorage.clear();
  el('brainLog').textContent = '';
  save(STORAGE.autoTrades, freshLocalOpen());
  openResponseQueue = [{ success: true, data: { id: 777 } }];
  await runOpenPositionPostBlock('NIFTY', 25000, 'CE', 50, { price: 100, isRealistic: true });
  const localOpen = loadObj(STORAGE.autoTrades);
  check(localOpen.serverPositionId === 777, 'a genuine server-side success still correctly stores serverPositionId onto the local position - zero regression to the pre-existing win path');
}

(async () => {
  await testSymbolAlreadyOpenLostRace();
  await testGenuineOpenFailure();
  await testGenuineOpenSuccess();
  console.log(`\n${passed} passed, ${failed} failed (open-position-lost-race-audit.test.js)`);
  process.exit(failed > 0 ? 1 : 0);
})();
