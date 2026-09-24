'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const indSrc = fs.readFileSync(path.join(__dirname, '../assets/chart-indicator-engine.js'), 'utf8');
const extSrc = fs.readFileSync(path.join(__dirname, '../assets/chart-tv-extensions.js'), 'utf8');
const store = { _d: {} };
const sandbox = {
  console,
  localStorage: {
    getItem(k) { return store._d[k] || null; },
    setItem(k, v) { store._d[k] = String(v); },
    removeItem(k) { delete store._d[k]; },
    clear() { store._d = {}; },
  },
};
sandbox.window = sandbox;
sandbox.global = sandbox;

vm.runInNewContext(indSrc, sandbox);
vm.runInNewContext(extSrc, sandbox);

const candles = [];
for (let i = 0; i < 50; i++) {
  const c = 100 + i * 0.2;
  candles.push({ t: i * 60000, o: c - 0.1, h: c + 0.2, l: c - 0.2, c, v: 100 + i });
}

const paramFormula = sandbox.FNO_CHART_INDICATORS.evaluateFormula('ema(close, period)', candles, { period: 5 });
assert.strictEqual(paramFormula.error, null, paramFormula.error);
assert.ok(Number.isFinite(paramFormula.values[49]));

const saved = sandbox.FNO_CHART_INDICATORS.saveCustomDefinition({
  name: 'Param EMA',
  type: 'overlay',
  formula: 'ema(close, period)',
  params: { period: 8 },
});
assert.strictEqual(saved.ok, true);

const pack = sandbox.FNO_CHART_INDICATORS.exportCustomDefinitionsPack();
assert.strictEqual(pack.format, 'fno-indicator-pack-v1');
assert.ok(pack.indicators.length >= 1);

store._d = {};
const imp = sandbox.FNO_CHART_INDICATORS.importCustomDefinitionsPack(JSON.stringify(pack));
assert.strictEqual(imp.ok, true);
assert.ok(sandbox.FNO_CHART_INDICATORS.listCustomDefinitions().length >= 1);

sandbox.FNO_CHART_EXTENSIONS.addDrawing('NIFTY', { type: 'hline', price: 105.5, color: '#fff' });
assert.strictEqual(sandbox.FNO_CHART_EXTENSIONS.loadDrawingsForSymbol('NIFTY').length, 1);

const preset = sandbox.FNO_CHART_EXTENSIONS.captureLayoutPreset('Test preset');
assert.strictEqual(preset.ok, true);
const applied = sandbox.FNO_CHART_EXTENSIONS.applyLayoutPreset(preset.preset.id);
assert.strictEqual(applied.ok, true);

sandbox.FNO_CHART_EXTENSIONS.addPriceAlert('NIFTY', 101, 'above');
const fired = sandbox.FNO_CHART_EXTENSIONS.evaluatePriceAlerts('NIFTY', 102, 100);
assert.strictEqual(fired.length, 1);

assert.ok(sandbox.FNO_CHART_EXTENSIONS.loadWatchlist().length >= 1);

console.log('chart-tv-extensions tests passed');
