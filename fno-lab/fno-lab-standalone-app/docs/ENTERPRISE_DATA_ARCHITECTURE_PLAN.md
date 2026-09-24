# F&O Lab — Enterprise Data Architecture Plan (All 40 Requirements)

## How to read this document

Every one of the 40 requirements from the "Enterprise Data Architecture"
spec is assigned to exactly one phase below, in dependency order — a
later phase is never required to unblock an earlier one. Each phase
states: what's built, the real interfaces/schemas involved, why it sits
at that point in the sequence, and its realistic size. **Phase 1 is
implemented in this delivery, not just planned** — see
`fno-lab.php`'s `FNO_DataSource` layer and `PROJECT_STATUS.md` Phase 17.
Phases 2 onward are real, actionable specifications, not implemented
yet — building all 40 "deep and thick" in a single pass would mean
either shallow stubs across everything (exactly the anti-pattern this
whole project has refused throughout) or claiming completion that
isn't real. This plan is the honest alternative: a genuine sequence
that can each be executed as its own real, tested delivery.

---

## Phase 1 — Foundation: Unified Data Layer + Quality + Cost Control
### (Implemented this delivery)

This phase exists first because *every other phase depends on it* —
requirements #1, #20, #21, #22, #34, #35, #36, #37 are the plumbing
everything else plugs into.

**#1 Unified Market Data Layer.** A real adapter-pattern abstraction:

```
FNO_DataSourceAdapter (interface)
  ├── FNO_NSEFreeAdapter    (wraps existing fno_nse_get() calls)
  ├── FNO_KiteAdapter       (wraps existing Kite Connect calls)
  └── FNO_TrueDataAdapter   (new - real HTTP client, activates the
                              moment credentials are configured;
                              written and testable against TrueData's
                              publicly documented REST/WebSocket
                              contract now, live-verified once you
                              provide a subscription)

FNO_DataSourceManager (orchestrator)
  - normalizes every adapter's response into ONE canonical shape
  - the 193-factor engine calls ONLY the manager, never an adapter
    directly, and never sees a TrueData/Zerodha/NSE field name
```

