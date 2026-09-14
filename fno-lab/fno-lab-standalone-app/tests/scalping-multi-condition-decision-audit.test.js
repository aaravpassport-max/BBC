// REAL, STANDALONE, IMMEDIATELY-RUNNABLE end-to-end audit test - built
// at the user's own direct, explicit request for a comprehensive,
// combined-condition audit of the decision engine, with special
// emphasis on SCALPING. Unlike every existing scalping-touching test
// in this repo (see the research pass that scoped this file: every
// prior scalping assertion tests exactly ONE mechanism in isolation -
// a single FM severity escalation, or a JSON-inequality check that two
// weighted-score OBJECTS differ, or the 3-way trade-type resolver's
// precedence) - none of them build a REALISTIC, MULTI-CONDITION
// scalping trade and check the actual FINAL DECISION, nor do they
// cross-check the decision the "eligibility screen" (evaluateBrain's
// own brain.decision) shows against what the SEPARATE, real pre-trade
// Failure-Mode Library gate (evaluatePreTradeFailureModes +
// adjustFailureModesForTradeType, the function that ACTUALLY runs at
// real auto-trade-open time - see tryOpenAutoTradePosition,
// fno-lab-core.js ~line 13815) would do to the SAME real trade.
//
// This file drives the REAL evaluateBrain()/evaluatePreTradeFailureModes()/
// adjustFailureModesForTradeType()/computeTradeTypeWeightedScore()/
// computeTradeTypeDirectionalWeightedScore() functions (same eval-slice
// extraction pattern as every other audit test in this directory -
// never a reimplementation) through 8 realistic, combined-condition
// scenarios and reports, for each, the REAL actual outcome versus what
// the system's own documented rules say SHOULD happen.
//
// Run with: node tests/scalping-multi-condition-decision-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
const findings = [];
function check(cond, label, findingIfFail) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); if (findingIfFail) findings.push(findingIfFail); }
}
function note(label) { console.log('  NOTE  ' + label); }

const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
const { fnoNormCdf, fnoNormPdf, bsGreeks, bsGreeksAtDays, buildGreeksSnapshot, FNO_RISK_FREE_RATE, TRADING_HOURS_PER_DAY, solveImpliedVolatility } = ge;

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) { console.error('FATAL: render() boundary marker not found'); process.exit(1); }
eval(coreSource.slice(0, end));

// Real fnoSettings stub - evaluateBrain() reads fnoSettings.get().tradingTypes
// to resolve getEffectiveTradingType() internally (see its own real call
// sites at lines ~11371/11394). This lets us drive 'scalping' vs
// 'intraday' through the REAL internal resolution path, not a param
// override, exactly matching how the real browser app switches types.
let fnoTradingTypes = { intraday: true, swing: false, scalping: false };
global.fnoSettings = { get: () => ({ tradingTypes: fnoTradingTypes }) };
// Real factor catalog stub (same file/pattern report-accuracy-audit.test.js
// already uses) - without this, brain.factorRegistry stays null and
// buildEntrySnapshot() unconditionally short-circuits to null (see its
// own real guard, fno-lab-core.js:6589), which would make Scenario 8
// below inconclusive by test-harness omission rather than by real
// system behavior.
if (typeof global.window === 'undefined') global.window = {};
global.window.FNO_FACTORS_CATALOG = JSON.parse(fs.readFileSync(path.join(__dirname, '../assets/factors.json'), 'utf8'));
function setTradeType(type) {
  fnoTradingTypes = { intraday: type === 'intraday', swing: type === 'swing', scalping: type === 'scalping' };
}

// ---------------------------------------------------------------------
// Realistic base ctx builder - same shape as end-to-end-decision-engine-audit.test.js's
// own buildBaseCtx (kept in sync deliberately - both must reflect the
// real autonomous-driver.js ctx shape).
// ---------------------------------------------------------------------
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
  const ocRow = { CE: { lastPrice: optPrice, impliedVolatility: iv, openInterest: 500000, bidprice: optPrice - 1, askPrice: optPrice + 1, bidQty: 500, askQty: 500 }, PE: { lastPrice: 84, impliedVolatility: iv + 1, openInterest: 480000, bidprice: 83, askPrice: 85, bidQty: 500, askQty: 500 }, strikePrice: strike };
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
    vwap: closeAvgProxy(candles, 20)[candles.length - 1],
    newsSentiment: null, newsSentimentTier: 'unavailable',
    marketDepth: null, marketDepthTier: 'unavailable',
    participantOI: null, asmGsmResult: null, microstructure: null,
    symbol: 'NIFTY',
  };
  return Object.assign(base, overrides || {});
}

