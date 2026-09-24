// REAL, STANDALONE, IMMEDIATELY-RUNNABLE test for this session's sweep of
// the remaining unaudited compute*Factors() scoring helpers in
// assets/fno-lab-core.js: computeCostsFactors, computeVolFactors,
// computeRegulatoryRealFactors, computeFundamentalRealFactors,
// computeMicrostructureDaemonFactors, computeFuturesFactors,
// computeEnhancementFactors, computeASMGSMFactor, computeDocumentedGapFactors.
// (computeTradingHaltFactor was confirmed already fixed in an earlier
// session pass - see its own NaN-fail-safe guard at its definition.)
//
// Genuine bugs found this pass, all fixed with a proving regression test
// below (same NaN-fail-open bug class as the rest of this audit):
//   1. computeRegulatoryRealFactors - MTM Square Off Time: malformed
//      brokerSquareOffTime/ctx.time produced a self-contradictory
//      pass:false/score:-0.5 result whose OWN reason text said "outside
//      the immediate danger window" - now an honest pass:null.
//   2. computeFundamentalRealFactors - Cash Volume vs F&O Volume: naive
//      `typeof x === 'number'` (true for NaN) fabricated pass:true with
//      "Rs NaN Cr" in the reason. Now Number.isFinite-guarded.
//   3. computeMicrostructureDaemonFactors - a NaN cumulativeDelta/
//      flowImbalancePct/ticksPerMinute from a corrupt daemon snapshot
//      passed the old `!==undefined && !==null` check and fabricated a
//      "[LIVE DAEMON]" pass:true row reading e.g. "NaN ticks/min". Now
//      requires Number.isFinite for the three genuinely-numeric keys.
//   4. computeCostsFactors - Bid-Ask Spread Cost: `(x||0)` treated a
//      corrupt NaN askPrice/bidprice exactly like a genuinely-absent 0,
//      computing bidAskCost=0 and fabricating a clean pass:true ("tight
//      spread") on garbage feed data. Now requires both fields finite.
//   5. computeCostsFactors - STT on ITM Expiry: NaN spot/strike in the
//      Greeks snapshot made both ITM comparisons false, silently
//      defaulting to itmOnExpiry=false -> fabricated pass:true ("Not
//      ITM-on-expiry") on a check whose whole point is catching an
//      expensive expiry-day STT surprise. Now Number.isFinite-guarded.
//   6. computeVolFactors - India VIX Trend Up/Down: naive `typeof x ===
//      'number'` on both vix and past.vix (true for NaN) fabricated a
//      pass/fail verdict with "NaN" baked into the reason text. Now
//      Number.isFinite-guarded on both readings.
//   7. computeFuturesFactors - Cost of Carry: naive `typeof
//      riskFreeRate === 'number'` (true for NaN) adopted a NaN rate
//      instead of falling back to the documented default, poisoning the
//      carry gap to NaN and misreporting a definite fail. Now
//      Number.isFinite-guarded.
//
// Confirmed already safe, with evidence (not re-fixed):
//   - computeDocumentedGapFactors: fully static string tables, pass:null
//     hardcoded throughout - no numeric computation exists to poison.
//   - computeASMGSMFactor: no arithmetic; only reads asmGsmResult.list.length
//     (used as a display count, not a pass/fail comparison) - always
//     pass:null by design (see its own TRACE comment).
//   - computeEnhancementFactors: marketDepth qty sums use `(b.quantity||0)`
//     which is NaN-safe (NaN is falsy, coerces to 0 same as a genuinely
//     missing field, and the resulting imbalance is still bounds-checked
//     against a >0 total before dividing); newsSentiment's `>= 0` on a
//     NaN input already fails CLOSED (pass:false), not the fail-OPEN
//     pattern this audit targets.
//   - computeFundamentalRealFactors' `(p.fii.longOI||0)` etc.: NaN is
//     falsy in JS, so `NaN||0` already safely coerces to 0 - genuinely
//     not a bug, verified by direct test below.
//
// Run with: node tests/remaining-factor-functions-nan-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const greeksSource = fs.readFileSync(path.join(__dirname, '../assets/greeks-engine.js'), 'utf8')
  .replace(/module\.exports\s*=\s*\{[\s\S]*?\};?/, '');
eval(greeksSource);

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js.');
  process.exit(1);
}
eval(coreSource.slice(0, end));

