// REAL Phase-3 interaction/synchronization audit. Loads the real
// fno-lab-core.js source (same eval-slice pattern as every other test in
// this directory) and ACTUALLY EXECUTES:
//  1. adjustFailureModesForTradeType() with 3/4/5 simultaneously-
//     triggered FMs of different severities/actions - exhaustive
//     priority-ordering checks, not just the 2-condition pairing Phase 2
//     already fixed and tested.
//  2. The Greeks-Deep/Vol same-strike IV-skew double-count fix (Phase 3
//     finding) - a real evaluateBrain() run in the degraded-fallback
//     state (no ctx.ocRows/expiryDates) verifying the signal is now
//     counted exactly once.
//  3. A real conflicting-indicator scenario (bullish price action vs
//     bearish options flow/OI) via evaluateBrain(), verifying the
//     engine reduces confidence/magnitude rather than letting either
//     side dominate or silently averaging into a falsely-confident
//     middle score.
//
// Run with: node tests/interaction-sync-audit.test.js

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

function buildBaseCtx(overrides) {
  const spot = 23200;
  const candles = [];
  let c = 23000;
  for (let i = 0; i < 60; i++) { c += (Math.sin(i / 5) * 10 + 2); candles.push({ c: Math.round(c * 100) / 100, t: 1700000000 + i * 300 }); }
  candles[candles.length - 1].c = spot;
  const closes = candles.map(x => x.c);
  const strike = 23200;
  const iv = 14;
  const lotSize = 50;
  const daysExp = 15;
  const optPrice = Math.round(calculateDecay(spot, strike, daysExp, iv, 100, lotSize, 'CE').snapshot.now.price * 100) / 100;
  const decay = calculateDecay(spot, strike, daysExp, iv, optPrice, lotSize, 'CE');
  const ocRow = { CE: { lastPrice: optPrice, impliedVolatility: iv, openInterest: 500000 }, PE: { lastPrice: 84, impliedVolatility: iv + 1, openInterest: 480000 }, strikePrice: strike };
  const ocRows = [
    { strikePrice: strike - 500, CE: { openInterest: 400000, impliedVolatility: 15 }, PE: { openInterest: 420000, impliedVolatility: 19 } },
    { strikePrice: strike, CE: { openInterest: 500000, impliedVolatility: 14 }, PE: { openInterest: 480000, impliedVolatility: 15 } },
    { strikePrice: strike + 500, CE: { openInterest: 380000, impliedVolatility: 13 }, PE: { openInterest: 360000, impliedVolatility: 14 } },
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
    symbol: 'NIFTY',
  };
  return Object.assign(base, overrides || {});
}

console.log('\n=== PHASE 3: INTERACTION / SYNCHRONIZATION AUDIT ===\n');

// ---------------------------------------------------------------------
// Part 1: exhaustive 3+/4+/5+ simultaneous-FM priority tests against
// the REAL adjustFailureModesForTradeType() function.
// ---------------------------------------------------------------------
console.log('--- Part 1: adjustFailureModesForTradeType() with 3+ simultaneous FMs ---');

function mkFmResult(entries) {
  // entries: [{id, severity, action}]
  return { triggered: entries.map(e => ({ id: e.id, condition: e.id, severity: e.severity, action: e.action, reason: e.id })) };
}

const ALL_ACTIONS = ['block', 'reject', 'require_confirmation', 'delay', 'reduce_position_size', 'reduce_confidence'];
const PRIORITY = ['block', 'reject', 'require_confirmation', 'delay', 'reduce_position_size', 'reduce_confidence', 'none'];

// 1a: every action fires simultaneously (6 conditions at once) -> must resolve to 'block'.
{
  const fm = mkFmResult(ALL_ACTIONS.map((a, i) => ({ id: 'FMx' + i, severity: 'low', action: a })));
  const r = adjustFailureModesForTradeType(fm, 'intraday');
  check(r.finalAction === 'block', 'All 6 distinct actions firing together (intraday, non-relevant) -> finalAction is block');
}

// 1b: 3 simultaneous, non-critical mix (delay + reduce_position_size + reduce_confidence) -> most severe of the three (delay) wins.
{
  const fm = mkFmResult([
    { id: 'FMa', severity: 'medium', action: 'reduce_confidence' },
    { id: 'FMb', severity: 'medium', action: 'reduce_position_size' },
    { id: 'FMc', severity: 'high', action: 'delay' },
  ]);
  const r = adjustFailureModesForTradeType(fm, 'intraday');
  check(r.finalAction === 'delay', '3 simultaneous non-critical actions (reduce_confidence+reduce_position_size+delay) -> finalAction is delay (most protective of the three)');
}

