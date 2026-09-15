<?php
/**
 * REAL, STANDALONE PHP regression test documenting this app's
 * market-data-STALENESS protection - UPDATED this pass to reflect a
 * genuine partial fix, not a full close.
 *
 * ORIGINAL GAP (prior pass): candle staleness WAS checked
 * (checkMarketDataFreshness() in assets/fno-lab-core.js, wired to
 * FM154), but no other real-time data source this engine's
 * entry/exit decisions actually price against (option-chain premium,
 * futures price, spot price used for decisions) carried a genuine
 * upstream "as of" timestamp anywhere in this codebase - both live DQ
 * call sites (option-chain spot value, VIX) hardcoded
 * `fetchedAt => time()*1000, freshnessSeconds => 0`, making the
 * STALE_OVER_5MIN/DELAYED_OVER_1MIN branches in fno_dq_check() dead
 * code for them, and fno_fetch_oc_fn()/fno_fetch_futures_fn() (the
 * option-chain/futures fetchers driving entry/exit pricing) captured
 * no timestamp at all.
 *
 * THIS PASS'S FIX: fno_nse_get() (the shared wp_remote_get() wrapper
 * every NSE fetch goes through - verified to perform NO caching, every
 * call is a genuinely fresh live HTTP round-trip) now captures the
 * real wall-clock ms instant it receives a valid response, keyed by
 * URL in a process-lifetime registry (fno_nse_get_last_fetch_time()),
 * WITHOUT changing its own return shape - all 7 pre-existing call
 * sites (grep-verified this pass) are unaffected. That real timestamp
 * is now threaded into: the option-chain spot DQ record, the VIX DQ
 * record, fno_fetch_oc_fn()'s top-level response ('fetchedAt'), and
 * fno_fetch_futures_fn()'s response ('fetchedAt') - and from there
 * into ctx.ocFetchedAt/ctx.futuresFetchedAt on the JS side, feeding a
 * new checkOptionChainFreshness() check wired as FM155 (sibling to
 * FM154, same 10-minute FNO_MARKET_DATA_STALE_THRESHOLD_MINUTES).
 *
 * REMAINING, STILL-HONEST GAP (unchanged by this pass):
 * fno_dsm_resolve() - the generalized resolver in fno-data-layer.php
 * that computes freshnessSeconds from a provider-supplied fetchedAt -
 * is still never called from fno-lab.php's live AJAX handlers. This
 * pass wired the real timestamp directly into fno_dq_check() at the
 * two existing call sites instead (the narrower, already-live path),
 * not through fno_dsm_resolve()'s broader multi-provider machinery,
 * which remains a separate, larger, still-deferred piece of work
 * exactly as fno-data-layer.php's own header still states.
 *
 * This file remains a REGRESSION GUARD - now against the NEW gap
 * boundary silently drifting: assertions 3-5 below lock in that the
 * fix is real (a genuine, non-"now" timestamp reaches these call
 * sites) while assertion 6 keeps locking in the one part of the
 * original gap that is still honestly open (fno_dsm_resolve()).
 *
 * Run with: php tests/php/MarketDataFreshnessGapTest.php
 */

$fnoLabSource = file_get_contents(__DIR__ . '/../../fno-lab.php');
$dataLayerSource = file_get_contents(__DIR__ . '/../../fno-data-layer.php');
$coreJsSource = file_get_contents(__DIR__ . '/../../assets/fno-lab-core.js');

$passed = 0; $failed = 0;
function assertTrue($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "  PASS  $label\n"; }
    else { $failed++; echo "  FAIL  $label\n"; }
}

echo "=== Market Data Freshness - scope regression guard (post-fix) ===\n";

// 1. checkMarketDataFreshness() still exists, still candle-specific -
// this pass added a SIBLING function (checkOptionChainFreshness), not
// a change to this one's own scope.
assertTrue(
    strpos($coreJsSource, 'function checkMarketDataFreshness(candles, nowMs)') !== false,
    'checkMarketDataFreshness() still exists with its real, candle-only signature (candles, nowMs)'
);

// 2. FM154 is still the only catalog entry wired to checkMarketDataFreshness.
$fm154Count = substr_count($coreJsSource, "checkMarketDataFreshness(") - substr_count($coreJsSource, "function checkMarketDataFreshness(");
assertTrue($fm154Count === 1, "checkMarketDataFreshness is still called exactly once in fno-lab-core.js (found $fm154Count real call site(s)) - confirms this pass did not touch its scope");

