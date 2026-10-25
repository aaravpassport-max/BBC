const fs = require('fs');
const path = require('path');

const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
Object.assign(global, ge);

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
global.localStorage = {
  store: {},
  getItem(k) { return this.store[k] != null ? this.store[k] : null; },
  setItem(k, v) { this.store[k] = v; },
  removeItem(k) { delete this.store[k]; },
};
global.window = { FNO_FACTORS_CATALOG: [] };
global.document = { addEventListener() {}, dispatchEvent() {} };
eval(coreSource.slice(0, end));

localStorage.setItem('fno_trading_controls_v1', JSON.stringify({
  scalpingProfitProfileEnabled: true,
  tradingTypes: { scalping: true, intraday: false, swing: false },
}));

let passed = 0;
let failed = 0;
function check(cond, msg) {
  if (cond) { passed++; console.log('  PASS  ' + msg); }
  else { failed++; console.log('  FAIL  ' + msg); }
}

console.log('\n=== scalping confidence (v16.37.4) ===\n');

check(
  computeRawDirectionalConfidenceTier(8, 17) === 'High',
  'scalping raw: |score| 8 with span 17 → High (45% ratio)'
);
check(
  computeRawDirectionalConfidenceTier(3.5, 17) === 'Medium',
  'scalping raw: |score| 3.5 → Medium (18% ratio)'
);
check(
  applyCoverageToConfidence('High', 50) === 'High',
  'scalping coverage 50%: High stays High (no 70% penalty)'
);
check(
  applyCoverageToConfidence('High', 15) === 'Medium',
  'scalping coverage 15%: one tier down only'
);

localStorage.setItem('fno_trading_controls_v1', JSON.stringify({
  scalpingProfitProfileEnabled: false,
  tradingTypes: { intraday: true, scalping: false },
}));
check(
  applyCoverageToConfidence('High', 50) === 'Medium',
  'non-scalping coverage 50%: still downgrades High→Medium'
);

localStorage.setItem('fno_trading_controls_v1', JSON.stringify({
  scalpingProfitProfileEnabled: true,
  tradingTypes: { scalping: true, intraday: false },
}));
check(
  applyScalpingConfidenceFloor('BUY_READY', 'Low', 12, 11, -17, 17) === 'Medium',
  'floor: BUY_READY with Low → at least Medium when score cleared buy threshold'
);
check(
  applyScalpingConfidenceFloor('WAIT', 'Low', 5, 11, -17, 17) === 'Low',
  'floor: WAIT unchanged'
);

console.log(`\nTOTAL: ${passed} passed, ${failed} failed\n`);
process.exit(failed ? 1 : 0);
