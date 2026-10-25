#!/usr/bin/env node
/**
 * Real, permanent regression test for this session's re-entry cooldown
 * gate (Decision Matrix Phase 4 / KB §7 "Re-entry after exit... needs
 * explicit cooldown/re-entry logic per trade type"). The browser path
 * got this gate first, wired into tryOpenAutoTradePosition() reusing
 * its own real, persisted closed-trade history; this driver had no
 * equivalent (an honestly-documented, open gap) until this session's
 * wiring using a module-level lastIntradaySlExitAt timestamp (the
 * driver has no persisted closed-trade history array to search, so it
 * tracks the one real timestamp it actually needs directly - see the
 * variable's own TRACE comment in autonomous-driver.js).
 *
 * Two things are checked, the same two-level discipline as
 * test-late-entry-gate.js:
 *
 * 1. A real, direct unit-level check that checkReEntryCooldown - the
 *    exact same, already-tested function the browser path uses -
 *    genuinely blocks a same-type entry shortly after a real SL exit,
 *    evaluated from the real, current fno-lab-core.js source.
 * 2. A real, static source-level check that autonomous-driver.js's own
 *    entry block genuinely CALLS checkReEntryCooldown before ever
 *    setting openPosition, and that the SL-exit branch genuinely
 *    records lastIntradaySlExitAt - so a future edit that accidentally
 *    removes either wiring fails this test loudly.
 *
 * Run with: node test/test-reentry-cooldown-gate.js
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

console.log('=== Driver Re-Entry Cooldown Gate Regression Test (KB §7) ===\n');

// --- Part 1: real, direct check of the real, current core function ---
const coreSource = fs.readFileSync(CORE_PATH, 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js - this test\'s own extraction is stale.');
  process.exit(1);
}
eval(coreSource.slice(0, end));

check(typeof checkReEntryCooldown === 'function', 'checkReEntryCooldown is present in the pre-render() extracted region (reachable by the driver)');

const now = Date.now();
const recentSlHistory = [{ ts: now - 5 * 60000, source: 'auto_sl', tradingType: 'intraday' }];
const cooldownActive = checkReEntryCooldown(recentSlHistory, 'intraday', now);
check(cooldownActive.onCooldown === true, 'checkReEntryCooldown genuinely blocks a new intraday entry 5 minutes after a real SL exit (needs 15+)');

const elapsedSlHistory = [{ ts: now - 20 * 60000, source: 'auto_sl', tradingType: 'intraday' }];
const cooldownElapsed = checkReEntryCooldown(elapsedSlHistory, 'intraday', now);
check(cooldownElapsed.onCooldown === false, 'checkReEntryCooldown correctly allows a new intraday entry once 20 minutes have elapsed (past the 15-minute requirement)');

const noSlHistory = [];
check(checkReEntryCooldown(noSlHistory, 'intraday', now).onCooldown === false, 'checkReEntryCooldown correctly does not block when there is genuinely no prior SL exit');

// --- Part 2: real, static proof the driver source actually wires this ---
const driverSource = fs.readFileSync(DRIVER_PATH, 'utf8');

check(/let lastIntradaySlExitAt = null;/.test(driverSource), 'the real driver declares its own module-level lastIntradaySlExitAt tracker');
check(/if \(exitReason === 'sl'\) \{ lastIntradaySlExitAt = Date\.now\(\); \}/.test(driverSource), 'the real driver genuinely records the timestamp on a real SL exit specifically, not on target/square_off');

const entryBlockMatch = driverSource.match(/if \(brain\.decision === 'BUY_READY' \|\| brain\.decision === 'SELL_READY'\) \{([\s\S]*?)\n    \}/);
check(!!entryBlockMatch, 'the real driver\'s BUY_READY/SELL_READY entry block was located in the current source');
const entryBlockBody = entryBlockMatch ? entryBlockMatch[1] : '';
check(entryBlockBody.includes('checkReEntryCooldown('), 'the real driver\'s entry block genuinely CALLS checkReEntryCooldown before opening a position');
check(/if \(cooldownCheck\.onCooldown\)[\s\S]*?return;/.test(entryBlockBody), 'the real driver returns (does not open a position) when the cooldown check reports onCooldown:true');

// Ordering: the cooldown guard must appear before the position-opening line.
const cooldownGuardIdx = entryBlockBody.indexOf('checkReEntryCooldown(');
const openIdx = entryBlockBody.indexOf('openPosition = {');
check(cooldownGuardIdx > -1 && openIdx > -1 && cooldownGuardIdx < openIdx,
  'the cooldown guard appears, in source order, BEFORE the line that actually sets openPosition - not after it, which would make it a no-op');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
