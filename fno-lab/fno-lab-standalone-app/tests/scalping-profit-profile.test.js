'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');

const fnoSettingsStart = coreSrc.indexOf('const FNO_SETTINGS_KEY');
const fnoSettingsEnd = coreSrc.indexOf('\n};\n', coreSrc.indexOf('const fnoSettings = {')) + 3;
const profileStart = coreSrc.indexOf('/** Scalping Profit Profile');
const profileEnd = coreSrc.indexOf('\n}\n\n/**\n * TRACE: structured rejection taxonomy', profileStart);
const simStart = coreSrc.indexOf('function simulateOrderRejection(leg, qty, side, opts)');
const simEnd = coreSrc.indexOf('\n}\n\n/**\n * TRACE: real, NEW Phase-5 fix (FM131', simStart);

const bootSrc = [
  'var localStorage = { _d: {}, getItem(k){ return this._d[k]||null; }, setItem(k,v){ this._d[k]=String(v); } };',
  'var document = { dispatchEvent(){} };',
  'function getThresholdOverride(){ return null; }',
  coreSrc.slice(fnoSettingsStart, fnoSettingsEnd)
    .replace(/const FNO_SETTINGS_KEY/g, 'var FNO_SETTINGS_KEY')
    .replace(/const FNO_SETTINGS_DEFAULTS/g, 'var FNO_SETTINGS_DEFAULTS')
    .replace(/const fnoSettings/g, 'var fnoSettings'),
  coreSrc.slice(profileStart, profileEnd + 2)
    .replace(/const FNO_SCALPING_PROFIT_PROFILE/g, 'var FNO_SCALPING_PROFIT_PROFILE'),
  coreSrc.slice(simStart, simEnd + 2),
  'return { fnoSettings, getEffectiveDecisionThresholds, isScalpingProfitProfileActive, applyScalpingProfitProfilePreset, simulateOrderRejection, FNO_SCALPING_PROFIT_PROFILE };',
].join('\n');

const api = new Function(bootSrc)();

assert.strictEqual(api.getEffectiveDecisionThresholds().buyThreshold, 11);
api.fnoSettings.set({ scalpingProfitProfileEnabled: true, tradingTypes: { intraday: false, scalping: true, swing: false } });
assert.strictEqual(api.isScalpingProfitProfileActive(), true);
const t = api.getEffectiveDecisionThresholds();
assert.strictEqual(t.buyThreshold, 8);
assert.strictEqual(t.sellThreshold, -13);
assert.strictEqual(t.source, 'scalping_profit_profile');

api.applyScalpingProfitProfilePreset();
const s = api.fnoSettings.get();
assert.strictEqual(s.tradingTypes.scalping, true);
assert.strictEqual(s.tradingTypes.intraday, false);
assert.strictEqual(s.tradeTypeTargetSlEnabled, true);
assert.strictEqual(s.tradeTypeSizingEnabled, false);
assert.strictEqual(s.autoCalibrateTargetWinRatePct, 70);

const thinOnly = api.simulateOrderRejection({ askQty: 10, bidQty: 500, bidprice: 100, askPrice: 101 }, 50, 'buy', { scalpingProfitProfile: true });
assert.strictEqual(thinOnly.rejected, false, 'depth-only should not reject under scalping profile');

const wideSpread = api.simulateOrderRejection({ askQty: 5000, bidQty: 5000, bidprice: 100, askPrice: 113 }, 50, 'buy', { scalpingProfitProfile: true });
assert.strictEqual(wideSpread.rejected, true, 'spread above 12% must reject under scalping profile');

const normal = api.simulateOrderRejection({ askQty: 5000, bidQty: 5000, bidprice: 100, askPrice: 100.5 }, 50, 'buy', { scalpingProfitProfile: true });
assert.strictEqual(normal.rejected, false);

console.log('All scalping profit profile tests passed.');
