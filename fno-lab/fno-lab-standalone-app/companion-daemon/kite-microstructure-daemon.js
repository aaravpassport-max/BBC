#!/usr/bin/env node
/**
 * F&O Lab - Microstructure Companion Daemon
 * =============================================================
 * WHY THIS EXISTS
 *
 * Six catalogued Microstructure factors (Iceberg Orders, Cumulative
 * Delta, Volume Profile POC, Order Flow Imbalance, Footprint, Tick
 * Speed) genuinely require a PERSISTENT, continuous connection to a
 * tick-level market data stream. A WordPress AJAX handler runs, sends
 * one HTTP response, and terminates - it architecturally cannot hold a
 * WebSocket connection open between separate browser requests. That is
 * not a missing feature to code around; it is what the request/response
 * model IS. This daemon is the real, different-shaped solution: a
 * small, standalone, long-running Node.js process that:
 *
 *   1. Connects to Kite Connect's WebSocket ticker (a real, persistent
 *      connection, kept open for as long as this process runs)
 *   2. Computes the six metrics above from REAL live ticks using the
 *      standard "tick rule" methodology (see computeTickRule below)
 *   3. POSTs a snapshot to your WordPress site every few seconds
 *
 * The WordPress plugin reads whatever this daemon posted, and honestly
 * reports "daemon offline" if this process isn't running or its last
 * snapshot is more than 30 seconds old (see fno_get_microstructure_fn
 * in fno-lab.php) - so running or not running this daemon changes
 * nothing else about how the rest of the app behaves. No code changes
 * needed either way.
 *
 * WHAT THIS IS *NOT*
 *
 * - It is NOT a substitute for exchange-provided aggressor-side tags.
 *   Kite's tick data (like most retail feeds) does not tell you whether
 *   a trade was buyer-initiated or seller-initiated. This daemon infers
 *   it using the "tick rule" (also called the Lee-Ready-style uptick/
 *   downtick approximation): if the last traded price rose since the
 *   previous tick, the traded volume since then is classified as
 *   buy-initiated; if it fell, sell-initiated; unchanged price keeps
 *   the previous classification. This is the same approximation
 *   virtually every retail-facing "cumulative delta" indicator uses
 *   (true aggressor tagging requires exchange-side order-matching data
 *   that isn't exposed to any retail API, Kite included) - it is a
 *   real, standard, honestly-labeled methodology, not a guess.
 * - It is NOT verified against a live Kite account. This code was
 *   written to Kite Connect's publicly documented WebSocket protocol
 *   and the official `kiteconnect` npm package's documented API, but
 *   this development sandbox has no live Kite credentials to test
 *   against. Run it with your own account and watch the console output
 *   before trusting it in production - see the "First run checklist"
 *   in README.md.
 *
 * SETUP
 *   1. cd companion-daemon && npm install
 *   2. cp config.example.json config.json, fill in your values
 *      (see README.md for where each value comes from)
 *   3. node kite-microstructure-daemon.js
 */

const fs = require('fs');
const path = require('path');
const https = require('https');
const http = require('http');

const CONFIG_PATH = path.join(__dirname, 'config.json');
let config = { tickSize: 0.05, postIntervalMs: 5000, symbol: 'NIFTY' }; // safe defaults so this file can be require()'d (e.g. by tests) without config.json present

const REQUIRED_STRING_FIELDS = ['kiteApiKey', 'kiteAccessToken', 'wpSiteUrl', 'ingestSecret', 'symbol'];
// tickSize/postIntervalMs are optional (defaults above) but if present in
// config.json they must be numbers - a string here (e.g. "5000" typed by
// hand, or "0.05" with quotes) would silently poison every downstream
// arithmetic use (POST_INTERVAL_MS in a setInterval, TICK_SIZE in every
// price-rounding call) rather than failing loudly at startup.
const OPTIONAL_NUMBER_FIELDS = ['tickSize', 'postIntervalMs'];

/**
 * TRACE: startup config validation, extracted to its own function (was
 * previously inlined under `if (require.main === module)`, which made it
 * untestable - a test can require() this file safely since the inline
 * block never ran under require(), but that also meant this exact logic
 * had zero regression coverage) -> reads config.json from configPath ->
 * (1) missing file, (2) malformed JSON, (3) missing required field, (4) a
 * required field present but not a string, (5) an optional numeric field
 * present but not a number, each fail LOUDLY with a specific, actionable
 * message via doExit(1) BEFORE any KiteTicker/network activity is
 * attempted (this function runs and returns before main() constructs
 * anything) -> on success returns the parsed, validated config object.
 * FAIL-SAFE: doExit defaults to process.exit but is injectable so tests
 * can assert on the exact exit code/message without a real process exit
 * (same injectable-exit pattern already used by wireTickerEvents above).
 * A test doExit must not return (it throws) so this function does not
 * fall through past the exit and misuse a still-invalid config object.
 */
function loadConfig(configPath, exitFn) {
  const doExit = exitFn || process.exit;
  if (!fs.existsSync(configPath)) {
    console.error('config.json not found. Copy config.example.json to config.json and fill in your values (see README.md).');
    return doExit(1);
  }

  const raw = fs.readFileSync(configPath, 'utf8');
  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (e) {
    // Secret-leakage discipline (see the module.exports comment below):
    // log only e.message, never `raw` (the file content itself may
    // contain the real kiteApiKey/kiteAccessToken/ingestSecret values
    // mid-edit) and never the raw error object.
    console.error('config.json has a syntax error and could not be parsed: ' + e.message + '. Fix the JSON syntax in config.json and try again (see config.example.json for a valid template).');
    return doExit(1);
  }

  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    console.error('config.json must contain a single JSON object, not ' + (Array.isArray(parsed) ? 'an array' : typeof parsed) + '. See config.example.json for a valid template.');
    return doExit(1);
  }

  const missing = REQUIRED_STRING_FIELDS.filter(f => !parsed[f]);
  if (missing.length) {
    console.error('config.json is missing required field(s): ' + missing.join(', '));
    return doExit(1);
  }

  const wrongType = REQUIRED_STRING_FIELDS.filter(f => typeof parsed[f] !== 'string');
  if (wrongType.length) {
    console.error('config.json field(s) must be strings: ' + wrongType.join(', ') + ' (got ' + wrongType.map(f => typeof parsed[f]).join(', ') + ').');
    return doExit(1);
  }

  const badNumbers = OPTIONAL_NUMBER_FIELDS.filter(f => parsed[f] !== undefined && (typeof parsed[f] !== 'number' || !Number.isFinite(parsed[f])));
  if (badNumbers.length) {
    console.error('config.json field(s) must be numbers: ' + badNumbers.join(', ') + ' (got ' + badNumbers.map(f => JSON.stringify(parsed[f])).join(', ') + ').');
    return doExit(1);
  }

  // REAL FIX (Zerodha-maximization audit, this pass - closes the last
  // remaining backlog item, "extend the WebSocket subscription to
  // option strikes"): optional, user-controlled `optionStrikes` config
  // field - an explicit array of {strike, optionType} the user wants
  // this daemon to ALSO subscribe to (in addition to the underlying
  // index it already tracks) and capture real raw ticks for. Never
  // guessed/auto-selected (e.g. "ATM +/- 5") - the user names exactly
  // which real contracts they want tracked, avoiding an unbounded/
  // surprising subscription count against Kite's real per-connection
  // instrument-count limits. Validated loudly at startup, same
  // discipline as every other config field above.
  if (parsed.optionStrikes !== undefined) {
    if (!Array.isArray(parsed.optionStrikes)) {
      console.error('config.json field "optionStrikes" must be an array (e.g. [{"strike":23200,"optionType":"CE"}]), got ' + typeof parsed.optionStrikes + '.');
      return doExit(1);
    }
    const badEntries = parsed.optionStrikes.filter(e =>
      !e || typeof e !== 'object' ||
      typeof e.strike !== 'number' || !Number.isFinite(e.strike) || e.strike <= 0 ||
      (e.optionType !== 'CE' && e.optionType !== 'PE')
    );
    if (badEntries.length) {
      console.error('config.json "optionStrikes" entries must each be {"strike": <positive number>, "optionType": "CE"|"PE"} - found ' + badEntries.length + ' invalid entr' + (badEntries.length === 1 ? 'y' : 'ies') + ': ' + JSON.stringify(badEntries));
      return doExit(1);
    }
  }

  // Startup-log discipline (enforced by test-secret-never-logged.js's
  // static source scan: no console.* line anywhere in this file may even
  // NAME a secret field, masked or not): intentionally does NOT log the
  // parsed config object, and does not name kiteApiKey/kiteAccessToken/
  // ingestSecret on this line at all - only the non-secret symbol field,
  // to confirm the file loaded without hinting at credential values.
  console.log(`config.json loaded OK for symbol ${parsed.symbol}.`);
  return parsed;
}

