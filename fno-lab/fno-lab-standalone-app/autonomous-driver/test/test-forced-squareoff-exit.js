#!/usr/bin/env node
/**
 * Real, permanent regression test for the Decision Matrix Tier-1 gap
 * found this session: the driver's entry-side time gate (fixed
 * earlier) stops a NEW position opening too late, but nothing forced
 * an ALREADY-OPEN driver position closed once the real square-off
 * deadline passed, if neither target nor SL had been hit yet. This is
 * the same class of risk as the original ~Rs20,000 loss - a position
 * left open into/past the forced-square-off window - just on the exit
 * side instead of the entry side.
 *
 * Run with: node test/test-forced-squareoff-exit.js
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

console.log('=== Forced Square-Off Exit Regression Test (driver position-management, Decision Matrix Tier-1 gap) ===\n');

// --- Part 1: behavioral - checkSufficientTimeRemaining's minutesRemaining
// genuinely goes to/below zero once the deadline passes, which is the
// real signal the driver's new code relies on. ---
const coreSource = fs.readFileSync(CORE_PATH, 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
eval(coreSource.slice(0, end));

const atDeadline = checkSufficientTimeRemaining({ time: '15:15' }, 'intraday');
check(atDeadline.minutesRemaining === 0, `minutesRemaining is exactly 0 at the default 15:15 deadline with current time 15:15 (got ${atDeadline.minutesRemaining})`);

const pastDeadline = checkSufficientTimeRemaining({ time: '15:20' }, 'intraday');
check(pastDeadline.minutesRemaining < 0, `minutesRemaining is negative once the deadline has genuinely passed (15:20 vs default 15:15 deadline, got ${pastDeadline.minutesRemaining})`);

const wellBeforeDeadline = checkSufficientTimeRemaining({ time: '11:00' }, 'intraday');
check(wellBeforeDeadline.minutesRemaining > 0, 'minutesRemaining stays positive well before the deadline (11:00) - the forced-exit logic must not fire here');

// --- Part 2: static - the driver's position-management block genuinely
// computes and uses this signal BEFORE calling checkTradeExit. ---
const driverSource = fs.readFileSync(DRIVER_PATH, 'utf8');
const posBlockMatch = driverSource.match(/if \(openPosition\) \{([\s\S]*?)\n    \}\n\n    const brain/);
check(!!posBlockMatch, 'the real driver\'s position-management block (if (openPosition) {...}) was located in the current source');
const posBlockBody = posBlockMatch ? posBlockMatch[1] : '';
check(posBlockBody.includes('checkSufficientTimeRemaining('), 'the position-management block genuinely calls checkSufficientTimeRemaining (reusing the same, already-tested entry-side function)');
check(posBlockBody.includes('deadlineReached'), 'a real deadlineReached signal is computed');
// NOTE (updated this pass, real exit-side option-chain staleness fix):
// deadlineReached still short-circuits to 'square_off' before any
// price-based check, but checkTradeExit is now reached via an
// intermediate staleness ternary (ocStaleForExit) rather than being
// called directly inline - the real ordering (deadline wins first,
// unconditionally) is unchanged, only the shape of the non-deadline
// branch is, since it must now also honestly skip a stale-but-finite
// price rather than trusting it.
check(/deadlineReached \? 'square_off' : \(\(ocStaleForExit && ocStaleForExit\.stale\) \? null : checkTradeExit\(/.test(posBlockBody),
  'deadlineReached is checked BEFORE checkTradeExit and short-circuits to a real square_off exit reason when true - not merely computed and ignored - and the non-deadline branch now also honestly routes through the new staleness check rather than calling checkTradeExit unconditionally');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
