# F&O Lab - Microstructure Companion Daemon

## Why this is a separate program, not more WordPress code

Seven catalogued Microstructure factors (Iceberg Orders, Cumulative Delta,
Volume Profile POC, Order Flow Imbalance, Footprint, Tick Speed, DOM
Ladder - Spoofing Orders) need a **persistent, continuously-open
connection** to a tick-by-tick market data stream. A WordPress AJAX
handler is a single HTTP request that starts, responds, and terminates -
it cannot hold a WebSocket connection open between two separate page
loads. That's not a missing feature to code around inside the plugin;
it's a fundamental property of the request/response model PHP request
handlers run in.

This daemon is the real, differently-shaped solution: a small,
long-running Node.js process, run separately from WordPress (on the same
server, a different server, or your own laptop while you trade - anywhere
that can reach both Kite's servers and your WordPress site), that:

1. Connects to Kite Connect's WebSocket ticker and keeps that connection
   open for as long as it runs.
2. Computes the seven metrics above from real live ticks.
3. Posts a microstructure snapshot to your WordPress site every few
   seconds.
4. **Also buffers every raw tick it receives and bulk-posts them
   separately** to a real, permanent raw-tick store (`wp_fno_raw_ticks`,
   MySQL-based, retained 7 days then automatically pruned) - this is a
   genuinely different capability from #2/#3 above: not a live computed
   metric, but the underlying raw observation itself, kept for later
   analysis/replay. **No separate setup needed** - it uses the exact
   same `wpSiteUrl`/`ingestSecret` config fields you already set up
   below, and starts automatically the moment you run this daemon on a
   config from this README.

The plugin reads whatever this daemon last posted and honestly shows
"daemon offline" if it's not running, or if its last snapshot is more
than 30 seconds old. Running or not running this daemon changes nothing
else about the rest of the app - no code changes needed either way.

## Setup

```bash
cd companion-daemon
npm install
cp config.example.json config.json
```

Edit `config.json`:

| Field | Where to get it |
|---|---|
| `kiteApiKey` | Your Kite Connect app's API key (kite.trade developer console) |
| `kiteAccessToken` | Generated via Kite's login flow - Kite tokens expire **daily**, you need a fresh one each trading day. The main F&O Lab app's own Kite login flow (Live Trading settings) generates one; you can reuse that same token here, or generate one independently. |
| `wpSiteUrl` | Your site's base URL, e.g. `https://example.com` |
| `ingestSecret` | wp-admin → Settings → F&O Lab Providers → scroll to "Microstructure Companion Daemon" → copy the "Daemon Ingest Secret" field shown there |
| `symbol` | `NIFTY`, `BANKNIFTY`, or `FINNIFTY` |
| `optionStrikes` (optional) | An array of specific option contracts you also want this daemon to track, e.g. `[{"strike":23200,"optionType":"CE"},{"strike":23200,"optionType":"PE"}]`. Each is matched against Kite's own real NFO instrument master (nearest real expiry, never a guessed/string-built tradingsymbol) and subscribed on the WebSocket alongside the underlying index, in the same full mode (real depth included). **As of this pass, the six computed microstructure snapshot factors (Iceberg/Cumulative Delta/Volume Profile POC/Order Flow Imbalance/Footprint/Tick Speed) now compute genuinely, independently PER OPTION STRIKE** - each tracked strike gets its own real, isolated state (via `getOrCreateState`/`computeMetricsForState` in `kite-microstructure-daemon.js`), posted every `POST_INTERVAL_MS` to the new `wp_fno_microstructure_instruments` table/`fno_ingest_microstructure_instrument`/`fno_get_microstructure_instruments` endpoint pair in `fno-lab.php` (kept deliberately separate from the existing underlying-only `wp_fno_microstructure` table/endpoints - see that table's own TRACE for why). Real option-strike ticks are also still captured into this daemon's raw-tick buffer as before (feeds `docs/ENTERPRISE_DATA_ARCHITECTURE_PLAN.md`'s raw observation store). Omit this field entirely (or leave it out of `config.json`) to track only the underlying index, exactly as before this field existed. |

Then run it:

```bash
npm start
```

You should see console output like:

```
F&O Lab Microstructure Daemon starting for NIFTY...
Resolved NIFTY -> instrument_token 256265
Connected to Kite WebSocket ticker.
Daemon running. Posting snapshots to https://example.com every 5000ms.
[2026-08-15T10:32:05.123Z] Snapshot posted OK - delta=1250 poc=23200 imbalance=54.2% ticks/min=38 iceberg_events=0 dom_spoof_events=0
[2026-08-15T10:32:05.456Z] Raw tick batch posted OK (47 ticks)
```

## Security

The daemon sends `ingestSecret` on every request as the
`X-Fno-Daemon-Secret` header (see `kite-microstructure-daemon.js`).
If `wpSiteUrl` in your `config.json` is a plain `http://` address,
that header - and every tick/snapshot this daemon posts - travels
over the network unencrypted. Use a real `https://` URL for
`wpSiteUrl` in any production or unattended deployment; `http://`
should only ever be used for local testing against a site on
`localhost`/`127.0.0.1`. This code does not enforce HTTPS itself, so
this is on you to get right.

