# Interaction & Synchronization Audit — 2026-08-30 (Phase 3)

Scope: how multiple simultaneously-true conditions combine across the
decision engine — the class of bug Phase 2 found one instance of
(`adjustFailureModesForTradeType()`'s priority-array gap). This pass
re-audits that function in full, hunts for sibling bugs in similar
"combine N triggered things into one verdict" logic, checks for
double-counted factors, checks how genuinely conflicting indicators are
handled, and checks for latent evaluation-order dependencies.

All findings below are backed by real code execution
(`tests/interaction-sync-audit.test.js`), not just reading source.

## 1. Full re-audit of `adjustFailureModesForTradeType()`

Read in full at `assets/fno-lab-core.js:5762-5810` (beyond just the
priority-array fix Phase 2 already made and locked in
`tests/fm-library-inventory-audit.test.js`).

The function has exactly two places where multiple simultaneously-
triggered conditions get combined:

1. **Per-item severity/action escalation** (`adjustedIdx`,
   `adjustedAction`, lines 5766-5775) — a real, deliberately bounded
   one-level bump (`Math.min(originalIdx + 1, ...)`, never more, never
   past `critical`) applied independently to each triggered item based
   on `typeRelevance`. This is a per-item transform, not a combination
   of multiple items, so it has no cross-item ordering/priority logic
   to get wrong the way the final-action reduction does.
2. **The final-action reduction** (`priority`/`adjustedFinalAction`,
   lines 5796-5800) — this is the exact mechanism Phase 2's bug lived
   in. Now uses the full 7-entry priority ordering
   (`['block','reject','require_confirmation','delay','reduce_position_size','reduce_confidence','none']`),
   matching `evaluatePreTradeFailureModes()`'s own priority list
   (line 5680) exactly in the first six entries.

**No second fragile-combination site was found inside this function
itself** — it genuinely only reduces one list to one final action, once.

### Sibling site found: `evaluatePreTradeFailureModes()`'s own reduction (line 5680-5684)

The RAW (pre-trade-type-adjustment) function has its **own**, separate
priority-list reduction, structurally identical to the one Phase 2
fixed:
```js
const priority = ['block', 'reject', 'require_confirmation', 'delay', 'reduce_position_size', 'reduce_confidence', 'proceed'];
let finalAction = 'proceed';
triggered.forEach(t => { if (priority.indexOf(t.action) < priority.indexOf(finalAction)) finalAction = t.action; });
```
This list is **already complete** (includes `reject`/`delay`/
`reduce_position_size` — it was never missing anything; Phase 2's bug
was specific to the *adjusted* function's copy of this list, which had
drifted out of sync). Confirmed by grepping every literal `action`
string passed to `check(...)` calls in the function body
(`block`, `delay`, `reduce_confidence`, `reduce_position_size`,
`require_confirmation` — 5 of the list's 7 entries; `reject`/`none`
appear only as defensive/future-proofing entries, never emitted by a
live check today) — none are missing from either priority list.

**One genuine, but purely cosmetic, naming inconsistency found:** the
raw function's default/lowest-tier sentinel is `'proceed'`
(`finalAction = 'proceed'` when nothing triggers), while the adjusted
function's is `'none'` (`adjustedFinalAction = 'none'`). These two
strings are never compared against each other, and
`fmResultRaw.finalAction` (the raw result) is **never itself branched
on anywhere in the codebase** — grepped and confirmed 0 uses; only the
adjusted `fmResult.finalAction` is checked at the real gate
(`assets/fno-lab-core.js:13038`, called after
`adjustFailureModesForTradeType()` has already run). So this is a real,
confirmed-non-functional inconsistency, not a bug — locked in as a
regression test (Part 2) so a future change that starts branching on
`fmResultRaw.finalAction` directly gets caught rather than silently
inheriting a naming mismatch.

### 3+/4+/5+ simultaneous-FM test results (real execution)

`tests/interaction-sync-audit.test.js` Part 1 (9 tests, all passing):

- All 6 distinct action types firing simultaneously → resolves to `block`.
- 3 simultaneous non-critical actions (`reduce_confidence` +
  `reduce_position_size` + `delay`) → resolves to `delay` (correct
  next tier).
- 4 simultaneous FMs with a genuine `critical`/`block` condition listed
  **last** in the triggered array, mixed with `delay`/
  `reduce_position_size`/`require_confirmation` → still resolves to
  `block`. This is the exact shape of the Phase 2 bug, re-verified with
  4 conditions instead of 2.
- 5 simultaneous FMs with no `block`/`reject` present → resolves to
  `require_confirmation` (the correct next tier down, not swallowed by
  a lower one).
- 4 simultaneous FMs including `reject` → resolves to `reject` (the
  gate treats `block` and `reject` identically at the call site, both
  verified to survive against every other action).
- **Exhaustive pairwise dominance test**: every one of the 6×5/2=15
  real-action pairs, fired together in **both array orders**, always
  resolves to whichever action is earlier in the real priority list —
  0 mismatches found.
- Type-relevance severity escalation (a `medium`/`reduce_confidence`
  condition bumped to `high`/`require_confirmation` for a
  scalping-relevant ID) does **not** outrank a simultaneous genuine
  `critical`/`block` condition.
- Zero triggered conditions → `none`, no crash.

**Conclusion: no sibling bug found in the fixed function or its raw
counterpart. Phase 2's fix was correct and complete; the exhaustive
3-6-condition testing here found no analogous ordering defect.**

## 2. Double-counting audit — factors measuring the same signal

Grepped every `compute*Factors()` function body for shared input
variables (`ema`, `rsi`, `iv`, `oi`, `delta`, `momentum`, `crossover`,
`percentile`) across categories. This codebase already has an unusually
high density of **deliberate, explicitly-commented** double-count
avoidance — informational (`score:0`) rows exist specifically because
an earlier session already found and fixed several: `OI Change CE/PE
Side` (Flow, informational — feeds Operator Intel instead), `Max Pain`
(Flow, informational — Operator Intel's own "Max Pain Pull Strength"
scores it), `Delta of Option` (Flow, informational — Greeks Deep scores
it), `Theta Decay Today` (Flow, informational — Decay category scores
it), `Bid-Ask deep` (Flow, informational — Costs category scores it).

10 candidate pairs were sampled for genuine independence vs. redundancy:

| Pair | Categories | Verdict |
|---|---|---|
| `NIFTY Trend vs 21 EMA` / `NIFTY vs 50 EMA Daily` | Market/Market | Independent — different EMA lookback, genuinely different trend-timeframe signal. |
| `EMA 9 vs 21 Crossover` / `NIFTY Trend vs 21 EMA` | Tech/Market | Independent — one is a crossover-state signal, the other spot-vs-single-EMA; different real inputs (EMA9 vs spot). |
| `RSI Level` (Tech) / `RSI Divergence` (via `FM016`) | Tech/FM-library | Independent — level vs divergence are different real conditions off the same RSI series, not summed as the same signal (FM016 is a gate check, not a scored factor). |
| `ATM IV` / `IV Rank/Percentile` | Vol/Vol | Independent — one is a static range check, the other a genuine historical-percentile computation; explicitly commented "same value feeding the BSM engine, not re-derived independently" for the level, percentile is a distinct derived statistic. |
| `Historical vs IV` (Vol) | Vol | Uses realized vol (from candles) vs IV — a genuinely distinct comparison, not a duplicate of `ATM IV`. |
| **`Vol Skew OTM Put vs Call` (Vol) / `Skew - OTM Put IV vs Call IV` (Greeks Deep)** | Vol/Greeks Deep | **GENUINE DOUBLE-COUNT BUG, FOUND AND FIXED** — see below. |
| `Gamma - Delta Change Speed` (Greeks Deep) / `Gamma Squeeze - Market Maker Hedging` (Greeks Deep) | Greeks Deep/Greeks Deep | Independent — same `now.gamma` value read twice, but scored against genuinely different thresholds/conditions (raw gamma magnitude vs. a squeeze-specific combined condition); not a naive duplicate. |
| `Put-Call Parity Arbitrage Broken?` (Greeks Deep) / `Vol Skew OTM Put vs Call` (Vol) | Greeks Deep/Vol | Independent — parity uses live CE/PE **prices**, skew uses live CE/PE **IV**; different real inputs despite both reading the same `ocRow`. |
| `Volume vs OI` (Flow) / `OI Change % >3%` (Flow) | Flow/Flow | Independent — one strike-specific volume/OI ratio, one aggregate-across-strikes OI-change percentage; different real aggregation scope. |
| Microstructure daemon factors (`Cumulative Delta`, `Volume Profile POC`, `Ticks/Min`) | Microstructure/Microstructure | All explicitly informational (`score:0`) by design — "real per-metric directional scoring remains separate, not-yet-built work" — correctly not summed at all. |

### Genuine bug found: same-strike IV-skew scored twice in the degraded-fallback path

`computeGreeksDeepFactors()`'s `'Skew - OTM Put IV vs Call IV'` factor
normally computes a genuinely different, cross-strike skew
(`computeIVSkewAcrossStrikes`, using `ctx.ocRows`/`ctx.expiryDates`) —
a real, independent signal from Vol category's same-strike
`'Vol Skew OTM Put vs Call'` factor.

But its **fallback branch** (reached when `ctx.ocRows`/
`ctx.expiryDates` are empty/absent but a single `ctx.ocRow` is still
present — a real, reachable partial-fetch state, not hypothetical)
computed the **exact same formula** as Vol category's factor
(`ocRow.PE.impliedVolatility - ocRow.CE.impliedVolatility`, same `< 3`
threshold) and gave it its **own non-zero score** (`0.5`/`-1`, vs
Vol's `0.3`/`-0.5`) — silently double-counting one real underlying
signal into `totalScore` by up to ~1.5 points whenever this fallback
was active. The code's own comment at the Vol-category call site
(`assets/fno-lab-core.js:8536`) already, honestly, said *"Same live
CE/PE IV pair used by Greeks Deep's Skew factor"* — the overlap was
known and documented, but the fallback branch still scored it a second
time instead of deferring to Vol's already-real score, unlike every
other documented overlap in this file.

**Fixed** at `assets/fno-lab-core.js:8879-8896`: the fallback branch
now reports `pass:true, score:0` (informational), matching this
codebase's own established pattern for every other deliberate overlap
(same treatment as `OI Change CE/PE Side`, `Max Pain`, `Delta of
Option`, `Theta Decay Today`, `Bid-Ask deep`). The normal (non-fallback,
cross-strike) path is untouched — confirmed by a real
`evaluateBrain()` run with realistic `ocRows`/`expiryDates` present,
which still shows the genuine cross-strike computation
(`tests/interaction-sync-audit.test.js` Part 3, "Normal (non-fallback)
path" test).

No other genuine double-count was found among the 10 sampled pairs.

## 3. Conflicting-indicator scenario (real execution)

Built two real `evaluateBrain()` scenarios sharing the identical
bullish price-action candle series (EMA/VWAP-confirmed uptrend):

- **Agreeing**: bullish price action + bullish flow (PCR 1.5, heavy
  CE-side OI, `ACCUMULATION` operator-intel bias) → `BUY_READY`,
  `Medium` confidence, `totalScore = 13.20`.
- **Conflicting**: identical bullish price action, but genuinely
  bearish flow (PCR 0.55, heavy PE-side OI, `DISTRIBUTION` operator-
  intel bias) → `WAIT`, `Medium` confidence, `totalScore = 8.20`.

Real, verified results: the conflicting scenario scores **strictly
lower** (8.20 < 13.20), its decision is downgraded all the way from
`BUY_READY` to `WAIT` (price action alone is not enough to open a
directional trade once flow/OI genuinely disagrees), and confidence is
never higher than the agreeing scenario. Critically, the conflicting
scenario does **not** land on a falsely-confident `BUY_READY`/`High`
result — the disagreement between the two indicator families
genuinely reduces the aggregate's magnitude and gates the decision to
`WAIT` rather than being silently averaged away into an artificially
confident middle score. This matches the intended behavior described
in the task: reduced-confidence caution, not arbitrary dominance by
either side.

## 4. Dependency-ordering audit

Searched the full body of `evaluateBrain()` (`assets/fno-lab-core.js:
10310` onward, ~460 lines) for any assignment to a `ctx.*` field
**during** the function's own execution (i.e., one `compute*Factors()`
call mutating `ctx` for a later call in the same pass to read) —
`grep`'d for `ctx\.\w+\s*=` across the entire function body.

**Result: zero matches.** Every `compute*Factors()` call reads only
`ctx` fields that were already present on entry to `evaluateBrain()`
(set by the caller — `autonomous-driver.js` or the manual-refresh flow
— before `evaluateBrain(ctx)` is invoked), never a field written by an
earlier call within the same invocation. `evaluatePreTradeFailureModes()`'s
`findResult()` helper reads `brain.results` (via closures over the
already-fully-returned `brain` object passed in as a parameter), not a
partially-built structure — so it has no ordering dependency on
`evaluateBrain()`'s own internal call sequence either; it only depends
on `evaluateBrain()` having *finished*, which is structurally
guaranteed by JavaScript's synchronous, single-threaded call semantics
(the caller literally cannot call `evaluatePreTradeFailureModes()` with
a `brain` value before `evaluateBrain()` returns it).

**No latent ordering dependency was found.** A future reordering of the
`compute*Factors()` calls inside `evaluateBrain()` would be safe with
respect to this specific failure mode (though any such reordering
should still re-run the full suite, since `evaluateBrain()`'s
`decisionTier`/threshold logic near the end of the function is written
assuming `totalScore`/`directionalScore` are fully accumulated by that
point — a structural, not merely conventional, dependency, and one this
audit did not find any violation of).

## 5. Genuine bugs found and fixed this pass

1. **Same-strike IV-skew double-counted in `computeGreeksDeepFactors()`'s
   degraded fallback** (§2) — fixed at `assets/fno-lab-core.js:8879-8896`
   by making the fallback branch informational (`score:0`), consistent
   with this file's own established double-count-avoidance pattern.
   This is a real, previously-undetected sibling of the class of bug
   Phase 2 hunted for — not in the FM-priority-reduction machinery
   itself (which re-audited clean), but in the parallel "same signal,
   two scored factors" failure mode the task also asked to check.

No other genuine bugs were found. The `adjustFailureModesForTradeType()`
re-audit and the exhaustive 3-6-condition simultaneous-FM testing found
no sibling of the Phase 2 priority-array bug. The `'proceed'` vs
`'none'` default-string mismatch is real but confirmed non-functional
(never branched on). No dependency-ordering bug was found.

## 6. New tests and regression counts

New file: `tests/interaction-sync-audit.test.js` (20 new tests):
- 9 tests exhaustively exercising `adjustFailureModesForTradeType()`
  with 3, 4, 5, and 6 simultaneously-triggered conditions of different
  severities/actions, including an exhaustive pairwise dominance sweep
  across all 6 real action types in both array orders.
- 1 static source-grep test locking in that `fmResultRaw.finalAction`
  is never itself branched on (the `'proceed'`/`'none'` naming
  mismatch stays cosmetic).
- 5 tests locking in the same-strike IV-skew double-count fix (both the
  fixed fallback path and the untouched normal cross-strike path).
- 5 tests locking in the real conflicting-indicator behavior (lower
  score, no-higher confidence, no falsely-confident `BUY_READY`/`High`
  result).

**Full suite results** (measured by actually reverting the fix and
removing the new test file, running the full suite, then restoring
both and running again — not estimated):

| | Before this pass | After this pass |
|---|---|---|
| Test files | 22 | 23 |
| Total tests passed | 1288 | 1308 |
| Total tests failed | 0 | 0 |

All 22 pre-existing test files still pass unchanged. The new file's 20
tests all pass.

## 7. Kill-switch re-confirmation

`fno-lab.php:80`:
```php
define('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED', false);
```
Confirmed unchanged — still exactly `false`. Not touched, weakened, or
bypassed by any change in this pass.
