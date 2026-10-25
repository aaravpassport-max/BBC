'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const evalStart = coreSrc.indexOf('function evaluateBrain(ctx)');
const evalEnd = coreSrc.indexOf('\nfunction ', evalStart + 100);
const chunk = coreSrc.slice(0, evalEnd);

const boot = [
  chunk.match(/function getBrainMaxLossCriticalThresholdRs[\s\S]*?^}/m)[0],
  chunk.match(/function shouldPersonalChecklistItemCriticalFail[\s\S]*?^}/m)[0],
  chunk.match(/function getBrainVixOptimalBand[\s\S]*?^}/m)[0],
  chunk.match(/function getBrainPcrNeutralBand[\s\S]*?^}/m)[0],
  'var fnoSettings = { get(){ return { maxDailyLossPctPreservation: 1.5, scalpingProfitProfileEnabled: true, tradingTypes: { scalping: true } }; } };',
  'function isScalpingProfitProfileActive(){ return fnoSettings.get().scalpingProfitProfileEnabled && fnoSettings.get().tradingTypes.scalping; }',
  'return { getBrainMaxLossCriticalThresholdRs, shouldPersonalChecklistItemCriticalFail, getBrainVixOptimalBand, getBrainPcrNeutralBand, isScalpingProfitProfileActive };',
].join('\n');

const api = new Function(boot)();

assert.ok(api.getBrainMaxLossCriticalThresholdRs({ assumedCapital: 100000 }) >= 4500);
assert.strictEqual(api.shouldPersonalChecklistItemCriticalFail('mindset'), false);
assert.strictEqual(api.getBrainVixOptimalBand().lo, 10);
assert.strictEqual(api.getBrainPcrNeutralBand().lo, 0.65);

console.log('eligibility-loosening-v16-37-20 tests passed.');
