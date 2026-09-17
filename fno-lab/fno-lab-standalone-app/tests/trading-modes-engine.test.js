'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const modesSrc = fs.readFileSync(path.join(__dirname, '../assets/trading-modes-engine.js'), 'utf8');

const fnoSettingsStart = coreSrc.indexOf('const FNO_SETTINGS_KEY');
const fnoSettingsEnd = coreSrc.indexOf('\n};\n', coreSrc.indexOf('const fnoSettings = {')) + 3;
const profileStart = coreSrc.indexOf('/** Scalping Profit Profile');
const profileEnd = coreSrc.indexOf('\n}\n\n/**\n * TRACE: structured rejection taxonomy', profileStart);

const bootSrc = [
  'var localStorage = { _d: {}, getItem(k){ return this._d[k]||null; }, setItem(k,v){ this._d[k]=String(v); }, removeItem(k){ delete this._d[k]; } };',
  'var document = { dispatchEvent(){}, getElementById(){ return null; } };',
  'function escapeHtml(s){ return String(s); }',
  'function getThresholdOverride(){ return null; }',
  'function getDecisionLog(){ return []; }',
  'function load(k){ return []; }',
  'const STORAGE = { autoTrades: "fno_autotrades_v8" };',
  coreSrc.slice(fnoSettingsStart, fnoSettingsEnd)
    .replace(/const FNO_SETTINGS_KEY/g, 'var FNO_SETTINGS_KEY')
    .replace(/const FNO_SETTINGS_DEFAULTS/g, 'var FNO_SETTINGS_DEFAULTS')
    .replace(/const FNO_SETTINGS_SCHEMA_KEY/g, 'var FNO_SETTINGS_SCHEMA_KEY')
    .replace(/const FNO_SETTINGS_SCHEMA_VERSION/g, 'var FNO_SETTINGS_SCHEMA_VERSION')
    .replace(/function migrateTradingControlsSchema/g, 'function migrateTradingControlsSchema')
    .replace(/const fnoSettings/g, 'var fnoSettings'),
  coreSrc.slice(profileStart, profileEnd + 2)
    .replace(/const FNO_SCALPING_PROFIT_PROFILE/g, 'var FNO_SCALPING_PROFIT_PROFILE'),
  modesSrc,
  'syncTargetSlUiFromPreset = function(){}; applyScalpingExecutionControlsFromSettings = function(){}; updateLotQtyHint = function(){};',
  'return { fnoSettings, FNO_SCALPING_TRADING_MODES, resolveScalpingTradingMode, getActiveTradingModeProfile, applyScalpingTradingModePreset, wouldModeAcceptSetup, resolveModeAdjustedLotCount, getModeEffectiveThresholds, getModeSpreadHardBlockPct, computeModeComparisonDashboard, logModeTradeOpen, logModeTradeClose, getModeTradeLog, FNO_MODE_TRADE_LOG_KEY, isExperimentalTradeTriggerMode };',
].join('\n');

const api = new Function(bootSrc)();

assert.strictEqual(Object.keys(api.FNO_SCALPING_TRADING_MODES).length, 7);
assert.strictEqual(api.FNO_SCALPING_TRADING_MODES.experimental_trigger.relaxExecutionGates, true);
assert.strictEqual(api.FNO_SCALPING_TRADING_MODES.experimental_trigger.buyThreshold, 2);
assert.strictEqual(api.FNO_SCALPING_TRADING_MODES.conservative.buyThreshold, 6);
assert.strictEqual(api.FNO_SCALPING_TRADING_MODES.balanced.scalpingFmSafetyProfile, 'balanced');
assert.strictEqual(api.FNO_SCALPING_TRADING_MODES.relaxed.buyThreshold, 5);
assert.strictEqual(api.FNO_SCALPING_TRADING_MODES.maximum_opportunity.experimental, true);

api.fnoSettings.set({ scalpingProfitProfileEnabled: true, tradingTypes: { scalping: true, intraday: false, swing: false }, scalpingTradingMode: 'conservative' });
assert.strictEqual(api.getModeEffectiveThresholds().source, 'trading_mode_conservative');

api.applyScalpingTradingModePreset('relaxed');
assert.strictEqual(api.resolveScalpingTradingMode(), 'relaxed');
assert.strictEqual(api.getActiveTradingModeProfile().weightedScorePolicy, 'downgrade');
assert.strictEqual(api.getModeSpreadHardBlockPct(), 14);

const brain = {
  decision: 'BUY_READY',
  confidence: 'Medium',
  directionalScore: 5.2,
  weightedDirectionalScore: 5.2,
  pretradeGateCheck: { finalAction: 'none', triggered: [] },
  results: [{ factor: 'Momentum', pass: true, score: 2.1, cat: 'Flow' }],
  reason: 'Momentum developing',
};
assert.strictEqual(api.wouldModeAcceptSetup('balanced', brain).accepted, false);
assert.strictEqual(api.wouldModeAcceptSetup('relaxed', brain).accepted, true);
assert.strictEqual(api.resolveModeAdjustedLotCount(2, brain), 2); // Medium ×0.75 → round(1.5)=2 min 1

const lowConfBrain = { ...brain, confidence: 'Low', directionalScore: 3.2, weightedDirectionalScore: 3.2 };
assert.strictEqual(api.wouldModeAcceptSetup('balanced', lowConfBrain).accepted, false);
assert.strictEqual(api.wouldModeAcceptSetup('maximum_opportunity', lowConfBrain).accepted, true);
assert.strictEqual(api.resolveModeAdjustedLotCount(2, lowConfBrain), 1); // Max opp Low ×0.15 → 1
assert.strictEqual(api.wouldModeAcceptSetup('experimental_trigger', { ...lowConfBrain, directionalScore: 2.1, weightedDirectionalScore: 2.1 }).accepted, true);
assert.strictEqual(api.isExperimentalTradeTriggerMode(), false);
api.applyScalpingTradingModePreset('experimental_trigger');
assert.strictEqual(api.isExperimentalTradeTriggerMode(), true);
assert.strictEqual(api.fnoSettings.get().scalpingProfitEngineMode, 'SIGNAL_ONLY');
assert.strictEqual(api.fnoSettings.get().scalpingCapitalPreservationEnabled, false);
assert.strictEqual(api.resolveModeAdjustedLotCount(2, lowConfBrain), 2); // Mode 7 uses full size multipliers

api.logModeTradeOpen(12345, brain, { sym: 'NIFTY', slPrice: 90, targetPrice: 110, spot: 24000 }, {
  sym: 'NIFTY', entry: { price: 100, qty: 50, strike: 24000, optionType: 'CE', target: 110, sl: 90 }, invalidation: 90,
});
const log = api.getModeTradeLog();
assert.ok(log.length >= 1);
assert.strictEqual(log[log.length - 1].phase, 'open');
assert.strictEqual(log[log.length - 1].balancedRejectRelaxedAccept, true);

api.applyScalpingTradingModePreset('opportunity');
assert.strictEqual(api.resolveScalpingTradingMode(), 'opportunity');
assert.strictEqual(api.fnoSettings.get().scalpingTradingMode, 'opportunity');
api.applyScalpingTradingModePreset('relaxed');
assert.strictEqual(api.resolveScalpingTradingMode(), 'relaxed');
assert.notStrictEqual(api.resolveScalpingTradingMode(), 'balanced');

const dash = api.computeModeComparisonDashboard({});
assert.ok(dash.byMode.conservative);
assert.ok(dash.byMode.relaxed);
assert.ok(dash.vsBalanced);

console.log('All trading modes engine tests passed.');
