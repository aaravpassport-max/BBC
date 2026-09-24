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

let fm086Threw = false;
try {
  evaluatePreTradeFailureModes({
    brain: { results: [], criticalFails: [], decision: 'BUY_READY', confidence: 'Medium', regime: { label: 'Trending', trend: 'Bullish', volatility: 'Normal Vol', extendedStates: [] }, factorRegistry: null, regimeAdjustment: null, failureLibraryAdjustment: null },
    ctx: {
      ocRows: [{ strikePrice: 24000, CE: { openInterest: 1 }, PE: { openInterest: 1 } }],
      spot: 24100,
      regimeLabel: 'Trending',
      fullJournal: [],
    },
    optionType: 'CE',
  });
} catch (e) {
  fm086Threw = true;
}
check(!fm086Threw, 'evaluatePreTradeFailureModes does not throw when FM086 payoff level exists but does not oppose (distPct evaluated lazily)');

let fm058Threw = false;
try {
  const origDep = computeRegimeDependentFactors;
  computeRegimeDependentFactors = () => [{ factorId: 'TestFactor', spreadPct: undefined, sampleSizeWarning: false, regimeBreakdown: [{ regime: 'Trending', accuracyPct: 10, tradesInfluenced: 30 }, { regime: 'Sideways', accuracyPct: 50, tradesInfluenced: 30 }] }];
  evaluatePreTradeFailureModes({
    brain: {
      results: [],
      criticalFails: [],
      decision: 'BUY_READY',
      confidence: 'Medium',
      regime: { label: 'Trending', trend: 'Bullish', volatility: 'Normal Vol', extendedStates: [] },
      factorRegistry: { byId: new Map([['TestFactor', { status: 'COMPUTED' }]]) },
      regimeAdjustment: null,
      failureLibraryAdjustment: null,
    },
    ctx: { regimeLabel: 'Trending', fullJournal: [{}] },
    optionType: 'CE',
  });
  computeRegimeDependentFactors = origDep;
} catch (e) {
  fm058Threw = true;
}
check(!fm058Threw, 'evaluatePreTradeFailureModes does not throw when FM058 spreadPct is missing (lazy reason)');

let volFactorsThrew = false;
try {
  const volOut = computeVolFactors(
    { iv: undefined, now: { gamma: undefined, thetaPerDay: undefined } },
    Array.from({ length: 25 }, (_, i) => 24000 + i),
    { vix: null, isExpiry: true, ocRow: null }
  );
  check(Array.isArray(volOut) && volOut.length >= 10, 'computeVolFactors returns factors with missing snapshot.iv');
} catch (e) {
  volFactorsThrew = true;
}
check(!volFactorsThrew, 'computeVolFactors does not throw when snapshot.iv is undefined');

let opIntelThrew = false;
try {
  const fakeRec = {
    data: [
      { strikePrice: 24000, CE: { changeinOpenInterest: 100, change: 1, openInterest: 1 }, PE: { changeinOpenInterest: 50, change: -1, openInterest: 1 } },
      { strikePrice: 24100, CE: { changeinOpenInterest: 10, change: 0, openInterest: 1 }, PE: { changeinOpenInterest: 10, change: 0, openInterest: 1 } },
    ],
  };
  computeOperatorIntel(fakeRec, 24050, undefined, null, 0.2, 5);
} catch (e) {
  opIntelThrew = true;
}
check(!opIntelThrew, 'computeOperatorIntel does not throw when PCR is undefined');

let flowGammaThrew = false;
try {
  computeFlowFactors(
    { iv: 18, now: { gamma: undefined, vega: undefined, valid: true } },
    [24000, 24010],
    {
      isExpiry: true,
      vix: 15,
      optPrice: 100,
      decay: { snapshot: { now: { gamma: undefined, vega: undefined, valid: true }, iv: 18 } },
      ocRows: [{ strikePrice: 24000, CE: { changeinOpenInterest: 1 }, PE: { changeinOpenInterest: 1 } }],
      spot: 24000,
      pcr: undefined,
    }
  );
} catch (e) {
  flowGammaThrew = true;
}
check(!flowGammaThrew, 'computeFlowFactors does not throw when greeks gamma/vega are undefined');

console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
