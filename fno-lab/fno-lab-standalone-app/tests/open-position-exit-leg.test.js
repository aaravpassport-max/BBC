'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const start = coreSrc.indexOf('function resolveOcRowByStrike');
const end = coreSrc.indexOf('function checkTradeExit', start);
assert.ok(start !== -1 && end !== -1, 'resolveOpenPositionLeg block not found');
const api = new Function(coreSrc.slice(start, end) + '\nreturn { resolveOpenPositionLeg, clampExitPriceToObservedExcursion };')();
const { resolveOpenPositionLeg, clampExitPriceToObservedExcursion } = api;

const rows = [
  { strikePrice: 24500, CE: { lastPrice: 120 }, PE: { lastPrice: 95 } },
  { strikePrice: 25000, CE: { lastPrice: 210 }, PE: { lastPrice: 180 } },
];
const open = { strike: 24500, optionType: 'CE' };
const resolved = resolveOpenPositionLeg(open, rows);
assert.strictEqual(resolved.leg.lastPrice, 120, 'must use open strike leg');

const uiWrong = resolveOpenPositionLeg(open, rows);
assert.notStrictEqual(uiWrong.leg.lastPrice, 210, 'must not use UI 25000 CE LTP for 24500 open');

assert.strictEqual(clampExitPriceToObservedExcursion({ mfe: 130, entryPrice: 100 }, 210, 'target'), 130);
assert.strictEqual(clampExitPriceToObservedExcursion({ mfe: 130, entryPrice: 100 }, 125, 'target'), 125);

assert.ok(/resolveOpenPositionLeg\(open, ocRows\)/.test(coreSrc), 'renderOpenTrades must resolve leg from open position');
assert.ok(/resolveOcRowByStrike\(curCtx\.ocRows/.test(coreSrc), 'tryOpenAutoTradePosition must use exact strike row');

console.log('open-position-exit-leg tests passed');
