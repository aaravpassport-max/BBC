'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const sandbox = { console, global: {}, localStorage: { _d: {}, getItem(k) { return this._d[k] || null; }, setItem(k, v) { this._d[k] = String(v); } } };
sandbox.global = sandbox;
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/chart-pine-compiler.js'), 'utf8'), sandbox);
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/chart-indicator-engine.js'), 'utf8'), sandbox);

const PINE = sandbox.FNO_CHART_PINE;
const FNO = sandbox.FNO_CHART_INDICATORS;

const emaPine = `//@version=5
indicator("My EMA", overlay=true)
len = input.int(14, "Length")
plot(ta.ema(close, len), color=color.orange)`;

const ema = PINE.compilePineScript(emaPine);
assert.strictEqual(ema.ok, true);
assert.strictEqual(ema.formula, 'ema(close, len)');
assert.strictEqual(ema.params.len, 14);
assert.strictEqual(ema.type, 'overlay');
assert.strictEqual(ema.color, '#FF9800');

const rsiPine = `//@version=5
indicator("RSI", overlay=false)
length = input.int(14, "RSI length")
plot(ta.rsi(close, length))`;

const rsi = PINE.compilePineScript(rsiPine);
assert.strictEqual(rsi.ok, true);
assert.strictEqual(rsi.formula, 'rsi(close, length)');
assert.strictEqual(rsi.type, 'panel');
assert.strictEqual(rsi.panelMin, 0);
assert.strictEqual(rsi.panelMax, 100);

const chainPine = `//@version=5
indicator("Chained", overlay=true)
fast = ta.ema(close, 9)
slow = ta.ema(close, 21)
plot(fast - slow)`;

const chain = PINE.compilePineScript(chainPine);
assert.strictEqual(chain.ok, true);
assert.strictEqual(chain.formula, '(ema(close, 9)) - (ema(close, 21))');

const bad = PINE.compilePineScript(`//@version=5
indicator("X")
x = request.security("NSE:RELIANCE", "D", close)
plot(x)`);
assert.strictEqual(bad.ok, false);
assert.ok(/Cross-symbol|not supported/i.test(bad.error));

const htfPine = `//@version=5
indicator("Daily close", overlay=true)
d = request.security(syminfo.tickerid, "D", close)
plot(ta.ema(d, 9), color=color.orange)`;
const htf = PINE.compilePineScript(htfPine);
assert.strictEqual(htf.ok, true);
assert.ok(/htf\(close,\s*1440\)/.test(htf.formula), 'formula=' + htf.formula);

const strat = PINE.compilePineScript(`//@version=5
strategy("X")
plot(close)`);
assert.strictEqual(strat.ok, false);
assert.ok(/strategy/i.test(strat.error));

const candles = [];
for (let i = 0; i < 40; i++) candles.push({ t: i, o: 100 + i, h: 101 + i, l: 99 + i, c: 100 + i, v: 10 });
const saved = FNO.saveCustomDefinitionFromPine(emaPine);
assert.strictEqual(saved.ok, true);
const ev = FNO.evaluateFormula(saved.def.formula, candles, saved.def.params);
assert.strictEqual(ev.error, null);
assert.ok(Number.isFinite(ev.values[ev.values.length - 1]));

assert.strictEqual(PINE.looksLikePineSource(emaPine), true);
assert.strictEqual(PINE.looksLikePineSource('{"format":"fno-indicator-pack-v1"}'), false);

const imported = FNO.importCustomDefinitionsPack(rsiPine, { merge: true });
assert.strictEqual(imported.ok, true);
assert.strictEqual(imported.pine, true);

console.log('chart-pine-compiler tests passed');
