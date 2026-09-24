// REAL, STANDALONE, IMMEDIATELY-RUNNABLE test - Phase 2 of the
// decision-engine audit (full Failure-Mode Library re-inventory).
//
// This pass's one genuine finding, fixed here with a real regression
// lock: adjustFailureModesForTradeType()'s own internal `priority`
// array (used to compute its adjusted `finalAction`) was a short,
// hand-picked subset of the real action vocabulary
// (['block','require_confirmation','reduce_confidence','none']) that
// omitted 'reject', 'delay' and 'reduce_position_size' - three real
// actions genuine check() calls in evaluatePreTradeFailureModes() use
// (e.g. FM021 = 'delay', FM022/FM023 = 'reduce_position_size').
// Array.prototype.indexOf on a value absent from the array returns -1,
// and -1 is LESS than every real, present index (0/1/2/3) - so any
// triggered 'delay' or 'reduce_position_size' condition was always
// treated as MORE severe than an actual 'block', silently overwriting
// a real critical block with 'delay'/'reduce_position_size' whenever
// both fired in the same refresh. The real gate at the call site
// (tryOpenAutoTradePosition, ~line 13019) only checks
// `fmResult.finalAction === 'block' || fmResult.finalAction ===
// 'reject'` - so this would have silently let a should-have-been-
// blocked trade open whenever a lower-tier delay/reduce_position_size
// condition also fired alongside a genuine critical/block condition.
// Fixed by using the exact same full priority ordering
// evaluatePreTradeFailureModes() itself already uses.
//
// Also locks in this pass's fresh, from-scratch re-derivation of the
// wired/unwired split (116 wired: 103 distinct literal check('FMxxx',
// ...) IDs with at least one genuinely live, non-fires:false call site,
// plus 13 more via the FM112-FM124 categoryFmIds loop) - a static,
// source-level audit-lock test, independent of the doc's own claimed
// figure, so a future edit that silently changes this count is caught.
//
// Run with: node tests/fm-library-inventory-audit.test.js

const assert = require('assert');
const fs = require('fs');
const path = require('path');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log('  PASS  ' + label); }
  else { failed++; console.log('  FAIL  ' + label); }
}

const greeksSource = fs.readFileSync(path.join(__dirname, '../assets/greeks-engine.js'), 'utf8')
  .replace(/module\.exports\s*=\s*\{[\s\S]*?\};?/, ''); // strip the CJS export tail, keep it in this eval scope - same established pattern as tests/medium-low-tier-fm-nan-audit.test.js, needed this pass so solveImpliedVolatility (used by the new FM156 check) is genuinely defined.
eval(greeksSource);

const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js.');
  process.exit(1);
}
global.window = global.window || { FNO_AJAX: null };
eval(coreSource.slice(0, end));

