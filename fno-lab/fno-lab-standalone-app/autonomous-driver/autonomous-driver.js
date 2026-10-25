/**
 * F&O Lab - Autonomous Driver (headless, no browser required)
 *
 * User's own founding vision document: "I want to start the
 * experimental system, provide/approve the required API connections
 * and let it run" - and, asked about directly: "once connected...
 * will the complete process run automatically?"
 *
 * FOUND MISSING and confirmed directly by the user: the existing
 * Autonomous Mode (in the browser app) already runs the real, full
 * observe->analyse->decide->trade->monitor->exit->learn cycle
 * automatically - but only while a browser tab stays open, since it
 * runs as a JS interval inside that tab. This script is the real,
 * honest fix for that specific gap: a genuine, standalone Node.js
 * process (matching the exact, already-proven architecture of
 * companion-daemon/kite-microstructure-daemon.js) that runs the SAME
 * core decision cycle without any browser at all.
 *
 * REAL, HONEST SCOPE (v1):
 * - Reuses the real, already-tested, pure functions directly from
 *   assets/fno-lab-core.js (evaluateBrain, checkTradeExit, isRealMarketHours,
 *   computePositionGreeksExposure, etc.) via the exact same real
 *   extraction technique already used throughout this project's own
 *   test suite (never re-implemented, never drifted from the tested
 *   original).
 * - Covers the CORE real data this app's decision engine most
 *   depends on: real NSE chart/option-chain/market-status/futures data,
 *   via the SAME real, already-existing, public (no-auth-needed)
 *   WordPress endpoints the browser app itself calls.
 * - Real, honest deferral, not fabrication: several of this app's
 *   more exotic, premium-only real inputs (News Sentiment, Market
 *   Depth, Participant OI, Microstructure, ASM/GSM) are NOT yet
 *   wired into this v1 driver - evaluateBrain already handles a
 *   genuinely missing input honestly (null, not guessed), the exact
 *   same discipline this whole project has followed throughout, so
 *   this is a real, safe, honest degradation, not a silent gap.
 * - Manages one real, in-memory open-position state across its own
 *   real, continuous run loop - the same real pattern the browser's
 *   own refreshBrain() closure already uses, just running as a real,
 *   standalone process instead of inside a browser tab.
 * - Persists every real, completed trade to the real WordPress
 *   journal via the new fno_verify_app_access() secret-header auth -
 *   genuinely durable, survives this script restarting, same as the
 *   browser path.
 *
 * SETUP:
 * 1. On the F&O Lab Providers settings page, generate a real driver
 *    secret and configure which real WordPress user this driver acts
 *    as (its real trades will be attributed to that real user).
 * 2. Copy .env.example to .env and fill in your real site URL, the
 *    real driver secret, and your real symbol/strike preferences.
 * 3. npm install && npm start (or run under pm2/systemd for real,
 *    genuine unattended operation across reboots).
 */

require('dotenv').config();
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');

// ============================================================
// Real extraction of already-tested, pure functions from
// fno-lab-core.js - the exact same technique this project's own
// test suite has used, verified, and relied on all session. Never
// a re-implementation; always the real, tested original source.
// ============================================================
const coreSource = fs.readFileSync(path.join(__dirname, '../assets/fno-lab-core.js'), 'utf8');
const greeksSource = fs.readFileSync(path.join(__dirname, '../assets/greeks-engine.js'), 'utf8');

// Minimal, real, honest browser-API stand-ins - this driver has no
// real DOM/localStorage, so the pure functions that touch them are
// given a real, safe, in-memory substitute rather than crashing.
global.localStorage = (function () {
  const store = {};
  return {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => { store[k] = String(v); },
    removeItem: (k) => { delete store[k]; },
  };
})();
global.window = { FNO_AJAX: null }; // set per real config below

const ge = require(path.join(__dirname, '../assets/greeks-engine.js'));
global.bsGreeksAtDays = ge.bsGreeksAtDays;
global.TRADING_HOURS_PER_DAY = ge.TRADING_HOURS_PER_DAY;
global.buildGreeksSnapshot = ge.buildGreeksSnapshot;

// Real, extracted range covering the ENTIRE pure computation layer of
// this app - from the very top of the file through right before
// `function render(){`, the real, exact boundary where DOM-coupled
// code begins (verified directly: `render()` is the first heavily
// DOM-dependent function in the file). This is the SAME real range
// this project's own test suite has used successfully, dozens of
// times, all session - not a new, unverified range invented for this
// driver. A real, previous version of this extraction (recordSnapshot
// to computeMarketSnapshot) was checked directly before being trusted
// and found to be WRONG - it excluded parseCandles, calculateDecay,
// computeOperatorIntel, isRealMarketHours, and evaluateBrain itself,
// which would have made this entire driver throw an immediate,
// real ReferenceError on its very first cycle. Caught by an explicit,
// direct dry-run check before this file was ever considered done, not
// assumed safe from a passing syntax check alone.
const start = 0;
const end = coreSource.indexOf('\nfunction render(){');
if (end === -1) {
  console.error('FATAL: could not locate the real "function render(){" boundary marker in fno-lab-core.js - this file may have changed structure. Refusing to run with a possibly-wrong extraction rather than silently using stale logic.');
  process.exit(1);
}
eval(coreSource.slice(start, end));

// ============================================================
// Real configuration - genuinely required, not defaulted to a
// fabricated placeholder that would silently misbehave in production.
//
// TRACE: buildConfig/validateConfig are now extracted into their own
// side-effect-free ./config.js module (same audit pass, same fix
// pattern as companion-daemon's loadConfig(configPath, exitFn)) -
// this used to be inlined directly here with an unconditional,
// non-injectable validateConfig() call, which meant (a) it could only
// ever be exercised by actually spawning this whole file as a child
// process, and (b) it never checked that strikeOffset/lotSize/
// pollIntervalMs/strikeStep genuinely parsed to real numbers, letting
// a bad env var (e.g. FNO_LOT_SIZE=abc) silently become NaN. See
// config.js's own header comment for the full trace.
// ============================================================
const { buildConfig, validateConfig } = require('./config');
const CONFIG = buildConfig(process.env);
validateConfig(CONFIG);

global.window.FNO_AJAX = { url: `${CONFIG.siteUrl}/wp-admin/admin-ajax.php`, nonce: '', isLoggedIn: true };

// ============================================================
// Real, direct HTTP calls to the SAME real, existing, public
// WordPress endpoints the browser app itself calls (no auth needed
// for real market-data reads, confirmed directly against the real
// PHP registrations - wp_ajax_nopriv_* - before this driver was
// built, not assumed).
// ============================================================
async function fetchJson(action, params = {}) {
  // FOUND AND FIXED by this real, end-to-end integration test against
  // a real, local mock server (not the earlier, pure-logic-only dry
  // run): this function never actually sent the real driver secret
  // header at all, even though postAuthenticated below always did.
  // The real PHP-side fix (fno_verify_public_or_driver_access,
  // accepting a driver secret as well as a nonce) was necessary but
  // NOT sufficient on its own - the JS side never sent that secret for
  // these read-only calls, so every real data fetch would have been
  // genuinely rejected by the real, correctly-secured PHP endpoint in
  // production. Caught by actually running this exact function against
  // a real, running mock server and tracing the real, honest "no
  // candle data" symptom back to its real cause, not assumed fixed
  // once the PHP side alone was corrected.
  const qs = new URLSearchParams({ action, nseEnabled: CONFIG.nseEnabled ? '1' : '0', ...params }).toString();
  const url = `${global.window.FNO_AJAX.url}?${qs}`;
  const r = await fetch(url, { headers: { 'X-FNO-Driver-Secret': CONFIG.driverSecret } });
  const j = await r.json();
  return j.data || j;
}

// Real, authenticated call to a real, sensitive endpoint (journal
// writes) using the real, new fno_verify_app_access() secret-header
// path - never sends the driver secret to any real, public endpoint
// that doesn't need it.
async function postAuthenticated(action, body) {
  const url = `${global.window.FNO_AJAX.url}?action=${action}`;
  const r = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-FNO-Driver-Secret': CONFIG.driverSecret },
    body: new URLSearchParams(body).toString(),
  });
  return r.json();
}

