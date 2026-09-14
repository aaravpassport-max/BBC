'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const pbceSrc = fs.readFileSync(path.join(__dirname, '../assets/pullback-continuation-engine.js'), 'utf8');
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
    .replace(/function migrateTradingControlsSchema/g, 'function migrateTradingControlsSchema')
    .replace(/const fnoSettings/g, 'var fnoSettings'),
  coreSrc.slice(emaStart, emaEnd),
  coreSrc.slice(rsiStart, rsiEnd),
  coreSrc.slice(macdStart, macdEnd),
  coreSrc.slice(hvStart, hvEnd + 2),
  'function isScalpingProfitProfileActive(){ return true; }',
  'function fnoThemePalette(){ return { panel:"#000", line:"#333", text:"#eee", muted:"#888", pass:"#0f0", fail:"#f00", warn:"#ff0" }; }',
  pbceSrc,
  'return { fnoSettings, isPullbackContinuationActive, computeMovementSpeedMetrics, classifyMovementSpeed, detectPullbackContinuationPhase, evaluateSpotTargetFeasibility, computePullbackContinuationSetup, resolvePullbackContinuationBracket, applyPullbackContinuationInfluence, checkPullbackContinuationEntryGate, convertSpotTargetToOptionBracket, FNO_PBCE_SPOT_TARGETS };',
].join('\n');

const api = new Function(bootSrc)();

api.fnoSettings.set({
  pullbackContinuationEnabled: true,
  scalpingProfitProfileEnabled: true,
  tradingTypes: { scalping: true, intraday: false, swing: false },
});

assert.strictEqual(api.FNO_PBCE_SPOT_TARGETS.slow, 10);
assert.strictEqual(api.FNO_PBCE_SPOT_TARGETS.medium, 15);
assert.strictEqual(api.FNO_PBCE_SPOT_TARGETS.fast, 20);

const fastCloses = [];
let p = 24000;
for (let i = 0; i < 30; i++) {
  p += i > 20 ? 8 : 2;
  fastCloses.push(p);
}
const fastCtx = { spot: p, candles: fastCloses.map((c, i) => ({ c, t: i })), decay: { snapshot: { now: { delta: 0.5 } } } };
const fastMetrics = api.computeMovementSpeedMetrics(fastCtx, null);
const fastClass = api.classifyMovementSpeed(fastMetrics);
assert.strictEqual(fastClass.spotTargetPoints, 20, 'fast movement should target 20 points');
assert.strictEqual(fastClass.tier, 'fast');

const slowCloses = [];
p = 24000;
for (let i = 0; i < 30; i++) {
  p += 0.1;
  slowCloses.push(p);
}
const slowCtx = { spot: p, candles: slowCloses.map((c, i) => ({ c, t: i })), decay: { snapshot: { now: { delta: 0.4 } } } };
const slowClass = api.classifyMovementSpeed(api.computeMovementSpeedMetrics(slowCtx, null));
assert.strictEqual(slowClass.spotTargetPoints, 10, 'slow movement should target 10 points');

const bracket = api.convertSpotTargetToOptionBracket(100, slowCtx, 15, 'bullish');
assert.ok(bracket.target > 100 && bracket.sl < 100);
assert.strictEqual(bracket.spotTargetPoints, 15);

const brainWait = { decision: 'WAIT', confidence: 'Medium', results: [] };
const setupWait = api.computePullbackContinuationSetup(slowCtx, brainWait);
assert.ok(setupWait.active);
assert.strictEqual(setupWait.entryAllowed, false);

const bullishCloses = [];
p = 24000;
for (let i = 0; i < 25; i++) {
  p += i > 15 ? (i % 3 === 0 ? -3 : 6) : 1.5;
  bullishCloses.push(p);
}
p += 4;
bullishCloses.push(p);
const contCtx = {
  spot: p,
  candles: bullishCloses.map((c, i) => ({ c, t: i })),
  decay: { snapshot: { now: { delta: 0.55, price: 100 } } },
};
const contBrain = { decision: 'BUY_READY', confidence: 'High', results: [] };
const contSetup = api.computePullbackContinuationSetup(contCtx, contBrain);
assert.ok(contSetup.active);
assert.ok(contSetup.spotTargetPoints >= 10 && contSetup.spotTargetPoints <= 20);
assert.ok(contSetup.selectionReason.includes(`${contSetup.spotTargetPoints}-point`));

const influenced = api.applyPullbackContinuationInfluence({ ...contBrain, reason: 'Test' }, { ...contSetup, entryAllowed: false, blockReason: 'phase incomplete' });
assert.strictEqual(influenced.decision, 'WAIT');
assert.ok(/PBCE/.test(influenced.reason));

const pbBracket = api.resolvePullbackContinuationBracket(100, contCtx, contBrain, 'scalping', 'CE');
assert.ok(pbBracket);
assert.ok(pbBracket.source.startsWith('pullback_continuation_'));

assert.ok(/checkPullbackContinuationEntryGate/.test(coreSrc));
assert.ok(/applyPullbackContinuationInfluence/.test(coreSrc));
assert.ok(/pullbackContinuation:/.test(coreSrc));
assert.ok(/pullback-continuation-engine\.js/.test(phpSrc));
assert.ok(/pullbackContinuationBox/.test(phpSrc));
assert.ok(/settingPullbackContinuation/.test(phpSrc));

console.log('All pullback-continuation-engine tests passed.');