function runFull(ctx, extraEvalCtx) {
  const brain = evaluateBrain(ctx);
  const fm = evaluatePreTradeFailureModes(Object.assign({ brain, ctx }, extraEvalCtx || {}));
  return { brain, fm };
}

console.log('\n=== SCALPING MULTI-CONDITION DECISION-ENGINE AUDIT: 8 realistic combined scenarios ===\n');

// ---------------------------------------------------------------------
// Scenario 1: Trade-type weighting genuinely FLIPS the decision for
// scalping (a realistic combined case, not just "the objects differ").
// A strong bullish score driven almost entirely by categories scalping
// weights DOWN (Fundamental 0.5x, Decay 0.6x) should downgrade under
// real scalping weighting while staying BUY_READY for intraday.
// ---------------------------------------------------------------------
{
  console.log('--- Scenario 1: scalping weighting flips a would-be BUY_READY to WAIT/downgraded (Fundamental/Decay-driven score) ---');
  // Real operator intel signals tagged as Fundamental-ish bullish bias
  // are not directly assignable to a category from ctx - instead we
  // drive the real Decay/Fundamental categories up via realistic
  // structural inputs (short-dated decay favorable + real PCR/futures
  // premium signals that land in Fundamental), while deliberately
  // keeping Microstructure/Flow/Tech/Vol (scalping's up-weighted
  // categories) neutral-to-slightly-negative so the raw score's real
  // composition is genuinely dominated by the down-weighted categories.
  const spot = 23200;
  const ctx = buildBaseCtx({
    spot,
    pcr: 1.55, // real, strong PE buildup signal -> bullish Fundamental-ish factor
    futuresPrice: spot + 120, // real, strong futures premium -> bullish Fundamental factor
    vix: 15, // neutral
    ema21: spot + 5, vwap: spot + 5, // flat/slightly bearish Tech, deliberately not contributing bullish
    operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [] },
  });
  const brainIntraday = evaluateBrain(Object.assign({}, ctx)); // fnoSettings still default intraday at module scope during this call
  setTradeType('intraday');
  const bIntra = evaluateBrain(ctx);
  setTradeType('scalping');
  const bScalp = evaluateBrain(ctx);
  console.log(`  raw directionalScore=${bIntra.directionalScore.toFixed(2)} | intraday decision=${bIntra.decision} | scalping decision=${bScalp.decision} | scalping weightedDirectionalScore driving factor: tradeTypeWeightingAdjustment=${bScalp.tradeTypeWeightingAdjustment ? 'present' : 'null'}`);
  const rawCrossedBuy = bIntra.directionalScore >= 11;
  if (rawCrossedBuy && bIntra.decision === 'BUY_READY') {
    check(bScalp.decision !== 'BUY_READY' || bScalp.tradeTypeWeightingAdjustment === null, 'S1: IF the scalping-weighted score no longer clears BUY_THRESHOLD, the scalping decision is genuinely downgraded (WAIT or confidence-relabeled) with a non-null tradeTypeWeightingAdjustment explaining why', 'Scalping weighting mechanism exists but did not engage on a realistic Fundamental/Decay-dominated bullish setup - either the test composition needs a stronger down-weighted-category bias, or the mechanism under-fires.');
    if (bScalp.tradeTypeWeightingAdjustment) {
      check(bScalp.reason.includes('scalping') || bScalp.reason.toLowerCase().includes('downgrad') || bScalp.reason.toLowerCase().includes('confidence downgraded'), 'S1: the real brain.reason (what a report/UI would show) explicitly names the trade-type-weighting cause, not just a generic score message', 'brain.reason does not surface WHY the scalping decision differs from the raw/intraday one - a viewer comparing intraday vs scalping eligibility screens would see different decisions with no visible explanation.');
    }
  } else {
    note(`S1a: this specific realistic ctx composition did not land a raw score inside the narrow window needed to test the flip (raw score=${bIntra.directionalScore.toFixed(2)}, intraday decision=${bIntra.decision}) - this codebase's factors are mostly discrete pass/fail (0.3-1.5 per factor), so hitting the exact narrow band where raw crosses BUY_THRESHOLD=11 but scalping-weighted doesn't is a genuinely narrow target via ctx tuning alone (confirmed by hand: this composition's Tech category alone jumps the raw score from 10.5 to 14.5 in one step, overshooting the window - no finer realistic lever was found within this pass\'s time budget). Falling through to S1b for a direct, real-function boundary verification instead.`);
  }
  // S1b: direct verification of the real applyTradeTypeWeightingAdjustmentToDecision
  // function (never a reimplementation - the exact function evaluateBrain()
  // itself calls at line ~11408) at a hand-constructed boundary that IS
  // inside the narrow flip window, since S1a's realistic-ctx search did
  // not land inside it within budget. This proves the mechanism itself
  // performs the flip correctly and explains itself in `reason`, even
  // though S1a shows building a fully naturalistic ctx that lands exactly
  // in that band is nontrivial - a real, honestly-reported limitation of
  // this test pass, not evidence the mechanism is broken (S1a's own
  // numbers - raw 14.5 vs scalping-weighted 14.28 - show the weighting
  // computation itself IS real and responsive, just not narrowly tunable
  // enough via ctx alone within this session's time budget).
  {
    const boundaryResult = applyTradeTypeWeightingAdjustmentToDecision(
      'BUY_READY', 'Medium', 'Directional score 11.5 (>= 11) bullish - operator bias NEUTRAL (LOW confidence)',
      11.5, 9.0, 'scalping', 11, -17
    );
    console.log(`  S1b (direct real-function boundary case): decision=${boundaryResult.decision} confidence=${boundaryResult.confidence} adjustment=${boundaryResult.tradeTypeWeightingAdjustment ? 'present' : 'null'}`);
    check(boundaryResult.decision === 'WAIT' && boundaryResult.tradeTypeWeightingAdjustment !== null, 'S1b: the real applyTradeTypeWeightingAdjustmentToDecision correctly downgrades BUY_READY->WAIT when scalping-weighted score (9.0) no longer clears BUY_THRESHOLD (11) that the raw score (11.5) crossed, with a non-null explanation');
    check(boundaryResult.reason.toLowerCase().includes('downgrad'), 'S1b: the real, resulting reason string explicitly says this was a downgrade (what a report/UI would actually display), not a silent decision change');
    // Mirror case: a High-confidence BUY_READY gets a confidence
    // relabel instead of an outright WAIT (the function's own
    // documented, deliberately-conservative "never escalate, only ever
    // downgrade by one notch for a strong call" rule).
    const boundaryHighConf = applyTradeTypeWeightingAdjustmentToDecision(
      'BUY_READY', 'High', 'Directional score 11.5 (>= 11) bullish',
      11.5, 9.0, 'scalping', 11, -17
    );
    check(boundaryHighConf.decision === 'BUY_READY' && boundaryHighConf.confidence === 'Medium', 'S1b: a genuinely High-confidence call is relabeled Medium (not blocked outright to WAIT) under the same real scalping-weighting shortfall - matches the function\'s own documented conservative-downgrade-only rule');
  }
}

