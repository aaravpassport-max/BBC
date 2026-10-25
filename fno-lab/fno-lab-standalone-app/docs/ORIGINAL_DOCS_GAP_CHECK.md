# Real Gaps Against the Two Original Founding Documents

**Method note**: these two documents (the 63-section Master Development
Prompt, and the SEBI/indicator-failure analysis) are the ORIGINAL
requirements — separate from, and predating, the later 40-item
Enterprise Data Architecture Plan I've been tracking against. I have
NOT done a full line-by-line pass of every one of 63 sections here
(that's a genuinely enormous task at real depth) — I checked the
sections most likely to have drifted, verified each finding directly
against the actual code (`grep`, direct function inspection), and I'm
reporting exactly what I found, not everything I could theoretically
still check. Where I say "confirmed," I mean I read the actual code
just now, not that I remembered it correctly.

---

## Real gaps found this pass (previously unflagged)

### 1. Factor Registry is missing most of §5's required fields
§5 specifies every factor must carry: Data Source, Calculation Method,
Current Value, Normalized Score, Directional Bias, Confidence,
Last Updated, Historical Performance — in addition to ID/Name/
Category/Weight/Status.

**Confirmed by reading `buildFactorRegistry()` directly**: the real
registry entry is `{id, cat, factor, weight, status, resultRow}`. The
attached `resultRow` only has `{cat, factor, pass, score, reason}`.
Missing: an explicit `dataSource` label, `calculationMethod`/version
tag, `normalizedScore` distinct from raw score, `lastUpdated` timestamp
per factor, and `historicalPerformance` — even though
`computeFactorPerformance()` computes exactly this data separately, it
was never joined back onto the registry entry itself.

**Real, bounded fix**: extend the registry entry to include these
fields, most of which can be populated from data that already exists
elsewhere (join in `computeFactorPerformance()`'s output, add a
timestamp at build time, add a `dataSource` string per category). Not
done.

### 2. Decision output is 4-tier, not the 7-tier scale §23 specifies
§23 requires: `STRONG LONG / LONG / WEAK LONG / NO TRADE / WEAK SHORT /
SHORT / STRONG SHORT`.

**Confirmed by reading the actual decision logic**: it only produces
`BUY_READY`, `SELL_READY`, `WAIT`, `NO_TRADE` — a flat 4-state output
with no distinction between a marginal bullish score and an
overwhelming one. `totalScore` is already a continuous number, so this
is a real, bounded fix (bucket the same score into 7 tiers instead of
2), not a redesign — just never done.

### 3. §23's "Expected Reward"/"Expected Risk"/"Cost Estimate" not surfaced on the live decision
**Confirmed**: `expectedValueRsApprox` and `riskReward` exist on the
entry SNAPSHOT (added Phase 39), but are not displayed as part of the
live "why this decision" block the way §23's example shows. A real,
already-computed number that isn't shown where the spec wants it.

### 4. §32 Factor Interaction Analysis — genuinely not built
**Confirmed by direct search**: zero code analyzes factor
*combinations* (e.g. "VWAP bullish + OI support + Volume confirmation
together" as a distinct pattern from each factor scored independently).
§26 (factor correlation) and §27/regime-performance are real and built,
but they're not the same thing §32 asks for — this is specifically
about combination performance, which nothing in this codebase computes.

### 5. Virtual account (§59) is missing "available capital" and "margin"
**Confirmed**: `computeEquityCurve()` gives real balance, cumulative
P&L, and drawdown — but there's no distinct "available capital" (after
margin blocked for open positions) or "margin" tracking. Given this app
is single-position, margin tracking would currently just equal "is a
position open or not," which is a real but minor gap.

---

## Real gaps already known and tracked (not new, restated for completeness)

- **§10 Portfolio-level view / multi-leg**: single-position architecture
  throughout — explicitly, deliberately deferred (large, invasive,
  real regression risk against everything built).
- **§39 Walk-forward testing**: real, but narrower than a full
  historical price-replay backtest (uses the actual trade journal
  split chronologically, not a full market-replay engine) — disclosed
  limitation, not silently narrower.
- **§48 Knowledge Base**: real, but requires a human to click "Save" —
  intentional (human-in-the-loop), not a gap.
- **§45-47 Daily/Weekly/Monthly Review**: real P&L/win-rate/expectancy
  exist; "best/worst factor combination" and "new hypotheses" specifically
  as part of the *review output itself* are not integrated (Suggested
  Observations, built separately, covers similar ground but isn't
  wired into the review documents themselves).

## What's confirmed genuinely solid (spot-checked this pass, not just assumed)

§2 (operational vs metadata-only factor distinction) - real, this is
the Factor Registry's actual core job and it does it correctly. §28-29
(immutable logging, full 193-factor snapshot at entry) - real, verified
in earlier phases and re-confirmed structurally sound just now. §36-38
(no uncontrolled self-modification, human-gated strategy versioning,
overfitting/sample-size protection) - real and consistently applied
everywhere I've checked this session.

---

## Honest correction: the second document (SEBI/indicator-failure analysis) was NOT checked in the pass above

You asked directly whether I'd checked both attached documents. The
honest answer to that question was no - the first pass checked several
sections of the Master Development Prompt against real code; the
trader-failure/indicator-failure document was only referenced for
context, never actually verified against the codebase. That's a real
gap in my own process, not just in the app, and worth naming plainly
rather than letting the first report imply more coverage than it had.
Checked it properly now - three more real, concrete findings.

### 6. The document's own explicit final recommendation doesn't exist: a pre-trade Failure-Mode Library
The document's closing recommendation is specific and concrete:
*"create a 'Failure-Mode Library' containing perhaps 150–250 specific
failure conditions, and make the system actively check for them
BEFORE every simulated trade."*

**Confirmed by direct search**: nothing in this codebase does this.
What exists (`classifyTradeFailure()`) is real, but it's the opposite
shape - it classifies a failure AFTER a trade has already closed and
lost money, for learning purposes. That's genuinely valuable and stays
valuable, but it is not what this specific recommendation asks for: a
pre-trade gate checking a real library of known failure patterns
(regime mismatch, thin liquidity, event-day risk stacking, IV already
elevated pre-entry, etc.) BEFORE opening the position, potentially
blocking it the way the Regulatory hard-block layer already does for
compliance reasons. This is real, substantial, unbuilt work - closer
in spirit to extending the existing pre-trade gates (cost-aware entry
gate, order-rejection check) than to anything already built for
post-trade learning.

### 7. The "4 Audits" framework doesn't exist as a named, structured deliverable
The document proposes a specific structure: **Trader Failure Audit,
Indicator Failure Audit, Market Failure Audit, System Failure Audit**
- explicitly calling the fourth one "particularly important" since a
system can have 193 individually reasonable factors and still lose to
correlation, regime change, execution, weighting, or miscalibration
interacting badly together.

**Confirmed**: real pieces exist that map loosely onto each of the
four (Failure Analysis Engine → trader/system audit territory; Factor
Correlation Engine → partial indicator audit; Regime-Dependent
Performance → market audit) - but they were never built AS these four
named, structured reports, and the redundancy "funnel" the document
specifically describes (**"193 factors → 40 redundant → 30 correlated
→ 25 regime-dependent → 20 unstable → 15 useful → 8 independent → 5
that actually improve the decision"**) doesn't exist as a single
coherent output anywhere - only the pairwise correlation piece of it
does.

### 8. A specific factor's own logic contradicts this document's explicit warning about that exact indicator
Section 8 of the document is explicit: *"RSI = 75. That does NOT
necessarily mean SELL. It may mean Strong momentum... [RSI] can remain
extreme during strong trends."*

**Confirmed by reading the actual RSI factor code**: `RSI Level`
unconditionally scores any reading above 70 or below 30 as negative
(`score: -1`), with no trend-context check at all - exactly the naive
interpretation this document says is wrong, contradicting its own
stated caveat about this specific indicator. This is a real, precise,
fixable bug in the factor's own logic (a real fix would check trend
direction - e.g. EMA9 vs EMA21 - before penalizing an extreme RSI
reading, distinguishing "overbought in a range" from "strong momentum
in a trend," which is exactly what the document asks for).

---

## Second full pass — every remaining checkable item, verified against code

You asked for every line checked, so here's the continuation: more
sections checked directly against real code, including the two most
important findings in this entire audit.

### 9. CONFIRMED CORRECT (not a gap — the most safety-critical requirement in the whole spec, verified true)
§60: *"ZERO REAL-MONEY EXECUTION... if broker integration is
architecturally prepared for future use, it must remain disabled."*

**Traced the real order-placement code path directly**: `fno_kite_order_fn()`
checks a real per-user "Live Trading" toggle, but even when that
toggle is on and valid Kite credentials exist, the function contains
`$is_demo = true;` - a hardcoded PHP constant, not derived from any
setting - with the real `wp_remote_post` call to Kite's live order API
sitting immediately after it, genuinely unreachable unless someone
manually edits the source code to flip that one constant. This is
**stricter** than the spec's own minimum bar: a UI toggle alone could
theoretically be flipped by a bug; a hardcoded source constant cannot.
Confirmed true, not assumed.

### 10. Real structural contradiction of §20's explicit classification requirement
§20 is explicit: *"Some of these are not market-direction signals.
Therefore do NOT force every factor into a bullish/bearish score.
Instead classify factors into: Directional / Trade-Quality / Risk /
Execution / Model-Quality / Human-Operator Factors."*

**Confirmed by reading the actual scoring code**: `Personal`, `Risk`,
and non-hard-blocking `Regulatory` factors all add directly into the
exact same `totalScore` variable that `Market`/`Flow`/`Tech`
directional factors add into (e.g. a Personal factor like "Mindset
Calm?" does `totalScore += 0.5`, identical mechanism to a directional
Market factor). This is a real, significant, previously unflagged
architectural gap - the spec explicitly says these should NOT be
merged into one directional score, and they currently are. **Partial
mitigation, stated honestly**: Regulatory factors DO have a separate
hard-block mechanism (`critFails`) that can force `NO_TRADE`
regardless of score, so Regulatory isn't purely conflated - but
Personal/Risk/Costs/Psychology have no equivalent separate track and
are fully merged into the directional total.

### 11. §7 Data Quality (High/Medium/Low/Unavailable) is not a per-factor field
**Confirmed**: no factor result row carries an explicit `dataQuality`
rating distinct from its COMPUTED/UNAVAILABLE status. The real Data
Quality Engine built later (`fno_dq_check`) exists but is only wired
to two fields (VIX, spot price), not threaded through to every
factor's own quality rating the way §7 asks. Consistent with, and
reinforces, an earlier finding about the Data Quality Engine's limited
integration scope.

### 12. §56's exact 5-stage Factor Activation Roadmap doesn't exist
§56 asks for factors to move through: `Catalogue → Data Source Defined
→ Calculation Implemented → Validated → Active`.

**Confirmed by direct search**: zero matches for this pipeline
anywhere. What exists is the coarser 4-state Factor Registry status
(COMPUTED/NOT_APPLICABLE/UNAVAILABLE/NOT_COMPUTED), which captures
"is it working right now" but not the specific multi-stage development
pipeline the spec describes (e.g. distinguishing "data source chosen
but calculation not written yet" from "calculation written but never
validated against a known-correct reference").

### 13. §33 Factor Weight Learning (proposed candidate weights, tested before activation) - not built
**Confirmed by direct search**: no code proposes a candidate weight
change for a specific factor based on its own measured performance
(e.g. "Volume Confirmation weight 2 → 2.5, based on X trades of
positive predictive value, pending validation"). `computeFactorPerformance()`
measures real accuracy per factor, and Strategy Versioning (§34) is
real and human-gated - but nothing connects factor-level performance
data into an actual proposed weight-change candidate the way §33's
example shows. The Suggested Observations engine (built later, covers
similar ground for redundant pairs and regime-dependent factors) does
not currently generate a weight-change suggestion specifically.

---

## Third pass — remaining sections checked directly against code

### 14. §44's own comment contradicts its own formula — Brokerage is claimed but not computed
`computeTradeCosts()`'s docstring explicitly says it reuses "the EXACT
same... 'brokerage, charges' on every simulated fill" - **but the
actual formula body has zero brokerage term**. STT, exchange charges,
GST, and stamp duty are all real and present; brokerage is not, despite
the function's own comment claiming otherwise.

**Found the fuller picture, not just the gap**: a SEPARATE, differently-
scoped function (the Costs category's informational "Brokerage Per
Trade" factor) does real, honest work here - it explicitly assumes
₹0 brokerage (documented: "most discount brokers quote zero F&O
brokerage, reviewed this session," explicitly labeled "NOT a live
rate-card feed, verify against your actual broker"). That assumption
is reasonable and disclosed. **The real problem is these two paths
don't share the same source** - if a user's actual broker charges a
flat per-order fee (many do, even on F&O), the REAL net P&L calculation
used for every closed trade in the journal would never reflect it,
while the informational display elsewhere correctly flags the
assumption. This breaks the "one shared source of truth for costs"
discipline this project has otherwise maintained consistently
elsewhere - found here as a real exception to it, not the norm.

### 15. §41 Trade Rejection Learning is missing 4 of 7 required fields
§41 requires storing: Opportunity, Factors, **Probability**, **Reason
rejected**, **Risk state**, **Cost state**, Later outcome.

**Confirmed by reading `fno_log_rejection_fn()` directly**: it stores
`decision`, `factor_snapshot` (real, full), and later a real outcome
via a separate evaluation endpoint - genuinely 3 of 7. Missing: no
explicit `probability` field (the trained model's prediction at
rejection time, when active, is never captured); no `reason` field
(evaluateBrain already computes a real human-readable reason string
for every decision - it's simply never passed to this endpoint); no
`risk_state` (was a Regulatory/Risk hard-block active at rejection
time?); no `cost_state` (would this hypothetical trade have cleared
the real cost-aware entry gate, §44?). All four are real, bounded
fixes - the underlying data already exists elsewhere in the app for
three of the four, it's simply never threaded through to this specific
endpoint's payload.

### 16. §49 Factor Reliability Score is missing 3 of 7 required fields
§49 requires: Sample, Directional Accuracy, **Profit Impact**, **False
Signal Rate**, **Current Weight**, **Suggested Weight**, **Reliability**
(a tiered label).

**Confirmed by reading `computeFactorPerformance()` directly**: it
computes real `tradesInfluenced` (Sample) and `accuracyPct` (Directional
Accuracy) - genuinely 2 of 7. Missing: Profit Impact (average P&L when
this factor was active, a distinct metric from directional agreement -
not computed at all); False Signal Rate (trivially derivable as
`100 - accuracyPct` but never exposed as its own field); Current
Weight (the catalog has this, it's simply never joined into this
function's output); Suggested Weight (same root cause as the earlier
§33 finding - no weight-change proposal mechanism exists); Reliability
as a categorical tier (only a boolean `sampleSizeWarning` exists, not
a real High/Medium/Low label the way §49's own example shows).

---

## Fully updated summary of every real finding, both documents, three passes

**Document 1 - confirmed real gaps** (16 total now): §5/§6 (Registry
missing most fields), §7 (no per-factor data quality), §20 (Personal/
Risk/Psychology wrongly merged into directional score - still the most
significant single finding), §23 (4-tier not 7-tier decision, expected
reward/risk/cost computed but not displayed), §32 (no combination
analysis), §33 (no weight-change proposals), §41 (rejection logging
missing probability/reason/risk-state/cost-state), §44 (brokerage
claimed in a comment but absent from the real cost formula, and two
cost paths don't share a source), §49 (reliability score missing
profit-impact/false-signal-rate/current-weight/suggested-weight/
reliability-tier), §56 (no 5-stage pipeline), §59 (no available-
capital/margin).

**Document 1 - confirmed CORRECT** (verified true): §60 (zero real-
money execution, hardcoded unreachable, stricter than the spec's own
bar), §2 (operational/metadata distinction), §28-29 (immutable
logging, full snapshot), §36-38 (no uncontrolled self-modification,
human-gated versioning, sample-size protection), §51/§53/§54/§55/§57
(Decision Replay, Market Snapshot, Data Availability Score, coverage-
aware confidence, honest UNAVAILABLE reporting) all real and correctly
built.

**Document 2 - confirmed real gaps**: the pre-trade Failure-Mode
Library (this document's own explicit closing recommendation) doesn't
exist; the "4 Audits" framework and redundancy funnel don't exist as
named structured outputs; the RSI factor's own logic contradicts this
document's explicit warning about that exact indicator.

**Still not individually checked against code**: §40's full regime
taxonomy against every listed dimension (gap days/event days/high-low
volume/strong-weak breadth specifically, beyond what's confirmed for
trend/vol/expiry/Panic-Recovery), §45-48's exact field-by-field
completeness (real Daily/Weekly/Monthly Review and Knowledge Base
exist, but I have not verified every single listed sub-item like
"best/worst factor combination" is genuinely present line-by-line),
§50's exact output format match, §58's full pipeline as one traceable
path versus assembled from separate real pieces, and §61-63 (philosophy
sections, not individually checkable as discrete code features beyond
the 25 rules in §62, most of which map onto findings already
confirmed above).
