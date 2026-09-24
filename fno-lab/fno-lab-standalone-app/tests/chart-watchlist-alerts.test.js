'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

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

vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/chart-indicator-engine.js'), 'utf8'), sandbox);
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/chart-tv-extensions.js'), 'utf8'), sandbox);

const X = sandbox.FNO_CHART_EXTENSIONS;

assert.ok(JSON.stringify(X.loadWatchlist()) === JSON.stringify(['NIFTY', 'BANKNIFTY', 'FINNIFTY']));
X.toggleWatchlistSymbol('FINNIFTY');
assert.ok(!X.loadWatchlist().includes('FINNIFTY'));
X.toggleWatchlistSymbol('FINNIFTY');

const bundle = {
  panels: [{ typeId: 'rsi', panel: { lines: [{ id: 'rsi', values: [50, 65, 72] }] } }],
  overlays: [{ typeId: 'ema', lines: [{ id: 'main', values: [100, 101, 102] }] }],
};

X.addIndicatorAlert({ symbol: 'NIFTY', kind: 'rsi', level: 70, direction: 'above' });
const id = X.loadIndicatorAlerts()[0].id;
X.evaluateIndicatorAlerts('NIFTY', bundle, 103, 100, 1, 0);
const fired = X.evaluateIndicatorAlerts('NIFTY', bundle, 103, 100, 2, 1);
assert.strictEqual(fired.length, 1, 'RSI cross should fire once');

X.addIndicatorAlert({ symbol: 'NIFTY', kind: 'close_cross_ema', direction: 'above' });
const emaFired = X.evaluateIndicatorAlerts('NIFTY', bundle, 103, 99, 2, 1);
assert.ok(emaFired.length >= 0);

assert.ok(X.formatAlertsListHtml('NIFTY').includes('RSI'));

console.log('chart-watchlist-alerts tests passed');