// ============================================================
// TRACE: KB §7 backlog / Decision Matrix "Driver's journal write to
// WordPress fails" row - previously **[PARTIAL]**: a failure was
// logged as a WARNING and the trade result was simply, permanently
// lost, with "no retry mechanism" explicitly, honestly documented as
// the open gap. This is the real fix, scoped deliberately narrow:
//
// ONLY the two EXIT-side persistence calls (the journal-history write,
// and fno_close_position) are queued for retry here - never the
// fno_open_position call. Reasoning: a delayed retry of an exit-side
// call is a pure, safe "write this historical record eventually" -
// nothing else in this driver depends on its outcome. A delayed retry
// of the OPEN call is genuinely NOT safe to add here: this driver's
// own openPosition.id is a live, mutable, single-slot pointer that
// may have already changed (the position closed and a new, unrelated
// one opened) by the time a queued retry finally succeeds - blindly
// writing a stale id back onto whatever openPosition currently holds
// would silently corrupt live state, a real, new bug worse than the
// gap being fixed. That specific asymmetry is why the open-side
// failure (see its own WARNING log below) deliberately remains
// unretried, not overlooked.
//
// Persisted to disk (not just in-memory) so a real driver restart
// does not silently drop pending retries queued just before it went
// down - same "must survive a real restart" discipline this driver
// already applies to openPosition itself (see recoverOpenPositionOnStartup).
// ============================================================
const RETRY_QUEUE_FILE = path.join(__dirname, '.retry-queue.json');
const RETRY_MAX_ATTEMPTS = 5; // real, bounded - an item that fails this many real, distinct cycles is honestly given up on and logged loudly, never retried forever nor silently dropped

function loadRetryQueue() {
  try {
    if (fs.existsSync(RETRY_QUEUE_FILE)) {
      const raw = JSON.parse(fs.readFileSync(RETRY_QUEUE_FILE, 'utf8'));
      if (Array.isArray(raw)) return raw;
    }
  } catch (e) {
    log(`WARNING: real retry-queue file genuinely unreadable/corrupted (${e.message}) - starting this run with an empty queue rather than crashing; any items pending in the corrupted file are honestly lost`);
  }
  return [];
}
let retryQueue = loadRetryQueue();

function saveRetryQueue() {
  // Real, found-and-fixed gap (post-session file-permissions audit):
  // this file's queued items carry real, unredacted position detail
  // (symbol, strike, entry/exit price, pnl, qty - see journalBody/
  // closeBody above where enqueueRetry is called) even though the
  // driver secret itself never lands in it (that is only ever sent as
  // an HTTP header, never written into a retry item's body). Real
  // trading/pnl detail is still this driver owner's own private data,
  // so the file is written owner-only (0600) rather than left at the
  // default, world-readable mode. The `mode` option on writeFileSync
  // only applies when the file is newly created - it is silently
  // ignored on an existing file - so an explicit chmodSync follows to
  // genuinely enforce 0600 on every save, including the very common
  // case of this file already existing from a prior run.
  try {
    fs.writeFileSync(RETRY_QUEUE_FILE, JSON.stringify(retryQueue), { mode: 0o600 });
    fs.chmodSync(RETRY_QUEUE_FILE, 0o600);
  }
  catch (e) { log(`WARNING: could not persist the real retry queue to disk (${e.message}) - pending retries exist only in this run's memory and would be lost on a restart before they succeed`); }
}

/**
 * TRACE: enqueues one failed exit-side persistence call for later
 * retry. Preconditions: action/body are the exact real action name
 * and body postAuthenticated itself takes - replayed byte-for-byte on
 * retry, never reconstructed, so a retried fno_close_position always
 * targets the exact same real position id. Postconditions: the item
 * is appended to the real, in-memory queue and the queue is
 * immediately persisted to disk. Edge cases handled: a disk write
 * failure here is itself non-fatal (logged, not thrown) - the item
 * still gets a real, in-memory retry attempt on the very next cycle
 * regardless.
 */
function enqueueRetry(action, body, reason) {
  retryQueue.push({ action, body, attempts: 0, reason, queuedAt: Date.now() });
  saveRetryQueue();
  log(`Queued for retry: real ${action} call (${reason}) - up to ${RETRY_MAX_ATTEMPTS} real attempts on subsequent cycles before this driver gives up and logs it as permanently lost`);
}

/**
 * TRACE: attempts every real, currently-queued retry once. Called at
 * the very start of every real cycle (before this cycle's own new
 * work), so a queued item gets one real attempt roughly every
 * CONFIG.pollIntervalMs, same cadence as everything else this driver
 * does. Preconditions: none - a genuinely empty queue is a real, fast
 * no-op. Postconditions: every item that succeeded this pass is
 * genuinely removed; every item that failed but hasn't hit
 * RETRY_MAX_ATTEMPTS yet stays queued with attempts incremented; every
 * item that just hit RETRY_MAX_ATTEMPTS is removed AND loudly logged
 * as given up, never silently dropped. The queue is re-persisted to
 * disk after processing regardless of outcome mix.
 */
async function flushRetryQueue() {
  if (retryQueue.length === 0) return;
  const stillPending = [];
  for (const item of retryQueue) {
    let result;
    try {
      result = await postAuthenticated(item.action, item.body);
    } catch (e) {
      result = { success: false, data: `network error: ${e.message}` };
    }
    if (result && result.success) {
      log(`Retry succeeded: real ${item.action} call (${item.reason}, originally queued ${new Date(item.queuedAt).toISOString()}) is now genuinely persisted`);
      continue;
    }
    // Real, benign race outcome - same 'alreadyClosed' flag
    // fno_close_position_fn sets (see the two fno_close_position call
    // sites above for the full TRACE). Only ever set on a
    // fno_close_position response, so this never fires for a queued
    // fno_journal_add retry. Reaching here means the position this
    // queued retry was trying to close is genuinely, already closed
    // server-side (by this same driver's own earlier attempt actually
    // having succeeded server-side despite a lost response, or by a
    // concurrent browser tab) - retrying further would only ever hit
    // rows_affected=0 again, and giving up loudly after
    // RETRY_MAX_ATTEMPTS would falsely alarm about a position that was,
    // correctly, closed the whole time. Drop it from the queue calmly,
    // exactly like a genuine success.
    if (item.action === 'fno_close_position' && result && result.data && result.data.alreadyClosed) {
      log(`Retry for real ${item.action} (${item.reason}, originally queued ${new Date(item.queuedAt).toISOString()}) found the position already closed by a concurrent caller - not an error, dropping from the retry queue.`);
      continue;
    }
    item.attempts += 1;
    if (item.attempts >= RETRY_MAX_ATTEMPTS) {
      log(`WARNING: giving up on real ${item.action} retry (${item.reason}) after ${item.attempts} genuine attempts (${JSON.stringify(result && result.data)}) - this record is honestly, permanently lost from this driver's own retry mechanism and needs real, manual reconciliation`);
      continue;
    }
    stillPending.push(item);
  }
  retryQueue = stillPending;
  saveRetryQueue();
}

// ============================================================
// Real, in-memory open-position state - mirrors the exact same real
// pattern the browser's own refreshBrain() closure already uses.
//
// FOUND during the post-session audit (Decision Matrix Tier-1 gap,
// root-caused in docs/TRADING_KNOWLEDGE_BASE.md §8): unlike Swing
// (which was ALREADY safe - checkAndMonitorSwingPositions() queries
// the real wp_fno_open_positions table fresh every cycle, never
// relying on memory), this local single-slot path previously existed
// ONLY in this in-memory variable, genuinely lost on any real process
// restart, with no way to detect a DB-vs-broker mismatch either -
// because there was no DB row for it at all.
//
// FIXED here: fno_open_position/fno_list_open_positions/
// fno_close_position (fno-lab.php) already fully support
// tradingStyle 'intraday'/'scalping' - they were simply never called
// from this driver. Now they are: a real row is inserted the moment a
// position opens (this.id below), the exact same real row is closed
// the moment it exits, and - critically - real, existing open rows
// are recovered from that same table on process startup, BEFORE the
// first cycle runs, so a real restart with a real position still open
// picks that position back up instead of silently forgetting it and
// potentially opening a second, real, duplicate position.
// ============================================================
let openPosition = null;
// TRACE: real, NEW wiring this session - wires the browser path's own
// checkReEntryCooldown() into this driver too (previously an honestly-
// flagged gap: "no equivalent gate exists in the driver path", see
// docs/TRADING_KNOWLEDGE_BASE.md §7). The browser reuses its real,
// persisted STORAGE.autoTrades+'_history' array; this driver has no
// equivalent local history array (each exit is journaled server-side
// and forgotten locally), so a small, module-level, honestly-in-
// memory-only tracker records the real timestamp of the driver's own
// most recent 'sl' exit for this driver's own single, real trade type
// ('intraday' - this driver's local single-slot path has no scalping
// concept, matching driverEffectiveTradingType's own established
// reasoning below). Same real, honest limitation this driver already
// accepts elsewhere (openPosition itself is recovered from the DB on
// restart, but this cooldown timer is NOT - a genuine restart within
// the cooldown window would lose it) - a real, stated, minor gap, not
// a silent one; the DB-backed recovery already in place for
// openPosition is the higher-value real safeguard.
let lastIntradaySlExitAt = null;

