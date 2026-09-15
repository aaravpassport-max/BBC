'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const greeksSrc = fs.readFileSync(path.join(__dirname, '../assets/greeks-engine.js'), 'utf8');

const settingsStart = coreSrc.indexOf('const FNO_SETTINGS_KEY');
const settingsEnd = coreSrc.indexOf('\n};\n', coreSrc.indexOf('const fnoSettings = {')) + 3;
const profileStart = coreSrc.indexOf('/** Scalping Profit Profile');
const evaluateStart = coreSrc.indexOf('function evaluateBrain(ctx)');
const evaluateEnd = coreSrc.indexOf('\nfunction ', evaluateStart + 1);

const boot = [
  greeksSrc.replace(/export /g, ''),
  'var window = { FNO_FACTORS_CATALOG: [] };',
  'var localStorage = { _d: {}, getItem(k){ return this._d[k]||null; }, setItem(k,v){ this._d[k]=String(v); } };',
  'var document = { getElementById(){ return null; }, dispatchEvent(){} };',
  'function getThresholdOverride(){ return null; }',
  'function loadObj(){ return null; }',
  'function save(){}',
  'function resolveDecisionTradingType(){ return "scalping"; }',
  coreSrc.slice(settingsStart, settingsEnd)
    .replace(/const FNO_SETTINGS_KEY/g, 'var FNO_SETTINGS_KEY')
    .replace(/const FNO_SETTINGS_DEFAULTS/g, 'var FNO_SETTINGS_DEFAULTS')
    .replace(/const FNO_SETTINGS_SCHEMA_VERSION/g, 'var FNO_SETTINGS_SCHEMA_VERSION')
    .replace(/const FNO_SETTINGS_SCHEMA_KEY/g, 'var FNO_SETTINGS_SCHEMA_KEY')
    .replace(/const fnoSettings/g, 'var fnoSettings'),
  coreSrc.slice(profileStart, evaluateEnd + 1)
    .replace(/const FNO_SCALPING_PROFIT_PROFILE/g, 'var FNO_SCALPING_PROFIT_PROFILE')
    .replace(/const FNO_TRADE_TYPE_CATEGORY_WEIGHTS/g, 'var FNO_TRADE_TYPE_CATEGORY_WEIGHTS'),
  'return { fnoSettings, evaluateBrain, computeValueDecayCriticalPct, shouldPushValueDecayCriticalFail, isScalpingProfitProfileActive, calculateDecay };',
].join('\n');

const api = new Function(boot)();

api.fnoSettings.set({
  scalpingProfitProfileEnabled: true,
  tradingTypes: { intraday: false, scalping: true, swing: false },
});

const spot = 23300;
const strike = 23200;
const daysExp = 4;
const iv = 14;
const lotSize = 75;
const optPrice = Math.round(api.calculateDecay(spot, strike, daysExp, iv, 120, lotSize, 'CE').snapshot.now.price * 100) / 100;
const decay = api.calculateDecay(spot, strike, daysExp, iv, optPrice, lotSize, 'CE');
const pct = api.computeValueDecayCriticalPct({ optPrice, decay: { ...decay, days: daysExp, snapshot: decay.snapshot } });

assert.ok(pct !== null && pct > 5, `expected realistic decay pct > 5 for 4 DTE ATM (got ${pct})`);
assert.strictEqual(
  api.shouldPushValueDecayCriticalFail({ optPrice, decay: { ...decay, days: daysExp, snapshot: decay.snapshot } }, pct),
  false,
  'scalping with DTE>1 must not hard-block normal weekly theta'
);

const brain = api.evaluateBrain({
  spot, ema21: spot - 10, vwap: spot - 5, pcr: 1.0,
  vix: 14, symbol: 'NIFTY', banList: [], banListSource: 'live',
  todayPnL: 0, daily: {},
  decay: { ...decay, days: daysExp, snapshot: decay.snapshot },
  optPrice, lotSize, candles: [{ c: spot }], ocRow: { strikePrice: strike },
  operatorIntel: { bias: 'NEUTRAL', score: 0, confidence: 'LOW', signals: [] },
  journalToday: [], fullJournal: [],
});
assert.notStrictEqual(brain.decision, 'NO_TRADE', '4 DTE scalping must not NO_TRADE solely for Value Decay');
assert.strictEqual(
  (brain.criticalFails || []).some(f => f.factor === 'Value Decay'),
  false
);

assert.strictEqual(
  api.shouldPushValueDecayCriticalFail({ optPrice: 100, decay: { days: 0.5, snapshot: decay.snapshot } }, 8),
  true,
  'near expiry (DTE<=1) still hard-blocks when theta > 5%'
);

assert.strictEqual(api.computeValueDecayCriticalPct({ optPrice: null, decay: { snapshot: decay.snapshot } }), null);
assert.strictEqual(api.shouldPushValueDecayCriticalFail({ optPrice: null, decay: { days: 4, snapshot: decay.snapshot } }, null), false);

console.log('All scalping value-decay policy tests passed.');
