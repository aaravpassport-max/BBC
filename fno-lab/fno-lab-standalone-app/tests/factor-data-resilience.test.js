// Factor data resilience — missing/unavailable inputs are skipped, never scored as 0/fail.
// Run: node tests/factor-data-resilience.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0;
let failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
const { fnoNormCdf, fnoNormPdf, bsGreeks, bsGreeksAtDays, buildGreeksSnapshot, FNO_RISK_FREE_RATE, TRADING_HOURS_PER_DAY, solveImpliedVolatility } = ge;

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const tseSrc = fs.readFileSync(path.join(__dirname, '../assets/trade-setup-engine.js'), 'utf8');
const speSrc = fs.readFileSync(path.join(__dirname, '../assets/scalping-profit-engine.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) { console.error('FATAL: render() boundary not found'); process.exit(1); }
eval(coreSource.slice(0, end));
global.window = global.window || {};
global.window.FNO_FACTORS_CATALOG = JSON.parse(fs.readFileSync(path.join(__dirname, '../assets/factors.json'), 'utf8'));

const emaStart = coreSource.indexOf('function ema(data,p)');
const emaEnd = coreSource.indexOf('function vwapCalc', emaStart);
const fnoSettingsStart = coreSource.indexOf('const FNO_SETTINGS_KEY');
const fnoSettingsEnd = coreSource.indexOf('\n};\n', coreSource.indexOf('const fnoSettings = {')) + 3;

const bootSrc = [
  'var localStorage = { _d: {}, getItem(k){ return this._d[k]||null; }, setItem(k,v){ this._d[k]=String(v); }, removeItem(k){ delete this._d[k]; } };',
  'var document = { dispatchEvent(){}, getElementById(){ return null; } };',
  coreSource.slice(fnoSettingsStart, fnoSettingsEnd)
    .replace(/const FNO_SETTINGS_KEY/g, 'var FNO_SETTINGS_KEY')
    .replace(/const FNO_SETTINGS_DEFAULTS/g, 'var FNO_SETTINGS_DEFAULTS')
    .replace(/const FNO_SETTINGS_SCHEMA_KEY/g, 'var FNO_SETTINGS_SCHEMA_KEY')
    .replace(/const FNO_SETTINGS_SCHEMA_VERSION/g, 'var FNO_SETTINGS_SCHEMA_VERSION')
    .replace(/const fnoSettings/g, 'var fnoSettings'),
  coreSource.slice(emaStart, emaEnd),
  'function computeBreakoutReversalCondition(candles, rangeWindow){ if(!candles||candles.length<5) return null; const closes=candles.map(c=>c.c); const win=closes.slice(-Math.min(rangeWindow||20,closes.length)); const hi=Math.max(...win), lo=Math.min(...win); const last=closes[closes.length-1]; const range=hi-lo; const choppy=range>0&&range/(last||1)*100<0.15; return { condition: choppy?"choppy":"range_bound", rangeHigh:hi, rangeLow:lo }; }',
  'function computeMarketRegime(ctx){ return { label:"Bullish-Normal Vol", trend:"Bullish", volatility:"Normal Vol", extendedStates:{} }; }',
  'function isScalpingProfitProfileActive(){ return true; }',
  'function checkScalpingCapitalPreservation(){ return { allowed: true }; }',
  'function checkOptionChainFreshness(){ return { stale: false }; }',
  tseSrc,
  speSrc,
  'return { fnoSettings, computeScalpingProfitEngine, evaluateMarketSafety, buildSpeMarketState, getScalpingProfitSettings, checkTradeSetupEntryGate, makeTradeDecision, evaluateTradeEligibility, isTradeSetupEngineActive, buildMarketState };',
].join('\n');
const api = new Function(bootSrc)();

api.fnoSettings.set({
  scalpingProfitEngineEnabled: true,
  scalpingProfitEngineMode: 'PAPER_ONLY',
  scalpingProfitProfileEnabled: true,
  tradingTypes: { scalping: true },
  tradeSetupEngineEnabled: true,
});

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
  const ocRow = { CE: { lastPrice: optPrice, bidprice: optPrice - 0.05, askPrice: optPrice + 0.05, bidQty: 8000, askQty: 8000, impliedVolatility: iv, openInterest: 500000 }, PE: { lastPrice: 84, impliedVolatility: iv + 1, openInterest: 480000 }, strikePrice: strike };
  const ocRows = [
    { strikePrice: strike - 500, CE: { openInterest: 400000, impliedVolatility: 15 }, PE: { openInterest: 420000, impliedVolatility: 19 } },
    { strikePrice: strike, CE: { openInterest: 500000, impliedVolatility: 14 }, PE: { openInterest: 480000, impliedVolatility: 15 } },
    { strikePrice: strike + 500, CE: { openInterest: 380000, impliedVolatility: 13 }, PE: { openInterest: 360000, impliedVolatility: 14 } },
  ];
  return Object.assign({
    spot, pcr: 1.0, totalPE: 480000, totalCE: 500000,
    vix: 15, time: '10:30', day: 'Monday', isExpiry: false,
    banList: [], banListSource: 'live', fiiLongShort: null,
    todayPnL: 0, tradesToday: 0, consecLoss: 0, fullJournal: [],
    accountAvailableCapital: 100000, accountCurrentDrawdownPct: 0, accountCurrentBalance: 100000,
    daily: {}, decay: { ...decay, days: daysExp },
    operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [] },
    status: {}, optPrice, lotSize, ocRow, candles, ocRows, expiryDates: ['21-Aug-2026'],
    correlationSym: 'BANKNIFTY', correlationCloses: [], futuresPrice: spot + 20,
    targetPrice: null, slPrice: null,
    ema21: ema(closes, 21)[closes.length - 1],
    vwap: vwapCalc(candles)[candles.length - 1],
    newsSentiment: null, newsSentimentTier: 'unavailable',
    marketDepth: null, marketDepthTier: 'unavailable',
    participantOI: null, asmGsmResult: null, microstructure: null,
    symbol: 'NIFTY', ocFetchedAt: Date.now(),
  }, overrides || {});
}

