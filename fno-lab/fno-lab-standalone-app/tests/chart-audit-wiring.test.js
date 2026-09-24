'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const php = fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8');
assert.ok(/id="priceChartSection"/.test(php), 'chart section wrapper must exist for resize/fullscreen');
assert.ok(/id="chartFullscreen"/.test(php), 'fullscreen control must exist in markup');
assert.ok(/fno-chart-section--fullscreen/.test(php), 'fullscreen CSS class must be defined');
assert.ok(/min-height:480px/.test(php), 'chart must use larger default plot height than old 420px inline');

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
assert.ok(/function fnoChartWireLayoutObserver/.test(coreSource));
assert.ok(/function fnoChartBuildCrosshairReadout/.test(coreSource));
assert.ok(/function fnoChartClassifyExitMarker/.test(coreSource));
assert.ok(/id="chartShowVolume"/.test(php), 'Show Volume checkbox must exist in chart toolbar');
assert.ok(/id="chartIndicatorDiagnostics"/.test(php), 'chart must show live indicator diagnostics line for user verification');
assert.ok(/function fnoChartSetVolumeIndicatorEnabled/.test(coreSource), 'volume checkbox must drive indicator instances');
assert.ok(/function fnoChartSyncIndicatorDiagnostics/.test(coreSource), 'live indicator diagnostics must be implemented in core');
assert.ok(/function fnoChartEnsureChartUiWired/.test(coreSource), 'chart UI wiring must retry until indicator scripts/DOM ready');
const ensureIdx = coreSource.indexOf('fnoChartEnsureChartUiWired()');
const guardIdx = coreSource.indexOf('if (fnoChartViewState.controlsWired) return', ensureIdx);
assert.ok(ensureIdx > 0 && guardIdx > ensureIdx, 'indicator UI wiring must run before controlsWired early exit');
assert.ok(/function _fnoChartHasVolumePanelForTest/.test(coreSource), 'harness volume panel probe must exist');

const end = coreSource.indexOf('\nfunction render(){');
assert.ok(end > 0, 'render() boundary required');
global.localStorage = global.localStorage || { _d: {}, getItem(k) { return this._d[k] || null; }, setItem(k, v) { this._d[k] = String(v); }, removeItem(k) { delete this._d[k]; }, clear() { this._d = {}; } };
vm.runInThisContext(coreSource.slice(0, end));

assert.strictEqual(fnoChartClassifyExitMarker('target hit'), 'target');
assert.strictEqual(fnoChartClassifyExitMarker('stop loss'), 'sl');
assert.strictEqual(fnoChartClassifyExitMarker('square_off'), 'square');
assert.strictEqual(fnoChartClassifyExitMarker('manual'), 'other');

const candles = [{ t: 1000, o: 10, h: 12, l: 9, c: 11, v: 1 }];
const bundle = { overlays: [{ name: 'EMA', lines: [{ id: 'main', values: [10.5] }] }], panels: [{ typeId: 'rsi', panel: { title: 'RSI (14)', lines: [{ id: 'rsi', values: [55] }] } }] };
const text = fnoChartBuildCrosshairReadout(
  { x: 20, price: 10.8 },
  candles,
  0,
  8,
  20,
  400,
  bundle,
  false
);
assert.ok(text.includes('O 10'), 'crosshair readout includes OHLC');
assert.ok(text.includes('EMA'), 'crosshair includes overlay values');
assert.ok(text.includes('RSI'), 'crosshair includes panel indicator values');

console.log('chart-audit-wiring tests passed');
