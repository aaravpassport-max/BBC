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
const preservationStart = coreSrc.indexOf('/** User checkbox is the master ON/OFF for capital preservation gates. */');
const preservationEnd = coreSrc.indexOf('/** One-time schema migrations', preservationStart);
const profitProfileActiveStart = coreSrc.indexOf('function isScalpingProfitProfileActive()');
const profitProfileActiveEnd = coreSrc.indexOf('\n}\n\nfunction getEffectiveDecisionThresholds', profitProfileActiveStart);

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
  coreSrc.slice(profileStart, profileEnd + 2)
    .replace(/const FNO_SCALPING_PROFIT_PROFILE/g, 'var FNO_SCALPING_PROFIT_PROFILE'),
  coreSrc.slice(profitProfileActiveStart, profitProfileActiveEnd + 2),
  coreSrc.slice(preservationStart, preservationEnd),
  modesSrc,
  'syncTargetSlUiFromPreset = function(){}; applyScalpingExecutionControlsFromSettings = function(){}; updateLotQtyHint = function(){};',
  'return { fnoSettings, isScalpingCapitalPreservationActive, getScalpingCapitalPreservationConfig, formatScalpingCapitalPreservationStatusText, checkScalpingCapitalPreservation, applyScalpingTradingModePreset, resolveScalpingTradingMode };',
].join('\n');

const api = new Function(bootSrc)();

const baseSettings = {
  scalpingProfitProfileEnabled: true,
  tradingTypes: { scalping: true, intraday: false, swing: false },
  scalpingTradingMode: 'relaxed',
  scalpingCapitalPreservationEnabled: true,
  maxLosingTradesPerDay: 1,
  maxDailyLossPctPreservation: 1.5,
  tradeTypeTargetSlEnabled: false,
  tradeTypeSizingEnabled: true,
  autoCalibrateThresholdEnabled: false,
};

api.fnoSettings.set(baseSettings);

assert.strictEqual(api.isScalpingCapitalPreservationActive(), true);
assert.ok(/Capital preservation ON \(Medium\+ confidence/.test(api.formatScalpingCapitalPreservationStatusText()));

const blocked = api.checkScalpingCapitalPreservation(
  { confidence: 'Low', pretradeGateCheck: null },
  { todayPnL: 0, accountAvailableCapital: 100000 },
  { journalToday: [] }
);
assert.ok(!blocked.allowed);
assert.ok(/Medium confidence/.test(blocked.reason));

api.fnoSettings.set({ scalpingCapitalPreservationEnabled: false });
assert.strictEqual(api.isScalpingCapitalPreservationActive(), false);
assert.strictEqual(api.formatScalpingCapitalPreservationStatusText(), 'Capital preservation OFF');

const allowedWhenOff = api.checkScalpingCapitalPreservation(
  { confidence: 'Low', pretradeGateCheck: { finalAction: 'block', triggered: [{ id: 'FM077' }] } },
  { todayPnL: -50000, accountAvailableCapital: 100000 },
  { journalToday: [{ pnl: -500 }, { pnl: -500 }] }
);
assert.strictEqual(allowedWhenOff.allowed, true);

api.fnoSettings.set({ scalpingCapitalPreservationEnabled: false, tradeTypeTargetSlEnabled: false, tradeTypeSizingEnabled: true, autoCalibrateThresholdEnabled: false });
api.applyScalpingTradingModePreset('opportunity');
const afterMode = api.fnoSettings.get();
assert.strictEqual(afterMode.scalpingCapitalPreservationEnabled, false, 'mode change must not re-enable capital preservation');
assert.strictEqual(afterMode.tradeTypeTargetSlEnabled, false, 'mode change must not override tradeTypeTargetSlEnabled');
assert.strictEqual(afterMode.tradeTypeSizingEnabled, true, 'mode change must not override tradeTypeSizingEnabled');
assert.strictEqual(afterMode.autoCalibrateThresholdEnabled, false, 'mode change must not re-enable auto-calibrate');
assert.strictEqual(afterMode.maxLosingTradesPerDay, 2, 'mode change should update suggested loss cap');

api.fnoSettings.set({ autoCalibrateThresholdEnabled: true });
api.applyScalpingTradingModePreset('maximum_opportunity');
assert.strictEqual(api.fnoSettings.get().autoCalibrateThresholdEnabled, false, 'experimental mode disables auto-calibrate');

assert.ok(/function formatScalpingCapitalPreservationStatusText/.test(coreSrc));
assert.ok(/window\.FNO_LAST_BRAIN = brain/.test(coreSrc));
assert.ok(/renderScalpingSessionReadiness\(window\.FNO_LAST_BRAIN/.test(coreSrc));
assert.ok(!/modeProfile && modeProfile\.capitalPreservation\s*\?\s*`🛡️ Capital preservation ON/.test(coreSrc),
  'readiness panel must not show preservation ON from mode profile alone');

assert.ok(/scalpingProfitEngineEnabled/.test(coreSrc));
assert.ok(/speMinTradeQualityScore/.test(coreSrc));
assert.ok(/FNO_SETTINGS_SCHEMA_VERSION = 12/.test(coreSrc));
assert.ok(/applyScalpingProfitInfluence/.test(coreSrc));
assert.ok(/checkScalpingProfitEntryGate/.test(coreSrc));

console.log('All settings-runtime-sync tests passed.');
