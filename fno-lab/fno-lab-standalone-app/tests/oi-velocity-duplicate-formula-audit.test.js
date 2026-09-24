// REAL, STANDALONE, IMMEDIATELY-RUNNABLE test - closes a genuine,
// previously-unverified duplicate-logic drift risk found this session
// while re-auditing docs/PENDING_REQUIREMENTS.md's FM059/FM088 rows.
//
// fno-lab.php's admin-only "OI Accumulation History" panel
// (fno-lab.php ~line 2343-2358) embeds its OWN, hand-duplicated copy
// of the velocity/acceleration formula, explicitly commented as "a
// real, direct mirror of the tested computeOIVelocityAcceleration()
// JS function... intentionally duplicated here rather than a real
// load-order risk" (that admin page is a separate script context that
// never loads assets/fno-lab-core.js). A hand-duplicated formula with
// no automated cross-check is exactly the kind of drift risk this
// project's own audit discipline exists to catch - a future edit to
// either copy (e.g. tightening the 0.2 relativeChange threshold, or
// fixing a real bug) could silently diverge from the other with
// nothing to catch it.
//
// This test extracts BOTH the real computeOIVelocityAcceleration()
// function body from assets/fno-lab-core.js AND the real embedded
// duplicate JS block from fno-lab.php directly from the live source
// (never a hand-retyped copy of either), evaluates the embedded block
// against several real {date, oi} history scenarios (steady,
// accelerating, decelerating, insufficient-data), and asserts its
// velocity/previousVelocity/acceleration/state values are IDENTICAL
// to the real, separately-tested function's output for the same
// input - proving the two copies are still in sync right now, and
// locking that fact in so a future edit to only one copy fails this
// test loudly instead of silently drifting into production.
//
// Run with: node tests/oi-velocity-duplicate-formula-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

// --- Step 1: load the real, tested computeOIVelocityAcceleration() from assets/fno-lab-core.js ---
const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const fnStart = coreSrc.indexOf('function computeOIVelocityAcceleration(');
assert(fnStart !== -1, 'computeOIVelocityAcceleration not found in assets/fno-lab-core.js - source may have moved');
const fnEnd = coreSrc.indexOf('\n}', fnStart) + 2;
eval(coreSrc.slice(fnStart, fnEnd)); // defines computeOIVelocityAcceleration in this scope

// --- Step 2: extract the real, current embedded duplicate block from fno-lab.php ---
const phpSrc = fs.readFileSync(path.join(__dirname, '../fno-lab.php'), 'utf8');
const dupStart = phpSrc.indexOf('var validDays = history.filter(function(h){ return h.oi !== null; });');
assert(dupStart !== -1, 'the duplicated OI-velocity JS block was not found at its expected location in fno-lab.php - it may have moved, been removed, or been rewritten; update this test\'s anchor string to match, then re-verify the duplication is still real');
const dupEnd = phpSrc.indexOf('var state = relativeChange', dupStart);
assert(dupEnd !== -1, 'could not find the end of the duplicated block (the state-assignment line) in fno-lab.php');
const stateLineEnd = phpSrc.indexOf('\n', dupEnd);
let dupBlock = phpSrc.slice(dupStart, stateLineEnd);
// The block lives inside a single-quoted PHP string, so PHP-level
// escaped quotes (\') appear in the raw source - unescape them to get
// real, valid, runnable JS (this is real string un-escaping of the
// actual embedded source, not a hand-retyped rewrite).
dupBlock = dupBlock.replace(/\\'/g, "'");

console.log('\n=== OI-velocity duplicate-formula drift audit (fno-lab.php embedded JS vs. assets/fno-lab-core.js computeOIVelocityAcceleration) ===');

function runDuplicateBlock(history) {
  // Real execution of the real, extracted embedded block (unmodified
  // except for the earlier quote-unescaping and closing the real
  // `if (validDays.length >= 3) { ... }` brace that the extraction
  // window deliberately cuts off right after the real `state`
  // assignment, since that's the last line this formula computes -
  // the brace itself carries no logic of its own to verify).
  const src = dupBlock + '\n}\n'
    + 'return (typeof state !== "undefined") ? { velocity, previousVelocity, acceleration, state } : null;';
  // eslint-disable-next-line no-new-func
  const fn = new Function('history', src);
  return fn(history);
}

const scenarios = [
  { name: 'steady (small relative change)', history: [{date:'d1',oi:100000},{date:'d2',oi:120000},{date:'d3',oi:139000},{date:'d4',oi:158000}] },
  { name: 'accelerating', history: [{date:'d1',oi:100000},{date:'d2',oi:110000},{date:'d3',oi:115000},{date:'d4',oi:145000}] },
  { name: 'decelerating', history: [{date:'d1',oi:100000},{date:'d2',oi:150000},{date:'d3',oi:200000},{date:'d4',oi:210000}] },
  { name: '4-day mixed real numbers', history: [{date:'d1',oi:52340},{date:'d2',oi:61870},{date:'d3',oi:58020},{date:'d4',oi:44110}] },
  // Deliberately chosen so relativeChange lands at exactly 0.25 -
  // squarely between a real 0.2 threshold ("accelerating") and a
  // drifted 0.3 threshold ("steady") - this is the scenario that
  // actually exercises the threshold boundary; the others above don't
  // land near either value, so they'd stay passing even if the two
  // copies' thresholds silently diverged.
  { name: 'threshold-boundary case (relativeChange = 0.25)', history: [{date:'d1',oi:1000},{date:'d2',oi:1100},{date:'d3',oi:1225}] },
];

for (const s of scenarios) {
  const real = computeOIVelocityAcceleration(s.history);
  const dup = runDuplicateBlock(s.history);
  check(dup !== null, `${s.name}: duplicate block produces a result for >=3 valid days`);
  if (dup) {
    check(dup.velocity === real.velocity, `${s.name}: velocity matches (dup=${dup.velocity}, real=${real.velocity})`);
    check(dup.previousVelocity === real.previousVelocity, `${s.name}: previousVelocity matches (dup=${dup.previousVelocity}, real=${real.previousVelocity})`);
    check(dup.acceleration === real.acceleration, `${s.name}: acceleration matches (dup=${dup.acceleration}, real=${real.acceleration})`);
    check(dup.state === real.accelerationState, `${s.name}: state matches (dup="${dup.state}", real="${real.accelerationState}")`);
  }
}

// Insufficient-data case: the duplicate block's own `if (validDays.length >= 3)`
// guard should skip entirely (result stays null), matching the real
// function's 'insufficient_data' non-computation (no velocity/state emitted).
const shortHistory = [{date:'d1',oi:1000},{date:'d2',oi:1100}];
const realShort = computeOIVelocityAcceleration(shortHistory);
const dupShort = runDuplicateBlock(shortHistory);
check(realShort.accelerationState === 'insufficient_data', 'real function correctly reports insufficient_data for 2 days');
check(dupShort === null, 'duplicate block correctly computes nothing (guarded by its own >=3 check) for 2 days - matches real function\'s honest non-computation, not a silent divergence');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