`config.json` holds your real `kiteApiKey`, `kiteAccessToken`, and
`ingestSecret` in plaintext. It is covered by this directory's
`.gitignore` - never commit it.

## First-run checklist (this code has NOT been tested against a live Kite account)

This script was written to Kite Connect's publicly documented WebSocket
protocol and the official `kiteconnect` npm package's documented API, but
the environment this was built in has no live Kite credentials to test
against. Before trusting it:

1. **Run it during market hours** and confirm ticks are actually arriving
   (the "Snapshot posted OK" lines should show changing numbers every few
   seconds, not the same static values repeated).
2. **Check the instrument lookup** - the "Resolved NIFTY -> instrument_token
   ..." line should show a real numeric token, not an error.
3. **Verify the WordPress side sees it** - open the F&O Lab app, filter
   factors by "Microstructure", and confirm the seven daemon-dependent
   factors show `[LIVE DAEMON]` in their reason text within ~10 seconds
   of starting this daemon.
4. **Stop the daemon and confirm graceful degradation** - within 30
   seconds, those same seven factors should switch back to "Companion tick
   daemon not running" rather than showing stale numbers forever.
5. **Watch for `noreconnect`** in the console - Kite access tokens expire
   daily, so a token that worked at market open may need regenerating if
   you leave this running past a session boundary.
6. **Check "Ticks Captured Today" and "Last Tick Received"** on the
   WordPress premium settings page (wp-admin → Settings → F&O Lab
   Providers → Raw Observation Store section) - a growing, non-zero
   count and a live-updating "Last Tick Received" indicator confirms
   the raw-tick pipeline is genuinely receiving and storing data, not
   just the microstructure snapshot working.

## What the seven metrics actually mean (and their honest limits)

- **Cumulative Delta**: buy-tagged minus sell-tagged traded volume, using
  the "tick rule" (price-up ticks classified as buyer-initiated,
  price-down as seller-initiated). This is the same approximation nearly
  every retail-facing cumulative-delta indicator uses - true exchange-side
  aggressor tagging isn't exposed to any retail API, Kite included.
- **Volume Profile POC / Footprint**: real traded volume bucketed by
  price level, using the tick size you configure. Point of Control is the
  price with the most volume.
- **Order Flow Imbalance**: reconstructed buy-side % of total flow from
  the same tick-rule delta.
- **Tick Speed**: literal ticks-per-minute over a rolling window - a
  direct, unambiguous measurement, no approximation involved.
- **Iceberg detection**: a heuristic - if a resting order-book level's
  quantity drops sharply (implying a fill) and then refills to near its
  previous size within 3 seconds, that's flagged as a probable iceberg
  replenishment. This is a real, standard pattern-matching heuristic used
  by professional order-flow tools, but it is not proof - coincidental
  similarity in resting size can also produce this pattern. Treat a
  nonzero count as "worth a closer look," not certainty.
- **DOM Ladder - Spoofing Orders**: a real, distinct heuristic from
  Iceberg detection above - the OPPOSITE pattern. If a large resting
  order (>= 500 contracts, a documented threshold) vanishes from the
  book on the very next tick while the volume traded that tick was far
  smaller than the vanished quantity, it looks cancelled rather than
  filled - the classic spoofing signature (place a large order to move
  the visible book, pull it before it can actually be hit). Same
  honesty caveat as Iceberg detection: large orders also vanish for
  entirely legitimate reasons (the trader changed their mind, a partial
  fill below the reporting threshold, a depth-refresh artifact). Treat
  a nonzero count as "worth a closer look," never proof of manipulation.

## Raw Tick Store (Enterprise Data Architecture Plan #2/#3)

Beyond the seven live-computed metrics above, this daemon also buffers
and bulk-posts every RAW tick it receives - the underlying observation
itself (symbol, LTP, volume, OI, bid, ask, timestamp), not a derived
metric. This feeds a real, permanent (7-day retention, then auto-pruned)
store on the WordPress side (`wp_fno_raw_ticks`, MySQL-based - see
`docs/ENTERPRISE_DATA_ARCHITECTURE_PLAN.md` Phase 2 for the full
architecture decision and rationale), intended for future point-in-time
decision replay and historical analysis beyond what the live factor
engine alone captures.

This is fully automatic once you're running this daemon with the setup
above - no extra config, no separate process, no additional npm
packages. If you were already running an older version of this daemon
before raw-tick posting existed, simply restarting it (`npm start`) is
enough to pick it up.

## Running tests

```bash
npm test
```

Tests the pure computation logic (tick rule, volume bucketing, POC
selection, iceberg heuristic, DOM Ladder spoofing heuristic) without
needing a live Kite connection. 16 tests as of this writing.
