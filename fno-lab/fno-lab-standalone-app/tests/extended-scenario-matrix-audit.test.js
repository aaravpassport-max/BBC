// PHASE 4: EXTENDED fake-trade scenario matrix, broader/more adversarial
// than the 9 scenarios in end-to-end-decision-engine-audit.test.js and
// the 3+-condition tests in interaction-sync-audit.test.js. Loads the
// REAL fno-lab-core.js source (same eval-slice pattern every other test
// in this directory uses) and calls the REAL evaluateBrain()/
// evaluatePreTradeFailureModes()/adjustFailureModesForTradeType() - no
// mocks of the decision-engine functions themselves.
//
// Run with: node tests/extended-scenario-matrix-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
const { fnoNormCdf, fnoNormPdf, bsGreeks, bsGreeksAtDays, buildGreeksSnapshot, FNO_RISK_FREE_RATE, TRADING_HOURS_PER_DAY, solveImpliedVolatility } = ge;

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) { console.error('FATAL: render() boundary marker not found'); process.exit(1); }
eval(coreSource.slice(0, end));

// ---------------------------------------------------------------------
// Realistic base ctx builder - same shape as end-to-end-decision-engine-
// audit.test.js's own buildBaseCtx (mirrors autonomous-driver.js's real
// ctx construction), parameterized by symbol/spot/iv/days so extreme-
// value scenarios can build a fully-consistent (non-self-contradictory)
// ctx rather than hand-editing one field in isolation.
// ---------------------------------------------------------------------
function buildCtx({ symbol = 'NIFTY', spot = 23200, strike = spot, iv = 14, daysExp = 15, lotSize = 50, overrides = {} } = {}) {
  const candles = [];
  let c = spot - 200;
  for (let i = 0; i < 60; i++) { c += (Math.sin(i / 5) * 10 + 2); candles.push({ c: Math.round(c * 100) / 100, t: 1700000000 + i * 300 }); }
  candles[candles.length - 1].c = spot;
  const closes = candles.map(x => x.c);
  const optPrice = Math.round(calculateDecay(spot, strike, daysExp, iv, 100, lotSize, 'CE').snapshot.now.price * 100) / 100;
  const decay = calculateDecay(spot, strike, daysExp, iv, optPrice, lotSize, 'CE');
  const ocRow = { CE: { lastPrice: optPrice, impliedVolatility: iv, openInterest: 500000 }, PE: { lastPrice: 84, impliedVolatility: iv + 1, openInterest: 480000 }, strikePrice: strike };
  const ocRows = [
    { strikePrice: strike - 500, CE: { openInterest: 400000, impliedVolatility: iv + 1 }, PE: { openInterest: 420000, impliedVolatility: iv + 5 } },
    { strikePrice: strike, CE: { openInterest: 500000, impliedVolatility: iv }, PE: { openInterest: 480000, impliedVolatility: iv + 1 } },
    { strikePrice: strike + 500, CE: { openInterest: 380000, impliedVolatility: iv - 1 }, PE: { openInterest: 360000, impliedVolatility: iv } },
  ];
  const base = {
    spot, pcr: 1.0, totalPE: 480000, totalCE: 500000,
    vix: 15, time: '10:30', day: 'Monday', isExpiry: false,
    banList: [], banListSource: 'live',
    fiiLongShort: null,
    todayPnL: 0, tradesToday: 0, consecLoss: 0,
    fullJournal: [],
    accountAvailableCapital: 100000, accountCurrentDrawdownPct: 0, accountCurrentBalance: 100000,
    daily: {}, decay: { ...decay, days: daysExp },
    operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [] },
    status: {}, optPrice, lotSize, ocRow, candles,
    ocRows, expiryDates: ['21-Aug-2026'],
    correlationSym: 'BANKNIFTY', correlationCloses: [],
    futuresPrice: spot + 20,
    targetPrice: null, slPrice: null,
    ema21: ema(closes, 21)[closes.length - 1],
    vwap: vwapCalc(candles)[candles.length - 1],
    newsSentiment: null, newsSentimentTier: 'unavailable',
    marketDepth: null, marketDepthTier: 'unavailable',
    participantOI: null, asmGsmResult: null, microstructure: null,
    symbol,
  };
  return Object.assign(base, overrides);
}

