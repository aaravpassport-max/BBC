// REAL, STANDALONE test verifying the §2.3 fix from
// docs/SCALPING_MULTI_CONDITION_AUDIT_2026-09-02.md: a blocked/rejected
// real trade-open attempt previously left NOTHING persisted anywhere -
// tryOpenAutoTradePosition() returned {opened:false, failureModeResult}
// with no write to any store, unlike an OPENED trade (which gets
// failureModeCheck/tradeTypeWeighting persisted onto its entrySnapshot).
//
// This drives the REAL logBlockedTradeAttempt/loadBlockedTradeAttempts/
// renderBlockedAttemptsHistory functions (same eval-slice extraction
// pattern as every other audit test in this directory), plus a static
// source-string lock confirming the real block site inside
// tryOpenAutoTradePosition() genuinely calls logBlockedTradeAttempt -
// not a reimplementation, and not a wiring claim taken on faith.
//
// Run with: node tests/blocked-trade-persistence-fix.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

// Real, minimal localStorage stub (same pattern used by
// ai-narrative-cross-symbol-race-audit.test.js / auto-trade-close-reentrancy-audit.test.js).
global.localStorage = (function () {
  let store = {};
  return {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => { store[k] = String(v); },
    removeItem: (k) => { delete store[k]; },
    clear: () => { store = {}; },
  };
})();

// Real, minimal DOM stub - just enough for
// document.getElementById('blockedAttemptsHistoryBox') to work, same
// pattern this repo's other DOM-touching audit tests already use.
const domElements = {};
function makeEl(id) {
  return { id, innerHTML: '' };
}
global.document = {
  getElementById: (id) => {
    if (!domElements[id]) domElements[id] = makeEl(id);
    return domElements[id];
  },
};
global.window = { document: global.document };

global.fnoSettings = { get: () => ({ tradingTypes: { intraday: true, swing: false, scalping: false } }) };
global.window.FNO_FACTORS_CATALOG = null;

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) { console.error('FATAL: render() boundary marker not found'); process.exit(1); }
eval(coreSource.slice(0, end));

// ---------------------------------------------------------------------
// Static wiring-audit lock: the real block site inside
// tryOpenAutoTradePosition() genuinely calls logBlockedTradeAttempt AND
// renderBlockedAttemptsHistory, right where fmResult.finalAction is
// 'block'/'reject' - the exact real site the audit report's §2.3
// finding pointed at (fno-lab-core.js, "return { opened: false,
// failureModeResult: fmResult };").
// ---------------------------------------------------------------------
{
  const fnStart = coreSource.indexOf('function tryOpenAutoTradePosition(params, reportFn) {');
  assert.ok(fnStart > -1, 'tryOpenAutoTradePosition must still exist under this exact name in the current source');
  const returnIdx = coreSource.indexOf('return { opened: false, failureModeResult: fmResult };', fnStart);
  assert.ok(returnIdx > -1, 'the real block/reject return site must still exist verbatim');
  const windowBefore = coreSource.slice(Math.max(fnStart, returnIdx - 700), returnIdx);
  check(windowBefore.includes('logBlockedTradeAttempt('), 'STATIC LOCK: the real block/reject return site genuinely calls logBlockedTradeAttempt() before returning opened:false - not merely a report message, a real persisted write');
  check(windowBefore.includes('renderBlockedAttemptsHistory()'), 'STATIC LOCK: the real block/reject return site genuinely calls renderBlockedAttemptsHistory() so the persisted write is also reflected in the UI the same refresh, not only on next page load');
  check(windowBefore.includes('strike, optionType, tradingType:') || (windowBefore.includes('strike') && windowBefore.includes('optionType') && windowBefore.includes('finalAction: fmResult.finalAction')), 'STATIC LOCK: the logged entry genuinely carries the real strike/optionType/finalAction for this specific blocked leg, not a generic flag');
}

