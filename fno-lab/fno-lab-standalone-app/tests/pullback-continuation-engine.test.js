'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const tseSrc = fs.readFileSync(path.join(__dirname, '../assets/trade-setup-engine.js'), 'utf8');
const phpSrc = fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8');

// Backward-compat: PBCE wrappers live in trade-setup-engine.js
assert.ok(/resolvePullbackContinuationBracket/.test(tseSrc));
assert.ok(/computePullbackContinuationSetup/.test(tseSrc));
assert.ok(/trade-setup-engine\.js/.test(phpSrc));
assert.ok(!/pullback-continuation-engine\.js/.test(phpSrc));
assert.ok(/checkTradeSetupEntryGate/.test(coreSrc));
assert.ok(/makeTradeDecision/.test(coreSrc));
assert.ok(/pullbackContinuationBox/.test(phpSrc));
assert.ok(/settingPullbackContinuation/.test(phpSrc));

console.log('All pullback-continuation-engine (TSE compat) tests passed.');
