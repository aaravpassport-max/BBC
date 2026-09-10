'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const reportSrc = fs.readFileSync(path.join(__dirname, '../assets/strategy-diagnostic-report.js'), 'utf8');
const bootSrc = [
  'function computeMissedOpportunityAnalysis(log) {',
  '  return { blockedButFavorable: (log || []).filter(e => e.decision === "BUY_READY" && !e.tradeOpened).slice(0, 2).map(e => ({ ts: e.ts, blockReason: e.blockReason || "test" })), waitNearMissButMoved: [], unpredictableMoves: [] };',
  '}',
  'function computeFalsePositiveAnalysis() { return { losingTrades: [], byFmId: {} }; }',
  'function computeFailureAnalysis(trades) {',
  '  const losses = (trades || []).filter(t => t.pnl < 0);',
  '  const categories = new Map();',
  '  categories.set("test_loss", { count: losses.length, totalLoss: losses.reduce((s,t)=>s+t.pnl,0) });',
  '  return { categories, totalLosses: losses.length };',
  '}',
  'function diagnoseEntryExitQuality(t) {',
  '  if (typeof t.entryPrice !== "number") return null;',
  '  return { entryQuality: t.pnl > 0 ? "Good entry" : "Poor entry", exitQuality: "Poor exit", mfeCapturedPct: 35 };',
  '}',
  'const FNO_PLUGIN_VERSION = "16.24.0";',
  'const FNO_STRATEGY_VERSION = "v1.0-baseline";',
  reportSrc,
  'return { buildStrategyDiagnosticReport, formatDiagnosticReportMarkdown, formatDiagnosticReportCsv, compareStrategyVersions, computePerformanceMetrics, buildEligibilityPipeline };',
].join('\n');

const api = new Function(bootSrc.replace(/const FNO_DIAGNOSTIC_REPORT_SCHEMA/g, 'var FNO_DIAGNOSTIC_REPORT_SCHEMA'))();

const t0 = Date.now();
const decisionLog = [];
for (let i = 0; i < 35; i++) {
  decisionLog.push({
    ts: t0 + i * 1000,
    sym: 'NIFTY',
    decision: 'WAIT',
    tradeOpened: false,
    blockReason: 'No directional signal',
    strategyVersion: 'v1.0-baseline',
  });
}
for (let i = 0; i < 4; i++) {
  decisionLog.push({
    ts: t0 + 40000 + i * 1000,
    sym: 'NIFTY',
    decision: 'BUY_READY',
    tradeOpened: false,
    blockReason: 'Autonomous Mode is not enabled',
    rejectionCategory: 'strategy_rule',
    directionalScore: 72,
    buyThreshold: 65,
    operatorIntelBias: 'bullish',
    regimeLabel: 'trending',
    strategyVersion: 'v1.0-baseline',
    signalOptionType: 'CE',
    atmStrike: 24500,
  });
}
decisionLog.push({
  ts: t0 + 50000,
  sym: 'NIFTY',
  decision: 'BUY_READY',
  tradeOpened: true,
  directionalScore: 78,
  strategyVersion: 'v1.0-baseline',
});

const tradeHistory = [
  { ts: t0 + 60000, symbol: 'NIFTY', pnl: 420, grossPnl: 450, costsTotal: 30, entryPrice: 120, exitPrice: 148, openedAt: t0 + 50000, mfe: 155, mae: 115, factorSnapshot: { strategyVersion: 'v1.0-baseline', regime: { label: 'trending' }, operatorBias: 'bullish', factors: {} } },
  { ts: t0 + 120000, symbol: 'NIFTY', pnl: -180, grossPnl: -160, costsTotal: 20, entryPrice: 90, exitPrice: 72, openedAt: t0 + 90000, mfe: 98, mae: 70, factorSnapshot: { strategyVersion: 'v1.0-baseline', regime: { label: 'choppy' }, operatorBias: 'neutral', factors: {} } },
  { ts: t0 + 180000, symbol: 'NIFTY', pnl: 0, entryPrice: 100, openedAt: t0 + 150000, factorSnapshot: { strategyVersion: 'v1.0-baseline' } },
];

const report = api.buildStrategyDiagnosticReport({
  decisionLog,
  tradeHistory,
  blockedAttempts: [],
  periodLabel: 'Test window',
  compareVersionA: 'v1.0-baseline',
  compareVersionB: 'v1.1-test',
});

assert.strictEqual(report.meta.schemaVersion, '1.0.0');
assert.strictEqual(report.executiveSummary.totalSignals, 5);
assert.strictEqual(report.executiveSummary.tradesTaken, 1);
assert.strictEqual(report.executiveSummary.setupsBlocked, 4);
assert.strictEqual(report.performance.wins, 1);
assert.strictEqual(report.performance.losses, 1);
assert.strictEqual(report.performance.breakeven, 1);
assert.ok(report.performance.winRatePct > 0);
assert.ok(report.eligibilityPipeline.strategyPassed === 5);
assert.ok(Array.isArray(report.strategyAssumptionsAndImprovements));
assert.ok(report.strategyAssumptionsAndImprovements.length >= 1);
assert.ok(report.signalsAndRejections.rejectionRecords.length >= 4);
assert.ok(report.strategyVersionComparison);
assert.ok(report.entryExitAnalysis.goodEntries + report.entryExitAnalysis.poorEntries >= 2);

const md = api.formatDiagnosticReportMarkdown(report);
assert.ok(/Executive Summary/.test(md));
assert.ok(/Strategy Assumptions/.test(md));
assert.ok(/Over-Restriction Detection/.test(md));

const csv = api.formatDiagnosticReportCsv(report);
assert.ok(/EXECUTIVE,totalSignals,5/.test(csv));
assert.ok(/ASSUMPTIONS/.test(csv));

const perf = api.computePerformanceMetrics(tradeHistory);
assert.strictEqual(perf.netPnl, 240);
assert.ok(perf.profitFactor > 2 && perf.profitFactor < 3);

const pipe = api.buildEligibilityPipeline(decisionLog);
assert.strictEqual(pipe.strategyPassed, 5);
assert.strictEqual(pipe.actualTrades, 1);

const coreSrc = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
assert.ok(/strategy-diagnostic-report\.js/.test(fs.readFileSync(path.join(__dirname, '../assets/standalone-app.php'), 'utf8')));
assert.ok(/getStrategyReportUiOptions/.test(fs.readFileSync(path.join(__dirname, '../assets/strategy-diagnostic-report.js'), 'utf8')));
assert.ok(/operatorIntelBias/.test(coreSrc));
assert.ok(/topFactors:/.test(coreSrc));

console.log('All strategy-diagnostic-report tests passed.');
