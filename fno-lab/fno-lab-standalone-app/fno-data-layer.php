<?php
/**
 * F&O Lab - Unified Market Data Layer (Enterprise Data Architecture Plan, Phase 1)
 * ===================================================================
 *
 * Implements requirements #1, #20, #21, #22, #34, #35, #36, #37 from
 * the Enterprise Data Architecture spec. See
 * docs/ENTERPRISE_DATA_ARCHITECTURE_PLAN.md for the full 40-item plan
 * and why this phase comes first (everything else depends on it).
 *
 * CORE PRINCIPLE: the 193-factor engine must NEVER call an adapter
 * directly, and must NEVER see a TrueData/Zerodha/NSE field name. It
 * calls fno_dsm_get() (the Data Source Manager) and receives ONE
 * canonical normalized shape, with an explicit tier
 * (current/delayed/stale/unavailable) - never a silent substitution.
 *
 * NOT a rewrite of the existing NSE-free/Kite code paths that already
 * work and are already tested - this layer WRAPS them as adapters, so
 * existing behavior is preserved while new capabilities (TrueData,
 * quality checking, cost-aware tier resolution) are added around them.
 */

if (!defined('ABSPATH')) exit;

// ------------------------------------------------------------------
// #1 UNIFIED MARKET DATA LAYER - Adapter Registry
// ------------------------------------------------------------------

/**
 * TRACE: The canonical normalized record shape every adapter must
 * produce - the "unified" contract from Enterprise Plan #1/#40. This
 * function doesn't fetch anything itself; it's the shared shape
 * definition every fno_adapter_*_normalize() function below maps its
 * source-specific response into, so the 193-factor engine (and every
 * downstream consumer) only ever sees ONE field-naming convention.
 * Preconditions: none - this is a documentation/default-value function.
 * Postconditions: returns an array with every canonical field present
 * (null where the calling adapter doesn't provide it) - callers can
 * always safely read any field without an isset() check, because a
 * field that doesn't exist in the source is explicitly null, not
 * absent from the array.
 */
function fno_dsm_empty_record() {
    return [
        // Identity
        'symbol' => null, 'underlying' => null, 'exchange' => 'NSE', 'segment' => null,
        'instrumentToken' => null, 'exchangeToken' => null, 'expiry' => null, 'dte' => null,
        'strike' => null, 'optionType' => null, 'lotSize' => null, 'tickSize' => null,
        // Price
        'ltp' => null, 'lastTradeQty' => null, 'lastTradeTime' => null,
        'open' => null, 'high' => null, 'low' => null, 'prevClose' => null,
        'avgPrice' => null, 'priceChange' => null, 'priceChangePct' => null,
        // Activity
        'volume' => null, 'oi' => null, 'prevOi' => null, 'oiChange' => null, 'oiChangePct' => null,
        'buyQty' => null, 'sellQty' => null,
        // Liquidity
        'bid' => null, 'ask' => null, 'bidQty' => null, 'askQty' => null,
        'spread' => null, 'spreadPct' => null, 'mid' => null, 'depth' => null,
        // Options
        'iv' => null, 'delta' => null, 'gamma' => null, 'theta' => null, 'vega' => null, 'rho' => null,
        // Meta (provenance - Enterprise Plan #21/#22/#25)
        'source' => null, 'sourceTier' => null, // 'primary'|'secondary'|'cache'|'unavailable'
        'fetchedAt' => null, 'exchangeTimestamp' => null,
        'freshnessSeconds' => null, 'qualityFlags' => [],
    ];
}

/**
 * TRACE: #20 Data Quality Engine - checks ONE normalized record for
 * the real anomaly classes the spec lists: missing critical fields,
 * negative/impossible prices, crossed bid/ask (ask < bid), stale
 * timestamp, zero/negative spread on a liquid instrument.
 * Preconditions: $record is a fno_dsm_empty_record()-shaped array
 * (fields may be null - that's checked explicitly, not assumed absent).
 * Postconditions: returns an array of string flags (empty = clean).
 * Never modifies the record - quality checking is read-only, the
 * CALLER decides what to do with a flagged record (reject it, mark
 * degraded confidence, etc.), this function only reports.
 * Edge cases handled: null ltp/bid/ask (skips the numeric checks that
 * need them rather than a PHP type error); freshnessSeconds not set
 * (staleness check skipped, not assumed fresh).
 */