function runFull(ctx, extraEvalCtx) {
  const brain = evaluateBrain(ctx);
  const fmRaw = evaluatePreTradeFailureModes(Object.assign({ brain, ctx }, extraEvalCtx || {}));
  return { brain, fm: fmRaw.triggered, fmRaw };
}

// ===========================================================================
// 1. EXTREME VALUES
// ===========================================================================
console.log('\n=== 1. EXTREME VALUES (unrealistic-but-not-NaN/Infinity inputs) ===');

{
  console.log('--- 1a. Extreme IV = 500% ---');
  const ctx = buildCtx({ iv: 500 });
  const { brain } = runFull(ctx);
  console.log(`  decision=${brain.decision} directionalScore=${brain.directionalScore.toFixed(2)} totalScore=${brain.totalScore.toFixed(2)}`);
  const atmIvRow = brain.results.find(r => r.factor === 'ATM IV');
  const histRow = brain.results.find(r => r.factor === 'Historical vs IV');
  check(Number.isFinite(brain.directionalScore), '1a: directionalScore stays finite (no overflow/NaN) at IV=500%');
  check(Math.abs(brain.directionalScore) < 200, '1a: directionalScore stays within a sane bounded range (no single extreme input dominates disproportionately) - actual: ' + brain.directionalScore.toFixed(2));
  check(atmIvRow.pass === false, '1a: ATM IV factor correctly fails (500% is outside the 10-25% "optimal" band) - engine degrades toward caution, not silently passing');
  check(atmIvRow.score === -0.5, '1a: ATM IV factor score is still bounded at -0.5 (not scaled unboundedly with the extreme input)');
  check(histRow.pass === false, '1a: Historical-vs-IV factor also correctly flags the 500% IV as "rich vs realized" - consistent, non-contradictory degradation');
}

{
  console.log('--- 1b. Spot near zero (0.01) ---');
  const ctx = buildCtx({ spot: 0.01, strike: 0.01 });
  const { brain } = runFull(ctx);
  console.log(`  decision=${brain.decision} directionalScore=${brain.directionalScore.toFixed(2)}`);
  check(Number.isFinite(brain.directionalScore), '1b: directionalScore stays finite with spot near zero');
  check(brain.results.every(r => Number.isFinite(r.score)), '1b: every individual result row score stays finite (no row divides by near-zero spot and blows up)');
}

{
  console.log('--- 1c. Single-strike option chain (ocRows.length === 1) ---');
  const ctx = buildCtx({ overrides: { ocRows: [{ strikePrice: 23200, CE: { openInterest: 500000, impliedVolatility: 14 }, PE: { openInterest: 480000, impliedVolatility: 15 } }] } });
  const { brain } = runFull(ctx);
  console.log(`  decision=${brain.decision} directionalScore=${brain.directionalScore.toFixed(2)}`);
  check(Number.isFinite(brain.directionalScore), '1c: single-strike option chain does not throw or produce NaN');
  const skewRow = brain.results.find(r => r.factor === 'Skew - OTM Put IV vs Call IV');
  check(skewRow && skewRow.pass === null, '1c: cross-strike Skew factor correctly reports unavailable (null) rather than fabricating a skew off 1 strike');
}

{
  console.log('--- 1d. OI = 0 across the whole chain ---');
  const ctx = buildCtx({ overrides: {
    totalPE: 0, totalCE: 0, pcr: 0,
    ocRow: { CE: { lastPrice: 100, impliedVolatility: 14, openInterest: 0 }, PE: { lastPrice: 84, impliedVolatility: 15, openInterest: 0 }, strikePrice: 23200 },
    ocRows: [{ strikePrice: 23200, CE: { openInterest: 0, impliedVolatility: 14 }, PE: { openInterest: 0, impliedVolatility: 15 } }],
  } });
  const { brain } = runFull(ctx);
  console.log(`  decision=${brain.decision} directionalScore=${brain.directionalScore.toFixed(2)}`);
  check(Number.isFinite(brain.directionalScore), '1d: OI=0 across the whole chain does not throw or produce NaN (no unguarded division by total OI)');
}

