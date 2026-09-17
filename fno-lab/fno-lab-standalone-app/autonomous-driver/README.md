# F&O Lab - Autonomous Driver

## Why this is a separate program, not more WordPress code

The in-browser Autonomous Mode (on the main app page) already runs the
full **observe → analyse → decide → trade → monitor → exit → learn**
cycle automatically. But it runs as a JavaScript interval inside a
browser tab - it stops the moment that tab closes. That's not a bug to
patch; it's what browsers are. A tab cannot keep running code once
you close it, the same real reason the companion daemon (a separate
program in this plugin's `companion-daemon/` folder) exists for
tick-level data.

This driver is the real, honest fix for that specific limitation: a
small, standalone Node.js process - run separately from any browser,
on the same server, a different server, or a small cloud VM - that
runs the exact same core decision cycle, genuinely unattended. This is
the closest this app comes to the founding vision document's own
explicit goal: *"I want to start the experimental system, provide/
approve the required API connections and let it run."*

## What this actually does

Every real polling interval (default: 1 minute, the same real interval
the browser's Autonomous Mode already uses), this script:

1. Fetches real, live NSE data (chart, option chain, market status,
   futures) via the exact same real, public WordPress endpoints the
   browser app itself calls.
2. Builds a real market context and calls `evaluateBrain()` - the
   **exact same, already-tested** decision function from
   `assets/fno-lab-core.js`, extracted directly from that file at
   startup, never re-implemented or copied by hand (so it can never
   silently drift from the real, tested logic the browser app uses).
3. If a real position is already open, checks real exit conditions
   using the same tested `checkTradeExit()` (against a trade-type-aware
   trailing stop when `FNO_TRAILING_ENABLED=1`, same as below), then a
   genuine signal-invalidation check (`checkSignalInvalidation()` -
   closes early if the live, re-evaluated signal has reversed
   direction, distinct from a target/SL exit), then the square-off
   deadline.
4. If no position is open, the decision is BUY_READY or SELL_READY,
   and a real re-entry cooldown isn't currently active for this trade
   type (`checkReEntryCooldown()` - blocks a new entry for a while
   right after a stop-loss exit), opens a real, in-memory paper
   position - the same real pattern the browser's own `refreshBrain()`
   already uses.
5. When a real position closes, persists the completed trade to your
   real WordPress journal and closes its DB row - genuinely durable,
   survives this script restarting. If either of those two writes
   fails (a real network/server hiccup), it's queued and retried
   automatically at the start of every subsequent cycle, up to 5
   attempts, before being honestly logged as given up rather than
   silently lost or retried forever.

## What this honestly does NOT do (yet)

- **A few more exotic, premium-only real inputs are not yet wired
  in**: News Sentiment, Market Depth, Participant OI, Microstructure,
  ASM/GSM. `evaluateBrain()` already handles a genuinely missing input
  honestly (null, not guessed) - the same discipline this whole app
  follows everywhere - so this is a real, safe, honest degradation,
  not a silent gap. The core signals this app's decision engine most
  depends on (price, OI, IV, Greeks, futures, regime) are all real and
  covered.
- **CLOSED this session: FM-coverage parity with the browser is now
  effectively complete.** All 11 `evaluatePreTradeFailureModes()`
  inputs the browser supplies are now wired into this driver, including
  the final 2 (`hypothesisDirectionStats`, `strategyVersionsCache` -
  feeding FM083/FM084 and FM069/FM106 respectively) that were
  previously genuinely browser-only. The real blocker was PHP-side
  auth: `fno_get_hypothesis_stats_fn()` hard-required a logged-in
  browser session, and `fno_get_strategy_versions_fn()` gated on the
  non-driver-secret-aware `fno_verify_app_nonce()`. Fixed by swapping
  `fno_get_strategy_versions_fn()` (`fno-lab.php` ~line 4249, site-wide
  data, no per-user scoping) to the same `fno_verify_public_or_driver_access()`
  dual-auth several other read endpoints already use, and
  `fno_get_hypothesis_stats_fn()` (`fno-lab.php` ~line 4872, genuinely
  per-user data - `WHERE user_id = get_current_user_id()`) to
  `fno_verify_app_access()`, the same pattern the driver's other
  per-user writes already use (it calls `wp_set_current_user()` against
  the real, configured driver user on a valid secret, so the per-user
  query is correctly scoped rather than silently reading `user_id=0`).
  `fno_get_hypothesis_stats` was also given its missing
  `wp_ajax_nopriv_` registration - required for a session-less driver
  request to ever reach it at all. Both real browser-session paths are
  provably unchanged (see
  `tests/php/StrategyVersionsAndHypothesisStatsAuthTest.php`). This
  driver now fetches both endpoints every real cycle (same
  `Promise.all` block as the other market-data reads,
  `autonomous-driver.js` ~line 579) - no TTL cache, matching this
  driver's own established no-caching precedent for every other
  per-cycle fetch. One honest, remaining difference from the browser:
  the browser's caches persist for the life of the page load / until
  its own periodic refresh runs, while this driver's are refetched
  fresh every single cycle - a real, minor cache-staleness-window
  difference, not a coverage gap (see
  `docs/PENDING_REQUIREMENTS.md` §3c for the full note).
- **Real target/stop-loss on a newly-opened position use a simple,
  documented automatic default** (+30%/-15% of entry premium).
  Checked directly before writing this: the browser app's own target/
  SL isn't some richer automatic calculation either - it's literally
  whatever real value a user manually types into the app's own target/
  SL input fields (optionally scaled by a trade-type-aware default when
  the browser's own opt-in "Trade-type-aware default target/SL"
  setting is on - see the main app's Settings → Trading Controls). This
  driver's default is fixed at the neutral, unscaled +30%/-15% - it has
  no trade-type selection to scale against, since it only ever trades
  as `'intraday'` (see the code comment above `driverEffectiveTradingType`
  in `autonomous-driver.js`); this is a deliberate, honest choice, not
  an oversight - applying that browser-side multiplier here would
  always compute the same neutral value anyway.
- **Position size is fixed at your configured `FNO_LOT_SIZE`** for
  every trade this driver opens - it does not offer the browser app's
  own opt-in trade-type-aware sizing, for the same reason as above
  (this driver only ever trades as `'intraday'`).
- **DB-vs-broker active reconciliation - the precondition is now
  built and tested (this session), closing the prior gap.** This
  driver still persists a real DB row the moment a position opens and
  closes it the moment it exits (with automatic retry on a failed
  write - see above); the WARNING log on an exhausted close-retry
  remains your immediate signal for THIS driver's own paper-trading
  DB rows (`wp_fno_open_positions`), which have no real broker
  counterpart at all since this driver only ever paper-trades.
  For the genuinely separate real-money path, `fno_broker_get_positions()`
  / `fno_broker_get_positions_zerodha()` (`fno-lab.php`, right after
  `fno_broker_place_order_zerodha()`) now exists - a real, read-only
  Kite Connect `GET /portfolio/positions` call built with the exact
  same auth/credential/error-handling discipline as the existing order
  -placement function, parsing Kite Connect API v3's own real,
  documented response shape. `fno_compare_broker_positions()` does the
  real matched/phantom-open/broker-only comparison against
  `wp_fno_real_money_journal` (never the paper-trading table - paper
  trades never reach a real broker), and `fno_reconcile_real_money_positions_fn()`
  (AJAX action `fno_reconcile_real_money_positions`) is a real,
  explicit, user/admin-triggered endpoint that runs the full
  fetch-compare-log cycle, durably logging every discrepancy to the
  existing `wp_fno_failure_events` table.
  **Deliberately NOT wired to any cron hook or scheduler by this
  driver or the plugin itself** - it stays a real, callable,
  explicitly-triggered check (an admin button, or a WP-Cron event you
  add yourself), consistent with real-money trading remaining
  structurally, globally disabled at the source level
  (`FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED`, FM135) - this driver
  does not install unattended broker-account polling on its own. 28
  new PHP regression tests (`tests/php/BrokerPositionsReconciliationTest.php`)
  mock the Kite response the same way `BrokerOrderMarketHoursTest.php`
  already mocks order placement; see
  `docs/TRADING_KNOWLEDGE_BASE.md` §8 for the full row.
- **Does not itself survive a server reboot** - use a real process
  manager (`pm2 start autonomous-driver.js`, or a real `systemd`
  service) if you want it to restart automatically. This script is
  the real driver logic; keeping it *running* across reboots is a
  real, standard ops concern, the same as for the companion daemon.

## Setup

1. In WordPress Admin, go to **Settings → F&O Lab Providers**, and
   find the **Autonomous Driver** section near the bottom.
2. Choose which real WordPress user the driver's real trades should be
   attributed to, and click **Save Driver User**.
3. Copy the real **Driver Secret** and **Site URL** shown on that page.
4. In this folder, copy `.env.example` to `.env` and fill in those two
   real values (plus your real symbol/strike/lot-size preferences).
   Optionally set `FNO_TRAILING_ENABLED=1` to enable the trade-type-
   aware trailing stop described above (off by default).
5. `npm install`
6. `npm start` (or `pm2 start autonomous-driver.js --name fno-driver`
   for real, unattended operation across reboots)

## Security

The Driver Secret authenticates this script to write real trades to
your real WordPress journal without a browser login - treat it exactly
like an API key. It is genuinely separate from the companion daemon's
own secret (different real service, different real scope: the daemon
only ever ingests tick data; this driver can read market data AND
write real trades, a materially broader scope, so keeping the two
secrets separate is a real, deliberate security boundary, not an
oversight).

The Driver Secret is sent on every request as the `X-FNO-Driver-Secret`
header (see `autonomous-driver.js`). If `FNO_SITE_URL` in your `.env`
is set to a plain `http://` address, that header - and every trade
this driver writes - travels over the network unencrypted, readable to
anyone on the same network path. Use a real `https://` URL for
`FNO_SITE_URL` in any production or unattended deployment; `http://`
should only ever be used for local testing against a site running on
`localhost`/`127.0.0.1`. This code does not enforce HTTPS itself, so
this is on you to get right.

`.env` holds your real Driver Secret in plaintext. It is covered by
this directory's `.gitignore` - never commit it, and never paste its
contents anywhere outside your own machine.

## Known data-quality issue in journal rows written by an older driver build

If you deployed and ran this driver **before** the entry/exit-price
journal fix landed, read this and check your own `wp_fno_journal`
table.

**The bug (now fixed):** every exit-side journal write built its POST
body with fields named `entry`/`exit`. `fno_journal_add_fn()` in
`fno-lab.php` has only ever read `$_POST['entry_price']` /
`$_POST['exit_price']` - it never read `entry`/`exit` at all. Both
columns use `isset($_POST[...]) ? ... : null`, so every journal row
this driver wrote before the fix landed silently stored
`entry_price = NULL` and `exit_price = NULL`. This was not a crash and
not a rejected request - the row was written, just with those two
columns empty.

**What was verified about the real blast radius, with exact evidence:**

- `pnl` was **not** affected. The driver computes `pnl` itself,
  independently, from its own local `optPrice`/`openPosition.entryPrice`
  values (`const pnl = (optPrice - openPosition.entryPrice) * ...` in
  `autonomous-driver.js`) and sends that already-computed number as its
  own POST field - it was never derived server-side from the broken
  `entry`/`exit` fields. A pre-fix row has a correct, real `pnl`; only
  the two display/audit columns are empty.
- The DB schema (`fno-lab.php`, `wp_fno_journal` CREATE TABLE) declares
  `entry_price DECIMAL(10,2) NULL` / `exit_price DECIMAL(10,2) NULL`,
  so the bad writes were accepted, not rejected - a pre-fix row really
  does sit in your table with those two columns NULL.
- Every downstream analytics function that drives win-rate / calibration
  / regime stats (`computeRegimeWinRate`, `computeCalibrationBuckets` in
  `assets/fno-lab-core.js`) keys strictly off `trade.pnl`, never off
  `entry_price`/`exit_price` - so those stats are unaffected by pre-fix
  rows.
- The Trade Ledger UI already tolerates a NULL entry/exit price per row
  (`hasFullPriceData` check, `assets/fno-lab-core.js`) and shows `-`
  plus a `(partial data)` tag instead of crashing or fabricating a
  value.

**Net effect:** a driver-written row from before this fix has a
correct, trustworthy `pnl`, but its `entry_price`/`exit_price` columns
- and only those two, display-only columns - are empty. Nothing needs
to be deleted or rewritten. To identify which rows those are in your
own database, run (read-only):

```sql
SELECT id, trade_ts, symbol, strike, option_type, pnl, source
FROM wp_fno_journal
WHERE entry_price IS NULL AND exit_price IS NULL AND pnl IS NOT NULL
ORDER BY trade_ts DESC;
```

Those rows are safe to keep as-is; their `pnl` is real. Their
entry/exit price columns just won't display a value in the ledger.

## Real, honest current status

This is a genuinely new, v1 capability, built and dry-run verified
against realistic mock market data (not yet run against a real, live
WordPress site, since none was available to test against directly).
Start it in paper mode, watch its console output for a few real
cycles, and confirm its real decisions and any real, opened/closed
positions look correct in your real WordPress journal before relying
on it for extended, unattended runs.
