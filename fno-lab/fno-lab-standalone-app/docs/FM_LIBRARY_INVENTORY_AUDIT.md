# Failure-Mode Library — Full Inventory Audit (Phase 2)

Date: 2026-08-30. This is Phase 2 of a multi-phase decision-engine audit. Phase 1 (see `docs/FACTOR_INVENTORY_AUDIT.md`) covered the 188 scoring factors and fixed a UI category-filter bug. This phase does a full, independent, from-scratch re-verification of the Failure-Mode Library (`docs/FAILURE_MODE_LIBRARY.md`, 153 catalog entries) — the wired/unwired split, reachability of every wired check, severity/action consistency, and a fresh spot-check of the unwired entries.

**Method**: every claim below is derived by direct source parsing (`assets/fno-lab-core.js`) or direct execution (Node scripts run against the real, unmodified function bodies via the same `eval(coreSource.slice(...))` extraction pattern this repo's own test suite already uses), never from trusting the doc's own prior-stated figures. Every re-derived number is also locked into a new regression test (`tests/fm-library-inventory-audit.test.js`) so future drift is caught automatically.

## 1. Re-derived wired/unwired split

**Result: 116 wired, matches the prior "116" figure exactly — no drift since it was last stated.**

Parsed directly from `evaluatePreTradeFailureModes()` (`assets/fno-lab-core.js:4541`–`5693`, 1153 lines):

- **114** literal `check('FMxxx', ...)` call sites total.
- Of those, **10** are deliberately hardcoded `fires: false` (the literal 5th argument is `false`) — disabled duplicates kept only for historical/ID-pairing reference, confirmed never able to fire: FM023 (dup of FM035), FM011 (dup of FM017), FM040 (dup of FM127), FM041 (dup of FM047), FM037 (dup of FM091), FM038 (dup of FM092), FM125 (dup, disabled standalone), FM014 (dup of FM126), FM030 (dup of FM077), FM090 (dup of FM090's own live call — FM090 has two literal call sites; one live, one disabled duplicate of itself).
- That leaves **103** distinct literal IDs with at least one genuinely live (non-`false`) call site.
- Plus **13** more IDs (FM112–FM124) wired via the `categoryFmIds` object-driven loop at `assets/fno-lab-core.js:4830` (relative line 290 inside the function), one real `check()` call per category, not hand-duplicated code.
- **103 + 13 = 116 distinct wired FM IDs.** Confirmed by direct execution, not just counting: a real `evaluatePreTradeFailureModes()` call with a Panic-regime `brain` genuinely returns FM002 in `triggered[]` (see test file, "Reachability" section).

No duplicate enabled IDs exist except one deliberate, non-buggy case: **FM155** has two mutually-exclusive `if/else if` branches (option-chain freshness vs. futures freshness) that both use the ID FM155 — not a bug, since only one branch can execute per refresh (confirmed by reading the guard: `if (ctx && typeof ctx.ocFetchedAt === 'number') {...} else if (ctx && typeof ctx.futuresFetchedAt === 'number') {...}`).

### A denominator subtlety worth flagging (doc-arithmetic note, not a code bug)

The doc's own catalog table (`docs/FAILURE_MODE_LIBRARY.md`) enumerates exactly **153** numbered rows (FM001–FM153, confirmed by parsing every `| FMxxx |` row start). Separately, **FM154 and FM155** exist as later, standalone additions (market-data / option-chain freshness checks) documented in prose but never given their own numbered table row within the 153. Both are genuinely wired.

This means the "116 wired" figure is 116 distinct FM IDs *wired in code*, but only **114 of those 116** fall within the enumerated **153**-row catalog (FM154/FM155 are the other 2). So:

- Wired-within-the-153-row-catalog: **114**
- Unwired-within-the-153-row-catalog: **39** (not 37 — see below)
- Wired-total-across-everything (153-catalog + FM154/FM155): **116**

The doc's own summary paragraph states "the remaining 37 break down as..." and lists an enumerated set that (on this pass's exact recount) is actually **39** IDs. Full list of the 39 (see the itemized table in §4 for status of each): FM008, FM011, FM037, FM041, FM042, FM043, FM050, FM059, FM062, FM065, FM066, FM079, FM088, FM089, FM095, FM096, FM098, FM099, FM107, FM108, FM109, FM125, FM135, FM136, FM137, FM138, FM139, FM140, FM141, FM142, FM143, FM144, FM145, FM146, FM147, FM148, FM149, FM152, FM153. The "37 vs 39" gap traces to FM011 (its true doc condition — "recent trend reversal within 3 candles" — is genuinely not detected anywhere in `evaluatePreTradeFailureModes`, despite `computeReversalSignal()`/`active_reversal_bullish`/`active_reversal_bearish` existing and being consumed elsewhere in `computeExtendedRegimeStates()`, just never fed back into a `check('FM011', ...)` call) and FM041 (also listed as its own row, correctly "still open" per the doc's own prose, but not counted in that specific "37" tally). This is a minor documentation-arithmetic drift, not a functional gap — every one of the 39 IDs is independently confirmed genuinely unwired below. No code or doc-listed conclusion changes as a result; this note exists purely so the "37" figure in the doc's summary paragraph is not silently propagated as correct in a future pass.

## 2. Reachability findings

**Every one of the 116 wired FM IDs is genuinely reachable and flows into the function's own aggregate result.**

Verified two ways:

1. **Static**: `evaluatePreTradeFailureModes()` has exactly one `return` statement (`assets/fno-lab-core.js:5693` area, `return { finalAction, triggered, summary }`), at the very end of the function, after every guard block (`if (brain) {...}`, `if (ctx) {...}`, `if (trapSignal) {...}`, etc. — 21 guard blocks total, all real data-presence checks on `evalCtx` fields, never a hardcoded `if (false)` or unreachable branch). Every `check()` call pushes into the same shared `triggered` array (a closure variable, not scoped per-block), and the trailing `priority.forEach` loop over `triggered` computes `finalAction` from every entry regardless of which guard block it came from. There is no early return anywhere before this aggregation — a check inside, say, the `if (latencyCheck) {...}` block at line ~715 is exactly as reachable as one in the first `if (brain) {...}` block at line 1.
2. **Dynamic**: executed the real function directly (not a mock) with a `brain` object shaped to satisfy FM002's condition (`regime.specialCondition === 'Panic'`) and confirmed by direct assertion that (a) FM002 appears in the returned `triggered[]` array and (b) `finalAction === 'block'` on the raw (pre-trade-type-adjustment) result. See `tests/fm-library-inventory-audit.test.js`, "Reachability" section.

The one place reachability was **not** originally guaranteed was *after* `evaluatePreTradeFailureModes()` returns — see §3 below, which found a genuine bug in the caller-side post-processing (`adjustFailureModesForTradeType()`), not in `evaluatePreTradeFailureModes()` itself.

## 3. Severity/action consistency — sample findings, and the one genuine bug found

Sampled **all 40** `critical`/`high`-severity wired checks (exceeds the required 30). Full list in the itemized table (§4), column "Sev/Action Consistent".

**Base-layer finding: consistent.** Every `critical`-severity check's declared `action` is `'block'` (confirmed for all critical rows: FM002, FM023, FM025, FM035, FM036, FM038, FM041(disabled dup only), FM044, FM047, FM048, FM061, FM090, FM092, FM128, FM130, FM150, FM151 — every live one). The raw `evaluatePreTradeFailureModes()` `priority` array (`['block','reject','require_confirmation','delay','reduce_position_size','reduce_confidence','proceed']`) puts `'block'` at index 0, the highest priority — so a fired critical check genuinely, correctly sets `finalAction === 'block'` at the function's own return. No mislabeled critical check was found (no critical check that only "logs" without contributing to `finalAction`).

**Genuine bug found in the caller-side adjustment layer** (`adjustFailureModesForTradeType()`, `assets/fno-lab-core.js:5762`, called from `tryOpenAutoTradePosition` right before the real gate at line 13019 — `if (fmResult.finalAction === 'block' || fmResult.finalAction === 'reject')`):

This function has its **own**, separate `priority` array used to compute an "adjusted" `finalAction` after applying trading-type-relevance severity bumps. That array was:

```js
const priority = ['block', 'require_confirmation', 'reduce_confidence', 'none'];
```

This omits three real actions that live `check()` calls genuinely use: `'reject'`, `'delay'` (e.g. FM021), and `'reduce_position_size'` (e.g. FM022, FM023, FM045). `Array.prototype.indexOf()` returns `-1` for a value not in the array, and **`-1` is less than every real, present index** (`0`, `1`, `2`, `3`). Since the comparison is `priority.indexOf(t.adjustedAction) < priority.indexOf(adjustedFinalAction)`, any triggered `'delay'` or `'reduce_position_size'` condition was *always* treated as strictly more severe than an already-set `'block'` — silently overwriting a genuine critical block with `'delay'`/`'reduce_position_size'` whenever both fired in the same refresh, **regardless of trigger order**.

Confirmed by direct execution before the fix:

```
Input:  FM002 (critical/block, Panic regime) + FM021 (medium/delay, Bollinger squeeze) both triggered
Before: adjusted.finalAction === 'delay'   <-- BUG: the real gate only checks for 'block'/'reject', so this trade would NOT have been blocked
After:  adjusted.finalAction === 'block'   <-- fixed
```

This is a real false-sense-of-protection bug exactly matching the audit brief's concern (a check *labeled* critical whose actual downstream handling doesn't block): here the check itself was correctly labeled and correctly wired, but the **trade-type-aware post-processing step downstream of it** could silently downgrade the aggregate decision whenever a second, lower-tier condition also fired in the same refresh — a realistic scenario (e.g. panic regime + Bollinger squeeze can co-occur).

**Fix applied** (`assets/fno-lab-core.js:5777`, in `adjustFailureModesForTradeType()`): the `priority` array now uses the exact same full ordering `evaluatePreTradeFailureModes()` itself already uses (`['block', 'reject', 'require_confirmation', 'delay', 'reduce_position_size', 'reduce_confidence', 'none']`), so every real action value has a real, present index and the min-index-wins comparison is correct regardless of which actions co-occur or in what order. Verified fixed by re-running the same reproduction (now returns `'block'` as shown above) plus 4 additional regression cases (delay-alongside-block in a different order, reduce_position_size-alongside-block, a lone-delay sanity check to confirm the fix doesn't regress the no-block case to `'none'`, and a require_confirmation-only sanity check) — all in `tests/fm-library-inventory-audit.test.js`.

No other severity/action mismatch was found in the 40-item critical/high sample. `require_confirmation` (the standard `high` mapping) and `reduce_confidence`/`reduce_position_size` (the standard `medium`/`high` mappings for less-severe cases) were used consistently with their declared severities; a small number of `high`-severity checks (FM024, FM027) deliberately use `'block'` instead of the more common `require_confirmation` — this is a *stricter* action than the severity label implies, i.e. more protective, not less, and therefore not a "false sense of protection" case.

## 4. Full 153-entry itemized table

Columns: **Wired** (a genuinely live, non-`false` check() call exists, or is loop-driven for FM112–124) · **Reachable** (flows to the function's own returned `finalAction`/`triggered[]` — "Yes" for every wired entry per §2's static+dynamic proof) · **Severity/Action** (doc-declared, cross-checked against the live code's own literal arguments where wired) · **Sev/Action Consistent** ("Yes" = sampled in §3 and confirmed consistent; "not-sampled" = wired but outside the 40-item critical/high sample this pass drew) · **Unwired spot-check** (this pass's fresh re-verification of a subset of the 39 unwired entries, per the task's request for 8-10; 10 were spot-checked).

| FM ID | Wired | Reachable | Severity (doc) | Action (doc) | Sev/Action Consistent | Unwired spot-check (this pass) |
|---|---|---|---|---|---|---|
| FM001 | Yes | Yes | high | require_confirmation | Yes | - |
| FM002 | Yes | Yes | critical | block | Yes | - |
| FM003 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM004 | Yes | Yes | high | reduce_confidence | Yes | - |
| FM005 | Yes | Yes | high | reduce_confidence | Yes | - |
| FM006 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM007 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM008 | No | - | medium | reduce_confidence | - | confirmed still blocked (no reliable intraday-move signal at this call site) |
| FM009 | Yes | Yes | high | require_confirmation | Yes | - |
| FM010 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM011 | No | - | medium | reduce_confidence | - | not spot-checked this pass (see §1 denominator note — a genuine, previously-uncounted open gap: `computeReversalSignal`/`active_reversal_*` exist and feed regime taxonomy but are never checked against trade direction inside `evaluatePreTradeFailureModes`) |
| FM012 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM013 | Yes | Yes | medium | block | not-sampled | - |
| FM014 | Yes | Yes | medium | reduce_confidence | Yes | - |
| FM015 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM016 | Yes | Yes | high | require_confirmation | Yes | - |
| FM017 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM018 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM019 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM020 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM021 | Yes | Yes | medium | delay | not-sampled | - |
| FM022 | Yes | Yes | high | reduce_position_size | Yes | - |
| FM023 | Yes | Yes | critical/high (two entries: disabled critical dup + live high) | block / reduce_position_size | Yes | - |
| FM024 | Yes | Yes | high | block | Yes | - |
| FM025 | Yes | Yes | critical | block | Yes | - |
| FM026 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM027 | Yes | Yes | high | block | Yes | - |
| FM028 | Yes | Yes | high | require_confirmation | Yes | - |
| FM029 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM030 | Yes | Yes | high | require_confirmation | Yes | - |
| FM031 | Yes | Yes | high | require_confirmation | Yes | - |
| FM032 | Yes | Yes | high | require_confirmation | Yes | - |
| FM033 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM034 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM035 | Yes | Yes | critical | block | Yes | - |
| FM036 | Yes | Yes | critical | block | Yes | - |
| FM037 | No | - | high | require_confirmation | - | confirmed still blocked (needs `hypothesisOutcome`, not in `evaluatePreTradeFailureModes`'s evalCtx) |
| FM038 | Yes | Yes | critical | block | Yes | - |
| FM039 | Yes | Yes | high | require_confirmation | Yes | - |
| FM040 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM041 | No | - | critical | block | - | confirmed still blocked (trailing-SL misconfig is inherently an open-position concept, doesn't naturally apply pre-trade) |
| FM042 | No | - | low | require_confirmation | - | confirmed still blocked (`checkPartialExit` has no separate, user-configurable "partial level" field — the doc condition describes a feature shape this app genuinely does not have) |
| FM043 | No | - | critical | block | - | not spot-checked this pass (doc: structurally dead code, already enforced earlier in the real call chain) |
| FM044 | Yes | Yes | critical | block | Yes | - |
| FM045 | Yes | Yes | medium | reduce_position_size | not-sampled | - |
| FM046 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM047 | Yes | Yes | critical | block | Yes | - |
| FM048 | Yes | Yes | critical | block | Yes | - |
| FM049 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM050 | No | - | high | reduce_confidence | - | not spot-checked this pass (doc: confirmed pure duplicate of FM005) |
| FM051 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM052 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM053 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM054 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM055 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM056 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM057 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM058 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM059 | No | - | low | reduce_confidence | - | confirmed still blocked (`computeOIVelocityAcceleration` has no client-side caller anywhere in this codebase — no synchronous data source at the entry-flow call site) |
| FM060 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM061 | Yes | Yes | critical | block | Yes | - |
| FM062 | No | - | low | reduce_confidence | - | not spot-checked this pass |
| FM063 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM064 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM065 | No | - | high | reduce_confidence | - | not spot-checked this pass (doc: permanent — no live news feed source exists) |
| FM066 | No | - | high | reduce_confidence | - | not spot-checked this pass (doc: permanent — no macro calendar source exists) |
| FM067 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM068 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM069 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM070 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM071 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM072 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM073 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM074 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM075 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM076 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM077 | Yes | Yes | high | require_confirmation | Yes | - |
| FM078 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM079 | No | - | low | reduce_confidence | - | not spot-checked this pass |
| FM080 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM081 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM082 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM083 | Yes | Yes | high | reduce_confidence | Yes | - |
| FM084 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM085 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM086 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM087 | Yes | Yes | low | require_confirmation | not-sampled | - |
| FM088 | No | - | low | reduce_confidence | - | confirmed still blocked (same missing per-strike OI-history data source as FM059) |
| FM089 | No | - | low | reduce_confidence | - | not spot-checked this pass |
| FM090 | Yes | Yes | critical | block | Yes | - |
| FM091 | Yes | Yes | high | require_confirmation | Yes | - |
| FM092 | Yes | Yes | critical | block | Yes | - |
| FM093 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM094 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM095 | No | - | critical | block | - | **FREE WIN partially unlocked, not yet fully closable**: `ctx.accountCurrentDrawdownPct` (`assets/fno-lab-core.js:11482`, from `computeEquityCurve()`) now genuinely exists in the entry-flow `ctx` — the doc's own prior blocker ("no account-balance field in ctx") is now stale. Re-checked directly: still no documented drawdown-% threshold exists anywhere in `docs/TRADING_KNOWLEDGE_BASE.md`, `docs/DECISION_MATRIX.md`, or elsewhere. Wiring a number now would be a fabricated threshold. Doc row text updated this pass to reflect the real current blocker. Genuinely still open — but for a different, narrower reason than previously documented. |
| FM096 | No | - | critical | block | - | **FREE WIN partially unlocked, not yet fully closable**: `ctx.accountCurrentBalance` (same `computeEquityCurve()` result) now genuinely exists. Same conclusion as FM095 — no documented minimum-Rs threshold exists anywhere. Doc row text updated this pass. Genuinely still open. |
| FM097 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM098 | No | - | medium | reduce_confidence | - | confirmed still blocked (no per-strike volume baseline exists anywhere in this codebase, only a whole-chain ATM CE+PE aggregate) |
| FM099 | No | - | medium | require_confirmation | - | not spot-checked this pass (same limitation as FM098) |
| FM100 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM101 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM102 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM103 | Yes | Yes | high | require_confirmation | Yes | - |
| FM104 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM105 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM106 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM107 | No | - | critical | reduce_confidence | - | not spot-checked this pass (doc: N/A — structural/architectural safeguard, not a per-trade check) |
| FM108 | No | - | low | reduce_confidence | - | not spot-checked this pass |
| FM109 | No | - | low | reduce_confidence | - | not spot-checked this pass (doc: N/A — structural, verified by test suite rather than per-trade) |
| FM110 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM111 | Yes | Yes | medium | require_confirmation | not-sampled | - |
| FM112 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM113 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM114 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM115 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM116 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM117 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM118 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM119 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM120 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM121 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM122 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM123 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM124 | Yes | Yes | medium | reduce_confidence | not-sampled | - |
| FM125 | No | - | low | reduce_confidence | - | not spot-checked this pass (literal `check('FM125', ..., false)` disabled duplicate exists in source but was not part of the 10-item spot-check sample) |
| FM126 | Yes | Yes | high | require_confirmation | Yes | - |
| FM127 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM128 | Yes | Yes | critical | block | Yes | - |
| FM129 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM130 | Yes | Yes | critical | block | Yes | - |
| FM131 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM132 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM133 | Yes | Yes | high | require_confirmation | Yes | - |
| FM134 | Yes | Yes | low | reduce_confidence | not-sampled | - |
| FM135 | No | - | critical | block | - | not spot-checked this pass (doc: real-money-specific, independently enforced at the PHP broker layer — redundant to duplicate here; kill-switch already covers this class, see §7) |
| FM136 | No | - | critical | block | - | not spot-checked this pass (same as FM135) |
| FM137 | No | - | critical | block | - | not spot-checked this pass (same as FM135) |
| FM138 | No | - | critical | block | - | not spot-checked this pass (same as FM135) |
| FM139 | No | - | critical | block | - | not spot-checked this pass (same as FM135) |
| FM140 | No | - | critical | block | - | not spot-checked this pass (same as FM135) |
| FM141 | No | - | low | block | - | not spot-checked this pass (same as FM135) |
| FM142 | No | - | medium | reduce_confidence | - | not spot-checked this pass (same as FM135) |
| FM143 | No | - | medium | reduce_confidence | - | not spot-checked this pass (doc: needs true intraday high/low, no data source) |
| FM144 | No | - | low | reduce_confidence | - | not spot-checked this pass (same as FM143) |
| FM145 | No | - | low | reduce_confidence | - | not spot-checked this pass (same as FM143) |
| FM146 | No | - | low | reduce_confidence | - | not spot-checked this pass (same as FM143) |
| FM147 | No | - | low | reduce_confidence | - | not spot-checked this pass (same as FM143) |
| FM148 | No | - | low | reduce_confidence | - | not spot-checked this pass (same as FM143) |
| FM149 | No | - | critical | block | - | confirmed still blocked (`qty % lotSize !== 0` is a genuine, distinct doc condition; not yet independently wired inside `evaluatePreTradeFailureModes` — lot-size validity is enforced elsewhere in the order-construction UI, not duplicated here) |
| FM150 | Yes | Yes | critical | block | Yes | - |
| FM151 | Yes | Yes | critical | block | Yes | - |
| FM152 | No | - | high | block | - | not spot-checked this pass (needs a real, live sum across every open Auto Trade + manual position — cross-position aggregation not yet threaded into entry-flow `ctx`) |
| FM153 | No | - | medium | require_confirmation | - | not spot-checked this pass (doc/code: `checkRealizedSlippage` fires post-fill via `reportFn`, deliberately outside `evaluatePreTradeFailureModes` since the trade has already opened by that point — informational-only by design, not a gate omission) |

**Beyond the 153-row catalog**: FM154 (candle-series data freshness) and FM155 (option-chain/futures data freshness) are both wired and reachable, each via a real `check()` call guarded by real ctx-presence checks (`if (ctx && Array.isArray(ctx.candles))` for FM154; the `ocFetchedAt`/`futuresFetchedAt` if/else-if pair for FM155). Both use `medium`/`reduce_confidence`, consistent with their non-blocking, data-quality nature.

## 5. Spot-check detail — 10 unwired entries re-verified

Per the task's request for 8-10 (not the full 39), these 10 were chosen to specifically probe whether any ctx field added later in this session (`accountCurrentBalance`, `accountCurrentDrawdownPct`, freshness timestamps, `optionType`/symbol enums) unlocked a previously-documented blocker: **FM008, FM037, FM041, FM042, FM059, FM088, FM095, FM096, FM098, FM149**.

- **8 of 10 (FM008, FM037, FM041, FM042, FM059, FM088, FM098, FM149) — confirmed still genuinely blocked, no change.** Each blocker was independently re-verified by direct source search this pass (not just trusting the doc's prior text) — see the table's "Unwired spot-check" column for each one's specific, current reason. None of this session's new ctx fields (`accountCurrentBalance`, `accountCurrentDrawdownPct`, `ocFetchedAt`, `futuresFetchedAt`, `optionType`, `requestedStrike`) resolve any of these 8 — their blockers are structurally different (missing data sources for OI-history endpoints, a feature shape the app doesn't have, an inherently open-position concept, a missing `hypothesisOutcome` field, or a real duplicate/architectural non-gap).
- **2 of 10 (FM095, FM096) — genuinely reclassified, doc updated, no code change yet possible.** Both were previously documented as blocked by "no account-balance field in ctx" — that specific blocker is now **resolved** (`ctx.accountCurrentBalance` and `ctx.accountCurrentDrawdownPct` both genuinely exist, threaded from `computeEquityCurve()` at `assets/fno-lab-core.js:11482`, confirmed by direct grep and read). However, wiring either FM095 or FM096 to an actual `check()` call still requires **a documented threshold number** (a drawdown-% for FM095, a minimum-Rs balance for FM096) — and that number genuinely does not exist anywhere in this codebase's docs (`docs/TRADING_KNOWLEDGE_BASE.md`, `docs/DECISION_MATRIX.md`, or elsewhere — re-grepped this pass). Wiring a made-up number would be exactly the kind of fabricated threshold this project's own audit standard forbids. **Not a free win in the sense of "closeable with existing data" — the missing piece is now a product decision (what %/Rs), not an engineering gap.** `docs/FAILURE_MODE_LIBRARY.md`'s FM095/FM096 rows were updated this pass to state the corrected, current blocker so a future pass doesn't re-investigate the now-resolved "missing data" half.

## 6. Genuine bugs found and fixed this pass

1. **`adjustFailureModesForTradeType()` priority-array bug** (§3) — the trade-type-aware post-processing layer's own `priority` array omitted `'reject'`, `'delay'`, and `'reduce_position_size'`, causing `Array.prototype.indexOf()`'s `-1`-for-missing behavior to make any triggered delay/reduce_position_size condition silently override an already-set `'block'`, regardless of order. **Fixed** at `assets/fno-lab-core.js:5777` by using the same full priority ordering `evaluatePreTradeFailureModes()` itself already uses. This is a genuine severity/action-consistency defect exactly of the kind the task asked to hunt for — a critical, correctly-labeled, correctly-wired check whose real-world effect on the actual trade-blocking gate could be silently defeated by an unrelated, lower-severity condition firing in the same refresh.
2. **`docs/FAILURE_MODE_LIBRARY.md` staleness (FM095/FM096 rows)** — both rows cited a blocker ("no account-balance field in ctx") that has been resolved since an earlier session threaded `ctx.accountCurrentBalance`/`ctx.accountCurrentDrawdownPct` through the entry-flow ctx (confirmed already documented, correctly, in `docs/PENDING_REQUIREMENTS.md` around line 2346-2355 — but the `FAILURE_MODE_LIBRARY.md` table rows themselves had not been updated to match). **Fixed**: both rows rewritten to state the real, current blocker (missing documented threshold number, not missing data).

No other genuine bugs were found. No fabricated data, no invented thresholds, no severity/action mismatch beyond the one above.

## 7. New tests and regression counts

New file: `tests/fm-library-inventory-audit.test.js` (13 new tests):
- 6 tests directly reproducing and locking the `adjustFailureModesForTradeType()` priority-array fix (the original bug reproduction, a second case with `reduce_position_size`, two non-regression sanity cases, and the type-relevance-escalation interaction).
- 5 static, source-level audit-lock tests re-deriving the 114-literal-calls / 10-disabled / 103-live / 13-loop / 116-total figures directly from the real function source (not the doc), so a future silent change to the wiring is caught by a failing test rather than requiring another manual audit.
- 2 dynamic reachability tests executing the real function directly.

**Full suite results:**

| | Before this pass | After this pass |
|---|---|---|
| Test files | 21 | 22 |
| Total tests passed | 1275 | 1288 |
| Total tests failed | 0 | 0 |

All 21 pre-existing test files still pass unchanged (no regression introduced by the fix — confirmed by running the full suite both before and after the edit). The new file's 13 tests all pass.

## 8. Kill-switch re-confirmation

`fno-lab.php:80`:
```php
define('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED', false);
```
Confirmed unchanged — still exactly `false`. Not touched, weakened, or bypassed by any change in this pass.

## Summary of re-derived figures (for quick reference)

- **116** FM IDs wired into the live gate (103 distinct literal `check()` IDs + 13 loop-driven FM112–124) — **unchanged from the prior session's figure**, re-derived independently and locked into a static test.
- **114** of the **153** enumerated catalog rows are wired (FM154/FM155 are the other 2 of the 116, outside the 153-row catalog — a denominator subtlety, not a functional gap).
- **39** catalog rows genuinely unwired (not 37 — see §1's denominator note).
- **All 116** wired checks are reachable (static + dynamic proof).
- **40** critical/high-severity checks sampled for severity/action consistency (exceeds the 30 minimum) — **1 genuine bug found and fixed** (the `adjustFailureModesForTradeType()` priority-array defect), 0 mismatches in the remaining 39.
- **10** unwired entries spot-checked — **8 confirmed still genuinely blocked**, **2 (FM095/FM096) had their specific blocker partially resolved by a newer ctx field**, but both remain genuinely open pending a real documented threshold number (not fabricatable).
- **1** doc-staleness fix (FM095/FM096 rows in `docs/FAILURE_MODE_LIBRARY.md`).
- **13** new regression tests added, all passing. Full suite: **1288 passed, 0 failed** across 22 files (up from 1275/0/21).
- Kill-switch (`FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED`) confirmed still `false`.

## 9. Follow-up pass (2026-09-02): the remaining 29 spot-checks + IV sanity ceiling investigation

**Scope**: the prior pass (§5 above) spot-checked 10 of the 39 unwired catalog entries. This pass individually re-verified the remaining **29**: FM011, FM043, FM050, FM062, FM065, FM066, FM079, FM089, FM099, FM107, FM108, FM109, FM125, FM135, FM136, FM137, FM138, FM139, FM140, FM141, FM142, FM143, FM144, FM145, FM146, FM147, FM148, FM152, FM153.

**Method**: re-read the FULL current `ctx` object built at the real call site (`assets/fno-lab-core.js:11526`-11594, the `const ctx={...}` literal feeding `evaluatePreTradeFailureModes` via `fmEvalCtx`) plus the full `evalCtx` destructure at the top of `evaluatePreTradeFailureModes()` itself (`assets/fno-lab-core.js:4570`), to get every field genuinely available today (including fields added earlier this long session: `accountAvailableCapital`/`accountCurrentDrawdownPct`/`accountCurrentBalance`, `ocFetchedAt`/`futuresFetchedAt`, `correlationSym`/`correlationCloses`, `portfolioPositions`/`portfolioPerPositionGreeks`, `hypothesisDirectionStats`, `strategyVersionsCache`, `requestedStrike`). Then, for each of the 29, directly re-derived (source read + grep, not trusted from prior doc text) whether its documented blocker is still genuinely true against this current field list.

**Result: 1 of 29 was genuinely unlockable (FM011) and has been wired. The other 28 remain genuinely, honestly blocked** — re-confirmed individually, not assumed:

- **FM011 — CLOSED this pass.** Genuine free win: `computeReversalSignal(ctx.candles)` (the real EMA9/21-flip-in-last-3-candles detector) already existed and was already consumed by two OTHER things this session (`computeExtendedRegimeStates`'s `active_reversal_bullish`/`active_reversal_bearish` states, and a display-only render() box at ~line 11940) but was never fed into a `check('FM011', ...)` call with its own TRUE doc condition — the only existing `check('FM011', ...)` call site was a deliberately-disabled duplicate mistagged onto FM017's MACD condition. Wired at `assets/fno-lab-core.js:5460`-5468 (inside the same `if (ctx && Array.isArray(ctx.candles))` block as FM013/FM154, right after FM013), comparing `computeReversalSignal(ctx.candles).direction` against the SAME CE=bullish/PE=bearish convention `computeWrongSidePositioningCheck` (FM032, `assets/fno-lab-core.js:7745`) already establishes — no new detector, no new data source, no new convention, no fabricated threshold (the condition is a real, existing boolean flip detector, not a numeric cutoff). Severity/action corrected from the doc's stale `high`/`require_confirmation` to `medium`/`reduce_confidence` to match every other live trend-contradiction check of this exact kind (FM009/FM010/FM016/FM017).
- **FM043, FM050, FM107, FM108, FM109, FM125, FM153** — confirmed still correctly classified as **not real gaps** (structural duplicates or architectural/non-per-trade safeguards already covered elsewhere), not merely "blocked" — no ctx change could affect these since they were never data-source-limited to begin with.
- **FM062, FM065, FM066** — re-confirmed **permanent** limitations: no holiday-calendar, live news, or macro-calendar data source exists anywhere in this codebase (re-grepped this pass — `isRealMarketHours()` still explicitly has no holiday feed; no `news`/`macro` API integration beyond the existing `newsSentiment`/`newsSentimentTier` fields, which are a sentiment SCORE, not an event-timing feed).
- **FM079, FM089, FM099** — re-confirmed still blocked: `computeIVSkewAcrossStrikes`/`computeOIRolloverFactor` both already feed the normal, already-scored factor pipeline (`brain.results`), but classifying a specific reading as "atypical"/"abnormally high" (as FM079/FM089/FM099's own doc conditions require) would need an invented normal/typical baseline this codebase does not have — same conclusion `docs/PENDING_REQUIREMENTS.md` (line ~3822) already reached for FM089 specifically, independently re-derived here for FM079/FM099 too.
- **FM135–FM142** — re-confirmed still correctly out of scope for `evaluatePreTradeFailureModes()`: these are real-money-specific safeguards enforced independently at the PHP broker layer (`fno-lab.php`), structurally unrelated to any JS `ctx` field, and duplicating them here would be redundant, not a real gap.
- **FM143–FM148** — re-confirmed still blocked: directly grepped for any intraday-high/intraday-low/`dayHigh`/`dayLow` field anywhere in `ctx` or `assets/fno-lab-core.js` — none exists; `ctx.candles` remains close-price-only (confirmed by the same `{c: ...}` shape used throughout this file, e.g. `computeReversalSignal`'s own `candles.map(c => c.c)`). No new candle-series data source was added this session, so this blocker is unchanged.
- **FM152** — re-confirmed still blocked, but **partially unlocked, same pattern as FM095/FM096**: `ctx.portfolioPositions`/`ctx.portfolioPerPositionGreeks` (a real, live cache of every open Auto Trade + manual position, already threaded into `evalCtx` and already used by FM045/FM046's correlation/hedge checks) genuinely exists and genuinely COULD sum real committed capital across every open position. What's still missing is a real, documented exposure-% threshold (the doc's own condition cites "a real, documented threshold (e.g. 50%)") — re-grepped `docs/TRADING_KNOWLEDGE_BASE.md`/`docs/DECISION_MATRIX.md` this pass, confirmed no such number exists anywhere. Wiring a specific %, would be exactly the fabricated-threshold case the audit standard forbids. Doc row unchanged in content (already correctly described the blocker) but this pass's fresh re-check confirms it's current.

### IV sanity ceiling investigation — outcome: BUILT (FM156)

A prior pass found IV=500% accepted with no dedicated flag and correctly declined to fabricate a raw ceiling number (no NSE/SEBI-published max-plausible-IV figure exists, and none exists in this codebase's own docs). This pass investigated whether a mathematically-honest alternative exists — **it does, and it has been built**:

This codebase already has its own real, tested, reverse-Black-Scholes implied-volatility solver, `solveImpliedVolatility()` (`assets/greeks-engine.js:133`), built earlier this session for the Kite-fallback IV path. That solver already has a real, principled non-convergence definition, pre-existing and independently justified (not invented for this task): Newton-Raphson genuinely diverging outside `[0%, 500%]`, or vega collapsing near zero (`assets/greeks-engine.js:170`-172) — a statement about what THIS APP'S OWN pricing model considers "no real, plausible IV can price this quote", derived from the solver's own iteration mechanics, not a fresh number picked for this check.

**New function `checkIVInternalConsistency()`** (`assets/fno-lab-core.js`, right after `checkOptionChainFreshness()`) reuses that exact solver, unmodified, as a genuine Black-Scholes-inversion CONSISTENCY check: given the SAME live premium/spot/strike/days this app already uses to price the option (`ctx.optPrice`, `ctx.decay.snapshot.{spot,strike,daysExp,optionType,r}` — all already available, no new fetch, no new ctx field), does the app's own trusted BSM engine, working independently from the quoted premium, converge on ANY real IV at all? If it genuinely cannot, that's real, structural evidence the quoted premium is internally inconsistent with Black-Scholes at any plausible volatility — mathematically corrupt/illiquid data, not merely "IV looks high".

This is strictly narrower and more honest than a raw IV ceiling: a reported IV of, say, 300% that DOES reproduce the live premium via BSM inversion (a genuinely extreme but internally-consistent quote, e.g. right before a binary event) does NOT fire this check — only a quote this app's own pricing engine cannot rationalize at any IV does. Wired as **FM156** (`assets/fno-lab-core.js`, inside `evaluatePreTradeFailureModes()`'s `if (ctx)` block, right after FM032), `medium`/`reduce_confidence` (matching FM154/FM155's severity for this same class of live-but-suspect data), beyond the 153-row catalog matching the FM154/FM155 precedent.

### New tests and regression counts (this follow-up pass)

`tests/fm-library-inventory-audit.test.js` grew from 13 to **23 tests** (10 new):
- 1 static audit-lock update (literal-call-count/enabled-ID-count/total-wired-count bumped from 114/103/116 to 116/105/118, reflecting both FM011's and FM156's new literal call sites) plus 2 new existence assertions (`enabledIds.has('FM011')`, `enabledIds.has('FM156')`).
- 5 dynamic FM011 tests (real bullish-reversal candle series genuinely produced by `computeReversalSignal`, fires on PE/opposite-direction, silent on CE/aligned-direction, silent on no-reversal, silent on no-optionType).
- 3 dynamic FM156 tests (silent on a real BSM round-trip-consistent quote — priced via the SAME `bsGreeks()` this app trusts and fed back in as "the live quote"; fires on a real, genuinely-below-intrinsic quote the solver's own existing precondition guard rejects; silent when `ctx.decay.snapshot` is genuinely missing).
- Test harness updated to load `assets/greeks-engine.js` into the same eval scope as `assets/fno-lab-core.js` (same established pattern already used by `tests/medium-low-tier-fm-nan-audit.test.js`), needed since FM156 depends on `solveImpliedVolatility`/`bsGreeks`.

**Full suite results** (every `tests/*.test.js` file run individually, node exit code + printed pass/fail count captured per file, matching the counting method Phase 10 of `docs/PENDING_REQUIREMENTS.md` already established as this codebase's own regression baseline):

| | Before this pass | After this pass |
|---|---|---|
| Test files (JS) | 27 | 27 |
| Total JS tests passed | 1433 | 1443 |
| Total JS tests failed | 0 | 0 |

All 27 test files pass, 0 regressions. (PHP suite: no PHP files were touched this pass — FM152/FM135-142's PHP-layer conclusions were verified by direct source read, not by running `phpunit`, since no `vendor/`/`composer.json` is present in this environment to run it; the last full PHP baseline, per Phase 10 of `docs/PENDING_REQUIREMENTS.md`, was 920 passed/0 failed across 50 standalone-runnable files, unaffected by this pass's JS-only changes.)

### Kill-switch re-confirmation

`fno-lab.php:80`:
```php
define('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED', false);
```
Confirmed unchanged — still exactly `false`. Not touched, weakened, or bypassed by any change in this pass.

### Updated summary figures (this follow-up pass)

- **118** FM IDs now wired into the live gate (105 distinct literal `check()` IDs + 13 loop-driven FM112-124), up from 116 — **+2** this pass (FM011 genuinely closed, FM156 genuinely new).
- **38** of the original 39 catalog-listed unwired entries remain genuinely unwired (FM011 closed); FM156 is a new, beyond-153-catalog entry (same category as FM154/FM155), not a closure of an existing catalog row.
- **29** previously-not-individually-spot-checked unwired entries re-verified this pass — **28 confirmed still genuinely blocked** (with a fresh, current reason each), **1 (FM011) genuinely unlockable and wired**.
- **1** new, mathematically-derived (not fabricated) IV sanity check built (FM156), closing the "IV=500% accepted with no flag" gap identified by a prior pass without inventing an arbitrary ceiling number.
- **10** new regression tests added this pass (13→23 in `tests/fm-library-inventory-audit.test.js`), all passing. Full JS suite: **1443 passed, 0 failed** across 27 files (up from 1433/0/27).
- Kill-switch (`FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED`) confirmed still `false`.
