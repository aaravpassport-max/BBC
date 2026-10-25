#!/usr/bin/env node
/**
 * Real, permanent regression test for this session's driver-side
 * signal-invalidation wiring (Decision Matrix Phase 4 / KB §7
 * "Cancellation/invalidation (distinct from SL)"). The browser path
 * got this check first (checkSignalInvalidation, checked every real
 * refresh cycle in renderOpenTrades); the driver had no equivalent
 * (an honestly-documented gap: "the driver does not re-evaluate the
 * brain for an open position at all today") until this session, since
 * the driver's position-management branch previously only ever called
 * checkTradeExit() on price, never evaluateBrain(), while a position
 * was open.
 *
 * Two things are checked, the same two-level discipline as
 * test-reentry-cooldown-gate.js:
 *
 * 1. A real, direct unit-level check that checkSignalInvalidation -
 *    the exact same, already-tested function the browser path uses -
 *    genuinely flags a reversed, non-Low-confidence signal, evaluated
 *    from the real, current fno-lab-core.js source.
 * 2. A real, static source-level check that autonomous-driver.js's own
 *    position-management block genuinely calls evaluateBrain() and
 *    checkSignalInvalidation() when no price-based exit has already
 *    fired, and that the exit path genuinely reaches fno_close_position
 *    with the distinct 'invalidated' reason - so a future edit that
 *    accidentally removes the wiring fails this test loudly.
 *
 * Run with: node test/test-signal-invalidation-gate.js
 */
const fs = require('fs');
const path = require('path');

const CORE_PATH = path.join(__dirname, '../../assets/fno-lab-core.js');
const DRIVER_PATH = path.join(__dirname, '../autonomous-driver.js');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log(`  PASS  ${label}`); }
  else { failed++; console.log(`  FAIL  ${label}`); }
}

console.log('=== Driver Signal-Invalidation Gate Regression Test (KB §7) ===\n');

// --- Part 1: real, direct check of the real, current core function ---
const coreSource = fs.readFileSync(CORE_PATH, 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js - this test\'s own extraction is stale.');
  process.exit(1);
}
eval(coreSource.slice(0, end));

check(typeof checkSignalInvalidation === 'function', 'checkSignalInvalidation is present in the pre-render() extracted region (reachable by the driver)');

const reversedHighConf = checkSignalInvalidation({ optionType: 'CE' }, { decision: 'SELL_READY', confidence: 'High' });
check(reversedHighConf.invalidated === true, 'checkSignalInvalidation genuinely flags a CE position when the live brain has reversed to SELL_READY at High confidence');

const agreeing = checkSignalInvalidation({ optionType: 'CE' }, { decision: 'BUY_READY', confidence: 'High' });
check(agreeing.invalidated === false, 'checkSignalInvalidation correctly does not flag when the live decision still agrees with the original thesis');

const lowConfReversal = checkSignalInvalidation({ optionType: 'CE' }, { decision: 'SELL_READY', confidence: 'Low' });
check(lowConfReversal.invalidated === false, 'checkSignalInvalidation correctly does not flag a Low-confidence reversal');

// --- Part 2: real, static proof the driver source actually wires this ---
const driverSource = fs.readFileSync(DRIVER_PATH, 'utf8');

const posBlockMatch = driverSource.match(/if \(openPosition\) \{([\s\S]*?)\n    \}\n\n    const brain = evaluateBrain\(ctx\);/);
check(!!posBlockMatch, 'the real driver\'s position-management block (if (openPosition) {...}) was located in the current source');
const posBlockBody = posBlockMatch ? posBlockMatch[1] : '';

check(/if \(!exitReason\) \{/.test(posBlockBody), 'the invalidation check must genuinely be gated on no price-based/deadline exit having already fired this cycle');
check(/evaluateBrain\(ctx\)/.test(posBlockBody), 'the real position-management block genuinely calls evaluateBrain(ctx) to get a live decision to check against');
check(/checkSignalInvalidation\(\{ optionType: positionOptionType \}, invalidationBrain\)/.test(posBlockBody), 'the real position-management block genuinely calls checkSignalInvalidation with the open position\'s real option side and the freshly-evaluated brain');
check(/exitReason = 'invalidated';/.test(posBlockBody), 'a genuine invalidation must genuinely set exitReason to the distinct \'invalidated\' value');

// The invalidation branch must appear BEFORE the exitReason-handling
// close/journal block, so a real invalidation this cycle is genuinely
// acted upon, not computed and silently ignored.
const invalidationIdx = posBlockBody.indexOf("checkSignalInvalidation(");
const closeCallIdx = posBlockBody.indexOf("postAuthenticated('fno_close_position'");
check(invalidationIdx > -1 && closeCallIdx > -1 && invalidationIdx < closeCallIdx,
  'the invalidation check appears, in source order, BEFORE the real fno_close_position call - so a genuine invalidation this cycle is actually closed, not merely computed');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