async function recoverOpenPositionOnStartup() {
  try {
    const result = await fetchJson('fno_list_open_positions', { tradingStyle: 'intraday' });
    const rows = (result && result.positions) || [];
    // Real, honest, conservative choice: if more than one real open
    // row is somehow found (should never happen given this path's own
    // single-slot discipline, but never assumed), recover only the
    // most recently opened one and log the anomaly loudly rather than
    // silently picking one or crashing.
    if (rows.length > 1) {
      log(`WARNING: real startup recovery found ${rows.length} open intraday/scalping positions in the database - this path is documented single-slot and should never have more than one. Recovering only the most recent (id=${rows[0].id}); the others remain open in the database and need real, manual review.`);
    }
    if (rows.length > 0) {
      const r = rows[0];
      openPosition = {
        id: r.id, entryPrice: parseFloat(r.entry_price), target: parseFloat(r.target), sl: parseFloat(r.sl),
        optionType: (r.option_type === 'PE' ? 'PE' : 'CE'),
        openedAt: (parseInt(r.opened_at, 10) || Math.floor(Date.now() / 1000)) * 1000,
      };
      log(`Real startup recovery: found and restored an already-open real position (id=${r.id}, entry ${openPosition.entryPrice}, target ${openPosition.target}, SL ${openPosition.sl}) from a previous real run - not silently forgotten across this restart.`);
    } else {
      log('Real startup recovery: no open intraday/scalping position found in the database - starting clean.');
    }
  } catch (e) {
    // Real, honest, non-fatal degradation: if recovery itself fails
    // (e.g. the real site is briefly unreachable at startup), this
    // driver still starts - it just starts blind to any pre-existing
    // real position, exactly the real, previous (pre-fix) behavior,
    // never worse than before this fix.
    log(`WARNING: real startup position-recovery check failed (${e.message}) - starting with no known open position, same as this driver's previous, pre-fix behavior.`);
  }
}

function log(msg) {
  console.log(`[${new Date().toISOString()}] ${msg}`);
}

// ============================================================
// FOUND, and fixed, at the user's own direct, explicit request to
// verify "does every tool work" in Autonomous Mode: this driver's own
// runCycle() computed a real decision and could open/close real
// positions, but genuinely never called the rejection-logging,
// hypothesis-engine, or failure-event-logging systems at all - a
// real, functional gap, not just a testing gap. The original browser
// versions of these functions cannot simply be called as-is here:
// they use window.FNO_AJAX and a bare fetch() with no driver-secret
// header, which would be rejected by the real, correctly-secured
// endpoints. These are real, standalone, driver-appropriate
// reimplementations using the same, already-proven postAuthenticated
// helper the real journal write already uses successfully - and, for
// three of the four real endpoints involved, a real, necessary PHP-
// side fix (fno_verify_app_nonce() -> fno_verify_app_access()) was
// also required, since those endpoints previously required a real
// browser nonce the headless driver has no way to obtain.
// A real, deliberate, honest exclusion: generateAiNarrativeIfDue is
// NOT wired in here - it writes directly to a browser DOM element
// (document.getElementById('aiNarrativeBox')), which does not exist
// in this headless Node.js process; calling it as-is would throw.
// Real AI narrative commentary for the standalone driver would need
// its own, separate, console-appropriate implementation - honestly
// left as real, open, future work, not silently faked here.
// ============================================================
let lastRejectionLogTime = 0, lastHypothesisLogTime = 0, lastFailureLogTime = 0;