// ---------------------------------------------------------------
// The genuine bug: a critical/block condition + a lower-tier
// delay/reduce_position_size condition firing together must still
// result in finalAction === 'block' after trade-type adjustment.
// ---------------------------------------------------------------
{
  const fmResult = {
    finalAction: 'block',
    triggered: [
      { id: 'FM002', condition: 'Panic regime', severity: 'critical', action: 'block', reason: 'panic' },
      { id: 'FM021', condition: 'Bollinger squeeze', severity: 'medium', action: 'delay', reason: 'squeeze' },
    ],
    summary: 'test',
  };
  const adjusted = adjustFailureModesForTradeType(fmResult, 'intraday');
  check(adjusted.finalAction === 'block', 'adjustFailureModesForTradeType: a critical/block condition alongside a delay condition still resolves to block (regression lock for the -1-indexOf priority-array bug)');
}
{
  // Same shape, but the lower-tier condition is 'reduce_position_size'
  // instead of 'delay' - the other real action the old priority array
  // omitted.
  const fmResult = {
    finalAction: 'block',
    triggered: [
      { id: 'FM022', condition: 'Theta decay >=5%/day', severity: 'high', action: 'reduce_position_size', reason: 'decay' },
      { id: 'FM092', condition: 'Trading halt', severity: 'critical', action: 'block', reason: 'halt' },
    ],
    summary: 'test',
  };
  const adjusted = adjustFailureModesForTradeType(fmResult, 'swing');
  check(adjusted.finalAction === 'block', 'adjustFailureModesForTradeType: block alongside reduce_position_size still resolves to block, regardless of trigger order');
}
{
  // Sanity: with no block/reject/require_confirmation present, a lone
  // 'delay' should still correctly become the adjusted finalAction
  // (not silently dropped to 'none' by this fix).
  const fmResult = {
    finalAction: 'delay',
    triggered: [
      { id: 'FM021', condition: 'Bollinger squeeze', severity: 'medium', action: 'delay', reason: 'squeeze' },
    ],
    summary: 'test',
  };
  const adjusted = adjustFailureModesForTradeType(fmResult, 'intraday');
  check(adjusted.finalAction === 'delay', 'adjustFailureModesForTradeType: a lone delay condition (no higher-tier condition present) still correctly resolves to delay, not "none"');
}
{
  // Sanity: an untouched, already-correct case (require_confirmation
  // alone) is unaffected by the fix.
  const fmResult = {
    finalAction: 'require_confirmation',
    triggered: [
      { id: 'FM001', condition: 'Vol expansion', severity: 'high', action: 'require_confirmation', reason: 'vol' },
    ],
    summary: 'test',
  };
  const adjusted = adjustFailureModesForTradeType(fmResult, 'intraday');
  check(adjusted.finalAction === 'require_confirmation', 'adjustFailureModesForTradeType: require_confirmation-only case unaffected by the fix');
}
{
  // The type-relevance escalation path (isRelevant true) still upgrades
  // severity/action correctly and still produces the more severe of
  // the two triggered actions.
  const fmResult = {
    finalAction: 'reduce_confidence',
    triggered: [
      // FM023 is in FNO_FM_SCALPING_RELEVANT_IDS; 'high' bumped to
      // 'critical' -> actionBySeverity.critical = 'block'.
      { id: 'FM023', condition: 'Wide bid-ask spread', severity: 'high', action: 'reduce_position_size', reason: 'spread' },
    ],
    summary: 'test',
  };
  const adjusted = adjustFailureModesForTradeType(fmResult, 'scalping');
  check(adjusted.triggered[0].adjustedSeverity === 'critical', 'adjustFailureModesForTradeType: scalping-relevant FM023 severity bumped high -> critical');
  check(adjusted.finalAction === 'block', 'adjustFailureModesForTradeType: scalping-relevant escalation to critical correctly resolves to block finalAction');
}

// ---------------------------------------------------------------
// Static, source-level audit lock: re-derive the wired/unwired split
// directly from the real evaluatePreTradeFailureModes() source rather
// than trusting a doc-stated figure, so a future silent drift is
// caught by this test failing.
// ---------------------------------------------------------------
{
  const fnStart = coreSource.indexOf('function evaluatePreTradeFailureModes(evalCtx) {');
  assert(fnStart !== -1, 'evaluatePreTradeFailureModes source must be found');
  // Find the matching closing brace by tracking depth from fnStart.
  let depth = 0, i = fnStart, fnEnd = -1;
  for (; i < coreSource.length; i++) {
    if (coreSource[i] === '{') depth++;
    else if (coreSource[i] === '}') { depth--; if (depth === 0) { fnEnd = i; break; } }
  }
  assert(fnEnd !== -1, 'evaluatePreTradeFailureModes closing brace must be found');
  const fnSrc = coreSource.slice(fnStart, fnEnd);

  const callRe = /check\(\s*'FM(\d+)'[\s\S]*?\)\s*;/g;
  const literalCalls = [];
  let m;
  while ((m = callRe.exec(fnSrc))) {
    const text = m[0];
    const isDisabled = /,\s*false\s*\)\s*;\s*$/.test(text.trim());
    literalCalls.push({ id: 'FM' + m[1], disabled: isDisabled });
  }
  // UPDATED this follow-up pass: FM011's TRUE doc condition (a real
  // computeReversalSignal(candles) EMA9/21 flip-in-last-3-candles,
  // opposite to trade direction) is now genuinely wired as a second,
  // live check('FM011', ...) call site alongside the pre-existing,
  // deliberately-disabled MACD-duplicate call (kept as-is, matching
  // this file's own established one-live+one-disabled pattern already
  // used for FM090) - so the literal-call-site count goes up by 1
  // (114->115), FM011 moves from disabled-only into the enabled set
  // (103->104 distinct live literal IDs; the 10 disabled IDs are
  // unchanged in COUNT since FM011's disabled call site still exists,
  // it's simply no longer disabled-ONLY), and the total wired count
  // goes up by 1 (116->117).
  // FM156 (real, NEW this pass - the IV-sanity-ceiling investigation's
  // buildable outcome, a mathematically-derived BSM-inversion
  // consistency check, see checkIVInternalConsistency's own TRACE)
  // adds one more literal call site (115->116), one more distinct
  // live ID (104->105), one more to the total (117->118). It is
  // beyond the 153-row catalog, matching the FM154/FM155 precedent.
  check(literalCalls.length === 116, `evaluatePreTradeFailureModes: exactly 116 literal check('FMxxx', ...) call sites found (got ${literalCalls.length})`);

  const disabledIds = new Set(literalCalls.filter(c => c.disabled).map(c => c.id));
  const enabledIds = new Set(literalCalls.filter(c => !c.disabled).map(c => c.id));
  check(disabledIds.size === 10, `evaluatePreTradeFailureModes: exactly 10 distinct deliberately-disabled (fires:false) duplicate IDs found (got ${disabledIds.size})`);
  check(enabledIds.size === 105, `evaluatePreTradeFailureModes: exactly 105 distinct genuinely-live literal FM IDs found (got ${enabledIds.size})`);
  check(enabledIds.has('FM011'), `FM011 now has a genuinely live check() call site (got enabledIds.has('FM011')=${enabledIds.has('FM011')})`);
  check(enabledIds.has('FM156'), `FM156 now has a genuinely live check() call site (got enabledIds.has('FM156')=${enabledIds.has('FM156')})`);

  const categoryLoopMatch = fnSrc.match(/categoryFmIds\s*=\s*\{([^}]*)\}/);
  assert(categoryLoopMatch, 'categoryFmIds map must be found');
  const loopIds = [...categoryLoopMatch[1].matchAll(/'FM(\d+)'/g)].map(x => 'FM' + x[1]);
  check(loopIds.length === 13, `categoryFmIds loop: exactly 13 category-driven FM IDs found (got ${loopIds.length})`);

  const totalWired = new Set([...enabledIds, ...loopIds]).size;
  check(totalWired === 118, `TOTAL genuinely-wired FM IDs (105 literal + 13 loop, deduplicated): exactly 118 (got ${totalWired})`);
}

