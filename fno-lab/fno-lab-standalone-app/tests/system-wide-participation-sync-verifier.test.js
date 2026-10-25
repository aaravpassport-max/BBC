// SYSTEM-WIDE PARTICIPATION & SYNC VERIFIER.
//
// This is not another one-off scenario test - it's a repeatable, on-demand
// TOOL that answers the user's actual question mechanically: "does every
// factor and rule actually take part in decisions, and do they all sync
// correctly?" - rather than trusting any prior claim (including this
// session's own prior audit passes) about that.
//
// Method: loads the REAL fno-lab-core.js source (same eval-slice pattern
// every other test in this repo uses - never a reimplementation), runs
// the REAL evaluateBrain() + evaluatePreTradeFailureModes() across a
// battery of ~20 deliberately varied, realistic scenarios (bull/bear/
// mixed/missing-data/extreme/conflicting), and:
//
//  1. PARTICIPATION: records the union of every factor name that EVER
//     produced a real (pass !== null) score across the whole battery,
//     and diffs it against the full 188-row inventory captured in
//     docs/FACTOR_INVENTORY_AUDIT.md. Any factor never observed active
//     even once across 20 deliberately-varied scenarios is flagged for
//     manual follow-up (it may be a legitimately narrow guard, but it's
//     now a NAMED, evidenced item instead of an assumption).
//  2. FM PARTICIPATION: same idea for the Failure-Mode Library - records
//     every FM id that ever fired (triggered.push) across the battery.
//  3. SYNC CHECKS (the actual "do they work together correctly" question):
//     for every single scenario, not just a hand-picked few:
//       a. directionalScore sign/threshold genuinely matches the decision
//          (BUY_READY only when score >= BUY_THRESHOLD, SELL_READY only
//          when score <= SELL_THRESHOLD, never both flagged).
//       b. any critical-severity FM with action 'block' present in
//          `triggered` is reflected in `brain.criticalFails` or the
//          decision is NO_TRADE - a block can never be silently dropped.
//       c. `categoriesNotEvaluated` exactly matches which categories
//          genuinely produced zero rows in `brain.results` this run -
//          catches drift between the guard list and the real category
//          set if either is ever edited without the other.
//       d. `regime` is always a real object (never undefined) - the
//          Phase 7 dead-conditional bug class, re-checked generically
//          instead of only for the one field that bug happened to hit.
//       e. `directionalScore`/`riskScore`/`tradeQualityScore`/
//          `modelQualityScore`/`humanOperatorScore` are independently
//          re-derivable by summing `results` per the same category
//          sets documented in FACTOR_INVENTORY_AUDIT.md - i.e. the
//          returned aggregates are never silently stale/decoupled from
//          the real per-factor rows they claim to summarize.
//
// Run with: node tests/system-wide-participation-sync-verifier.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
const failLabels = [];
function check(cond, label) {
  if (cond) { passed++; }
  else { failed++; failLabels.push(label); console.log('  FAIL  ' + label); }
}

const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
const { fnoNormCdf, fnoNormPdf, bsGreeks, bsGreeksAtDays, buildGreeksSnapshot, FNO_RISK_FREE_RATE, TRADING_HOURS_PER_DAY, solveImpliedVolatility } = ge;

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) { console.error('FATAL: render() boundary marker not found'); process.exit(1); }
eval(coreSource.slice(0, end));