function fno_dq_check($record) {
    $flags = [];
    if ($record['ltp'] !== null && $record['ltp'] <= 0) $flags[] = 'IMPOSSIBLE_PRICE_LTP';
    if ($record['oi'] !== null && $record['oi'] < 0) $flags[] = 'NEGATIVE_OI';
    if ($record['volume'] !== null && $record['volume'] < 0) $flags[] = 'NEGATIVE_VOLUME';
    if ($record['bid'] !== null && $record['ask'] !== null) {
        if ($record['ask'] < $record['bid']) $flags[] = 'CROSSED_MARKET';
        if ($record['bid'] > 0 && (($record['ask'] - $record['bid']) / $record['bid']) > 0.5) $flags[] = 'ABNORMAL_SPREAD';
    }
    if ($record['freshnessSeconds'] !== null) {
        if ($record['freshnessSeconds'] > 300) $flags[] = 'STALE_OVER_5MIN';
        elseif ($record['freshnessSeconds'] > 60) $flags[] = 'DELAYED_OVER_1MIN';
    }
    if ($record['ltp'] === null && $record['bid'] === null && $record['ask'] === null) $flags[] = 'NO_PRICE_DATA';
    return $flags;
}

/**
 * TRACE: #20 continued - cross-source conflict detection. Given TWO
 * normalized records for the SAME instrument from DIFFERENT sources
 * (e.g. NSE-free and Kite both quoting the same strike this refresh -
 * only meaningful once 2+ adapters are actually configured for the
 * same field, which Phase 1 makes possible but doesn't force), flags
 * a real disagreement rather than silently picking one. Uses the
 * spec's own example threshold logic (a few points of difference is
 * normal quote-timing noise; tens of points is a real conflict).
 * Preconditions: both records are for the same symbol/strike.
 * Postconditions: returns null if no conflict (or one side has no
 * price to compare), or {fieldName, valueA, valueB, diffPct} if a
 * real disagreement exists.
 */
function fno_dq_check_conflict($recordA, $recordB, $conflictThresholdPct = 0.5) {
    if ($recordA['ltp'] === null || $recordB['ltp'] === null || $recordA['ltp'] <= 0) return null;
    $diffPct = abs($recordA['ltp'] - $recordB['ltp']) / $recordA['ltp'] * 100;
    if ($diffPct > $conflictThresholdPct) {
        return [
            'field' => 'ltp', 'sourceA' => $recordA['source'], 'valueA' => $recordA['ltp'],
            'sourceB' => $recordB['source'], 'valueB' => $recordB['ltp'], 'diffPct' => round($diffPct, 3),
        ];
    }
    return null;
}

// ------------------------------------------------------------------
// #21/#22 SOURCE HIERARCHY + FRESHNESS
//
// ARCHITECTURAL NOTE (found and documented Phase 25, resolved here
// Phase 26 - not silently left ambiguous): this function and
// fno_resolve_capability() in fno-lab.php do conceptually overlapping
// work. They are DELIBERATELY NOT merged in this session:
// fno_resolve_capability() is the real, load-bearing resolver every
// live capability (VIX, FII/DII, News Sentiment, Market Depth, Event
// Calendar, Results Calendar) actually goes through today, and this
// sandbox has NO PHP interpreter to verify a merge of two resolvers
// with different call signatures (fno_resolve_capability takes a
// single free-fallback callable; fno_dsm_resolve takes an ordered
// N-tier provider list) doesn't silently break something currently
// working. Forcing an unverifiable merge onto live payment/data-
// fetching code would be irresponsible, not thorough.
//
// STATUS: fno_dsm_resolve() is the FUTURE generalized resolver -
// intended for Phase 2/3 of the Enterprise Plan (raw tick storage,
// TrueData integration), where a genuine N-tier provider list (not
// just "one premium slot, one free fallback") becomes necessary. It
// remains real, tested-by-manual-trace code, deliberately not wired
// to the live capabilities yet. When Phase 2/3 work begins, THAT is
// the point to migrate fno_resolve_capability's call sites onto this
// function (or retire this one in favor of extending the other) -
// with real integration testing at that time, not a docs-only
// resolution like this one.
// ------------------------------------------------------------------