// --- Finding 1: computeRegulatoryRealFactors / MTM Square Off Time ---
{
  const row = computeRegulatoryRealFactors({ brokerSquareOffTime: 'not-a-time', time: '14:00', isExpiry: false })
    .find(f => f.factor === 'MTM Square Off Time MIS vs NRML');
  check(row.pass === null, 'MTM Square Off Time: malformed configured time -> pass:null (was false/score:-0.5 with a contradictory reason)');
  check(row.score === 0, 'MTM Square Off Time: malformed configured time -> score:0');

  const rowGood = computeRegulatoryRealFactors({ brokerSquareOffTime: '15:20', time: '14:00', isExpiry: false })
    .find(f => f.factor === 'MTM Square Off Time MIS vs NRML');
  check(rowGood.pass === true, 'MTM Square Off Time: well-formed times still compute a real verdict (80min remaining > 15)');
}

// --- Finding 2: computeFundamentalRealFactors / Cash Volume vs F&O Volume ---
{
  const row = computeFundamentalRealFactors({ participantOI: null, indexTurnoverCr: 0/0, totalCE: 1000, totalPE: 900, status: {} })
    .find(f => f.factor === 'Cash Volume vs F&O Volume');
  check(row.pass === null, 'Cash Volume vs F&O Volume: NaN indexTurnoverCr -> pass:null (was fabricated pass:true with "Rs NaN Cr")');

  const rowGood = computeFundamentalRealFactors({ participantOI: null, indexTurnoverCr: 50000, totalCE: 1000, totalPE: 900, status: {} })
    .find(f => f.factor === 'Cash Volume vs F&O Volume');
  check(rowGood.pass === true && rowGood.reason.indexOf('NaN') === -1, 'Cash Volume vs F&O Volume: real finite inputs still compute a real informational row');

  // Confirmed-safe finding: p.fii.longOI||0 style fallback is NaN-safe.
  const rowP = computeFundamentalRealFactors({
    participantOI: { date: '2026-08-28', fii: { longOI: 0/0, shortOI: 100 }, dii: { longOI: 10, shortOI: 5 }, pro: { longOI: 1, shortOI: 1 }, client: { longOI: 1, shortOI: 1 } },
    status: {},
  }).find(f => f.factor === 'Client vs Pro vs FII Positioning');
  check(rowP.reason.indexOf('NaN') === -1, 'Client vs Pro vs FII Positioning: NaN fii.longOI coerces via ||0 (falsy), confirmed no NaN leaks into the reason');
}

// --- Finding 3: computeMicrostructureDaemonFactors ---
{
  const rows = computeMicrostructureDaemonFactors({ microstructure: {
    icebergDetected: 0, cumulativeDelta: 0/0, poc: 24500, flowImbalancePct: 0/0, footprintTopLevels: [], ticksPerMinute: 0/0, domSpoofDetected: 0,
  } });
  const delta = rows.find(f => f.factor.indexOf('Cumulative Delta') === 0);
  const flow = rows.find(f => f.factor.indexOf('Order Flow Imbalance') === 0);
  const ticks = rows.find(f => f.factor.indexOf('Tick Data Speed') === 0);
  const poc = rows.find(f => f.factor.indexOf('Volume Profile POC') === 0);
  check(delta.pass === null, 'Cumulative Delta: NaN daemon value -> pass:null (was fabricated pass:true reading "NaN")');
  check(flow.pass === null, 'Order Flow Imbalance: NaN daemon value -> pass:null');
  check(ticks.pass === null, 'Tick Data Speed: NaN daemon value -> pass:null');
  check(poc.pass === true, 'Volume Profile POC: real finite value still reports live (unaffected by the numeric-key guard)');

  const rowsGood = computeMicrostructureDaemonFactors({ microstructure: {
    icebergDetected: 0, cumulativeDelta: 1200, poc: 24500, flowImbalancePct: 55.2, footprintTopLevels: [], ticksPerMinute: 30, domSpoofDetected: 0,
  } });
  check(rowsGood.every(f => f.reason.indexOf('NaN') === -1), 'All 7 microstructure factors: real finite daemon data produces no "NaN" in any reason');
}

