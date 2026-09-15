'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const tseSrc = fs.readFileSync(path.join(__dirname, '../assets/trade-setup-engine.js'), 'utf8');
const speSrc = fs.readFileSync(path.join(__dirname, '../assets/scalping-profit-engine.js'), 'utf8');
const phpSrc = fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8');

const emaStart = coreSrc.indexOf('function ema(data,p)');
const emaEnd = coreSrc.indexOf('function vwapCalc', emaStart);
const rsiStart = coreSrc.indexOf('function rsiCalc(closes, period)');
const rsiEnd = coreSrc.indexOf('function macdCalc(closes)', rsiStart);
const macdStart = rsiEnd;
const macdEnd = coreSrc.indexOf('function bollinger(closes', macdStart);
const hvStart = coreSrc.indexOf('function historicalVolPct(closes, period)');
const hvEnd = coreSrc.indexOf('\n\n/**\n * TRACE: Zerodha-maximization', hvStart);
const fnoSettingsStart = coreSrc.indexOf('const FNO_SETTINGS_KEY');
const fnoSettingsEnd = coreSrc.indexOf('\n};\n', coreSrc.indexOf('const fnoSettings = {')) + 3;

const bootSrc = [
  'var localStorage = { _d: {}, getItem(k){ return this._d[k]||null; }, setItem(k,v){ this._d[k]=String(v); }, removeItem(k){ delete this._d[k]; } };',
  'var document = { dispatchEvent(){}, getElementById(){ return null; } };',
  coreSrc.slice(fnoSettingsStart, fnoSettingsEnd)
    .replace(/const FNO_SETTINGS_KEY/g, 'var FNO_SETTINGS_KEY')
    .replace(/const FNO_SETTINGS_DEFAULTS/g, 'var FNO_SETTINGS_DEFAULTS')
    .replace(/const FNO_SETTINGS_SCHEMA_KEY/g, 'var FNO_SETTINGS_SCHEMA_KEY')
    .replace(/const FNO_SETTINGS_SCHEMA_VERSION/g, 'var FNO_SETTINGS_SCHEMA_VERSION')
    .replace(/const fnoSettings/g, 'var fnoSettings'),
  coreSrc.slice(emaStart, emaEnd),
  coreSrc.slice(rsiStart, rsiEnd),
  coreSrc.slice(macdStart, macdEnd),
  coreSrc.slice(hvStart, hvEnd + 2),
  'function computeBreakoutReversalCondition(candles, rangeWindow){ if(!candles||candles.length<5) return null; const closes=candles.map(c=>c.c); const win=closes.slice(-Math.min(rangeWindow||20,closes.length)); const hi=Math.max(...win), lo=Math.min(...win); const last=closes[closes.length-1]; const range=hi-lo; const choppy=range>0&&range/(last||1)*100<0.15; return { condition: choppy?"choppy":"range_bound", rangeHigh:hi, rangeLow:lo }; }',
  'function computeMarketRegime(ctx){ return { label:"Bullish-Normal Vol", trend:"Bullish", volatility:"Normal Vol", extendedStates:{} }; }',
  'function isScalpingProfitProfileActive(){ return true; }',
  'function checkScalpingCapitalPreservation(brain, ctx, opts){ return { allowed: true }; }',
  'function checkOptionChainFreshness(ts, now){ return { stale: false, ageMinutes: 0 }; }',
  tseSrc,
  speSrc,
  'return { fnoSettings, isScalpingProfitEngineActive, isScalpingProfitEngineEntryBlocking, getScalpingProfitSettings, computeScalpingProfitEngine, applyScalpingProfitInfluence, checkScalpingProfitEntryGate, evaluateMarketSafety, detectSpeRegime, evaluateSpeDirection, detectSpeSetups, evaluateAntiChase, evaluateTradeQuality, evaluateExpectedMove, computeScalpingProfitStatistics, classifySpeFailureMode, FNO_SPE_MODE, FNO_SPE_NO_TRADE, FNO_SPE_REGIME, FNO_SPE_SETUP, buildSpeMarketState, evaluateScalpingProfitExit, computeTradeHealth, FNO_SPE_SETTING_META };',
].join('\n');

const api = new Function(bootSrc)();

