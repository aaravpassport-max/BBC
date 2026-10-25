'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const engineSrc = fs.readFileSync(path.join(__dirname, '../assets/liquidity-behaviour-engine.js'), 'utf8');
const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const php = fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8');
const fm = fs.readFileSync(path.join(__dirname, '../assets/failure-mode-library.json'), 'utf8');

assert.ok(/function computeLiquidityTrapEngine/.test(engineSrc));
assert.ok(/function computeLiquidityMap/.test(engineSrc));
assert.ok(/function computeLiquiditySweepSignal/.test(engineSrc));
assert.ok(/function computeCEPETrapStages/.test(engineSrc));
assert.ok(/function computePathBetweenLevels/.test(engineSrc));
assert.ok(/FNO_LIQUIDITY_TRAP_STATES/.test(engineSrc));
assert.ok(/trapScoreLabel/.test(engineSrc));
assert.ok(/probabilities/.test(engineSrc));
assert.ok(/evaluateLiquidityTrapOutcomes/.test(engineSrc));
assert.ok(/computeLiquidityTrapValidationStats/.test(engineSrc));
assert.ok(/computeMicrostructureAbsorptionBoost/.test(engineSrc));
assert.ok(/inferLiquidityTrapExpectedDirection/.test(engineSrc));

assert.ok(/strikeShiftForRefresh/.test(coreSrc), 'strike shift must use pre-ctx cache variable, not refreshCtx before initialization');
const refreshCtxDeclPos = coreSrc.indexOf('const refreshCtx={');
const earlyRefreshCtxStrikeShift = coreSrc.indexOf('refreshCtx.strikeShift');
assert.ok(refreshCtxDeclPos > 0, 'const refreshCtx={ must exist');
assert.ok(earlyRefreshCtxStrikeShift === -1 || earlyRefreshCtxStrikeShift > refreshCtxDeclPos, 'refreshCtx.strikeShift must not appear before const refreshCtx={');
assert.ok(/const refreshCtx=\{/.test(coreSrc), 'refreshBrain uses refreshCtx instead of ctx to avoid TDZ collisions');
assert.ok(/fetchOIAccumulationHistory/.test(coreSrc));
assert.ok(/check\('FM088'/.test(coreSrc));
assert.ok(/evaluateLiquidityTrapOutcomes\(spot, sym\)/.test(coreSrc));
assert.ok(/disproofs/.test(engineSrc));

assert.ok(/computeLiquidityTrapEngine\(ctx, brain/.test(coreSrc));
assert.ok(/renderLiquidityBehaviourPanel/.test(coreSrc));
assert.ok(/ctx\.liquidityInputs/.test(coreSrc));
assert.ok(/check\('FM157'/.test(coreSrc));
assert.ok(/check\('FM158'/.test(coreSrc));
assert.ok(/check\('FM159'/.test(coreSrc));

assert.ok(/liquidity-behaviour-engine\.js/.test(php));
assert.ok(/liquidityBehaviourBox/.test(php));
assert.ok(/"FM157"/.test(fm));
assert.ok(/"FM158"/.test(fm));
assert.ok(/"FM159"/.test(fm));

console.log('All liquidity-behaviour-engine tests passed.');
