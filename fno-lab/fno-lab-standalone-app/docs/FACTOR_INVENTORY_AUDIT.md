# Factor Inventory Audit (Phase 1)

_Generated 2026-08-30 as part of the multi-phase decision-engine audit._

## Method (real evidence, not read-only inspection)

This inventory was built by **actually executing** `evaluateBrain()` from `assets/fno-lab-core.js`, using the same
eval-slice + fixture pattern `tests/end-to-end-decision-engine-audit.test.js` already uses, with a fully-populated,
realistic `ctx` (candles, decay snapshot, option chain rows, journal, daily checklist, microstructure daemon snapshot,
futures data, operator-intel signal) so every guarded `compute*Factors()` block actually runs instead of being skipped.
The real `brain.results` array (the live per-refresh factor list) was captured and is the source of every row below -
not a transcription of source code by inspection alone.

One run of a realistic scenario produced **188 individual factor rows** (some factors are conditionally emitted only
when their guard is satisfied - e.g. Decay/Greeks-Deep need `ctx.decay.snapshot`, Tech/Vol need `ctx.candles`, all of
which were populated in this run). This is the full, live enumeration for that refresh; the ~193 figure quoted in the
task description is the `factors.json` catalogue count (includes a handful of permanently-documented-gap rows that are
always `pass:null,score:0` by design - see `computeDocumentedGapFactors()`).

## The 18 compute*Factors() functions (confirmed via grep)

```
computeDecayFactors, computeMarketFactors, computeFlowFactors, computeDocumentedGapFactors,
computeRegulatoryRealFactors, computeTradingHaltFactor, computeFundamentalRealFactors, computeASMGSMFactor,
computeMicrostructureDaemonFactors, computeFuturesFactors, computeOIRolloverFactor, computeEnhancementFactors,
computePsychologyFactors, computeTechFactors, computeVolFactors, computeCostsFactors, computeRiskFactors,
computeGreeksDeepFactors
```

