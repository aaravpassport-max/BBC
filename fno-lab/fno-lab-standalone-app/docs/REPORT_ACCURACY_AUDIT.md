# Phase 6 Audit: Report/BrainLog Accuracy

Audit of the actual data structure a user sees as "the report" for a
trade decision, and whether it accurately, honestly, and completely
represents what the decision engine actually did this refresh.

## 1. What "the report" actually is

Traced end to end:

- `evaluateBrain(ctx)` (`assets/fno-lab-core.js:10338`) - the core
  scoring pass. Returns `{results, totalScore, directionalScore,
  tradeQualityScore, modelQualityScore, humanOperatorScore, riskScore,
  passCount, failCount, criticalFails, decision, decisionTier, reason,
  operatorIntel, factorRegistry, confidence, rawConfidence,
  regimeAdjustment, failureLibraryAdjustment, tradeTypeWeighting,
  tradeTypeWeightingAdjustment, tradeTypeAdjustment,
  categoriesNotEvaluated}` (last field added this phase, see section 2).
- `evaluatePreTradeFailureModes(evalCtx)` (`fno-lab-core.js:4569`) - the
  live Failure-Mode Library gate. Returns `{finalAction, triggered,
  summary}`.
- `buildEntrySnapshot(brain, ctx, sym)` (`fno-lab-core.js:6463`) - the
  frozen, at-entry-time report that actually gets journaled with a
  trade (`tryOpenAutoTradePosition`, per Phase 5 (a)). Returns
  `{factors, counts, coveragePct, totalScore, decision, confidence,
  rawConfidence, operatorBias, strategyVersion, snapshotTs, regime,
  riskReward, expectedValueRsApprox, missingRequiredFields,
  rawTickLink, categoriesNotEvaluated}` (last field added this phase).
- Rendering: `assets/standalone-app.php` (factor-registry/coverage
  panel markup, ~L274-281) + `fno-lab-core.js` render functions
  (~L11700-11780 for the live decision/registry panel).

### 15-item checklist - verified against real code, not assumed

1. All factors evaluated - Partial, explicitly labeled. `results` array holds every factor that actually ran this refresh; `buildFactorRegistry()` (`fno-lab-core.js:1254`) cross-references the full 193-entry `factors.json` catalog and classifies every catalogued factor as COMPUTED/NOT_APPLICABLE/UNAVAILABLE/NOT_COMPUTED - not every catalogued factor runs every refresh, but which ones did is truthfully reported via `coveragePct`.
2. All applicable indicators - Yes. Same registry mechanism; NOT_APPLICABLE is a distinct, explicit status (e.g. Physical Settlement for index options), never silently omitted.
3. All triggered failure conditions - Yes. `evaluatePreTradeFailureModes()`'s `triggered` array - every `check('FMxxx', ...)` call that fired, with id/severity/action/reason each.
4. All positive/negative conditions - Yes. Every `results` row carries `pass:true/false/null` + `score`, rendered per-factor in the UI factor table.
5. Scores/calculations - Yes. `totalScore`, `directionalScore`, `tradeQualityScore`, `modelQualityScore`, `humanOperatorScore`, `riskScore` (the section-20 category-separated scores), plus per-row `score`.
6. Triggered rules - Yes. FM `triggered` list (Failure-Mode Library) + `criticalFails` (hard-block rules) are both distinct, both surfaced.
7. Reasons behind the decision - FIXED (follow-up phase, see section 8 below). `brain.reason` now cites `directionalScore` + `operatorIntel.bias/confidence` (or the critFails list) AND names the real top contributing directional factors by name (up to 4, highest-|score| first, matching sign for the winning direction) via the new `summarizeTopDirectionalFactors()` helper.
8. Factors ignored and WHY - Was silent - fixed this phase. See section 2.
9. Warnings - Yes. FM `triggered` entries at `reduce_confidence`/`require_confirmation`/`delay` severities function as warnings; `missingRequiredFields` in the entry snapshot.
10. Exceptions - Yes. pass:null rows carry an explicit reason (feed down, disabled, not reported, etc.) rather than a bare failure.
11. Risk indicators - Yes. Dedicated `riskScore`, Risk category factors (f86-f100), `riskReward`/`expectedValueRsApprox` in the entry snapshot.
12. Trade-specific findings - Yes. `buildEntrySnapshot()` freezes the exact per-trade factor state, regime, strategy version, and FM check result at open time (confirmed Phase 5).
13. Final recommendation - Yes. `decision` (`BUY_READY`/`SELL_READY`/`WAIT`/`NO_TRADE`) + `decisionTier` (7-tier, section 23).
14. Overall decision - Yes. Same as item 13.
15. Supporting evidence - Yes. Every `results` row's `reason` string names the real number/threshold that drove it (e.g. `VIX 15.0 optimal`, `PCR 1.40 oversold`) - confirmed not boilerplate by reading dozens of these call sites directly.

