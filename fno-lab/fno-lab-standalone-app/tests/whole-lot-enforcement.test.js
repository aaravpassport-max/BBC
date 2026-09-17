'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const start = coreSrc.indexOf('const FNO_EXCHANGE_LOT_SIZES');
const palette = coreSrc.indexOf('function fnoThemePalette');
const partialStart = coreSrc.indexOf('function simulatePartialFillQty');
const partialEnd = coreSrc.indexOf('\n}\n\n/**', partialStart);
assert.ok(start >= 0 && palette > start && partialEnd > partialStart);
const bootSrc = [
  coreSrc.slice(start, palette),
  coreSrc.slice(partialStart, partialEnd + 2),
  'return { finalizeExchangeOrderQty, floorQtyToWholeLots, simulatePartialFillQty, getExchangeLotSize, isValidExchangeQty };',
].join('\n');
const api = new Function(bootSrc.replace(/const FNO_/g, 'var FNO_'))();

console.log('\n=== whole-lot enforcement (v16.37.24) ===\n');

assert.strictEqual(api.finalizeExchangeOrderQty('NIFTY', 150).ok, true);
assert.strictEqual(api.finalizeExchangeOrderQty('NIFTY', 150).qty, 150);
assert.strictEqual(api.finalizeExchangeOrderQty('NIFTY', 37).ok, false);
assert.strictEqual(api.finalizeExchangeOrderQty('NIFTY', 65).ok, false);
assert.strictEqual(api.finalizeExchangeOrderQty('NIFTY', 80).ok, true);
assert.strictEqual(api.finalizeExchangeOrderQty('NIFTY', 80).qty, 75);

const depthPartial = api.simulatePartialFillQty({ askQty: 65, bidQty: 500 }, 150, 'buy', 'NIFTY');
assert.strictEqual(depthPartial.filledQty, 0, '65 resting qty must not produce odd partial lot fill');
assert.strictEqual(depthPartial.isPartial, false);

const depthOneLot = api.simulatePartialFillQty({ askQty: 100, bidQty: 500 }, 150, 'buy', 'NIFTY');
assert.strictEqual(depthOneLot.filledQty, 75);
assert.strictEqual(api.isValidExchangeQty('NIFTY', depthOneLot.filledQty), true);

assert.ok(coreSrc.includes('FNO_CORE_BUILD_MARKER'), 'core must expose build marker for upgrade verification');
assert.ok(coreSrc.includes('all-or-nothing fill'), 'tryOpen must reject partial depth fills');

console.log('whole-lot enforcement OK');