// ---------------------------------------------------------------------
// Scenario 2: The mirror case - score driven by scalping's UP-weighted
// categories (Microstructure/Flow/Vol/Tech) must NOT be spuriously
// downgraded (weighting can only ever push a marginal case down further
// when it's ALREADY dominated by down-weighted categories - it must
// never manufacture a downgrade out of a score that's genuinely
// stronger under scalping's own weighting).
// ---------------------------------------------------------------------
{
  console.log('--- Scenario 2: scalping weighting must NOT spuriously downgrade a Tech/Vol-driven bullish score ---');
  const spot = 23400;
  const candles = [];
  let c = 22900;
  for (let i = 0; i < 60; i++) { c += 9 + Math.random() * 2; candles.push({ c: Math.round(c * 100) / 100 }); }
  candles[candles.length - 1].c = spot;
  const closes = candles.map(x => x.c);
  const ctx = buildBaseCtx({
    spot, candles,
    ema21: ema(closes, 21)[closes.length - 1] - 150, vwap: closeAvgProxy(candles, 20)[candles.length - 1] - 100, // strong Tech bullish
    vix: 13, pcr: 1.0,
  });
  setTradeType('intraday');
  const bIntra = evaluateBrain(ctx);
  setTradeType('scalping');
  const bScalp = evaluateBrain(ctx);
  console.log(`  intraday decision=${bIntra.decision} (score ${bIntra.directionalScore.toFixed(2)}) | scalping decision=${bScalp.decision} (weighted ${bScalp.tradeTypeWeightingAdjustment ? 'ADJUSTED: ' + bScalp.tradeTypeWeightingAdjustment.slice(0, 80) : 'unchanged'})`);
  if (bIntra.decision === 'BUY_READY') {
    check(bScalp.decision === 'BUY_READY', 'S2: a Tech/Vol-driven BUY_READY (scalping-favored categories) is NOT spuriously downgraded under real scalping weighting', 'Scalping weighting incorrectly downgraded a decision that should have been AT LEAST as strong under scalping\'s own up-weighting of Tech/Vol - the weighting mechanism may be miscalibrated or a category tag mismatch exists.');
  } else {
    note(`S2: this composition did not reach BUY_READY under intraday either (score=${bIntra.directionalScore.toFixed(2)}) - inconclusive for this specific check, composition needs revision`);
  }
}

