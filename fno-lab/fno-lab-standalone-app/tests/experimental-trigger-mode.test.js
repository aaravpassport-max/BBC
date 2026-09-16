'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const modesSrc = fs.readFileSync(path.join(__dirname, '../assets/trading-modes-engine.js'), 'utf8');

assert.match(coreSrc, /function isVirtualSimulatedAutoTradeContext\(\)/);
assert.match(coreSrc, /function shouldUseRelaxedPaperExecutionLane\(\)/);
assert.match(coreSrc, /m === 'paper' \|\| m === 'live'/);
assert.match(coreSrc, /shouldPushValueDecayCriticalFail[\s\S]*isExperimentalTradeTriggerMode/);
assert.match(coreSrc, /relaxFmBlocks/);
assert.match(modesSrc, /experimental_trigger:/);
assert.match(modesSrc, /relaxExecutionGates: true/);

console.log('experimental trigger mode wiring tests passed.');