// 1c: 4 simultaneous including a real critical block buried LAST in triggered order, mixed with delay/reduce_position_size/require_confirmation - block must win regardless of array position (this is exactly the shape of bug Phase 2 found and fixed).
{
  const fm = mkFmResult([
    { id: 'FM021', severity: 'medium', action: 'delay' },
    { id: 'FMx', severity: 'medium', action: 'reduce_position_size' },
    { id: 'FMy', severity: 'high', action: 'require_confirmation' },
    { id: 'FM002', severity: 'critical', action: 'block' },
  ]);
  const r = adjustFailureModesForTradeType(fm, 'intraday');
  check(r.finalAction === 'block', '4 simultaneous FMs, critical block listed LAST -> finalAction is still block (order-independence, the exact Phase-2 bug shape re-verified with 4 conditions)');
}

// 1d: 5 simultaneous, no block/reject present -> require_confirmation (highest-priority action actually present) wins over delay/reduce_position_size/reduce_confidence.
{
  const fm = mkFmResult([
    { id: 'FMa', severity: 'low', action: 'reduce_confidence' },
    { id: 'FMb', severity: 'medium', action: 'reduce_position_size' },
    { id: 'FMc', severity: 'medium', action: 'delay' },
    { id: 'FMd', severity: 'high', action: 'require_confirmation' },
    { id: 'FMe', severity: 'low', action: 'reduce_confidence' },
  ]);
  const r = adjustFailureModesForTradeType(fm, 'swing');
  check(r.finalAction === 'require_confirmation', '5 simultaneous FMs, no block/reject -> finalAction is require_confirmation (correct next-tier)');
}

// 1e: 'reject' present alongside a lower-severity 'block'-shaped entry ordering - reject and block are BOTH top-tier gate actions (line 13038 treats them identically); verify reject also survives against every other action.
{
  const fm = mkFmResult([
    { id: 'FMa', severity: 'low', action: 'reduce_confidence' },
    { id: 'FMb', severity: 'medium', action: 'delay' },
    { id: 'FMc', severity: 'high', action: 'require_confirmation' },
    { id: 'FMd', severity: 'critical', action: 'reject' },
  ]);
  const r = adjustFailureModesForTradeType(fm, 'intraday');
  check(r.finalAction === 'reject', '4 simultaneous FMs incl. reject -> finalAction is reject (not overridden by any lower-priority action)');
}

// 1f: exhaustive pairwise dominance - for every pair (A,B) in the real priority list, firing both simultaneously (in BOTH array orders) must resolve to whichever is earlier in PRIORITY, regardless of position in the triggered array.
{
  let allPairsOk = true;
  for (let i = 0; i < PRIORITY.length - 1; i++) {
    for (let j = i + 1; j < PRIORITY.length - 1; j++) {
      const a = PRIORITY[i], b = PRIORITY[j];
      const expected = a; // earlier index = more severe/protective
      const fmForward = mkFmResult([{ id: 'A', severity: 'medium', action: a }, { id: 'B', severity: 'medium', action: b }]);
      const fmReverse = mkFmResult([{ id: 'B', severity: 'medium', action: b }, { id: 'A', severity: 'medium', action: a }]);
      const rF = adjustFailureModesForTradeType(fmForward, 'intraday').finalAction;
      const rR = adjustFailureModesForTradeType(fmReverse, 'intraday').finalAction;
      if (rF !== expected || rR !== expected) {
        allPairsOk = false;
        console.log(`    MISMATCH: pair (${a}, ${b}) expected ${expected}, got forward=${rF} reverse=${rR}`);
      }
    }
  }
  check(allPairsOk, `Exhaustive pairwise priority dominance across all ${PRIORITY.length - 1} real action types (both array orders) - always resolves to the more protective action`);
}

// 1g: severity-escalation (typeRelevance bump) interacting with a simultaneous unrelated critical block - a scalping-relevant medium condition bumped to high/require_confirmation must NOT be able to outrank a genuine, already-critical/block condition firing in the same refresh.
{
  const fm = mkFmResult([
    { id: 'FM023', severity: 'medium', action: 'reduce_confidence' }, // scalping-relevant -> bumped to high/require_confirmation
    { id: 'FM002', severity: 'critical', action: 'block' },
  ]);
  const r = adjustFailureModesForTradeType(fm, 'scalping');
  const fm023 = r.triggered.find(t => t.id === 'FM023');
  check(fm023.adjustedSeverity === 'high' && fm023.adjustedAction === 'require_confirmation', 'FM023 (scalping-relevant) genuinely escalated medium->high, reduce_confidence->require_confirmation');
  check(r.finalAction === 'block', 'Escalated require_confirmation from type-relevance bump does NOT outrank a simultaneous genuine critical block');
}

