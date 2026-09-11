'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const funnelStart = coreSrc.indexOf('function funnelBlockLabel(meta)');
const funnelEnd = coreSrc.indexOf('\n/**\n * TRACE: Real, trade-type-aware category weighting', funnelStart);
const rejectionStart = coreSrc.indexOf('const FNO_EXEC_REJECTION = {');
const rejectionEnd = funnelStart;
const logStart = coreSrc.indexOf('const FNO_DECISION_LOG_KEY');
const logEnd = coreSrc.indexOf('\n/**\n * TRACE: Real "missed opportunity" analysis', logStart);

const bootSrc = [
  'var localStorage = { _d: {}, getItem(k){ return this._d[k]||null; }, setItem(k,v){ this._d[k]=String(v); } };',
  coreSrc.slice(rejectionStart, rejectionEnd),
  coreSrc.slice(logStart, logEnd),
  coreSrc.slice(funnelStart, funnelEnd),
  'return { classifyExecutionRejection, FNO_EXEC_REJECTION, logDecisionSnapshot, getDecisionLog, computeEligibilityFunnel, funnelBlockLabel };',
].join('\n');

const api = new Function(bootSrc.replace(/const FNO_/g, 'var FNO_').replace(/function /g, 'function '))();

const t0 = Date.now();
for (let i = 0; i < 40; i++) {
  api.logDecisionSnapshot({
    ts: t0 + i * 1000,
    sym: 'NIFTY',
    decision: 'WAIT',
    tradeOpened: false,
    blockReason: 'No directional signal this refresh (WAIT).',
    tradingType: 'scalping',
  });
}
for (let i = 0; i < 5; i++) {
  api.logDecisionSnapshot({
    ts: t0 + 50000 + i * 1000,
    sym: 'NIFTY',
    decision: 'BUY_READY',
    tradeOpened: false,
    blockReason: 'Autonomous Mode is not enabled - a real BUY/SELL signal existed this refresh but no automatic open was attempted.',
    rejectionCategory: api.FNO_EXEC_REJECTION.STRATEGY_RULE,
    rejectionSubcategory: 'autonomous_off',
    tradingType: 'scalping',
  });
}
api.logDecisionSnapshot({
  ts: t0 + 60000,
  sym: 'NIFTY',
  decision: 'BUY_READY',
  tradeOpened: true,
  tradingType: 'scalping',
});

const funnel = api.computeEligibilityFunnel(api.getDecisionLog(), { limit: 100 });
assert.strictEqual(funnel.totalRefreshes, 46);
assert.strictEqual(funnel.potentialSetups, 6);
assert.strictEqual(funnel.opened, 1);
assert.strictEqual(funnel.setupBlocked, 5);
assert.ok(funnel.breakdown['Autonomous Mode OFF'] >= 5);
assert.ok(funnel.eligibilityRatePct > 0 && funnel.eligibilityRatePct < 20);
assert.ok(funnel.executionRatePct > 0 && funnel.executionRatePct < 25);

const zeroExecLog = [];
for (let i = 0; i < 12; i++) {
  zeroExecLog.push({
    ts: t0 + 70000 + i * 1000,
    sym: 'NIFTY',
    decision: 'BUY_READY',
    tradeOpened: false,
    blockReason: 'Autonomous Mode is not enabled',
  });
}
const zeroFunnel = api.computeEligibilityFunnel(zeroExecLog, { limit: 100 });
assert.ok(zeroFunnel.alert && /zero executed/i.test(zeroFunnel.alert.message), 'should alert when setups but zero executions');

const lowSignal = [];
for (let i = 0; i < 35; i++) {
  lowSignal.push({ ts: t0 + i, sym: 'NIFTY', decision: 'WAIT', tradeOpened: false });
}
const lowFunnel = api.computeEligibilityFunnel(lowSignal, { limit: 100 });
assert.ok(lowFunnel.alert && /eligibility rate unusually low/i.test(lowFunnel.alert.message));

assert.strictEqual(
  api.funnelBlockLabel(api.classifyExecutionRejection('BUY_READY', 'Autonomous Mode is not enabled')),
  'Autonomous Mode OFF'
);

assert.ok(/function renderEligibilityFunnel\(/.test(coreSrc));
assert.ok(/renderEligibilityFunnel\(sym\)/.test(coreSrc));
assert.ok(/scalpingProfitProfileEnabled: true/.test(coreSrc));
assert.ok(/tradingTypes: \{ intraday: false, scalping: true/.test(coreSrc));
assert.ok(/FNO_SETTINGS_SCHEMA_VERSION = 8/.test(coreSrc));
assert.ok(/topCritFailReasonsList/.test(coreSrc));

const critLog = [];
for (let i = 0; i < 10; i++) {
  critLog.push({ ts: t0 + 80000 + i, sym: 'NIFTY', decision: 'NO_TRADE', critFailIds: ['Value Decay'], tradeOpened: false });
}
const critFunnel = api.computeEligibilityFunnel(critLog, { limit: 100 });
assert.strictEqual(critFunnel.noSignalNoTrade, 10);
assert.ok(critFunnel.topCritFailReasonsList.some(r => r.reason === 'Value Decay' && r.count === 10));
assert.ok(/defaultLots: 2/.test(coreSrc));
assert.ok(/scalpingCapitalPreservationEnabled: true/.test(coreSrc));

console.log('All eligibility-funnel tests passed.');