## 2. "Ignored factors and why" - confirmed silent, now fixed

Phase 4 found that when a whole category's real input (`ctx.candles`,
`ctx.ocRows`, `ctx.decay.snapshot`, etc.) is absent, the corresponding
`compute*Factors()` call inside `evaluateBrain()` is skipped by its own
real `if (...)` guard (e.g. `fno-lab-core.js:10486`
`if (ctx.candles && ctx.candles.length) { ... computeTechFactors ... }`),
producing **zero rows** for that category - not even a `pass:null`
placeholder.

Re-verified directly this phase: `buildFactorRegistry()` *does* catch
this at the individual-factor level (a catalogued factor with no
matching result row is marked `NOT_COMPUTED`, counted in the UI panel
at `fno-lab-core.js:11745`) - but every such registry entry carries
`resultRow: null`, so **no reason text exists anywhere** for *why* it
is `NOT_COMPUTED`. The UI showed only a bare count ("14 Not Computed"),
never the cause. This is the real silence Phase 4 flagged, confirmed
still present going into this phase.

**Fix** (`fno-lab-core.js:10697-10731`, the return of `evaluateBrain`):
added `categoriesNotEvaluated`, a real array built by mirroring the
*exact same* guard conditions already used above in the function (not
new checks, not guesses) - e.g. `if (!(ctx.candles && ctx.candles.length))
categoriesNotEvaluated.push({cat:'Tech', why:'ctx.candles
empty/unavailable this refresh (NSE chart endpoint) - Tech (f41-f60)
factors were not evaluated'})`. Covers Decay, Greeks Deep, Tech,
Market, Regulatory (Trading Halt), Vol, Costs, Flow, Fundamental
(OI Rollover), Psychology - every category-level `if` guard in the
function. Threaded through `buildEntrySnapshot()`
(`fno-lab-core.js:6508`) and rendered in the UI as a new
`#categoriesNotEvaluated` panel under the Factor Registry card
(`fno-lab-core.js` render block, markup added at
`assets/standalone-app.php:280`).

Regression tests: `tests/report-accuracy-audit.test.js` Tests 1-4
(part of 19 assertions total, see section 5) - including a cross-check
that the reported "why" genuinely corresponds to the real `results`
array having zero rows for that category's category-only factors
(e.g. RSI Level absent when candles stripped), not just an assumed
correlation.

## 3. Mismatch hunting - results

Ran 6 targeted scenarios reusing the exact ctx-builder pattern from
`tests/end-to-end-decision-engine-audit.test.js` (verified against
`autonomous-driver.js`'s real `evaluateBrain(ctx)` call shape):

- No fabricated category claims when skipped: with `ctx.candles`
  and `ctx.ocRows` both stripped, `brain.reason` was checked to never
  reference a Tech/Market/VWAP/RSI/Flow/PCR/OI-derived signal - it
  didn't (`reason` only ever cites `directionalScore` and
  `operatorIntel.bias`, both real aggregates that legitimately exclude
  Tech/Flow's contribution of exactly 0 when those categories didn't
  run). No "passed" claim found where a factor was actually
  skipped/not-applicable - `pass` is `null` for every genuinely
  unavailable/skipped row, and the UI/registry logic never coerces
  `null` to `true`.
- Reason vs. real dominant driver: in a multi-signal BUY-leaning
  scenario (2 Operator Intel signals + Tech/Market bullish factors),
  `reason` cites the real `directionalScore` value (the actual sum of
  every Directional-category row, confirmed by reading the
  `computeSeparatedScores()` call site) and the real
  `operatorIntel.bias`/`confidence` - both genuinely multi-factor
  aggregates, not a single cherry-picked factor. This is honest, though
  narrower than an itemized "these 6 factors drove this" reason string
  - noted as a real, minor UX gap (not fixed this phase; the underlying
  per-factor detail is available separately via `results` and the
  Factor Registry panel, so nothing is actually hidden, just not
  restated in the one-line `reason`).
- No single-dominant-factor mislabeling found: `reason` never names
  an individual factor as the decision driver except in the
  `criticalFails` branch, where it explicitly and correctly lists
  every critical factor's name (`Critical: ${critFails.map(f=>f.factor).join(', ')}`)
  - genuinely enumerates all of them, not just the first.
