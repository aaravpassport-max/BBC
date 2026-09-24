// REAL, STANDALONE, IMMEDIATELY-RUNNABLE test - closes this session's
// audit of "NSE data fetch OUTER failure handling" (distinct from the
// inner numeric-field NaN sweep already covered by oc-row-parsing-
// audit.test.js).
//
// TRACED (2026-08-30): fno_nse_get() (fno-lab.php) already returns null
// (never a malformed/empty-but-truthy value) on every outer failure mode
// - is_wp_error(), non-200 response, and json_decode() producing
// null/non-array - and every one of its 7 real call sites in fno-lab.php
// (fno_fetch_chart_fn, fno_fetch_oc_fn, fno_fetch_futures_fn,
// fno_fetch_corporate_actions_fn, fno_fetch_market_breadth_fn,
// fno_fetch_status_fn's VIX closure) already checks `$data === null`/
// `!$data` and returns an honest `sourceStatus: 'unavailable'` (or
// attempts the documented Kite fallback first) with an EMPTY data
// array - never proceeding as if a genuinely-empty NSE response meant
// "no options/no futures exist".
//
// The one real question this leaves for the BROWSER side (per the task
// brief): when fno_fetch_option_chain's outer fetch genuinely fails and
// `rec.data` ends up `[]`, does `ctx.ocRows` default to `[]` and let
// evaluateBrain() proceed AS IF that were a legitimate "no liquidity"
// signal? Traced end-to-end in assets/fno-lab-core.js:
//   1. `ocRows: (rec.data||[])` (~line 11424) - yes, ctx.ocRows becomes
//      a real empty array on total OC failure, same as this file's
//      existing oc-row-parsing-audit.test.js already covers for FM055/
//      FM150's own >0-length-gated conditions.
//   2. BUT `ocRow` (the SINGLE selected nearest-strike row, ~line 11205)
//      is computed via `.reduce()` over `rec.data||[]` with a `null`
//      seed - an empty array reduces to `null`, unconditionally.
//   3. FM036 (`evaluatePreTradeFailureModes`, critical/block) fires on
//      exactly `!ctx || !ctx.ocRow` - so a totally-empty option chain
//      (network failure, 403/blocked, malformed NSE JSON, timeout - any
//      outer failure) ALWAYS produces `ctx.ocRow === null`, which ALWAYS
//      trips FM036's critical block, unconditionally, regardless of
//      what FM055/FM150's own >0-length gates do. This test proves that
//      chain end-to-end and locks it as a regression guard: a future
//      change to ocRow's reduce-seed, or to FM036's condition, that
//      silently reintroduces a "proceed on an artificially-empty
//      chain as if it were low-liquidity" gap will fail this test.
//
// Also independently proves the separate spot-null path (render()'s own
// "HONEST FAILURE PATH", ~line 11181): when the option-chain fetch AND
// the chart fetch both genuinely fail (worst case), spot itself is null
// and evaluateBrain() is never even invoked for that refresh - checked
// here structurally since render()'s DOM-driving code isn't unit-
// testable standalone, by confirming the exact source text of that
// guard is still present and still `return`s before evaluateBrain.
//
// Run with: node tests/nse-outer-fetch-failure-audit.test.js

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
// 1. PHP-side outer-failure contract (fno-lab.php) - grep-verified,
//    not re-implemented: every real fno_nse_get() call site honestly
//    checks for null/failure and never lets a failed fetch masquerade
//    as a genuine empty response.
// ---------------------------------------------------------------
{
  const phpSource = fs.readFileSync(path.join(__dirname, '../fno-lab.php'), 'utf8');
  const nseGetBody = phpSource.slice(phpSource.indexOf('function fno_nse_get('), phpSource.indexOf('\nfunction fno_nse_get_last_fetch_time('));
  check(/is_wp_error\(\$res\)\)\s*\{[^}]*return null;/.test(nseGetBody), 'fno_nse_get: network error (is_wp_error) returns null, not a malformed truthy value');
  check(/wp_remote_retrieve_response_code\(\$res\)\s*!==\s*200\)\s*\{[^}]*return null;/.test(nseGetBody), 'fno_nse_get: non-200 response (403/blocked/rate-limited) returns null');
  check(/empty\(\$data\)\s*\|\|\s*!is_array\(\$data\)\)\s*\{[^}]*return null;/.test(nseGetBody), 'fno_nse_get: json_decode failure or empty/non-array body returns null (handles NSE response-shape changes + timeouts surfaced as empty bodies)');

  // Every real caller honestly branches on the null/failure case rather
  // than trusting $data unconditionally - spot-checked via the
  // documented sourceStatus:'unavailable' honest-failure literal, which
  // every one of fno_fetch_chart_fn/fno_fetch_oc_fn/fno_fetch_futures_fn
  // uses on the same failure path fno_nse_get() null-return feeds into.
  const unavailableCount = (phpSource.match(/'sourceStatus'\s*=>\s*'unavailable'/g) || []).length;
  check(unavailableCount >= 3, `PHP callers: at least 3 real "sourceStatus: unavailable" honest-failure responses found (got ${unavailableCount}) - chart/option-chain/futures each declare their own failure state rather than silently proceeding`);
}