// 1h: no triggered conditions at all -> 'none', not a crash, not a false action.
{
  const r = adjustFailureModesForTradeType(mkFmResult([]), 'intraday');
  check(r.finalAction === 'none', 'Zero triggered FMs -> finalAction is none');
}

// ---------------------------------------------------------------------
// Part 2: evaluatePreTradeFailureModes()'s OWN priority/default
// ('proceed') vs adjustFailureModesForTradeType()'s ('none') - confirm
// this naming mismatch is genuinely cosmetic (raw fmResultRaw.finalAction
// is never itself compared to 'block'/'reject' anywhere - only the
// ADJUSTED result is, at the real call site) and not a live bug.
// ---------------------------------------------------------------------
console.log('\n--- Part 2: raw vs adjusted default-action naming ("proceed" vs "none") ---');
{
  const rawSource = coreSource.slice(0, end);
  const rawFinalActionUses = (rawSource.match(/fmResultRaw\.finalAction/g) || []).length;
  check(rawFinalActionUses === 0, 'fmResultRaw.finalAction (raw, pre-adjustment result) is never itself compared/branched on anywhere - only the adjusted fmResult.finalAction is (confirms the "proceed" vs "none" default-string mismatch between the two functions is cosmetic, not a live gating bug)');
}

// ---------------------------------------------------------------------
// Part 3: Greeks-Deep / Vol same-strike IV-skew double-count fix
// (genuine Phase-3 finding, fixed this pass at computeGreeksDeepFactors,
// ~line 8879).
// ---------------------------------------------------------------------
console.log('\n--- Part 3: same-strike IV-skew double-count fix (Greeks Deep fallback) ---');
{
  // Force the DEGRADED fallback branch: no ctx.ocRows / no expiryDates,
  // but a real single ocRow with valid CE/PE IV - a real, reachable
  // refresh state (e.g. a partial NSE fetch that returned the selected
  // strike but not the full multi-expiry chain).
  const ctx = buildBaseCtx({ ocRows: [], expiryDates: [] });
  const brain = evaluateBrain(ctx);
  const volSkew = brain.results.find(r => r.factor === 'Vol Skew OTM Put vs Call');
  const greeksSkew = brain.results.find(r => r.factor === 'Skew - OTM Put IV vs Call IV');
  check(!!volSkew && !!greeksSkew, 'Both same-strike-skew factor rows are present in this degraded-fallback scenario');
  check(volSkew.score !== 0, "Vol category's skew factor still carries a real, non-zero score (the one genuine scoring of this signal)");
  check(greeksSkew.score === 0, "Greeks Deep's fallback skew factor is now informational (score:0) - the same PE-CE IV differential is no longer double-counted into totalScore");
  check(greeksSkew.pass === true, "Greeks Deep's fallback skew factor reports pass:true (informational, not a fabricated fail) to match this file's other score:0 informational rows");
}
{
  // Sanity: the NORMAL (non-fallback) path, with real ocRows/expiryDates
  // present, still computes a genuinely DIFFERENT cross-strike signal
  // and is untouched by this fix.
  const ctx = buildBaseCtx({});
  const brain = evaluateBrain(ctx);
  const greeksSkew = brain.results.find(r => r.factor === 'Skew - OTM Put IV vs Call IV');
  check(greeksSkew.reason.includes('cross-strike'), 'Normal (non-fallback) path still uses the genuine cross-strike skew computation, unaffected by the fallback-only fix');
}

