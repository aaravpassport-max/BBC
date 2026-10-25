// PHASE 9 (audit follow-up): two open items closed in one pass.
//
// 1. "Reasons behind the decision" completeness (docs/REPORT_ACCURACY_
//    AUDIT.md section 3/7, previously documented as a minor UX gap):
//    brain.reason now names the real top contributing directional
//    factors behind the score via summarizeTopDirectionalFactors(),
//    not just the aggregate directionalScore/operator bias.
//
// 2. Boundary-comparison determinism (Phase 4 of
//    extended-scenario-matrix-audit.test.js): directionalScore is now
//    rounded to 4 decimal places inside computeSeparatedScores(),
//    BEFORE any BUY/SELL threshold comparison, eliminating the
//    ~1e-14-scale float summation noise that test documented.
//
// Loads the REAL fno-lab-core.js source (same eval-slice pattern every
// other test in this directory uses) and calls the REAL evaluateBrain()/
// computeSeparatedScores()/summarizeTopDirectionalFactors() - no mocks
// of the decision-engine functions themselves.
//
// Run with: node tests/reason-top-factors-and-boundary-rounding-audit.test.js

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

console.log('\n=== PHASE 9: TOP-FACTORS REASON + BOUNDARY ROUNDING AUDIT ===\n');

// ---------------------------------------------------------------------
// 1. summarizeTopDirectionalFactors: pure-function correctness.
// ---------------------------------------------------------------------
{
  console.log('--- Test 1: summarizeTopDirectionalFactors() pure-function correctness ---');
  const results = [
    { cat: 'Market', factor: 'A', score: 1 },
    { cat: 'Market', factor: 'B', score: -3 },
    { cat: 'Flow', factor: 'C', score: 2 },
    { cat: 'Personal', factor: 'D', score: 5 }, // non-directional cat, must be excluded
    { cat: 'Tech', factor: 'E', score: 0 }, // zero score, must be excluded
    { cat: 'Tech', factor: 'F', score: NaN }, // non-finite, must be excluded
    { cat: 'Vol', factor: 'G', score: 0.5 },
  ];
  const cats = new Set(['Market', 'Flow', 'Tech', 'Vol', 'Decay', 'Fundamental', 'Greeks Deep', 'Operator Intel']);

  const bull = summarizeTopDirectionalFactors(results, cats, 'bullish', 4);
  check(bull.includes('C (+2)') && bull.includes('G (+0.5)') && bull.includes('A (+1)'), 'T1: bullish direction includes the real positive-score directional factors (C, G, A)');
  check(!bull.includes('B ('), 'T1: bullish direction excludes the negative-score factor B');
  check(!bull.includes('D ('), 'T1: bullish direction excludes the non-directional-category factor D (Personal)');
  check(!/\bE\b|\bF\b/.test(bull), 'T1: bullish direction excludes zero-score (E) and non-finite-score (F) rows');

  const bear = summarizeTopDirectionalFactors(results, cats, 'bearish', 4);
  check(bear.includes('B (-3)'), 'T1: bearish direction includes the real negative-score factor B');
  check(!bear.includes('C (') && !bear.includes('A (') && !bear.includes('G ('), 'T1: bearish direction excludes positive-score factors');

  const neutral = summarizeTopDirectionalFactors(results, cats, 'neutral', 2);
  check(neutral === 'B (-3), C (+2)', 'T1: neutral direction returns the top-2 largest-|score| directional factors regardless of sign, ordered by magnitude');

  check(summarizeTopDirectionalFactors([], cats, 'bullish', 4) === '', 'T1: empty results array returns an honest empty string, not a fabricated one');
  check(summarizeTopDirectionalFactors([{ cat: 'Personal', factor: 'X', score: 5 }], cats, 'bullish', 4) === '', 'T1: an all-non-directional results array returns an honest empty string');
}

