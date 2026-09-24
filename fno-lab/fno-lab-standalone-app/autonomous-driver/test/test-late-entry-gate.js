#!/usr/bin/env node
/**
 * Real, permanent regression test for the specific, reported
 * ~Rs20,000 loss scenario: a real Intraday position opened around
 * 3:30 PM IST and immediately force-closed at square-off. This test
 * exists to make sure that class of loss cannot repeat via the
 * STANDALONE DRIVER path specifically - the browser-tab path already
 * had its own equivalent protection before this fix; this file proves
 * the driver now has it too.
 *
 * Two things are checked, deliberately at two different levels:
 *
 * 1. A real, direct unit-level check that checkSufficientTimeRemaining
 *    and evaluatePreTradeFailureModes (FM061) - the exact same,
 *    already-tested functions the browser path uses - genuinely block
 *    a 15:29 IST entry attempt, evaluated from the real, current
 *    fno-lab-core.js source, not a hand-copied re-implementation that
 *    could silently drift from what's actually shipped.
 * 2. A real, static source-level check that autonomous-driver.js's own
 *    entry block genuinely CALLS checkSufficientTimeRemaining and
 *    evaluatePreTradeFailureModes before ever setting openPosition -
 *    so a future edit that accidentally removes the call (even if the
 *    underlying functions themselves stay correct) fails this test
 *    loudly, rather than silently reopening the exact gap this file
 *    was written to close.
 *
 * Run with: node test/test-late-entry-gate.js
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

console.log('=== Late-Entry / Square-Off Gate Regression Test (the ~Rs20,000 loss scenario) ===\n');

// --- Part 1: real, direct check of the real, current core functions ---
const coreSource = fs.readFileSync(CORE_PATH, 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js - this test\'s own extraction is stale.');
  process.exit(1);
}
// Real, same extraction technique the actual driver uses - proves this
// test evaluates the SAME functions that ship, not a copy.
eval(coreSource.slice(0, end));

check(typeof checkSufficientTimeRemaining === 'function', 'checkSufficientTimeRemaining is present in the pre-render() extracted region (reachable by the driver)');
check(typeof evaluatePreTradeFailureModes === 'function', 'evaluatePreTradeFailureModes is present in the pre-render() extracted region (reachable by the driver)');
check(typeof adjustFailureModesForTradeType === 'function', 'adjustFailureModesForTradeType is present in the pre-render() extracted region (reachable by the driver)');

// The exact reported scenario: 15:29 IST, Intraday, no configured
// broker square-off time (falls back to the documented 15:15 default).
const lateCtx = { time: '15:29' };
const timeCheckLate = checkSufficientTimeRemaining(lateCtx, 'intraday');
check(timeCheckLate.sufficient === false, 'checkSufficientTimeRemaining genuinely blocks a new Intraday entry at 15:29 IST (the exact reported ~3:30 PM scenario)');
check(/Rs20,?000/.test(timeCheckLate.reason || ''), 'the block reason explicitly names this as the real, reported ~Rs20,000-loss-preventing rule');

// A safely-timed entry should NOT be blocked, proving this isn't an
// overzealous, always-block gate.
const earlyCtx = { time: '11:00' };
const timeCheckEarly = checkSufficientTimeRemaining(earlyCtx, 'intraday');
check(timeCheckEarly.sufficient === true, 'checkSufficientTimeRemaining correctly allows a new Intraday entry at 11:00 IST (plenty of time remaining)');

// FM061 (the Failure-Mode Library's own, independent, second copy of
// this rule) must also fire for the same 15:29 scenario.
const brainStub = { regime: {}, results: [], criticalFails: 0 };
const fmLate = evaluatePreTradeFailureModes({ brain: brainStub, ctx: { ...lateCtx, ocRow: {} } });
const fm061 = fmLate.triggered.find(t => t.id === 'FM061');
check(!!fm061, 'FM061 (the Failure-Mode Library\'s independent, second copy of the same timing rule) also fires at 15:29 IST');
check(fm061 && fm061.action === 'block', 'FM061 action is a hard block, not merely a warning (this was itself a previously-fixed regression - see the code comment at its definition)');

// --- Part 2: real, static proof the driver source actually calls these ---
const driverSource = fs.readFileSync(DRIVER_PATH, 'utf8');
const entryBlockMatch = driverSource.match(/if \(brain\.decision === 'BUY_READY' \|\| brain\.decision === 'SELL_READY'\) \{([\s\S]*?)\n    \}/);
check(!!entryBlockMatch, 'the real driver\'s BUY_READY/SELL_READY entry block was located in the current source');
const entryBlockBody = entryBlockMatch ? entryBlockMatch[1] : '';
check(entryBlockBody.includes('checkSufficientTimeRemaining('), 'the real driver\'s entry block genuinely CALLS checkSufficientTimeRemaining before opening a position (not merely available - actually invoked)');
check(entryBlockBody.includes('evaluatePreTradeFailureModes('), 'the real driver\'s entry block genuinely CALLS evaluatePreTradeFailureModes before opening a position');
check(/if \(!timeCheck\.sufficient\)[\s\S]*?return;/.test(entryBlockBody), 'the real driver returns (does not open a position) when the time-sufficiency check fails');
check(/finalAction === 'block' \|\| fmResult\.finalAction === 'reject'[\s\S]*?return;/.test(entryBlockBody), 'the real driver returns (does not open a position) when the Failure-Mode Library verdict is block/reject');
// The position-opening line itself must appear textually AFTER both guards, not before them.
const timeGuardIdx = entryBlockBody.indexOf('checkSufficientTimeRemaining(');
const fmGuardIdx = entryBlockBody.indexOf('evaluatePreTradeFailureModes(');
const openIdx = entryBlockBody.indexOf('openPosition = {');
check(timeGuardIdx > -1 && fmGuardIdx > -1 && openIdx > -1 && timeGuardIdx < openIdx && fmGuardIdx < openIdx,
  'both guards appear, in source order, BEFORE the line that actually sets openPosition - not after it, which would make them no-ops');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
