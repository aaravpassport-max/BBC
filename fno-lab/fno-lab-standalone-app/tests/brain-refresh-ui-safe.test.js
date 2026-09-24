// Brain refresh UI — fnoFormatFixed must never throw on undefined (regression for refreshBrainImpl).
// Run: node tests/brain-refresh-ui-safe.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0;
let failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const markerStart = coreSource.indexOf('const FNO_CORE_BUILD_MARKER');
const renderStart = coreSource.indexOf('\nfunction render(){');
const slice = coreSource.slice(markerStart, renderStart);
eval(slice);

check(typeof fnoFormatFixed === 'function', 'fnoFormatFixed exported in core slice');
check(fnoFormatFixed(undefined, 1) === '—', 'undefined → fallback');
check(fnoFormatFixed(null, 1) === '—', 'null → fallback');
check(fnoFormatFixed(12.345, 1) === '12.3', 'finite number formats');

function factorRegistryCoverageLine(brain) {
  const pct = brain.factorRegistry.coveragePct;
  const dirPct = brain.factorDataAvailability ? brain.factorDataAvailability.directionalCoveragePct : null;
  const dirCovOk = Number.isFinite(dirPct);
  const catCovOk = Number.isFinite(pct);
  return dirCovOk
    ? `Trade signal data: ${fnoFormatFixed(dirPct, 1)}% … catalog: ${fnoFormatFixed(pct, 1)}%`
    : `Catalog roadmap: ${catCovOk ? fnoFormatFixed(pct, 1) : '—'}%`;
}

const partialBrain = {
  factorRegistry: { coveragePct: undefined, counts: { COMPUTED: 1 }, totalCatalogued: 193 },
  factorDataAvailability: { directionalCoveragePct: undefined, directionalUsedCount: 0, directionalUnavailableCount: 0 },
};
let threw = false;
try {
  factorRegistryCoverageLine(partialBrain);
} catch (e) {
  threw = true;
}
check(!threw, 'factor registry coverage text with missing pct/dirPct does not throw');

let hypoThrew = false;
try {
  computeParticipantPayoffHypothesis({ bias: 'ACCUMULATION' }, null, null, null, 24000);
} catch (e) {
  hypoThrew = true;
}
check(!hypoThrew, 'hypothesis with operator bias but no score does not throw');

const modesSrc = fs.readFileSync(path.join(__dirname, '../assets/trading-modes-engine.js'), 'utf8');
check(/Number\.isFinite\(r\.score\)/.test(modesSrc), 'mode comparison dashboard guards missing log score with isFinite');

check(/function paintCoreBrainDecisionUi/.test(coreSource), 'paintCoreBrainDecisionUi exists for early core decision paint');
check(/function safePaintCoreBrainDecisionUi/.test(coreSource), 'safePaintCoreBrainDecisionUi wraps core paint');
check(/maybeAutoCalibrateThreshold\(getDecisionLog\(\)\)/.test(coreSource) && /Auto threshold calibration failed/.test(coreSource), 'maybeAutoCalibrateThreshold wrapped in try/catch');

let regimeAdjThrew = false;
try {
  const fakeMap = new Map([['Trending', { tradeCount: 20, winRatePct: undefined, sampleSizeWarning: false }]]);
  computeRegimeAdjustedConfidence('Medium', 'Trending', fakeMap);
} catch (e) {
  regimeAdjThrew = true;
}
check(!regimeAdjThrew, 'computeRegimeAdjustedConfidence does not throw when winRatePct is missing');

let fmPretradeThrew = false;
try {
  const origRegimeWin = computeRegimeWinRate;
  computeRegimeWinRate = () => new Map([['BadRegime', { tradeCount: 25, winRatePct: undefined, sampleSizeWarning: false }]]);
  evaluatePreTradeFailureModes({
    brain: {
      results: [],
      criticalFails: [],
      decision: 'BUY_READY',
      confidence: 'Medium',
      regime: { label: 'BadRegime', trend: 'Bullish', volatility: 'Normal Vol', extendedStates: [] },
      factorRegistry: null,
      regimeAdjustment: null,
      failureLibraryAdjustment: null,
    },
    ctx: { regimeLabel: 'BadRegime', fullJournal: [{ pnl: 100, factorSnapshot: { regime: { label: 'BadRegime' } } }] },
  });
  computeRegimeWinRate = origRegimeWin;
} catch (e) {
  fmPretradeThrew = true;
}
check(!fmPretradeThrew, 'evaluatePreTradeFailureModes does not throw when regime winRatePct is missing');

console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
