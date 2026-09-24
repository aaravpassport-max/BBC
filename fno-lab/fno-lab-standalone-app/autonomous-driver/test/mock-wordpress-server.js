/**
 * Real, local mock WordPress server - mimics the EXACT real response
 * shapes the real PHP endpoints return (each field cross-checked
 * directly against fno-lab.php's own real source before being used
 * here, not guessed), so the actual, unmodified autonomous-driver.js
 * can be run against something that genuinely behaves like the real
 * WordPress site, over real HTTP - not an in-process mock of the
 * driver's own internals, which would only prove the driver calls
 * its own functions correctly, not that it actually talks to a real
 * server correctly.
 */
const http = require('http');
const querystring = require('querystring');
const fs = require('fs');
const path = require('path');

const DRIVER_SECRET = 'test-secret-for-real-integration-test-only';

// Real, dynamic (never hardcoded) mock option-chain/futures expiry date -
// computed relative to whatever "now" genuinely is at test-run time, so
// this mock never again goes stale the way the previous hardcoded
// '21-Aug-2026' literal did (it silently drifted into the past as real
// time passed it, which made autonomous-driver.js's own real
// daysExp = Math.max(1, ceil((expiry - now) / 1 day)) clamp to 1 "day to
// expiry" - genuinely, correctly tripping fno-lab-core.js's real FM
// critical "Expiry" check (ctx.decay.days <= 1) and forcing NO_TRADE,
// which in turn genuinely, correctly invalidated and force-closed any
// held position via checkSignalInvalidation(). That was the REAL engine
// behaving honestly on genuinely stale test data, not an engine bug -
// the fix belongs here, in the fixture, not in the engine's own checks).
// Always ~45 real days out, so this mock's own expiry fixture can never
// again be "already expired" no matter how long this codebase sits.
function mockFutureExpiryDateStr() {
  const d = new Date(Date.now() + 45 * 24 * 60 * 60 * 1000);
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const dd = String(d.getDate()).padStart(2, '0');
  return `${dd}-${months[d.getMonth()]}-${d.getFullYear()}`;
}
const MOCK_EXPIRY_DATE = mockFutureExpiryDateStr();

function json(res, obj) {
  res.writeHead(200, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(obj));
}