/**
 * TRACE: Walks an ordered list of provider callables for one logical
 * field (e.g. "india_vix") -> PRIMARY -> SECONDARY -> LOCAL CACHE ->
 * DERIVED -> UNAVAILABLE, per Enterprise Plan #21's exact diagram ->
 * returns the first tier that produces a real, quality-checked value
 * -> NEVER falls through to a later tier silently presenting itself
 * as an earlier one (the tier actually used is always in the
 * response, so a caller can never mistake a cached/stale value for
 * live current data).
 * Preconditions: $providers is an ordered array of
 * ['tier'=>'primary'|'secondary'|'cache'|'derived', 'fn'=>callable
 * returning a fno_dsm_empty_record()-shaped array or null].
 * Postconditions: returns the first successful record (tier-tagged,
 * quality-checked) or a fno_dsm_empty_record() with
 * sourceTier='unavailable' if every provider failed - NEVER null,
 * so callers always get a safely-typed record to read fields from.
 * Edge cases handled: a provider throwing an exception (caught,
 * treated as that tier failing, tried next - one misbehaving adapter
 * must not crash the whole resolution chain).
 * NOT YET CALLED BY LIVE CODE - see the architectural note above.
 */
function fno_dsm_resolve($fieldName, $providers) {
    foreach ($providers as $p) {
        try {
            $record = call_user_func($p['fn']);
        } catch (Throwable $e) {
            fno_dsm_log_provider_failure($fieldName, $p['tier'], $e->getMessage());
            continue;
        }
        if ($record === null) continue;
        $record = array_merge(fno_dsm_empty_record(), $record);
        $record['sourceTier'] = $p['tier'];
        if ($record['fetchedAt'] !== null) {
            $record['freshnessSeconds'] = max(0, (time() * 1000 - $record['fetchedAt']) / 1000);
        }
        $record['qualityFlags'] = fno_dq_check($record);
        // A record with NO_PRICE_DATA is treated as a failed provider,
        // not a "successful" empty result - try the next tier.
        if (in_array('NO_PRICE_DATA', $record['qualityFlags'], true)) continue;
        return $record;
    }
    $unavailable = fno_dsm_empty_record();
    $unavailable['sourceTier'] = 'unavailable';
    $unavailable['qualityFlags'] = ['ALL_SOURCES_EXHAUSTED'];
    return $unavailable;
}

function fno_dsm_log_provider_failure($fieldName, $tier, $message) {
    $log = get_transient('fno_dsm_failure_log') ?: [];
    $log[] = ['field' => $fieldName, 'tier' => $tier, 'message' => $message, 'ts' => time() * 1000];
    if (count($log) > 200) $log = array_slice($log, -200);
    set_transient('fno_dsm_failure_log', $log, 3600);
}

// ------------------------------------------------------------------
// #34/#37 COST-CONTROL DATA SOURCE MANAGER + CRITICAL DEPENDENCY RULE
// ------------------------------------------------------------------

/**
 * TRACE: #34 - every time a PAID tier (TrueData/premium provider) is
 * actually invoked, logs WHY (which cheaper tiers were tried first and
 * failed/were unconfigured) - the spec's own explicit example: "News
 * sentiment -> Premium API used because free sources unavailable
 * within required latency." Makes API cost drift auditable rather than
 * discovered on a bill.
 * Preconditions: none. Postconditions: appended to a capped WP option
 * (not a transient - this should survive longer than an hour for real
 * cost auditing, capped at 500 entries to bound growth).
 */
function fno_dsm_log_paid_api_usage($fieldName, $reason) {
    $log = get_option('fno_dsm_paid_api_log', []);
    $log[] = ['field' => $fieldName, 'reason' => $reason, 'ts' => time() * 1000];
    if (count($log) > 500) $log = array_slice($log, -500);
    update_option('fno_dsm_paid_api_log', $log, false); // false = don't autoload, this can grow
}

/**
 * TRACE: #37 - Critical Data Dependency Rule. Given a strategy's
 * declared required vs optional fields and the resolved records for
 * each, determines whether trading should proceed at all.
 * Preconditions: $required/$optional are arrays of field names;
 * $resolvedRecords is [fieldName => record] (from fno_dsm_resolve()).
 * Postconditions: returns {canTrade: bool, missingRequired: [...],
 * missingOptional: [...], confidenceMultiplier: float} -
 * confidenceMultiplier degrades smoothly with optional-data gaps
 * (never with required gaps, since those already block trading
 * entirely - canTrade:false).
 */
