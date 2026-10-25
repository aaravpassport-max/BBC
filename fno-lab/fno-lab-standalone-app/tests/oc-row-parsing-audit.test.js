// REAL, STANDALONE, IMMEDIATELY-RUNNABLE test - closes this session's
// raw-NSE-JSON-to-ctx.ocRows parsing audit.
//
// FINDING: no bug in the actual live data path - this app's real
// option-chain source (nseindia.com/api/option-chain-indices|equities,
// fetched with Accept: application/json in fno_nse_get(), fno-lab.php)
// is NSE's genuine REST JSON API, confirmed by direct code read to be
// passed through PHP's json_decode()+wp_send_json_success() with no
// reshaping. That specific endpoint returns real native JSON numbers
// (or omits the field/leg entirely for illiquid strikes) - the
// well-known NSE "-" placeholder convention belongs to NSE's
// HTML-rendered chain page and bhavcopy CSVs, a different channel this
// app never fetches. The many typeof==='number'/Number.isFinite guards
// already throughout fno-lab-core.js correctly fail safe on the real
// omission case.
//
// One genuine latent gap found and fixed as defense-in-depth (not a
// fix for an observed live bug): several ocRows consumers use a bare
// `||0` idiom with no typeof guard (e.g. `d.CE.openInterest||0`) - a
// stray non-numeric string would NOT be caught by `||0` (truthy) and
// would silently corrupt a numeric accumulator via string
// concatenation. sanitizeOcRows()/sanitizeOcLeg()/sanitizeOcNumericField()
// added (assets/fno-lab-core.js, just above computeMaxPainInfo) and
// wired in once at the `rec.data` entry point in render(), so every
// ocRows consumer is protected without being individually touched.
//
// FM150 strike-comparison check: confirmed already safe. r.strikePrice
// comes from the same raw NSE JSON (a native number on the real
// endpoint), and requestedStrike is always produced via parseFloat()
// before the FM150 `===` comparison (fno-lab-core.js, the `strike=
// parseFloat(...)` line and the `requestedStrike: (typeof strike ===
// 'number') ? strike : parseFloat(strike)` ctx-build line) - both
// operands are always JS numbers, so no string-vs-number `===`
// mismatch (e.g. "17500.00" vs 17500) can occur. Covered below too.
//
// Run with: node tests/oc-row-parsing-audit.test.js

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
// 1. Real NSE-JSON-shaped clean row (native numbers) must pass through
//    completely unchanged - the sanitizer is a defense-in-depth no-op
//    on this app's actual, confirmed response shape.
// ---------------------------------------------------------------
(function () {
  const cleanRow = {
    strikePrice: 22500, expiryDate: '26-Sep-2026',
    CE: { lastPrice: 123.45, openInterest: 45000, changeinOpenInterest: -1200, totalTradedVolume: 98000, impliedVolatility: 14.2, bidprice: 122.5, askPrice: 123.9, bidQty: 550, askQty: 300 },
    PE: { lastPrice: 87.1, openInterest: 39000, changeinOpenInterest: 800, totalTradedVolume: 71000, impliedVolatility: 15.6, bidprice: 86.6, askPrice: 87.6, bidQty: 400, askQty: 620 },
  };
  const out = sanitizeOcRows([cleanRow])[0];
  check(out.strikePrice === 22500, 'clean row: strikePrice untouched');
  check(out.CE.lastPrice === 123.45 && out.CE.openInterest === 45000 && out.CE.impliedVolatility === 14.2, 'clean row: CE numeric fields untouched');
  check(out.PE.lastPrice === 87.1 && out.PE.openInterest === 39000, 'clean row: PE numeric fields untouched');
  check(out.expiryDate === '26-Sep-2026', 'clean row: non-numeric field (expiryDate) untouched');
})();

