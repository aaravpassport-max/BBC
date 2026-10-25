# F&O LAB — COMPLETE SESSION HANDOFF DOCUMENT

**Purpose of this file:** This is the single, complete handoff for a fresh chat to pick up this project with full context. Paste this whole file (or point Claude at it) at the start of a new conversation. It covers: what the app is, what's genuinely working, what's not, what was explicitly requested but never built, and exactly where to pick up.

**Read this file first, before touching code.** It exists specifically so nothing from this session gets lost or has to be re-discovered.

---

## 1. WHAT THIS APP IS

A WordPress plugin (`fno-lab-standalone-app`) — a personal, real-money-disabled **paper-trading decision engine** for NSE F&O (Nifty/BankNifty/FinNifty options). It runs as a standalone single-page app inside WordPress (no theme/shortcode dependency).

**Core architecture:** A 193-factor scoring engine (`evaluateBrain()` in `assets/fno-lab-core.js`) evaluates live market data every refresh cycle and produces a BUY_READY / SELL_READY / WAIT decision with a confidence level, backed by:
- A 153-entry **Failure-Mode Library** (72 currently live-gated, can block trades)
- A 16-state market-regime taxonomy
- Real, Zerodha-rate-accurate paper trade simulation (brokerage/STT/stamp duty/GST)
- A real, permanent database journal of every trade
- An **Autonomous Mode** that runs the full cycle automatically (browser-tab-dependent) plus a separate, standalone headless driver (`autonomous-driver/`) for unattended operation

**Real money trading exists in the code but is deliberately, structurally disabled** — verified multiple times this session, requires deliberate source-code edits to ever activate. Never assume it's "just a flag away" — it's genuinely locked down at multiple layers.

**Files that matter most:**
- `fno-lab.php` — WordPress plugin bootstrap, all PHP AJAX endpoints, DB schema (~5,400 lines)
- `assets/fno-lab-core.js` — the entire client-side app: UI, `evaluateBrain()`, all decision logic (~11,700 lines)
- `assets/greeks-engine.js` — Black-Scholes Greeks + the new IV solver (~210 lines)
- `assets/standalone-app.php` — the HTML/CSS shell
- `autonomous-driver/autonomous-driver.js` — the standalone headless trading driver
- `companion-daemon/` — optional Node.js daemon for tick-level microstructure data via Kite WebSocket
- `lib/fpdf.php` + `lib/font/` — bundled, real, free PDF library for report export
- `tests/php/*.php` (30 files) — real, standalone PHP tests, run with `php tests/php/X.php`
- `tests/greeks-engine.test.js` — the main JS test suite (674 tests), run with `node tests/greeks-engine.test.js`
- `companion-daemon/daemon-logic.test.js` — 16 tests
- `autonomous-driver/test/run-integration-test.js` — 10 real integration tests against a mock WordPress server
- `docs/PROJECT_STATUS.md` — the **complete, phase-by-phase history of this entire session** (168 phases). Read this for full detail on any specific piece.
- `docs/PENDING_REQUIREMENTS.md` — tracked, honest list of open items
- `docs/FAILURE_MODE_LIBRARY.md` — the complete 153-entry catalog with live-gate status

---

## 2. HOW TO SET UP A LOCAL TEST ENVIRONMENT (for whoever picks this up)

This session used a local WordPress + MySQL sandbox to verify everything live. To recreate:
```bash
# Start MySQL (MariaDB)
rm -f /run/mysqld/mysqld.sock /run/mysqld/mysqld.pid
setsid /usr/sbin/mariadbd --datadir=/var/lib/mysql --socket=/run/mysqld/mysqld.sock \
  --pid-file=/run/mysqld/mysqld.pid --user=mysql < /dev/null > /tmp/mdb.log 2>&1 &
sleep 5 && mysqladmin ping

# Start PHP's built-in server pointed at the real WordPress install
cd /var/www/html && setsid php -S localhost:8080 -t /var/www/html < /dev/null > /tmp/php-server.log 2>&1 &

# Deploy the plugin files
cp fno-lab.php assets/*.php assets/*.js lib/fpdf.php lib/font/*.json \
  /var/www/html/wp-content/plugins/fno-lab-standalone-app/[matching paths]

# Login: admin / adminpass123 at http://localhost:8080/wp-login.php
```
**Important, established habit this whole session:** always run `node --check` / `php -l` on any changed file BEFORE deploying it, and check the real PHP server log (`grep -i "fatal\|warning" /tmp/php-server.log`) after every deploy. MySQL and the PHP server both needed restarting frequently in this sandbox (stale sockets) — that's an environment quirk, not a code issue.