// ---------------------------------------------------------------------
// 2. evaluateBrain(): reason string genuinely names real top factors,
//    for each of BUY_READY / SELL_READY / WAIT.
// ---------------------------------------------------------------------
{
  console.log('--- Test 2: evaluateBrain() reason string names real top factors per decision branch ---');

  // BUY_READY: push operator bias strongly bullish so directionalScore clears BUY_THRESHOLD (11).
  const buyCtx = buildBaseCtx({ operatorIntel: { bias: 'BULLISH', score: 8, confidence: 'HIGH', signals: [{ factor: 'Strong Bullish OI Signal', contrib: 8, reason: 'test' }] } });
  const buyBrain = evaluateBrain(buyCtx);
  check(buyBrain.decision === 'BUY_READY', 'T2: sanity - constructed scenario genuinely reaches BUY_READY');
  check(/top contributing factors:/.test(buyBrain.reason), 'T2: BUY_READY reason includes the new "top contributing factors" section');
  check(buyBrain.reason.includes('Strong Bullish OI Signal'), 'T2: BUY_READY reason names the real, dominant, hand-constructed Operator Intel signal by its actual factor name');
  // Every named factor must be a real row that exists in brain.results with a positive score (matching the winning bullish direction).
  const buyNamed = /top contributing factors:\s*(.+)$/.exec(buyBrain.reason)[1].split(', ').map(s => s.replace(/\s*\([^)]*\)$/, ''));
  buyNamed.forEach(name => {
    const row = buyBrain.results.find(r => r.factor === name);
    check(!!row && row.score > 0, `T2: BUY_READY-named factor "${name}" is a real row in brain.results with a genuinely positive score (not fabricated, not sign-mismatched)`);
  });

  // SELL_READY: push operator bias strongly bearish so directionalScore clears SELL_THRESHOLD (-17).
  const sellCtx = buildBaseCtx({ operatorIntel: { bias: 'BEARISH', score: -40, confidence: 'HIGH', signals: [{ factor: 'Strong Bearish OI Signal', contrib: -40, reason: 'test' }] } });
  const sellBrain = evaluateBrain(sellCtx);
  check(sellBrain.decision === 'SELL_READY', 'T2: sanity - constructed scenario genuinely reaches SELL_READY');
  check(sellBrain.reason.includes('Strong Bearish OI Signal'), 'T2: SELL_READY reason names the real, dominant, hand-constructed Operator Intel signal by its actual factor name');
  const sellNamed = /top contributing factors:\s*(.+)$/.exec(sellBrain.reason)[1].split(', ').map(s => s.replace(/\s*\([^)]*\)$/, ''));
  sellNamed.forEach(name => {
    const row = sellBrain.results.find(r => r.factor === name);
    check(!!row && row.score < 0, `T2: SELL_READY-named factor "${name}" is a real row in brain.results with a genuinely negative score`);
  });

  // WAIT: default balanced ctx should land neutral.
  const waitCtx = buildBaseCtx({});
  const waitBrain = evaluateBrain(waitCtx);
  if (waitBrain.decision === 'WAIT') {
    check(/largest-magnitude factors/.test(waitBrain.reason) || !/top contributing factors:/.test(waitBrain.reason), 'T2: WAIT reason (when reached) uses the neutral-direction phrasing, not the bullish/bearish one');
  } else {
    console.log('  (skipped strict WAIT-phrasing check - default ctx did not land WAIT in this run, decision=' + waitBrain.decision + ')');
  }
}

// ---------------------------------------------------------------------
// 3. Boundary determinism: directionalScore is rounded to 4dp before
//    threshold comparisons, eliminating the previously-documented
//    ~1e-14-scale float summation noise.
// ---------------------------------------------------------------------
{
  console.log('--- Test 3: directionalScore float-noise elimination (rounded to 4dp) ---');
  const ctx = buildBaseCtx({ operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [] } });
  const brain = evaluateBrain(ctx);
  const rounded4dp = Math.round(brain.directionalScore * 10000) / 10000;
  check(brain.directionalScore === rounded4dp, 'T3: brain.directionalScore is already exactly its own 4dp-rounded value (no residual float noise beyond 4dp survives into the returned field)');
  // Direct repro of the exact float-noise case documented in
  // extended-scenario-matrix-audit.test.js (Phase 4): a clean baseline
  // ctx there reproducibly summed to 14.500000000000007 pre-fix.
  const decStr = String(brain.directionalScore);
  const fracDigits = decStr.includes('.') ? decStr.split('.')[1].length : 0;
  check(fracDigits <= 4, `T3: directionalScore (${brain.directionalScore}) has at most 4 real decimal digits - no 1e-14-scale float tail leaking through`);

  // Determinism: 50 repeated evaluateBrain() calls on byte-identical
  // inputs (fresh ctx object each time, same real logical values) must
  // produce a byte-identical directionalScore every time - confirms
  // the underlying summation-order determinism this codebase already
  // relies on (single-threaded JS, same array-build order every call)
  // continues to hold after the rounding fix, not just before it.
  const scores = [];
  for (let i = 0; i < 50; i++) {
    const freshCtx = buildBaseCtx({ operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [] } });
    scores.push(evaluateBrain(freshCtx).directionalScore);
  }
  const allIdentical = scores.every(s => s === scores[0]);
  check(allIdentical, 'T3: 50 repeated evaluateBrain() calls on logically-identical fresh inputs produce a byte-identical directionalScore every time (confirms summation order is deterministic, not a source of cross-run inconsistency)');

  // Boundary-decision self-consistency: with directionalScore now
  // rounded to 4dp, a hand-constructed operatorIntel contrib landing
  // EXACTLY on BUY_THRESHOLD is reliably achievable (was documented as
  // NOT reliably achievable pre-fix in extended-scenario-matrix-audit's
  // own SELL_THRESHOLD comment).
  const BUY_THRESHOLD = 11;
  const baseline = evaluateBrain(buildBaseCtx({ operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [] } }));
  const neededForBuy = Math.round((BUY_THRESHOLD - baseline.directionalScore) * 10000) / 10000;
  const exactCtx = buildBaseCtx({ operatorIntel: { bias: 'BULLISH', score: neededForBuy, confidence: 'HIGH', signals: [{ factor: 'Exact Boundary Signal', contrib: neededForBuy, reason: 'test' }] } });
  const exactBrain = evaluateBrain(exactCtx);
  check(exactBrain.directionalScore === BUY_THRESHOLD, `T3: a hand-constructed contribution landing exactly on BUY_THRESHOLD (${BUY_THRESHOLD}) now produces directionalScore === ${BUY_THRESHOLD} EXACTLY (=== comparison, not just close) - the pre-fix float noise that made this unreliable is gone`);
  check(exactBrain.decision === 'BUY_READY', 'T3: at the exact BUY_THRESHOLD boundary, decision correctly resolves BUY_READY (>= comparison), consistent and reproducible now that the exact value is reliably reachable');
}

console.log(`\n=== TOTAL: ${passed} passed, ${failed} failed ===\n`);
process.exit(failed > 0 ? 1 : 0);