{
  console.log('--- 1e. Spread 0% (perfectly liquid) vs 90% of premium (near-worthless liquidity) ---');
  const optPrice = buildCtx({}).optPrice;
  const ctxTight = buildCtx({ overrides: { ocRow: { CE: { lastPrice: optPrice, impliedVolatility: 14, openInterest: 500000, bidprice: optPrice, askPrice: optPrice }, PE: { lastPrice: 84, impliedVolatility: 15, openInterest: 480000 }, strikePrice: 23200 } } });
  const ctxWide = buildCtx({ overrides: { ocRow: { CE: { lastPrice: optPrice, impliedVolatility: 14, openInterest: 500000, bidprice: optPrice * 0.1, askPrice: optPrice }, PE: { lastPrice: 84, impliedVolatility: 15, openInterest: 480000 }, strikePrice: 23200 } } });
  const { brain: bTight } = runFull(ctxTight);
  const { brain: bWide } = runFull(ctxWide);
  const rowTight = bTight.results.find(r => r.factor === 'Bid-Ask Spread Cost');
  const rowWide = bWide.results.find(r => r.factor === 'Bid-Ask Spread Cost');
  console.log(`  tight spread row: pass=${rowTight.pass} score=${rowTight.score}; wide (90%) spread row: pass=${rowWide.pass} score=${rowWide.score}`);
  check(rowTight.pass === true && rowTight.score === 0.3, '1e: a genuinely 0%-of-premium spread scores as tight/pass (bounded +0.3)');
  check(rowWide.pass === false && rowWide.score === -1, '1e: a 90%-of-premium spread (near-worthless liquidity) scores as wide/fail (bounded -1, not scaled unboundedly with how wide it is)');
  check(Number.isFinite(rowTight.score) && Number.isFinite(rowWide.score), '1e: both spread extremes stay finite');
}

// ===========================================================================
// 2. TRADE-STYLE SCOPING (scalping/swing/intraday, both directions, all 3
//    symbols) + the ambiguous/undetermined-style question
// ===========================================================================
console.log('\n=== 2. TRADE-STYLE SCOPING ===');

// evaluateBrain's own effective-trading-type resolution
// (getEffectiveTradingType) reads fnoSettings.get().tradingTypes off
// localStorage, not off ctx - so trade-style is driven via a
// localStorage-backed fnoSettings mock, matching the real production
// data path (fnoSettings itself reads real localStorage) rather than
// monkey-patching an internal function.
let __ls = {};
global.localStorage = {
  getItem: (k) => (k in __ls ? __ls[k] : null),
  setItem: (k, v) => { __ls[k] = v; },
};
global.document = global.document || { dispatchEvent: () => {} };
function withTradingType(swing, scalping, fn) {
  __ls = { fno_trading_controls_v1: JSON.stringify({ tradingTypes: { swing, scalping } }) };
  return fn();
}

{
  console.log('--- 2a. getEffectiveTradingType is always exactly one of scalping/intraday/swing - never ambiguous ---');
  check(getEffectiveTradingType(true) === 'swing', '2a: isSwingPath=true -> swing (the dedicated server-side swing path always wins, regardless of any scalping setting)');
  const bothTrue = withTradingType(true, true, () => getEffectiveTradingType(true));
  const neitherTrue = withTradingType(false, false, () => getEffectiveTradingType(false));
  console.log(`  both scalping+swing enabled -> "${bothTrue}" (documented precedence: swing wins); neither enabled -> "${neitherTrue}" (documented default: intraday)`);
  check(bothTrue === 'swing', '2a: when BOTH scalping and swing settings are simultaneously true (a real, reachable settings state), the function resolves deterministically to swing per its own documented precedence - never a fabricated 4th "ambiguous"/"auto" value');
  check(neitherTrue === 'intraday', '2a: when neither scalping nor swing is enabled, resolves deterministically to intraday (this app\'s documented default) - the codebase does NOT support/return an ambiguous/undetermined trade style; it is a total function with exactly 3 possible outputs');
}