**This sandbox has no real internet access to NSE or Kite** — every "live data" verification in this session either used mocked/injected data or confirmed graceful degradation (the app correctly reports "unavailable" rather than crashing). **Nothing in this app has been tested against genuinely live market data.** That is the single biggest remaining gap — see Section 5.

---

## 3. WHAT IS GENUINELY WORKING (verified, tested, live-deployed at least once)

- **Core 193-factor decision engine** — real, extensively tested, produces honest "unavailable" states rather than fabricated data.
- **NSE + Kite dual data source** — Kite (Zerodha) is the primary source; NSE is optional (off by default) via a real Settings toggle. Real fallbacks built for chart/candles, option chain (the hardest piece — Kite has no single "whole chain" call), VIX, and futures.
- **A real, tested reverse-Black-Scholes IV solver** (`solveImpliedVolatility` in `greeks-engine.js`) — fills in IV when Kite-sourced data lacks it directly. Caught and fixed a genuine unit-conversion bug before it shipped.
- **Real-time, timezone-correct market-hours gating** — two separate historical timezone bugs found and fixed (client-side and a separate server-side one tied to WordPress's own configurable timezone).
- **Real market-hours enforcement on ALL order-placement paths** — found and fixed a serious gap where only Autonomous Mode checked market hours; the manual "Simulate Order" button and the real-money dispatcher did not. Now enforced at all three, both client and server side (defense in depth).
- **Trade-type foundation** — every trade now genuinely records its type (scalping/intraday/swing) at open time, with a clear, documented rule for which type applies given the current settings. Displayed in the Trade Ledger and a live badge.
- **Trade-type-aware category weighting** — a real, additive (non-invasive) scoring layer that reweights the 193 factors' categories per trade type, purely informational (not yet wired into the actual decision).
- **Trade-type-aware Failure-Mode Library adaptation** — wired into the real, actual blocking gate; a scalping-relevant risk carries more weight for scalping trades, swing-relevant risks more weight for swing.
- **Trade-type-independent learning** — a real, separate win-rate track record per trade type, downgrading confidence (or blocking to WAIT) when a specific trade type has a proven poor track record — mirrors the already-proven regime-based learning.
- **THE CRITICAL FIX FROM THIS SESSION'S LAST ROUND**: a real, hard, trade-type-aware "sufficient time remaining before square-off" gate. This directly fixes the user-reported ~₹20,000 loss (an intraday trade opened at ~3:30 PM, immediately force-closed at square-off). Intraday needs 30+ minutes remaining, Scalping needs 10+, Swing is exempt. Enforced in two independent places (the Failure-Mode Library's FM061, now `block` not `require_confirmation`, AND a direct, hard check in the entry function itself).
- **Scalping and Swing trading exist architecturally** — a real, server-side, multi-position-capable `wp_fno_open_positions` table (Intraday/Scalping still share one local slot; Swing has independent server-side tracking, monitored by the standalone driver even with no browser open).
- **Independent trading-type toggles, NSE toggle, Light/Dark mode, sound notifications** — a full, centralized Settings panel, all wired to a shared, reactive settings module (`fnoSettings`).
- **A downloadable diagnostic report (CSV + PDF)** — pulls real trade stats, factor reliability, and failure-event history. Found and fixed a real bug where it initially always showed 0 trades (wrong DB column name).
- **All safety/real-money architecture** — verified multiple times, structurally locked, tested extensively (`RealMoneyTradingTest.php`, 35 assertions).

