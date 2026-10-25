// Phase 6 REPORT/BRAINLOG ACCURACY AUDIT.
//
// Reuses the exact real eval-slice + ctx-builder pattern already proven
// in tests/end-to-end-decision-engine-audit.test.js (same repo, same
// real evaluateBrain()/evaluatePreTradeFailureModes() call sites - not a
// reimplementation). Two real things are checked:
//
// 1. REGRESSION for the categoriesNotEvaluated fix: when a category's
//    real ctx input is missing, evaluateBrain() must now say so
//    explicitly (categoriesNotEvaluated array with a real, specific
//    reason), not stay silent about the gap.
// 2. MISMATCH HUNT: for several real scenarios, does brain.reason cite
//    the actual dominant contributor, and does the report ever claim a
//    category "passed" when it was really skipped/not-applicable?
//
// Run with: node tests/report-accuracy-audit.test.js

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

console.log('\n=== PHASE 6: REPORT/BRAINLOG ACCURACY AUDIT ===\n');

// ---------------------------------------------------------------------
// 1. categoriesNotEvaluated regression: full-data baseline has none.
// ---------------------------------------------------------------------
{
  console.log('--- Test 1: full-data baseline reports zero missing categories ---');
  const ctx = buildBaseCtx({});
  const brain = evaluateBrain(ctx);
  check(Array.isArray(brain.categoriesNotEvaluated), 'T1: categoriesNotEvaluated is a real array on the return value');
  check(brain.categoriesNotEvaluated.length === 0, 'T1: full ctx (candles+ocRows+decay.snapshot+optPrice/lotSize/spot+fullJournal all present) -> nothing reported missing');
}

// ---------------------------------------------------------------------
// 2. categoriesNotEvaluated regression: strip ctx.candles entirely.
// ---------------------------------------------------------------------
{
  console.log('--- Test 2: ctx.candles missing -> Tech/Market/Regulatory(halt) explicitly flagged, with real reasons ---');
  const ctx = buildBaseCtx({ candles: [] });
  const brain = evaluateBrain(ctx);
  const cats = brain.categoriesNotEvaluated.map(c => c.cat);
  check(cats.includes('Tech'), 'T2: Tech listed as not evaluated');
  check(cats.includes('Market'), 'T2: Market listed as not evaluated');
  check(cats.includes('Regulatory'), 'T2: Regulatory (Trading Halt) listed as not evaluated');
  const techEntry = brain.categoriesNotEvaluated.find(c => c.cat === 'Tech');
  check(!!techEntry && /candles/.test(techEntry.why), 'T2: Tech reason genuinely names ctx.candles as the missing real input, not a generic placeholder');
  // Cross-check against the actual results array: computeTechFactors()'s
  // own factors (e.g. RSI Level) must be genuinely absent - note "Price
  // vs VWAP" is a SEPARATE inline Tech-category check (guarded only on
  // ctx.spot/ctx.vwap, not ctx.candles) so it legitimately still appears
  // even with candles stripped; this test targets a real
  // computeTechFactors-only factor instead of the whole category.
  const rsiRow = brain.results.find(r => r.factor === 'RSI Level');
  check(!rsiRow, 'T2: computeTechFactors-only factor (RSI Level) truly absent from results this refresh (confirms the reported Tech gap matches reality, not just a guess)');
}

// ---------------------------------------------------------------------
// 3. categoriesNotEvaluated regression: strip ctx.ocRows entirely.
// ---------------------------------------------------------------------
{
  console.log('--- Test 3: ctx.ocRows missing -> Flow/Fundamental(rollover) explicitly flagged ---');
  const ctx = buildBaseCtx({ ocRows: null });
  const brain = evaluateBrain(ctx);
  const cats = brain.categoriesNotEvaluated.map(c => c.cat);
  check(cats.includes('Flow'), 'T3: Flow listed as not evaluated');
  check(cats.includes('Fundamental'), 'T3: Fundamental (OI Rollover) listed as not evaluated');
  // "PCR Level" is a separate inline Flow-category check (guarded only
  // on ctx.pcr, not ctx.ocRows) so it legitimately still appears; target
  // a real computeFlowFactors-only factor instead.
  const maxPainRow = brain.results.find(r => r.factor === 'Max Pain Distance');
  check(!maxPainRow, 'T3: computeFlowFactors-only factor (Max Pain Distance) truly absent from results this refresh');
}

