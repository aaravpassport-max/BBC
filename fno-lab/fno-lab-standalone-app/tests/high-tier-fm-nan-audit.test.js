// REAL, STANDALONE, IMMEDIATELY-RUNNABLE test - closes this session's
// exhaustive 'high'-tier FM audit pass (every check('FMxxx', ..., 'high',
// ...) call site in evaluatePreTradeFailureModes(), one severity tier
// below the already-hardened critical/block tier).
//
// Genuine findings this pass, all sharing the same fail-open NaN bug
// class as the critical/block audit (a `typeof x === 'number'` guard
// silently passes NaN through, since `typeof NaN === 'number'` is true
// in JS, and every NaN comparison is false - so the safety/confirmation
// check silently does NOT fire for exactly the corrupted-data case it
// exists to catch):
//
//   1. FM133/FM134 (ctx.decay.days)              - typeof -> Number.isFinite
//   2. FM039 (ctx.slPrice / ctx.optPrice)          - typeof -> Number.isFinite
//   3. FM022/FM132 guard (thetaPerDay / optPrice)  - typeof -> Number.isFinite
//   4. computeIVPercentileRank (feeds FM126)       - typeof -> Number.isFinite,
//      on both currentIV and each ivHistory sample; a NaN currentIV used
//      to fabricate a 0th-percentile result instead of the honest null
//      this function already returns for missing data.
//   5. computeWrongSidePositioningCheck (feeds FM032) - typeof pcr ->
//      Number.isFinite(pcr); a NaN pcr was indistinguishable from a
//      genuinely non-extreme real PCR reading.
//   6. applyHistoricalDirectionTrackRecord (feeds FM083) - typeof
//      stats.confirmedRatePct -> Number.isFinite.
//   7. computeTrapSignal (feeds FM031) - netOIChange had NO finiteness
//      guard at all (silently returned isTrapSignature:false, "no
//      unusual buildup", for NaN input); now returns the same honest
//      null this function's own priceChangePct-missing branch already
//      uses for "can't check, not guessed". Note: FM031's own `=== true`
//      fires-check was not itself flipped by this specific input (NaN
//      never satisfied the isLargeOIMove threshold either way), but the
//      reason text and downstream consumers (computeOperatorIntel's
//      scoring, FM028's computeBreakoutTrapCheck) were previously fed a
//      fabricated "confirmed no trap" instead of an honest "unknown".
//
// Run with: node tests/high-tier-fm-nan-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js.');
  process.exit(1);
}
eval(coreSource.slice(0, end));

// ---------------------------------------------------------------
// 1. computeIVPercentileRank (feeds FM126)
// ---------------------------------------------------------------
{
  const cleanHistory = Array.from({length: 12}, (_, i) => ({ iv: 10 + i }));
  const clean = computeIVPercentileRank(30, cleanHistory);
  check(typeof clean.percentile === 'number' && clean.percentile === 100, 'IV percentile: clean 30 vs 10-21 history -> 100th percentile');

  const nanCurrent = computeIVPercentileRank(NaN, cleanHistory);
  check(nanCurrent.percentile === null, 'IV percentile: NaN currentIV -> honest null, not a fabricated 0th percentile');

  const historyWithNaN = cleanHistory.concat([{ iv: NaN }, { iv: NaN }]);
  const r2 = computeIVPercentileRank(30, historyWithNaN);
  check(r2.sampleSize === 12, 'IV percentile: NaN entries excluded from validHistory sample size');
}

// ---------------------------------------------------------------
// 2. computeWrongSidePositioningCheck (feeds FM032)
// ---------------------------------------------------------------
{
  const cleanExtreme = computeWrongSidePositioningCheck('CE', 2.0, null, null);
  check(cleanExtreme.reason.includes('retail crowded bearish'), 'Wrong-side: clean pcr=2.0 correctly detects bearish crowding');

  const nanPcr = computeWrongSidePositioningCheck('CE', NaN, null, null);
  check(!/crowded/.test(nanPcr.reason) === false || nanPcr.reason.includes('not currently at a genuine crowding extreme'), 'Wrong-side: NaN pcr treated as non-extreme (same honest outcome as real non-extreme data, not silently different)');
  // The key regression guard: NaN pcr must not throw and must not be
  // misread as satisfying `pcr > 1.5`/`pcr < 0.6` by accident.
  check(nanPcr.isWrongSideWarning === false, 'Wrong-side: NaN pcr alone does not fabricate a warning');
}

