const fs = require('fs');
const path = require('path');
const assert = require('assert');

const coreSrc = fs.readFileSync(
  path.join(__dirname, '../assets/fno-lab-core.js'),
  'utf8'
);

(function testStrategySignalCapture() {
  const idx = coreSrc.indexOf('const brain=evaluateBrain(refreshCtx);');
  assert.ok(idx !== -1, 'refreshBrain must call evaluateBrain');
  const slice = coreSrc.slice(idx, idx + 500);
  assert.match(slice, /brain\.strategySignalDecision\s*=\s*brain\.decision/);
  const tseIdx = coreSrc.indexOf('applyTradeSetupInfluence(brain, tsd);', idx);
  assert.ok(tseIdx > idx, 'TSE apply must come after evaluateBrain');
})();

(function testAutonomousUsesStrategy() {
  assert.match(coreSrc, /resolveStrategyEntryDecision\(brain\)/);
  assert.match(coreSrc, /isPaperAutoExecutionEnabled\(\) && strategyEntryDecision/);
})();

(function testTryOpenDirectionResolver() {
  const fnStart = coreSrc.indexOf('function tryOpenAutoTradePosition(');
  assert.ok(fnStart !== -1);
  const fnEnd = coreSrc.indexOf('\n  function ', fnStart + 50);
  const fnBody = coreSrc.slice(fnStart, fnEnd > fnStart ? fnEnd : fnStart + 12000);
  assert.match(fnBody, /const entryBrain = lastBrain/);
  assert.ok(!/\n    if \(brain && \(entryDirectionDecision/.test(fnBody), 'must not reference undefined global brain in tryOpenAutoTradePosition');
})();

assert.match(coreSrc, /skipOverlayEntryGates = shouldUseRelaxedPaperExecutionLane\(\)/);
assert.match(coreSrc, /preWeightingDecision/);
assert.match(coreSrc, /isPaperTradingMode\(\)/);

console.log('autonomous-paper-strategy-signal.test.js: all passed');