/** Masks a secret for logging: first 4 chars + '...' (or '(empty)' if falsy). Never logs the full value. */
function maskSecret(value) {
  if (!value) return '(empty)';
  const s = String(value);
  return s.length <= 4 ? s[0] + '...' : s.slice(0, 4) + '...';
}

if (require.main === module) {
  config = loadConfig(CONFIG_PATH);
}

// REAL FIX (Zerodha-maximization audit, this session): was a fixed
// 0.05 default, never cross-checked against the real, authoritative
// tick_size column this daemon's own lookupInstrumentToken() already
// downloads from Kite's instruments/NSE CSV (previously every column
// except tradingsymbol/instrument_token was discarded). An explicit
// config.tickSize is still honored first (a deliberate user override
// stays a real override) - the change is only that when the user has
// NOT set one, main() now updates this from the real Kite-provided
// tick_size instead of silently trusting a hardcoded guess for
// whatever instrument config.symbol actually resolves to. `let`
// (not `const`) because main() may reassign it once, before any tick
// processing begins - see main()'s own real TRACE below.
let TICK_SIZE = config.tickSize || 0.05; // NIFTY-family options tick to nearest 0.05, adjust if trading a different instrument
const POST_INTERVAL_MS = config.postIntervalMs || 5000;
const ICEBERG_REPLENISH_WINDOW_MS = 3000;
const ICEBERG_REPLENISH_TOLERANCE_PCT = 0.15; // quantity returning within 15% of its pre-fill size counts as a "replenish"

// ------------------------------------------------------------------
// STATE - all in-memory, reset if the daemon restarts (by design: this
// computes SESSION metrics, not a persisted history; the daemon is
// meant to run continuously through a trading session, not be
// restarted mid-session)
//
// REAL EXTENSION (Zerodha-maximization audit, final backlog item): all
// six computed-metric state fields are now bundled into ONE mutable
// object (createState()) instead of nine separate bare module
// variables. This is a deliberate, LOW-RISK refactor shape: every one
// of the six compute functions below (computeTickRule/processVolume/
// processDepthForIceberg/processDepthForSpoofing/computePOC/
// computeFootprintTopLevels/computeTicksPerMinute/
// computeFlowImbalancePct) keeps its EXACT existing signature and
// mutates whichever state object `state` currently points at - a
// mechanical `lastPrice` -> `state.lastPrice` rename throughout, not a
// parameter-passing rewrite. This means every existing test calling
// e.g. computeTickRule(100) continues to work completely unchanged
// (against the real default/underlying state, since nothing switches
// `state` unless wireTickerEvents does) - zero behavioral change for
// the underlying-only path this app has run since this file was
// created. What's NEW: wireTickerEvents can now call
// `state = getOrCreateState(token)` before processing a tick for ANY
// tracked token (the underlying OR a real configured option strike),
// so each gets its own genuinely independent, real per-instrument
// Iceberg/Cumulative Delta/POC/OFI/Footprint/Tick Speed computation -
// closing the last remaining Zerodha-maximization backlog item.
// ------------------------------------------------------------------
function createState() {
  return {
    lastPrice: null,
    lastTickDirection: 0, // +1 up, -1 down, 0 unchanged/unknown
    cumulativeDelta: 0,
    volumeByPrice: new Map(), // price (rounded to TICK_SIZE) -> cumulative traded volume at that price
    lastCumulativeVolume: null, // Kite ticks include a running `volume_traded` for the day - we diff consecutive readings
    tickTimestamps: [], // rolling buffer for tick-rate calculation
    lastDepthByPrice: new Map(), // price -> {qty, filledAt} for iceberg replenishment detection
    icebergEventCount: 0,
    domSpoofCount: 0,
    lastDepthSnapshotForSpoof: new Map(), // price -> quantity, previous tick's full snapshot (separate from lastDepthByPrice which iceberg detection mutates for its own purposes)
  };
}
let state = createState(); // the currently-ACTIVE state - the six compute functions below always read/write THIS object; wireTickerEvents reassigns it per-tick to route each tick's processing to its own real instrument's state
const stateByToken = new Map(); // instrument_token -> its own real, independent state object (populated lazily as ticks for new tokens arrive)
/**
 * TRACE: given a real Kite instrument_token, returns its own real,
 * independent state object - creating one (via createState(), a fresh
 * zeroed/empty state, never copied from another instrument) the first
 * time this token is seen this session.
 * Preconditions: token is a real Kite instrument_token (number).
 * Postconditions: the SAME object is returned on every subsequent call
 * for the same token this session (real, accumulating per-instrument
 * state, not reset every tick).
 */