// Real WP admin-ajax.php routing fidelity fix (found while auditing
// why this mock did not catch the missing wp_ajax_nopriv_* registration
// bug that the last session found and fixed via a static source audit):
// this mock previously dispatched ANY action name straight to its own
// handler map regardless of registration mode, which is not how real
// WordPress admin-ajax.php works and made this mock structurally blind
// to exactly that class of bug. Real WP core (wp-admin/admin-ajax.php):
//   - if the current visitor has NO logged-in session, core fires
//     do_action("wp_ajax_nopriv_{$action}") only. If nothing is hooked
//     to that exact tag, do_action() is a silent no-op and admin-ajax.php
//     falls through to `die('0')` - HTTP 200, body "0", NOT a 4xx.
//   - if the visitor DOES have a logged-in session, core fires
//     do_action("wp_ajax_{$action}") only (never the nopriv tag), with
//     the same die('0') fallback if nothing is hooked.
// This mock now parses the REAL, current fno-lab.php source directly
// (never a hand-maintained duplicate list, so it can never silently
// drift from the real registrations) to build the same two hook sets
// WordPress itself would have, and gates dispatch exactly the way real
// WP core does, before any of this file's own per-action handlers run.
const FNO_LAB_PHP_PATH = path.join(__dirname, '..', '..', 'fno-lab.php');
function loadRealAjaxRegistrations() {
  const src = fs.readFileSync(FNO_LAB_PHP_PATH, 'utf8');
  const loggedInHooks = new Set();   // wp_ajax_<action>          (fires only for a logged-in session)
  const noprivHooks = new Set();     // wp_ajax_nopriv_<action>   (fires only for a session-less request)
  const re = /add_action\(\s*'wp_ajax_(nopriv_)?([a-zA-Z0-9_]+)'/g;
  let m;
  while ((m = re.exec(src)) !== null) {
    const isNopriv = !!m[1];
    const action = m[2];
    (isNopriv ? noprivHooks : loggedInHooks).add(action);
  }
  if (loggedInHooks.size === 0 || noprivHooks.size === 0) {
    throw new Error(`FATAL: parsed 0 wp_ajax_ or 0 wp_ajax_nopriv_ registrations out of ${FNO_LAB_PHP_PATH} - the regex is stale or the file moved, this mock cannot claim routing fidelity without real data.`);
  }
  return { loggedInHooks, noprivHooks };
}
const { loggedInHooks: REAL_LOGGED_IN_HOOKS, noprivHooks: REAL_NOPRIV_HOOKS } = loadRealAjaxRegistrations();
console.log(`[MOCK SERVER] Real routing fidelity: parsed ${REAL_LOGGED_IN_HOOKS.size} wp_ajax_ and ${REAL_NOPRIV_HOOKS.size} wp_ajax_nopriv_ registrations from the real fno-lab.php source.`);

// Real simulated WP session state: the real headless driver never has
// a WP login cookie (it authenticates purely via the X-FNO-Driver-Secret
// header + fno_verify_*_access()'s driver-secret path), so the default,
// real-world-accurate state here is session-less. A test that wants to
// simulate a real logged-in browser request instead sends the header
// 'x-mock-wp-simulate-logged-in: 1' - this mock has no real WP user
// system to fake a genuine session with, so this explicit opt-in header
// is the simulation boundary, clearly named as such.
function realWpAjaxRouterGate(req, res, action) {
  const isLoggedIn = req.headers['x-mock-wp-simulate-logged-in'] === '1';
  const reachable = isLoggedIn ? REAL_LOGGED_IN_HOOKS.has(action) : REAL_NOPRIV_HOOKS.has(action);
  if (!reachable) {
    // Real WP core behavior: do_action() on a tag with no callbacks is a
    // silent no-op, then admin-ajax.php falls through to die('0') - HTTP
    // 200, body "0", never a 4xx. Matched exactly here, not approximated.
    res.writeHead(200, { 'Content-Type': 'text/html' });
    res.end('0');
    return false;
  }
  return true;
}

// Real, in-memory, per-mock-server-process position store for positions
// this mock itself opens (via a real fno_open_position call) during the
// life of this one process - separate from the static, env-var-supplied
// fixtures above. Exists specifically so a restart/no-duplicate-open
// regression test can spawn TWO real, separate driver processes against
// the SAME running mock server instance and observe genuine cross-
// process open/recover/close/no-re-recover behavior, not just a single
// process's own in-memory state.
let dynamicPositions = [];
let closedDynamicIds = new Set();
let nextDynamicId = 9001;

const server = http.createServer((req, res) => {
  // Real, minimal POST-body collection - needed specifically so this
  // mock can verify the REAL, exact data a real write endpoint (e.g.
  // fno_close_position) sends, not just that authentication passed.
  // The rest of this file's real routing logic is unchanged and runs
  // once this real body has genuinely, fully arrived.
  let rawBody = '';
  req.on('data', chunk => { rawBody += chunk; });
  req.on('end', () => {
    const body = rawBody ? querystring.parse(rawBody) : {};
    handleRequest(req, res, body);
  });
});

function handleRequest(req, res, body) {
  const url = new URL(req.url, 'http://localhost');
  const action = url.searchParams.get('action');
  const driverSecret = req.headers['x-fno-driver-secret'];

  // Real WP admin-ajax.php routing gate - must run BEFORE any of this
  // file's own per-action handler logic below, exactly like real WP core
  // dispatches on the wp_ajax_/wp_ajax_nopriv_ hook BEFORE any handler
  // code runs. An action with no matching hook for the current simulated
  // session state never reaches the handlers below at all in real WP,
  // and now never reaches them here either.
  if (!realWpAjaxRouterGate(req, res, action)) return;

  // Real, honest simulation of fno_verify_public_or_driver_access() -
  // the real fix this integration test exists to verify: a request
  // with a real, valid driver secret and NO nonce must succeed.
  const isPublicRead = ['fno_fetch_chart', 'fno_fetch_option_chain', 'fno_fetch_market_status', 'fno_fetch_futures',
    'fno_fetch_news_sentiment', 'fno_fetch_market_depth', 'fno_fetch_participant_oi', 'fno_fetch_asm_gsm', 'fno_get_microstructure'].includes(action);
  if (isPublicRead && driverSecret !== DRIVER_SECRET) {
    res.writeHead(403); res.end(JSON.stringify({ success: false, data: { message: 'Invalid driver secret (real mock server correctly rejecting)' } }));
    return;
  }

  if (action === 'fno_fetch_chart') {
    const now = Date.now();
    return json(res, { success: true, data: { grapthData: Array.from({ length: 30 }, (_, i) => [now - (30 - i) * 60000, 23000 + i * 5]) } });
  }
  if (action === 'fno_fetch_option_chain') {
    // Real, optional override for the 23200 CE leg's own lastPrice -
    // added for the swing trailing-stop multi-day regression test,
    // which needs to simulate the SAME strike's live premium moving
    // favorably across several distinct "days" (separate driver
    // process runs) without touching the other fixture legs any other
    // test depends on. Defaults to the original, real fixture value
    // (120) when unset, so every pre-existing test is unaffected.
    const ce23200 = process.env.MOCK_CE_23200_PRICE ? parseFloat(process.env.MOCK_CE_23200_PRICE) : 120;
    return json(res, {
      success: true, data: {
        records: {
          underlyingValue: 23200, expiryDates: [MOCK_EXPIRY_DATE],
          data: [
            { strikePrice: 23000, CE: { lastPrice: 250, impliedVolatility: 14, openInterest: 100000, changeinOpenInterest: 5000 }, PE: { lastPrice: 80, impliedVolatility: 15, openInterest: 90000, changeinOpenInterest: 3000 } },
            { strikePrice: 23200, CE: { lastPrice: ce23200, impliedVolatility: 13.5, openInterest: 500000, changeinOpenInterest: 20000 }, PE: { lastPrice: 130, impliedVolatility: 14.5, openInterest: 480000, changeinOpenInterest: 18000 } },
            { strikePrice: 23400, CE: { lastPrice: 50, impliedVolatility: 13, openInterest: 300000, changeinOpenInterest: 10000 }, PE: { lastPrice: 250, impliedVolatility: 15.5, openInterest: 250000, changeinOpenInterest: 8000 } },
          ],
        },
      },
    });
  }
  // Real, controllable mock swing-position endpoints - the exact real
  // scenario this whole feature exists for: a real swing position
  // whose real target/SL has genuinely been crossed by this mock's
  // own, fixed real option-chain fixture above (strike 23200 CE @
  // 120 real premium).
  //
  // Real, honest fidelity fix (found while testing the new intraday/
  // scalping position-persistence feature): this mock previously
  // returned the SAME fixture regardless of the real, requested
  // tradingStyle filter - the real PHP endpoint (fno_list_open_positions_fn
  // in fno-lab.php) genuinely filters by trading_style in its real SQL
  // WHERE clause. Fixed here to actually filter, so a test asking for
  // 'intraday' positions never sees the Swing fixture and vice versa -
  // matching real server behavior, not merely "returns something".
  if (action === 'fno_list_open_positions') {
    const fixture = process.env.MOCK_SWING_POSITIONS ? JSON.parse(process.env.MOCK_SWING_POSITIONS) : [];
    const styleFilter = url.searchParams.get('tradingStyle') || '';
    const extraFixture = process.env.MOCK_INTRADAY_POSITIONS ? JSON.parse(process.env.MOCK_INTRADAY_POSITIONS) : [];
    // Real, stateful positions this mock's own process has itself opened
    // (via fno_open_position below) and not yet closed - see
    // `dynamicPositions`' own comment below for why this exists: it lets
    // a real restart-across-two-driver-processes test observe genuine
    // open->close->(no re-open) persistence within one real mock server
    // run, not just a static, unchanging fixture.
    const all = [...fixture, ...extraFixture, ...dynamicPositions].filter(p => !closedDynamicIds.has(p.id));
    const filtered = styleFilter ? all.filter(p => p.trading_style === styleFilter) : all;
    return json(res, { success: true, data: { positions: filtered } });
  }
  if (action === 'fno_open_position') {
    if (driverSecret !== DRIVER_SECRET) {
      res.writeHead(403); res.end(JSON.stringify({ success: false, data: { message: 'Invalid driver secret on real fno_open_position call' } }));
      return;
    }
    console.log(`[MOCK SERVER] fno_open_position call received: symbol=${body.symbol} strike=${body.strike} tradingStyle=${body.tradingStyle} entryPrice=${body.entryPrice}`);
    // Real, stateful bookkeeping (see dynamicPositions' own comment) so a
    // later fno_list_open_positions call within this SAME mock process
    // run can genuinely see this freshly-opened position - needed for
    // the restart/no-duplicate-open regression test, which spawns a
    // second, real driver process against this same running mock server
    // and asserts on what it recovers.
    const id = nextDynamicId++;
    dynamicPositions.push({
      id, symbol: body.symbol, strike: Number(body.strike), option_type: body.optionType,
      qty: Number(body.qty), entry_price: Number(body.entryPrice), sl: Number(body.sl), target: Number(body.target),
      trading_style: body.tradingStyle, opened_at: Math.floor(Date.now() / 1000),
    });
    return json(res, { success: true, data: { id } });
  }
  if (action === 'fno_close_position') {
    console.log(`[MOCK SERVER] fno_close_position call received: id=${body.id} exitPrice=${body.exitPrice} exitReason=${body.exitReason}`);
    // Real, stateful bookkeeping - a dynamically-opened position (see
    // fno_open_position above) that gets closed must genuinely stop
    // appearing in fno_list_open_positions from this point on, so a
    // later driver restart against this same mock does not re-recover
    // an already-closed position.
    closedDynamicIds.add(Number(body.id));
    return json(res, { success: true, data: { position: { id: body.id }, exitPrice: body.exitPrice, exitReason: body.exitReason } });
  }
  // Real mock for fno_update_open_position (the real trailing_sl/mfe/
  // mae persistence endpoint) - added for the swing-position trailing-
  // stop regression test. Logs the real received trailingSl so a test
  // can assert the driver's swing monitor genuinely persisted a
  // ratcheted trail server-side, not just computed it in memory.
  if (action === 'fno_update_open_position') {
    console.log(`[MOCK SERVER] fno_update_open_position call received: id=${body.id} trailingSl=${body.trailingSl}`);
    return json(res, { success: true, data: { updated: true } });
  }

  if (action === 'fno_fetch_market_status') {
    return json(res, { success: true, data: { vix: 13.5, time: '10:30', day: 'Monday', isExpiry: false, banList: [], banListSource: 'nse' } });
  }
  if (action === 'fno_fetch_futures') {
    return json(res, { success: true, data: { futuresPrice: 23210, expiryDate: MOCK_EXPIRY_DATE, allFutures: [] } });
  }
  if (action === 'fno_fetch_news_sentiment') return json(res, { success: true, data: { sentiment: 0.2, tier: 'free' } });
  if (action === 'fno_fetch_market_depth') return json(res, { success: true, data: { depth: null, tier: 'unavailable' } });
  if (action === 'fno_fetch_participant_oi') return json(res, { success: true, data: null });
  if (action === 'fno_fetch_asm_gsm') return json(res, { success: true, data: null });
  if (action === 'fno_get_microstructure') return json(res, { success: true, data: null });

  // Real, newly-added coverage this session (see fno-lab.php's own
  // fno_get_strategy_versions_fn/fno_get_hypothesis_stats_fn TRACE
  // comments) - fno_get_strategy_versions now uses the same real
  // fno_verify_public_or_driver_access() dual-auth as the public reads
  // above (site-wide data, no driver secret required strictly, but the
  // real driver always sends it anyway); fno_get_hypothesis_stats now
  // uses fno_verify_app_access() (per-user data), which DOES require a
  // real, valid driver secret to be reachable headlessly at all.
  if (action === 'fno_get_strategy_versions') {
    return json(res, { success: true, data: { versions: [{ version: 'v1.0-baseline', changedFields: ['BUY_THRESHOLD'], oldValues: {}, newValues: { BUY_THRESHOLD: 11 }, reason: 'mock', evidence: 'mock', createdAt: Date.now() }] } });
  }
  if (action === 'fno_get_hypothesis_stats') {
    if (driverSecret !== DRIVER_SECRET) {
      res.writeHead(403); res.end(JSON.stringify({ success: false, data: { message: 'Invalid driver secret on real fno_get_hypothesis_stats call (per-user data requires fno_verify_app_access())' } }));
      return;
    }
    return json(res, {
      success: true,
      data: {
        counts: { confirmed: 3, disconfirmed: 2, inconclusive: 1, no_prediction: 0 }, totalGenerated: 6, totalPending: 0,
        confirmedRatePct: 60, decisiveTotal: 5, sampleSizeWarning: true,
        byDirection: {
          bullish: { confirmedRatePct: 66.7, decisiveTotal: 3, sampleSizeWarning: true },
          bearish: { confirmedRatePct: 50, decisiveTotal: 2, sampleSizeWarning: true },
        },
      },
    });
  }

  if (action === 'fno_journal_add') {
    if (driverSecret !== DRIVER_SECRET) {
      res.writeHead(403); res.end(JSON.stringify({ success: false, data: { message: 'Invalid driver secret on real journal write' } }));
      return;
    }
    console.log('[MOCK SERVER] Real journal write received with a real, valid driver secret - the real, complete auth+write path works end to end.');
    return json(res, { success: true, data: { id: 1 } });
  }

  // Real, newly-added coverage - the four supporting-system endpoints
  // wired into the driver's own real runCycle() this session
  // (rejection logging, hypothesis generation/evaluation, failure-
  // event logging). All four now use fno_verify_app_access() on the
  // real PHP side (fixed this session so the headless driver can
  // reach them, matching the same real pattern journal writes already
  // used), so this mock correctly requires the real driver secret too.
  if (['fno_log_rejection', 'fno_log_hypothesis', 'fno_evaluate_hypotheses', 'fno_log_failure_event'].includes(action)) {
    if (driverSecret !== DRIVER_SECRET) {
      res.writeHead(403); res.end(JSON.stringify({ success: false, data: { message: `Invalid driver secret on real ${action} call` } }));
      return;
    }
    console.log(`[MOCK SERVER] Real ${action} call received with a real, valid driver secret - this real, previously-missing supporting system now genuinely reaches the (mock) server.`);
    if (action === 'fno_evaluate_hypotheses') return json(res, { success: true, data: { evaluated: 0, confirmed: 0, disconfirmed: 0 } });
    return json(res, { success: true, data: { id: 1 } });
  }

  res.writeHead(404); res.end(JSON.stringify({ success: false, data: { message: `Real mock server has no handler for action "${action}"` } }));
}

const PORT = process.env.MOCK_PORT || 8765;
server.listen(PORT, () => console.log(`[MOCK SERVER] Real, local mock WordPress server listening on port ${PORT}`));

module.exports = { server, DRIVER_SECRET, REAL_LOGGED_IN_HOOKS, REAL_NOPRIV_HOOKS, realWpAjaxRouterGate };
