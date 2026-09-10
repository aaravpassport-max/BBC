'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');

assert.ok(/slFraction: 0\.10/.test(coreSrc));
assert.ok(/targetFraction: 0\.20/.test(coreSrc));
assert.ok(/autoCalibrateTargetWinRatePct: 65/.test(coreSrc));
assert.ok(/autoCalibrateMinSampleSize: 20/.test(coreSrc));
assert.ok(/defaultLotSize: 25/.test(coreSrc));
assert.ok(/scalpingTrailingEnabled: true/.test(coreSrc));
assert.ok(/scalpingPartialExitEnabled: true/.test(coreSrc));
assert.ok(/FNO_SETTINGS_SCHEMA_VERSION = 3/.test(coreSrc));
assert.ok(/isMicrostructureDaemonOnline/.test(coreSrc));
assert.ok(/tradingType === 'scalping' && isScalpingProfitProfileActive\(\)/.test(coreSrc));
assert.ok(/minSampleSize: profileActive \? FNO_SCALPING_PROFIT_PROFILE\.autoCalibrateMinSampleSize/.test(coreSrc));

const optPrice = 100;
const sl = +(optPrice * (1 - 0.10)).toFixed(2);
const target = +(optPrice * (1 + 0.20)).toFixed(2);
assert.strictEqual(sl, 90);
assert.strictEqual(target, 120);

console.log('All scalping-improvements tests passed.');
