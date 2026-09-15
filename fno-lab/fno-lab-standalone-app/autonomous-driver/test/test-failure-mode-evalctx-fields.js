#!/usr/bin/env node
/**
 * Real, permanent regression test for this session's fix to a real,
 * previously-uncatalogued gap (docs/PENDING_REQUIREMENTS.md §3c): the
 * driver used to call evaluatePreTradeFailureModes() with only
 * {brain, ctx}, silently leaving trapSignal/breakoutCondition/
 * maxPainCheck/spreadLevelCheck/spreadWideningCheck/gapFillCheck/
 * ivPercentile/optionType/rejectionCheck/latencyCheck permanently
 * unable to fire in headless operation, even though the driver already
 * fetches every raw input each of these needs.
 *
 * Two things are checked, the same two-level discipline as
 * test-signal-invalidation-gate.js:
 *
 * 1. Real, direct unit-level checks that the SAME, already-tested pure
 *    helper functions this fix reuses (never re-implemented) produce
 *    the real, expected shape from realistic inputs.
 * 2. A real, static source-level check that autonomous-driver.js's own
 *    evaluatePreTradeFailureModes() call site genuinely threads each
 *    of these fields through - so a future edit that accidentally
 *    drops one silently regresses back to the half-covered gap this
 *    fix closed.
 *
 * Run with: node test/test-failure-mode-evalctx-fields.js
 */
const fs = require('fs');
const path = require('path');

const CORE_PATH = path.join(__dirname, '../../assets/fno-lab-core.js');
const DRIVER_PATH = path.join(__dirname, '../autonomous-driver.js');

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log(`  PASS  ${label}`); }
  else { failed++; console.log(`  FAIL  ${label}`); }
}

console.log('=== Driver Failure-Mode evalCtx Field-Coverage Regression Test (PENDING_REQUIREMENTS §3c) ===\n');

// --- Part 1: real, direct checks of the real, current core functions ---
global.localStorage = (function () {
  const store = {};
  return {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => { store[k] = String(v); },
    removeItem: (k) => { delete store[k]; },
  };
})();
const coreSource = fs.readFileSync(CORE_PATH, 'utf8');
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js - this test\'s own extraction is stale.');
  process.exit(1);
}
eval(coreSource.slice(0, end));

['computeTrapSignal', 'computeBreakoutReversalCondition', 'computeMaxPainInfo', 'checkSpreadLevel',
  'checkSpreadWideningVsEarlier', 'checkGapFillStatus', 'computeIVPercentileRank', 'simulateOrderRejection',
  'checkExecutionLatencyRisk', 'recordSnapshot', 'getSnapshotHistory', 'getSnapshotNearMinutesAgo']
  .forEach(name => check(typeof global[name] === 'function' || typeof eval(name) === 'function', `${name} is present in the pre-render() extracted region (reachable by the driver)`));

const candles = [];
for (let i = 0; i < 40; i++) candles.push({ t: Date.now() - (40 - i) * 60000, c: 100 + Math.sin(i / 3) });
const breakout = computeBreakoutReversalCondition(candles, 20);
check(breakout && typeof breakout.condition === 'string', 'computeBreakoutReversalCondition genuinely returns a real condition string from ordinary candle data');

const trap = computeTrapSignal(600000, 1.5);
check(trap && typeof trap.isTrapSignature === 'boolean', 'computeTrapSignal genuinely returns a real isTrapSignature verdict from a large OI move + price change');

const rows = [
  { strikePrice: 24000, CE: { openInterest: 1000 }, PE: { openInterest: 500 } },
  { strikePrice: 24100, CE: { openInterest: 800 }, PE: { openInterest: 1200 } },
];
const maxPain = computeMaxPainInfo(rows, 24050);
check(maxPain && typeof maxPain.strike === 'number', 'computeMaxPainInfo genuinely returns a real strike from ordinary option-chain rows');

const leg = { bidprice: 100, askPrice: 106, bidQty: 10, askQty: 500 };
const spreadLevel = checkSpreadLevel(leg);
check(typeof spreadLevel.spreadPct === 'number' && spreadLevel.spreadPct > 5, 'checkSpreadLevel genuinely computes a real spread% from bid/ask prices');