// ---------------------------------------------------------------
// FM156 dynamic tests - the IV-sanity-ceiling investigation's real,
// buildable outcome: a mathematically-derived BSM-inversion
// consistency check (checkIVInternalConsistency), never a fabricated
// raw-IV ceiling number. Confirms the real solveImpliedVolatility()
// (already-tested, unmodified) genuinely drives this new check both
// ways: silent on an internally-consistent quote (even an extreme
// one), fires on a quote no real BSM inversion can rationalize.
// ---------------------------------------------------------------
{
  // A real, internally-consistent quote: price a real option via the
  // SAME bsGreeks() this app already trusts, then feed that EXACT
  // price back in as "the live quote" - a real, honest round-trip,
  // not a synthetic flag.
  const S = 23500, K = 23500, days = 7, iv = 18, r = 0.065;
  const genuinePrice = bsGreeks(S, K, days / 365, iv, 'CE', r).price;
  const fmConsistent = evaluatePreTradeFailureModes({ brain: null, ctx: { optPrice: genuinePrice, decay: { snapshot: { spot: S, strike: K, daysExp: days, optionType: 'CE', r } } } });
  check(!fmConsistent.triggered.some(t => t.id === 'FM156'), 'FM156 silent: a real, genuinely BSM-consistent quoted premium must NOT fire');

  // A real, internally-INCONSISTENT quote: a premium far BELOW real
  // intrinsic value for a deep ITM option (23500 spot, 20000 strike -
  // real intrinsic is 3500) - solveImpliedVolatility's own real
  // precondition guard genuinely rejects this (arbitrage-violating),
  // exactly the class of corrupt/stale quote this check exists for.
  const fmInconsistent = evaluatePreTradeFailureModes({ brain: null, ctx: { optPrice: 1, decay: { snapshot: { spot: 23500, strike: 20000, daysExp: 7, optionType: 'CE', r: 0.065 } } } });
  check(fmInconsistent.triggered.some(t => t.id === 'FM156'), 'FM156 fires: a real quote genuinely below intrinsic value (unsolvable at any real IV) must fire');

  // Missing real data (no decay.snapshot) - must NOT fire, matches
  // this file's own fail-silent-on-missing-data discipline.
  const fmNoSnapshot = evaluatePreTradeFailureModes({ brain: null, ctx: { optPrice: 100 } });
  check(!fmNoSnapshot.triggered.some(t => t.id === 'FM156'), 'FM156 silent: no real decay.snapshot data this refresh - honestly not checkable, must NOT fire');
}