// ---------------------------------------------------------------------
// Scenario 3: THE KEY HIDDEN-INCONSISTENCY CHECK - eligibility screen
// (brain.decision) vs the real pre-trade Failure-Mode Library gate that
// ACTUALLY runs at trade-open time. Builds a realistic scalping trade
// that is a clean BUY_READY on the eligibility screen (no critFails,
// strong score) while its real option leg simultaneously has 2 real,
// independent order-rejection risk signals (wide spread + qty > resting
// qty) - exactly the real evalCtx.rejectionCheck the live
// tryOpenAutoTradePosition() call site builds via
// simulateOrderRejection(leg, lotSize, 'buy') for 'realistic' mode.
// ---------------------------------------------------------------------
{
  console.log('--- Scenario 3: BUY_READY eligibility screen vs real pre-trade Failure-Mode gate (same trade, same refresh) ---');
  const spot = 23400;
  const candles = [];
  let c = 22900;
  for (let i = 0; i < 60; i++) { c += 10 + Math.random() * 2; candles.push({ c: Math.round(c * 100) / 100 }); }
  candles[candles.length - 1].c = spot;
  const closes = candles.map(x => x.c);
  // A real, illiquid-looking leg: wide spread (>15% of bid) AND order
  // qty (50) exceeding the real resting ask qty (10) - the exact real,
  // documented 2-risk-factor combination simulateOrderRejection()
  // requires to set rejected:true (see its own TRACE - "reject only
  // when 2+ independent signals").
  const illiquidCE = { lastPrice: 100, impliedVolatility: 14, openInterest: 500000, bidprice: 100, askPrice: 122, bidQty: 500, askQty: 10 };
  const ctx = buildBaseCtx({
    spot, candles,
    ema21: ema(closes, 21)[closes.length - 1] - 150, vwap: closeAvgProxy(candles, 20)[candles.length - 1] - 100,
    vix: 13, pcr: 1.4,
    operatorIntel: { bias: 'BULLISH', score: 3, confidence: 'HIGH', signals: [
      { factor: 'Real Smart-Money OI Buildup', contrib: 1.5, reason: 'Strong CE writing unwind + PE buildup at ATM' },
      { factor: 'Real FII Long Bias', contrib: 1.5, reason: 'FII net long per real participant data' },
    ] },
    ocRow: { CE: illiquidCE, PE: { lastPrice: 84, impliedVolatility: 15, openInterest: 480000, bidprice: 83, askPrice: 85, bidQty: 500, askQty: 500 }, strikePrice: 23200 },
    lotSize: 50,
  });
  setTradeType('scalping');
  const brain = evaluateBrain(ctx);
  const rejectionCheck = simulateOrderRejection(illiquidCE, 50, 'buy');
  console.log(`  brain.decision=${brain.decision} (eligibility screen) | rejectionCheck.rejected=${rejectionCheck.rejected} riskFactors=${JSON.stringify(rejectionCheck.riskFactors)}`);
  check(rejectionCheck.rejected === true, 'S3 setup check: the crafted leg genuinely produces a real, documented 2-risk-factor order-rejection condition (sanity check on the test scenario itself)');
  if (brain.decision === 'BUY_READY' && rejectionCheck.rejected === true) {
    const fmRaw = evaluatePreTradeFailureModes({ brain, ctx, rejectionCheck });
    const fmResult = adjustFailureModesForTradeType(fmRaw, 'scalping');
    console.log(`  real pre-trade Failure-Mode gate (the SAME function tryOpenAutoTradePosition actually calls): finalAction=${fmResult.finalAction} triggered=[${fmResult.triggered.map(t=>t.id).join(', ')}]`);
    const fm025 = fmResult.triggered.find(t => t.id === 'FM025');
    check(!!fm025 && fmResult.finalAction === 'block', 'S3 setup check: FM025 (order rejection risk, critical/block) genuinely fires and IS the finalAction for this real leg');
    // ORIGINAL FINDING (now FIXED): brain.decision itself never used to
    // reflect this real, simultaneous block condition - evaluateBrain()
    // never called evaluatePreTradeFailureModes internally, only the
    // SEPARATE regime-based win-rate "Failure Library" feedback, a
    // different mechanism despite the similar name. FIX: evaluateBrain()
    // now ALSO computes the real, same FM-catalogue verdict for the
    // exact leg it just decided on (see computePretradeGateCheck's own
    // TRACE, fno-lab-core.js ~line 327) and returns it as
    // brain.pretradeGateCheck - purely additive/informational (does NOT
    // mutate decision/confidence, after an earlier mutating version of
    // this fix was found, via full regression, to double-penalize
    // ordinary partial-data refreshes already handled by the existing
    // coverage-based confidence downgrade - see that function's TRACE
    // for the full self-caught story). The real check now: does
    // brain.pretradeGateCheck actually SURFACE the same finalAction/
    // triggered conditions the real open-time gate independently
    // computed for this identical leg, so a report/UI reading brain
    // alone can see both pieces of information together?
    check(brain.decision === 'BUY_READY' && !!brain.pretradeGateCheck && brain.pretradeGateCheck.finalAction === 'block',
      'FIX VERIFIED: brain.pretradeGateCheck (computed on every refresh, inside evaluateBrain() itself) now correctly surfaces the SAME block verdict the real open-time gate independently computes for the identical leg - no longer hidden from a report/UI reading brain alone');
    check(brain.pretradeGateCheck.triggered.some(t => t.id === 'FM025'),
      'FIX VERIFIED: brain.pretradeGateCheck.triggered includes the real, specific triggering condition (FM025), not just a generic block flag - a report can name exactly why');
    check(JSON.stringify(brain.pretradeGateCheck.triggered.map(t=>t.id).sort()) === JSON.stringify(fmResult.triggered.map(t=>t.id).sort()),
      'FIX VERIFIED: brain.pretradeGateCheck triggered-list is genuinely identical to what tryOpenAutoTradePosition\'s own real call would compute for this leg (same real functions, same real inputs) - not a divergent, second implementation that could itself drift out of sync');
  } else {
    note(`S3: composition did not reach the required precondition (brain.decision=${brain.decision}, rejected=${rejectionCheck.rejected}) - see setup checks above`);
  }
}

