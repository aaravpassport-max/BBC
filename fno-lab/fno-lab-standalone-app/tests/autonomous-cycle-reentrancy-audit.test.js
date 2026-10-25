// REAL, STANDALONE test - Autonomous Mode's own browser-side polling
// loop (startAutonomousMode()'s `cycle`, assets/fno-lab-core.js), a
// direct follow-up to the fnoRefreshGeneration / closeAutoTrade
// re-entrancy findings.
//
// WHAT THIS FOUND: `autonomousModeInterval = setInterval(cycle, ...)`
// does NOT wait for a previous `cycle()` call's promise to settle
// before scheduling/firing the next invocation - setInterval only
// spaces INVOCATIONS by the configured interval, it never checks
// whether the previous callback's returned promise has resolved. A real
// refreshBrain() call (NSE Promise.all + journal sync + paper-account
// fetch) that happens to take longer than the current poll interval (as
// low as 15s for Scalping) means a SECOND `cycle()` genuinely starts,
// and calls its own refreshBrain(), while the first is still in flight
// - two real, overlapping refreshBrain() calls from the SAME polling
// loop, with no user action required at all.
//
// FIX: a `cycleInProgress` guard around `cycle()`, same fix shape as
// autonomous-driver.js's own `cycleInProgress` guard (see its TRACE) -
// a tick that finds the previous one still running skips itself
// entirely rather than starting a second, overlapping refreshBrain().
//
// This test drives the REAL `cycle` closure (extracted from
// assets/fno-lab-core.js's startAutonomousMode(), by its own real
// function boundaries) with a controllable, artificially slow
// refreshBrain() stub and two back-to-back invocations simulating
// setInterval firing again before the first tick's refreshBrain() has
// resolved.
//
// Run with: node tests/autonomous-cycle-reentrancy-audit.test.js

const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const statusEl = { textContent: '', style: {} };
const toggleBtn = { textContent: '', style: {} };
global.document = {
  getElementById: (id) => (id === 'autonomousModeStatus' ? statusEl : (id === 'autonomousModeToggle' ? toggleBtn : { textContent: '', style: {} })),
};
global.localStorage = (function () {
  let store = {};
  return { getItem: (k) => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); }, removeItem: (k) => { delete store[k]; } };
})();

// Real collaborators this closure genuinely depends on, controlled by
// the test the same way every other audit test in this directory
// controls its real function's external collaborators:
//   - isRealMarketHours: stubbed to always report "market open" so the
//     test is deterministic regardless of when it actually runs (this
//     app's real isRealMarketHours() is a pure function of the current
//     wall-clock time, irrelevant to the re-entrancy property under
//     test here).
//   - refreshBrain: a controllable stub that returns a promise the test
//     resolves on demand, so it can genuinely hold a "cycle" mid-flight
//     the same way a real, slow NSE fetch chain would.
let refreshBrainCalls = 0;
let pendingResolvers = [];
global.isRealMarketHours = () => ({ isMarketHours: true, reason: '' });
global.refreshBrain = () => {
  refreshBrainCalls++;
  return new Promise((resolve) => { pendingResolvers.push(resolve); });
};
global.autonomousModeInterval = null;
global.fnoSettings = { get: () => ({ tradingTypes: { scalping: false, intraday: true, swing: false } }) };
function escapeHtml(s) { return String(s == null ? '' : s); }

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
function extract(startMarker, endMarker) {
  const start = coreSource.indexOf(startMarker);
  if (start === -1) { console.error(`FATAL: could not locate "${startMarker}"`); process.exit(1); }
  const end = coreSource.indexOf(endMarker, start);
  if (end === -1) { console.error(`FATAL: could not locate closing marker after "${startMarker}"`); process.exit(1); }
  return coreSource.slice(start, end + endMarker.length);
}

const getPollMsSrc = extract('function getAutonomousPollMs() {', "return AUTONOMOUS_POLL_MS_BY_STYLE.intraday; // real, safe default if a user somehow has genuinely none enabled\n  }");
const updateStatusSrc = extract('function updateAutonomousStatusDisplay(statusText, isError) {', "el.style.color = isError ? '#f87171' : '#4ade80';\n  }");
const startAutonomousSrc = extract('function startAutonomousMode() {', 'autonomousModeInterval = setInterval(cycle, getAutonomousPollMs());\n  }');

const AUTONOMOUS_POLL_MS_BY_STYLE_SRC = "const AUTONOMOUS_POLL_MS_BY_STYLE = { scalping: 15000, intraday: 60000, swing: 300000 };";

eval(
  AUTONOMOUS_POLL_MS_BY_STYLE_SRC.replace(/^  /, '') + '\n' +
  getPollMsSrc.replace(/^  /gm, '') + '\n' +
  updateStatusSrc.replace(/^  /gm, '') + '\n' +
  startAutonomousSrc.replace(/^  /gm, '').replace('autonomousModeInterval = setInterval(cycle, getAutonomousPollMs());', 'global.__cycle = cycle; /* capture instead of real setInterval - the test drives invocations directly */') + '\n'
);

async function testOverlappingTicksAreSkipped() {
  refreshBrainCalls = 0;
  pendingResolvers = [];
  statusEl.textContent = '';

  startAutonomousMode(); // this itself invokes cycle() once immediately
  check(refreshBrainCalls === 1, 'the immediate real cycle() call reached refreshBrain() (1 call)');
  check(pendingResolvers.length === 1, 'that real refreshBrain() call is genuinely still pending (mid-flight)');

  // Simulate setInterval firing again before the first tick's
  // refreshBrain() has resolved - the exact real condition a slow NSE
  // fetch chain outlasting the poll interval reproduces.
  const p2 = global.__cycle();
  check(refreshBrainCalls === 1, 'the overlapping second tick did NOT call refreshBrain() again - skipped by the guard (still 1 real call)');
  check(statusEl.textContent.includes('skipping this tick'), 'the skipped tick is honestly surfaced in the status display, not silently dropped');

  // Let the first tick's refreshBrain() finally resolve.
  pendingResolvers[0]();
  await new Promise((r) => setTimeout(r, 0));
  await p2;

  // A genuinely later, non-overlapping tick (guard released) must still
  // work normally - the fix must not permanently wedge the loop.
  const p3 = global.__cycle();
  check(refreshBrainCalls === 2, 'a later, non-overlapping tick (guard released) DOES call refreshBrain() again (2 real calls total)');
  pendingResolvers[1]();
  await p3;
}

(async () => {
  await testOverlappingTicksAreSkipped();
  console.log(`\n${passed} passed, ${failed} failed (autonomous-cycle-reentrancy-audit.test.js)`);
  process.exit(failed > 0 ? 1 : 0);
})();