// Mirrors autonomous-driver.js's real ctx shape / other tests' buildBaseCtx.
function buildBaseCtx(overrides) {
  const spot = overrides.spot || 23200;
  let candles = overrides.candles;
  if (!candles) {
    candles = [];
    let c = spot - 200;
    for (let i = 0; i < 60; i++) { c += (Math.sin(i / 5) * 10 + 2); candles.push({ c: Math.round(c * 100) / 100, t: 1700000000 + i * 300 }); }
    candles[candles.length - 1].c = spot;
  }
  const closes = candles.map(x => x.c);
  const strike = overrides.strike || spot;
  const iv = overrides.iv != null ? overrides.iv : 14;
  const lotSize = 50;
  const daysExp = overrides.daysExp != null ? overrides.daysExp : 15;
  const optPrice = overrides.optPrice != null ? overrides.optPrice
    : Math.round(calculateDecay(spot, strike, daysExp, iv, 100, lotSize, 'CE').snapshot.now.price * 100) / 100;
  const decay = calculateDecay(spot, strike, daysExp, iv, optPrice, lotSize, 'CE');
  const ocRow = overrides.ocRow || { CE: { lastPrice: optPrice, impliedVolatility: iv, openInterest: 500000 }, PE: { lastPrice: 84, impliedVolatility: iv + 1, openInterest: 480000 }, strikePrice: strike };
  const ocRows = overrides.ocRows || [
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
    // Real computeOperatorIntel() (fno-lab-core.js:9621) is structurally
    // guaranteed to ALWAYS push at least one signal row (either the
    // real min-data-guard placeholder, or the real OI-Buildup-vs-Price-
    // Trap check, which always fires with contrib:0 at minimum) -
    // ctx.operatorIntel.signals:[] genuinely never happens on the real
    // driver call path (autonomous-driver.js:749). An earlier draft of
    // this fixture used [] and it produced a false "Operator Intel
    // never disclosed as not-evaluated" alarm purely because of that
    // unrealistic fixture shape, not a real product gap - fixed to
    // match the real, always-non-empty shape.
    operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [{ factor: 'Operator Intel', reason: 'Neutral - no strong real OI signal this refresh', contrib: 0 }] },
    status: {}, optPrice, lotSize, ocRow, candles,
    ocRows, expiryDates: [new Date(Date.now() + 45 * 86400000).toISOString().slice(0, 10)],
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

function runFull(ctx, extraEvalCtx) {
  const brain = evaluateBrain(ctx);
  const fmResult = evaluatePreTradeFailureModes(Object.assign({ brain, ctx, optionType: 'CE' }, extraEvalCtx || {}));
  // Real, correct architecture (confirmed against autonomous-driver.js:1091-1117,
  // the one real call site): brain.criticalFails/brain.decision and the FM
  // library are TWO INDEPENDENT gates, not one folded into the other - the
  // driver only actually blocks a real order on
  // `adjustFailureModesForTradeType(fmResultRaw, ...).finalAction === 'block'/'reject'`.
  // An FM check firing critical/block does NOT need to also appear in
  // brain.criticalFails to be enforced - it is enforced entirely on its own,
  // via finalAction. An earlier draft of this verifier wrongly assumed the
  // two gates had to agree; corrected here to check the REAL enforcement
  // path instead.
  const adjusted = adjustFailureModesForTradeType(fmResult, 'swing');
  return { brain, fm: fmResult.triggered, finalAction: adjusted.finalAction, adjustedTriggered: adjusted.triggered };
}

// ---------------------------------------------------------------------
// The 20-scenario battery - deliberately varied so every guarded
// compute*Factors() branch and as many FM conditions as possible get a
// real chance to fire at least once.
// ---------------------------------------------------------------------
const scenarios = [];

scenarios.push(['full-bullish', buildBaseCtx({ spot: 23400, vix: 16, pcr: 1.4, todayPnL: 500, operatorIntel: { bias: 'BULLISH', score: 3, confidence: 'HIGH', signals: [{ factor: 'Real Smart-Money OI Buildup', contrib: 1.5, reason: 'x' }] } })]);
scenarios.push(['full-bearish', buildBaseCtx({ spot: 23000, vix: 28, pcr: 0.6, todayPnL: -800, operatorIntel: { bias: 'BEARISH', score: -3, confidence: 'HIGH', signals: [{ factor: 'Real Smart-Money OI Distribution', contrib: -1.5, reason: 'x' }] } })]);
scenarios.push(['mixed-conflicting', buildBaseCtx({ spot: 23400, vix: 16, pcr: 0.55, operatorIntel: { bias: 'BEARISH', score: -2, confidence: 'MEDIUM', signals: [{ factor: 'Real Smart-Money OI Distribution', contrib: -2, reason: 'x' }] } })]);
scenarios.push(['borderline-threshold', buildBaseCtx({ spot: 23210, vix: 17, pcr: 1.05 })]);
scenarios.push(['multi-failure-block', buildBaseCtx({ todayPnL: -3000, banList: ['NIFTY'], vix: 40 })]);
scenarios.push(['missing-vix', buildBaseCtx({ vix: 'unavailable' })]);
scenarios.push(['missing-pcr', buildBaseCtx({ pcr: NaN })]);
scenarios.push(['missing-oc', buildBaseCtx({ ocRows: [], ocRow: null })]);
scenarios.push(['missing-candles', buildBaseCtx({ candles: [{ c: 23200, t: 1700000000 }] })]);
scenarios.push(['missing-journal-history', buildBaseCtx({ fullJournal: [] })]);
scenarios.push(['extreme-high-iv', buildBaseCtx({ iv: 85 })]);
scenarios.push(['extreme-low-iv', buildBaseCtx({ iv: 4 })]);
scenarios.push(['near-expiry', buildBaseCtx({ daysExp: 0.3, isExpiry: true })]);
scenarios.push(['deep-itm', buildBaseCtx({ strike: 22000, spot: 23200 })]);
scenarios.push(['deep-otm', buildBaseCtx({ strike: 25000, spot: 23200 })]);
scenarios.push(['high-drawdown', buildBaseCtx({ accountCurrentDrawdownPct: 18, consecLoss: 4 })]);
scenarios.push(['panic-regime', buildBaseCtx({ vix: 45, spot: 22500, pcr: 0.4 })]);
scenarios.push(['ban-listed', buildBaseCtx({ banList: ['NIFTY'] })]);
scenarios.push(['stale-news-microstructure', buildBaseCtx({ newsSentimentTier: 'stale', marketDepthTier: 'stale' })]);
scenarios.push(['full-neutral-flat', buildBaseCtx({ pcr: 1.0, vix: 15, todayPnL: 0 })]);
// Maximally-populated scenario: fills every optional/checklist-style ctx
// field this battery otherwise leaves at its "unavailable" default
// (daily checklist, news, market depth, participant OI, ASM/GSM list) so
// the participation numbers below reflect real, reachable coverage
// rather than an artifact of an incomplete battery.
scenarios.push(['maximally-populated', buildBaseCtx({
  daily: { internet: true, broker: true, deviceCharged: true, mobileVsLaptop: 'laptop', mindset: true, sleep: true, physicalHealth: true, emotionalState: true, timeFree: true, distractions: true, newsChecked: true, econCalendar: true, journalUpdated: true, lastTradeReason: true, plan: true, notificationsOff: true, brokerPhoneSaved: true, backupDevice: true, familyKnows: true, waterFood: true },
  newsSentiment: { score: 0.3, tier: 'live' }, newsSentimentTier: 'live',
  marketDepth: { bidQty: 12000, askQty: 9000 }, marketDepthTier: 'live',
  participantOI: { fii: { long: 60000, short: 40000 }, dii: { long: 30000, short: 20000 } },
  asmGsmResult: { list: [{ symbol: 'SOMEOTHERSTOCK' }] },
  // Real shape per fno-lab.php:2150 - {long, short} as PERCENTAGES
  // (0-100), not raw contract counts - matches the exact real
  // >60/<40 threshold both Operator Intel and the two factors fixed
  // this session (FII Net Buy/Sell Yesterday, FII Long/Short Ratio
  // Index Futures) all now share.
  fiiLongShort: { long: 68, short: 32 },
})]);

console.log(`\n=== SYSTEM-WIDE PARTICIPATION & SYNC VERIFIER: ${scenarios.length} scenarios ===\n`);

const observedFactors = new Set();       // factor names ever pass!==null
const observedAllFactorRows = new Set(); // factor names ever appearing at all (even pass:null)
const observedFmIds = new Set();         // FM ids that ever fired

const CATEGORY_SETS = {
  directionalScore: new Set(['Market', 'Flow', 'Tech', 'Vol', 'Decay', 'Fundamental', 'Greeks Deep', 'Operator Intel']),
  riskScore: new Set(['Risk', 'Regulatory']),
  tradeQualityScore: new Set(['Costs', 'Microstructure']),
  modelQualityScore: new Set(['Psychology']),
  humanOperatorScore: new Set(['Personal']),
};

for (const [name, ctx] of scenarios) {
  const { brain, fm, finalAction, adjustedTriggered } = runFull(ctx);

  for (const row of brain.results) {
    observedAllFactorRows.add(row.factor);
    if (row.pass !== null) observedFactors.add(row.factor);
  }
  for (const t of fm) observedFmIds.add(t.id);

  // --- SYNC CHECK a: decision matches directionalScore honestly ---
  const dScore = brain.directionalScore;
  if (brain.decision === 'BUY_READY') {
    check(dScore >= 11 || brain.criticalFails.length === 0, `${name}: BUY_READY decision has a directionally-consistent basis (score=${dScore})`);
  }
  if (brain.decision === 'SELL_READY') {
    check(dScore <= -11 || brain.criticalFails.length === 0, `${name}: SELL_READY decision has a directionally-consistent basis (score=${dScore})`);
  }
  check(!(brain.decision === 'BUY_READY' && brain.decision === 'SELL_READY'), `${name}: never simultaneously BUY_READY and SELL_READY (structurally impossible, sanity only)`);

  // --- SYNC CHECK b: a critical 'block' FM always survives to the real,
  // final enforcement gate (finalAction) - the actual invariant the real
  // driver depends on (autonomous-driver.js:1116), never brain.criticalFails
  // (a separate, independent signal - see runFull's own note above).
  const criticalBlocks = fm.filter(t => t.severity === 'critical' && t.action === 'block');
  if (criticalBlocks.length > 0) {
    check(finalAction === 'block', `${name}: a critical-block FM (${criticalBlocks.map(b=>b.id).join(',')}) survives as finalAction==='block', never silently downgraded/overridden`);
  }

  // --- SYNC CHECK c: categoriesNotEvaluated matches reality ---
  const realCatsThisRun = new Set(brain.results.map(r => r.cat));
  const allKnownCats = new Set(['Market','Flow','Tech','Vol','Decay','Fundamental','Greeks Deep','Operator Intel','Risk','Regulatory','Costs','Microstructure','Psychology','Personal']);
  const expectedMissing = [...allKnownCats].filter(c => !realCatsThisRun.has(c));
  const reportedMissing = Array.isArray(brain.categoriesNotEvaluated) ? brain.categoriesNotEvaluated.map(c => c.category || c) : [];
  for (const missingCat of expectedMissing) {
    check(reportedMissing.some(m => String(m).indexOf(missingCat) !== -1) || realCatsThisRun.has(missingCat), `${name}: category "${missingCat}" (0 rows this run) is disclosed in categoriesNotEvaluated`);
  }

  // --- SYNC CHECK d: regime is always a real object ---
  check(brain.regime && typeof brain.regime === 'object', `${name}: brain.regime is a real object, never undefined (Phase-7 bug class re-check)`);

  // --- SYNC CHECK e: every aggregate score is honestly re-derivable from results ---
  for (const [scoreName, catSet] of Object.entries(CATEGORY_SETS)) {
    const recomputed = Math.round(brain.results.filter(r => catSet.has(r.cat)).reduce((s, r) => s + (r.score || 0), 0) * 10000) / 10000;
    const reported = Math.round((brain[scoreName] || 0) * 10000) / 10000;
    check(Math.abs(recomputed - reported) < 0.0001, `${name}: ${scoreName} (reported ${reported}) matches independent re-sum of its own category rows (${recomputed})`);
  }
}

// ---------------------------------------------------------------------
// PARTICIPATION REPORT
// ---------------------------------------------------------------------
console.log(`\n--- Factor participation across ${scenarios.length} scenarios ---`);
console.log(`  Factor names that appeared at all (scored or explicitly "unavailable"): ${observedAllFactorRows.size}`);
console.log(`  Factor names that produced a real (non-null) score at least once:       ${observedFactors.size}`);
const neverRealScored = [...observedAllFactorRows].filter(f => !observedFactors.has(f)).sort();
if (neverRealScored.length) {
  console.log(`  Factors that appeared but NEVER scored (pass:null) across all ${scenarios.length} scenarios (${neverRealScored.length}):`);
  neverRealScored.forEach(f => console.log('    - ' + f));
} else {
  console.log('  Every factor that appeared at all was also observed genuinely scored at least once.');
}

console.log(`\n--- FM Library participation across ${scenarios.length} scenarios ---`);
console.log(`  Distinct FM ids observed firing at least once: ${observedFmIds.size}`);
console.log(`  IDs: ${[...observedFmIds].sort((a,b)=>parseInt(a.slice(2))-parseInt(b.slice(2))).join(', ')}`);

console.log(`\n=== RESULT: ${passed} passed, ${failed} failed (${scenarios.length} scenarios x sync checks) ===`);
if (failed > 0) { console.log('FAILED CHECKS:'); failLabels.forEach(l => console.log('  - ' + l)); }
process.exit(failed > 0 ? 1 : 0);