// ---------------------------------------------------------------------
// Functional: logBlockedTradeAttempt actually persists to localStorage,
// loadBlockedTradeAttempts reads it back, and the log is genuinely
// capped (never grows unbounded).
// ---------------------------------------------------------------------
{
  localStorage.clear();
  check(loadBlockedTradeAttempts().length === 0, 'loadBlockedTradeAttempts() starts empty on a fresh store (sanity)');

  const entry = logBlockedTradeAttempt({
    strike: 23200, optionType: 'CE', tradingType: 'scalping', lastPrice: 100,
    finalAction: 'block',
    triggered: [{ id: 'FM025', severity: 'critical', action: 'block', typeRelevance: null, condition: 'order rejection risk', reason: 'wide spread + qty>resting' }],
    summary: 'blocked: order rejection risk',
  });
  check(!!entry.id && !!entry.timestamp, 'logBlockedTradeAttempt returns the stored entry with a generated id/timestamp');

  const list = loadBlockedTradeAttempts();
  check(list.length === 1, 'the entry genuinely persisted to localStorage and is readable back via loadBlockedTradeAttempts()');
  check(list[0].strike === 23200 && list[0].optionType === 'CE' && list[0].finalAction === 'block', 'the persisted entry genuinely carries the real strike/optionType/finalAction passed in, unchanged');
  check(Array.isArray(list[0].triggered) && list[0].triggered[0].id === 'FM025', 'the persisted entry genuinely carries the real triggered FM condition id (FM025), not just a generic block flag - a report can name exactly why');

  // Cap test: push past 200 and confirm the oldest is trimmed, not the log growing unbounded.
  for (let i = 0; i < 205; i++) {
    logBlockedTradeAttempt({ strike: 20000 + i, optionType: 'PE', tradingType: 'intraday', lastPrice: 50, finalAction: 'block', triggered: [], summary: `synthetic #${i}` });
  }
  const capped = loadBlockedTradeAttempts();
  check(capped.length === 200, `the log is genuinely capped at 200 most-recent entries (got ${capped.length}), never growing unbounded in localStorage`);
  check(capped[capped.length - 1].strike === 20000 + 204, 'the most-recently-logged entry is genuinely retained (not the trimming discarding the wrong end)');
  check(capped[0].strike !== 23200, 'the original first entry (strike 23200) was genuinely trimmed once the cap was exceeded - oldest-first eviction, not newest');
}

// ---------------------------------------------------------------------
// Functional: renderBlockedAttemptsHistory genuinely renders the real,
// persisted log into #blockedAttemptsHistoryBox - the actual UI half of
// this fix, since a persisted-but-never-displayed log is not truly a
// reviewable report.
// ---------------------------------------------------------------------
{
  localStorage.clear();
  renderBlockedAttemptsHistory();
  const boxEmpty = document.getElementById('blockedAttemptsHistoryBox');
  check(boxEmpty.innerHTML.includes('No blocked attempts recorded'), 'renderBlockedAttemptsHistory() shows the honest empty-state placeholder when the real log is genuinely empty (not a fabricated example row)');

  logBlockedTradeAttempt({ strike: 23500, optionType: 'PE', tradingType: 'scalping', lastPrice: 84, finalAction: 'block', triggered: [{ id: 'FM025' }], summary: 'blocked' });
  renderBlockedAttemptsHistory();
  const box = document.getElementById('blockedAttemptsHistoryBox');
  check(box.innerHTML.includes('23500') && box.innerHTML.includes('PE') && box.innerHTML.includes('FM025'), 'renderBlockedAttemptsHistory() genuinely renders the real, just-persisted entry (strike/optionType/triggered id), not a static/generic placeholder');
  check(!box.innerHTML.includes('No blocked attempts recorded'), 'the empty-state placeholder is genuinely replaced once a real entry exists');
}

console.log(`\n${passed} passed, ${failed} failed (blocked-trade-persistence-fix.test.js)`);
process.exit(failed > 0 ? 1 : 0);