const widening = checkSpreadWideningVsEarlier(9, 2);
check(widening.isWidened === true, 'checkSpreadWideningVsEarlier genuinely flags a real, meaningful point-widening');

const gapFill = checkGapFillStatus(candles);
check(gapFill && ('hasGap' in gapFill), 'checkGapFillStatus genuinely returns a real hasGap verdict from ordinary candle data');

recordSnapshot({ ts: Date.now() - 5000, vix: 15, pcr: 1, iv: 20, ceSpreadPct: 3, peSpreadPct: 4 });
const hist = getSnapshotHistory();
check(Array.isArray(hist) && hist.length === 1, 'recordSnapshot/getSnapshotHistory genuinely accumulate via the driver\'s own in-memory localStorage stub');

const rejection = simulateOrderRejection(leg, 50000, 'buy');
check(typeof rejection.rejected === 'boolean', 'simulateOrderRejection genuinely returns a real rejected verdict from leg/qty/side');

// --- Part 2: real, static proof the driver source actually wires these fields through ---
const driverSource = fs.readFileSync(DRIVER_PATH, 'utf8');
const callSiteMatch = driverSource.match(/const fmResultRaw = evaluatePreTradeFailureModes\(\{[\s\S]*?\}\);/);
check(!!callSiteMatch, 'the real driver\'s evaluatePreTradeFailureModes() call site was located in the current source');
const callSiteBody = callSiteMatch ? callSiteMatch[0] : '';

['optionType: entryOptionType', 'execMode: driverExecMode', 'trapSignal,', 'breakoutCondition,', 'maxPainCheck,',
  'spreadLevelCheck,', 'spreadWideningCheck,', 'gapFillCheck,', 'ivPercentile,', 'rejectionCheck,', 'latencyCheck,']
  .forEach(fragment => check(callSiteBody.includes(fragment), `the real evaluatePreTradeFailureModes() call site genuinely threads through: ${fragment}`));

// Real, UPDATED this session: hypothesisDirectionStats/strategyVersionsCache
// are no longer hardcoded null - both are now real, per-cycle fetches
// (fno_get_hypothesis_stats/fno_get_strategy_versions) threaded through as
// the driver-local consts computed above the call site. Assert the call
// site passes the real computed consts (not a literal null anymore).
check(/hypothesisDirectionStats,/.test(callSiteBody), 'hypothesisDirectionStats is threaded through the call site as the real, fetched const (no longer a hardcoded null)');
check(/strategyVersionsCache,/.test(callSiteBody), 'strategyVersionsCache is threaded through the call site as the real, fetched const (no longer a hardcoded null)');
check(!/hypothesisDirectionStats: null/.test(driverSource), 'the old hardcoded "hypothesisDirectionStats: null" placeholder is genuinely gone from the driver source');
check(!/strategyVersionsCache: null/.test(driverSource), 'the old hardcoded "strategyVersionsCache: null" placeholder is genuinely gone from the driver source');

// Real, static proof the two new fetches and their parsing exist and use
// the exact same response-shape expectations as the browser's own
// loadStrategyVersions()/loadHypothesisStats() (fno-lab-core.js).
check(/fetchJson\('fno_get_strategy_versions'\)/.test(driverSource), 'the driver genuinely fetches fno_get_strategy_versions every cycle');
check(/fetchJson\('fno_get_hypothesis_stats'\)/.test(driverSource), 'the driver genuinely fetches fno_get_hypothesis_stats every cycle');
check(/strategyVersionsResult && Array\.isArray\(strategyVersionsResult\.versions\)/.test(driverSource), 'strategyVersionsCache is parsed from response.versions, the same real shape fno_get_strategy_versions_fn returns and loadStrategyVersions() already expects');
check(/hypothesisStatsResult && hypothesisStatsResult\.byDirection/.test(driverSource), 'hypothesisDirectionStats is parsed from response.byDirection, the same real shape fno_get_hypothesis_stats_fn returns and loadHypothesisStats() already expects (window.FNO_HYPOTHESIS_DIRECTION_STATS_CACHE = byDirection)');