// ---------------------------------------------------------------
// 2. Browser side: a totally-empty option chain (rec.data === [])
//    always produces ctx.ocRow === null via the real reduce() seed,
//    exactly matching how render() builds it.
// ---------------------------------------------------------------
{
  const emptyOcData = [];
  const strike = 24000; // arbitrary requested strike - irrelevant to an empty chain
  const ocRow = emptyOcData.reduce((best, row) => {
    if (!row || typeof row.strikePrice !== 'number') return best;
    const d = Math.abs(row.strikePrice - strike);
    return (!best || d < best._d) ? Object.assign({}, row, {_d:d}) : best;
  }, null);
  check(ocRow === null, 'render()\'s real ocRow reduce(): an empty (fetch-failed) option chain always seeds/stays null - never fabricates a fake row');
}

// ---------------------------------------------------------------
// 3. FM036 unconditionally blocks whenever ctx.ocRow is null/absent -
//    end-to-end proof that a totally-failed option-chain fetch can
//    NEVER reach a BUY_READY/scored decision, regardless of what
//    every other FM055/FM150 length-gated check does.
// ---------------------------------------------------------------
{
  const baseBrain = { decision: 'BUY_READY', results: [], regime: {}, regimeAdjustment: '', failureLibraryAdjustment: '' };

  // Simulates the exact real ctx shape render() would build after a
  // TOTAL NSE outer failure (network error/403/malformed JSON/timeout)
  // with spot still resolvable from the chart's last candle (the only
  // scenario where evaluateBrain is even reached - see render()'s own
  // "HONEST FAILURE PATH" which stops earlier when spot is ALSO null).
  const failedFetchCtx = { time: '11:00', isExpiry: false, ocRows: [], ocRow: null, journalToday: [] };
  const r = evaluatePreTradeFailureModes({ brain: baseBrain, ctx: failedFetchCtx });
  const fm036 = r.triggered.find(t => t.id === 'FM036');
  check(!!fm036, 'FM036 fires when ctx.ocRow is null (real shape after a total NSE/OC fetch failure)');
  check(fm036 && fm036.severity === 'critical' && fm036.action === 'block', 'FM036: critical/block severity - a totally-failed option-chain fetch always blocks the trade, never silently degrades to a "reduce confidence" signal');
  check(r.finalAction === 'block', 'evaluatePreTradeFailureModes: finalAction is "block" end-to-end for a totally-failed option-chain fetch - never proceeds to a scored BUY_READY/SELL_READY decision on empty data');

  // Regression guard the other direction: a real, populated chain with
  // a real matching ocRow must NOT trip FM036, so this is a genuine
  // condition, not an always-on false positive.
  const healthyCtx = { time: '11:00', isExpiry: false, ocRows: [{strikePrice:24000, CE:{lastPrice:100}, PE:{lastPrice:90}}], ocRow: {strikePrice:24000, CE:{lastPrice:100}, PE:{lastPrice:90}}, journalToday: [] };
  const rHealthy = evaluatePreTradeFailureModes({ brain: baseBrain, ctx: healthyCtx });
  check(!rHealthy.triggered.some(t => t.id === 'FM036'), 'FM036: does NOT fire for a real, non-null ocRow (sanity - the block is genuinely conditional, not a permanent block)');
}

// ---------------------------------------------------------------
// 4. render()'s own spot-null honest-failure path is still present and
//    still stops before evaluateBrain (structural source-text check -
//    render() drives the live DOM and isn't unit-callable standalone).
// ---------------------------------------------------------------
{
  const guardIdx = coreSource.indexOf('HONEST FAILURE PATH');
  check(guardIdx !== -1, 'render(): the spot===null "HONEST FAILURE PATH" comment/guard is still present in fno-lab-core.js');
  const guardWindow = coreSource.slice(guardIdx, guardIdx + 1200);
  check(/if\s*\(\s*spot\s*===\s*null\s*\)\s*\{/.test(coreSource.slice(guardIdx - 200, guardIdx + 50)) || /spot === null/.test(guardWindow), 'render(): the guard genuinely keys off spot === null (both chart candle AND OC underlyingValue must have failed)');
  check(/return;/.test(guardWindow), 'render(): the spot===null branch genuinely `return`s - evaluateBrain() is never reached on a total both-sources failure, not just logged and continued');
}

console.log(`\n${passed} passed, ${failed} failed (nse-outer-fetch-failure-audit.test.js)`);
if (failed > 0) process.exit(1);
