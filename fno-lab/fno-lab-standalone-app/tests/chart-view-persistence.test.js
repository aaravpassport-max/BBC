'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
assert.ok(end > 0);

const store = { _d: {} };
global.localStorage = {
  getItem(k) { return store._d[k] || null; },
  setItem(k, v) { store._d[k] = String(v); },
  removeItem(k) { delete store._d[k]; },
  clear() { store._d = {}; },
};
vm.runInThisContext(coreSource.slice(0, end));

_fnoChartViewStateForTest().timeframeMinutes = 1;
_fnoChartViewStateForTest().visibleCount = 80;
fnoChartSaveViewState();

_fnoChartViewStateForTest().timeframeMinutes = 5;
_fnoChartViewStateForTest().visibleCount = 120;
_fnoChartViewStateForTest().offsetFromEnd = 10;
_fnoChartViewStateForTest().priceZoom = 2;
_fnoChartViewStateForTest().pricePan = 0.3;
_fnoChartViewStateForTest().autoFitPrice = false;
fnoChartSaveViewState();

_fnoChartViewStateForTest().timeframeMinutes = 1;
_fnoChartViewStateForTest().visibleCount = 80;
_fnoChartViewStateForTest().offsetFromEnd = 0;
fnoChartLoadViewState();

assert.strictEqual(_fnoChartViewStateForTest().timeframeMinutes, 5);
assert.strictEqual(_fnoChartViewStateForTest().visibleCount, 120);
assert.strictEqual(_fnoChartViewStateForTest().offsetFromEnd, 10);
assert.strictEqual(_fnoChartViewStateForTest().priceZoom, 2);
assert.strictEqual(_fnoChartViewStateForTest().autoFitPrice, false);

assert.strictEqual(fnoChartCrosshairBarIndex({ x: 28 }, 8, 20, 400, 10), 1);
assert.strictEqual(fnoChartCrosshairBarIndex(null, 8, 20, 400, 10), null);

console.log('chart-view-persistence tests passed');