(`grep -n "^function compute.*Factors" assets/fno-lab-core.js` also matches `computeRegimeDependentFactors` at
line 3379 - excluded from this list, and correctly so: it is a journal-analytics helper called from the Learning
panel, not part of `evaluateBrain()`'s scoring path at all, so it is out of scope for this per-decision audit.)

## How the 14 live categories map into the 5 aggregate scores

`evaluateBrain()` pushes every factor into one flat `results` array tagged with a `cat`. `computeSeparatedScores(results)`
(fno-lab-core.js:9932) then sums `results` into five independent totals by category set - **not** by function:

| Aggregate score | Categories summed into it | Actually drives BUY/SELL decision? |
|---|---|---|
| `directionalScore` | Market, Flow, Tech, Vol, Decay, Fundamental, Greeks Deep, Operator Intel | **YES** - the sole input to `BUY_THRESHOLD`/`SELL_THRESHOLD` comparison (fno-lab-core.js:10648-10650) and `computeDecisionTier()` |
| `riskScore` | Risk, Regulatory | No (Risk/Regulatory critical-fail rows still gate via `critFails`, checked separately, before threshold comparison) |
| `tradeQualityScore` | Costs, Microstructure | No - informational only |
| `modelQualityScore` | Psychology | No - informational only |
| `humanOperatorScore` | Personal | No - informational only |

All five scores are genuinely returned by `evaluateBrain()` (fno-lab-core.js:10742) and all five are genuinely
displayed - together, in the `#brainLog` debug text line (fno-lab-core.js:12091) - so none of them are silently
discarded. Confirmed by direct grep: no `tradeQualityScore`/`modelQualityScore`/`humanOperatorScore`/`riskScore`
read site exists anywhere else in the codebase (`assets/standalone-app.php` does not reference any of the four by name),
so their only real-world effect on a user today is that one debug log line - by design, per the code's own §20 comment
("shown for real transparency but never move the threshold"), not a bug.

## Finding #1 (checked, no bug): no orphaned categories

Every one of the 14 live `cat` values produced by the 18 functions above is a member of exactly one of the five
`Set`s in `computeSeparatedScores()` (fno-lab-core.js:9932-9937). Verified programmatically against the real,
captured 188-row output - zero categories fell outside all five sets. **No factor is computed and then structurally
unreachable from every aggregate score.**

## Finding #2 (checked, no bug, but disclose clearly): 7 Microstructure daemon factors are structurally always score:0

`computeMicrostructureDaemonFactors()` (fno-lab-core.js:7523-7565) returns 7 factors (Iceberg Orders, Cumulative Delta,
Volume Profile POC, Order Flow Imbalance, Footprint Chart, Tick Data Speed, DOM Ladder). Every live-daemon branch
returns `score:0` unconditionally (fno-lab-core.js:7555) - so even when the companion Node daemon is running and
posting real tick data, these 7 factors **never move `tradeQualityScore` or `directionalScore` at all** - they are
structurally phantom by weight, matching the audit's "phantom factor" definition (weight always evaluates to zero).
This is NOT a silent bug: the code's own comment (fno-lab-core.js:7538-7554) states this was a deliberate downgrade
from a flat non-zero `score:0.2` after finding that constant ignored each metric's real value, and every one of the
7 reasons strings explicitly says "Currently genuinely informational (score:0)". The `#factorsList` UI badge for these
rows is `pass:true` (green "PASS" badge) with `score:0` - **visually indistinguishable from a real, contributing PASS**
unless the user reads the reason text. This is the closest live analogue to the prior session's "score-tile" bug class,
but is judged NOT a new bug to silently fix in Phase 1, because: (a) the reason text is honest and specific, (b) a UI
redesign that visually distinguishes "PASS, scored" from "PASS, informational-only" would touch the shared badge
renderer used by all 188 rows (risk of a bigger, untested UI change this phase should not take on), and (c) the same
pattern already exists, disclosed the same way, for several other `score:0` rows across Costs/Flow/Regulatory (17 total
`pass:true,score:0` rows in the captured run - see full table). Flagged here explicitly for a future phase to consider
a dedicated "informational" badge style; not fixed in this pass to avoid scope creep into UI-wide badge semantics.

## Finding #3 (bug found and fixed): `#filterF` category-filter dropdown omitted 8 of 14 live categories

**File:** `assets/standalone-app.php:386`

The factors-list category filter `<select id="filterF">` only had `<option>` entries for 6 of the 14 real `cat` values
`evaluateBrain()` actually produces: Regulatory, Decay, Greeks Deep, Microstructure, Fundamental, Psychology. Missing:
**Market, Flow, Tech, Vol, Costs, Risk, Personal, Operator Intel** - together over half of the live factor rows in the
captured run (21+19+20+10+15+16+20+1 = 122 of 188 rows, 65%). Because the default "All" option (`value=""`) still shows
every row, this did not hide any factor from the list by default - but it meant a user could never filter the list down
to just, say, "Risk" or "Personal" factors, silently making 8 of 14 real, live, scored categories unreachable through the
one filtering control the UI provides for a 188-row list. This is a genuine user-facing gap in the same honesty-first
spirit as the task brief's "score-tile" precedent (a control that implies coverage it does not have), even though it is
not itself a false-positive score claim.

**Fix:** added the 8 missing `<option>` entries. Diff (both are the literal before/after of the one `<select>` line):

```diff
- <select id="filterF" class="input"><option value="">All</option><option>Regulatory</option><option>Decay</option><option>Greeks Deep</option><option>Microstructure</option><option>Fundamental</option><option>Psychology</option></select>
+ <select id="filterF" class="input"><option value="">All</option><option>Market</option><option>Flow</option><option>Tech</option><option>Vol</option><option>Costs</option><option>Risk</option><option>Personal</option><option>Operator Intel</option><option>Regulatory</option><option>Decay</option><option>Greeks Deep</option><option>Microstructure</option><option>Fundamental</option><option>Psychology</option></select>
```

**Regression test:** `tests/factor-filter-dropdown-coverage-audit.test.js` - calls the real `evaluateBrain()` with a
fully-populated ctx, real-reads the actual `<select id="filterF">` markup from `assets/standalone-app.php`, and asserts
every live `cat` value has a matching `<option>`. Fails against the pre-fix markup (would report the 8 categories above
as missing); passes against the fixed markup. 3/3 assertions pass.

## Finding #4 (checked, no bug): no `weight:0`/`enabled:false`-but-computed literal patterns found

Grepped all 18 functions for `weight:` and `enabled:` object keys - neither pattern is used by any of the 18
`compute*Factors()` functions (weighting in this codebase happens later, per-category, in
`computeTradeTypeDirectionalWeightedScore()`, not per-factor at computation time). No factor carries a structural
always-zero multiplier other than the 7 Microstructure daemon rows already covered in Finding #2.

## Full itemized table (188 rows from one real, captured evaluateBrain() run)

Columns: **#** row index in the captured run; **Category**; **Source function(s)** (best-effort mapping - several
categories are populated by more than one function, since `evaluateBrain()` calls several small, category-scoped
functions per category, e.g. Regulatory = `computeRegulatoryRealFactors` + `computeTradingHaltFactor` +
`computeDocumentedGapFactors`); **Factor**; **Wired?** (does this category feed one of the 5 real aggregate scores -
all Yes, see Finding #1); **Score bucket** (which of the 5 aggregate scores actually receives this row's `score`);
**Live pass/score this run** (`pass` / `score` as actually returned - `null`/`0` means genuinely UNAVAILABLE this
refresh, not a computed neutral, per this codebase's own established convention); **Displayed in UI?** (Yes for every
row - `#factorsList` renders 100% of `brain.results` with no per-row filtering logic beyond the user's own search/filter
box, confirmed at fno-lab-core.js:12048-12067).

| # | Category | Source function(s) | Factor | Wired | Score bucket | Live pass/score | Displayed |
|---|---|---|---|---|---|---|---|
| 1 | Market | computeMarketFactors | NIFTY Trend vs 21 EMA | Yes | directionalScore | true / 1 | Yes |
| 2 | Market | computeMarketFactors | India VIX Level | Yes | directionalScore | true / 1 | Yes |
| 3 | Flow | computeFlowFactors | PCR Level | Yes | directionalScore | true / 1 | Yes |
| 4 | Tech | computeTechFactors | Price vs VWAP | Yes | directionalScore | true / 1 | Yes |
| 5 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | F&O Ban List - Stock in Ban? | Yes | riskScore | true / 1 | Yes |
| 6 | Risk | computeRiskFactors | Max Loss Per Day | Yes | riskScore | true / 1 | Yes |
| 7 | Personal | evaluateBrain() inline (personalSpecs) | Internet Speed Stability | Yes | humanOperatorScore | true / 0.5 | Yes |
| 8 | Personal | evaluateBrain() inline (personalSpecs) | Broker Platform Working? | Yes | humanOperatorScore | true / 0.5 | Yes |
| 9 | Personal | evaluateBrain() inline (personalSpecs) | Laptop/Phone Charged | Yes | humanOperatorScore | true / 0.5 | Yes |
| 10 | Personal | evaluateBrain() inline (personalSpecs) | Mobile vs Laptop | Yes | humanOperatorScore | null / 0 | Yes |
| 11 | Personal | evaluateBrain() inline (personalSpecs) | Mindset Angry/Tired/Greedy? | Yes | humanOperatorScore | true / 0.5 | Yes |
| 12 | Personal | evaluateBrain() inline (personalSpecs) | Sleep Last Night | Yes | humanOperatorScore | true / 0.5 | Yes |
| 13 | Personal | evaluateBrain() inline (personalSpecs) | Physical Health Today | Yes | humanOperatorScore | true / 0.5 | Yes |
| 14 | Personal | evaluateBrain() inline (personalSpecs) | Emotional After Win/Loss | Yes | humanOperatorScore | true / 0.5 | Yes |
| 15 | Personal | evaluateBrain() inline (personalSpecs) | Time Free Next 2 Hours | Yes | humanOperatorScore | true / 0.5 | Yes |
| 16 | Personal | evaluateBrain() inline (personalSpecs) | Distractions Family/TV/Noise | Yes | humanOperatorScore | true / 0.5 | Yes |
| 17 | Personal | evaluateBrain() inline (personalSpecs) | Breaking News Checked? | Yes | humanOperatorScore | true / 0.5 | Yes |
| 18 | Personal | evaluateBrain() inline (personalSpecs) | Economic Calendar Today | Yes | humanOperatorScore | true / 0.5 | Yes |
| 19 | Personal | evaluateBrain() inline (personalSpecs) | Journal Updated? | Yes | humanOperatorScore | true / 0.5 | Yes |
| 20 | Personal | evaluateBrain() inline (personalSpecs) | Last Trade Reason Written? | Yes | humanOperatorScore | true / 0.5 | Yes |
| 21 | Personal | evaluateBrain() inline (personalSpecs) | Plan Written Before Open | Yes | humanOperatorScore | true / 0.5 | Yes |
| 22 | Personal | evaluateBrain() inline (personalSpecs) | Notifications Off | Yes | humanOperatorScore | true / 0.5 | Yes |
| 23 | Personal | evaluateBrain() inline (personalSpecs) | Broker Phone Number Saved | Yes | humanOperatorScore | true / 0.5 | Yes |
| 24 | Personal | evaluateBrain() inline (personalSpecs) | Backup Device Ready | Yes | humanOperatorScore | true / 0.5 | Yes |
| 25 | Personal | evaluateBrain() inline (personalSpecs) | Family Knows Not to Disturb | Yes | humanOperatorScore | true / 0.5 | Yes |
| 26 | Personal | evaluateBrain() inline (personalSpecs) | Water Food Nearby | Yes | humanOperatorScore | true / 0.5 | Yes |
| 27 | Operator Intel | evaluateBrain() inline (opIntel.signals passthrough) | Real Smart-Money OI Buildup | Yes | directionalScore | true / 1 | Yes |
| 28 | Decay | computeDecayFactors | Theta Decay Per Day Rs | Yes | directionalScore | true / 0.5 | Yes |
| 29 | Decay | computeDecayFactors | Theta Decay Per Hour | Yes | directionalScore | true / 0.5 | Yes |
| 30 | Decay | computeDecayFactors | Time Value % of Premium | Yes | directionalScore | false / -1 | Yes |
| 31 | Decay | computeDecayFactors | Intrinsic vs Time Value | Yes | directionalScore | false / -1 | Yes |
| 32 | Decay | computeDecayFactors | Value Decay Per Day % | Yes | directionalScore | true / 0.5 | Yes |
| 33 | Decay | computeDecayFactors | Days to Expiry | Yes | directionalScore | true / 1 | Yes |
| 34 | Decay | computeDecayFactors | Expiry Week Decay Multiplier | Yes | directionalScore | true / 0.5 | Yes |
| 35 | Decay | computeDecayFactors | Lot Decay Rs Per Day | Yes | directionalScore | true / 0.5 | Yes |
| 36 | Decay | computeDecayFactors | Charm Delta Decay | Yes | directionalScore | true / 0.5 | Yes |
| 37 | Decay | computeDecayFactors | Theta Curve Accelerating | Yes | directionalScore | true / 0.5 | Yes |
| 38 | Decay | computeDecayFactors | Time Decay After 1 PM Expiry | Yes | directionalScore | true / 0.5 | Yes |
| 39 | Decay | computeDecayFactors | IV Crush After Event | Yes | directionalScore | null / 0 | Yes |
| 40 | Decay | computeDecayFactors | Weekend Time Decay | Yes | directionalScore | true / 0.5 | Yes |
| 41 | Decay | computeDecayFactors | Value Decay vs Target | Yes | directionalScore | null / 0 | Yes |
| 42 | Decay | computeDecayFactors | OTM Lottery Decay 99% to 0 | Yes | directionalScore | true / -0.5 | Yes |
| 43 | Greeks Deep | computeGreeksDeepFactors | Gamma - Delta Change Speed | Yes | directionalScore | true / 0.5 | Yes |
| 44 | Greeks Deep | computeGreeksDeepFactors | Vega - IV Sensitivity | Yes | directionalScore | true / 0.5 | Yes |
| 45 | Greeks Deep | computeGreeksDeepFactors | Vanna - Delta Change with IV | Yes | directionalScore | true / 0.5 | Yes |
| 46 | Greeks Deep | computeGreeksDeepFactors | Vomma - Vega Convexity | Yes | directionalScore | true / 0.5 | Yes |
| 47 | Greeks Deep | computeGreeksDeepFactors | Rho - Interest Rate Sensitivity | Yes | directionalScore | true / 0.2 | Yes |
| 48 | Greeks Deep | computeGreeksDeepFactors | Put-Call Parity Arbitrage Broken? | Yes | directionalScore | false / 1.5 | Yes |
| 49 | Greeks Deep | computeGreeksDeepFactors | Skew - OTM Put IV vs Call IV | Yes | directionalScore | null / 0 | Yes |
| 50 | Greeks Deep | computeGreeksDeepFactors | Term Structure Weekly vs Monthly IV | Yes | directionalScore | null / 0 | Yes |
| 51 | Greeks Deep | computeGreeksDeepFactors | Gamma Squeeze - Market Maker Hedging | Yes | directionalScore | true / 0.3 | Yes |
| 52 | Greeks Deep | computeGreeksDeepFactors | Delta Hedging Cost | Yes | directionalScore | true / 0.3 | Yes |
| 53 | Tech | computeTechFactors | EMA 9 vs 21 Crossover | Yes | directionalScore | true / 1 | Yes |
| 54 | Tech | computeTechFactors | EMA 21 vs 50 | Yes | directionalScore | true / 1 | Yes |
| 55 | Tech | computeTechFactors | RSI Level | Yes | directionalScore | true / 0.5 | Yes |
| 56 | Tech | computeTechFactors | RSI Divergence | Yes | directionalScore | false / -1 | Yes |
| 57 | Tech | computeTechFactors | MACD Crossover | Yes | directionalScore | false / -0.5 | Yes |
| 58 | Tech | computeTechFactors | Bollinger Squeeze | Yes | directionalScore | false / -0.5 | Yes |
| 59 | Tech | computeTechFactors | Bollinger Position | Yes | directionalScore | true / 0.3 | Yes |
| 60 | Tech | computeTechFactors | HH/HL Structure | Yes | directionalScore | false / -0.3 | Yes |
| 61 | Tech | computeTechFactors | Chart Pattern Flag/Triangle | Yes | directionalScore | null / 0 | Yes |
| 62 | Tech | computeTechFactors | Fibonacci Level | Yes | directionalScore | true / 0 | Yes |
| 63 | Tech | computeTechFactors | Opening Range 15m High/Low | Yes | directionalScore | null / 0 | Yes |
| 64 | Tech | computeTechFactors | OR Range Size | Yes | directionalScore | null / 0 | Yes |
| 65 | Tech | computeTechFactors | Support Previous Day Low | Yes | directionalScore | null / 0 | Yes |
| 66 | Tech | computeTechFactors | Resistance Previous Day High | Yes | directionalScore | null / 0 | Yes |
| 67 | Tech | computeTechFactors | ATR Stop Distance | Yes | directionalScore | null / 0 | Yes |
| 68 | Tech | computeTechFactors | Rejection Wick | Yes | directionalScore | null / 0 | Yes |
| 69 | Tech | computeTechFactors | Volume Confirmation | Yes | directionalScore | null / 0 | Yes |
| 70 | Tech | computeTechFactors | Supertrend Direction | Yes | directionalScore | null / 0 | Yes |
| 71 | Tech | computeTechFactors | Weekly High/Low | Yes | directionalScore | null / 0 | Yes |
| 72 | Vol | computeVolFactors | India VIX Trend Up/Down | Yes | directionalScore | null / 0 | Yes |
| 73 | Vol | computeVolFactors | ATM IV | Yes | directionalScore | true / 0.5 | Yes |
| 74 | Vol | computeVolFactors | IV Rank/Percentile | Yes | directionalScore | null / 0 | Yes |
| 75 | Vol | computeVolFactors | Straddle vs Yesterday | Yes | directionalScore | null / 0 | Yes |
| 76 | Vol | computeVolFactors | Historical vs IV | Yes | directionalScore | false / -0.5 | Yes |
| 77 | Vol | computeVolFactors | Vol Skew OTM Put vs Call | Yes | directionalScore | true / 0.3 | Yes |
| 78 | Vol | computeVolFactors | Intraday Vol Range/ATR | Yes | directionalScore | true / 0.3 | Yes |
| 79 | Vol | computeVolFactors | Gap Size | Yes | directionalScore | true / 0.3 | Yes |
| 80 | Vol | computeVolFactors | News Volatility | Yes | directionalScore | null / 0 | Yes |
| 81 | Vol | computeVolFactors | Expiry Day Volatility | Yes | directionalScore | true / 0.3 | Yes |
| 82 | Costs | computeCostsFactors | Brokerage Per Trade | Yes | tradeQualityScore | true / 0 | Yes |
| 83 | Costs | computeCostsFactors | STT Tax | Yes | tradeQualityScore | true / 0 | Yes |
| 84 | Costs | computeCostsFactors | Exchange+GST | Yes | tradeQualityScore | true / 0 | Yes |
| 85 | Costs | computeCostsFactors | Stamp Duty | Yes | tradeQualityScore | true / 0 | Yes |
| 86 | Costs | computeCostsFactors | Total Charges % of Target | Yes | tradeQualityScore | null / 0 | Yes |
| 87 | Costs | computeCostsFactors | Slippage Expected vs Actual | Yes | tradeQualityScore | null / 0 | Yes |
| 88 | Costs | computeCostsFactors | Bid-Ask Spread Cost | Yes | tradeQualityScore | null / 0 | Yes |
| 89 | Costs | computeCostsFactors | Lot Size & Capital | Yes | tradeQualityScore | true / 0 | Yes |
| 90 | Costs | computeCostsFactors | Margin Blocked | Yes | tradeQualityScore | null / 0 | Yes |
| 91 | Costs | computeCostsFactors | Liquidity Volume >1000 | Yes | tradeQualityScore | false / -1 | Yes |
| 92 | Costs | computeCostsFactors | Impact Cost Large Qty | Yes | tradeQualityScore | null / 0 | Yes |
| 93 | Costs | computeCostsFactors | STT on ITM Expiry | Yes | tradeQualityScore | true / 0.3 | Yes |
| 94 | Costs | computeCostsFactors | Auto Square Off Time | Yes | tradeQualityScore | true / 0 | Yes |
| 95 | Costs | computeCostsFactors | MTM Loss Futures | Yes | tradeQualityScore | null / 0 | Yes |
| 96 | Costs | computeCostsFactors | MTF Interest Leverage | Yes | tradeQualityScore | null / 0 | Yes |
| 97 | Risk | computeRiskFactors | Max Loss Per Day 2% Rule | Yes | riskScore | true / 0.5 | Yes |
| 98 | Risk | computeRiskFactors | Max Loss Per Trade 1% Rule | Yes | riskScore | true / 0.5 | Yes |
| 99 | Risk | computeRiskFactors | Position Size Calculation | Yes | riskScore | null / 0 | Yes |
| 100 | Risk | computeRiskFactors | Stop Loss Before Entry | Yes | riskScore | false / -1.5 | Yes |
| 101 | Risk | computeRiskFactors | Target & Risk:Reward >=1:2 | Yes | riskScore | null / 0 | Yes |
| 102 | Risk | computeRiskFactors | Consecutive Losses Today | Yes | riskScore | true / 0.3 | Yes |
| 103 | Risk | computeRiskFactors | Consecutive Wins Overconfidence | Yes | riskScore | true / 0.3 | Yes |
| 104 | Risk | computeRiskFactors | Total Open Positions | Yes | riskScore | null / 0 | Yes |
| 105 | Risk | computeRiskFactors | Overtrading Count >5 | Yes | riskScore | true / 0.3 | Yes |
| 106 | Risk | computeRiskFactors | Capital Left After Trade | Yes | riskScore | true / 0 | Yes |
| 107 | Risk | computeRiskFactors | Correlation Same Direction | Yes | riskScore | null / 0 | Yes |
| 108 | Risk | computeRiskFactors | Hedging Is Position Hedged? | Yes | riskScore | null / 0 | Yes |
| 109 | Risk | computeRiskFactors | Expiry Day Lottery? | Yes | riskScore | true / 0.3 | Yes |
| 110 | Risk | computeRiskFactors | SL Market or Limit? | Yes | riskScore | null / 0 | Yes |
| 111 | Risk | computeRiskFactors | Emergency Exit Plan | Yes | riskScore | true / 0.5 | Yes |
| 112 | Market | computeMarketFactors | NIFTY vs 50 EMA Daily | Yes | directionalScore | true / 0.5 | Yes |
| 113 | Market | computeMarketFactors | VIX Change 15m | Yes | directionalScore | null / 0 | Yes |
| 114 | Market | computeMarketFactors | SGX/Gift Nifty Trend | Yes | directionalScore | null / 0 | Yes |
| 115 | Market | computeMarketFactors | US Futures Dow/Nasdaq | Yes | directionalScore | null / 0 | Yes |
| 116 | Market | computeMarketFactors | Asian Markets | Yes | directionalScore | null / 0 | Yes |
| 117 | Market | computeMarketFactors | FII Net Buy/Sell Yesterday | Yes | directionalScore | null / 0 | Yes |
| 118 | Market | computeMarketFactors | DII Net Buy/Sell | Yes | directionalScore | null / 0 | Yes |
| 119 | Market | computeMarketFactors | Event Day RBI/Fed/Budget/Election | Yes | directionalScore | null / 0 | Yes |
| 120 | Market | computeMarketFactors | Stock Results Today | Yes | directionalScore | null / 0 | Yes |
| 121 | Market | computeMarketFactors | Corporate Action Near Expiry | Yes | directionalScore | null / 0 | Yes |
| 122 | Market | computeMarketFactors | Day of Week | Yes | directionalScore | false / -0.2 | Yes |
| 123 | Market | computeMarketFactors | Time of Day | Yes | directionalScore | true / 0.3 | Yes |
| 124 | Market | computeMarketFactors | Market Breadth Adv/Decl | Yes | directionalScore | null / 0 | Yes |
| 125 | Market | computeMarketFactors | Sector Trend Bank vs Nifty | Yes | directionalScore | null / 0 | Yes |
| 126 | Market | computeMarketFactors | USDINR Movement | Yes | directionalScore | null / 0 | Yes |
| 127 | Market | computeMarketFactors | Crude Oil | Yes | directionalScore | null / 0 | Yes |
| 128 | Market | computeMarketFactors | 10Y Bond Yield | Yes | directionalScore | null / 0 | Yes |
| 129 | Market | computeMarketFactors | Previous Day High/Low Break | Yes | directionalScore | null / 0 | Yes |
| 130 | Market | computeMarketFactors | Gap Up/Down Opening | Yes | directionalScore | null / 0 | Yes |
| 131 | Flow | computeFlowFactors | OI Change CE Side | Yes | directionalScore | null / 0 | Yes |
| 132 | Flow | computeFlowFactors | OI Change PE Side | Yes | directionalScore | null / 0 | Yes |
| 133 | Flow | computeFlowFactors | OI Change % >3% | Yes | directionalScore | true / 0.3 | Yes |
| 134 | Flow | computeFlowFactors | PCR Change 30m | Yes | directionalScore | null / 0 | Yes |
| 135 | Flow | computeFlowFactors | Max Pain Level | Yes | directionalScore | null / 0 | Yes |
| 136 | Flow | computeFlowFactors | Distance from Max Pain | Yes | directionalScore | null / 0 | Yes |
| 137 | Flow | computeFlowFactors | Bid Qty vs Ask Qty | Yes | directionalScore | null / 0 | Yes |
| 138 | Flow | computeFlowFactors | Bid-Ask Spread Options | Yes | directionalScore | null / 0 | Yes |
| 139 | Flow | computeFlowFactors | Volume vs OI | Yes | directionalScore | true / 0.3 | Yes |
| 140 | Flow | computeFlowFactors | ATM Straddle Price | Yes | directionalScore | true / 0 | Yes |
| 141 | Flow | computeFlowFactors | ATM Straddle % Change | Yes | directionalScore | null / 0 | Yes |
| 142 | Flow | computeFlowFactors | Delta of Option | Yes | directionalScore | null / 0 | Yes |
| 143 | Flow | computeFlowFactors | Gamma Expiry Day | Yes | directionalScore | true / 0.3 | Yes |
| 144 | Flow | computeFlowFactors | Theta Decay Today | Yes | directionalScore | null / 0 | Yes |
| 145 | Flow | computeFlowFactors | Vega VIX Impact | Yes | directionalScore | true / 0.3 | Yes |
| 146 | Flow | computeFlowFactors | OI Concentration at Strike | Yes | directionalScore | null / 0 | Yes |
| 147 | Flow | computeFlowFactors | ATM vs OTM OI Change | Yes | directionalScore | null / 0 | Yes |
| 148 | Flow | computeFlowFactors | Long/Short Buildup | Yes | directionalScore | null / 0 | Yes |
| 149 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | MTM Square Off Time MIS vs NRML | Yes | riskScore | null / 0 | Yes |
| 150 | Microstructure | computeMicrostructureDaemonFactors / computeFuturesFactors / computeDocumentedGapFactors / computeEnhancementFactors | VWAP Bands 1SD 2SD | Yes | tradeQualityScore | null / 0 | Yes |
| 151 | Fundamental | computeFundamentalRealFactors / computeFuturesFactors / computeOIRolloverFactor / computeEnhancementFactors / computeDocumentedGapFactors | FII Long/Short Ratio Index Futures | Yes | directionalScore | null / 0 | Yes |
| 152 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | Peak Margin Rule 100% Upfront | Yes | riskScore | true / 0.2 | Yes |
| 153 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | Physical Settlement Risk - Stock Options ITM | Yes | riskScore | true / 0.2 | Yes |
| 154 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | Circuit Limit - Upper/Lower Circuit? | Yes | riskScore | true / 0.1 | Yes |
| 155 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | SEBI Expiry Day Extra Margin 2% | Yes | riskScore | true / 0 | Yes |
| 156 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | Corporate Action - Bonus/Split/Dividend Adjustment? | Yes | riskScore | true / 0.1 | Yes |
| 157 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | Short Selling Ban? | Yes | riskScore | true / 0.1 | Yes |
| 158 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | MTM Square Off Time MIS vs NRML | Yes | riskScore | null / 0 | Yes |
| 159 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | Trading Halt - NSE Halt 10/15/20%? | Yes | riskScore | null / 0 | Yes |
| 160 | Fundamental | computeFundamentalRealFactors / computeFuturesFactors / computeOIRolloverFactor / computeEnhancementFactors / computeDocumentedGapFactors | Client vs Pro vs FII Positioning | Yes | directionalScore | null / 0 | Yes |
| 161 | Fundamental | computeFundamentalRealFactors / computeFuturesFactors / computeOIRolloverFactor / computeEnhancementFactors / computeDocumentedGapFactors | Promoter Pledging % High? | Yes | directionalScore | true / 0.1 | Yes |
| 162 | Fundamental | computeFundamentalRealFactors / computeFuturesFactors / computeOIRolloverFactor / computeEnhancementFactors / computeDocumentedGapFactors | Dividend Yield & Ex-Date | Yes | directionalScore | true / 0.1 | Yes |
| 163 | Fundamental | computeFundamentalRealFactors / computeFuturesFactors / computeOIRolloverFactor / computeEnhancementFactors / computeDocumentedGapFactors | Cash Volume vs F&O Volume | Yes | directionalScore | null / 0 | Yes |
| 164 | Fundamental | computeFundamentalRealFactors / computeFuturesFactors / computeOIRolloverFactor / computeEnhancementFactors / computeDocumentedGapFactors | Results Calendar Today Reliance etc | Yes | directionalScore | null / 0 | Yes |
| 165 | Regulatory | computeRegulatoryRealFactors / computeTradingHaltFactor / computeDocumentedGapFactors | ASM/GSM List - Surveillance? | Yes | riskScore | null / 0 | Yes |
| 166 | Microstructure | computeMicrostructureDaemonFactors / computeFuturesFactors / computeDocumentedGapFactors / computeEnhancementFactors | Iceberg Orders - Hidden Big Orders | Yes | tradeQualityScore | true / 0 | Yes |
| 167 | Microstructure | computeMicrostructureDaemonFactors / computeFuturesFactors / computeDocumentedGapFactors / computeEnhancementFactors | Cumulative Delta - Buy Vol - Sell Vol | Yes | tradeQualityScore | true / 0 | Yes |
| 168 | Microstructure | computeMicrostructureDaemonFactors / computeFuturesFactors / computeDocumentedGapFactors / computeEnhancementFactors | Volume Profile POC - Point of Control | Yes | tradeQualityScore | true / 0 | Yes |
| 169 | Microstructure | computeMicrostructureDaemonFactors / computeFuturesFactors / computeDocumentedGapFactors / computeEnhancementFactors | Order Flow Imbalance 80% Bid | Yes | tradeQualityScore | true / 0 | Yes |
| 170 | Microstructure | computeMicrostructureDaemonFactors / computeFuturesFactors / computeDocumentedGapFactors / computeEnhancementFactors | Footprint Chart Volume at Price | Yes | tradeQualityScore | true / 0 | Yes |
| 171 | Microstructure | computeMicrostructureDaemonFactors / computeFuturesFactors / computeDocumentedGapFactors / computeEnhancementFactors | Tick Data Speed - Fast/Slow Tape | Yes | tradeQualityScore | true / 0 | Yes |
| 172 | Microstructure | computeMicrostructureDaemonFactors / computeFuturesFactors / computeDocumentedGapFactors / computeEnhancementFactors | DOM Ladder - Spoofing Orders? | Yes | tradeQualityScore | true / 0 | Yes |
| 173 | Microstructure | computeMicrostructureDaemonFactors / computeFuturesFactors / computeDocumentedGapFactors / computeEnhancementFactors | Futures vs Spot Premium/Discount | Yes | tradeQualityScore | true / 0.3 | Yes |
| 174 | Fundamental | computeFundamentalRealFactors / computeFuturesFactors / computeOIRolloverFactor / computeEnhancementFactors / computeDocumentedGapFactors | Cost of Carry Futures vs Spot | Yes | directionalScore | false / -0.5 | Yes |
| 175 | Fundamental | computeFundamentalRealFactors / computeFuturesFactors / computeOIRolloverFactor / computeEnhancementFactors / computeDocumentedGapFactors | OI Rollover % Current to Next Expiry | Yes | directionalScore | null / 0 | Yes |
| 176 | Fundamental | computeFundamentalRealFactors / computeFuturesFactors / computeOIRolloverFactor / computeEnhancementFactors / computeDocumentedGapFactors | News Sentiment Score Positive/Negative | Yes | directionalScore | null / 0 | Yes |
| 177 | Fundamental | computeFundamentalRealFactors / computeFuturesFactors / computeOIRolloverFactor / computeEnhancementFactors / computeDocumentedGapFactors | Social Media Sentiment Twitter/StockTwits | Yes | directionalScore | null / 0 | Yes |
| 178 | Microstructure | computeMicrostructureDaemonFactors / computeFuturesFactors / computeDocumentedGapFactors / computeEnhancementFactors | Market Depth Top 5 Bids/Ask | Yes | tradeQualityScore | null / 0 | Yes |
| 179 | Psychology | computePsychologyFactors | FOMO Level - Entering Because Missed Move? | Yes | modelQualityScore | null / 0 | Yes |
| 180 | Psychology | computePsychologyFactors | Anchoring Bias - Saw 23500 Now 23200 Cheap? | Yes | modelQualityScore | null / 0 | Yes |
| 181 | Psychology | computePsychologyFactors | Loss Aversion - Holding Losers, Booking Winners Early? | Yes | modelQualityScore | true / 0.3 | Yes |
| 182 | Psychology | computePsychologyFactors | Recency Bias - Last 2 Won So Next Will Win? | Yes | modelQualityScore | true / 0.3 | Yes |
| 183 | Psychology | computePsychologyFactors | Backtest Overfitting - 50 or 500 Trades? | Yes | modelQualityScore | false / -0.3 | Yes |
| 184 | Psychology | computePsychologyFactors | Slippage in Backtest Included? | Yes | modelQualityScore | null / 0 | Yes |
| 185 | Psychology | computePsychologyFactors | Data Quality NSE Error? | Yes | modelQualityScore | null / 0 | Yes |
| 186 | Psychology | computePsychologyFactors | Survivorship Bias - Tested on NIFTY Only? | Yes | modelQualityScore | false / -0.3 | Yes |
| 187 | Psychology | computePsychologyFactors | Broker API Rate Limit 1000/sec? | Yes | modelQualityScore | null / 0 | Yes |
| 188 | Psychology | computePsychologyFactors | GTT/OCO Order Type Supported? | Yes | modelQualityScore | null / 0 | Yes |

## Summary

- Total factor rows enumerated (real, captured run): **188**
- Compute*Factors() functions confirmed: **18** (grep-verified, `computeRegimeDependentFactors` correctly excluded)
- Orphaned factors (computed, never read by any aggregate score): **0**
- Structurally phantom factors (score always literal 0 regardless of real input): **7** (Microstructure daemon rows,
  already honestly disclosed in their own reason text - see Finding #2; not silently misrepresented)
- Genuine UI honesty/coverage bug found and fixed: **1** (`#filterF` dropdown missing 8/14 live categories -
  Finding #3, `assets/standalone-app.php:386`)
- New regression test added: **1 file, 3 assertions** (`tests/factor-filter-dropdown-coverage-audit.test.js`)
- Kill switch (`FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED`, `fno-lab.php:80`): confirmed unchanged, still exactly `false`
