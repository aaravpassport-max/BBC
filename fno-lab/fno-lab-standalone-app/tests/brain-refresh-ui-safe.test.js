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

console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