// ---------------------------------------------------------------------
// Part 4: conflicting-indicator scenario - price action bullish
// (EMA9>EMA21, uptrending candles) but options flow/OI genuinely
// bearish (heavy PE OI buildup / low PCR / bearish operator intel) -
// verify this produces a genuinely reduced-confidence/lower-magnitude
// outcome, not either side dominating and not a falsely-confident
// middle score.
// ---------------------------------------------------------------------
console.log('\n--- Part 4: conflicting-indicator scenario (bullish price action vs bearish flow) ---');
{
  // Baseline: all-bullish scenario (price action AND flow/OI agree) for
  // comparison. Keep spot/strike/decay/ema21/vwap exactly as
  // buildBaseCtx's own internal, self-consistent construction produces
  // (varying only candles/pcr/ocRow-OI/operatorIntel - the real
  // flow/OI-vs-price-action signals under test - not spot/strike, which
  // would desync the pre-built decay/greeks snapshot from a different
  // strike and manufacture an unrelated NaN in this TEST's ctx, not a
  // product bug).
  const candlesA = [];
  let cA = 22900;
  for (let i = 0; i < 60; i++) { cA += 9; candlesA.push({ c: Math.round(cA * 100) / 100, t: 1700000000 + i * 300 }); }
  candlesA[candlesA.length - 1].c = 23200; // land on the same spot buildBaseCtx's decay/ocRow strike already use
  // Real operatorIntel shape (matches computeOperatorIntel's actual
  // return: {bias, score, confidence, signals:[{factor,contrib,reason}]}
  // - opIntel.signals.forEach reads s.contrib, not a plain string).
  const ctxAgree = buildBaseCtx({
    candles: candlesA, pcr: 1.5,
    ocRow: { CE: { lastPrice: 120, impliedVolatility: 14, openInterest: 500000 }, PE: { lastPrice: 60, impliedVolatility: 15, openInterest: 300000 }, strikePrice: 23200 },
    operatorIntel: { bias: 'ACCUMULATION', score: 3, confidence: 'HIGH', signals: [{ factor: 'CE OI Buildup', contrib: 1.5, reason: 'strong CE buildup' }] },
    ema21: ema(candlesA.map(x => x.c), 21)[candlesA.length - 1],
    vwap: vwapCalc(candlesA)[candlesA.length - 1],
  });
  const brainAgree = evaluateBrain(ctxAgree);

  // Conflicting scenario: SAME bullish price action, but flow/OI is
  // genuinely bearish (low PCR = heavy call writing/put buying skew
  // toward bearish, heavy PE-side OI, bearish operator intel).
  const ctxConflict = buildBaseCtx({
    candles: candlesA, pcr: 0.55,
    ocRow: { CE: { lastPrice: 120, impliedVolatility: 14, openInterest: 300000 }, PE: { lastPrice: 60, impliedVolatility: 15, openInterest: 700000 }, strikePrice: 23200 },
    operatorIntel: { bias: 'DISTRIBUTION', score: -3, confidence: 'HIGH', signals: [{ factor: 'PE OI Buildup', contrib: -1.5, reason: 'heavy PE OI buildup, bearish operator positioning' }] },
    ema21: ema(candlesA.map(x => x.c), 21)[candlesA.length - 1],
    vwap: vwapCalc(candlesA)[candlesA.length - 1],
  });
  const brainConflict = evaluateBrain(ctxConflict);

  console.log(`    Agreeing scenario: decision=${brainAgree.decision} confidence=${brainAgree.confidence} totalScore=${brainAgree.totalScore != null ? brainAgree.totalScore.toFixed(2) : brainAgree.totalScore}`);
  console.log(`    Conflicting scenario: decision=${brainConflict.decision} confidence=${brainConflict.confidence} totalScore=${brainConflict.totalScore != null ? brainConflict.totalScore.toFixed(2) : brainConflict.totalScore}`);

  check(typeof brainAgree.totalScore === 'number' && typeof brainConflict.totalScore === 'number', 'Both scenarios produce a real numeric totalScore');
  check(brainConflict.totalScore < brainAgree.totalScore, 'Conflicting-indicator scenario genuinely scores LOWER than the agreeing scenario (price-action-only bullishness is not enough on its own once flow/OI disagrees)');

  const confidenceRank = { 'Low': 0, 'Medium': 1, 'High': 2 };
  const agreeRank = confidenceRank[brainAgree.confidence];
  const conflictRank = confidenceRank[brainConflict.confidence];
  check(agreeRank !== undefined && conflictRank !== undefined, 'Both scenarios produce a recognized confidence label');
  check(conflictRank <= agreeRank, 'Conflicting-indicator scenario confidence is genuinely no higher than the agreeing scenario (disagreement reduces confidence, does not get silently averaged into equal or higher confidence)');

  // The key "not a falsely-confident middle score" check: the
  // conflicting scenario must NOT resolve to a ready-to-trade BUY
  // decision at High confidence - if price action alone could still
  // push a BUY_READY/High result despite genuinely bearish flow/OI,
  // that is exactly the "one indicator silently dominates" failure
  // mode the task asked to check for.
  check(!(brainConflict.decision === 'BUY_READY' && brainConflict.confidence === 'High'), 'Conflicting scenario does not produce a falsely-confident BUY_READY/High result despite genuinely bearish flow/OI');
}

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
console.log(`\n=== RESULTS: ${passed} passed, ${failed} failed ===\n`);
if (failed > 0) process.exit(1);
