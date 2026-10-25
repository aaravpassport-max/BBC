'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
assert.ok(/function recordPositionLtpTick/.test(coreSource));
assert.ok(/fno_position_ltp_history_v1/.test(coreSource));

const store = { _d: {} };
global.localStorage = {
  getItem(k) { return store._d[k] || null; },
  setItem(k, v) { store._d[k] = String(v); },
  removeItem(k) { delete store._d[k]; },
  clear() { store._d = {}; },
};

const end = coreSource.indexOf('\nfunction render(){');
vm.runInThisContext(coreSource.slice(0, end));

const open = { id: 12345, symbol: 'NIFTY', strike: 23200, optionType: 'CE', entryPrice: 100, target: 130, sl: 85, trailingEnabled: false };
recordPositionLtpTick(open, { spot: 23150, symbol: 'NIFTY' }, 101);
recordPositionLtpTick(open, { spot: 23155, symbol: 'NIFTY' }, 101);
recordPositionLtpTick(open, { spot: 23160, symbol: 'NIFTY' }, 104);

const series = getPositionLtpSeriesForOpen(open);
assert.strictEqual(series.length, 2, 'dedupes same LTP within min interval');
assert.strictEqual(series[0].ltp, 101);
assert.strictEqual(series[1].ltp, 104);

const visible = [
  { t: 1000, o: 1, h: 1, l: 1, c: 1 },
  { t: 2000, o: 1, h: 1, l: 1, c: 1 },
];
const mapped = fnoChartMapHistoryToVisibleBars([
  { ts: 950, ltp: 99 },
  { ts: 1900, ltp: 103 },
], visible, 60 * 60 * 1000);
assert.strictEqual(mapped[0], 99);
assert.strictEqual(mapped[1], 103);

console.log('chart-option-ltp tests passed');