// ---------------------------------------------------------------------
// 4. buildEntrySnapshot carries the same real list through, unmodified.
// ---------------------------------------------------------------------
{
  console.log('--- Test 4: buildEntrySnapshot threads categoriesNotEvaluated through honestly ---');
  const ctx = buildBaseCtx({ candles: [] });
  global.window = global.window || {};
  global.window.FNO_FACTORS_CATALOG = JSON.parse(fs.readFileSync(path.join(__dirname, '../assets/factors.json'), 'utf8'));
  const brain = evaluateBrain(ctx);
  const snap = buildEntrySnapshot(brain, ctx, 'NIFTY');
  check(!!brain.factorRegistry, 'T4: real factors.json catalog loaded -> factorRegistry actually built this time');
  check(!!snap, 'T4: snapshot built (factorRegistry present)');
  check(Array.isArray(snap.categoriesNotEvaluated) && snap.categoriesNotEvaluated.length === brain.categoriesNotEvaluated.length, 'T4: snapshot.categoriesNotEvaluated matches brain.categoriesNotEvaluated exactly, no silent drop');
  // Cross-check: every catalogued Tech factor should show status
  // NOT_COMPUTED in the registry (the pre-existing per-factor mechanism)
  // AND the new categoriesNotEvaluated list independently agrees Tech
  // was skipped - two independent real mechanisms, consistent with each
  // other.
  const techEntries = [...brain.factorRegistry.byId.values()].filter(e => e.cat === 'Tech');
  const allTechNotComputed = techEntries.length > 0 && techEntries.every(e => e.status === 'NOT_COMPUTED' || e.factor === 'Price vs VWAP');
  check(allTechNotComputed, 'T4: registry independently confirms every catalogued Tech factor (besides the separate inline VWAP check) is NOT_COMPUTED, agreeing with categoriesNotEvaluated');
  global.window.FNO_FACTORS_CATALOG = [];
}

// ---------------------------------------------------------------------
// 5. MISMATCH HUNT: decision reason must not claim a category "passed"
//    when it was actually skipped this refresh (checked against the
//    real results array, not guessed).
// ---------------------------------------------------------------------
{
  console.log('--- Test 5: mismatch hunt - reason text vs real triggered/skipped categories ---');
  const ctx = buildBaseCtx({ candles: [], ocRows: null });
  const brain = evaluateBrain(ctx);
  // UPDATED for the "top contributing factors" reason-string enhancement
  // (docs/REPORT_ACCURACY_AUDIT.md section 3/7 follow-up): brain.reason
  // now names the real top-magnitude directional factors behind the
  // score (see summarizeTopDirectionalFactors()), sourced from the SAME
  // `results` rows categoriesNotEvaluated/factorRegistry already agree
  // are real for this refresh. `candles:[]`/`ocRows:null` genuinely
  // disable the DEEP, candle/chain-driven compute*Factors() functions
  // (computeTechFactors/computeMarketFactors/computeVolFactors/
  // computeFlowFactors - confirmed NOT_COMPUTED via T4 above), but do
  // NOT disable the small set of always-on inline base checks that read
  // ctx.ema21/ctx.vwap/ctx.pcr directly (still real, non-empty inputs
  // here) - "NIFTY Trend vs 21 EMA" (cat Market), "Price vs VWAP" (cat
  // Tech) and "PCR Level" (cat Flow) genuinely DO compute this refresh
  // and are correctly eligible to appear as top factors; only the
  // DEEP/candle-only factor names (RSI, MACD, Bollinger, OI-buildup
  // signatures, etc.) must never be fabricated into the reason text.
  const dominantFactor = /top contributing factors:\s*([^)]+\([^)]*\)(?:,\s*[^)]+\([^)]*\))*)/.exec(brain.reason);
  const topFactorNames = dominantFactor ? dominantFactor[1] : '';
  check(!/rsi|macd|bollinger|oi buildup|oi change/i.test(topFactorNames), 'T5: reason text does not name a deep, candle/chain-only Tech/Market/Flow signal (e.g. RSI/MACD/Bollinger) when those compute*Factors() genuinely produced zero rows this refresh - only the always-on base EMA/VWAP/PCR checks (real, non-candle inputs still present) may legitimately appear');
  check(/NIFTY Trend vs 21 EMA|Price vs VWAP|PCR Level/.test(topFactorNames), 'T5: sanity - the always-on base EMA/VWAP/PCR checks (genuinely computed from real, still-present ctx.ema21/ctx.vwap/ctx.pcr, independent of candles/ocRows) DO legitimately appear as top factors, confirming the assertion above is discriminating, not vacuously true');
}