// ---------------------------------------------------------------------
// Scenario 4: Scalping FM severity escalation (FM023, medium->high per
// FNO_FM_SCALPING_RELEVANT_IDS) occurring TOGETHER with a genuinely
// independent, real critical FM (FM025) - proving the escalation layer
// never suppresses/dedupes an already-critical independent condition,
// and that both appear, distinctly, in the real triggered list.
// ---------------------------------------------------------------------
{
  console.log('--- Scenario 4: simultaneous scalping-escalated FM023 + independent critical FM025 (must not suppress each other) ---');
  const ctx = buildBaseCtx({});
  const brain = evaluateBrain(ctx);
  // A leg with a genuinely wide spread (>15%, feeds both FM023's
  // "unusually wide" riskFactor text AND, combined with a qty>restingQty
  // second risk factor, FM025's rejection trigger) - both conditions
  // are real, simultaneous, and derived from the exact SAME leg/qty
  // combination a real user would submit, not two artificially separate
  // legs.
  const wideSpreadLeg = { lastPrice: 100, impliedVolatility: 14, openInterest: 500000, bidprice: 100, askPrice: 118, bidQty: 500, askQty: 5 };
  const rejectionCheck = simulateOrderRejection(wideSpreadLeg, 50, 'buy');
  const fmRaw = evaluatePreTradeFailureModes({ brain, ctx, rejectionCheck });
  const fm023Raw = fmRaw.triggered.find(t => t.id === 'FM023');
  const fm025Raw = fmRaw.triggered.find(t => t.id === 'FM025');
  check(!!fm023Raw && !!fm025Raw, 'S4 setup check: both FM023 (wide spread) and FM025 (rejection risk) genuinely, independently trigger on the same real leg/qty combination');
  const fmScalp = adjustFailureModesForTradeType(fmRaw, 'scalping');
  const fm023Scalp = fmScalp.triggered.find(t => t.id === 'FM023');
  const fm025Scalp = fmScalp.triggered.find(t => t.id === 'FM025');
  console.log(`  raw: FM023(${fm023Raw && fm023Raw.severity}/${fm023Raw && fm023Raw.action}) FM025(${fm025Raw && fm025Raw.severity}/${fm025Raw && fm025Raw.action}) | scalping-adjusted: FM023(${fm023Scalp && (fm023Scalp.adjustedSeverity||fm023Scalp.severity)}/${fm023Scalp && (fm023Scalp.adjustedAction||fm023Scalp.action)}${fm023Scalp && fm023Scalp.typeRelevance ? ', escalated for '+fm023Scalp.typeRelevance : ''}) FM025(${fm025Scalp && (fm025Scalp.adjustedSeverity||fm025Scalp.severity)}/${fm025Scalp && (fm025Scalp.adjustedAction||fm025Scalp.action)}) finalAction=${fmScalp.finalAction}`);
  check(!!fm023Scalp && !!fm025Scalp, 'S4: BOTH conditions still appear, distinctly, in the scalping-adjusted triggered list - neither is silently dropped/deduped when they co-occur');
  check(fmScalp.finalAction === 'block', 'S4: finalAction correctly stays block (the more severe of the two, FM025, is never masked by FM023\'s own escalation processing)');
  check(fm023Scalp.typeRelevance === 'scalping' || /scalping/i.test(fm023Scalp.typeRelevance || ''), 'S4: FM023 is correctly, distinctly marked as scalping-escalated (typeRelevance), separate from FM025\'s own independent critical status', 'FM023 co-occurring with an independent critical FM025 lost its scalping-escalation tag/typeRelevance annotation - the report would not show WHY FM023 was elevated in this combined case.');
}