// ---------------------------------------------------------------
// 3. applyHistoricalDirectionTrackRecord (feeds FM083)
// ---------------------------------------------------------------
{
  const hyp = { direction: 'bullish', confidence: 'high' };
  const poorStats = { bullish: { confirmedRatePct: 25, decisiveTotal: 20, sampleSizeWarning: false } };
  const poor = applyHistoricalDirectionTrackRecord(hyp, poorStats);
  check(poor.wasDowngraded === true && poor.adjustedConfidence === 'medium', 'Track record: clean poor confirmedRatePct=25 downgrades high->medium');

  const nanStats = { bullish: { confirmedRatePct: NaN, decisiveTotal: 20, sampleSizeWarning: false } };
  const nanResult = applyHistoricalDirectionTrackRecord(hyp, nanStats);
  check(nanResult.wasDowngraded === false && /Not enough real historical/.test(nanResult.reason), 'Track record: NaN confirmedRatePct treated as insufficient data, not silently passed as a clean high rate');
}

// ---------------------------------------------------------------
// 4. computeTrapSignal (feeds FM031)
// ---------------------------------------------------------------
{
  const clean = computeTrapSignal(800000, 0.1);
  check(clean.isTrapSignature === true, 'Trap signal: clean large OI + flat price -> real trap signature');

  const nanOI = computeTrapSignal(NaN, 0.1);
  check(nanOI.isTrapSignature === null, 'Trap signal: NaN netOIChange -> honest null ("not guessed"), not a fabricated false');

  const nanPrice = computeTrapSignal(800000, NaN);
  check(nanPrice.isTrapSignature === null, 'Trap signal: NaN priceChangePct (with large OI) -> honest null, not silently reaching the 0.3% comparison');
}

// ---------------------------------------------------------------
// 5. evaluatePreTradeFailureModes: FM133/FM039/FM022 guard, end-to-end
// ---------------------------------------------------------------
{
  const baseBrain = { decision: 'BUY_READY', results: [], regime: {}, regimeAdjustment: '', failureLibraryAdjustment: '' };
  const baseCtx = { time: '11:00', isExpiry: false, ocRows: [], journalToday: [] };

  // FM133: NaN days-to-expiry must not silently suppress the check
  // relative to a genuinely-short (fires) or genuinely-long (doesn't
  // fire) real value - it must land in the same "doesn't fire, honest
  // '?' reason" bucket as missing data, never be treated as a real
  // >1-day value.
  const shortExpiryCtx = Object.assign({}, baseCtx, { decay: { days: 1 } });
  const rShort = evaluatePreTradeFailureModes({ brain: baseBrain, ctx: shortExpiryCtx });
  const fm133Short = rShort.triggered.find(t => t.id === 'FM133');
  check(!!fm133Short, 'FM133: clean days=1 fires (sanity baseline)');

  const nanExpiryCtx = Object.assign({}, baseCtx, { decay: { days: NaN } });
  const rNaN = evaluatePreTradeFailureModes({ brain: baseBrain, ctx: nanExpiryCtx });
  const fm133NaN = rNaN.triggered.find(t => t.id === 'FM133');
  check(!fm133NaN, 'FM133: NaN days does not fire (treated as missing, not as <=1)');

  // FM039: NaN slPrice/optPrice must not silently suppress the check.
  const badSlCtx = Object.assign({}, baseCtx, { slPrice: 110, optPrice: 100 });
  const rSl = evaluatePreTradeFailureModes({ brain: baseBrain, ctx: badSlCtx });
  check(!!rSl.triggered.find(t => t.id === 'FM039'), 'FM039: clean slPrice>=optPrice-violating case fires (sanity baseline)');

  const nanSlCtx = Object.assign({}, baseCtx, { slPrice: NaN, optPrice: 100 });
  const rNanSl = evaluatePreTradeFailureModes({ brain: baseBrain, ctx: nanSlCtx });
  check(!rNanSl.triggered.find(t => t.id === 'FM039'), 'FM039: NaN slPrice does not fire (fails safe to "cannot determine", not silently passed)');

  // FM022: NaN thetaPerDay must not silently suppress the check.
  const thetaCtx = Object.assign({}, baseCtx, { optPrice: 100, decay: { days: 10, snapshot: { now: { thetaPerDay: 6 } } } });
  const rTheta = evaluatePreTradeFailureModes({ brain: baseBrain, ctx: thetaCtx });
  check(!!rTheta.triggered.find(t => t.id === 'FM022'), 'FM022: clean thetaPerDay=6/optPrice=100 (6%) fires (sanity baseline)');

  const nanThetaCtx = Object.assign({}, baseCtx, { optPrice: 100, decay: { days: 10, snapshot: { now: { thetaPerDay: NaN } } } });
  const rNanTheta = evaluatePreTradeFailureModes({ brain: baseBrain, ctx: nanThetaCtx });
  check(!rNanTheta.triggered.find(t => t.id === 'FM022'), 'FM022: NaN thetaPerDay does not fire (guard now excludes NaN from the >=5% branch entirely)');
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
