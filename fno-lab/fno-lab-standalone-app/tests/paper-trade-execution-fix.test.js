'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const driverSrc = fs.readFileSync(path.join(__dirname, '../autonomous-driver/autonomous-driver.js'), 'utf8');
const configSrc = fs.readFileSync(path.join(__dirname, '../autonomous-driver/config.js'), 'utf8');
const envExample = fs.readFileSync(path.join(__dirname, '../autonomous-driver/.env.example'), 'utf8');

const fnoSettingsStart = coreSrc.indexOf('const FNO_SETTINGS_KEY');
const fnoSettingsEnd = coreSrc.indexOf('\n};\n', coreSrc.indexOf('const fnoSettings = {')) + 3;
const helpersStart = coreSrc.indexOf('function getEffectiveTradingType(isSwingPath)');
const helpersEnd = coreSrc.indexOf('\n}\n\n/** Scalping Profit Profile');
const rejectionStart = coreSrc.indexOf('const FNO_EXEC_REJECTION = {');
const rejectionEnd = coreSrc.indexOf('\n}\n\n/**\n * TRACE: Real, trade-type-aware category weighting', rejectionStart);

const bootSrc = [
  'var localStorage = { _d: {}, getItem(k){ return this._d[k]||null; }, setItem(k,v){ this._d[k]=String(v); } };',
  'var document = { dispatchEvent(){} };',
  coreSrc.slice(fnoSettingsStart, fnoSettingsEnd)
    .replace(/const FNO_SETTINGS_KEY/g, 'var FNO_SETTINGS_KEY')
    .replace(/const FNO_SETTINGS_DEFAULTS/g, 'var FNO_SETTINGS_DEFAULTS')
    .replace(/const fnoSettings/g, 'var fnoSettings'),
  coreSrc.slice(helpersStart, helpersEnd + 2),
  coreSrc.slice(rejectionStart, rejectionEnd + 2).replace(/const FNO_EXEC_REJECTION/g, 'var FNO_EXEC_REJECTION'),
  'return { fnoSettings, getEffectiveTradingType, resolveDecisionTradingType, resolveLocalSlotTradingType, isAnyTradingTypeEnabled, classifyExecutionRejection, FNO_EXEC_REJECTION };',
].join('\n');

const api = new Function(bootSrc)();

function withTypes(intraday, scalping, swing, fn) {
  const prev = api.fnoSettings.get();
  api.fnoSettings.set({ tradingTypes: { intraday, scalping, swing } });
  try { return fn(); } finally { api.fnoSettings.set(prev); }
}

withTypes(true, false, true, () => {
  assert.strictEqual(api.resolveDecisionTradingType(), 'intraday');
  assert.strictEqual(api.resolveLocalSlotTradingType(), 'intraday');
});
withTypes(false, false, true, () => {
  assert.strictEqual(api.resolveDecisionTradingType(), 'swing');
  assert.strictEqual(api.resolveLocalSlotTradingType(), null);
});
assert.strictEqual(api.classifyExecutionRejection('BUY_READY', 'Blocked by the Failure-Mode Library').category, api.FNO_EXEC_REJECTION.RISK_VALIDATION);
assert.ok(!/getEffectiveTradingType\(fnoSettings\.get\(\)\.tradingTypes\.swing\)/.test(coreSrc));
assert.ok(/brain\.decision === 'BUY_READY' \? 'CE' : 'PE'/.test(driverSrc));
assert.ok(/rejectionCategory:/.test(coreSrc));
assert.ok(/scalpingProfitProfileEnabled/.test(coreSrc));
assert.ok(/getEffectiveDecisionThresholds/.test(coreSrc));
console.log('All paper-trade execution fix tests passed.');