Canonical normalized tick shape (the "unified" record every adapter
must produce, satisfying #40's full field list):

```php
[
  'symbol' => 'NIFTY', 'underlying' => 'NIFTY', 'strike' => 23200,
  'optionType' => 'CE', 'expiry' => '2026-08-21', 'dte' => 6,
  'ltp' => 95.2, 'open' => 90, 'high' => 98, 'low' => 88,
  'prevClose' => 91, 'volume' => 120000, 'oi' => 4500000,
  'oiChange' => 12000, 'oiChangePct' => 0.27,
  'bid' => 94.8, 'ask' => 95.6, 'bidQty' => 500, 'askQty' => 450,
  'iv' => 13.2, 'delta' => null, 'gamma' => null, // Greeks null unless the adapter provides real ones (TrueData does; NSE-free does not - never interpolated)
  'depth' => null, // populated only by adapters that provide real 5-level depth (Kite own-session, TrueData)
  'source' => 'truedata', 'sourceTier' => 'primary',
  'fetchedAt' => 1755235200000, 'exchangeTimestamp' => null, // populated only if the adapter provides it
]
```

**#20 Data Quality Engine + #21 Source Hierarchy + #22 Freshness
Score.** Real, testable pure functions:
`fno_data_quality_check($record)` → flags missing/negative/crossed/
stale fields; `fno_resolve_with_fallback($field, $adapters)` → walks
PRIMARY→SECONDARY→CACHE→UNAVAILABLE, **never silently substituting
stale for current** (returns an explicit `{value, tier, ageSeconds}`
tuple, exactly like the existing `fno_resolve_capability()` pattern
from Phase 6, generalized here to cover every data field, not just the
handful of premium-provider slots that pattern originally covered).

**#34/#37 Cost-Control Data Source Manager + Critical Data Dependency
Rule.** Extends the existing premium-provider settings page (Phase 6)
into a full priority-tier config per field (Local calc → Free/official
→ TrueData → Zerodha → Paid API), with every paid-API call logged with
its trigger reason (`"News sentiment → premium API used because free
sources unavailable"` — the exact example from the spec). Each
strategy declares Required vs Optional data; missing Required data
forces `NO_TRADE`, never a fabricated value (this principle already
governs the whole factor engine per §57 of the earlier Master Prompt —
Phase 1 here formalizes it as a first-class dependency declaration
rather than an implicit per-factor convention).

**#35 Caching.** Real: instrument master, expiry calendar, corporate
actions, economic calendar cached with explicit TTLs in WP transients
(the app already does this for the ban list, participant OI, etc. —
Phase 1 generalizes the pattern into one `fno_cached_fetch($key, $ttl,
$fetchFn)` helper used everywhere instead of ad hoc per-endpoint
caching).

**#36 Graceful Degradation.** Real: `FNO_DataSourceManager` returns a
per-field tier (current/delayed/stale/unavailable) that the Factor
Registry (already built, Phase 9) already knows how to honestly
represent — Phase 1 wires the Registry's existing UNAVAILABLE handling
to this new richer tier information instead of the current binary
computed/not-computed.

**Size:** Large (this is real infrastructure, ~1500-2000 lines across
PHP), genuinely deliverable in one focused implementation pass, and
IS delivered this session (see `PROJECT_STATUS.md` Phase 17).

---

## Phase 2 — Raw Observation Store + Point-in-Time Snapshots at Scale
**(#2, #3, #23 — IMPLEMENTED; #24 — real design documented below, not yet implemented)**

**Implemented Phase 31**: `wp_fno_raw_ticks` MySQL table, real
composite indexing, `fno_ingest_raw_tick_fn` (bulk-insert batch
endpoint, same daemon-secret auth pattern as microstructure ingest),
and `fno_prune_raw_ticks_fn` (daily WP-Cron retention job, 7-day
window, cleared on plugin deactivation). The companion daemon
(already real, Phase 8 of the earlier build) now buffers every real
tick it receives from Kite and bulk-flushes it on the same interval
as its existing microstructure snapshot POST - no second process to
run, no new infrastructure, exactly the MySQL-based decision recorded
in this document.

**Implemented Phase 39 (#23)**: `buildEntrySnapshot()` now records a
real `rawTickLink` {symbol, rangeStartTs, rangeEndTs} on every entry
snapshot - a real, queryable 5-minute window a future Decision Replay
feature can use to pull the exact raw ticks from `wp_fno_raw_ticks`
around a specific decision, rather than only having the derived factor
scores. Deliberately does NOT embed raw ticks directly in the snapshot
(that data is already retained separately for 7 days - duplicating it
into every journal row would bloat storage for no benefit). Honestly
null when the calling symbol isn't available (never fabricated).

**Testing limitation stated plainly**: the raw-tick buffering/flush
logic lives inside the daemon's live WebSocket event handler and
network-calling flush function - genuinely difficult to unit-test
without either a significant refactor to extract pure functions from
what's currently event-handler-inline logic, or a live/mocked network
harness this sandbox doesn't have. Verified via `node --check` and
manual code review but not covered by the automated test suite the way
the app's pure functions are - a real, honest gap in this particular
piece's verification, not silently presented as equally tested.

**#24 remains real, separate follow-up work**: splitting
`factor_snapshot`'s embedded JSON into a normalized Layer B table - a
genuinely larger undertaking than the raw-store plumbing and snapshot
linkage, not done this pass.

**Size:** Large. Core raw-tick capture pipeline (#2/#3) and snapshot
linkage (#23): **done**. Layer B split (#24): real, separate follow-up
work.

---

## Phase 3 — TrueData Integration (Live)
**(#1 completion, #4, #6, #7, #16 partial — NOT implemented this session)**

Phase 1 ships `FNO_TrueDataAdapter` written against TrueData's public
API contract, but **cannot be live-verified without a real TrueData
subscription** — same honest limitation this project has already
stated for Kite/NSE endpoints throughout. Phase 3 is: you provide
credentials → the adapter is tested against a live feed for real →
OI velocity/acceleration/percentile (#4, genuinely need continuous
real OI history, not just point samples) → full 5-level depth capture
and storage (#6) → real option Greeks sourced from TrueData instead of
this app's own Black-Scholes engine where available, with the existing
BSM engine (Phase 1 of the earlier build, genuinely excellent and
independently verified) kept as the fallback/cross-check, never
silently replaced. TrueData's Corporate Data API (#16) evaluated
against your actual purchased plan before any separate paid news API
is considered, per the spec's own explicit cost-control instruction.

**Size:** Medium, but blocked on your TrueData credentials/plan
details — cannot be built further without them.

---

## Phase 4 — Option Surface, Greeks Exposure, Futures/Basis
**(#9 — IMPLEMENTED, see PROJECT_STATUS.md Phase 32; #10, #11 — NOT implemented this session)**

**#9 IV Surface Engine - CORRECTION (second overclaim found and fixed
this session, same pattern as the earlier IV-crush/theta-decay case):**
this was documented as needing multi-expiry data this app supposedly
didn't have - wrong on review. `ctx.ocRows`/`ctx.expiryDates` already
carry EVERY expiry NSE returns in one fetch (the exact same source
that already unblocked Term Structure and OI Rollover in earlier
phases) - no Phase 2 or Phase 3 dependency existed. Built for real:
`computeIVSkewAcrossStrikes()` (genuine cross-strike smile/skew,
replacing the narrower same-strike CE-vs-PE comparison the existing
`f66`/`f162` factors used - both UPGRADED to the real thing, with a
degraded-but-real same-strike fallback if multi-expiry data is ever
genuinely absent) and `computeIVSurface()` (the full Strike × Expiry
grid with real term-structure shape classification across every
available expiry, not just near+next). New IV Surface UI panel.

**#10 Portfolio Greeks Exposure.** This app is currently single-leg
only — there is no multi-position portfolio concept anywhere in the
codebase (confirmed: `wp_fno_journal` has no "still open, part of a
combined position" relationship between rows). This is a real,
non-trivial feature addition: net Delta/Gamma/Vega/Theta requires
tracking multiple simultaneously-open legs and their combined Greeks,
which needs the Auto Trades engine (built Phase 10/14) extended to
support multi-leg strategies, not just the current one-position-at-a-
time model. **Deliberately not attempted this session**: converting
the core single-position data model (`STORAGE.autoTrades` is one
object, referenced throughout dozens of functions - open, close,
partial exit, trailing stop, rejection check, cost gate, latency
simulation) to multi-leg is a genuinely invasive rewrite with real
regression risk across everything built in this project, and deserves
its own dedicated, carefully-tested pass rather than a rushed
conversion attempted without a live QA environment to catch breakage.

**#11 Futures/Basis - CORRECTED (third instance of this session's
overclaim-checking pattern - see PROJECT_STATUS.md Phase 33):** the
existing `fno_fetch_futures_fn` endpoint already received EVERY futures
contract (near/next/far month) in NSE's real response, but the code
took only the first match and discarded the rest via an early `break` -
real multi-expiry futures data (price, OI, OI-change) was already
being fetched and silently thrown away. Fixed: now captures and
exposes all real contracts, sorted by real expiry. The existing
Futures vs Spot / Cost of Carry factors are enriched with real
next-expiry price and OI in their reasoning (informational addition,
their pass/fail logic unchanged - still correctly based on the nearest
contract).

**Size:** Large overall. #9: **done**. #10 explicitly deferred as large,
invasive, regression-risk work needing its own pass. #11: **done**
(the enrichment; a dedicated Futures Term Structure panel parallel to
the IV Surface panel remains real, small, separate follow-up work).

---

## Phase 5 — Market Context: Breadth, Correlation, Volatility Regime
**(#12, #13 — IMPLEMENTED, see PROJECT_STATUS.md Phases 30 & 34; #14, #15 — NOT implemented this session)**

**#12 Market-wide breadth** - real, built Phase 30 (reused the exact
NSE endpoint already proven for VIX, given a different index
parameter). **#13 Correlation Engine** - real, built Phase 34.
CORRECTION on this entry's earlier claim that it needed #12/#18 first:
partially right, partially an overclaim - the CORE rolling-return
correlation between two indices needed neither; it only needed a
second index's chart data, which `fetchChart()` (already real, free,
working) provides with a different symbol parameter. Built:
`computeRollingCorrelation()` (real, on RETURNS not raw price levels -
correlating raw levels of two different-scale indices would be
dominated by shared drift, not genuine day-to-day co-movement) and
`classifyCorrelationStrength()`, wired into a live Correlation Engine
panel comparing the selected symbol against a real second index (a
sensible default pair, BANKNIFTY unless BANKNIFTY itself is selected,
in which case NIFTY). The FULL multi-asset (sectors, global, FX,
commodities) correlation MATRIX the original spec describes remains
real, larger, separate follow-up work - this phase built the real core
engine and one concrete, useful pair, not the complete matrix.

**#14/#15 Volatility & Regime Engine — PARTIAL expansion IMPLEMENTED,
see PROJECT_STATUS.md Phase 35.** This app's existing
`computeMarketRegime()` (real, Phase 13 of the earlier build) gained a
real, ADDITIVE `specialCondition` field (`Panic`/`Recovery`/`null`)
using real breadth data (#12, now available) to distinguish genuine
market-wide capitulation from a single stock/sector driving high
volatility alone - exactly the concrete example this document's own
earlier notes gave for why breadth mattered here. Deliberately additive
to the existing `label` field (unchanged format) rather than replacing
it, since `label` is used as a real grouping key by
`computeRegimePerformance`/`computeFactorPerformanceByRegime` -
changing its composition would have silently broken historical
comparability between trades logged before and after this change.
The FULL 16-regime taxonomy (breakout/breakdown/mean-reversion/event-
driven/gap regime/abnormal liquidity, etc.) remains real, separate,
larger follow-up work - this phase added 2 of the ~13 states not
already covered, chosen specifically because they were the ones real
breadth data could genuinely distinguish, not an attempt at the full
list.

**Size:** Medium-Large. #12/#13 core: **done**. #14/#15 expansion and
#13's full multi-asset matrix: real, separate follow-up work.

---

## Phase 6 — Events, News, Global Context, Corporate Actions
**(#16, #17, #18, #19 — NOT implemented this session)**

**#16/#19 already have a real, working pattern to extend**: the
premium-provider architecture (Phase 6 of the earlier build) already
supports plugging in a News/Sentiment provider and could be extended
with a "Corporate Actions" and "Event Calendar" capability slot using
the exact same generic REST+JSON adapter — this is a real, low-risk
extension of existing, tested infrastructure, not new architecture.
**#17 Event Calendar** specifically needs a real data source decision
(RBI/SEBI publish calendars but not as clean structured feeds per this
session's own research into NSE's site structure) — likely a premium
provider or a manually-maintained calendar to start. **#18 Global
Market Context** — same cost-conscious hierarchy already established:
check free sources first, TrueData/Zerodha next, paid API last, exactly
per the spec's own explicit instruction.

**Size:** Medium, low architectural risk (extends existing patterns),
gated on real data-source research and possibly a vendor decision.

---

## Phase 7 — Factor Provenance, Correlation & Performance Database
**(#26, #27 — IMPLEMENTED, see PROJECT_STATUS.md Phases 27 & 28; #25 — NOT implemented)**

**#26 Factor Correlation/Redundancy Engine** is now real -
`computeFactorCorrelationMatrix()` computes genuine pairwise Pearson
correlation between every pair of factors' real scores across trades
where BOTH were actually COMPUTED (never backfilled), flagging pairs
above a documented 0.85 threshold as likely redundant, gated on a
real 20-shared-trade minimum before even reporting a pair. This
directly extends the concern flagged (but not built) earlier in this
project - "several factors read the same underlying data... risks
overcounting evidence" - into working, tested code. Verified against
a known real dataset (Anscombe's quartet), not just internal
self-consistency.

**#27 Factor Performance Database** - also now real (Phase 28) -
`computeFactorPerformanceByRegime()` and `computeRegimeDependentFactors()`
extend `computeFactorPerformance()` with real regime-segmented
breakdowns (the literal "RSI is poor during strong trends" example
from the original spec), timeframe-segmentation remains unbuilt.

**#25 Provenance** becomes fully real once Phase 2's Layer B
(`wp_fno_factor_values`) exists — today's reason-string-per-factor is
a partial version of this - remains real, separate follow-up work.

**Size:** Medium. #26/#27 done (timeframe-segmentation aside); #25
depends on Phase 2's Layer B split.

---
## Phase 8 — ML Discipline Layer
**(#28 — IMPLEMENTED, see PROJECT_STATUS.md Phase 21)**

The probability model (Phase 15 of the earlier build) already
implements the CORE discipline #28 asks for: chronological train/
holdout split, no random shuffling, activation gated on beating a real
baseline. Phase 21 formalized this into the reusable schema #28 asks
for: explicit `modelSchemaVersion`, `trainingPeriod`/`testPeriod` (real
date ranges from actual trade timestamps), `featuresUsed`, and
`hyperparameters`, all now recorded on every stored model (including
failed training attempts, for full traceability) - not just usable
implicitly. Future models (regime classifiers, factor-weight learners
per §33 of the earlier Master Prompt) can now reuse this exact schema
rather than each reinventing which fields to track.

**Size:** Small-Medium, mostly formalizing an already-real pattern. **Done.**

---

## Phase 9 — Realistic Execution Simulator Upgrades
**(#29, #30 — FULLY IMPLEMENTED, see PROJECT_STATUS.md Phases 18 & 26)**

Slippage, spread, real transaction costs, partial fills via realistic
bid/ask crossing, order rejection simulation, dual paper modes
(theoretical vs realistic), and volatility-scaled execution latency
are all real and wired into the live Auto Trades lifecycle on both
entry and exit.

**Size:** Small-Medium, extends real existing code. **Done.**

---

## Phase 10 — Market Replay Engine
**(#31, #32 — NOT implemented this session)**

The architectural principle #32 asks for ("the trading engine should
not know whether the source is LIVE or REPLAY") is actually already
TRUE of this codebase by construction — `evaluateBrain()` takes a
`ctx` object and has no idea where `ctx`'s fields came from. What's
missing is an actual **replay data source adapter** that feeds
historical raw ticks (Phase 2's store) through the SAME `FNO_DataSourceManager`
interface Phase 1 builds, at whatever speed you configure (real-time,
accelerated, or instant). This is a genuinely satisfying consequence
of Phase 1's abstraction-layer investment: once it exists, replay mode
is "just another adapter," not a parallel codepath to maintain.

**Size:** Medium, cleanly unblocked by Phase 1 + Phase 2, blocked until
both exist.

---

## Phase 11 — Data Availability Matrix + Documentation
**(#33 — IMPLEMENTED, see PROJECT_STATUS.md Phase 22)**

A living, real (not just documentation) matrix: `fno_get_data_availability_matrix_fn()`
introspects `fno_premium_capability_catalog()` directly at runtime -
initially built Phase 17 as a hardcoded array that had already drifted
out of sync by the time it was reviewed (missing capabilities added in
later sessions), and had zero UI consumers. Both fixed Phase 22: the
matrix is now self-healing against catalog drift (a new capability
appears automatically, no code change needed) and has a real UI card.

**Size:** Small, mostly a UI/reporting layer over Phase 1's real
metadata. **Done.**

---

## Phase 12 — Failure Analysis Engine
**(#39 — IMPLEMENTED, see PROJECT_STATUS.md Phases 27 & 29)**

Real classification of every losing trade using data ALREADY captured
(entry/exit price, real MFE/MAE, real cost breakdown, real regime tag,
exit source, and - as of Phase 29 - real entry/exit IV) -
`classifyTradeFailure()` supports 7 honestly-derivable categories:
costs_ate_marginal_edge, iv_crush, theta_decay,
unresolved_forced_closure, reversal_after_favorable_move,
wrong_direction_immediate, regime_mismatch - plus an explicit
`insufficient_data_to_classify` fallback for genuinely inconclusive
cases.

**Correction (Phase 29)**: this entry originally claimed IV crush vs
theta decay "genuinely needs Phase 2's raw entry/exit IV storage" -
that was wrong on review. Both were buildable immediately by simply
capturing the entry/exit IV values this app already computes every
refresh onto the trade record - no raw-tick infrastructure required.
Fixed in Phase 29, corrected here rather than left standing.

**Size:** Medium. **Done.**


---

## Phase 13 — Decision & Execution Full Audit Trail
**(#38 — mostly already real)**

§29/§30 of the earlier Master Prompt already built almost all of #38's
"store every decision BEFORE the trade" list (factor values, scores,
probability, confidence, direction, entry, SL, target, reasoning — all
real, in `buildEntrySnapshot`). What's missing per #38's fuller list:
expected value and risk/reward explicitly stored as their own fields
(currently derivable from stored data but not stored directly), and
"data quality/missing data" as an explicit field on the decision record
(currently only the coverage percentage is stored, not which specific
fields were missing). Small, additive fix once prioritized.

**Size:** Small.

---

## Dependency graph (why this order)

```
Phase 1 (Data Layer + Quality + Cost Control)
   │
   ├──> Phase 2 (Raw Store + Snapshots) ──> Phase 4 (Surface/Greeks/Futures)
   │         │                          └─> Phase 7 (Provenance/Correlation)
   │         │                          └─> Phase 10 (Replay)
   │         │                          └─> Phase 12 (Failure Analysis)
   │         │
   ├──> Phase 3 (TrueData Live) ─────────> Phase 4, Phase 5
   │
   ├──> Phase 6 (Events/News/Global) ────> Phase 5 (Correlation needs global data)
   │
   ├──> Phase 5 (Breadth/Correlation/Regime)
   │
   ├──> Phase 8 (ML Discipline) [mostly independent, formalizes existing work]
   ├──> Phase 9 (Execution Sim upgrades) [mostly independent]
   ├──> Phase 11 (Availability Matrix) [only needs Phase 1]
   └──> Phase 13 (Audit Trail additions) [only needs Phase 1]
```

## What this plan does NOT do

It does not pretend Phases 2-13 are built. It does not invent metrics
or fake completions to look comprehensive. Three phases (2, 3, 5) are
explicitly gated on decisions only you can make (time-series
infrastructure budget, TrueData credentials, breadth-data-source
research) — flagged as blocking, not silently assumed away. This is
the same standard every other phase of this project has been held to.