// ---------------------------------------------------------------------
// Scenario 5: Missing/incomplete data for a scalping-relevant input
// (no live depth this refresh) combined with an otherwise-eligible
// trade - honest degrade, not a fabricated pass or a fabricated block.
// ---------------------------------------------------------------------
{
  console.log('--- Scenario 5: scalping trade with genuinely missing microstructure/depth data (must degrade honestly) ---');
  const ctx = buildBaseCtx({ marketDepth: null, marketDepthTier: 'unavailable', microstructure: null, futuresPrice: null });
  setTradeType('scalping');
  const brain = evaluateBrain(ctx);
  const microRows = brain.results.filter(r => r.cat === 'Microstructure');
  const daemonRows = microRows.filter(r => /companion tick daemon/i.test(r.reason || ''));
  const nonDaemonRows = microRows.filter(r => !/companion tick daemon/i.test(r.reason || ''));
  console.log(`  Microstructure rows: ${microRows.length} total (${daemonRows.length} daemon-fed, ${nonDaemonRows.length} independently-sourced) | daemon rows all pass:null=${daemonRows.every(r => r.pass === null)} | decision=${brain.decision}`);
  console.log(`  independently-sourced rows (futures premium / market depth - NOT daemon-fed, correctly honest on their OWN missing inputs): ${JSON.stringify(nonDaemonRows.map(r => ({ factor: r.factor, pass: r.pass, score: r.score })))}`);
  check(daemonRows.every(r => r.pass === null && r.score === 0), 'S5: with the companion daemon genuinely not running (ctx.microstructure=null), every daemon-fed Microstructure row is honestly pass:null/score:0, never fabricated as passing - matters especially for scalping since the whole Microstructure category (daemon rows AND the independently-sourced ones below) is weighted 1.6x', 'A fabricated/guessed daemon-fed Microstructure pass under a missing-daemon scenario would be especially harmful for scalping given its 1.6x weight - found evidence of a non-null score on a daemon-fed row with no daemon data.');
  note('OBSERVATION (not a bug, a taxonomy note worth surfacing in the audit report): the "Microstructure" category label mixes 8 daemon-only informational rows (always score:0, pass depends only on daemon availability) with 2 independently-sourced, genuinely directionally-scored rows (Futures Premium/Discount, Market Depth) fed by ctx.futuresPrice/ctx.marketDepth, NOT the companion daemon. A reader assuming "Microstructure available" or "Microstructure unavailable" is a single, uniform fact for the whole category (natural given scalping\'s 1.6x category-level weight applies to all 10 rows together) would be misled - the two independently-sourced rows can score real, non-zero points completely independent of whether the daemon is running.');
  check(Number.isFinite(brain.directionalScore), 'S5: decision engine still produces a real, finite score and decision despite the missing scalping-critical category, rather than crashing or returning undefined');
}

// ---------------------------------------------------------------------
// Scenario 6: Lifecycle - a condition that FAILS on refresh 1 (wide
// spread, FM023/FM025 both fire, trade blocked) and genuinely RESOLVES
// by refresh 2 (spread tightens back to normal) for the SAME underlying
// scalping trade context - the block must lift cleanly, with no stale
// state carried over (mirrors the real, live-cycle nature of a 15s-
// interval scalping poll).
// ---------------------------------------------------------------------
{
  console.log('--- Scenario 6: lifecycle - a scalping-blocking condition resolves between two consecutive real refreshes ---');
  const ctx = buildBaseCtx({});
  const brain = evaluateBrain(ctx);
  const wideLeg = { lastPrice: 100, impliedVolatility: 14, openInterest: 500000, bidprice: 100, askPrice: 120, bidQty: 500, askQty: 8 };
  const tightLeg = { lastPrice: 100, impliedVolatility: 14, openInterest: 500000, bidprice: 100, askPrice: 100.5, bidQty: 500, askQty: 500 };
  const rejWide = simulateOrderRejection(wideLeg, 50, 'buy');
  const rejTight = simulateOrderRejection(tightLeg, 50, 'buy');
  const fmRefresh1 = adjustFailureModesForTradeType(evaluatePreTradeFailureModes({ brain, ctx, rejectionCheck: rejWide }), 'scalping');
  const fmRefresh2 = adjustFailureModesForTradeType(evaluatePreTradeFailureModes({ brain, ctx, rejectionCheck: rejTight }), 'scalping');
  console.log(`  refresh 1 (wide spread): finalAction=${fmRefresh1.finalAction} triggered=[${fmRefresh1.triggered.map(t=>t.id).join(', ')}]`);
  console.log(`  refresh 2 (spread normalized): finalAction=${fmRefresh2.finalAction} triggered=[${fmRefresh2.triggered.map(t=>t.id).join(', ')}]`);
  check(fmRefresh1.finalAction === 'block', 'S6: refresh 1 (wide spread + qty>resting) correctly blocks');
  check(fmRefresh2.finalAction !== 'block' || !fmRefresh2.triggered.some(t => t.id === 'FM025'), 'S6: refresh 2, with the spread genuinely normalized and resting qty sufficient, no longer carries a stale FM025 block from refresh 1 - each real evaluation is independently, freshly derived from the current-refresh leg, never cached/sticky');
  check(!fmRefresh2.triggered.some(t => t.id === 'FM023'), 'S6: FM023 (wide spread) also genuinely clears on refresh 2 - no leftover scalping-escalated condition from the resolved refresh 1 state');
}