api.fnoSettings.set({
  scalpingProfitEngineEnabled: true,
  scalpingProfitEngineMode: 'PAPER_ONLY',
  scalpingProfitProfileEnabled: true,
  tradingTypes: { scalping: true, intraday: false, swing: false },
  speMinTradeQualityScore: 70,
  speMinRegimeConfidence: 60,
  speMinDirectionConfidence: 65,
  tradeSetupEngineEnabled: true,
});

function makeCandles(closes) {
  return closes.map((c, i) => ({ c, t: Date.now() + i * 60000 }));
}

assert.strictEqual(api.isScalpingProfitEngineActive(), true);
assert.strictEqual(api.isScalpingProfitEngineEntryBlocking(), true);

api.fnoSettings.set({ scalpingProfitEngineMode: 'OFF' });
assert.strictEqual(api.isScalpingProfitEngineActive(), false);
api.fnoSettings.set({ scalpingProfitEngineMode: 'PAPER_ONLY' });

const closes = [];
let p = 24000;
for (let i = 0; i < 30; i++) { p += i > 15 ? 6 : 1.5; closes.push(p); }
const ctx = {
  spot: p,
  candles: makeCandles(closes),
  ocRow: { CE: { bidprice: 100, askPrice: 100.08, bidQty: 5000, askQty: 5000, lastPrice: 100.04 }, PE: { lastPrice: 95 } },
  ocFetchedAt: Date.now(),
  decay: { snapshot: { now: { delta: 0.5, price: 100 } } },
};
const brain = { decision: 'BUY_READY', confidence: 'High', reason: 'test' };

const spe = api.computeScalpingProfitEngine(ctx, brain, { journalToday: [] });
assert.ok(spe.active);
assert.ok(['ENTER', 'NO_TRADE', 'SIGNAL'].includes(spe.decision));
assert.ok(typeof spe.tradeQualityScore === 'number');
assert.ok(spe.layers && spe.layers.safety);
assert.ok(spe.explanation);

const blockedBrain = { decision: 'BUY_READY', reason: 'x' };
api.applyScalpingProfitInfluence(blockedBrain, Object.assign({}, spe, { entryAllowed: false, decision: 'NO_TRADE', noTradeReason: 'LOW_SCORE' }));
if (!spe.entryAllowed) {
  assert.ok(['WAIT', 'NO_TRADE'].includes(blockedBrain.decision));
}

api.fnoSettings.set({ speMinTradeQualityScore: 99 });
const strictSpe = api.computeScalpingProfitEngine(ctx, brain, {});
assert.ok(strictSpe.noTradeReasons.includes('LOW_SCORE') || strictSpe.blockers.includes('LOW_SCORE'));
api.fnoSettings.set({ speMinTradeQualityScore: 70 });

api.fnoSettings.set({ scalpingProfitEngineMode: 'SIGNAL_ONLY' });
assert.strictEqual(api.isScalpingProfitEngineEntryBlocking(), false);

const ms = api.buildSpeMarketState(ctx, brain);
const safety = api.evaluateMarketSafety(ms, ctx, api.getScalpingProfitSettings());
assert.ok(safety.liquidityScore >= 0);
const regime = api.detectSpeRegime(ms, ctx);
assert.ok(regime.regime);
const direction = api.evaluateSpeDirection(ms, regime, brain);
assert.strictEqual(direction.direction, 'BULLISH');

const stats = api.computeScalpingProfitStatistics([]);
assert.strictEqual(stats.n, 0);

assert.ok(/applyScalpingProfitInfluence/.test(coreSrc));
assert.ok(/checkScalpingProfitEntryGate/.test(coreSrc));
assert.ok(/computeScalpingProfitEngine/.test(coreSrc));
assert.ok(/scalping-profit-engine\.js/.test(phpSrc));
assert.ok(/scalpingProfitEngineBox/.test(phpSrc));
assert.ok(/settingScalpingProfitEngine/.test(phpSrc));
assert.ok(/scalpingProfitEngineEnabled/.test(coreSrc));
assert.ok(Object.keys(api.FNO_SPE_SETTING_META).length >= 15);

console.log('scalping-profit-engine.test.js: all tests passed');
