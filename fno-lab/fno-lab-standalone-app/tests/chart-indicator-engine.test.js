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
for (let i = 0; i < 30; i++) {
  const c = 100 + i;
  candles.push({ t: 1000000 + i * 60000, o: c - 1, h: c + 2, l: c - 2, c, v: 100 + i });
}

const inst = [
  { instanceId: 'e1', typeId: 'ema', enabled: true, params: { period: 21 } },
  { instanceId: 'v1', typeId: 'vwap', enabled: true, params: {} },
  { instanceId: 'r1', typeId: 'rsi', enabled: true, params: { period: 14 } },
];
const out = FNO.computeForCandles(candles, inst);
assert.strictEqual(out.overlays.length, 2);
assert.strictEqual(out.panels.length, 1);
assert.strictEqual(out.overlays[0].lines[0].values.length, candles.length);
assert.ok(Number.isFinite(out.overlays[1].lines[0].values[29]));
assert.ok(out.panels[0].panel.lines[0].values.length === candles.length);

FNO.saveInstances(inst);
assert.ok(FNO.loadInstances().length === 3);

console.log('chart-indicator-engine tests passed');