// Real, static proof the PHP-side auth for both endpoints was actually
// swapped to a driver-reachable pattern, not just the JS side changed.
const phpPath = path.join(__dirname, '../../fno-lab.php');
const phpSource = fs.readFileSync(phpPath, 'utf8');
const stratFnMatch = phpSource.match(/function fno_get_strategy_versions_fn\(\) \{[\s\S]*?\n\}/);
check(!!stratFnMatch && /fno_verify_public_or_driver_access\(\)/.test(stratFnMatch[0]), 'fno_get_strategy_versions_fn genuinely calls fno_verify_public_or_driver_access() (site-wide data, no per-user scoping needed)');
const hypFnMatch = phpSource.match(/function fno_get_hypothesis_stats_fn\(\) \{[\s\S]*?\n\}/);
check(!!hypFnMatch && /fno_verify_app_access\(\)/.test(hypFnMatch[0]), 'fno_get_hypothesis_stats_fn genuinely calls fno_verify_app_access() (per-user data - needs the real wp_set_current_user() the driver secret triggers there)');
check(/add_action\('wp_ajax_nopriv_fno_get_hypothesis_stats', 'fno_get_hypothesis_stats_fn'\)/.test(phpSource), 'fno_get_hypothesis_stats is genuinely registered nopriv, required for the headless driver (no session) to ever reach it');

// --- Part 3: real, follow-up fix - ctx.fullJournal/accountAvailableCapital
// were never threaded through to the driver at all (a previously-
// undiscovered gap, distinct from Part 1/2's field set - this one feeds
// evaluatePreTradeFailureModes() one level upstream, via ctx, not a
// direct evalCtx field). Closes FM044 for the driver, plus the
// previously-silent FM057/FM058/FM072/FM104/FM105/FM129/FM130 gap (all
// gated on Array.isArray(ctx.fullJournal), which was always undefined
// here before this fix).
check(/fetchJson\('fno_journal_list'\)/.test(driverSource), 'the driver genuinely fetches fno_journal_list every cycle');
check(/fetchJson\('fno_get_paper_account'\)/.test(driverSource), 'the driver genuinely fetches fno_get_paper_account every cycle');
check(/journalResult && Array\.isArray\(journalResult\.journal\)/.test(driverSource), 'driverJournal is parsed from response.journal, the same real shape fno_journal_list_fn returns and the browser\'s syncServerJournal() already expects');
check(/computeEquityCurve\(driverJournal, paperAccountResult, openPosition\)/.test(driverSource), 'the driver genuinely reuses computeEquityCurve() - the exact same function loadPaperAccount() uses in the browser - rather than inventing new balance math');
check(/fullJournal: driverJournal,/.test(driverSource), 'ctx.fullJournal is now genuinely threaded through the driver\'s ctx object literal');
check(/accountAvailableCapital, accountCurrentDrawdownPct, accountCurrentBalance,/.test(driverSource), 'ctx.accountAvailableCapital/accountCurrentDrawdownPct/accountCurrentBalance are now genuinely threaded through the driver\'s ctx object');
const journalFnMatch = phpSource.match(/function fno_journal_list_fn\(\) \{[\s\S]*?\n\}/);
check(!!journalFnMatch && /fno_verify_app_access\(\)/.test(journalFnMatch[0]), 'fno_journal_list_fn genuinely calls fno_verify_app_access() (per-user data - needs the real wp_set_current_user() the driver secret triggers there)');
check(/add_action\('wp_ajax_nopriv_fno_journal_list', 'fno_journal_list_fn'\)/.test(phpSource), 'fno_journal_list is genuinely registered nopriv, required for the headless driver (no session) to ever reach it');
const paperAcctFnMatch = phpSource.match(/function fno_get_paper_account_fn\(\) \{[\s\S]*?\n\}/);
check(!!paperAcctFnMatch && /fno_verify_app_access\(\)/.test(paperAcctFnMatch[0]), 'fno_get_paper_account_fn genuinely calls fno_verify_app_access() (per-user data)');
check(/add_action\('wp_ajax_nopriv_fno_get_paper_account', 'fno_get_paper_account_fn'\)/.test(phpSource), 'fno_get_paper_account is genuinely registered nopriv, required for the headless driver (no session) to ever reach it');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