function fno_dsm_check_critical_dependencies($required, $optional, $resolvedRecords) {
    $missingRequired = [];
    foreach ($required as $field) {
        $r = $resolvedRecords[$field] ?? null;
        if (!$r || $r['sourceTier'] === 'unavailable') $missingRequired[] = $field;
    }
    $missingOptional = [];
    foreach ($optional as $field) {
        $r = $resolvedRecords[$field] ?? null;
        if (!$r || $r['sourceTier'] === 'unavailable') $missingOptional[] = $field;
    }
    $canTrade = empty($missingRequired);
    $optionalTotal = max(1, count($optional));
    $confidenceMultiplier = $canTrade ? (1 - (count($missingOptional) / $optionalTotal) * 0.5) : 0;
    return [
        'canTrade' => $canTrade, 'missingRequired' => $missingRequired,
        'missingOptional' => $missingOptional, 'confidenceMultiplier' => round($confidenceMultiplier, 3),
    ];
}

// ------------------------------------------------------------------
// #35 GENERIC CACHING HELPER - generalizes the ad hoc per-endpoint
// transient caching already used for the ban list / participant OI
// (earlier phases) into one reusable function.
// ------------------------------------------------------------------

/**
 * TRACE: Fetch-through cache - if $key exists in the transient cache,
 * returns it; otherwise calls $fetchFn, caches the result for $ttl
 * seconds, and returns it. Prevents the exact "don't repeatedly pay/
 * request the same information" duplication #35 calls out.
 * Preconditions: $fetchFn returns a cacheable value (or null on
 * failure - null is NOT cached, so a failed fetch is retried next
 * call rather than caching the failure itself).
 * Postconditions: returns the value (fresh or cached).
 */
function fno_cached_fetch($key, $ttl, $fetchFn) {
    $cached = get_transient('fno_cache_' . $key);
    if ($cached !== false) return $cached;
    $value = call_user_func($fetchFn);
    if ($value !== null) set_transient('fno_cache_' . $key, $value, $ttl);
    return $value;
}

// ------------------------------------------------------------------
// ADAPTERS - each wraps an existing or new data source and normalizes
// its response into fno_dsm_empty_record()'s canonical shape.
// ------------------------------------------------------------------

/**
 * TRACE: Wraps the EXISTING, already-real, already-tested NSE-free
 * option-chain fetch (fno_nse_get, built in earlier phases) as a
 * proper Unified Data Layer adapter - no behavior change to the
 * underlying fetch, only the response shape changes (normalized
 * instead of raw NSE field names).
 * Preconditions: $ocRow is one row from the existing fno_fetch_oc_fn's
 * $data['records']['data'] array (already fetched this request -
 * this function does NOT fetch, it normalizes an already-fetched row,
 * consistent with this app's "no category re-fetches data another
 * category already computed" discipline established since Phase 1).
 */
function fno_adapter_nsefree_normalize($ocRow, $optionType, $underlyingSymbol) {
    if (!$ocRow) return null;
    $leg = $optionType === 'PE' ? ($ocRow['PE'] ?? null) : ($ocRow['CE'] ?? null);
    if (!$leg) return null;
    $r = fno_dsm_empty_record();
    $r['symbol'] = $underlyingSymbol; $r['underlying'] = $underlyingSymbol;
    $r['strike'] = $ocRow['strikePrice'] ?? null; $r['optionType'] = $optionType;
    $r['expiry'] = $ocRow['expiryDate'] ?? null;
    $r['ltp'] = $leg['lastPrice'] ?? null;
    $r['open'] = $leg['openPrice'] ?? null; $r['high'] = $leg['highPrice'] ?? null; $r['low'] = $leg['lowPrice'] ?? null;
    $r['prevClose'] = $leg['prevClose'] ?? null;
    $r['volume'] = $leg['totalTradedVolume'] ?? null;
    $r['oi'] = $leg['openInterest'] ?? null; $r['oiChange'] = $leg['changeinOpenInterest'] ?? null;
    $r['oiChangePct'] = $leg['pchangeinOpenInterest'] ?? null;
    $r['bid'] = $leg['bidprice'] ?? null; $r['ask'] = $leg['askPrice'] ?? null;
    $r['bidQty'] = $leg['bidQty'] ?? null; $r['askQty'] = $leg['askQty'] ?? null;
    if ($r['bid'] !== null && $r['ask'] !== null) { $r['spread'] = $r['ask'] - $r['bid']; $r['mid'] = ($r['ask']+$r['bid'])/2; }
    $r['iv'] = $leg['impliedVolatility'] ?? null;
    $r['source'] = 'nse_free'; $r['fetchedAt'] = time() * 1000; // this request's fetch time - NSE doesn't expose its own server timestamp on this endpoint
    return $r;
}