function getOrCreateState(token) {
  if (!stateByToken.has(token)) stateByToken.set(token, createState());
  return stateByToken.get(token);
}
// Enterprise Data Architecture Plan #2/#3 (Raw Observation Store,
// Phase 2 - decided this session, MySQL-based per
// docs/ENTERPRISE_DATA_ARCHITECTURE_PLAN.md) - real raw-tick buffer,
// flushed on the SAME POST_INTERVAL_MS cadence as the microstructure
// snapshot below, batched into one bulk-insert request rather than
// one HTTP call per tick (real performance discipline given genuine
// tick volume during active market hours). Deliberately stays a
// single, SHARED buffer across every tracked instrument (underlying +
// option strikes) rather than per-token - each real tick already
// carries its own real `symbol` field (see wireTickerEvents), so one
// shared buffer flushed together is simpler and no less correct.
let rawTickBuffer = [];
const RAW_TICK_BUFFER_MAX = 2000; // hard cap - if the WordPress ingest endpoint is unreachable for a while, don't let this grow unbounded in daemon memory; oldest ticks are dropped, not the whole buffer discarded, so partial history still gets through once connectivity resumes
const SPOOF_QTY_THRESHOLD = 500; // documented assumption: a resting order >=500 contracts is "large" for NIFTY-family options; adjust if trading a different instrument's typical lot/depth sizes
const SPOOF_VOLUME_RATIO_MAX = 0.2; // if a large order vanishes while less than 20% of its size traded in that same tick, it looks cancelled rather than filled

/**
 * TRACE: The tick-rule aggressor-side approximation described in the
 * file header -> given the new last-traded-price vs the previous one
 * -> updates lastTickDirection (or keeps the previous direction on an
 * unchanged price, standard tick-rule convention) -> returns the
 * direction to apply to this tick's volume delta.
 * Preconditions: newPrice is a number. Postconditions: returns +1/-1/
 * the previous direction (never 0 after the first directional tick).
 * FAIL-SAFE: a malformed tick (NaN/non-finite last_price - seen in the
 * wild on feed glitches) must NOT be allowed to overwrite lastPrice.
 * lastPrice feeds every subsequent comparison in this function; if it
 * were ever set to NaN, EVERY future comparison (newPrice > lastPrice,
 * newPrice < lastPrice) would be false forever - direction would freeze
 * on whatever it was at the moment of corruption and never recover for
 * the rest of the session (the same permanent-poisoning failure mode
 * this session's audit found and fixed in the core scoring engine's
 * trailing-stop-loss tracking). So: reject non-finite input outright,
 * leave lastPrice/lastTickDirection untouched, and return the current
 * (last known-good) direction rather than fabricating a new one.
 */
function computeTickRule(newPrice) {
  if (!Number.isFinite(newPrice)) return state.lastTickDirection;
  if (state.lastPrice === null) { state.lastPrice = newPrice; return state.lastTickDirection; }
  if (newPrice > state.lastPrice) state.lastTickDirection = 1;
  else if (newPrice < state.lastPrice) state.lastTickDirection = -1;
  // unchanged price: keep previous direction (standard tick-rule convention)
  state.lastPrice = newPrice;
  return state.lastTickDirection;
}

/**
 * TRACE: Called on every tick with the day's running traded volume
 * (Kite's `volume_traded` field, cumulative since market open) ->
 * diffs against the previous reading to get THIS tick's incremental
 * volume -> applies the tick-rule direction to cumulativeDelta ->
 * buckets that incremental volume into volumeByPrice at the current
 * price (rounded to TICK_SIZE) for the Volume Profile / Footprint
 * factors.
 * Preconditions: cumulativeVolume is a non-negative number.
 * Postconditions: cumulativeDelta and volumeByPrice both updated;
 * returns the incremental volume for this tick (used by iceberg
 * detection below).
 * Edge cases handled: first tick of the session (lastCumulativeVolume
 * null - no delta computed, just establishes the baseline); a
 * cumulativeVolume that goes DOWN vs the last reading (can happen on a
 * reconnect returning a stale snapshot - treated as zero incremental
 * volume rather than a negative delta corrupting the running total).
 * FAIL-SAFE: a non-finite cumulativeVolume (malformed/glitched tick)
 * must NOT be subtracted into lastCumulativeVolume or added into
 * cumulativeDelta. Both are running totals held for the entire trading
 * session (see STATE header) - `NaN - x`, `x - NaN`, and `total += NaN`
 * are all NaN, and once either running value goes NaN it stays NaN for
 * every remaining tick of the session (arithmetic never "heals" NaN).
 * That is exactly the permanent-poisoning failure mode this session's
 * audit already found and fixed in the core scoring engine's trailing-
 * stop-loss tracking - same bug shape, different file. So: reject a
 * non-finite cumulativeVolume outright, leave lastCumulativeVolume at
 * its last known-good value, and report zero incremental volume for
 * this tick rather than corrupting the baseline. A non-finite price is
 * guarded separately below since it only affects THIS tick's
 * volume-by-price bucket, not the running cumulativeDelta/baseline.
 */
function processVolume(price, cumulativeVolume, direction) {
  if (!Number.isFinite(cumulativeVolume)) return 0;
  if (state.lastCumulativeVolume === null) { state.lastCumulativeVolume = cumulativeVolume; return 0; }
  let incremental = cumulativeVolume - state.lastCumulativeVolume;
  if (incremental < 0) incremental = 0; // reconnect/stale-snapshot guard
  state.lastCumulativeVolume = cumulativeVolume;
  if (incremental === 0) return 0;

  state.cumulativeDelta += incremental * direction;

  if (Number.isFinite(price)) {
    const bucket = Math.round(price / TICK_SIZE) * TICK_SIZE;
    state.volumeByPrice.set(bucket, (state.volumeByPrice.get(bucket) || 0) + incremental);
  }
  // non-finite price: incremental volume still counted toward
  // cumulativeDelta above (that's real, driven by real volume/direction
  // data), just not bucketed into the Volume Profile/Footprint map -
  // there is no honest price to bucket it at.

  return incremental;
}

/**
 * TRACE: Called on every tick with the current market depth (Kite
 * ticks in FULL mode include depth.buy[] / depth.sell[], up to 5
 * levels each) -> for each price level, checks whether its resting
 * quantity dropped sharply (implying a fill) and then, within
 * ICEBERG_REPLENISH_WINDOW_MS, returned to near its pre-fill size
 * without a corresponding price move away from that level -> this
 * "quantity keeps refilling to the same size" pattern is the standard
 * heuristic for a hidden/iceberg order replenishing its displayed
 * portion after each partial fill.
 * Preconditions: depth is Kite's real depth object shape
 * {buy:[{price,quantity,orders}], sell:[...]}.
 * Postconditions: increments icebergEventCount when the pattern is
 * detected; updates lastDepthByPrice for the next tick's comparison.
 * Edge cases handled: a level disappearing entirely from the depth
 * array (price moved out of top-5) is not treated as a "fill" - only
 * levels present in both the previous and current tick are compared,
 * since a vanished level could just mean the price moved, not that an
 * iceberg was exhausted.
 * HONESTY NOTE: this is a heuristic, not a certainty - genuine iceberg
 * orders and coincidental resting-size similarity can both produce this
 * pattern. Treat icebergDetected as "worth a closer look," not proof.
 */