- No generic boilerplate found: every `reason` string interpolates
  the real, scenario-specific `directionalScore.toFixed(1)` and the
  real threshold constants - confirmed different text for different
  scenarios by direct execution, not templated filler.

No new decision-vs-evidence mismatch bugs were found beyond the
already-fixed silent-category gap in section 2.

## 4. Genuine bugs found and fixed this phase

1. Silent "categories not evaluated" gap (section 2) - the report gave
   a bare NOT_COMPUTED count with zero explanation of cause. Fixed via
   `categoriesNotEvaluated` (real, derived from the function's own
   existing guard conditions), threaded through `evaluateBrain()`,
   `buildEntrySnapshot()`, and the live UI panel.

No other genuine report-accuracy bugs were found this phase. The
`reason`-string narrowness noted in section 3 was originally documented
as a minor UX limitation rather than fixed (all the underlying data was
genuinely present elsewhere in the same report); it was subsequently
closed properly in a follow-up phase - see section 8.

## 5. Regression tests

New file: `tests/report-accuracy-audit.test.js` - 19 real assertions,
0 failing:
- Tests 1-4: `categoriesNotEvaluated` regression (full-data baseline
  empty; `ctx.candles` stripped -> Tech/Market/Regulatory flagged with
  a real, specific reason; `ctx.ocRows` stripped -> Flow/Fundamental
  flagged; `buildEntrySnapshot()` threads the list through unmodified,
  cross-checked against the real factor-registry NOT_COMPUTED status
  loaded from the actual `assets/factors.json` catalog).
- Tests 5-6: mismatch hunting (`reason` never claims a skipped
  category's signal; `reason` genuinely reflects the real aggregate
  multi-factor drivers in a multi-contributor scenario).

## 6. Full JS suite - before/after

Ran every `tests/*.test.js` file individually (`timeout 20 node
<file>`), 0 non-zero exits either before or after this phase's change:

- Before (24 pre-existing files, excluding the new one): 1371
  passing, 0 failing.
- After (25 files, including the 19 new assertions): 1390
  passing, 0 failing.

(Section 6's counts are this Phase 6 pass's own before/after. See
section 8's closing paragraph for the follow-up phase's own full-suite
before/after, which is larger due to the new section 8/9 test file and
the section 8 test update.)

No regressions. (`autonomous-driver/test/*.js` is a separate
integration suite with a long-running mock server process not exercised
by this audit; it was not touched and is out of scope for this
core-engine report-accuracy pass.)

## 8. Follow-up phase: `reason` now names the real top contributing factors

The section 3/7 "minor UX limitation" above was closed properly rather
than left documented-only, per explicit instruction to close every
genuine gap. Real fix, `assets/fno-lab-core.js`:

- `summarizeTopDirectionalFactors(results, directionalCats, direction,
  limit)` (new function, just above `computeDecisionTier`) - a pure
  function over the SAME `results` array and SAME `DIRECTIONAL_CATS`
  set `computeSeparatedScores()` already sums for `directionalScore`
  (no new computation, no fabrication). Filters to directional-category
  rows with a real, finite, nonzero score matching the winning
  direction's sign (`'bullish'`→positive, `'bearish'`→negative,
  `'neutral'`→either, used for WAIT), sorts by `|score|` descending,
  and formats the top N (default 4) as `Factor Name (+/-score)` joined
  with `, ` - the same `.map(...).join(', ')` pattern this file already
  uses for `critFails` (`Critical: ${critFails.map(f=>f.factor).join(', ')}`).
- Wired into all three non-critical branches of the `decision`/`reason`
  block (`evaluateBrain`, ~line 10785): BUY_READY and SELL_READY append
  `" - top contributing factors: <names>"`; WAIT appends `" -
  largest-magnitude factors (offsetting each other): <names>"` since
  neither side "won" there. The `NO_TRADE`/`critFails` branch is
  unchanged - it already names every critical factor by ID.

Before (BUY_READY example, unchanged inputs):
```
Directional score 11.0 (>= 11) bullish - operator bias NEUTRAL (LOW confidence)
```
After (same scenario):
```
Directional score 11.0 (>= 11) bullish - operator bias NEUTRAL (LOW confidence) - top contributing factors: Put-Call Parity Arbitrage Broken? (+1.5), NIFTY Trend vs 21 EMA (+1), India VIX Level (+1), PCR Level (+1)
```

`reason` is now genuinely traceable to the specific factors that drove
the result, not just the aggregate score - while the aggregate score
and operator-bias framing (already correct, per section 3) are kept
unchanged, since they remain real and useful context.

