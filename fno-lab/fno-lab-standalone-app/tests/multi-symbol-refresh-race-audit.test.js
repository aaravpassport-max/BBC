// REAL, STANDALONE, IMMEDIATELY-RUNNABLE test - multi-symbol state
// isolation audit pass (module-level/shared JS state review).
//
// WHAT THIS FOUND: refreshBrain() (the UI's per-symbol refresh cycle)
// captures `sym` from <select id="sym"> at call start, then does
// several real `await`s (the big Promise.all of NSE fetches, a journal
// sync, a paper-account fetch) BEFORE writing any results to the DOM.
// Network timing does not guarantee call order: if the user switches
// symbols (or the autonomous-mode poll interval fires again) while an
// earlier refreshBrain() call is still in flight, that OLDER call can
// resolve AFTER a NEWER call for the now-selected symbol - and would
// silently overwrite the newer, currently-relevant results in the DOM
// with stale, wrong-symbol ones (e.g. NIFTY's score painted over
// BANKNIFTY's, while the dropdown still reads BANKNIFTY). This is
// exactly the same failure SHAPE as the module-level-state cross-
// contamination this audit pass was looking for, just surfacing
// through response ordering rather than a shared mutable variable
// (this file's actual module-level mutable state - `fnoSettings`,
// `fnoRefreshGeneration` - was independently confirmed genuinely
// symbol-agnostic/safe to share; ema() and every per-tick computation
// take their state as arguments/return values, never a closure-
// captured module var; see the audit's chat-log report for the full
// inventory).
//
// FIX: a single module-level monotonic counter, `fnoRefreshGeneration`
// (assets/fno-lab-core.js, top of file) - deliberately the ONLY
// module-level mutable state refreshBrain() touches. It holds no
// per-symbol data itself (chart/oc/sym stay function-local per call,
// so concurrent calls never share or corrupt each other's actual
// data) - it is purely a "which call is newest" ticket, incremented
// once per refreshBrain() call and checked after every await; a call
// that finds itself superseded bails out before writing anything to
// the DOM.
//
// This test drives the REAL refreshBrain() (extracted from
// assets/fno-lab-core.js the same way every other audit test in this
// directory extracts real functions) through a simulated symbol
// switch mid-flight, using controllable network mocks so the OLDER
// call's fetches resolve strictly AFTER the NEWER call has already
// finished and rendered - the exact interleaving that reproduces the
// bug.
//
// Run with: node tests/multi-symbol-refresh-race-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

// ---------------------------------------------------------------
// Minimal DOM stub: auto-vivifying elements (any id gets a plain
// settable object first access), a handful of ids pre-seeded with the
// real values refreshBrain() reads to drive its synchronous
// computation (strike/option-type/manual-override/etc). Real getters
// so we can inspect exactly what refreshBrain() wrote, per test.
// ---------------------------------------------------------------
const elements = new Map();
function el(id) {
  if (!elements.has(id)) {
    elements.set(id, { value: '', textContent: '', innerHTML: '', checked: false, style: {}, classList: { add(){}, remove(){}, contains(){return false;} } });
  }
  return elements.get(id);
}
el('sym').value = 'NIFTY';
el('strike').value = '25000';
el('optType').value = 'CE';
el('optPrice').value = '100';
el('daysExp').value = '2';
el('iv').value = '18';
el('lotSize').value = '50';
el('manualPriceOverride').checked = false;