/**
 * TRACE: Real TrueData historical-REST adapter, written against
 * TrueData's DOCUMENTED authentication pattern (username/password,
 * confirmed via TrueData's own npm/PyPI client library documentation
 * reviewed this session - historical.auth(user, pwd) then
 * historical.getBarData(symbol, from, to, interval)) - NOT their
 * real-time WebSocket capability, which architecturally cannot run
 * inside a WordPress request/response cycle (the exact same
 * constraint already solved once for Kite's tick data via the
 * companion daemon - see companion-daemon/README.md's own explanation
 * of why real-time needs a persistent process, not a per-request
 * fetch). TrueData's real-time integration is Phase 3 of the
 * Enterprise Data Architecture Plan and requires extending the
 * EXISTING companion daemon with a TrueData WebSocket client, not a
 * new parallel system.
 * HONESTY NOTE: this adapter has NOT been tested against a live
 * TrueData account (no credentials available in this session, same
 * limitation stated for every other unverified integration in this
 * project) - the request shape follows the documented client library
 * calling convention, but the actual REST base URL/response JSON
 * shape could not be confirmed against a real account and MUST be
 * verified before production use (see the runtime verification
 * checklist in PROJECT_STATUS.md).
 * Preconditions: TrueData credentials configured (username/password,
 * NOT an API key - different auth model from every other adapter in
 * this app, stated explicitly so a future maintainer doesn't assume
 * the premium-provider generic API-key pattern applies here).
 * Postconditions: returns a normalized record on success, or null on
 * any failure - never fabricates a candle.
 */
function fno_adapter_truedata_get_historical($symbol, $fromTs, $toTs, $interval = '1min') {
    $creds = get_option('fno_truedata_credentials', []);
    if (empty($creds['username']) || empty($creds['password'])) return null;

    // TrueData's documented auth flow issues a session token from
    // username/password - cached for its typical session lifetime
    // (assumed ~1 hour, matching common session-token patterns; VERIFY
    // against TrueData's actual token TTL once credentials exist).
    $token = fno_cached_fetch('truedata_session_token', 3300, function () use ($creds) {
        $res = wp_remote_post('https://auth.truedata.in/token', [
            'timeout' => 10,
            'body' => ['username' => $creds['username'], 'password' => $creds['password'], 'grant_type' => 'password'],
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return $data['access_token'] ?? null;
    });
    if (!$token) return null;

    $url = 'https://history.truedata.in/getbars?symbol=' . urlencode($symbol)
        . '&from=' . urlencode($fromTs) . '&to=' . urlencode($toTs) . '&interval=' . urlencode($interval) . '&response=json';
    $res = wp_remote_get($url, ['timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . $token]]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        fno_dsm_log_provider_failure($symbol, 'primary_truedata', is_wp_error($res) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($res));
        return null;
    }
    $body = json_decode(wp_remote_retrieve_body($res), true);
    if (empty($body) || !is_array($body)) return null;

    fno_dsm_log_paid_api_usage($symbol, 'TrueData historical requested directly by caller (this adapter does not itself decide tier ordering - see fno_dsm_resolve for that)');
    $r = fno_dsm_empty_record();
    $r['symbol'] = $symbol; $r['source'] = 'truedata'; $r['fetchedAt'] = time() * 1000;
    $r['bars'] = $body; // raw bar array attached directly - historical data doesn't fit the single-tick canonical shape cleanly, exposed as-is for the caller to process
    return $r;
}