One pre-existing test needed updating as a direct consequence:
`tests/report-accuracy-audit.test.js` Test 5 previously asserted
`reason` never contains the substrings `tech|vwap|ema|rsi` or
`flow|pcr|oi` at all, under a `candles:[]`/`ocRows:null` scenario. That
assertion was too broad even before this fix's intent - it conflated
"the deep, candle/chain-only compute*Factors() functions genuinely
didn't run" (true, confirmed via `categoriesNotEvaluated`/registry
NOT_COMPUTED status) with "no factor whose name happens to contain
those substrings can ever legitimately appear" (false - the base
21EMA/VWAP/PCR checks read `ctx.ema21`/`ctx.vwap`/`ctx.pcr` directly,
independent of `candles`/`ocRows`, and were already real, always-on
inline checks even before this fix). Updated to assert specifically
that no DEEP-only factor name (RSI/MACD/Bollinger/OI-buildup) appears,
while confirming the always-on base checks legitimately do - both
verified directly against `brain.results` rows, not guessed.

New regression tests: `tests/reason-top-factors-and-boundary-rounding-audit.test.js`
(shared with section 9 below), Tests 1-2, 27 assertions total for both
sections combined, 0 failing - direct-execution proof that every named
factor is a real `brain.results` row with a sign-matching real score,
never a fabricated or sign-mismatched name.

Full JS suite, this follow-up phase, before/after (ran every
`tests/*.test.js` file individually with `node <file>`, checked exit
code + passed/failed counts):

- Before (27 pre-existing files, excluding the new one): 1443
  total assertions run, 1441 passing / 2 failing (the two
  `report-accuracy-audit.test.js` T5 assertions this phase's `reason`
  enhancement legitimately outdated - see the "one pre-existing test
  needed updating" paragraph above).
- After (28 files, including the new 27-assertion file and the T5
  update): 1470 total assertions, 1470 passing, 0 failing.

No other regressions.

## 9. Follow-up phase: boundary-comparison determinism investigated and fixed

Phase 4 of `tests/extended-scenario-matrix-audit.test.js` had documented
(not fixed) that `directionalScore` carries ~1e-14-scale float
summation noise (confirmed by direct execution there: a clean baseline
ctx reproducibly computed `14.500000000000007`, not `14.5`).

Investigation: this noise is fully deterministic within a single
process/run (same inputs → same array-build order → same summation
order → byte-identical float result every time in this single-threaded
JS engine) - confirmed directly by running 50 repeated `evaluateBrain()`
calls on fresh, logically-identical ctx objects and asserting
`Array.every(s => s === scores[0])` (`tests/reason-top-factors-and-boundary-rounding-audit.test.js`
Test 3). So it is NOT a cross-run/nondeterminism risk. It IS a real,
narrow risk to the actual `>=`/`<=` threshold comparisons: every real
factor score in this file is a multiple of 0.1 at its finest granularity
(checked directly - `0.1/0.2/0.5/1/2` are the only score increments
used anywhere), so the true, exact sum of any real combination of
factors always lands on a multiple of 0.1 - meaning a genuine boundary
case (score mathematically exactly `11.0`, the real `BUY_THRESHOLD`)
could have its float representation land a hair on the wrong side of
the integer threshold purely from summation-order noise, silently
flipping `BUY_READY` to `WAIT` (or vice versa) for a case that should
be unambiguous.

Real fix, `computeSeparatedScores()` (`assets/fno-lab-core.js`,
~line 10092): all five returned scores (`directionalScore` and the
other four category scores, for consistency) are now rounded to 4
decimal places via `Math.round(n * 10000) / 10000` - `Number` rounding,
not string formatting, so the same rounded value is what both the
threshold comparisons AND the UI's `toFixed(1)` displays see. 4dp gives
1000x headroom over the real 0.1 minimum factor granularity (so no real
sub-0.1 factor contribution can ever be masked) while reliably erasing
the documented 1e-14-scale noise before any threshold comparison
happens.

New regression tests confirm (same file, Test 3): `directionalScore` is
now exactly its own 4dp-rounded value on every call; a hand-constructed
contribution landing exactly on `BUY_THRESHOLD` now produces
`directionalScore === 11` with a strict `===` (previously undocumented
as reliably achievable - `extended-scenario-matrix-audit.test.js`'s own
comment noted landing exactly on a threshold "is therefore not reliably
achievable in a real ctx" pre-fix); and the resulting decision correctly
and reproducibly resolves `BUY_READY` at that exact boundary.

## 10. Kill-switch re-confirmation

`fno-lab.php:80` - `define('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED',
false);` - verified unchanged, exact value `false`, at the end of this
phase.
