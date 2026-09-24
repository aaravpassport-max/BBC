'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const lotStart = coreSrc.indexOf('const FNO_EXCHANGE_LOT_SIZES');
const lotEnd = coreSrc.indexOf('function fnoThemePalette');
const exitStart = coreSrc.indexOf('function validatePaperExitEconomics');
const exitEnd = coreSrc.indexOf('function classifyPaperExitLabels', exitStart);
const boot = [
  coreSrc.slice(lotStart, lotEnd),
  coreSrc.slice(exitStart, exitEnd),
  'return { normalizeUnderlyingSymbol, validatePaperExitEconomics, normalizeTradeAlertSymbol, resolveUiSymbol };',
].join('\n');
const api = new Function(boot.replace(/const FNO_/g, 'var FNO_'))();

console.log('\n=== trade exit integrity ===\n');

const open = { entryPrice: 96.95, target: 110, sl: 85, trailingEnabled: false };
assert.strictEqual(
  api.validatePaperExitEconomics(open, 'target', 109.5, 108).ok,
  false,
  'must block target exit when live premium below target'
);
const okTarget = api.validatePaperExitEconomics(open, 'target', 110.2, 109);
assert.ok(okTarget.ok);
assert.ok(okTarget.exitPrice <= 110.25, 'sell fill cannot exceed triggering live quote');

assert.strictEqual(
  api.validatePaperExitEconomics(open, 'sl', 90, 88).ok,
  false,
  'must block SL exit when live still above stop'
);

const fakeSelect = { value: 'NIFTY' };
assert.strictEqual(api.normalizeTradeAlertSymbol(fakeSelect), 'NIFTY');
assert.strictEqual(api.normalizeTradeAlertSymbol('[object HTMLSelectElement]'), 'NIFTY');
assert.strictEqual(api.normalizeTradeAlertSymbol('[object HTMLSelectElement]', { symbol: 'BANKNIFTY' }), 'BANKNIFTY');

assert.ok(/resolveUiSymbol\(params\.symbol\)/.test(coreSrc));
assert.ok(/symbol: symForLot/.test(coreSrc));
assert.ok(/validatePaperExitEconomics\(open, exitReason/.test(coreSrc));
assert.ok(/speExit\.reason, sym, liveNow/.test(coreSrc));

console.log('trade exit integrity OK');
