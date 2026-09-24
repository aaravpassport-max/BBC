'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const src = fs.readFileSync(path.join(__dirname, '../assets/chart-indicator-engine.js'), 'utf8');
const sandbox = { window: {}, localStorage: { _s: {}, getItem(k) { return this._s[k] || null; }, setItem(k, v) { this._s[k] = v; } } };
sandbox.window = sandbox;
vm.runInNewContext(src, sandbox);
const FNO = sandbox.FNO_CHART_INDICATORS;

const candles = [];
for (let i = 0; i < 40; i++) {
  const c = 100 + i * 0.5;
  candles.push({ t: 1000000 + i * 60000, o: c - 0.2, h: c + 1, l: c - 1, c, v: 100 + i });
}

const ema9 = FNO.evaluateFormula('ema(close, 9)', candles);
assert.ok(!ema9.error, ema9.error);
assert.ok(Number.isFinite(ema9.values[39]));

const macdLike = FNO.evaluateFormula('ema(close, 12) - ema(close, 26)', candles);
assert.ok(!macdLike.error, macdLike.error);

const bad = FNO.evaluateFormula('evil(close, 1)', candles);
assert.ok(bad.error);

const saved = FNO.saveCustomDefinition({ name: 'Test EMA 5', type: 'overlay', formula: 'ema(close, 5)', color: '#fff' });
assert.ok(saved.ok, saved.error);
assert.ok(FNO.getDefinition(saved.typeId));

const out = FNO.computeForCandles(candles, [
  { instanceId: 'i1', typeId: saved.typeId, enabled: true, params: {} },
]);
assert.strictEqual(out.overlays.length, 1);
assert.strictEqual(out.overlays[0].lines[0].values.length, candles.length);

FNO.deleteCustomDefinition(saved.def.id);
assert.strictEqual(FNO.listCustomDefinitions().length, 0);

console.log('chart-custom-formula tests passed');
