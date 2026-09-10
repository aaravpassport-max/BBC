'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');

const bootStart = coreSrc.indexOf('const FNO_EXCHANGE_LOT_SIZES');
const bootEnd = coreSrc.indexOf('/** One-time schema migrations', bootStart);
const preservationStart = coreSrc.indexOf('function checkScalpingCapitalPreservation');
const preservationEnd = coreSrc.indexOf('/** One-time schema migrations', preservationStart);

const bootSrc = [
  coreSrc.slice(bootStart, bootEnd),
  coreSrc.slice(preservationStart, preservationEnd),
  'var fnoSettings = { get(){ return { scalpingCapitalPreservationEnabled: true, maxLosingTradesPerDay: 1, maxDailyLossPctPreservation: 1.5, scalpingProfitProfileEnabled: true, tradingTypes: { scalping: true } }; } };',
  'function isScalpingProfitProfileActive(){ return true; }',
  'return { normalizeUnderlyingSymbol, getExchangeLotSize, lotsToQty, qtyToLots, isValidExchangeQty, resolveOrderQuantity, formatLotQtyLabel, checkScalpingCapitalPreservation };',
].join('\n');

const api = new Function(bootSrc.replace(/const FNO_/g, 'var FNO_'))();

assert.strictEqual(api.getExchangeLotSize('NIFTY'), 75);
assert.strictEqual(api.getExchangeLotSize('BANKNIFTY'), 15);
assert.strictEqual(api.getExchangeLotSize('FINNIFTY'), 40);
assert.strictEqual(api.lotsToQty('NIFTY', 2), 150);
assert.strictEqual(api.lotsToQty('BANKNIFTY', 2), 30);
assert.strictEqual(api.qtyToLots('NIFTY', 150), 2);
assert.ok(api.isValidExchangeQty('NIFTY', 150));
assert.ok(!api.isValidExchangeQty('NIFTY', 2));
assert.ok(/2 lots = 150 qty \(75\/lot\)/.test(api.formatLotQtyLabel('NIFTY', 2)));

const blocked = api.checkScalpingCapitalPreservation(
  { confidence: 'Medium', pretradeGateCheck: null },
  { todayPnL: 0, accountAvailableCapital: 100000 },
  { journalToday: [] }
);
assert.ok(!blocked.allowed);
assert.ok(/High confidence/.test(blocked.reason));

const afterLoss = api.checkScalpingCapitalPreservation(
  { confidence: 'High', pretradeGateCheck: { finalAction: 'none', triggered: [] } },
  { todayPnL: -100, accountAvailableCapital: 100000 },
  { journalToday: [{ pnl: -500 }] }
);
assert.ok(!afterLoss.allowed);
assert.ok(/losing trade/i.test(afterLoss.reason));

assert.ok(/FNO_SETTINGS_SCHEMA_VERSION = 7/.test(coreSrc));
assert.ok(/defaultLots: 2/.test(coreSrc));
assert.ok(/scalpingCapitalPreservationEnabled: true/.test(coreSrc));
assert.ok(/function getLotCountFromUi/.test(coreSrc));
assert.ok(/function resolveOrderQuantity/.test(coreSrc));
assert.ok(/FM149/.test(coreSrc));
assert.ok(/getLotCountFromUi\(\)/.test(coreSrc));
assert.ok(/resolveOrderQuantity\(symVal, lotCount\)/.test(coreSrc));

console.log('All exchange-lots tests passed.');