['NIFTY', 'BANKNIFTY', 'FINNIFTY'].forEach(symbol => {
  console.log(`--- 2b/2c. FM cross-type presence for symbol=${symbol}: a swing-relevant FM (FM006, isExpiry) evaluated under scalping, and a scalping-relevant FM (FM024) evaluated under swing ---`);
  const ctxExpiry = buildCtx({ symbol, overrides: { isExpiry: true } });
  const { brain, fmRaw } = runFull(ctxExpiry);
  const adjScalping = adjustFailureModesForTradeType(fmRaw, 'scalping');
  const adjSwing = adjustFailureModesForTradeType(fmRaw, 'swing');
  const adjIntraday = adjustFailureModesForTradeType(fmRaw, 'intraday');
  const fm006Scalping = adjScalping.triggered.find(t => t.id === 'FM006');
  const fm006Swing = adjSwing.triggered.find(t => t.id === 'FM006');
  const fm006Intraday = adjIntraday.triggered.find(t => t.id === 'FM006');
  console.log(`  FM006 (swing-relevant) under scalping: present=${!!fm006Scalping} typeRelevance=${fm006Scalping && fm006Scalping.typeRelevance} sev=${fm006Scalping && fm006Scalping.severity}->${fm006Scalping && fm006Scalping.adjustedSeverity}`);
  console.log(`  FM006 (swing-relevant) under swing:    present=${!!fm006Swing} typeRelevance=${fm006Swing && fm006Swing.typeRelevance} sev=${fm006Swing && fm006Swing.severity}->${fm006Swing && fm006Swing.adjustedSeverity}`);
  // Per adjustFailureModesForTradeType's own design (assets/fno-lab-core.js
  // ~5762-5776): trade-type scoping is DELIBERATELY additive-severity-only,
  // never a hard filter - a swing-relevant FM firing during a scalping
  // evaluation is still real, still reported, just not escalated. This is
  // the actual, documented, correct behavior (a real FM006 expiry-day
  // condition still matters somewhat to a scalper, just not as much as to
  // a swing holder) - confirming it directly here since the original task
  // framing assumed a filter that this codebase intentionally does not have.
  check(!!fm006Scalping, `2b [${symbol}]: FM006 (swing-relevant) is NOT filtered out of a scalping evaluation - by design this is severity-adjustment-only, never a hard filter`);
  check(fm006Scalping && fm006Scalping.typeRelevance === null && fm006Scalping.adjustedSeverity === fm006Scalping.severity, `2b [${symbol}]: under scalping, FM006 keeps its original (unescalated) severity since it is not scalping-relevant`);
  check(!!fm006Swing, `2c [${symbol}]: FM006 (swing-relevant) is present under swing too`);
  check(fm006Swing && fm006Swing.typeRelevance === 'swing' && fm006Swing.adjustedSeverity !== fm006Swing.severity, `2c [${symbol}]: under swing, FM006 IS escalated exactly one severity level (medium->high) since it is swing-relevant`);
  check(!!fm006Intraday, `2b/2c [${symbol}]: FM006 also present (unescalated, typeRelevance null) under intraday - trade-type scoping never removes a real triggered condition for ANY trade type`);
});

