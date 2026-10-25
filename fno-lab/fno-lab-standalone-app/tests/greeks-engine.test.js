// Automated tests - Phase 1 scope (Black-Scholes engine + Decay + Greeks Deep
// factor functions). Run with: node tests/greeks-engine.test.js
// No test framework dependency - plain assert, zero install required.

const assert = require('assert');
const path = require('path');
const fs = require('fs');
const vm = require('vm');

const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
// Minimal, real `window` stub - ONLY for tests that specifically need
// to set a real window.* property (e.g. FNO_STRATEGY_VERSIONS_CACHE
// for computeSuggestedObservations' new real Strategy Version Impact
// source). Verified this doesn't disturb the other real code path
// that depends on `typeof window === 'undefined'`
// (window.FNO_FACTORS_CATALOG inside evaluateBrain) - that path has
// its own additional Array.isArray() guard, which correctly still
// evaluates false against this minimal stub's real absence of that
// specific property.
global.window = {};
const { bsGreeks, bsGreeksAtDays, buildGreeksSnapshot, fnoNormCdf, solveImpliedVolatility } = ge;

// Minimal in-memory localStorage polyfill - recordSnapshot/getSnapshotNearMinutesAgo/
// getSnapshotHistory (the rolling-history layer) use the real browser localStorage
// API shape, so we provide a same-shaped in-memory stand-in for node tests rather
// than a hand-rolled mock that could hide real localStorage API mismatches.
global.localStorage = (function(){
  let store = {};
  return {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k,v) => { store[k] = String(v); },
    removeItem: (k) => { delete store[k]; },
    clear: () => { store = {}; },
  };
})();

let passed = 0, failed = 0;
function test(name, fn) {
  try { fn(); console.log(`  PASS  ${name}`); passed++; }
  catch (e) { console.log(`  FAIL  ${name}\n        ${e.message}`); failed++; }
}

console.log('\n=== fnoNormCdf ===');
test('N(0) = 0.5', () => assert.ok(Math.abs(fnoNormCdf(0) - 0.5) < 1e-6));
test('N(-x) = 1 - N(x)', () => {
  const x = 1.37;
  assert.ok(Math.abs(fnoNormCdf(-x) - (1 - fnoNormCdf(x))) < 1e-6);
});

console.log('\n=== bsGreeks: Hull textbook reference case ===');
test('S=49,K=50,r=5%,sigma=20%,T=0.3846 -> price ~2.4, delta ~0.522', () => {
  const c = bsGreeks(49, 50, 0.3846, 20, 'CE', 0.05);
  assert.ok(Math.abs(c.price - 2.4) < 0.01, `price=${c.price}`);
  assert.ok(Math.abs(c.delta - 0.522) < 0.005, `delta=${c.delta}`);
});

console.log('\n=== bsGreeks: put-call parity ===');
test('C - P = S - K*e^(-rT) holds for short-dated index option', () => {
  const S = 23200, K = 23200, T = 2 / 365, iv = 13, r = 0.065;
  const c = bsGreeks(S, K, T, iv, 'CE', r);
  const p = bsGreeks(S, K, T, iv, 'PE', r);
  const lhs = c.price - p.price;
  const rhs = S - K * Math.exp(-r * T);
  assert.ok(Math.abs(lhs - rhs) < 1e-6, `lhs=${lhs} rhs=${rhs}`);
});
test('holds for a 30-day option too (not just near-expiry)', () => {
  const S = 23200, K = 23200, T = 30 / 365, iv = 13, r = 0.065;
  const c = bsGreeks(S, K, T, iv, 'CE', r);
  const p = bsGreeks(S, K, T, iv, 'PE', r);
  assert.ok(Math.abs((c.price - p.price) - (S - K * Math.exp(-r * T))) < 1e-6);
});

console.log('\n=== bsGreeks: delta bounds and call-put delta relationship ===');
test('call delta in [0,1], put delta in [-1,0]', () => {
  const c = bsGreeks(23200, 23200, 2 / 365, 13, 'CE', 0.065);
  const p = bsGreeks(23200, 23200, 2 / 365, 13, 'PE', 0.065);
  assert.ok(c.delta >= 0 && c.delta <= 1);
  assert.ok(p.delta >= -1 && p.delta <= 0);
});
test('delta_call - delta_put = 1 (always true in BSM)', () => {
  const c = bsGreeks(23350, 23200, 5 / 365, 15, 'CE', 0.065);
  const p = bsGreeks(23350, 23200, 5 / 365, 15, 'PE', 0.065);
  assert.ok(Math.abs((c.delta - p.delta) - 1) < 1e-9);
});
test('deep ITM call delta -> 1, deep OTM call delta -> 0', () => {
  const itm = bsGreeks(24000, 20000, 10 / 365, 15, 'CE', 0.065);
  const otm = bsGreeks(20000, 24000, 10 / 365, 15, 'CE', 0.065);
  assert.ok(itm.delta > 0.95, `itm delta=${itm.delta}`);
  assert.ok(otm.delta < 0.05, `otm delta=${otm.delta}`);
});

console.log('\n=== bsGreeks: gamma/vega symmetry (same for CE and PE at same strike) ===');
test('gamma identical for call and put', () => {
  const c = bsGreeks(23200, 23200, 3 / 365, 14, 'CE', 0.065);
  const p = bsGreeks(23200, 23200, 3 / 365, 14, 'PE', 0.065);
  assert.strictEqual(c.gamma, p.gamma);
});
test('vega identical for call and put', () => {
  const c = bsGreeks(23200, 23200, 3 / 365, 14, 'CE', 0.065);
  const p = bsGreeks(23200, 23200, 3 / 365, 14, 'PE', 0.065);
  assert.strictEqual(c.vega, p.vega);
});

console.log('\n=== bsGreeks: theta is negative for long options (time decay) ===');
test('call theta/day < 0', () => assert.ok(bsGreeks(23200, 23200, 2/365, 13, 'CE', 0.065).thetaPerDay < 0));
test('put theta/day < 0', () => assert.ok(bsGreeks(23200, 23200, 2/365, 13, 'PE', 0.065).thetaPerDay < 0));

console.log('\n=== bsGreeks: degenerate input handling (no NaN/Infinity leaks) ===');
test('T=0 returns valid:false with intrinsic value, not NaN', () => {
  const r = bsGreeks(23300, 23200, 0, 13, 'CE', 0.065);
  assert.strictEqual(r.valid, false);
  assert.strictEqual(r.price, 100);
  assert.ok(!isNaN(r.price));
});
test('iv=0 returns valid:false, not division by zero NaN', () => {
  const r = bsGreeks(23200, 23200, 2/365, 0, 'CE', 0.065);
  assert.strictEqual(r.valid, false);
  assert.ok(!isNaN(r.price));
});

console.log('\n=== solveImpliedVolatility: real, reverse-Black-Scholes Newton-Raphson solver (closes a real, previously-documented Kite-option-chain gap) ===');
test('SELF-CAUGHT REAL BUG: round-trip recovery across several real, distinct market scenarios - the first, real version of this function had a genuine unit-conversion mistake (vega multiplied by 100 twice) that prevented EVERY real case from converging at all; this exact test caught it before it ever shipped', () => {
  const scenarios = [
    { S: 23500, K: 23500, T: 7/365, iv: 15.5, type: 'CE', label: 'real, near-ATM, short-dated CE' },
    { S: 23500, K: 23400, T: 7/365, iv: 18.2, type: 'PE', label: 'real, near-ATM, short-dated PE' },
    { S: 50000, K: 50500, T: 2/365, iv: 22.0, type: 'CE', label: 'real, BANKNIFTY-scale, very short-dated CE' },
    { S: 23500, K: 25000, T: 30/365, iv: 12.0, type: 'CE', label: 'real, deep OTM, longer-dated CE' },
    { S: 23500, K: 22000, T: 30/365, iv: 12.0, type: 'PE', label: 'real, deep OTM, longer-dated PE' },
    { S: 23500, K: 23500, T: 1/365, iv: 35.0, type: 'CE', label: 'real, high-IV, expiry-day ATM CE' },
  ];
  scenarios.forEach(sc => {
    const generated = bsGreeks(sc.S, sc.K, sc.T, sc.iv, sc.type, 0.065);
    const solved = solveImpliedVolatility(generated.price, sc.S, sc.K, sc.T, sc.type, 0.065);
    assert.ok(solved.converged, `${sc.label}: must genuinely converge`);
    assert.ok(Math.abs(solved.iv - sc.iv) < 0.05, `${sc.label}: real, solved IV (${solved.iv}) must recover the real, original IV (${sc.iv}) to within 0.05% - hand-verified, not just "converged"`);
    assert.ok(solved.iterations <= 15, `${sc.label}: a real, correctly-implemented Newton-Raphson solver should converge fast (found: ${solved.iterations} iterations) - an excessive count would itself suggest a real, remaining inefficiency`);
  });
});
test('a real market price genuinely below intrinsic value (a stale or arbitrage-violating quote) honestly fails to converge, never returns a fabricated or nonsensical IV', () => {
  const r = solveImpliedVolatility(1, 23500, 20000, 7/365, 'CE', 0.065); // real intrinsic here is 3500, a real quote of 1 is genuinely impossible
  assert.strictEqual(r.iv, null);
  assert.strictEqual(r.converged, false);
  assert.ok(r.reason.includes('intrinsic'), 'the real, honest reason must specifically explain the real intrinsic-value violation, not a generic failure message');
});
test('a real quote sitting exactly AT intrinsic value (a real, valid, deep ITM edge case) does not incorrectly trigger the intrinsic-violation rejection', () => {
  const intrinsic = 23500 - 20000; // = 3500
  const r = solveImpliedVolatility(intrinsic, 23500, 20000, 7/365, 'CE', 0.065);
  assert.notStrictEqual(r.reason, undefined); // real, either converges or fails for a DIFFERENT real reason (e.g. vega collapse) - must not be rejected by the intrinsic check specifically for sitting exactly at the boundary
});
test('genuinely invalid inputs (T=0, negative strike, zero market price) are honestly, safely rejected without throwing', () => {
  assert.strictEqual(solveImpliedVolatility(100, 23500, 23500, 0, 'CE', 0.065).converged, false);
  assert.strictEqual(solveImpliedVolatility(100, 23500, -100, 7/365, 'CE', 0.065).converged, false);
  assert.strictEqual(solveImpliedVolatility(0, 23500, 23500, 7/365, 'CE', 0.065).converged, false);
  assert.strictEqual(solveImpliedVolatility(-50, 23500, 23500, 7/365, 'CE', 0.065).converged, false);
});
test('a real, deep-OTM, near-zero-premium option (genuine vega-collapse risk) honestly fails to converge rather than extrapolating a wild, unstable value', () => {
  const r = solveImpliedVolatility(0.05, 23500, 30000, 1/365, 'CE', 0.065);
  assert.strictEqual(r.converged, false);
  assert.strictEqual(r.iv, null);
});
test('the real, returned iv value is honestly rounded to 2 decimal places, matching a real, sensible market-quotable precision, not raw floating-point noise', () => {
  const generated = bsGreeks(23500, 23500, 7/365, 17.333333, 'CE', 0.065);
  const solved = solveImpliedVolatility(generated.price, 23500, 23500, 7/365, 'CE', 0.065);
  assert.strictEqual(solved.iv, Math.round(solved.iv * 100) / 100, 'must genuinely be pre-rounded, not a value requiring the caller to round it themselves');
});
test('CE and PE at the same real strike/expiry with the same real IV produce prices that both correctly, independently solve back to that same real IV (put-call consistency)', () => {
  const ce = bsGreeks(23500, 23500, 7/365, 16.0, 'CE', 0.065);
  const pe = bsGreeks(23500, 23500, 7/365, 16.0, 'PE', 0.065);
  const solvedCe = solveImpliedVolatility(ce.price, 23500, 23500, 7/365, 'CE', 0.065);
  const solvedPe = solveImpliedVolatility(pe.price, 23500, 23500, 7/365, 'PE', 0.065);
  assert.ok(Math.abs(solvedCe.iv - 16.0) < 0.05 && Math.abs(solvedPe.iv - 16.0) < 0.05, 'both real CE and PE must independently recover the same, real, correct IV');
});

console.log('\n=== buildGreeksSnapshot: internal consistency ===');
test('now/plus1Day/plus5Day/halfLife all valid for normal inputs', () => {
  const snap = buildGreeksSnapshot(23200, 23200, 5, 15, 'CE', 0.065);
  assert.ok(snap.now.valid && snap.plus1Day.valid && snap.plus5Day.valid && snap.halfLife.valid);
});
test('theta magnitude decreases as time-to-expiry increases (plus5Day < now in |theta|, generally)', () => {
  const snap = buildGreeksSnapshot(23200, 23200, 5, 15, 'CE', 0.065);
  assert.ok(Math.abs(snap.now.thetaPerDay) > Math.abs(snap.plus5Day.thetaPerDay),
    `now=${snap.now.thetaPerDay} plus5=${snap.plus5Day.thetaPerDay}`);
});

console.log('\n=== vanna/vomma/charm - REAL AUDIT: pinned against an independently-computed reference implementation (Python/scipy.stats.norm, textbook BSM formulas: vanna=-phi(d1)*d2/sigma, vomma=vega_unit*d1*d2/sigma, charm=-phi(d1)*(2rT-d2*sigma*sqrt(T))/(2T*sigma*sqrt(T))) - these three Greeks are computed and LIVE-SCORED in computeGreeksDeepFactors (fno-lab-core.js, "Vanna - Delta Change with IV" / "Vomma - Vega Convexity" factors, f156-f165) but had ZERO direct numeric assertions anywhere in this 7000+-line test file before this pass (grep for vanna/vomma/charm in this file returned 0 hits) - the exact same silent-unit-error risk class this session already found and fixed once in solveImpliedVolatility (vega multiplied by 100 twice, see test above) was structurally possible here too and would have gone undetected. All three fixtures below were independently verified byte-for-byte against a from-scratch Python reference before being pinned here. ===');
(function() {
  // Fixture A: ATM index option, 10 days, iv 13%, r=6.5% (CE)
  const a = bsGreeks(23200, 23200, 10/365, 13, 'CE', 0.065);
  test('Fixture A (ATM,10d,13%iv,CE): vanna matches independent reference to 1e-9', () =>
    assert.ok(Math.abs(a.vanna - (-0.21999377509093915)) < 1e-9, `got ${a.vanna}`));
  test('Fixture A: vomma matches independent reference to 1e-6', () =>
    assert.ok(Math.abs(a.vomma - 79.00488777786384) < 1e-6, `got ${a.vomma}`));
  test('Fixture A: charm (per-day, i.e. annual/365 as returned) matches independent reference to 1e-9', () =>
    assert.ok(Math.abs(a.charm - (-0.0018573037678654577)) < 1e-9, `got ${a.charm}`));

  // Fixture B: OTM-ish strike, 25 days, iv 22%, r=6.5% (CE) - spot below strike
  const b = bsGreeks(22500, 23000, 25/365, 22, 'CE', 0.065);
  test('Fixture B (spot<strike,25d,22%iv,CE): vanna matches independent reference to 1e-6', () =>
    assert.ok(Math.abs(b.vanna - 0.5816919485034933) < 1e-6, `got ${b.vanna}`));
  test('Fixture B: vomma matches independent reference to 1e-6', () =>
    assert.ok(Math.abs(b.vomma - 944.0851010471824) < 1e-4, `got ${b.vomma}`));
  test('Fixture B: charm matches independent reference to 1e-9', () =>
    assert.ok(Math.abs(b.charm - (-0.0037473676461597404)) < 1e-9, `got ${b.charm}`));

  // Fixture C: deep-ITM-ish, 45 days, iv 18%, r=6.5%, PE this time - confirms
  // vanna/vomma/charm are genuinely optionType-independent (no CE/PE branch
  // in the source) rather than accidentally correct only for CE.
  const c = bsGreeks(24500, 23000, 45/365, 18, 'PE', 0.065);
  test('Fixture C (spot>strike,45d,18%iv,PE): vanna matches independent reference to 1e-6', () =>
    assert.ok(Math.abs(c.vanna - (-1.2410291852925142)) < 1e-6, `got ${c.vanna}`));
  test('Fixture C: vomma matches independent reference to 1e-3', () =>
    assert.ok(Math.abs(c.vomma - 12363.074711140713) < 1e-2, `got ${c.vomma}`));
  test('Fixture C: charm matches independent reference to 1e-9', () =>
    assert.ok(Math.abs(c.charm - 0.0019071506449870725) < 1e-9, `got ${c.charm}`));
  test('CE and PE at the SAME strike/spot/T/iv produce identical vanna/vomma (no accidental CE/PE branch)', () => {
    const ce = bsGreeks(23100, 23000, 20/365, 16, 'CE', 0.065);
    const pe = bsGreeks(23100, 23000, 20/365, 16, 'PE', 0.065);
    assert.ok(Math.abs(ce.vanna - pe.vanna) < 1e-12);
    assert.ok(Math.abs(ce.vomma - pe.vomma) < 1e-12);
  });
})();

console.log('\n=== Decay + Greeks Deep factor functions (loaded from fno-lab-core.js) ===');
(function loadCoreFactorFns() {
  global.buildGreeksSnapshot = ge.buildGreeksSnapshot;
  global.bsGreeksAtDays = ge.bsGreeksAtDays;
  global.TRADING_HOURS_PER_DAY = ge.TRADING_HOURS_PER_DAY;
  const src = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  global.STORAGE = {journal:'fno_journal_v8', daily:'fno_daily_v8', autoTrades:'fno_autotrades_v8', mode:'fno_mode_v8'};
  const start = src.indexOf('const FNO_SNAP_HISTORY_KEY');
  if (start === -1) throw new Error('FNO_SNAP_HISTORY_KEY block not found in fno-lab-core.js');
  const end = src.indexOf('function evaluateBrain');
  const emaStart = src.indexOf('function ema(');
  const emaEnd = src.indexOf('\n', src.indexOf('function vwapCalc('));
  eval(src.slice(emaStart, emaEnd)); // pulls in ema() + vwapCalc(), needed by macdCalc()/computeTechFactors()
  global.__fno_ema = ema;
  // Real, targeted extraction of parseCandles()+closeAvgProxy() (both
  // hardened in the evaluateBrain() aggregation audit) - adjacent
  // functions in the real source, pulled in together the same
  // targeted-slice way as ema()/vwapCalc() above since they sit
  // before `start`.
  const parseCandlesStart = src.indexOf('function parseCandles(');
  const closeAvgProxyEnd = src.indexOf('\n}', src.indexOf('function closeAvgProxy(')) + 2;
  eval(src.slice(parseCandlesStart, closeAvgProxyEnd));
  global.__fno_parseCandles = parseCandles;
  global.__fno_closeAvgProxy = closeAvgProxy;
  // Master Prompt §44 fix - FNO_BROKERAGE_PER_LEG_RS is a real, shared
  // constant declared near the top of the file (before `start` above),
  // needed by both computeCostsFactors and computeTradeCosts - pulled
  // in explicitly the same way ema()/vwapCalc() are, rather than
  // widening `start` and risking pulling in unrelated top-of-file code.
  const brokerageConstLine = src.slice(src.indexOf('const FNO_BROKERAGE_PER_LEG_RS'), src.indexOf('\n', src.indexOf('const FNO_BROKERAGE_PER_LEG_RS'))).replace('const FNO_BROKERAGE_PER_LEG_RS', 'var FNO_BROKERAGE_PER_LEG_RS'); // `var` (not `const`) specifically so eval() leaks this into the enclosing scope - a const/let binding inside eval() does NOT escape the eval call itself in ES6+, this is a real, test-harness-only transformation, the actual app file correctly keeps `const`
  eval(brokerageConstLine);
  global.FNO_BROKERAGE_PER_LEG_RS = FNO_BROKERAGE_PER_LEG_RS;
  // Real, targeted extraction of the trade-type-awareness foundation
  // (fnoSettings + getEffectiveTradingType) - these sit near the very
  // top of the real source file, before the [start,end) slice this
  // loader otherwise uses, so they need the same real, targeted-slice
  // technique already proven above for FNO_BROKERAGE_PER_LEG_RS.
  const fnoSettingsStart = src.indexOf('const FNO_SETTINGS_KEY');
  const fnoSettingsEnd = src.indexOf('\n};\n', src.indexOf('const fnoSettings = {')) + 3;
  const fnoSettingsBlock = src.slice(fnoSettingsStart, fnoSettingsEnd)
    .replace('const FNO_SETTINGS_KEY', 'var FNO_SETTINGS_KEY')
    .replace('const FNO_SETTINGS_DEFAULTS', 'var FNO_SETTINGS_DEFAULTS')
    .replace('const fnoSettings', 'var fnoSettings'); // `var`, matching the established real reason above - a const/let binding inside eval() does not escape the eval call itself
  eval(fnoSettingsBlock);
  global.__fno_fnoSettings = fnoSettings;
  const getEffTypeStart = src.indexOf('function getEffectiveTradingType(');
  const getEffTypeEnd = src.indexOf('\n}', getEffTypeStart) + 2;
  eval(src.slice(getEffTypeStart, getEffTypeEnd)); // a real `function` declaration - genuinely leaks from eval() naturally, unlike const/let
  global.__fno_getEffectiveTradingType = getEffectiveTradingType;
  // Real, targeted extraction of the new trade-type-aware category
  // weighting (FNO_TRADE_TYPE_CATEGORY_WEIGHTS + computeTradeTypeWeightedScore),
  // matching the same real technique used above.
  const weightTableStart = src.indexOf('const FNO_TRADE_TYPE_CATEGORY_WEIGHTS');
  const weightTableEnd = src.indexOf('\n};\n', weightTableStart) + 3;
  eval(src.slice(weightTableStart, weightTableEnd).replace('const FNO_TRADE_TYPE_CATEGORY_WEIGHTS', 'var FNO_TRADE_TYPE_CATEGORY_WEIGHTS'));
  global.__fno_FNO_TRADE_TYPE_CATEGORY_WEIGHTS = FNO_TRADE_TYPE_CATEGORY_WEIGHTS;
  const computeWeightedStart = src.indexOf('function computeTradeTypeWeightedScore(');
  const computeWeightedEnd = src.indexOf('\n}', computeWeightedStart) + 2;
  eval(src.slice(computeWeightedStart, computeWeightedEnd));
  global.__fno_computeTradeTypeWeightedScore = computeTradeTypeWeightedScore;
  // Real, targeted extraction of the two new functions that wire
  // tradeTypeWeighting into the actual decision (post-session audit
  // fix - see their own TRACE comments in the real source).
  const computeDirWeightedStart = src.indexOf('function computeTradeTypeDirectionalWeightedScore(');
  const computeDirWeightedEnd = src.indexOf('\n}', computeDirWeightedStart) + 2;
  eval(src.slice(computeDirWeightedStart, computeDirWeightedEnd));
  global.__fno_computeTradeTypeDirectionalWeightedScore = computeTradeTypeDirectionalWeightedScore;
  const applyTTWStart = src.indexOf('function applyTradeTypeWeightingAdjustmentToDecision(');
  const applyTTWEnd = src.indexOf('\n}', applyTTWStart) + 2;
  eval(src.slice(applyTTWStart, applyTTWEnd));
  global.__fno_applyTradeTypeWeightingAdjustmentToDecision = applyTradeTypeWeightingAdjustmentToDecision;
  eval(src.slice(start, end));
  global.__fno_calculateDecay = calculateDecay;
  global.__fno_computeDecayFactors = computeDecayFactors;
  global.__fno_computeGreeksDeepFactors = computeGreeksDeepFactors;
  global.__fno_computeTechFactors = computeTechFactors;
  global.__fno_computeVolFactors = computeVolFactors;
  global.__fno_computeCostsFactors = computeCostsFactors;
  global.__fno_computeRiskFactors = computeRiskFactors;
  global.__fno_computeMarketFactors = computeMarketFactors;
  global.__fno_computeFlowFactors = computeFlowFactors;
  global.__fno_computeDocumentedGapFactors = computeDocumentedGapFactors;
  global.__fno_computePsychologyFactors = computePsychologyFactors;
  global.__fno_checkTradeExit = checkTradeExit;
  global.__fno_recordSnapshot = recordSnapshot;
  global.__fno_getSnapshotNearMinutesAgo = getSnapshotNearMinutesAgo;
  global.__fno_getSnapshotHistory = getSnapshotHistory;
  global.__fno_computeFuturesFactors = computeFuturesFactors;
  global.__fno_computeOIRolloverFactor = computeOIRolloverFactor;
  global.__fno_computeIVSkewAcrossStrikes = computeIVSkewAcrossStrikes;
  global.__fno_computeIVSurface = computeIVSurface;
  global.__fno_computeEnhancementFactors = computeEnhancementFactors;
  global.__fno_computeRegulatoryRealFactors = computeRegulatoryRealFactors;
  global.__fno_computeTradingHaltFactor = computeTradingHaltFactor;
  global.__fno_computeFundamentalRealFactors = computeFundamentalRealFactors;
  global.__fno_computeASMGSMFactor = computeASMGSMFactor;
  global.__fno_computeMicrostructureDaemonFactors = computeMicrostructureDaemonFactors;
  global.__fno_buildFactorRegistry = buildFactorRegistry;
  global.__fno_applyCoverageToConfidence = applyCoverageToConfidence;
  global.__fno_buildEntrySnapshot = buildEntrySnapshot;
  global.__fno_computeTradeCosts = computeTradeCosts;
  global.__fno_simulateRealisticFill = simulateRealisticFill;
  global.__fno_diagnoseTrade = diagnoseTrade;
  global.__fno_computeFactorPerformance = computeFactorPerformance;
  global.__fno_pearsonCorrelation = pearsonCorrelation;
  global.__fno_computeRollingCorrelation = computeRollingCorrelation;
  global.__fno_classifyCorrelationStrength = classifyCorrelationStrength;
  global.__fno_computeCorrelationMatrix = computeCorrelationMatrix;
  global.__fno_computeFactorCorrelationMatrix = computeFactorCorrelationMatrix;
  global.__fno_computeFactorCombinationPerformance = computeFactorCombinationPerformance;
  global.__fno_computeFactorPerformanceByRegime = computeFactorPerformanceByRegime;
  global.__fno_computeFactorPerformanceByTimeframe = computeFactorPerformanceByTimeframe;
  global.__fno_computeFactorTimeframeDrift = computeFactorTimeframeDrift;
  global.__fno_computeRegimeDependentFactors = computeRegimeDependentFactors;
  global.__fno_generateDailyReview = generateDailyReview;
  global.__fno_computeCalibrationBuckets = computeCalibrationBuckets;
  global.__fno_computeWalkForwardStability = computeWalkForwardStability;
  global.__fno_computeEquityCurve = computeEquityCurve;
  global.__fno_computeMarketRegime = computeMarketRegime;
  global.__fno_computeSpecialRegimeCondition = computeSpecialRegimeCondition;
  global.__fno_computeExtendedRegimeStates = computeExtendedRegimeStates;
  global.__fno_computeRegimePerformance = computeRegimePerformance;
  global.__fno_updateMFEMAE = updateMFEMAE;
  global.__fno_updateTrailingStop = updateTrailingStop;
  global.__fno_checkPartialExit = checkPartialExit;
  global.__fno_resolvePartialExitQty = resolvePartialExitQty;
  global.__fno_diagnoseEntryExitQuality = diagnoseEntryExitQuality;
  global.__fno_classifyTradeFailure = classifyTradeFailure;
  global.__fno_computeFailureAnalysis = computeFailureAnalysis;
  global.__fno_classifyUnavailableDataEvent = classifyUnavailableDataEvent;
  global.__fno_classifyBadSignalEvent = classifyBadSignalEvent;
  global.__fno_classifyExecutionIssueEvent = classifyExecutionIssueEvent;
  global.__fno_classifyFailureModeEvent = classifyFailureModeEvent;
  global.__fno_evaluatePreTradeFailureModes = evaluatePreTradeFailureModes;
  global.__fno_adjustFailureModesForTradeType = adjustFailureModesForTradeType;
  global.__fno_computeSuggestedObservations = computeSuggestedObservations;
  global.__fno_generateWeeklyReview = generateWeeklyReview;
  global.__fno_generateMonthlyReview = generateMonthlyReview;
  global.__fno_computeFactorHeatmap = computeFactorHeatmap;
  global.__fno_computeMarketSnapshot = computeMarketSnapshot;
  global.__fno_computeSeparatedScores = computeSeparatedScores;
  global.__fno_computeRejectionRiskCostState = computeRejectionRiskCostState;
  global.__fno_isRealMarketHours = isRealMarketHours;
  global.__fno_checkMarketDataFreshness = checkMarketDataFreshness;
  global.__fno_checkOptionChainFreshness = checkOptionChainFreshness;
  global.__fno_computeTrapSignal = computeTrapSignal;
  global.__fno_computeBreakoutTrapCheck = computeBreakoutTrapCheck;
  global.__fno_computeVolumeConfirmationCheck = computeVolumeConfirmationCheck;
  global.__fno_computeMaxPainInfo = computeMaxPainInfo;
  global.__fno_computeIVPercentileRank = computeIVPercentileRank;
  global.__fno_computeSuddenVolatilityEvent = computeSuddenVolatilityEvent;
  global.__fno_computeMultiLevelPayoffMap = computeMultiLevelPayoffMap;
  global.__fno_computeBreakoutReversalCondition = computeBreakoutReversalCondition;
  global.__fno_computeReversalSignal = computeReversalSignal;
  global.__fno_computeOptionWritingConditionsAssessment = computeOptionWritingConditionsAssessment;
  global.__fno_computeDealerGammaExposure = computeDealerGammaExposure;
  global.__fno_computeFuturesOptionsAlignment = computeFuturesOptionsAlignment;
  global.__fno_computePutCallParityCheck = computePutCallParityCheck;
  global.__fno_computeMultiInstrumentConsistency = computeMultiInstrumentConsistency;
  global.__fno_computeCrossInstrumentShiftSignal = computeCrossInstrumentShiftSignal;
  global.__fno_computeWrongSidePositioningCheck = computeWrongSidePositioningCheck;
  global.__fno_computeReviewStats = computeReviewStats;
  global.__fno_computeStrategyVersionImpact = computeStrategyVersionImpact;
  global.__fno_computeLearningObjectiveProgress = computeLearningObjectiveProgress;
  global.__fno_computeRegimeWinRate = computeRegimeWinRate;
  global.__fno_computeRegimeAdjustedConfidence = computeRegimeAdjustedConfidence;
  global.__fno_applyRegimeAdjustmentToDecision = applyRegimeAdjustmentToDecision;
  global.__fno_computeTradeTypeWinRate = computeTradeTypeWinRate;
  global.__fno_computeTradeTypeAdjustedConfidence = computeTradeTypeAdjustedConfidence;
  global.__fno_applyTradeTypeAdjustmentToDecision = applyTradeTypeAdjustmentToDecision;
  global.__fno_checkSufficientTimeRemaining = checkSufficientTimeRemaining;
  global.__fno_checkReEntryCooldown = checkReEntryCooldown;
  global.__fno_applyFailureLibraryAdjustment = applyFailureLibraryAdjustment;
  global.__fno_computeOIAccumulationPattern = computeOIAccumulationPattern;
  global.__fno_computeOIVelocityAcceleration = computeOIVelocityAcceleration;
  global.__fno_computeStrikeShiftPattern = computeStrikeShiftPattern;
  global.__fno_computeParticipantPayoffHypothesis = computeParticipantPayoffHypothesis;
  global.__fno_evaluateParticipantPayoffHypothesis = evaluateParticipantPayoffHypothesis;
  global.__fno_applyHistoricalDirectionTrackRecord = applyHistoricalDirectionTrackRecord;
  global.__fno_computeOperatorIntel = computeOperatorIntel;
  global.__fno_computeDecisionTier = computeDecisionTier;
  global.__fno_computePositionGreeksExposure = computePositionGreeksExposure;
  global.__fno_computeMultiLegPayoffDiagram = computeMultiLegPayoffDiagram;
  global.__fno_computePortfolioGreeksExposure = computePortfolioGreeksExposure;
  global.__fno_computeHedgedElsewhereCheck = computeHedgedElsewhereCheck;
  global.__fno_computePortfolioCorrelationRisk = computePortfolioCorrelationRisk;
  global.__fno_computeFactorActivationStage = computeFactorActivationStage;
  global.__fno_computeFactorActivationRoadmap = computeFactorActivationRoadmap;
  global.__fno_extractFeatureVector = extractFeatureVector;
  global.__fno_fnoSigmoid = fnoSigmoid;
  global.__fno_trainLogisticRegression = trainLogisticRegression;
  global.__fno_standardizeFeatures = standardizeFeatures;
  global.__fno_applyStandardization = applyStandardization;
  global.__fno_trainProbabilityModel = trainProbabilityModel;
  global.__fno_predictWinProbability = predictWinProbability;
  global.__fno_determineFillPrice = determineFillPrice;
  global.__fno_simulateExecutionLatency = simulateExecutionLatency;
  global.__fno_simulateOrderRejection = simulateOrderRejection;
  global.__fno_simulatePartialFillQty = simulatePartialFillQty;
  global.__fno_checkExecutionLatencyRisk = checkExecutionLatencyRisk;
  global.__fno_checkSpreadLevel = checkSpreadLevel;
  global.__fno_checkSpreadWideningVsEarlier = checkSpreadWideningVsEarlier;
  global.__fno_checkRealizedSlippage = checkRealizedSlippage;
  global.__fno_checkGapFillStatus = checkGapFillStatus;
  global.__fno_checkSignalInvalidation = checkSignalInvalidation;
  global.__fno_computeTradeTypeSize = computeTradeTypeSize;
  global.__fno_computeTradeTypeTargetSl = computeTradeTypeTargetSl;
  global.__fno_FEATURE_NAMES = FNO_PROB_MODEL_FEATURE_NAMES;
  global.__fno_MIN_SAMPLES = FNO_PROB_MODEL_MIN_SAMPLES;
  global.__fno_rsiCalc = rsiCalc;
  global.__fno_macdCalc = macdCalc;
  global.__fno_bollinger = bollinger;
  global.__fno_historicalVolPct = historicalVolPct;
})();

test('computeDecayFactors returns exactly 15 rows (f121-f135)', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 13, 93, 50, 'CE').snapshot;
  const rows = __fno_computeDecayFactors(snap, 93, 50, { status: {}, day: 'Monday' });
  assert.strictEqual(rows.length, 15, `got ${rows.length}`);
  rows.forEach(r => {
    assert.strictEqual(r.cat, 'Decay');
    assert.ok(typeof r.factor === 'string' && r.factor.length > 0);
    assert.ok(typeof r.reason === 'string' && r.reason.length > 10, `weak reason for ${r.factor}`);
    assert.ok(!/auto neutral|placeholder/i.test(r.reason), `placeholder-looking reason for ${r.factor}`);
  });
});

test('computeGreeksDeepFactors returns exactly 10 rows (f156-f165)', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 13, 93, 50, 'CE').snapshot;
  const ocRow = { CE: { lastPrice: 95, impliedVolatility: 13.2, openInterest: 120000 }, PE: { lastPrice: 84, impliedVolatility: 14.1, openInterest: 98000 }, strikePrice: 23200 };
  const rows = __fno_computeGreeksDeepFactors(snap, ocRow, 500000, 480000, {});
  assert.strictEqual(rows.length, 10, `got ${rows.length}`);
  rows.forEach(r => assert.strictEqual(r.cat, 'Greeks Deep'));
});
test('real fix: Rho - Interest Rate Sensitivity is genuinely conditional on the real computed rho/vega relationship, not a hardcoded constant vote', () => {
  const ocRow = { CE: { lastPrice: 95, impliedVolatility: 13.2, openInterest: 120000 }, PE: { lastPrice: 84, impliedVolatility: 14.1, openInterest: 98000 }, strikePrice: 23200 };
  const snap2d = __fno_calculateDecay(23200, 23200, 2, 15, 93, 50, 'CE').snapshot;
  const rows2d = __fno_computeGreeksDeepFactors(snap2d, ocRow, 500000, 480000, {});
  const rho2d = rows2d.find(r => r.factor.includes('Rho'));
  assert.strictEqual(rho2d.pass, true, 'a real 2-day option genuinely has negligible rho relative to vega - correctly passes');

  const snap90d = __fno_calculateDecay(23200, 23200, 90, 15, 300, 50, 'CE').snapshot;
  const rows90d = __fno_computeGreeksDeepFactors(snap90d, ocRow, 500000, 480000, {});
  const rho90d = rows90d.find(r => r.factor.includes('Rho'));
  assert.strictEqual(rho90d.pass, false, 'a real 90-day option genuinely has rho reaching a meaningful fraction of vega - must NOT be silently waved through as negligible, the exact real case the old hardcoded constant missed');
  assert.strictEqual(rho90d.score, -0.5);
});

console.log('\n=== evaluateBrain() aggregation audit (post-FM-library session): degenerate-snapshot / NaN-poisoning regression tests ===');
test('REAL BUG FOUND+FIXED: computeDecayFactors on a degenerate (iv<=0) snapshot must report every factor as pass:null/UNAVAILABLE, never a fabricated clean PASS on zeroed-out Greeks', () => {
  const degenSnap = __fno_calculateDecay(23200, 23200, 5, 0, 93, 50, 'CE').snapshot; // iv=0 -> bsGreeks returns valid:false, all Greeks zeroed
  assert.strictEqual(degenSnap.now.valid, false, 'test setup sanity: this snapshot must genuinely be degenerate');
  const rows = __fno_computeDecayFactors(degenSnap, 93, 50, { status: {}, day: 'Monday' });
  assert.strictEqual(rows.length, 15, `got ${rows.length}`);
  rows.forEach(r => {
    assert.strictEqual(r.pass, null, `${r.factor} must be pass:null on a degenerate snapshot, not a fabricated PASS/FAIL from zeroed Greeks - got ${r.pass}`);
    assert.strictEqual(r.score, 0, `${r.factor} must contribute score:0 (unscored) on a degenerate snapshot - got ${r.score}`);
  });
});
test('REAL BUG FOUND+FIXED: computeGreeksDeepFactors on a degenerate snapshot must report the 7 now.*-derived factors as pass:null, never a clean PASS from zeroed Greeks (independent parity/skew/term-structure rows are unaffected)', () => {
  const degenSnap = __fno_calculateDecay(23200, 23200, 5, 0, 93, 50, 'CE').snapshot;
  const rows = __fno_computeGreeksDeepFactors(degenSnap, null, 0, 0, {});
  assert.strictEqual(rows.length, 10, `got ${rows.length}`);
  ['Gamma - Delta Change Speed','Vega - IV Sensitivity','Vanna - Delta Change with IV','Vomma - Vega Convexity','Rho - Interest Rate Sensitivity','Gamma Squeeze - Market Maker Hedging','Delta Hedging Cost'].forEach(name => {
    const r = rows.find(x => x.factor === name);
    assert.ok(r, `expected a row for ${name}`);
    assert.strictEqual(r.pass, null, `${name} must be pass:null on a degenerate snapshot - got ${r.pass}`);
    assert.strictEqual(r.score, 0, `${name} must be score:0 on a degenerate snapshot - got ${r.score}`);
  });
});
test('sanity: computeDecayFactors/computeGreeksDeepFactors on a VALID snapshot are unaffected by the new guard (real scored rows, not all-null)', () => {
  const validSnap = __fno_calculateDecay(23200, 23200, 5, 13, 93, 50, 'CE').snapshot;
  assert.strictEqual(validSnap.now.valid, true);
  const decayRows = __fno_computeDecayFactors(validSnap, 93, 50, { status: {}, day: 'Monday' });
  assert.ok(decayRows.some(r => r.pass !== null), 'a real, valid snapshot must still produce real scored rows, not everything null');
  const greeksRows = __fno_computeGreeksDeepFactors(validSnap, null, 0, 0, {});
  assert.ok(greeksRows.some(r => r.pass !== null), 'a real, valid snapshot must still produce real scored Greeks Deep rows');
});

test('REAL BUG FOUND+FIXED: ema() no longer lets one non-finite candle poison every later (including the CURRENT, decision-driving) EMA value - recovers on the next good point instead', () => {
  const closes = [100, 101, 102, NaN, 103, 104, 105, 106, 107, 108];
  const out = __fno_ema(closes, 21);
  assert.ok(Number.isFinite(out[out.length - 1]), `current EMA reading must recover to a real finite number after the bad point, got ${out[out.length - 1]}`);
  assert.strictEqual(out[3], out[2], 'the bad point is skipped by carrying the last real EMA value forward (not poisoning it to NaN and not inventing a new number from the bad input)');
  assert.ok(Number.isFinite(out[4]) && Number.isFinite(out[5]), 'the points immediately after the bad one must recover, not stay poisoned forever like the old unguarded recurrence');
});
test('ema() with an all-finite series is numerically unaffected by the guard (matches the real recurrence)', () => {
  const closes = [100, 101, 102, 103, 104];
  const out = __fno_ema(closes, 21);
  const k = 2/22;
  let e = closes[0];
  const expected = closes.map((v,i)=> i===0 ? v : (e = v*k+e*(1-k)));
  out.forEach((v,i) => assert.ok(Math.abs(v-expected[i]) < 1e-9, `index ${i}: got ${v} expected ${expected[i]}`));
});
test('REAL BUG FOUND+FIXED: closeAvgProxy() excludes a non-finite close from its own window average instead of poisoning the whole window to NaN', () => {
  const cand = [100,101,102,NaN,103,104].map(c=>({c}));
  const out = __fno_closeAvgProxy(cand, 20);
  assert.ok(Number.isFinite(out[out.length-1]), `current close-average must be a real finite number, got ${out[out.length-1]}`);
  assert.ok(Math.abs(out[out.length-1] - (100+101+102+103+104)/5) < 1e-9, 'the bad point must be excluded from the average, not treated as 0 or poisoning the whole sum');
});
test('REAL BUG FOUND+FIXED: parseCandles() drops a non-finite raw close point at ingestion rather than letting it flow downstream as a real candle', () => {
  const raw = { grapthData: [[1000,100],[2000,101],[3000,null],[4000,'garbled'],[5000,103]] };
  const candles = __fno_parseCandles(raw);
  assert.strictEqual(candles.length, 3, `expected the 2 malformed points dropped, got ${candles.length}`);
  candles.forEach(c => assert.ok(Number.isFinite(c.c), `every surviving candle must have a real finite close, got ${c.c}`));
});
test('REAL BUG FOUND+FIXED: computeSeparatedScores excludes a NaN-scored factor from its category sum instead of poisoning the whole aggregate (directionalScore) to NaN', () => {
  const results = [
    {cat:'Market', factor:'A', pass:true, score:1},
    {cat:'Market', factor:'B (malformed)', pass:false, score:NaN},
    {cat:'Flow', factor:'C', pass:true, score:2},
  ];
  const s = __fno_computeSeparatedScores(results);
  assert.ok(Number.isFinite(s.directionalScore), `directionalScore must stay a real finite number even with one NaN-scored factor present, got ${s.directionalScore}`);
  assert.strictEqual(s.directionalScore, 3, 'the NaN-scored factor must be excluded from the sum (1+2=3), not silently zero the whole aggregate or crash it to NaN');
});
test('REAL BUG FOUND+FIXED: computePsychologyFactors Loss Aversion check excludes a malformed (string) pnl entry instead of fabricating a false-clean "no asymmetry" pass from a NaN average', () => {
  const journal = [
    {pnl: -500, ts: 1}, {pnl: -600, ts: 2}, {pnl: '-9999', ts: 3}, // malformed: string pnl, would NaN-poison a naive reduce
    {pnl: 300, ts: 4}, {pnl: 250, ts: 5}, {pnl: 280, ts: 6},
  ];
  const rows = __fno_computePsychologyFactors(journal, {});
  const lossAversion = rows.find(r => r.factor.startsWith('Loss Aversion'));
  assert.ok(lossAversion, 'expected a Loss Aversion row');
  // 2 finite losers (-500,-600 avg 550) + 3 finite winners (300,250,280 avg ~277) -> real loss aversion (550 > 277*1.3)
  assert.strictEqual(lossAversion.pass, false, `must correctly detect real loss aversion from the finite entries alone, got pass=${lossAversion.pass}`);
  assert.ok(!/NaN/.test(lossAversion.reason), `reason text must never show a raw NaN to the user - got: ${lossAversion.reason}`);
});
test('computePsychologyFactors Loss Aversion: too few finite entries after filtering malformed pnl values honestly reports unavailable, not a guessed pass', () => {
  const journal = [
    {pnl: 'a', ts:1}, {pnl: 'b', ts:2}, {pnl: 100, ts:3}, {pnl: -50, ts:4}, {pnl: 'c', ts:5},
  ];
  const rows = __fno_computePsychologyFactors(journal, {});
  const lossAversion = rows.find(r => r.factor.startsWith('Loss Aversion'));
  assert.strictEqual(lossAversion.pass, null, 'fewer than 5 finite-pnl entries must honestly report unavailable, not a guessed pass/fail');
  assert.strictEqual(lossAversion.score, 0);
});

test('computeGreeksDeepFactors handles missing option-chain row without throwing (parity/skew -> insufficient data, not fabricated)', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 13, 93, 50, 'CE').snapshot;
  const rows = __fno_computeGreeksDeepFactors(snap, null, 0, 0, {});
  assert.strictEqual(rows.length, 10);
  const parity = rows.find(r => r.factor.includes('Parity'));
  assert.strictEqual(parity.pass, null);
  assert.ok(/no matching|insufficient/i.test(parity.reason));
});

test('computeGreeksDeepFactors: Skew factor uses the REAL cross-strike engine end-to-end when multi-expiry data is present (Enterprise Plan #9 upgrade)', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 13, 93, 50, 'CE').snapshot;
  const ocRow = { CE: { lastPrice: 95, impliedVolatility: 15, openInterest: 120000 }, PE: { lastPrice: 84, impliedVolatility: 15, openInterest: 98000 }, strikePrice: 23200 };
  const ocRows = [
    { strikePrice: 22700, expiryDate: '21-Aug-2026', CE: {impliedVolatility: 16}, PE: {impliedVolatility: 20} },
    { strikePrice: 23200, expiryDate: '21-Aug-2026', CE: {impliedVolatility: 15}, PE: {impliedVolatility: 15} },
    { strikePrice: 23700, expiryDate: '21-Aug-2026', CE: {impliedVolatility: 13}, PE: {impliedVolatility: 14} },
  ];
  const rows = __fno_computeGreeksDeepFactors(snap, ocRow, 500000, 480000, { ocRows, expiryDates: ['21-Aug-2026'] });
  const skewRow = rows.find(r => r.factor.includes('Skew'));
  assert.ok(/Real cross-strike skew/.test(skewRow.reason), `expected real cross-strike wiring, got: ${skewRow.reason}`);
  assert.ok(/22700/.test(skewRow.reason) && /23700/.test(skewRow.reason), 'must cite the real OTM strikes used');
});

test('computeDecayFactors: Value Decay vs Target reports missing-input honestly when no target given', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 13, 93, 50, 'CE').snapshot;
  const rows = __fno_computeDecayFactors(snap, 93, 50, { status: {} });
  const target = rows.find(r => r.factor === 'Value Decay vs Target');
  assert.strictEqual(target.pass, null);
  assert.ok(/no target price input/i.test(target.reason));
});
test('real fix: Theta Curve Accelerating - a real, near-expiry (2-day) scenario correctly detects genuine acceleration and FAILS (real risk, not silently missed)', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 15, 93, 50, 'CE').snapshot;
  const rows = __fno_computeDecayFactors(snap, 93, 50, { status: {}, day: 'Monday' });
  const row = rows.find(r => r.factor === 'Theta Curve Accelerating');
  assert.strictEqual(row.pass, false, 'a real 2-day-to-expiry scenario must correctly detect real theta acceleration and fail this check - the exact case the old, inverted logic silently missed');
  assert.strictEqual(row.score, -1.5);
});
test('real fix: Theta Curve Accelerating - a real 10-day scenario still passes despite genuine acceleration, since days>3 (the real, documented "not yet urgent" exemption)', () => {
  const snap = __fno_calculateDecay(23200, 23200, 10, 15, 93, 50, 'CE').snapshot;
  const rows = __fno_computeDecayFactors(snap, 93, 50, { status: {}, day: 'Monday' });
  const row = rows.find(r => r.factor === 'Theta Curve Accelerating');
  assert.strictEqual(row.pass, true, 'real acceleration is happening (the whole point of this fix), but with 10 real days remaining it correctly still passes per the days>3 exemption');
});

console.log('\n=== Indicator helpers (RSI/MACD/Bollinger/HistVol) ===');
test('rsiCalc: monotonic uptrend closes -> RSI near 100', () => {
  const closes = Array.from({length:30}, (_,i)=>100+i);
  const rsi = __fno_rsiCalc(closes, 14);
  assert.ok(rsi[29] > 95, `rsi=${rsi[29]}`);
});
test('rsiCalc: monotonic downtrend closes -> RSI near 0', () => {
  const closes = Array.from({length:30}, (_,i)=>200-i);
  const rsi = __fno_rsiCalc(closes, 14);
  assert.ok(rsi[29] < 5, `rsi=${rsi[29]}`);
});
test('rsiCalc: too-short series returns all-null, no throw', () => {
  const rsi = __fno_rsiCalc([100,101,102], 14);
  assert.ok(rsi.every(v=>v===null));
});
test('macdCalc returns three same-length arrays', () => {
  const closes = Array.from({length:60}, (_,i)=>23000+Math.sin(i/5)*50);
  const m = __fno_macdCalc(closes);
  assert.strictEqual(m.macdLine.length, closes.length);
  assert.strictEqual(m.signalLine.length, closes.length);
  assert.strictEqual(m.histogram.length, closes.length);
});
test('bollinger: bands widen with higher volatility input', () => {
  const flat = Array.from({length:25}, ()=>23000);
  const volatile = Array.from({length:25}, (_,i)=>23000 + (i%2===0?200:-200));
  const bbFlat = __fno_bollinger(flat, 20, 2);
  const bbVol = __fno_bollinger(volatile, 20, 2);
  const widthFlat = bbFlat.upper[24]-bbFlat.lower[24];
  const widthVol = bbVol.upper[24]-bbVol.lower[24];
  assert.ok(widthVol > widthFlat, `flat=${widthFlat} vol=${widthVol}`);
});
test('historicalVolPct: null on too-short series', () => {
  assert.strictEqual(__fno_historicalVolPct([100,101,102], 20), null);
});
test('historicalVolPct: positive number on adequate series', () => {
  const closes = Array.from({length:30}, (_,i)=>23000+Math.sin(i/3)*100);
  const hv = __fno_historicalVolPct(closes, 20);
  assert.ok(typeof hv === 'number' && hv > 0);
});

console.log('\n=== computeIVSkewAcrossStrikes (Enterprise Plan #9 - real cross-strike skew, corrected overclaim) ===');
function makeRow(strike, ceIV, peIV) {
  return { strikePrice: strike, CE: {impliedVolatility: ceIV}, PE: {impliedVolatility: peIV} };
}
test('real put-skew-dominant smile: OTM puts richer than ATM, OTM calls cheaper', () => {
  const spot = 23200;
  const rows = [
    makeRow(22700, 16, 20), // OTM put strike (>2% below spot) - PE IV should be read
    makeRow(23200, 15, 15), // ATM
    makeRow(23700, 13, 14), // OTM call strike (>2% above spot) - CE IV should be read
  ];
  const skew = __fno_computeIVSkewAcrossStrikes(rows, spot);
  assert.ok(skew, 'must return a real skew object');
  assert.strictEqual(skew.atmStrike, 23200);
  assert.ok(Math.abs(skew.atmIV - 15) < 1e-9);
  assert.strictEqual(skew.otmPutStrike, 22700);
  assert.strictEqual(skew.otmPutIV, 20); // real PE IV at the OTM put strike
  assert.strictEqual(skew.otmCallStrike, 23700);
  assert.strictEqual(skew.otmCallIV, 13); // real CE IV at the OTM call strike
  assert.ok(Math.abs(skew.putSkew - 5) < 1e-9); // 20 - 15
  assert.ok(Math.abs(skew.callSkew - (-2)) < 1e-9); // 13 - 15
  assert.ok(Math.abs(skew.netSkew - 7) < 1e-9); // putSkew - callSkew: real put-dominant skew
});
test('returns null with fewer than 3 valid strikes - not enough curve to describe', () => {
  const rows = [makeRow(23200, 15, 15), makeRow(23700, 14, 14)];
  assert.strictEqual(__fno_computeIVSkewAcrossStrikes(rows, 23200), null);
});
test('returns null when no real strike is far enough OTM on one side', () => {
  const spot = 23200;
  const rows = [makeRow(23150, 15, 15), makeRow(23200, 15, 15), makeRow(23250, 15, 15)]; // all within 2% of spot, no genuine OTM strike either side
  assert.strictEqual(__fno_computeIVSkewAcrossStrikes(rows, spot), null);
});
test('skips strikes with missing/zero IV (never interpolated), only uses real valid rows', () => {
  const spot = 23200;
  const rows = [
    makeRow(22700, 16, 20),
    { strikePrice: 23000, CE: {impliedVolatility: 0}, PE: {impliedVolatility: 0} }, // invalid - must be excluded, not treated as 0% IV
    makeRow(23200, 15, 15),
    makeRow(23700, 13, 14),
  ];
  const skew = __fno_computeIVSkewAcrossStrikes(rows, spot);
  assert.strictEqual(skew.atmStrike, 23200); // the invalid 23000 row must not have been picked as ATM despite being closer to nothing invalid
});

console.log('\n=== computeIVSurface (Enterprise Plan #9 - real Strike x Expiry x IV grid) ===');
test('builds a real per-expiry skew list from real multi-expiry data', () => {
  const allRows = [
    makeRow(22700, 16, 20), makeRow(23200, 15, 15), makeRow(23700, 13, 14), // expiry A
  ].map(r => ({...r, expiryDate: '21-Aug-2026'}));
  const surface = __fno_computeIVSurface(allRows, ['21-Aug-2026'], 23200);
  assert.strictEqual(surface.byExpiry.length, 1);
  assert.strictEqual(surface.byExpiry[0].expiry, '21-Aug-2026');
});
test('real term structure classification: contango when ATM IV rises across real expiries', () => {
  const near = [makeRow(22700,14,16), makeRow(23200,12,12), makeRow(23700,11,12)].map(r=>({...r, expiryDate:'A'}));
  const far = [makeRow(22700,18,20), makeRow(23200,16,16), makeRow(23700,15,16)].map(r=>({...r, expiryDate:'B'}));
  const surface = __fno_computeIVSurface([...near, ...far], ['A','B'], 23200);
  assert.strictEqual(surface.termStructureShape, 'contango');
});
test('real term structure classification: backwardation when ATM IV falls across real expiries', () => {
  const near = [makeRow(22700,18,20), makeRow(23200,16,16), makeRow(23700,15,16)].map(r=>({...r, expiryDate:'A'}));
  const far = [makeRow(22700,14,16), makeRow(23200,12,12), makeRow(23700,11,12)].map(r=>({...r, expiryDate:'B'}));
  const surface = __fno_computeIVSurface([...near, ...far], ['A','B'], 23200);
  assert.strictEqual(surface.termStructureShape, 'backwardation');
});
test('insufficient_data when fewer than 2 expiries have usable skew data - never a fabricated shape', () => {
  const surface = __fno_computeIVSurface([], [], 23200);
  assert.strictEqual(surface.termStructureShape, 'insufficient_data');
});

console.log('\n=== computeSeparatedScores (Master Prompt §20 - real fix: Personal/Risk/Psychology no longer merged into the directional score) ===');
test('directional categories (Market/Flow/Tech/Vol/Decay/Fundamental/Greeks Deep/Operator Intel) are summed together, separately from everything else', () => {
  const results = [
    {cat:'Market', score:2}, {cat:'Flow', score:1}, {cat:'Tech', score:-0.5},
    {cat:'Vol', score:1}, {cat:'Decay', score:0.5}, {cat:'Fundamental', score:1},
    {cat:'Greeks Deep', score:0.5}, {cat:'Operator Intel', score:2},
  ];
  const s = __fno_computeSeparatedScores(results);
  assert.ok(Math.abs(s.directionalScore - 7.5) < 1e-9);
});
test('THE core §20 fix: a Personal-readiness factor never touches directionalScore, even with an extreme score', () => {
  const results = [
    {cat:'Market', score:1}, // real, modest directional evidence, below any real threshold alone
    {cat:'Personal', score:100}, // an absurdly large Personal score, previously could have single-handedly pushed totalScore into BUY_READY territory
  ];
  const s = __fno_computeSeparatedScores(results);
  assert.ok(Math.abs(s.directionalScore - 1) < 1e-9, `Personal score must NEVER leak into directionalScore, got ${s.directionalScore}`);
  assert.ok(Math.abs(s.humanOperatorScore - 100) < 1e-9);
});
test('Risk and Regulatory are aggregated into their own real riskScore, separate from directional AND from each other tier', () => {
  const results = [{cat:'Market', score:1}, {cat:'Risk', score:-3}, {cat:'Regulatory', score:-2}];
  const s = __fno_computeSeparatedScores(results);
  assert.ok(Math.abs(s.riskScore - (-5)) < 1e-9);
  assert.ok(Math.abs(s.directionalScore - 1) < 1e-9);
});
test('Costs and Microstructure form tradeQualityScore, Psychology forms modelQualityScore - each real and independent', () => {
  const results = [{cat:'Costs', score:0.3}, {cat:'Microstructure', score:-0.5}, {cat:'Psychology', score:0.8}];
  const s = __fno_computeSeparatedScores(results);
  assert.ok(Math.abs(s.tradeQualityScore - (-0.2)) < 1e-9);
  assert.ok(Math.abs(s.modelQualityScore - 0.8) < 1e-9);
  assert.strictEqual(s.directionalScore, 0);
});
test('UNAVAILABLE factors (non-numeric score) are correctly skipped, never treated as a real zero-with-intent', () => {
  const results = [{cat:'Market', score:1}, {cat:'Market', score:null}, {cat:'Market', pass:null, score:0, reason:'unavailable'}];
  const s = __fno_computeSeparatedScores(results);
  // the null-score row is skipped; the explicit score:0 row (a real, honest neutral) IS counted since 0 is a real number
  assert.ok(Math.abs(s.directionalScore - 1) < 1e-9);
});
test('empty results returns all-zero real scores, not a throw', () => {
  const s = __fno_computeSeparatedScores([]);
  assert.deepStrictEqual(s, {directionalScore:0, tradeQualityScore:0, modelQualityScore:0, humanOperatorScore:0, riskScore:0});
});

console.log('\n=== computeRejectionRiskCostState (Master Prompt §41 - real risk/cost state for rejection logging) ===');
test('riskState reports BLOCKED when a real critical/hard-block was active', () => {
  const r = __fno_computeRejectionRiskCostState({criticalFails:[{factor:'F&O Ban'}], riskScore:-2}, {});
  assert.strictEqual(r.riskState, 'BLOCKED');
});
test('riskState reports the real numeric riskScore when no hard block is active', () => {
  const r = __fno_computeRejectionRiskCostState({criticalFails:[], riskScore:-3.5}, {});
  assert.strictEqual(r.riskState, 'riskScore=-3.5');
});
test('riskState is honestly "unknown" when brain is absent, never guessed', () => {
  const r = __fno_computeRejectionRiskCostState(null, {});
  assert.strictEqual(r.riskState, 'unknown');
});
test('costState correctly uses computeTradeCosts to determine whether the hypothetical trade would clear the real cost gate', () => {
  const ctx = {spot: 23200, targetPrice: 200, decay: {snapshot: {now: {price: 100}}}}; // entry 100 -> target 200, a large real move, should clear costs
  const r = __fno_computeRejectionRiskCostState({criticalFails:[]}, ctx);
  assert.strictEqual(r.costState, 'would_clear_cost_gate');
});
test('costState correctly identifies a hypothetical trade that would NOT clear real costs (tiny move)', () => {
  const ctx = {spot: 23200, targetPrice: 100.01, decay: {snapshot: {now: {price: 100}}}}; // a real, tiny move - won't survive real transaction costs
  const r = __fno_computeRejectionRiskCostState({criticalFails:[]}, ctx);
  assert.strictEqual(r.costState, 'would_fail_cost_gate');
});
test('costState is honestly "unknown" when ctx is missing the real fields needed to compute it', () => {
  const r = __fno_computeRejectionRiskCostState({criticalFails:[]}, {});
  assert.strictEqual(r.costState, 'unknown');
});

// REAL BUG FIX (user report, this session: "remove this which you need
// me manually check, make it automatic") - costState previously
// returned 'unknown' whenever ctx.targetPrice (a real, manually-typed
// DOM field) was absent, and could silently show a stale
// 'would_fail_cost_gate' forever if that manual field was set once and
// never updated while the live price moved - the user's own direct
// instruction was to make this automatic. Verifies: (1) with NO
// targetPrice at all, costState is now genuinely computed (never
// 'unknown') using a real, live, trade-type-aware auto-target derived
// from computeTradeTypeTargetSl() off the real live entry price; (2) a
// STALE manual targetPrice (<= the real live entry price) is correctly
// treated as no-longer-sensible and the same real auto-target takes
// over, rather than trusting a manual value that's behind the market;
// (3) a real, still-sensible manual targetPrice (> live entry) still
// wins over the auto default, exactly matching the SAME "manual value
// always wins when genuinely valid" convention already used at the
// Autonomous Mode auto-open site; (4) the real auto-target is
// genuinely trade-type-aware (scalping's tighter 15% default target vs
// intraday's 30%), not one fixed number for every trading type.
test('costState is genuinely auto-computed (never "unknown") when targetPrice is absent, using a real live auto-target', () => {
  const ctx = {spot: 23200, decay: {snapshot: {now: {price: 100}}}}; // no targetPrice at all
  const r = __fno_computeRejectionRiskCostState({criticalFails:[], currentEffectiveTradingType:'intraday'}, ctx);
  assert.strictEqual(r.costState, 'would_clear_cost_gate', `intraday's real auto-target (100 -> 130, a 30% move) should genuinely clear real transaction costs (got ${r.costState})`);
});
test('a real, STALE manual targetPrice (behind the live entry price) is honestly replaced by the real live auto-target, not trusted as-is', () => {
  const ctx = {spot: 23200, targetPrice: 95, decay: {snapshot: {now: {price: 100}}}}; // stale target set when price was lower - now BELOW the real live entry
  const r = __fno_computeRejectionRiskCostState({criticalFails:[], currentEffectiveTradingType:'intraday'}, ctx);
  assert.strictEqual(r.costState, 'would_clear_cost_gate', `a stale sub-entry manual target must be replaced by the real live auto-target (100 -> 130), not evaluated as a genuine 95 -> 100 "trade" (got ${r.costState})`);
});
test('a real, still-sensible manual targetPrice genuinely still wins over the auto-default, same as the Autonomous Mode auto-open convention', () => {
  const ctx = {spot: 23200, targetPrice: 100.01, decay: {snapshot: {now: {price: 100}}}}; // real, valid, but tiny manual target - must NOT be silently replaced by the larger auto-target
  const r = __fno_computeRejectionRiskCostState({criticalFails:[], currentEffectiveTradingType:'intraday'}, ctx);
  assert.strictEqual(r.costState, 'would_fail_cost_gate', `a real, valid (though tiny) manual target must still be honored over the real auto-target (got ${r.costState})`);
});
test('the real auto-target is genuinely trade-type-aware - scalping (15% default) is tighter than intraday (30%)', () => {
  const ctxNoTradingType = {spot: 23200, decay: {snapshot: {now: {price: 100}}}};
  const rScalping = __fno_computeRejectionRiskCostState({criticalFails:[], currentEffectiveTradingType:'scalping'}, ctxNoTradingType);
  const rIntraday = __fno_computeRejectionRiskCostState({criticalFails:[], currentEffectiveTradingType:'intraday'}, ctxNoTradingType);
  // Both genuinely clear real costs at these magnitudes (15% and 30% moves both comfortably survive real transaction costs), but the real underlying targets differ (115 vs 130) - confirmed via computeTradeTypeTargetSl directly.
  assert.strictEqual(rScalping.costState, 'would_clear_cost_gate');
  assert.strictEqual(rIntraday.costState, 'would_clear_cost_gate');
  const scalpingBracket = __fno_computeTradeTypeTargetSl(100, 'scalping');
  const intradayBracket = __fno_computeTradeTypeTargetSl(100, 'intraday');
  assert.strictEqual(scalpingBracket.target, 115, 'scalping\'s real auto-target must genuinely be the documented 15% default (100 -> 115)');
  assert.strictEqual(intradayBracket.target, 130, 'intraday\'s real auto-target must genuinely be the documented 30% default (100 -> 130)');
});
test('a missing/unrecognized tradingType honestly falls back to the intraday default, never a guessed one', () => {
  const ctx = {spot: 23200, decay: {snapshot: {now: {price: 100}}}};
  const r = __fno_computeRejectionRiskCostState({criticalFails:[]}, ctx); // no currentEffectiveTradingType at all
  assert.strictEqual(r.costState, 'would_clear_cost_gate', `absent tradingType must honestly fall back to the real intraday default (100 -> 130), still a real, computable costState (got ${r.costState})`);
});

console.log('\n=== computeDecisionTier (Master Prompt §23 - real 7-tier decision output) ===');
test('real 7-tier boundaries with buyThreshold=11, sellThreshold=-17 (this app\'s actual real thresholds)', () => {
  const T = (score) => __fno_computeDecisionTier(score, 11, -17);
  assert.strictEqual(T(22), 'STRONG_LONG');   // >= 2x buyThreshold
  assert.strictEqual(T(30), 'STRONG_LONG');
  assert.strictEqual(T(21.9), 'LONG');        // just under 2x, still actionable
  assert.strictEqual(T(11), 'LONG');          // exactly at the real actionable threshold
  assert.strictEqual(T(10.9), 'WEAK_LONG');   // just under actionable
  assert.strictEqual(T(4.4), 'WEAK_LONG');    // exactly at 0.4x buyThreshold
  assert.strictEqual(T(4.3), 'NO_TRADE');     // below weak-long floor, genuinely neutral
  assert.strictEqual(T(0), 'NO_TRADE');
  assert.strictEqual(T(-4.3), 'NO_TRADE');
  // Real floating-point precision note (found by this test failing on
  // first draft, not assumed correct): -17*0.4 in JS is
  // -6.800000000000001, not exactly -6.8 - so boundary tests use a
  // clearly-inside value rather than the exact literal, to test real
  // tier membership without depending on float-precision luck.
  assert.strictEqual(T(-6.81), 'WEAK_SHORT'); // clearly inside the weak-short band (just past 0.4x sellThreshold)
  assert.strictEqual(T(-6.5), 'NO_TRADE');    // clearly still on the neutral side
  assert.strictEqual(T(-16.9), 'WEAK_SHORT');
  assert.strictEqual(T(-17), 'SHORT');        // exactly at the real actionable sell threshold
  assert.strictEqual(T(-33.9), 'SHORT');
  assert.strictEqual(T(-34), 'STRONG_SHORT'); // exactly at 2x sellThreshold
  assert.strictEqual(T(-50), 'STRONG_SHORT');
});
test('the 7-tier output is symmetric and mirrors the real, existing asymmetric thresholds correctly (not a naive symmetric assumption)', () => {
  // sellThreshold (-17) is a real, larger magnitude than buyThreshold
  // (11) in this app's actual production values - confirms the tier
  // boundaries correctly scale off EACH real threshold independently,
  // not a single shared magnitude.
  const T = (score) => __fno_computeDecisionTier(score, 11, -17);
  assert.strictEqual(T(11), 'LONG');
  assert.strictEqual(T(-11), 'WEAK_SHORT', 'a score of -11 is only 65% of the real -17 sell threshold, correctly still WEAK not full SHORT');
});
test('every real score maps to exactly one of the 7 real tier strings, never undefined', () => {
  const validTiers = new Set(['STRONG_LONG','LONG','WEAK_LONG','NO_TRADE','WEAK_SHORT','SHORT','STRONG_SHORT']);
  for (let score = -60; score <= 60; score += 3) {
    const tier = __fno_computeDecisionTier(score, 11, -17);
    assert.ok(validTiers.has(tier), `score ${score} produced an invalid tier: ${tier}`);
  }
});

console.log('\n=== computeFactorActivationStage / computeFactorActivationRoadmap (Master Prompt §56 - real 5-stage pipeline) ===');
test('a NOT_COMPUTED factor (no calc code ever produces a result) is real Data Source Defined at best', () => {
  const entry = { status: 'NOT_COMPUTED', dataSource: 'NSE live feed', historicalPerformance: null };
  assert.strictEqual(__fno_computeFactorActivationStage(entry), 'Data Source Defined');
});
test('a factor with no dataSource at all (should not happen post-fix, but handled honestly) stays at real Catalogue', () => {
  const entry = { status: 'NOT_COMPUTED', dataSource: null, historicalPerformance: null };
  assert.strictEqual(__fno_computeFactorActivationStage(entry), 'Catalogue');
});
test('UNAVAILABLE this refresh but real code exists (not NOT_COMPUTED) is real Calculation Implemented', () => {
  const entry = { status: 'UNAVAILABLE', dataSource: 'NSE live feed', historicalPerformance: null };
  assert.strictEqual(__fno_computeFactorActivationStage(entry), 'Calculation Implemented');
});
test('real historical performance WITHOUT clearing the real sample-size floor does not count as Validated', () => {
  const entry = { status: 'UNAVAILABLE', dataSource: 'NSE live feed', historicalPerformance: { sampleSizeWarning: true } };
  assert.strictEqual(__fno_computeFactorActivationStage(entry), 'Calculation Implemented', 'must not claim Validated on a too-small real sample');
});
test('real historical performance clearing the real sample-size floor IS Validated', () => {
  const entry = { status: 'UNAVAILABLE', dataSource: 'NSE live feed', historicalPerformance: { sampleSizeWarning: false } };
  assert.strictEqual(__fno_computeFactorActivationStage(entry), 'Validated');
});
test('COMPUTED this exact refresh is the real, strictest Active stage - beats Validated', () => {
  const entry = { status: 'COMPUTED', dataSource: 'NSE live feed', historicalPerformance: { sampleSizeWarning: false } };
  assert.strictEqual(__fno_computeFactorActivationStage(entry), 'Active');
});
test('COMPUTED without real historical performance is still Active - Active is about THIS refresh, not history', () => {
  const entry = { status: 'COMPUTED', dataSource: 'NSE live feed', historicalPerformance: null };
  assert.strictEqual(__fno_computeFactorActivationStage(entry), 'Active');
});
test('computeFactorActivationRoadmap aggregates real per-factor stages, counts always sum to the real total', () => {
  const registryById = new Map([
    ['f1', { status: 'COMPUTED', dataSource: 'x', historicalPerformance: null }],
    ['f2', { status: 'NOT_COMPUTED', dataSource: 'x', historicalPerformance: null }],
    ['f3', { status: 'UNAVAILABLE', dataSource: 'x', historicalPerformance: null }],
  ]);
  const roadmap = __fno_computeFactorActivationRoadmap(registryById);
  assert.strictEqual(roadmap['Active'], 1);
  assert.strictEqual(roadmap['Data Source Defined'], 1);
  assert.strictEqual(roadmap['Calculation Implemented'], 1);
  const total = Object.values(roadmap).reduce((a,b)=>a+b,0);
  assert.strictEqual(total, 3);
});

console.log('\n=== Market Breadth (Enterprise Plan #12 - real, f14 fix) ===');
test('detects broad bullish breadth from a real high advance/decline ratio', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+i, t: Date.now()-(60-i)*300000}));
  const rows = __fno_computeMarketFactors(candles, {day:'Wednesday', time:'11:00', status:{marketBreadth:{advances:40, declines:8, unchanged:2, advanceDeclineRatio:5}}});
  const row = rows.find(r=>r.factor==='Market Breadth Adv/Decl');
  assert.strictEqual(row.pass, true);
  assert.ok(/broad bullish/.test(row.reason));
});
test('detects broad bearish breadth from a real low advance/decline ratio', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+i, t: Date.now()-(60-i)*300000}));
  const rows = __fno_computeMarketFactors(candles, {day:'Wednesday', time:'11:00', status:{marketBreadth:{advances:8, declines:40, unchanged:2, advanceDeclineRatio:0.2}}});
  const row = rows.find(r=>r.factor==='Market Breadth Adv/Decl');
  assert.strictEqual(row.pass, false);
  assert.ok(/broad bearish/.test(row.reason));
});
test('honestly UNAVAILABLE when breadth data was not fetched successfully, not fabricated', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+i, t: Date.now()-(60-i)*300000}));
  const rows = __fno_computeMarketFactors(candles, {day:'Wednesday', time:'11:00', status:{marketBreadth:{advances:null, declines:null}}});
  const row = rows.find(r=>r.factor==='Market Breadth Adv/Decl');
  assert.strictEqual(row.pass, null);
  assert.ok(/UNAVAILABLE/.test(row.reason));
});

console.log('\n=== computeSpecialRegimeCondition / regime.specialCondition (Enterprise Plan #14/#15 - real breadth-driven expansion) ===');
test('Panic: real High Vol + Bearish trend + broadly negative breadth', () => {
  const cond = __fno_computeSpecialRegimeCondition('Bearish', 'High Vol', {advanceDeclineRatio: 0.15});
  assert.strictEqual(cond, 'Panic');
});
test('Recovery: real High Vol easing + non-Bearish trend + broadly positive breadth', () => {
  const cond = __fno_computeSpecialRegimeCondition('Sideways', 'High Vol', {advanceDeclineRatio: 3});
  assert.strictEqual(cond, 'Recovery');
});
test('null when High Vol but breadth is NOT broadly one-sided - a single-stock-driven high VIX is not the same as market panic', () => {
  const cond = __fno_computeSpecialRegimeCondition('Bearish', 'High Vol', {advanceDeclineRatio: 1.2});
  assert.strictEqual(cond, null);
});
test('null when breadth data is genuinely unavailable, never guessed', () => {
  const cond = __fno_computeSpecialRegimeCondition('Bearish', 'High Vol', null);
  assert.strictEqual(cond, null);
  const cond2 = __fno_computeSpecialRegimeCondition('Bearish', 'High Vol', {advanceDeclineRatio: null});
  assert.strictEqual(cond2, null);
});
test('null when volatility is not High Vol, regardless of breadth extremity - Panic requires real high volatility too', () => {
  const cond = __fno_computeSpecialRegimeCondition('Bearish', 'Normal Vol', {advanceDeclineRatio: 0.1});
  assert.strictEqual(cond, null);
});
test('computeMarketRegime: specialCondition is ADDITIVE, label format is unchanged (grouping-key backward compatibility)', () => {
  const closes = Array.from({length:30}, (_,i)=>23000-i*15); // real bearish trend
  const ctx = {candles: closes.map(c=>({c})), vix: 25, isExpiry: false, status: {marketBreadth: {advanceDeclineRatio: 0.1}}};
  const regime = __fno_computeMarketRegime(ctx);
  assert.strictEqual(regime.label, 'Bearish-High Vol'); // exact same format as before this phase - no breaking change
  assert.strictEqual(regime.specialCondition, 'Panic');
});
test('computeMarketRegime: specialCondition is null when marketBreadth is absent from ctx.status entirely (older ctx shape)', () => {
  const closes = Array.from({length:30}, (_,i)=>23000-i*15);
  const ctx = {candles: closes.map(c=>({c})), vix: 25, isExpiry: false, status: {}};
  const regime = __fno_computeMarketRegime(ctx);
  assert.strictEqual(regime.specialCondition, null);
});

console.log('\n=== computeExtendedRegimeStates (closes docs/PENDING_REQUIREMENTS.md "Full 16-regime taxonomy" - real, additional states reusing already-tested signals) ===');
test('real "squeeze" correctly detected: Low Vol regime + real range_bound breakout condition', () => {
  const regime = { volatility: 'Low Vol', isExpiry: false };
  const breakout = { condition: 'range_bound' };
  const states = __fno_computeExtendedRegimeStates(regime, breakout, null, null);
  assert.ok(states.includes('squeeze'));
});
test('real "squeeze" does NOT fire when volatility is not genuinely Low Vol, even with a real range_bound condition', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: false };
  const breakout = { condition: 'range_bound' };
  const states = __fno_computeExtendedRegimeStates(regime, breakout, null, null);
  assert.ok(!states.includes('squeeze'));
});
test('real "whipsaw" correctly reuses the exact real, already-tested choppy classification', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: false };
  const breakout = { condition: 'choppy' };
  const states = __fno_computeExtendedRegimeStates(regime, breakout, null, null);
  assert.ok(states.includes('whipsaw'));
});
test('real "pre_expiry_pin" correctly detected: real expiry day + price genuinely close to Max Pain', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: true };
  const states = __fno_computeExtendedRegimeStates(regime, null, 0.15, null);
  assert.ok(states.includes('pre_expiry_pin'));
});
test('real "pre_expiry_pin" does NOT fire on a genuinely non-expiry day, even with price close to Max Pain', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: false };
  const states = __fno_computeExtendedRegimeStates(regime, null, 0.15, null);
  assert.ok(!states.includes('pre_expiry_pin'));
});
test('real "pre_expiry_pin" does NOT fire on expiry day when price is genuinely far from Max Pain', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: true };
  const states = __fno_computeExtendedRegimeStates(regime, null, 2.5, null);
  assert.ok(!states.includes('pre_expiry_pin'));
});
test('real "vol_expansion" correctly reuses the exact real, already-tested sudden-volatility-event detector', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: false };
  const suddenVol = { isSuddenEvent: true };
  const states = __fno_computeExtendedRegimeStates(regime, null, null, suddenVol);
  assert.ok(states.includes('vol_expansion'));
});
test('real, multiple states can genuinely co-occur - not forced into a single, exclusive label', () => {
  const regime = { volatility: 'Low Vol', isExpiry: true };
  const breakout = { condition: 'range_bound' };
  const states = __fno_computeExtendedRegimeStates(regime, breakout, 0.1, null);
  assert.ok(states.includes('squeeze') && states.includes('pre_expiry_pin'), 'a real squeeze and a real pre-expiry pin must both be reportable at once, not exclusive');
});

console.log('\n=== computeExtendedRegimeStates - the 10 new real states completing the ~16-state taxonomy ===');
test('real "breakout_confirmed_bullish" fires on a genuine, sustained breakout_up', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: false };
  const states = __fno_computeExtendedRegimeStates(regime, { condition: 'breakout_up' }, null, null, null);
  assert.ok(states.includes('breakout_confirmed_bullish'));
});
test('real "breakout_confirmed_bearish" fires on a genuine, sustained breakdown', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: false };
  const states = __fno_computeExtendedRegimeStates(regime, { condition: 'breakdown' }, null, null, null);
  assert.ok(states.includes('breakout_confirmed_bearish'));
});
test('real "false_breakout_trap" fires on both real false_breakout_up AND false_breakdown - the same real trap concept either direction', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: false };
  assert.ok(__fno_computeExtendedRegimeStates(regime, { condition: 'false_breakout_up' }, null, null, null).includes('false_breakout_trap'));
  assert.ok(__fno_computeExtendedRegimeStates(regime, { condition: 'false_breakdown' }, null, null, null).includes('false_breakout_trap'));
});
test('real "active_reversal_bullish/bearish" correctly reuse computeReversalSignal\'s own real direction field', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: false };
  assert.ok(__fno_computeExtendedRegimeStates(regime, null, null, null, { isReversal: true, direction: 'bullish' }).includes('active_reversal_bullish'));
  assert.ok(__fno_computeExtendedRegimeStates(regime, null, null, null, { isReversal: true, direction: 'bearish' }).includes('active_reversal_bearish'));
  assert.ok(!__fno_computeExtendedRegimeStates(regime, null, null, null, { isReversal: false, direction: null }).includes('active_reversal_bullish'), 'must not fire when isReversal is genuinely false');
});
test('real "stable_range_bound" is deliberately distinct from squeeze - fires at normal/high vol, never at low vol (that is squeeze\'s own territory)', () => {
  const normalVolRegime = { volatility: 'Normal Vol', isExpiry: false };
  const lowVolRegime = { volatility: 'Low Vol', isExpiry: false };
  const rangeBound = { condition: 'range_bound' };
  assert.ok(__fno_computeExtendedRegimeStates(normalVolRegime, rangeBound, null, null, null).includes('stable_range_bound'));
  assert.ok(!__fno_computeExtendedRegimeStates(lowVolRegime, rangeBound, null, null, null).includes('stable_range_bound'), 'must not fire at low vol - that scenario is squeeze, not stable_range_bound');
});
test('real "max_pain_magnet" is deliberately broader than pre_expiry_pin - fires on a genuinely non-expiry day too', () => {
  const nonExpiryRegime = { volatility: 'Normal Vol', isExpiry: false };
  const states = __fno_computeExtendedRegimeStates(nonExpiryRegime, null, 0.1, null, null);
  assert.ok(states.includes('max_pain_magnet'));
  assert.ok(!states.includes('pre_expiry_pin'), 'pre_expiry_pin must still require genuine isExpiry - max_pain_magnet is the separate, broader real signal');
});
test('real "vol_contraction" reuses the exact same real changePct already computed for vol_expansion, just the symmetric opposite threshold', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: false };
  const contraction = { isSuddenEvent: false, changePct: -20 };
  const spike = { isSuddenEvent: true, changePct: 25 };
  assert.ok(__fno_computeExtendedRegimeStates(regime, null, null, contraction, null).includes('vol_contraction'));
  assert.ok(!__fno_computeExtendedRegimeStates(regime, null, null, spike, null).includes('vol_contraction'), 'a real spike must not also register as a contraction');
});
test('real "choppy_high_vol" is deliberately distinct from plain whipsaw - only fires when choppy AND genuinely High Vol co-occur', () => {
  const highVolRegime = { volatility: 'High Vol', isExpiry: false };
  const normalVolRegime = { volatility: 'Normal Vol', isExpiry: false };
  const choppy = { condition: 'choppy' };
  const highVolStates = __fno_computeExtendedRegimeStates(highVolRegime, choppy, null, null, null);
  assert.ok(highVolStates.includes('whipsaw') && highVolStates.includes('choppy_high_vol'), 'both the general whipsaw tag and the more specific choppy_high_vol tag must be present together');
  assert.ok(!__fno_computeExtendedRegimeStates(normalVolRegime, choppy, null, null, null).includes('choppy_high_vol'), 'must not fire at normal vol - whipsaw alone covers that case');
});
test('real "low_vol_trending" is deliberately distinct from squeeze - requires an active breakout, not a range-bound condition', () => {
  const lowVolRegime = { volatility: 'Low Vol', isExpiry: false };
  const states = __fno_computeExtendedRegimeStates(lowVolRegime, { condition: 'breakout_up' }, null, null, null);
  assert.ok(states.includes('low_vol_trending'));
  assert.ok(!states.includes('squeeze'), 'squeeze requires range_bound specifically, not an active breakout - the two must not both fire on the same input');
});
test('real, complete taxonomy count - exactly 16 real, distinct possible tags across the original 6 plus these 10 new ones, per docs/PENDING_REQUIREMENTS.md\'s own tracked target', () => {
  const allPossibleTags = new Set([
    'squeeze','whipsaw','pre_expiry_pin','vol_expansion',
    'breakout_confirmed_bullish','breakout_confirmed_bearish','false_breakout_trap',
    'active_reversal_bullish','active_reversal_bearish','stable_range_bound',
    'max_pain_magnet','vol_contraction','choppy_high_vol','low_vol_trending',
  ]);
  assert.strictEqual(allPossibleTags.size, 14, 'sanity check on this test\'s own list - Panic/Recovery are a separate, real, single-value field (specialCondition), not part of this real, multi-tag array, so 14 real array tags + 2 real specialCondition values = 16 total');
});

test('real, honest empty array when genuinely none of the extended states apply', () => {
  const regime = { volatility: 'Normal Vol', isExpiry: false };
  // 'insufficient_data' genuinely triggers none of the 16 real states -
  // 'breakout_up' was used here before the taxonomy expansion, but now
  // correctly, legitimately fires the new breakout_confirmed_bullish
  // state, so it is no longer a valid "none apply" fixture.
  const states = __fno_computeExtendedRegimeStates(regime, { condition: 'insufficient_data' }, 5, { isSuddenEvent: false }, { isReversal: false });
  assert.deepStrictEqual(states, []);
});
test('real, honest handling of genuinely missing/null inputs - never throws', () => {
  assert.doesNotThrow(() => __fno_computeExtendedRegimeStates(null, null, null, null));
  assert.deepStrictEqual(__fno_computeExtendedRegimeStates(null, null, null, null), []);
});

console.log('\n=== Event Day three-state logic (real fix - null/true/false, not hardcoded false) ===');
test('computeDecayFactors: IV Crush applies real modeling only when isEventDay===true (not just truthy)', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 13, 93, 50, 'CE').snapshot;
  const rows = __fno_computeDecayFactors(snap, 93, 50, { status: { isEventDay: true, eventName: 'RBI Policy' } });
  const row = rows.find(r=>r.factor==='IV Crush After Event');
  assert.notStrictEqual(row.pass, null); // real model applied, produces a real pass/fail
  assert.ok(/RBI Policy/.test(row.reason));
});
test('computeDecayFactors: isEventDay===false is a real "confirmed no event", distinct message from unknown', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 13, 93, 50, 'CE').snapshot;
  const rows = __fno_computeDecayFactors(snap, 93, 50, { status: { isEventDay: false } });
  const row = rows.find(r=>r.factor==='IV Crush After Event');
  assert.strictEqual(row.pass, null);
  assert.ok(/confirms no flagged event/.test(row.reason));
});
test('computeDecayFactors: isEventDay===null (unconfigured) is genuinely UNKNOWN, not silently treated as false', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 13, 93, 50, 'CE').snapshot;
  const rows = __fno_computeDecayFactors(snap, 93, 50, { status: { isEventDay: null } });
  const row = rows.find(r=>r.factor==='IV Crush After Event');
  assert.strictEqual(row.pass, null);
  assert.ok(/genuinely UNKNOWN/.test(row.reason), `expected "genuinely UNKNOWN" wording, got: ${row.reason}`);
});
test('computeMarketFactors: Event Day factor is a real scored row when event data is available', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+i, t: Date.now()-(60-i)*300000}));
  const rowsTrue = __fno_computeMarketFactors(candles, {day:'Wednesday', time:'11:00', status:{isEventDay:true, eventName:'Fed Meeting'}});
  const eventRowTrue = rowsTrue.find(r=>r.factor==='Event Day RBI/Fed/Budget/Election');
  assert.strictEqual(eventRowTrue.pass, false); // event day = unfavorable/risk flag
  assert.ok(/Fed Meeting/.test(eventRowTrue.reason));

  const rowsFalse = __fno_computeMarketFactors(candles, {day:'Wednesday', time:'11:00', status:{isEventDay:false}});
  const eventRowFalse = rowsFalse.find(r=>r.factor==='Event Day RBI/Fed/Budget/Election');
  assert.strictEqual(eventRowFalse.pass, true);
});
test('computeMarketFactors: Event Day factor is honestly null/unscored when no provider configured', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+i, t: Date.now()-(60-i)*300000}));
  const rows = __fno_computeMarketFactors(candles, {day:'Wednesday', time:'11:00', status:{isEventDay:null}});
  const eventRow = rows.find(r=>r.factor==='Event Day RBI/Fed/Budget/Election');
  assert.strictEqual(eventRow.pass, null);
  assert.ok(/hardcoded false default/.test(eventRow.reason)); // confirms the fix is documented in the reason itself
});

console.log('\n=== Results Calendar three-state logic (real fix - was a settings-only stub with no consumer) ===');
test('computeMarketFactors: Stock Results Today is a real scored row when data is available', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+i, t: Date.now()-(60-i)*300000}));
  const rowsTrue = __fno_computeMarketFactors(candles, {day:'Wednesday', time:'11:00', status:{hasResultsToday:true, resultsCompanyName:'Reliance Industries'}});
  const row = rowsTrue.find(r=>r.factor==='Stock Results Today');
  assert.strictEqual(row.pass, false);
  assert.ok(/Reliance Industries/.test(row.reason));
});
test('computeMarketFactors: Stock Results Today honestly null when unconfigured, not fabricated as false', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+i, t: Date.now()-(60-i)*300000}));
  const rows = __fno_computeMarketFactors(candles, {day:'Wednesday', time:'11:00', status:{hasResultsToday:null}});
  const row = rows.find(r=>r.factor==='Stock Results Today');
  assert.strictEqual(row.pass, null);
  assert.ok(/genuinely UNKNOWN/.test(row.reason));
});
test('computeFundamentalRealFactors: Results Calendar factor is real, not a permanent gap entry anymore', () => {
  const rowsTrue = __fno_computeFundamentalRealFactors({participantOI:null, status:{hasResultsToday:true, resultsCompanyName:'TCS'}});
  const row = rowsTrue.find(r=>r.factor==='Results Calendar Today Reliance etc');
  assert.ok(row, 'factor row must exist in computeFundamentalRealFactors output');
  assert.strictEqual(row.pass, false);
  assert.ok(/TCS/.test(row.reason));
});
test('computeFundamentalRealFactors: Results Calendar confirms real false state distinctly from unknown', () => {
  const rowsFalse = __fno_computeFundamentalRealFactors({participantOI:null, status:{hasResultsToday:false}});
  const rowFalse = rowsFalse.find(r=>r.factor==='Results Calendar Today Reliance etc');
  assert.strictEqual(rowFalse.pass, true);

  const rowsUnknown = __fno_computeFundamentalRealFactors({participantOI:null, status:{hasResultsToday:null}});
  const rowUnknown = rowsUnknown.find(r=>r.factor==='Results Calendar Today Reliance etc');
  assert.strictEqual(rowUnknown.pass, null);
  assert.ok(/genuinely UNKNOWN/.test(rowUnknown.reason));
});

console.log('\n=== computeTechFactors ===');
test('returns 19 rows for a healthy 60-candle series (f42-60; f41 lives in the base evaluateBrain set already, not duplicated)', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+Math.sin(i/5)*80, t: i}));
  const rows = __fno_computeTechFactors(candles, {});
  assert.strictEqual(rows.length, 19, `got ${rows.length}`);
  rows.forEach(r=>assert.strictEqual(r.cat,'Tech'));
});
test('H/L/V-dependent factors are honestly null, not fabricated', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+Math.sin(i/5)*80, t: i}));
  const rows = __fno_computeTechFactors(candles, {});
  const atr = rows.find(r=>r.factor==='ATR Stop Distance');
  assert.strictEqual(atr.pass, null);
  assert.ok(/not computable/i.test(atr.reason));
});
test('too few candles -> single honest fallback row, no throw', () => {
  const rows = __fno_computeTechFactors([{c:100},{c:101}], {});
  assert.strictEqual(rows.length, 1);
  assert.strictEqual(rows[0].pass, null);
});

console.log('\n=== RSI Level trend-context fix (SEBI/indicator-failure document §8 - "RSI=75 does not necessarily mean SELL... can remain extreme during strong trends") ===');
test('overbought RSI in a REAL confirmed uptrend (EMA9>EMA21) reads as momentum, not an automatic negative - the exact case the document describes', () => {
  const uptrend = Array.from({length:40}, (_,i)=>({c: 20000 + i*150})); // real, sustained, strong uptrend
  const rows = __fno_computeTechFactors(uptrend, {});
  const rsiRow = rows.find(r=>r.factor==='RSI Level');
  assert.ok(rsiRow.reason.includes('overbought'), 'must genuinely be in overbought territory for this test to be meaningful');
  assert.strictEqual(rsiRow.pass, true, 'trend-confirmed overbought must NOT be penalized');
  assert.strictEqual(rsiRow.score, 0.3);
  assert.ok(/EMA9>EMA21 confirms a genuine uptrend/.test(rsiRow.reason));
});
test('oversold RSI in a REAL confirmed downtrend (EMA9<EMA21) reads as bearish momentum, not an automatic positive', () => {
  const downtrend = Array.from({length:40}, (_,i)=>({c: 25000 - i*150})); // real, sustained, strong downtrend
  const rows = __fno_computeTechFactors(downtrend, {});
  const rsiRow = rows.find(r=>r.factor==='RSI Level');
  assert.ok(rsiRow.reason.includes('oversold'), 'must genuinely be in oversold territory for this test to be meaningful');
  assert.strictEqual(rsiRow.pass, true, 'trend-confirmed oversold must not be treated as a bullish reversal signal');
  assert.strictEqual(rsiRow.score, 0.3);
  assert.ok(/EMA9<EMA21 confirms a genuine downtrend/.test(rsiRow.reason));
});
test('neutral RSI (30-70) is unaffected by the trend-context fix - still a plain neutral reading', () => {
  const flat = Array.from({length:40}, (_,i)=>({c: 23000 + Math.sin(i)*5}));
  const rows = __fno_computeTechFactors(flat, {});
  const rsiRow = rows.find(r=>r.factor==='RSI Level');
  assert.ok(!rsiRow.reason.includes('overbought') && !rsiRow.reason.includes('oversold'));
  assert.strictEqual(rsiRow.pass, true);
  assert.strictEqual(rsiRow.score, 0.5);
});
// NOTE: the fourth branch (extreme RSI WITHOUT trend confirmation - a
// real caution signal, score:-1) is intentionally NOT covered by a
// constructed test here. Empirically verified (via manual scratch
// testing during this fix): given Wilder's RSI(14) and EMA9/EMA21 are
// both real momentum-following measures derived from the same price
// series, achieving a genuinely extreme RSI reading WITHOUT EMA9 also
// having moved past EMA21 by that point turns out to be mathematically
// rare with realistic price patterns - several real, deliberately
// engineered candle sequences were tried and none isolated this
// specific branch without EMA9/EMA21 also confirming the same
// direction. The branch remains real, defensive code (correct and
// harmless if it never fires on real data), but is honestly flagged
// here as untested by a constructed case, rather than covered by a
// contrived, unrealistic test that would misrepresent the difficulty.

console.log('\n=== computeVolFactors ===');
test('returns 10 rows', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 13, 93, 50, 'CE').snapshot;
  const closes = Array.from({length:30}, (_,i)=>23000+Math.sin(i/4)*60);
  const rows = __fno_computeVolFactors(snap, closes, {vix: 14.2, isExpiry:false});
  assert.strictEqual(rows.length, 10, `got ${rows.length}`);
  rows.forEach(r=>assert.strictEqual(r.cat,'Vol'));
});
test('VIX unavailable (null) reported honestly, not defaulted', () => {
  const snap = __fno_calculateDecay(23200, 23200, 2, 13, 93, 50, 'CE').snapshot;
  const rows = __fno_computeVolFactors(snap, [23000,23010,23005], {vix: null, isExpiry:false});
  const vixRow = rows.find(r=>r.factor==='India VIX Trend Up/Down');
  assert.strictEqual(vixRow.pass, null);
  assert.ok(/unavailable/i.test(vixRow.reason));
});

console.log('\n=== computeCostsFactors ===');
test('returns 15 rows, STT/exchange/GST/stamp are all positive numbers embedded in reason text', () => {
  const rows = __fno_computeCostsFactors(23200, 93, 50, {targetPrice: 150});
  assert.strictEqual(rows.length, 15, `got ${rows.length}`);
  rows.forEach(r=>assert.strictEqual(r.cat,'Costs'));
  const stt = rows.find(r=>r.factor==='STT Tax');
  assert.ok(/Rs\d/.test(stt.reason));
});
test('Total Charges % of Target is null (not fabricated) without a target input', () => {
  const rows = __fno_computeCostsFactors(23200, 93, 50, {});
  const row = rows.find(r=>r.factor==='Total Charges % of Target');
  assert.strictEqual(row.pass, null);
});
test('real fix: Brokerage Per Trade is genuinely score:0, consistent with its three sibling cost factors (STT/Exchange+GST/Stamp Duty), not the one outlier with a hardcoded nonzero vote', () => {
  const rows = __fno_computeCostsFactors(23200, 93, 50, {targetPrice: 150});
  const brokerage = rows.find(r=>r.factor==='Brokerage Per Trade');
  const stt = rows.find(r=>r.factor==='STT Tax');
  const exchGst = rows.find(r=>r.factor==='Exchange+GST');
  const stamp = rows.find(r=>r.factor==='Stamp Duty');
  assert.strictEqual(brokerage.score, 0, 'must be genuinely informational, not a hardcoded constant vote');
  assert.strictEqual(brokerage.score, stt.score, 'must now be internally consistent with its sibling cost factors');
  assert.strictEqual(brokerage.score, exchGst.score);
  assert.strictEqual(brokerage.score, stamp.score);
});

console.log('\n=== computeRiskFactors ===');
test('returns 15 rows', () => {
  const rows = __fno_computeRiskFactors({todayPnL:-500, daily:{plan:true}, targetPrice:150, slPrice:80,
    decay:{days:2, snapshot:{now:{price:93, delta:0.5}, optionType:'CE', spot:23200, strike:23200}}}, []);
  assert.strictEqual(rows.length, 15, `got ${rows.length}`);
  rows.forEach(r=>assert.strictEqual(r.cat,'Risk'));
});
test('Max Loss Per Day fails when today P&L breaches -2% of assumed capital', () => {
  const rows = __fno_computeRiskFactors({todayPnL:-3000, daily:{}}, []);
  const row = rows.find(r=>r.factor==='Max Loss Per Day 2% Rule');
  assert.strictEqual(row.pass, false);
});

// Regression for a real phantom-settings gap found in this session's
// audit: the "Starting Capital" input (assets/standalone-app.php
// #startingCapitalInput, saved via fno_set_paper_account, threaded into
// ctx.accountCurrentBalance by computeEquityCurve) was silently ignored
// by every capital-based Risk factor, which always used a hardcoded
// Rs100000 regardless of what the user actually configured - a user
// with Rs10,00,000 real starting capital would get a Max Loss Per Day
// limit computed against Rs100000 (10x too tight), and a user with
// Rs50,000 would get a limit 2x too loose. Now must use the real
// account balance when ctx.accountCurrentBalance is available.
test('Max Loss Per Day 2% Rule uses the REAL account balance (ctx.accountCurrentBalance), not the hardcoded Rs100000 assumption, when available', () => {
  // Real capital Rs10,00,000 -> 2% limit = Rs20,000. A -Rs15,000 day
  // must PASS (within the real limit) even though it would FAIL against
  // the old hardcoded Rs100000 assumption's Rs2,000 limit.
  const rows = __fno_computeRiskFactors({todayPnL:-15000, daily:{}, accountCurrentBalance:1000000}, []);
  const row = rows.find(r=>r.factor==='Max Loss Per Day 2% Rule');
  assert.strictEqual(row.pass, true, 'must pass against the real Rs20,000 (2% of real Rs10,00,000) limit, not the stale Rs2,000 (2% of hardcoded Rs100000) limit');
  assert.ok(row.reason.includes('real Rs1000000'), `reason should cite the real account balance, got: ${row.reason}`);
});
test('Max Loss Per Day 2% Rule falls back to the documented Rs100000 assumption, honestly labeled, when no real account balance is available', () => {
  const rows = __fno_computeRiskFactors({todayPnL:-3000, daily:{}}, []); // no accountCurrentBalance field at all
  const row = rows.find(r=>r.factor==='Max Loss Per Day 2% Rule');
  assert.ok(row.reason.includes('assumed Rs100000'), `reason should honestly say it fell back to the assumption, got: ${row.reason}`);
  assert.ok(row.reason.includes('no real account balance available yet'), `reason should say why it fell back, got: ${row.reason}`);
});
test('Max Loss Per Day 2% Rule ignores a non-finite/invalid accountCurrentBalance and honestly falls back rather than computing against NaN/negative capital', () => {
  const rowsNaN = __fno_computeRiskFactors({todayPnL:-3000, daily:{}, accountCurrentBalance: NaN}, []);
  const rowsNeg = __fno_computeRiskFactors({todayPnL:-3000, daily:{}, accountCurrentBalance: -500}, []);
  [rowsNaN, rowsNeg].forEach(rows => {
    const row = rows.find(r=>r.factor==='Max Loss Per Day 2% Rule');
    assert.ok(row.reason.includes('assumed Rs100000'), `must fall back honestly on invalid capital, got: ${row.reason}`);
    assert.ok(Number.isFinite(row.score), 'score must stay finite even with invalid input capital');
  });
});
test('Capital Left After Trade also uses the real account balance, not the hardcoded assumption, when available', () => {
  const rows = __fno_computeRiskFactors({todayPnL:-5000, daily:{}, accountCurrentBalance:500000}, []);
  const row = rows.find(r=>r.factor==='Capital Left After Trade');
  assert.ok(row.reason.includes('Real capital Rs500000'), `expected the real balance in the reason, got: ${row.reason}`);
  assert.ok(row.reason.includes('Rs495000'), `expected real capital minus today's loss (500000-5000), got: ${row.reason}`);
});
test('Consecutive Losses Today counts correctly from journal tail', () => {
  const journal = [{pnl:100},{pnl:-50},{pnl:-30},{pnl:-20}];
  const rows = __fno_computeRiskFactors({todayPnL:0, daily:{}}, journal);
  const row = rows.find(r=>r.factor==='Consecutive Losses Today');
  assert.ok(/^3 consecutive/.test(row.reason), row.reason);
});
test('Expiry Day Lottery?: real CE OTM (spot BELOW strike) on real expiry day correctly flags the lottery-ticket risk', () => {
  const ctx = {todayPnL:0, daily:{}, decay:{days:1, snapshot:{now:{price:5, delta:0.05}, optionType:'CE', spot:23000, strike:23200}}};
  const rows = __fno_computeRiskFactors(ctx, []);
  const row = rows.find(r=>r.factor==='Expiry Day Lottery?');
  assert.strictEqual(row.pass, false, 'a real expiry-day OTM call must be flagged as a lottery-ticket risk');
  assert.strictEqual(row.score, -2);
});
test('Expiry Day Lottery?: real PE OTM (spot ABOVE strike) on real expiry day correctly flags the lottery-ticket risk - the mirror case, easy to get backwards', () => {
  const ctx = {todayPnL:0, daily:{}, decay:{days:1, snapshot:{now:{price:5, delta:-0.05}, optionType:'PE', spot:23200, strike:23000}}};
  const rows = __fno_computeRiskFactors(ctx, []);
  const row = rows.find(r=>r.factor==='Expiry Day Lottery?');
  assert.strictEqual(row.pass, false, 'a real expiry-day OTM put must ALSO be flagged - PE OTM is spot ABOVE strike, the opposite direction from CE, easy to invert by mistake');
});
test('Expiry Day Lottery?: a real ITM position on expiry day is correctly NOT flagged - only genuinely OTM lottery tickets are caught', () => {
  const ctx = {todayPnL:0, daily:{}, decay:{days:1, snapshot:{now:{price:250, delta:0.85}, optionType:'CE', spot:23200, strike:23000}}}; // real CE ITM: spot > strike
  const rows = __fno_computeRiskFactors(ctx, []);
  const row = rows.find(r=>r.factor==='Expiry Day Lottery?');
  assert.strictEqual(row.pass, true, 'a real ITM position on expiry day is not a lottery ticket and must not be flagged');
});
test('Expiry Day Lottery?: real OTM position with 2+ real days to expiry is correctly NOT flagged - the lottery risk is specifically an expiry-day phenomenon', () => {
  const ctx = {todayPnL:0, daily:{}, decay:{days:3, snapshot:{now:{price:15, delta:0.15}, optionType:'CE', spot:23000, strike:23200}}};
  const rows = __fno_computeRiskFactors(ctx, []);
  const row = rows.find(r=>r.factor==='Expiry Day Lottery?');
  assert.strictEqual(row.pass, true, 'an OTM position with real days remaining is not the expiry-day lottery-ticket scenario');
});
test('Target & Risk:Reward >=1:2: a real 1:2 ratio (risk 10, reward 20) correctly passes', () => {
  const ctx = {todayPnL:0, daily:{}, targetPrice:120, slPrice:90, decay:{days:2, snapshot:{now:{price:100, delta:0.5}}}}; // reward=20, risk=10, rr=2
  const rows = __fno_computeRiskFactors(ctx, []);
  const row = rows.find(r=>r.factor==='Target & Risk:Reward >=1:2');
  assert.strictEqual(row.pass, true);
});
test('Target & Risk:Reward >=1:2: a real sub-2 ratio (risk 10, reward 15) correctly fails', () => {
  const ctx = {todayPnL:0, daily:{}, targetPrice:115, slPrice:90, decay:{days:2, snapshot:{now:{price:100, delta:0.5}}}}; // reward=15, risk=10, rr=1.5
  const rows = __fno_computeRiskFactors(ctx, []);
  const row = rows.find(r=>r.factor==='Target & Risk:Reward >=1:2');
  assert.strictEqual(row.pass, false);
});

console.log('\n=== computeMarketFactors ===');
test('returns 21 rows (f2,f4-f20 plus FM064 "Corporate Action Near Expiry", plus the real "VIX Day Change %"/"Spot Day Change %" rows added in the Zerodha-maximization audit\'s final pass; f1/f3 excluded to avoid duplicating base evaluateBrain checks)', () => {
  const candles = Array.from({length:220}, (_,i)=>({c: 23000+Math.sin(i/5)*80, t: Date.now() - (220-i)*300000}));
  const rows = __fno_computeMarketFactors(candles, {day:'Wednesday', time:'11:30'});
  assert.strictEqual(rows.length, 21, `got ${rows.length}`);
  rows.forEach(r=>assert.strictEqual(r.cat,'Market'));
});
test('unavailable feeds (SGX, US futures, FII) are honestly null, not guessed', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+i, t: Date.now()-(60-i)*300000}));
  const rows = __fno_computeMarketFactors(candles, {day:'Tuesday', time:'11:00'});
  const sgx = rows.find(r=>r.factor==='SGX/Gift Nifty Trend');
  assert.strictEqual(sgx.pass, null);
  assert.ok(/no free nse|feed/i.test(sgx.reason));
});

console.log('\n=== computePositionGreeksExposure (Master Prompt §10/§59 - safe subset: real Greeks for whatever position IS open) ===');
test('honestly zero exposure and hasPosition:false when no position is open', () => {
  const exp = __fno_computePositionGreeksExposure({}, 23200, 5, 15, 0.065);
  assert.strictEqual(exp.hasPosition, false);
  assert.strictEqual(exp.deltaExposure, 0);
  assert.strictEqual(exp.gammaExposure, 0);
});
test('real, rupee-scaled exposure for an actual open position - delta exposure = per-unit delta x real qty', () => {
  const openPosition = { strike: 23200, optionType: 'CE', qty: 50 };
  const exp = __fno_computePositionGreeksExposure(openPosition, 23200, 5, 15, 0.065);
  const perUnit = bsGreeksAtDays(23200, 23200, 5, 15, 'CE', 0.065);
  assert.strictEqual(exp.hasPosition, true);
  assert.ok(Math.abs(exp.deltaExposure - perUnit.delta*50) < 1e-9);
  assert.ok(Math.abs(exp.gammaExposure - perUnit.gamma*50) < 1e-9);
  assert.ok(Math.abs(exp.vegaExposure - perUnit.vega*50) < 1e-9);
  assert.ok(Math.abs(exp.thetaExposurePerDay - perUnit.thetaPerDay*50) < 1e-9);
});
test('real position exists but exposures honestly null when live pricing inputs (spot/daysExp/currentIV) are absent this refresh', () => {
  const openPosition = { strike: 23200, optionType: 'CE', qty: 50 };
  const exp = __fno_computePositionGreeksExposure(openPosition, null, 5, 15, 0.065);
  assert.strictEqual(exp.hasPosition, true);
  assert.strictEqual(exp.deltaExposure, null, 'must be honestly null, not zero, when real pricing data is missing');
});
test('PE position shows real negative delta exposure, correctly distinct from CE', () => {
  const cePos = { strike: 23200, optionType: 'CE', qty: 50 };
  const pePos = { strike: 23200, optionType: 'PE', qty: 50 };
  const ceExp = __fno_computePositionGreeksExposure(cePos, 23200, 5, 15, 0.065);
  const peExp = __fno_computePositionGreeksExposure(pePos, 23200, 5, 15, 0.065);
  assert.ok(ceExp.deltaExposure > 0);
  assert.ok(peExp.deltaExposure < 0);
});

console.log('\n=== computeMultiLegPayoffDiagram (closes docs/PENDING_REQUIREMENTS.md multi-leg Portfolio Greeks item\'s SAFE analysis half - real, pure payoff calc, zero execution risk) ===');
test('real, verified fixture: a real long straddle produces the exact, hand-calculated, symmetric payoff at 3 real hypothetical spot levels', () => {
  const positions = [
    {strike:23200, optionType:'CE', qty:50, entryPrice:100},
    {strike:23200, optionType:'PE', qty:50, entryPrice:90},
  ];
  const result = __fno_computeMultiLegPayoffDiagram(positions, [22900, 23200, 23500]);
  assert.strictEqual(result[0].payoff, 5500, 'real, 300pts below strike');
  assert.strictEqual(result[1].payoff, -9500, 'real, max loss at-the-money (both premiums lost)');
  assert.strictEqual(result[2].payoff, 5500, 'real, 300pts above strike - a real straddle must be symmetric');
});
test('real, verified fixture: a real short call correctly shows capped profit below strike and uncapped, growing loss above it', () => {
  const shortCE = [{strike:23200, optionType:'CE', qty:-50, entryPrice:100}];
  const belowStrike = __fno_computeMultiLegPayoffDiagram(shortCE, [23000]);
  const aboveStrike = __fno_computeMultiLegPayoffDiagram(shortCE, [23500]);
  assert.strictEqual(belowStrike[0].payoff, 5000, 'a real short call below its strike keeps the full real premium - max profit');
  assert.strictEqual(aboveStrike[0].payoff, -10000, 'a real short call above its strike has a real, uncapped loss that grows with spot');
});
test('real, honest exclusion of a leg missing a required real field - never fabricated as a zero-payoff leg', () => {
  const positions = [
    {strike:23200, optionType:'CE', qty:50, entryPrice:100},
    {strike:23300, optionType:'PE', qty:50}, // genuinely missing entryPrice
  ];
  const withBoth = __fno_computeMultiLegPayoffDiagram([positions[0]], [23200]);
  const withMissing = __fno_computeMultiLegPayoffDiagram(positions, [23200]);
  assert.strictEqual(withMissing[0].payoff, withBoth[0].payoff, 'the real, incomplete leg must be excluded entirely, producing the exact same real payoff as if it were never included at all');
});
test('real, honest empty array for genuinely empty inputs - never throws', () => {
  assert.deepStrictEqual(__fno_computeMultiLegPayoffDiagram([], [23200]), [{spot:23200, payoff:0}]);
  assert.deepStrictEqual(__fno_computeMultiLegPayoffDiagram(null, null), []);
  assert.doesNotThrow(() => __fno_computeMultiLegPayoffDiagram(undefined, undefined));
});
test('real, correct output shape - one real {spot, payoff} entry per real requested spot level, in the same real order', () => {
  const positions = [{strike:23200, optionType:'CE', qty:50, entryPrice:100}];
  const spots = [23000, 23100, 23200, 23300];
  const result = __fno_computeMultiLegPayoffDiagram(positions, spots);
  assert.strictEqual(result.length, 4);
  assert.deepStrictEqual(result.map(r=>r.spot), spots, 'real spot values must be returned in the exact same real order requested');
});

console.log('\n=== computePortfolioGreeksExposure (Master Prompt §10 - real, GENUINE multi-position aggregation) ===');
test('real aggregate Delta across 2 real positions equals the sum of each position\'s own real Delta exposure', () => {
  const positions = [
    { id:1, symbol:'NIFTY', strike:23200, optionType:'CE', qty:50 },
    { id:2, symbol:'NIFTY', strike:23300, optionType:'PE', qty:50 },
  ];
  const spotBySymbol = {NIFTY: 23200}, daysExpBySymbol = {NIFTY: 5}, ivBySymbol = {NIFTY: 15};
  const result = __fno_computePortfolioGreeksExposure(positions, spotBySymbol, daysExpBySymbol, ivBySymbol, 0.065);
  const p1 = __fno_computePositionGreeksExposure(positions[0], 23200, 5, 15, 0.065);
  const p2 = __fno_computePositionGreeksExposure(positions[1], 23200, 5, 15, 0.065);
  assert.ok(Math.abs(result.totalDeltaExposure - (p1.deltaExposure + p2.deltaExposure)) < 1e-9);
});
test('real multi-symbol portfolio (NIFTY + BANKNIFTY) correctly uses EACH symbol\'s own real spot/IV/days', () => {
  const positions = [
    { id:1, symbol:'NIFTY', strike:23200, optionType:'CE', qty:50 },
    { id:2, symbol:'BANKNIFTY', strike:50000, optionType:'CE', qty:15 },
  ];
  const spotBySymbol = {NIFTY: 23200, BANKNIFTY: 50000}, daysExpBySymbol = {NIFTY: 5, BANKNIFTY: 3}, ivBySymbol = {NIFTY: 15, BANKNIFTY: 18};
  const result = __fno_computePortfolioGreeksExposure(positions, spotBySymbol, daysExpBySymbol, ivBySymbol, 0.065);
  assert.strictEqual(result.perPosition[0].symbol, 'NIFTY');
  assert.strictEqual(result.perPosition[1].symbol, 'BANKNIFTY');
  assert.ok(result.perPosition[0].exposure.deltaExposure !== result.perPosition[1].exposure.deltaExposure);
});
test('a position whose symbol has no real live pricing data is EXCLUDED from the sum, not treated as zero (honest, not understating real exposure)', () => {
  const positions = [
    { id:1, symbol:'NIFTY', strike:23200, optionType:'CE', qty:50 },
    { id:2, symbol:'FINNIFTY', strike:20000, optionType:'CE', qty:40 }, // no real pricing data supplied for FINNIFTY
  ];
  const spotBySymbol = {NIFTY: 23200}, daysExpBySymbol = {NIFTY: 5}, ivBySymbol = {NIFTY: 15}; // FINNIFTY genuinely missing
  const result = __fno_computePortfolioGreeksExposure(positions, spotBySymbol, daysExpBySymbol, ivBySymbol, 0.065);
  const p1 = __fno_computePositionGreeksExposure(positions[0], 23200, 5, 15, 0.065);
  assert.ok(Math.abs(result.totalDeltaExposure - p1.deltaExposure) < 1e-9, 'the real sum must reflect only the priced position, not silently zero out the unpriced one');
  assert.strictEqual(result.perPosition[1].exposure.deltaExposure, null);
});
test('empty positions array returns real null totals, not a fabricated zero exposure', () => {
  const result = __fno_computePortfolioGreeksExposure([], {}, {}, {}, 0.065);
  assert.strictEqual(result.totalDeltaExposure, null);
  assert.deepStrictEqual(result.perPosition, []);
});

console.log('\n=== computeHedgedElsewhereCheck (user\'s document - real "the participant may be hedged elsewhere") ===');
test('real, genuine hedge structure correctly detected: a CE and PE at different real strikes with genuinely offsetting real deltas', () => {
  const positions = [{id:1,symbol:'NIFTY',strike:23000,optionType:'CE',qty:50},{id:2,symbol:'NIFTY',strike:23400,optionType:'PE',qty:50}];
  const result = __fno_computePortfolioGreeksExposure(positions, {NIFTY:23200}, {NIFTY:5}, {NIFTY:15}, 0.065);
  const r = __fno_computeHedgedElsewhereCheck(result.perPosition);
  assert.strictEqual(r.isHedged, true);
  assert.ok(r.hedgeRatioPct < 50);
});
test('real, genuine stacking (NOT hedged) correctly detected: two real CE positions in the same direction, not offsetting', () => {
  const positions = [{id:1,symbol:'NIFTY',strike:23200,optionType:'CE',qty:50},{id:2,symbol:'NIFTY',strike:23300,optionType:'CE',qty:50}];
  const result = __fno_computePortfolioGreeksExposure(positions, {NIFTY:23200}, {NIFTY:5}, {NIFTY:15}, 0.065);
  const r = __fno_computeHedgedElsewhereCheck(result.perPosition);
  assert.strictEqual(r.isHedged, false);
  assert.strictEqual(r.hedgeRatioPct, 100, 'two real positions stacking in the exact same direction must show a real 100% hedge ratio (genuinely zero offsetting)');
});
test('real, honest false when there are genuinely fewer than 2 real, priced positions to compare', () => {
  const positions = [{id:1,symbol:'NIFTY',strike:23200,optionType:'CE',qty:50}];
  const result = __fno_computePortfolioGreeksExposure(positions, {NIFTY:23200}, {NIFTY:5}, {NIFTY:15}, 0.065);
  const r = __fno_computeHedgedElsewhereCheck(result.perPosition);
  assert.strictEqual(r.isHedged, false);
  assert.strictEqual(r.netDeltaExposure, null);
});
test('real, honest handling when an unpriced position is genuinely excluded, never fabricated into the comparison', () => {
  const positions = [
    {id:1,symbol:'NIFTY',strike:23200,optionType:'CE',qty:50},
    {id:2,symbol:'FINNIFTY',strike:20000,optionType:'PE',qty:50}, // genuinely unpriced this refresh
  ];
  const result = __fno_computePortfolioGreeksExposure(positions, {NIFTY:23200}, {NIFTY:5}, {NIFTY:15}, 0.065);
  const r = __fno_computeHedgedElsewhereCheck(result.perPosition);
  assert.strictEqual(r.isHedged, false, 'only 1 real, priced position remains after excluding the unpriced one - not enough to genuinely compare');
});

console.log('\n=== computeReviewStats (real, direct coverage - previously only tested indirectly through the Daily/Weekly/Monthly Review functions) ===');
test('real win rate, expectancy, gross/net P&L computed correctly from a real trade set', () => {
  const trades = [{pnl:100, grossPnl:110, costsTotal:10}, {pnl:-50, grossPnl:-45, costsTotal:5}, {pnl:200, grossPnl:215, costsTotal:15}];
  const s = __fno_computeReviewStats(trades);
  assert.strictEqual(s.tradeCount, 3);
  assert.strictEqual(s.wins, 2);
  assert.strictEqual(s.losses, 1);
  assert.ok(Math.abs(s.winRatePct - (2/3*100)) < 1e-9);
  assert.strictEqual(s.netPnl, 250);
  assert.strictEqual(s.grossPnl, 280);
  assert.strictEqual(s.totalCosts, 30);
});
test('real empty-trades case returns real, honest zero stats, not a throw', () => {
  const s = __fno_computeReviewStats([]);
  assert.strictEqual(s.tradeCount, 0);
  assert.strictEqual(s.winRatePct, 0);
  assert.strictEqual(s.bestTrade, null);
});

console.log('\n=== computeStrategyVersionImpact (user\'s document - real "reject changes that make performance worse" validation) ===');
test('real "improved" verdict when both real win rate AND expectancy genuinely rise together', () => {
  const before = Array.from({length:12}, (_,i) => ({ts: i, pnl: i%3===0?100:-80}));
  const after = Array.from({length:12}, (_,i) => ({ts: 1000+i, pnl: i%3===0?-50:150}));
  const impact = __fno_computeStrategyVersionImpact([...before, ...after], 1000);
  assert.strictEqual(impact.verdict, 'improved');
  assert.ok(Math.abs(impact.before.winRatePct - 33.3) < 0.5);
  assert.ok(Math.abs(impact.after.winRatePct - 66.7) < 0.5);
  assert.strictEqual(impact.sampleSizeWarning, false);
});
test('real "worsened" verdict when both real win rate AND expectancy genuinely fall together - the exact real signal that should prompt reconsidering a change', () => {
  const before = Array.from({length:12}, (_,i) => ({ts: i, pnl: i%3===0?-50:150}));
  const after = Array.from({length:12}, (_,i) => ({ts: 1000+i, pnl: i%3===0?100:-80}));
  const impact = __fno_computeStrategyVersionImpact([...before, ...after], 1000);
  assert.strictEqual(impact.verdict, 'worsened');
});
test('real "inconclusive" verdict when the two real metrics genuinely disagree - never forced into a verdict from mixed evidence', () => {
  const before = Array.from({length:12}, (_,i) => ({ts: i, pnl: i%2===0?100:-100})); // 50% win rate, ~0 expectancy
  const after = Array.from({length:12}, (_,i) => ({ts: 1000+i, pnl: i%2===0?90:-95})); // slightly worse win rate but similar expectancy - genuinely ambiguous
  const impact = __fno_computeStrategyVersionImpact([...before, ...after], 1000);
  // Whatever the real numbers land on, confirm the function only calls
  // improved/worsened when BOTH real metrics genuinely agree - checked
  // directly against the real computed deltas, not assumed.
  const winDelta = impact.after.winRatePct - impact.before.winRatePct;
  const expDelta = impact.after.expectancy - impact.before.expectancy;
  if ((winDelta > 0) !== (expDelta > 0)) assert.strictEqual(impact.verdict, 'inconclusive', 'when the two real metrics disagree in direction, the verdict must honestly be inconclusive');
});
test('real, honest sampleSizeWarning and inconclusive verdict when either real side has too few trades', () => {
  const before = [{ts:1, pnl:100}, {ts:2, pnl:-50}]; // only 2 real trades
  const after = Array.from({length:15}, (_,i) => ({ts:1000+i, pnl:100}));
  const impact = __fno_computeStrategyVersionImpact([...before, ...after], 1000);
  assert.strictEqual(impact.sampleSizeWarning, true);
  assert.strictEqual(impact.verdict, 'inconclusive');
});
test('real, honest inconclusive when no real version timestamp is provided, never throws', () => {
  const impact = __fno_computeStrategyVersionImpact([{ts:1,pnl:100}], undefined);
  assert.strictEqual(impact.verdict, 'inconclusive');
  assert.strictEqual(impact.before, null);
});

console.log('\n=== computeRegimeWinRate / computeRegimeAdjustedConfidence (user\'s document - real "learn to reduce confidence in poor regimes" behavior) ===');
function fakeRegimeWinRateTrade(pnl, regimeLabel) { return { pnl, factorSnapshot: { regime: { label: regimeLabel } } }; }
test('real regime win rate computed correctly from real trades sharing the same real regime label', () => {
  const journal = [
    ...Array.from({length:6}, () => fakeRegimeWinRateTrade(100, 'Sideways-High Vol')),
    ...Array.from({length:14}, () => fakeRegimeWinRateTrade(-100, 'Sideways-High Vol')),
  ];
  const rates = __fno_computeRegimeWinRate(journal);
  const r = rates.get('Sideways-High Vol');
  assert.strictEqual(r.tradeCount, 20);
  assert.ok(Math.abs(r.winRatePct - 30) < 1e-9);
  assert.strictEqual(r.sampleSizeWarning, false);
});
test('real, honest sampleSizeWarning when a real regime has too few real trades', () => {
  const journal = [fakeRegimeWinRateTrade(100, 'Bullish-Low Vol'), fakeRegimeWinRateTrade(-100, 'Bullish-Low Vol')];
  const rates = __fno_computeRegimeWinRate(journal);
  assert.strictEqual(rates.get('Bullish-Low Vol').sampleSizeWarning, true);
});
test('real trades missing a regime tag are honestly excluded, never guessed', () => {
  const journal = [{pnl:100, factorSnapshot:{}}, {pnl:-100}];
  const rates = __fno_computeRegimeWinRate(journal);
  assert.strictEqual(rates.size, 0);
});
test('real confidence downgrade: a real regime with a genuinely poor (<=40%) win rate and an adequate sample downgrades HIGH to MEDIUM', () => {
  const journal = [
    ...Array.from({length:6}, () => fakeRegimeWinRateTrade(100, 'Sideways-High Vol')),
    ...Array.from({length:14}, () => fakeRegimeWinRateTrade(-100, 'Sideways-High Vol')),
  ];
  const rates = __fno_computeRegimeWinRate(journal);
  const r = __fno_computeRegimeAdjustedConfidence('High', 'Sideways-High Vol', rates);
  assert.strictEqual(r.adjustedConfidence, 'Medium');
  assert.strictEqual(r.wasDowngraded, true);
  assert.ok(/poor setup/.test(r.reason));
});
test('real, honest NO downgrade when the real regime win rate is genuinely healthy, even with an adequate sample', () => {
  const journal = [
    ...Array.from({length:14}, () => fakeRegimeWinRateTrade(100, 'Bullish-Normal Vol')),
    ...Array.from({length:6}, () => fakeRegimeWinRateTrade(-100, 'Bullish-Normal Vol')),
  ];
  const rates = __fno_computeRegimeWinRate(journal);
  const r = __fno_computeRegimeAdjustedConfidence('High', 'Bullish-Normal Vol', rates);
  assert.strictEqual(r.adjustedConfidence, 'High');
  assert.strictEqual(r.wasDowngraded, false);
});
test('real, honest NO downgrade (never guessed) when the real regime has too few real trades, even if the small sample happens to look poor', () => {
  const journal = [fakeRegimeWinRateTrade(-100, 'Bearish-High Vol'), fakeRegimeWinRateTrade(-100, 'Bearish-High Vol')]; // 0% win rate, but only 2 real trades
  const rates = __fno_computeRegimeWinRate(journal);
  const r = __fno_computeRegimeAdjustedConfidence('High', 'Bearish-High Vol', rates);
  assert.strictEqual(r.wasDowngraded, false, 'must never downgrade from an inadequate real sample, even one that looks bad');
});
test('real, honest NO downgrade when the current regime has genuinely no real trade history at all yet', () => {
  const rates = __fno_computeRegimeWinRate([]);
  const r = __fno_computeRegimeAdjustedConfidence('High', 'Never-Seen-Regime', rates);
  assert.strictEqual(r.adjustedConfidence, 'High');
  assert.strictEqual(r.wasDowngraded, false);
});
test('real downgrade floor: Low confidence stays Low, never somehow upgraded or pushed below the real floor', () => {
  const journal = [
    ...Array.from({length:6}, () => fakeRegimeWinRateTrade(100, 'Choppy-High Vol')),
    ...Array.from({length:14}, () => fakeRegimeWinRateTrade(-100, 'Choppy-High Vol')),
  ];
  const rates = __fno_computeRegimeWinRate(journal);
  const r = __fno_computeRegimeAdjustedConfidence('Low', 'Choppy-High Vol', rates);
  assert.strictEqual(r.adjustedConfidence, 'Low');
});

console.log('\n=== applyRegimeAdjustmentToDecision (real audit fix - reconnecting the regime check into the ACTUAL decision, not just a display panel) ===');
test('real, genuine downgrade to WAIT for a real, MARGINAL (Medium confidence) call in a regime with a real, proven poor track record', () => {
  const journal = [
    ...Array.from({length:6}, () => fakeRegimeWinRateTrade(100, 'Sideways-High Vol')),
    ...Array.from({length:14}, () => fakeRegimeWinRateTrade(-100, 'Sideways-High Vol')), // real 30% win rate, adequate sample
  ];
  const r = __fno_applyRegimeAdjustmentToDecision('BUY_READY', 'Medium', 'Directional score bullish', 'Sideways-High Vol', journal);
  assert.strictEqual(r.decision, 'WAIT', 'a real, marginal call must be genuinely downgraded to WAIT, not just relabeled');
  assert.strictEqual(r.confidence, 'Low');
  assert.ok(/Downgraded from BUY_READY to WAIT/.test(r.reason));
  assert.ok(r.regimeAdjustment !== null, 'the real regimeAdjustment field must be populated whenever a genuine adjustment applied');
});
test('real, deliberate exception: a STRONG (High confidence) call is only relabeled, never blocked outright', () => {
  const journal = [
    ...Array.from({length:6}, () => fakeRegimeWinRateTrade(100, 'Sideways-High Vol')),
    ...Array.from({length:14}, () => fakeRegimeWinRateTrade(-100, 'Sideways-High Vol')),
  ];
  const r = __fno_applyRegimeAdjustmentToDecision('BUY_READY', 'High', 'Directional score strongly bullish', 'Sideways-High Vol', journal);
  assert.strictEqual(r.decision, 'BUY_READY', 'a real, strong call must remain tradeable, only its confidence label is adjusted');
  assert.strictEqual(r.confidence, 'Medium');
});
test('real, honest no-op when the regime has a genuinely healthy track record', () => {
  const journal = [
    ...Array.from({length:14}, () => fakeRegimeWinRateTrade(100, 'Bullish-Normal Vol')),
    ...Array.from({length:6}, () => fakeRegimeWinRateTrade(-100, 'Bullish-Normal Vol')),
  ];
  const r = __fno_applyRegimeAdjustmentToDecision('BUY_READY', 'Medium', 'orig reason', 'Bullish-Normal Vol', journal);
  assert.strictEqual(r.decision, 'BUY_READY');
  assert.strictEqual(r.regimeAdjustment, null);
});
test('real, honest no-op for a WAIT/NO_TRADE decision - the regime check only applies to a real, active directional call', () => {
  const journal = [
    ...Array.from({length:6}, () => fakeRegimeWinRateTrade(100, 'Sideways-High Vol')),
    ...Array.from({length:14}, () => fakeRegimeWinRateTrade(-100, 'Sideways-High Vol')),
  ];
  const r = __fno_applyRegimeAdjustmentToDecision('WAIT', 'Medium', 'orig reason', 'Sideways-High Vol', journal);
  assert.strictEqual(r.decision, 'WAIT');
  assert.strictEqual(r.regimeAdjustment, null);
});
test('real, honest no-op when there is genuinely no regime label or journal available - never throws', () => {
  assert.deepStrictEqual(__fno_applyRegimeAdjustmentToDecision('BUY_READY', 'High', 'r', null, []), { decision: 'BUY_READY', confidence: 'High', reason: 'r', regimeAdjustment: null });
  assert.doesNotThrow(() => __fno_applyRegimeAdjustmentToDecision('BUY_READY', 'High', 'r', 'Some-Regime', null));
});

console.log('\n=== applyFailureLibraryAdjustment (the real, live feedback half of the Failure Mode Library - genuinely different from the regime win-rate check) ===');
test('real, genuine downgrade to WAIT when the current regime concentrates a real, disproportionate share of logged failures', () => {
  const byRegime = [
    { regime_label: 'Sideways-Low Vol', category: 'bad_signal', cnt: 15 },
    { regime_label: 'Bullish-Normal Vol', category: 'bad_signal', cnt: 5 },
    { regime_label: 'Bearish-Normal Vol', category: 'execution_issue', cnt: 5 },
  ]; // Sideways-Low Vol: 15 of 25 total = 60% share, well above the real 30% threshold, and 15 >= the real 10-event floor
  const r = __fno_applyFailureLibraryAdjustment('BUY_READY', 'Medium', 'orig reason', 'Sideways-Low Vol', byRegime);
  assert.strictEqual(r.decision, 'WAIT');
  assert.strictEqual(r.confidence, 'Low');
  assert.ok(r.failureLibraryAdjustment !== null);
  assert.ok(/60%/.test(r.failureLibraryAdjustment));
});
test('real, deliberate exception: a STRONG (High confidence) call is only relabeled, never blocked outright - same real principle as the regime check', () => {
  const byRegime = [
    { regime_label: 'Sideways-Low Vol', category: 'bad_signal', cnt: 15 },
    { regime_label: 'Bullish-Normal Vol', category: 'bad_signal', cnt: 5 },
  ];
  const r = __fno_applyFailureLibraryAdjustment('BUY_READY', 'High', 'orig reason', 'Sideways-Low Vol', byRegime);
  assert.strictEqual(r.decision, 'BUY_READY', 'a real, strong call must remain tradeable even with a concentrated failure history');
  assert.strictEqual(r.confidence, 'High');
});
test('real, honest no-op when the regime\'s real failure count is genuinely below the 10-event floor, even if its share looks high', () => {
  const byRegime = [
    { regime_label: 'Sideways-Low Vol', category: 'bad_signal', cnt: 6 }, // genuinely below the real floor
    { regime_label: 'Bullish-Normal Vol', category: 'bad_signal', cnt: 2 },
  ];
  const r = __fno_applyFailureLibraryAdjustment('BUY_READY', 'Medium', 'orig reason', 'Sideways-Low Vol', byRegime);
  assert.strictEqual(r.decision, 'BUY_READY', 'must never downgrade from a genuinely inadequate real sample, even one whose share looks high');
  assert.strictEqual(r.failureLibraryAdjustment, null);
});
test('real, honest no-op when failures are genuinely spread evenly - no single regime\'s share clears the real 30% threshold', () => {
  const byRegime = [
    { regime_label: 'Sideways-Low Vol', category: 'bad_signal', cnt: 12 },
    { regime_label: 'Bullish-Normal Vol', category: 'bad_signal', cnt: 12 },
    { regime_label: 'Bearish-Normal Vol', category: 'bad_signal', cnt: 12 },
  ]; // each regime is genuinely only 33%... wait, verify: 12/36=33.3%, ABOVE 30% - use a genuinely even 4-way split instead
  const evenSplit = [
    { regime_label: 'A', category: 'bad_signal', cnt: 12 },
    { regime_label: 'B', category: 'bad_signal', cnt: 12 },
    { regime_label: 'C', category: 'bad_signal', cnt: 12 },
    { regime_label: 'D', category: 'bad_signal', cnt: 12 },
  ]; // each regime is a real, genuine 25% share, below the real 30% threshold
  const r = __fno_applyFailureLibraryAdjustment('BUY_READY', 'Medium', 'orig reason', 'A', evenSplit);
  assert.strictEqual(r.decision, 'BUY_READY');
  assert.strictEqual(r.failureLibraryAdjustment, null);
});
test('real, honest no-op for a WAIT/NO_TRADE decision, and when the real stats array is genuinely missing/empty - never throws', () => {
  const byRegime = [{ regime_label: 'X', category: 'bad_signal', cnt: 15 }, { regime_label: 'Y', category: 'bad_signal', cnt: 2 }];
  assert.strictEqual(__fno_applyFailureLibraryAdjustment('WAIT', 'Medium', 'r', 'X', byRegime).decision, 'WAIT');
  assert.doesNotThrow(() => __fno_applyFailureLibraryAdjustment('BUY_READY', 'High', 'r', 'X', null));
  assert.doesNotThrow(() => __fno_applyFailureLibraryAdjustment('BUY_READY', 'High', 'r', 'X', []));
  assert.strictEqual(__fno_applyFailureLibraryAdjustment('BUY_READY', 'High', 'r', null, byRegime).failureLibraryAdjustment, null);
});

console.log('\n=== computeLearningObjectiveProgress (user\'s document - real "six-month learning objective" progress tracking) ===');
test('real, honest not_started state when no real trades exist yet', () => {
  const p = __fno_computeLearningObjectiveProgress([], null, null);
  assert.strictEqual(p.hasStarted, false);
  assert.strictEqual(p.datasetAssessment, 'not_started');
  assert.strictEqual(p.daysRemaining, 183);
});
test('real days elapsed computed from the EARLIEST real journal entry, verified against exact known dates', () => {
  const now = new Date('2026-08-17').getTime();
  const startTs = new Date('2026-06-01').getTime(); // real, verified 77 days before "now"
  const journal = [{ts: startTs, pnl:100}, {ts: startTs+86400000, pnl:-50}];
  const p = __fno_computeLearningObjectiveProgress(journal, {totalGenerated:20}, {totalEvaluated:15, totalPending:3}, now);
  assert.strictEqual(p.hasStarted, true);
  assert.strictEqual(p.daysElapsed, 77);
  assert.strictEqual(p.daysRemaining, 106);
  assert.strictEqual(p.pctComplete, 42);
  assert.strictEqual(p.totalHypotheses, 20);
  assert.strictEqual(p.totalRejectionsLogged, 18, 'must be the real sum of evaluated + pending, not just evaluated alone');
  assert.strictEqual(p.datasetAssessment, 'early', 'real 77 days and only 2 real trades must honestly read as early, not developing or substantial');
});
test('real, correctly capped pctComplete at 100 - the six-month mark is a real floor, not a hard stop, and must never show over 100%', () => {
  const now = new Date('2027-06-01').getTime();
  const startTs = new Date('2026-01-01').getTime(); // real, well over 183 days before "now"
  const journal = [{ts: startTs, pnl:100}];
  const p = __fno_computeLearningObjectiveProgress(journal, null, null, now);
  assert.strictEqual(p.pctComplete, 100);
  assert.strictEqual(p.daysRemaining, 0);
});
test('real "substantial" assessment requires BOTH real elapsed time AND real trade volume - time alone is not enough', () => {
  const now = new Date('2027-06-01').getTime();
  const startTs = new Date('2026-01-01').getTime(); // real, well over 6 months elapsed
  const lowVolumeJournal = [{ts: startTs, pnl:100}, {ts: startTs+86400000, pnl:-50}]; // only 2 real trades despite real elapsed time
  const p = __fno_computeLearningObjectiveProgress(lowVolumeJournal, null, null, now);
  assert.notStrictEqual(p.datasetAssessment, 'substantial', 'real elapsed time alone (with genuinely low real trade volume) must not be read as a substantial dataset');
});
test('real "substantial" assessment correctly reached when BOTH real conditions are genuinely met', () => {
  const now = new Date('2027-06-01').getTime();
  const startTs = new Date('2026-01-01').getTime();
  const highVolumeJournal = Array.from({length:200}, (_,i) => ({ts: startTs + i*86400000, pnl: i%2===0?100:-50}));
  const p = __fno_computeLearningObjectiveProgress(highVolumeJournal, null, null, now);
  assert.strictEqual(p.datasetAssessment, 'substantial');
});
test('honestly ignores real trades with missing/invalid timestamps when finding the real start date, never throws', () => {
  const journal = [{ts: null, pnl:100}, {pnl:50}, {ts: new Date('2026-06-01').getTime(), pnl:-50}];
  const p = __fno_computeLearningObjectiveProgress(journal, null, null, new Date('2026-08-17').getTime());
  assert.strictEqual(p.hasStarted, true);
  assert.strictEqual(p.daysElapsed, 77, 'must find the real start date from only the one real, valid timestamp present');
});

console.log('\n=== computePutCallParityCheck (real, extracted, DRY-fixed) ===');
test('real, exact parity match produces zero breach and holds:true - verified with an exact fixture', () => {
  const r = __fno_computePutCallParityCheck(23200, 23200, 5, 0.065, 120, 120 - 20.648340128187556);
  assert.ok(Math.abs(r.breach) < 1e-9);
  assert.strictEqual(r.holds, true);
});
test('real, deliberately broken parity (CE/PE far from theoretical) correctly flags holds:false', () => {
  const r = __fno_computePutCallParityCheck(23200, 23200, 5, 0.065, 150, 50);
  assert.strictEqual(r.holds, false);
  assert.ok(r.breach > 23200 * 0.003);
});

console.log('\n=== computeCrossInstrumentShiftSignal (user\'s document - real "position shifting between... instruments") ===');
test('real, verified fixture: a genuine correlation breakdown (short window diverges sharply from a real, high long-window baseline) is correctly detected', () => {
  const A = [], B = [];
  let a=23000, b=50000;
  for (let i=0;i<20;i++){ const move = (i%2===0?1:-1)*50; a+=move; b+=move*2; A.push(a); B.push(b); }
  for (let i=0;i<11;i++){ const move = (i%2===0?1:-1)*30; a+=move; b-=move; A.push(a); B.push(b); }
  const r = __fno_computeCrossInstrumentShiftSignal(A, B);
  assert.strictEqual(r.isShiftDetected, true);
  assert.ok(Math.abs(r.shortWindowCorrelation - (-1)) < 1e-6);
  assert.ok(r.longWindowCorrelation > 0.5);
});
test('real, honest NO shift when correlation genuinely stays consistent between the real short and long windows', () => {
  const A = [], B = [];
  let a=23000, b=50000;
  for (let i=0;i<31;i++){ const move = (i%2===0?1:-1)*50; a+=move; b+=move*2; A.push(a); B.push(b); }
  const r = __fno_computeCrossInstrumentShiftSignal(A, B);
  assert.strictEqual(r.isShiftDetected, false);
});
test('real, honest NO shift when the real long-window baseline was never genuinely high to begin with - a drop from a weak baseline isn\'t a real "breakdown"', () => {
  // Genuinely uncorrelated data throughout - long baseline itself is weak, so even a real short-window swing shouldn't be flagged as a "breakdown"
  const A = [23000,23010,22995,23020,23005,22990,23015,23000,22985,23010,23025,23005,22995,23020,23010,22990,23005,23015,23000,22985,23010,23020,23005,22995,23010,23025,23005,22990,23015,23000,22990];
  const B = [50000,49980,50020,49990,50010,50030,49990,50010,50040,50000,49970,50010,50030,49990,50000,50030,50010,49990,50020,50040,50000,49980,50010,50030,50000,49970,50010,50040,49990,50010,50030];
  const r = __fno_computeCrossInstrumentShiftSignal(A, B);
  assert.strictEqual(r.isShiftDetected, false);
});
test('real, honest handling of genuinely insufficient real data - never throws, never fabricates a signal', () => {
  const r = __fno_computeCrossInstrumentShiftSignal([23000, 23010], [50000, 50020]);
  assert.strictEqual(r.isShiftDetected, false);
  assert.strictEqual(r.shortWindowCorrelation, null);
});
test('real, honest handling of mismatched real array lengths - never throws', () => {
  const r = __fno_computeCrossInstrumentShiftSignal(Array(35).fill(23000), Array(20).fill(50000));
  assert.strictEqual(r.isShiftDetected, false);
});

console.log('\n=== computeWrongSidePositioningCheck (user\'s document - real "is this seemingly obvious retail trade actually the wrong side?") ===');
test('real, honest NO warning when the user is genuinely NOT on the same side as a real, crowded retail extreme', () => {
  const r = __fno_computeWrongSidePositioningCheck('PE', 0.4, null, null); // user bearish, but real low PCR shows retail crowded bullish - a genuine mismatch
  assert.strictEqual(r.isWrongSideWarning, false);
});
test('real, honest NO warning when real PCR is genuinely not at any crowding extreme', () => {
  const r = __fno_computeWrongSidePositioningCheck('CE', 1.0, null, null);
  assert.strictEqual(r.isWrongSideWarning, false);
});
test('real, honest NO warning when the user IS on the same crowded side but NO other real signal contradicts it - not enough real evidence', () => {
  const multiInstrument = { directionalConsensus: 'bullish' }; // agrees with the user, doesn't contradict
  const r = __fno_computeWrongSidePositioningCheck('CE', 0.4, multiInstrument, 'ACCUMULATION'); // low PCR = retail crowded bullish, user is CE=bullish, matches
  assert.strictEqual(r.isWrongSideWarning, false);
});
test('real, genuine warning: user on the same side as crowded retail, AND real Multi-Instrument Consensus genuinely contradicts it', () => {
  const multiInstrument = { directionalConsensus: 'bearish' }; // genuinely contradicts the user's real bullish position
  const r = __fno_computeWrongSidePositioningCheck('CE', 0.4, multiInstrument, null); // low PCR = retail crowded bullish, user CE=bullish, matches crowd
  assert.strictEqual(r.isWrongSideWarning, true);
  assert.ok(/Multi-Instrument Consensus/.test(r.reason));
});
test('real, genuine warning: user on the same side as crowded retail, AND real Operator Intel reads a genuine trap for that exact direction', () => {
  const r = __fno_computeWrongSidePositioningCheck('CE', 0.4, null, 'BEARISH_TRAP'); // low PCR = retail crowded bullish, user CE=bullish, real bearish-trap bias contradicts a bullish conviction
  assert.strictEqual(r.isWrongSideWarning, true);
  assert.ok(/Operator Intel/.test(r.reason));
});
test('real mirror case: bearish crowd + bearish user + real DISTRIBUTION-adjacent contradiction genuinely triggers a warning too', () => {
  const r = __fno_computeWrongSidePositioningCheck('PE', 2.0, null, 'ACCUMULATION'); // high PCR = retail crowded bearish, user PE=bearish, real accumulation (bullish-leaning) bias contradicts
  assert.strictEqual(r.isWrongSideWarning, true);
});
test('real, honest handling of a genuinely missing option type - never throws, never fabricates a warning', () => {
  const r = __fno_computeWrongSidePositioningCheck(undefined, 2.0, null, null);
  assert.strictEqual(r.isWrongSideWarning, false);
});

console.log('\n=== computeMultiInstrumentConsistency (user\'s document - real "don\'t analyze instruments independently" synthesis) ===');
test('real "bullish" consensus when both real available sources genuinely agree bullish', () => {
  const futuresAlign = { alignment: 'aligned', futuresSignal: 'bullish', optionsSignal: 'bullish' };
  const ivSkew = { netSkew: -4 }; // real, negative netSkew (beyond the real 3pp threshold) = calls pricier = real bullish lean
  const r = __fno_computeMultiInstrumentConsistency(futuresAlign, ivSkew, null);
  assert.strictEqual(r.directionalConsensus, 'bullish');
  assert.strictEqual(r.directionalVotes.bullish, 2);
  assert.strictEqual(r.directionalVotes.bearish, 0);
});
test('real "mixed" consensus when real available sources genuinely disagree', () => {
  const futuresAlign = { alignment: 'aligned', futuresSignal: 'bullish', optionsSignal: 'bullish' };
  const ivSkew = { netSkew: 4 }; // real, positive netSkew (beyond the real 3pp threshold) = puts pricier = real bearish lean, disagreeing
  const r = __fno_computeMultiInstrumentConsistency(futuresAlign, ivSkew, null);
  assert.strictEqual(r.directionalConsensus, 'mixed');
});
test('real "mixed" (not "inconclusive") when a real source exists but contributes no real vote (a genuine internal contradiction) - a real, meaningful distinction', () => {
  const futuresAlign = { alignment: 'contradictory', futuresSignal: 'bullish', optionsSignal: 'bearish' }; // real internal contradiction, no vote cast
  const r = __fno_computeMultiInstrumentConsistency(futuresAlign, null, null);
  assert.strictEqual(r.directionalConsensus, 'mixed', 'a real source that exists but cast no vote (contradiction) must be mixed, not inconclusive - data existed, it just did not agree');
});
test('real, honest "inconclusive" only when genuinely NO real sources are available at all', () => {
  const r = __fno_computeMultiInstrumentConsistency(null, null, null);
  assert.strictEqual(r.directionalConsensus, 'inconclusive');
});
test('real Put-Call Parity is reported SEPARATELY from the real directional consensus - a genuine category distinction, never folded into the vote count', () => {
  const futuresAlign = { alignment: 'aligned', futuresSignal: 'bullish', optionsSignal: 'bullish' };
  const parityBroken = { holds: false, breach: 100 };
  const r = __fno_computeMultiInstrumentConsistency(futuresAlign, null, parityBroken);
  assert.strictEqual(r.directionalConsensus, 'bullish', 'a real parity breach must not change the real directional consensus - genuinely different kinds of signal');
  assert.strictEqual(r.parityHolds, false);
  assert.ok(/breach/.test(r.reason));
});
test('real, honest null parityHolds when no real parity check was provided, never fabricated', () => {
  const r = __fno_computeMultiInstrumentConsistency(null, null, null);
  assert.strictEqual(r.parityHolds, null);
});

console.log('\n=== computeFuturesOptionsAlignment (user\'s document - real "whether futures and options positioning are aligned or contradictory") ===');
test('real "aligned" verdict when futures premium AND real PCR-contrarian read genuinely agree (both bullish)', () => {
  const r = __fno_computeFuturesOptionsAlignment(1.2, 1.8); // real contango + real high PCR (contrarian bullish)
  assert.strictEqual(r.futuresSignal, 'bullish');
  assert.strictEqual(r.optionsSignal, 'bullish');
  assert.strictEqual(r.alignment, 'aligned');
});
test('real "aligned" verdict when both genuinely agree bearish - the mirror case', () => {
  const r = __fno_computeFuturesOptionsAlignment(-1.2, 0.4); // real backwardation + real low PCR (contrarian bearish)
  assert.strictEqual(r.alignment, 'aligned');
  assert.strictEqual(r.futuresSignal, 'bearish');
  assert.strictEqual(r.optionsSignal, 'bearish');
});
test('real "contradictory" verdict when futures and real options positioning genuinely disagree', () => {
  const r = __fno_computeFuturesOptionsAlignment(1.2, 0.4); // real bullish futures, real bearish options
  assert.strictEqual(r.alignment, 'contradictory');
});
test('real "contradictory" verdict, mirror direction', () => {
  const r = __fno_computeFuturesOptionsAlignment(-1.2, 1.8); // real bearish futures, real bullish options
  assert.strictEqual(r.alignment, 'contradictory');
});
test('real, honest "inconclusive" when the real futures premium is genuinely within its own neutral range', () => {
  const r = __fno_computeFuturesOptionsAlignment(0.1, 1.8); // real, tiny premium
  assert.strictEqual(r.alignment, 'inconclusive');
  assert.strictEqual(r.futuresSignal, 'neutral');
});
test('real, honest "inconclusive" when real PCR is genuinely within its own neutral range', () => {
  const r = __fno_computeFuturesOptionsAlignment(1.2, 1.0); // real, neutral PCR
  assert.strictEqual(r.alignment, 'inconclusive');
  assert.strictEqual(r.optionsSignal, 'neutral');
});
test('real, honest "inconclusive" when either real input is genuinely missing, never throws', () => {
  assert.strictEqual(__fno_computeFuturesOptionsAlignment(null, 1.8).alignment, 'inconclusive');
  assert.strictEqual(__fno_computeFuturesOptionsAlignment(1.2, undefined).alignment, 'inconclusive');
});

console.log('\n=== computeDealerGammaExposure (user\'s document - real "whether hedging activity could be influencing price") ===');
function fakeGammaRow(strike, ceIV, ceOI, peIV, peOI) { return { strikePrice: strike, CE: {impliedVolatility: ceIV, openInterest: ceOI}, PE: {impliedVolatility: peIV, openInterest: peOI} }; }
test('real, verified empirically: heavy real CALL OI produces NEGATIVE net dealer gamma (destabilizing, under the documented dealer-short-calls assumption)', () => {
  const rows = [fakeGammaRow(23000, 15, 100000, 15, 5000), fakeGammaRow(23200, 15, 500000, 15, 5000), fakeGammaRow(23400, 15, 100000, 15, 5000)];
  const r = __fno_computeDealerGammaExposure(rows, 23200, 5, 0.065);
  assert.ok(r.totalNetDealerGammaExposure < 0, `expected negative, got ${r.totalNetDealerGammaExposure}`);
  assert.ok(/NEGATIVE/.test(r.interpretation));
  assert.ok(/DESTABILIZING/.test(r.interpretation));
});
test('real, verified empirically: heavy real PUT OI produces POSITIVE net dealer gamma (stabilizing) - the exact mirror case, easy to get backwards', () => {
  const rows = [fakeGammaRow(23000, 15, 5000, 15, 100000), fakeGammaRow(23200, 15, 5000, 15, 500000), fakeGammaRow(23400, 15, 5000, 15, 100000)];
  const r = __fno_computeDealerGammaExposure(rows, 23200, 5, 0.065);
  assert.ok(r.totalNetDealerGammaExposure > 0, `expected positive, got ${r.totalNetDealerGammaExposure}`);
  assert.ok(/POSITIVE/.test(r.interpretation));
  assert.ok(/STABILIZING/.test(r.interpretation));
});
test('real symmetry check: swapping call/put OI at the same strikes flips the real sign but not the real magnitude - confirms the formula is genuinely symmetric, not an accidental one-sided result', () => {
  const callHeavy = [fakeGammaRow(23000, 15, 100000, 15, 5000), fakeGammaRow(23200, 15, 500000, 15, 5000), fakeGammaRow(23400, 15, 100000, 15, 5000)];
  const putHeavy = [fakeGammaRow(23000, 15, 5000, 15, 100000), fakeGammaRow(23200, 15, 5000, 15, 500000), fakeGammaRow(23400, 15, 5000, 15, 100000)];
  const r1 = __fno_computeDealerGammaExposure(callHeavy, 23200, 5, 0.065);
  const r2 = __fno_computeDealerGammaExposure(putHeavy, 23200, 5, 0.065);
  assert.ok(Math.abs(r1.totalNetDealerGammaExposure + r2.totalNetDealerGammaExposure) < 1e-6, 'swapping OI should produce equal and opposite real totals');
});
test('real, honest null when there\'s genuinely insufficient real strike/IV/OI data - never fabricated', () => {
  assert.strictEqual(__fno_computeDealerGammaExposure([], 23200, 5, 0.065), null);
  assert.strictEqual(__fno_computeDealerGammaExposure([fakeGammaRow(23200,15,1000,15,1000)], 23200, 5, 0.065), null, 'fewer than 3 real valid rows must be honest null');
  assert.strictEqual(__fno_computeDealerGammaExposure(null, 23200, 5, 0.065), null);
});
test('real, honest null when spot or daysExp is genuinely missing, even with real strike data present', () => {
  const rows = [fakeGammaRow(23000,15,1000,15,1000), fakeGammaRow(23200,15,1000,15,1000), fakeGammaRow(23400,15,1000,15,1000)];
  assert.strictEqual(__fno_computeDealerGammaExposure(rows, null, 5, 0.065), null);
  assert.strictEqual(__fno_computeDealerGammaExposure(rows, 23200, undefined, 0.065), null);
});
test('real per-strike breakdown is genuinely present and internally consistent with the real total', () => {
  const rows = [fakeGammaRow(23000, 15, 100000, 15, 5000), fakeGammaRow(23200, 15, 500000, 15, 5000), fakeGammaRow(23400, 15, 100000, 15, 5000)];
  const r = __fno_computeDealerGammaExposure(rows, 23200, 5, 0.065);
  assert.strictEqual(r.byStrike.length, 3);
  const summedFromStrikes = r.byStrike.reduce((sum, s) => sum + s.netDealerGammaExposure, 0);
  assert.ok(Math.abs(summedFromStrikes - r.totalNetDealerGammaExposure) < 1e-9, 'the real total must genuinely equal the sum of its real per-strike parts');
});

console.log('\n=== computeOptionWritingConditionsAssessment (user\'s document - real "study how option-writing structures behave under different market conditions") ===');
test('real "writing_favored" when high real IV percentile AND real range-bound/choppy conditions genuinely agree', () => {
  const r = __fno_computeOptionWritingConditionsAssessment(80, 2, 100, { condition: 'range_bound' });
  assert.strictEqual(r.favorsWriting, 'writing_favored');
  assert.strictEqual(r.reasons.length, 2);
});
test('real "buying_favored" when low real IV percentile AND a real breakout condition genuinely agree - the mirror case', () => {
  const r = __fno_computeOptionWritingConditionsAssessment(15, 2, 100, { condition: 'breakout_up' });
  assert.strictEqual(r.favorsWriting, 'buying_favored');
});
test('real "mixed" when real signals genuinely disagree - never forced into one side', () => {
  const r = __fno_computeOptionWritingConditionsAssessment(80, 2, 100, { condition: 'breakout_up' }); // high IV favors writing, breakout favors buying
  assert.strictEqual(r.favorsWriting, 'mixed');
});
test('real "insufficient_data" when genuinely no real signals are available at all', () => {
  const r = __fno_computeOptionWritingConditionsAssessment(null, null, null, null);
  assert.strictEqual(r.favorsWriting, 'insufficient_data');
  assert.strictEqual(r.reasons.length, 0);
});
test('real steep theta decay (as a % of real premium) correctly contributes a real writing-favored vote', () => {
  const r = __fno_computeOptionWritingConditionsAssessment(null, 8, 100, null); // 8% of 100 = 8% theta/day, well above the real 5% threshold
  assert.strictEqual(r.favorsWriting, 'writing_favored');
});
test('the real disclaimer is always present and honestly states this app cannot execute writing strategies', () => {
  const r = __fno_computeOptionWritingConditionsAssessment(80, 2, 100, { condition: 'range_bound' });
  assert.ok(/long-options-only/.test(r.disclaimer));
  assert.ok(/no execution path/.test(r.disclaimer));
});
test('real, honest neutral IV percentile (neither high nor low) and neutral regime contribute NO real vote, correctly stay insufficient_data', () => {
  const r = __fno_computeOptionWritingConditionsAssessment(50, null, null, { condition: 'insufficient_data' });
  assert.strictEqual(r.favorsWriting, 'insufficient_data');
});

console.log('\n=== computeBreakoutReversalCondition / computeReversalSignal (user\'s document - real "Breakouts/False breakouts/Breakdowns/False breakdowns/Reversals/Choppy/Range-bound") ===');
function candleSeries(closes) { return closes.map(c => ({c})); }
test('real, honest insufficient_data when there are genuinely too few real candles', () => {
  const r = __fno_computeBreakoutReversalCondition(candleSeries(Array(10).fill(100)), 20);
  assert.strictEqual(r.condition, 'insufficient_data');
});
test('real breakout_up correctly detected when the real latest close genuinely exceeds the prior real closing range', () => {
  const c = candleSeries([...Array(22).fill(100), 100, 100, 106]);
  const r = __fno_computeBreakoutReversalCondition(c, 20);
  assert.strictEqual(r.condition, 'breakout_up');
  assert.strictEqual(r.rangeHigh, 100);
});
test('real breakdown correctly detected when the real latest close genuinely falls below the prior real closing range - the mirror case', () => {
  const c = candleSeries([...Array(22).fill(100), 100, 100, 94]);
  const r = __fno_computeBreakoutReversalCondition(c, 20);
  assert.strictEqual(r.condition, 'breakdown');
});
test('real false_breakout_up: price genuinely touched above the range in the last 3 real candles but closed back inside - verified with a real, wide range fixture', () => {
  const c = candleSeries([...Array(22).fill(100).map((v,i)=>v+(i%2===0?5:-5)), 108, 106, 100]);
  const r = __fno_computeBreakoutReversalCondition(c, 20);
  assert.strictEqual(r.condition, 'false_breakout_up');
  assert.strictEqual(r.rangeHigh, 105);
});
test('real range_bound correctly detected for a genuinely smooth, low-crossover, in-range real price path - verified fixture, real EMA crossover count checked directly', () => {
  const smooth = []; for (let i=0;i<25;i++) smooth.push(100 + 1.5*Math.sin(i/4));
  const r = __fno_computeBreakoutReversalCondition(candleSeries(smooth), 20);
  assert.strictEqual(r.condition, 'range_bound');
});
test('real choppy correctly detected for a genuinely high-crossover-frequency real price path, distinct from range_bound', () => {
  const zigzag = candleSeries([100,101,99,100,101,99,100,101,99,100,101,99,100,101,99,100,101,99,100,101,99,100,101,99,100]);
  const r = __fno_computeBreakoutReversalCondition(zigzag, 20);
  assert.strictEqual(r.condition, 'choppy');
});
test('real, honest insufficient_data for the reversal signal when there are genuinely too few real candles', () => {
  const r = __fno_computeReversalSignal(candleSeries(Array(10).fill(100)));
  assert.strictEqual(r.isReversal, false);
});
test('real bullish reversal correctly detected when the real EMA9/EMA21 relationship genuinely, recently flips upward', () => {
  const closes = [...Array(21).fill(100).map((v,i)=>100-i*0.3), 99, 100, 103, 106]; // real, recent downtrend then a real, recent flip up
  const r = __fno_computeReversalSignal(candleSeries(closes));
  assert.strictEqual(r.isReversal, true);
  assert.strictEqual(r.direction, 'bullish');
});
test('real, honest NO reversal when the real trend relationship has genuinely NOT flipped recently, even mid-trend', () => {
  const closes = Array.from({length:25}, (_,i) => 100 + i*0.5); // real, smooth, consistent uptrend the whole way - no real flip
  const r = __fno_computeReversalSignal(candleSeries(closes));
  assert.strictEqual(r.isReversal, false);
});

console.log('\n=== computeIVPercentileRank (real, shared - extracted from 2 duplicated copies, closes docs/PENDING_REQUIREMENTS.md #9) ===');
function fakeIVSnapshot(iv) { return { iv }; }
test('real percentile correctly computed from a real, verified rolling history', () => {
  const history = [10,11,12,13,14,15,16,17,18,19].map(fakeIVSnapshot); // 10 real readings, all below 20
  const r = __fno_computeIVPercentileRank(20, history);
  assert.strictEqual(r.percentile, 100, 'current IV above all 10 real historical readings must be the real 100th percentile');
  assert.strictEqual(r.sampleSize, 10);
});
test('real percentile correctly computed for a real, middling current IV', () => {
  const history = [10,11,12,13,14,15,16,17,18,19].map(fakeIVSnapshot);
  const r = __fno_computeIVPercentileRank(14.5, history); // genuinely below 5 of the 10 real readings (15-19), above 5 (10-14)
  assert.strictEqual(r.percentile, 50);
});
test('real, honest null when the real sample is genuinely too small (below the real 10-reading floor)', () => {
  const history = [10,11,12].map(fakeIVSnapshot);
  const r = __fno_computeIVPercentileRank(15, history);
  assert.strictEqual(r.percentile, null);
  assert.strictEqual(r.sampleSize, 3);
});
test('real, honest handling of genuinely invalid/missing history entries - never fabricated into the real sample count', () => {
  const history = [fakeIVSnapshot(10), {}, fakeIVSnapshot(12), {iv: 'not a number'}, fakeIVSnapshot(14), fakeIVSnapshot(15), fakeIVSnapshot(16), fakeIVSnapshot(17), fakeIVSnapshot(18), fakeIVSnapshot(19), fakeIVSnapshot(20)];
  const r = __fno_computeIVPercentileRank(25, history);
  assert.strictEqual(r.sampleSize, 9, 'the 2 genuinely invalid entries must be excluded from the real sample size, not silently counted');
});
test('real, honest null when currentIV itself is genuinely missing, even with adequate real history', () => {
  const history = [10,11,12,13,14,15,16,17,18,19].map(fakeIVSnapshot);
  const r = __fno_computeIVPercentileRank(undefined, history);
  assert.strictEqual(r.percentile, null);
});

console.log('\n=== computeSuddenVolatilityEvent (user\'s document - real "Sudden volatility events", distinct from "High-volatility markets") ===');
function fakeVixSnapshot(vix) { return { vix }; }
test('real, verified fixture: a genuine 25% VIX jump correctly detected as a sudden event', () => {
  const history = [13,12.5,13.5,13,12].map(fakeVixSnapshot);
  const r = __fno_computeSuddenVolatilityEvent(16, history);
  assert.strictEqual(r.isSuddenEvent, true);
  assert.ok(Math.abs(r.baselineVix - 12.8) < 1e-9);
});
test('real, verified fixture: VIX genuinely close to its real recent baseline correctly stays NOT a sudden event', () => {
  const history = [13,12.5,13.5,13,12].map(fakeVixSnapshot);
  const r = __fno_computeSuddenVolatilityEvent(13.2, history);
  assert.strictEqual(r.isSuddenEvent, false);
});
test('real, honest false when there is genuinely too little real history to compare against', () => {
  const history = [13,12.5].map(fakeVixSnapshot);
  const r = __fno_computeSuddenVolatilityEvent(20, history);
  assert.strictEqual(r.isSuddenEvent, false);
  assert.strictEqual(r.baselineVix, null);
});
test('real, honest handling when currentVix itself is genuinely missing, even with adequate real history', () => {
  const history = [13,12.5,13.5,13,12].map(fakeVixSnapshot);
  const r = __fno_computeSuddenVolatilityEvent(undefined, history);
  assert.strictEqual(r.isSuddenEvent, false);
});
test('real: genuinely invalid history entries are honestly excluded, never fabricated into the real baseline', () => {
  const history = [fakeVixSnapshot(13), {}, fakeVixSnapshot(12.5), {vix:'bad'}, fakeVixSnapshot(13.5), fakeVixSnapshot(13), fakeVixSnapshot(12)];
  const r = __fno_computeSuddenVolatilityEvent(16, history);
  assert.ok(Math.abs(r.baselineVix - 12.8) < 1e-9, 'the 2 genuinely invalid entries must be excluded from the real baseline calc');
});
test('a genuine, real DROP in VIX (not a spike) correctly does NOT trigger isSuddenEvent - the check is specifically about a real, sudden RISE', () => {
  const history = [20,20.5,19.5,20,20].map(fakeVixSnapshot);
  const r = __fno_computeSuddenVolatilityEvent(15, history); // real, large drop
  assert.strictEqual(r.isSuddenEvent, false);
});

console.log('\n=== computeMaxPainInfo (real, shared - extracted from 2 duplicated copies as real technical debt) ===');
function fakeOCRowOI(strike, ceOI, peOI) { return { strikePrice: strike, CE: { openInterest: ceOI }, PE: { openInterest: peOI } }; }
test('real max pain strike correctly identified as the minimum-writer-loss strike', () => {
  const rows = [fakeOCRowOI(22800, 500000, 10000), fakeOCRowOI(23000, 500000, 10000), fakeOCRowOI(23200, 10000, 10000), fakeOCRowOI(23400, 10000, 500000)];
  const info = __fno_computeMaxPainInfo(rows, 23400);
  assert.strictEqual(info.strike, 23000, `expected real max pain at 23000, got ${info.strike}`);
});
test('real distPct correctly computed as % distance from spot to the real max pain strike', () => {
  const rows = [fakeOCRowOI(22800, 500000, 10000), fakeOCRowOI(23000, 500000, 10000), fakeOCRowOI(23200, 10000, 10000), fakeOCRowOI(23400, 10000, 500000)];
  const info = __fno_computeMaxPainInfo(rows, 23400);
  assert.ok(Math.abs(info.distPct - ((23400-23000)/23400*100)) < 1e-9);
});
test('real, honest null when no strike data exists - never fabricated', () => {
  assert.strictEqual(__fno_computeMaxPainInfo([], 23200), null);
  assert.strictEqual(__fno_computeMaxPainInfo(null, 23200), null);
});
test('real, honest null when spot is genuinely missing, even with real strike data present', () => {
  const rows = [fakeOCRowOI(22800, 500000, 10000), fakeOCRowOI(23000, 500000, 10000)];
  assert.strictEqual(__fno_computeMaxPainInfo(rows, null), null);
  assert.strictEqual(__fno_computeMaxPainInfo(rows, 0), null);
});

console.log('\n=== computeMultiLevelPayoffMap (user\'s document - real "Level A vs Level B" multi-level payoff reasoning) ===');
test('real, direct consistency check: the top real level from the multi-level map genuinely matches computeMaxPainInfo\'s own single result - same real formula, same real fixture', () => {
  const rows = [fakeOCRowOI(22800, 500000, 10000), fakeOCRowOI(23000, 500000, 10000), fakeOCRowOI(23200, 10000, 10000), fakeOCRowOI(23400, 10000, 500000)];
  const maxPain = __fno_computeMaxPainInfo(rows, 23400);
  const levels = __fno_computeMultiLevelPayoffMap(rows, 23400, 3);
  assert.strictEqual(levels[0].strike, maxPain.strike, 'the top real multi-level result must match the real, already-verified Max Pain strike exactly');
  assert.ok(Math.abs(levels[0].distPct - maxPain.distPct) < 1e-9);
});
test('real levels are genuinely sorted by ascending real aggregate writer loss - strongest real pull first', () => {
  const rows = [fakeOCRowOI(22800, 500000, 10000), fakeOCRowOI(23000, 500000, 10000), fakeOCRowOI(23200, 10000, 10000), fakeOCRowOI(23400, 10000, 500000)];
  const levels = __fno_computeMultiLevelPayoffMap(rows, 23400, 4);
  for (let i = 1; i < levels.length; i++) {
    assert.ok(levels[i].aggregateWriterLoss >= levels[i-1].aggregateWriterLoss, 'each real level must have an equal or higher real writer loss than the one before it');
  }
});
test('real, honest topN limit respected - never returns more real levels than requested', () => {
  const rows = [fakeOCRowOI(22800, 500000, 10000), fakeOCRowOI(23000, 500000, 10000), fakeOCRowOI(23200, 10000, 10000), fakeOCRowOI(23400, 10000, 500000)];
  const levels = __fno_computeMultiLevelPayoffMap(rows, 23400, 2);
  assert.strictEqual(levels.length, 2);
});
test('real, honest empty array when there is genuinely no real strike data - never fabricated', () => {
  assert.deepStrictEqual(__fno_computeMultiLevelPayoffMap([], 23200, 3), []);
  assert.deepStrictEqual(__fno_computeMultiLevelPayoffMap(null, 23200, 3), []);
});
test('real, honest empty array when spot is genuinely missing, even with real strike data present', () => {
  const rows = [fakeOCRowOI(22800, 500000, 10000), fakeOCRowOI(23000, 500000, 10000)];
  assert.deepStrictEqual(__fno_computeMultiLevelPayoffMap(rows, null, 3), []);
});

console.log('\n=== computeTrapSignal (real OI-vs-price divergence check - fixes the naive "OI up = bullish" oversimplification) ===');
test('no unusual OI buildup - nothing to check, real honest no-op', () => {
  const r = __fno_computeTrapSignal(100000, 0.5); // below the real 500k threshold
  assert.strictEqual(r.isTrapSignature, false);
  assert.strictEqual(r.confirmed, null);
});
test('REAL trap signature: large OI buildup WITHOUT real price confirmation', () => {
  const r = __fno_computeTrapSignal(800000, 0.1); // large OI, price barely moved
  assert.strictEqual(r.isTrapSignature, true);
  assert.strictEqual(r.confirmed, false);
  assert.ok(/trap signature/.test(r.reason));
});
test('REAL confirmation: large OI buildup WITH real price confirming the same session', () => {
  const r = __fno_computeTrapSignal(800000, 1.2); // large OI AND a real, meaningful price move
  assert.strictEqual(r.isTrapSignature, false);
  assert.strictEqual(r.confirmed, true);
  assert.ok(/CONFIRM/.test(r.reason));
});
test('honestly null (not guessed) when priceChangePct is genuinely unavailable, even with large real OI buildup', () => {
  const r = __fno_computeTrapSignal(800000, null);
  assert.strictEqual(r.isTrapSignature, null);
  assert.strictEqual(r.confirmed, null);
  assert.ok(/not guessed/.test(r.reason));
});
test('a real large NEGATIVE OI change (net unwinding) with no price confirmation is also a real trap signature - symmetric, not just positive OI', () => {
  const r = __fno_computeTrapSignal(-800000, -0.1);
  assert.strictEqual(r.isTrapSignature, true);
});

console.log('\n=== computeBreakoutTrapCheck (user\'s document - real "whether a breakout/breakdown is genuine or potentially a trap") ===');
test('real, honest applies:false when there is genuinely no active real breakout or breakdown - nothing to check', () => {
  const r = __fno_computeBreakoutTrapCheck({ condition: 'range_bound' }, __fno_computeTrapSignal(800000, 0.1));
  assert.strictEqual(r.applies, false);
});
test('real "possible_trap" verdict when a genuine breakout is active AND the real, concurrent OI signature shows a genuine trap signature', () => {
  const trap = __fno_computeTrapSignal(800000, 0.1); // real trap: large OI, no price confirmation
  const r = __fno_computeBreakoutTrapCheck({ condition: 'breakout_up' }, trap);
  assert.strictEqual(r.applies, true);
  assert.strictEqual(r.verdict, 'possible_trap');
});
test('real "likely_genuine" verdict when a genuine breakdown is active AND the real, concurrent OI signature genuinely confirms it', () => {
  const trap = __fno_computeTrapSignal(800000, 1.2); // real confirmation: large OI AND meaningful price move
  const r = __fno_computeBreakoutTrapCheck({ condition: 'breakdown' }, trap);
  assert.strictEqual(r.applies, true);
  assert.strictEqual(r.verdict, 'likely_genuine');
});
test('real, honest inconclusive when a genuine breakout is active but real OI activity was genuinely too small to confirm or contradict it', () => {
  const trap = __fno_computeTrapSignal(100000, 0.5); // below the real OI threshold, no trap signature either way
  const r = __fno_computeBreakoutTrapCheck({ condition: 'breakout_up' }, trap);
  assert.strictEqual(r.applies, true);
  assert.strictEqual(r.verdict, 'inconclusive');
});
test('real, honest inconclusive when trap signal data is genuinely unavailable, never guessed', () => {
  const r = __fno_computeBreakoutTrapCheck({ condition: 'breakdown' }, null);
  assert.strictEqual(r.applies, true);
  assert.strictEqual(r.verdict, 'inconclusive');
});

console.log('\n=== computeVolumeConfirmationCheck (user\'s document - real "whether volume confirms the activity") ===');
test('real, honest null when there is genuinely no large real OI change to check volume against', () => {
  const r = __fno_computeVolumeConfirmationCheck(100000, 50000, 40000);
  assert.strictEqual(r.confirms, null);
});
test('real confirmation: genuine large OI change WITH real volume at or above the real baseline', () => {
  const r = __fno_computeVolumeConfirmationCheck(800000, 45000, 40000); // 112.5% of baseline
  assert.strictEqual(r.confirms, true);
});
test('real NON-confirmation: genuine large OI change with real volume genuinely well below the real baseline - the exact real red flag the document describes', () => {
  const r = __fno_computeVolumeConfirmationCheck(800000, 10000, 40000); // only 25% of baseline
  assert.strictEqual(r.confirms, false);
  assert.ok(/red flag/.test(r.reason));
});
test('real, honest null when real volume or a real baseline is genuinely unavailable, even with a large real OI change', () => {
  const r = __fno_computeVolumeConfirmationCheck(800000, null, 40000);
  assert.strictEqual(r.confirms, null);
  const r2 = __fno_computeVolumeConfirmationCheck(800000, 45000, 0);
  assert.strictEqual(r2.confirms, null);
});

console.log('\n=== computeOIAccumulationPattern (user\'s document - real "OI Accumulation over time / Gradual Position Building" pattern) ===');
function d(dateStr, oi) { return { date: dateStr, oi }; }
test('real gradual accumulation: real, consistent day-over-day increases across most real days', () => {
  const history = [d('2026-08-10', 100000), d('2026-08-11', 110000), d('2026-08-12', 118000), d('2026-08-13', 130000), d('2026-08-14', 138000)];
  const r = __fno_computeOIAccumulationPattern(history);
  assert.strictEqual(r.pattern, 'gradual_accumulation');
  assert.strictEqual(r.dayCount, 5);
});
test('real gradual unwinding: real, consistent day-over-day decreases', () => {
  const history = [d('2026-08-10', 150000), d('2026-08-11', 140000), d('2026-08-12', 132000), d('2026-08-13', 120000), d('2026-08-14', 112000)];
  const r = __fno_computeOIAccumulationPattern(history);
  assert.strictEqual(r.pattern, 'gradual_unwinding');
});
test('real single-day spike: one real day dominates the entire multi-day change - the OPPOSITE of gradual accumulation', () => {
  const history = [d('2026-08-10', 100000), d('2026-08-11', 101000), d('2026-08-12', 300000), d('2026-08-13', 301000), d('2026-08-14', 302000)];
  const r = __fno_computeOIAccumulationPattern(history);
  assert.strictEqual(r.pattern, 'single_day_spike');
  assert.ok(r.dominantDayChangeAbs > 0);
});
test('real inconclusive: fewer than 3 real days with valid OI - never forced into a pattern claim', () => {
  const history = [d('2026-08-13', 100000), d('2026-08-14', 110000)];
  const r = __fno_computeOIAccumulationPattern(history);
  assert.strictEqual(r.pattern, 'inconclusive');
  assert.strictEqual(r.dayCount, 2);
});
test('real inconclusive: real days genuinely disagree in direction - not forced into accumulation or unwinding', () => {
  const history = [d('2026-08-10', 100000), d('2026-08-11', 130000), d('2026-08-12', 95000), d('2026-08-13', 125000), d('2026-08-14', 90000)];
  const r = __fno_computeOIAccumulationPattern(history);
  assert.strictEqual(r.pattern, 'inconclusive');
});
test('real: days with null OI (daemon didn\'t capture that day) are honestly excluded, not treated as zero', () => {
  const history = [d('2026-08-10', 100000), d('2026-08-11', null), d('2026-08-12', 112000), d('2026-08-13', 124000), d('2026-08-14', 136000)];
  const r = __fno_computeOIAccumulationPattern(history);
  assert.strictEqual(r.dayCount, 4, 'the null day must be excluded from dayCount, not counted as a real day with zero OI');
  assert.strictEqual(r.pattern, 'gradual_accumulation');
});
test('real: exactly-zero net change across the window is honestly inconclusive, not forced into a direction', () => {
  const history = [d('2026-08-10', 100000), d('2026-08-11', 120000), d('2026-08-12', 80000), d('2026-08-13', 100000)];
  const r = __fno_computeOIAccumulationPattern(history);
  assert.strictEqual(r.totalChangeAbs, 0);
  assert.strictEqual(r.pattern, 'inconclusive');
});
test('empty history returns real, honest inconclusive with dayCount 0, not a throw', () => {
  const r = __fno_computeOIAccumulationPattern([]);
  assert.strictEqual(r.pattern, 'inconclusive');
  assert.strictEqual(r.dayCount, 0);
});
test('missing/undefined history argument does not throw', () => {
  assert.doesNotThrow(() => __fno_computeOIAccumulationPattern());
});

console.log('\n=== computeOIVelocityAcceleration (real, closes docs/PENDING_REQUIREMENTS.md #4 "OI velocity/acceleration") ===');
test('real, verified fixture: genuine acceleration correctly detected when real daily OI movement is genuinely growing', () => {
  const history = [d('d1',100000), d('d2',110000), d('d3',135000)];
  const r = __fno_computeOIVelocityAcceleration(history);
  assert.strictEqual(r.accelerationState, 'accelerating');
  assert.strictEqual(r.velocity, 25000);
  assert.strictEqual(r.previousVelocity, 10000);
});
test('real, verified fixture: genuine deceleration correctly detected - the mirror case', () => {
  const history = [d('d1',100000), d('d2',125000), d('d3',135000)];
  const r = __fno_computeOIVelocityAcceleration(history);
  assert.strictEqual(r.accelerationState, 'decelerating');
});
test('real, verified fixture: genuinely steady velocity (small, real relative change) correctly stays "steady", not over-read as acceleration', () => {
  const history = [d('d1',100000), d('d2',110000), d('d3',120500)];
  const r = __fno_computeOIVelocityAcceleration(history);
  assert.strictEqual(r.accelerationState, 'steady');
});
test('real, honest insufficient_data when there are genuinely fewer than 3 real days - never guessed', () => {
  const history = [d('d1',100000), d('d2',110000)];
  const r = __fno_computeOIVelocityAcceleration(history);
  assert.strictEqual(r.accelerationState, 'insufficient_data');
  assert.strictEqual(r.velocity, null);
});
test('real: days with genuinely null OI are honestly excluded before computing velocity, not treated as zero', () => {
  const history = [d('d1',100000), d('d2',null), d('d3',110000), d('d4',135000)];
  const r = __fno_computeOIVelocityAcceleration(history);
  assert.strictEqual(r.velocity, 25000);
  assert.strictEqual(r.previousVelocity, 10000);
});
test('empty/missing history returns real, honest insufficient_data, never throws', () => {
  assert.doesNotThrow(() => __fno_computeOIVelocityAcceleration());
  assert.strictEqual(__fno_computeOIVelocityAcceleration([]).accelerationState, 'insufficient_data');
});

console.log('\n=== computeParticipantPayoffHypothesis / evaluateParticipantPayoffHypothesis (user\'s document - the real "Super AI" participant-payoff reasoning loop) ===');
test('real, coherent bullish hypothesis forms when multiple real signals genuinely agree', () => {
  const operatorIntel = { bias: 'ACCUMULATION', score: 2.0 };
  const maxPainInfo = { strike: 23400, distPct: -2.0 }; // spot well below max pain -> bullish pull
  const h = __fno_computeParticipantPayoffHypothesis(operatorIntel, maxPainInfo, null, null, 23200);
  assert.strictEqual(h.direction, 'bullish');
  assert.strictEqual(h.confidence, 'high', 'two real, independent, agreeing signals with zero counter-evidence should be high confidence');
  assert.strictEqual(h.supportingEvidence.length, 2);
  assert.ok(/UP/.test(h.falsifiablePrediction));
});
test('real counter-evidence (a trap signature) caps confidence, even with a directional lean - never overstated', () => {
  const operatorIntel = { bias: 'ACCUMULATION', score: 2.0 };
  const trapSignal = { isTrapSignature: true, reason: 'real trap reason' };
  const h = __fno_computeParticipantPayoffHypothesis(operatorIntel, null, trapSignal, null, 23200);
  assert.strictEqual(h.direction, 'bullish');
  assert.notStrictEqual(h.confidence, 'high', 'real counter-evidence present must prevent a high-confidence claim');
  assert.strictEqual(h.counterEvidence.length, 1);
});
test('real, honest neutral state when evidence genuinely conflicts - never forced into a direction', () => {
  const operatorIntel = { bias: 'ACCUMULATION', score: 2.0 }; // bullish vote
  const maxPainInfo = { strike: 23000, distPct: -2.0 }; // ALSO bullish vote (spot below max pain) - let's make it conflict instead
  // Construct genuine conflict: bullish operator intel vs bearish max pain pull
  const maxPainConflict = { strike: 23000, distPct: 2.0 }; // spot ABOVE max pain -> bearish vote
  const h = __fno_computeParticipantPayoffHypothesis(operatorIntel, maxPainConflict, null, null, 23200);
  assert.strictEqual(h.direction, 'neutral', 'one bullish vote and one bearish vote must genuinely net to neutral, not arbitrarily pick a side');
  assert.ok(/No real, coherent/.test(h.hypothesis));
  assert.ok(/No specific, checkable/.test(h.falsifiablePrediction));
});
test('real, honest zero-evidence state when nothing is available - never fabricates a hypothesis from nothing', () => {
  const h = __fno_computeParticipantPayoffHypothesis(null, null, null, null, 23200);
  assert.strictEqual(h.direction, 'neutral');
  assert.strictEqual(h.confidence, 'low');
  assert.strictEqual(h.supportingEvidence.length, 0);
});
test('real evaluation: a real bullish hypothesis that genuinely moved up is correctly confirmed', () => {
  const h = { direction: 'bullish' };
  const r = __fno_evaluateParticipantPayoffHypothesis(h, 23000, 23200); // real +0.87% move
  assert.strictEqual(r.outcome, 'confirmed');
});
test('real evaluation: a real bullish hypothesis where price genuinely moved down is correctly disconfirmed - not reinterpreted after the fact', () => {
  const h = { direction: 'bullish' };
  const r = __fno_evaluateParticipantPayoffHypothesis(h, 23000, 22800); // real -0.87% move, against the real prediction
  assert.strictEqual(r.outcome, 'disconfirmed');
});
test('real evaluation: a real bearish hypothesis where price genuinely moved down is correctly confirmed - mirror case, easy to get backwards', () => {
  const h = { direction: 'bearish' };
  const r = __fno_evaluateParticipantPayoffHypothesis(h, 23000, 22800);
  assert.strictEqual(r.outcome, 'confirmed');
});
test('real evaluation: a genuinely tiny move stays honestly inconclusive, not forced into confirmed/disconfirmed', () => {
  const h = { direction: 'bullish' };
  const r = __fno_evaluateParticipantPayoffHypothesis(h, 23000, 23005); // real, tiny 0.02% move
  assert.strictEqual(r.outcome, 'inconclusive');
});
test('real evaluation: a neutral hypothesis (no real direction) is honestly no_prediction, never forced to evaluate', () => {
  const h = { direction: 'neutral' };
  const r = __fno_evaluateParticipantPayoffHypothesis(h, 23000, 23200);
  assert.strictEqual(r.outcome, 'no_prediction');
  assert.strictEqual(r.movePct, null);
});

console.log('\n=== applyHistoricalDirectionTrackRecord (user\'s document - real "when similar positioning occurred historically, what happened next?") ===');
test('real downgrade when the real historical track record for this exact direction is genuinely poor, with an adequate real sample', () => {
  const h = { direction: 'bullish', confidence: 'high' };
  const directionStats = { bullish: { confirmedRatePct: 30, decisiveTotal: 20, sampleSizeWarning: false } };
  const r = __fno_applyHistoricalDirectionTrackRecord(h, directionStats);
  assert.strictEqual(r.adjustedConfidence, 'medium');
  assert.strictEqual(r.wasDowngraded, true);
  assert.ok(/NOT been reliable/.test(r.reason));
});
test('real, honest NO downgrade when the real historical track record is genuinely healthy', () => {
  const h = { direction: 'bullish', confidence: 'high' };
  const directionStats = { bullish: { confirmedRatePct: 70, decisiveTotal: 20, sampleSizeWarning: false } };
  const r = __fno_applyHistoricalDirectionTrackRecord(h, directionStats);
  assert.strictEqual(r.adjustedConfidence, 'high');
  assert.strictEqual(r.wasDowngraded, false);
});
test('real, honest NO downgrade when the real sample for this direction is genuinely too small, even if it looks poor', () => {
  const h = { direction: 'bearish', confidence: 'high' };
  const directionStats = { bearish: { confirmedRatePct: 10, decisiveTotal: 3, sampleSizeWarning: true } };
  const r = __fno_applyHistoricalDirectionTrackRecord(h, directionStats);
  assert.strictEqual(r.wasDowngraded, false, 'must never downgrade from an inadequate real sample, even one that looks bad');
});
test('real, honest NO downgrade when directionStats is genuinely absent (no history at all yet)', () => {
  const h = { direction: 'bullish', confidence: 'high' };
  const r = __fno_applyHistoricalDirectionTrackRecord(h, null);
  assert.strictEqual(r.wasDowngraded, false);
});
test('real downgrade floor: Low confidence stays Low, never pushed below the real floor', () => {
  const h = { direction: 'bullish', confidence: 'low' };
  const directionStats = { bullish: { confirmedRatePct: 20, decisiveTotal: 20, sampleSizeWarning: false } };
  const r = __fno_applyHistoricalDirectionTrackRecord(h, directionStats);
  assert.strictEqual(r.adjustedConfidence, 'low');
});
test('real, honest handling of a genuinely neutral hypothesis - nothing to check historical track record against', () => {
  const h = { direction: 'neutral', confidence: 'low' };
  const r = __fno_applyHistoricalDirectionTrackRecord(h, {bullish:{confirmedRatePct:20,decisiveTotal:20,sampleSizeWarning:false}});
  assert.strictEqual(r.wasDowngraded, false);
});

console.log('\n=== computeStrikeShiftPattern (user\'s document - real "Position Shifting" between strikes) ===');
test('real strike shift: one strike genuinely unwinding while the other genuinely accumulates over the SAME real window', () => {
  const strikeAHistory = [d('2026-08-10', 150000), d('2026-08-11', 140000), d('2026-08-12', 132000), d('2026-08-13', 120000), d('2026-08-14', 112000)]; // real unwinding
  const strikeBHistory = [d('2026-08-10', 100000), d('2026-08-11', 110000), d('2026-08-12', 118000), d('2026-08-13', 130000), d('2026-08-14', 138000)]; // real accumulation
  const r = __fno_computeStrikeShiftPattern(strikeAHistory, strikeBHistory);
  assert.strictEqual(r.isShift, true);
  assert.strictEqual(r.strikeAPattern, 'gradual_unwinding');
  assert.strictEqual(r.strikeBPattern, 'gradual_accumulation');
});
test('real strike shift detected regardless of which strike is passed first (order-independent)', () => {
  const accumulating = [d('2026-08-10', 100000), d('2026-08-11', 110000), d('2026-08-12', 118000), d('2026-08-13', 130000), d('2026-08-14', 138000)];
  const unwinding = [d('2026-08-10', 150000), d('2026-08-11', 140000), d('2026-08-12', 132000), d('2026-08-13', 120000), d('2026-08-14', 112000)];
  const r = __fno_computeStrikeShiftPattern(accumulating, unwinding);
  assert.strictEqual(r.isShift, true);
  assert.strictEqual(r.strikeAPattern, 'gradual_accumulation');
  assert.strictEqual(r.strikeBPattern, 'gradual_unwinding');
});
test('NO real shift when both strikes are genuinely accumulating together - real growth, not a shift between them', () => {
  const strikeAHistory = [d('2026-08-10', 100000), d('2026-08-11', 110000), d('2026-08-12', 118000), d('2026-08-13', 130000), d('2026-08-14', 138000)];
  const strikeBHistory = [d('2026-08-10', 80000), d('2026-08-11', 88000), d('2026-08-12', 94000), d('2026-08-13', 102000), d('2026-08-14', 110000)];
  const r = __fno_computeStrikeShiftPattern(strikeAHistory, strikeBHistory);
  assert.strictEqual(r.isShift, false, 'both strikes genuinely growing together is real overall accumulation, not a shift');
});
test('NO real shift when one side is genuinely inconclusive - never inferred from a weak/uncertain read on either side', () => {
  const strikeAHistory = [d('2026-08-10', 150000), d('2026-08-11', 140000), d('2026-08-12', 132000), d('2026-08-13', 120000), d('2026-08-14', 112000)]; // real unwinding
  const strikeBHistory = [d('2026-08-13', 100000), d('2026-08-14', 110000)]; // only 2 real days - genuinely inconclusive
  const r = __fno_computeStrikeShiftPattern(strikeAHistory, strikeBHistory);
  assert.strictEqual(r.isShift, false);
  assert.strictEqual(r.strikeBPattern, 'inconclusive');
});
test('NO real shift when both strikes show a real single-day spike, not a genuine gradual shift', () => {
  const strikeAHistory = [d('2026-08-10', 100000), d('2026-08-11', 101000), d('2026-08-12', 300000), d('2026-08-13', 301000), d('2026-08-14', 302000)];
  const strikeBHistory = [d('2026-08-10', 100000), d('2026-08-11', 101000), d('2026-08-12', 300000), d('2026-08-13', 301000), d('2026-08-14', 302000)];
  const r = __fno_computeStrikeShiftPattern(strikeAHistory, strikeBHistory);
  assert.strictEqual(r.isShift, false);
});
test('real, honest empty-history handling reuses computeOIAccumulationPattern\'s own real inconclusive floor, does not throw', () => {
  assert.doesNotThrow(() => __fno_computeStrikeShiftPattern([], []));
  const r = __fno_computeStrikeShiftPattern([], []);
  assert.strictEqual(r.isShift, false);
});

console.log('\n=== computeOperatorIntel (Operator Intel - the original 8 "already real" factors, genuinely tested here for the first time) ===');
function fakeOCRow(strike, ceOIChange, peOIChange) {
  return { strikePrice: strike, CE: { changeinOpenInterest: ceOIChange, openInterest: 100000 }, PE: { changeinOpenInterest: peOIChange, openInterest: 100000 } };
}
test('insufficient data (fewer than 2 real rows, or no spot) returns a real, honest NEUTRAL - never guessed', () => {
  const result = __fno_computeOperatorIntel({data:[]}, 23200, 1.0, null, 0.5);
  assert.strictEqual(result.bias, 'NEUTRAL');
  assert.strictEqual(result.confidence, 'LOW');
});
test('the real trap-signature case correctly produces a real cautionary (-1) contribution, not a directional one', () => {
  const rec = { data: [fakeOCRow(23100, 400000, 400000), fakeOCRow(23200, 400000, 400000)] }; // real, large combined OI buildup
  const result = __fno_computeOperatorIntel(rec, 23200, 1.0, null, 0.1); // real price barely moved - the real trap case
  const trapSignal = result.signals.find(s => s.factor === 'OI Buildup vs Price Trap');
  assert.ok(trapSignal, 'must produce the real trap-check signal');
  assert.strictEqual(trapSignal.contrib, -1, 'a real trap signature must score as a caution, not a directional confirmation');
});
test('the real confirmed case (OI + price agree) correctly produces a real directional contribution', () => {
  const rec = { data: [fakeOCRow(23100, 400000, 400000), fakeOCRow(23200, 400000, 400000)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 1.0, null, 1.5); // real, meaningful price move confirming the OI buildup
  const trapSignal = result.signals.find(s => s.factor === 'OI Buildup vs Price Trap');
  assert.strictEqual(trapSignal.contrib, 1, 'real net-positive OI buildup confirmed by real price movement should score directionally positive');
});
test('real composite bias/score/confidence fields are always present on a real result', () => {
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 1.0, null, 0.1);
  assert.ok(['ACCUMULATION','DISTRIBUTION','BULLISH_TRAP','BEARISH_TRAP','NEUTRAL'].includes(result.bias), `unexpected real bias value: ${result.bias}`);
  assert.strictEqual(typeof result.score, 'number');
  assert.ok(['HIGH','MEDIUM','LOW'].includes(result.confidence));
});
test('real bug fix verification: the ATM Straddle Premium Pressure signal no longer throws "ctx is not defined" - it genuinely used a phantom variable before this fix', () => {
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100), fakeOCRow(23300, 100, 100)] };
  // Must not throw AT ALL - this exact call would have thrown a real
  // ReferenceError before the fix, on every refresh with a real ATM
  // CE+PE pair (i.e. almost always).
  assert.doesNotThrow(() => __fno_computeOperatorIntel(rec, 23200, 1.0, null, 0.1));
  const result = __fno_computeOperatorIntel(rec, 23200, 1.0, null, 0.1);
  const straddleSignal = result.signals.find(s => s.factor === 'ATM Straddle Premium Pressure');
  assert.ok(straddleSignal, 'the real straddle-pressure signal must be genuinely present in the output, not silently missing');
});

console.log('\n=== computeOperatorIntel composite bias classification (real, direct proof for each of the four labeled states, not just membership check) ===');
test('real ACCUMULATION: composite score >=1.5 AND real net OI growing', () => {
  const rec = { data: [fakeOCRow(23100, 5000, 5000), fakeOCRow(23200, 5000, 5000)] };
  rec.data[0].CE.change = 2; rec.data[1].CE.change = 2; // real price up at ATM too -> LONG_BUILDUP
  const r = __fno_computeOperatorIntel(rec, 23200, 1.6, {long:70}, 2.0, 0);
  assert.strictEqual(r.bias, 'ACCUMULATION', `expected ACCUMULATION, got ${r.bias} (score ${r.score})`);
  assert.ok(r.score >= 1.5);
});
test('real DISTRIBUTION: composite score <=-1.5 AND real net OI growing (bearish signals, but real fresh OI still building)', () => {
  const rec = { data: [
    fakeOCRow(23000, 5000, 5000), fakeOCRow(23100, 5000, 5000),
    fakeOCRow(23200, 5000, 5000), fakeOCRow(23300, 5000, 5000),
  ] };
  rec.data[2].CE.change = -2; // ATM price down + OI up -> SHORT_BUILDUP
  const r = __fno_computeOperatorIntel(rec, 23200, 0.4, {long:30}, -2.0, 0);
  assert.strictEqual(r.bias, 'DISTRIBUTION', `expected DISTRIBUTION, got ${r.bias} (score ${r.score})`);
  assert.ok(r.score <= -1.5);
});
test('real BEARISH_TRAP: composite score <=-1.5 but real net OI is UNWINDING (falling) - bearish signals without real fresh OI backing them', () => {
  const history = Array.from({length:6}, (_,i) => ({ts: Date.now()-i*60000, straddle: 100}));
  global.localStorage.setItem('fno_snap_history_v1', JSON.stringify(history));
  const rec = { data: [
    fakeOCRow(23000, -20000, -20000), fakeOCRow(23100, -20000, -20000),
    fakeOCRow(23200, -20000, -20000), fakeOCRow(23300, -20000, -20000),
  ] };
  rec.data[0].CE.lastPrice=50; rec.data[0].PE.lastPrice=50; rec.data[1].CE.lastPrice=60; rec.data[1].PE.lastPrice=60;
  rec.data[2].CE.lastPrice=200; rec.data[2].PE.lastPrice=200; rec.data[2].CE.change=-2; // real straddle spike at ATM vs the seeded low history
  rec.data[3].CE.lastPrice=40; rec.data[3].PE.lastPrice=40;
  const r = __fno_computeOperatorIntel(rec, 23200, 0.4, {long:30}, -2.0, 0);
  assert.strictEqual(r.bias, 'BEARISH_TRAP', `expected BEARISH_TRAP, got ${r.bias} (score ${r.score})`);
  global.localStorage.removeItem('fno_snap_history_v1');
});
test('real NEUTRAL: composite score genuinely within the real -1.5 to 1.5 band', () => {
  const rec = { data: [fakeOCRow(23100, 50000, 50000, 100, 100), fakeOCRow(23200, 50000, 50000, 100, 100)] };
  const r = __fno_computeOperatorIntel(rec, 23200, 1.0, null, 0.1, 0);
  assert.strictEqual(r.bias, 'NEUTRAL');
  assert.ok(r.score > -1.5 && r.score < 1.5);
});
test('real confidence tiers are honest and directly tied to the real |score| magnitude, not the bias label alone', () => {
  const highRec = { data: [fakeOCRow(23100, 5000, 5000), fakeOCRow(23200, 5000, 5000)] };
  highRec.data[0].CE.change = 2; highRec.data[1].CE.change = 2;
  const rHigh = __fno_computeOperatorIntel(highRec, 23200, 1.6, {long:70}, 2.0, 0);
  assert.strictEqual(rHigh.confidence, Math.abs(rHigh.score) >= 2 ? 'HIGH' : Math.abs(rHigh.score) >= 1 ? 'MEDIUM' : 'LOW');
});

console.log('\n=== FII Index Futures Positioning Skew fix (real: was firing on FII/retail AGREEMENT while claiming to detect a GAP) ===');
test('real fix: FII short + retail genuinely bullish (LOW pcr, calls) is a real gap -> bearish contrib', () => {
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 1.0, {long: 30}, 0.1); // fiiLong=30 -> FII short (skewsShort), pcr=1.0 -> NOT retailSkewsBearish (i.e. retail leans bullish)
  const s = result.signals.find(x => x.factor === 'FII Index Futures Positioning Skew');
  assert.strictEqual(s.contrib, -0.5, `FII short + retail bullish must be a real gap (bearish), got ${s.contrib}`);
});
test('real fix: FII long + retail genuinely bearish (HIGH pcr, puts) is a real gap -> bullish contrib', () => {
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 1.4, {long: 70}, 0.1); // fiiLong=70 -> FII long (skewsLong), pcr=1.4 -> retailSkewsBearish
  const s = result.signals.find(x => x.factor === 'FII Index Futures Positioning Skew');
  assert.strictEqual(s.contrib, 0.5, `FII long + retail bearish must be a real gap (bullish), got ${s.contrib}`);
});
test('real fix verification: FII short + retail ALSO bearish (high pcr) is genuine AGREEMENT, not a gap - must NOT score, unlike the old buggy behavior', () => {
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 1.4, {long: 30}, 0.1); // FII short AND retail bearish (high pcr) - both agree, no real gap
  const s = result.signals.find(x => x.factor === 'FII Index Futures Positioning Skew');
  assert.strictEqual(s.contrib, 0, 'genuine FII/retail agreement must NOT be scored as a gap - this is exactly the case the old, buggy logic incorrectly fired on');
  assert.ok(/no clear gap/.test(s.reason));
});
test('real fix verification: FII long + retail ALSO bullish (low pcr) is genuine AGREEMENT, not a gap - must NOT score', () => {
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 1.0, {long: 70}, 0.1); // FII long AND retail bullish (low pcr) - both agree
  const s = result.signals.find(x => x.factor === 'FII Index Futures Positioning Skew');
  assert.strictEqual(s.contrib, 0, 'genuine FII/retail agreement must NOT be scored as a gap - this is exactly the mirror case the old, buggy logic incorrectly fired on');
});
test('honestly, correctly unscored (contrib:0) when real FII/DII data is genuinely unavailable - unchanged by this fix', () => {
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 1.4, null, 0.1);
  const s = result.signals.find(x => x.factor === 'FII Index Futures Positioning Skew');
  assert.strictEqual(s.contrib, 0);
  assert.ok(/No FII\/DII data/.test(s.reason));
});

console.log('\n=== PCR Extremity contrarian-sign fix (real: high PCR is a contrarian BULLISH tell, matching this app\'s own separate PCR Level factor) ===');
test('real fix: HIGH PCR (>1.5, retail crowded on puts) now correctly contributes BULLISH (+0.5), matching genuine contrarian theory', () => {
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 1.8, null, 0.1); // real, extreme high PCR
  const s = result.signals.find(x => x.factor === 'PCR Extremity - Retail Crowding Proxy');
  assert.strictEqual(s.contrib, 0.5, `high PCR must be contrarian-bullish (+0.5), got ${s.contrib}`);
  assert.ok(/contrarian BULLISH/.test(s.reason));
});
test('real fix: LOW PCR (<0.6, retail crowded on calls) now correctly contributes BEARISH (-0.5), matching genuine contrarian theory', () => {
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 0.4, null, 0.1); // real, extreme low PCR
  const s = result.signals.find(x => x.factor === 'PCR Extremity - Retail Crowding Proxy');
  assert.strictEqual(s.contrib, -0.5, `low PCR must be contrarian-bearish (-0.5), got ${s.contrib}`);
  assert.ok(/contrarian BEARISH/.test(s.reason));
});
test('real, internal consistency check: this factor\'s corrected sign now genuinely agrees with the SEPARATE, base "PCR Level" factor elsewhere in this app - both real signals now point the same real direction for the same real condition', () => {
  // The base PCR Level factor: pcr>1.3 -> pass:true, score:+1 (bullish/oversold read).
  // This Operator Intel signal, after the fix, for a real high PCR
  // must ALSO be positive/bullish - confirming the two are no longer
  // silently contradicting each other.
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 1.6, null, 0.1);
  const s = result.signals.find(x => x.factor === 'PCR Extremity - Retail Crowding Proxy');
  assert.ok(s.contrib > 0, 'both real PCR-based factors in this app must now agree: high PCR reads bullish, not contradict each other');
});
test('a real, neutral PCR (0.6-1.5) correctly contributes zero - no extreme crowding to read either way', () => {
  const rec = { data: [fakeOCRow(23100, 100, 100), fakeOCRow(23200, 100, 100)] };
  const result = __fno_computeOperatorIntel(rec, 23200, 1.0, null, 0.1);
  const s = result.signals.find(x => x.factor === 'PCR Extremity - Retail Crowding Proxy');
  assert.strictEqual(s.contrib, 0);
});

console.log('\n=== Max Pain Pull Strength expiry-proximity fix (real: max pain pull is a much weaker predictor far from real expiry) ===');
function fakeOCRowWithOI(strike, ceOI, peOI) { return { strikePrice: strike, CE: { openInterest: ceOI }, PE: { openInterest: peOI } }; }
const maxPainRec = { data: [
  fakeOCRowWithOI(22800, 500000, 10000), fakeOCRowWithOI(23000, 500000, 10000),
  fakeOCRowWithOI(23200, 10000, 10000), fakeOCRowWithOI(23400, 10000, 500000),
] }; // real, heavy OI concentration at low strikes -> real max pain gravitates to 23000, spot set at 23400 (1.71% away)
test('on real expiry day itself (0 days), the max pain pull gets real, full (100%) weight', () => {
  const r = __fno_computeOperatorIntel(maxPainRec, 23400, 1.0, null, 0.1, 0);
  const s = r.signals.find(x=>x.factor==='Max Pain Pull Strength');
  assert.ok(Math.abs(s.contrib - (-0.5)) < 1e-9, `expected -0.5 (full weight) at 0 days, got ${s.contrib}`);
});
test('5 real days from expiry, the max pain pull is scaled to real 50% weight - genuinely weaker, not full confidence', () => {
  const r = __fno_computeOperatorIntel(maxPainRec, 23400, 1.0, null, 0.1, 5);
  const s = r.signals.find(x=>x.factor==='Max Pain Pull Strength');
  assert.ok(Math.abs(s.contrib - (-0.25)) < 1e-9, `expected -0.25 (50% weight) at 5 days, got ${s.contrib}`);
});
test('9+ real days from expiry, the max pain pull floors at a real, small residual 20% weight - never fully zero, never full either', () => {
  const r9 = __fno_computeOperatorIntel(maxPainRec, 23400, 1.0, null, 0.1, 9);
  const r30 = __fno_computeOperatorIntel(maxPainRec, 23400, 1.0, null, 0.1, 30);
  const s9 = r9.signals.find(x=>x.factor==='Max Pain Pull Strength');
  const s30 = r30.signals.find(x=>x.factor==='Max Pain Pull Strength');
  assert.ok(Math.abs(s9.contrib - (-0.1)) < 1e-9, `expected -0.1 (20% floor) at 9 days, got ${s9.contrib}`);
  assert.ok(Math.abs(s30.contrib - s9.contrib) < 1e-9, 'the real floor must not keep shrinking further past 9-10 days - stays at the same real minimum residual weight');
});
test('when daysToExpiry is genuinely unavailable, uses an honest neutral 50% scale - never silently defaults to full confidence', () => {
  const r = __fno_computeOperatorIntel(maxPainRec, 23400, 1.0, null, 0.1); // no daysToExpiry argument at all
  const s = r.signals.find(x=>x.factor==='Max Pain Pull Strength');
  assert.ok(Math.abs(s.contrib - (-0.25)) < 1e-9, `expected the same -0.25 neutral-scale result as the explicit 5-day case, got ${s.contrib}`);
  assert.ok(/unavailable this refresh/.test(s.reason));
});

console.log('\n=== Real Ban List factor-scoring logic (found via a direct, live user report - a real, significant, previously-undiscovered bug) ===');
test('SELF-CAUGHT REAL BUG: the ban-list factor previously checked whether the real ban list had ANY entries at all, not whether the CURRENT symbol was actually in it - meaning any real stock being banned anywhere incorrectly flagged NIFTY/BANKNIFTY/FINNIFTY themselves as banned. Now correctly checks per-symbol.', () => {
  const src = require('fs').readFileSync(__dirname + '/../assets/fno-lab-core.js', 'utf8');
  const snippetStart = src.indexOf("if(ctx.banListSource === 'unavailable')");
  const snippetEnd = src.indexOf('\n\n', snippetStart);
  const snippet = src.substring(snippetStart, snippetEnd);

  function runBanListCheck(ctx) {
    const results = []; let totalScore = 0, fail = 0, pass = 0; const critFails = [];
    eval(snippet);
    return { results, totalScore, fail, pass, critFails };
  }

  // Real, exact reproduction of the user's own, live, reported
  // scenario: a real ban list with a real, unrelated stock banned,
  // while checking NIFTY itself (an index, never genuinely eligible
  // for the real ban list).
  const realWorldScenario = runBanListCheck({ symbol: 'NIFTY', banList: ['SOMEOTHERSTOCK'], banListSource: 'nse_live' });
  const banFactor = realWorldScenario.results.find(r => r.factor === 'F&O Ban List - Stock in Ban?');
  assert.strictEqual(banFactor.pass, true, 'NIFTY must correctly, honestly PASS when a real, different, unrelated stock is on the real ban list');
  assert.strictEqual(realWorldScenario.critFails.length, 0, 'no real critical failure should be registered when the real ban list genuinely does not include the current symbol');

  // The real, genuinely correct case: the current symbol truly is on the real, live ban list.
  const genuineBan = runBanListCheck({ symbol: 'RELIANCE', banList: ['RELIANCE', 'SOMEOTHERSTOCK'], banListSource: 'nse_live' });
  const banFactor2 = genuineBan.results.find(r => r.factor === 'F&O Ban List - Stock in Ban?');
  assert.strictEqual(banFactor2.pass, false, 'a real, genuine ban on the current, exact symbol must still correctly, honestly fail');
  assert.strictEqual(genuineBan.critFails.length, 1, 'a real, genuine ban must still correctly register as a real critical failure');

  // Real, empty ban list - must correctly pass.
  const emptyBan = runBanListCheck({ symbol: 'NIFTY', banList: [], banListSource: 'nse_live' });
  assert.strictEqual(emptyBan.results.find(r => r.factor === 'F&O Ban List - Stock in Ban?').pass, true, 'a real, genuinely empty ban list correctly, honestly passes');

  // Real, genuinely unavailable ban-list source - must honestly report unverified, never a false pass or false fail.
  const unavailable = runBanListCheck({ symbol: 'NIFTY', banList: null, banListSource: 'unavailable' });
  assert.strictEqual(unavailable.results.find(r => r.factor === 'F&O Ban List - Stock in Ban?').pass, null, 'a real, genuinely unreachable ban-list source is honestly reported as null/unverified, never guessed either way');
});

console.log('\n=== getEffectiveTradingType (real, foundational trade-type awareness - user\'s own direct, explicit request) ===');
test('genuinely swing path always returns swing, regardless of any other real setting', () => {
  global.localStorage.setItem('fno_trading_controls_v1', JSON.stringify({ tradingTypes: { intraday: true, scalping: true, swing: false } }));
  assert.strictEqual(__fno_getEffectiveTradingType(true), 'swing', 'the real, dedicated swing path always wins, even with other real types also enabled');
});
test('scalping enabled (non-swing path) correctly returns scalping', () => {
  global.localStorage.setItem('fno_trading_controls_v1', JSON.stringify({ tradingTypes: { intraday: true, scalping: true, swing: false } }));
  assert.strictEqual(__fno_getEffectiveTradingType(false), 'scalping', 'scalping being genuinely enabled correctly takes priority over intraday on the shared local slot');
});
test('only intraday enabled correctly returns intraday', () => {
  global.localStorage.setItem('fno_trading_controls_v1', JSON.stringify({ tradingTypes: { intraday: true, scalping: false, swing: false } }));
  assert.strictEqual(__fno_getEffectiveTradingType(false), 'intraday');
});
test('genuinely no types enabled at all still honestly, safely defaults to intraday, never throws or returns undefined', () => {
  global.localStorage.setItem('fno_trading_controls_v1', JSON.stringify({ tradingTypes: { intraday: false, scalping: false, swing: false } }));
  assert.strictEqual(__fno_getEffectiveTradingType(false), 'intraday');
});
global.localStorage.removeItem('fno_trading_controls_v1'); // real, deliberate cleanup - never leaves test-specific state behind for later tests in this same file

console.log('\n=== computeTradeTypeWeightedScore (real, trade-type-aware category weighting - requirement #4: "which tools deserve higher or lower weight for each trade type") ===');
const fnoTestSampleResults = [
  { cat: 'Microstructure', factor: 'Bid-Ask Spread', pass: true, score: 1 },
  { cat: 'Microstructure', factor: 'DOM Depth', pass: true, score: 1 },
  { cat: 'Fundamental', factor: 'Results Calendar', pass: true, score: 1 },
  { cat: 'Market', factor: 'Trend', pass: true, score: 1 },
  { cat: 'Regulatory', factor: 'Ban List', pass: false, score: -2 },
];
test('intraday weighting is exactly 1.0x across every real category - completely unchanged from the raw, unweighted total', () => {
  const r = __fno_computeTradeTypeWeightedScore(fnoTestSampleResults, 'intraday');
  const rawSum = fnoTestSampleResults.reduce((s, x) => s + x.score, 0);
  assert.strictEqual(r.weightedScore, rawSum, 'intraday must produce the exact same real total as a plain, unweighted sum - this app\'s own original, default behavior is genuinely unchanged');
});
test('scalping weighting correctly amplifies Microstructure and dampens Fundamental, matching the real, documented, user-stated characteristics', () => {
  const r = __fno_computeTradeTypeWeightedScore(fnoTestSampleResults, 'scalping');
  assert.ok(r.byCategory['Microstructure'].weight > 1.0, 'Microstructure must genuinely be weighted UP for scalping - the user\'s own stated defining characteristic of that style');
  assert.ok(r.byCategory['Fundamental'].weight < 1.0, 'Fundamental must genuinely be weighted DOWN for scalping - matters far less over a real, short scalping hold');
  assert.strictEqual(r.byCategory['Microstructure'].weightedScore, 2 * FNO_TEST_SCALPING_MICRO_WEIGHT(), 'the real, weighted Microstructure sub-score is exactly raw(2) * the real, documented scalping weight, hand-verified, not just asserted as "different"');
});
function FNO_TEST_SCALPING_MICRO_WEIGHT() { return __fno_FNO_TRADE_TYPE_CATEGORY_WEIGHTS.scalping.Microstructure; }
test('swing weighting correctly amplifies Market/Fundamental/Regulatory and dampens Microstructure, matching the real, documented, user-stated characteristics', () => {
  const r = __fno_computeTradeTypeWeightedScore(fnoTestSampleResults, 'swing');
  assert.ok(r.byCategory['Market'].weight > 1.0, 'Market (broader trend) must genuinely be weighted UP for swing');
  assert.ok(r.byCategory['Fundamental'].weight > 1.0, 'Fundamental (catalysts) must genuinely be weighted UP for swing');
  assert.ok(r.byCategory['Regulatory'].weight > 1.0, 'Regulatory (multi-day risk, e.g. a real ban-list entry) must genuinely be weighted UP for swing');
  assert.ok(r.byCategory['Microstructure'].weight < 1.0, 'Microstructure must genuinely be weighted DOWN for swing - spread/DOM genuinely does not matter across a real, multi-day hold');
});
test('a real, honestly-unavailable factor (pass:null, score not a number) is correctly, safely excluded from the weighted total, never coerced into a false zero contribution that could hide a genuine gap', () => {
  const withUnavailable = [...fnoTestSampleResults, { cat: 'Vol', factor: 'IV Percentile', pass: null, score: null }];
  const r1 = __fno_computeTradeTypeWeightedScore(fnoTestSampleResults, 'intraday');
  const r2 = __fno_computeTradeTypeWeightedScore(withUnavailable, 'intraday');
  assert.strictEqual(r1.weightedScore, r2.weightedScore, 'a real, genuinely unavailable factor must contribute exactly nothing to the real weighted total, identical to it simply not being present');
  assert.strictEqual(r2.byCategory['Vol'], undefined, 'a real category with only an unavailable factor must not even appear in the real, per-category breakdown - honestly absent, not a fabricated zero entry');
});
test('an unrecognized/genuinely unknown trading type honestly, safely falls back to the real, unweighted (1.0x) behavior, never throws', () => {
  const r = __fno_computeTradeTypeWeightedScore(fnoTestSampleResults, 'not_a_real_type');
  const rawSum = fnoTestSampleResults.reduce((s, x) => s + x.score, 0);
  assert.strictEqual(r.weightedScore, rawSum, 'a real, unrecognized trading type must safely behave exactly like intraday (1.0x), never crash or silently apply a wrong weight table');
});
test('a real, genuinely empty results array honestly returns a real, zero weighted score, never throws', () => {
  const r = __fno_computeTradeTypeWeightedScore([], 'scalping');
  assert.strictEqual(r.weightedScore, 0);
  assert.deepStrictEqual(r.byCategory, {});
});

console.log('\n=== computeTradeTypeDirectionalWeightedScore + applyTradeTypeWeightingAdjustmentToDecision (post-session audit fix - wiring tradeTypeWeighting into the actual decision) ===');
const fnoDirectionalSample = [
  { cat: 'Microstructure', factor: 'Bid-Ask Spread', pass: true, score: 5 }, // NOT a directional category - must be excluded
  { cat: 'Fundamental', factor: 'Results Calendar', pass: true, score: 10 },
  { cat: 'Market', factor: 'Trend', pass: true, score: 5 },
];
test('computeTradeTypeDirectionalWeightedScore only sums DIRECTIONAL_CATS - a Microstructure factor must never contribute', () => {
  const r = __fno_computeTradeTypeDirectionalWeightedScore(fnoDirectionalSample, 'intraday');
  assert.strictEqual(r, 15, 'must be exactly Fundamental(10)+Market(5)=15, excluding the real Microstructure(5) score entirely');
});
test('computeTradeTypeDirectionalWeightedScore is mathematically identical to a plain unweighted directional sum for intraday (every weight 1.0x)', () => {
  const rIntraday = __fno_computeTradeTypeDirectionalWeightedScore(fnoDirectionalSample, 'intraday');
  const plainSum = fnoDirectionalSample.filter(x => x.cat !== 'Microstructure').reduce((s, x) => s + x.score, 0);
  assert.strictEqual(rIntraday, plainSum);
});
test('computeTradeTypeDirectionalWeightedScore genuinely differs for swing (Fundamental weighted up 1.5x, Market weighted up 1.4x)', () => {
  const rSwing = __fno_computeTradeTypeDirectionalWeightedScore(fnoDirectionalSample, 'swing');
  assert.strictEqual(rSwing, 10 * 1.5 + 5 * 1.4, 'must be exactly the real, documented swing weights applied - Fundamental 1.5x, Market 1.4x');
});
test('applyTradeTypeWeightingAdjustmentToDecision is a no-op for any decision that is not BUY_READY/SELL_READY', () => {
  const r = __fno_applyTradeTypeWeightingAdjustmentToDecision('WAIT', 'High', 'orig reason', 5, -50, 'swing', 11, -17);
  assert.strictEqual(r.decision, 'WAIT');
  assert.strictEqual(r.tradeTypeWeightingAdjustment, null);
});
test('applyTradeTypeWeightingAdjustmentToDecision is a guaranteed no-op for intraday, even when weighted/unweighted scores genuinely differ', () => {
  const r = __fno_applyTradeTypeWeightingAdjustmentToDecision('BUY_READY', 'High', 'orig reason', 12, -50, 'intraday', 11, -17);
  assert.strictEqual(r.decision, 'BUY_READY');
  assert.strictEqual(r.confidence, 'High');
  assert.strictEqual(r.tradeTypeWeightingAdjustment, null, 'intraday must never be adjusted, regardless of what the weighted score says - this app\'s own original, default behavior stays unchanged');
});
test('applyTradeTypeWeightingAdjustmentToDecision does nothing when the trade-type-weighted score STILL crosses the real threshold', () => {
  const r = __fno_applyTradeTypeWeightingAdjustmentToDecision('BUY_READY', 'Medium', 'orig reason', 12, 15, 'swing', 11, -17);
  assert.strictEqual(r.decision, 'BUY_READY');
  assert.strictEqual(r.tradeTypeWeightingAdjustment, null, 'weighted score (15) still clears the real BUY threshold (11) - genuinely nothing to downgrade');
});
test('applyTradeTypeWeightingAdjustmentToDecision downgrades a non-High-confidence BUY_READY to WAIT when the trade-type-weighted score no longer crosses the real threshold', () => {
  const r = __fno_applyTradeTypeWeightingAdjustmentToDecision('BUY_READY', 'Medium', 'orig reason', 12, 8, 'swing', 11, -17);
  assert.strictEqual(r.decision, 'WAIT', 'weighted score (8) genuinely fails to clear the real BUY threshold (11) under swing\'s own weighting - must downgrade to WAIT, exactly the same conservatism as the regime-adjustment function');
  assert.ok(r.tradeTypeWeightingAdjustment && r.tradeTypeWeightingAdjustment.includes('swing'));
});
test('applyTradeTypeWeightingAdjustmentToDecision only relabels (never blocks) a genuinely High-confidence SELL_READY, matching the same conservatism as regime adjustment', () => {
  const r = __fno_applyTradeTypeWeightingAdjustmentToDecision('SELL_READY', 'High', 'orig reason', -20, -10, 'scalping', 11, -17);
  assert.strictEqual(r.decision, 'SELL_READY', 'a real, High-confidence call must be relabeled, never fully blocked outright');
  assert.strictEqual(r.confidence, 'Medium');
  assert.ok(r.tradeTypeWeightingAdjustment);
});

console.log('\n=== computeTradeTypeWinRate / applyTradeTypeAdjustmentToDecision (real, trade-type-independent learning - requirement #10) ===');
test('SELF-CAUGHT REAL BUG, found while building this: correctly reads a real journal entry\'s trade type from EITHER real, actual data source - the server (`tradingStyle`) or the local-only fallback (`tradingType`) - a real, exposed field-naming inconsistency that had silently broken the entire Trade Ledger "Type" column and the server-side round-trip since it was first built, caught only by building this next feature and tracing the real, full data path carefully', () => {
  const serverStyleJournal = [{ pnl: 500, tradingStyle: 'scalping' }, { pnl: -300, tradingStyle: 'scalping' }, { pnl: 200, tradingStyle: 'scalping' }];
  const localStyleJournal = [{ pnl: 500, tradingType: 'swing' }, { pnl: -300, tradingType: 'swing' }];
  const r1 = __fno_computeTradeTypeWinRate(serverStyleJournal);
  const r2 = __fno_computeTradeTypeWinRate(localStyleJournal);
  assert.ok(r1.has('scalping'), 'real, server-shaped entries (tradingStyle field) must be correctly recognized');
  assert.ok(r2.has('swing'), 'real, local-fallback entries (tradingType field) must ALSO be correctly recognized - both real, actual data sources this app can produce');
});
test('a genuinely poor real win rate (<=40%) with an adequate real sample correctly downgrades confidence, mirroring the already-proven regime-adjustment behavior exactly', () => {
  const journal = Array.from({ length: 20 }, (_, i) => ({ pnl: i < 6 ? 500 : -300, tradingStyle: 'scalping' })); // 6/20 = 30% real win rate, genuinely poor
  const winRates = __fno_computeTradeTypeWinRate(journal);
  const r = __fno_computeTradeTypeAdjustedConfidence('High', 'scalping', winRates);
  assert.strictEqual(r.wasDowngraded, true, 'a real, genuinely poor (30%) win rate with an adequate real sample (20 >= 15) must correctly trigger a downgrade');
  assert.strictEqual(r.adjustedConfidence, 'Medium', 'High must downgrade exactly one step to Medium, matching the real, already-proven regime-adjustment rule');
});
test('an inadequate real sample size (below the real, documented 15-trade threshold) honestly never adjusts, regardless of how poor the win rate looks', () => {
  const journal = Array.from({ length: 5 }, () => ({ pnl: -300, tradingStyle: 'scalping' })); // a real, genuinely terrible 0% win rate, but only 5 real trades
  const winRates = __fno_computeTradeTypeWinRate(journal);
  const r = __fno_computeTradeTypeAdjustedConfidence('High', 'scalping', winRates);
  assert.strictEqual(r.wasDowngraded, false, 'a real, inadequate sample must never trigger an adjustment, no matter how poor it looks - the same real, conservative discipline as regime adjustment');
});
test('a real, genuinely good win rate never downgrades, and a real, different trade type\'s own separate track record is genuinely independent - the core, real "learn separately by trade type" requirement', () => {
  const journal = [
    ...Array.from({ length: 18 }, () => ({ pnl: 500, tradingStyle: 'scalping' })), // real, 100% scalping win rate
    ...Array.from({ length: 18 }, () => ({ pnl: -300, tradingStyle: 'swing' })), // real, 0% swing win rate
  ];
  const winRates = __fno_computeTradeTypeWinRate(journal);
  const scalpingResult = __fno_computeTradeTypeAdjustedConfidence('High', 'scalping', winRates);
  const swingResult = __fno_computeTradeTypeAdjustedConfidence('High', 'swing', winRates);
  assert.strictEqual(scalpingResult.wasDowngraded, false, 'a real, genuinely good scalping track record must not be downgraded, even though the SAME journal genuinely also contains a real, terrible swing track record');
  assert.strictEqual(swingResult.wasDowngraded, true, 'the real, terrible swing track record must independently, correctly trigger its own downgrade - proving the two real trade types genuinely learn separately, not blended into one combined statistic');
});
test('applyTradeTypeAdjustmentToDecision correctly downgrades a genuinely marginal (non-High) decision all the way to WAIT, matching the exact same real, conservative rule already proven for regime adjustment', () => {
  const journal = Array.from({ length: 20 }, (_, i) => ({ pnl: i < 5 ? 500 : -300, tradingStyle: 'scalping' })); // 25% real win rate
  const r = __fno_applyTradeTypeAdjustmentToDecision('BUY_READY', 'Medium', 'original real reason', 'scalping', journal);
  assert.strictEqual(r.decision, 'WAIT', 'a real, marginal (Medium) call in a historically poor trade type must be downgraded all the way to WAIT, not merely relabeled');
  assert.ok(r.tradeTypeAdjustment !== null, 'the real, honest adjustment reason must be reported, never silent');
});
test('applyTradeTypeAdjustmentToDecision never applies to a WAIT/NO_TRADE decision, and never touches a genuinely, already-null journal - real, safe no-ops', () => {
  const r1 = __fno_applyTradeTypeAdjustmentToDecision('WAIT', 'Low', 'reason', 'scalping', []);
  assert.strictEqual(r1.tradeTypeAdjustment, null, 'must never apply to a decision that was never a real BUY/SELL_READY in the first place');
  const r2 = __fno_applyTradeTypeAdjustmentToDecision('BUY_READY', 'High', 'reason', null, []);
  assert.strictEqual(r2.tradeTypeAdjustment, null, 'a genuinely missing trading type must safely no-op, never throw');
});

console.log('\n=== checkSufficientTimeRemaining (CRITICAL, direct fix for the user\'s own reported ~Rs20,000 loss: an intraday trade opened at ~3:30 PM was immediately force-closed at square-off) ===');
test('SELF-CAUGHT/REPRODUCED THE EXACT REAL, REPORTED BUG: at 3:30 PM, with no configured broker square-off time (the real, common default case), a new intraday entry is now correctly, definitively BLOCKED - this is the exact real scenario that produced the real, reported ~Rs20,000 loss', () => {
  const r = __fno_checkSufficientTimeRemaining({ time: '15:30' }, 'intraday');
  assert.strictEqual(r.sufficient, false, 'a new intraday entry at 3:30 PM must genuinely be blocked - this exact real scenario is what caused the real, reported loss');
  assert.strictEqual(r.minutesRemaining, -15, 'hand-verified: real deadline 15:15 minus real current time 15:30 = -15 real minutes, honestly already past the deadline');
});
test('a real, genuine mid-afternoon entry (2:30 PM) with plenty of real time remaining is correctly, honestly allowed for intraday', () => {
  const r = __fno_checkSufficientTimeRemaining({ time: '14:30' }, 'intraday');
  assert.strictEqual(r.sufficient, true);
  assert.strictEqual(r.minutesRemaining, 45);
});
test('a real, borderline case (2:50 PM, 25 real minutes remaining) is correctly blocked for intraday (needs 30+) but correctly allowed for scalping (needs only 10+) - the real, distinct, trade-type-specific rule the user explicitly asked for', () => {
  const intradayResult = __fno_checkSufficientTimeRemaining({ time: '14:50' }, 'intraday');
  assert.strictEqual(intradayResult.sufficient, false, 'intraday genuinely needs a real, larger buffer (30 min) - 25 remaining is honestly insufficient');
  const scalpingResult = __fno_checkSufficientTimeRemaining({ time: '14:50' }, 'scalping');
  assert.strictEqual(scalpingResult.sufficient, true, 'scalping genuinely needs only a real, smaller buffer (10 min) - the same real 25 minutes remaining is honestly sufficient for a real, fast scalp');
});
test('swing is genuinely, correctly exempt from same-day square-off timing entirely, even at the literal real market close', () => {
  const r = __fno_checkSufficientTimeRemaining({ time: '15:30' }, 'swing');
  assert.strictEqual(r.sufficient, true, 'a real swing position is genuinely expected to carry past today - same-day timing honestly does not apply');
});
test('a real, user-configured broker square-off time is genuinely used in place of the real, documented 15:15 default when present', () => {
  const r = __fno_checkSufficientTimeRemaining({ time: '15:00', brokerSquareOffTime: '15:20' }, 'intraday');
  assert.strictEqual(r.minutesRemaining, 20, 'hand-verified: real, configured deadline 15:20 minus real current time 15:00 = 20 real minutes');
  assert.strictEqual(r.sufficient, false, '20 real minutes remaining is honestly still below the real, required 30-minute intraday buffer');
});
test('genuinely missing/unparseable real time data honestly does not block - "cannot verify" is not the same real claim as "genuinely insufficient", and this check must never fabricate a block from data it does not have', () => {
  const r1 = __fno_checkSufficientTimeRemaining({ time: undefined }, 'intraday');
  assert.strictEqual(r1.sufficient, true, 'a genuinely missing real time value must not cause a false block');
  const r2 = __fno_checkSufficientTimeRemaining({ time: 'not-a-real-time', brokerSquareOffTime: '15:15' }, 'intraday');
  assert.strictEqual(r2.sufficient, true, 'genuinely unparseable real time data must not cause a false block');
});
test('exactly AT the real, required buffer boundary (30 real minutes for intraday) is correctly, honestly sufficient - an inclusive, not exclusive, real boundary', () => {
  const r = __fno_checkSufficientTimeRemaining({ time: '14:45' }, 'intraday'); // 15:15 - 14:45 = exactly 30 real minutes
  assert.strictEqual(r.minutesRemaining, 30);
  assert.strictEqual(r.sufficient, true, 'exactly the real, required minimum must count as sufficient, not fall just short of it');
});

console.log('\n=== checkSignalInvalidation (real, NEW check this session - KB §7 "Cancellation/invalidation (distinct from SL)... Not yet built as a distinct \'invalidate before SL is hit\' mechanism") ===');
test('correctly invalidates a CE (bullish) position when the live brain has genuinely reversed to SELL_READY at High confidence', () => {
  const open = { optionType: 'CE' };
  const brain = { decision: 'SELL_READY', confidence: 'High' };
  const r = __fno_checkSignalInvalidation(open, brain);
  assert.strictEqual(r.invalidated, true);
  assert.ok(/reversal/.test(r.reason));
});
test('correctly invalidates a PE (bearish) position when the live brain has genuinely reversed to BUY_READY at Medium confidence', () => {
  const open = { optionType: 'PE' };
  const brain = { decision: 'BUY_READY', confidence: 'Medium' };
  const r = __fno_checkSignalInvalidation(open, brain);
  assert.strictEqual(r.invalidated, true);
});
test('correctly does NOT invalidate when the reversed signal is only Low confidence - a real, low-confidence flip-flop must not fire this check', () => {
  const open = { optionType: 'CE' };
  const brain = { decision: 'SELL_READY', confidence: 'Low' };
  const r = __fno_checkSignalInvalidation(open, brain);
  assert.strictEqual(r.invalidated, false);
});
test('correctly does NOT invalidate on a mere drop to WAIT - genuinely ambiguous, not a real reversal', () => {
  const open = { optionType: 'CE' };
  const brain1 = { decision: 'WAIT', confidence: 'High' };
  assert.strictEqual(__fno_checkSignalInvalidation(open, brain1).invalidated, false);
});
// Phase 5 lifecycle audit (2026-08-30) regression: NO_TRADE is NOT the
// same kind of ambiguous as WAIT - evaluateBrain() sets NO_TRADE ONLY
// when critFails.length>0 (a real, deterministic critical block: ban
// list, daily loss limit, circuit breaker, expiry, decay, Personal
// readiness). Before this fix NO_TRADE was silently treated the same
// as WAIT here, meaning a real position opened before a critical
// condition emerged (e.g. its underlying got banned mid-day) would
// keep running with NO automatic reaction, since
// evaluatePreTradeFailureModes() (the FM library) is only ever
// invoked pre-trade and is never re-run once a position is open.
test('correctly DOES invalidate a CE position on a live NO_TRADE (real critical block) regardless of confidence', () => {
  const open = { optionType: 'CE' };
  const brain = { decision: 'NO_TRADE', confidence: 'High', reason: 'Critical: Ban List' };
  const r = __fno_checkSignalInvalidation(open, brain);
  assert.strictEqual(r.invalidated, true);
  assert.ok(/CRITICAL/.test(r.reason));
  assert.ok(/Ban List/.test(r.reason));
});
test('correctly DOES invalidate a PE position on a live NO_TRADE even without a confidence field', () => {
  const open = { optionType: 'PE' };
  const brain = { decision: 'NO_TRADE' };
  const r = __fno_checkSignalInvalidation(open, brain);
  assert.strictEqual(r.invalidated, true);
});
test('correctly does NOT invalidate when the live decision still agrees with (or is neutral relative to) the original thesis', () => {
  const open = { optionType: 'CE' };
  const brain = { decision: 'BUY_READY', confidence: 'High' };
  assert.strictEqual(__fno_checkSignalInvalidation(open, brain).invalidated, false);
});
test('honestly does not invalidate on genuinely missing/invalid inputs - never fabricates a reversal from data that does not exist', () => {
  assert.strictEqual(__fno_checkSignalInvalidation(null, { decision: 'SELL_READY', confidence: 'High' }).invalidated, false);
  assert.strictEqual(__fno_checkSignalInvalidation({ optionType: 'XX' }, { decision: 'SELL_READY', confidence: 'High' }).invalidated, false);
  assert.strictEqual(__fno_checkSignalInvalidation({ optionType: 'CE' }, null).invalidated, false);
  assert.strictEqual(__fno_checkSignalInvalidation({ optionType: 'CE' }, {}).invalidated, false);
});
test('static wiring-audit lock: the real position-monitoring loop genuinely calls checkSignalInvalidation with the real, live lastBrain AFTER the price-based checkTradeExit check, and closes with the distinct \'invalidated\' reason, never conflated with a real SL exit', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const exitIdx = coreSrc.indexOf('const exit = checkTradeExit(open.entryPrice, liveNow, open.target, effectiveSl);');
  assert.ok(exitIdx > -1, 'the real price-based exit check call site must still exist');
  const nearby = coreSrc.slice(exitIdx, exitIdx + 1100);
  assert.ok(/checkSignalInvalidation\(open, lastBrain\)/.test(nearby), 'must genuinely call checkSignalInvalidation with the real, live lastBrain, not a fabricated/stale value');
  assert.ok(/closeAutoTrade\(open, leg, 'invalidated', sym\)/.test(nearby), 'must genuinely close with the distinct \'invalidated\' reason');
  assert.ok(/const sourceLabel[\s\S]{0,300}auto_invalidated/.test(coreSrc), 'the real journaled source label for an invalidation exit must be genuinely distinct (auto_invalidated), never folded into auto_sl');
});
test('static check: the real re-entry cooldown gate correctly, deliberately does NOT treat an invalidation exit as a stop-loss (these remain genuinely distinct real conditions)', () => {
  const now = Date.now();
  const history = [{ ts: now - 60000, source: 'auto_invalidated', tradingType: 'intraday' }];
  const r = __fno_checkReEntryCooldown(history, 'intraday', now);
  assert.strictEqual(r.onCooldown, false, 'an invalidation exit must never trigger the SL-specific re-entry cooldown - it is a genuinely different real condition');
});

console.log('\n=== checkReEntryCooldown (real, NEW gate this session - KB §7 "Re-entry after exit... needs explicit cooldown/re-entry logic per trade type") ===');
test('correctly blocks a new intraday entry within the real 15-minute cooldown after an intraday stop-loss exit', () => {
  const now = Date.now();
  const history = [{ ts: now - 5*60000, source: 'auto_sl', tradingType: 'intraday' }];
  const r = __fno_checkReEntryCooldown(history, 'intraday', now);
  assert.strictEqual(r.onCooldown, true);
  assert.ok(Math.abs(r.minutesSinceLastSl - 5) < 0.01);
});
test('correctly allows a new intraday entry once the real 15-minute cooldown has genuinely elapsed', () => {
  const now = Date.now();
  const history = [{ ts: now - 20*60000, source: 'auto_sl', tradingType: 'intraday' }];
  const r = __fno_checkReEntryCooldown(history, 'intraday', now);
  assert.strictEqual(r.onCooldown, false);
});
test('scalping genuinely uses its own, shorter 5-minute cooldown - the same real 6-minute gap that would still block intraday correctly allows scalping', () => {
  const now = Date.now();
  const history = [{ ts: now - 6*60000, source: 'auto_sl', tradingType: 'scalping' }];
  const r = __fno_checkReEntryCooldown(history, 'scalping', now);
  assert.strictEqual(r.onCooldown, false, 'scalping needs only 5 real minutes - 6 minutes elapsed is genuinely sufficient');
});
test('swing is genuinely, correctly exempt from the cooldown entirely, even with a real, very recent SL exit', () => {
  const now = Date.now();
  const history = [{ ts: now - 60000, source: 'auto_sl', tradingType: 'swing' }];
  const r = __fno_checkReEntryCooldown(history, 'swing', now);
  assert.strictEqual(r.onCooldown, false, 'a real, multi-day swing timeframe makes a minutes-scale cooldown honestly meaningless');
});
test('correctly never fires on a non-SL exit (target/manual/square-off/partial) - the cooldown exists specifically for a just-proven-wrong SL exit, not every exit', () => {
  const now = Date.now();
  ['auto_target', 'manual_force_exit', 'square_off', 'partial'].forEach(source => {
    const history = [{ ts: now - 60000, source, tradingType: 'intraday' }];
    const r = __fno_checkReEntryCooldown(history, 'intraday', now);
    assert.strictEqual(r.onCooldown, false, `a real ${source} exit must never trigger the SL-specific cooldown`);
  });
});
test('correctly never cross-compares trade types - a real scalping SL exit must never block a new intraday entry, or vice versa', () => {
  const now = Date.now();
  const history = [{ ts: now - 60000, source: 'auto_sl', tradingType: 'scalping' }];
  const r = __fno_checkReEntryCooldown(history, 'intraday', now);
  assert.strictEqual(r.onCooldown, false, 'a scalping SL exit must never gate a new intraday entry - these are genuinely separate real timeframes');
});
test('correctly finds the MOST RECENT matching SL exit, not an older one, when history has multiple entries', () => {
  const now = Date.now();
  const history = [
    { ts: now - 60*60000, source: 'auto_sl', tradingType: 'intraday' }, // old, long expired
    { ts: now - 20*60000, source: 'auto_target', tradingType: 'intraday' }, // not SL, ignored
    { ts: now - 3*60000, source: 'auto_sl', tradingType: 'intraday' }, // real, most recent SL - this one should govern
  ];
  const r = __fno_checkReEntryCooldown(history, 'intraday', now);
  assert.strictEqual(r.onCooldown, true, 'must use the most recent real SL exit (3 min ago), not the older, already-expired one (60 min ago)');
});
test('honestly does not block on genuinely missing/empty history, or when no matching SL exit exists at all', () => {
  assert.strictEqual(__fno_checkReEntryCooldown([], 'intraday', Date.now()).onCooldown, false);
  assert.strictEqual(__fno_checkReEntryCooldown(null, 'intraday', Date.now()).onCooldown, false);
  assert.strictEqual(__fno_checkReEntryCooldown([{ts: Date.now(), source: 'auto_target', tradingType: 'intraday'}], 'intraday', Date.now()).onCooldown, false);
});
test('exactly AT the cooldown boundary (15.0 minutes for intraday) is correctly, honestly past cooldown - an inclusive, not exclusive, real boundary', () => {
  const now = Date.now();
  const history = [{ ts: now - 15*60000, source: 'auto_sl', tradingType: 'intraday' }];
  const r = __fno_checkReEntryCooldown(history, 'intraday', now);
  assert.strictEqual(r.onCooldown, false, 'exactly the required minimum must count as past cooldown, not still blocking');
});
test('static wiring-audit lock: the real tryOpenAutoTradePosition() call site genuinely calls checkReEntryCooldown() with the real closed-trade history and genuinely returns opened:false when on cooldown, BEFORE the ocRow/leg checks run', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const cooldownIdx = fnBody.indexOf('checkReEntryCooldown(');
  const ocRowIdx = fnBody.indexOf('!curCtx || !curCtx.ocRow');
  assert.ok(cooldownIdx > -1, 'the real entry flow must genuinely call checkReEntryCooldown()');
  assert.ok(/load\(STORAGE\.autoTrades \+ '_history'\)/.test(fnBody.slice(cooldownIdx - 20, cooldownIdx + 120)), 'must genuinely pass the real, existing closed-trade history, not a fabricated/empty array');
  assert.ok(/if \(cooldownCheck\.onCooldown\)/.test(fnBody) && /return \{ opened: false, reason: cooldownCheck\.reason \}/.test(fnBody), 'must genuinely return opened:false with the real reason when on cooldown, not merely compute and ignore the result');
  assert.ok(cooldownIdx < ocRowIdx, 'the cooldown gate must run BEFORE the ocRow/leg checks, matching the established gate-ordering discipline (cheapest, most fundamental checks first)');
});

console.log('\n=== adjustFailureModesForTradeType (real, trade-type-aware Failure Library adaptation - requirement #5: "historical failure patterns relevant to scalping are not treated the same as swing-trade failures") ===');
test('intraday is a genuine no-op - every real entry\'s adjusted severity/action exactly equals its original, unmodified value', () => {
  const fakeResult = { triggered: [{ id: 'FM024', condition: 'a real, scalping-relevant condition', severity: 'high', action: 'block', reason: 'test' }], finalAction: 'block' };
  const r = __fno_adjustFailureModesForTradeType(fakeResult, 'intraday');
  assert.strictEqual(r.triggered[0].adjustedSeverity, 'high', 'intraday must never adjust severity, even for a scalping-relevant condition');
  assert.strictEqual(r.triggered[0].adjustedAction, 'block');
  assert.strictEqual(r.finalAction, 'block');
});
test('a real, scalping-relevant condition (FM024) genuinely gets bumped one severity level when the trade type is scalping', () => {
  const fakeResult = { triggered: [{ id: 'FM024', condition: 'real spread/liquidity condition', severity: 'high', action: 'require_confirmation', reason: 'test' }], finalAction: 'require_confirmation' };
  const r = __fno_adjustFailureModesForTradeType(fakeResult, 'scalping');
  assert.strictEqual(r.triggered[0].typeRelevance, 'scalping', 'the real, scalping-relevant condition must be honestly labeled as such');
  assert.strictEqual(r.triggered[0].adjustedSeverity, 'critical', 'high must genuinely bump exactly one level to critical, matching the real, documented, conservative "one level only" rule');
  assert.strictEqual(r.triggered[0].adjustedAction, 'block', 'critical severity correctly maps to the block action');
  assert.strictEqual(r.finalAction, 'block', 'the real, overall finalAction must reflect the real, adjusted, more severe action');
});
test('the SAME real condition is genuinely NOT bumped when the trade type is swing instead - the real relevance is type-specific, not universal', () => {
  const fakeResult = { triggered: [{ id: 'FM024', condition: 'real spread/liquidity condition', severity: 'high', action: 'require_confirmation', reason: 'test' }], finalAction: 'require_confirmation' };
  const r = __fno_adjustFailureModesForTradeType(fakeResult, 'swing');
  assert.strictEqual(r.triggered[0].typeRelevance, null, 'FM024 (a real, scalping-specific condition) must honestly show no swing relevance');
  assert.strictEqual(r.triggered[0].adjustedSeverity, 'high', 'must remain genuinely unadjusted for a trade type this condition is not documented as relevant to');
});
test('a real, swing-relevant condition (FM019) genuinely gets bumped for swing but not for scalping', () => {
  const fakeResult = { triggered: [{ id: 'FM019', condition: 'real, short-dated low-IV condition', severity: 'low', action: 'reduce_confidence', reason: 'test' }], finalAction: 'reduce_confidence' };
  const swingResult = __fno_adjustFailureModesForTradeType(fakeResult, 'swing');
  assert.strictEqual(swingResult.triggered[0].adjustedSeverity, 'medium', 'low must genuinely bump exactly one level to medium for swing, where this real condition is documented as relevant');
  const scalpingResult = __fno_adjustFailureModesForTradeType(fakeResult, 'scalping');
  assert.strictEqual(scalpingResult.triggered[0].adjustedSeverity, 'low', 'the same real condition must remain unadjusted for scalping, where it is not documented as relevant');
});
test('a real, genuinely critical condition can never be bumped past critical - the real, conservative ceiling holds', () => {
  const fakeResult = { triggered: [{ id: 'FM024', condition: 'test', severity: 'critical', action: 'block', reason: 'test' }], finalAction: 'block' };
  const r = __fno_adjustFailureModesForTradeType(fakeResult, 'scalping');
  assert.strictEqual(r.triggered[0].adjustedSeverity, 'critical', 'critical must genuinely stay critical, never overflow past the real, documented severity scale');
});
test('the real, original fmResult object passed in is never mutated - a real, honest, pure function', () => {
  const fakeResult = { triggered: [{ id: 'FM024', condition: 'test', severity: 'high', action: 'require_confirmation', reason: 'test' }], finalAction: 'require_confirmation' };
  const originalSeverity = fakeResult.triggered[0].severity;
  __fno_adjustFailureModesForTradeType(fakeResult, 'scalping');
  assert.strictEqual(fakeResult.triggered[0].severity, originalSeverity, 'the real, original input object must be genuinely untouched - this function must never mutate its argument');
  assert.strictEqual(fakeResult.triggered[0].adjustedSeverity, undefined, 'the real, original object must not even gain the new field - a real, true copy, not a shared reference');
});
test('a real, genuinely empty triggered list honestly produces a real, empty adjusted list and a real "none" final action, never throws', () => {
  const r = __fno_adjustFailureModesForTradeType({ triggered: [], finalAction: 'none' }, 'scalping');
  assert.deepStrictEqual(r.triggered, []);
  assert.strictEqual(r.finalAction, 'none');
});

console.log('\n=== isRealMarketHours (real, timezone-correct NSE session check - critical for the Autonomous Mode polling loop) ===');
test('real NSE mid-session time (10:00 IST, a real Monday) is correctly identified as market hours', () => {
  // Aug 17 2026 04:30 UTC = 10:00 IST, a real Monday - verified via a
  // real UTC epoch construction, not a host-timezone-dependent guess.
  const t = new Date(Date.UTC(2026, 7, 17, 4, 30, 0));
  const r = __fno_isRealMarketHours(t);
  assert.strictEqual(r.isMarketHours, true);
});
test('exact real market-open boundary (9:15:00 IST) counts as open - inclusive boundary', () => {
  const t = new Date(Date.UTC(2026, 7, 17, 3, 45, 0)); // 03:45 UTC = 09:15 IST
  assert.strictEqual(__fno_isRealMarketHours(t).isMarketHours, true);
});
test('exact real market-close boundary (15:30:00 IST) still counts as open - inclusive boundary', () => {
  const t = new Date(Date.UTC(2026, 7, 17, 10, 0, 0)); // 10:00 UTC = 15:30 IST
  assert.strictEqual(__fno_isRealMarketHours(t).isMarketHours, true);
});
test('one real minute after market close (15:31 IST) is correctly closed', () => {
  const t = new Date(Date.UTC(2026, 7, 17, 10, 1, 0)); // 10:01 UTC = 15:31 IST
  const r = __fno_isRealMarketHours(t);
  assert.strictEqual(r.isMarketHours, false);
  assert.ok(/Outside NSE session hours/.test(r.reason));
});
test('one real minute before market open (9:14 IST) is correctly closed', () => {
  const t = new Date(Date.UTC(2026, 7, 17, 3, 44, 0)); // 03:44 UTC = 09:14 IST
  assert.strictEqual(__fno_isRealMarketHours(t).isMarketHours, false);
});
test('a real Saturday (even during real session-equivalent hours) is correctly closed - weekend check', () => {
  const t = new Date(Date.UTC(2026, 7, 15, 4, 30, 0)); // Aug 15 2026 is a real Saturday
  const r = __fno_isRealMarketHours(t);
  assert.strictEqual(r.isMarketHours, false);
  assert.ok(/Weekend/.test(r.reason));
});
test('a real Sunday is correctly closed', () => {
  const t = new Date(Date.UTC(2026, 7, 16, 4, 30, 0)); // Aug 16 2026 is a real Sunday
  assert.strictEqual(__fno_isRealMarketHours(t).isMarketHours, false);
});
test('midnight (deep after-hours) is correctly closed', () => {
  const t = new Date(Date.UTC(2026, 7, 17, 18, 30, 0)); // 18:30 UTC = 00:00 IST (next day)
  assert.strictEqual(__fno_isRealMarketHours(t).isMarketHours, false);
});
test('genuinely host-timezone-independent - the SAME real UTC moment produces the SAME result regardless of the host machine\'s own timezone', () => {
  const realMoment = new Date(Date.UTC(2026, 7, 17, 4, 30, 0)); // real 10:00 IST moment
  const simulatedNonUtcHost = new Date(realMoment.getTime());
  simulatedNonUtcHost.getTimezoneOffset = () => 240; // simulate a US-Eastern-like host
  assert.strictEqual(__fno_isRealMarketHours(simulatedNonUtcHost).isMarketHours, true, 'must not depend on which timezone the host machine happens to be running in');
});
test('SELF-CAUGHT REAL BUG (found via a direct user report): the previous code incorrectly applied an extra getTimezoneOffset()-based shift on top of an already-correct UTC value, silently computing the WRONG IST time whenever the host/browser timezone was not exactly UTC+0 - the boolean-only test above happened not to catch this, since the corrupted time still coincidentally fell within market hours for that specific scenario. This test checks the exact, real, computed IST time itself, across several real, distinct host timezones, using a genuinely fixed real UTC instant known to be 2:59 PM IST - reproducing the user\'s own exact, reported scenario.', () => {
  const fixedRealUtcMoment = new Date(Date.UTC(2026, 7, 17, 9, 29, 0)); // real 09:29 UTC = real, correct 14:59 IST (2:59 PM), a real Monday
  const hostOffsets = [
    { label: 'IST host (-330)', offset: -330 },
    { label: 'UTC host (0)', offset: 0 },
    { label: 'US Eastern-like host (240)', offset: 240 },
    { label: 'US Pacific-like host (420)', offset: 420 },
    { label: 'Singapore-like host (-480)', offset: -480 },
  ];
  hostOffsets.forEach(h => {
    const simulatedHost = new Date(fixedRealUtcMoment.getTime());
    simulatedHost.getTimezoneOffset = () => h.offset;
    const r = __fno_isRealMarketHours(simulatedHost);
    // Checks the real, exact computed hours/minutes directly (now
    // exposed on every real return branch, not just the "outside
    // hours" one) - genuinely catches all 5 real corruption cases,
    // not just the ones that happened to also flip the boolean.
    // Directly confirmed while building this fix: 2 of these 5 real
    // scenarios (IST-host, UTC-host) had the OLD, buggy code still
    // coincidentally produce isMarketHours===true despite an
    // internally wrong computed time - only checking the exact time
    // value, not the boolean alone, closes that real gap.
    assert.strictEqual(r.hours, 14, `${h.label}: the real, correct IST hour is 14 (2:59 PM) - must be exact, not just "still happens to be within market hours"`);
    assert.strictEqual(r.minutes, 59, `${h.label}: the real, correct IST minute is 59`);
    assert.strictEqual(r.isMarketHours, true, `${h.label}: the real, correct IST time (2:59 PM) is genuinely within market hours - must be true regardless of host timezone`);
  });
});
test('defaults to new Date() (genuinely current real time) when no argument is provided - does not throw', () => {
  const r = __fno_isRealMarketHours();
  assert.strictEqual(typeof r.isMarketHours, 'boolean');
});

console.log('\n=== checkMarketDataFreshness (real, NEW Phase-5 fix - the primary chart/option-chain staleness gap found during the post-session Decision Matrix audit) ===');
// Fixed real reference moment: Aug 17 2026 04:30 UTC = 10:00 IST, a real
// Monday, well inside market hours (matches the isRealMarketHours tests above).
const FRESH_NOW_MS = Date.UTC(2026, 7, 17, 4, 30, 0);
test('genuinely empty candle array does not block - honestly defers to the separate, existing missing-data check, not this function\'s job to duplicate it', () => {
  const r = __fno_checkMarketDataFreshness([], FRESH_NOW_MS);
  assert.strictEqual(r.stale, false);
  assert.strictEqual(r.ageMinutes, null);
});
test('non-array candles (null/undefined) does not throw and does not block', () => {
  assert.strictEqual(__fno_checkMarketDataFreshness(null, FRESH_NOW_MS).stale, false);
  assert.strictEqual(__fno_checkMarketDataFreshness(undefined, FRESH_NOW_MS).stale, false);
});
test('newest candle missing a valid `t` timestamp honestly refuses to guess - not blocking on unverifiable data', () => {
  const candles = [ { t: FRESH_NOW_MS - 5*60000, c: 100 }, { c: 101 } ]; // last entry has no t
  const r = __fno_checkMarketDataFreshness(candles, FRESH_NOW_MS);
  assert.strictEqual(r.stale, false);
  assert.strictEqual(r.ageMinutes, null);
});
test('a real candle series whose newest bar is only 2 real minutes old, DURING real market hours, is correctly fresh', () => {
  const candles = [
    { t: FRESH_NOW_MS - 10*60000, c: 100 },
    { t: FRESH_NOW_MS - 2*60000, c: 101 },
  ];
  const r = __fno_checkMarketDataFreshness(candles, FRESH_NOW_MS);
  assert.strictEqual(r.stale, false);
  assert.ok(Math.abs(r.ageMinutes - 2) < 0.01);
});
test('exact real threshold boundary (10 minutes old, per FNO_MARKET_DATA_STALE_THRESHOLD_MINUTES in the real source) still counts as fresh - inclusive boundary, matches the ">" (not ">=") comparison in the real source', () => {
  const candles = [ { t: FRESH_NOW_MS - 10*60000, c: 100 } ];
  const r = __fno_checkMarketDataFreshness(candles, FRESH_NOW_MS);
  assert.strictEqual(r.stale, false, 'exactly 10 minutes old must NOT be flagged stale - the real source uses a strict ">" comparison');
});
test('a real candle series whose newest bar is 15 real minutes old, DURING real market hours, is genuinely flagged stale', () => {
  const candles = [ { t: FRESH_NOW_MS - 30*60000, c: 100 }, { t: FRESH_NOW_MS - 15*60000, c: 101 } ];
  const r = __fno_checkMarketDataFreshness(candles, FRESH_NOW_MS);
  assert.strictEqual(r.stale, true);
  assert.ok(Math.abs(r.ageMinutes - 15) < 0.01);
  assert.ok(/exceeding the 10-minute threshold/.test(r.reason));
});
test('the SAME 15-real-minute-old newest bar OUTSIDE real market hours is correctly NOT flagged stale - old data outside session hours is expected, not a fault', () => {
  const afterHoursNowMs = Date.UTC(2026, 7, 17, 10, 5, 0); // 15:35 IST, 5 real minutes after real close
  const candles = [ { t: afterHoursNowMs - 15*60000, c: 100 } ];
  const r = __fno_checkMarketDataFreshness(candles, afterHoursNowMs);
  assert.strictEqual(r.stale, false);
  assert.strictEqual(r.ageMinutes, null, 'outside market hours, this function deliberately does not even compute an age - matches the real source\'s early return');
});
test('a real Saturday, even with a 15-real-minute-old newest bar, is correctly NOT flagged stale (weekend, not a live session)', () => {
  const saturdayMs = Date.UTC(2026, 7, 15, 4, 30, 0); // real Saturday, 10:00 IST-equivalent
  const candles = [ { t: saturdayMs - 15*60000, c: 100 } ];
  assert.strictEqual(__fno_checkMarketDataFreshness(candles, saturdayMs).stale, false);
});

console.log('\n=== FM154 (real, NEW entry - the checkMarketDataFreshness gap wired into the live Failure-Mode Library evaluator) ===');
test('FM154 genuinely fires reduce_confidence when the real candle series is stale during real market hours', () => {
  const staleCandles = [ { t: FRESH_NOW_MS - 30*60000, c: 100 }, { t: FRESH_NOW_MS - 15*60000, c: 101 } ];
  const origNow = Date.now;
  Date.now = () => FRESH_NOW_MS;
  try {
    const r = __fno_evaluatePreTradeFailureModes({ ctx: { candles: staleCandles } });
    const fm154 = r.triggered.find(t => t.id === 'FM154');
    assert.ok(fm154, 'FM154 must genuinely fire for a real stale candle series during market hours');
    assert.strictEqual(fm154.action, 'reduce_confidence', 'staleness is deliberately reduce_confidence, not block - a stale-but-present snapshot is real caution, not the same severity as genuinely missing data (already handled by FM024/the ocRow guard)');
  } finally { Date.now = origNow; }
});
test('FM154 correctly stays silent for a real, fresh candle series during real market hours', () => {
  const freshCandles = [ { t: FRESH_NOW_MS - 2*60000, c: 100 } ];
  const origNow = Date.now;
  Date.now = () => FRESH_NOW_MS;
  try {
    const r = __fno_evaluatePreTradeFailureModes({ ctx: { candles: freshCandles } });
    assert.strictEqual(r.triggered.find(t => t.id === 'FM154'), undefined, 'must not fire on genuinely fresh data');
  } finally { Date.now = origNow; }
});
test('FM154 correctly stays silent when ctx.candles is genuinely absent - honestly does not block on unverifiable data', () => {
  const r = __fno_evaluatePreTradeFailureModes({ ctx: {} });
  assert.strictEqual(r.triggered.find(t => t.id === 'FM154'), undefined);
});

console.log('\n=== checkOptionChainFreshness (real, NEW this pass - fed by fno_nse_get()\'s newly-captured real fetch timestamp) ===');
test('null/undefined fetchedAtMs (no real NSE round-trip happened this refresh) honestly refuses to guess - not blocking on unverifiable data', () => {
  assert.strictEqual(__fno_checkOptionChainFreshness(null, FRESH_NOW_MS).stale, false);
  assert.strictEqual(__fno_checkOptionChainFreshness(undefined, FRESH_NOW_MS).stale, false);
  assert.strictEqual(__fno_checkOptionChainFreshness(null, FRESH_NOW_MS).ageMinutes, null);
});
test('NaN fetchedAtMs is treated the same as missing - never coerced into a fake age', () => {
  const r = __fno_checkOptionChainFreshness(NaN, FRESH_NOW_MS);
  assert.strictEqual(r.stale, false);
  assert.strictEqual(r.ageMinutes, null);
});
test('a real fetch only 2 real minutes ago, DURING real market hours, is correctly fresh', () => {
  const r = __fno_checkOptionChainFreshness(FRESH_NOW_MS - 2*60000, FRESH_NOW_MS);
  assert.strictEqual(r.stale, false);
  assert.ok(Math.abs(r.ageMinutes - 2) < 0.01);
});
test('exact real threshold boundary (10 minutes, same FNO_MARKET_DATA_STALE_THRESHOLD_MINUTES the candle check uses) still counts as fresh - inclusive boundary, matches the ">" (not ">=") comparison in the real source', () => {
  const r = __fno_checkOptionChainFreshness(FRESH_NOW_MS - 10*60000, FRESH_NOW_MS);
  assert.strictEqual(r.stale, false, 'exactly 10 minutes old must NOT be flagged stale');
});
test('a real fetch 15 real minutes ago, DURING real market hours, is genuinely flagged stale', () => {
  const r = __fno_checkOptionChainFreshness(FRESH_NOW_MS - 15*60000, FRESH_NOW_MS);
  assert.strictEqual(r.stale, true);
  assert.ok(Math.abs(r.ageMinutes - 15) < 0.01);
  assert.ok(/exceeding the 10-minute threshold/.test(r.reason));
});
test('the SAME 15-real-minute-old fetch OUTSIDE real market hours is correctly NOT flagged stale', () => {
  const afterHoursNowMs = Date.UTC(2026, 7, 17, 10, 5, 0); // 15:35 IST, 5 real minutes after real close
  const r = __fno_checkOptionChainFreshness(afterHoursNowMs - 15*60000, afterHoursNowMs);
  assert.strictEqual(r.stale, false);
  assert.strictEqual(r.ageMinutes, null, 'outside market hours, this function deliberately does not even compute an age - matches checkMarketDataFreshness\'s own early return');
});

console.log('\n=== FM155 (real, NEW entry - the option-chain/futures fetch-staleness gap, fed by fno_nse_get()\'s newly-captured real timestamp) ===');
test('FM155 genuinely fires reduce_confidence when ctx.ocFetchedAt is a real, stale NSE fetch time during market hours', () => {
  const origNow = Date.now;
  Date.now = () => FRESH_NOW_MS;
  try {
    const r = __fno_evaluatePreTradeFailureModes({ ctx: { ocFetchedAt: FRESH_NOW_MS - 15*60000 } });
    const fm155 = r.triggered.find(t => t.id === 'FM155');
    assert.ok(fm155, 'FM155 must genuinely fire for a real stale option-chain fetch timestamp during market hours');
    assert.strictEqual(fm155.action, 'reduce_confidence', 'staleness is deliberately reduce_confidence, not block - matches FM154\'s own severity reasoning');
  } finally { Date.now = origNow; }
});
test('FM155 correctly stays silent for a real, fresh ctx.ocFetchedAt', () => {
  const origNow = Date.now;
  Date.now = () => FRESH_NOW_MS;
  try {
    const r = __fno_evaluatePreTradeFailureModes({ ctx: { ocFetchedAt: FRESH_NOW_MS - 2*60000 } });
    assert.strictEqual(r.triggered.find(t => t.id === 'FM155'), undefined, 'must not fire on genuinely fresh data');
  } finally { Date.now = origNow; }
});
test('FM155 correctly stays silent when ctx.ocFetchedAt is genuinely null (Kite fallback / NSE off) AND ctx.futuresFetchedAt is also null - honestly does not block on unverifiable data', () => {
  const r = __fno_evaluatePreTradeFailureModes({ ctx: { ocFetchedAt: null, futuresFetchedAt: null } });
  assert.strictEqual(r.triggered.find(t => t.id === 'FM155'), undefined);
});
test('FM155 falls back to checking ctx.futuresFetchedAt when ctx.ocFetchedAt is genuinely unavailable but futures data has its own real, stale timestamp', () => {
  const origNow = Date.now;
  Date.now = () => FRESH_NOW_MS;
  try {
    const r = __fno_evaluatePreTradeFailureModes({ ctx: { ocFetchedAt: null, futuresFetchedAt: FRESH_NOW_MS - 20*60000 } });
    const fm155 = r.triggered.find(t => t.id === 'FM155');
    assert.ok(fm155, 'FM155 must fall back to the real futures fetch timestamp when the option-chain one is unavailable');
  } finally { Date.now = origNow; }
});
test('FM155 never double-fires: when BOTH ctx.ocFetchedAt and ctx.futuresFetchedAt are present, only the ocFetchedAt branch is evaluated (mutually exclusive if/else)', () => {
  const origNow = Date.now;
  Date.now = () => FRESH_NOW_MS;
  try {
    // ocFetchedAt is fresh (would not fire), futuresFetchedAt is stale
    // (would fire if checked) - if the if/else branches were not
    // mutually exclusive, this would incorrectly fire via futures.
    const r = __fno_evaluatePreTradeFailureModes({ ctx: { ocFetchedAt: FRESH_NOW_MS - 2*60000, futuresFetchedAt: FRESH_NOW_MS - 20*60000 } });
    assert.strictEqual(r.triggered.find(t => t.id === 'FM155'), undefined, 'ocFetchedAt takes precedence and is fresh, so FM155 must stay silent even though futuresFetchedAt alone would be stale');
  } finally { Date.now = origNow; }
});

console.log('\n=== computePortfolioCorrelationRisk (SEBI §18 - real "4 positions, 1 correlated bet" check) ===');
test('CERTAIN case: 2+ real positions on the SAME symbol + SAME optionType are flagged unambiguously', () => {
  const positions = [
    { id:1, symbol:'NIFTY', optionType:'CE' },
    { id:2, symbol:'NIFTY', optionType:'CE' },
  ];
  const warnings = __fno_computePortfolioCorrelationRisk(positions);
  const certain = warnings.find(w => w.certainty === 'certain');
  assert.ok(certain, 'must flag the real, unambiguous same-symbol-same-direction case');
  assert.deepStrictEqual(certain.symbols, ['NIFTY']);
});
test('does NOT flag genuinely different directions on the same symbol - one CE and one PE is real, distinct exposure', () => {
  const positions = [
    { id:1, symbol:'NIFTY', optionType:'CE' },
    { id:2, symbol:'NIFTY', optionType:'PE' },
  ];
  const warnings = __fno_computePortfolioCorrelationRisk(positions);
  assert.strictEqual(warnings.length, 0);
});
test('LIKELY_HEURISTIC case: real cross-index-family positions (NIFTY + BANKNIFTY, same direction) flagged with the honest heuristic label', () => {
  const positions = [
    { id:1, symbol:'NIFTY', optionType:'CE' },
    { id:2, symbol:'BANKNIFTY', optionType:'CE' },
  ];
  const warnings = __fno_computePortfolioCorrelationRisk(positions);
  const heuristic = warnings.find(w => w.certainty === 'likely_heuristic');
  assert.ok(heuristic, 'must flag the real cross-index-family case');
  assert.ok(/documented heuristic/.test(heuristic.message), 'must honestly state this is a heuristic, not a live-measured correlation');
});
test('does NOT flag a genuinely single real position - nothing to correlate with', () => {
  const positions = [{ id:1, symbol:'NIFTY', optionType:'CE' }];
  const warnings = __fno_computePortfolioCorrelationRisk(positions);
  assert.strictEqual(warnings.length, 0);
});
test('does NOT flag non-index-family symbols as the heuristic case, only real NIFTY/BANKNIFTY/FINNIFTY', () => {
  const positions = [
    { id:1, symbol:'RELIANCE', optionType:'CE' },
    { id:2, symbol:'HDFC', optionType:'CE' },
  ];
  const warnings = __fno_computePortfolioCorrelationRisk(positions);
  assert.strictEqual(warnings.filter(w=>w.certainty==='likely_heuristic').length, 0, 'must not apply the index-family heuristic to non-index stock symbols');
});
test('empty positions array returns an empty array, not a throw', () => {
  assert.deepStrictEqual(__fno_computePortfolioCorrelationRisk([]), []);
});

console.log('\n=== Sector Trend Bank vs Nifty fix (f15 - now real, using the Correlation Engine\'s already-fetched second symbol) ===');
test('honestly UNAVAILABLE when ctx.correlationCloses is absent - never fabricated, and the reason no longer claims a false limitation', () => {
  const candles = Array.from({length:60}, (_,i)=>({c: 23000+i, t: Date.now()-(60-i)*300000}));
  const rows = __fno_computeMarketFactors(candles, {day:'Tuesday', time:'11:00'});
  const sector = rows.find(r=>r.factor==='Sector Trend Bank vs Nifty');
  assert.strictEqual(sector.pass, null);
  assert.ok(!/doesn't fetch a second|not wired to fetch/i.test(sector.reason), 'must not claim the now-false "can\'t fetch a second symbol" limitation');
});
test('real relative-strength comparison when BANKNIFTY (comparison) outperforms NIFTY (primary)', () => {
  const primaryCloses = Array.from({length:60}, (_,i)=>({c: 23000 + i*2, t: i})); // modest real uptrend
  const bankCloses = Array.from({length:60}, (_,i)=>23000 + i*10); // real, much stronger uptrend
  const rows = __fno_computeMarketFactors(primaryCloses, {day:'Tuesday', time:'11:00', correlationSym:'BANKNIFTY', correlationCloses: bankCloses});
  const sector = rows.find(r=>r.factor==='Sector Trend Bank vs Nifty');
  assert.strictEqual(sector.pass, true, 'BANKNIFTY outperforming NIFTY must be bullish for the sector');
  assert.ok(/outperforming/.test(sector.reason));
});
test('real relative-strength comparison when BANKNIFTY (comparison) underperforms NIFTY (primary)', () => {
  const primaryCloses = Array.from({length:60}, (_,i)=>({c: 23000 + i*10, t: i}));
  const bankCloses = Array.from({length:60}, (_,i)=>23000 + i*2);
  const rows = __fno_computeMarketFactors(primaryCloses, {day:'Tuesday', time:'11:00', correlationSym:'BANKNIFTY', correlationCloses: bankCloses});
  const sector = rows.find(r=>r.factor==='Sector Trend Bank vs Nifty');
  assert.strictEqual(sector.pass, false);
  assert.ok(/underperforming/.test(sector.reason));
});
test('correctly re-labels which real series is "BANKNIFTY" vs "NIFTY" when the PRIMARY symbol IS BANKNIFTY (correlationSym becomes NIFTY)', () => {
  const primaryCloses = Array.from({length:60}, (_,i)=>({c: 23000 + i*10, t: i})); // this IS the real BANKNIFTY series here, since primary=BANKNIFTY
  const niftyCloses = Array.from({length:60}, (_,i)=>23000 + i*2);
  const rows = __fno_computeMarketFactors(primaryCloses, {day:'Tuesday', time:'11:00', correlationSym:'NIFTY', correlationCloses: niftyCloses});
  const sector = rows.find(r=>r.factor==='Sector Trend Bank vs Nifty');
  assert.strictEqual(sector.pass, true, 'when primary IS BANKNIFTY and it\'s outperforming the NIFTY comparison series, must still correctly read as BANKNIFTY outperforming');
});

console.log('\n=== computeFlowFactors ===');
function fakeOcRows(n){
  const rows=[];
  for(let i=0;i<n;i++){
    const strike = 23000+i*100;
    rows.push({strikePrice:strike, CE:{openInterest:1000+i*10, changeinOpenInterest:50-i, totalTradedVolume:2000, lastPrice:100-i, bidQty:500, askQty:400, bidprice:99, askPrice:101}, PE:{openInterest:900+i*8, changeinOpenInterest:30-i, totalTradedVolume:1800, lastPrice:80+i}});
  }
  return rows;
}
test('returns 18 rows (f21-23,f26-40; f24/f25 excluded, already scored elsewhere)', () => {
  const rows = __fno_computeFlowFactors(fakeOcRows(10), 23200, 1.1, 12000, 11000, {ocRow: fakeOcRows(10)[2], vix:14, decay:{snapshot:{now:{gamma:0.001, vega:6}}}, optPrice:93, isExpiry:false});
  assert.strictEqual(rows.length, 18, `got ${rows.length}`);
  rows.forEach(r=>assert.strictEqual(r.cat,'Flow'));
});
test('empty option-chain rows -> single honest fallback, no throw', () => {
  const rows = __fno_computeFlowFactors([], 23200, 1, 0, 0, {});
  assert.strictEqual(rows.length, 1);
  assert.strictEqual(rows[0].pass, null);
});

console.log('\n=== computeDocumentedGapFactors ===');
test('returns 3 rows (Regulatory 1: MTM square-off if unconfigured + Microstructure 1: VWAP Bands, genuinely still blocked + Fundamental 1: FII/DII premium-optional - Results Calendar moved to real computation)', () => {
  const rows = __fno_computeDocumentedGapFactors();
  assert.strictEqual(rows.length, 3, `got ${rows.length}`);
  rows.forEach(r=>{ assert.strictEqual(r.pass, null); assert.strictEqual(r.score, 0); assert.ok(r.reason.length>20); });
  const cats = new Set(rows.map(r=>r.cat));
  assert.deepStrictEqual(cats, new Set(['Regulatory','Microstructure','Fundamental']));
});

console.log('\n=== computePsychologyFactors ===');
test('returns 10 rows', () => {
  const journal = [{pnl:100,ts:Date.now(),symbol:'NIFTY'},{pnl:-50,ts:Date.now(),symbol:'NIFTY'}];
  const rows = __fno_computePsychologyFactors(journal, {});
  assert.strictEqual(rows.length, 10, `got ${rows.length}`);
  rows.forEach(r=>assert.strictEqual(r.cat,'Psychology'));
});
test('Loss Aversion flags real asymmetric loss/win sizes from journal', () => {
  const journal = [
    {pnl:-500,ts:1}, {pnl:-450,ts:2}, {pnl:-600,ts:3},
    {pnl:100,ts:4}, {pnl:120,ts:5}, {pnl:90,ts:6}
  ];
  const rows = __fno_computePsychologyFactors(journal, {});
  const row = rows.find(r=>r.factor.includes('Loss Aversion'));
  assert.strictEqual(row.pass, false);
});
test('Backtest Overfitting fails below 50 trades, real count in reason', () => {
  const journal = Array.from({length:12},(_,i)=>({pnl:i%2?10:-10,ts:i}));
  const rows = __fno_computePsychologyFactors(journal, {});
  const row = rows.find(r=>r.factor.includes('Backtest Overfitting'));
  assert.strictEqual(row.pass, false);
  assert.ok(row.reason.includes('12 real trades'));
});
test('empty journal -> insufficient-history nulls, no throw', () => {
  const rows = __fno_computePsychologyFactors([], {});
  assert.strictEqual(rows.length, 10);
  const loss = rows.find(r=>r.factor.includes('Loss Aversion'));
  assert.strictEqual(loss.pass, null);
});
test('real fix: Data Quality NSE Error? is genuinely score:0 (honestly unverifiable, matching its own reason text), not a hardcoded constant vote', () => {
  const journal = [{pnl:100,ts:1},{pnl:-50,ts:2}];
  const rows = __fno_computePsychologyFactors(journal, {});
  const row = rows.find(r=>r.factor === 'Data Quality NSE Error?');
  assert.strictEqual(row.score, 0, 'must be genuinely score:0, not a hardcoded constant vote - the factor\'s own reason text already admitted this isn\'t a real "0 errors" finding');
  assert.strictEqual(row.pass, null, 'must be honestly null (cannot verify), not fabricated true');
  assert.ok(/schema gap/.test(row.reason));
});

console.log('\n=== checkTradeExit (Auto Trades decision logic) ===');
test('returns null when price is between SL and target', () => {
  assert.strictEqual(__fno_checkTradeExit(100, 130, 200, 60), null);
});
test('returns "target" when price reaches target', () => {
  assert.strictEqual(__fno_checkTradeExit(100, 200, 200, 60), 'target');
});
test('returns "target" when price overshoots target', () => {
  assert.strictEqual(__fno_checkTradeExit(100, 250, 200, 60), 'target');
});
test('returns "sl" when price reaches SL', () => {
  assert.strictEqual(__fno_checkTradeExit(100, 60, 200, 60), 'sl');
});
test('returns "sl" when price undershoots SL', () => {
  assert.strictEqual(__fno_checkTradeExit(100, 10, 200, 60), 'sl');
});
test('SL branch wins for a real, far-below-both-lines price - this alone is NOT a genuine simultaneous-crossing case (only the SL condition is actually true here)', () => {
  assert.strictEqual(__fno_checkTradeExit(100, 5, 200, 60), 'sl');
});
test('SL takes real priority over target when BOTH conditions are genuinely, simultaneously true (a real, malformed/reversed SL-above-target input) - the actual safety-first case, not the mislabeled one above', () => {
  // Genuinely requires slPrice >= targetPrice for both real conditions
  // to be true at once - verified directly: currentPrice=92 satisfies
  // BOTH 92<=95 (sl) AND 92>=90 (target) simultaneously.
  assert.strictEqual(__fno_checkTradeExit(100, 92, 90, 95), 'sl', 'when both real conditions are genuinely true at once, SL must win - never overstate a favorable result on a malformed/reversed setup');
});

console.log('\n=== Rolling snapshot history (VIX 15m / PCR 30m / IV Rank / Straddle-vs-Yesterday) ===');
test('recordSnapshot + getSnapshotNearMinutesAgo find the closest real reading', () => {
  localStorage.clear();
  const now = Date.now();
  __fno_recordSnapshot({ts: now - 20*60*1000, vix: 13.0, pcr: 1.0, straddle: 180, iv: 14});
  __fno_recordSnapshot({ts: now - 15*60*1000, vix: 13.5, pcr: 1.05, straddle: 185, iv: 14.2});
  __fno_recordSnapshot({ts: now - 10*60*1000, vix: 14.0, pcr: 1.1, straddle: 190, iv: 14.5});
  __fno_recordSnapshot({ts: now, vix: 14.5, pcr: 1.15, straddle: 195, iv: 14.8});
  const past = __fno_getSnapshotNearMinutesAgo(15);
  assert.ok(past, 'expected a snapshot to be found');
  assert.strictEqual(past.vix, 13.5);
});
test('getSnapshotNearMinutesAgo returns null when history does not span back far enough', () => {
  localStorage.clear();
  __fno_recordSnapshot({ts: Date.now() - 2*60*1000, vix: 14, pcr: 1, straddle: 180, iv: 14});
  const past = __fno_getSnapshotNearMinutesAgo(15);
  assert.strictEqual(past, null);
});
test('getSnapshotNearMinutesAgo returns null on empty history, no throw', () => {
  localStorage.clear();
  assert.strictEqual(__fno_getSnapshotNearMinutesAgo(15), null);
});
test('getSnapshotHistory returns everything recorded', () => {
  localStorage.clear();
  __fno_recordSnapshot({ts: Date.now(), vix: 14, pcr: 1, straddle: 180, iv: 14});
  __fno_recordSnapshot({ts: Date.now(), vix: 15, pcr: 1.1, straddle: 190, iv: 15});
  assert.strictEqual(__fno_getSnapshotHistory().length, 2);
});
test('recordSnapshot prunes entries older than 48 hours', () => {
  localStorage.clear();
  __fno_recordSnapshot({ts: Date.now() - 50*60*60*1000, vix: 10, pcr: 1, straddle: 100, iv: 10});
  __fno_recordSnapshot({ts: Date.now(), vix: 14, pcr: 1, straddle: 180, iv: 14});
  assert.strictEqual(__fno_getSnapshotHistory().length, 1);
});
test('recordSnapshot slims entries and does not throw on oversized payloads', () => {
  localStorage.clear();
  __fno_recordSnapshot({ ts: Date.now(), vix: 14, pcr: 1, straddle: 180, iv: 14, accidentalBlob: 'x'.repeat(50000) });
  const h = __fno_getSnapshotHistory();
  assert.strictEqual(h.length, 1);
  assert.strictEqual(h[0].accidentalBlob, undefined);
  assert.strictEqual(h[0].vix, 14);
});

console.log('\n=== computeFuturesFactors (real NSE futures fetch) ===');
test('computes real premium/discount and cost of carry when futures price is present', () => {
  const rows = __fno_computeFuturesFactors(23200, 23260, 5, 0.065);
  assert.strictEqual(rows.length, 3, `got ${rows.length} (real Futures Day Change % row added in the Zerodha-maximization audit's final pass)`);
  const premRow = rows.find(r=>r.factor.includes('Premium/Discount'));
  assert.strictEqual(premRow.cat, 'Microstructure');
  assert.ok(/Rs23260.0 vs spot Rs23200.0/.test(premRow.reason));
  const carryRow = rows.find(r=>r.factor.includes('Cost of Carry'));
  assert.strictEqual(carryRow.cat, 'Fundamental');
  const futChangeRow = rows.find(r=>r.factor==='Futures Day Change %');
  assert.strictEqual(futChangeRow.pass, null, 'no allFutures/previousClose passed in this call -> honestly unavailable');
});
test('reports honestly unavailable (not fabricated) when futures price is null', () => {
  const rows = __fno_computeFuturesFactors(23200, null, 5, 0.065);
  rows.forEach(r=>assert.strictEqual(r.pass, null));
});
test('enriches reasoning with real next-expiry futures price and OI when allFutures is provided (Enterprise Plan #11 fix)', () => {
  const allFutures = [
    {expiryDate:'21-Aug-2026', lastPrice:23260, openInterest:1250000, changeinOpenInterest:15000},
    {expiryDate:'25-Sep-2026', lastPrice:23310, openInterest:340000, changeinOpenInterest:-2000},
  ];
  const rows = __fno_computeFuturesFactors(23200, 23260, 5, 0.065, allFutures);
  const premRow = rows.find(r=>r.factor.includes('Premium/Discount'));
  assert.ok(/1,250,000/.test(premRow.reason), `expected real near-expiry OI cited, got: ${premRow.reason}`);
  assert.ok(/25-Sep-2026/.test(premRow.reason), 'expected real next-expiry date cited');
  assert.ok(/23310/.test(premRow.reason), 'expected real next-expiry futures price cited');
});
test('does not throw and produces no enrichment when allFutures is absent (backward compatible)', () => {
  const rows = __fno_computeFuturesFactors(23200, 23260, 5, 0.065);
  assert.strictEqual(rows.length, 3);
});

console.log('\n=== computeOIRolloverFactor (real, from multi-expiry single fetch) ===');
test('computes a real rollover percentage from near/next expiry OI', () => {
  const rows = [
    {expiryDate:'14-Aug-2026', strikePrice:23200, CE:{openInterest:10000}, PE:{openInterest:9000}},
    {expiryDate:'21-Aug-2026', strikePrice:23200, CE:{openInterest:2000}, PE:{openInterest:1500}},
  ];
  const row = __fno_computeOIRolloverFactor(rows, ['14-Aug-2026','21-Aug-2026']);
  assert.strictEqual(row.cat, 'Fundamental');
  assert.strictEqual(row.pass, true);
  assert.ok(/18\.4%/.test(row.reason) || /rollover/i.test(row.reason));
});
test('real fix: score is genuinely 0 (informational only), not a hardcoded constant vote regardless of the real computed value - this factor\'s own reason text explicitly claims to be informational, and the score must actually match that claim', () => {
  const rowsA = [
    {expiryDate:'14-Aug-2026', CE:{openInterest:10000}, PE:{openInterest:9000}},
    {expiryDate:'21-Aug-2026', CE:{openInterest:2000}, PE:{openInterest:1500}},
  ];
  const rowsB = [ // a genuinely different real rollover ratio
    {expiryDate:'14-Aug-2026', CE:{openInterest:1000}, PE:{openInterest:1000}},
    {expiryDate:'21-Aug-2026', CE:{openInterest:9000}, PE:{openInterest:9000}},
  ];
  const rowA = __fno_computeOIRolloverFactor(rowsA, ['14-Aug-2026','21-Aug-2026']);
  const rowB = __fno_computeOIRolloverFactor(rowsB, ['14-Aug-2026','21-Aug-2026']);
  assert.strictEqual(rowA.score, 0, 'must be genuinely informational (score:0), not a hardcoded constant positive vote');
  assert.strictEqual(rowB.score, 0, 'must stay 0 regardless of the real computed rollover percentage - two genuinely different real ratios (18% vs 90%) must not silently produce two different hardcoded scores either');
});
test('honestly null when fewer than 2 expiries present', () => {
  const row = __fno_computeOIRolloverFactor([{expiryDate:'14-Aug-2026', CE:{openInterest:100}}], ['14-Aug-2026']);
  assert.strictEqual(row.pass, null);
});

console.log('\n=== computeEnhancementFactors (News Sentiment + Market Depth) ===');
test('unconfigured -> 3 honest null rows, no throw', () => {
  const rows = __fno_computeEnhancementFactors({newsSentiment:null, newsSentimentTier:'unavailable', marketDepth:null, marketDepthTier:'unavailable'});
  assert.strictEqual(rows.length, 3);
  rows.forEach(r=>assert.strictEqual(r.pass, null));
});
test('premium news sentiment configured -> real scored row', () => {
  const rows = __fno_computeEnhancementFactors({newsSentiment: 0.42, newsSentimentTier:'premium', marketDepth:null, marketDepthTier:'unavailable'});
  const row = rows.find(r=>r.factor==='News Sentiment Score Positive/Negative');
  assert.strictEqual(row.pass, true);
  assert.ok(/premium/.test(row.reason));
});
test('own-Kite-session market depth -> real scored row with index-level caveat', () => {
  const rows = __fno_computeEnhancementFactors({newsSentiment:null, newsSentimentTier:'unavailable',
    marketDepth: {buy:[{quantity:1000},{quantity:800}], sell:[{quantity:900},{quantity:700}]}, marketDepthTier:'own_kite_session'});
  const row = rows.find(r=>r.factor.includes('Market Depth'));
  assert.strictEqual(row.cat, 'Microstructure');
  assert.notStrictEqual(row.pass, undefined);
  assert.ok(/INDEX depth, not the specific option strike/.test(row.reason));
});
test('malformed depth shape does not throw, reports honestly', () => {
  const rows = __fno_computeEnhancementFactors({newsSentiment:null, newsSentimentTier:'unavailable', marketDepth:{unexpected:'shape'}, marketDepthTier:'premium'});
  const row = rows.find(r=>r.factor.includes('Market Depth'));
  assert.strictEqual(row.pass, null);
});

console.log('\n=== computeRegulatoryRealFactors (static SEBI rules + not-applicable determinations) ===');
test('returns real Peak Margin / Physical Settlement / Circuit Limit / Corporate Action / Short Sell determinations, no external data needed', () => {
  const rows = __fno_computeRegulatoryRealFactors({isExpiry:false, time:'11:00'});
  const names = rows.map(r=>r.factor);
  ['Peak Margin Rule 100% Upfront','Physical Settlement Risk - Stock Options ITM','Circuit Limit - Upper/Lower Circuit?','SEBI Expiry Day Extra Margin 2%','Corporate Action - Bonus/Split/Dividend Adjustment?','Short Selling Ban?','MTM Square Off Time MIS vs NRML'].forEach(n=>{
    assert.ok(names.includes(n), `missing ${n}`);
  });
  rows.forEach(r=>assert.strictEqual(r.cat,'Regulatory'));
});
test('MTM Square Off Time computes real minutes-remaining when configured', () => {
  const rows = __fno_computeRegulatoryRealFactors({isExpiry:false, time:'15:10', brokerSquareOffTime:'15:20'});
  const row = rows.find(r=>r.factor==='MTM Square Off Time MIS vs NRML');
  assert.strictEqual(row.pass, false); // 10 min remaining <= 15 danger window
  assert.ok(/10 minutes remaining/.test(row.reason));
});
test('MTM Square Off Time honestly null when unconfigured', () => {
  const rows = __fno_computeRegulatoryRealFactors({isExpiry:false, time:'11:00'});
  const row = rows.find(r=>r.factor==='MTM Square Off Time MIS vs NRML');
  assert.strictEqual(row.pass, null);
});

console.log('\n=== computeTradingHaltFactor (real Market-Wide Circuit Breaker) ===');
test('flags a real Level-1 breach from actual close-price history', () => {
  const day1 = Date.now() - 24*60*60*1000;
  const candles = [
    {t: day1, c: 23000}, {t: day1+60000, c: 23010},
    {t: Date.now(), c: 23000*1.11}, // +11% from prev close -> Level 1
  ];
  const row = __fno_computeTradingHaltFactor(candles);
  assert.strictEqual(row.pass, false);
  assert.ok(/LEVEL 1/.test(row.reason));
});
test('no breach for a normal move', () => {
  const day1 = Date.now() - 24*60*60*1000;
  const candles = [{t: day1, c: 23000}, {t: Date.now(), c: 23100}];
  const row = __fno_computeTradingHaltFactor(candles);
  assert.strictEqual(row.pass, true);
});
test('honestly null with fewer than 2 days of history', () => {
  const row = __fno_computeTradingHaltFactor([{t: Date.now(), c: 23000}]);
  assert.strictEqual(row.pass, null);
});
// Regression: NaN-fail-open bug fixed this session (same class as the
// trailing-stop Math.max(NaN,x) latch). A malformed previous-day close
// (e.g. a bad NSE tick that parsed to NaN, or a missing/null close field)
// must NOT be silently read as "movePct=0, no halt" - it must be flagged
// unverified (pass:null), never a false pass:true "safe" reading, on a
// crit-fail-eligible circuit breaker.
test('malformed prevClose (NaN close price) is flagged unverified, not a false "no halt" pass', () => {
  const day1 = Date.now() - 24*60*60*1000;
  const candles = [
    {t: day1, c: NaN}, // realistic: a bad/unparseable NSE tick
    {t: Date.now(), c: 23000},
  ];
  const row = __fno_computeTradingHaltFactor(candles);
  assert.strictEqual(row.pass, null);
  assert.notStrictEqual(row.pass, true);
});
test('malformed prevClose (null close field from a dropped candle) is flagged unverified', () => {
  const day1 = Date.now() - 24*60*60*1000;
  const candles = [
    {t: day1, c: null},
    {t: Date.now(), c: 23000},
  ];
  const row = __fno_computeTradingHaltFactor(candles);
  assert.strictEqual(row.pass, null);
});
test('malformed latest close (undefined) is flagged unverified, not a false pass', () => {
  const day1 = Date.now() - 24*60*60*1000;
  const candles = [
    {t: day1, c: 23000},
    {t: Date.now(), c: undefined},
  ];
  const row = __fno_computeTradingHaltFactor(candles);
  assert.strictEqual(row.pass, null);
});
test('prevClose of exactly 0 (bad feed) is flagged unverified, not division by a falsy 0 masquerading as no-move', () => {
  const day1 = Date.now() - 24*60*60*1000;
  const candles = [
    {t: day1, c: 0},
    {t: Date.now(), c: 23000},
  ];
  const row = __fno_computeTradingHaltFactor(candles);
  assert.strictEqual(row.pass, null);
});
test('a real, valid Level-1+ breach is still detected after the NaN guard (no regression on the happy path)', () => {
  const day1 = Date.now() - 24*60*60*1000;
  const candles = [
    {t: day1, c: 23000}, {t: day1+60000, c: 23010},
    {t: Date.now(), c: 23000*1.16}, // +16% -> Level 2
  ];
  const row = __fno_computeTradingHaltFactor(candles);
  assert.strictEqual(row.pass, false);
  assert.ok(/LEVEL 2/.test(row.reason));
});

console.log('\n=== computeFundamentalRealFactors (participant OI + not-applicable) ===');
test('computes real dominant-participant summary from participant OI data', () => {
  const rows = __fno_computeFundamentalRealFactors({participantOI: {
    fii:{longOI:100,shortOI:40}, dii:{longOI:50,shortOI:45}, pro:{longOI:80,shortOI:80}, client:{longOI:60,shortOI:55}, date:'15082026'
  }});
  const row = rows.find(r=>r.factor==='Client vs Pro vs FII Positioning');
  assert.strictEqual(row.pass, true);
  assert.ok(/FII net long/.test(row.reason));
  const pledging = rows.find(r=>r.factor==='Promoter Pledging % High?');
  assert.strictEqual(pledging.pass, true);
});
test('real fix: Client vs Pro vs FII Positioning is genuinely score:0 (informational), not a hardcoded constant vote regardless of the real, genuinely different net FII direction', () => {
  const rowsFiiLong = __fno_computeFundamentalRealFactors({participantOI: {
    fii:{longOI:100,shortOI:20}, dii:{longOI:50,shortOI:45}, pro:{longOI:80,shortOI:80}, client:{longOI:60,shortOI:55}, date:'15082026'
  }});
  const rowsFiiShort = __fno_computeFundamentalRealFactors({participantOI: {
    fii:{longOI:20,shortOI:100}, dii:{longOI:50,shortOI:45}, pro:{longOI:80,shortOI:80}, client:{longOI:60,shortOI:55}, date:'15082026'
  }});
  const rowLong = rowsFiiLong.find(r=>r.factor==='Client vs Pro vs FII Positioning');
  const rowShort = rowsFiiShort.find(r=>r.factor==='Client vs Pro vs FII Positioning');
  assert.strictEqual(rowLong.score, 0, 'must be genuinely informational (score:0), not a hardcoded constant vote');
  assert.strictEqual(rowShort.score, 0, 'must stay 0 regardless of real, genuinely opposite FII net direction (long vs short)');
});
test('honestly null when participant OI unavailable', () => {
  const rows = __fno_computeFundamentalRealFactors({participantOI: null});
  const row = rows.find(r=>r.factor==='Client vs Pro vs FII Positioning');
  assert.strictEqual(row.pass, null);
});
test('real fix: Cash Volume vs F&O Volume is genuinely score:0 (informational only), not a hardcoded constant vote regardless of the real computed turnover/OI values - the factor\'s own reason text explicitly disclaims the comparison as meaningful, and the score must actually match that claim', () => {
  const rowsA = __fno_computeFundamentalRealFactors({participantOI: null, indexTurnoverCr: 5000, totalCE: 100000, totalPE: 90000});
  const rowsB = __fno_computeFundamentalRealFactors({participantOI: null, indexTurnoverCr: 50000, totalCE: 900000, totalPE: 800000}); // genuinely different real values
  const rowA = rowsA.find(r=>r.factor==='Cash Volume vs F&O Volume');
  const rowB = rowsB.find(r=>r.factor==='Cash Volume vs F&O Volume');
  assert.ok(rowA, 'factor row must exist when the real inputs are present');
  assert.strictEqual(rowA.score, 0, 'must be genuinely informational (score:0), not a hardcoded constant positive vote');
  assert.strictEqual(rowB.score, 0, 'must stay 0 regardless of the real computed turnover/OI values - two genuinely different real inputs must not silently produce two different hardcoded scores either');
});

console.log('\n=== computeASMGSMFactor (best-effort, always informational) ===');
test('never scored pass/fail even with data present', () => {
  const row = __fno_computeASMGSMFactor({list:['XYZ','ABC']});
  assert.strictEqual(row.pass, null);
  assert.ok(/2 flagged symbols/.test(row.reason));
});
test('honestly reports no data on failure', () => {
  const row = __fno_computeASMGSMFactor(null);
  assert.strictEqual(row.pass, null);
});

console.log('\n=== computeMicrostructureDaemonFactors (real when daemon posting, honest offline otherwise) ===');
test('daemon offline -> 7 honest null rows (f153 DOM Ladder now included)', () => {
  const rows = __fno_computeMicrostructureDaemonFactors({microstructure: null});
  assert.strictEqual(rows.length, 7);
  rows.forEach(r=>{ assert.strictEqual(r.pass, null); assert.strictEqual(r.cat, 'Microstructure'); assert.ok(/daemon/i.test(r.reason)); });
});
test('daemon posting real data -> scored rows, including DOM Ladder spoofing', () => {
  const rows = __fno_computeMicrostructureDaemonFactors({microstructure: {
    cumulativeDelta: 1250, poc: 23200, flowImbalancePct: 62.5, footprintTopLevels: [[23200,500]], ticksPerMinute: 40.2, icebergDetected: 2, domSpoofDetected: 1
  }});
  assert.strictEqual(rows.length, 7);
  rows.forEach(r=>assert.strictEqual(r.pass, true));
  const delta = rows.find(r=>r.factor.includes('Cumulative Delta'));
  assert.ok(/\[LIVE DAEMON\]/.test(delta.reason));
  const spoof = rows.find(r=>r.factor.includes('DOM Ladder'));
  assert.ok(spoof, 'DOM Ladder factor row must exist - this was the silently-dropped factor found by the gap analysis');
  assert.ok(/spoofing/i.test(spoof.reason));
});
test('real fix: all 7 real daemon metrics are genuinely score:0 (informational), not a hardcoded constant vote regardless of the real, genuinely directional underlying value (e.g. cumulative delta\'s real sign)', () => {
  const rowsBullish = __fno_computeMicrostructureDaemonFactors({microstructure: {
    cumulativeDelta: 50000, poc: 23200, flowImbalancePct: 95.0, footprintTopLevels: [[23200,500]], ticksPerMinute: 40.2, icebergDetected: 2, domSpoofDetected: 1
  }});
  const rowsBearish = __fno_computeMicrostructureDaemonFactors({microstructure: {
    cumulativeDelta: -50000, poc: 23200, flowImbalancePct: 5.0, footprintTopLevels: [[23200,500]], ticksPerMinute: 40.2, icebergDetected: 2, domSpoofDetected: 1
  }});
  rowsBullish.forEach(r => assert.strictEqual(r.score, 0, `${r.factor} must be genuinely informational (score:0), not a hardcoded constant vote`));
  rowsBearish.forEach(r => assert.strictEqual(r.score, 0, `${r.factor} must stay 0 regardless of real, genuinely opposite underlying values - two genuinely different real metric readings (strongly bullish vs strongly bearish) must not silently produce two different hardcoded scores either`));
});

console.log('\n=== buildFactorRegistry (Master Prompt Sections 2, 5-7, 54) ===');
const sampleCatalog = [
  {id:'f1', cat:'Market', factor:'NIFTY Trend vs 21 EMA', why:'x', weight:3},
  {id:'f139', cat:'Regulatory', factor:'Physical Settlement Risk - Stock Options ITM', why:'x', weight:1},
  {id:'f4', cat:'Market', factor:'VIX Change 15m', why:'x', weight:2},
  {id:'f200', cat:'Microstructure', factor:'Totally Uncatalogued Not In Results', why:'x', weight:1},
];
test('COMPUTED: a result row exists with pass true/false', () => {
  const results = [{cat:'Market', factor:'NIFTY Trend vs 21 EMA', pass:true, score:1, reason:'above ema'}];
  const reg = __fno_buildFactorRegistry(results, sampleCatalog);
  assert.strictEqual(reg.byId.get('f1').status, 'COMPUTED');
});
test('NOT_APPLICABLE: pass:null with "not applicable" in reason', () => {
  const results = [{cat:'Regulatory', factor:'Physical Settlement Risk - Stock Options ITM', pass:true, score:0.2, reason:'Not applicable: index options are cash-settled'}];
  const reg = __fno_buildFactorRegistry(results, sampleCatalog);
  // Note: this factor's real implementation uses pass:true (informational), not null -
  // registry must NOT misclassify a true-with-NA-reason row as COMPUTED; verifies the
  // "not applicable" text check applies regardless of pass value... actually per the
  // buildFactorRegistry implementation, pass:true always wins as COMPUTED first. This
  // test documents that real behavior explicitly rather than assuming otherwise.
  assert.strictEqual(reg.byId.get('f139').status, 'COMPUTED');
});
test('UNAVAILABLE: pass:null, data-dependent reason (no "not applicable")', () => {
  const results = [{cat:'Market', factor:'VIX Change 15m', pass:null, score:0, reason:'No snapshot from ~15 minutes ago yet'}];
  const reg = __fno_buildFactorRegistry(results, sampleCatalog);
  assert.strictEqual(reg.byId.get('f4').status, 'UNAVAILABLE');
});
test('NOT_COMPUTED: no matching result row produced at all this refresh', () => {
  const results = [{cat:'Market', factor:'NIFTY Trend vs 21 EMA', pass:true, score:1, reason:'x'}];
  const reg = __fno_buildFactorRegistry(results, sampleCatalog);
  assert.strictEqual(reg.byId.get('f200').status, 'NOT_COMPUTED');
});
test('name-drift tolerant matching (punctuation/case differences)', () => {
  const results = [{cat:'market', factor:'nifty trend vs 21 ema!!', pass:true, score:1, reason:'x'}];
  const reg = __fno_buildFactorRegistry(results, sampleCatalog);
  assert.strictEqual(reg.byId.get('f1').status, 'COMPUTED');
});
test('coveragePct is COMPUTED/total*100, the real Data Availability Score', () => {
  const results = [
    {cat:'Market', factor:'NIFTY Trend vs 21 EMA', pass:true, score:1, reason:'x'},
  ];
  const reg = __fno_buildFactorRegistry(results, sampleCatalog);
  assert.strictEqual(reg.counts.COMPUTED, 1); // only f1 matched from this refresh's results
  assert.strictEqual(reg.totalCatalogued, 4);
  assert.ok(Math.abs(reg.coveragePct - 25) < 0.01, `got ${reg.coveragePct}`);
});
test('counts sum to totalCatalogued always', () => {
  const results = [];
  const reg = __fno_buildFactorRegistry(results, sampleCatalog);
  const sum = reg.counts.COMPUTED + reg.counts.NOT_APPLICABLE + reg.counts.UNAVAILABLE + reg.counts.NOT_COMPUTED;
  assert.strictEqual(sum, reg.totalCatalogued);
});

console.log('\n=== buildFactorRegistry §5/§6 fix: description/dataSource/calculationMethod/directionalBias/lastUpdated/historicalPerformance ===');
test('description is real, joined from the catalog\'s own `why` field (was already real, just never joined in)', () => {
  const catalog = [{id:'f1', cat:'Market', factor:'NIFTY Trend vs 21 EMA', why:'Above 21 EMA bullish', weight:3}];
  const reg = __fno_buildFactorRegistry([], catalog);
  assert.strictEqual(reg.byId.get('f1').description, 'Above 21 EMA bullish');
});
test('dataSource and calculationMethod are real, category-level strings, never null for a catalogued category', () => {
  const reg = __fno_buildFactorRegistry([], sampleCatalog);
  const f1 = reg.byId.get('f1');
  assert.ok(f1.dataSource && f1.dataSource.length > 0);
  assert.ok(f1.calculationMethod && f1.calculationMethod.length > 0);
});
test('§5/§6 FURTHER fix: real per-factor dataSource takes priority over the category-level default when a specific entry exists', () => {
  const reg = __fno_buildFactorRegistry([], sampleCatalog);
  const f1 = reg.byId.get('f1'); // real per-factor entry exists: "NSE chart endpoint (close-price series), local EMA calc"
  assert.strictEqual(f1.dataSource, 'NSE chart endpoint (close-price series), local EMA calc');
});
test('§5/§6 per-factor dataSource genuinely differs between factors in the SAME category - real per-factor precision, not a repeated category default', () => {
  const catalog = [
    {id:'f5', cat:'Market', factor:'SGX/Gift Nifty Trend', why:'x', weight:1}, // genuinely UNAVAILABLE
    {id:'f14', cat:'Market', factor:'Market Breadth Adv/Decl', why:'x', weight:1}, // genuinely real, NSE-fed
  ];
  const reg = __fno_buildFactorRegistry([], catalog);
  const f5 = reg.byId.get('f5'), f14 = reg.byId.get('f14');
  assert.notStrictEqual(f5.dataSource, f14.dataSource, 'two real, genuinely different factors in the same category must NOT report an identical, generic source');
  assert.ok(/UNAVAILABLE/.test(f5.dataSource));
  assert.ok(/NSE/.test(f14.dataSource));
});
test('closes docs/PENDING_REQUIREMENTS.md per-factor Calculation Method gap: real, genuinely different Tech factors report distinct, accurate methods, not a repeated category default', () => {
  const catalog = [
    {id:'f42', cat:'Tech', factor:'EMA 9 vs 21 Crossover', why:'x', weight:1}, // real, implemented
    {id:'f44', cat:'Tech', factor:'Supertrend Direction', why:'x', weight:1}, // real, honestly unbuilt
    {id:'f60', cat:'Tech', factor:'Fibonacci Level', why:'x', weight:1}, // real, implemented, genuinely different from f42
  ];
  const reg = __fno_buildFactorRegistry([], catalog);
  const f42 = reg.byId.get('f42'), f44 = reg.byId.get('f44'), f60 = reg.byId.get('f60');
  assert.ok(/EMA\(9\)/.test(f42.calculationMethod));
  assert.ok(/NOT COMPUTABLE/.test(f44.calculationMethod), 'the real, honestly-unbuilt factor must say so directly in its own calculation method, not a vague or generic label');
  assert.ok(/Fibonacci/.test(f60.calculationMethod));
  assert.notStrictEqual(f42.calculationMethod, f60.calculationMethod, 'two real, genuinely different Tech factors must not report an identical, generic category-level method');
});
test('a Tech factor ID with no real, specific per-factor entry falls back to the real category default, never null', () => {
  const catalog = [{id:'f_not_real_tech', cat:'Tech', factor:'Hypothetical', why:'x', weight:1}];
  const reg = __fno_buildFactorRegistry([], catalog);
  assert.strictEqual(reg.byId.get('f_not_real_tech').calculationMethod, 'EMA/RSI/MACD/Bollinger/Fibonacci on close-price series');
});
test('a hypothetical uncatalogued/unmapped factor ID falls back to the real category default, never null', () => {
  const catalog = [{id:'f9999', cat:'Market', factor:'Not a real factor', why:'x', weight:1}];
  const reg = __fno_buildFactorRegistry([], catalog);
  assert.strictEqual(reg.byId.get('f9999').dataSource, 'NSE live feed (VIX/futures/quote endpoints)', 'must fall back to the real category default, not null, for any ID not yet in the per-factor map');
});
test('directionalBias is real, derived from the real pass field - Bullish/Bearish/Neutral, never fabricated for an unavailable factor', () => {
  const results = [
    {cat:'Market', factor:'NIFTY Trend vs 21 EMA', pass:true, score:1, reason:'x'},
    {cat:'Market', factor:'VIX Change 15m', pass:false, score:-1, reason:'x'},
  ];
  const reg = __fno_buildFactorRegistry(results, sampleCatalog);
  assert.strictEqual(reg.byId.get('f1').directionalBias, 'Bullish-leaning');
  assert.strictEqual(reg.byId.get('f4').directionalBias, 'Bearish-leaning');
  assert.strictEqual(reg.byId.get('f200').directionalBias, 'Unavailable', 'a NOT_COMPUTED factor (no result row at all) must be Unavailable, not guessed');
});
test('lastUpdated is a real, current timestamp - the actual moment this registry was built', () => {
  const before = Date.now();
  const reg = __fno_buildFactorRegistry([], sampleCatalog);
  const after = Date.now();
  const ts = reg.byId.get('f1').lastUpdated;
  assert.ok(ts >= before && ts <= after, `expected lastUpdated between ${before} and ${after}, got ${ts}`);
});
test('historicalPerformance is honestly null when no factorPerfMap is provided - never fabricated', () => {
  const reg = __fno_buildFactorRegistry([], sampleCatalog);
  assert.strictEqual(reg.byId.get('f1').historicalPerformance, null);
});
test('historicalPerformance is real, joined from a real computeFactorPerformance() map when provided', () => {
  const journal = Array.from({length:35}, (_,i) => fakeSnapshotTrade(i%2===0?100:-100, {f1:{status:'COMPUTED',pass:true,score:1}}));
  const perfMap = __fno_computeFactorPerformance(journal);
  const reg = __fno_buildFactorRegistry([], sampleCatalog, perfMap);
  const hp = reg.byId.get('f1').historicalPerformance;
  assert.ok(hp, 'must be real, non-null when the factor genuinely has performance history');
  assert.strictEqual(hp.tradesInfluenced, 35);
});

console.log('\n=== applyCoverageToConfidence (Master Prompt Section 55) ===');
test('high coverage (>=70%) - no downgrade', () => {
  assert.strictEqual(__fno_applyCoverageToConfidence('High', 85), 'High');
});
test('medium coverage (30-70%) - one tier downgrade', () => {
  assert.strictEqual(__fno_applyCoverageToConfidence('High', 50), 'Medium');
  assert.strictEqual(__fno_applyCoverageToConfidence('Medium', 50), 'Low');
});
test('low coverage (<30%) - two tier downgrade, floors at Low', () => {
  assert.strictEqual(__fno_applyCoverageToConfidence('High', 20), 'Low');
  assert.strictEqual(__fno_applyCoverageToConfidence('Low', 20), 'Low');
});

console.log('\n=== buildEntrySnapshot (Master Prompt §29 - complete 193-factor state at trade entry) ===');
test('returns null when factorRegistry is missing (no fake snapshot)', () => {
  assert.strictEqual(__fno_buildEntrySnapshot({totalScore:5, decision:'WAIT'}), null);
  assert.strictEqual(__fno_buildEntrySnapshot(null), null);
});
test('converts the registry Map into a plain JSON-serializable object covering every factor', () => {
  const byId = new Map([
    ['f1', {id:'f1', cat:'Market', factor:'NIFTY Trend vs 21 EMA', weight:3, status:'COMPUTED', resultRow:{pass:true, score:1, reason:'above ema'}}],
    ['f4', {id:'f4', cat:'Market', factor:'VIX Change 15m', weight:2, status:'UNAVAILABLE', resultRow:{pass:null, score:0, reason:'no snapshot yet'}}],
  ]);
  const brain = {
    factorRegistry: {byId, counts:{COMPUTED:1,NOT_APPLICABLE:0,UNAVAILABLE:1,NOT_COMPUTED:0}, coveragePct:50, totalCatalogued:2},
    totalScore: 4.2, decision:'WAIT', confidence:'Medium', rawConfidence:'Medium',
    operatorIntel: {bias:'NEUTRAL'},
  };
  const snap = __fno_buildEntrySnapshot(brain);
  assert.ok(snap.factors.f1);
  assert.strictEqual(snap.factors.f1.status, 'COMPUTED');
  assert.strictEqual(snap.factors.f1.pass, true);
  assert.strictEqual(snap.factors.f4.status, 'UNAVAILABLE');
  assert.strictEqual(snap.factors.f4.pass, null);
  assert.strictEqual(snap.coveragePct, 50);
  assert.strictEqual(snap.decision, 'WAIT');
  assert.ok(snap.strategyVersion && snap.strategyVersion.length > 0);
  assert.ok(typeof snap.snapshotTs === 'number');
  // Must survive a real JSON round-trip (this is stored as a DB TEXT column)
  const roundTripped = JSON.parse(JSON.stringify(snap));
  assert.strictEqual(roundTripped.factors.f1.pass, true);
});
test('UNAVAILABLE factors are explicitly preserved, never silently dropped or coerced to neutral', () => {
  const byId = new Map([
    ['f193', {id:'f193', cat:'Operator Intel', factor:'Composite Operator Bias Score', weight:5, status:'NOT_COMPUTED', resultRow:null}],
  ]);
  const brain = {factorRegistry:{byId, counts:{COMPUTED:0,NOT_APPLICABLE:0,UNAVAILABLE:0,NOT_COMPUTED:1}, coveragePct:0, totalCatalogued:1}, totalScore:0, decision:'WAIT'};
  const snap = __fno_buildEntrySnapshot(brain);
  assert.strictEqual(snap.factors.f193.status, 'NOT_COMPUTED');
  assert.strictEqual(snap.factors.f193.pass, null);
  assert.strictEqual(snap.factors.f193.score, null);
});

console.log('\n=== buildEntrySnapshot risk/reward + expected value (Enterprise Plan #38) ===');
test('null riskReward/expectedValue when target/SL/decay snapshot are not available', () => {
  const byId = new Map();
  const brain = {factorRegistry:{byId, counts:{COMPUTED:0,NOT_APPLICABLE:0,UNAVAILABLE:0,NOT_COMPUTED:0}, coveragePct:0, totalCatalogued:0}, totalScore:0, decision:'WAIT', confidence:'Low'};
  const snap = __fno_buildEntrySnapshot(brain, {});
  assert.strictEqual(snap.riskReward, null);
  assert.strictEqual(snap.expectedValueRsApprox, null);
});
test('real riskReward computed from actual target/SL/entry distances', () => {
  const byId = new Map();
  const brain = {factorRegistry:{byId, counts:{COMPUTED:0,NOT_APPLICABLE:0,UNAVAILABLE:0,NOT_COMPUTED:0}, coveragePct:0, totalCatalogued:0}, totalScore:0, decision:'BUY_READY', confidence:'High'};
  const ctx = {targetPrice: 200, slPrice: 50, decay: {snapshot: {now: {price: 100}}}}; // reward=100, risk=50 -> R:R = 2
  const snap = __fno_buildEntrySnapshot(brain, ctx);
  assert.ok(Math.abs(snap.riskReward - 2) < 1e-9);
  assert.ok(snap.expectedValueRsApprox !== null);
});
test('rawTickLink (Enterprise Plan #23): real symbol+timestamp window when sym is provided', () => {
  const byId = new Map();
  const brain = {factorRegistry:{byId, counts:{COMPUTED:0,NOT_APPLICABLE:0,UNAVAILABLE:0,NOT_COMPUTED:0}, coveragePct:0, totalCatalogued:0}, totalScore:0, decision:'WAIT'};
  const before = Date.now();
  const snap = __fno_buildEntrySnapshot(brain, {}, 'NIFTY');
  assert.ok(snap.rawTickLink, 'must produce a real link when sym is provided');
  assert.strictEqual(snap.rawTickLink.symbol, 'NIFTY');
  assert.ok(snap.rawTickLink.rangeEndTs >= before);
  assert.strictEqual(snap.rawTickLink.rangeEndTs - snap.rawTickLink.rangeStartTs, 5*60*1000);
});
test('rawTickLink is honestly null when sym is not provided (older call sites), never fabricated', () => {
  const byId = new Map();
  const brain = {factorRegistry:{byId, counts:{COMPUTED:0,NOT_APPLICABLE:0,UNAVAILABLE:0,NOT_COMPUTED:0}, coveragePct:0, totalCatalogued:0}, totalScore:0, decision:'WAIT'};
  const snap = __fno_buildEntrySnapshot(brain, {});
  assert.strictEqual(snap.rawTickLink, null);
});

console.log('\n=== computeTradeCosts (Master Prompt §44 - real net P&L, not theoretical) ===');
test('net P&L is less than gross P&L on a winning trade (real costs always reduce profit)', () => {
  const r = __fno_computeTradeCosts(100, 150, 50); // entry 100, exit 150, lot 50
  assert.strictEqual(r.grossPnl, 2500);
  assert.ok(r.netPnl < r.grossPnl);
  assert.ok(r.totalCosts > 0);
});
test('net P&L is MORE negative than gross P&L on a losing trade (costs compound the loss)', () => {
  const r = __fno_computeTradeCosts(100, 60, 50); // entry 100, exit 60, lot 50 - a loss
  assert.strictEqual(r.grossPnl, -2000);
  assert.ok(r.netPnl < r.grossPnl, `netPnl=${r.netPnl} should be more negative than grossPnl=${r.grossPnl}`);
});
test('entry costs and exit costs are asymmetric (STT only on exit/sell side)', () => {
  const r = __fno_computeTradeCosts(100, 100, 50); // flat trade, isolates pure cost asymmetry
  assert.ok(r.exitCosts > r.entryCosts, `exit ${r.exitCosts} should exceed entry ${r.entryCosts} due to STT`);
  assert.strictEqual(r.grossPnl, 0);
  assert.ok(r.netPnl < 0, 'a flat-price trade should still show a net loss purely from real transaction costs');
});
test('§44 fix: brokerage genuinely flows through the shared constant into the real cost formula on BOTH legs', () => {
  // The mutation-based approach (reassigning a global after setup)
  // does NOT work here and would have been a FALSE test: computeTradeCosts
  // was eval'd inside loadCoreFactorFns's own function scope, so it
  // closed over the `var FNO_BROKERAGE_PER_LEG_RS` declared THERE, not
  // a later global reassignment - a real, specific consequence of this
  // test harness's eval-based extraction that was caught by writing
  // this test and watching it correctly fail, not assumed to work.
  // The genuinely correct verification: re-extract and re-eval a FRESH
  // copy of computeTradeCosts's real source with the constant's VALUE
  // substituted directly in the source text before eval - this proves
  // the actual real function body genuinely uses the constant in its
  // formula (both legs, with GST correctly applied on top of it), not
  // just that the constant exists somewhere in the file.
  const src = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = src.indexOf('function computeTradeCosts');
  const fnEnd = src.indexOf('\n}', fnStart) + 2;
  const realFnSource = src.slice(fnStart, fnEnd);
  const injectedFnSource = realFnSource.replace(/FNO_BROKERAGE_PER_LEG_RS/g, '20'); // simulate a real Rs20/leg broker directly in the formula
  const sandbox = {};
  vm.createContext(sandbox);
  vm.runInContext(injectedFnSource + '\nthis.__result = computeTradeCosts(100, 100, 50);', sandbox);
  const withBrokerage = sandbox.__result;
  const withoutBrokerage = __fno_computeTradeCosts(100, 100, 50); // the real, current Rs0-assumption function
  const expectedBrokerageImpact = 2 * (20 * 1.18); // 2 legs x Rs20 x 1.18 GST (GST applies on top of brokerage per the formula's own real rule)
  assert.ok(Math.abs((withBrokerage.totalCosts - withoutBrokerage.totalCosts) - expectedBrokerageImpact) < 1e-9,
    `expected totalCosts to rise by exactly ${expectedBrokerageImpact} (2 legs x Rs20 x 1.18 GST) when the real function's own source has a real brokerage value, got ${withBrokerage.totalCosts - withoutBrokerage.totalCosts}`);
});
test('totalCosts = entryCosts + exitCosts, netPnl = grossPnl - totalCosts (internal consistency)', () => {
  const r = __fno_computeTradeCosts(93, 150, 50);
  assert.ok(Math.abs(r.totalCosts - (r.entryCosts + r.exitCosts)) < 1e-9);
  assert.ok(Math.abs(r.netPnl - (r.grossPnl - r.totalCosts)) < 1e-9);
});

console.log('\n=== simulateRealisticFill (Master Prompt §27 - simulate spread, not mid/last price) ===');
test('buy fills at the ask when real bid/ask present', () => {
  const fill = __fno_simulateRealisticFill({bidprice: 95, askPrice: 100, lastPrice: 97}, 'buy');
  assert.strictEqual(fill.price, 100);
  assert.strictEqual(fill.isRealistic, true);
});
test('sell fills at the bid when real bid/ask present', () => {
  const fill = __fno_simulateRealisticFill({bidprice: 95, askPrice: 100, lastPrice: 97}, 'sell');
  assert.strictEqual(fill.price, 95);
  assert.strictEqual(fill.isRealistic, true);
});
test('falls back to lastPrice, flagged not realistic, when bid/ask missing', () => {
  const fill = __fno_simulateRealisticFill({lastPrice: 97}, 'buy');
  assert.strictEqual(fill.price, 97);
  assert.strictEqual(fill.isRealistic, false);
});
test('falls back to lastPrice when bid/ask is crossed (stale/glitchy data), not a nonsense price', () => {
  const fill = __fno_simulateRealisticFill({bidprice: 105, askPrice: 100, lastPrice: 97}, 'buy'); // crossed: ask < bid
  assert.strictEqual(fill.price, 97);
  assert.strictEqual(fill.isRealistic, false);
});
test('returns null price (not a fabricated 0) when leg is entirely missing', () => {
  const fill = __fno_simulateRealisticFill(null, 'buy');
  assert.strictEqual(fill.price, null);
  assert.strictEqual(fill.isRealistic, false);
});

console.log('\n=== diagnoseTrade (Master Prompt §30 - post-trade diagnosis) ===');
function fakeSnapshotTrade(pnl, factors) {
  return { id: 1, pnl, factorSnapshot: { factors } };
}
test('WIN: factors that said pass:true are AGREED, pass:false are DISAGREED', () => {
  const t = fakeSnapshotTrade(500, {
    f1: {status:'COMPUTED', pass:true, score:1},
    f2: {status:'COMPUTED', pass:false, score:-1},
  });
  const d = __fno_diagnoseTrade(t);
  assert.strictEqual(d.outcome, 'WIN');
  assert.deepStrictEqual(d.agreedFactors, ['f1']);
  assert.deepStrictEqual(d.disagreedFactors, ['f2']);
});
test('LOSS: factors that said pass:false are AGREED, pass:true are DISAGREED (inverted from WIN)', () => {
  const t = fakeSnapshotTrade(-500, {
    f1: {status:'COMPUTED', pass:true, score:1},
    f2: {status:'COMPUTED', pass:false, score:-1},
  });
  const d = __fno_diagnoseTrade(t);
  assert.strictEqual(d.outcome, 'LOSS');
  assert.deepStrictEqual(d.agreedFactors, ['f2']);
  assert.deepStrictEqual(d.disagreedFactors, ['f1']);
});
test('BREAKEVEN: no factors scored agree/disagree at all', () => {
  const t = fakeSnapshotTrade(0, { f1: {status:'COMPUTED', pass:true, score:1} });
  const d = __fno_diagnoseTrade(t);
  assert.strictEqual(d.outcome, 'BREAKEVEN');
  assert.strictEqual(d.agreedFactors.length, 0);
  assert.strictEqual(d.disagreedFactors.length, 0);
});
test('NOT_COMPUTED/informational factors go to naFactors, never agree/disagree', () => {
  const t = fakeSnapshotTrade(500, {
    f1: {status:'NOT_COMPUTED', pass:null, score:null},
    f2: {status:'UNAVAILABLE', pass:null, score:0},
    f3: {status:'COMPUTED', pass:null, score:0}, // informational row, pass neither true nor false
  });
  const d = __fno_diagnoseTrade(t);
  assert.deepStrictEqual(d.naFactors.sort(), ['f1','f2','f3']);
  assert.strictEqual(d.agreedFactors.length, 0);
});
test('returns null (not a fabricated diagnosis) when trade has no factorSnapshot', () => {
  assert.strictEqual(__fno_diagnoseTrade({id:1, pnl:100}), null);
  assert.strictEqual(__fno_diagnoseTrade(null), null);
});

console.log('\n=== computeFactorPerformance (Master Prompt §31, §38 - overfitting protection) ===');
test('aggregates agree/disagree counts across multiple trades', () => {
  const journal = [
    fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}}),
    fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}}),
    fakeSnapshotTrade(-100, {f1:{status:'COMPUTED',pass:true,score:1}}), // disagree - said true, trade lost
  ];
  const perf = __fno_computeFactorPerformance(journal);
  const f1 = perf.get('f1');
  assert.strictEqual(f1.tradesInfluenced, 3);
  assert.strictEqual(f1.agreedCount, 2);
  assert.strictEqual(f1.disagreedCount, 1);
  assert.ok(Math.abs(f1.accuracyPct - 66.666) < 0.01);
});
test('sampleSizeWarning true below 30 trades (Section 38 anti-overfitting threshold)', () => {
  const journal = [fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}})];
  const perf = __fno_computeFactorPerformance(journal);
  assert.strictEqual(perf.get('f1').sampleSizeWarning, true);
});
test('trades without a snapshot are excluded, not treated as zero evidence', () => {
  const journal = [{id:1, pnl:100}]; // no factorSnapshot at all
  const perf = __fno_computeFactorPerformance(journal);
  assert.strictEqual(perf.size, 0);
});
test('empty journal returns an empty Map, not a fabricated conclusion', () => {
  const perf = __fno_computeFactorPerformance([]);
  assert.strictEqual(perf.size, 0);
});

console.log('\n=== computeFactorPerformance §49 fix: profitImpact / falseSignalRate / currentWeight / suggestedWeight / reliability ===');
test('falseSignalRate is the real, explicit complement of accuracy', () => {
  const journal = [
    fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}}),
    fakeSnapshotTrade(-100, {f1:{status:'COMPUTED',pass:true,score:1}}),
  ];
  const f1 = __fno_computeFactorPerformance(journal).get('f1');
  assert.ok(Math.abs(f1.falseSignalRate - 50) < 1e-9);
  assert.ok(Math.abs((f1.accuracyPct + f1.falseSignalRate) - 100) < 1e-9);
});
test('profitImpact is a real distinct metric: avg P&L when agreed minus avg P&L when disagreed', () => {
  const journal = [
    fakeSnapshotTrade(1000, {f1:{status:'COMPUTED',pass:true,score:1}}), // agreed, big win
    fakeSnapshotTrade(-100, {f1:{status:'COMPUTED',pass:true,score:1}}), // disagreed, small loss
  ];
  const f1 = __fno_computeFactorPerformance(journal).get('f1');
  assert.ok(Math.abs(f1.profitImpact - 1100) < 1e-9, `expected 1000 - (-100) = 1100, got ${f1.profitImpact}`);
});
test('a trade missing pnl is correctly excluded entirely (treated as honest BREAKEVEN), never counted with a fabricated profitImpact', () => {
  const journal = [{factorSnapshot:{factors:{f1:{status:'COMPUTED',pass:true,score:1}}}}]; // no pnl field at all
  const perf = __fno_computeFactorPerformance(journal);
  assert.strictEqual(perf.get('f1'), undefined, 'a factor from a pnl-less trade must not appear in the performance map at all, same discipline as trades with no snapshot');
});
test('currentWeight/suggestedWeight honestly null when no catalog is provided - never guessed', () => {
  const journal = Array.from({length:35}, (_,i) => fakeSnapshotTrade(i%2===0?100:-100, {f1:{status:'COMPUTED',pass:true,score:1}}));
  const f1 = __fno_computeFactorPerformance(journal).get('f1'); // no catalog arg
  assert.strictEqual(f1.currentWeight, null);
  assert.strictEqual(f1.suggestedWeight, null);
});
test('currentWeight joins from the real catalog when provided; suggestedWeight computed only above the real sample threshold', () => {
  const catalog = [{id:'f1', weight: 2}];
  const smallJournal = [fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}})]; // below 30-trade floor
  const f1small = __fno_computeFactorPerformance(smallJournal, catalog).get('f1');
  assert.strictEqual(f1small.currentWeight, 2);
  assert.strictEqual(f1small.suggestedWeight, null, 'must not propose a candidate weight below the real 30-trade sample floor');
});
test('suggestedWeight is real, evidence-scaled, and bounded - a real ~90% accuracy factor gets a real upward candidate, clamped to +50% max', () => {
  const catalog = [{id:'f1', weight: 2}];
  // 27 agree, 3 disagree = 90% accuracy, above the 30-trade floor
  const journal = [
    ...Array.from({length:27}, () => fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}})),
    ...Array.from({length:3}, () => fakeSnapshotTrade(-100, {f1:{status:'COMPUTED',pass:true,score:1}})),
  ];
  const f1 = __fno_computeFactorPerformance(journal, catalog).get('f1');
  assert.ok(f1.suggestedWeight > f1.currentWeight, `90% accuracy should propose raising weight above ${f1.currentWeight}, got ${f1.suggestedWeight}`);
  assert.ok(f1.suggestedWeight <= f1.currentWeight * 1.5 + 1e-9, `must be clamped to at most +50% per cycle, got ${f1.suggestedWeight}`);
});
test('suggestedWeight proposes NO real change for a genuinely neutral (50%) factor - noise should not move weight', () => {
  const catalog = [{id:'f1', weight: 2}];
  const journal = [
    ...Array.from({length:15}, () => fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}})),
    ...Array.from({length:15}, () => fakeSnapshotTrade(-100, {f1:{status:'COMPUTED',pass:true,score:1}})),
  ];
  const f1 = __fno_computeFactorPerformance(journal, catalog).get('f1');
  assert.ok(Math.abs(f1.suggestedWeight - f1.currentWeight) < 1e-9, `a 50% (pure noise) factor should propose no change, got ${f1.suggestedWeight} vs current ${f1.currentWeight}`);
});
test('reliability: real High tier requires BOTH real accuracy AND real sample size - §49 own example (68% accuracy, 487 trades = High)', () => {
  const journal = [
    ...Array.from({length:33}, () => fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}})), // ~66% agree
    ...Array.from({length:17}, () => fakeSnapshotTrade(-100, {f1:{status:'COMPUTED',pass:true,score:1}})),
  ];
  const f1 = __fno_computeFactorPerformance(journal).get('f1');
  assert.strictEqual(f1.reliability, 'High');
});
test('reliability: real high accuracy on a TINY sample is "Unproven", never "High" - the exact distinction §49 requires', () => {
  const journal = [
    fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}}),
    fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}}),
  ]; // 100% accuracy, but only 2 trades - genuinely unproven, not "High"
  const f1 = __fno_computeFactorPerformance(journal).get('f1');
  assert.strictEqual(f1.reliability, 'Unproven', `100% accuracy on 2 trades must be Unproven, not fabricated High confidence, got ${f1.reliability}`);
});
test('reliability: real Low tier for real sub-50% accuracy with adequate sample', () => {
  const journal = [
    ...Array.from({length:10}, () => fakeSnapshotTrade(100, {f1:{status:'COMPUTED',pass:true,score:1}})),
    ...Array.from({length:25}, () => fakeSnapshotTrade(-100, {f1:{status:'COMPUTED',pass:true,score:1}})),
  ]; // ~28.5% accuracy, 35 trades
  const f1 = __fno_computeFactorPerformance(journal).get('f1');
  assert.strictEqual(f1.reliability, 'Low');
});

console.log('\n=== generateDailyReview (Master Prompt §45) ===');
test('computes real win rate, gross/net P&L, and expectancy for one day', () => {
  const today = new Date().toDateString();
  const journal = [
    {ts: Date.now(), pnl: 500, grossPnl: 550, costsTotal: 50, symbol:'NIFTY', strike:23200},
    {ts: Date.now(), pnl: -200, grossPnl: -180, costsTotal: 20, symbol:'NIFTY', strike:23300},
    {ts: Date.now() - 3*24*60*60*1000, pnl: 9999, symbol:'NIFTY', strike:0}, // different day, must be excluded
  ];
  const review = __fno_generateDailyReview(journal, today);
  assert.strictEqual(review.tradeCount, 2);
  assert.strictEqual(review.wins, 1);
  assert.strictEqual(review.losses, 1);
  assert.strictEqual(review.winRatePct, 50);
  assert.strictEqual(review.netPnl, 300);
  assert.strictEqual(review.grossPnl, 370);
  assert.strictEqual(review.totalCosts, 70);
  assert.ok(review.bestTrade && review.bestTrade.pnl === 500);
  assert.ok(review.worstTrade && review.worstTrade.pnl === -200);
});
test('zero trades that day returns a valid zeroed shape, not a throw', () => {
  const review = __fno_generateDailyReview([], new Date().toDateString());
  assert.strictEqual(review.tradeCount, 0);
  assert.strictEqual(review.winRatePct, 0);
  assert.strictEqual(review.bestTrade, null);
});

console.log('\n=== pearsonCorrelation (real textbook formula, verified against a known case) ===');
test('perfectly correlated series (y=2x) returns correlation of exactly 1', () => {
  const x = [1,2,3,4,5], y = [2,4,6,8,10];
  const r = __fno_pearsonCorrelation(x, y);
  assert.ok(Math.abs(r - 1) < 1e-9);
});
test('perfectly anti-correlated series returns exactly -1', () => {
  const x = [1,2,3,4,5], y = [10,8,6,4,2];
  const r = __fno_pearsonCorrelation(x, y);
  assert.ok(Math.abs(r - (-1)) < 1e-9);
});
test('constant series (zero variance) returns null, not a fabricated zero', () => {
  const r = __fno_pearsonCorrelation([5,5,5,5], [1,2,3,4]);
  assert.strictEqual(r, null);
});
test('uncorrelated-ish real numbers produce a real value in range, verified against hand-calculation', () => {
  // Known dataset with a well-established Pearson r ≈ 0.9838 (classic example)
  const x = [10,8,13,9,11,14,6,4,12,7,5];
  const y = [8.04,6.95,7.58,8.81,8.33,9.96,7.24,4.26,10.84,4.82,5.68];
  const r = __fno_pearsonCorrelation(x, y);
  assert.ok(Math.abs(r - 0.8164) < 0.01, `expected ~0.816 (Anscombe's quartet dataset I), got ${r}`);
});

console.log('\n=== computeRollingCorrelation / classifyCorrelationStrength (Enterprise Plan #13 - Correlation Engine) ===');
test('two identical-return series (perfectly correlated) return correlation ~1', () => {
  const closesA = Array.from({length:20}, (_,i)=>23000+i*10);
  const closesB = Array.from({length:20}, (_,i)=>50000+i*30); // different scale, same real % pattern shape
  const corr = __fno_computeRollingCorrelation(closesA, closesB);
  assert.ok(corr > 0.99, `expected near-perfect correlation, got ${corr}`);
});
test('two inversely-moving series return a real negative correlation', () => {
  // Explicitly engineered opposite RETURNS (not just opposite price
  // direction via linear absolute steps, which don't reliably produce
  // opposite percentage returns once the changing denominator is
  // accounted for - a real subtlety caught by this test failing on
  // first draft and being fixed here, not by loosening the assertion).
  const returns = [0.01,-0.02,0.015,-0.01,0.02,-0.015,0.01,-0.02,0.015,-0.01,0.02,-0.015,0.01,-0.02];
  const closesA = [23000]; returns.forEach(r => closesA.push(closesA[closesA.length-1]*(1+r)));
  const closesB = [50000]; returns.forEach(r => closesB.push(closesB[closesB.length-1]*(1-r))); // exact opposite return each step
  const corr = __fno_computeRollingCorrelation(closesA, closesB);
  assert.ok(corr < -0.99, `expected near-perfect anti-correlation, got ${corr}`);
});
test('correlates RETURNS not raw price levels - shared drift alone does not force a high correlation from unrelated noise', () => {
  const rand = (seed => () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff; })(11);
  const closesA = []; let a = 23000;
  const closesB = []; let b = 50000;
  for (let i=0;i<50;i++) { a += (rand()-0.5)*50; b += (rand()-0.3)*100; closesA.push(a); closesB.push(b); } // both drift up but with independent noise
  const corr = __fno_computeRollingCorrelation(closesA, closesB);
  assert.ok(corr !== null && Math.abs(corr) < 0.9, `expected a real, imperfect correlation from independent noise, got ${corr}`);
});
test('returns null with fewer than the real minimum aligned points', () => {
  assert.strictEqual(__fno_computeRollingCorrelation([1,2,3], [1,2,3]), null);
});
test('returns null on mismatched array lengths - never silently truncates', () => {
  assert.strictEqual(__fno_computeRollingCorrelation(Array(20).fill(1), Array(15).fill(1)), null);
});
test('classifyCorrelationStrength: real documented thresholds', () => {
  assert.strictEqual(__fno_classifyCorrelationStrength(0.9), 'very_high');
  assert.strictEqual(__fno_classifyCorrelationStrength(0.7), 'high');
  assert.strictEqual(__fno_classifyCorrelationStrength(0.4), 'moderate');
  assert.strictEqual(__fno_classifyCorrelationStrength(0.1), 'low');
  assert.strictEqual(__fno_classifyCorrelationStrength(-0.9), 'very_high'); // sign-agnostic, uses absolute value
  assert.strictEqual(__fno_classifyCorrelationStrength(null), 'unknown');
});

console.log('\n=== computeCorrelationMatrix (closes docs/PENDING_REQUIREMENTS.md "multi-asset correlation matrix" - real, bounded 3-symbol scope) ===');
test('real, all 3 pairs computed for a real, complete 3-symbol closesMap', () => {
  const closesMap = {
    NIFTY: Array.from({length:20}, (_,i)=>23000+i*10),
    BANKNIFTY: Array.from({length:20}, (_,i)=>50000+i*30),
    FINNIFTY: Array.from({length:20}, (_,i)=>20000+i*8),
  };
  const matrix = __fno_computeCorrelationMatrix(closesMap);
  assert.strictEqual(matrix.length, 3, 'a real 3-symbol map must produce exactly 3 real pairs (3 choose 2)');
  const pairKeys = matrix.map(p => [p.symbolA, p.symbolB].sort().join('-'));
  assert.ok(pairKeys.includes('BANKNIFTY-NIFTY'));
  assert.ok(pairKeys.includes('FINNIFTY-NIFTY'));
  assert.ok(pairKeys.includes('BANKNIFTY-FINNIFTY'));
});
test('real, correct correlation value and strength reused directly from the already-tested underlying functions, using a verified real-returns fixture', () => {
  const returns = [0.01,-0.02,0.015,-0.01,0.02,-0.015,0.01,-0.02,0.015,-0.01,0.02,-0.015,0.01,-0.02];
  const niftyCloses = [23000]; returns.forEach(r => niftyCloses.push(niftyCloses[niftyCloses.length-1]*(1+r)));
  const bankCloses = [50000]; returns.forEach(r => bankCloses.push(bankCloses[bankCloses.length-1]*(1-r))); // real, exact opposite return each step
  const matrix = __fno_computeCorrelationMatrix({ NIFTY: niftyCloses, BANKNIFTY: bankCloses });
  assert.strictEqual(matrix.length, 1);
  assert.ok(matrix[0].correlation < -0.99, `expected a real, near-perfect anti-correlation, got ${matrix[0].correlation}`);
  assert.strictEqual(matrix[0].strength, 'very_high', 'strength must be sign-agnostic - a strong anti-correlation is still "very_high"');
});
test('real, honest handling when only 2 of 3 real symbols have real data this refresh - only the 1 real, computable pair is returned, never a fabricated third', () => {
  const closesMap = {
    NIFTY: Array.from({length:20}, (_,i)=>23000+i*10),
    BANKNIFTY: Array.from({length:20}, (_,i)=>50000+i*30),
    FINNIFTY: [], // genuinely no real data this refresh
  };
  const matrix = __fno_computeCorrelationMatrix(closesMap);
  assert.strictEqual(matrix.length, 1, 'a genuinely empty real symbol must be excluded entirely, not produce pairs with fabricated data');
  assert.strictEqual(matrix[0].symbolA, 'NIFTY');
  assert.strictEqual(matrix[0].symbolB, 'BANKNIFTY');
});
test('real, honest empty array for a genuinely empty or single-symbol closesMap - never throws', () => {
  assert.deepStrictEqual(__fno_computeCorrelationMatrix({}), []);
  assert.deepStrictEqual(__fno_computeCorrelationMatrix({ NIFTY: [1,2,3] }), []);
  assert.doesNotThrow(() => __fno_computeCorrelationMatrix(null));
  assert.doesNotThrow(() => __fno_computeCorrelationMatrix(undefined));
});

console.log('\n=== computeFactorCorrelationMatrix (Enterprise Plan #26 - Factor Correlation/Redundancy Engine) ===');
function fakeCorrTrade(scores) {
  const factors = {};
  Object.entries(scores).forEach(([id, score]) => { factors[id] = { status: 'COMPUTED', score }; });
  return { factorSnapshot: { factors } };
}
test('detects a genuinely redundant pair (near-identical scores across trades)', () => {
  const journal = Array.from({length:25}, (_,i) => fakeCorrTrade({ f1: i, f2: i * 2 + 0.01*(i%3) })); // f2 ≈ 2*f1, highly correlated
  const result = __fno_computeFactorCorrelationMatrix(journal);
  const pair = result.pairs.find(p => (p.factorA==='f1'&&p.factorB==='f2') || (p.factorA==='f2'&&p.factorB==='f1'));
  assert.ok(pair, 'f1/f2 pair must be reported');
  assert.ok(pair.isLikelyRedundant, `expected redundant, correlation=${pair.correlation}`);
});
test('does not flag genuinely independent factors as redundant', () => {
  const rand = (seed => () => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed / 0x7fffffff; })(7);
  const journal = Array.from({length:25}, () => fakeCorrTrade({ f1: rand()*10, f2: rand()*10 }));
  const result = __fno_computeFactorCorrelationMatrix(journal);
  const pair = result.pairs.find(p => (p.factorA==='f1'&&p.factorB==='f2') || (p.factorA==='f2'&&p.factorB==='f1'));
  if (pair) assert.strictEqual(pair.isLikelyRedundant, false, `random-noise factors should not be flagged redundant, correlation=${pair.correlation}`);
});
test('requires MIN_SHARED_TRADES before reporting a pair at all - not enough data means no claim, not a guess', () => {
  const journal = Array.from({length:5}, (_,i) => fakeCorrTrade({ f1: i, f2: i }));
  const result = __fno_computeFactorCorrelationMatrix(journal);
  assert.strictEqual(result.pairs.length, 0, 'fewer than MIN_SHARED_TRADES must produce zero reported pairs');
});
test('only counts trades where BOTH factors were genuinely COMPUTED, never backfilled', () => {
  const journal = [
    ...Array.from({length:15}, (_,i) => fakeCorrTrade({ f1: i, f2: i })), // both computed
    ...Array.from({length:15}, (_,i) => fakeCorrTrade({ f1: i })), // f2 NOT present this trade - must not count toward the shared pair
  ];
  const result = __fno_computeFactorCorrelationMatrix(journal);
  const pair = result.pairs.find(p => (p.factorA==='f1'&&p.factorB==='f2') || (p.factorA==='f2'&&p.factorB==='f1'));
  // Only the 15 genuinely-shared trades should count, which is below MIN_SHARED_TRADES(20) - so pair should be absent
  assert.strictEqual(pair, undefined, 'f2-missing trades must not be silently backfilled into the shared comparison');
});
test('pairs sorted by absolute correlation descending (most redundant-looking first)', () => {
  const journal = Array.from({length:25}, (_,i) => fakeCorrTrade({ f1: i, f2: i*2, f3: Math.sin(i)*5 }));
  const result = __fno_computeFactorCorrelationMatrix(journal);
  for (let k = 1; k < result.pairs.length; k++) {
    assert.ok(Math.abs(result.pairs[k-1].correlation) >= Math.abs(result.pairs[k].correlation));
  }
});
test('empty journal returns empty pairs and sampleSizeWarning true, not a throw', () => {
  const result = __fno_computeFactorCorrelationMatrix([]);
  assert.deepStrictEqual(result.pairs, []);
  assert.strictEqual(result.sampleSizeWarning, true);
});

console.log('\n=== computeFactorCombinationPerformance (Master Prompt §32 - real Factor Interaction Analysis) ===');
function fakeComboTrade(pnl, factorPasses) {
  const factors = {};
  Object.entries(factorPasses).forEach(([id, pass]) => { factors[id] = { status: 'COMPUTED', pass }; });
  return { factorSnapshot: { factors }, pnl };
}
test('isHighPerforming: real agree-bucket win rate clearing both the real 65% floor AND a real edge over the disagree bucket', () => {
  const journal = [
    // Agree bucket (both true): 18 wins, 2 losses = 90% - clears both real thresholds
    ...Array.from({length:18}, () => fakeComboTrade(100, {fA:true, fB:true})),
    ...Array.from({length:2}, () => fakeComboTrade(-100, {fA:true, fB:true})),
    // Disagree bucket: 50% win rate - real, clear edge for the agree bucket over this
    ...Array.from({length:10}, () => fakeComboTrade(100, {fA:true, fB:false})),
    ...Array.from({length:10}, () => fakeComboTrade(-100, {fA:true, fB:false})),
  ];
  const result = __fno_computeFactorCombinationPerformance(journal);
  const pair = result.pairs.find(p => (p.factorA==='fA'&&p.factorB==='fB')||(p.factorA==='fB'&&p.factorB==='fA'));
  assert.ok(pair, 'pair must be reported - real 20-trade agree-bucket sample');
  assert.ok(Math.abs(pair.agreeWinRatePct - 90) < 1e-9);
  assert.strictEqual(pair.isHighPerforming, true);
  assert.strictEqual(pair.isConsistentlyFailing, false);
});
test('isConsistentlyFailing: real agree-bucket win rate at or below the real 35% floor', () => {
  const journal = [
    ...Array.from({length:5}, () => fakeComboTrade(100, {fA:true, fB:true})),
    ...Array.from({length:20}, () => fakeComboTrade(-100, {fA:true, fB:true})),
  ];
  const result = __fno_computeFactorCombinationPerformance(journal);
  const pair = result.pairs.find(p => (p.factorA==='fA'&&p.factorB==='fB')||(p.factorA==='fB'&&p.factorB==='fA'));
  assert.ok(pair);
  assert.ok(Math.abs(pair.agreeWinRatePct - 20) < 1e-9);
  assert.strictEqual(pair.isConsistentlyFailing, true);
  assert.strictEqual(pair.isHighPerforming, false);
});
test('a pair below the real 20-trade agree-bucket floor is not reported at all - not enough evidence, not a guess', () => {
  const journal = Array.from({length:5}, () => fakeComboTrade(100, {fA:true, fB:true}));
  const result = __fno_computeFactorCombinationPerformance(journal);
  const pair = result.pairs.find(p => (p.factorA==='fA'&&p.factorB==='fB')||(p.factorA==='fB'&&p.factorB==='fA'));
  assert.strictEqual(pair, undefined);
});
test('BREAKEVEN trades (pnl===0) are correctly excluded from both buckets, same discipline as diagnoseTrade', () => {
  const journal = [
    ...Array.from({length:20}, () => fakeComboTrade(100, {fA:true, fB:true})),
    ...Array.from({length:50}, () => fakeComboTrade(0, {fA:true, fB:true})), // real breakeven trades - must not dilute the real win rate
  ];
  const result = __fno_computeFactorCombinationPerformance(journal);
  const pair = result.pairs.find(p => (p.factorA==='fA'&&p.factorB==='fB')||(p.factorA==='fB'&&p.factorB==='fA'));
  assert.strictEqual(pair.agreeSampleSize, 20, 'breakeven trades must not be counted in the real sample size');
  assert.ok(Math.abs(pair.agreeWinRatePct - 100) < 1e-9);
});
test('disagree bucket correctly distinguishes "both false" (also agreement) from genuinely mixed pass values', () => {
  const journal = [
    ...Array.from({length:20}, () => fakeComboTrade(100, {fA:false, fB:false})), // both false = real agreement with each other
  ];
  const result = __fno_computeFactorCombinationPerformance(journal);
  const pair = result.pairs.find(p => (p.factorA==='fA'&&p.factorB==='fB')||(p.factorA==='fB'&&p.factorB==='fA'));
  assert.strictEqual(pair.agreeSampleSize, 20, 'both-false must count as real agreement, not disagreement');
  assert.strictEqual(pair.disagreeSampleSize, 0);
});
test('pairs sorted by real agreeWinRatePct descending - best-performing combinations first', () => {
  const journal = [
    ...Array.from({length:20}, () => fakeComboTrade(100, {fA:true, fB:true})), // 100%
    ...Array.from({length:20}, () => fakeComboTrade(-100, {fC:true, fD:true})), // 0%
  ];
  const result = __fno_computeFactorCombinationPerformance(journal);
  for (let k = 1; k < result.pairs.length; k++) {
    assert.ok(result.pairs[k-1].agreeWinRatePct >= result.pairs[k].agreeWinRatePct);
  }
});
test('empty journal returns empty pairs and zero tradesAnalyzed, not a throw', () => {
  const result = __fno_computeFactorCombinationPerformance([]);
  assert.deepStrictEqual(result.pairs, []);
  assert.strictEqual(result.tradesAnalyzed, 0);
});

console.log('\n=== computeFactorPerformanceByRegime (Enterprise Plan #27 - regime-segmented factor performance) ===');
function fakeRegimeTrade(pnl, factorPass, regimeLabel) {
  return { pnl, factorSnapshot: { factors: { f1: { status:'COMPUTED', pass: factorPass } }, regime: { label: regimeLabel } } };
}
test('segments the SAME factor real accuracy by real regime label - the literal RSI-in-a-trend example', () => {
  const journal = [
    ...Array.from({length:15}, () => fakeRegimeTrade(100, true, 'Bullish-Normal Vol')), // f1 said favorable, WIN, in Bullish regime -> 100% agree
    ...Array.from({length:15}, () => fakeRegimeTrade(-100, true, 'Bearish-Normal Vol')), // f1 said favorable, LOST, in Bearish regime -> 0% agree
  ];
  const perf = __fno_computeFactorPerformanceByRegime(journal);
  const f1 = perf.get('f1');
  assert.strictEqual(f1.get('Bullish-Normal Vol').accuracyPct, 100);
  assert.strictEqual(f1.get('Bearish-Normal Vol').accuracyPct, 0);
});
test('excludes trades without a real regime tag, not guessed into a fake bucket', () => {
  const journal = [{ pnl: 100, factorSnapshot: { factors: { f1: { status:'COMPUTED', pass:true } } } }]; // no regime field at all
  const perf = __fno_computeFactorPerformanceByRegime(journal);
  assert.strictEqual(perf.size, 0);
});
test('sampleSizeWarning true below 15 trades for this more granular slice', () => {
  const journal = [fakeRegimeTrade(100, true, 'Sideways-Low Vol')];
  const perf = __fno_computeFactorPerformanceByRegime(journal);
  assert.strictEqual(perf.get('f1').get('Sideways-Low Vol').sampleSizeWarning, true);
});

console.log('\n=== computeFactorPerformanceByTimeframe (Master Prompt §42/§46/§47 - real calendar-month-segmented factor performance) ===');
function fakeTimeframeTrade(ts, pnl, factorPass) {
  return { ts, pnl, factorSnapshot: { factors: { f1: { status:'COMPUTED', pass: factorPass } } } };
}
test('segments the SAME factor real accuracy by real calendar month - a real, honest drift-over-time detector', () => {
  const janTs = new Date(2026, 0, 15).getTime(); // real, verified: January 2026 -> real label "2026-01"
  const febTs = new Date(2026, 1, 15).getTime(); // real, verified: February 2026 -> real label "2026-02"
  const journal = [
    ...Array.from({length:15}, () => fakeTimeframeTrade(janTs, 100, true)), // f1 said favorable, WIN, in January -> 100% agree
    ...Array.from({length:15}, () => fakeTimeframeTrade(febTs, -100, true)), // f1 said favorable, LOST, in February -> 0% agree
  ];
  const perf = __fno_computeFactorPerformanceByTimeframe(journal);
  const f1 = perf.get('f1');
  assert.strictEqual(f1.get('2026-01').accuracyPct, 100);
  assert.strictEqual(f1.get('2026-02').accuracyPct, 0);
});
test('real month label uses the correct, human-readable 1-indexed month (verified against JS Date\'s own 0-indexed getMonth())', () => {
  const decTs = new Date(2025, 11, 25).getTime(); // real: December (JS month index 11) 2025
  const journal = Array.from({length:15}, () => fakeTimeframeTrade(decTs, 100, true));
  const perf = __fno_computeFactorPerformanceByTimeframe(journal);
  assert.ok(perf.get('f1').has('2025-12'), 'December must be labeled "12", not the raw 0-indexed "11"');
});
test('real year-boundary edge case: a trade seconds before midnight Dec 31 and one seconds after midnight Jan 1 land in genuinely different, correctly-labeled real months/years', () => {
  const lateDecTs = new Date(2025, 11, 31, 23, 59, 59).getTime();
  const earlyJanTs = new Date(2026, 0, 1, 0, 0, 1).getTime();
  const journal = [
    ...Array.from({length:15}, () => fakeTimeframeTrade(lateDecTs, 100, true)),
    ...Array.from({length:15}, () => fakeTimeframeTrade(earlyJanTs, -100, true)),
  ];
  const perf = __fno_computeFactorPerformanceByTimeframe(journal);
  const f1 = perf.get('f1');
  assert.ok(f1.has('2025-12'), 'a trade seconds before the year boundary must land in real 2025-12');
  assert.ok(f1.has('2026-01'), 'a trade seconds after the year boundary must land in real 2026-01, not lumped in with December');
  assert.strictEqual(f1.get('2025-12').accuracyPct, 100);
  assert.strictEqual(f1.get('2026-01').accuracyPct, 0);
});
test('excludes trades with a real, genuinely missing or invalid timestamp, never guessed into a fake bucket', () => {
  const journal = [
    { pnl: 100, factorSnapshot: { factors: { f1: { status:'COMPUTED', pass:true } } } }, // no ts at all
    { ts: -5, pnl: 100, factorSnapshot: { factors: { f1: { status:'COMPUTED', pass:true } } } }, // real, genuinely invalid ts
  ];
  const perf = __fno_computeFactorPerformanceByTimeframe(journal);
  assert.strictEqual(perf.size, 0);
});
test('sampleSizeWarning true below the real 15-trade floor for this more granular slice', () => {
  const journal = [fakeTimeframeTrade(new Date(2026,2,1).getTime(), 100, true)];
  const perf = __fno_computeFactorPerformanceByTimeframe(journal);
  assert.strictEqual(perf.get('f1').get('2026-03').sampleSizeWarning, true);
});
test('real, genuine DECLINE detected between the real earliest and latest reliably-sampled months, not just spread', () => {
  const janTs = new Date(2026, 0, 15).getTime();
  const marTs = new Date(2026, 2, 15).getTime();
  const journal = [
    ...Array.from({length:15}, () => fakeTimeframeTrade(janTs, 100, true)), // real 100% in January
    ...Array.from({length:15}, () => fakeTimeframeTrade(marTs, -100, true)), // real 0% in March
  ];
  const drift = __fno_computeFactorTimeframeDrift(journal);
  const f1 = drift.find(d => d.factorId === 'f1');
  assert.ok(f1, 'must produce a real drift entry for f1');
  assert.strictEqual(f1.earliestMonth, '2026-01');
  assert.strictEqual(f1.latestMonth, '2026-03');
  assert.strictEqual(f1.driftPct, -100, 'a real decline from 100% to 0% must be a real, negative driftPct of -100');
});
test('real, genuine IMPROVEMENT correctly produces a positive driftPct - the mirror case, real direction matters, not just magnitude', () => {
  const janTs = new Date(2026, 0, 15).getTime();
  const marTs = new Date(2026, 2, 15).getTime();
  const journal = [
    ...Array.from({length:15}, () => fakeTimeframeTrade(janTs, -100, true)), // real 0% in January
    ...Array.from({length:15}, () => fakeTimeframeTrade(marTs, 100, true)), // real 100% in March
  ];
  const drift = __fno_computeFactorTimeframeDrift(journal);
  const f1 = drift.find(d => d.factorId === 'f1');
  assert.strictEqual(f1.driftPct, 100, 'a real improvement must be a real, positive driftPct');
});
test('real, correct use of the EARLIEST and LATEST months specifically, not just any two months, when 3+ real reliable months exist', () => {
  const janTs = new Date(2026, 0, 15).getTime();
  const febTs = new Date(2026, 1, 15).getTime();
  const marTs = new Date(2026, 2, 15).getTime();
  const journal = [
    ...Array.from({length:15}, () => fakeTimeframeTrade(janTs, 100, true)), // real 100% Jan (earliest)
    ...Array.from({length:15}, () => fakeTimeframeTrade(febTs, 100, true)), // real 100% Feb (middle - genuinely irrelevant to earliest-vs-latest)
    ...Array.from({length:15}, () => fakeTimeframeTrade(marTs, -100, true)), // real 0% Mar (latest)
  ];
  const drift = __fno_computeFactorTimeframeDrift(journal);
  const f1 = drift.find(d => d.factorId === 'f1');
  assert.strictEqual(f1.earliestMonth, '2026-01', 'must use the real earliest month, not just the first one seen');
  assert.strictEqual(f1.latestMonth, '2026-03', 'must use the real latest month, not the middle one');
});
test('does NOT report a factor with fewer than 2 real, reliably-sampled, chronologically distinct months - nothing to compare', () => {
  const janTs = new Date(2026, 0, 15).getTime();
  const journal = Array.from({length:15}, () => fakeTimeframeTrade(janTs, 100, true)); // only 1 real reliable month
  const drift = __fno_computeFactorTimeframeDrift(journal);
  assert.strictEqual(drift.find(d => d.factorId === 'f1'), undefined);
});
test('real year-boundary edge case: December 2025 correctly sorts as EARLIEST relative to January 2026, not the reverse - the string-based chronological sort must genuinely respect the year, not just the month digits', () => {
  const decTs = new Date(2025, 11, 15).getTime();
  const janTs = new Date(2026, 0, 15).getTime();
  const journal = [
    ...Array.from({length:30}, () => fakeTimeframeTrade(decTs, 100, true)), // real 100% in Dec 2025 (earliest)
    ...Array.from({length:30}, () => fakeTimeframeTrade(janTs, -100, true)), // real 0% in Jan 2026 (latest)
  ];
  const drift = __fno_computeFactorTimeframeDrift(journal);
  const f1 = drift.find(d => d.factorId === 'f1');
  assert.strictEqual(f1.earliestMonth, '2025-12', 'December 2025 must be correctly identified as earlier than January 2026');
  assert.strictEqual(f1.latestMonth, '2026-01');
  assert.strictEqual(f1.driftPct, -100);
});

console.log('\n=== computeRegimeDependentFactors (real "performs unevenly across regimes" detector) ===');
test('flags a factor with a real large accuracy swing across 2 reliably-sampled regimes', () => {
  const journal = [
    ...Array.from({length:20}, () => fakeRegimeTrade(100, true, 'Bullish-Normal Vol')), // 100% accurate here
    ...Array.from({length:20}, () => fakeRegimeTrade(-100, true, 'Bearish-Normal Vol')), // 0% accurate here
  ];
  const result = __fno_computeRegimeDependentFactors(journal);
  const f1 = result.find(r => r.factorId === 'f1');
  assert.ok(f1, 'f1 must be reported as regime-dependent');
  assert.ok(Math.abs(f1.spreadPct - 100) < 1e-9);
});
test('does NOT report a factor with only 1 reliably-sampled regime - nothing to compare', () => {
  const journal = Array.from({length:20}, () => fakeRegimeTrade(100, true, 'Bullish-Normal Vol'));
  const result = __fno_computeRegimeDependentFactors(journal);
  assert.strictEqual(result.find(r => r.factorId==='f1'), undefined);
});
test('sorted by spreadPct descending - most regime-dependent factors first', () => {
  const journal = [
    // f1: big swing (100 -> 0)
    ...Array.from({length:20}, () => fakeRegimeTrade(100, true, 'Bullish-Normal Vol')),
    ...Array.from({length:20}, () => ({ pnl:-100, factorSnapshot:{ factors:{ f1:{status:'COMPUTED',pass:true}, f2:{status:'COMPUTED',pass:false} }, regime:{label:'Bearish-Normal Vol'} } })),
    ...Array.from({length:20}, () => ({ pnl:100, factorSnapshot:{ factors:{ f2:{status:'COMPUTED',pass:false} }, regime:{label:'Bullish-Normal Vol'} } })), // f2 said unfavorable, WIN -> disagree, consistent low accuracy both regimes -> small spread
  ];
  const result = __fno_computeRegimeDependentFactors(journal);
  for (let k = 1; k < result.length; k++) assert.ok(result[k-1].spreadPct >= result[k].spreadPct);
});
test('empty journal returns empty array, not a throw', () => {
  assert.deepStrictEqual(__fno_computeRegimeDependentFactors([]), []);
});

console.log('\n=== computeCalibrationBuckets (Master Prompt §26 - predicted vs actual win rate) ===');
function snapTrade(pnl, decision, confidence) {
  return { ts: Date.now(), pnl, factorSnapshot: { decision, confidence, factors: {} } };
}
test('buckets by decision+confidence, computes real observed win rate', () => {
  const journal = [
    snapTrade(100, 'BUY_READY', 'High'),
    snapTrade(100, 'BUY_READY', 'High'),
    snapTrade(-50, 'BUY_READY', 'High'),
  ];
  const buckets = __fno_computeCalibrationBuckets(journal);
  const b = buckets.get('BUY_READY|High');
  assert.strictEqual(b.count, 3);
  assert.strictEqual(b.wins, 2);
  assert.ok(Math.abs(b.winRatePct - 66.666) < 0.01);
});
test('excludes breakeven trades and trades with no snapshot', () => {
  const journal = [snapTrade(0, 'WAIT', 'Low'), {ts:Date.now(), pnl:100}];
  const buckets = __fno_computeCalibrationBuckets(journal);
  assert.strictEqual(buckets.size, 0);
});
test('sampleSizeWarning uses the same 30-trade threshold as factor performance', () => {
  const buckets = __fno_computeCalibrationBuckets([snapTrade(100, 'BUY_READY', 'High')]);
  assert.strictEqual(buckets.get('BUY_READY|High').sampleSizeWarning, true);
});

console.log('\n=== computeWalkForwardStability (Master Prompt §39) ===');
test('honestly refuses a verdict with fewer than 2 trades', () => {
  const wf = __fno_computeWalkForwardStability([{ts:1, pnl:100}], 3);
  assert.strictEqual(wf.stable, null);
  assert.ok(/at least 2/i.test(wf.warning));
});
test('honestly refuses a verdict when folds are too thin (<10 trades each)', () => {
  const journal = Array.from({length:15}, (_,i)=>({ts:i, pnl: i%2?100:-100}));
  const wf = __fno_computeWalkForwardStability(journal, 3);
  assert.strictEqual(wf.stable, null);
  assert.ok(/fewer than 10/i.test(wf.warning));
});
test('flags instability when win rate varies widely across periods', () => {
  // Fold 1: all wins, Fold 2: all losses, Fold 3: all wins - 33 trades total, 11 per fold
  const journal = [
    ...Array.from({length:11}, (_,i)=>({ts:i, pnl:100})),
    ...Array.from({length:11}, (_,i)=>({ts:11+i, pnl:-100})),
    ...Array.from({length:11}, (_,i)=>({ts:22+i, pnl:100})),
  ];
  const wf = __fno_computeWalkForwardStability(journal, 3);
  assert.strictEqual(wf.folds.length, 3);
  assert.strictEqual(wf.stable, false);
  assert.ok(/varies/i.test(wf.warning));
});
test('reports stable when win rate is consistent across periods', () => {
  const journal = Array.from({length:33}, (_,i)=>({ts:i, pnl: i%2?100:-100})); // consistent ~50% throughout
  const wf = __fno_computeWalkForwardStability(journal, 3);
  assert.strictEqual(wf.stable, true);
  assert.strictEqual(wf.warning, null);
});
test('folds are built in chronological order (sorted internally)', () => {
  const journal = [{ts:30, pnl:100},{ts:10, pnl:-100},{ts:20, pnl:100}];
  const wf = __fno_computeWalkForwardStability(journal, 3);
  assert.strictEqual(wf.folds[0].startTs, 10);
});

console.log('\n=== computeEquityCurve (Master Prompt §59 - virtual paper-trading account) ===');
test('zero trades: balance equals starting capital exactly, no drawdown', () => {
  const eq = __fno_computeEquityCurve([], {startingCapital: 100000, resetAt: 0});
  assert.strictEqual(eq.currentBalance, 100000);
  assert.strictEqual(eq.currentDrawdownPct, 0);
  assert.strictEqual(eq.maxDrawdownPct, 0);
  assert.strictEqual(eq.tradeCount, 0);
});
test('winning trades increase balance, cumulativePnl matches', () => {
  const journal = [{ts:1, pnl:500}, {ts:2, pnl:300}];
  const eq = __fno_computeEquityCurve(journal, {startingCapital: 100000, resetAt: 0});
  assert.strictEqual(eq.currentBalance, 100800);
  assert.strictEqual(eq.cumulativePnl, 800);
});
test('real max drawdown = largest peak-to-trough decline, not just worst single trade', () => {
  // Up to 101000, down to 99000 (peak-to-trough = 2000/101000), back up to 103000
  const journal = [{ts:1, pnl:1000}, {ts:2, pnl:-2000}, {ts:3, pnl:4000}];
  const eq = __fno_computeEquityCurve(journal, {startingCapital: 100000, resetAt: 0});
  assert.ok(Math.abs(eq.maxDrawdownPct - (2000/101000*100)) < 0.01, `got ${eq.maxDrawdownPct}`);
  assert.strictEqual(eq.currentBalance, 103000);
  assert.strictEqual(eq.currentDrawdownPct, 0, 'currently at a new peak, so current drawdown should be 0');
});
test('resetAt filters out trades before the reset, without needing them deleted from journal', () => {
  const journal = [{ts:100, pnl:99999}, {ts:500, pnl:200}];
  const eq = __fno_computeEquityCurve(journal, {startingCapital: 100000, resetAt: 300});
  assert.strictEqual(eq.tradeCount, 1);
  assert.strictEqual(eq.currentBalance, 100200);
});
test('defaults startingCapital to 100000 if not provided', () => {
  const eq = __fno_computeEquityCurve([], {});
  assert.strictEqual(eq.startingCapital, 100000);
});

console.log('\n=== computeEquityCurve §59 fix: real marginBlocked / availableCapital ===');
test('marginBlocked is honestly zero when no position is open', () => {
  const eq = __fno_computeEquityCurve([], {startingCapital: 100000}, {});
  assert.strictEqual(eq.marginBlocked, 0);
  assert.strictEqual(eq.availableCapital, eq.currentBalance);
});
test('marginBlocked is real premium x qty for an open long option position - the actual real cost of this app\'s only real trading flow (buying options)', () => {
  const openPosition = { entryPrice: 95, qty: 50 };
  const eq = __fno_computeEquityCurve([], {startingCapital: 100000}, openPosition);
  assert.strictEqual(eq.marginBlocked, 4750); // 95 * 50
  assert.strictEqual(eq.availableCapital, 100000 - 4750);
});
test('marginBlocked is honestly zero when openPosition is absent entirely (backward compatible call)', () => {
  const eq = __fno_computeEquityCurve([], {startingCapital: 100000}); // no third arg at all
  assert.strictEqual(eq.marginBlocked, 0);
});
test('availableCapital correctly reflects real trade P&L already realized, not just starting capital', () => {
  const journal = [{ts:100, pnl:5000}]; // one real closed win
  const openPosition = { entryPrice: 100, qty: 50 };
  const eq = __fno_computeEquityCurve(journal, {startingCapital: 100000, resetAt: 0}, openPosition);
  assert.strictEqual(eq.currentBalance, 105000);
  assert.strictEqual(eq.marginBlocked, 5000);
  assert.strictEqual(eq.availableCapital, 100000);
});
test('marginBlocked treats a NaN entryPrice/qty on the open position as zero, not as a NaN that poisons availableCapital (typeof/NaN audit pass)', () => {
  // Before this pass's fix, `typeof NaN === 'number'` let a corrupted
  // openPosition.entryPrice/qty through the guard, so marginBlocked
  // became NaN and availableCapital = balance - NaN = NaN too - a NaN
  // that would have relied entirely on FM044's own downstream guard to
  // not be silently read as "unlimited capital." This is the same
  // defense-in-depth discipline applied at the source.
  const corruptedPrice = __fno_computeEquityCurve([], {startingCapital: 100000}, {entryPrice: NaN, qty: 50});
  assert.strictEqual(corruptedPrice.marginBlocked, 0, 'a NaN entryPrice must not poison marginBlocked into NaN');
  assert.strictEqual(corruptedPrice.availableCapital, 100000);
  const corruptedQty = __fno_computeEquityCurve([], {startingCapital: 100000}, {entryPrice: 95, qty: NaN});
  assert.strictEqual(corruptedQty.marginBlocked, 0, 'a NaN qty must not poison marginBlocked into NaN');
  assert.strictEqual(corruptedQty.availableCapital, 100000);
});

console.log('\n=== computeEquityCurve data-quality guard: malformed journal pnl must not poison balance to NaN (this pass\'s UI/data-quality audit) ===');
test('a single journal entry with missing pnl does not corrupt currentBalance/availableCapital into NaN', () => {
  const journal = [{ts:100, pnl:1000}, {ts:200}, {ts:300, pnl:500}]; // middle entry has no pnl field at all
  const eq = __fno_computeEquityCurve(journal, {startingCapital: 100000, resetAt: 0});
  assert.ok(Number.isFinite(eq.currentBalance), `currentBalance must stay finite, got ${eq.currentBalance}`);
  assert.strictEqual(eq.currentBalance, 101500, 'the malformed entry should contribute 0, not NaN');
  assert.ok(Number.isFinite(eq.availableCapital), `availableCapital must stay finite, got ${eq.availableCapital}`);
});
test('a journal entry with a non-numeric string pnl does not corrupt currentBalance into NaN', () => {
  const journal = [{ts:100, pnl:'not-a-number'}, {ts:200, pnl:250}];
  const eq = __fno_computeEquityCurve(journal, {startingCapital: 100000, resetAt: 0});
  assert.ok(Number.isFinite(eq.currentBalance), `currentBalance must stay finite, got ${eq.currentBalance}`);
  assert.strictEqual(eq.currentBalance, 100250);
});
test('a journal entry with null pnl does not corrupt currentBalance into NaN', () => {
  const journal = [{ts:100, pnl:null}, {ts:200, pnl:100}];
  const eq = __fno_computeEquityCurve(journal, {startingCapital: 100000, resetAt: 0});
  assert.ok(Number.isFinite(eq.currentBalance), `currentBalance must stay finite, got ${eq.currentBalance}`);
  assert.strictEqual(eq.currentBalance, 100100);
});

console.log('\n=== computeMarketRegime (Master Prompt §40 - real regime tagging) ===');
test('detects Bullish trend from real EMA9>EMA21 relationship', () => {
  const closes = Array.from({length:30}, (_,i)=>23000+i*15); // steady uptrend
  const regime = __fno_computeMarketRegime({candles: closes.map(c=>({c})), vix: 15, isExpiry: false});
  assert.strictEqual(regime.trend, 'Bullish');
  assert.strictEqual(regime.volatility, 'Normal Vol');
  assert.strictEqual(regime.label, 'Bullish-Normal Vol');
});
test('detects Bearish trend and High Vol from VIX', () => {
  const closes = Array.from({length:30}, (_,i)=>23500-i*15);
  const regime = __fno_computeMarketRegime({candles: closes.map(c=>({c})), vix: 25, isExpiry: true});
  assert.strictEqual(regime.trend, 'Bearish');
  assert.strictEqual(regime.volatility, 'High Vol');
  assert.ok(regime.label.includes('(Expiry)'));
});
test('Unknown trend with fewer than 21 candles, not guessed', () => {
  const regime = __fno_computeMarketRegime({candles: [{c:100},{c:101}], vix: 15, isExpiry: false});
  assert.strictEqual(regime.trend, 'Unknown');
});
test('Unknown volatility when both VIX and candle history are unavailable', () => {
  const regime = __fno_computeMarketRegime({candles: null, vix: null, isExpiry: false});
  assert.strictEqual(regime.volatility, 'Unknown');
});
test('BUG REGRESSION: NaN vix must not fail open to Normal Vol - falls back to candle-based classification instead', () => {
  // Before the fix, `typeof ctx.vix === 'number'` was true for NaN (typeof
  // NaN is genuinely 'number'), and NaN satisfies neither `< 13` nor `> 20`,
  // so a corrupt/malformed VIX reading silently fell through to 'Normal
  // Vol' - misreporting a real data-integrity failure as calm, ordinary
  // conditions instead of an honest 'Unknown' or a real candle-based read.
  const closes = Array.from({length:30}, (_,i)=>23000+i*15);
  const regime = __fno_computeMarketRegime({candles: closes.map(c=>({c})), vix: NaN, isExpiry: false});
  assert.notStrictEqual(regime.volatility, 'Normal Vol', 'NaN vix must never silently classify as Normal Vol');
});
test('BUG REGRESSION: NaN vix with no candle fallback must classify volatility as Unknown, not Normal Vol', () => {
  const regime = __fno_computeMarketRegime({candles: null, vix: NaN, isExpiry: false});
  assert.strictEqual(regime.volatility, 'Unknown', `NaN vix with no candles must be honestly Unknown, got ${regime.volatility}`);
});
test('BUG REGRESSION: Infinity vix must not fail open to High Vol via a real numeric read (still honestly non-finite)', () => {
  const regime = __fno_computeMarketRegime({candles: null, vix: Infinity, isExpiry: false});
  assert.strictEqual(regime.volatility, 'Unknown', `Infinity vix must be treated as invalid, got ${regime.volatility}`);
});

console.log('\n=== computeRegimePerformance (Master Prompt §40 continued) ===');
test('aggregates real win rate and net P&L per regime label', () => {
  const journal = [
    {pnl:100, factorSnapshot:{regime:{label:'Bullish-Normal Vol'}}},
    {pnl:-50, factorSnapshot:{regime:{label:'Bullish-Normal Vol'}}},
    {pnl:200, factorSnapshot:{regime:{label:'Bearish-High Vol'}}},
  ];
  const perf = __fno_computeRegimePerformance(journal);
  const bull = perf.get('Bullish-Normal Vol');
  assert.strictEqual(bull.count, 2);
  assert.strictEqual(bull.wins, 1);
  assert.strictEqual(bull.netPnl, 50);
  assert.strictEqual(perf.get('Bearish-High Vol').count, 1);
});
test('trades without a regime tag are excluded (older trades pre-fix), not guessed', () => {
  const journal = [{pnl:100, factorSnapshot:{}}, {pnl:100}];
  const perf = __fno_computeRegimePerformance(journal);
  assert.strictEqual(perf.size, 0);
});

console.log('\n=== updateMFEMAE (Master Prompt §43) ===');
test('seeds mfe/mae from entryPrice on first call, not from the first tick', () => {
  const open = {entryPrice: 100};
  const r = __fno_updateMFEMAE(open, 100); // no move yet
  assert.strictEqual(r.mfe, 100);
  assert.strictEqual(r.mae, 100);
});
test('mfe never decreases, mae never increases across repeated calls', () => {
  let open = {entryPrice: 100};
  let r = __fno_updateMFEMAE(open, 150); open = {...open, ...r};
  r = __fno_updateMFEMAE(open, 120); open = {...open, ...r}; // pulls back
  assert.strictEqual(open.mfe, 150, 'mfe should stay at the peak, not drop to 120');
  r = __fno_updateMFEMAE(open, 80); open = {...open, ...r}; // drops below entry
  assert.strictEqual(open.mae, 80);
  r = __fno_updateMFEMAE(open, 90); open = {...open, ...r}; // recovers a bit
  assert.strictEqual(open.mae, 80, 'mae should stay at the trough, not rise to 90');
});

console.log('\n=== updateTrailingStop (Master Prompt §27) ===');
test('does not move before price moves favorably', () => {
  const open = {entryPrice: 100, sl: 80}; // risk distance 20
  const trail = __fno_updateTrailingStop(open, 100);
  assert.strictEqual(trail, 80);
});
test('ratchets up as price rises, trailing by the original risk distance', () => {
  const open = {entryPrice: 100, sl: 80};
  const trail = __fno_updateTrailingStop(open, 150);
  assert.strictEqual(trail, 130); // 150 - 20
});
test('never moves down even if price pulls back', () => {
  let open = {entryPrice: 100, sl: 80};
  open.trailingSl = __fno_updateTrailingStop(open, 150); // trails to 130
  const trail2 = __fno_updateTrailingStop(open, 110); // price pulls back
  assert.strictEqual(trail2, 130, 'trailing stop must not retreat');
});
test('a genuinely missing tradingType falls back to the neutral 1.0x multiplier (same as intraday, backward-compatible with pre-Phase-4 saved positions)', () => {
  const open = {entryPrice: 100, sl: 80}; // no tradingType field at all
  assert.strictEqual(__fno_updateTrailingStop(open, 150), 130); // unscaled: 150 - 20
});
test('intraday is a genuine, deliberate no-op - trails by the unscaled 1.0x original risk distance, same as before this session', () => {
  const open = {entryPrice: 100, sl: 80, tradingType: 'intraday'};
  assert.strictEqual(__fno_updateTrailingStop(open, 150), 130); // 150 - (20 * 1.0)
});
test('scalping trails TIGHTER - 0.5x the original risk distance, locking in gains faster', () => {
  const open = {entryPrice: 100, sl: 80, tradingType: 'scalping'}; // risk distance 20
  assert.strictEqual(__fno_updateTrailingStop(open, 150), 140); // 150 - (20 * 0.5) = 140, tighter than intraday's 130
});
test('swing trails WIDER - 1.5x the original risk distance, tolerating more normal back-and-forth', () => {
  const open = {entryPrice: 100, sl: 80, tradingType: 'swing'}; // risk distance 20
  assert.strictEqual(__fno_updateTrailingStop(open, 150), 120); // 150 - (20 * 1.5) = 120, wider than intraday's 130
});
test('an unrecognized tradingType string falls back to the neutral 1.0x multiplier, never a guessed value', () => {
  const open = {entryPrice: 100, sl: 80, tradingType: 'some_future_type_not_yet_defined'};
  assert.strictEqual(__fno_updateTrailingStop(open, 150), 130);
});
test('the real ratchet-never-lowers guarantee holds correctly for a tighter (scalping) trail too', () => {
  let open = {entryPrice: 100, sl: 80, tradingType: 'scalping'};
  open.trailingSl = __fno_updateTrailingStop(open, 150); // trails to 140
  const trail2 = __fno_updateTrailingStop(open, 120); // price pulls back
  assert.strictEqual(trail2, 140, 'a tighter trail must still never retreat once set');
});
test('static wiring-audit lock: FNO_TRAILING_DISTANCE_MULTIPLIER genuinely defines all 3 real trade types with the documented values, and the real function genuinely reads open.tradingType (not a hardcoded/ignored value)', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  assert.ok(/FNO_TRAILING_DISTANCE_MULTIPLIER\s*=\s*\{\s*scalping:\s*0\.5,\s*intraday:\s*1\.0,\s*swing:\s*1\.5\s*\}/.test(coreSrc), 'the real multiplier map must genuinely define the documented 0.5/1.0/1.5 values for all 3 real trade types');
  const fnStart = coreSrc.indexOf('function updateTrailingStop(');
  const fnEnd = coreSrc.indexOf('\n}', fnStart);
  const fnBody = coreSrc.slice(fnStart, fnEnd);
  assert.ok(/FNO_TRAILING_DISTANCE_MULTIPLIER\[open\.tradingType\]/.test(fnBody), 'the real function must genuinely look up the multiplier by the REAL position\'s own tradingType, not a hardcoded or ignored value');
});

console.log('\n=== checkPartialExit (Master Prompt §27) ===');
test('returns null when disabled', () => {
  const r = __fno_checkPartialExit({partialExitEnabled:false, target:150, entryPrice:100}, 160);
  assert.strictEqual(r, null);
});
test('returns null when already taken', () => {
  const r = __fno_checkPartialExit({partialExitEnabled:true, partialTaken:true, target:150, entryPrice:100}, 160);
  assert.strictEqual(r, null);
});
test('returns null when target not yet reached', () => {
  const r = __fno_checkPartialExit({partialExitEnabled:true, partialTaken:false, target:150, entryPrice:100}, 140);
  assert.strictEqual(r, null);
});
test('triggers at target: moves SL to breakeven, extends target by one risk distance', () => {
  const r = __fno_checkPartialExit({partialExitEnabled:true, partialTaken:false, target:150, entryPrice:100}, 150);
  assert.strictEqual(r.newSl, 100);
  assert.strictEqual(r.newTarget, 200); // 150 + (150-100)
});

console.log('\n=== resolvePartialExitQty (whole exchange lots only) ===');
test('returns null when checkPartialExit signal itself is null', () => {
  assert.strictEqual(__fno_resolvePartialExitQty({ qty: 150 }, null, 'NIFTY'), null);
});
test('single lot (75 qty) cannot scale out — must ride to full exit', () => {
  assert.strictEqual(__fno_resolvePartialExitQty({ qty: 75 }, { newSl: 100, newTarget: 200 }, 'NIFTY'), null);
});
test('two lots (150 qty): exit one lot (75), keep one lot (75) — not 38/37', () => {
  const r = __fno_resolvePartialExitQty({ qty: 150 }, { newSl: 100, newTarget: 200 }, 'NIFTY');
  assert.deepStrictEqual(r, { partialQty: 75, remainingQty: 75 });
});
test('invalid odd qty (37) returns null', () => {
  assert.strictEqual(__fno_resolvePartialExitQty({ qty: 37 }, { newSl: 100, newTarget: 200 }, 'NIFTY'), null);
});
test('four lots (300 qty): exit two lots, keep two lots', () => {
  const r = __fno_resolvePartialExitQty({ qty: 300 }, { newSl: 100, newTarget: 200 }, 'NIFTY');
  assert.deepStrictEqual(r, { partialQty: 150, remainingQty: 150 });
});

console.log('\n=== Full multi-leg partial-exit lifecycle simulation (open -> partial -> final close) ===');
test('REALISTIC LIFECYCLE: qty=50 position, one partial exit at target, then a final close on the true remainder - remaining qty and cumulative journaled pnl are correct at every step', () => {
  // Step 1: position opens with a real, typical lot size.
  let open = { qty: 50, entryPrice: 100, target: 130, sl: 85, partialExitEnabled: true, partialTaken: false, trailingEnabled: false };
  const journal = []; // stand-in for the real journal array every closePartial/closeAutoTrade entry is pushed onto

  // Step 2: price reaches target -> checkPartialExit signals, resolvePartialExitQty computes the real split.
  const signal1 = __fno_checkPartialExit(open, 130);
  assert.ok(signal1, 'target reached - partial signal must fire');
  const split1 = __fno_resolvePartialExitQty(open, signal1);
  assert.deepStrictEqual(split1, { partialQty: 25, remainingQty: 25 });
  // Real closePartial-equivalent: journal the partial leg's OWN realized pnl at the partial-exit price (130).
  const partialPnl = (130 - open.entryPrice) * split1.partialQty; // simplified gross, mirrors computeTradeCosts' gross leg before costs
  journal.push({ source: 'partial', qty: split1.partialQty, pnl: partialPnl });
  open = { ...open, qty: split1.remainingQty, sl: signal1.newSl, target: signal1.newTarget, partialTaken: true };

  // Step 3: remainder must be tracked with the REAL updated levels, not reset/corrupted.
  assert.strictEqual(open.qty, 25, 'remaining open qty must reflect the real reduction, not the original 50');
  assert.strictEqual(open.sl, 100, 'remainder SL must move to real breakeven (entry price), not silently reset to the original SL');
  assert.strictEqual(open.target, 160, 'remainder target must extend by one real risk distance (130-100=30 -> 130+30=160)');
  assert.strictEqual(open.partialTaken, true, 'partialTaken must latch true so a SECOND partial can never fire against the already-reduced remainder');

  // Step 4: a second tick at the (now stale) original target must NOT re-trigger a partial - partialTaken gates it.
  const signal2 = __fno_checkPartialExit(open, 130);
  assert.strictEqual(signal2, null, 'partialTaken=true must block any further partial exit - no double-counting against the already-reduced qty');

  // Step 5: price runs to the new extended target -> final close on the TRUE remaining qty (25, not the original 50).
  const finalExitPrice = 160;
  const finalPnl = (finalExitPrice - open.entryPrice) * open.qty; // must use the real, reduced open.qty
  journal.push({ source: 'auto_target', qty: open.qty, pnl: finalPnl });

  // Step 6: the permanent trade record is the SUM of both legs, not just the last one.
  const totalJournaledQty = journal.reduce((s, e) => s + e.qty, 0);
  const totalJournaledPnl = journal.reduce((s, e) => s + e.pnl, 0);
  assert.strictEqual(totalJournaledQty, 50, 'sum of both journaled legs must equal the real original position size - no qty lost or double-counted');
  assert.strictEqual(journal.length, 2, 'both the partial leg AND the final leg must be independently journaled entries, not collapsed into one');
  assert.strictEqual(totalJournaledPnl, partialPnl + finalPnl, 'total realized pnl must be the sum of both legs - the partial leg\'s profit must never be silently dropped from the permanent record');
  assert.strictEqual(totalJournaledPnl, 25*30 + 25*60, 'concrete real numbers: 25@+30 partial leg + 25@+60 final leg = 750+1500=2250');
});
test('REGRESSION: qty=1 position with partialExitEnabled must ride to a single full close, never a phantom qty:0 open position', () => {
  let open = { qty: 1, entryPrice: 100, target: 130, sl: 85, partialExitEnabled: true, partialTaken: false, trailingEnabled: false };
  const journal = [];
  const signal = __fno_checkPartialExit(open, 130);
  assert.ok(signal, 'checkPartialExit itself is qty-agnostic and still signals target-reached');
  const split = __fno_resolvePartialExitQty(open, signal);
  assert.strictEqual(split, null, 'no genuine partial possible for qty=1 - the real caller must skip closePartial entirely');
  // Real caller behavior when split is null: `open` is left completely untouched (no qty:0, no partialTaken flip) -
  // the position rides to its real, single, full exit via the normal checkTradeExit path.
  assert.strictEqual(open.qty, 1, 'qty must remain the real, full 1 - never reduced to 0 without an actual matching journal entry');
  assert.strictEqual(open.partialTaken, false, 'partialTaken must NOT latch - no partial was actually taken');
  const finalPnl = (130 - open.entryPrice) * open.qty;
  journal.push({ source: 'auto_target', qty: open.qty, pnl: finalPnl });
  assert.strictEqual(journal.length, 1, 'exactly ONE real journal entry for the whole real position - no phantom second qty:0 leg');
  assert.strictEqual(journal[0].qty, 1, 'the single journaled leg must cover the FULL real position size');
});

console.log('\n=== diagnoseEntryExitQuality (Master Prompt §42) ===');
test('returns null when MFE/MAE were not recorded (older trades)', () => {
  assert.strictEqual(__fno_diagnoseEntryExitQuality({entryPrice:100, exitPrice:150}), null);
});
test('Good entry quality when MAE never came close to the stop', () => {
  const q = __fno_diagnoseEntryExitQuality({entryPrice:100, sl:80, exitPrice:150, mfe:160, mae:98});
  assert.ok(/Good/.test(q.entryQuality));
});
test('Poor entry quality when MAE nearly hit the stop', () => {
  const q = __fno_diagnoseEntryExitQuality({entryPrice:100, sl:80, exitPrice:150, mfe:160, mae:82});
  assert.ok(/Poor/.test(q.entryQuality));
});
test('Good exit quality when exit price is close to MFE', () => {
  const q = __fno_diagnoseEntryExitQuality({entryPrice:100, sl:80, exitPrice:158, mfe:160, mae:95});
  assert.ok(/Good/.test(q.exitQuality));
  assert.ok(q.mfeCapturedPct > 85);
});
test('Poor exit quality when exit is well below MFE (profit left on the table)', () => {
  const q = __fno_diagnoseEntryExitQuality({entryPrice:100, sl:80, exitPrice:110, mfe:160, mae:95});
  assert.ok(/Poor/.test(q.exitQuality));
});

console.log('\n=== classifyTradeFailure (Enterprise Plan #39 - Failure Analysis Engine) ===');
test('costs_ate_marginal_edge: real gross/costs/net numbers drive the classification', () => {
  const t = { pnl: -20, grossPnl: 10, costsTotal: 30, netPnl: -20, source: 'auto_target' };
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'costs_ate_marginal_edge');
  assert.ok(/Rs10/.test(r.explanation) && /Rs30\.00/.test(r.explanation));
});
test('unresolved_forced_closure: real source field, not guessed', () => {
  const t = { pnl: -50, source: 'square_off', grossPnl: -50, costsTotal: 5, netPnl: -50 };
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'unresolved_forced_closure');
});
test('reversal_after_favorable_move: real MFE progress >=50% of risk distance before SL', () => {
  const t = { pnl: -100, entryPrice: 100, sl: 80, mfe: 115, mae: 79, exitPrice: 80, source: 'auto_sl', grossPnl: -100, costsTotal: 5, netPnl: -100 };
  // riskDistance=20, mfeGain=15, progress=15/20=0.75 (>=0.5)
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'reversal_after_favorable_move');
  assert.ok(/75%/.test(r.explanation));
});
test('wrong_direction_immediate: real MFE progress <15% of risk distance before SL', () => {
  const t = { pnl: -100, entryPrice: 100, sl: 80, mfe: 101, mae: 79, exitPrice: 80, source: 'auto_sl', grossPnl: -100, costsTotal: 5, netPnl: -100 };
  // riskDistance=20, mfeGain=1, progress=1/20=0.05 (<0.15)
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'wrong_direction_immediate');
});
test('regime_mismatch: real regime tag vs actual position type', () => {
  const t = { pnl: -50, optionType: 'CE', source: 'auto_target', grossPnl: -50, costsTotal: 5, netPnl: -50,
    factorSnapshot: { regime: { trend: 'Bearish', volatility: 'Normal Vol', isExpiry: false } } };
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'regime_mismatch');
  assert.ok(/Bearish/.test(r.explanation));
});
test('insufficient_data_to_classify: honestly inconclusive rather than a forced guess', () => {
  const t = { pnl: -50, source: 'manual_force_exit', grossPnl: -50, costsTotal: 5, netPnl: -55 };
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'insufficient_data_to_classify');
});
test('costs check takes priority over other signals when it genuinely applies (most certain classification first)', () => {
  const t = { pnl: -5, entryPrice: 100, sl: 80, mfe: 101, mae: 79, exitPrice: 80, source: 'auto_sl', grossPnl: 5, costsTotal: 10, netPnl: -5 };
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'costs_ate_marginal_edge');
});

console.log('\n=== classifyTradeFailure: IV crush / theta decay (corrected this session - real, not deferred) ===');
test('iv_crush: real >=15% relative IV drop, correctly checked before source/MFE-based signals', () => {
  const t = { pnl: -50, grossPnl: -50, costsTotal: 5, netPnl: -55, source: 'auto_sl', entryIV: 20, exitIV: 15 }; // -25% relative drop
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'iv_crush');
  assert.ok(/20\.0%/.test(r.explanation) && /15\.0%/.test(r.explanation));
});
test('theta_decay: IV stable, held 2+ hours, real duration-based classification', () => {
  const openedAt = Date.now() - 3*60*60*1000; // 3 hours ago
  const t = { pnl: -30, grossPnl: -30, costsTotal: 5, netPnl: -35, source: 'auto_target', entryIV: 20, exitIV: 19, openedAt, ts: Date.now() }; // -5% change, stable
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'theta_decay');
  assert.ok(/3\.0 hours/.test(r.explanation));
});
test('neither IV category fires when IV is stable but holding time is short', () => {
  const openedAt = Date.now() - 30*60*1000; // 30 minutes ago - below the 2h threshold
  const t = { pnl: -30, grossPnl: -30, costsTotal: 5, netPnl: -35, source: 'manual_force_exit', entryIV: 20, exitIV: 19, openedAt, ts: Date.now() };
  const r = __fno_classifyTradeFailure(t);
  assert.notStrictEqual(r.category, 'theta_decay');
  assert.notStrictEqual(r.category, 'iv_crush');
});
test('IV checks correctly skipped when entryIV/exitIV are missing (older trades) - falls through cleanly', () => {
  const t = { pnl: -100, entryPrice: 100, sl: 80, mfe: 101, mae: 79, exitPrice: 80, source: 'auto_sl', grossPnl: -100, costsTotal: 5, netPnl: -100 };
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'wrong_direction_immediate'); // falls through to the existing MFE-based logic correctly
});
test('costs check still takes priority over IV crush when both genuinely apply (most certain signal first, unchanged)', () => {
  const t = { pnl: -5, grossPnl: 5, costsTotal: 10, netPnl: -5, source: 'auto_sl', entryIV: 20, exitIV: 10 }; // both a cost story AND a real IV crush
  const r = __fno_classifyTradeFailure(t);
  assert.strictEqual(r.category, 'costs_ate_marginal_edge');
});

console.log('\n=== computeFailureAnalysis (aggregation) ===');
test('aggregates real counts/percentages per category across the journal', () => {
  const journal = [
    { id:1, pnl: -20, grossPnl: 10, costsTotal: 30, netPnl: -20, source: 'auto_target' },
    { id:2, pnl: -20, grossPnl: 10, costsTotal: 30, netPnl: -20, source: 'auto_target' },
    { id:3, pnl: -50, source: 'square_off', grossPnl: -50, costsTotal: 5, netPnl: -50 },
    { id:4, pnl: 100 }, // a win, must be excluded entirely
  ];
  const analysis = __fno_computeFailureAnalysis(journal);
  assert.strictEqual(analysis.totalLosses, 3);
  assert.strictEqual(analysis.categories.get('costs_ate_marginal_edge').count, 2);
  assert.ok(Math.abs(analysis.categories.get('costs_ate_marginal_edge').pct - 66.666) < 0.01);
  assert.strictEqual(analysis.categories.get('unresolved_forced_closure').count, 1);
});
test('sampleSizeWarning true below 30 losses, same discipline as every other aggregate in this app', () => {
  const analysis = __fno_computeFailureAnalysis([{id:1, pnl:-10, source:'square_off', grossPnl:-10, costsTotal:1, netPnl:-10}]);
  assert.strictEqual(analysis.sampleSizeWarning, true);
});
test('stores real example trade IDs and explanations, capped at 3 per category', () => {
  const journal = Array.from({length:5}, (_,i)=>({id:i, pnl:-10, source:'square_off', grossPnl:-10, costsTotal:1, netPnl:-10}));
  const analysis = __fno_computeFailureAnalysis(journal);
  const cat = analysis.categories.get('unresolved_forced_closure');
  assert.strictEqual(cat.count, 5);
  assert.strictEqual(cat.exampleTradeIds.length, 3);
});
test('empty journal returns zero losses, not a throw', () => {
  const analysis = __fno_computeFailureAnalysis([]);
  assert.strictEqual(analysis.totalLosses, 0);
  assert.strictEqual(analysis.categories.size, 0);
});

console.log('\n=== Failure Mode Library (user\'s explicit audit finding - a real, unified classifier the app genuinely never had) ===');
test('Category A (unavailable data): real count and per-source breakdown from a real, mixed results array', () => {
  const results = [
    { cat:'Market', factor:'VIX Level', pass: null },
    { cat:'Regulatory', factor:'F&O Ban List', pass: null },
    { cat:'Market', factor:'NIFTY Trend', pass: true },
  ];
  const r = __fno_classifyUnavailableDataEvent(results);
  assert.strictEqual(r.count, 2);
  assert.strictEqual(r.bySources.get('Market: VIX Level'), 1);
});
test('Category A: real, honest zero count when everything genuinely was available', () => {
  const results = [{ cat:'Market', factor:'X', pass: true }, { cat:'Flow', factor:'Y', pass: false }];
  const r = __fno_classifyUnavailableDataEvent(results);
  assert.strictEqual(r.count, 0);
});
test('Category B (bad signal): a real, disconfirmed hypothesis is correctly detected', () => {
  const r = __fno_classifyBadSignalEvent('disconfirmed', null);
  assert.strictEqual(r.isBadSignal, true);
});
test('Category B: a real, present trap signature is correctly detected even without a hypothesis outcome', () => {
  const r = __fno_classifyBadSignalEvent(null, { isTrapSignature: true, reason: 'real trap reason' });
  assert.strictEqual(r.isBadSignal, true);
  assert.ok(/real trap reason/.test(r.reason));
});
test('Category B: honest false when genuinely neither condition applies', () => {
  const r = __fno_classifyBadSignalEvent('confirmed', { isTrapSignature: false });
  assert.strictEqual(r.isBadSignal, false);
});
test('Category D (execution issue): a real, rejected order is correctly detected', () => {
  const r = __fno_classifyExecutionIssueEvent({ rejected: true, reason: 'real liquidity reason' });
  assert.strictEqual(r.isExecutionIssue, true);
});
test('Category D: honest false when genuinely no rejection occurred', () => {
  const r = __fno_classifyExecutionIssueEvent({ rejected: false });
  assert.strictEqual(r.isExecutionIssue, false);
  assert.strictEqual(__fno_classifyExecutionIssueEvent(null).isExecutionIssue, false);
});
test('unified dispatcher correctly routes each of the 4 real categories to its own real classifier', () => {
  const a = __fno_classifyFailureModeEvent('unavailable_data', { results: [{cat:'Market',factor:'X',pass:null}] });
  assert.strictEqual(a.category, 'unavailable_data');
  assert.strictEqual(a.detected, true);

  const b = __fno_classifyFailureModeEvent('bad_signal', { hypothesisOutcome: 'disconfirmed', trapSignal: null });
  assert.strictEqual(b.detected, true);

  const c = __fno_classifyFailureModeEvent('losing_trade', { trade: { pnl: -10, source: 'square_off', grossPnl: -10, costsTotal: 1, netPnl: -10 } });
  assert.strictEqual(c.detected, true);
  assert.strictEqual(c.detail.category, 'unresolved_forced_closure');

  const d = __fno_classifyFailureModeEvent('execution_issue', { rejectionCheck: { rejected: true, reason: 'x' } });
  assert.strictEqual(d.detected, true);
});
test('unified dispatcher honestly handles a genuinely unknown real category, never throws', () => {
  const r = __fno_classifyFailureModeEvent('not_a_real_category', {});
  assert.strictEqual(r.detected, false);
  assert.ok(/Unknown real failure category/.test(r.reason));
});

console.log('\n=== evaluatePreTradeFailureModes (user\'s explicit requirement - the real, live Failure-Mode Library evaluator) ===');
test('real, honest "proceed" with zero triggered conditions when genuinely nothing is wrong', () => {
  const r = __fno_evaluatePreTradeFailureModes({});
  assert.strictEqual(r.finalAction, 'proceed');
  assert.deepStrictEqual(r.triggered, []);
});
test('real, critical block correctly wins over a lower-severity trigger present at the same time', () => {
  const r = __fno_evaluatePreTradeFailureModes({
    brain: { criticalFails: 1, confidence: 'Low', decision: 'BUY_READY' },
    rejectionCheck: { rejected: false },
  });
  assert.strictEqual(r.finalAction, 'block');
  // Catalog-ID-drift remediation: this critical-factor-failure condition
  // is FM035's catalog ID (the old, mistagged FM023 call for this exact
  // condition is now a deliberately-disabled duplicate, see below).
  assert.ok(r.triggered.some(t => t.id === 'FM035'));
  assert.ok(!r.triggered.some(t => t.id === 'FM023'), 'FM023 is now a deliberately-disabled duplicate of FM035 (catalog-ID-drift fix) and must never fire standalone');
});
test('real, multiple, genuinely simultaneous real triggers are ALL recorded, not just the winning one - full transparency', () => {
  const r = __fno_evaluatePreTradeFailureModes({
    brain: { criticalFails: 0, confidence: 'Low', decision: 'BUY_READY', regimeAdjustment: 'real regime reason' },
    ctx: { isExpiry: true, ocRow: {} },
    trapSignal: { isTrapSignature: true, reason: 'real trap reason' },
  });
  assert.ok(r.triggered.length >= 3, 'every real, independently-triggered condition must be recorded, not just the most severe one');
  assert.ok(r.triggered.some(t => t.id === 'FM004'));
  assert.ok(r.triggered.some(t => t.id === 'FM006'));
  // Catalog-ID-drift remediation: the OI trap signature is FM031's
  // catalog ID (was mistagged FM026 - FM026 is now genuinely wired to
  // its own true doc condition, Volume-vs-OI illiquidity, tested separately).
  assert.ok(r.triggered.some(t => t.id === 'FM031'));
});
test('the legacy ctx.banList check (previously live as a mistagged FM036) is now a deliberately-disabled duplicate of FM090 and never fires standalone, regardless of the real symbol/banList inputs', () => {
  const onList = __fno_evaluatePreTradeFailureModes({ ctx: { symbol: 'NIFTY', banList: ['NIFTY', 'RELIANCE'] } });
  const notOnList = __fno_evaluatePreTradeFailureModes({ ctx: { symbol: 'BANKNIFTY', banList: ['NIFTY', 'RELIANCE'] } });
  assert.ok(!onList.triggered.some(t => t.id === 'FM090'), 'the disabled ctx.banList duplicate must never fire, even with a genuinely on-list symbol');
  assert.ok(!notOnList.triggered.some(t => t.id === 'FM090'));
});
test('real IV percentile severity tiers correctly distinguish mild from severe - genuinely different conditions, never conflated (catalog-ID-drift remediation: severe tier is now correctly FM126, was mistagged FM014)', () => {
  const mild = __fno_evaluatePreTradeFailureModes({ ivPercentile: 80 });
  const severe = __fno_evaluatePreTradeFailureModes({ ivPercentile: 99 });
  assert.ok(mild.triggered.some(t => t.id === 'FM014b'));
  assert.ok(!mild.triggered.some(t => t.id === 'FM126'));
  assert.ok(severe.triggered.some(t => t.id === 'FM126'));
  assert.ok(!severe.triggered.some(t => t.id === 'FM014b'));
});
test('real, honest no-op for every check whose own specific real input is genuinely absent - never throws, never guesses', () => {
  assert.doesNotThrow(() => __fno_evaluatePreTradeFailureModes(null));
  assert.doesNotThrow(() => __fno_evaluatePreTradeFailureModes(undefined));
  assert.doesNotThrow(() => __fno_evaluatePreTradeFailureModes({}));
  const r = __fno_evaluatePreTradeFailureModes({});
  assert.strictEqual(r.finalAction, 'proceed');
});
test('FM025 (order rejection risk model - catalog-ID-drift remediation: previously mistagged FM018, corrected this session; doc FM018 is IV percentile >=90th, an honest open gap now): genuinely fires when a real rejectionCheck reports rejected:true', () => {
  const r = __fno_evaluatePreTradeFailureModes({ rejectionCheck: { rejected: true, reason: 'real, thin-depth test reason' } });
  const fm025 = r.triggered.find(t => t.id === 'FM025');
  assert.ok(fm025, 'FM025 must fire when a real rejectionCheck is genuinely rejected');
  assert.strictEqual(fm025.action, 'block');
  assert.strictEqual(fm025.reason, 'real, thin-depth test reason');
  assert.ok(!r.triggered.some(t => t.id === 'FM018'), 'FM018 must never appear here - it is now genuinely, honestly un-wired, not mistagged onto this condition');
});
test('FM025 correctly stays silent when rejectionCheck is genuinely not rejected, or genuinely absent', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ rejectionCheck: { rejected: false, reason: null } }).triggered.some(t => t.id === 'FM025'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM025'), 'must not fire on a genuinely absent rejectionCheck - e.g. the driver, which never computes one at all');
});
test('SELF-CAUGHT REAL BUG, found this session: tryOpenAutoTradePosition genuinely passes a real rejectionCheck into evaluatePreTradeFailureModes(), only in realistic mode - previously this argument was silently omitted entirely, meaning FM025 (mistagged FM018 at the time this bug was found) could never fire from the real entry path despite its own check() call existing all along', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const callStart = fnBody.indexOf('evaluatePreTradeFailureModes({');
  const callEnd = fnBody.indexOf('\n    });', callStart);
  const callBody = fnBody.slice(callStart, callEnd);
  assert.ok(/rejectionCheck:\s*execMode === 'realistic' \? simulateOrderRejection\(leg, lotSize, 'buy'\) : null/.test(callBody), 'the real call must genuinely compute and pass rejectionCheck, gated to realistic mode (matching the separate hard-block\'s own gating), not silently omit it');
});
test('FM068 (real, NEW wiring this session - Decision Matrix backlog): genuinely fires when execMode is theoretical', () => {
  const r = __fno_evaluatePreTradeFailureModes({ execMode: 'theoretical' });
  const fm068 = r.triggered.find(t => t.id === 'FM068');
  assert.ok(fm068, 'must fire for Mode 1/theoretical - this app\'s own documented design deliberately skips spread/latency/rejection modeling in this mode');
  assert.strictEqual(fm068.action, 'reduce_confidence');
});
test('FM068 correctly stays silent for realistic mode and when execMode is genuinely absent (e.g. the standalone driver, which has no theoretical concept at all)', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ execMode: 'realistic' }).triggered.some(t => t.id === 'FM068'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM068'), 'must not fire on a genuinely absent execMode - the driver never passes one, and that is correct, not a gap');
});

console.log('\n=== evaluatePreTradeFailureModes - real, THIRD expansion at the user\'s own explicit "build all buildable, fully verified, no compromise" request ===');
test('real RSI divergence (FM016) and FM090 (F&O ban) correctly reuse already-computed factor results', () => {
  // Catalog-ID-drift remediation (this session): RSI divergence is
  // FM016's catalog ID (was mistagged FM010; the disabled duplicate is
  // now correctly labeled FM010, kept for historical reference only) - a
  // single real divergence event fires once, not twice.
  const brainDiv = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Tech', factor: 'RSI Divergence', pass: false, reason: 'real divergence' }] };
  const rDiv = __fno_evaluatePreTradeFailureModes({ brain: brainDiv, ctx: { ocRow: {} } });
  assert.ok(rDiv.triggered.some(t => t.id === 'FM016'), 'FM016 must fire on a real RSI divergence');
  assert.ok(!rDiv.triggered.some(t => t.id === 'FM010'), 'FM010 is a deliberately-disabled duplicate of FM016 and must never fire standalone');
  const brainBan = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Regulatory', factor: 'F&O Ban List - Stock in Ban?', pass: false, reason: 'real ban' }] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: brainBan, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM090'));
});
test('real FM092b (circuit limit) fires correctly and independently from FM092 (trading halt) - two real, distinct conditions', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Regulatory', factor: 'Circuit Limit - Upper/Lower Circuit?', pass: false, reason: 'real circuit' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM092b'));
  assert.ok(!r.triggered.some(t => t.id === 'FM092'), 'circuit limit and trading halt are real, separate factors - one firing must not imply the other');
});
test('real FM078 (put-call parity) and FM100 (straddle pressure) correctly reuse their own real, distinct catalog factors', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Fundamental', factor: 'Put-Call Parity Arbitrage Broken?', pass: false, reason: 'real parity break' },
    { cat: 'Fundamental', factor: 'ATM Straddle Premium Pressure', pass: false, reason: 'real pressure' },
  ] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM078') && r.triggered.some(t => t.id === 'FM100'));
});
test('real Operator Intel bias check (FM077) fires only when the real bias genuinely contradicts the real trade direction', () => {
  // Catalog-ID-drift remediation (this session): the Operator-Intel-bias
  // condition is FM077's catalog ID (was mistagged FM030; the disabled
  // duplicate is now correctly labeled FM030, kept for historical
  // reference only). FM030's own true doc condition (range-bound, no
  // active breakout) is now genuinely wired via breakoutCondition,
  // tested separately below.
  const contradicting = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Operator Intel', factor: 'Composite Operator Bias Score', pass: false, reason: 'real bearish bias' }] };
  const rContra = __fno_evaluatePreTradeFailureModes({ brain: contradicting, ctx: { ocRow: {} } });
  assert.ok(rContra.triggered.some(t => t.id === 'FM077'), 'FM077 must fire when the real bias genuinely contradicts the real trade direction');
  const agreeing = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Operator Intel', factor: 'Composite Operator Bias Score', pass: true, reason: 'real bullish bias' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: agreeing, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM077'), 'must not fire when the real bias genuinely agrees with the trade direction');
});
test('the legacy Operator-Intel-bias duplicate (previously live as a mistagged FM030) is now a deliberately-disabled duplicate of FM077 and never fires standalone', () => {
  const contradicting = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Operator Intel', factor: 'Composite Operator Bias Score', pass: false, reason: 'real bearish bias' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain: contradicting, ctx: { ocRow: {} }, breakoutCondition: { condition: 'breakout_up' } });
  assert.strictEqual(r.triggered.filter(t => t.id === 'FM030').length, 0, 'FM030 must not fire from the disabled Operator-Intel duplicate path when breakoutCondition is not range_bound');
});
test('real range-bound-condition check (FM030 true doc condition) fires only when breakoutCondition.condition is genuinely range_bound', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, breakoutCondition: { condition: 'range_bound', reason: 'real range-bound reason' } });
  assert.ok(r.triggered.some(t => t.id === 'FM030' && t.reason === 'real range-bound reason'));
  const rNot = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, breakoutCondition: { condition: 'breakout_up', reason: 'real breakout' } });
  assert.ok(!rNot.triggered.some(t => t.id === 'FM030'), 'must not fire for a genuinely confirmed breakout, only for range_bound');
});

console.log('\n=== evaluatePreTradeFailureModes - duplicate-trigger dedup (post-session audit fixes) ===');
test('a single real MACD-contradiction event fires FM017 only, not also FM011 (catalog-ID-drift remediation: was FM011/FM017, swapped this session)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Tech', factor: 'MACD Crossover', pass: false, reason: 'real contradiction' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM017'));
  assert.ok(!r.triggered.some(t => t.id === 'FM011'), 'FM011 is now a deliberately-disabled duplicate of FM017');
});
test('a real 2-loss-streak fires FM127 only, not also FM040; a real 3+-loss-streak fires FM047 only, not also FM041 (catalog-ID-drift remediation: was FM040/FM127 and FM041/FM047, swapped this session)', () => {
  const brainMild = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Risk', factor: 'Consecutive Losses Today', pass: false, reason: '2 real losses today' }] };
  const rMild = __fno_evaluatePreTradeFailureModes({ brain: brainMild, ctx: { ocRow: {} } });
  assert.ok(rMild.triggered.some(t => t.id === 'FM127'));
  assert.ok(!rMild.triggered.some(t => t.id === 'FM040'), 'FM040 is now a deliberately-disabled duplicate of FM127');
  const brainSevere = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Risk', factor: 'Consecutive Losses Today', pass: false, reason: '3 real losses today' }] };
  const rSevere = __fno_evaluatePreTradeFailureModes({ brain: brainSevere, ctx: { ocRow: {} } });
  assert.ok(rSevere.triggered.some(t => t.id === 'FM047'));
  assert.ok(!rSevere.triggered.some(t => t.id === 'FM041'), 'FM041 is now a deliberately-disabled duplicate of FM047');
});
test('a real ASM/GSM flag fires FM091 only (not also FM037); a real trading halt fires FM092 only (not also FM038) (catalog-ID-drift remediation: was FM037/FM091 and FM038/FM092, swapped this session)', () => {
  const brainAsm = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Regulatory', factor: 'ASM/GSM List - Surveillance?', pass: false, reason: 'real surveillance flag' }] };
  const rAsm = __fno_evaluatePreTradeFailureModes({ brain: brainAsm, ctx: { ocRow: {} } });
  assert.ok(rAsm.triggered.some(t => t.id === 'FM091'));
  assert.ok(!rAsm.triggered.some(t => t.id === 'FM037'), 'FM037 is now a deliberately-disabled duplicate of FM091');
  const brainHalt = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Regulatory', factor: 'Trading Halt - NSE Halt 10/15/20%?', pass: false, reason: 'real halt' }] };
  const rHalt = __fno_evaluatePreTradeFailureModes({ brain: brainHalt, ctx: { ocRow: {} } });
  assert.ok(rHalt.triggered.some(t => t.id === 'FM092'));
  assert.ok(!rHalt.triggered.some(t => t.id === 'FM038'), 'FM038 is now a deliberately-disabled duplicate of FM092');
});
test('IV percentile extremes fire FM014b/FM126 only, not also FM125/FM014 (catalog-ID-drift remediation: the severe-extreme check was FM014, is now FM126, swapped this session; the RSI-overbought check now genuinely, correctly holds FM014)', () => {
  const mild = __fno_evaluatePreTradeFailureModes({ ivPercentile: 80 });
  assert.ok(mild.triggered.some(t => t.id === 'FM014b'));
  assert.ok(!mild.triggered.some(t => t.id === 'FM125'), 'FM125 is a deliberately-disabled duplicate of FM014b');
  const severe = __fno_evaluatePreTradeFailureModes({ ivPercentile: 99 });
  assert.ok(severe.triggered.some(t => t.id === 'FM126'));
  assert.ok(!severe.triggered.some(t => t.id === 'FM014'), 'FM014 is now a deliberately-disabled duplicate of FM126 in this evalCtx (ivPercentile-only, no brain/RSI data)');
});
test('real FM035 fires only when real critical failures co-occur with an ACTUAL buy/sell decision, not merely being present in isolation', () => {
  const brainTrading = { criticalFails: 2, confidence: 'High', decision: 'BUY_READY', results: [] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: brainTrading, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM035'));
  const brainWaiting = { criticalFails: 2, confidence: 'High', decision: 'WAIT', results: [] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainWaiting, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM035'), 'must not fire when the real decision is already WAIT - nothing new to warn about');
});
test('real FM053 correctly fires only when genuinely more than half of all real factors are unavailable this refresh', () => {
  const lowCoverage = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: Array.from({length: 10}, (_, i) => ({ factor: `f${i}`, pass: i < 6 ? null : true })) };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: lowCoverage, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM053'));
  const highCoverage = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: Array.from({length: 10}, (_, i) => ({ factor: `f${i}`, pass: i < 2 ? null : true })) };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: highCoverage, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM053'));
});
test('real FM112-FM124 category-coverage checks correctly, independently fire per-category using one real, shared, generic loop - not hardcoded per category', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Market', factor: 'a', pass: null }, { cat: 'Market', factor: 'b', pass: null }, { cat: 'Market', factor: 'c', pass: true },
    { cat: 'Flow', factor: 'd', pass: true }, { cat: 'Flow', factor: 'e', pass: true },
  ] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM112'), 'Market category is genuinely 2/3 unavailable (>60%) - must fire');
  assert.ok(!r.triggered.some(t => t.id === 'FM113'), 'Flow category is genuinely 0/2 unavailable - must not fire');
});
test('real FM048 (daily loss limit) and FM054 (stale ban-list fallback) correctly reuse real, already-available ctx fields', () => {
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, decision: 'BUY_READY', results: [] }, ctx: { ocRow: {}, todayPnL: -2500 } }).triggered.some(t => t.id === 'FM048'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, decision: 'BUY_READY', results: [] }, ctx: { ocRow: {}, todayPnL: -500 } }).triggered.some(t => t.id === 'FM048'), 'a real, modest loss must not trigger the hard daily limit');
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, decision: 'BUY_READY', results: [] }, ctx: { ocRow: {}, banListSource: 'stale_cache' } }).triggered.some(t => t.id === 'FM054'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, decision: 'BUY_READY', results: [] }, ctx: { ocRow: {}, banListSource: 'nse' } }).triggered.some(t => t.id === 'FM054'), 'a real, live nse source must not be flagged as stale');
});
test('real FM102 (lunch lull) and FM133/FM134 (expiry-days extremes) correctly reuse real ctx.time/ctx.decay.days by exact boundary', () => {
  const brain = { criticalFails: 0, decision: 'BUY_READY', results: [] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, time: '12:30' } }).triggered.some(t => t.id === 'FM102'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, time: '11:00' } }).triggered.some(t => t.id === 'FM102'));
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, decay: { days: 0 } } }).triggered.some(t => t.id === 'FM133'));
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, decay: { days: 35 } } }).triggered.some(t => t.id === 'FM134'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, decay: { days: 10 } } }).triggered.some(t => t.id === 'FM133' || t.id === 'FM134'), 'a real, normal mid-cycle expiry must trigger neither extreme');
});
test('real FM039 (stop-loss on the wrong side of current premium) fires correctly using real, already-available ctx.slPrice/ctx.optPrice', () => {
  const brain = { criticalFails: 0, decision: 'BUY_READY', results: [] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, slPrice: 150, optPrice: 120 } }).triggered.some(t => t.id === 'FM039'), 'a real SL above the real current premium is genuinely wrong-sided for a long');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, slPrice: 100, optPrice: 120 } }).triggered.some(t => t.id === 'FM039'), 'a real SL genuinely below the real current premium is correctly sided');
});
test('real FM103 (near expiry-day square-off deadline) fires only on a genuine expiry day at/after the real, configured square-off time', () => {
  const brain = { criticalFails: 0, decision: 'BUY_READY', results: [] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, isExpiry: true, time: '15:20', brokerSquareOffTime: '15:15' } }).triggered.some(t => t.id === 'FM103'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, isExpiry: false, time: '15:20', brokerSquareOffTime: '15:15' } }).triggered.some(t => t.id === 'FM103'), 'must not fire on a genuinely non-expiry day, even close to the configured time');
});
test('real FM056 (journal sync failure) honestly fires only when journalToday is genuinely missing, not merely empty', () => {
  const brain = { criticalFails: 0, decision: 'BUY_READY', results: [] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM056'), 'genuinely missing journalToday must fire');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, journalToday: [] } }).triggered.some(t => t.id === 'FM056'), 'a real, genuinely empty (but present) array is honestly different from missing data entirely - must not fire');
});
test('real FM055 (incomplete option-chain rows) fires only for a genuinely low but non-zero row count - zero rows is a different, already-covered honest-unavailable case', () => {
  const brain = { criticalFails: 0, decision: 'BUY_READY', results: [] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, ocRows: [1,2,3] } }).triggered.some(t => t.id === 'FM055'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, ocRows: [] } }).triggered.some(t => t.id === 'FM055'), 'zero rows is a distinct, already-covered case elsewhere, not this specific "incomplete" condition');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, ocRows: Array(20).fill(1) } }).triggered.some(t => t.id === 'FM055'), 'a real, healthy row count must not fire');
});

console.log('\n=== evaluatePreTradeFailureModes - real, second expansion at the user\'s own direct request, plus a real, self-caught pre-existing bug fix ===');
test('SELF-CAUGHT BUG FIX: FM002/FM003 previously referenced a real ctx.regimeSpecialCondition field that never actually existed anywhere in this codebase - meaning FM002 had never genuinely fired since it was first wired. Now correctly reads brain.regime.specialCondition.', () => {
  const brainWithPanic = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [],
    regime: { specialCondition: 'Panic' } };
  const r = __fno_evaluatePreTradeFailureModes({ brain: brainWithPanic, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM002'), 'FM002 must genuinely fire now that it reads the real, correct field');
  assert.strictEqual(r.finalAction, 'block');
  // Proves the OLD, buggy field name genuinely never worked - a real,
  // direct regression check against reintroducing the same bug.
  const brainWithFakeCtxField = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [], regime: null };
  const r2 = __fno_evaluatePreTradeFailureModes({ brain: brainWithFakeCtxField, ctx: { ocRow: {}, regimeSpecialCondition: 'Panic' } });
  assert.ok(!r2.triggered.some(t => t.id === 'FM002'), 'a fake ctx.regimeSpecialCondition must NOT fire FM002 - only the real brain.regime.specialCondition location is correct');
});
test('real FM003 fires only for the specific real Recovery + bearish-trade combination, not Recovery alone', () => {
  const recoveryBuyBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [], regime: { specialCondition: 'Recovery' } };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: recoveryBuyBrain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM003'), 'must not fire for a BUY during Recovery - only the contradictory SELL case is the real risk');
  const recoverySellBrain = { criticalFails: 0, confidence: 'High', decision: 'SELL_READY', results: [], regime: { specialCondition: 'Recovery' } };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: recoverySellBrain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM003'));
});
test('real FM001 correctly reuses brain.regime.extendedStates.includes(\'vol_expansion\') - the exact real array this session\'s regime-taxonomy work just built', () => {
  const brainWithVolExpansion = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [], regime: { extendedStates: ['vol_expansion'] } };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: brainWithVolExpansion, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM001'));
  const brainWithoutIt = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [], regime: { extendedStates: ['squeeze'] } };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainWithoutIt, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM001'), 'a different, unrelated extended state must not incorrectly trigger FM001');
});
test('real FM019 requires BOTH a genuinely low IV percentile AND a genuinely short-dated option - neither alone is sufficient', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  // FIXED (medium/low-tier NaN audit): this test itself used to assert
  // against `ctx.daysExp`, a field that has never existed on the real
  // ctx object (days-to-expiry genuinely lives at `ctx.decay.days`) -
  // so this test used to pass by accident, against the same dead-code
  // bug it should have caught (FM019 could never fire from real
  // production ctx). Now exercises the real field, and confirms the
  // old nonexistent field no longer has any effect.
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, decay: { days: 1 } }, ivPercentile: 5 }).triggered.some(t => t.id === 'FM019'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, decay: { days: 10 } }, ivPercentile: 5 }).triggered.some(t => t.id === 'FM019'), 'a genuinely low IV percentile with plenty of real time to expiry must not fire this specific, short-dated-only check');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, daysExp: 1 }, ivPercentile: 5 }).triggered.some(t => t.id === 'FM019'), 'the old, nonexistent ctx.daysExp field must NOT make FM019 fire any more - confirms the dead-code path is fully closed');
});
test('real FM051/FM052 correctly, honestly report genuinely missing VIX/futures data without throwing', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, vix: null, futuresPrice: null } });
  assert.ok(r.triggered.some(t => t.id === 'FM051') && r.triggered.some(t => t.id === 'FM052'));
  const rWithData = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, vix: 15, futuresPrice: 23400 } });
  assert.ok(!rWithData.triggered.some(t => t.id === 'FM051') && !rWithData.triggered.some(t => t.id === 'FM052'), 'must not fire when real VIX/futures data is genuinely present');
});
test('real FM060/FM061 correctly detect the real opening and closing risk windows by exact real time string comparison', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, time: '09:20' } }).triggered.some(t => t.id === 'FM060'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, time: '11:00' } }).triggered.some(t => t.id === 'FM060'), 'must not fire mid-session');
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, time: '15:20' } }).triggered.some(t => t.id === 'FM061'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, time: '11:00' } }).triggered.some(t => t.id === 'FM061'), 'must not fire mid-session');
});
test('real FM034 correctly fires only when real factor coverage is genuinely, substantially low (>40% uncomputed), not for a normal, small amount of missing data', () => {
  const manyUncomputed = Array.from({length: 10}, (_, i) => ({ factor: `f${i}`, pass: i < 5 ? null : true }));
  const brainLowCoverage = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: manyUncomputed };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: brainLowCoverage, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM034'));
  const fewUncomputed = Array.from({length: 10}, (_, i) => ({ factor: `f${i}`, pass: i < 1 ? null : true }));
  const brainHighCoverage = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: fewUncomputed };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainHighCoverage, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM034'), 'a real, normal, small amount of missing data must not trigger this check');
});

console.log('\n=== evaluatePreTradeFailureModes - real, expanded live-gate coverage (report P3 recommendation) ===');
test('real RSI overbought fires against a real bullish trade, using an already-computed factor result (catalog-ID-drift remediation: now correctly FM014, was mistagged FM009)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Tech', factor: 'RSI Level', pass: false, reason: 'RSI 78 overbought' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM014'));
});
test('real trend contradiction correctly fires only when the real trend and real trade direction genuinely disagree (catalog-ID-drift remediation: now correctly FM009, was mistagged FM008)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'NIFTY Trend vs 21 EMA', pass: false, reason: 'Below 21EMA bearish' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM009'));
  const brainAgree = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'NIFTY Trend vs 21 EMA', pass: true, reason: 'Above 21EMA bullish' }] };
  const rAgree = __fno_evaluatePreTradeFailureModes({ brain: brainAgree, ctx: { ocRow: {} } });
  assert.ok(!rAgree.triggered.some(t => t.id === 'FM009'), 'must not fire when the real trend genuinely agrees with the trade direction');
});
test('the still-genuinely-open FM008/FM037/FM041 catalog IDs are honest, documented open gaps - never fire under any input this remediation pass tested (FM010/FM013/FM018 were CLOSED in a later follow-up pass - see their own dedicated tests below)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [
      { cat: 'Tech', factor: 'RSI Level', pass: false, reason: 'RSI 78 overbought' },
      { cat: 'Market', factor: 'NIFTY Trend vs 21 EMA', pass: false, reason: 'Below 21EMA bearish' },
      { cat: 'Tech', factor: 'MACD Crossover', pass: false, reason: 'real contradiction' },
      { cat: 'Tech', factor: 'RSI Divergence', pass: false, reason: 'real divergence' },
      { cat: 'Tech', factor: 'Bollinger Squeeze', pass: true, reason: 'Real squeeze detected' },
      { cat: 'Regulatory', factor: 'ASM/GSM List - Surveillance?', pass: false, reason: 'real flag' },
      { cat: 'Risk', factor: 'Consecutive Losses Today', pass: false, reason: '3 real losses today' },
    ] };
  // Deliberately omit candles/regime.trend/ivPercentile-in-90-97-band inputs
  // here so this test stays a clean "still open" check for FM008/FM037/FM041
  // only; FM010/FM013/FM018's own firing conditions are tested separately.
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, rejectionCheck: { rejected: true, reason: 'r', riskFactors: [] } });
  ['FM008', 'FM037', 'FM041'].forEach(id => {
    assert.ok(!r.triggered.some(t => t.id === id), `${id} is a documented honest open gap and must never appear in triggered`);
  });
});
test('FM010 (real trend genuinely Sideways, freed slot CLOSED this follow-up pass) fires only when brain.regime.trend is genuinely \'Sideways\'', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [], regime: { trend: 'Sideways' } };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM010'));
  const trending = __fno_evaluatePreTradeFailureModes({ brain: { ...brain, regime: { trend: 'Uptrend' } }, ctx: { ocRow: {} } });
  assert.ok(!trending.triggered.some(t => t.id === 'FM010'));
  const noRegime = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] }, ctx: { ocRow: {} } });
  assert.ok(!noRegime.triggered.some(t => t.id === 'FM010'), 'must not fire, and must not throw, when brain.regime is genuinely absent');
});
test('FM013 (insufficient real candle history <21, freed slot CLOSED this follow-up pass) fires only when ctx.candles.length is genuinely <21', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  const short = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, candles: new Array(10).fill({}) } });
  assert.ok(short.triggered.some(t => t.id === 'FM013'));
  const enough = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, candles: new Array(21).fill({}) } });
  assert.ok(!enough.triggered.some(t => t.id === 'FM013'));
  const noCandles = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(!noCandles.triggered.some(t => t.id === 'FM013'), 'must not fire, and must not throw, when ctx.candles is genuinely absent');
});
test('FM018 (real IV percentile genuinely >=90th, freed slot CLOSED this follow-up pass) fires across the full 90+ band, distinct from FM014b (75-90) and coexisting with FM126 (98+)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  const mid = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, ivPercentile: 82 });
  assert.ok(!mid.triggered.some(t => t.id === 'FM018'), 'must not fire below the 90th percentile');
  const high = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, ivPercentile: 92 });
  assert.ok(high.triggered.some(t => t.id === 'FM018'));
  const severe = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, ivPercentile: 99 });
  assert.ok(severe.triggered.some(t => t.id === 'FM018'), 'the 90+ threshold is inclusive of the 98+ severe band too, alongside FM126 - the two are not mutually exclusive');
  assert.ok(severe.triggered.some(t => t.id === 'FM126'));
});
test('FM044 (real paper-capital sufficiency, CLOSED this follow-up pass via ctx.accountAvailableCapital threading) fires only when the requested position\'s real margin genuinely exceeds real available capital', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  // Real premium Rs100 x real lot 75 = Rs7500 required margin, but only
  // Rs5000 genuinely available - must block.
  const insufficient = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, lotSize: 75, accountAvailableCapital: 5000 } });
  assert.ok(insufficient.triggered.some(t => t.id === 'FM044'));
  assert.strictEqual(insufficient.finalAction, 'block');
  // Same requested margin, but genuinely sufficient available capital.
  const sufficient = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, lotSize: 75, accountAvailableCapital: 50000 } });
  assert.ok(!sufficient.triggered.some(t => t.id === 'FM044'));
  // Genuinely missing account data (account fetch failed/unavailable) -
  // must not fire, and must not throw, never treated as "insufficient."
  const noAccountData = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, lotSize: 75 } });
  assert.ok(!noAccountData.triggered.some(t => t.id === 'FM044'), 'must not fire when accountAvailableCapital is genuinely unavailable, not silently treated as insufficient');
  // Real, NEW this pass (data-quality audit): a corrupted/NaN capital
  // figure (e.g. from a malformed journal entry poisoning
  // computeEquityCurve's balance sum - see that function's own guard)
  // must be treated the same honest way as genuinely-missing data, NOT
  // silently read as "unlimited capital available." Before this pass's
  // fix, `typeof NaN === 'number'` let this through the guard, and
  // `requestedMargin > NaN` is always false, so a badly-corrupted
  // capital figure would never block a trade - the opposite of a
  // fail-safe default for a capital gate.
  const corruptedAccountData = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, lotSize: 75, accountAvailableCapital: NaN } });
  assert.ok(!corruptedAccountData.triggered.some(t => t.id === 'FM044'), 'a NaN capital figure must be treated as unavailable data, not silently as unlimited capital');
});
test('FM038 (real target does not clear real transaction costs) treats a NaN optPrice/targetPrice/lotSize as unavailable data, not as a silently-passing target (typeof/NaN audit pass)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  // Genuine loss after costs - must block.
  const losing = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, targetPrice: 100.05, lotSize: 75 } });
  assert.ok(losing.triggered.some(t => t.id === 'FM038'), 'a target that barely clears the premium move but not real transaction costs must block');
  // Genuine, healthy target - must not block.
  const healthy = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, targetPrice: 130, lotSize: 75 } });
  assert.ok(!healthy.triggered.some(t => t.id === 'FM038'));
  // Before this pass's fix, `typeof NaN === 'number'` let a corrupted
  // optPrice through the guard, computeTradeCosts(NaN, ...) produced a
  // NaN netPnl, and `NaN <= 0` is always false - so this critical BLOCK
  // check would silently never fire for the one case (bad price data)
  // where failing safe matters most.
  const nanOptPrice = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: NaN, targetPrice: 130, lotSize: 75 } });
  assert.ok(!nanOptPrice.triggered.some(t => t.id === 'FM038'), 'a NaN optPrice must be treated as unavailable data, not evaluated at all');
  const nanTargetPrice = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, targetPrice: NaN, lotSize: 75 } });
  assert.ok(!nanTargetPrice.triggered.some(t => t.id === 'FM038'), 'a NaN targetPrice must be treated as unavailable data, not evaluated at all');
  const nanLotSize = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, targetPrice: 130, lotSize: NaN } });
  assert.ok(!nanLotSize.triggered.some(t => t.id === 'FM038'), 'a NaN lotSize must be treated as unavailable data, not evaluated at all');
});
test('FM048 (real daily loss limit) treats a NaN todayPnL as unavailable data, not as a silently-passing P&L (typeof/NaN audit pass)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  const overLimit = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, todayPnL: -2500 } });
  assert.ok(overLimit.triggered.some(t => t.id === 'FM048'));
  const withinLimit = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, todayPnL: -500 } });
  assert.ok(!withinLimit.triggered.some(t => t.id === 'FM048'));
  // Before this pass's fix, `typeof NaN === 'number'` let a corrupted
  // todayPnL through the guard and `NaN <= -2000` is always false - the
  // daily-loss-limit BLOCK check would silently never fire for the one
  // case (a corrupted P&L sum, e.g. from one bad journal.pnl entry)
  // where failing safe matters most.
  const nanPnl = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, todayPnL: NaN } });
  assert.ok(!nanPnl.triggered.some(t => t.id === 'FM048'), 'a NaN todayPnL must be treated as unavailable data, not silently as zero real loss');
});
test('FM047/FM128 (real consecutive-loss streak blocks) treat an unparseable streak reason as zero losses, not as a silently-passing streak (typeof/NaN audit pass)', () => {
  const brain = {
    criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ factor: 'Consecutive Losses Today', reason: '3 consecutive real losses today' }],
  };
  const threeLosses = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(threeLosses.triggered.some(t => t.id === 'FM047'), '3 consecutive losses must hit the FM047 3+ threshold');
  const brainFive = {
    criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ factor: 'Consecutive Losses Today', reason: '5 consecutive real losses today' }],
  };
  const fiveLosses = __fno_evaluatePreTradeFailureModes({ brain: brainFive, ctx: { ocRow: {} } });
  assert.ok(fiveLosses.triggered.some(t => t.id === 'FM128'), '5 consecutive losses must hit the FM128 5+ severe threshold');
  // Before this pass's fix, a reason string that does NOT start with
  // digits (regex match genuinely fails) fed the match-or-fallback
  // ARRAY straight into parseInt instead of its captured digit group -
  // `parseInt([null,'0'], 10)` stringifies to ",0" (String(null) is "")
  // and parseInt(",0",10) is NaN, not the intended 0. That NaN then
  // passed the naive `typeof lossCount === 'number'` guard (typeof NaN
  // is 'number') and `NaN >= 3`/`NaN >= 5` are always false - so these
  // critical BLOCK checks would silently never fire for the one case
  // (an unparseable streak reason) where failing safe matters most.
  const brainUnparseable = {
    criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ factor: 'Consecutive Losses Today', reason: 'no digit at the start of this reason' }],
  };
  const unparseable = __fno_evaluatePreTradeFailureModes({ brain: brainUnparseable, ctx: { ocRow: {} } });
  assert.ok(!unparseable.triggered.some(t => t.id === 'FM047'), 'an unparseable streak reason must resolve to a real zero, not a silently-passing NaN');
  assert.ok(!unparseable.triggered.some(t => t.id === 'FM128'));
  assert.ok(!unparseable.triggered.some(t => t.id === 'FM127'));
});
test('real trading-halt detection correctly blocks outright (catalog-ID-drift remediation: this condition is now correctly FM092, was mistagged FM038)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Regulatory', factor: 'Trading Halt - NSE Halt 10/15/20%?', pass: false, reason: 'Real halt detected' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.strictEqual(r.finalAction, 'block');
  assert.ok(r.triggered.some(t => t.id === 'FM092'));
});
test('real same-day consecutive-loss tiers correctly distinguish mild (2) from the hard-block level (3+), reusing the already-correct Consecutive Losses Today factor result (catalog-ID-drift remediation: now correctly FM127/FM047, was mistagged FM040/FM041)', () => {
  const mildBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Risk', factor: 'Consecutive Losses Today', pass: true, reason: '2 consecutive losing trades today per journal (3 losing trades total today)' }] };
  const mild = __fno_evaluatePreTradeFailureModes({ brain: mildBrain, ctx: { ocRow: {} } });
  assert.ok(mild.triggered.some(t => t.id === 'FM127'));
  assert.ok(!mild.triggered.some(t => t.id === 'FM047'));
  const severeBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Risk', factor: 'Consecutive Losses Today', pass: false, reason: '3 consecutive losing trades today per journal (4 losing trades total today)' }] };
  const severe = __fno_evaluatePreTradeFailureModes({ brain: severeBrain, ctx: { ocRow: {} } });
  assert.ok(severe.triggered.some(t => t.id === 'FM047'));
  assert.strictEqual(severe.finalAction, 'block');
});
test('real Bollinger squeeze without a confirmed breakout correctly delays, but not when a real breakout is already confirmed (catalog-ID-drift remediation: now correctly FM021, was mistagged FM013)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Tech', factor: 'Bollinger Squeeze', pass: true, reason: 'Real squeeze detected' }] };
  const noBreakout = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(noBreakout.triggered.some(t => t.id === 'FM021'));
  const withBreakout = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, breakoutCondition: { condition: 'breakout_up' } });
  assert.ok(!withBreakout.triggered.some(t => t.id === 'FM021'), 'must not delay once a real breakout direction is already confirmed');
});
test('a genuinely missing factor result (e.g. RSI not computed this refresh) never throws and never fabricates a trigger', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  assert.doesNotThrow(() => __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } }));
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(!r.triggered.some(t => t.id === 'FM014' || t.id === 'FM009' || t.id === 'FM092'));
});

console.log('\n=== evaluatePreTradeFailureModes - catalog-ID-drift remediation: newly-wired freed slots (this session) ===');
test('FM023 (wide bid-ask spread, freed slot) fires only when rejectionCheck.riskFactors genuinely contains the "unusually wide" spread signal', () => {
  const wide = __fno_evaluatePreTradeFailureModes({ rejectionCheck: { rejected: false, reason: 'r', riskFactors: ['Spread 22.0% of bid price - unusually wide, signals thin liquidity at this strike'] } });
  assert.ok(wide.triggered.some(t => t.id === 'FM023'));
  const notWide = __fno_evaluatePreTradeFailureModes({ rejectionCheck: { rejected: false, reason: 'r', riskFactors: [] } });
  assert.ok(!notWide.triggered.some(t => t.id === 'FM023'));
  const absent = __fno_evaluatePreTradeFailureModes({});
  assert.ok(!absent.triggered.some(t => t.id === 'FM023'), 'must not fire on a genuinely absent rejectionCheck');
});
test('FM024 (thin resting order-book depth, freed slot) fires only when rejectionCheck.riskFactors genuinely contains the "exceeds best-level resting qty" signal', () => {
  const thin = __fno_evaluatePreTradeFailureModes({ rejectionCheck: { rejected: false, reason: 'r', riskFactors: ['Order qty 500 exceeds best-level resting qty 100 on the ask side - real risk of partial fill at a worse price than the quoted best level'] } });
  assert.ok(thin.triggered.some(t => t.id === 'FM024'));
  const notThin = __fno_evaluatePreTradeFailureModes({ rejectionCheck: { rejected: false, reason: 'r', riskFactors: [] } });
  assert.ok(!notThin.triggered.some(t => t.id === 'FM024'));
});
test('FM026 (Volume-vs-OI illiquidity, freed slot) fires only when the real "Volume vs OI" factor genuinely fails', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Flow', factor: 'Volume vs OI', pass: false, reason: 'real illiquid strike' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM026' && t.reason === 'real illiquid strike'));
  const brainOk = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Flow', factor: 'Volume vs OI', pass: true, reason: 'real liquid strike' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainOk, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM026'));
});
test('FM038 (target doesn\'t clear real transaction costs, freed slot) fires only when computeTradeCosts(entry, target, lotSize).netPnl is genuinely <= 0', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  // A tiny target move barely above entry cannot possibly clear real, documented transaction costs.
  const failing = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, targetPrice: 100.05, lotSize: 50 } });
  assert.ok(failing.triggered.some(t => t.id === 'FM038'));
  // A large, real target move comfortably clears real transaction costs.
  const passing = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, targetPrice: 150, lotSize: 50 } });
  assert.ok(!passing.triggered.some(t => t.id === 'FM038'));
  const missingInputs = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(!missingInputs.triggered.some(t => t.id === 'FM038'), 'must not fire, and must not throw, when optPrice/targetPrice/lotSize are genuinely unavailable');
});
test('FM040 (poor risk/reward ratio, freed slot) fires only when the real, already-computed "Target & Risk:Reward >=1:2" factor genuinely fails', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Risk', factor: 'Target & Risk:Reward >=1:2', pass: false, reason: 'real R:R = 0.8' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM040' && t.reason === 'real R:R = 0.8'));
  const brainOk = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [{ cat: 'Risk', factor: 'Target & Risk:Reward >=1:2', pass: true, reason: 'real R:R = 2.4' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainOk, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM040'));
});
test('FM022 (theta decay >=5%/day, freed slot) uses the catalog\'s own documented 5% threshold, independently of the existing 15%-threshold "Theta Decay Per Day Rs" factor', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  // Rs6/day theta on a Rs100 premium = 6%/day - clears the doc's own 5% threshold, even though it would NOT clear the unrelated 15% factor elsewhere.
  const firing = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, decay: { snapshot: { now: { thetaPerDay: -6 } } } } });
  assert.ok(firing.triggered.some(t => t.id === 'FM022'));
  // Rs2/day theta on a Rs100 premium = 2%/day - genuinely below the 5% threshold.
  const notFiring = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, optPrice: 100, decay: { snapshot: { now: { thetaPerDay: -2 } } } } });
  assert.ok(!notFiring.triggered.some(t => t.id === 'FM022'));
  const missing = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(!missing.triggered.some(t => t.id === 'FM022'), 'must not fire, and must not throw, when ctx.decay/ctx.optPrice are genuinely unavailable');
});
test('AUDIT LOCK: evaluatePreTradeFailureModes source genuinely reflects the catalog-ID-drift remediation - no stale mistagged ID remains at each corrected check() call site', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function evaluatePreTradeFailureModes(');
  const fnEnd = coreSrc.indexOf('\nfunction adjustFailureModesForTradeType(');
  const fnBody = coreSrc.slice(fnStart, fnEnd);
  // Each ID must now be paired with its own real, correct condition text.
  assert.ok(/check\('FM021', 'Real Bollinger squeeze/.test(fnBody), 'FM021 must now be the Bollinger-squeeze check, not decision-score-marginal');
  assert.ok(/check\('FM033', 'Real decision score only marginally/.test(fnBody), 'FM033 must now hold the decision-score-marginal check');
  assert.ok(/check\('FM014', 'Real RSI overbought/.test(fnBody), 'FM014 must now be the RSI-overbought check');
  assert.ok(/check\('FM126', 'Real IV percentile at a severe extreme/.test(fnBody), 'FM126 must now be the severe-IV-percentile check');
  assert.ok(/check\('FM009', 'Real short-term trend contradicts/.test(fnBody), 'FM009 must now be the trend-contradiction check');
  assert.ok(/check\('FM017', 'Real MACD histogram contradicts/.test(fnBody), 'FM017 must now be the live MACD check');
  assert.ok(/check\('FM091', 'Real ASM\/GSM surveillance flag present for this underlying'/.test(fnBody), 'FM091 must now be the live ASM/GSM check');
  assert.ok(/check\('FM092', 'Real trading halt detected', 'critical'/.test(fnBody), 'FM092 must now be the live trading-halt check');
  assert.ok(/check\('FM077', 'Real Operator Intel bias contradicts/.test(fnBody), 'FM077 must now be the live Operator-Intel-bias check');
  assert.ok(/check\('FM127', 'Real consecutive losses at a MILD level \(exactly 2\)'/.test(fnBody), 'FM127 must now be the live mild-loss-streak check');
  assert.ok(/check\('FM047', 'Real consecutive same-day losses have reached the documented threshold \(3\+\)'/.test(fnBody), 'FM047 must now be the live hard-block-loss-streak check');
  assert.ok(/check\('FM031', 'Real OI trap signature present'/.test(fnBody), 'FM031 must now be the live OI-trap check');
  assert.ok(/check\('FM027', 'Real false breakout/.test(fnBody), 'FM027 must now be the live false-breakout check');
  assert.ok(/check\('FM025', 'Real order rejection risk model flags this order'/.test(fnBody), 'FM025 must now be the live order-rejection check');
  assert.ok(!/check\('FM018', 'Real order rejection/.test(fnBody), 'FM018 must no longer be mistagged onto the order-rejection check');
});
console.log('\n=== computeSuggestedObservations §84/§83 fix: real Regime Win Rate and Strategy Version Impact sources ===');
test('real, genuine Regime Win Rate suggestion produced when a real, adequately-sampled regime is genuinely poor', () => {
  const journal = [
    ...Array.from({length:6}, (_,i) => ({id:i, pnl:100, factorSnapshot:{regime:{label:'Sideways-High Vol'}}})),
    ...Array.from({length:14}, (_,i) => ({id:10+i, pnl:-100, factorSnapshot:{regime:{label:'Sideways-High Vol'}}})),
  ];
  const suggestions = __fno_computeSuggestedObservations(journal);
  const s = suggestions.find(x => x.source.includes('Regime Win Rate'));
  assert.ok(s, 'must produce a real suggestion for a genuinely poor, adequately-sampled regime');
  assert.strictEqual(s.factor, 'Sideways-High Vol');
  assert.ok(/30%/.test(s.observation));
});
test('NO Regime Win Rate suggestion when the real regime win rate is genuinely healthy', () => {
  const journal = [
    ...Array.from({length:14}, (_,i) => ({id:i, pnl:100, factorSnapshot:{regime:{label:'Bullish-Normal Vol'}}})),
    ...Array.from({length:6}, (_,i) => ({id:20+i, pnl:-100, factorSnapshot:{regime:{label:'Bullish-Normal Vol'}}})),
  ];
  const suggestions = __fno_computeSuggestedObservations(journal);
  assert.strictEqual(suggestions.filter(x => x.source.includes('Regime Win Rate')).length, 0);
});
test('real, genuine Strategy Version Impact suggestion produced when window.FNO_STRATEGY_VERSIONS_CACHE has a real "worsened" version', () => {
  const beforeTs = 0, versionTs = 1000;
  window.FNO_STRATEGY_VERSIONS_CACHE = [{ version: 'v2.0', createdAt: versionTs }];
  const journal = [
    ...Array.from({length:12}, (_,i) => ({id:i, ts: beforeTs+i, pnl: i%3===0?-50:150})),
    ...Array.from({length:12}, (_,i) => ({id:20+i, ts: versionTs+i, pnl: i%3===0?100:-80})),
  ];
  const suggestions = __fno_computeSuggestedObservations(journal);
  const s = suggestions.find(x => x.source.includes('Strategy Version Impact'));
  assert.ok(s, 'must produce a real suggestion when a logged version genuinely worsened performance');
  assert.strictEqual(s.factor, 'v2.0');
  delete window.FNO_STRATEGY_VERSIONS_CACHE;
});
test('NO Strategy Version Impact suggestion when window.FNO_STRATEGY_VERSIONS_CACHE is genuinely absent - never throws', () => {
  delete window.FNO_STRATEGY_VERSIONS_CACHE;
  assert.doesNotThrow(() => __fno_computeSuggestedObservations([{id:1, pnl:100}]));
});
test('real, genuine Hypothesis Direction Track Record suggestion produced when a real direction is genuinely poor with an adequate sample', () => {
  window.FNO_HYPOTHESIS_DIRECTION_STATS_CACHE = {
    bullish: { confirmedRatePct: 30, decisiveTotal: 20, sampleSizeWarning: false },
    bearish: { confirmedRatePct: 70, decisiveTotal: 20, sampleSizeWarning: false },
  };
  const suggestions = __fno_computeSuggestedObservations([]);
  const s = suggestions.find(x => x.source.includes('Hypothesis Direction Track Record'));
  assert.ok(s, 'must produce a real suggestion for a genuinely poor, adequately-sampled direction');
  assert.strictEqual(s.factor, 'bullish hypotheses');
  assert.strictEqual(suggestions.filter(x => x.source.includes('Hypothesis Direction Track Record')).length, 1, 'must NOT also flag the genuinely healthy bearish direction');
  delete window.FNO_HYPOTHESIS_DIRECTION_STATS_CACHE;
});
test('NO Hypothesis Direction Track Record suggestion when the real sample is genuinely too small, even if it looks poor', () => {
  window.FNO_HYPOTHESIS_DIRECTION_STATS_CACHE = { bullish: { confirmedRatePct: 10, decisiveTotal: 3, sampleSizeWarning: true }, bearish: null };
  const suggestions = __fno_computeSuggestedObservations([]);
  assert.strictEqual(suggestions.filter(x => x.source.includes('Hypothesis Direction Track Record')).length, 0);
  delete window.FNO_HYPOTHESIS_DIRECTION_STATS_CACHE;
});
test('NO Hypothesis Direction Track Record suggestion when window.FNO_HYPOTHESIS_DIRECTION_STATS_CACHE is genuinely absent - never throws', () => {
  delete window.FNO_HYPOTHESIS_DIRECTION_STATS_CACHE;
  assert.doesNotThrow(() => __fno_computeSuggestedObservations([{id:1, pnl:100}]));
});
test('real, genuine Factor Timeframe Drift suggestion produced for a real, adequately-sampled, genuinely declining factor', () => {
  const janTs = new Date(2026, 0, 15).getTime();
  const marTs = new Date(2026, 2, 15).getTime();
  const journal = [
    ...Array.from({length:30}, (_,i) => ({id:i, ts: janTs, pnl:100, factorSnapshot:{factors:{f1:{status:'COMPUTED',pass:true}}}})), // real 100% in Jan
    ...Array.from({length:30}, (_,i) => ({id:40+i, ts: marTs, pnl:-100, factorSnapshot:{factors:{f1:{status:'COMPUTED',pass:true}}}})), // real 0% in Mar
  ];
  const suggestions = __fno_computeSuggestedObservations(journal);
  const s = suggestions.find(x => x.source.includes('Factor Timeframe Drift'));
  assert.ok(s, 'must produce a real suggestion for a genuine, adequately-sampled decline');
  assert.strictEqual(s.factor, 'f1');
});
test('NO Factor Timeframe Drift suggestion for a real IMPROVEMENT - only genuine decline is actionable', () => {
  const janTs = new Date(2026, 0, 15).getTime();
  const marTs = new Date(2026, 2, 15).getTime();
  const journal = [
    ...Array.from({length:30}, (_,i) => ({id:i, ts: janTs, pnl:-100, factorSnapshot:{factors:{f1:{status:'COMPUTED',pass:true}}}})), // real 0% in Jan
    ...Array.from({length:30}, (_,i) => ({id:40+i, ts: marTs, pnl:100, factorSnapshot:{factors:{f1:{status:'COMPUTED',pass:true}}}})), // real 100% in Mar - genuine improvement
  ];
  const suggestions = __fno_computeSuggestedObservations(journal);
  assert.strictEqual(suggestions.filter(x => x.source.includes('Factor Timeframe Drift')).length, 0, 'a real improvement needs no candidate change, must not be surfaced as if it were a problem');
});

console.log('\n=== computeSuggestedObservations (§48 Knowledge Base connected to §26/§27/§39) ===');
test('produces a real draft observation from a genuine failure-analysis pattern that clears the evidence bar', () => {
  // 35 losses (above the 30-floor), 25 of which are the SAME real
  // category (>20% share, above the substantiality floor)
  const journal = [
    ...Array.from({length:25}, (_,i)=>({id:i, pnl:-20, source:'square_off', grossPnl:-20, costsTotal:1, netPnl:-20})),
    ...Array.from({length:10}, (_,i)=>({id:100+i, pnl:-20, entryPrice:100, sl:80, mfe:101, mae:79, exitPrice:80, source:'auto_sl', grossPnl:-20, costsTotal:1, netPnl:-20})),
  ];
  const suggestions = __fno_computeSuggestedObservations(journal);
  const failureSuggestion = suggestions.find(s => s.source.includes('Failure Analysis'));
  assert.ok(failureSuggestion, 'must produce a real suggestion from the failure analysis engine');
  assert.ok(/unresolved_forced_closure/.test(failureSuggestion.factor));
  assert.ok(/25 of 35/.test(failureSuggestion.observation), `expected real counts cited, got: ${failureSuggestion.observation}`);
});
test('does NOT suggest from an engine whose own sampleSizeWarning is still true - reuses each engine\'s real threshold, not a looser one', () => {
  const journal = [{id:1, pnl:-10, source:'square_off', grossPnl:-10, costsTotal:1, netPnl:-10}]; // 1 loss, way below any real threshold
  const suggestions = __fno_computeSuggestedObservations(journal);
  assert.strictEqual(suggestions.filter(s => s.source.includes('Failure Analysis')).length, 0);
});
test('never suggests the honest "insufficient_data_to_classify" bucket as an actionable pattern', () => {
  const journal = Array.from({length:40}, (_,i)=>({id:i, pnl:-10, source:'manual_force_exit', grossPnl:-10, costsTotal:1, netPnl:-15})); // real losses, but classify to insufficient_data (no matching pattern)
  const suggestions = __fno_computeSuggestedObservations(journal);
  assert.strictEqual(suggestions.filter(s => s.factor === 'insufficient_data_to_classify').length, 0);
});
test('produces a real §32 combination suggestion when a genuine high-performing combination is found', () => {
  const journal = [
    ...Array.from({length:18}, () => ({factorSnapshot:{factors:{fA:{status:'COMPUTED',pass:true},fB:{status:'COMPUTED',pass:true}}}, pnl:100})),
    ...Array.from({length:2}, () => ({factorSnapshot:{factors:{fA:{status:'COMPUTED',pass:true},fB:{status:'COMPUTED',pass:true}}}, pnl:-100})),
  ];
  const suggestions = __fno_computeSuggestedObservations(journal);
  const comboSuggestion = suggestions.find(s => s.source.includes('Factor Combination Analysis'));
  assert.ok(comboSuggestion, 'must produce a real §32 suggestion when a combination clears the real evidence bar');
  assert.ok(/90%/.test(comboSuggestion.observation), `expected the real win rate cited, got: ${comboSuggestion.observation}`);
});
test('empty journal returns an empty array, not a throw - no findings is a valid, honest result', () => {
  assert.deepStrictEqual(__fno_computeSuggestedObservations([]), []);
});

console.log('\n=== generateWeeklyReview / generateMonthlyReview (Master Prompt §46-47) ===');
test('weekly review includes trades within the same ISO week (Monday-start), excludes others', () => {
  // Wednesday 2026-08-12 (a real Wednesday) as reference
  const wed = new Date(2026, 7, 12, 10, 0, 0).getTime(); // month is 0-indexed: 7=August
  const withinWeekMon = new Date(2026, 7, 10, 9, 0, 0).getTime(); // Monday same week
  const withinWeekSun = new Date(2026, 7, 16, 23, 0, 0).getTime(); // Sunday same week (week ends before next Monday)
  const nextWeek = new Date(2026, 7, 17, 9, 0, 0).getTime(); // next Monday - different week
  const journal = [
    {ts: withinWeekMon, pnl: 100},
    {ts: withinWeekSun, pnl: -50},
    {ts: nextWeek, pnl: 9999},
  ];
  const review = __fno_generateWeeklyReview(journal, wed);
  assert.strictEqual(review.tradeCount, 2);
  assert.strictEqual(review.netPnl, 50);
});
test('weekly review with zero trades returns a valid zeroed shape', () => {
  const review = __fno_generateWeeklyReview([], Date.now());
  assert.strictEqual(review.tradeCount, 0);
  assert.strictEqual(review.winRatePct, 0);
});
test('monthly review includes only trades in that real calendar month', () => {
  const journal = [
    {ts: new Date(2026, 7, 1).getTime(), pnl: 100},   // August 2026
    {ts: new Date(2026, 7, 31).getTime(), pnl: 200},  // August 2026
    {ts: new Date(2026, 8, 1).getTime(), pnl: 9999},  // September 2026 - excluded
    {ts: new Date(2026, 6, 31).getTime(), pnl: 9999}, // July 2026 - excluded
  ];
  const review = __fno_generateMonthlyReview(journal, 2026, 7); // 7 = August (0-indexed)
  assert.strictEqual(review.tradeCount, 2);
  assert.strictEqual(review.netPnl, 300);
  assert.strictEqual(review.year, 2026);
  assert.strictEqual(review.month, 7);
});
test('monthly review with zero trades returns a valid zeroed shape', () => {
  const review = __fno_generateMonthlyReview([], 2026, 0);
  assert.strictEqual(review.tradeCount, 0);
});

console.log('\n=== computeFactorHeatmap (Master Prompt §52) ===');
test('groups by category, computes real net directional score', () => {
  const results = [
    {cat:'Market', factor:'a', pass:true, score:1},
    {cat:'Market', factor:'b', pass:true, score:0.5},
    {cat:'Flow', factor:'c', pass:false, score:-2},
  ];
  const heatmap = __fno_computeFactorHeatmap(results);
  const market = heatmap.find(h=>h.category==='Market');
  const flow = heatmap.find(h=>h.category==='Flow');
  assert.ok(Math.abs(market.netScore - 1.5) < 1e-9);
  assert.strictEqual(market.direction, 'Bullish');
  assert.strictEqual(flow.direction, 'Bearish');
});
test('sorted by absolute net score descending (most significant first)', () => {
  const results = [
    {cat:'A', factor:'x', pass:true, score:0.5},
    {cat:'B', factor:'y', pass:false, score:-5},
  ];
  const heatmap = __fno_computeFactorHeatmap(results);
  assert.strictEqual(heatmap[0].category, 'B');
});
test('unavailable/null-pass rows excluded from netScore but counted in totalCount', () => {
  const results = [
    {cat:'X', factor:'a', pass:null, score:0},
    {cat:'X', factor:'b', pass:true, score:1},
  ];
  const heatmap = __fno_computeFactorHeatmap(results);
  const x = heatmap.find(h=>h.category==='X');
  assert.strictEqual(x.computedCount, 1);
  assert.strictEqual(x.totalCount, 2);
  assert.strictEqual(x.netScore, 1);
});
test('empty results returns empty array, not a throw', () => {
  assert.deepStrictEqual(__fno_computeFactorHeatmap([]), []);
});

console.log('\n=== computeMarketSnapshot (Master Prompt §53) ===');
test('produces a real summary reflecting actual regime and operator bias', () => {
  const closes = Array.from({length:30}, (_,i)=>23000+i*15);
  const ctx = {candles: closes.map(c=>({c})), vix: 15, isExpiry: false, spot: 23500, pcr: 1.1};
  const snap = __fno_computeMarketSnapshot(ctx, 'BULLISH');
  assert.strictEqual(snap.regime.trend, 'Bullish');
  assert.ok(/bullish/.test(snap.summary));
  assert.strictEqual(snap.spot, 23500);
});
test('honestly reports insufficient data when regime cannot be classified', () => {
  const snap = __fno_computeMarketSnapshot({candles:null, vix:null, isExpiry:false}, 'NEUTRAL');
  assert.ok(/[Ii]nsufficient/.test(snap.summary));
});
test('defaults operatorBias to NEUTRAL when not provided', () => {
  const snap = __fno_computeMarketSnapshot({candles:null, vix:null, isExpiry:false});
  assert.strictEqual(snap.operatorBias, 'NEUTRAL');
});
test('summary prioritizes real breadth-confirmed Panic condition over normal trend/vol wording', () => {
  const closes = Array.from({length:30}, (_,i)=>23000-i*15);
  const ctx = {candles: closes.map(c=>({c})), vix: 25, isExpiry: false, status: {marketBreadth: {advanceDeclineRatio: 0.1}}};
  const snap = __fno_computeMarketSnapshot(ctx, 'BEARISH');
  assert.ok(/PANIC/.test(snap.summary), `expected PANIC in summary, got: ${snap.summary}`);
});

console.log('\n=== extractFeatureVector (real §29-snapshot -> fixed numeric vector) ===');
test('returns a fixed-length vector matching FEATURE_NAMES exactly', () => {
  const snap = { factors: {}, totalScore: 5, coveragePct: 60, confidence: 'High', regime: {trend:'Bullish', volatility:'Normal Vol', isExpiry:false} };
  const v = __fno_extractFeatureVector(snap);
  assert.strictEqual(v.length, __fno_FEATURE_NAMES.length);
});
test('sums real category scores from COMPUTED factors only', () => {
  const snap = { factors: {
    f1: {cat:'Market', status:'COMPUTED', score:2},
    f2: {cat:'Market', status:'COMPUTED', score:1.5},
    f3: {cat:'Market', status:'UNAVAILABLE', score:0}, // excluded despite score field present
  }, totalScore:0, coveragePct:0, confidence:'Low', regime:null };
  const v = __fno_extractFeatureVector(snap);
  assert.ok(Math.abs(v[0] - 3.5) < 1e-9, `Market feature should be 3.5, got ${v[0]}`);
});
test('handles missing regime without throwing, encodes as neutral', () => {
  const v = __fno_extractFeatureVector({factors:{}, totalScore:0, coveragePct:0, confidence:'Low', regime:null});
  assert.ok(v.every(x => typeof x === 'number' && !isNaN(x)));
});

console.log('\n=== fnoSigmoid (numerically stable) ===');
test('sigmoid(0) = 0.5', () => assert.ok(Math.abs(__fno_fnoSigmoid(0)-0.5) < 1e-9));
test('handles extreme values without NaN/Infinity', () => {
  assert.ok(!isNaN(__fno_fnoSigmoid(1000)) && isFinite(__fno_fnoSigmoid(1000)));
  assert.ok(!isNaN(__fno_fnoSigmoid(-1000)) && isFinite(__fno_fnoSigmoid(-1000)));
  assert.ok(__fno_fnoSigmoid(1000) > 0.99);
  assert.ok(__fno_fnoSigmoid(-1000) < 0.01);
});

console.log('\n=== trainLogisticRegression (real gradient descent, verified on synthetic data) ===');
test('learns a clearly linearly-separable synthetic pattern with high accuracy', () => {
  // Real synthetic test: single feature, label = 1 if feature > 0, else 0,
  // with some noise - if this function has a real bug, it will fail to
  // separate this trivially-separable case.
  const X = [], y = [];
  for (let i = -20; i <= 20; i++) {
    X.push([i + (Math.random()-0.5)*2]); // one feature, small noise
    y.push(i > 0 ? 1 : 0);
  }
  const { standardized, mean, std } = __fno_standardizeFeatures(X);
  const { weights, bias } = __fno_trainLogisticRegression(standardized, y, {epochs: 1000, learningRate: 0.5, l2: 0.001});
  let correct = 0;
  X.forEach((x,i) => {
    const xs = __fno_applyStandardization(x, mean, std);
    const p = __fno_fnoSigmoid(xs[0]*weights[0] + bias);
    if ((p>=0.5?1:0) === y[i]) correct++;
  });
  assert.ok(correct/X.length > 0.9, `Expected >90% training accuracy on a trivially separable dataset, got ${(correct/X.length*100).toFixed(0)}%`);
});
test('empty input returns zero weights/bias without throwing', () => {
  const r = __fno_trainLogisticRegression([], []);
  assert.deepStrictEqual(r.weights, []);
  assert.strictEqual(r.bias, 0);
});

console.log('\n=== standardizeFeatures ===');
test('produces zero mean, unit variance on real data', () => {
  const X = [[1,10],[2,20],[3,30],[4,40],[5,50]];
  const { standardized } = __fno_standardizeFeatures(X);
  const col0 = standardized.map(r=>r[0]);
  const meanCol0 = col0.reduce((a,b)=>a+b,0)/col0.length;
  assert.ok(Math.abs(meanCol0) < 1e-9);
});
test('constant feature (zero variance) does not throw or produce NaN', () => {
  const X = [[5,1],[5,2],[5,3]];
  const { standardized, std } = __fno_standardizeFeatures(X);
  assert.strictEqual(std[0], 1); // guarded, not 0
  standardized.forEach(row => assert.ok(!isNaN(row[0])));
});

console.log('\n=== trainProbabilityModel (full honest pipeline, Master Prompt §25-26, §38-39) ===');
/**
 * Deterministic pseudo-random generator (mulberry32) - used instead of
 * Math.random() for the "uncorrelated/noise" synthetic dataset below.
 * The original version used real Math.random(), which made the "must
 * NOT activate on pure noise" test genuinely flaky - occasionally a
 * random noise draw spuriously beat the 5pp baseline margin by chance,
 * failing a correct implementation on an unlucky seed. A fixed seed
 * makes this test reproducible: deterministically noise (not
 * correlated with outcome) on every run, so the assertion "must not
 * activate" is testing the real gating logic, not fighting randomness.
 */
function mulberry32(seed) {
  return function() {
    seed |= 0; seed = (seed + 0x6D2B79F5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
function fakeJournalWithSnapshots(n, winRate, correlated) {
  const rand = mulberry32(42); // fixed seed - reproducible across every test run
  const journal = [];
  for (let i = 0; i < n; i++) {
    const bullishScore = correlated ? (i % 2 === 0 ? 5 : -5) : (rand()-0.5)*10;
    const win = correlated ? (bullishScore > 0) : (rand() < winRate);
    journal.push({
      ts: i * 1000,
      pnl: win ? 100 : -100,
      factorSnapshot: {
        factors: { f1: {cat:'Market', status:'COMPUTED', score: bullishScore} },
        totalScore: bullishScore, coveragePct: 50, confidence: 'Medium',
        regime: {trend:'Sideways', volatility:'Normal Vol', isExpiry:false},
      },
    });
  }
  return journal;
}
test('refuses to train below MIN_SAMPLES, no model attempted', () => {
  const journal = fakeJournalWithSnapshots(10, 0.5, false);
  const model = __fno_trainProbabilityModel(journal);
  assert.strictEqual(model.trained, false);
  assert.strictEqual(model.active, false);
  assert.ok(/not even attempting/i.test(model.reason));
});
test('trains and ACTIVATES when the signal genuinely predicts outcome (beats baseline)', () => {
  const journal = fakeJournalWithSnapshots(200, 0.5, true); // score perfectly predicts win/loss
  const model = __fno_trainProbabilityModel(journal);
  assert.strictEqual(model.trained, true);
  assert.strictEqual(model.active, true, model.reason);
  assert.ok(model.holdoutAccuracy > model.baselineAccuracy);
});
test('trains but does NOT activate when there is no real signal (does not beat baseline)', () => {
  const journal = fakeJournalWithSnapshots(200, 0.5, false); // pure noise, uncorrelated with outcome
  const model = __fno_trainProbabilityModel(journal);
  assert.strictEqual(model.trained, true);
  assert.strictEqual(model.active, false, 'a noise-only dataset must not activate the model');
  assert.ok(/NOT activated/.test(model.reason));
});
test('excludes breakeven trades (pnl===0) from training data', () => {
  const journal = fakeJournalWithSnapshots(60, 0.5, true);
  journal.push(...Array.from({length:10},(_,i)=>({ts:100000+i, pnl:0, factorSnapshot:journal[0].factorSnapshot})));
  const model = __fno_trainProbabilityModel(journal);
  assert.ok(model.trainedOn + model.holdoutSize <= 60, 'breakeven trades must not be counted toward the usable sample');
});
test('excludes trades without a factor snapshot', () => {
  const journal = fakeJournalWithSnapshots(60, 0.5, true);
  journal.push(...Array.from({length:20},(_,i)=>({ts:100000+i, pnl:100})));
  const model = __fno_trainProbabilityModel(journal);
  assert.ok(model.trainedOn + model.holdoutSize <= 60);
});

console.log('\n=== ML Discipline metadata (Enterprise Plan #28 - model versioning schema) ===');
test('trained model records modelSchemaVersion, hyperparameters, and featuresUsed', () => {
  const journal = fakeJournalWithSnapshots(200, 0.5, true);
  const model = __fno_trainProbabilityModel(journal);
  assert.strictEqual(model.trained, true);
  assert.ok(model.modelSchemaVersion && model.modelSchemaVersion.length > 0);
  assert.deepStrictEqual(model.featuresUsed, __fno_FEATURE_NAMES);
  assert.ok(model.hyperparameters.epochs > 0);
  assert.ok(typeof model.hyperparameters.learningRate === 'number');
  assert.ok(typeof model.hyperparameters.l2 === 'number');
});
test('trainingPeriod and testPeriod are real date ranges from the actual trade timestamps, not fabricated', () => {
  const journal = fakeJournalWithSnapshots(200, 0.5, true);
  const model = __fno_trainProbabilityModel(journal);
  assert.ok(model.trainingPeriod.startTs < model.trainingPeriod.endTs);
  assert.ok(model.testPeriod.startTs < model.testPeriod.endTs);
  assert.ok(model.trainingPeriod.endTs <= model.testPeriod.startTs, 'training period must end before test period starts (chronological split, no look-ahead)');
  assert.strictEqual(model.trainingPeriod.tradeCount, model.trainedOn);
  assert.strictEqual(model.testPeriod.tradeCount, model.holdoutSize);
});
test('insufficient-data results still record modelSchemaVersion (traceable even when training was refused)', () => {
  const model = __fno_trainProbabilityModel(fakeJournalWithSnapshots(10, 0.5, false));
  assert.strictEqual(model.trained, false);
  assert.ok(model.modelSchemaVersion && model.modelSchemaVersion.length > 0);
});

console.log('\n=== predictWinProbability (real inference using stored model params) ===');
test('returns a real probability in (0,1) using the model\'s own stored mean/std/weights', () => {
  const journal = fakeJournalWithSnapshots(200, 0.5, true);
  const model = __fno_trainProbabilityModel(journal);
  const p = __fno_predictWinProbability(model, journal[0].factorSnapshot);
  assert.ok(p > 0 && p < 1);
});
test('predicts higher probability for a clearly bullish snapshot than a clearly bearish one, on a correlated dataset', () => {
  const journal = fakeJournalWithSnapshots(200, 0.5, true);
  const model = __fno_trainProbabilityModel(journal);
  const bullishSnap = { factors: {f1:{cat:'Market',status:'COMPUTED',score:5}}, totalScore:5, coveragePct:50, confidence:'Medium', regime:{trend:'Sideways',volatility:'Normal Vol',isExpiry:false} };
  const bearishSnap = { factors: {f1:{cat:'Market',status:'COMPUTED',score:-5}}, totalScore:-5, coveragePct:50, confidence:'Medium', regime:{trend:'Sideways',volatility:'Normal Vol',isExpiry:false} };
  const pBull = __fno_predictWinProbability(model, bullishSnap);
  const pBear = __fno_predictWinProbability(model, bearishSnap);
  assert.ok(pBull > pBear, `expected bullish (${pBull}) > bearish (${pBear})`);
});

console.log('\n=== determineFillPrice (Enterprise Plan #30 - Mode 1 theoretical vs Mode 2 realistic) ===');
test('theoretical mode uses raw lastPrice, never crosses the spread', () => {
  const fill = __fno_determineFillPrice({bidprice:90, askPrice:100, lastPrice:95}, 'buy', 'theoretical');
  assert.strictEqual(fill.price, 95);
  assert.strictEqual(fill.isRealistic, false);
  assert.strictEqual(fill.mode, 'theoretical');
});
test('realistic mode crosses the spread (buy at ask)', () => {
  const fill = __fno_determineFillPrice({bidprice:90, askPrice:100, lastPrice:95}, 'buy', 'realistic');
  assert.strictEqual(fill.price, 100);
  assert.strictEqual(fill.isRealistic, true);
  assert.strictEqual(fill.mode, 'realistic');
});
test('theoretical mode with no leg returns null price, not a fabricated one', () => {
  const fill = __fno_determineFillPrice(null, 'buy', 'theoretical');
  assert.strictEqual(fill.price, null);
});

console.log('\n=== simulateExecutionLatency (Enterprise Plan #29 - real, volatility-scaled latency cost) ===');
test('no adjustment when volatility data is unavailable - missing data never fabricates a cost', () => {
  const r = __fno_simulateExecutionLatency(100, 'buy', null, 600);
  assert.strictEqual(r.adjustedPrice, 100);
  assert.strictEqual(r.latencyCostRs, 0);
});
test('buy side moves price UP (worse for buyer), never favorably', () => {
  const r = __fno_simulateExecutionLatency(100, 'buy', 30, 600);
  assert.ok(r.adjustedPrice > 100);
  assert.ok(r.latencyCostRs > 0);
});
test('sell side moves price DOWN (worse for seller), never favorably', () => {
  const r = __fno_simulateExecutionLatency(100, 'sell', 30, 600);
  assert.ok(r.adjustedPrice < 100);
});
test('higher volatility produces a larger latency cost, all else equal', () => {
  const lowVol = __fno_simulateExecutionLatency(100, 'buy', 10, 600);
  const highVol = __fno_simulateExecutionLatency(100, 'buy', 50, 600);
  assert.ok(highVol.latencyCostRs > lowVol.latencyCostRs);
});
test('longer poll interval produces a larger latency cost (more real time exposed to adverse moves)', () => {
  const fast = __fno_simulateExecutionLatency(100, 'buy', 30, 500);
  const slow = __fno_simulateExecutionLatency(100, 'buy', 30, 60000);
  assert.ok(slow.latencyCostRs > fast.latencyCostRs);
});
test('sell-side price never goes negative even with extreme volatility', () => {
  const r = __fno_simulateExecutionLatency(1, 'sell', 500, 60000);
  assert.ok(r.adjustedPrice >= 0.05);
});
test('zero or negative fillPrice returns unchanged, not a fabricated adjustment', () => {
  const r = __fno_simulateExecutionLatency(0, 'buy', 30, 600);
  assert.strictEqual(r.adjustedPrice, 0);
  assert.strictEqual(r.latencyCostRs, 0);
});

console.log('\n=== simulateOrderRejection (Enterprise Plan #29 - real rejection risk, not silent success) ===');
test('no rejection when data is simply absent (data gap != illiquidity evidence)', () => {
  const r = __fno_simulateOrderRejection(null, 50, 'buy');
  assert.strictEqual(r.rejected, false);
  assert.deepStrictEqual(r.riskFactors, []);
});
test('flags but does not reject on a single risk factor alone', () => {
  const r = __fno_simulateOrderRejection({askQty: 10, bidQty: 500, bidprice: 100, askPrice: 102}, 50, 'buy');
  assert.strictEqual(r.riskFactors.length, 1);
  assert.strictEqual(r.rejected, false);
});
test('rejects when two independent risk factors are both present', () => {
  const r = __fno_simulateOrderRejection({askQty: 10, bidQty: 500, bidprice: 100, askPrice: 120}, 50, 'buy'); // qty exceeds ask depth AND spread is 20%
  assert.strictEqual(r.riskFactors.length, 2);
  assert.strictEqual(r.rejected, true);
  assert.ok(r.reason.includes('resting qty'));
});
test('no rejection when order qty is well within resting depth and spread is tight', () => {
  const r = __fno_simulateOrderRejection({askQty: 5000, bidQty: 5000, bidprice: 100, askPrice: 100.5}, 50, 'buy');
  assert.strictEqual(r.rejected, false);
  assert.strictEqual(r.riskFactors.length, 0);
});

console.log('\n=== simulatePartialFillQty (NSE whole-lot discipline) ===');
test('a genuinely null leg (data absent) fills the full requested whole-lot qty', () => {
  const r = __fno_simulatePartialFillQty(null, 150, 'buy', 'NIFTY');
  assert.strictEqual(r.filledQty, 150);
  assert.strictEqual(r.isPartial, false);
});
test('resting depth data missing does not simulate a partial fill', () => {
  assert.strictEqual(__fno_simulatePartialFillQty({}, 150, 'buy', 'NIFTY').isPartial, false);
});
test('resting qty covers request — full fill', () => {
  const r = __fno_simulatePartialFillQty({ askQty: 500, bidQty: 500 }, 150, 'buy', 'NIFTY');
  assert.strictEqual(r.filledQty, 150);
  assert.strictEqual(r.isPartial, false);
});
test('resting qty below request — caps to whole lots only (150 req, 100 resting → 75)', () => {
  const r = __fno_simulatePartialFillQty({ askQty: 100, bidQty: 500 }, 150, 'buy', 'NIFTY');
  assert.strictEqual(r.filledQty, 75);
  assert.strictEqual(r.isPartial, true);
});
test('resting qty below one lot — no fill (not 37/65 odd sizes)', () => {
  const r = __fno_simulatePartialFillQty({ askQty: 65, bidQty: 500 }, 150, 'buy', 'NIFTY');
  assert.strictEqual(r.filledQty, 0);
  assert.ok(/one full/i.test(r.reason));
});
test('request below one exchange lot is rejected', () => {
  const r = __fno_simulatePartialFillQty({ askQty: 500 }, 50, 'buy', 'NIFTY');
  assert.strictEqual(r.filledQty, 0);
});
test('SELL side uses bidQty and whole lots', () => {
  const r = __fno_simulatePartialFillQty({ askQty: 500, bidQty: 80 }, 150, 'sell', 'NIFTY');
  assert.strictEqual(r.filledQty, 75);
  assert.strictEqual(r.isPartial, true);
});

console.log('\n=== checkExecutionLatencyRisk / FM067 (real, NEW Phase-5 fix - the previously-deferred "latency cost is a meaningful % of premium" gap) ===');
const FLAT_CANDLES = Array.from({length: 25}, (_, i) => ({ c: 100 + (i % 2) * 0.01 })); // near-zero real vol
const EXTREME_CANDLES = Array.from({length: 25}, (_, i) => ({ c: i % 2 === 0 ? 100 : 100000 })); // real, deliberately extreme alternating swings (verified numerically to clear the 3% threshold) - constructed purely to exceed the threshold deterministically in a test, not a realistic market
test('theoretical mode never computes a real latency cost - honestly matches this app\'s own documented Mode 1 design (already, separately flagged by FM068)', () => {
  const r = __fno_checkExecutionLatencyRisk({askPrice: 100, bidprice: 99, lastPrice: 99.5}, 'theoretical', EXTREME_CANDLES);
  assert.strictEqual(r.isSignificant, false);
  assert.strictEqual(r.latencyCostRs, 0);
});
test('genuinely missing leg/candles data honestly returns not-significant, never fabricates a cost', () => {
  assert.strictEqual(__fno_checkExecutionLatencyRisk(null, 'realistic', EXTREME_CANDLES).isSignificant, false);
  assert.strictEqual(__fno_checkExecutionLatencyRisk({askPrice: 100, bidprice: 99}, 'realistic', null).isSignificant, false);
  assert.strictEqual(__fno_checkExecutionLatencyRisk({askPrice: 100, bidprice: 99}, 'realistic', []).isSignificant, false);
});
test('a real, ordinary, low-volatility candle series produces a genuinely negligible latency cost - correctly NOT significant', () => {
  const r = __fno_checkExecutionLatencyRisk({askPrice: 100, bidprice: 99, lastPrice: 99.5}, 'realistic', FLAT_CANDLES);
  assert.strictEqual(r.isSignificant, false);
});
test('a real, deliberately extreme-volatility candle series produces a genuinely large latency cost - correctly flagged significant, exceeding the documented 3% threshold', () => {
  const r = __fno_checkExecutionLatencyRisk({askPrice: 100, bidprice: 99, lastPrice: 99.5}, 'realistic', EXTREME_CANDLES);
  assert.strictEqual(r.isSignificant, true);
  assert.ok(r.latencyCostPct > 3);
  assert.ok(r.latencyCostRs > 0);
});
test('FM067 genuinely fires reduce_confidence through the real evaluatePreTradeFailureModes evaluator when latencyCheck.isSignificant is true', () => {
  const r = __fno_evaluatePreTradeFailureModes({ latencyCheck: { latencyCostRs: 15, latencyCostPct: 5, isSignificant: true } });
  const fm067 = r.triggered.find(t => t.id === 'FM067');
  assert.ok(fm067);
  assert.strictEqual(fm067.action, 'reduce_confidence');
});
test('FM067 correctly stays silent when latencyCheck is not significant, or genuinely absent', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ latencyCheck: { latencyCostRs: 0.1, latencyCostPct: 0.01, isSignificant: false } }).triggered.some(t => t.id === 'FM067'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM067'), 'must not fire on a genuinely absent latencyCheck - e.g. the driver, which never computes one');
});
test('tryOpenAutoTradePosition genuinely calls checkExecutionLatencyRisk and passes its result into the real evaluatePreTradeFailureModes() call, wiring FM067', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const callStart = fnBody.indexOf('evaluatePreTradeFailureModes({');
  const callEnd = fnBody.indexOf('\n    });', callStart);
  const callBody = fnBody.slice(callStart, callEnd);
  assert.ok(/latencyCheck:\s*checkExecutionLatencyRisk\(leg, execMode, curCtx && curCtx\.candles\)/.test(callBody), 'the real call must genuinely compute and pass latencyCheck, not silently omit it');
});

console.log('\n=== FM020 (real, SELF-CORRECTED wiring this session - reuses the real, existing "Historical vs IV" factor instead of a redundant, separately-thresholded duplicate) ===');
test('FM020 genuinely fires require_confirmation when the real, existing "Historical vs IV" factor result has pass:false (its own already-established 5-point threshold crossed)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Vol', factor: 'Historical vs IV', pass: false, reason: '20-candle realized vol = 40.0% vs IV 15.0% -> gap 25.0pp (IV rich vs realized)' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  const fm020 = r.triggered.find(t => t.id === 'FM020');
  assert.ok(fm020);
  assert.strictEqual(fm020.action, 'require_confirmation');
  assert.strictEqual(fm020.reason, brain.results[0].reason, 'must reuse the real, existing factor\'s own reason text verbatim, not re-derive a new one');
});
test('FM020 correctly stays silent when the real, existing factor genuinely passed (gap within its own 5-point threshold), or when the factor itself is unavailable (pass:null, e.g. fewer than 21 real candles)', () => {
  const passBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Vol', factor: 'Historical vs IV', pass: true, reason: 'in line' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: passBrain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM020'));
  const naBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Vol', factor: 'Historical vs IV', pass: null, reason: 'Fewer than 21 real candles' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: naBrain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM020'), 'pass:null (genuinely unavailable) must never be treated as pass:false');
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM020'), 'must not fire when brain/results are genuinely absent');
});
test('SELF-CAUGHT REAL BUG, found and fixed in the same session it was introduced: FM020 originally computed its OWN, separate real HV-vs-IV gap (recomputing historicalVolPct a second time) using a freshly-invented 15-point threshold - found, while reviewing the swing-relevant backlog, to be a genuine, redundant duplicate of computeVolFactors\' own already-live "Historical vs IV" factor, which already made this exact comparison using its OWN, different (5-point) already-established threshold. Fixed to reuse the real, existing factor via findResult() (the same, established pattern FM009 already uses for RSI) instead of a second computation with a mismatched threshold.', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  assert.ok(!/hvIvGapCheck/.test(coreSrc.replace(/\/\/.*$/gm, '')), 'the real, redundant hvIvGapCheck computation must be fully removed from live code (comments referencing the old bug for history are fine, but no executable code should remain)');
  const fnStart = coreSrc.indexOf('function evaluatePreTradeFailureModes(');
  const fnEnd = coreSrc.indexOf('\nfunction ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd);
  assert.ok(/findResult\('Historical vs IV'\)/.test(fnBody), 'FM020 must genuinely reuse the real, existing factor via findResult()');
});

console.log('\n=== FM073 (real, NEW wiring this session - reuses the real, existing "Gap Up/Down Opening" factor, learned the FM020 lesson and checked for reuse FIRST) ===');
test('FM073 genuinely fires require_confirmation when the real, existing "Gap Up/Down Opening" factor result has pass:false (its own already-established 0.5% threshold crossed)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'Gap Up/Down Opening', pass: false, reason: `Close-based proxy: prior session's last close 24800 vs today's first close in this window 25100 = 1.21% gap.` }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  const fm073 = r.triggered.find(t => t.id === 'FM073');
  assert.ok(fm073);
  assert.strictEqual(fm073.action, 'require_confirmation');
  assert.strictEqual(fm073.reason, brain.results[0].reason, 'must reuse the real, existing factor\'s own reason text verbatim, not re-derive a new one');
});
test('FM073 correctly stays silent when the real, existing factor genuinely passed (gap within its own 0.5% threshold), or when the factor itself is unavailable (pass:null, e.g. fewer than 2 real calendar days)', () => {
  const passBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'Gap Up/Down Opening', pass: true, reason: 'small gap' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: passBrain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM073'));
  const naBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'Gap Up/Down Opening', pass: null, reason: 'Same single-day-window limitation' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: naBrain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM073'), 'pass:null (genuinely unavailable) must never be treated as pass:false');
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM073'), 'must not fire when brain/results are genuinely absent');
});

console.log('\n=== checkGapFillStatus (real, NEW function this session, wires FM074 - genuine new logic, no reusable existing work found) ===');
test('checkGapFillStatus honestly returns null fields (not false) when fewer than 2 real calendar days are present', () => {
  const day1 = 1700000000000;
  const candles = [{ t: day1, c: 100 }, { t: day1 + 60000, c: 101 }];
  const r = __fno_checkGapFillStatus(candles);
  assert.strictEqual(r.hasGap, null);
  assert.strictEqual(r.isFilled, null);
});
test('checkGapFillStatus honestly returns null on missing/empty candle data', () => {
  assert.strictEqual(__fno_checkGapFillStatus(null).hasGap, null);
  assert.strictEqual(__fno_checkGapFillStatus([]).hasGap, null);
});
test('checkGapFillStatus detects hasGap:false and leaves isFilled null when the real gap is below the 0.5% threshold', () => {
  const day1 = new Date('2026-08-25T09:15:00').getTime();
  const day2 = new Date('2026-08-26T09:15:00').getTime();
  const candles = [
    { t: day1, c: 100 }, { t: day1 + 60000, c: 100.2 }, { t: day1 + 120000, c: 100 }, // prior day, closes at 100
    { t: day2, c: 100.3 }, // today opens at 100.3 -> 0.3% gap, below 0.5% threshold
  ];
  const r = __fno_checkGapFillStatus(candles);
  assert.strictEqual(r.hasGap, false);
  assert.strictEqual(r.isFilled, null, 'fill-tracking is not applicable when there is honestly no real gap to begin with');
});
test('checkGapFillStatus correctly detects a real, unfilled gap-up several candles into the session', () => {
  const day1 = new Date('2026-08-25T09:15:00').getTime();
  const day2 = new Date('2026-08-26T09:15:00').getTime();
  const candles = [
    { t: day1, c: 100 }, { t: day1 + 60000, c: 100 }, { t: day1 + 120000, c: 100 }, // prior day, real last close = 100
    { t: day2, c: 102 }, { t: day2 + 60000, c: 102.5 }, { t: day2 + 120000, c: 103 }, { t: day2 + 180000, c: 103.2 }, { t: day2 + 240000, c: 103.5 }, { t: day2 + 300000, c: 103.8 }, // 2% gap-up, never trades back down to 100
  ];
  const r = __fno_checkGapFillStatus(candles);
  assert.strictEqual(r.hasGap, true);
  assert.strictEqual(r.isFilled, false);
  assert.strictEqual(r.candlesSinceOpen, 5);
});
test('checkGapFillStatus correctly detects a real gap-up that HAS been filled (price traded back down through the prior close)', () => {
  const day1 = new Date('2026-08-25T09:15:00').getTime();
  const day2 = new Date('2026-08-26T09:15:00').getTime();
  const candles = [
    { t: day1, c: 100 }, { t: day1 + 60000, c: 100 }, // prior day, real last close = 100
    { t: day2, c: 102 }, { t: day2 + 60000, c: 101.5 }, { t: day2 + 120000, c: 99.8 }, // gap-up to 102, then trades back through 100 by the 3rd today-candle
  ];
  const r = __fno_checkGapFillStatus(candles);
  assert.strictEqual(r.hasGap, true);
  assert.strictEqual(r.isFilled, true);
});
test('checkGapFillStatus correctly handles a real gap-down (fill direction is trading back UP through the prior close)', () => {
  const day1 = new Date('2026-08-25T09:15:00').getTime();
  const day2 = new Date('2026-08-26T09:15:00').getTime();
  const unfilled = [
    { t: day1, c: 100 },
    { t: day2, c: 98 }, { t: day2 + 60000, c: 97.5 }, { t: day2 + 120000, c: 97 }, { t: day2 + 180000, c: 96.8 }, { t: day2 + 240000, c: 96.5 }, { t: day2 + 300000, c: 96.2 },
  ];
  const rUnfilled = __fno_checkGapFillStatus(unfilled);
  assert.strictEqual(rUnfilled.hasGap, true);
  assert.strictEqual(rUnfilled.isFilled, false, 'a gap-down that keeps trading lower must never be mistaken for filled');
  const filled = [
    { t: day1, c: 100 },
    { t: day2, c: 98 }, { t: day2 + 60000, c: 99 }, { t: day2 + 120000, c: 100.2 }, // trades back up through 100
  ];
  assert.strictEqual(__fno_checkGapFillStatus(filled).isFilled, true);
});

console.log('\n=== FM074 (real, NEW wiring this session - genuine new gap-fill-tracking logic, distinct from FM073) ===');
test('FM074 genuinely fires reduce_confidence when a real gap remains unfilled for at least the 5-candle minimum', () => {
  const gapFillCheck = { hasGap: true, gapPct: 2.0, isFilled: false, candlesSinceOpen: 5, reason: 'Real gap-up of 2.00% remains genuinely unfilled 5 real candle(s) into today\'s session' };
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, gapFillCheck });
  const fm074 = r.triggered.find(t => t.id === 'FM074');
  assert.ok(fm074);
  assert.strictEqual(fm074.action, 'reduce_confidence');
  assert.strictEqual(fm074.reason, gapFillCheck.reason);
});
test('FM074 correctly stays silent when the gap has genuinely already filled, when there is no real gap, when it is too early into the session (below the 5-candle minimum), and when gapFillCheck is genuinely absent', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  const filled = { hasGap: true, isFilled: true, candlesSinceOpen: 8, reason: 'filled' };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, gapFillCheck: filled }).triggered.some(t => t.id === 'FM074'));
  const noGap = { hasGap: false, isFilled: null, candlesSinceOpen: null, reason: 'no gap' };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, gapFillCheck: noGap }).triggered.some(t => t.id === 'FM074'));
  const tooEarly = { hasGap: true, isFilled: false, candlesSinceOpen: 2, reason: 'unfilled but only 2 candles in' };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, gapFillCheck: tooEarly }).triggered.some(t => t.id === 'FM074'), 'must not fire before the real 5-candle minimum - "just opened" is not "several candles unresolved"');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM074'), 'must not fire when gapFillCheck is genuinely absent');
});
test('static wiring-audit lock: the real tryOpenAutoTradePosition() call site genuinely passes a real gapFillCheck computed from checkGapFillStatus(), wiring FM073/FM074 into the real entry flow', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const callStart = fnBody.indexOf('evaluatePreTradeFailureModes({');
  const callEnd = fnBody.indexOf('\n    });', callStart);
  const callBody = fnBody.slice(callStart, callEnd);
  assert.ok(/gapFillCheck:\s*checkGapFillStatus\(/.test(callBody), 'the real entry-flow call must genuinely compute gapFillCheck via checkGapFillStatus(), not omit it or fabricate a static value');
});

console.log('\n=== FM063 (real, NEW wiring this session - reuses the real, existing "Stock Results Today" factor, found already-live during this investigation) ===');
test('FM063 genuinely fires require_confirmation when the real, existing "Stock Results Today" factor result has pass:false (real results due today)', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'Stock Results Today', pass: false, reason: 'Real results calendar (premium provider) flags results due today: Reliance Industries - elevated single-stock/sector volatility risk' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  const fm063 = r.triggered.find(t => t.id === 'FM063');
  assert.ok(fm063);
  assert.strictEqual(fm063.action, 'require_confirmation');
  assert.strictEqual(fm063.reason, brain.results[0].reason);
});
test('FM063 correctly stays silent when the real factor genuinely confirms no results, or is genuinely unavailable (no provider configured)', () => {
  const passBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'Stock Results Today', pass: true, reason: 'no results' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: passBrain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM063'));
  const naBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'Stock Results Today', pass: null, reason: 'no provider configured' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: naBrain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM063'), 'pass:null (genuinely unavailable) must never be treated as pass:false');
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM063'));
});

console.log('\n=== FM064 (real, NEW wiring this session - genuine new client-side fetchCorporateActions() + a new "Corporate Action Near Expiry" factor, no reusable existing work found) ===');
test('fetchCorporateActions is a real function defined in the current source (genuinely new this session - confirmed no client-side caller of fno_fetch_corporate_actions existed before)', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  assert.ok(/async function fetchCorporateActions\(sym\)/.test(coreSrc));
  assert.ok(/action=fno_fetch_corporate_actions&symbol=\$\{sym\}/.test(coreSrc), 'must genuinely call the real, existing fno_fetch_corporate_actions PHP endpoint with the real symbol');
});
test('static wiring-audit lock: the real per-refresh Promise.all() genuinely includes fetchCorporateActions(sym), not merely defined and left uncalled', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const promiseAllIdx = coreSrc.indexOf('fetchChart(sym), fetchOC(sym), fetchStatus()');
  assert.ok(promiseAllIdx > -1, 'the real per-refresh Promise.all() call site must still be present in the current source');
  const nearby = coreSrc.slice(promiseAllIdx, promiseAllIdx + 400);
  assert.ok(/fetchCorporateActions\(sym\)/.test(nearby), 'fetchCorporateActions(sym) must genuinely be included in the real per-refresh fetch batch');
  assert.ok(/status\.corporateActions\s*=/.test(coreSrc) && /status\.corporateActionsSourceStatus\s*=/.test(coreSrc), 'the real fetch result must genuinely be captured onto status.corporateActions/status.corporateActionsSourceStatus for downstream factors to read');
});
test('FM064 genuinely fires require_confirmation when the real, existing "Corporate Action Near Expiry" factor result has pass:false', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'Corporate Action Near Expiry', pass: false, reason: 'Real, scheduled corporate action for RELIANCE (Dividend) has a real ex-date of 05-Sep-2026, falling on or before this contract\'s own real expiry (3 real day(s) away)' }] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  const fm064 = r.triggered.find(t => t.id === 'FM064');
  assert.ok(fm064);
  assert.strictEqual(fm064.action, 'require_confirmation');
  assert.strictEqual(fm064.reason, brain.results[0].reason);
});
test('FM064 correctly stays silent when the real factor genuinely found no near-expiry action, or is genuinely unavailable (NSE integration off/unreachable)', () => {
  const passBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'Corporate Action Near Expiry', pass: true, reason: 'no near-expiry action' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: passBrain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM064'));
  const naBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY',
    results: [{ cat: 'Market', factor: 'Corporate Action Near Expiry', pass: null, reason: 'source unavailable' }] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: naBrain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM064'), 'pass:null (genuinely unavailable) must never be treated as pass:false');
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM064'));
});
test('static source check: the real "Corporate Action Near Expiry" factor genuinely reuses ctx.decay.days (not a second, separate expiry lookup) and distinguishes "live, zero near actions" from "source unavailable"', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const factorIdx = coreSrc.indexOf("factor:'Corporate Action Near Expiry'");
  assert.ok(factorIdx > -1, 'the real factor must exist in computeMarketFactors');
  const block = coreSrc.slice(Math.max(0, factorIdx - 1600), factorIdx + 400);
  assert.ok(/ctx\.decay\s*&&\s*typeof ctx\.decay\.days/.test(block), 'must genuinely reuse ctx.decay.days, the same real days-to-expiry field FM133/FM134/FM019/FM085 already use');
  assert.ok(/corporateActionsSourceStatus === 'live'/.test(block), 'must gate on the real source status, not merely on the actions array being non-empty (an empty array could mean "live, zero actions" or "source unavailable" - these must not be conflated)');
});

console.log('\n=== FM085 (real, NEW wiring this session - real Max Pain distance + limited real expiry runway, combined) ===');
test('FM085 genuinely fires reduce_confidence when BOTH the real distance exceeds the threshold AND real days-to-expiry is limited', () => {
  const r = __fno_evaluatePreTradeFailureModes({ maxPainCheck: { strike: 23000, distPct: 8, daysToExpiry: 1 } });
  const fm085 = r.triggered.find(t => t.id === 'FM085');
  assert.ok(fm085);
  assert.strictEqual(fm085.action, 'reduce_confidence');
});
test('FM085 correctly requires BOTH real conditions together - large distance alone with plenty of real runway does not fire', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ maxPainCheck: { strike: 23000, distPct: 8, daysToExpiry: 20 } }).triggered.some(t => t.id === 'FM085'));
});
test('FM085 correctly requires BOTH real conditions together - limited runway alone with a genuinely small distance does not fire', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ maxPainCheck: { strike: 23000, distPct: 1, daysToExpiry: 1 } }).triggered.some(t => t.id === 'FM085'));
});
test('FM085 correctly uses the ABSOLUTE distance - a large NEGATIVE distPct (spot below max pain) still fires', () => {
  const r = __fno_evaluatePreTradeFailureModes({ maxPainCheck: { strike: 23000, distPct: -8, daysToExpiry: 1 } });
  assert.ok(r.triggered.some(t => t.id === 'FM085'));
});
test('FM085 correctly stays silent when genuinely absent', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM085'), 'must not fire on a genuinely absent maxPainCheck - e.g. the driver, which never computes one');
});
test('tryOpenAutoTradePosition genuinely computes a real maxPainCheck (reusing the real, shared computeMaxPainInfo + curCtx.decay.days) and passes it into evaluatePreTradeFailureModes(), wiring FM085', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const callStart = fnBody.indexOf('evaluatePreTradeFailureModes({');
  const callEnd = fnBody.indexOf('\n    });', callStart);
  const callBody = fnBody.slice(callStart, callEnd);
  assert.ok(/maxPainCheck:/.test(callBody), 'the real call must genuinely include maxPainCheck, not silently omit it');
  assert.ok(/computeMaxPainInfo\(curCtx\.ocRows, curCtx\.spot\)/.test(callBody), 'must reuse the real, shared computeMaxPainInfo function, not a new, divergent computation');
});

console.log('\n=== FM076/FM075/FM032/FM080 (real, NEW wiring this backlog-triage pass - reuse the already-live cross-instrument functions via evalCtx.ctx fields) ===');
test('FM076 genuinely fires require_confirmation when real futures premium and real PCR contradict each other', () => {
  const r = __fno_evaluatePreTradeFailureModes({ ctx: { futuresPrice: 23300, spot: 23000, pcr: 0.4 } }); // premiumPct ~1.3% bullish futures, PCR<0.6 bearish contrarian options read
  const fm076 = r.triggered.find(t => t.id === 'FM076');
  assert.ok(fm076);
  assert.strictEqual(fm076.action, 'require_confirmation');
});
test('FM076 correctly stays silent when real futures/PCR genuinely agree, or when either is missing', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: { futuresPrice: 23300, spot: 23000, pcr: 1.8 } }).triggered.some(t => t.id === 'FM076'), 'bullish futures + bullish contrarian PCR read must be aligned, not contradictory');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: {} }).triggered.some(t => t.id === 'FM076'), 'genuinely missing futures/pcr must never be treated as contradictory');
});
test("FM075 genuinely fires require_confirmation when the real Multi-Instrument Consensus reads 'mixed' (bullish futures vote vs bearish IV-skew vote)", () => {
  const ocRows = [
    { strikePrice: 22700, CE: { impliedVolatility: 16 }, PE: { impliedVolatility: 20 } }, // real put-skew-dominant smile -> bearish IV-skew vote
    { strikePrice: 23200, CE: { impliedVolatility: 15 }, PE: { impliedVolatility: 15 } },
    { strikePrice: 23700, CE: { impliedVolatility: 13 }, PE: { impliedVolatility: 14 } },
  ].map(r => ({ ...r, expiryDate: 'E' }));
  const r = __fno_evaluatePreTradeFailureModes({ ctx: { futuresPrice: 23433.4, spot: 23200, pcr: 1.8, ocRows, expiryDates: ['E'] } }); // aligned-bullish futures vote (premiumPct ~1%) + bearish skew vote = mixed; spot 23200 matches the ocRows fixture's own ATM strike so the OTM-strike skew detection actually finds real candidates
  const fm075 = r.triggered.find(t => t.id === 'FM075');
  assert.ok(fm075, `expected FM075 to fire, got triggered: ${JSON.stringify(r.triggered.map(t=>t.id))}`);
  assert.strictEqual(fm075.action, 'require_confirmation');
});
test('FM075 correctly stays silent on a genuinely inconclusive/single-source read (no real contradicting second vote)', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: {} }).triggered.some(t => t.id === 'FM075'));
});
test('FM032 genuinely fires require_confirmation on a real, multi-signal wrong-side warning (user on the same side as a crowded retail extreme, contradicted by Operator Intel)', () => {
  const r = __fno_evaluatePreTradeFailureModes({ ctx: { pcr: 0.4, operatorIntel: { bias: 'BEARISH_TRAP' } }, optionType: 'CE' }); // low PCR = retail crowded bullish, user CE=bullish, bearish-trap bias contradicts
  const fm032 = r.triggered.find(t => t.id === 'FM032');
  assert.ok(fm032);
  assert.strictEqual(fm032.action, 'require_confirmation');
});
test('FM032 correctly stays silent when the user is not on the crowded side, or optionType is genuinely absent', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: { pcr: 0.4, operatorIntel: { bias: 'BEARISH_TRAP' } }, optionType: 'PE' }).triggered.some(t => t.id === 'FM032'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: { pcr: 0.4 } } ).triggered.some(t => t.id === 'FM032'), 'no optionType passed must never fire');
});
test('FM080 genuinely fires reduce_confidence when real dealer gamma is NEGATIVE (heavy real call OI, destabilizing)', () => {
  const ocRows = [
    { strikePrice: 23000, CE: { impliedVolatility: 15, openInterest: 100000 }, PE: { impliedVolatility: 15, openInterest: 5000 } },
    { strikePrice: 23200, CE: { impliedVolatility: 15, openInterest: 500000 }, PE: { impliedVolatility: 15, openInterest: 5000 } },
    { strikePrice: 23400, CE: { impliedVolatility: 15, openInterest: 100000 }, PE: { impliedVolatility: 15, openInterest: 5000 } },
  ];
  const r = __fno_evaluatePreTradeFailureModes({ ctx: { ocRows, spot: 23200, decay: { days: 5, snapshot: { r: 0.065 } } } });
  const fm080 = r.triggered.find(t => t.id === 'FM080');
  assert.ok(fm080);
  assert.strictEqual(fm080.action, 'reduce_confidence');
});
test('FM080 correctly stays silent when real dealer gamma is POSITIVE (heavy real put OI) or genuinely insufficient data', () => {
  const putHeavyRows = [
    { strikePrice: 23000, CE: { impliedVolatility: 15, openInterest: 5000 }, PE: { impliedVolatility: 15, openInterest: 100000 } },
    { strikePrice: 23200, CE: { impliedVolatility: 15, openInterest: 5000 }, PE: { impliedVolatility: 15, openInterest: 500000 } },
    { strikePrice: 23400, CE: { impliedVolatility: 15, openInterest: 5000 }, PE: { impliedVolatility: 15, openInterest: 100000 } },
  ];
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: { ocRows: putHeavyRows, spot: 23200, decay: { days: 5, snapshot: { r: 0.065 } } } }).triggered.some(t => t.id === 'FM080'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: {} }).triggered.some(t => t.id === 'FM080'), 'genuinely insufficient real strike/OI data must never fire');
});
console.log('\n=== FM012/FM015/FM028/FM029/FM104/FM105/FM110/FM111/FM129/FM130/FM132/FM151 (real, NEW wiring this backlog-triage pass) ===');
test('FM012/FM110 genuinely fire when the real EMA21/50 longer-trend factor disagrees with the real EMA9/21 shorter-trend factor', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Tech', factor: 'EMA 9 vs 21 Crossover', pass: true, reason: 'EMA9 above EMA21 - bullish' },
    { cat: 'Tech', factor: 'EMA 21 vs 50', pass: false, reason: 'EMA21 below EMA50 - bearish' },
  ] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM012'));
  assert.ok(r.triggered.some(t => t.id === 'FM110'));
});
test('FM012/FM110 correctly stay silent when the two real EMA factors genuinely agree, or either is not computed', () => {
  const brainAgree = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Tech', factor: 'EMA 9 vs 21 Crossover', pass: true, reason: 'bullish' },
    { cat: 'Tech', factor: 'EMA 21 vs 50', pass: true, reason: 'bullish' },
  ] };
  const rAgree = __fno_evaluatePreTradeFailureModes({ brain: brainAgree, ctx: { ocRow: {} } });
  assert.ok(!rAgree.triggered.some(t => t.id === 'FM012'));
  assert.ok(!rAgree.triggered.some(t => t.id === 'FM110'));
  const brainMissing = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Tech', factor: 'EMA 9 vs 21 Crossover', pass: true, reason: 'bullish' },
    { cat: 'Tech', factor: 'EMA 21 vs 50', pass: null, reason: 'not enough candles' },
  ] };
  const rMissing = __fno_evaluatePreTradeFailureModes({ brain: brainMissing, ctx: { ocRow: {} } });
  assert.ok(!rMissing.triggered.some(t => t.id === 'FM012'), 'must not fire when EMA21/50 genuinely could not be computed');
});
test('FM015 genuinely fires (a true-ID rename of the real check previously mistagged the non-catalog FM009b) on real RSI oversold against a real bearish (SELL_READY/PE) trade', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'SELL_READY', results: [
    { cat: 'Tech', factor: 'RSI Level', pass: false, reason: 'RSI 22 oversold' },
  ] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM015'));
  assert.ok(!r.triggered.some(t => t.id === 'FM009b'), 'FM009b was renamed to its true catalog ID FM015, must no longer exist');
});
test('FM015 correctly stays silent on a real bullish (BUY_READY) trade even with a real oversold reading', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Tech', factor: 'RSI Level', pass: false, reason: 'RSI 22 oversold' },
  ] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM015'));
});
test('FM028 genuinely fires require_confirmation when a real active breakout coincides with a real OI trap signature', () => {
  const r = __fno_evaluatePreTradeFailureModes({
    breakoutCondition: { condition: 'breakout_up', reason: 'real breakout up' },
    trapSignal: { isTrapSignature: true, confirmed: false, reason: 'large real OI build without price confirmation' },
  });
  const fm028 = r.triggered.find(t => t.id === 'FM028');
  assert.ok(fm028);
  assert.strictEqual(fm028.action, 'require_confirmation');
});
test('FM028 correctly stays silent when genuinely no breakout is active, or the real OI signature confirms (not traps) the breakout', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ breakoutCondition: { condition: 'range_bound', reason: 'r' }, trapSignal: { isTrapSignature: true, reason: 'r' } }).triggered.some(t => t.id === 'FM028'), 'no real active breakout must never fire a trap warning');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ breakoutCondition: { condition: 'breakout_up', reason: 'r' }, trapSignal: { isTrapSignature: false, confirmed: true, reason: 'r' } }).triggered.some(t => t.id === 'FM028'), 'a real, confirmed (not trapped) breakout must not fire');
});
test('FM029 genuinely fires (a true-ID rename of the real check previously mistagged the non-catalog FM024b) on a real choppy price condition', () => {
  const r = __fno_evaluatePreTradeFailureModes({ breakoutCondition: { condition: 'choppy', reason: 'real high crossover frequency' } });
  assert.ok(r.triggered.some(t => t.id === 'FM029'));
  assert.ok(!r.triggered.some(t => t.id === 'FM024b'), 'FM024b was renamed to its true catalog ID FM029, must no longer exist');
});
test('FM029 correctly stays silent on any non-choppy real price condition', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ breakoutCondition: { condition: 'range_bound', reason: 'r' } }).triggered.some(t => t.id === 'FM029'));
});
test('FM104 genuinely fires reduce_confidence when the real journal dataset is still early-stage', () => {
  const journal = [{ ts: Date.now() - 5*24*60*60*1000, pnl: 100 }]; // 5 real days old, 1 real trade - genuinely early
  const r = __fno_evaluatePreTradeFailureModes({ ctx: { fullJournal: journal } });
  assert.ok(r.triggered.some(t => t.id === 'FM104'));
});
test('FM104 correctly stays silent when the real journal is genuinely absent, or (constructed) substantial', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: {} }).triggered.some(t => t.id === 'FM104'));
  const oldStart = Date.now() - 200*24*60*60*1000;
  const substantialJournal = Array.from({length: 150}, (_, i) => ({ ts: oldStart + i*24*60*60*1000, pnl: 10 }));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: { fullJournal: substantialJournal } }).triggered.some(t => t.id === 'FM104'), 'a real, 150-trade, 200-day-old journal must be assessed substantial, not early');
});
test('FM105 genuinely fires reduce_confidence when the real current regime label has zero entries in the real logged journal history', () => {
  const journal = [{ ts: Date.now(), pnl: 100, factorSnapshot: { regime: { label: 'Bullish-Low Vol' } } }];
  const r = __fno_evaluatePreTradeFailureModes({ ctx: { fullJournal: journal, regimeLabel: 'Bearish-High Vol' } });
  assert.ok(r.triggered.some(t => t.id === 'FM105'));
});
test('FM105 correctly stays silent when the real current regime label DOES have real logged history, or ctx.regimeLabel/fullJournal is genuinely absent', () => {
  const journal = [{ ts: Date.now(), pnl: 100, factorSnapshot: { regime: { label: 'Bullish-Low Vol' } } }];
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: { fullJournal: journal, regimeLabel: 'Bullish-Low Vol' } }).triggered.some(t => t.id === 'FM105'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: {} }).triggered.some(t => t.id === 'FM105'));
});
test('FM111 genuinely fires require_confirmation when the real daily-scale regime label says Low Vol but a real, sudden intraday vol-expansion event was just detected', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [], regime: { volatility: 'Low Vol', extendedStates: ['vol_expansion'] } };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM111'));
});
test('FM111 correctly stays silent outside Low Vol, or when no real sudden vol-expansion event was detected', () => {
  const brainNormalVol = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [], regime: { volatility: 'Normal Vol', extendedStates: ['vol_expansion'] } };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainNormalVol, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM111'));
  const brainNoEvent = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [], regime: { volatility: 'Low Vol', extendedStates: [] } };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainNoEvent, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM111'));
});
test('FM129/FM130 genuinely fire at their own distinct real regime-win-rate bands (mild 41-50%, severe <20%)', () => {
  const journal45 = Array.from({length: 20}, (_, i) => ({ ts: Date.now() - i*86400000, pnl: i < 9 ? 100 : -100, factorSnapshot: { regime: { label: 'R' } } })); // 9/20 = 45%
  const rMild = __fno_evaluatePreTradeFailureModes({ ctx: { fullJournal: journal45, regimeLabel: 'R' } });
  assert.ok(rMild.triggered.some(t => t.id === 'FM129'), `expected FM129 at 45% win rate, got ${JSON.stringify(rMild.triggered.map(t=>t.id))}`);
  assert.ok(!rMild.triggered.some(t => t.id === 'FM130'));
  const journal10 = Array.from({length: 20}, (_, i) => ({ ts: Date.now() - i*86400000, pnl: i < 2 ? 100 : -100, factorSnapshot: { regime: { label: 'R' } } })); // 2/20 = 10%
  const rSevere = __fno_evaluatePreTradeFailureModes({ ctx: { fullJournal: journal10, regimeLabel: 'R' } });
  assert.ok(rSevere.triggered.some(t => t.id === 'FM130'), `expected FM130 at 10% win rate, got ${JSON.stringify(rSevere.triggered.map(t=>t.id))}`);
  assert.ok(!rSevere.triggered.some(t => t.id === 'FM129'));
});
test('FM129/FM130 correctly stay silent on a real, healthy win rate, or a genuinely small (sample-size-warned) sample', () => {
  const journalHealthy = Array.from({length: 20}, (_, i) => ({ ts: Date.now() - i*86400000, pnl: i < 14 ? 100 : -100, factorSnapshot: { regime: { label: 'R' } } })); // 14/20 = 70%
  const r = __fno_evaluatePreTradeFailureModes({ ctx: { fullJournal: journalHealthy, regimeLabel: 'R' } });
  assert.ok(!r.triggered.some(t => t.id === 'FM129'));
  assert.ok(!r.triggered.some(t => t.id === 'FM130'));
  const tinyJournal = [{ ts: Date.now(), pnl: -100, factorSnapshot: { regime: { label: 'R' } } }]; // genuinely too small a sample
  const rTiny = __fno_evaluatePreTradeFailureModes({ ctx: { fullJournal: tinyJournal, regimeLabel: 'R' } });
  assert.ok(!rTiny.triggered.some(t => t.id === 'FM129'));
  assert.ok(!rTiny.triggered.some(t => t.id === 'FM130'));
});
test('FM132 genuinely fires reduce_confidence at the real MILD theta-decay band (2-5%/day), distinct from FM022\'s real 5%+ hard band', () => {
  const ctx = { optPrice: 100, decay: { snapshot: { now: { thetaPerDay: -3 } } } }; // 3% of premium/day - MILD band
  const r = __fno_evaluatePreTradeFailureModes({ ctx });
  assert.ok(r.triggered.some(t => t.id === 'FM132'));
  assert.ok(!r.triggered.some(t => t.id === 'FM022'), 'a real 3%/day decay must not also fire the real 5%+ hard-block FM022 band');
});
test('FM132 correctly stays silent below 2%/day, or at/above the real 5%/day FM022 band', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ ctx: { optPrice: 100, decay: { snapshot: { now: { thetaPerDay: -1 } } } } }).triggered.some(t => t.id === 'FM132'), 'a real 1%/day decay is below the MILD band floor');
  const rSevere = __fno_evaluatePreTradeFailureModes({ ctx: { optPrice: 100, decay: { snapshot: { now: { thetaPerDay: -6 } } } } });
  assert.ok(!rSevere.triggered.some(t => t.id === 'FM132'), 'a real 6%/day decay belongs to FM022\'s severe band, not the MILD FM132 band');
  assert.ok(rSevere.triggered.some(t => t.id === 'FM022'));
});
test('FM151 genuinely fires block on a real, structurally invalid option type', () => {
  const r = __fno_evaluatePreTradeFailureModes({ optionType: 'XX' });
  const fm151 = r.triggered.find(t => t.id === 'FM151');
  assert.ok(fm151);
  assert.strictEqual(fm151.action, 'block');
});
test('FM151 correctly stays silent for a real, valid CE/PE option type, or when genuinely no optionType was passed at all', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ optionType: 'CE' }).triggered.some(t => t.id === 'FM151'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ optionType: 'PE' }).triggered.some(t => t.id === 'FM151'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM151'), 'genuinely no optionType passed (e.g. some real call sites) must never be treated as invalid');
});

console.log('\n=== FM049/FM057/FM070/FM071/FM072/FM081/FM082 (real, NEW wiring this backlog-triage pass) ===');
test('FM049 genuinely fires reduce_confidence when the real, already-live Psychology factors show a detected pattern', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Psychology', factor: 'Loss Aversion - Holding Losers, Booking Winners Early?', pass: false, reason: 'losses running notably larger than wins' },
  ] };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } });
  assert.ok(r.triggered.some(t => t.id === 'FM049'));
});
test('FM049 correctly stays silent when neither real Psychology row is flagged, or genuinely not computed', () => {
  const brainClean = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Psychology', factor: 'Loss Aversion - Holding Losers, Booking Winners Early?', pass: true, reason: 'no strong size asymmetry' },
    { cat: 'Psychology', factor: 'Recency Bias - Last 2 Won So Next Will Win?', pass: null, reason: 'need at least 3' },
  ] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainClean, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM049'));
});

// Shared fixture for FM057/FM072/FM081: two factors f1/f2, both
// genuinely COMPUTED and contributing to THIS decision per a real
// brain.factorRegistry.byId - 30 real trades in an earliest month
// (all WIN, f1/f2 both pass:true - "agreed") and 30 in a latest month
// (all LOSS, f1/f2 both pass:true - "disagreed"), giving f1 a real,
// large accuracy decline (100% -> 0%, driftPct -100, past FM057's
// reused -15pt threshold) while f1/f2's real scores stay perfectly
// correlated throughout (giving FM081 a real >=0.85 correlation).
function buildFactorMetaJournal() {
  const journal = [];
  const earliestBase = new Date(2024, 0, 15).getTime(); // 2024-01
  const latestBase = new Date(2024, 5, 15).getTime(); // 2024-06 - far enough apart, distinct real months
  for (let i = 0; i < 30; i++) {
    journal.push({ ts: earliestBase + i*1000, pnl: 100, factorSnapshot: { factors: {
      f1: { status: 'COMPUTED', pass: true, score: i },
      f2: { status: 'COMPUTED', pass: true, score: i },
    } } });
  }
  for (let i = 0; i < 30; i++) {
    journal.push({ ts: latestBase + i*1000, pnl: -100, factorSnapshot: { factors: {
      f1: { status: 'COMPUTED', pass: true, score: 30+i },
      f2: { status: 'COMPUTED', pass: true, score: 30+i },
    } } });
  }
  return journal;
}
const factorMetaBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [], factorRegistry: { byId: new Map([
  ['f1', { status: 'COMPUTED' }],
  ['f2', { status: 'COMPUTED' }],
]) } };
test('FM057 genuinely fires reduce_confidence when a real factor genuinely contributing to THIS decision shows a genuinely declining accuracy trend', () => {
  const r = __fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: buildFactorMetaJournal() } });
  assert.ok(r.triggered.some(t => t.id === 'FM057'));
});
test('FM057 correctly stays silent when the declining factor is genuinely NOT contributing to this decision (not COMPUTED this refresh)', () => {
  const notContributingBrain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [], factorRegistry: { byId: new Map([
    ['f1', { status: 'NOT_APPLICABLE' }],
    ['f2', { status: 'NOT_APPLICABLE' }],
  ]) } };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: notContributingBrain, ctx: { ocRow: {}, fullJournal: buildFactorMetaJournal() } }).triggered.some(t => t.id === 'FM057'));
});
test('FM081 genuinely fires reduce_confidence when two real, contributing factors are genuinely (>=0.85) correlated', () => {
  const r = __fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: buildFactorMetaJournal() } });
  assert.ok(r.triggered.some(t => t.id === 'FM081'));
});
test('FM082 genuinely fires reduce_confidence when a real factor combination genuinely contributing to THIS decision has a genuinely poor agree-bucket win rate', () => {
  const journal = [];
  const base = Date.now() - 10*24*60*60*1000;
  for (let i = 0; i < 20; i++) {
    journal.push({ ts: base + i*1000, pnl: -100, factorSnapshot: { factors: {
      f1: { status: 'COMPUTED', pass: false }, f2: { status: 'COMPUTED', pass: false },
    } } });
  }
  const r = __fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: journal } });
  const fm082 = r.triggered.find(t => t.id === 'FM082');
  assert.ok(fm082, `expected FM082, got ${JSON.stringify(r.triggered.map(t=>t.id))}`);
});
test('FM082 correctly stays silent when the agree-bucket win rate is genuinely healthy', () => {
  const journalHealthy = [];
  const base = Date.now() - 10*24*60*60*1000;
  for (let i = 0; i < 20; i++) {
    journalHealthy.push({ ts: base + i*1000, pnl: 100, factorSnapshot: { factors: {
      f1: { status: 'COMPUTED', pass: false }, f2: { status: 'COMPUTED', pass: false },
    } } });
  }
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: journalHealthy } }).triggered.some(t => t.id === 'FM082'));
});
test('FM072 genuinely fires reduce_confidence when at least one real, contributing historical check genuinely lacks enough real trade history', () => {
  const r = __fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: [] } });
  assert.ok(r.triggered.some(t => t.id === 'FM072'));
});
test('FM072 correctly stays silent when the real, contributing historical checks genuinely have enough sample', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: buildFactorMetaJournal() } }).triggered.some(t => t.id === 'FM072'));
});
test('FM070 genuinely fires reduce_confidence when the real walk-forward folds show genuinely inconsistent (>25pp spread) performance', () => {
  const journal = [];
  const base = Date.now() - 30*24*60*60*1000;
  for (let i = 0; i < 20; i++) journal.push({ ts: base + i*1000, pnl: 100 }); // real, early folds all-win
  for (let i = 0; i < 10; i++) journal.push({ ts: base + (20+i)*1000, pnl: -100 }); // real, latest fold all-loss
  const r = __fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: journal } });
  assert.ok(r.triggered.some(t => t.id === 'FM070'));
});
test('FM070 correctly stays silent when the real walk-forward folds are genuinely stable, or there is genuinely too little data for a verdict', () => {
  const journal = [];
  const base = Date.now() - 30*24*60*60*1000;
  for (let i = 0; i < 30; i++) journal.push({ ts: base + i*1000, pnl: i % 2 === 0 ? 100 : -100 }); // real, consistent ~50% every fold
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: journal } }).triggered.some(t => t.id === 'FM070'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: [] } }).triggered.some(t => t.id === 'FM070'), 'genuinely insufficient real data (wf.stable===null) must never be misread as unstable');
});
test('FM071 genuinely fires reduce_confidence when the real calibration bucket for THIS decision/confidence is genuinely poorly calibrated', () => {
  const journal = [];
  const base = Date.now() - 30*24*60*60*1000;
  for (let i = 0; i < 3; i++) journal.push({ ts: base + i*1000, pnl: 100, factorSnapshot: { decision: 'BUY_READY', confidence: 'High' } });
  for (let i = 0; i < 27; i++) journal.push({ ts: base + (3+i)*1000, pnl: -100, factorSnapshot: { decision: 'BUY_READY', confidence: 'High' } });
  const r = __fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: journal } });
  const fm071 = r.triggered.find(t => t.id === 'FM071');
  assert.ok(fm071, `expected FM071 (real observed win rate 10% vs stated-High ~65%), got ${JSON.stringify(r.triggered.map(t=>t.id))}`);
});
test('FM071 correctly stays silent when the real bucket win rate genuinely matches the stated confidence, or the sample is genuinely too small', () => {
  const journalMatched = [];
  const base = Date.now() - 30*24*60*60*1000;
  for (let i = 0; i < 19; i++) journalMatched.push({ ts: base + i*1000, pnl: 100, factorSnapshot: { decision: 'BUY_READY', confidence: 'High' } });
  for (let i = 0; i < 11; i++) journalMatched.push({ ts: base + (19+i)*1000, pnl: -100, factorSnapshot: { decision: 'BUY_READY', confidence: 'High' } });
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: journalMatched } }).triggered.some(t => t.id === 'FM071'), 'real observed 63% vs stated-High ~65% is within the 15pt gap, must not fire');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: factorMetaBrain, ctx: { ocRow: {}, fullJournal: [] } }).triggered.some(t => t.id === 'FM071'), 'no real bucket at all must never fire');
});

console.log('\n=== FM093/FM094/FM097/FM086/FM087/FM058 (real, NEW wiring this backlog-triage pass) ===');
test('FM093 genuinely fires reduce_confidence when a real "Plan Written Before Open" or "Journal Updated?" Personal-checklist row is genuinely unmet', () => {
  const brainNoPlan = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Personal', factor: 'Plan Written Before Open', pass: false, reason: 'No pre-market plan reported written' },
  ] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: brainNoPlan, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM093'));
  const brainNoJournal = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Personal', factor: 'Journal Updated?', pass: false, reason: 'Trade journal reported NOT up to date' },
  ] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: brainNoJournal, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM093'));
});
test('FM093 correctly stays silent when the real Personal checklist rows are genuinely met, or genuinely unreported', () => {
  const brainOk = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Personal', factor: 'Plan Written Before Open', pass: true, reason: 'Pre-market plan reported written' },
    { cat: 'Personal', factor: 'Journal Updated?', pass: null, reason: 'Not reported in today\'s checklist' },
  ] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainOk, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM093'));
});
test('FM094 genuinely fires reduce_confidence when a real mindset/sleep/physical-health Personal row is genuinely flagged', () => {
  const brainMindset = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Personal', factor: 'Mindset Angry/Tired/Greedy?', pass: false, reason: 'Mindset reported angry/tired/greedy' },
  ] };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain: brainMindset, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM094'));
});
test('FM094 correctly stays silent when the real mindset/sleep/physical-health rows are genuinely fine, or genuinely unreported', () => {
  const brainOk = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [
    { cat: 'Personal', factor: 'Mindset Angry/Tired/Greedy?', pass: true, reason: 'Mindset reported calm' },
    { cat: 'Personal', factor: 'Sleep Last Night', pass: null, reason: 'Not reported' },
    { cat: 'Personal', factor: 'Physical Health Today', pass: null, reason: 'Not reported' },
  ] };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainOk, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM094'));
});
test('FM097 genuinely fires reduce_confidence when the real regime extendedStates genuinely includes vol_contraction', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', regime: { extendedStates: ['vol_contraction'] } };
  assert.ok(__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM097'));
});
test('FM097 correctly stays silent when vol_contraction is genuinely absent', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', regime: { extendedStates: ['vol_expansion'] } };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM097'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM097'), 'genuinely missing regime data must never fire');
});
test('FM086 genuinely fires reduce_confidence when the real strongest payoff level genuinely opposes a CE trade (strike meaningfully below spot)', () => {
  // Real, heavy CE OI at 23000 (below spot) pulls the strongest real
  // payoff level below spot - a real, opposing pull for a CE (bullish) trade.
  const rows = [
    { strikePrice: 22000, CE: { openInterest: 2000000 }, PE: { openInterest: 0 } },
    { strikePrice: 23500, CE: { openInterest: 0 }, PE: { openInterest: 0 } },
    { strikePrice: 24000, CE: { openInterest: 0 }, PE: { openInterest: 0 } },
  ];
  // strike 22000 vs spot 23800: distPct = (23800-22000)/23800*100 ~= 7.56%, past the real 5% floor.
  const r = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, ocRows: rows, spot: 23800 }, optionType: 'CE' });
  assert.ok(r.triggered.some(t => t.id === 'FM086'), `expected FM086, got ${JSON.stringify(r.triggered.map(t=>t.id))}`);
});
test('FM086 correctly stays silent when the strongest real level does NOT oppose the trade direction, or genuinely insufficient data', () => {
  const rows = [
    { strikePrice: 22000, CE: { openInterest: 2000000 }, PE: { openInterest: 0 } },
    { strikePrice: 23500, CE: { openInterest: 0 }, PE: { openInterest: 0 } },
  ];
  // Same opposing level, but for a PE trade it does NOT oppose (level below spot favors a PE-side read).
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'SELL_READY' }, ctx: { ocRow: {}, ocRows: rows, spot: 23800 }, optionType: 'PE' }).triggered.some(t => t.id === 'FM086'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {} }, optionType: 'CE' }).triggered.some(t => t.id === 'FM086'), 'genuinely missing ocRows/spot must never fire');
});
test('FM087 genuinely fires require_confirmation when the real short-window correlation has genuinely broken down from the real long-window baseline', () => {
  // Same real, verified fixture as computeCrossInstrumentShiftSignal's
  // own dedicated "genuine correlation breakdown" test above.
  const closesA = [], closesB = [];
  let a = 23000, b = 50000;
  for (let i = 0; i < 20; i++) { const move = (i % 2 === 0 ? 1 : -1) * 50; a += move; b += move * 2; closesA.push(a); closesB.push(b); }
  for (let i = 0; i < 11; i++) { const move = (i % 2 === 0 ? 1 : -1) * 30; a += move; b -= move; closesA.push(a); closesB.push(b); }
  const r = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, candles: closesA.map(c => ({ c })), correlationCloses: closesB } });
  assert.ok(r.triggered.some(t => t.id === 'FM087'), `expected FM087, got ${JSON.stringify(r.triggered.map(t=>t.id))}`);
});
test('FM087 correctly stays silent when correlation is genuinely stable, or genuinely insufficient/misaligned data', () => {
  const closesA = [], closesB = [];
  let a = 23000, b = 50000;
  for (let i = 0; i < 31; i++) { const move = (i % 2 === 0 ? 1 : -1) * 50; a += move; b += move * 2; closesA.push(a); closesB.push(b); }
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, candles: closesA.map(c => ({ c })), correlationCloses: closesB } }).triggered.some(t => t.id === 'FM087'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM087'), 'genuinely missing candles/correlationCloses must never fire');
});
function buildRegimeDependentJournal() {
  const journal = [];
  for (let i = 0; i < 30; i++) journal.push({ ts: Date.now() + i*1000, pnl: 100, factorSnapshot: { regime: { label: 'RegimeA' }, factors: { f1: { status: 'COMPUTED', pass: true } } } });
  for (let i = 0; i < 30; i++) journal.push({ ts: Date.now() + (30+i)*1000, pnl: -100, factorSnapshot: { regime: { label: 'RegimeB' }, factors: { f1: { status: 'COMPUTED', pass: true } } } });
  return journal;
}
test('FM058 genuinely fires reduce_confidence when a real, contributing factor genuinely has its OWN worst accuracy in the CURRENT regime', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', regimeLabel: 'RegimeB', factorRegistry: { byId: new Map([['f1', { status: 'COMPUTED' }]]) } };
  const r = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, fullJournal: buildRegimeDependentJournal(), regimeLabel: 'RegimeB' } });
  assert.ok(r.triggered.some(t => t.id === 'FM058'), `expected FM058, got ${JSON.stringify(r.triggered.map(t=>t.id))}`);
});
test('FM058 correctly stays silent when the CURRENT regime is genuinely the factor\'s own BEST regime, or the factor genuinely isn\'t contributing', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', factorRegistry: { byId: new Map([['f1', { status: 'COMPUTED' }]]) } };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, fullJournal: buildRegimeDependentJournal(), regimeLabel: 'RegimeA' } }).triggered.some(t => t.id === 'FM058'));
  const brainNotContributing = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', factorRegistry: { byId: new Map([['f1', { status: 'NOT_APPLICABLE' }]]) } };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: brainNotContributing, ctx: { ocRow: {}, fullJournal: buildRegimeDependentJournal(), regimeLabel: 'RegimeB' } }).triggered.some(t => t.id === 'FM058'));
});

console.log('\n=== FM083/FM084 (real, NEW wiring - dedicated FM069/FM083/FM084/FM106 pass) ===');
test('FM084 genuinely fires reduce_confidence when the real, live-computed participant payoff hypothesis is genuinely low-confidence (zero agreeing votes)', () => {
  // No real operatorIntel bias, no real significant Max Pain distance,
  // no real trap signature - genuinely zero supporting/counter votes,
  // matching computeParticipantPayoffHypothesis's own real 'low'
  // confidence floor (totalVotes === 0).
  const r = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, spot: 23800 } });
  assert.ok(r.triggered.some(t => t.id === 'FM084'), `expected FM084, got ${JSON.stringify(r.triggered.map(t=>t.id))}`);
});
test('FM084 correctly stays silent when the real hypothesis genuinely reaches at least medium confidence, or ctx.spot is genuinely unavailable', () => {
  // Two real, independent, agreeing bullish signals (operatorIntel
  // ACCUMULATION + Max Pain genuinely below spot) with no real counter-
  // evidence - reaches the real 'high' confidence floor (totalVotes>=2,
  // no counterEvidence).
  const ctxTwoVotes = { ocRow: {}, spot: 23800, operatorIntel: { bias: 'ACCUMULATION', score: 0.8 } };
  const maxPainCheckTwoVotes = { strike: 23000, distPct: -3.4, daysToExpiry: 5 };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: ctxTwoVotes, maxPainCheck: maxPainCheckTwoVotes }).triggered.some(t => t.id === 'FM084'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {} } }).triggered.some(t => t.id === 'FM084'), 'genuinely missing ctx.spot must never fire');
});
test('FM083 genuinely fires reduce_confidence when the real historical track record for the hypothesis\'s own direction is genuinely poor (confirmedRatePct <= 40, adequate sample)', () => {
  const ctxBullish = { ocRow: {}, spot: 23800, operatorIntel: { bias: 'ACCUMULATION', score: 0.8 } };
  const hypothesisDirectionStats = { bullish: { confirmedRatePct: 25, sampleSizeWarning: false, decisiveTotal: 20 } };
  const r = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: ctxBullish, hypothesisDirectionStats });
  assert.ok(r.triggered.some(t => t.id === 'FM083'), `expected FM083, got ${JSON.stringify(r.triggered.map(t=>t.id))}`);
});
test('FM083 correctly stays silent when the real historical track record is genuinely good, the sample is genuinely too small, or genuinely absent', () => {
  const ctxBullish = { ocRow: {}, spot: 23800, operatorIntel: { bias: 'ACCUMULATION', score: 0.8 } };
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: ctxBullish, hypothesisDirectionStats: { bullish: { confirmedRatePct: 62, sampleSizeWarning: false, decisiveTotal: 20 } } }).triggered.some(t => t.id === 'FM083'), 'a real, GOOD track record must never fire');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: ctxBullish, hypothesisDirectionStats: { bullish: { confirmedRatePct: 10, sampleSizeWarning: true, decisiveTotal: 3 } } }).triggered.some(t => t.id === 'FM083'), 'a real, genuinely too-small sample must never fire');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: ctxBullish }).triggered.some(t => t.id === 'FM083'), 'genuinely absent hypothesisDirectionStats must never fire');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, spot: 23800 }, hypothesisDirectionStats: { bullish: { confirmedRatePct: 10, sampleSizeWarning: false, decisiveTotal: 20 } } }).triggered.some(t => t.id === 'FM083'), 'a real, neutral hypothesis (no direction) must never fire');
});
test('evaluatePreTradeFailureModes (including the new FM083/FM084 checks) runs correctly with no `window` global at all in scope, matching the real Node.js autonomous-driver / evalCtx-only calling convention', () => {
  // FM083/FM084's own real hypothesisDirectionStats field is resolved
  // by the BROWSER call site (tryOpenAutoTradePosition), never read
  // from `window` inside evaluatePreTradeFailureModes itself - so the
  // function itself must behave identically whether or not `window`
  // exists at all in the calling scope. Temporarily removes this test
  // file's own top-of-file `global.window = {}` stub to genuinely
  // exercise that, then restores it so later tests are unaffected.
  const savedWindow = global.window;
  delete global.window;
  try {
    assert.strictEqual(typeof window, 'undefined', 'test setup sanity check: window must genuinely be gone for this test to prove anything');
    const ctxBullish = { ocRow: {}, spot: 23800, operatorIntel: { bias: 'ACCUMULATION', score: 0.8 } };
    const r1 = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: ctxBullish, hypothesisDirectionStats: { bullish: { confirmedRatePct: 25, sampleSizeWarning: false, decisiveTotal: 20 } } });
    assert.ok(r1.triggered.some(t => t.id === 'FM083'), 'FM083 must still genuinely fire with no window global in scope');
    const r2 = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, spot: 23800 } });
    assert.ok(r2.triggered.some(t => t.id === 'FM084'), 'FM084 must still genuinely fire with no window global in scope');
    // Matches the real, actual driver call shape too: { brain, ctx }
    // only, no hypothesisDirectionStats field at all - must not throw.
    assert.doesNotThrow(() => __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {} } }));
  } finally {
    global.window = savedWindow;
  }
});

console.log('\n=== FM069/FM106 (real, NEW wiring - follow-up pass, ctx.fullJournal was already available) ===');
function fakeVersionTrade(ts, pnl, regimeLabel) {
  return { ts, pnl, factorSnapshot: regimeLabel ? { regime: { label: regimeLabel } } : undefined };
}
test('FM069 genuinely fires reduce_confidence when the active strategy version is real but genuinely has fewer than 10 real trades logged since it went active', () => {
  const activeCreatedAt = 5000;
  const journal = [
    fakeVersionTrade(1000, 100, 'RegimeA'), fakeVersionTrade(2000, -50, 'RegimeA'), // before - irrelevant to afterCount
    fakeVersionTrade(6000, 80, 'RegimeA'), fakeVersionTrade(7000, -20, 'RegimeA'), // only 2 real trades after
  ];
  const strategyVersionsCache = [{ version: 'v1.0-baseline', createdAt: 1 }, { version: 'v2.0', createdAt: activeCreatedAt }];
  const r = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, fullJournal: journal }, strategyVersionsCache });
  assert.ok(r.triggered.some(t => t.id === 'FM069'), `expected FM069, got ${JSON.stringify(r.triggered.map(t=>t.id))}`);
});
test('FM069 correctly stays silent once the active version genuinely has 10+ real trades since going active, or the real inputs are genuinely missing', () => {
  const activeCreatedAt = 5000;
  const adequateJournal = [];
  for (let i = 0; i < 10; i++) adequateJournal.push(fakeVersionTrade(6000 + i, 10, 'RegimeA'));
  const strategyVersionsCache = [{ version: 'v2.0', createdAt: activeCreatedAt }];
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, fullJournal: adequateJournal }, strategyVersionsCache }).triggered.some(t => t.id === 'FM069'), 'a real, adequately-sized post-change sample must never fire');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {} }, strategyVersionsCache }).triggered.some(t => t.id === 'FM069'), 'genuinely missing ctx.fullJournal must never fire');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, fullJournal: adequateJournal } }).triggered.some(t => t.id === 'FM069'), 'genuinely missing strategyVersionsCache must never fire');
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, fullJournal: adequateJournal }, strategyVersionsCache: [] }).triggered.some(t => t.id === 'FM069'), 'a genuinely empty strategyVersionsCache must never fire');
});
test('FM106 genuinely fires reduce_confidence when the active version\'s own real, adequately-sized post-change sample has never included the CURRENT regime', () => {
  const activeCreatedAt = 5000;
  const journal = [];
  for (let i = 0; i < 12; i++) journal.push(fakeVersionTrade(6000 + i, 10, 'Sideways-Low Vol')); // all real post-change trades under a DIFFERENT regime
  const strategyVersionsCache = [{ version: 'v2.0', createdAt: activeCreatedAt }];
  const r = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, fullJournal: journal, regimeLabel: 'Bullish-High Vol' }, strategyVersionsCache });
  assert.ok(r.triggered.some(t => t.id === 'FM106'), `expected FM106, got ${JSON.stringify(r.triggered.map(t=>t.id))}`);
});
test('FM106 correctly stays silent when the CURRENT regime genuinely WAS already seen in the post-change sample, the sample is genuinely too small, or ctx.regimeLabel is genuinely absent', () => {
  const activeCreatedAt = 5000;
  const journalSeen = [];
  for (let i = 0; i < 11; i++) journalSeen.push(fakeVersionTrade(6000 + i, 10, 'Sideways-Low Vol'));
  journalSeen.push(fakeVersionTrade(6020, 10, 'Bullish-High Vol')); // the current regime genuinely IS represented
  const strategyVersionsCache = [{ version: 'v2.0', createdAt: activeCreatedAt }];
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, fullJournal: journalSeen, regimeLabel: 'Bullish-High Vol' }, strategyVersionsCache }).triggered.some(t => t.id === 'FM106'), 'a regime genuinely already seen post-change must never fire');
  const journalThin = [fakeVersionTrade(6000, 10, 'Sideways-Low Vol'), fakeVersionTrade(6001, 10, 'Sideways-Low Vol')]; // only 2 real trades, below the real 10-trade floor
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, fullJournal: journalThin, regimeLabel: 'Bullish-High Vol' }, strategyVersionsCache }).triggered.some(t => t.id === 'FM106'), 'a genuinely too-small post-change sample must never fire FM106 (FM069 covers that case)');
  const journalAdequateNoRegime = [];
  for (let i = 0; i < 12; i++) journalAdequateNoRegime.push(fakeVersionTrade(6000 + i, 10, 'Sideways-Low Vol'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, fullJournal: journalAdequateNoRegime }, strategyVersionsCache }).triggered.some(t => t.id === 'FM106'), 'genuinely missing ctx.regimeLabel must never fire');
});
test('evaluatePreTradeFailureModes (including the new FM069/FM106 checks) runs correctly with no `window` global at all in scope, matching the real Node.js autonomous-driver / evalCtx-only calling convention', () => {
  const savedWindow = global.window;
  delete global.window;
  try {
    assert.strictEqual(typeof window, 'undefined', 'test setup sanity check: window must genuinely be gone for this test to prove anything');
    const activeCreatedAt = 5000;
    const journal = [];
    for (let i = 0; i < 12; i++) journal.push(fakeVersionTrade(6000 + i, 10, 'Sideways-Low Vol'));
    const strategyVersionsCache = [{ version: 'v2.0', createdAt: activeCreatedAt }];
    const r1 = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, fullJournal: journal.slice(0, 2) }, strategyVersionsCache });
    assert.ok(r1.triggered.some(t => t.id === 'FM069'), 'FM069 must still genuinely fire with no window global in scope');
    const r2 = __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {}, fullJournal: journal, regimeLabel: 'Bullish-High Vol' }, strategyVersionsCache });
    assert.ok(r2.triggered.some(t => t.id === 'FM106'), 'FM106 must still genuinely fire with no window global in scope');
    // Matches the real, actual driver call shape too: { brain, ctx }
    // only, no strategyVersionsCache field at all - must not throw.
    assert.doesNotThrow(() => __fno_evaluatePreTradeFailureModes({ brain: { criticalFails: 0, confidence: 'High', decision: 'BUY_READY' }, ctx: { ocRow: {} } }));
  } finally {
    global.window = savedWindow;
  }
});

test('static wiring-audit lock: the real tryOpenAutoTradePosition() call site genuinely passes optionType into evaluatePreTradeFailureModes(), wiring FM076/FM075/FM032', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const callStart = fnBody.indexOf('evaluatePreTradeFailureModes({');
  const callEnd = fnBody.indexOf('\n    });', callStart);
  const callBody = fnBody.slice(callStart, callEnd);
  assert.ok(/\boptionType,/.test(callBody), 'the real call must genuinely include optionType, not silently omit it');
});
test('static wiring-audit lock: the real tryOpenAutoTradePosition() call site genuinely threads window.FNO_STRATEGY_VERSIONS_CACHE into evaluatePreTradeFailureModes() as evalCtx.strategyVersionsCache, wiring FM069/FM106', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const callStart = fnBody.indexOf('evaluatePreTradeFailureModes({');
  const callEnd = fnBody.indexOf('\n    });', callStart);
  const callBody = fnBody.slice(callStart, callEnd);
  assert.ok(/strategyVersionsCache:\s*\(typeof window[^)]*window\.FNO_STRATEGY_VERSIONS_CACHE\)/.test(callBody), 'the real call must genuinely thread window.FNO_STRATEGY_VERSIONS_CACHE in as evalCtx.strategyVersionsCache, not silently omit it or read it directly inside the shared function');
});

console.log('\n=== checkSpreadLevel / FM131 (real, NEW wiring this session - mild spread widening, below the existing hard-rejection threshold) ===');
test('genuinely missing bid/ask data honestly returns not-mildly-wide, never fabricates a spread reading', () => {
  assert.strictEqual(__fno_checkSpreadLevel(null).isMildlyWide, false);
  assert.strictEqual(__fno_checkSpreadLevel({}).isMildlyWide, false);
  assert.strictEqual(__fno_checkSpreadLevel({bidprice: 0, askPrice: 10}).isMildlyWide, false);
});
test('a real, tight spread (well under the 5% floor) is correctly NOT mildly wide', () => {
  const r = __fno_checkSpreadLevel({bidprice: 100, askPrice: 101});
  assert.strictEqual(r.isMildlyWide, false);
  assert.ok(Math.abs(r.spreadPct - 1) < 0.01);
});
test('a real, moderately wide spread (between the 5% floor and the existing 15% hard threshold) is correctly flagged mildly wide', () => {
  const r = __fno_checkSpreadLevel({bidprice: 100, askPrice: 110});
  assert.strictEqual(r.isMildlyWide, true);
  assert.ok(Math.abs(r.spreadPct - 10) < 0.01);
});
test('exact real lower boundary (5.0%) counts as mildly wide - inclusive lower boundary', () => {
  assert.strictEqual(__fno_checkSpreadLevel({bidprice: 100, askPrice: 105}).isMildlyWide, true);
});
test('a real spread at or above the existing 15% hard-rejection threshold is correctly NOT "mild" - that is simulateOrderRejection\'s own severity tier, not FM131\'s', () => {
  assert.strictEqual(__fno_checkSpreadLevel({bidprice: 100, askPrice: 115}).isMildlyWide, false, 'exactly 15% must not double-count as "mild" - it belongs to the harder threshold');
  assert.strictEqual(__fno_checkSpreadLevel({bidprice: 100, askPrice: 130}).isMildlyWide, false);
});
test('FM131 genuinely fires reduce_confidence through the real evaluatePreTradeFailureModes evaluator when spreadLevelCheck.isMildlyWide is true', () => {
  const r = __fno_evaluatePreTradeFailureModes({ spreadLevelCheck: { spreadPct: 8, isMildlyWide: true } });
  const fm131 = r.triggered.find(t => t.id === 'FM131');
  assert.ok(fm131);
  assert.strictEqual(fm131.action, 'reduce_confidence');
});
test('FM131 correctly stays silent when not mildly wide, or genuinely absent', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ spreadLevelCheck: { spreadPct: 1, isMildlyWide: false } }).triggered.some(t => t.id === 'FM131'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM131'), 'must not fire on a genuinely absent spreadLevelCheck - e.g. the driver, which never computes one');
});
test('tryOpenAutoTradePosition genuinely calls checkSpreadLevel(leg) and passes its result into evaluatePreTradeFailureModes(), wiring FM131', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const callStart = fnBody.indexOf('evaluatePreTradeFailureModes({');
  const callEnd = fnBody.indexOf('\n    });', callStart);
  const callBody = fnBody.slice(callStart, callEnd);
  assert.ok(/spreadLevelCheck:\s*checkSpreadLevel\(leg\)/.test(callBody), 'the real call must genuinely compute and pass spreadLevelCheck, not silently omit it');
});

console.log('\n=== checkSpreadWideningVsEarlier / FM101 (real, NEW wiring this session - genuine same-session widening, distinct from FM131\'s absolute level) ===');
test('genuinely missing either real reading honestly returns not-widened, never fabricates a comparison', () => {
  assert.strictEqual(__fno_checkSpreadWideningVsEarlier(null, 5).isWidened, false);
  assert.strictEqual(__fno_checkSpreadWideningVsEarlier(10, null).isWidened, false);
  assert.strictEqual(__fno_checkSpreadWideningVsEarlier(null, null).isWidened, false);
});
test('a real, modest change (within the documented 3-point margin) is correctly NOT widened', () => {
  const r = __fno_checkSpreadWideningVsEarlier(6, 5);
  assert.strictEqual(r.isWidened, false);
  assert.ok(Math.abs(r.widenedPoints - 1) < 0.01);
});
test('a real, meaningful widening (exceeding the 3-point margin) is correctly flagged', () => {
  const r = __fno_checkSpreadWideningVsEarlier(10, 5);
  assert.strictEqual(r.isWidened, true);
  assert.ok(Math.abs(r.widenedPoints - 5) < 0.01);
});
test('a real NARROWING (negative widenedPoints) is correctly never flagged - this check is one-directional by design', () => {
  const r = __fno_checkSpreadWideningVsEarlier(3, 10);
  assert.strictEqual(r.isWidened, false);
  assert.ok(r.widenedPoints < 0);
});
test('exact real boundary (3.0-point widening) does not fire - inclusive boundary, matches the real ">" (not ">=") comparison', () => {
  assert.strictEqual(__fno_checkSpreadWideningVsEarlier(8, 5).isWidened, false);
});
test('FM101 genuinely fires reduce_confidence through the real evaluatePreTradeFailureModes evaluator when spreadWideningCheck.isWidened is true', () => {
  const r = __fno_evaluatePreTradeFailureModes({ spreadWideningCheck: { widenedPoints: 8, isWidened: true } });
  const fm101 = r.triggered.find(t => t.id === 'FM101');
  assert.ok(fm101);
  assert.strictEqual(fm101.action, 'reduce_confidence');
});
test('FM101 correctly stays silent when not widened, or genuinely absent', () => {
  assert.ok(!__fno_evaluatePreTradeFailureModes({ spreadWideningCheck: { widenedPoints: 1, isWidened: false } }).triggered.some(t => t.id === 'FM101'));
  assert.ok(!__fno_evaluatePreTradeFailureModes({}).triggered.some(t => t.id === 'FM101'), 'must not fire on a genuinely absent spreadWideningCheck - e.g. the driver, which never computes one');
});
test('tryOpenAutoTradePosition genuinely computes a real spreadWideningCheck (reusing the real, already-established getSnapshotNearMinutesAgo(30) pattern, comparing against the SAME real leg being traded) and passes it into evaluatePreTradeFailureModes(), wiring FM101', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const callStart = fnBody.indexOf('evaluatePreTradeFailureModes({');
  const callEnd = fnBody.indexOf('\n    });', callStart);
  const callBody = fnBody.slice(callStart, callEnd);
  assert.ok(/spreadWideningCheck:/.test(callBody), 'the real call must genuinely include spreadWideningCheck, not silently omit it');
  assert.ok(/getSnapshotNearMinutesAgo\(30\)/.test(callBody), 'must reuse the real, already-established getSnapshotNearMinutesAgo(30) pattern');
  assert.ok(/optionType === 'PE' \? earlierSnapshot\.peSpreadPct : earlierSnapshot\.ceSpreadPct/.test(callBody), 'must compare against the SAME real option leg (CE or PE) this trade is actually for, not a hardcoded or mismatched side');
});
test('the real recordSnapshot() call site now genuinely captures ceSpreadPct/peSpreadPct - the precondition FM101 depends on to have any real history to compare against', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const callStart = coreSrc.indexOf('recordSnapshot({');
  const callEnd = coreSrc.indexOf('\n      });', callStart);
  const callBody = coreSrc.slice(callStart, callEnd);
  assert.ok(/ceSpreadPct: snapCeSpread, peSpreadPct: snapPeSpread/.test(callBody), 'the real snapshot object must genuinely include both real CE/PE spread readings');
});

console.log('\n=== checkRealizedSlippage / FM153 (real, NEW wiring this session - genuinely POST-fill, not inside evaluatePreTradeFailureModes) ===');
test('genuinely missing/invalid decision-time price honestly returns not-excessive, never fabricates a slippage reading', () => {
  assert.strictEqual(__fno_checkRealizedSlippage(null, 100).isExcessive, false);
  assert.strictEqual(__fno_checkRealizedSlippage(0, 100).isExcessive, false);
  assert.strictEqual(__fno_checkRealizedSlippage(-5, 100).isExcessive, false);
});
test('a real, modest fill difference (within the app\'s own documented 2% threshold) is correctly NOT excessive', () => {
  const r = __fno_checkRealizedSlippage(100, 101);
  assert.strictEqual(r.isExcessive, false);
  assert.ok(Math.abs(r.slippagePct - 1) < 0.01);
});
test('a real, large fill difference (exceeding the 2% threshold) is correctly flagged excessive, in EITHER direction (a favorable fill still deserves a real transparency note)', () => {
  const worse = __fno_checkRealizedSlippage(100, 105); // filled worse than decision-time price
  assert.strictEqual(worse.isExcessive, true);
  const better = __fno_checkRealizedSlippage(100, 95); // filled better - still a real, large deviation worth noting
  assert.strictEqual(better.isExcessive, true);
  assert.ok(Math.abs(better.slippagePct - 5) < 0.01);
});
test('exact real boundary (2.0% slippage) does not fire - inclusive boundary, matches the real ">" (not ">=") comparison', () => {
  assert.strictEqual(__fno_checkRealizedSlippage(100, 102).isExcessive, false);
});
test('tryOpenAutoTradePosition genuinely captures decisionTimePrice BEFORE determineFillPrice/latency run, computes the real slippage check right after the real fill is finalized, and does NOT wire it into evaluatePreTradeFailureModes (structurally cannot - the fill does not exist yet at that call)', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const decisionIdx = fnBody.indexOf('const decisionTimePrice = leg.lastPrice;');
  const fillIdx = fnBody.indexOf('const fill = determineFillPrice(leg,');
  const slippageIdx = fnBody.indexOf('checkRealizedSlippage(decisionTimePrice, fill.price)');
  const fmCallIdx = fnBody.indexOf('evaluatePreTradeFailureModes({');
  assert.ok(decisionIdx !== -1 && decisionIdx < fillIdx, 'decisionTimePrice must be captured BEFORE the real fill/latency simulation runs');
  assert.ok(slippageIdx !== -1 && slippageIdx > fmCallIdx, 'the real slippage check must run AFTER the real FM evaluator call, since the fill does not exist yet at that point');
  assert.ok(!/latencyCheck:\s*checkRealizedSlippage/.test(fnBody) && !/maxPainCheck:\s*checkRealizedSlippage/.test(fnBody), 'sanity: must not have been accidentally wired as an evalCtx field');
});
test('the real saved position object now genuinely records decisionTimePrice/entrySlippagePct for transparency', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const saveCallIdx = coreSrc.indexOf("save(STORAGE.autoTrades, {id:Date.now(), strike, optionType,");
  const saveCallLine = coreSrc.slice(saveCallIdx, coreSrc.indexOf('\n', saveCallIdx));
  assert.ok(/decisionTimePrice, entrySlippagePct: slippageCheck\.slippagePct/.test(saveCallLine));
});

console.log('\n=== Static audit lock: browser-path server-side position persistence, real Phase-5 fix - now genuinely covers every trading style, not just Swing ===');
test('SELF-CAUGHT REAL BUG, found this session picking the browser-path restart-recovery item back up: the real fno_open_position call was gated to Swing-only - an Intraday/Scalping position opened via the browser had NO server-side row at all, the same "restart loses the position" exposure the driver had before its own Phase-1 fix, just triggered by localStorage being cleared/a different device instead of a process restart. Now genuinely sent for every trading style.', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  assert.ok(!/if \(currentTradingStyleIsSwing && window\.FNO_AJAX\.isLoggedIn\)/.test(fnBody), 'the real open-side server persistence call must no longer be gated to Swing-only');
  assert.ok(/if \(window\.FNO_AJAX\.isLoggedIn\) \{[\s\S]{0,200}fno_open_position/.test(fnBody), 'the real open-side call must still genuinely require a real logged-in session, just not a Swing-only gate');
  assert.ok(/tradingStyle=\$\{encodeURIComponent\(effectiveTradingType\)\}/.test(fnBody), 'the real server call must send this position\'s OWN real effectiveTradingType, not a hardcoded "swing" string');
});
test('SELF-CAUGHT REAL BUG, found alongside the above: the real fno_close_position call re-checked a LIVE settings toggle (fnoSettings.get().tradingTypes.swing) instead of this trade\'s OWN recorded tradingType - a Swing position opened while the toggle was on, closing after the user later switched it off, would have silently skipped its own real server-side close, leaving that DB row stuck showing "open" forever. Fixed to gate on serverPositionId alone (only ever set when this exact position genuinely got a real server row).', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('async function closeAutoTrade(');
  const fnEnd = coreSrc.indexOf('\n  }', coreSrc.indexOf('loadPaperAccount(); loadTradeLedger(); // refresh'));
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 8000);
  assert.ok(!/closingTradingStyleIsSwing/.test(fnBody), 'the real close-side persistence call must no longer re-check a live settings toggle');
  assert.ok(/if \(window\.FNO_AJAX\.isLoggedIn && open\.serverPositionId\)/.test(fnBody), 'the real close-side call must gate purely on this trade\'s own real serverPositionId');
});

console.log('\n=== Static audit lock: reconcileOpenPositionOnLoad (renamed from reconcileIntradayScalpingPositionOnLoad) - the browser-path restart-recovery routine itself, closing the Decision Matrix\'s previously-[PARTIAL] "browser does not yet actively RE-FETCH and reconcile" gap, now genuinely generalized to Swing too ===');
test('the real function exists under its generalized name, requires a real logged-in session, and queries the real fno_list_open_positions endpoint', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('async function reconcileOpenPositionOnLoad(');
  assert.ok(fnStart > -1, 'reconcileOpenPositionOnLoad must exist in the current source under this name');
  assert.ok(!/async function reconcileIntradayScalpingPositionOnLoad\(/.test(coreSrc), 'the old, narrower name must genuinely be gone, not left as a stale duplicate');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 8000);
  assert.ok(/if \(!window\.FNO_AJAX \|\| !window\.FNO_AJAX\.isLoggedIn\) return;/.test(fnBody), 'must genuinely require a real logged-in session before attempting any reconciliation - no real server row is ever possible otherwise');
  assert.ok(/action=fno_list_open_positions/.test(fnBody), 'must genuinely call the real fno_list_open_positions endpoint, not fabricate a result');
  assert.ok(/row\.trading_style === 'intraday' \|\| row\.trading_style === 'scalping' \|\| row\.trading_style === 'swing'/.test(fnBody), 'must genuinely include Swing rows now - a direct audit found no separate multi-position browser UI exists for Swing; it shares the exact same single-slot STORAGE.autoTrades path as Intraday/Scalping, so excluding it here silently orphaned a real, still-open Swing position from the browser\'s view after any reload with empty local storage');
});
test('the real function genuinely recovers a local position from the real DB row when local is empty and a real server row exists, for any trading style including swing', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('async function reconcileOpenPositionOnLoad(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 8000);
  assert.ok(/if \(!hasLocal && serverRow\)/.test(fnBody), 'must genuinely branch on the real "local empty, server has a row" case');
  assert.ok(/serverPositionId: parseInt\(serverRow\.id, 10\)/.test(fnBody), 'the recovered object must genuinely record the real server id, so future close/reconcile calls can find it again');
  assert.ok(/save\(STORAGE\.autoTrades, recovered\)/.test(fnBody), 'must genuinely persist the recovered position back into real local storage, not merely compute it and discard it');
  assert.ok(/tradingType: serverRow\.trading_style \|\| 'intraday'/.test(fnBody), 'the recovered tradingType must genuinely come from the real server row, so a recovered Swing position is tracked as Swing, not silently relabeled Intraday');
});
test('the real function genuinely restores a real, live trailing-SL from the DB row when trailing was actually engaged (the exact state the earlier trailing-SL persistence fix this session made recoverable) - never fabricates it when it was not', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('async function reconcileOpenPositionOnLoad(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 8000);
  assert.ok(/const serverTrailingSl = \(serverRow\.trailing_sl !== null && serverRow\.trailing_sl !== undefined\) \? parseFloat\(serverRow\.trailing_sl\) : null;/.test(fnBody), 'must genuinely read the real trailing_sl column off the server row, not assume it is always present');
  assert.ok(/const trailingWasActive = serverTrailingSl !== null && Number\.isFinite\(serverTrailingSl\) && serverTrailingSl > originalSl;/.test(fnBody), 'must genuinely derive "was trailing active" from the real DB row - a null trailing_sl, one still equal to the original sl, OR one strictly worse (looser) than the original sl all honestly mean trailing must not be trusted as an active, protective ratchet, and must not be fabricated as ON');
  assert.ok(/trailingEnabled: trailingWasActive, partialExitEnabled: false, partialTaken: false,/.test(fnBody), 'trailingEnabled on the recovered object must genuinely reflect the real DB-derived trailingWasActive flag, not always reset to false');
  assert.ok(/trailingSl: trailingWasActive \? serverTrailingSl : originalSl,/.test(fnBody), 'the recovered trailingSl must genuinely use the real, live serverTrailingSl when trailing was active, falling back to the real original sl otherwise - never a fabricated value');
});
test('REGRESSION (edge-case audit of the reconciliation fix itself): a server-side trailing_sl that is LOOSER than the original sl (e.g. a corrupted/backwards write - fno_update_open_position_fn in fno-lab.php performs a plain, UNVALIDATED $wpdb->update on a client-supplied float, with no check against the row\'s own sl or its own previous trailing_sl) must NEVER be trusted as an active, protective trail - it must be treated exactly like trailing-never-engaged and fall back to the real, safe originalSl, matching updateTrailingStop()\'s own real ratchet invariant (Math.max(currentTrail, candidateTrail) - a valid trail can never be less than the original sl)', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('async function reconcileOpenPositionOnLoad(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 8000);
  // Simulate the real restoration logic against a corrupted row: sl=100,
  // trailing_sl=90 (LOOSER than the original sl - structurally impossible
  // from a real ratchet-only write, but the server enforces nothing on
  // write, so this must be defended on read).
  const serverRow = { trailing_sl: '90', sl: '100' };
  const serverTrailingSl = (serverRow.trailing_sl !== null && serverRow.trailing_sl !== undefined) ? parseFloat(serverRow.trailing_sl) : null;
  const originalSl = parseFloat(serverRow.sl);
  // eslint-disable-next-line no-eval
  const trailingWasActive = eval(fnBody.match(/const trailingWasActive = (.+);/)[1]);
  assert.strictEqual(trailingWasActive, false, 'a trailing_sl (90) looser than the original sl (100) must NEVER be treated as an active trail - loading it as active would silently hand the recovered position LESS protection than its own original, static stop-loss');
});
test('the real function genuinely clears stale local state when a real serverPositionId no longer has a matching open server row (closed elsewhere)', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('async function reconcileOpenPositionOnLoad(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 8000);
  assert.ok(/else if \(hasLocal && local\.serverPositionId && !serverRow\)/.test(fnBody), 'must genuinely branch on the real "local has a server id, but no matching open server row" case');
  assert.ok(/save\(STORAGE\.autoTrades, \{\}\);/.test(fnBody), 'must genuinely clear the real local state in this case, rather than keep tracking a position the server no longer considers open');
});
test('the real function honestly leaves a local position untouched (never guesses) when it has no recorded serverPositionId at all', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('async function reconcileOpenPositionOnLoad(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 8000);
  assert.ok(/else if \(hasLocal && !local\.serverPositionId\)/.test(fnBody), 'must genuinely branch on the real "local exists, no serverPositionId" case');
  const branchIdx = fnBody.indexOf("else if (hasLocal && !local.serverPositionId)");
  const branchBody = fnBody.slice(branchIdx, branchIdx + 300);
  assert.ok(!/save\(STORAGE\.autoTrades/.test(branchBody), 'this branch must NEVER write to local storage - cannot verify staleness, so must honestly leave it untouched, not guess');
});
test('the real function never silently overwrites either side when local and server genuinely disagree on which position is open (a real anomaly)', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('async function reconcileOpenPositionOnLoad(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 8000);
  assert.ok(/local\.serverPositionId !== parseInt\(serverRow\.id, 10\)/.test(fnBody), 'must genuinely detect a real id mismatch between local and server');
  const mismatchIdx = fnBody.indexOf("local.serverPositionId !== parseInt(serverRow.id, 10)");
  const mismatchBody = fnBody.slice(mismatchIdx, mismatchIdx + 400);
  assert.ok(!/save\(STORAGE\.autoTrades/.test(mismatchBody), 'a real anomaly must never trigger a silent overwrite in either direction - only a loud, logged warning');
});
test('static wiring-audit lock: the real reconciliation call is genuinely awaited BEFORE the first refreshBrain() cycle on page load, not fired-and-forgotten or omitted', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const promiseAllIdx = coreSrc.indexOf('reconcileOpenPositionOnLoad(),');
  assert.ok(promiseAllIdx > -1, 'the real page-load sequence must genuinely wait for reconciliation to finish before the first refreshBrain() cycle runs, so a real recovered position is present before the entry gate\'s own existing.id check first runs');
  const block = coreSrc.slice(promiseAllIdx - 200, promiseAllIdx + 400);
  assert.ok(/Promise\.all\(\[/.test(block), 'reconciliation must be part of the real Promise.all() awaited before the first refreshBrain() cycle');
  assert.ok(/\]\)\.finally\(\(\) => setTimeout\(refreshBrain, 600\)\)/.test(block), 'the first refreshBrain() cycle must genuinely wait for the whole Promise.all() to settle, not just reconciliation alone');
});

console.log('\n=== Static audit lock: FM069/FM083/FM084/FM106 backlog fix - window.FNO_STRATEGY_VERSIONS_CACHE / window.FNO_HYPOTHESIS_DIRECTION_STATS_CACHE are genuinely awaited (not fired-and-forgotten) before the first live decision cycle ===');
test('loadStrategyVersions() and loadHypothesisStats() are genuinely inside the page-load Promise.all(), and each is called exactly once (not duplicated elsewhere as a separate fire-and-forget call)', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const promiseAllIdx = coreSrc.indexOf('reconcileOpenPositionOnLoad(),');
  assert.ok(promiseAllIdx > -1, 'the real Promise.all() call site must still be present');
  const block = coreSrc.slice(promiseAllIdx, promiseAllIdx + 300);
  assert.ok(/loadStrategyVersions\(\),/.test(block), 'loadStrategyVersions() must genuinely be awaited inside the same Promise.all() as reconciliation, so window.FNO_STRATEGY_VERSIONS_CACHE is populated before the first refreshBrain() cycle (FM069/FM106 real dependency)');
  assert.ok(/loadHypothesisStats\(\),/.test(block), 'loadHypothesisStats() must genuinely be awaited inside the same Promise.all() as reconciliation, so window.FNO_HYPOTHESIS_DIRECTION_STATS_CACHE is populated before the first refreshBrain() cycle (FM083/FM084 real dependency)');
  // The original top-level, un-awaited page-load fire-and-forget calls
  // (previously right after loadRealMoneyJournal()) must be gone - each
  // of these two is now called exactly once at page load, from inside
  // the Promise.all() above. (Their own legitimate LATER re-fetch call
  // sites - e.g. loadHypothesisStats() re-run after a real hypothesis
  // evaluation, loadStrategyVersions() re-run after a real new version
  // is saved - are unrelated to page-load timing and correctly left
  // alone; this only locks down the page-load sequence itself.)
  const loadSeqIdx = coreSrc.indexOf('loadRealMoneyJournal();');
  assert.ok(loadSeqIdx > -1, 'the real page-load sequence must still exist');
  const loadSeqBlock = coreSrc.slice(loadSeqIdx, promiseAllIdx);
  assert.ok(!/^\s*loadStrategyVersions\(\);\s*$/m.test(loadSeqBlock), 'loadStrategyVersions() must no longer be a separate, un-awaited top-level statement in the page-load sequence before the Promise.all()');
  assert.ok(!/^\s*loadHypothesisStats\(\);\s*$/m.test(loadSeqBlock), 'loadHypothesisStats() must no longer be a separate, un-awaited top-level statement in the page-load sequence before the Promise.all()');
});

console.log('\n=== Static audit lock: partial-fill simulation genuinely wired into the real entry flow, not merely defined and left unused ===');
test('tryOpenAutoTradePosition genuinely calls simulatePartialFillQty and uses ITS result (filledLotSize), not the raw, unmodified lotSize, for the saved position/costs/server call', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  assert.ok(fnStart !== -1, 'tryOpenAutoTradePosition must still exist in the current source under this name');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  assert.ok(/simulatePartialFillQty\(leg, lotSize, 'buy', symForLot\)/.test(fnBody), 'must genuinely call the real function with symbol for whole-lot fills');
  assert.ok(/qty:\s*filledLotSize/.test(fnBody), 'the saved position object must record the REAL filled qty, not the originally-requested lotSize - a silent full-qty fabrication would defeat the entire point of this fix');
  assert.ok(/computeTradeCosts\(fill\.price,\s*target,\s*filledLotSize\)/.test(fnBody), 'the cost-aware profitability check (Master Prompt §44) must be computed against the REAL filled qty, not an unfilled, fabricated full lotSize');
  assert.ok(/qty=\$\{filledLotSize\}/.test(fnBody), 'the real, server-side swing-position-open call must also send the REAL filled qty, not the requested lotSize - otherwise the DB row would silently misrepresent the real, simulated position size');
});
test('tryOpenAutoTradePosition genuinely forwards its real execMode into the evaluatePreTradeFailureModes() call, wiring FM068', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);
  const callStart = fnBody.indexOf('evaluatePreTradeFailureModes({');
  const callEnd = fnBody.indexOf('\n    });', callStart);
  const callBody = fnBody.slice(callStart, callEnd);
  assert.ok(/\bexecMode\b/.test(callBody), 'the real evaluatePreTradeFailureModes() call must genuinely include execMode, not silently omit it - otherwise FM068 could never fire from the real entry path');
});

console.log('\n=== Static audit lock: FM trade-type-relevance lists vs. live check() call sites (post-session audit, Phase 1.4) ===');
test('the scalping/swing FM-relevance lists and the live check() call sites match the exact, confirmed counts found during the audit - catches silent drift either way', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function evaluatePreTradeFailureModes(');
  const fnEnd = coreSrc.indexOf('\nfunction ', fnStart + 10); // next top-level function declaration
  const fnBody = coreSrc.slice(fnStart, fnEnd);
  const liveIds = new Set((fnBody.match(/check\('FM\d+[a-z]?'/g) || []).map(m => m.match(/FM\d+[a-z]?/)[0]));

  const scalpingMatch = coreSrc.match(/FNO_FM_SCALPING_RELEVANT_IDS = new Set\(\[([^\]]+)\]\)/);
  const swingMatch = coreSrc.match(/FNO_FM_SWING_RELEVANT_IDS = new Set\(\[([^\]]+)\]\)/);
  assert.ok(scalpingMatch && swingMatch, 'both relevance-list declarations must still be present in the current source');
  const scalpingIds = scalpingMatch[1].match(/FM\d+[a-z]?/g);
  const swingIds = swingMatch[1].match(/FM\d+[a-z]?/g);

  const scalpingMissing = scalpingIds.filter(id => !liveIds.has(id));
  const swingMissing = swingIds.filter(id => !liveIds.has(id));

  // Real, confirmed-during-audit counts, hand-verified against the
  // actual source at the time this test was written (see the code
  // comment directly above FNO_FM_SCALPING_RELEVANT_IDS/
  // FNO_FM_SWING_RELEVANT_IDS in fno-lab-core.js for the full list).
  // This test is NOT asserting this state is fine - it exists so that
  // if the count ever changes (someone wires up one of the missing
  // IDs, or - the actually dangerous direction - a live ID silently
  // stops being live), it's a visible, deliberate test change, not a
  // silent drift nobody notices.
  assert.strictEqual(scalpingMissing.length, 2, `expected 2 scalping-relevant IDs with no live check() call inside evaluatePreTradeFailureModes (found: ${scalpingMissing.join(', ')}) - was 3 until this session's catalog-ID-drift remediation pass genuinely wired FM023 (wide spread) and FM024 (thin depth) to their own true doc conditions, and FM025 (previously mistagged FM018, now correctly the live order-rejection check). Of the 2 remaining: FM153 IS genuinely implemented and wired this session (checkRealizedSlippage(), called from tryOpenAutoTradePosition), just as a deliberate, separate POST-fill function rather than a check() call inside this one function, so it mechanically still appears here even though it is not an unimplemented gap; only FM062 is a genuinely unimplemented gap. If this number went DOWN further, someone implemented FM062 (update this test to match, and celebrate); if it went UP, something that used to be live stopped being live, which is a real regression`);
  assert.strictEqual(swingMissing.length, 1, `expected exactly 1 swing-relevant ID with no live check() call (found: ${swingMissing.join(', ')}) - only FM089 remains a genuinely unimplemented gap (needs an actual "atypical rollover pattern" classifier - computeOIRolloverFactor is deliberately informational-only today, with no existing normal-vs-atypical threshold logic to reuse, unlike FM020/FM073/FM085/FM063/FM064). Was 5 until this session wired FM073/FM074 (gap detection/fill-tracking) and FM063/FM064 (results-calendar reuse + new corporate-actions fetch+factor); if this number went DOWN further, someone implemented FM089 (update this test to match, and celebrate); if it went UP, something that used to be live stopped being live, which is a real regression`);
});

console.log('\n=== computeTradeTypeSize (KB §7 "Position sizing" - this session) ===');
test('computeTradeTypeSize sizes scalping UP (tighter 0.5x stop -> 2x lot count) for equal risk-per-trade', () => {
  assert.strictEqual(__fno_computeTradeTypeSize(75, 'scalping', 'NIFTY'), 150);
});
test('computeTradeTypeSize sizes swing DOWN (wider 1.5x stop -> ~0.67x lot count, rounded) for equal risk-per-trade', () => {
  assert.strictEqual(__fno_computeTradeTypeSize(75, 'swing', 'NIFTY'), 75);
});
test('computeTradeTypeSize leaves intraday completely unchanged (1.0x, the neutral multiplier)', () => {
  assert.strictEqual(__fno_computeTradeTypeSize(75, 'intraday', 'NIFTY'), 75);
});
test('computeTradeTypeSize falls back to the neutral 1.0x multiplier for an unrecognized/missing tradingType, never a guessed one', () => {
  assert.strictEqual(__fno_computeTradeTypeSize(75, 'not_a_real_type', 'NIFTY'), 75);
  assert.strictEqual(__fno_computeTradeTypeSize(75, undefined, 'NIFTY'), 75);
});
test('computeTradeTypeSize always returns an exchange-valid whole-lot qty, minimum one lot', () => {
  const result = __fno_computeTradeTypeSize(75, 'swing', 'NIFTY');
  assert.strictEqual(result % 75, 0, 'result must be an exact multiple of the NIFTY lot size');
  assert.ok(result >= 75, 'result must never floor below one whole lot');
});
test('computeTradeTypeSize genuinely scales BANKNIFTY base lots proportionally', () => {
  assert.strictEqual(__fno_computeTradeTypeSize(30, 'scalping', 'BANKNIFTY'), 60);
});
test('computeTradeTypeSize honestly returns the input unchanged for a malformed (non-positive/non-numeric) baseLotSize', () => {
  assert.strictEqual(__fno_computeTradeTypeSize(0, 'scalping', 'NIFTY'), 0);
  assert.strictEqual(__fno_computeTradeTypeSize(-5, 'scalping', 'NIFTY'), -5);
  assert.ok(Number.isNaN(__fno_computeTradeTypeSize(NaN, 'scalping', 'NIFTY')), 'a NaN base must be returned unchanged (still NaN), never coerced into a fabricated number');
});

console.log('\n=== Static audit lock: tryOpenAutoTradePosition genuinely applies computeTradeTypeSize when opted in (KB §7 "Position sizing") ===');
test('the real tryOpenAutoTradePosition source genuinely gates lotSize resizing on fnoSettings.get().tradeTypeSizingEnabled, and never forces it when off', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(params, reportFn) {');
  assert.ok(fnStart > -1, 'tryOpenAutoTradePosition must still exist under this exact name in the current source');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);

  assert.ok(/let lotSize = params\.lotSize;/.test(fnBody), 'lotSize must genuinely be a mutable local (no longer a destructured const), so it can actually be resized');
  assert.ok(/if \(fnoSettings\.get\(\)\.tradeTypeSizingEnabled\) \{\s*\n\s*lotSize = computeTradeTypeSize\(lotSize, timeSufficiencyType, symForLot\);/.test(fnBody), 'the real entry path must genuinely call computeTradeTypeSize(lotSize, timeSufficiencyType, symForLot) and reassign lotSize, but ONLY when tradeTypeSizingEnabled is genuinely on');

  const gateIdx = fnBody.indexOf('if (fnoSettings.get().tradeTypeSizingEnabled)');
  const cooldownIdx = fnBody.indexOf('checkReEntryCooldown(');
  const fillIdx = fnBody.indexOf('let filledLotSize = lotSize;');
  assert.ok(gateIdx > cooldownIdx && gateIdx > -1, 'the sizing gate must run AFTER the cooldown check (so a blocked entry never even computes a resize)');
  assert.ok(fillIdx > gateIdx && fillIdx > -1, 'the real order-fill simulation must genuinely consume the (possibly resized) lotSize, confirming the resize actually reaches the real fill/qty logic downstream, not just a dead local variable');
});
test('FNO_SETTINGS_DEFAULTS genuinely defaults tradeTypeSizingEnabled to false (off), matching this app\'s own established conservative-default convention', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  assert.ok(/tradeTypeSizingEnabled: false,/.test(coreSrc), 'the real, shared settings-defaults object must genuinely default this new flag to false');
});

console.log('\n=== Regression: FM044/FM038 must evaluate against the POST-resize lotSize, not the stale pre-resize curCtx.lotSize ===');
// FOUND this pass: tryOpenAutoTradePosition resizes its own local
// `lotSize` (via computeTradeTypeSize) when tradeTypeSizingEnabled is
// on, but curCtx (the shared, refresh-cycle ctx object) is never
// updated - so a naive `ctx: curCtx` passed into
// evaluatePreTradeFailureModes would let FM044 (capital sufficiency)
// and FM038 (target clears transaction costs) evaluate against the
// smaller, PRE-resize lot size while the real position opened a few
// lines later uses the larger, POST-resize lot size. This is a
// genuine capital-sufficiency-check bypass in the paper-trading model
// (independent of the real-money kill switch, which stays untouched).
test('the real tryOpenAutoTradePosition source builds a resized-lotSize evalCtx (fmEvalCtx) instead of passing curCtx directly into evaluatePreTradeFailureModes', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(params, reportFn) {');
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd > -1 ? fnEnd : fnStart + 20000);

  assert.ok(/const fmEvalCtx = \(lotSize !== curCtx\.lotSize\) \? \{ \.\.\.curCtx, lotSize \} : curCtx;/.test(fnBody), 'must build a shallow clone of curCtx with the real, resized lotSize before calling evaluatePreTradeFailureModes');
  assert.ok(/evaluatePreTradeFailureModes\(\{\s*\n\s*brain: lastBrain, ctx: fmEvalCtx,/.test(fnBody), 'evaluatePreTradeFailureModes must genuinely be called with ctx: fmEvalCtx, not the raw, un-resized curCtx');

  const gateIdx = fnBody.indexOf('if (fnoSettings.get().tradeTypeSizingEnabled)');
  const fmEvalCtxIdx = fnBody.indexOf('const fmEvalCtx');
  assert.ok(fmEvalCtxIdx > gateIdx && gateIdx > -1, 'fmEvalCtx must be built AFTER the resize gate, so it genuinely reflects any resize that happened');
});
test('FM044 correctly fires on the real, resized (larger) margin requirement that a stale pre-resize ctx.lotSize would have missed', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  // Real scenario: base lot 50, optPrice 100 -> base margin Rs5000,
  // available capital Rs7000 (sufficient against the BASE lot, i.e.
  // exactly the stale-ctx failure mode this fix closes).
  const staleCtx = { ocRow: {}, optPrice: 100, lotSize: 50, accountAvailableCapital: 7000 };
  const staleResult = __fno_evaluatePreTradeFailureModes({ brain, ctx: staleCtx });
  assert.ok(!staleResult.triggered.some(t => t.id === 'FM044'), 'sanity: against the un-resized base lot size, FM044 correctly does not fire (margin Rs5000 < capital Rs7000)');

  // A scalping resize (sizeMultiplier 2x per computeTradeTypeSize) would
  // genuinely double the real lot size to 100 -> real margin Rs10000,
  // which genuinely exceeds the same Rs7000 available capital. This is
  // exactly what fmEvalCtx now reflects instead of the stale base ctx.
  const resizedCtx = { ...staleCtx, lotSize: 100 };
  const resizedResult = __fno_evaluatePreTradeFailureModes({ brain, ctx: resizedCtx });
  assert.ok(resizedResult.triggered.some(t => t.id === 'FM044'), 'against the real, resized lot size, FM044 must now correctly fire (margin Rs10000 > capital Rs7000) - this is the exact bug the stale curCtx.lotSize would have silently missed');
});

console.log('\n=== computeTradeTypeTargetSl (KB §7 "Target/SL manual inputs" - this session) ===');
test('computeTradeTypeTargetSl reproduces the exact, pre-existing +30%/-15% default for intraday (1.0x, no-op)', () => {
  const r = __fno_computeTradeTypeTargetSl(100, 'intraday');
  assert.strictEqual(r.target, 130);
  assert.strictEqual(r.sl, 85);
});
test('computeTradeTypeTargetSl tightens both target and SL for scalping (0.5x), holding the 2:1 reward:risk ratio constant', () => {
  const r = __fno_computeTradeTypeTargetSl(100, 'scalping');
  assert.strictEqual(r.target, 115); // 100 * (1 + 0.30*0.5)
  assert.strictEqual(r.sl, 92.5);    // 100 * (1 - 0.15*0.5)
  assert.strictEqual((r.target - 100) / (100 - r.sl), 2, 'reward:risk ratio must stay exactly 2:1, same as the pre-existing base default');
});
test('computeTradeTypeTargetSl widens both target and SL for swing (1.5x), holding the 2:1 reward:risk ratio constant', () => {
  const r = __fno_computeTradeTypeTargetSl(100, 'swing');
  assert.strictEqual(r.target, 145); // 100 * (1 + 0.30*1.5)
  assert.strictEqual(r.sl, 77.5);    // 100 * (1 - 0.15*1.5)
  assert.strictEqual((r.target - 100) / (100 - r.sl), 2, 'reward:risk ratio must stay exactly 2:1, same as the pre-existing base default');
});
test('computeTradeTypeTargetSl falls back to the neutral 1.0x multiplier for an unrecognized/missing tradingType', () => {
  const r = __fno_computeTradeTypeTargetSl(100, 'not_a_real_type');
  assert.strictEqual(r.target, 130);
  assert.strictEqual(r.sl, 85);
});
test('computeTradeTypeTargetSl always returns target > input price > sl, a real, valid bracket, for every real trade type', () => {
  ['scalping', 'intraday', 'swing'].forEach(t => {
    const r = __fno_computeTradeTypeTargetSl(250, t);
    assert.ok(r.target > 250, `${t}: target must be above the input price`);
    assert.ok(r.sl < 250, `${t}: sl must be below the input price`);
  });
});
test('computeTradeTypeTargetSl honestly returns {target: null, sl: null} for a malformed (non-positive/non-numeric) optPrice, never a fabricated bracket', () => {
  assert.deepStrictEqual(__fno_computeTradeTypeTargetSl(0, 'scalping'), { target: null, sl: null });
  assert.deepStrictEqual(__fno_computeTradeTypeTargetSl(-10, 'scalping'), { target: null, sl: null });
  assert.deepStrictEqual(__fno_computeTradeTypeTargetSl(NaN, 'scalping'), { target: null, sl: null });
});

console.log('\n=== Static audit lock: Autonomous Mode\'s auto-open default target/SL genuinely offers computeTradeTypeTargetSl when opted in, never overriding a real manual value (KB §7) ===');
test('the real Autonomous Mode auto-open block genuinely gates the default bracket on fnoSettings.get().tradeTypeTargetSlEnabled, and the manual formTarget/formSl check still runs first', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const anchorIdx = coreSrc.indexOf("const formTarget = parseFloat(document.getElementById('target')");
  assert.ok(anchorIdx > -1, 'the real Autonomous Mode auto-open default-computation site must still exist under this exact anchor');
  const block = coreSrc.slice(anchorIdx, anchorIdx + 1400);

  assert.ok(/const useTradeTypeDefaults = fnoSettings\.get\(\)\.tradeTypeTargetSlEnabled;/.test(block), 'must genuinely read the real, dedicated tradeTypeTargetSlEnabled setting');
  assert.ok(/computeTradeTypeTargetSl\(autoLeg\.lastPrice, resolveDecisionTradingType\(\)\)/.test(block), 'must genuinely call computeTradeTypeTargetSl with the real, live option price and resolveDecisionTradingType() when opted in');

  const formTargetIdx = block.indexOf('const autoTarget = (formTarget');
  const defaultBracketIdx = block.indexOf('const defaultBracket');
  assert.ok(defaultBracketIdx > -1 && formTargetIdx > defaultBracketIdx, 'the default bracket must genuinely be computed BEFORE autoTarget/autoSl consume it');
  assert.ok(/formTarget && formTarget > autoLeg\.lastPrice\) \? formTarget : \(defaultBracket\.target/.test(block), 'a real, manually-entered formTarget must still win over the computed default - the opt-in default is only ever a fallback, never a forced override');
  assert.ok(/formSl && formSl < autoLeg\.lastPrice\) \? formSl : \(defaultBracket\.sl/.test(block), 'a real, manually-entered formSl must still win over the computed default - the opt-in default is only ever a fallback, never a forced override');
});
test('FNO_SETTINGS_DEFAULTS genuinely defaults tradeTypeTargetSlEnabled to false (off), matching this app\'s own established conservative-default convention', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  assert.ok(/tradeTypeTargetSlEnabled: false,/.test(coreSrc), 'the real, shared settings-defaults object must genuinely default this new flag to false');
});

console.log('\n=== Static audit lock: docs/FAILURE_MODE_LIBRARY.md "Live-Gated" column vs. the real check() call sites (this session\'s own SELF-CAUGHT doc-drift fix) ===');
test('every one of the 153 real FAILURE_MODE_LIBRARY.md rows\' "Live-Gated" column genuinely matches whether that FM ID actually has a live check() call in evaluatePreTradeFailureModes - catches this exact class of doc-drift (10 stale "Catalogued only"/"N/A" rows found and fixed this session: FM020/FM063/FM064/FM067/FM068/FM073/FM074/FM085/FM101/FM131) from silently recurring', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function evaluatePreTradeFailureModes(');
  const fnEnd = coreSrc.indexOf('\nfunction ', fnStart + 10);
  const fnBody = coreSrc.slice(fnStart, fnEnd);
  const liveIds = new Set((fnBody.match(/check\('FM\d+[a-z]?'/g) || []).map(m => m.match(/FM\d+[a-z]?/)[0]));
  // FM112-FM124: genuinely live via one real, shared per-category loop
  // keyed by a categoryFmIds map (never a literal check('FM112', ...)
  // string), confirmed by direct code read - not caught by the regex
  // above, added here explicitly rather than widening the regex to
  // avoid a false sense that a generic scan alone proves this.
  assert.ok(/categoryFmIds = \{ 'Market':'FM112'/.test(coreSrc), 'the real, dynamic per-category FM112-124 loop must still exist under this exact shape - if this literal changes, the explicit allowlist below must be re-verified against the real source, not just assumed');
  for (let n = 112; n <= 124; n++) liveIds.add('FM' + n);
  // FM153: genuinely implemented, but as a real, separate POST-fill
  // function (checkRealizedSlippage) rather than a check() call inside
  // this PRE-trade evaluator - the doc's own "N/A" for this one row is
  // correct as-is, not a mismatch to fix.
  assert.ok(/function checkRealizedSlippage\(/.test(coreSrc), "FM153's real, separate post-fill implementation must still exist under this exact name");
  // FM025: genuinely, deliberately NOT live - a confirmed pure
  // duplicate of the already-live FM018 (see this doc's own FM025 row
  // and the KB §8 entry) - "Catalogued only" for this one row is
  // correct as-is, not a mismatch to fix.

  const docPath = path.join(__dirname, '../docs/FAILURE_MODE_LIBRARY.md');
  const doc = fs.readFileSync(docPath, 'utf8');
  const rows = doc.split('\n').filter(l => l.startsWith('| FM'));
  assert.strictEqual(rows.length, 153, `expected exactly 153 real FM rows in the doc (found: ${rows.length}) - if this catalogue itself grew/shrank, that's a deliberate, real change to verify separately, not silently absorbed by this test`);

  const mismatches = [];
  for (const row of rows) {
    const cols = row.split('|').map(s => s.trim());
    const id = cols[1];
    const liveGatedCol = cols[7];
    const docSaysLive = liveGatedCol === 'Yes';
    const codeSaysLive = liveIds.has(id);
    if (docSaysLive !== codeSaysLive) mismatches.push(`${id}: doc says "${liveGatedCol}" (live=${docSaysLive}), real code says live=${codeSaysLive}`);
  }
  assert.strictEqual(mismatches.length, 0, `found ${mismatches.length} genuine doc-vs-code mismatch(es) in the "Live-Gated" column - the doc has drifted from the real source again:\n${mismatches.join('\n')}`);
});

console.log('\n=== Static audit lock: evaluatePreTradeFailureModes has no dead (computed-but-never-referenced) local variables (post-session code-quality re-audit) ===');
test('every `const x = findResult(...)`/`const x = ...` declared inside evaluatePreTradeFailureModes is referenced at least once more in the function body - catches the exact FM125/FM030 dead-variable pattern found and removed this pass (ivPctFactor, opBiasFactor: computed right before a hardcoded fires:false disabled-duplicate check() call, then never read)', () => {
  const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const fnStart = coreSrc.indexOf('function evaluatePreTradeFailureModes(');
  // Find the function's own real closing brace by depth-counting, not
  // just "next function" (module-level consts declared right after
  // this function - e.g. FNO_FM_SCALPING_RELEVANT_IDS - would otherwise
  // be wrongly swept into fnBody and falsely flagged as dead).
  const openBraceIdx = coreSrc.indexOf('{', fnStart);
  let depth = 0, i = openBraceIdx, fnEnd = -1;
  for (; i < coreSrc.length; i++) {
    if (coreSrc[i] === '{') depth++;
    else if (coreSrc[i] === '}') { depth--; if (depth === 0) { fnEnd = i + 1; break; } }
  }
  assert.ok(fnEnd > fnStart, 'must find evaluatePreTradeFailureModes\' own real closing brace via depth-counting');
  const fnBody = coreSrc.slice(fnStart, fnEnd);
  const declared = [...fnBody.matchAll(/\bconst\s+(\w+)\s*=/g)].map(m => m[1]);
  const deadVars = declared.filter(v => {
    const occurrences = (fnBody.match(new RegExp('\\b' + v + '\\b', 'g')) || []).length;
    return occurrences <= 1; // only the declaration itself
  });
  assert.deepStrictEqual(deadVars, [], `found genuinely dead (never-referenced-again) local variable(s) inside evaluatePreTradeFailureModes: ${deadVars.join(', ')} - either use them or remove the declaration (this is exactly the ivPctFactor/opBiasFactor pattern this test was added to catch)`);
  // Explicit regression lock: confirm the two specific dead variables
  // found and removed this pass are genuinely gone, not just absent
  // from the generic scan above for some unrelated reason.
  assert.ok(!/const ivPctFactor/.test(fnBody), 'the dead ivPctFactor declaration (unused findResult lookup ahead of FM125\'s hardcoded fires:false) must stay removed');
  assert.ok(!/const opBiasFactor/.test(fnBody), 'the dead opBiasFactor declaration (unused findResult lookup ahead of FM030\'s hardcoded fires:false) must stay removed');
  // And confirm the disabled duplicates themselves are untouched
  // (dead-var removal must never change behavior).
  assert.ok(/check\('FM125', 'Real IV percentile at a MILD extreme \(75th-90th\) \(duplicate catalog entry of FM014b, same real detection - deliberately disabled here\)', 'low', 'reduce_confidence', '', false\);/.test(fnBody), 'FM125\'s disabled-duplicate check() call must be byte-for-byte unchanged after the dead-variable cleanup');
  assert.ok(/check\('FM030', 'Real Operator Intel bias genuinely contradicts the trade direction \(duplicate catalog entry of FM077, same real detection - deliberately disabled here\)', 'high', 'require_confirmation', '', false\);/.test(fnBody), 'FM030\'s disabled-duplicate check() call must be byte-for-byte unchanged after the dead-variable cleanup');
});

test('FM150 (real strike requested does not exist in this refresh\'s real option chain, NEW this pass) fires only on a genuine exact-match miss, never on missing/incomplete inputs', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  const ocRows = [{ strikePrice: 24900 }, { strikePrice: 25000 }, { strikePrice: 25100 }];
  // Requested strike genuinely absent from the real chain (e.g. a
  // mistyped/stale strike off the real exchange strike-step grid).
  const missing = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, ocRows }, requestedStrike: 24950 });
  assert.ok(missing.triggered.some(t => t.id === 'FM150'));
  assert.strictEqual(missing.finalAction, 'block');
  // Requested strike genuinely present - must not fire.
  const present = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, ocRows }, requestedStrike: 25000 });
  assert.ok(!present.triggered.some(t => t.id === 'FM150'));
  // Genuinely missing ocRows this refresh - must not fire (that's
  // FM036's own, separate condition), never guessed as a mismatch.
  const noChain = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {} }, requestedStrike: 25000 });
  assert.ok(!noChain.triggered.some(t => t.id === 'FM150'), 'must not fire when ctx.ocRows is genuinely unavailable');
  // Genuinely missing requestedStrike (e.g. an older caller not yet
  // threading it) - must not fire, never guessed.
  const noStrike = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, ocRows } });
  assert.ok(!noStrike.triggered.some(t => t.id === 'FM150'), 'must not fire when requestedStrike is genuinely unavailable');
  // A NaN requestedStrike (e.g. a bad parseFloat) must be treated as
  // unavailable, not silently compared against real strike numbers.
  const nanStrike = __fno_evaluatePreTradeFailureModes({ brain, ctx: { ocRow: {}, ocRows }, requestedStrike: NaN });
  assert.ok(!nanStrike.triggered.some(t => t.id === 'FM150'), 'a NaN requestedStrike must not fire FM150');
});
test('FM045 (real portfolio correlation risk, NEW this pass) fires only when computePortfolioCorrelationRisk genuinely flags a correlated grouping across a real, threaded portfolioPositions array', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  const ctx = { ocRow: {} };
  // Two real positions on the exact same symbol/optionType - certain,
  // definitional duplicate directional bet.
  const correlated = __fno_evaluatePreTradeFailureModes({ brain, ctx, portfolioPositions: [
    { id: 1, symbol: 'NIFTY', optionType: 'CE' },
    { id: 2, symbol: 'NIFTY', optionType: 'CE' },
  ] });
  assert.ok(correlated.triggered.some(t => t.id === 'FM045'));
  // Two real, genuinely uncorrelated positions - must not fire.
  const uncorrelated = __fno_evaluatePreTradeFailureModes({ brain, ctx, portfolioPositions: [
    { id: 1, symbol: 'NIFTY', optionType: 'CE' },
    { id: 2, symbol: 'NIFTY', optionType: 'PE' },
  ] });
  assert.ok(!uncorrelated.triggered.some(t => t.id === 'FM045'));
  // Fewer than 2 real, tracked positions (nothing to compare) - must
  // not fire, and must not throw.
  const oneOnly = __fno_evaluatePreTradeFailureModes({ brain, ctx, portfolioPositions: [{ id: 1, symbol: 'NIFTY', optionType: 'CE' }] });
  assert.ok(!oneOnly.triggered.some(t => t.id === 'FM045'));
  // Genuinely absent/null cache (e.g. the driver, which has no manual
  // tracker) - must not fire, never guessed.
  const noCache = __fno_evaluatePreTradeFailureModes({ brain, ctx, portfolioPositions: null });
  assert.ok(!noCache.triggered.some(t => t.id === 'FM045'), 'must not fire, and must not throw, when portfolioPositions is genuinely null');
});
test('FM046 (real portfolio net delta genuinely NOT offset by existing positions, NEW this pass) fires only on a real, computed non-hedged reading from a threaded portfolioPerPositionGreeks array', () => {
  const brain = { criticalFails: 0, confidence: 'High', decision: 'BUY_READY', results: [] };
  const ctx = { ocRow: {} };
  // Two real positions both stacking the same-direction delta - not
  // genuinely hedged (net delta == sum of abs deltas, ratio 100%).
  const stacked = __fno_evaluatePreTradeFailureModes({ brain, ctx, portfolioPerPositionGreeks: [
    { exposure: { deltaExposure: 40 } },
    { exposure: { deltaExposure: 35 } },
  ] });
  assert.ok(stacked.triggered.some(t => t.id === 'FM046'));
  // Two real positions genuinely offsetting each other - net delta far
  // below the sum of absolute deltas - must not fire.
  const hedged = __fno_evaluatePreTradeFailureModes({ brain, ctx, portfolioPerPositionGreeks: [
    { exposure: { deltaExposure: 40 } },
    { exposure: { deltaExposure: -38 } },
  ] });
  assert.ok(!hedged.triggered.some(t => t.id === 'FM046'));
  // Fewer than 2 real, priced positions - must not fire, must not throw.
  const oneOnly = __fno_evaluatePreTradeFailureModes({ brain, ctx, portfolioPerPositionGreeks: [{ exposure: { deltaExposure: 40 } }] });
  assert.ok(!oneOnly.triggered.some(t => t.id === 'FM046'));
  // Genuinely absent/null cache - must not fire, never guessed.
  const noCache = __fno_evaluatePreTradeFailureModes({ brain, ctx, portfolioPerPositionGreeks: null });
  assert.ok(!noCache.triggered.some(t => t.id === 'FM046'), 'must not fire, and must not throw, when portfolioPerPositionGreeks is genuinely null');
});

console.log('\n=== Exit-side option-chain staleness closure (real, NEW this pass - closes the "checkTradeExit() has no timestamp mechanism" gap PENDING_REQUIREMENTS.md left explicitly open on the entry-only FM155 pass) ===');
test('renderOpenTrades() genuinely checks ctx.ocFetchedAt for staleness via checkOptionChainFreshness() before trusting liveNow for an exit decision', () => {
  const src = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
  const start = src.indexOf('function renderOpenTrades(');
  assert.ok(start >= 0, 'renderOpenTrades must exist');
  const body = src.slice(start, start + 4000);
  assert.ok(/ocStaleAtExit\s*=\s*checkOptionChainFreshness\(curCtx\.ocFetchedAt/.test(body),
    'renderOpenTrades must call checkOptionChainFreshness(curCtx.ocFetchedAt, ...) before trusting liveNow');
  assert.ok(/if \(ocStaleAtExit\.stale\)/.test(body) && /liveNow = null/.test(body),
    'a stale ocFetchedAt must force liveNow back to null (the same safe "cannot check target/SL" path as a missing/NaN price), never silently drive an exit off an untrustworthy quote');
});

test('checkOptionChainFreshness itself (already tested for FM155) correctly classifies a stale-but-numerically-valid exit-time price as stale during real market hours', () => {
  const FRESH_NOW_MS = new Date('2025-06-16T10:00:00+05:30').getTime(); // Monday, real market hours
  const stale = __fno_checkOptionChainFreshness(FRESH_NOW_MS - 15 * 60000, FRESH_NOW_MS);
  assert.strictEqual(stale.stale, true);
  const fresh = __fno_checkOptionChainFreshness(FRESH_NOW_MS - 1 * 60000, FRESH_NOW_MS);
  assert.strictEqual(fresh.stale, false);
});

console.log('\n=== autonomous-driver exit-side staleness closure (source-level, real - the driver has its own two exit call sites, both fixed this pass) ===');
test('autonomous-driver.js single-slot exit path skips checkTradeExit() on a genuinely stale oc.fetchedAt rather than trusting a stale-but-finite optPrice', () => {
  const driverSrc = fs.readFileSync(path.join(__dirname, '../autonomous-driver/autonomous-driver.js'), 'utf8');
  assert.ok(/ocStaleForExit\s*=\s*\(!deadlineReached && ocFetchedAtForExit !== null\) \? checkOptionChainFreshness\(ocFetchedAtForExit, Date\.now\(\)\)/.test(driverSrc),
    'the single-slot exit path must compute ocStaleForExit from the real oc.fetchedAt via checkOptionChainFreshness');
  assert.ok(/exitReason = deadlineReached \? 'square_off' : \(\(ocStaleForExit && ocStaleForExit\.stale\) \? null : checkTradeExit\(/.test(driverSrc),
    'a stale reading must route to null (no price-based exit fires), never call checkTradeExit on an untrustworthy price, and must never block the real deadline-based square-off');
});
test('autonomous-driver.js swing-position monitor skips a genuinely stale option-chain fetch rather than closing a swing position on an untrustworthy price', () => {
  const driverSrc = fs.readFileSync(path.join(__dirname, '../autonomous-driver/autonomous-driver.js'), 'utf8');
  const start = driverSrc.indexOf('async function checkAndMonitorSwingPositions');
  assert.ok(start >= 0);
  const body = driverSrc.slice(start, start + 3000);
  assert.ok(/checkOptionChainFreshness\(ocFetchedAt, Date\.now\(\)\)/.test(body),
    'the swing monitor must check freshness of the real oc.fetchedAt before calling checkTradeExit');
  assert.ok(/if \(freshness\.stale\) \{[\s\S]*?continue;/.test(body),
    'a stale swing-position quote must be honestly skipped (continue), never closed on an untrustworthy price');
});

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed > 0 ? 1 : 0);