// ---------------------------------------------------------------
// FM011 dynamic reachability + condition test - this follow-up pass's
// one genuine wiring fix. Reuses the real, unmodified
// computeReversalSignal() and evaluatePreTradeFailureModes() (already
// eval'd into this process above), builds real 25+-candle series whose
// EMA9/EMA21 relationship genuinely flips in the last 3 candles, and
// confirms FM011 fires only when that real reversal direction is
// opposite the real CE=bullish/PE=bearish trade direction convention
// (assets/fno-lab-core.js:7745, computeWrongSidePositioningCheck) -
// never fabricated, never a guessed threshold.
// ---------------------------------------------------------------
{
  // Build a real close-price series: 25 candles flat/declining (so
  // EMA9 stays below EMA21), then a real, sharp last-3-candle rally
  // strong enough to flip EMA9 above EMA21 - a genuine bullish
  // reversal-in-progress, not a synthetic flag.
  function buildReversalCandles(bullishFlip) {
    const candles = [];
    let price = 100;
    for (let i = 0; i < 25; i++) { candles.push({ c: price }); price -= 0.2; }
    const lastMoves = bullishFlip ? [8, 10, 12] : null;
    if (bullishFlip) { lastMoves.forEach(m => { price += m; candles.push({ c: price }); }); }
    else { [8, 10, 12].forEach(m => { price -= m; candles.push({ c: price }); }); }
    return candles;
  }

  const bullishReversalCandles = buildReversalCandles(true);
  const sig = computeReversalSignal(bullishReversalCandles);
  check(sig.isReversal === true && sig.direction === 'bullish', `computeReversalSignal: real candle series genuinely produces a bullish reversal (got isReversal=${sig.isReversal}, direction=${sig.direction})`);

  const fmPE = evaluatePreTradeFailureModes({ brain: null, ctx: { candles: bullishReversalCandles }, optionType: 'PE' });
  check(fmPE.triggered.some(t => t.id === 'FM011'), 'FM011 fires: real bullish reversal + PE (bearish) trade direction - genuinely opposite, must fire');

  const fmCE = evaluatePreTradeFailureModes({ brain: null, ctx: { candles: bullishReversalCandles }, optionType: 'CE' });
  check(!fmCE.triggered.some(t => t.id === 'FM011'), 'FM011 silent: real bullish reversal + CE (bullish) trade direction - genuinely aligned, must NOT fire');

  const flatCandles = [];
  for (let i = 0; i < 30; i++) flatCandles.push({ c: 100 });
  const fmFlat = evaluatePreTradeFailureModes({ brain: null, ctx: { candles: flatCandles }, optionType: 'PE' });
  check(!fmFlat.triggered.some(t => t.id === 'FM011'), 'FM011 silent: no real reversal detected at all - must NOT fire regardless of direction');

  const fmNoOptionType = evaluatePreTradeFailureModes({ brain: null, ctx: { candles: bullishReversalCandles } });
  check(!fmNoOptionType.triggered.some(t => t.id === 'FM011'), 'FM011 silent: no optionType supplied - cannot determine trade direction, must NOT fire');
}

// ---------------------------------------------------------------
// Reachability: every triggered check must flow into the function's
// final aggregate result (no early return before the aggregation,
// no dead branch). Verified by direct execution, not just static
// inspection - a real evalCtx that satisfies FM002's condition must
// actually appear in the returned triggered[] and set finalAction.
// ---------------------------------------------------------------
{
  const brain = { regime: { specialCondition: 'Panic', volatility: 'Low Vol', extendedStates: [] }, decision: 'BUY_READY', confidence: 'High', criticalFails: 0, results: [] };
  const result = evaluatePreTradeFailureModes({ brain, ctx: null });
  const fm002 = result.triggered.find(t => t.id === 'FM002');
  check(!!fm002, 'evaluatePreTradeFailureModes: FM002 (Panic regime) genuinely fires and appears in triggered[] given a real Panic brain.regime');
  check(result.finalAction === 'block', 'evaluatePreTradeFailureModes: FM002 firing correctly sets the function\'s own finalAction to block (reachable all the way to the return statement)');
}

console.log(`\n${passed} passed, ${failed} failed (fm-library-inventory-audit.test.js)`);
process.exit(failed > 0 ? 1 : 0);