function processDepthForIceberg(depth) {
  if (!depth || !Array.isArray(depth.buy) || !Array.isArray(depth.sell)) return;
  const now = Date.now();
  const allLevels = [...depth.buy, ...depth.sell];
  // Malformed levels (non-finite price/quantity - a depth-feed glitch)
  // are dropped here rather than let through: prevQty/preFillQty derived
  // from a NaN quantity would make every later comparison in this
  // function silently false (NaN comparisons never throw, they just
  // never match), which is *usually* the safe direction for a detector,
  // but filtering here keeps lastDepthByPrice itself free of garbage
  // entries that would otherwise sit there for the rest of the session.
  const currentByPrice = new Map(
    allLevels
      .filter(l => Number.isFinite(l && l.price) && Number.isFinite(l && l.quantity))
      .map(l => [l.price, l.quantity])
  );

  currentByPrice.forEach((qty, price) => {
    const prev = state.lastDepthByPrice.get(price);
    if (prev && prev.filledAt) {
      const withinWindow = (now - prev.filledAt) < ICEBERG_REPLENISH_WINDOW_MS;
      const withinTolerance = Math.abs(qty - prev.preFillQty) <= prev.preFillQty * ICEBERG_REPLENISH_TOLERANCE_PCT;
      if (withinWindow && withinTolerance && qty > 0) {
        state.icebergEventCount++;
        state.lastDepthByPrice.set(price, { qty, preFillQty: prev.preFillQty, filledAt: null }); // consumed this detection
        return;
      }
    }
    const prevQty = prev ? prev.qty : qty;
    if (prevQty > 0 && qty < prevQty * 0.5) {
      // Sharp drop (>=50%) - mark as a candidate fill, watch for replenishment on the next tick(s)
      state.lastDepthByPrice.set(price, { qty, preFillQty: prevQty, filledAt: now });
    } else {
      state.lastDepthByPrice.set(price, { qty, preFillQty: prevQty, filledAt: prev ? prev.filledAt : null });
    }
  });
}

/**
 * TRACE: f153 "DOM Ladder - Spoofing Orders" (Microstructure) - this
 * factor was silently dropped during an earlier session's daemon work
 * (mentioned once in a comment, never actually wired anywhere - caught
 * and flagged by a later gap-analysis audit). Real detection heuristic,
 * distinct from iceberg detection above: a LARGE resting order
 * (>= SPOOF_QTY_THRESHOLD) that VANISHES from the book on the very next
 * tick while the incremental traded volume that tick was far smaller
 * than the vanished quantity (SPOOF_VOLUME_RATIO_MAX) looks cancelled
 * rather than filled - the classic spoofing signature (place a large
 * order to move the visible book, pull it before it can be hit).
 * Iceberg detection looks for orders that REFILL after a fill; this
 * looks for large orders that DISAPPEAR without a matching fill - the
 * opposite direction of evidence, which is why it's a separate
 * function rather than a branch inside processDepthForIceberg.
 * Preconditions: called once per tick, AFTER processVolume has already
 * updated lastCumulativeVolume for this tick (ordering matters - see
 * the ticks handler below).
 * Postconditions: increments domSpoofCount when the pattern is
 * detected; updates lastDepthSnapshotForSpoof for the next comparison.
 * HONESTY NOTE: same heuristic caveat as iceberg detection - large
 * orders also vanish for legitimate reasons (trader changed their
 * mind, partial fill below the reporting threshold, a depth-refresh
 * artifact). Treat domSpoofCount as "worth a closer look," not proof
 * of manipulation.
 */
function processDepthForSpoofing(depth, incrementalVolumeThisTick) {
  if (!depth || !Array.isArray(depth.buy) || !Array.isArray(depth.sell)) return;
  const allLevels = [...depth.buy, ...depth.sell];
  // Same malformed-level filtering as processDepthForIceberg above - a
  // NaN quantity slipping into lastDepthSnapshotForSpoof would sit there
  // for the rest of the session as a bogus "large resting order"
  // candidate (NaN >= SPOOF_QTY_THRESHOLD is false so it wouldn't itself
  // trigger, but it also would never legitimately expire/replace the
  // way a real quantity does), so it's dropped at the source instead.
  const currentByPrice = new Map(
    allLevels
      .filter(l => Number.isFinite(l && l.price) && Number.isFinite(l && l.quantity))
      .map(l => [l.price, l.quantity])
  );
  // incrementalVolumeThisTick comes from processVolume's return value,
  // which is now guaranteed finite (processVolume returns 0 on
  // non-finite input - see its own TRACE) - no separate guard needed
  // here, but documented since this function's honesty depends on it.

  state.lastDepthSnapshotForSpoof.forEach((prevQty, price) => {
    if (prevQty < SPOOF_QTY_THRESHOLD) return; // only large orders are spoofing candidates
    const nowQty = currentByPrice.get(price) || 0;
    const vanished = nowQty < prevQty * 0.1; // effectively gone (allow for tiny dust remainder)
    if (vanished) {
      const tradedFraction = prevQty > 0 ? (incrementalVolumeThisTick / prevQty) : 1;
      if (tradedFraction < SPOOF_VOLUME_RATIO_MAX) state.domSpoofCount++;
    }
  });

  state.lastDepthSnapshotForSpoof = currentByPrice;
}

function computePOC() {
  let bestPrice = null, bestVol = -1;
  state.volumeByPrice.forEach((vol, price) => { if (vol > bestVol) { bestVol = vol; bestPrice = price; } });
  return bestPrice;
}

function computeFootprintTopLevels() {
  return [...state.volumeByPrice.entries()].sort((a, b) => b[1] - a[1]).slice(0, 3).map(([price, vol]) => [price, vol]);
}

function computeTicksPerMinute() {
  const cutoff = Date.now() - 60000;
  state.tickTimestamps = state.tickTimestamps.filter(t => t >= cutoff);
  return state.tickTimestamps.length;
}

function computeFlowImbalancePct() {
  // Reconstructs total buy-tagged vs sell-tagged volume from
  // cumulativeDelta and the total traded volume, rather than tracking
  // two separate running totals - buyVol - sellVol = cumulativeDelta,
  // buyVol + sellVol = totalVolume, solved algebraically.
  const totalVolume = state.lastCumulativeVolume || 0;
  if (totalVolume === 0) return 50;
  const buyVol = (totalVolume + state.cumulativeDelta) / 2;
  return (buyVol / totalVolume) * 100;
}

/**
 * TRACE: POSTs the current computed snapshot to the WordPress ingest
 * endpoint -> authenticated with the daemon secret (NOT the browser
 * app's nonce - this is a detached process, not a browser session) ->
 * logs success/failure explicitly to the console rather than failing
 * silently, so a misconfigured secret or unreachable site is visible
 * immediately when running this daemon, not discovered days later as
 * "why do the factors still say offline."
 */