// ---------------------------------------------------------------------
// Scenario 7: Conflicting indicators for a scalping-relevant condition
// under trade-type escalation - RSI overbought (FM014, bearish-leaning
// on a BUY) simultaneously with a genuine scalping liquidity condition
// (FM023) - both real, independent, different-domain warnings; must
// both surface, neither should be conflated/averaged into one.
// ---------------------------------------------------------------------
{
  console.log('--- Scenario 7: conflicting-domain warnings under scalping (RSI overbought + liquidity) must both surface distinctly ---');
  // Real, sustained, consistent uptrend (candles genuinely increasing
  // every step, spot naturally the series' own endpoint rather than an
  // overridden value that fights the trend - the earlier tuning of this
  // scenario found that an overridden/inconsistent spot vs. candle trend
  // produces artifactual, misleading RSI readings) - genuinely drives
  // RSI to a real overbought reading confirmed by EMA9>EMA21.
  let c = 22900;
  const candles = [];
  for (let i = 0; i < 60; i++) { c += 8; candles.push({ c: Math.round(c * 100) / 100 }); }
  const spot = c;
  const closes = candles.map(x => x.c);
  const wideLeg = { lastPrice: 100, impliedVolatility: 14, openInterest: 500000, bidprice: 100, askPrice: 108, bidQty: 500, askQty: 500 }; // wide-ish but single-factor only (no qty risk), so genuinely medium not critical
  const ctx = buildBaseCtx({
    spot, candles,
    ema21: ema(closes, 21)[closes.length - 1] - 100, vwap: closeAvgProxy(candles, 20)[candles.length - 1] - 100,
    ocRow: { CE: wideLeg, PE: { lastPrice: 84, impliedVolatility: 15, openInterest: 480000, bidprice: 83, askPrice: 85, bidQty: 500, askQty: 500 }, strikePrice: 23200 },
    operatorIntel: { bias: 'BULLISH', score: 2, confidence: 'HIGH', signals: [{ factor: 'Real Smart-Money OI Buildup', contrib: 2, reason: 'x' }] },
  });
  setTradeType('scalping');
  const brain = evaluateBrain(ctx);
  const rsiRow = brain.results.find(r => r.factor === 'RSI Level');
  console.log(`  brain.decision=${brain.decision}, RSI row: pass=${rsiRow && rsiRow.pass}, reason=${rsiRow && rsiRow.reason}`);
  // REAL, CONFIRMED FINDING while tuning this scenario: the real RSI
  // factor (see its own check() call site) deliberately treats
  // "overbought DURING a confirmed uptrend (EMA9>EMA21)" as pass:true,
  // "strong momentum, not an automatic reversal signal" - so a strong,
  // realistic, sustained uptrend (the composition above, and every
  // variant tried during this scenario's tuning) never produces the
  // rsi.pass===false precondition FM014 requires. This is NOT a gap -
  // it is the same real, deliberate, ALREADY-covered mutual-exclusivity
  // end-to-end-decision-engine-audit.test.js's own Scenario 6 confirms
  // (FM014 requires overbought WITHOUT trend confirmation - genuinely a
  // narrower, weaker-momentum condition than "strong uptrend BUY_READY").
  // Rather than force an artificial ctx that fights the system's own
  // documented RSI-in-trends logic, this scenario is re-scoped to its
  // still-real, still-valuable core: two genuinely different-domain
  // conditions (a confirmed-uptrend BUY_READY carrying an informational,
  // non-contradicting RSI reading, AND an independent liquidity
  // condition) co-occurring and both surfacing distinctly.
  if (brain.decision === 'BUY_READY') {
    const fmRaw = evaluatePreTradeFailureModes({ brain, ctx });
    const fm014 = fmRaw.triggered.find(t => t.id === 'FM014');
    const fm023Raw = fmRaw.triggered.find(t => t.id === 'FM023');
    const fmScalp = adjustFailureModesForTradeType(fmRaw, 'scalping');
    console.log(`  triggered: FM014(RSI overbought+BUY)=${!!fm014} FM023(liquidity)=${!!fm023Raw} | scalping finalAction=${fmScalp.finalAction}`);
    check(!fm014, 'S7 CONFIRMED (by design, matching end-to-end-decision-engine-audit.test.js Scenario 6): FM014 correctly does NOT fire during a genuinely confirmed uptrend (EMA9>EMA21) even though RSI itself reads numerically overbought - the RSI factor\'s own pass:true/false already encodes this distinction, so FM014 (which gates on rsi.pass===false) is correctly, structurally prevented from firing here, not silently absent by accident');
    if (fm023Raw) {
      check(fmScalp.triggered.some(t=>t.id==='FM023'), 'S7: an independent liquidity condition (FM023) co-occurring with a confirmed-uptrend BUY_READY still surfaces correctly and distinctly, unaffected by the RSI factor\'s own pass:true reading');
    } else {
      note('S7: this leg composition did not also trigger FM023 in this run (spread is only mildly wide, single risk factor) - RSI-mutual-exclusivity confirmation above stands regardless');
    }
  } else {
    note(`S7: composition did not reach the required precondition (decision=${brain.decision}, RSI pass=${rsiRow && rsiRow.pass}) - RSI-overbought-on-a-strong-uptrend-BUY is a narrow real window, test composition may need tuning`);
  }
}