// ===========================================================================
// 3. SCORE-BOUNDARY CROSSING
// ===========================================================================
console.log('\n=== 3. SCORE-BOUNDARY CROSSING ===');
{
  const BUY_THRESHOLD = 11, SELL_THRESHOLD = -17;
  const baseline = evaluateBrain(buildCtx({ overrides: { operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [] } } }));
  console.log(`  baseline directionalScore (no operator signals) = ${baseline.directionalScore.toFixed(4)}`);
  const neededForBuy = BUY_THRESHOLD - baseline.directionalScore;

  [
    { label: 'one unit below BUY_THRESHOLD', contrib: neededForBuy - 1, expectBuy: false },
    { label: 'exactly at BUY_THRESHOLD', contrib: neededForBuy, expectBuy: true },
    { label: 'one unit above BUY_THRESHOLD', contrib: neededForBuy + 1, expectBuy: true },
  ].forEach(({ label, contrib, expectBuy }) => {
    const ctx = buildCtx({ overrides: { operatorIntel: { bias: 'BULLISH', score: contrib, confidence: 'HIGH', signals: [{ factor: 'Boundary Test Signal', contrib, reason: 'boundary test' }] } } });
    const brain = evaluateBrain(ctx);
    console.log(`  [${label}] directionalScore=${brain.directionalScore.toFixed(4)} decision=${brain.decision}`);
    check(Math.abs(brain.directionalScore - (baseline.directionalScore + contrib)) < 1e-6, `3: [${label}] directionalScore math is exactly as expected (no hidden rounding drift)`);
    check((brain.decision === 'BUY_READY') === expectBuy, `3: [${label}] decision matches the documented ">= ${BUY_THRESHOLD}" rule exactly - the boundary comparison in the actual decision line uses the SAME >= operator as computeDecisionTier's own boundary check (no off-by-one, no >/>= inconsistency between the two)`);
  });

  // Cross-check: the exact-boundary score (directionalScore === BUY_THRESHOLD)
  // must also agree between evaluateBrain's own decision line and the
  // independent computeDecisionTier() helper - both read the real BUY_THRESHOLD
  // constant and must never disagree on which side of >= the same number falls.
  const exactCtx = buildCtx({ overrides: { operatorIntel: { bias: 'BULLISH', score: neededForBuy, confidence: 'HIGH', signals: [{ factor: 'Boundary Test Signal', contrib: neededForBuy, reason: 'boundary test' }] } } });
  const exactBrain = evaluateBrain(exactCtx);
  const tierAtExact = computeDecisionTier(exactBrain.directionalScore, BUY_THRESHOLD, SELL_THRESHOLD);
  console.log(`  exact-boundary cross-check: decision=${exactBrain.decision} decisionTier=${exactBrain.decisionTier} independent computeDecisionTier()=${tierAtExact}`);
  check(exactBrain.decision === 'BUY_READY' && (tierAtExact === 'LONG' || tierAtExact === 'STRONG_LONG'), '3: at the exact BUY_THRESHOLD boundary, both evaluateBrain\'s decision AND the independently-callable computeDecisionTier agree the score crossed the threshold - consistent >=, no drift between the two code paths');
  check(exactBrain.decisionTier === tierAtExact, '3: brain.decisionTier (as returned by evaluateBrain) exactly equals a fresh, independent computeDecisionTier() call on the same directionalScore/thresholds - the field is not stale or separately derived');

  // SELL_THRESHOLD boundary, same treatment. NOTE: evaluateBrain's own
  // directionalScore is the sum of dozens of individually-computed
  // factor scores (many of them themselves derived from floating-point
  // BSM math), so it carries ~1e-14-scale float noise (confirmed by
  // direct execution: an all-clean baseline ctx here reproducibly
  // computes 14.500000000000007, not a clean 14.5). Constructing a
  // hand-picked operatorIntel contrib to land EXACTLY on the -17.0
  // threshold is therefore not reliably achievable in a real ctx - the
  // real, meaningful thing to verify at "the boundary" is that
  // whatever directionalScore actually results, the decision is
  // self-consistent with the SAME real <= comparison evaluateBrain
  // itself uses, at 1-point steps that safely straddle the threshold
  // clear of that float noise.
  const neededForSell = SELL_THRESHOLD - baseline.directionalScore;
  [
    { label: 'clearly above SELL_THRESHOLD (less negative)', contrib: neededForSell + 1 },
    { label: 'clearly at/below SELL_THRESHOLD', contrib: neededForSell },
    { label: 'clearly below SELL_THRESHOLD (more negative)', contrib: neededForSell - 1 },
  ].forEach(({ label, contrib }) => {
    const ctx = buildCtx({ overrides: { operatorIntel: { bias: 'BEARISH', score: contrib, confidence: 'HIGH', signals: [{ factor: 'Boundary Test Signal', contrib, reason: 'boundary test' }] } } });
    const brain = evaluateBrain(ctx);
    const expectSell = brain.directionalScore <= SELL_THRESHOLD;
    console.log(`  [${label}] directionalScore=${brain.directionalScore.toFixed(4)} decision=${brain.decision} expectSell(<=${SELL_THRESHOLD})=${expectSell}`);
    check((brain.decision === 'SELL_READY') === expectSell, `3: [${label}] decision matches the documented "<= ${SELL_THRESHOLD}" rule exactly (self-consistent with the actual computed directionalScore, not a hand-assumed one) at the SELL boundary too`);
  });
  // And the clean 1-below/1-above pair confirms the boundary itself is
  // in the expected place (no off-by-one at the ~integer scale):
  const justAbove = evaluateBrain(buildCtx({ overrides: { operatorIntel: { bias: 'BEARISH', score: neededForSell + 1, confidence: 'HIGH', signals: [{ factor: 'X', contrib: neededForSell + 1, reason: 'x' }] } } }));
  const justBelow = evaluateBrain(buildCtx({ overrides: { operatorIntel: { bias: 'BEARISH', score: neededForSell - 1, confidence: 'HIGH', signals: [{ factor: 'X', contrib: neededForSell - 1, reason: 'x' }] } } }));
  check(justAbove.decision !== 'SELL_READY' && justBelow.decision === 'SELL_READY', '3: a full 1-point score swing straddling SELL_THRESHOLD (clear of float-noise scale) flips the decision exactly once, at the documented threshold, confirming no off-by-one');
}

