#!/usr/bin/env node
/**
 * Real, permanent regression test for this session's driver-side
 * trailing-stop wiring (Decision Matrix / KB §7 "Trailing-stop-loss
 * condition... driver path has no trailing-stop logic at all"). The
 * browser path has had a real, tested, trade-type-aware
 * updateTrailingStop() since earlier this session; the driver had NO
 * trailing-stop logic whatsoever until now - it only ever checked the
 * ORIGINAL, static sl.
 *
 * Two things are checked, the same two-level discipline as the other
 * driver gate tests this session:
 *
 * 1. A real, direct unit-level check that updateTrailingStop - the
 *    exact same, already-tested function the browser path uses -
 *    genuinely ratchets for an 'intraday' position (this driver's own
 *    real, fixed effective trade type).
 * 2. A real, static source-level check that autonomous-driver.js's own
 *    position-management block genuinely calls updateTrailingStop()
 *    when CONFIG.trailingEnabled is on, persists the running
 *    trailingSl onto the real, in-memory openPosition object, and
 *    genuinely uses the resulting effectiveSl (not the original,
 *    static sl) in the real checkTradeExit() call - so a future edit
 *    that accidentally breaks the wiring (e.g. reverting to the
 *    static sl) fails this test loudly.
 *
 * Run with: node test/test-driver-trailing-stop.js
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

console.log('=== Driver Trailing-Stop Regression Test (KB §7) ===\n');

// --- Part 1: real, direct check of the real, current core function ---
const coreSource = fs.readFileSync(CORE_PATH, 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js - this test\'s own extraction is stale.');
  process.exit(1);
}
eval(coreSource.slice(0, end));

check(typeof updateTrailingStop === 'function', 'updateTrailingStop is present in the pre-render() extracted region (reachable by the driver)');

const intradayTrail = updateTrailingStop({ entryPrice: 100, sl: 80, tradingType: 'intraday' }, 150);
check(intradayTrail === 130, 'updateTrailingStop genuinely ratchets an intraday position by the unscaled (1.0x) original risk distance: 150 - 20 = 130');

const neverRetreats = updateTrailingStop({ entryPrice: 100, sl: 80, tradingType: 'intraday', trailingSl: 130 }, 110);
check(neverRetreats === 130, 'updateTrailingStop genuinely never retreats even when price pulls back');

// --- Part 2: real, static proof the driver source actually wires this ---
const driverSource = fs.readFileSync(DRIVER_PATH, 'utf8');

// trailingEnabled's definition now lives in the extracted config.js
// (see config.js's own header comment - this pass extracted
// buildConfig/validateConfig out of autonomous-driver.js for the same
// testability reasons companion-daemon's loadConfig was extracted).
const configSource = fs.readFileSync(path.join(__dirname, '..', 'config.js'), 'utf8');
check(/trailingEnabled: env\.FNO_TRAILING_ENABLED === '1',/.test(configSource), 'CONFIG.trailingEnabled must be genuinely defined, opt-in (default off), matching this driver\'s own established conservative-default discipline');

const posBlockMatch = driverSource.match(/if \(openPosition\) \{([\s\S]*?)\n    \}\n\n    const brain = evaluateBrain\(ctx\);/);
check(!!posBlockMatch, 'the real driver\'s position-management block (if (openPosition) {...}) was located in the current source');
const posBlockBody = posBlockMatch ? posBlockMatch[1] : '';

check(/if \(CONFIG\.trailingEnabled\) \{/.test(posBlockBody), 'the real position-management block must genuinely gate the trailing computation on CONFIG.trailingEnabled');
check(/openPosition\.trailingSl = updateTrailingStop\(/.test(posBlockBody), 'the real position-management block must genuinely call updateTrailingStop() and persist the result onto the real, in-memory openPosition object (so it ratchets across cycles)');
check(/const effectiveSl = CONFIG\.trailingEnabled \? openPosition\.trailingSl : openPosition\.sl;/.test(posBlockBody), 'the real effectiveSl must genuinely be the trailing level when enabled, or the original static sl otherwise - never silently always one or the other');
check(/checkTradeExit\(openPosition\.entryPrice, optPrice, openPosition\.target, effectiveSl\)/.test(posBlockBody), 'the real checkTradeExit() call must genuinely use effectiveSl, not the original, static openPosition.sl unconditionally - otherwise trailing would be computed and silently ignored');

// Ordering: the trailing computation must appear BEFORE the
// checkTradeExit() call that consumes effectiveSl.
const trailIdx = posBlockBody.indexOf('updateTrailingStop(');
const exitCheckIdx = posBlockBody.indexOf('checkTradeExit(openPosition.entryPrice, optPrice, openPosition.target, effectiveSl)');
check(trailIdx > -1 && exitCheckIdx > -1 && trailIdx < exitCheckIdx,
  'the trailing computation appears, in source order, BEFORE the real checkTradeExit() call that consumes its result');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
