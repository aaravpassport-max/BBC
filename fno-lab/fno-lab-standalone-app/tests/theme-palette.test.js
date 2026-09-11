'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const bootStart = coreSrc.indexOf('function fnoThemePalette()');
const bootEnd = coreSrc.indexOf('function checkScalpingCapitalPreservation', bootStart);
assert.ok(bootStart > 0 && bootEnd > bootStart, 'fnoThemePalette exists');
const fn = new Function(coreSrc.slice(bootStart, bootEnd) + `
  global.document = { documentElement: { getAttribute: () => 'light' } };
  return fnoThemePalette();
`);
const light = fn();
assert.strictEqual(light.text, '#0f172a');
assert.strictEqual(light.panel, '#f1f5f9');
assert.strictEqual(light.accentBlue, '#1d4ed8');
assert.ok(/renderOptionChainTable[\s\S]*fnoThemePalette\(\)/.test(coreSrc));
assert.ok(/brain\.decision==='BUY_READY'[\s\S]*tpDec\.passBg/.test(coreSrc));

console.log('All theme-palette tests passed.');
