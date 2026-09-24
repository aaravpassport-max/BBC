// REAL, STANDALONE, IMMEDIATELY-RUNNABLE test - closes this session's
// exhaustive critical/block-tier FM audit pass (every check('FMxxx', ...,
// 'critical', 'block', ...) call site in evaluatePreTradeFailureModes()).
//
// This pass's one genuine finding: simulateOrderRejection() (feeds
// FM025, critical/block) used `typeof x === 'number'` guards on
// leg.bidprice/leg.askPrice/restingQty/qty. Since `typeof NaN ===
// 'number'` is true in JS, a corrupt live quote (NaN bid/ask price or
// resting qty) would silently skip BOTH risk-factor checks (every NaN
// comparison is false) instead of being flagged - so a genuinely
// corrupt option-chain leg could produce rejected:false purely because
// the data was bad, feeding FM025 a false "order is fine" verdict on
// exactly the kind of data this check exists to catch.
//
// Run with: node tests/critical-block-fm-nan-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js.');
  process.exit(1);
}
eval(coreSource.slice(0, end));

// ---------------------------------------------------------------
// Baseline correctness (unchanged behavior) - realistic clean leg
// ---------------------------------------------------------------
{
  const cleanLeg = { bidprice: 100, askPrice: 101, bidQty: 500, askQty: 500 };
  const r = simulateOrderRejection(cleanLeg, 50, 'buy');
  check(r.rejected === false, 'simulateOrderRejection: clean tight-spread/adequate-depth leg -> not rejected');
}
{
  // realistic double-flag: qty exceeds resting AND wide spread
  const thinLeg = { bidprice: 100, askPrice: 120, bidQty: 10, askQty: 10 };
  const r = simulateOrderRejection(thinLeg, 500, 'buy');
  check(r.rejected === true, 'simulateOrderRejection: thin depth + wide spread (both real numeric) -> still correctly rejected (regression guard)');
}

// ---------------------------------------------------------------
// FM025 regression: realistic corrupt live-quote scenarios that must
// now be flagged as risk factors instead of silently passing through.
// ---------------------------------------------------------------
{
  // realistic scenario: a feed glitch computes bidprice as 0/0 = NaN
  // upstream (e.g. a divide-by-zero in a prior normalization step)
  // while askPrice and depth otherwise look plausible.
  const corruptBidLeg = { bidprice: 0 / 0, askPrice: 105, bidQty: 10, askQty: 10 };
  const r = simulateOrderRejection(corruptBidLeg, 500, 'buy');
  check(r.riskFactors.some(f => /non-finite/.test(f)), 'simulateOrderRejection: NaN bidprice with wide-looking quote -> flagged as a risk factor, not silently skipped');
  check(r.rejected === true, 'simulateOrderRejection: NaN bidprice + thin depth (qty>restingQty) -> now correctly rejected (2 real risk factors)');
}
{
  // realistic scenario: askQty comes back as NaN from a malformed
  // depth payload, while qty genuinely exceeds a sane resting size.
  const corruptDepthLeg = { bidprice: 100, askPrice: 101, bidQty: 500, askQty: NaN };
  const r = simulateOrderRejection(corruptDepthLeg, 1000, 'buy');
  check(r.riskFactors.some(f => /Resting ask qty is non-finite/.test(f)), 'simulateOrderRejection: NaN askQty -> flagged as a risk factor instead of silently skipping the depth check');
}
{
  // regression: fields genuinely absent (not corrupt) must NOT be
  // treated as a risk factor - matches this function's documented
  // "never flags when data is simply absent" discipline.
  const sparseLeg = { bidprice: 100, askPrice: 101 }; // no bidQty/askQty at all
  const r = simulateOrderRejection(sparseLeg, 50, 'buy');
  check(!r.riskFactors.some(f => /non-finite/.test(f)), 'simulateOrderRejection: genuinely absent askQty/bidQty (not corrupt) -> no spurious non-finite risk factor');
}
{
  // FM025 end-to-end: a corrupt leg with BOTH NaN price and NaN depth
  // now genuinely produces >=2 risk factors -> rejected:true -> the
  // critical/block FM025 check's `rejectionCheck.rejected === true`
  // condition now correctly fires instead of failing open.
  const doublyCorruptLeg = { bidprice: NaN, askPrice: NaN, bidQty: NaN, askQty: NaN };
  const r = simulateOrderRejection(doublyCorruptLeg, 100, 'sell');
  check(r.rejected === true, 'FM025 end-to-end: doubly-corrupt live leg (NaN price AND NaN depth) -> rejectionCheck.rejected === true (critical/block check now fires)');
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