function makeFakeDomNode() {
  return { style: {}, classList: { add(){}, remove(){}, contains(){return false;}, toggle(){} }, appendChild(){}, addEventListener(){}, setAttribute(){}, dataset: {}, querySelectorAll: () => [] };
}
global.document = {
  getElementById: (id) => el(id),
  createElement: () => makeFakeDomNode(),
  documentElement: makeFakeDomNode(),
  body: makeFakeDomNode(),
  dispatchEvent: () => {},
  addEventListener: () => {},
  querySelectorAll: () => [],
};
// refreshBrain() fires a few genuinely-fire-and-forget side-effect
// calls (logRejectionIfDue, generateAiNarrativeIfDue,
// evaluateHypothesesIfDue().then(loadHypothesisStats), etc) that are
// real functions declared as SIBLINGS of refreshBrain inside render()
// (not ancestors it needs, so not part of what this test's guard
// covers) - stubbed to real no-ops so their absence here doesn't
// throw. None of them run before the totalScore checkpoint this test
// asserts on (confirmed by iterating this test until stubbing them
// stopped changing which checkpoint was reached).
['renderOptionChainTable', 'logFailureEventsIfDue', 'loadLearningObjectiveProgress', 'loadPortfolioTracker', 'loadHypothesisStats', 'logRejectionIfDue', 'generateAiNarrativeIfDue', 'generateAndLogHypothesisIfDue', 'updateRegimeAdjustedConfidenceIfDue'].forEach(name => { global[name] = function () { return undefined; }; });
global.dailyState = {};
process.on('unhandledRejection', () => {}); // fire-and-forget stub calls above can still reject; irrelevant to what this test checks
global.window = { FNO_AJAX: { url: 'http://test.invalid/wp-admin/admin-ajax.php', nonce: 'n', isLoggedIn: false } };
global.localStorage = (function () {
  let store = {};
  return {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => { store[k] = String(v); },
    removeItem: (k) => { delete store[k]; },
    clear: () => { store = {}; },
  };
})();
global.CustomEvent = function CustomEvent(type, opts) { this.type = type; this.detail = opts && opts.detail; };
// Anything reaching real network (syncServerJournal, fetchPaperAccount,
// etc - all correctly try/catch-wrapped and degrade to []/null on
// failure per their own TRACEs) gets a clean rejection, never a hang.
global.fetch = () => Promise.reject(new Error('no network in this test'));

// fno-lab-core.js's real code path (buildGreeksSnapshot, called from
// inside refreshBrain) depends on assets/greeks-engine.js's real
// exports being present as globals - same real dependency
// tests/greeks-engine.test.js already exercises directly.
const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
Object.assign(global, ge);

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js.');
  process.exit(1);
}
// Every helper refreshBrain() depends on (fetchChart, parseCandles,
// evaluateBrain, calculateDecay, computeOperatorIntel, syncServerJournal,
// fetchPaperAccount, fnoRefreshGeneration, ...) is declared BEFORE
// render() and is extracted here exactly like every other audit test
// in this directory extracts its real functions.
//
// refreshBrain() itself is declared INSIDE render() (real WordPress
// standalone-app.php DOM setup, not runnable headless here), so it is
// extracted separately by its own real function boundaries and
// appended below, re-indented to top level, so it can be called
// directly - same real source text, just reachable without executing
// render()'s DOM setup. Both slices are eval'd in ONE call (not two)
// so refreshBrain's real references to the module-level
// `fnoRefreshGeneration` (a `let`, which - unlike a function
// declaration - does NOT leak out of a direct eval's own lexical
// scope into a separate eval call) resolve to the SAME real counter
// this test also inspects.
const RB_START_MARKER = '  async function refreshBrain() {';
const rbStart = coreSource.indexOf(RB_START_MARKER);
if (rbStart === -1) {
  console.error('FATAL: could not locate "async function refreshBrain() {" in fno-lab-core.js.');
  process.exit(1);
}
const rbEnd = coreSource.indexOf('\n  }', rbStart);
if (rbEnd === -1) {
  console.error('FATAL: could not locate the closing "  }" of refreshBrain() in fno-lab-core.js.');
  process.exit(1);
}
// Strip the leading 2-space nesting indent so it declares a real,
// directly-callable top-level `async function refreshBrain(){...}` -
// no logic altered, purely re-indentation of the extracted text.
const rbSource = coreSource.slice(rbStart, rbEnd + 4).split('\n').map(l => l.startsWith('  ') ? l.slice(2) : l).join('\n');
// Also expose the real counter to the test via a function declaration
// (which DOES leak out of direct eval, same as refreshBrain itself) -
// no new state, just a getter closing over the real `let` declared in
// this same eval call.
eval(coreSource.slice(0, end) + '\n' + rbSource + '\nfunction __getFnoRefreshGeneration(){ return fnoRefreshGeneration; }');

check(typeof __getFnoRefreshGeneration() === 'number', 'fnoRefreshGeneration exists as the real module-level refresh-generation counter');
check(typeof refreshBrain === 'function', 'refreshBrain extracted successfully from the real source');

// ---------------------------------------------------------------
// Gated network layer: every fetch* function refreshBrain() calls
// inside its opening Promise.all is overridden to wait on whichever
// gate is "active" at the SYNCHRONOUS moment it's invoked (captured
// via closure before the first await inside each override, exactly
// mirroring real network-call timing - the call is issued
// synchronously, the response arrives whenever the gate says it does).
// ---------------------------------------------------------------
function makeGate() {
  let resolve;
  const promise = new Promise((r) => { resolve = r; });
  return { promise, resolve };
}
let activeGate = null; // set by the test immediately before each refreshBrain() call