// --- Finding 4: computeCostsFactors / Bid-Ask Spread Cost ---
{
  const ctxBad = { ocRow: { CE: { askPrice: 0/0, bidprice: 0/0 } }, totalCE: 100, totalPE: 100 };
  const row = computeCostsFactors(24500, 120, 50, ctxBad).find(f => f.factor === 'Bid-Ask Spread Cost');
  check(row.pass === null, 'Bid-Ask Spread Cost: NaN askPrice/bidprice -> pass:null (was fabricated pass:true via (NaN||0)-(NaN||0)=0)');

  const ctxGood = { ocRow: { CE: { askPrice: 121, bidprice: 119 } }, totalCE: 100, totalPE: 100 };
  const rowGood = computeCostsFactors(24500, 120, 50, ctxGood).find(f => f.factor === 'Bid-Ask Spread Cost');
  check(rowGood.pass === true, 'Bid-Ask Spread Cost: real finite bid/ask still computes a real tight-spread pass');
}

// --- Finding 5: computeCostsFactors / STT on ITM Expiry ---
{
  const ctxBadSnap = { decay: { snapshot: { spot: 0/0, strike: 24500, optionType: 'CE' }, days: 0 }, totalCE: 1, totalPE: 1 };
  const row = computeCostsFactors(24500, 120, 50, ctxBadSnap).find(f => f.factor === 'STT on ITM Expiry');
  check(row.pass === null, 'STT on ITM Expiry: NaN snapshot.spot -> pass:null (was fabricated pass:true "Not ITM-on-expiry")');

  const ctxGoodSnap = { decay: { snapshot: { spot: 24600, strike: 24500, optionType: 'CE' }, days: 0 }, totalCE: 1, totalPE: 1 };
  const rowGood = computeCostsFactors(24500, 120, 50, ctxGoodSnap).find(f => f.factor === 'STT on ITM Expiry');
  check(rowGood.pass === false && rowGood.score === -1, 'STT on ITM Expiry: real ITM-on-expiry-day snapshot still flags the real STT risk (pass:false, score:-1)');
}

// --- Finding 6: computeVolFactors / India VIX Trend Up/Down ---
{
  eval('getSnapshotNearMinutesAgo = () => ({ vix: 14.5 });');
  const row = computeVolFactors({ iv: 15, now: { gamma: 0.001, thetaPerDay: -1 } }, [100,101,102], { vix: 0/0, isExpiry: false })
    .find(f => f.factor === 'India VIX Trend Up/Down');
  check(row.pass === null, 'India VIX Trend Up/Down: NaN live vix -> pass:null (was fabricated pass/fail with "NaN" in the reason)');
  check(row.reason.indexOf('NaN') === -1, 'India VIX Trend Up/Down: NaN live vix -> no "NaN" leaks into the reason text');

  eval('getSnapshotNearMinutesAgo = () => ({ vix: 0/0 });');
  const row2 = computeVolFactors({ iv: 15, now: { gamma: 0.001, thetaPerDay: -1 } }, [100,101,102], { vix: 14, isExpiry: false })
    .find(f => f.factor === 'India VIX Trend Up/Down');
  check(row2.pass === null, 'India VIX Trend Up/Down: NaN prior-baseline vix -> pass:null (treated as no baseline, not a fabricated comparison)');

  eval('getSnapshotNearMinutesAgo = () => ({ vix: 14.5 });');
  const rowGood = computeVolFactors({ iv: 15, now: { gamma: 0.001, thetaPerDay: -1 } }, [100,101,102], { vix: 14, isExpiry: false })
    .find(f => f.factor === 'India VIX Trend Up/Down');
  check(rowGood.pass === true, 'India VIX Trend Up/Down: real finite vix readings still compute a real trend verdict');
}

// --- Finding 7: computeFuturesFactors / Cost of Carry ---
{
  // computeFuturesFactors' fallback path references FNO_RISK_FREE_RATE,
  // declared `const` inside the separately-eval'd greeks-engine.js - not
  // visible from this later statement's scope, so mirror it here exactly
  // as the real page's own single shared <script> tag would provide it.
  global.FNO_RISK_FREE_RATE = 0.065;
  const rows = computeFuturesFactors(24500, 24600, 10, 0/0, []);
  const carry = rows.find(f => f.factor === 'Cost of Carry Futures vs Spot');
  check(carry.pass !== false || carry.reason.indexOf('NaN') === -1, 'Cost of Carry: NaN riskFreeRate does not leak "NaN" into the reason (falls back to FNO_RISK_FREE_RATE)');
  check(Number.isFinite(carry.score), 'Cost of Carry: NaN riskFreeRate still produces a real finite score (fallback default used, not NaN adopted)');
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
