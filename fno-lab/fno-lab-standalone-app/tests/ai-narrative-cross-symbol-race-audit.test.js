// REAL, STANDALONE test - AI-narrative cross-symbol race audit, a
// direct follow-up to the fnoRefreshGeneration finding documented at
// the top of assets/fno-lab-core.js and exercised by
// tests/multi-symbol-refresh-race-audit.test.js.
//
// WHAT THIS FOUND: generateAiNarrativeIfDue() (assets/fno-lab-core.js)
// is fired-and-forgotten (never awaited) from refreshBrain(), and
// itself does two real awaits (fetch + .json()) before writing the
// OpenAI-returned narrative string into the ONE shared #aiNarrativeBox
// element. Its own throttle key is scoped per-symbol
// (`fno_last_ai_narrative_${sym}`), so it does NOT stop two DIFFERENT
// symbols' calls from being in flight at the same time - and unlike
// refreshBrain()'s main panels, this box was never covered by the
// fnoRefreshGeneration / isStaleRefresh() guard at all. If the user
// switches symbols while an earlier symbol's narrative call is still in
// flight, an OLDER, slower call for the PREVIOUSLY-selected symbol can
// resolve AFTER a NEWER call for the now-selected symbol and silently
// paint the wrong symbol's AI commentary under the current, different
// decision panel.
//
// FIX: generateAiNarrativeIfDue() now takes an `isStaleRefresh`
// callback (the SAME real staleness-check closure refreshBrain()
// already builds around the existing `fnoRefreshGeneration` counter -
// no new, parallel mechanism invented) and re-checks it immediately
// after its own awaits, before writing to #aiNarrativeBox; a call
// superseded by a newer refreshBrain() call bails out silently, exactly
// like every other stale-refresh guard in this file.
//
// This test drives the REAL generateAiNarrativeIfDue() (extracted from
// assets/fno-lab-core.js by its own real function boundaries) through a
// simulated symbol switch mid-flight: call 1 (NIFTY) starts and is held
// mid-fetch; a newer generation ticket is minted (simulating a second
// refreshBrain() call for BANKNIFTY starting and finishing while call 1
// is still in flight); call 1's fetch is then allowed to resolve.
//
// Run with: node tests/ai-narrative-cross-symbol-race-audit.test.js

const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const boxEl = { innerHTML: '' };
global.document = { getElementById: (id) => (id === 'aiNarrativeBox' ? boxEl : { textContent: '', innerHTML: '' }) };
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

let fetchCalls = [];
global.fetch = (url, opts) => {
  let resolveFn;
  const p = new Promise((resolve) => { resolveFn = resolve; });
  const call = { url, body: opts && opts.body, resolveFn };
  fetchCalls.push(call);
  return p;
};

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const renderStart = coreSource.indexOf('\nfunction render(){');
if (renderStart === -1) { console.error('FATAL: could not locate "function render(){" boundary.'); process.exit(1); }
const baseSlice = coreSource.slice(0, renderStart);

const START = 'async function generateAiNarrativeIfDue(brain, sym, isStaleRefresh) {';
const start = coreSource.indexOf(START);
if (start === -1) { console.error('FATAL: could not locate generateAiNarrativeIfDue.'); process.exit(1); }
const end = coreSource.indexOf('\n}\n', start);
if (end === -1) { console.error('FATAL: could not locate closing "}" of generateAiNarrativeIfDue.'); process.exit(1); }
const fnSrc = coreSource.slice(start, end + 2);

// escapeHtml is declared before generateAiNarrativeIfDue in the base
// slice already, so it's included for free.
eval(baseSlice + '\n' + fnSrc + '\nglobal.generateAiNarrativeIfDue = generateAiNarrativeIfDue;');

function fakeBrain() { return { decision: 'BUY', confidence: 'HIGH', reason: 'test', results: [] }; }

async function testStaleCallIsDiscarded() {
  localStorage.clear();
  fetchCalls = [];
  boxEl.innerHTML = '';

  let gen = 1;
  const isStale = (myGen) => () => myGen !== gen;

  // call 1: NIFTY, generation 1.
  const myGen1 = gen; // 1
  const p1 = generateAiNarrativeIfDue(fakeBrain(), 'NIFTY', isStale(myGen1));
  check(fetchCalls.length === 1, 'call 1 (NIFTY) genuinely reached its fetch await point');

  // Simulate the user switching symbols: a NEWER refreshBrain()
  // generation starts and FINISHES for BANKNIFTY while call 1 is still
  // in flight - bumps the shared ticket exactly like refreshBrain()'s
  // real `++fnoRefreshGeneration` does.
  gen = 2;
  const myGen2 = gen;
  const p2 = generateAiNarrativeIfDue(fakeBrain(), 'BANKNIFTY', isStale(myGen2));
  check(fetchCalls.length === 2, 'call 2 (BANKNIFTY, newer generation) also reached its fetch await point');

  // Newer call's response arrives first (the common case, but not the
  // one that mattered for the bug) and finishes.
  fetchCalls[1].resolveFn({ json: () => Promise.resolve({ success: true, data: { narrative: 'BANKNIFTY commentary' } }) });
  await p2;
  check(boxEl.innerHTML.includes('BANKNIFTY commentary'), 'the newer (BANKNIFTY) call correctly painted its own narrative');

  // Now the OLDER call's (NIFTY) response FINALLY arrives, network-late,
  // after the newer call has already rendered - this is the exact
  // reordering the bug lived in.
  fetchCalls[0].resolveFn({ json: () => Promise.resolve({ success: true, data: { narrative: 'STALE NIFTY commentary' } }) });
  await p1;

  check(!boxEl.innerHTML.includes('STALE NIFTY commentary'), 'the stale, older (NIFTY) call did NOT overwrite the newer BANKNIFTY narrative');
  check(boxEl.innerHTML.includes('BANKNIFTY commentary'), 'the box still shows the correct, current (BANKNIFTY) narrative after the stale response arrived');
}

async function testNonStaleCallStillRenders() {
  // Sanity/negative-control: when there is genuinely no newer call
  // in-flight, a real successful response IS written normally - the
  // fix must not turn this into a permanent no-op.
  localStorage.clear();
  fetchCalls = [];
  boxEl.innerHTML = '';
  let gen = 1;
  const isStale = () => false; // never superseded
  const p = generateAiNarrativeIfDue(fakeBrain(), 'NIFTY', isStale);
  fetchCalls[0].resolveFn({ json: () => Promise.resolve({ success: true, data: { narrative: 'Fresh commentary' } }) });
  await p;
  check(boxEl.innerHTML.includes('Fresh commentary'), 'a non-stale call still renders its real narrative normally (fix is not a blanket no-op)');
}

(async () => {
  await testStaleCallIsDiscarded();
  await testNonStaleCallStillRenders();
  console.log(`\n${passed} passed, ${failed} failed (ai-narrative-cross-symbol-race-audit.test.js)`);
  process.exit(failed > 0 ? 1 : 0);
})();