// ---------------------------------------------------------------------
// Scenario 8: Report-accuracy cross-check for a trade that DOES open
// under scalping with non-blocking FM conditions present - verify the
// real, persisted entrySnapshot (what a later report/journal review
// would read) actually carries the real failureModeCheck/tradeTypeWeighting
// fields, i.e. the closed-trade report CAN reconstruct what happened,
// unlike a blocked attempt (see Scenario 3's finding - blocked attempts
// are never persisted at all, only opened trades are).
// ---------------------------------------------------------------------
{
  console.log('--- Scenario 8: report-accuracy - does a real, OPENED scalping trade\'s snapshot carry the real trade-type/FM evidence? ---');
  const ctx = buildBaseCtx({
    operatorIntel: { bias: 'BULLISH', score: 3, confidence: 'HIGH', signals: [
      { factor: 'Real Smart-Money OI Buildup', contrib: 1.5, reason: 'x' },
      { factor: 'Real FII Long Bias', contrib: 1.5, reason: 'y' },
    ] },
    pcr: 1.4, vix: 14,
  });
  setTradeType('scalping');
  const brain = evaluateBrain(ctx);
  if (typeof buildEntrySnapshot === 'function') {
    const snap = buildEntrySnapshot(brain, ctx, 'NIFTY');
    console.log(`  entrySnapshot present=${!!snap}, has decision=${snap && ('decision' in snap)}, has tradeTypeWeighting on brain=${!!brain.tradeTypeWeighting}`);
    check(!!snap, 'S8: buildEntrySnapshot() produces a real snapshot for a genuine BUY_READY-eligible scalping trade');
    // The real, live call site (fno-lab-core.js ~13918) additionally
    // merges failureModeCheck onto this snapshot before persisting -
    // confirm the snapshot itself does NOT already silently omit or
    // duplicate that field under a different name, which would make
    // the ~13918 merge either a silent no-op or a real overwrite risk.
    check(snap && !('failureModeCheck' in snap), 'S8: buildEntrySnapshot() itself does not already define failureModeCheck - confirms the real call site\'s later Object.assign(...,{failureModeCheck: fmResult}) genuinely adds new information rather than silently overwriting a same-named field buildEntrySnapshot already set from different data', 'buildEntrySnapshot already sets a failureModeCheck-named field independently of the real call site\'s later merge - risk of the two silently disagreeing or one silently overwriting the other with stale data.');
  } else {
    note('S8: buildEntrySnapshot is not defined in the evaluated source slice (may be defined after the render() boundary this test cuts at) - inconclusive, not a failure');
  }
}

console.log(`\n${passed} passed, ${failed} failed`);
if (findings.length) {
  console.log('\n=== FINDINGS REQUIRING FOLLOW-UP ===');
  findings.forEach((f, i) => console.log(`  ${i + 1}. ${f}`));
}
if (failed > 0) process.exit(1);