console.log('\n=== FACTOR DATA RESILIENCE ===\n');

{
  console.log('--- Brain: factorDataAvailability + available-only decision score ---');
  const ctx = buildBaseCtx({});
  const brain = evaluateBrain(ctx);
  check(!!brain.factorDataAvailability, 'factorDataAvailability present when registry builds');
  check(typeof brain.directionalScoreAvailableOnly === 'number', 'directionalScoreAvailableOnly is numeric');
  check(brain.reason.includes('available factors only'), 'reason cites available-factors-only score');
  check(Array.isArray(brain.categoriesNotEvaluated), 'categoriesNotEvaluated array present');
}

{
  console.log('--- Brain: missing candles skips categories, does not silent-fail ---');
  const ctx = buildBaseCtx({ candles: [] });
  const brain = evaluateBrain(ctx);
  const cats = brain.categoriesNotEvaluated.map(c => c.cat);
  check(cats.includes('Tech'), 'Tech flagged when candles missing');
  check(brain.factorDataAvailability == null || brain.factorDataAvailability.missingDataImpact !== 'none' || cats.length > 0,
    'missing-data impact recorded or categories flagged');
}

{
  console.log('--- SPE: missing bid/ask skips spread (not fake pass) ---');
  const closes = [];
  let p = 24000;
  for (let i = 0; i < 30; i++) { p += 2; closes.push(p); }
  const ctx = { spot: p, candles: closes.map((c, i) => ({ c, t: i })), ocRow: { CE: { lastPrice: 100 } }, ocFetchedAt: Date.now() };
  const ms = api.buildSpeMarketState(ctx, { decision: 'BUY_READY' });
  const safety = api.evaluateMarketSafety(ms, ctx, api.getScalpingProfitSettings());
  check(safety.spreadSkipped === true, 'spread check skipped when bid/ask missing');
  check(safety.spreadOk === null, 'spreadOk not forced true when data missing');
  check(safety.safe === true, 'missing spread does not auto-block market safety');
  check((safety.unavailableInputs || []).includes('bidAsk'), 'bidAsk recorded unavailable');
}

{
  console.log('--- SPE: missing candles skips safety layer ---');
  const ctx = { spot: 24000, candles: [], ocRow: { CE: { lastPrice: 100, bidprice: 99, askPrice: 100.1, bidQty: 1000, askQty: 1000 } } };
  const ms = api.buildSpeMarketState(ctx, null);
  const safety = api.evaluateMarketSafety(ms, ctx, api.getScalpingProfitSettings());
  check(safety.skippedLayers.includes('marketSafety'), 'market safety skipped without candles');
  check(safety.blockers.length === 0, 'no blockers when layer skipped');
}

{
  console.log('--- SPE: factorDataAvailability on engine output ---');
  const closes = [];
  let p = 24000;
  for (let i = 0; i < 30; i++) { p += 3; closes.push(p); }
  const ctx = { spot: p, candles: closes.map((c, i) => ({ c, t: i })), ocRow: { CE: { lastPrice: 100 } }, ocFetchedAt: Date.now() };
  const spe = api.computeScalpingProfitEngine(ctx, { decision: 'BUY_READY' }, {});
  check(!!spe.factorDataAvailability, 'SPE returns factorDataAvailability');
  check(Array.isArray(spe.factorDataAvailability.unavailableInputs), 'SPE unavailableInputs listed');
}

{
  console.log('--- TSE: missing candles bypasses (does not BLOCK) ---');
  const ctx = { spot: 24000, candles: [] };
  const elig = api.evaluateTradeEligibility(ctx, { decision: 'BUY_READY' });
  check(elig.action === 'BYPASS', 'TSE bypasses when candles missing');
  check(elig.bypass === true, 'bypass flag set');
  check(!((elig.blockers || []).includes('Insufficient candles')), 'no hard block on missing candles');
  const gate = api.checkTradeSetupEntryGate({ decision: 'BUY_READY', tradeSetupDecision: api.makeTradeDecision(ctx, { decision: 'BUY_READY' }) }, ctx, 'CE');
  check(gate.allowed === true, 'entry gate allows when TSE bypassed');
}

{
  console.log('--- UI hook: factorDataAvailabilityBox in standalone-app.php ---');
  const php = fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8');
  check(/factorDataAvailabilityBox/.test(php), 'factor data availability panel in UI');
  check(/renderFactorDataAvailabilityPanel/.test(coreSource), 'panel renderer wired in core');
}

console.log(`\nResults: ${passed} passed, ${failed} failed\n`);
process.exit(failed ? 1 : 0);