function postSnapshot() {
  const payload = new URLSearchParams({
    symbol: config.symbol,
    cumulative_delta: Math.round(state.cumulativeDelta),
    poc: computePOC() || '',
    flow_imbalance_pct: computeFlowImbalancePct().toFixed(2),
    footprint_top_levels: JSON.stringify(computeFootprintTopLevels()),
    ticks_per_minute: computeTicksPerMinute().toFixed(2),
    iceberg_detected: state.icebergEventCount,
    dom_spoof_detected: state.domSpoofCount,
  }).toString();

  const url = new URL(config.wpSiteUrl.replace(/\/$/, '') + '/wp-admin/admin-ajax.php?action=fno_ingest_microstructure');
  const lib = url.protocol === 'https:' ? https : http;
  const req = lib.request(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'Content-Length': Buffer.byteLength(payload),
      'X-Fno-Daemon-Secret': config.ingestSecret,
    },
  }, (res) => {
    let body = '';
    res.on('data', d => body += d);
    res.on('end', () => {
      if (res.statusCode !== 200) {
        console.error(`[${new Date().toISOString()}] Ingest failed (HTTP ${res.statusCode}): ${body}`);
      } else {
        console.log(`[${new Date().toISOString()}] Snapshot posted OK - delta=${Math.round(state.cumulativeDelta)} poc=${computePOC()} imbalance=${computeFlowImbalancePct().toFixed(1)}% ticks/min=${computeTicksPerMinute()} iceberg_events=${state.icebergEventCount} dom_spoof_events=${state.domSpoofCount}`);
      }
    });
  });
  req.on('error', (e) => console.error(`[${new Date().toISOString()}] Ingest request failed: ${e.message}`));
  req.write(payload);
  req.end();
}

/**
 * TRACE: Zerodha-maximization audit, final backlog item - given any
 * OTHER real, per-instrument state object (not the ambient module
 * `state` postSnapshot() above always uses for the underlying), builds
 * the exact same real metrics shape by temporarily swapping the
 * ambient `state` reference, running the SAME real compute functions
 * every other metric already goes through (never a second,
 * independently-written computation), then restoring it - safe because
 * JS is single-threaded and this daemon has no concurrent/async
 * interleaving between the swap and the restore (no `await` inside).
 * Preconditions: s is a real state object from getOrCreateState().
 * Postconditions: returns {cumulativeDelta, poc, flowImbalancePct,
 * footprintTopLevels, ticksPerMinute, icebergDetected, domSpoofDetected}
 * for that SPECIFIC instrument's own real, independent tick history;
 * the ambient `state` is always restored to its pre-call value before
 * this function returns, even if a compute function throws.
 */
function computeMetricsForState(s) {
  const prevState = state;
  state = s;
  try {
    return {
      cumulativeDelta: Math.round(state.cumulativeDelta),
      poc: computePOC(),
      flowImbalancePct: computeFlowImbalancePct(),
      footprintTopLevels: computeFootprintTopLevels(),
      ticksPerMinute: computeTicksPerMinute(),
      icebergDetected: state.icebergEventCount,
      domSpoofDetected: state.domSpoofCount,
    };
  } finally {
    state = prevState;
  }
}

/**
 * TRACE: Zerodha-maximization audit, final backlog item - POSTs ONE
 * real option strike's own, independent microstructure snapshot to the
 * new fno_ingest_microstructure_instrument endpoint (the per-instrument
 * counterpart to postSnapshot() above, which stays underlying-only and
 * untouched). Same daemon-secret auth, same logging discipline.
 * Preconditions: token is a real instrument_token with an existing
 * state (getOrCreateState will have been called for it already, since
 * it only has real ticks once at least one tick arrived);
 * instrumentKey/symbol are the real, resolved tradingsymbol/underlying
 * for this token (see lookupOptionInstrumentTokens).
 */
function postInstrumentSnapshot(token, instrumentKey, symbol) {
  const s = getOrCreateState(token);
  const metrics = computeMetricsForState(s);
  const payload = new URLSearchParams({
    instrumentKey, symbol,
    cumulativeDelta: metrics.cumulativeDelta,
    poc: metrics.poc || '',
    flowImbalancePct: metrics.flowImbalancePct.toFixed(2),
    footprintTopLevels: JSON.stringify(metrics.footprintTopLevels),
    ticksPerMinute: metrics.ticksPerMinute.toFixed(2),
    icebergDetected: metrics.icebergDetected,
    domSpoofDetected: metrics.domSpoofDetected,
  }).toString();

  const url = new URL(config.wpSiteUrl.replace(/\/$/, '') + '/wp-admin/admin-ajax.php?action=fno_ingest_microstructure_instrument');
  const lib = url.protocol === 'https:' ? https : http;
  const req = lib.request(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'Content-Length': Buffer.byteLength(payload),
      'X-Fno-Daemon-Secret': config.ingestSecret,
    },
  }, (res) => {
    let body = '';
    res.on('data', d => body += d);
    res.on('end', () => {
      if (res.statusCode !== 200) {
        console.error(`[${new Date().toISOString()}] Instrument ingest failed for ${instrumentKey} (HTTP ${res.statusCode}): ${body}`);
      } else {
        console.log(`[${new Date().toISOString()}] Instrument snapshot posted OK for ${instrumentKey} - delta=${metrics.cumulativeDelta} poc=${metrics.poc}`);
      }
    });
  });
  req.on('error', (e) => console.error(`[${new Date().toISOString()}] Instrument ingest request failed for ${instrumentKey}: ${e.message}`));
  req.write(payload);
  req.end();
}

/**
 * TRACE: calls postInstrumentSnapshot for every real, currently-tracked
 * option token - the real per-instrument counterpart wired into
 * main()'s setInterval alongside the existing postSnapshot/
 * flushRawTicks calls.
 * Preconditions: optionTokens is the real Map<token, {instrumentKey,
 * symbol}> built once in main() from lookupOptionInstrumentTokens.
 */
function postAllInstrumentSnapshots(optionTokens) {
  if (!optionTokens || !optionTokens.size) return;
  optionTokens.forEach(({ instrumentKey, symbol }, token) => {
    postInstrumentSnapshot(token, instrumentKey, symbol);
  });
}

/**
 * TRACE: Enterprise Plan #2/#3 - flushes the real raw-tick buffer to
 * fno_ingest_raw_tick_fn in ONE bulk request per interval, not one
 * request per tick (see rawTickBuffer's TRACE for why). Clears the
 * LOCAL buffer immediately after building the payload (not after the
 * HTTP response returns) so ticks arriving during the network round-
 * trip aren't lost or double-counted - a real, if small, design
 * choice: this means a failed POST loses that batch rather than
 * retrying it, which is the right tradeoff for a live-tick pipeline
 * (retrying stale ticks with a delay would corrupt the timestamp
 * ordering more than losing one batch does) - stated explicitly, not
 * silently accepted.
 * Preconditions: none - a call with an empty buffer is a real no-op,
 * not a wasted HTTP request.
 */