function candlesFor(closePrice) {
  return { grapthData: [[1000, closePrice], [2000, closePrice], [3000, closePrice]] };
}
function ocFor(spot) {
  return { records: { data: [], underlyingValue: spot } };
}

function gatedChart(sym) { const g = activeGate; return g.promise.then(() => candlesFor(sym === 'NIFTY' ? 25000 : sym === 'BANKNIFTY' ? 52000 : 23000)); }
function gatedOC(sym) { const g = activeGate; return g.promise.then(() => ocFor(sym === 'NIFTY' ? 25000 : sym === 'BANKNIFTY' ? 52000 : 23000)); }
function gatedNull() { const g = activeGate; return g.promise.then(() => null); }
function gatedEmptyObj() { const g = activeGate; return g.promise.then(() => ({})); }

fetchChart = gatedChart;
fetchOC = gatedOC;
fetchStatus = gatedEmptyObj;
fetchFutures = gatedNull;
fetchNewsSentiment = gatedNull;
fetchMarketDepth = gatedNull;
fetchParticipantOI = gatedNull;
fetchASMGSM = gatedNull;
fetchMicrostructure = gatedNull;
fetchEventCalendar = gatedNull;
fetchResultsCalendar = gatedNull;
fetchCorporateActions = gatedNull;
fetchMarketBreadth = gatedNull;

// ---------------------------------------------------------------
// THE RACE: call A starts for NIFTY (its gate stays closed - fetches
// hang). While it's in flight, the user switches the dropdown to
// BANKNIFTY and a NEW refreshBrain() call (B) starts, with its gate
// already resolved - B completes fully first. Only THEN do we open
// A's gate and let its now-stale fetches resolve.
// ---------------------------------------------------------------
async function runRaceTest() {
  const genBefore = __getFnoRefreshGeneration();

  const gateA = makeGate();
  activeGate = gateA;
  el('sym').value = 'NIFTY';
  const callA = refreshBrain(); // synchronously captures myRefreshGen, issues gated (hung) fetches for NIFTY

  const gateB = makeGate();
  gateB.resolve(); // B's network layer resolves immediately
  activeGate = gateB;
  el('sym').value = 'BANKNIFTY'; // simulated user symbol switch mid-flight
  const callB = refreshBrain();

  await callB; // newer call finishes completely first
  const totalScoreAfterB = el('totalScore').textContent;
  const brainLogAfterB = el('brainLog').textContent;

  check(__getFnoRefreshGeneration() === genBefore + 2, 'fnoRefreshGeneration incremented exactly once per refreshBrain() call (real counter, not per-await)');
  check(totalScoreAfterB !== '', 'call B (BANKNIFTY, newer) reached the real DOM-writing stage and set totalScore');

  // Now let the OLDER, now-stale NIFTY call's fetches resolve.
  gateA.resolve();
  await callA;

  check(el('totalScore').textContent === totalScoreAfterB, 'REGRESSION GUARD: the stale NIFTY call, resolving AFTER BANKNIFTY already rendered, did NOT overwrite totalScore');
  check(el('brainLog').textContent === brainLogAfterB, 'REGRESSION GUARD: the stale NIFTY call did not touch brainLog either (bailed before any post-fetch DOM write, not just totalScore)');
  check(el('sym').value === 'BANKNIFTY', 'the symbol dropdown itself was never touched by refreshBrain() (sanity: the fix does not depend on refreshBrain resetting the select)');
}

// ---------------------------------------------------------------
// Baseline (non-race) sanity: a single, uncontended refreshBrain()
// call must still reach the DOM-writing stage normally - the new
// guard must not itself introduce a false-positive "stale" bail on
// the happy path.
// ---------------------------------------------------------------
async function runBaselineTest() {
  elements.clear();
  el('sym').value = 'FINNIFTY';
  el('strike').value = '23000';
  el('optType').value = 'CE';
  el('optPrice').value = '100';
  el('daysExp').value = '2';
  el('iv').value = '18';
  el('lotSize').value = '50';
  el('manualPriceOverride').checked = false;

  const gate = makeGate();
  gate.resolve();
  activeGate = gate;
  await refreshBrain();

  check(el('totalScore').textContent !== '', 'baseline: a single, uncontended refreshBrain() call still reaches the DOM-writing stage (guard has no false-positive on the happy path)');
}

(async () => {
  await runBaselineTest();
  await runRaceTest();

  console.log(`\n${passed} passed, ${failed} failed`);
  process.exit(failed > 0 ? 1 : 0);
})();