// ---------------------------------------------------------------------
// 6. MISMATCH HUNT: multi-factor BUY_READY - does the reason string
//    reflect that several factors (not just one) contributed, or does
//    it read as generic boilerplate independent of which factors fired?
// ---------------------------------------------------------------------
{
  console.log('--- Test 6: multi-contributor scenario - reason genuinely reflects the real dominant driver (operator bias), not a fabricated single-factor claim ---');
  const spot = 23300;
  const candles = [];
  let c = 22800;
  for (let i = 0; i < 60; i++) { c += 8; candles.push({ c: Math.round(c * 100) / 100 }); }
  candles[candles.length - 1].c = spot;
  const closes = candles.map(x => x.c);
  const ctx = buildBaseCtx({
    spot, vix: 16, pcr: 1.4, candles,
    ema21: ema(closes, 21)[closes.length - 1] - 100,
    vwap: vwapCalc(candles)[candles.length - 1] - 50,
    operatorIntel: { bias: 'BULLISH', score: 3, confidence: 'HIGH', signals: [
      { factor: 'Real Smart-Money OI Buildup', contrib: 1.5, reason: 'Strong CE writing unwind + PE buildup at ATM' },
      { factor: 'Real FII Long Bias', contrib: 1.5, reason: 'FII net long per real participant data' },
    ] },
  });
  const brain = evaluateBrain(ctx);
  const passingFactors = brain.results.filter(r => r.pass === true).map(r => r.factor);
  // Genuine check: reason cites the real directionalScore number and the
  // real operatorIntel.bias/confidence, both of which are aggregate,
  // multi-factor-derived values (directionalScore literally sums every
  // Directional-category row; opIntel.bias/confidence come from every
  // Operator Intel signal) - so the reason is honestly summarizing many
  // real contributors via those two real aggregates, not silently
  // crediting just one while ignoring the rest.
  check(brain.reason.includes(brain.directionalScore.toFixed(1)), 'T6: reason cites the real, aggregate directionalScore (which is the sum of every Directional-category factor row) rather than one row in isolation');
  check(brain.reason.includes(brain.operatorIntel.bias), 'T6: reason cites the real aggregate operator bias (derived from multiple Operator Intel signals), consistent with there being 2 real signals behind it');
  check(passingFactors.length > 3, `T6: sanity - scenario genuinely has multiple (${passingFactors.length}) passing factors, confirming this is a real multi-contributor case, not accidentally single-factor`);
}

console.log(`\n=== Phase 6 report-accuracy audit: ${passed} passed, ${failed} failed ===\n`);
if (failed > 0) process.exit(1);