// ===========================================================================
// 4. RAPID STATE CHANGES - 5-tick sequence, full-engine level
// ===========================================================================
console.log('\n=== 4. RAPID STATE CHANGES (5 consecutive evaluateBrain calls) ===');
{
  // Tick 1: strong buy signal, everything clean
  const ctx1 = buildCtx({ overrides: {
    operatorIntel: { bias: 'BULLISH', score: 6, confidence: 'HIGH', signals: [
      { factor: 'Tick1 Signal A', contrib: 3, reason: 'x' }, { factor: 'Tick1 Signal B', contrib: 3, reason: 'y' } ] },
  } });
  const r1 = runFull(ctx1);
  console.log(`  tick1 (strong buy, clean): decision=${r1.brain.decision} score=${r1.brain.directionalScore.toFixed(2)} critFails=[${r1.brain.criticalFails.map(f=>f.factor).join(',')}] fmTriggered=${r1.fm.length}`);

  // Tick 2: a critical FM fires (no live option-chain data -> FM036) -
  // must immediately override, independent of tick1's positive score.
  const ctx2 = buildCtx({ overrides: {
    ocRow: null,
    operatorIntel: { bias: 'BULLISH', score: 6, confidence: 'HIGH', signals: [
      { factor: 'Tick2 Signal A', contrib: 3, reason: 'x' }, { factor: 'Tick2 Signal B', contrib: 3, reason: 'y' } ] },
  } });
  const r2 = runFull(ctx2);
  const fm036 = r2.fm.find(f => f.id === 'FM036');
  console.log(`  tick2 (FM036 no-live-ocRow fires): decision=${r2.brain.decision} score=${r2.brain.directionalScore.toFixed(2)} FM036=${!!fm036} finalAction=${r2.fmRaw.finalAction}`);

  // Tick 3: FM036 clears (ocRow back) but market has moved unfavorably
  // (bearish operator signal now).
  const ctx3 = buildCtx({ overrides: {
    operatorIntel: { bias: 'BEARISH', score: -6, confidence: 'HIGH', signals: [
      { factor: 'Tick3 Signal A', contrib: -3, reason: 'x' }, { factor: 'Tick3 Signal B', contrib: -3, reason: 'y' } ] },
  } });
  const r3 = runFull(ctx3);
  const fm036AtTick3 = r3.fm.find(f => f.id === 'FM036');
  console.log(`  tick3 (FM036 cleared, market moved bearish): decision=${r3.brain.decision} score=${r3.brain.directionalScore.toFixed(2)} FM036 still present=${!!fm036AtTick3}`);

  // Tick 4: a DIFFERENT FM fires - 3 consecutive same-day losses (FM047),
  // journal-driven, independent of tick2's FM036 condition.
  const lossJournal = [
    { pnl: -100, closedAt: Date.now() - 3000 }, { pnl: -150, closedAt: Date.now() - 2000 }, { pnl: -80, closedAt: Date.now() - 1000 },
  ];
  const ctx4 = buildCtx({ overrides: { fullJournal: lossJournal, journalToday: lossJournal, consecLoss: 3 } });
  const r4 = runFull(ctx4);
  const fm047 = r4.fm.find(f => f.id === 'FM047');
  console.log(`  tick4 (FM047 consec-loss fires): decision=${r4.brain.decision} FM047=${!!fm047} FM036 still present=${!!r4.fm.find(f=>f.id==='FM036')}`);

  // Tick 5: everything clears, fresh strong signal appears.
  const ctx5 = buildCtx({ overrides: {
    operatorIntel: { bias: 'BULLISH', score: 6, confidence: 'HIGH', signals: [
      { factor: 'Tick5 Signal A', contrib: 3, reason: 'x' }, { factor: 'Tick5 Signal B', contrib: 3, reason: 'y' } ] },
  } });
  const r5 = runFull(ctx5);
  console.log(`  tick5 (fresh clean strong buy): decision=${r5.brain.decision} score=${r5.brain.directionalScore.toFixed(2)} critFails=[${r5.brain.criticalFails.map(f=>f.factor).join(',')}]`);

  check(r1.brain.criticalFails.length === 0 && (r1.brain.decision === 'BUY_READY' || r1.brain.decision === 'WAIT'), '4-tick1: clean strong-buy tick is not blocked');
  check(!!fm036, '4-tick2: FM036 (no live option-chain data) genuinely fires when ctx.ocRow is null');
  check(r2.fmRaw.finalAction === 'block' || r2.fmRaw.finalAction === 'reject', '4-tick2: the critical FM immediately drives finalAction to a blocking action THIS tick, regardless of tick1\'s positive score - no bleed-through of tick1\'s state');
  check(!fm036AtTick3, '4-tick3: FM036 correctly does NOT persist into tick3 once ctx.ocRow is present again - each tick is independently derived from ITS OWN ctx, no stale carryover from tick2');
  check(r3.brain.directionalScore < r1.brain.directionalScore, '4-tick3: tick3\'s bearish operator signal genuinely moves the score down relative to tick1, proving tick3 used its own ctx and not tick1\'s cached bullish signals');
  check(!!fm047, '4-tick4: FM047 (3+ consecutive same-day losses) genuinely fires off tick4\'s own journal/consecLoss data');
  check(!r4.fm.find(f => f.id === 'FM036'), '4-tick4: tick2\'s FM036 does not bleed through into tick4 (different FM, unrelated condition, no shared mutable state)');
  check(r5.brain.criticalFails.length === 0, '4-tick5: once tick2/tick4\'s conditions clear and a fresh ctx is supplied, tick5 has no critical fails - fully independent of every prior tick in the sequence');
  check(r5.brain.decision === 'BUY_READY' || r5.brain.decision === 'WAIT', '4-tick5: fresh clean strong-buy ctx at tick5 is not blocked by anything from ticks 1-4');
  check(Math.abs(r1.brain.directionalScore - r5.brain.directionalScore) < 1e-6, '4: tick1 and tick5 (identical operator-intel shape, same base ctx) independently produce the SAME score - proving evaluateBrain is a pure function of its own ctx across this 5-call sequence, not accumulating any module-level state');
}

