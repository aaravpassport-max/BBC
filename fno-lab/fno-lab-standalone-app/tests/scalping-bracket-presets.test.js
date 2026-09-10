'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const phpSrc = fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8');

const sliceStart = coreSrc.indexOf('const FNO_SCALPING_BRACKET_PRESETS');
const sliceEnd = coreSrc.indexOf('function isScalpingProfitProfileActive', sliceStart);

const bootSrc = [
  coreSrc.slice(sliceStart, sliceEnd).replace(/const FNO_SCALPING_BRACKET_PRESETS/g, 'var FNO_SCALPING_BRACKET_PRESETS'),
  `var _settings = {
    scalpingBracketPreset: 'standard',
    savedManualTargetPct: 30,
    savedManualSlPct: 15,
    savedManualTrailingEnabled: true,
    savedManualPartialExitEnabled: true,
    tradeTypeTargetSlEnabled: true,
  };
  var fnoSettings = {
    get(){ return _settings; },
    set(p){ _settings = { ..._settings, ...p }; },
  };`,
  'function isScalpingProfitProfileActive(){ return true; }',
  `function computeTradeTypeTargetSl(optPrice, tradingType) {
    if (typeof optPrice !== 'number' || !isFinite(optPrice) || optPrice <= 0) return { target: null, sl: null };
    const cfg = getScalpingBracketConfig();
    return computeBracketPrices(optPrice, cfg);
  }`,
  'return { getScalpingBracketPresetId, getScalpingBracketConfig, computeBracketPrices, resolveTradeBracketForEntry, formatScalpingBracketLabel, FNO_SCALPING_BRACKET_PRESETS, settings: fnoSettings };',
].join('\n');

const api = new Function(bootSrc)();

assert.ok(api.FNO_SCALPING_BRACKET_PRESETS.standard.targetFraction === 0.20);
assert.ok(api.FNO_SCALPING_BRACKET_PRESETS.standard.slFraction === 0.10);
assert.ok(api.FNO_SCALPING_BRACKET_PRESETS.balanced.targetFraction === 0.25);
assert.ok(api.FNO_SCALPING_BRACKET_PRESETS.balanced.slFraction === 0.125);
assert.ok(api.FNO_SCALPING_BRACKET_PRESETS.manual.targetFraction === 0.30);
assert.ok(api.FNO_SCALPING_BRACKET_PRESETS.manual.slFraction === 0.15);
assert.strictEqual(api.getScalpingBracketPresetId(), 'standard');

const std = api.computeBracketPrices(100, api.getScalpingBracketConfig());
assert.strictEqual(std.target, 120);
assert.strictEqual(std.sl, 90);

api.settings.set({ scalpingBracketPreset: 'balanced' });
const bal = api.computeBracketPrices(100, api.getScalpingBracketConfig());
assert.strictEqual(bal.target, 125);
assert.strictEqual(bal.sl, 87.5);

api.settings.set({ scalpingBracketPreset: 'manual', savedManualTargetPct: 35, savedManualSlPct: 12 });
const man = api.computeBracketPrices(100, api.getScalpingBracketConfig());
assert.strictEqual(man.target, 135);
assert.strictEqual(man.sl, 88);

const resolved = api.resolveTradeBracketForEntry(100, 'scalping');
assert.strictEqual(resolved.target, 135);
assert.ok(/scalping_bracket_manual/.test(resolved.source));

assert.ok(/FNO_SETTINGS_SCHEMA_VERSION = 6/.test(coreSrc));
assert.ok(/scalpingBracketPreset: 'standard'/.test(coreSrc));
assert.ok(/saveManualScalpingBracketFromUi/.test(coreSrc));
assert.ok(/syncTargetSlUiFromPreset/.test(coreSrc));
assert.ok(/resolveTradeBracketForEntry/.test(coreSrc));
assert.ok(/id="scalpingBracketPreset"/.test(phpSrc));
assert.ok(/saveManualBracketBtn/.test(phpSrc));
assert.ok(/settingScalpingBracketPreset/.test(phpSrc));

console.log('All scalping-bracket-presets tests passed.');
