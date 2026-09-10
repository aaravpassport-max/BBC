'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');

assert.ok(/autoCalibrateTargetWinRatePct: 65/.test(coreSrc));
assert.ok(/autoCalibrateMinSampleSize: 20/.test(coreSrc));
assert.ok(/defaultLots: 2/.test(coreSrc));
assert.ok(/scalpingTrailingEnabled: true/.test(coreSrc));
assert.ok(/scalpingPartialExitEnabled: true/.test(coreSrc));
assert.ok(/scalpingCapitalPreservationEnabled: true/.test(coreSrc));
assert.ok(/FNO_SETTINGS_SCHEMA_VERSION = 6/.test(coreSrc));
assert.ok(/FNO_SCALPING_BRACKET_PRESETS/.test(coreSrc));
assert.ok(/standard:[\s\S]*targetFraction: 0\.20/.test(coreSrc));
assert.ok(/balanced:[\s\S]*targetFraction: 0\.25/.test(coreSrc));
assert.ok(/manual:[\s\S]*targetFraction: 0\.30/.test(coreSrc));
assert.ok(/FNO_EXCHANGE_LOT_SIZES/.test(coreSrc));
assert.ok(/isMicrostructureDaemonOnline/.test(coreSrc));
assert.ok(/tradingType === 'scalping' && isScalpingProfitProfileActive\(\)/.test(coreSrc));
assert.ok(/minSampleSize: profileActive \? FNO_SCALPING_PROFIT_PROFILE\.autoCalibrateMinSampleSize/.test(coreSrc));

console.log('All scalping-improvements tests passed.');