// ===========================================================================
// 5. CATEGORY-AVAILABILITY / REPORT-HONESTY - some categories have real
//    data, others receive NO relevant ctx fields at all (not null/NaN
//    fields WITHIN a category - the whole category's inputs are absent)
// ===========================================================================
console.log('\n=== 5. CATEGORY-AVAILABILITY / REPORT-HONESTY ===');
{
  // Market/Tech/Risk/Regulatory/Costs/Microstructure/Fundamental/Psychology
  // keep real base-ctx data; Decay/Greeks-Deep (ctx.decay=null) and Flow/Vol
  // -relevant OI data (ocRow=null, ocRows=[], pcr=NaN, totalPE/totalCE
  // undefined) receive NO relevant fields at all this refresh.
  const ctx = buildCtx({ overrides: {
    decay: null, ocRow: null, ocRows: [], pcr: NaN, totalPE: undefined, totalCE: undefined,
    operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [] },
  } });
  const brain = evaluateBrain(ctx);
  const catsPresent = new Set(brain.results.map(r => r.cat));
  console.log(`  decision=${brain.decision} directionalScore=${brain.directionalScore.toFixed(2)}`);
  console.log(`  categories present in results: ${[...catsPresent].sort().join(', ')}`);

  check(!catsPresent.has('Decay'), '5: with ctx.decay=null, the entire Decay category is genuinely absent from results (not fabricated as scored/passed)');
  check(!catsPresent.has('Greeks Deep'), '5: with ctx.decay=null, the entire Greeks Deep category is genuinely absent (it is derived from the same BSM snapshot)');
  check(!catsPresent.has('Vol'), '5: with ctx.decay=null, the entire Vol category (ATM IV/skew/etc, also derived from the BSM snapshot) is genuinely absent');
  check(catsPresent.has('Market') && catsPresent.has('Tech') && catsPresent.has('Risk'), '5: categories with real data present (Market/Tech/Risk) are genuinely still evaluated and present in results');

  // The aggregate score must reflect only what actually ran: sum of ALL
  // individual row scores (not just directional ones) must equal totalScore
  // exactly, and the sum restricted to the categories that genuinely had
  // data (Market/Tech/Risk/etc, i.e. everything except Decay/Vol/Greeks Deep)
  // must equal the SAME totalScore, since the skipped categories contribute
  // literally zero rows (not zero-scored rows - zero rows).
  const sumAll = brain.results.reduce((s, r) => s + (Number.isFinite(r.score) ? r.score : 0), 0);
  check(Math.abs(sumAll - brain.totalScore) < 1e-6, '5: totalScore genuinely equals the sum of every present result row (no hidden contribution from a category that was actually skipped)');

  // Report/brainLog honesty: no row for a skipped-category factor exists
  // that could be mistaken for "evaluated and passed" - since the whole
  // category has zero rows here, this also directly verifies the "report
  // says everything worked when some components did not execute" concern:
  // a naive summary counting brain.results.length or "categories evaluated"
  // must not claim Decay/Vol/Greeks Deep ran.
  const decayRelatedFactorNames = ['ATM IV', 'Value Decay', 'Days to Expiry', 'Theta', 'Vega', 'Vanna', 'Vomma', 'Rho', 'Skew', 'Term Structure'];
  const leaked = brain.results.filter(r => decayRelatedFactorNames.some(n => r.factor && r.factor.includes(n)));
  check(leaked.length === 0, '5: no Decay/Vol/Greeks-Deep-named factor leaks into results when ctx.decay is null - the report is explicit (by omission, verified structurally) about which categories did not execute, not merely silent-but-technically-present with a fake unscored row');

  // Cross-check against the pre-existing category registry so a future
  // refactor that silently starts emitting empty-but-labeled rows (e.g.
  // pass:null placeholders) for these categories would also be caught -
  // not just relying on category absence.
  check(brain.results.filter(r => r.cat === 'Decay' || r.cat === 'Vol' || r.cat === 'Greeks Deep').length === 0, '5: zero rows of any kind (including pass:null placeholders) exist for Decay/Vol/Greeks Deep this refresh - genuinely skipped, not represented at all');
}

// ===========================================================================
// Kill-switch confirmation
// ===========================================================================
console.log('\n=== Kill-switch confirmation ===');
{
  const phpSource = fs.readFileSync(path.join(__dirname, '../fno-lab.php'), 'utf8');
  check(/define\('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED',\s*false\);/.test(phpSource), 'Kill-switch: FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED is still exactly `false` in fno-lab.php');
}

console.log(`\nTOTAL: ${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