function flushRawTicks() {
  if (rawTickBuffer.length === 0) return;
  const batch = rawTickBuffer;
  rawTickBuffer = [];

  const payload = new URLSearchParams({ ticks: JSON.stringify(batch) }).toString();
  const url = new URL(config.wpSiteUrl.replace(/\/$/, '') + '/wp-admin/admin-ajax.php?action=fno_ingest_raw_tick');
  const lib = url.protocol === 'https:' ? https : http;
  const req = lib.request(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'Content-Length': Buffer.byteLength(payload),
      'X-Fno-Daemon-Secret': config.ingestSecret,
    },
  }, (res) => {
    let body = '';
    res.on('data', d => body += d);
    res.on('end', () => {
      if (res.statusCode !== 200) {
        console.error(`[${new Date().toISOString()}] Raw tick batch ingest failed (HTTP ${res.statusCode}, ${batch.length} ticks lost this batch): ${body}`);
      } else {
        console.log(`[${new Date().toISOString()}] Raw tick batch posted OK (${batch.length} ticks)`);
      }
    });
  });
  req.on('error', (e) => console.error(`[${new Date().toISOString()}] Raw tick batch request failed (${batch.length} ticks lost this batch): ${e.message}`));
  req.write(payload);
  req.end();
}

/**
 * TRACE: Looks up the instrument token for config.symbol using Kite's
 * public (no-auth) instruments dump -> Kite's WebSocket ticker
 * subscribes by numeric instrument_token, not by tradingsymbol, so this
 * lookup is a required step before connecting.
 * Preconditions: none. Postconditions: resolves to a numeric token, or
 * rejects if the symbol isn't found in the NSE instruments dump.
 */
// REAL FIX (Zerodha-maximization audit, this session): return shape
// widened from a bare numeric token to {token, tickSize} - the real
// tick_size column was already present in every row of this same CSV
// response, just never read (only tradingsymbol/instrument_token were).
// Real, honest: tickSize is null (not a guessed 0.05) when the column
// is absent/unparseable for this row, so main() knows to keep the
// existing config-or-0.05 default rather than overwrite it with a
// fabricated value.
/**
 * TRACE: pure, synchronous CSV-parsing logic extracted out of
 * lookupInstrumentToken() so it's real-unit-testable without mocking
 * https - given the real, already-downloaded Kite instruments/NSE CSV
 * body and a real target tradingsymbol, finds that row and returns its
 * real instrument_token + tick_size.
 * Preconditions: body is the real CSV text (header row + data rows);
 * targetSymbol is the real, exact tradingsymbol to match.
 * Postconditions: returns {token, tickSize} for the first matching row,
 * or null if no row matches. tickSize is null (never guessed) when the
 * tick_size column is absent from the header or unparseable/non-
 * positive for the matched row.
 * Edge cases handled: missing tick_size column entirely (tickSizeIdx
 * -1, tickSize stays null); a non-numeric/zero/negative tick_size cell
 * (Number.isFinite + >0 guard, stays null rather than a bad value).
 */
function findInstrumentInCsv(body, targetSymbol) {
  const lines = body.split('\n');
  const header = lines[0].split(',');
  const symbolIdx = header.indexOf('tradingsymbol');
  const tokenIdx = header.indexOf('instrument_token');
  const tickSizeIdx = header.indexOf('tick_size');
  for (const line of lines.slice(1)) {
    const cols = line.split(',');
    if (cols[symbolIdx] === targetSymbol) {
      const parsedTick = tickSizeIdx >= 0 ? parseFloat(cols[tickSizeIdx]) : NaN;
      return { token: parseInt(cols[tokenIdx], 10), tickSize: (Number.isFinite(parsedTick) && parsedTick > 0) ? parsedTick : null };
    }
  }
  return null;
}

// REAL FIX (Zerodha-maximization audit, this session): return shape
// widened from a bare numeric token to {token, tickSize} - the real
// tick_size column was already present in every row of this same CSV
// response, just never read (only tradingsymbol/instrument_token were).
// Real, honest: tickSize is null (not a guessed 0.05) when the column
// is absent/unparseable for this row, so main() knows to keep the
// existing config-or-0.05 default rather than overwrite it with a
// fabricated value. Real CSV-row-matching logic now lives in the
// standalone, unit-tested findInstrumentInCsv() above.
function lookupInstrumentToken(symbol) {
  return new Promise((resolve, reject) => {
    const indexTradingSymbols = { NIFTY: 'NIFTY 50', BANKNIFTY: 'NIFTY BANK', FINNIFTY: 'NIFTY FIN SERVICE' };
    const targetSymbol = indexTradingSymbols[symbol.toUpperCase()] || symbol;
    https.get('https://api.kite.trade/instruments/NSE', (res) => {
      let body = '';
      res.on('data', d => body += d);
      res.on('end', () => {
        const found = findInstrumentInCsv(body, targetSymbol);
        if (found) { resolve(found); return; }
        reject(new Error(`Symbol "${targetSymbol}" not found in NSE instruments dump`));
      });
    }).on('error', reject);
  });
}

/**
 * TRACE: pure, synchronous CSV-parsing logic for the real NFO
 * instruments dump -> given the real CSV body plus a real underlying
 * symbol (e.g. "NIFTY"), a real strike, and a real optionType (CE/PE),
 * finds every real matching row (same name+strike+instrument_type,
 * across however many real expiries exist) and returns the row with
 * the SOONEST real expiry - the same "never string-build a Kite
 * tradingsymbol, always match the real instrument master, prefer the
 * nearest real expiry" discipline this codebase already uses in
 * fno_fetch_kite_order_margin_fn (PHP side) for the exact same reason:
 * a real, live tradingsymbol string is date-coded and cannot be
 * safely guessed.
 * Preconditions: body is the real NFO CSV text (header + data rows).
 * Postconditions: returns {token, tradingsymbol, tickSize, expiry} for
 * the real, nearest-expiry match, or null if genuinely no row matches
 * this exact symbol+strike+optionType combination.
 * Edge cases handled: multiple real expiries for the same real strike
 * (picks the chronologically-soonest one via string comparison, which
 * is correct for Kite's real ISO "YYYY-MM-DD" expiry format); a
 * missing tick_size column/cell (tickSize stays null, never guessed);
 * zero real matches (returns null, never fabricates a token).
 */
function findOptionInstrumentInCsv(body, underlyingSymbol, strike, optionType) {
  const lines = body.split('\n');
  const header = lines[0].split(',');
  const nameIdx = header.indexOf('name');
  const tokenIdx = header.indexOf('instrument_token');
  const symbolIdx = header.indexOf('tradingsymbol');
  const strikeIdx = header.indexOf('strike');
  const typeIdx = header.indexOf('instrument_type');
  const expiryIdx = header.indexOf('expiry');
  const tickSizeIdx = header.indexOf('tick_size');
  let best = null;
  for (const line of lines.slice(1)) {
    const cols = line.split(',');
    if (cols[nameIdx] !== underlyingSymbol) continue;
    if (parseFloat(cols[strikeIdx]) !== strike) continue;
    if (cols[typeIdx] !== optionType) continue;
    const expiry = cols[expiryIdx];
    if (!best || expiry < best.expiry) {
      const parsedTick = tickSizeIdx >= 0 ? parseFloat(cols[tickSizeIdx]) : NaN;
      best = {
        token: parseInt(cols[tokenIdx], 10),
        tradingsymbol: cols[symbolIdx],
        tickSize: (Number.isFinite(parsedTick) && parsedTick > 0) ? parsedTick : null,
        expiry,
      };
    }
  }
  return best;
}