**Test suite state (last confirmed this session):** 674 JS tests + 16 daemon tests + 10 driver integration tests = 700 total, all passing. 30 real PHP test files, all passing (one, `FactorHealthTest.php`, needs a live MySQL connection to run — it's not broken, just DB-dependent).

---

## 4. WHAT IS NOT WORKING / NOT YET DONE — BE HONEST ABOUT THIS

**The single most important gap:** almost nothing has been tested against real, live market data. This sandbox has no internet access to NSE or Kite. Every "verified" claim above means "verified against injected/mocked data, or verified to fail gracefully" — not "confirmed correct against real prices." **The very first thing a new session should do is deploy to the real site and watch it run through a real trading session, closely.**

**From the user's most recent, large request (the "Trade-Type Framework" / "Trading Knowledge Base" document) — NONE of this is built yet:**
1. A formal, written Trading Knowledge Base document (entry/exit/sizing/SL/target/trailing rules per trade type, documented as the reference layer).
2. A full Decision Matrix (situation → decision table) as a real, implemented structure — not just scattered logic.
3. Trade-type-specific **position sizing** rules (currently: one manually-set lot size for everyone, just persisted properly now — see Section 3).
4. Trade-type-specific **stop-loss/target/trailing-stop** rules (currently: the same SL/target inputs apply regardless of trade type).
5. Formal **re-entry** rules after an exit.
6. Formal **trade cancellation/invalidation** logic (distinct from stop-loss).
7. A genuinely comprehensive **failure-scenario audit** covering: API failures mid-order, partial execution, duplicate orders, position mismatch (DB says open, broker says closed, or vice versa), app restart recovery, stale data detection thresholds, conflicting simultaneous signals. Some of this exists partially (circuit breakers, honest "unavailable" states) but **not as a systematic, complete framework** the way the user is asking for.
8. The "Market Data → Analysis → Signal → Trade-Type Classification → Risk Assessment → Timing Validation → Entry Decision → Position Management → Exit Decision → Post-Trade Analysis" **explicit pipeline structure** — the pieces exist, but not organized as one clear, auditable pipeline with named stages.

**Known, real, honest technical gaps (not bugs, documented limitations):**
- Kite-sourced option chains have no OI-change data (Kite's API doesn't provide it) and, until the new IV solver's output is trusted more broadly, patchy IV in edge cases (deep ITM/OTM where the solver honestly can't converge).
- Market breadth (NIFTY 50 advance/decline) has no Kite fallback — deliberately not built, since Kite has no reliable way to confirm current index membership and a hardcoded list risks silently going stale.
- Four NSE-only capabilities (F&O ban list, corporate actions, participant OI, ASM/GSM) have **no broker equivalent at all** — this is permanent, not a gap to close.
- `FactorHealthTest.php` requires a live MySQL connection to run (environment-dependent, not broken).
- Light Mode covers the app's core structure (cards, header, buttons) but not every individual dynamically-colored data value (deliberately, to avoid breaking meaningful pass/fail color-coding).

**Real, one specific bug found in this final round that is DOCUMENTED BUT NOT YET RE-VERIFIED after the fix:** a field-naming mismatch (`tradingType` vs `tradingStyle`) between client and server broke the Trade Ledger's "Type" column and the trade-type learning system's ability to read historical data correctly. This was found and fixed (see `PROJECT_STATUS.md` Phase 168) and verified live once — but given how many places this field flows through, a new session should re-confirm this thoroughly with a batch of real trades across all three types before trusting the trade-type learning system's output.

---

## 5. RECOMMENDED, HONEST PRIORITY ORDER FOR A NEW SESSION

1. **Deploy to the real site, connect real Kite credentials, and watch it run through at least one real trading session.** This is overdue and is the single highest-value next step — most of the real bugs found this whole session (timezone issues, the square-off timing bug, the field-naming mismatch) were only found because the user was actually using the app, not from testing in isolation.
2. **Build the formal Trading Knowledge Base + Decision Matrix** the user explicitly asked for — a real, documented reference (could be a new `docs/TRADING_KNOWLEDGE_BASE.md` plus wiring key rules into the code where they're currently implicit/scattered).
3. **Trade-type-specific SL/target/trailing/position-sizing rules** — currently one-size-fits-all; the user explicitly wants these to differ by trade type, same as weighting/Failure-Library already do.
4. **The systematic failure-scenario audit** (API failures, partial execution, position-state-mismatch recovery, app-restart recovery) — real, valuable, safety-critical work not yet done comprehensively.
5. Everything else in `docs/PENDING_REQUIREMENTS.md`.

---

## 6. WORKING STYLE ESTABLISHED THIS SESSION (the user has asked for this to continue)

- **Audit → fix → build → test → verify, continuously**, without stopping to ask "should I continue" after every small piece.
- **Test before trusting** — this session repeatedly found real bugs (in its own new code) by hand-verifying math, testing the actual old vs. new behavior side by side, and refusing to mark something "done" without direct evidence.
- **Never fabricate data** — every "unavailable" state in this app is honest; nothing is guessed to look more complete than it is.
- **Deploy and verify live** whenever a change touches something consequential, not just unit tests in isolation.
- **One consolidated zip delivery**, not one after every small change — deliver the package when there's a meaningfully complete chunk of work, not after each individual fix.