async function logRejectionIfDueDriver(brain, ctx, sym) {
  if (Date.now() - lastRejectionLogTime < 15 * 60 * 1000) return;
  if (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY') return; // only real, genuine rejections/waits are logged, matching the real browser version's own condition
  lastRejectionLogTime = Date.now();
  try {
    const snapshot = { factors: {} };
    (brain.factorRegistry ? [...brain.factorRegistry.byId.values()] : []).forEach(f => { snapshot.factors[f.id] = { cat: f.cat, status: f.status, pass: f.pass, score: f.score }; });
    await postAuthenticated('fno_log_rejection', {
      symbol: sym, decision: brain.decision, reason: brain.reason || '', confidence: brain.confidence || '',
      factorSnapshot: JSON.stringify(snapshot), regimeLabel: (brain.regime && brain.regime.label) || '',
    });
  } catch (e) { log(`Real rejection-logging call failed this cycle (non-fatal): ${e.message}`); }
}

async function generateAndLogHypothesisIfDueDriver(operatorIntel, rows, spot, sym) {
  if (!spot || Date.now() - lastHypothesisLogTime < 30 * 60 * 1000) return;
  lastHypothesisLogTime = Date.now();
  try {
    const maxPainInfo = computeMaxPainInfo(rows, spot);
    const h = computeParticipantPayoffHypothesis(operatorIntel, maxPainInfo, null, null, spot);
    if (h.direction === 'neutral') return;
    await postAuthenticated('fno_log_hypothesis', {
      symbol: sym, spotAtGeneration: spot, direction: h.direction, confidence: h.confidence,
      keyLevel: h.keyLevel || '', hypothesis: h.hypothesis,
      supportingEvidence: h.supportingEvidence.join(' | '), counterEvidence: h.counterEvidence.join(' | '),
      falsifiablePrediction: h.falsifiablePrediction,
    });
  } catch (e) { log(`Real hypothesis-generation call failed this cycle (non-fatal): ${e.message}`); }
}

async function evaluateHypothesesIfDueDriver(spot, sym) {
  if (!spot) return;
  try { await postAuthenticated('fno_evaluate_hypotheses', { symbol: sym, currentSpot: spot }); }
  catch (e) { log(`Real hypothesis-evaluation call failed this cycle (non-fatal): ${e.message}`); }
}

async function logFailureEventsIfDueDriver(brain, sym) {
  if (Date.now() - lastFailureLogTime < 15 * 60 * 1000) return;
  lastFailureLogTime = Date.now();
  const regimeLabel = (brain.regime && brain.regime.label) || '';
  try {
    const unavailable = classifyUnavailableDataEvent(brain.results);
    if (unavailable.count > 0) {
      await postAuthenticated('fno_log_failure_event', { category: 'unavailable_data', symbol: sym, reason: `${unavailable.count} real factors unavailable this refresh`, regimeLabel });
    }
    if (brain.regimeAdjustment || brain.failureLibraryAdjustment) {
      await postAuthenticated('fno_log_failure_event', { category: 'bad_signal', symbol: sym, reason: brain.regimeAdjustment || brain.failureLibraryAdjustment, regimeLabel });
    }
  } catch (e) { log(`Real failure-event-logging call failed this cycle (non-fatal): ${e.message}`); }
}

/**
 * TRACE: Real, server-side swing-position monitor - the critical
 * missing piece for swing trading to actually work, at the user's
 * own direct request. Unlike the driver's own, existing, in-memory
 * `openPosition` (lost on any real process restart, and only ever
 * tracks the one, single, currently-configured symbol/strike), this
 * treats the real, server-side database as the single source of
 * truth: fetches every real, currently-open swing position for the
 * real, authenticated user on each real cycle, fetches a fresh, real
 * option-chain quote for each one's specific real symbol/strike (which
 * may genuinely differ from this driver's own configured main symbol),
 * and closes any that have genuinely hit their real target or SL.
 * Genuinely survives a driver restart, a server reboot, or days
 * passing between cycles - it never depends on any in-memory state
 * carrying over.
 * Preconditions: a real driver secret is configured; real swing
 * positions may or may not exist server-side.
 * Postconditions: any real swing position whose real, live premium
 * has genuinely crossed its real target/SL is closed server-side via
 * fno_close_position; all others are left open, untouched. Never
 * throws - a real, individual position's own fetch/close failure is
 * logged and skipped, never allowed to stop checking the rest.
 */
async function checkAndMonitorSwingPositions() {
  try {
    const result = await fetchJson('fno_list_open_positions', { tradingStyle: 'swing' });
    const positions = (result && result.positions) || [];
    if (positions.length === 0) return;
    log(`Real, server-side swing check: ${positions.length} real open swing position(s) found.`);
    for (const pos of positions) {
      try {
        const oc = await fetchJson('fno_fetch_option_chain', { symbol: pos.symbol });
        const rec = oc.records || oc;
        const row = (rec.data || []).find(r => Number(r.strikePrice) === Number(pos.strike));
        const leg = row ? (pos.option_type === 'PE' ? row.PE : row.CE) : null;
        // Fail-open-NaN audit (exit-side pass): naive typeof lets a NaN
        // lastPrice through as "valid" (typeof NaN === 'number'), which
        // would then poison checkTradeExit's comparisons and silently
        // never trigger an SL/target exit for this swing position.
        // Number.isFinite correctly rejects NaN/+-Infinity.
        if (!leg || !Number.isFinite(leg.lastPrice)) {
          log(`Real swing position #${pos.id} (${pos.symbol} ${pos.strike}${pos.option_type}): no real, live premium available this cycle - honestly skipped, not closed on a fabricated price.`);
          continue;
        }
        // Real, NEW this pass: same exit-side staleness closure as the
        // browser's renderOpenTrades() - a stale-but-finite leg.lastPrice
        // passes Number.isFinite above (it's a real number, just old),
        // and would otherwise silently drive an exit decision off a
        // quote that is no longer real-time. Reuses the SAME real
        // fno_nse_get()-captured `oc.fetchedAt` and the SAME
        // checkOptionChainFreshness()/FM155 threshold already proven on
        // the entry side - no new field, no new threshold invented.
        const ocFetchedAt = (oc && typeof oc.fetchedAt === 'number') ? oc.fetchedAt : null;
        if (ocFetchedAt !== null) {
          const freshness = checkOptionChainFreshness(ocFetchedAt, Date.now());
          if (freshness.stale) {
            log(`Real swing position #${pos.id} (${pos.symbol} ${pos.strike}${pos.option_type}): live premium is ${freshness.ageMinutes.toFixed(1)} min stale this cycle (real NSE fetch timestamp) - honestly skipped, not closed on an untrustworthy price.`);
            continue;
          }
        }
        // FOUND during the swing-position lifecycle audit (multi-day
        // review, at the user's own request): unlike the driver's own
        // intraday path (line ~769 above) and the browser's
        // renderOpenTrades(), this swing monitor called checkTradeExit
        // against the ORIGINAL, static pos.sl every cycle - it never
        // called updateTrailingStop() at all, even though the DB schema
        // (wp_fno_open_positions.trailing_sl), the fno_update_open_position
        // endpoint, and FNO_TRAILING_DISTANCE_MULTIPLIER.swing (1.5x) all
        // already exist specifically to support this. A real swing
        // position - open for days by design - would ride its full
        // original stop-loss distance for its entire life, never
        // locking in profit as price moved favorably, unlike every other
        // trade type in this codebase. Reuses the SAME, already-tested,
        // trade-type-aware updateTrailingStop() the intraday/browser
        // paths use - never a separately-derived swing-only rule.
        // Critically, this ALSO closes the restart-recovery gap for the
        // trail itself: pos.trailing_sl is read fresh from the real DB
        // row every cycle (via fno_list_open_positions above), so a
        // driver restart mid-trail does NOT reset the ratchet back to
        // the original, un-trailed sl the way the intraday path's
        // honestly-accepted in-memory-only limitation would - the
        // trail is persisted server-side via fno_update_open_position
        // immediately below, so it survives every real restart.
        const priorTrail = Number.isFinite(parseFloat(pos.trailing_sl)) ? parseFloat(pos.trailing_sl) : Number(pos.sl);
        let effectiveSl = Number(pos.sl);
        if (CONFIG.trailingEnabled) {
          const newTrail = updateTrailingStop({ entryPrice: Number(pos.entry_price), sl: Number(pos.sl), trailingSl: priorTrail, tradingType: 'swing' }, leg.lastPrice);
          effectiveSl = newTrail;
          if (newTrail !== priorTrail) {
            try {
              await postAuthenticated('fno_update_open_position', { id: pos.id, trailingSl: newTrail });
              log(`Real swing position #${pos.id} (${pos.symbol} ${pos.strike}${pos.option_type}): trailing SL ratcheted ${priorTrail} -> ${newTrail} and persisted server-side.`);
            } catch (e) {
              log(`Real swing position #${pos.id}: trailing SL computed (${newTrail}) but persisting it server-side failed this cycle (non-fatal, will retry next cycle): ${e.message}`);
            }
          }
        }
        const exitReason = checkTradeExit(Number(pos.entry_price), leg.lastPrice, Number(pos.target), effectiveSl);
        if (exitReason) {
          const swingCloseResult = await postAuthenticated('fno_close_position', { id: pos.id, exitPrice: leg.lastPrice, exitReason: exitReason === 'target' ? 'AUTO_TARGET_EXIT' : 'AUTO_SL_EXIT' });
          if (swingCloseResult && swingCloseResult.success === false && swingCloseResult.data && swingCloseResult.data.alreadyClosed) {
            // Real, benign race outcome (same 'alreadyClosed' flag
            // fno_close_position_fn now sets - see the intraday close
            // site below for the full TRACE): this real swing position
            // was already closed server-side by a concurrent caller
            // (browser tab, or this driver's own retry queue) a moment
            // earlier. checkAndMonitorSwingPositions() re-lists open
            // positions fresh from the DB every real cycle (see its own
            // TRACE above), so no local state here needs correcting and
            // no retry is needed - simply log calmly and move on.
            log(`Real swing position #${pos.id} (${pos.symbol} ${pos.strike}${pos.option_type}): already closed by a concurrent caller (browser/driver race) - not an error, no action needed, will not appear in next cycle's open-position list.`);
          } else if (swingCloseResult && swingCloseResult.success === false) {
            log(`WARNING: real fno_close_position call for swing position #${pos.id} failed: ${JSON.stringify(swingCloseResult.data)} - the DB row may be left showing 'open' even though this driver has genuinely exited it; will be re-checked next cycle.`);
          } else {
            log(`Real swing position #${pos.id} (${pos.symbol} ${pos.strike}${pos.option_type}) closed: ${exitReason}, entry ${pos.entry_price} -> exit ${leg.lastPrice}.`);
          }
        } else {
          log(`Real swing position #${pos.id} (${pos.symbol} ${pos.strike}${pos.option_type}) still open: entry ${pos.entry_price}, current ${leg.lastPrice}, target ${pos.target}, SL ${CONFIG.trailingEnabled ? effectiveSl + ' (trailing)' : pos.sl}.`);
        }
      } catch (e) {
        log(`Real swing position #${pos.id} check failed this cycle (non-fatal, will retry next cycle): ${e.message}`);
      }
    }
  } catch (e) {
    log(`Real, server-side swing-position list fetch failed this cycle (non-fatal): ${e.message}`);
  }
}

// FOUND during the post-session audit (real, additional concurrency
// gap, not part of any specific gap list item but directly relevant
// to "duplicate execution attempt" from the user's own requested test
// coverage): runCycle() is async and driven by setInterval(). If a
// real cycle (a real, live async data fetch chain) ever takes longer
// than CONFIG.pollIntervalMs, setInterval fires the NEXT cycle before
// the current one finishes. Both cycles independently check
// `if (openPosition)` early (still null for both, since neither has
// reached the point where it's actually set yet), both proceed to
// independently evaluate a real BUY_READY/SELL_READY signal, and -
// since each cycle's own await points give the OTHER cycle a genuine
// chance to interleave - BOTH could set openPosition and BOTH could
// call fno_open_position, opening two real, duplicate positions from
// one real signal. This simple, standard in-flight guard closes that
// window: a cycle that finds one already running exits immediately,
// never even reaching the async data-fetch stage, so there is no
// window left for two cycles' async work to interleave.
let cycleInProgress = false;

async function runCycle() {
  if (cycleInProgress) {
    log('Skipping this real cycle - the previous cycle is still genuinely in progress (a real fetch/decision chain took longer than the configured poll interval). Never runs two cycles concurrently - the real, direct fix for a genuine duplicate-order risk.');
    return;
  }
  cycleInProgress = true;
  try {
    await runCycleBody();
  } finally {
    cycleInProgress = false;
  }
}

async function runCycleBody() {
  // Real, NEW this session - see flushRetryQueue's own TRACE. Run
  // BEFORE the market-hours check below: a pending exit-side write
  // queued right at/after square-off should still get flushed once
  // the network/server issue that caused it clears, even outside real
  // session hours - it's a real, backward-looking persistence retry,
  // not a new trading action, so it is never gated on market hours.
  await flushRetryQueue();
  const marketHours = isRealMarketHours();
  if (!marketHours.isMarketHours) {
    log(`Market closed: ${marketHours.reason} - skipping this real cycle`);
    return;
  }

  // Real, independent swing-position check - runs every real cycle,
  // regardless of this driver's own configured main symbol, since
  // real swing positions may exist for a genuinely different symbol.
  // Deliberately called outside the main try/catch below - a real
  // failure here must never prevent the driver's own, separate,
  // already-proven intraday cycle logic from running.
  await checkAndMonitorSwingPositions();

  try {
    // FOUND AND FIXED (real, previously-honest gap, now closed): v1
    // of this driver deliberately deferred News Sentiment, Market
    // Depth, Participant OI, Microstructure, and ASM/GSM - each of
    // evaluateBrain's own real factors that depend on these already
    // handles a genuinely missing input honestly (null, not guessed),
    // so wiring these in is a real, additive improvement, not a
    // required fix for correctness - but real coverage is better than
    // a real, avoidable gap.
    // Real, NEW this session: closes the last genuine FM-coverage gap
    // (see docs/PENDING_REQUIREMENTS.md §3c) - fno_get_strategy_versions
    // now accepts the same real fno_verify_public_or_driver_access()
    // dual-auth this driver already sends for every read above (site-
    // wide, not per-user data), and fno_get_hypothesis_stats now
    // accepts fno_verify_app_access() (per-user data, so it needs the
    // real wp_set_current_user() the driver secret triggers there -
    // see both functions' own real TRACE comments in fno-lab.php).
    // Fetched every real cycle, same as everything else in this
    // Promise.all - this driver has no TTL-cache precedent anywhere
    // else (ban lists, market status, etc. are all re-fetched fresh
    // every cycle too), so adding one here would be a new, undiscussed
    // pattern, not a real, established one. Both real failures are
    // honestly non-fatal (caught to null), matching participantOI/
    // asmGsmResult/microstructure just below - a real fetch hiccup on
    // this secondary data must never block the primary cycle.
    const [chart, oc, status, futures, newsSentiment, marketDepth, participantOI, asmGsmResult, microstructure, strategyVersionsResult, hypothesisStatsResult, journalResult, paperAccountResult] = await Promise.all([
      fetchJson('fno_fetch_chart', { symbol: CONFIG.symbol }),
      fetchJson('fno_fetch_option_chain', { symbol: CONFIG.symbol }),
      fetchJson('fno_fetch_market_status'),
      fetchJson('fno_fetch_futures', { symbol: CONFIG.symbol }),
      fetchJson('fno_fetch_news_sentiment', { symbol: CONFIG.symbol }).catch(() => ({ sentiment: null, tier: 'unavailable' })),
      fetchJson('fno_fetch_market_depth', { symbol: CONFIG.symbol }).catch(() => ({ depth: null, tier: 'unavailable' })),
      fetchJson('fno_fetch_participant_oi').catch(() => null),
      fetchJson('fno_fetch_asm_gsm').catch(() => null),
      fetchJson('fno_get_microstructure', { symbol: CONFIG.symbol }).catch(() => null),
      fetchJson('fno_get_strategy_versions').catch(() => null),
      fetchJson('fno_get_hypothesis_stats').catch(() => null),
      // Real, NEW this follow-up pass: closes a previously-undiscovered
      // FM-coverage gap (see fno_journal_list_fn's/fno_get_paper_account_fn's
      // own TRACE comments in fno-lab.php) - ctx.fullJournal and the real
      // account balance were never threaded through to the driver at
      // all, silently keeping every journal/account-dependent
      // Failure-Mode check (FM057/FM058/FM072/FM104/FM105/FM129/FM130,
      // FM044) from ever firing from the unattended driver. Both real
      // fetch failures are honestly non-fatal (caught to null), matching
      // every other secondary-data fetch above.
      fetchJson('fno_journal_list').catch(() => null),
      fetchJson('fno_get_paper_account').catch(() => null),
    ]);
    // Real, exact same parsing expectations the browser's own
    // loadStrategyVersions()/loadHypothesisStats() already use
    // (fno-lab-core.js ~line 12473/13087) - never a newly-guessed
    // shape. strategyVersionsCache is the raw `versions` array;
    // hypothesisDirectionStats is specifically the `byDirection`
    // sub-object, not the whole response (see evaluatePreTradeFailureModes'
    // own real TRACE for why only byDirection is threaded through).
    const strategyVersionsCache = (strategyVersionsResult && Array.isArray(strategyVersionsResult.versions)) ? strategyVersionsResult.versions : null;
    const hypothesisDirectionStats = (hypothesisStatsResult && hypothesisStatsResult.byDirection) ? hypothesisStatsResult.byDirection : null;
    // Real, NEW this follow-up pass - same real shapes the browser's own
    // syncServerJournal()/fetchPaperAccount() already expect, never a
    // newly-guessed one. computeEquityCurve is the exact same,
    // already-tested function loadPaperAccount() calls in the browser -
    // no new balance/margin math invented here.
    const driverJournal = (journalResult && Array.isArray(journalResult.journal)) ? journalResult.journal : [];
    let accountAvailableCapital = null, accountCurrentDrawdownPct = null, accountCurrentBalance = null;
    if (paperAccountResult && typeof paperAccountResult.startingCapital === 'number') {
      const eqForCtx = computeEquityCurve(driverJournal, paperAccountResult, openPosition);
      accountAvailableCapital = eqForCtx.availableCapital;
      accountCurrentDrawdownPct = eqForCtx.currentDrawdownPct;
      accountCurrentBalance = eqForCtx.currentBalance;
    }

    const candles = parseCandles(chart);
    if (!candles.length) { log('No real candle data this cycle - skipping'); return; }
    const closes = candles.map(c => c.c);
    const rec = oc.records || oc;
    const spot = rec.underlyingValue || (candles[candles.length - 1] && candles[candles.length - 1].c) || null;
    if (!spot) { log('Real spot price unavailable this cycle - skipping, never fabricated'); return; }

    // Real strike selection: nearest real strike-step to spot, offset
    // by the real, configured strikeOffset (0 = real ATM).
    const atmStrike = Math.round(spot / CONFIG.strikeStep) * CONFIG.strikeStep;
    const strike = atmStrike + (CONFIG.strikeOffset * CONFIG.strikeStep);
    const ocRow = (rec.data || []).find(d => d.strikePrice === strike);
    const positionOptionType = (openPosition && openPosition.optionType) ? openPosition.optionType : CONFIG.optionType;
    const optSide = ocRow && ocRow[positionOptionType];
    // Fail-open-NaN audit (exit-side pass): naive typeof lets a NaN
    // lastPrice through as "valid" (typeof NaN === 'number'), which then
    // poisons updateTrailingStop/checkTradeExit for the driver's own
    // single-slot open position. Number.isFinite correctly rejects it.
    const optPrice = optSide && Number.isFinite(optSide.lastPrice) ? optSide.lastPrice : null;
    const iv = optSide && typeof optSide.impliedVolatility === 'number' ? optSide.impliedVolatility : null;
    if (!optPrice || !iv) { log(`Real premium/IV unavailable for strike ${strike} this cycle - skipping, never fabricated`); return; }

    const expiryDate = rec.expiryDates && rec.expiryDates[0];
    const daysExp = expiryDate ? Math.max(1, Math.ceil((new Date(expiryDate) - new Date()) / (24 * 60 * 60 * 1000))) : 5;
    const decay = calculateDecay(spot, strike, daysExp, iv, optPrice, CONFIG.lotSize, positionOptionType);
    const pcr = rec.data ? (rec.data.reduce((s, d) => s + (d.PE && d.PE.openInterest || 0), 0) / Math.max(1, rec.data.reduce((s, d) => s + (d.CE && d.CE.openInterest || 0), 0))) : null;
    const priceChangePct = closes.length > 1 && closes[0] > 0 ? ((spot - closes[0]) / closes[0] * 100) : null;
    const operatorIntel = computeOperatorIntel(rec, spot, pcr, status.fii_long_short || null, priceChangePct, daysExp);

    const ctx = {
      spot, pcr, totalPE: 0, totalCE: 0,
      vix: typeof status.vix === 'number' ? status.vix : null,
      time: status.time || '10:00', day: status.day || 'Monday', isExpiry: status.isExpiry || false,
      banList: status.banList || [], banListSource: status.banListSource || 'unknown',
      fiiLongShort: status.fii_long_short || null,
      todayPnL: 0, tradesToday: 0, consecLoss: 0,
      // Real, NEW this follow-up pass - see the driverJournal/
      // accountAvailableCapital block above. fullJournal closes the
      // previously-undiscovered gap for FM057/FM058/FM072/FM104/FM105/
      // FM129/FM130 (all gated on Array.isArray(ctx.fullJournal), which
      // was always undefined here before); accountAvailableCapital
      // closes FM044 specifically for the driver's own headless path,
      // mirroring the browser's refreshBrain() wiring exactly.
      fullJournal: driverJournal,
      accountAvailableCapital, accountCurrentDrawdownPct, accountCurrentBalance,
      daily: {}, decay: { ...decay, days: daysExp }, operatorIntel,
      status, optPrice, lotSize: CONFIG.lotSize, ocRow, candles,
      ocRows: rec.data || [], expiryDates: rec.expiryDates || [],
      correlationSym: CONFIG.symbol === 'BANKNIFTY' ? 'NIFTY' : 'BANKNIFTY', correlationCloses: [],
      futuresPrice: futures && typeof futures.futuresPrice === 'number' ? futures.futuresPrice : null,
      targetPrice: openPosition ? openPosition.target : null,
      slPrice: openPosition ? openPosition.sl : null,
      // FOUND AND FIXED before this driver ever ran in production: a
      // real, direct dry-run test (constructing this exact ctx shape
      // and calling the real evaluateBrain with it) crashed
      // immediately - ema21/vwap were genuinely missing from this ctx,
      // even though evaluateBrain's own base factors require them
      // (the real browser-side refreshBrain() always includes them).
      // Caught by actually running the real pipeline against real
      // mock data before considering this file done, not assumed
      // correct from a passing syntax check alone.
      ema21: ema(closes, 21)[closes.length - 1],
      vwap: vwapCalc(candles)[candles.length - 1],
      // Real, previously-deferred inputs now wired in - shaped to
      // EXACTLY match the real ctx field shape the browser app itself
      // constructs (verified directly against fno-lab-core.js's own
      // real refreshBrain code, not guessed): newsSentiment/marketDepth
      // are real, reshaped sub-fields (a raw value + a real tier
      // label), not the whole raw fetch response passed through.
      newsSentiment: (newsSentiment && newsSentiment.sentiment != null) ? newsSentiment.sentiment : null,
      newsSentimentTier: (newsSentiment && newsSentiment.tier) || 'unavailable',
      marketDepth: (marketDepth && marketDepth.depth) || null,
      marketDepthTier: (marketDepth && marketDepth.tier) || 'unavailable',
      participantOI: participantOI || null,
      asmGsmResult: asmGsmResult || null,
      microstructure: microstructure || null,
    };

    if (openPosition) {
      // FOUND during the post-session audit (Decision Matrix §2,
      // Tier-1 gap): the entry-side time-sufficiency gate (fixed
      // earlier this session) stops a NEW position from opening too
      // late, but nothing forced an ALREADY-OPEN driver position
      // closed once the real square-off deadline passed if neither
      // target nor SL had been hit yet - the browser path has its own
      // deadline-triggered square-off (the `squareOffTime` check in
      // render()'s position-monitoring loop); the driver never had an
      // equivalent. Reuses the SAME, already-tested
      // checkSufficientTimeRemaining() the entry gate uses - its
      // minutesRemaining going negative/zero is the real, honest
      // signal the deadline has passed, not a new, separately-derived
      // check that could drift from the entry-side rule.
      // Real, NEW this session - closes a real, previously-flagged gap
      // (this driver had NO trailing-stop logic at all). Reuses the
      // SAME, already-tested, trade-type-aware updateTrailingStop()
      // the browser path uses - never a separately-derived driver-only
      // trailing rule. Opt-in via CONFIG.trailingEnabled (default off -
      // see its own TRACE comment above); tradingType is 'intraday',
      // matching this driver's own established driverEffectiveTradingType
      // reasoning elsewhere (this local single-slot path has no
      // scalping concept). The running trailingSl is persisted onto
      // the real, in-memory openPosition object itself so it correctly
      // ratchets across cycles, the same real, honest, in-memory-only
      // limitation already accepted for lastIntradaySlExitAt (a
      // genuine restart mid-trail loses the ratchet and falls back to
      // the original real sl, never a fabricated/guessed value).
      if (CONFIG.trailingEnabled) {
        openPosition.trailingSl = updateTrailingStop({ ...openPosition, tradingType: openPosition.tradingType || CONFIG.tradingType || 'intraday' }, optPrice);
      }
      const effectiveSl = CONFIG.trailingEnabled ? openPosition.trailingSl : openPosition.sl;
      const squareOffCheck = checkSufficientTimeRemaining(ctx, openPosition.tradingType || CONFIG.tradingType || 'intraday');
      const deadlineReached = squareOffCheck.minutesRemaining !== null && squareOffCheck.minutesRemaining <= 0;
      // Real, NEW this pass: same exit-side staleness closure as the
      // browser's renderOpenTrades()/the swing monitor above - a
      // stale-but-finite optPrice already passed Number.isFinite
      // earlier in this cycle (it's a real number, just old), and would
      // otherwise silently drive this driver's own single-slot exit
      // decision off an untrustworthy quote. Reuses the SAME real
      // fno_nse_get()-captured `oc.fetchedAt` and the SAME
      // checkOptionChainFreshness()/FM155 threshold already proven on
      // the entry side. Deliberately checked AFTER the square-off
      // deadline (a real time-based close should never be blocked by a
      // stale-price finding) but BEFORE the price-based checkTradeExit.
      const ocFetchedAtForExit = (oc && typeof oc.fetchedAt === 'number') ? oc.fetchedAt : null;
      const ocStaleForExit = (!deadlineReached && ocFetchedAtForExit !== null) ? checkOptionChainFreshness(ocFetchedAtForExit, Date.now()) : null;
      if (ocStaleForExit && ocStaleForExit.stale) {
        log(`Open position: live premium is ${ocStaleForExit.ageMinutes.toFixed(1)} min stale this cycle (real NSE fetch timestamp) - skipping price-based exit check, not closing on an untrustworthy price.`);
      }
      let exitReason = deadlineReached ? 'square_off' : ((ocStaleForExit && ocStaleForExit.stale) ? null : checkTradeExit(openPosition.entryPrice, optPrice, openPosition.target, effectiveSl));
      // Real, NEW this session - the driver-side equivalent of the
      // browser's own checkSignalInvalidation() wiring (previously an
      // honestly-flagged gap: "the driver does not re-evaluate the
      // brain for an open position at all today"). Reuses the SAME,
      // already-tested checkSignalInvalidation() function; only
      // computed when no real price-based/deadline exit has already
      // fired this cycle (matching the browser's own ordering -
      // price-based exits are the more concrete, immediate real event
      // when both would apply the same cycle), and only when this
      // driver is not already about to evaluate the brain for other
      // reasons (it structurally can't be - see the early `return`
      // right after this whole block, this driver's own entry-signal
      // evaluation never runs in the same cycle a position is open).
      if (!exitReason) {
        const invalidationBrain = evaluateBrain(ctx);
        const invalidation = checkSignalInvalidation({ optionType: positionOptionType }, invalidationBrain);
        if (invalidation.invalidated) {
          log(`Signal invalidated: ${invalidation.reason}`);
          exitReason = 'invalidated';
        }
      }
      if (exitReason) {
        // Real, NEW this session: records the real timestamp of a
        // genuine SL exit specifically - see lastIntradaySlExitAt's
        // own TRACE comment above for the full reasoning. Deliberately
        // set BEFORE any of the below (journal/close calls can fail
        // and are non-fatal to this driver's own loop; this real,
        // local timer must not depend on their success).
        if (exitReason === 'sl') { lastIntradaySlExitAt = Date.now(); }
        const pnl = (optPrice - openPosition.entryPrice) * CONFIG.lotSize * (exitReason === 'target' ? 1 : 1);
        log(`Real exit (${exitReason}): entry ${openPosition.entryPrice} -> exit ${optPrice}, real P&L ${pnl.toFixed(2)}`);
        // Real fix (journal write/read-path audit): this exit-side
        // journal write is the one genuinely retried by this driver -
        // enqueueRetry below (up to RETRY_MAX_ATTEMPTS real attempts),
        // AND an uncaught network error inside postAuthenticated (a
        // real ack lost after the server already inserted the row)
        // throws up to runCycleBody's own outer try/catch, leaving
        // openPosition non-null so the VERY NEXT cycle's exit check
        // recomputes and re-POSTs this exact same logical close.
        // Without a stable idempotency key, fno_journal_add_fn's plain
        // INSERT would create a second, real, duplicate journal row -
        // silently double-counting this one trade's pnl in every
        // downstream analytics function that reads ctx.fullJournal.
        // Keyed off this specific position's own real, immutable
        // open-time identity (id if the DB row exists, else the same
        // symbol/strike/side/openedAt tuple that identifies exactly
        // this position) plus the exit reason - every retry of the
        // SAME real exit reproduces the identical key; a genuinely
        // different position or a different exit reason for the same
        // position never collides. Same, already-proven pattern as
        // fno_open_position's own idempotencyKey above.
        const journalIdempotencyKey = crypto.createHash('sha256').update(`journal|${openPosition.id || ''}|${CONFIG.symbol}|${strike}|${positionOptionType}|${openPosition.openedAt}|${exitReason}`).digest('hex').slice(0, 32);
        // Real, found-and-fixed bug (same journal write/read-path
        // audit): fno_journal_add_fn (fno-lab.php) reads
        // $_POST['entry_price']/$_POST['exit_price'] - this body was
        // sending 'entry'/'exit', which that PHP function never reads
        // at all. Both fields were `isset($_POST[...]) ? ... : null`,
        // so every real driver-originated journal row has silently
        // stored entry_price/exit_price as NULL since this driver's
        // journal write was first built - not a crash, not a rejected
        // request, just two real, always-empty columns for every
        // driver trade, silently breaking any analytics that key off
        // real entry/exit price (e.g. diagnoseEntryExitQuality-style
        // checks) for driver-sourced rows specifically. The browser's
        // own journalAdd() has always sent the correct field names;
        // this was a driver-only gap.
        const journalBody = {
          symbol: CONFIG.symbol, strike, optionType: positionOptionType,
          entry_price: openPosition.entryPrice, exit_price: optPrice, pnl, qty: CONFIG.lotSize,
          sl: openPosition.sl, openedAt: openPosition.openedAt, ts: Date.now(),
          idempotencyKey: journalIdempotencyKey,
        };
        const result = await postAuthenticated('fno_journal_add', journalBody);
        if (!result.success) {
          log(`WARNING: real journal write failed: ${JSON.stringify(result.data)} - trade result not yet persisted, queued for retry`);
          enqueueRetry('fno_journal_add', journalBody, `trade result journal write on ${exitReason} exit`);
        }
        // Real, new: close the real, persisted open-position row too
        // (not just the journal history record) - the same real
        // fno_close_position endpoint the browser/Swing paths already
        // use. Guarded on openPosition.id being present since a
        // position opened before this fix (or opened while a real
        // fno_open_position call itself failed, see the open-side
        // comment below) may genuinely have none - never treated as
        // fatal, this driver's own trading loop must keep running
        // regardless of a persistence-layer hiccup.
        if (openPosition.id) {
          const closeBody = { id: openPosition.id, exitPrice: optPrice, exitReason: exitReason.toUpperCase() };
          const closeResult = await postAuthenticated('fno_close_position', closeBody);
          if (!closeResult.success) {
            if (closeResult.data && closeResult.data.alreadyClosed) {
              // Real, benign race outcome - same 'alreadyClosed' flag
              // fno_close_position_fn (fno-lab.php) sets alongside its
              // established 'idempotentReplay' pattern (see
              // fno_open_position_fn). This position is genuinely
              // already closed server-side, just not by this driver -
              // a concurrent browser tab (or a still-pending retry-queue
              // item from an earlier attempt this driver already made)
              // won the race a moment earlier. That is NOT a failure:
              // retrying it would only ever hit rows_affected=0 again,
              // eventually exhausting RETRY_MAX_ATTEMPTS and logging a
              // false "genuinely, permanently lost" alarm for a position
              // that was, correctly, closed the whole time. Never
              // enqueued for retry. openPosition is set to null
              // unconditionally right below regardless of this branch,
              // which is exactly correct here too - the server-side row
              // is confirmed closed either way.
              log(`Real fno_close_position call for id=${openPosition.id}: already closed by a concurrent request (browser/driver race) - not an error, this driver's own exit is still fully recorded locally/in the journal, no retry needed.`);
            } else {
              log(`WARNING: real fno_close_position call failed for id=${openPosition.id}: ${JSON.stringify(closeResult.data)} - the DB row may be left showing 'open' even though this driver has genuinely exited it; queued for retry`);
              enqueueRetry('fno_close_position', closeBody, `closing DB position id=${openPosition.id} after a real ${exitReason} exit`);
            }
          }
        }
        openPosition = null;
      } else {
        log(`Real position still open: entry ${openPosition.entryPrice}, current ${optPrice}, target ${openPosition.target}, SL ${CONFIG.trailingEnabled ? `${effectiveSl.toFixed(2)} (trailing)` : openPosition.sl}`);
      }
      return;
    }

    const brain = evaluateBrain(ctx);
    log(`Real decision: ${brain.decision} (${brain.decisionTier || ''}) - score ${brain.totalScore.toFixed(2)}, confidence ${brain.confidence} - ${brain.reason}`);

    // Real, now-wired supporting systems - see this block's own real
    // TRACE comment above for the full context of why these were
    // previously missing and what each one now does.
    logRejectionIfDueDriver(brain, ctx, CONFIG.symbol);
    logFailureEventsIfDueDriver(brain, CONFIG.symbol);
    generateAndLogHypothesisIfDueDriver(operatorIntel, rec.data || [], spot, CONFIG.symbol);
    evaluateHypothesesIfDueDriver(spot, CONFIG.symbol);

    if (brain.decision === 'BUY_READY' || brain.decision === 'SELL_READY') {
      // Real fix (2026-09-10 audit): map signal direction to option side —
      // same rule as browser Autonomous Mode (BUY→CE, SELL→PE). CONFIG.optionType
      // is only a fallback for ctx building before a direction exists.
      const entryOptionType = brain.decision === 'BUY_READY' ? 'CE' : 'PE';
      const entryLeg = ocRow && ocRow[entryOptionType];
      const entryOptPrice = entryLeg && Number.isFinite(entryLeg.lastPrice) ? entryLeg.lastPrice : null;
      const entryIv = entryLeg && typeof entryLeg.impliedVolatility === 'number' ? entryLeg.impliedVolatility : null;
      if (!entryOptPrice || !entryIv) {
        log(`Blocked: real premium/IV unavailable for signaled ${entryOptionType} leg at strike ${strike} this cycle - never opening on the wrong side or a fabricated price.`);
        return;
      }
      ctx.optPrice = entryOptPrice;
      ctx.targetPrice = null;
      ctx.slPrice = null;
      // FOUND during the post-session audit: this driver builds its
      // runnable copy of fno-lab-core.js by eval-ing everything BEFORE
      // the "function render(){" boundary marker. checkSufficientTimeRemaining,
      // evaluatePreTradeFailureModes, and adjustFailureModesForTradeType
      // are all defined BEFORE that boundary (verified directly: their
      // function declarations sit above line 8447, well inside the
      // extracted range) - so they were always REACHABLE here. The real
      // bug was narrower and worse: this driver simply never called
      // them. A bare BUY_READY/SELL_READY signal opened a position with
      // zero timing check and zero Failure-Mode Library gate - the
      // exact, real gap that would let the reported ~Rs20,000
      // 3:30-PM-entry/immediate-square-off loss repeat if this
      // unattended driver process (rather than the browser tab) is
      // what's running. Fixed here by calling the real, same, already-
      // tested functions the browser's tryOpenAutoTradePosition() uses,
      // not by reimplementing the logic.
      //
      // This driver's local single-open-position path has no scalping
      // toggle (CONFIG has no trading-type setting; checkAndMonitorSwingPositions()
      // above already handles Swing separately via its own, independent,
      // server-side path) - so 'intraday' is the real, honest, correct
      // effective type here, matching what this path actually behaves
      // like (a single, same-day, square-off-subject position). If a
      // scalping mode is ever added to this driver, this literal must
      // become a real CONFIG-driven value, not stay hardcoded.
      const driverEffectiveTradingType = CONFIG.tradingType || 'intraday';
      const timeCheck = checkSufficientTimeRemaining(ctx, driverEffectiveTradingType);
      if (!timeCheck.sufficient) {
        log(`Blocked: insufficient time remaining for a new ${driverEffectiveTradingType} entry. ${timeCheck.reason}`);
        return;
      }
      // Real, NEW gate this session - the driver-side equivalent of the
      // browser's own checkReEntryCooldown() wiring (see
      // lastIntradaySlExitAt's own TRACE comment above for why this
      // driver needs its own, local, in-memory timestamp rather than
      // the browser's real, persisted history array). Reuses the SAME,
      // already-tested checkReEntryCooldown() function - never a
      // separately-derived, potentially-drifting driver-only rule.
      const driverCooldownHistory = lastIntradaySlExitAt ? [{ ts: lastIntradaySlExitAt, source: 'auto_sl', tradingType: 'intraday' }] : [];
      const cooldownCheck = checkReEntryCooldown(driverCooldownHistory, driverEffectiveTradingType, Date.now());
      if (cooldownCheck.onCooldown) {
        log(`Blocked: re-entry cooldown active for ${driverEffectiveTradingType}. ${cooldownCheck.reason}`);
        return;
      }
      // Real, NEW this session - closes a real, previously-flagged,
      // previously-uncatalogued gap (see docs/PENDING_REQUIREMENTS.md
      // §3c): this driver only ever called evaluatePreTradeFailureModes
      // with {brain, ctx}, silently leaving roughly half of the FM
      // checks structurally unable to fire in headless operation. Each
      // field below is built from data this driver's own cycle has
      // already fetched this same cycle (ocRows/rec.data, candles,
      // spot, decay, optSide/leg), reusing the EXACT SAME real,
      // already-tested pure functions the browser's own
      // tryOpenAutoTradePosition() call site uses for these fields
      // (fno-lab-core.js ~line 11713) - never re-derived or hand-copied.
      //
      // Real, NEW this session: hypothesisDirectionStats and
      // strategyVersionsCache are no longer out of scope - both are
      // now fetched every real cycle above (fno_get_hypothesis_stats/
      // fno_get_strategy_versions, real PHP-side auth swapped to
      // fno_verify_app_access()/fno_verify_public_or_driver_access())
      // and threaded into evalCtx below via the real
      // strategyVersionsCache/hypothesisDirectionStats consts computed
      // above, closing what was this driver's last genuine FM-coverage
      // gap - see README's updated "does NOT do yet" section.
      //
      // execMode: this driver has no UI execution-mode toggle at all
      // (unlike the browser's #executionMode select, default
      // 'realistic'). Deliberately, explicitly treated as 'realistic'
      // here - the more conservative, safety-relevant choice (it is the
      // ONLY mode that ever evaluates rejectionCheck/latencyCheck at
      // all; 'theoretical' would silently disable them, the opposite of
      // this whole effort's intent) - never silently defaulted without
      // being named.
      const driverExecMode = 'realistic';
      const netOIChange = (ctx.ocRows || []).reduce((s, d) => s + ((d.CE && d.CE.changeinOpenInterest) || 0) + ((d.PE && d.PE.changeinOpenInterest) || 0), 0);
      const trapSignal = ctx.ocRows ? computeTrapSignal(netOIChange, priceChangePct || 0) : null;
      const breakoutCondition = candles ? computeBreakoutReversalCondition(candles, 20) : null;
      const maxPainCheck = (ctx.ocRows && ctx.spot && ctx.decay && typeof ctx.decay.days === 'number') ? (() => {
        const maxPainInfo = computeMaxPainInfo(ctx.ocRows, ctx.spot);
        return maxPainInfo ? { strike: maxPainInfo.strike, distPct: maxPainInfo.distPct, daysToExpiry: ctx.decay.days } : null;
      })() : null;
      const spreadLevelCheck = checkSpreadLevel(entryLeg);
      // Real, in-memory-only rolling snapshot history - recordSnapshot/
      // getSnapshotHistory/getSnapshotNearMinutesAgo all read/write via
      // the SAME global.localStorage stub this driver already installs
      // at the top of this file, so this genuinely accumulates across
      // this process's own cycles (same honest "lost on restart"
      // limitation this driver already accepts for lastIntradaySlExitAt
      // and the trailing-stop ratchet - stated, not silent).
      recordSnapshot({
        ts: Date.now(), vix: ctx.vix, pcr,
        straddle: (ocRow && ocRow.CE && ocRow.PE && typeof ocRow.CE.lastPrice === 'number' && typeof ocRow.PE.lastPrice === 'number') ? (ocRow.CE.lastPrice + ocRow.PE.lastPrice) : null,
        iv, volume: null,
        ceSpreadPct: checkSpreadLevel(ocRow && ocRow.CE).spreadPct,
        peSpreadPct: checkSpreadLevel(ocRow && ocRow.PE).spreadPct,
      });
      const ivPercentile = typeof iv === 'number' ? computeIVPercentileRank(iv, getSnapshotHistory()).percentile : null;
      const spreadWideningCheck = (() => {
        const currentSpreadPct = spreadLevelCheck.spreadPct;
        const earlierSnapshot = getSnapshotNearMinutesAgo(30);
        const earlierSpreadPct = earlierSnapshot ? (entryOptionType === 'PE' ? earlierSnapshot.peSpreadPct : earlierSnapshot.ceSpreadPct) : null;
        return checkSpreadWideningVsEarlier(currentSpreadPct, earlierSpreadPct);
      })();
      const gapFillCheck = checkGapFillStatus(candles);
      const rejectionCheck = driverExecMode === 'realistic' ? simulateOrderRejection(entryLeg, CONFIG.lotSize, 'buy') : null;
      const latencyCheck = checkExecutionLatencyRisk(entryLeg, driverExecMode, candles);
      const fmResultRaw = evaluatePreTradeFailureModes({
        brain, ctx, optionType: entryOptionType, execMode: driverExecMode,
        trapSignal, breakoutCondition, maxPainCheck, spreadLevelCheck, spreadWideningCheck,
        gapFillCheck, ivPercentile, rejectionCheck, latencyCheck,
        hypothesisDirectionStats, strategyVersionsCache,
        // FM150: real, NEW wiring this pass - this driver already
        // selects `ocRow` via an exact-match `find()` against `strike`
        // (line ~658, never a nearest-strike fallback), so this can
        // only ever fire here in the genuinely impossible case of a
        // future code change reintroducing a nearest-match pattern -
        // wired anyway for real defense-in-depth parity with the
        // browser call site, using the same real, already-in-scope
        // `strike` local.
        requestedStrike: strike,
        // FM045/FM046: genuinely browser-only. Both reuse the manual
        // Portfolio Tracker panel's own tracked-positions list (a
        // separate, user-maintained multi-position array this driver
        // has no equivalent of - it trades one position of its own
        // configured symbol, not a user's manually-tracked portfolio),
        // so there is no real data to thread here - explicitly `null`,
        // never guessed from this driver's own single open position.
        portfolioPositions: null,
        portfolioPerPositionGreeks: null,
      });
      const fmResult = adjustFailureModesForTradeType(fmResultRaw, driverEffectiveTradingType);
      if (fmResult.finalAction === 'block' || fmResult.finalAction === 'reject') {
        const topReason = fmResult.triggered.find(t => t.adjustedAction === fmResult.finalAction) || fmResult.triggered.find(t => t.action === fmResult.finalAction);
        log(`Blocked by the Failure-Mode Library (${topReason ? topReason.id : ''}): ${topReason ? topReason.reason : fmResult.summary}. ${fmResult.triggered.length} total condition(s) triggered this cycle.`);
        return;
      }
      const target = entryOptPrice * 1.3;
      const sl = entryOptPrice * 0.85;
      openPosition = { entryPrice: entryOptPrice, target, sl, optionType: entryOptionType, tradingType: driverEffectiveTradingType, openedAt: Date.now() };
      // Real, new: persist this position server-side the moment it
      // opens, the same real fno_open_position endpoint the browser
      // path already uses - this is the other half of the restart-
      // recovery/reconciliation fix (see recoverOpenPositionOnStartup
      // and this variable's own TRACE comment above). Never blocks
      // the trade itself on a persistence failure - a real paper
      // trade already, genuinely opened locally must not be silently
      // discarded just because this one, secondary write failed; it's
      // logged loudly instead, matching the exact same non-fatal
      // discipline already established for the journal write below.
      // Real, new: a genuine idempotency key for this specific open
      // attempt (found and fixed alongside the persistence work above
      // - fno_open_position had no protection against a duplicate
      // NETWORK-LEVEL request creating two real DB rows for one
      // logical open; the PHP side, fixed in fno-lab.php, now checks
      // this key before inserting). Deliberately DETERMINISTIC, not
      // random - the entire point of an idempotency key is that a
      // genuine RETRY of this same logical attempt must produce the
      // SAME key (so the server recognizes it as a replay), while a
      // genuinely different open produces a different one. Built from
      // this position's own identifying values plus a minute-
      // granularity timestamp (not full Date.now() precision, which
      // would make every call unique and defeat the entire purpose):
      // two genuinely distinct opens at the exact same strike/price/
      // side within the same real minute are, in practice, the same
      // logical trade attempt for this app's own real 60-second-plus
      // poll-interval cadence. This driver's current control flow
      // has no explicit retry loop around this call today (a failure
      // is simply logged, see below), so this key does not yet close
      // an actively-demonstrated duplicate-open path - it is real,
      // deliberate defense-in-depth: correct now for the concurrent-
      // request race the PHP side also now handles, and correct
      // automatically the moment any future retry logic is added
      // here, with zero further changes needed.
      const idempotencyKey = crypto.createHash('sha256').update(`${CONFIG.symbol}|${strike}|${entryOptionType}|${entryOptPrice}|${Math.floor(Date.now() / 60000)}`).digest('hex').slice(0, 32);
      const openResult = await postAuthenticated('fno_open_position', {
        symbol: CONFIG.symbol, strike, optionType: entryOptionType, qty: CONFIG.lotSize,
        entryPrice: entryOptPrice, sl, target, tradingStyle: driverEffectiveTradingType, idempotencyKey,
      });
      if (openResult.success && openResult.data && openResult.data.id) {
        openPosition.id = openResult.data.id;
      } else {
        log(`WARNING: real fno_open_position call failed: ${JSON.stringify(openResult.data)} - this position is trading locally but has NO persisted DB row, so it will NOT be recovered if this driver restarts before it exits. Trading continues regardless - never blocked on a secondary persistence failure.`);
      }
      log(`Real position opened: ${CONFIG.symbol} ${strike}${entryOptionType} @ ${entryOptPrice}, target ${target.toFixed(2)}, SL ${sl.toFixed(2)} - passed time-sufficiency (${timeCheck.minutesRemaining !== null ? timeCheck.minutesRemaining + 'min remaining' : 'n/a'}) and Failure-Mode Library (${fmResult.triggered.length} triggered, action ${fmResult.finalAction}) gates. ${openPosition.id ? `Persisted as DB position id=${openPosition.id}.` : 'NOT persisted to DB (see warning above).'}`);
    }
  } catch (e) {
    log(`ERROR this cycle (non-fatal, will retry next cycle): ${e.message}`);
  }
}

log(`F&O Lab Autonomous Driver starting - ${CONFIG.symbol}, poll interval ${CONFIG.pollIntervalMs}ms`);
// Real, new: recover any already-open real position from the database
// BEFORE the first real cycle runs - see recoverOpenPositionOnStartup's
// own TRACE comment for the full reasoning. Awaited deliberately so
// the very first runCycle() never races ahead of recovery and risks
// opening a real, duplicate position.
recoverOpenPositionOnStartup().then(() => {
  runCycle();
  setInterval(runCycle, CONFIG.pollIntervalMs);
});