/**
 * TRACE: real network wrapper around findOptionInstrumentInCsv - fetches
 * the real Kite NFO instruments dump ONCE and resolves every one of the
 * user's real configured optionStrikes entries against that same real
 * response (a single real HTTP call regardless of how many strikes are
 * configured, not one call per strike).
 * Preconditions: symbol is the real underlying (e.g. "NIFTY");
 * optionStrikes is a real, already-validated array of {strike,
 * optionType}.
 * Postconditions: resolves to an array of {strike, optionType, token,
 * tradingsymbol, tickSize} - one entry per requested strike that had a
 * real match. A requested strike/type with genuinely no real match in
 * the dump is silently OMITTED (not fabricated, not a fatal error -
 * logged so the operator can see it, but the daemon still starts and
 * tracks whatever real contracts it did resolve).
 */
function lookupOptionInstrumentTokens(symbol, optionStrikes) {
  return new Promise((resolve, reject) => {
    if (!optionStrikes || !optionStrikes.length) { resolve([]); return; }
    https.get('https://api.kite.trade/instruments/NFO', (res) => {
      let body = '';
      res.on('data', d => body += d);
      res.on('end', () => {
        const resolved = [];
        optionStrikes.forEach(({ strike, optionType }) => {
          const found = findOptionInstrumentInCsv(body, symbol.toUpperCase(), strike, optionType);
          if (found) {
            resolved.push({ strike, optionType, token: found.token, tradingsymbol: found.tradingsymbol, tickSize: found.tickSize });
          } else {
            console.error(`No real matching NFO contract found for ${symbol} ${strike}${optionType} - this strike will NOT be tracked (never subscribing to a guessed/fabricated token).`);
          }
        });
        resolve(resolved);
      });
    }).on('error', reject);
  });
}

/*
 * Wires the KiteTicker instance's events. Extracted from main() so the
 * 'noreconnect' exit behavior below can be exercised by a real
 * regression test against a mock EventEmitter ticker, instead of only
 * being trusted by code inspection.
 *
 * WHY EXIT ON 'noreconnect':
 * The kiteconnect library's own WebSocket client already does the real
 * reconnect-with-backoff work (exponential backoff between attempts, a
 * bounded retry count) - that logic lives in the library, not here, and
 * is not reimplemented in this file. 'noreconnect' fires once the
 * library has exhausted its own retry budget (e.g. after a daily Kite
 * access-token expiry, which nothing short of a fresh login token can
 * fix). Previously this handler only logged and let the process keep
 * running - the daemon looked "alive" to any process supervisor
 * (systemd/pm2/forever) forever, while silently never receiving another
 * tick again, with nothing but a console line to notice it by. Exiting
 * non-zero here lets a process supervisor actually restart/alert on it,
 * the same way every other fatal condition in this file already does
 * (see the process.exit(1) calls above for missing config/module and
 * failed instrument lookup). The downstream WordPress side already
 * treats a stale/no snapshot as honestly "offline" after 30s regardless
 * (see fno-lab.php's fno_get_microstructure_fn, $ageSeconds > 30 check)
 * - so this exit doesn't change data honesty, only operability.
 *
 * REAL EXTENSION (Zerodha-maximization audit, this pass - closes the
 * last remaining backlog item): `optionTokens`, when provided, is a
 * real Map of instrument_token -> {instrumentKey, tradingsymbol} for
 * the user's own configured option strikes (see
 * lookupOptionInstrumentTokens above). These are subscribed alongside
 * the underlying index token, in the SAME full mode (so real depth
 * data is available for them too), and every real tick for them is
 * BOTH captured into the SAME rawTickBuffer this file already uses
 * (Enterprise Data Layer raw-tick sink, per-instrument by design -
 * `symbol` is set to the REAL matched tradingsymbol for that specific
 * contract, never config.symbol) AND routed through the real, same
 * six compute functions (computeTickRule/processVolume/
 * processDepthForIceberg/processDepthForSpoofing) against that
 * token's OWN, independent state object (via getOrCreateState), never
 * the underlying's - see createState()'s own TRACE above for why this
 * is safe (single-threaded swap, no await in between). The ambient
 * `state` is restored to the underlying's own state object at the end
 * of every tick-batch invocation so postSnapshot()'s independent timer
 * always sees the right default between event-loop turns.
 */
function wireTickerEvents(ticker, token, exitFn, optionTokens) {
  const doExit = exitFn || process.exit;
  const optTokenMap = optionTokens || new Map();
  const allTokens = [token, ...optTokenMap.keys()];
  const underlyingState = state; // the real, single underlying state object this function must always restore `state` to between ticks

  ticker.on('connect', () => {
    const optNames = [...optTokenMap.values()].map(v => v.tradingsymbol);
    console.log('Connected to Kite WebSocket ticker.' + (optTokenMap.size ? ` Also tracking ${optTokenMap.size} real option strike(s): ${optNames.join(', ')}.` : ''));
    ticker.subscribe(allTokens);
    ticker.setMode(ticker.modeFull, allTokens); // full mode required for depth data (iceberg/order-flow factors need it for the underlying and, now, for each real option strike too)
  });

  ticker.on('ticks', (ticks) => {
    ticks.forEach((tick) => {
      const optInfo = optTokenMap.get(tick.instrument_token);
      if (tick.instrument_token !== token && !optInfo) return;

      // Real, genuine per-instrument state routing: the underlying uses
      // its own real state object; a real option strike gets its own,
      // independent state via getOrCreateState (never the underlying's -
      // that would silently corrupt the index-level metrics with a
      // different instrument's ticks). Restored to underlyingState at
      // the end of this same synchronous forEach iteration.
      state = optInfo ? getOrCreateState(tick.instrument_token) : underlyingState;

      state.tickTimestamps.push(Date.now());
      const direction = computeTickRule(tick.last_price);
      const incrementalVol = processVolume(tick.last_price, tick.volume_traded || 0, direction);
      if (tick.depth) {
        processDepthForIceberg(tick.depth);
        processDepthForSpoofing(tick.depth, incrementalVol); // f153 - must run AFTER processVolume above so incrementalVol reflects this same tick
      }

      // Enterprise Plan #2/#3 - real raw tick buffered for the next
      // batch flush. Uses the SAME canonical field names
      // fno_dsm_empty_record() defines server-side (Phase 1's Unified
      // Data Layer), so this daemon-sourced tick and any future
      // TrueData-sourced tick land in the identical schema without a
      // second translation layer. Real symbol: the matched option
      // tradingsymbol for a strike tick, config.symbol for the
      // underlying - never guessed.
      const bestBid = tick.depth && tick.depth.buy && tick.depth.buy[0];
      const bestAsk = tick.depth && tick.depth.sell && tick.depth.sell[0];
      rawTickBuffer.push({
        ts: Date.now(), symbol: optInfo ? optInfo.tradingsymbol : config.symbol, ltp: tick.last_price,
        volume: tick.volume_traded || null, oi: tick.oi || null,
        bid: bestBid ? bestBid.price : null, ask: bestAsk ? bestAsk.price : null,
        source: 'daemon_kite',
      });
      if (rawTickBuffer.length > RAW_TICK_BUFFER_MAX) rawTickBuffer.shift(); // drop oldest, not the whole buffer - see RAW_TICK_BUFFER_MAX's TRACE above
    });
    state = underlyingState; // restore the ambient state to the underlying's own object so postSnapshot()'s independent timer always sees the right default between event-loop turns
  });

  ticker.on('disconnect', (err) => console.error('Disconnected from Kite ticker:', err && err.message));
  ticker.on('error', (err) => console.error('Kite ticker error:', err && err.message));
  ticker.on('reconnect', (count, delay) => console.log(`Reconnecting (attempt ${count}, delay ${delay}ms)...`));
  ticker.on('noreconnect', () => {
    console.error('Kite ticker gave up reconnecting after exhausting its retry budget - data has stopped. Check your access_token has not expired (Kite tokens expire daily). Exiting so a process supervisor can restart/alert on this instead of the daemon looking "alive" while silently receiving nothing.');
    doExit(1);
  });
}

