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
const IND = sandbox.FNO_CHART_INDICATORS;

assert.ok(JSON.stringify(X.loadWatchlist()) === JSON.stringify(['NIFTY', 'BANKNIFTY', 'FINNIFTY']));

IND.saveInstances(IND.loadInstances().concat([
  { instanceId: 'rsi1', typeId: 'rsi', enabled: true, params: { period: 14, color: '#a78bfa', lineWidth: 1.5, lineStyle: 'solid' } },
  { instanceId: 'macd1', typeId: 'macd', enabled: true, params: { fast: 12, slow: 26, signal: 9, color: '#22d3ee', lineWidth: 1.5, lineStyle: 'solid' } },
  { instanceId: 'bb1', typeId: 'bollinger', enabled: true, params: { period: 20, stdDev: 2, color: '#94a3b8', lineWidth: 1, lineStyle: 'solid' } },
]));

const bundle = {
  panels: [{
    typeId: 'macd',
    panel: {
      lines: [
        { id: 'macd', values: [-0.5, -0.1, 0.2] },
        { id: 'signal', values: [-0.3, -0.05, 0.05] },
      ],
    },
  }],
  overlays: [{
    typeId: 'bollinger',
    lines: [
      { id: 'upper', values: [102, 103, 104] },
      { id: 'lower', values: [98, 97, 96] },
    ],
  }],
};

X.addIndicatorAlert({ symbol: 'NIFTY', kind: 'rsi', level: 70, direction: 'above' });
const rsiId = X.loadIndicatorAlerts()[0].id;
X.evaluateIndicatorAlerts('NIFTY', { panels: [{ typeId: 'rsi', panel: { lines: [{ id: 'rsi', values: [50, 65] }] } }] }, 100, 99, 0, null);
const rsiFired = X.evaluateIndicatorAlerts('NIFTY', { panels: [{ typeId: 'rsi', panel: { lines: [{ id: 'rsi', values: [50, 65, 72] }] } }] }, 100, 99, 2, 1);
assert.strictEqual(rsiFired.length, 1);

X.rearmAlert(rsiId);
assert.ok(X.loadIndicatorAlerts()[0].enabled);

X.addIndicatorAlert({ symbol: 'NIFTY', kind: 'macd_zero', direction: 'above' });
assert.ok(X.loadIndicatorAlerts().some((a) => a.kind === 'macd_zero'));
X.evaluateIndicatorAlerts('NIFTY', bundle, 101, 100, 1, 0);
const macdFired = X.evaluateIndicatorAlerts('NIFTY', bundle, 101, 100, 2, 1);
assert.strictEqual(macdFired.length, 1);

X.addIndicatorAlert({ symbol: 'NIFTY', kind: 'bb_upper', direction: 'above' });
const bbFired = X.evaluateIndicatorAlerts('NIFTY', bundle, 105, 103, 2, 1);
assert.strictEqual(bbFired.length, 1);

const rep = X.addPriceAlert('NIFTY', 100, 'above', null, true);
assert.ok(rep.alert.repeat);
X.evaluatePriceAlerts('NIFTY', 101, 99);
assert.ok(X.loadPriceAlerts()[0].enabled, 'repeat price alert stays armed');

console.log('chart-watchlist-alerts tests passed');