// ---------------------------------------------------------------
// 2. The "-" string quirk case (defense-in-depth, not observed on
//    this app's real endpoint, but guarded against regardless): a
//    stray "-" on openInterest/lastPrice/impliedVolatility must
//    become null, not the truthy, corrupting string "-".
// ---------------------------------------------------------------
(function () {
  const dashRow = {
    strikePrice: 23000, expiryDate: '26-Sep-2026',
    CE: { lastPrice: '-', openInterest: '-', changeinOpenInterest: '-', totalTradedVolume: '-', impliedVolatility: '-', bidprice: '-', askPrice: '-' },
    PE: { lastPrice: 5.5, openInterest: 1000, changeinOpenInterest: 100, totalTradedVolume: 500, impliedVolatility: 40, bidprice: 5.2, askPrice: 5.8 },
  };
  const out = sanitizeOcRows([dashRow])[0];
  check(out.CE.lastPrice === null, '"-" quirk: CE.lastPrice sanitized to null, not the string "-"');
  check(out.CE.openInterest === null, '"-" quirk: CE.openInterest sanitized to null');
  check(out.CE.impliedVolatility === null, '"-" quirk: CE.impliedVolatility sanitized to null');
  // Prove the pre-existing `||0` idiom used throughout fno-lab-core.js
  // (e.g. the totalPE/totalCE accumulation loop) is now immune: before
  // this fix, `0 + "-"` would have silently become the STRING "0-"
  // rather than staying a number.
  let totalCE = 0;
  totalCE += out.CE.openInterest || 0;
  check(totalCE === 0 && typeof totalCE === 'number', '"-" quirk: sanitized null falls through `||0` to a real 0, no string-concat corruption');

  // And prove the UNSANITIZED raw value really would have corrupted it
  // (demonstrates this is a real latent risk, not a strawman):
  let corruptTotal = 0;
  corruptTotal += dashRow.CE.openInterest || 0;
  check(typeof corruptTotal === 'string' && corruptTotal === '0-', 'proof: unsanitized raw "-" DOES corrupt a `||0` numeric accumulator into a string');
})();

// ---------------------------------------------------------------
// 3. Genuinely missing leg/field (the REAL quirk of this app's actual
//    endpoint - an illiquid/newly-listed strike can omit a whole
//    CE/PE leg, or a field within it) must stay absent/null, never be
//    fabricated into a number.
// ---------------------------------------------------------------
(function () {
  const thinRow = { strikePrice: 24000, expiryDate: '26-Sep-2026', CE: { lastPrice: 0.05, openInterest: 0 }, PE: null };
  const out = sanitizeOcRows([thinRow])[0];
  check(out.PE === null, 'missing leg (PE: null) stays null, not fabricated');
  check(out.CE.openInterest === 0, 'genuine zero OI stays a real 0, not turned into null');
  check(typeof out.CE.lastPrice === 'number' && out.CE.lastPrice === 0.05, 'sparse CE leg fields present are preserved');
})();

// ---------------------------------------------------------------
// 4. Non-array / malformed input handled without throwing.
// ---------------------------------------------------------------
(function () {
  check(sanitizeOcRows(null) === null, 'non-array input (null) passed through unchanged, no throw');
  check(sanitizeOcRows(undefined) === undefined, 'non-array input (undefined) passed through unchanged, no throw');
  const withJunkRow = sanitizeOcRows([null, 42, { strikePrice: 21000, CE: null, PE: null }]);
  check(withJunkRow[0] === null && withJunkRow[1] === 42, 'junk array entries (null/non-object) passed through unchanged, no throw');
  check(withJunkRow[2].strikePrice === 21000, 'well-formed row among junk entries still sanitized correctly');
})();

// ---------------------------------------------------------------
// 5. FM150 strike-comparison: both operands are always numbers, so a
//    formatting quirk (trailing-zero string, "17500.00" vs 17500)
//    cannot cause a false-positive block on a correctly-resolved
//    strike. Reproduces the exact `===` FM150 uses.
// ---------------------------------------------------------------
(function () {
  const ocRows = sanitizeOcRows([{ strikePrice: 17500, CE: {}, PE: {} }]);
  // requestedStrike as this app's own two real call sites always
  // produce it: parseFloat() of a (possibly zero-padded) form value.
  const requestedStrike = parseFloat('17500.00');
  check(typeof requestedStrike === 'number' && requestedStrike === 17500, 'parseFloat("17500.00") normalizes to the plain number 17500');
  const matched = ocRows.some(r => r && typeof r.strikePrice === 'number' && r.strikePrice === requestedStrike);
  check(matched === true, 'FM150: a genuinely-matching strike is NOT falsely flagged as missing despite trailing-zero input formatting');

  // And the true-positive case still fires correctly (FM150 doing its
  // real job): a strike genuinely absent from the chain.
  const requestedGhostStrike = parseFloat('17550');
  const ghostMatched = ocRows.some(r => r && typeof r.strikePrice === 'number' && r.strikePrice === requestedGhostStrike);
  check(ghostMatched === false, 'FM150: a genuinely-absent strike is still correctly flagged (not weakened by this audit)');
})();

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) process.exit(1);