async function main() {
  console.log(`F&O Lab Microstructure Daemon starting for ${config.symbol}...`);
  let KiteTicker;
  try {
    ({ KiteTicker } = require('kiteconnect'));
  } catch (e) {
    console.error('The "kiteconnect" npm package is not installed. Run: npm install kiteconnect');
    process.exit(1);
  }

  const lookup = await lookupInstrumentToken(config.symbol).catch(e => {
    console.error('Instrument lookup failed: ' + e.message);
    process.exit(1);
  });
  const token = lookup.token;
  console.log(`Resolved ${config.symbol} -> instrument_token ${token}`);
  // REAL FIX (Zerodha-maximization audit, this session): only override
  // TICK_SIZE from the real Kite-provided value when the user did NOT
  // explicitly set config.tickSize themselves (a deliberate override
  // always wins) AND Kite's own response genuinely provided one -
  // reassigned exactly once, before ticker.connect() below starts
  // delivering real ticks that read TICK_SIZE.
  if (!config.tickSize && lookup.tickSize) {
    console.log(`Real tick_size ${lookup.tickSize} obtained from Kite's own instrument master for ${config.symbol} (was using the ${TICK_SIZE} default)`);
    TICK_SIZE = lookup.tickSize;
  }

  // REAL EXTENSION (Zerodha-maximization audit, this pass): resolve the
  // user's own configured optionStrikes (if any) against the real Kite
  // NFO instruments dump before connecting - never guessed, never
  // fabricated; a strike with no real match is logged and simply
  // omitted (see lookupOptionInstrumentTokens' own TRACE).
  const optionResolved = await lookupOptionInstrumentTokens(config.symbol, config.optionStrikes).catch(e => {
    console.error('Option-strike instrument lookup failed (continuing with the underlying index only): ' + e.message);
    return [];
  });
  // REAL FIX (this pass, per-strike microstructure refactor): the map's
  // value is now a real {instrumentKey, tradingsymbol} object (was a
  // bare tradingsymbol string) - instrumentKey is the real Kite
  // tradingsymbol used as the new wp_fno_microstructure_instruments
  // table's PRIMARY KEY (see fno-lab.php's fno_create_journal_table()
  // TRACE for that table); tradingsymbol is kept alongside for the
  // existing raw-tick-buffer/connect-log usages that predate this pass.
  // Both are the SAME real, Kite-matched string - never fabricated.
  const optionTokens = new Map();
  optionResolved.forEach(o => {
    optionTokens.set(o.token, { instrumentKey: o.tradingsymbol, tradingsymbol: o.tradingsymbol, symbol: config.symbol });
    console.log(`Resolved ${config.symbol} ${o.strike}${o.optionType} -> instrument_token ${o.token} (${o.tradingsymbol})`);
  });

  const ticker = new KiteTicker({ api_key: config.kiteApiKey, access_token: config.kiteAccessToken });
  wireTickerEvents(ticker, token, undefined, optionTokens);

  ticker.connect();
  setInterval(postSnapshot, POST_INTERVAL_MS);
  setInterval(flushRawTicks, POST_INTERVAL_MS); // Enterprise Plan #2/#3 - same cadence as the microstructure snapshot above, real batching not per-tick posting
  // REAL EXTENSION (this pass): posts the real, independently-computed
  // per-strike microstructure snapshot for every tracked option token,
  // same cadence as the underlying's own postSnapshot above.
  setInterval(() => postAllInstrumentSnapshots(optionTokens), POST_INTERVAL_MS);

  console.log(`Daemon running. Posting snapshots to ${config.wpSiteUrl} every ${POST_INTERVAL_MS}ms.`);
  console.log('Press Ctrl+C to stop. Stopping this process makes the six daemon-dependent factors honestly report "offline" again within 30 seconds.');
}

if (require.main === module) {
  // Secret-leakage audit fix: this used to log the raw error object
  // (a console.error call passing the raw error object as a second
  // argument). main() constructs KiteTicker
  // directly with config.kiteApiKey/config.kiteAccessToken - if that
  // (or any third-party call inside main()) ever throws an Error
  // subclass/plain object carrying those values as enumerable
  // properties (not guaranteed absent, since KiteTicker is an external
  // dependency this file does not control), a wholesale object dump
  // would print them to stdout/log files. Log only .message/.stack,
  // the same discipline already used by every other catch block in
  // this file - never the raw error object.
  main().catch((e) => { console.error(`Fatal error: ${e && e.message ? e.message : e}${e && e.stack ? '\n' + e.stack : ''}`); process.exit(1); });
}

module.exports = {
  computeTickRule, processVolume, processDepthForIceberg, processDepthForSpoofing,
  computePOC, computeFootprintTopLevels, computeFlowImbalancePct, computeTicksPerMinute,
  // Exported for tests only (fidelity tests need to actually exercise the
  // real network-posting code paths against a mock WordPress server,
  // rather than trusting that postSnapshot/flushRawTicks build the right
  // URL/action/header/body by code inspection alone - see
  // test-daemon-wp-ingest-fidelity.test.js).
  postSnapshot, flushRawTicks, wireTickerEvents,
  loadConfig, maskSecret,
  findInstrumentInCsv,
  findOptionInstrumentInCsv, lookupOptionInstrumentTokens,
  // Exported for tests only (this pass's per-strike microstructure
  // refactor - tests need to exercise real per-token state isolation
  // and the real per-instrument posting path against a mock WordPress
  // server, the same way postSnapshot/flushRawTicks already are above).
  createState, getOrCreateState, computeMetricsForState,
  postInstrumentSnapshot, postAllInstrumentSnapshots,
  _setConfigForTest(overrides) { Object.assign(config, overrides); },
  _addRawTickForTest(tick) { rawTickBuffer.push(tick); },
  _getRawTickBufferForTest() { return rawTickBuffer; },
  _resetStateByTokenForTest() { stateByToken.clear(); },
};