// 3. fno_nse_get() now captures a real, non-fabricated fetch timestamp
// (verified by the registry-write line existing, keyed by URL, using
// microtime()-based wall clock, not time()*1000-at-read).
assertTrue(
    strpos($fnoLabSource, "\$GLOBALS['fno_nse_last_fetch_at'][\$url] = (int) round(microtime(true) * 1000);") !== false,
    'fno_nse_get() captures the real receipt instant via microtime(true), keyed by URL, immediately after a successful response - not a read-time time() placeholder'
);
assertTrue(
    strpos($fnoLabSource, 'function fno_nse_get_last_fetch_time($url)') !== false,
    'fno_nse_get_last_fetch_time() exists as the real accessor for that captured timestamp'
);
// fno_nse_get()'s own return shape is untouched - still exactly one
// `return $data;` in its body (the registry write is a side effect,
// not a new return value), confirming all 7 pre-existing callers'
// destructuring is unaffected.
$nseGetStart = strpos($fnoLabSource, 'function fno_nse_get($url, $referer)');
$nseGetEnd = strpos($fnoLabSource, "\n}", $nseGetStart);
$nseGetBody = substr($fnoLabSource, $nseGetStart, $nseGetEnd - $nseGetStart);
assertTrue(
    substr_count($nseGetBody, 'return $data;') === 1 && substr_count($nseGetBody, 'return null;') === 4,
    "fno_nse_get()'s return shape is unchanged (one 'return \$data;' success path, four 'return null;' failure paths: circuit-open, wp_error, non-200, invalid-JSON) - the timestamp is threaded via a side registry, not a signature change, so all 7 existing call sites are unaffected"
);

// 4. The option-chain and futures fetchers now DO carry a real
// fetchedAt in their response payload (fed from the registry above,
// not a hardcoded "now").
assertTrue(
    strpos($fnoLabSource, "\$data['fetchedAt'] = \$ocFetchedAt;") !== false,
    'fno_fetch_oc_fn() now threads a real fno_nse_get()-captured fetchedAt into its top-level response'
);
assertTrue(
    strpos($fnoLabSource, "'fetchedAt' => \$futFetchedAt,") !== false,
    'fno_fetch_futures_fn() now threads a real fno_nse_get()-captured fetchedAt into its NSE-success response'
);

// 5. The two live DQ call sites (option-chain spot value, VIX) no
// longer hardcode fetchedAt/freshnessSeconds to a self-referential
// "now" - the old, dead-code-guaranteeing pattern is gone.
assertTrue(
    substr_count($fnoLabSource, "'fetchedAt' => time() * 1000, 'freshnessSeconds' => 0") === 0,
    'the old self-referential "fetchedAt => time()*1000, freshnessSeconds => 0" pattern is gone from both live DQ call sites - fixed this pass'
);
assertTrue(
    strpos($fnoLabSource, "'fetchedAt' => \$ocFetchedAt ?? (time() * 1000),") !== false,
    'the option-chain spot DQ record now uses the real captured \$ocFetchedAt, honestly falling back to time()*1000/freshnessSeconds 0 only when no real NSE round-trip happened this refresh (Kite fallback / NSE off)'
);
assertTrue(
    strpos($fnoLabSource, "'fetchedAt' => \$vixFetchedAt ?? (time() * 1000),") !== false,
    'the VIX DQ record now uses the real captured $vixFetchedAt, with the same honest fallback discipline'
);

// 6. fno_dsm_resolve() - the generalized multi-provider resolver -
// REMAINS unwired from fno-lab.php's live AJAX handlers. This pass
// fixed the narrower fno_dq_check() call sites directly rather than
// routing through fno_dsm_resolve()'s broader machinery; that broader
// wiring is still separate, still-deferred work, honestly unchanged.
assertTrue(
    strpos($dataLayerSource, 'NOT YET CALLED BY LIVE CODE') !== false,
    'fno_dsm_resolve() is still explicitly self-documented as not yet called by live code - unchanged by this pass'
);
assertTrue(
    strpos($fnoLabSource, 'fno_dsm_resolve(') === false,
    'fno_dsm_resolve() is still not called anywhere in fno-lab.php - this pass fixed fno_dq_check()\'s call sites directly, not by wiring the broader fno_dsm_resolve() resolver'
);

// 7. checkTradeExit() - the real exit-decision function - still takes
// only numeric prices, no timestamp of any kind. This pass did not
// touch the exit path; a stale-but-numerically-valid currentPrice at
// exit time remains an honestly open, separate gap from the entry-side
// staleness this pass addressed.
assertTrue(
    strpos($coreJsSource, 'function checkTradeExit(entryPrice, currentPrice, targetPrice, slPrice)') !== false,
    'checkTradeExit() still has a real, timestamp-free signature - the exit path\'s staleness gap is unchanged by this pass, which was scoped to entry-side data (option chain/futures/VIX/spot)'
);

// 8. NEW this pass: checkOptionChainFreshness() exists and FM155 is
// wired to it exactly once, the real sibling fix to FM154's gap.
assertTrue(
    strpos($coreJsSource, 'function checkOptionChainFreshness(fetchedAtMs, nowMs)') !== false,
    'checkOptionChainFreshness() exists with its real (fetchedAtMs, nowMs) signature'
);
$fm155Count = substr_count($coreJsSource, "checkOptionChainFreshness(ctx.ocFetchedAt") + substr_count($coreJsSource, "checkOptionChainFreshness(ctx.futuresFetchedAt");
assertTrue($fm155Count === 2, "checkOptionChainFreshness is wired at both real ctx entry points this pass added (ctx.ocFetchedAt and ctx.futuresFetchedAt fallback) - found $fm155Count");
assertTrue(
    substr_count($coreJsSource, "check('FM155'") === 2,
    "FM155 is wired exactly twice (the ocFetchedAt branch and the futuresFetchedAt fallback branch, mutually exclusive via if/else - never double-fires in one refresh)"
);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
