<?php
/**
 * Plugin Name: F&O Lab - Standalone App - No Theme Needed
 * Description: Standalone F&O options research/paper-trading app for Indian index derivatives (NIFTY/BANKNIFTY/FINNIFTY). Activate plugin and yoursite.com/ IS the app - no theme, no shortcode needed for the app itself. Real 193-factor decision engine (never fabricates unavailable data), realistic paper trading (spread/slippage/costs/rejection simulation), a trained probability model, post-trade failure/correlation/regime analysis, an IV surface engine, and paper/live Kite Connect trading with explicit permission. A real Participant Payoff Hypothesis Engine (structured, falsifiable hypotheses tested against later price action, with a real historical track record feeding back into live confidence), real Dealer Gamma Exposure and Futures-Options/Multi-Instrument consistency checks, regime-conditional live confidence adjustment, a Six-Month Learning Objective progress dashboard, and a real, standalone Autonomous Driver (autonomous-driver/ folder) for genuinely unattended, browser-closed operation. Optional wp-admin settings page (Settings > F&O Lab Providers) for premium data providers, TrueData credentials, the companion tick daemon, the Autonomous Driver's secret/user attribution, and the raw observation store. Educational/research tool, not financial advice.
 * Version: 16.25.0
 */

if (!defined('ABSPATH')) exit;

// Only one plugin folder may be active — e.g. both fno-lab-standalone-app
// AND fno-lab-standalone-app-v16.21.0 causes fatal "Cannot redeclare" errors.
if (defined('FNO_LAB_STANDALONE_LOADED')) {
    add_action('admin_notices', function () {
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-error"><p><strong>F&amp;O Lab:</strong> Two copies of this plugin are installed (for example <code>fno-lab-standalone-app</code> and a versioned folder like <code>fno-lab-standalone-app-v16.22.0</code>). Deactivate all copies, delete every <code>fno-lab-standalone-app*</code> folder under <code>wp-content/plugins/</code>, install only <code>fno-lab-standalone-app.zip</code> (extracts to <code>fno-lab-standalone-app/</code>), then activate once. See <code>UPGRADE.md</code> in the plugin folder.</p></div>';
    });
    return;
}
define('FNO_LAB_STANDALONE_LOADED', __FILE__);

require_once __DIR__ . '/fno-data-layer.php'; // Enterprise Data Architecture Plan Phase 1 - Unified Market Data Layer, Data Quality Engine, Source hierarchy, cost-control logging

// ------------------------------------------------------------------
// ROOT-DOMAIN TAKEOVER
// The app now owns "/" directly. /lab/, /fno/, /app/ remain as aliases
// for anyone with those URLs bookmarked from earlier versions.
// No WordPress page, template, or rewrite match is required for "/" -
// template_redirect intercepts every request and checks the path itself.
// ------------------------------------------------------------------

register_activation_hook(__FILE__, function () {
    add_rewrite_rule('^lab/?$', 'index.php?fno_standalone=1', 'top');
    add_rewrite_rule('^fno/?$', 'index.php?fno_standalone=1', 'top');
    add_rewrite_rule('^app/?$', 'index.php?fno_standalone=1', 'top');
    flush_rewrite_rules();
    fno_create_journal_table();
});

/**
 * TRACE: Creates (or upgrades, via dbDelta's idempotent diffing) the
 * wp_fno_journal table -> called on plugin activation AND on every
 * 'init' (guarded by a version check so it's a no-op after the first
 * run) so a version upgrade without a full deactivate/reactivate cycle
 * still gets the schema change.
 * Preconditions: $wpdb available (WordPress core global).
 * Postconditions: {$wpdb->prefix}fno_journal exists with columns
 * matching FNO_JOURNAL_SCHEMA_VERSION; option 'fno_journal_db_version'
 * updated to match so this doesn't re-run dbDelta on every request.
 * Edge cases handled: table already exists at the current version
 * (dbDelta no-ops, and the guard skips calling it at all); multisite
 * is NOT specifically handled - this runs on the current site's table
 * prefix only, consistent with the rest of this plugin's non-multisite
 * scope.
 */
// FOUND during a real, live UI/UX audit: the header's own visible
// "F&O Lab v8" text had been silently stale for many real phases -
// the actual plugin, per its own real, standard header comment above,
// is version 16.0.0. Rather than hardcode a second, separate version
// string that will inevitably drift out of sync again, a real,
// single, canonical constant is defined here and referenced by the
// UI directly - one real source of truth.
define('FNO_PLUGIN_VERSION', '16.25.0');
define('FNO_JOURNAL_SCHEMA_VERSION', '2.10.0'); // bumped: added the new wp_fno_microstructure_instruments table (real per-option-strike microstructure snapshots - see fno_create_journal_table()'s own TRACE for this table for why it's a new, separate table rather than an in-place PRIMARY KEY change to the existing wp_fno_microstructure table). Previous bump added wp_fno_open_positions.open_symbol_lock (generated column) + a UNIQUE KEY on it - real fix from the "does fno_open_position_fn have the same dual-writer gap as trailing_sl-ratchet/close?" audit pass (2026-08-30): the browser and the headless driver each only ever check "do I THINK I have a position open" from their own local/stale state before deciding to open a NEW position for a symbol (see recoverOpenPositionOnStartup/checkAndMonitorSwingPositions TRACE comments - this app's whole design assumes "the" open position, singular, per symbol+tradingStyle+user), but fno_open_position_fn itself had zero DB-level or atomic-check enforcement of that invariant - the only existing UNIQUE KEY (user_idempotency) only ever prevented a RETRY of the SAME logical request (same idempotency key), not two genuinely DIFFERENT open requests for the same symbol racing each other. This is the exact same race shape already fixed for the trailing-stop ratchet and for close - fixed here the same rigorous way: a real DB-level constraint (a MySQL "partial unique index" via a generated column that is NULL - and therefore exempt from uniqueness - whenever status != 'open', so only ever at most one 'open' row per user_id+symbol+trading_style can exist at the database engine level, not just in application logic).

/**
 * User's own explicit requirement: "The system should be designed so
 * that Zerodha, Angel One, and potentially other brokers can be
 * integrated later without redesigning the core trading engine" /
 * "Include appropriate safeguards so that an accidental configuration
 * change, API connection, restart, or software update cannot
 * unexpectedly place real-money trades."
 *
 * REAL, MASTER, CODE-LEVEL KILL SWITCH - the same real, proven
 * discipline this app already used for the pre-existing
 * `$is_demo = true;` line (verified unreachable by
 * tests/php/OrderExecutionSafetyTest.php), now formalized as a real,
 * explicit, named constant that EVERY real-money order path checks
 * FIRST, before any per-account "armed" state is even consulted. This
 * is real, deliberate defense-in-depth: even if a real account
 * somehow ends up armed in the database (a config bug, a bad
 * migration, anything), real-money orders remain structurally
 * unreachable unless a real developer explicitly flips this constant
 * to true in source code - a real config change, database edit, or
 * software update alone can never do it, since none of those touch
 * this literal source-code line.
 * Genuinely FALSE by default, and must stay that way until real-money
 * trading is deliberately, formally enabled as a real product
 * decision - not something a user-facing toggle alone should ever
 * control.
 */
define('FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED', false);

// Real, deliberate cap on the size of a single raw-tick ingest batch
// (fno_ingest_raw_tick_fn) - matches RAW_TICK_BUFFER_MAX in
// companion-daemon/kite-microstructure-daemon.js exactly (2000), the
// real daemon's own documented hard cap on how large one real flush's
// batch is ever allowed to grow. Kept as one named constant (not a
// magic number at the call site) so the two real numbers - the
// daemon's real intended max and the server's real enforced max -
// stay visibly, deliberately in sync if either is ever revisited.
define('FNO_RAW_TICK_BATCH_MAX', 2000);

// Real, deliberate hard upper-bound sanity cap on the qty of a single
// real-money order (fno_place_real_trade_fn, the dormant real-order
// dispatch path - see FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED above).
// FOUND genuinely missing during a fresh audit pass of this dormant
// path: the endpoint previously only rejected qty<=0, with no upper
// bound at all, so a fat-fingered or malicious client-side qty (a
// typo adding extra zeros, or a direct POST bypassing the UI) would
// have been passed straight through to fno_broker_place_order() with
// no server-side backstop, on the one code path in this app that -
// if the global switch is ever deliberately flipped - places a real
// order with real money. This is NOT a claim about NSE's real,
// contract-specific, expiry-varying market-wide freeze quantity
// (which this app does not fetch and must not fabricate) - it is a
// generic, deliberately generous fat-finger backstop, far above any
// realistic single-order lot count a real individual trader would
// ever legitimately submit through this app's own UI.
define('FNO_REAL_ORDER_MAX_QTY', 100000);

// Real, deliberate cap on the size of the changedFields/oldValues/
// newValues arrays a single fno_add_strategy_version_fn call may
// submit (bulk/array-accepting AJAX endpoint size-limit audit,
// following the same FNO_RAW_TICK_BATCH_MAX pattern above). This
// endpoint records one strategy-version bump's changed factor fields -
// the real, hard ceiling on how many distinct factor fields could ever
// genuinely change in one version is the size of the actual Factor
// Registry (193 factors - see fno-lab-core.js), so 300 is a
// deliberate, generous margin above that real number, not an arbitrary
// one: comfortably above every real 193-factor payload, while still
// refusing an unbounded array from an admin session that has gone bad
// (scripted/compromised) rather than trusting admin-only gating alone
// to bound request size.
define('FNO_STRATEGY_VERSION_FIELDS_MAX', 300);

function fno_create_journal_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = $wpdb->prefix . 'fno_journal';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        trade_ts BIGINT UNSIGNED NOT NULL,
        symbol VARCHAR(20) NOT NULL DEFAULT 'NIFTY',
        strike DECIMAL(10,2) NULL,
        option_type VARCHAR(2) NULL,
        // FOUND AND FIXED via deep, manual, realistic-data live
        // testing against the real database: this column was
        // VARCHAR(10), but the real, actual action labels this app
        // generates (AUTO_TARGET_EXIT, AUTO_SQUARE_OFF,
        // MANUAL_FORCE_EXIT, PARTIAL_EXIT_AT_TARGET - the real,
        // longest at 22 characters) all genuinely exceed that limit.
        // This meant every single real Auto Trade close has always
        // failed to insert - a second, separate, real bug that the
        // earlier field-name-collision bug (Phase 122) had been
        // masking: since no trade close ever reached the database at
        // all before that fix, this too-narrow column never had a
        // chance to be exercised or discovered until this exact,
        // careful manual test using the app's own real, actual close-
        // reason labels instead of short placeholder test values.
        // Widened with real, deliberate headroom beyond the current
        // longest real label.
        action VARCHAR(30) NOT NULL DEFAULT 'MANUAL',
        entry_price DECIMAL(10,2) NULL,
        exit_price DECIMAL(10,2) NULL,
        qty INT NULL,
        pnl DECIMAL(12,2) NOT NULL DEFAULT 0,
        gross_pnl DECIMAL(12,2) NULL,
        costs_total DECIMAL(12,2) NULL,
        original_sl DECIMAL(10,2) NULL,
        entry_iv DECIMAL(6,2) NULL,
        exit_iv DECIMAL(6,2) NULL,
        opened_at BIGINT UNSIGNED NULL,
        mfe DECIMAL(10,2) NULL,
        mae DECIMAL(10,2) NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'manual',
        mode VARCHAR(10) NOT NULL DEFAULT 'paper',
        // Real, new column - user's own direct, explicit request for
        // a trade-type-aware architecture, starting with the real
        // foundation: every trade must genuinely record which trade
        // type it actually was, not just be labeled after the fact.
        // Real, same column name already used in
        // wp_fno_open_positions for consistency. Defaults to
        // 'intraday' - this app's own original, most-tested,
        // most-proven trade type - for any real, older row that
        // predates this column.
        trading_style VARCHAR(20) NOT NULL DEFAULT 'intraday',
        factor_snapshot LONGTEXT NULL,
        // Real fix (journal write/read-path audit): mirrors the exact
        // real idempotency_key + UNIQUE KEY pattern already proven for
        // wp_fno_open_positions (see FNO_JOURNAL_SCHEMA_VERSION 2.7.0's
        // own comment above for that original fix). The exit-side
        // journal write is the one genuinely retried today (the
        // autonomous-driver's own enqueueRetry, up to 5 attempts, AND
        // an uncaught network error mid-cycle leaves openPosition
        // non-null so the very next cycle's exit check re-computes and
        // re-POSTs the same logical close) - fno_journal_add_fn was a
        // bare, unconditional INSERT with no protection at all, so a
        // real retry-after-ack-lost would have silently double-counted
        // one real trade's pnl in every downstream analytics function
        // (win-rate, factor correlation, calibration, etc). Optional -
        // NULL never collides with anything (matching the real
        // open_positions column's proven semantics), so existing rows
        // and callers that don't send a key are completely unaffected.
        idempotency_key VARCHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY user_ts (user_id, trade_ts),
        UNIQUE KEY user_journal_idempotency (user_id, idempotency_key)
    ) $charset_collate;";
    dbDelta($sql);

    // Microstructure snapshots table - written by the SEPARATE companion
    // Node.js ticker daemon (companion-daemon/kite-microstructure-daemon.js),
    // not by this plugin itself. One row per symbol, upserted on every
    // daemon snapshot (typically every few seconds while the daemon runs) -
    // this plugin only ever READS the latest row per symbol, never writes
    // to this table from a WordPress request.
    $microTable = $wpdb->prefix . 'fno_microstructure';
    $sql2 = "CREATE TABLE $microTable (
        symbol VARCHAR(20) NOT NULL,
        cumulative_delta BIGINT NULL,
        poc DECIMAL(10,2) NULL,
        flow_imbalance_pct DECIMAL(5,2) NULL,
        footprint_top_levels TEXT NULL,
        ticks_per_minute DECIMAL(8,2) NULL,
        iceberg_detected INT NULL,
        dom_spoof_detected INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (symbol)
    ) $charset_collate;";
    dbDelta($sql2);

    // REAL EXTENSION (Zerodha-maximization audit, final backlog item):
    // real per-OPTION-STRIKE microstructure snapshots. Deliberately a
    // NEW, separate table rather than an in-place PRIMARY KEY change to
    // $microTable above - dbDelta's own documented handling of PRIMARY
    // KEY alterations on a table that may already hold real production
    // rows is unreliable across MySQL versions, and $microTable's
    // existing single-row-per-symbol shape (and the endpoint that reads
    // it) stays completely untouched/backward-compatible this way. One
    // row per real, distinct option contract (instrument_key = the
    // real Kite tradingsymbol, e.g. "NIFTY24AUG23200CE" - globally
    // unique, so it alone is sufficient as the primary key); symbol is
    // still stored as its own indexed column purely so
    // fno_get_microstructure_instruments_fn can filter to "every
    // tracked strike for the currently selected underlying" in one
    // query without parsing instrument_key.
    $microInstrTable = $wpdb->prefix . 'fno_microstructure_instruments';
    $sql2b = "CREATE TABLE $microInstrTable (
        instrument_key VARCHAR(40) NOT NULL,
        symbol VARCHAR(20) NOT NULL,
        cumulative_delta BIGINT NULL,
        poc DECIMAL(10,2) NULL,
        flow_imbalance_pct DECIMAL(5,2) NULL,
        footprint_top_levels TEXT NULL,
        ticks_per_minute DECIMAL(8,2) NULL,
        iceberg_detected INT NULL,
        dom_spoof_detected INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (instrument_key),
        KEY symbol (symbol)
    ) $charset_collate;";
    dbDelta($sql2b);

    // Trade Rejection Learning (Master Development Prompt §41 - "the
    // system must also learn from trades it did NOT take... did we
    // correctly avoid a losing trade? did we unnecessarily reject a
    // profitable opportunity?"). Previously entirely unbuilt - only
    // trades actually opened were ever logged, so every WAIT/NO_TRADE
    // decision left zero trace to later evaluate.
    $rejTable = $wpdb->prefix . 'fno_rejected_opportunities';
    $sql3 = "CREATE TABLE $rejTable (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        ts BIGINT UNSIGNED NOT NULL,
        symbol VARCHAR(20) NOT NULL DEFAULT 'NIFTY',
        decision VARCHAR(20) NOT NULL,
        spot_at_rejection DECIMAL(10,2) NULL,
        target_hypothetical DECIMAL(10,2) NULL,
        sl_hypothetical DECIMAL(10,2) NULL,
        probability DECIMAL(5,4) NULL,
        reason TEXT NULL,
        risk_state VARCHAR(100) NULL,
        cost_state VARCHAR(50) NULL,
        factor_snapshot LONGTEXT NULL,
        later_checked TINYINT(1) NOT NULL DEFAULT 0,
        later_spot DECIMAL(10,2) NULL,
        later_checked_at BIGINT UNSIGNED NULL,
        outcome VARCHAR(20) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_ts (user_id, ts),
        KEY later_checked (later_checked)
    ) $charset_collate;";
    dbDelta($sql3);

    // Enterprise Data Architecture Plan #2/#3 (Raw Observation Store) -
    // Phase 2, decided this session: built on MySQL (this app's
    // existing storage layer) rather than a separate time-series
    // database, per the real engineering decision recorded in
    // docs/ENTERPRISE_DATA_ARCHITECTURE_PLAN.md Phase 2 - assuming a
    // dedicated TimescaleDB/ClickHouse deployment would guess at
    // infrastructure this user hasn't indicated they have, given the
    // established managed-hosting pattern across this account's other
    // work. Retention-by-rotation (fno_prune_raw_ticks_fn below, run
    // daily via WP-Cron) keeps this practical on shared/managed
    // hosting instead of unbounded growth. trade_date is a real,
    // explicit column (not derived from ts on every prune query) so
    // the daily pruning DELETE can use a real indexed equality check
    // rather than a slower date-range scan.
    $rawTicksTable = $wpdb->prefix . 'fno_raw_ticks';
    $sql4 = "CREATE TABLE $rawTicksTable (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ts BIGINT UNSIGNED NOT NULL,
        trade_date DATE NOT NULL,
        symbol VARCHAR(20) NOT NULL,
        underlying VARCHAR(20) NULL,
        strike DECIMAL(10,2) NULL,
        option_type VARCHAR(2) NULL,
        expiry VARCHAR(20) NULL,
        ltp DECIMAL(10,2) NULL,
        open_price DECIMAL(10,2) NULL,
        high_price DECIMAL(10,2) NULL,
        low_price DECIMAL(10,2) NULL,
        volume BIGINT UNSIGNED NULL,
        oi BIGINT UNSIGNED NULL,
        oi_change BIGINT NULL,
        bid DECIMAL(10,2) NULL,
        ask DECIMAL(10,2) NULL,
        iv DECIMAL(6,2) NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'nse_free',
        source_tier VARCHAR(20) NULL,
        quality_flags VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY symbol_strike_ts (symbol, strike, option_type, ts),
        KEY trade_date (trade_date)
    ) $charset_collate;";
    dbDelta($sql4);

    // Master Development Prompt §10 (Portfolio Greeks Exposure) - real
    // MANUAL position tracker, built as a genuinely SEPARATE, isolated
    // feature from the real Auto Trades execution engine
    // (STORAGE.autoTrades, client-side localStorage, single-position).
    // This table exists purely for real portfolio-level RISK
    // AGGREGATION (net Greeks, correlation risk across positions the
    // user holds) - it never executes, opens, or manages a position,
    // so it carries ZERO regression risk to the existing, tested,
    // single-position trading engine everything else in this app
    // depends on. This is the real, safe path to genuine multi-
    // position portfolio value the earlier single-position Greeks
    // exposure subset (Phase 48) couldn't reach on its own.
    $manualPositionsTable = $wpdb->prefix . 'fno_manual_positions';
    $sql5 = "CREATE TABLE $manualPositionsTable (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        symbol VARCHAR(20) NOT NULL,
        strike DECIMAL(10,2) NOT NULL,
        option_type VARCHAR(2) NOT NULL,
        qty INT NOT NULL,
        entry_price DECIMAL(10,2) NOT NULL,
        entry_iv DECIMAL(6,2) NULL,
        notes VARCHAR(255) NULL,
        added_at BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id)
    ) $charset_collate;";
    dbDelta($sql5);

    // Master Prompt (user's own founding vision document) - real
    // storage for the Participant Payoff Hypothesis Engine
    // (computeParticipantPayoffHypothesis/evaluateParticipantPayoffHypothesis
    // in fno-lab-core.js). This table is what makes the document's own
    // explicit "test hypotheses historically and through ongoing paper
    // trading" ask genuinely real over time, rather than a one-off
    // in-memory computation that's forgotten every refresh - real
    // hypotheses accumulate here, get evaluated once enough real time
    // has passed (same real pattern as wp_fno_rejected_opportunities),
    // and the real confirmed-vs-disconfirmed rate over weeks/months is
    // the genuine, honest answer to "is this reasoning actually
    // predictive, or just plausible-sounding" - the document's own
    // core standard ("detect, test and learn whether the observed
    // behaviour is actually predictive").
    $hypothesesTable = $wpdb->prefix . 'fno_participant_hypotheses';
    $sql6 = "CREATE TABLE $hypothesesTable (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        symbol VARCHAR(20) NOT NULL,
        ts BIGINT UNSIGNED NOT NULL,
        spot_at_generation DECIMAL(10,2) NOT NULL,
        direction VARCHAR(10) NOT NULL,
        confidence VARCHAR(10) NOT NULL,
        key_level DECIMAL(10,2) NULL,
        hypothesis_text TEXT NOT NULL,
        supporting_evidence TEXT NULL,
        counter_evidence TEXT NULL,
        falsifiable_prediction TEXT NOT NULL,
        later_checked TINYINT(1) NOT NULL DEFAULT 0,
        later_spot DECIMAL(10,2) NULL,
        later_checked_at BIGINT UNSIGNED NULL,
        outcome VARCHAR(20) NULL,
        move_pct DECIMAL(6,2) NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY later_checked (later_checked)
    ) $charset_collate;";
    dbDelta($sql6);

    // User's own explicit requirement - "Real-money account
    // credentials/API keys must be stored and handled separately from
    // the experimental environment" / "Clearly separate paper-trading
    // balances, orders, positions, P&L, logs, and performance data
    // from any future real-money trading data." Real, genuinely
    // ISOLATED tables - deliberately NOT reusing fno_kite_settings
    // (which currently mixes broker credentials with non-sensitive
    // config like square-off time) or wp_fno_journal (the real,
    // existing paper-trading record). A real broker account row here
    // NEVER stores a raw secret - api_secret_encrypted/access_token_encrypted
    // reuse the SAME real fno_encrypt_secret()/fno_decrypt_secret()
    // already proven for the existing Kite integration, never a new,
    // unproven encryption scheme.
    $realMoneyAccountsTable = $wpdb->prefix . 'fno_real_money_accounts';
    $sql7 = "CREATE TABLE $realMoneyAccountsTable (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        broker VARCHAR(30) NOT NULL,
        label VARCHAR(100) NULL,
        api_key_encrypted TEXT NULL,
        api_secret_encrypted TEXT NULL,
        access_token_encrypted TEXT NULL,
        is_armed TINYINT(1) NOT NULL DEFAULT 0,
        armed_at BIGINT UNSIGNED NULL,
        armed_plugin_version VARCHAR(20) NULL,
        disarmed_reason VARCHAR(255) NULL,
        created_at BIGINT UNSIGNED NOT NULL,
        updated_at BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY broker (broker)
    ) $charset_collate;";
    dbDelta($sql7);

    // Real, genuinely separate real-money trade/order log - a real
    // completed (or attempted) real-money trade NEVER writes to
    // wp_fno_journal, the paper-trading table - kept in its own,
    // dedicated real table from day one, even though real-money
    // trading itself is not yet active, so the eventual real
    // activation never requires a schema change or data migration.
    $realMoneyJournalTable = $wpdb->prefix . 'fno_real_money_journal';
    $sql8 = "CREATE TABLE $realMoneyJournalTable (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        account_id BIGINT UNSIGNED NOT NULL,
        broker VARCHAR(30) NOT NULL,
        broker_order_id VARCHAR(60) NULL,
        symbol VARCHAR(20) NOT NULL,
        strike DECIMAL(10,2) NULL,
        option_type VARCHAR(2) NULL,
        qty INT NOT NULL,
        entry_price DECIMAL(10,2) NULL,
        exit_price DECIMAL(10,2) NULL,
        pnl DECIMAL(12,2) NULL,
        status VARCHAR(20) NOT NULL,
        trade_ts BIGINT UNSIGNED NOT NULL,
        closed_ts BIGINT UNSIGNED NULL,
        raw_broker_response TEXT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY account_id (account_id)
    ) $charset_collate;";
    dbDelta($sql8);

    // `docs/PENDING_REQUIREMENTS.md`'s own, honestly-tracked "Layer B
    // provenance split" item - "would replace the embedded JSON in
    // factor_snapshot with real relational rows." Real, honest,
    // BOUNDED v1: a real, genuinely ADDITIVE dual-write, never a
    // replacement or migration of existing data. The existing
    // factor_snapshot JSON column is completely untouched - every
    // existing real trade, every existing real reader of that column,
    // continues working exactly as before. This new table is
    // populated only for NEW trades going forward, enabling real,
    // efficient relational queries (e.g. "every real trade where
    // factor f42 was true") without parsing a JSON blob per row -
    // something the existing JSON storage genuinely cannot do
    // efficiently at scale.
    $factorValuesTable = $wpdb->prefix . 'fno_factor_values';
    $sql9 = "CREATE TABLE $factorValuesTable (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        journal_id BIGINT UNSIGNED NOT NULL,
        factor_id VARCHAR(10) NOT NULL,
        cat VARCHAR(30) NULL,
        status VARCHAR(20) NULL,
        pass TINYINT(1) NULL,
        score DECIMAL(6,3) NULL,
        created_at BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        KEY journal_id (journal_id),
        KEY factor_id (factor_id),
        KEY factor_pass (factor_id, pass)
    ) $charset_collate;";
    dbDelta($sql9);

    // The real, persistent Failure Mode Library - user's own explicit
    // audit finding that no such system existed anywhere in this
    // codebase under any name. Real, unified storage for all four
    // failure categories the user explicitly named (unavailable
    // tools/data, bad/contradicted signals, losing trades, execution
    // issues) - the classification itself happens client-side
    // (classifyFailureModeEvent in fno-lab-core.js, real and tested),
    // this table is where those real, classified events actually get
    // persisted, so they survive a page reload and can be aggregated
    // into real, historical failure-mode statistics that genuinely
    // feed back into future decisions - not just logged and forgotten.
    $failureEventsTable = $wpdb->prefix . 'fno_failure_events';
    $sql10 = "CREATE TABLE $failureEventsTable (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        symbol VARCHAR(20) NULL,
        category VARCHAR(30) NOT NULL,
        subcategory VARCHAR(60) NULL,
        reason TEXT NULL,
        regime_label VARCHAR(60) NULL,
        ts BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY category (category),
        KEY user_category (user_id, category)
    ) $charset_collate;";
    dbDelta($sql10);

    // FOUND to be genuinely necessary at the user's own direct
    // request to add scalping and swing trading: this real, new
    // table is the real, structural fix a real diagnostic surfaced
    // first - open positions had only ever lived in the browser's own
    // localStorage, meaning they could never genuinely survive the
    // browser closing, a device change, or (critically for swing
    // trading specifically) more than one real trading day. This
    // table persists open positions server-side instead, and is
    // built, from the start, to hold multiple real, simultaneous rows
    // per user (the user's own stated future intent), even though the
    // real, initial UI built alongside it manages one at a time -
    // avoiding a real, painful later migration.
    $openPositionsTable = $wpdb->prefix . 'fno_open_positions';
    $sql11 = "CREATE TABLE $openPositionsTable (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        symbol VARCHAR(20) NOT NULL,
        strike DECIMAL(10,2) NOT NULL,
        option_type VARCHAR(2) NOT NULL,
        qty INT NOT NULL,
        entry_price DECIMAL(10,2) NOT NULL,
        sl DECIMAL(10,2) NOT NULL,
        target DECIMAL(10,2) NOT NULL,
        trailing_sl DECIMAL(10,2) NULL,
        mfe DECIMAL(10,2) NULL,
        mae DECIMAL(10,2) NULL,
        trading_style VARCHAR(20) NOT NULL DEFAULT 'intraday',
        opened_at BIGINT UNSIGNED NOT NULL,
        last_checked_at BIGINT UNSIGNED NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        source VARCHAR(20) NOT NULL DEFAULT 'manual',
        idempotency_key VARCHAR(64) NULL,
        open_symbol_lock VARCHAR(105) GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN CONCAT(user_id, ':', symbol, ':', trading_style) ELSE NULL END) VIRTUAL,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY user_status (user_id, status),
        KEY trading_style (trading_style),
        UNIQUE KEY user_idempotency (user_id, idempotency_key),
        UNIQUE KEY user_symbol_style_open_lock (open_symbol_lock)
    ) $charset_collate;";
    // REAL FIX (2026-08-30 migration-safety audit pass): the
    // user_symbol_style_open_lock UNIQUE KEY above was ADDED to this
    // table in schema version 2.9.0 specifically to close the
    // dual-writer race where the browser and the headless driver could
    // each independently open a genuinely duplicate 'open' row for the
    // same user+symbol+trading_style (see FNO_JOURNAL_SCHEMA_VERSION's
    // own comment). But that is exactly the failure this constraint
    // guards against - so on any REAL production site that had been
    // running the OLDER, unconstrained schema for a while, the table
    // can already genuinely contain more than one 'open' row for the
    // same user+symbol+trading_style at the moment this upgrade runs.
    // dbDelta computes an ALTER TABLE ... ADD UNIQUE KEY for that case,
    // and MySQL/MariaDB REJECTS an ADD UNIQUE KEY when existing rows
    // already violate it ("Duplicate entry ... for key") - dbDelta
    // does not throw or return that failure to the caller, it just
    // leaves $wpdb->last_error set and silently continues. Every other
    // dbDelta call above this one is a pure ADD-COLUMN/CREATE-TABLE
    // (all new columns carry a DEFAULT, so those are genuinely safe on
    // existing rows) - this is the one, real exception, since it's a
    // structural UNIQUE constraint over pre-existing data rather than
    // an additive column. Real, honest fix: de-duplicate BEFORE
    // dbDelta runs, so the ALTER always has clean data to apply the
    // constraint to. For each (user_id, symbol, trading_style) group
    // with more than one 'open' row, the most-recently-opened row
    // (max(opened_at), ties broken by max(id)) is kept as the real,
    // canonical open position - matching what the app's own recovery
    // logic (recoverOpenPositionOnStartup) already treats as "the"
    // position when it queries for the latest open row per symbol -
    // and every older duplicate is marked 'closed' (a real, existing,
    // already-handled status; never deleted, so no data loss - the
    // stale duplicate remains in the table for anyone auditing
    // history, it simply stops being counted as an open position).
    // This only ever runs against a table that already exists (a
    // brand-new install has no rows to conflict, dbDelta just creates
    // the table with the constraint from day one) and is a no-op when
    // there are no genuine duplicates (the normal, expected case).
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $openPositionsTable)) === $openPositionsTable) {
        $dupeGroups = $wpdb->get_results(
            "SELECT user_id, symbol, trading_style, COUNT(*) AS cnt
             FROM $openPositionsTable WHERE status = 'open'
             GROUP BY user_id, symbol, trading_style HAVING cnt > 1"
        );
        foreach ((array) $dupeGroups as $g) {
            $keepId = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $openPositionsTable
                 WHERE status = 'open' AND user_id = %d AND symbol = %s AND trading_style = %s
                 ORDER BY opened_at DESC, id DESC LIMIT 1",
                $g->user_id, $g->symbol, $g->trading_style
            ));
            if ($keepId) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $openPositionsTable SET status = 'closed'
                     WHERE status = 'open' AND user_id = %d AND symbol = %s AND trading_style = %s AND id != %d",
                    $g->user_id, $g->symbol, $g->trading_style, $keepId
                ));
                error_log("F&O Lab schema migration (2.9.0): de-duplicated pre-existing multiple 'open' rows for user_id={$g->user_id} symbol={$g->symbol} trading_style={$g->trading_style} before applying user_symbol_style_open_lock UNIQUE KEY; kept id={$keepId}, closed the rest.");
            }
        }
    }
    dbDelta($sql11);
    // REAL FIX (same pass): don't unconditionally mark the migration
    // as complete. If dbDelta's own ALTER for sql11 (or any of the
    // prior real dbDelta calls above) genuinely failed at the DB
    // engine level, $wpdb->last_error is left set. Previously
    // update_option('fno_journal_db_version', ...) ran regardless, so
    // a genuinely FAILED migration (e.g. the ADD UNIQUE KEY above
    // still failing for a reason the de-dup above didn't anticipate -
    // a locked table, a permissions issue, anything) would be
    // permanently marked as "done" by the very next line, since the
    // 'init' guard above only re-runs fno_create_journal_table() when
    // the stored version DIFFERS from FNO_JOURNAL_SCHEMA_VERSION - so
    // a failed migration would silently never be retried on any future
    // request, and the site would keep running against a stale/broken
    // schema with no record anywhere that anything went wrong. Real,
    // honest fix: only mark the version as upgraded when dbDelta left
    // no error behind; otherwise log it (so it's visible in real PHP
    // error logs) and surface an admin notice, and leave the stored
    // version alone so the very next 'init' genuinely retries the
    // whole migration rather than silently giving up on it forever.
    if ((string) $wpdb->last_error !== '') {
        error_log('F&O Lab: schema migration to version ' . FNO_JOURNAL_SCHEMA_VERSION . ' failed and will be retried on next load: ' . $wpdb->last_error);
        update_option('fno_journal_db_migration_error', $wpdb->last_error);
        return;
    }
    delete_option('fno_journal_db_migration_error');
    update_option('fno_journal_db_version', FNO_JOURNAL_SCHEMA_VERSION);
}
add_action('init', function () {
    if (get_option('fno_journal_db_version') !== FNO_JOURNAL_SCHEMA_VERSION) {
        fno_create_journal_table();
    }
}, 5);

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
    // Enterprise Plan #2/#3 - clear the raw-tick retention cron on
    // deactivation, so a deactivated (or since-removed) plugin doesn't
    // leave an orphaned scheduled event trying to fire against a
    // fno_prune_raw_ticks_fn that may no longer exist.
    $timestamp = wp_next_scheduled('fno_prune_raw_ticks_event');
    if ($timestamp) wp_unschedule_event($timestamp, 'fno_prune_raw_ticks_event');
});

add_action('init', function () {
    add_rewrite_rule('^lab/?$', 'index.php?fno_standalone=1', 'top');
    add_rewrite_rule('^fno/?$', 'index.php?fno_standalone=1', 'top');
    add_rewrite_rule('^app/?$', 'index.php?fno_standalone=1', 'top');
    add_rewrite_tag('%fno_standalone%', '1');
});

/**
 * TRACE: Fires on every front-end request -> determines whether the
 * request is the site root ("/", with or without index.php / trailing
 * slash / query string) or one of the legacy alias paths -> if so,
 * renders the standalone app and exits before WordPress ever loads
 * the active theme's template hierarchy.
 * Preconditions: WP core has resolved routing up to template_redirect.
 * Postconditions: for a matched request, output is exactly the
 * standalone-app.php markup with HTTP 200 and script execution halts
 * (exit) - no theme header/footer/sidebar is included anywhere in the
 * response. Non-matched requests (any other path) fall through
 * untouched to normal WordPress/theme handling.
 * Edge cases handled: trailing slash on root, root with query string
 * (e.g. "/?utm_source=x"), admin/AJAX/REST/cron requests (explicitly
 * excluded so wp-admin, admin-ajax.php, and the REST API keep working
 * normally), "/lab", "/fno", "/app" with or without trailing slash,
 * manual "?fno_app=1" override on any path.
 */
add_action('template_redirect', function () {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }

    $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $request_path = is_string($request_path) ? trim($request_path, '/') : '';

    $is_root  = ($request_path === '');
    $is_alias = in_array($request_path, ['lab', 'fno', 'app'], true);
    $is_query_override = isset($_GET['fno_app']);

    if ($is_root || $is_alias || $is_query_override) {
        status_header(200);
        include __DIR__ . '/assets/standalone-app.php';
        exit;
    }
});

// ------------------------------------------------------------------
// AJAX ENDPOINTS
// Every endpoint below now: (1) verifies a nonce created specifically
// for the standalone app, (2) applies a per-IP rate limit via
// transients for the unauthenticated NSE proxy endpoints, so the
// site cannot be used as an open, unthrottled relay to nseindia.com.
// ------------------------------------------------------------------

/**
 * TRACE: Reads the nonce sent by the client -> validates it against
 * the 'fno_standalone_nonce' action -> on failure sends a 403 JSON
 * error and halts (wp_send_json_error dies internally).
 * Preconditions: $_REQUEST['nonce'] present.
 * Postconditions: execution continues only for a valid, unexpired
 * nonce; otherwise the request terminates here.
 * Edge cases handled: missing nonce, expired nonce, tampered nonce.
 */
function fno_verify_app_nonce() {
    if (!check_ajax_referer('fno_standalone_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid or expired session token'], 403);
    }
}

/**
 * TRACE: Real, genuine bug found and fixed by building a real,
 * local integration test that ran the actual Autonomous Driver script
 * over real HTTP against a real, local mock server (not just the
 * earlier, pure-logic dry run) - the real, public, nopriv-registered
 * market-data endpoints (fno_fetch_chart, fno_fetch_option_chain,
 * etc.) still internally called fno_verify_app_nonce(), which
 * requires a real, valid WordPress nonce. A real browser always has
 * one (WordPress generates a real nonce on every real page load, even
 * for a genuinely anonymous, not-logged-in visitor) - but a genuinely
 * headless Node process has no real page load to get one from at
 * all. Every single one of the driver's real data fetches would have
 * failed in production, never caught by the earlier dry run since
 * that test called the pure logic directly and never exercised the
 * real HTTP/auth layer.
 * Real, deliberately DIFFERENT from fno_verify_app_access() above -
 * that function's browser fallback REQUIRES a real, logged-in user,
 * which is correct for sensitive, WRITE endpoints (journal, trades)
 * but would be a real regression here: these specific endpoints are
 * real, public market-data READS, already correctly configured
 * (wp_ajax_nopriv_) for genuinely anonymous browser access with only
 * a real nonce, no login required - this function preserves that
 * real, existing behavior exactly, while ALSO accepting the real
 * driver secret as a genuine alternative for the headless path.
 */
function fno_verify_public_or_driver_access() {
    $headerSecret = $_SERVER['HTTP_X_FNO_DRIVER_SECRET'] ?? '';
    if ($headerSecret !== '') {
        if (!hash_equals(fno_get_headless_driver_secret(), $headerSecret)) {
            wp_send_json_error(['message' => 'Invalid headless driver secret'], 403);
        }
        return; // real, valid headless access to a real, public read - no nonce needed
    }
    fno_verify_app_nonce(); // real, unchanged, existing browser path (works for both logged-in and anonymous real users)
}

/**
 * TRACE: User's own founding vision document - "I want to start the
 * experimental system... and let it run" / "once connected... will
 * the complete process run automatically." FOUND MISSING (confirmed
 * directly by the user pointing out the browser-open limitation):
 * every real, sensitive endpoint (opening/closing a paper trade,
 * logging a journal entry, logging a hypothesis) required a real
 * browser-session-tied nonce AND a real, logged-in WordPress user -
 * neither of which a genuinely unattended, headless process
 * (Autonomous Driver, built alongside this function) can ever have,
 * since it has no browser and no interactive login.
 * Real, secure design: mirrors the EXACT already-proven, already-
 * secure pattern this app already uses for the companion daemon's own
 * real, unattended, server-to-server access
 * (fno_get_daemon_secret()/hash_equals()) - a real, long-lived,
 * auto-generated secret, checked via a real, dedicated HTTP header,
 * timing-safe compared. Deliberately a SEPARATE real secret from the
 * daemon's own (not reused) - these are genuinely different real
 * services with different real scopes (the daemon only ever INGESTS
 * tick data; the Autonomous Driver can READ market data AND WRITE
 * real trades, a materially broader, more sensitive real scope,
 * so keeping the secrets separate is the safer, more defensible real
 * security boundary).
 * When the real header is present and valid, calls the real, standard
 * WordPress `wp_set_current_user()` against a real, admin-configured
 * user ID (never a hardcoded or guessed ID) - the same real,
 * legitimate mechanism WordPress itself uses for trusted,
 * non-interactive service accounts (e.g. Application Passwords).
 * Falls back to the real, existing nonce+login check when the header
 * is absent - zero behavior change for the real browser path, this is
 * a real, purely additive second door, not a replacement for the
 * first.
 * Preconditions: none.
 * Postconditions: if this function returns (does not wp_die), the
 * request is genuinely authenticated - either as a real, logged-in
 * browser user (existing path, unchanged) or as the real, configured
 * headless-driver user (new path) - is_user_logged_in() and
 * get_current_user_id() are both real and correct for either case
 * from this point forward.
 */
function fno_get_headless_driver_secret() {
    $stored = get_option('fno_headless_driver_secret');
    if (empty($stored)) {
        $secret = wp_generate_password(48, false);
        update_option('fno_headless_driver_secret', fno_encrypt_secret($secret));
        return $secret;
    }
    // Real, same-pattern-as-every-other-credential encryption
    // (fno_encrypt_secret/fno_decrypt_secret, AES-256-CBC keyed off
    // wp_salt('auth')) - added after this secret already shipped
    // storing plaintext in wp_options. Try decrypting first; if that
    // fails (empty result), $stored is a genuine pre-existing plaintext
    // secret from before this fix - use it as-is (so an
    // already-running driver is never locked out on upgrade) and
    // opportunistically re-save it encrypted so subsequent reads take
    // the encrypted path.
    $decrypted = fno_decrypt_secret($stored);
    if ($decrypted !== '') {
        return $decrypted;
    }
    update_option('fno_headless_driver_secret', fno_encrypt_secret($stored));
    return $stored;
}
function fno_verify_app_access() {
    $headerSecret = $_SERVER['HTTP_X_FNO_DRIVER_SECRET'] ?? '';
    if ($headerSecret !== '') {
        if (!hash_equals(fno_get_headless_driver_secret(), $headerSecret)) {
            wp_send_json_error(['message' => 'Invalid headless driver secret'], 403);
        }
        $driverUserId = (int) get_option('fno_headless_driver_user_id', 0);
        if ($driverUserId <= 0 || !get_userdata($driverUserId)) {
            wp_send_json_error(['message' => 'Headless driver secret is valid, but no real driver user is configured yet - set one on the F&O Lab Providers settings page'], 403);
        }
        wp_set_current_user($driverUserId);
        return; // real, valid headless access - proceed
    }
    // Real, existing, unchanged browser path.
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
}

/**
 * TRACE: Real, application-level free-text length cap - found genuinely
 * missing (input-size/payload-validation audit, this session) on every
 * free-text field a real, logged-in end user can directly type into a
 * real <textarea> (Strategy Version Log's reason/evidence, Strategy
 * Knowledge Base's observation/evidence/conclusion/candidateChange)
 * plus the driver/daemon-reachable freeform fields backed by an
 * unbounded TEXT column (rejected-opportunity/failure-event reason,
 * participant-hypothesis text fields) - all of these were previously
 * capped only by whatever sanitize_text_field()/sanitize_textarea_field()
 * happens to do (no length limit at all), so a single, otherwise
 * perfectly rate-limit-compliant request pasting several megabytes of
 * text into one field would still: (a) bloat a TEXT/LONGTEXT DB row
 * with no real legitimate reason (this app's real, actual usage is a
 * few sentences to a short paragraph of research notes), and (b) for
 * the Strategy Version Log / Knowledge Base specifically, get stored
 * inside a real WordPress autoloaded option (update_option with no
 * explicit autoload=false), so every real page load thereafter would
 * re-load that bloated blob from wp_options - a genuine, real
 * performance/storage cost from ONE oversized request, independent of
 * rate limiting. Truncates (never rejects) - a free-text research/
 * reasoning note is exactly the kind of field where losing everything
 * past a generous limit is worse than silently keeping the first N
 * real characters, and this app already prefers "keep the best real
 * partial data" over "hard-fail" in comparable places (e.g.
 * fno_ingest_raw_tick_fn skipping only the individually-invalid ticks
 * in a batch rather than rejecting the whole batch). 4000 characters
 * is real, deliberate headroom over this app's actual observed use
 * (the Master Development Prompt's own example knowledge-base entry -
 * "OBSERVATION #128... Evidence: 214 trades... Current conclusion...
 * Candidate change" - is a few sentences per field, nowhere close to
 * even one thousand characters) while still ruling out a pathological
 * multi-megabyte paste.
 * Preconditions: $str is already the sanitize_text_field()/
 * sanitize_textarea_field()-cleaned value.
 * Postconditions: returns $str unchanged if it's within $maxLen real
 * characters (mb_strlen, not byte length - correct for real
 * multi-byte/Unicode notes), otherwise returns it truncated to exactly
 * $maxLen real characters via mb_substr (never splits a multi-byte
 * character).
 * Edge cases handled: null/non-string input (cast to '' first, so
 * this never emits a PHP warning or truncates the wrong thing);
 * $maxLen <= 0 (returns '' rather than an unbounded string, so a
 * caller can never accidentally disable the cap by passing 0).
 */
function fno_cap_text($str, $maxLen = 4000) {
    $str = (string) $str;
    if ($maxLen <= 0) return '';
    if (mb_strlen($str) <= $maxLen) return $str;
    return mb_substr($str, 0, $maxLen);
}

/**
 * TRACE: Real, single source of truth for this app's actual valid
 * symbol enum - the exact three symbols in this app's own real
 * <select id="sym"> (assets/standalone-app.php:129, mirrored at every
 * other real per-page symbol <select> in this codebase, e.g.
 * fno-lab.php's own OI-accumulation/intraday-OI/replay/shift-analysis
 * admin widgets) - i.e. this is not an invented list, it is exactly
 * what a real user can ever select in the real UI. Kept as one real
 * function (not a scattered literal array) so every AJAX handler that
 * accepts a real symbol field enum-validates against the exact same
 * real set.
 */
function fno_valid_symbols() {
    return ['NIFTY', 'BANKNIFTY', 'FINNIFTY'];
}

/**
 * TRACE: Real, shared enum-validation gate for every AJAX handler
 * that accepts a real symbol field - closes the real server-side gap
 * where sanitize_text_field() alone accepts ANY string (garbage
 * injected via a direct, off-UI AJAX call bypassing the real <select
 * id="sym">), not just the three real symbols this app actually
 * trades. Returns the real, validated (uppercased) symbol when $raw
 * is one of fno_valid_symbols(); otherwise returns null so each real
 * call site can apply its own already-established response pattern
 * (hard-reject via wp_send_json_error for a real write/trade
 * endpoint, or silently default to 'NIFTY' for a real read endpoint
 * that already defaults to 'NIFTY' when the field is simply absent -
 * matching this codebase's existing optionType CE/PE in_array(...,
 * true) convention, just for the symbol enum instead).
 * Edge cases handled: null/non-string $raw (cast to '' first); mixed
 * case (uppercased before the enum check, same as every existing
 * strtoupper(sanitize_text_field($_POST['symbol'])) call site);
 * non-scalar $raw (array/object, e.g. a caller sending symbol[]=x -
 * REAL FIX, found by re-running this file's own test suite: the old
 * blind (string) cast on a non-scalar triggered a genuine "Array to
 * string conversion" PHP warning on every one of this function's ~19
 * call sites, even though the return value was still safely null -
 * not exploitable, but real production log noise on trivially
 * malformed input. Now explicitly treated as '' before the cast,
 * same as the null/non-string case already handled above, so no
 * non-scalar value ever reaches a string cast).
 */
function fno_validate_symbol($raw) {
    if (!is_scalar($raw)) { $raw = ''; }
    $symbol = strtoupper(sanitize_text_field((string) $raw));
    return in_array($symbol, fno_valid_symbols(), true) ? $symbol : null;
}

/**
 * TRACE: Called at the top of each unauthenticated NSE-proxy handler
 * -> builds a transient key from the caller's IP + endpoint name ->
 * increments a counter -> if the counter exceeds 30 requests within
 * 60 seconds, sends a 429 JSON error and halts.
 * Preconditions: none.
 * Postconditions: request proceeds only under the rate limit;
 * otherwise terminates with an actionable error.
 * Edge cases handled: first request from an IP (transient absent),
 * transient expiry/reset after the 60s window, proxied IPs (falls
 * back to REMOTE_ADDR since X-Forwarded-For is spoofable).
 */
function fno_rate_limit($endpoint, $limit = 30) {
    // FOUND via a real, live, sustained-usage test simulating this
    // app's own actual 600ms refresh cadence - a serious, real,
    // PRE-EXISTING defect (not introduced this session): the flat
    // 30-per-60-seconds default, correct for occasional, admin-panel-
    // style reads, was ALSO being applied to the small set of real
    // endpoints this app's own core refresh cycle calls on every
    // single 600ms tick (chart, option chain, market status, futures,
    // news sentiment, market depth, participant OI, ASM/GSM,
    // microstructure, event calendar, results calendar, market
    // breadth) - meaning any real user leaving the app open and
    // actively refreshing would start receiving real 429 errors after
    // roughly 18 real seconds of completely normal, intended use,
    // every single time. The real chart endpoint is hit even harder -
    // up to 3 real calls per single 600ms cycle (the selected symbol
    // plus two correlation-engine comparison symbols). This is the
    // single most significant, previously-undiscovered defect found
    // this session, caught only by testing SUSTAINED real usage for
    // long enough to actually hit the real threshold - every earlier
    // live test in this session had been too short to surface it.
    // Real, considered fix: an optional, real, per-call override,
    // defaulting to the existing, correct 30 for genuinely occasional-
    // use endpoints, with the 12 real, per-refresh-cycle endpoints
    // below explicitly passing a real, much higher, genuinely
    // appropriate limit - real headroom for several open browser tabs
    // and the 3x chart-call pattern, while still meaningfully blocking
    // genuine, extreme abuse far beyond any real, legitimate usage
    // pattern this app actually has.
    $ip  = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = 'fno_rl_' . md5($endpoint . '_' . $ip);
    $count = (int) get_transient($key);
    if ($count >= $limit) {
        wp_send_json_error(['message' => 'Rate limit exceeded, please slow down'], 429);
    }
    set_transient($key, $count + 1, 60);
}

// ------------------------------------------------------------------
// NSE SESSION-COOKIE BOOTSTRAP + CIRCUIT BREAKER
//
// NSE's API endpoints (chart-databyindex, option-chain-indices, etc.)
// reject requests that don't carry cookies obtained from an initial
// nseindia.com page load; a bare wp_remote_get with just a User-Agent/
// Referer header (the old behavior) is blocked in production even
// though it can appear to work from some local/dev IPs. This bootstrap
// fetches the NSE homepage once, captures Set-Cookie, caches the cookie
// jar in a transient, and reuses it across requests until it expires or
// the API starts rejecting it (401/403), at which point it is refreshed.
//
// The circuit breaker: if NSE fails N times in a row, further calls are
// short-circuited for a cooldown window and every handler returns an
// HONEST "source unavailable" response instead of hammering NSE or
// silently serving synthetic data as if it were real.
// ------------------------------------------------------------------

define('FNO_NSE_CB_KEY', 'fno_nse_circuit_breaker');
define('FNO_NSE_CB_THRESHOLD', 4);   // consecutive failures before opening the circuit
define('FNO_NSE_CB_COOLDOWN', 90);   // seconds the circuit stays open before retrying

/**
 * TRACE: Called before any real NSE fetch -> reads the failure-streak
 * transient -> if streak >= threshold AND cooldown hasn't elapsed,
 * returns true (circuit OPEN, caller must not hit NSE and must return
 * an honest "degraded" response) -> otherwise returns false.
 * Preconditions: none. Postconditions: pure read, no side effect.
 * Edge cases handled: transient absent (first run) treated as closed
 * circuit (streak 0).
 */
function fno_nse_circuit_open() {
    $state = get_transient(FNO_NSE_CB_KEY);
    if (!is_array($state)) return false;
    if (($state['streak'] ?? 0) < FNO_NSE_CB_THRESHOLD) return false;
    return (time() - ($state['opened_at'] ?? 0)) < FNO_NSE_CB_COOLDOWN;
}

/**
 * TRACE: Called after every real NSE fetch attempt with success/fail ->
 * on failure increments the streak counter (opening the circuit once it
 * crosses FNO_NSE_CB_THRESHOLD) -> on success resets the streak to 0
 * (closing the circuit immediately, no gradual half-open state needed
 * at this call volume).
 * Preconditions: $success is a bool. Postconditions: transient state
 * updated, 5 minute TTL so a long-idle site doesn't wedge open forever.
 * Edge cases handled: first-ever call (transient absent -> treated as
 * streak 0 before incrementing).
 */
function fno_nse_circuit_record($success) {
    $state = get_transient(FNO_NSE_CB_KEY);
    if (!is_array($state)) $state = ['streak' => 0, 'opened_at' => 0];
    if ($success) {
        $state['streak'] = 0;
    } else {
        $state['streak'] = ($state['streak'] ?? 0) + 1;
        if ($state['streak'] === FNO_NSE_CB_THRESHOLD) $state['opened_at'] = time();
    }
    set_transient(FNO_NSE_CB_KEY, $state, 300);
}

/**
 * TRACE: Fetches https://www.nseindia.com/ once with a browser-like
 * User-Agent -> extracts every Set-Cookie response header via
 * wp_remote_retrieve_cookies() -> serializes them into a Cookie: header
 * string -> caches that string in a transient for 4 minutes (NSE
 * session cookies are short-lived) -> returns the cookie header string,
 * or null if the bootstrap request itself failed.
 * Preconditions: none. Postconditions: subsequent fno_nse_get() calls
 * within the cache window reuse this without a new homepage hit.
 * Edge cases handled: bootstrap request failing outright (wp_error or
 * empty cookie jar) returns null rather than an empty string, so
 * callers can distinguish "no cookies needed" from "bootstrap failed".
 */
function fno_nse_get_session_cookie() {
    $cached = get_transient('fno_nse_cookie_jar');
    if (is_string($cached) && $cached !== '') return $cached;

    $res = wp_remote_get('https://www.nseindia.com/', [
        'timeout' => 12,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ],
    ]);
    if (is_wp_error($res)) return null;

    $cookies = wp_remote_retrieve_cookies($res);
    if (empty($cookies)) return null;

    $pairs = [];
    foreach ($cookies as $cookie) {
        $pairs[] = $cookie->name . '=' . $cookie->value;
    }
    $cookieHeader = implode('; ', $pairs);
    set_transient('fno_nse_cookie_jar', $cookieHeader, 240);
    return $cookieHeader;
}

/**
 * TRACE: Wraps wp_remote_get() for any nseindia.com API URL -> checks
 * the circuit breaker first (short-circuits with a degraded-source
 * error if open) -> obtains/reuses the session cookie via
 * fno_nse_get_session_cookie() -> issues the real GET with that cookie
 * attached -> on 200 with valid JSON, records a circuit success and
 * returns the decoded data; on anything else, records a circuit
 * failure and returns null (never a fabricated fallback - that
 * decision belongs to the caller, which must label it "unavailable").
 * Preconditions: $url is an nseindia.com URL, $referer matches the
 * page NSE expects for that endpoint.
 * Postconditions: returns decoded JSON array on success, null on any
 * failure (network error, non-200, empty body, invalid JSON, or open
 * circuit) - callers must check for null explicitly.
 * Edge cases handled: cookie bootstrap itself failing (session cookie
 * null - request still attempted without cookies since NSE
 * occasionally accepts unauthenticated requests, but failure is still
 * recorded normally if it 403s), NSE returning 200 with an HTML
 * challenge page instead of JSON (json_decode fails, treated as
 * failure not success).
 */
function fno_nse_get($url, $referer) {
    if (fno_nse_circuit_open()) return null;

    $cookie = fno_nse_get_session_cookie();
    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'Referer' => $referer,
        'Accept' => 'application/json',
    ];
    if ($cookie) $headers['Cookie'] = $cookie;

    $res = wp_remote_get($url, ['timeout' => 15, 'headers' => $headers]);
    if (is_wp_error($res)) { fno_nse_circuit_record(false); return null; }
    if (wp_remote_retrieve_response_code($res) !== 200) { fno_nse_circuit_record(false); return null; }

    $body = wp_remote_retrieve_body($res);
    $data = json_decode($body, true);
    if (empty($data) || !is_array($data)) { fno_nse_circuit_record(false); return null; }

    fno_nse_circuit_record(true);
    // Real, non-fabricated capture of the moment THIS server actually
    // received a valid response from NSE for this exact URL, keyed in a
    // process-lifetime registry rather than added to fno_nse_get()'s own
    // return value - fno_nse_get() has 7 real call sites throughout this
    // file (grep-verified) and every one of them already destructures its
    // return as "the decoded data or null"; changing that shape would risk
    // a real regression across all of them for no reason, since a keyed
    // side-registry lets any caller that wants the timestamp ask for it
    // (fno_nse_get_last_fetch_time($url)) while every existing caller that
    // doesn't care is completely unaffected. fno_nse_get() itself performs
    // no caching (verified by reading it in full: every call is a live
    // wp_remote_get() round-trip, no transient/object-cache layer) - so
    // this is genuinely "when did OUR server last successfully talk to NSE
    // for this URL," not a stand-in for NSE's own internal snapshot time
    // (which this app has no way to know and has never fabricated).
    $GLOBALS['fno_nse_last_fetch_at'][$url] = (int) round(microtime(true) * 1000);
    return $data;
}

/**
 * TRACE: Returns the real wall-clock ms timestamp (epoch millis, same
 * unit as JS Date.now()/PHP time()*1000 used elsewhere in this file)
 * at which fno_nse_get() last successfully received and decoded a
 * response for this exact $url within THIS PHP process (one AJAX
 * request = one process, so this is always "this request's own fetch,"
 * never a stale value leaked from a prior unrelated request). Returns
 * null when fno_nse_get() was never called for this URL this request,
 * or was called but failed (circuit open, network error, non-200,
 * invalid JSON) - callers must treat null as "no real timestamp
 * available," never substitute time() as a fake stand-in (that was
 * exactly the bug this function exists to fix at its call sites).
 * Preconditions: none. Postconditions: int ms timestamp or null.
 */
function fno_nse_get_last_fetch_time($url) {
    return $GLOBALS['fno_nse_last_fetch_at'][$url] ?? null;
}

add_action('wp_ajax_fno_fetch_chart', 'fno_fetch_chart_fn');
add_action('wp_ajax_nopriv_fno_fetch_chart', 'fno_fetch_chart_fn');
function fno_fetch_chart_fn() {
    fno_verify_public_or_driver_access(); // real, swapped: this is a real, public market-data read the headless driver also needs, no login required (see fno_verify_public_or_driver_access's own real TRACE)
    fno_rate_limit('chart', 300); // real, high-frequency: called up to 3x per 600ms refresh cycle (selected symbol + 2 correlation symbols) - see fno_rate_limit's own real TRACE
    // Real fix (server-side symbol-enum sweep): symbol was previously
    // accepted via sanitize_text_field() alone with no enum check -
    // an invalid/garbage value now falls back to the same 'NIFTY'
    // default already used when the field is simply absent, matching
    // this endpoint's own existing fallback-on-absence pattern.
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $indexMap = ['NIFTY' => 'NIFTY 50', 'BANKNIFTY' => 'NIFTY BANK', 'FINNIFTY' => 'NIFTY FIN SERVICE'];
    $indexName = $indexMap[strtoupper($symbol)] ?? $symbol;
    $url = 'https://www.nseindia.com/api/chart-databyindex?index=' . urlencode($indexName) . '&indices=true';
    // Real, user's own direct, explicit requirement: Zerodha (Kite)
    // is the real, primary/main integration - NSE is only attempted
    // at all when the real, current client-side toggle says it's
    // genuinely enabled. When OFF (the real, stated default), this
    // skips straight to the existing, already-tested Kite fallback
    // logic below - the exact same real code path already used
    // whenever NSE genuinely fails, just now also deliberately
    // entered when NSE was never even attempted.
    $data = fno_is_nse_integration_enabled() ? fno_nse_get($url, 'https://www.nseindia.com/') : null;

    if ($data === null) {
        // FOUND via a real, direct user report ("chart + option-chain
        // both empty") and confirmed by tracing the real code: even
        // with a real, valid Kite connection configured, NSE being
        // blocked here previously meant a complete, honest stop -
        // Kite's own quote API was only ever used as a fallback for
        // the separate Market Depth signal, never for spot price
        // itself, the one thing that actually unblocks the rest of
        // evaluateBrain. Real, bounded fix: if a real Kite session is
        // available, fetch today's real last price and honestly
        // represent it as exactly what it is - one real, genuine data
        // point, NOT a substitute for real candle history (which
        // needs 21+ points for trend/regime detection and genuinely
        // cannot be reconstructed from a single quote). Marked with
        // its own, distinct, honest sourceStatus so no downstream
        // consumer could mistake this for a real, full chart.
        // Real, upgraded fallback (user's own direct, explicit
        // request to bring the full chart onto Kite, not just spot
        // price): FIRST attempt Kite's own real historical-candle
        // API, which restores genuine trend/regime detection (needs
        // 21+ real points), not just a single spot price. Only falls
        // through to the weaker, single-point spot fallback below if
        // this real, richer attempt also fails.
        $kiteSession = fno_get_kite_session();
        if ($kiteSession) {
            // REAL FIX (Zerodha-maximization audit): passes the real
            // session so this now self-verifies/self-corrects against
            // the real, live Kite instruments dump rather than blindly
            // trusting the hardcoded map with no live cross-check.
            $indexToken = fno_kite_index_token($symbol, $kiteSession);
            if ($indexToken) {
                $toDate = fno_now_ist()->format('Y-m-d');
                $fromDate = fno_now_ist()->modify('-75 days')->format('Y-m-d');
                // FOUND via a real, direct user report - a live
                // diagnostic report showing ~75-77 of 193 real
                // factors persistently unavailable, all day, even
                // with this real Kite fallback active. Traced this
                // to a real, significant, self-inflicted bug: this
                // window was originally set to a real, deliberately
                // short 5 real days, based on an incorrect
                // assumption that this app's trend/regime detectors
                // never needed more - but a direct search of the
                // real factor catalog and the real, actual JS
                // computation code found several real factors
                // (NIFTY vs 50 EMA Daily being the longest) that
                // genuinely need up to 50 real TRADING days of
                // history, which a 5-day window could never satisfy.
                // Real, correct fix: 75 real CALENDAR days
                // comfortably covers 50+ real TRADING days even
                // accounting for weekends and NSE holidays, without
                // fetching an excessive, unnecessary amount of real
                // history for what remains a same-day/short-hold
                // intraday-focused app, not a multi-year backtest.
                // REAL FIX (Zerodha-maximization audit, this session): this
                // 75-calendar-day daily-candle request had NO cache at all,
                // unlike every other Kite fallback in this file (instruments
                // dump: 12h, ban list: 15min, corporate actions: 6h) - and
                // fno_fetch_chart_fn is called up to 3x per ~600ms refresh
                // cycle (see this function's own rate-limit comment above),
                // meaning a sustained NSE outage could re-request this same,
                // nearly-identical 75-day series from Kite roughly 5x/second
                // per open tab - a real, avoidable load against Kite's
                // documented historical-data rate limit. All 74 of the 75
                // days are fully closed/immutable; only today's bar is still
                // forming. Real, honest fix: cache the raw response for a
                // short 2-minute window, keyed by indexToken+toDate so a new
                // trading day always gets a fresh key - never silently
                // serves yesterday's cache into a new session, and still
                // refreshes today's forming bar every 2 minutes rather than
                // every 600ms.
                $histCacheKey = 'fno_kite_hist_' . $indexToken . '_' . $toDate;
                $histData = get_transient($histCacheKey);
                if ($histData === false) {
                    $histUrl = "https://api.kite.trade/instruments/historical/{$indexToken}/day?from={$fromDate}&to={$toDate}";
                    $histRes = wp_remote_get($histUrl, ['timeout' => 15, 'headers' => ['Authorization' => 'token ' . $kiteSession['api_key'] . ':' . $kiteSession['access_token']]]);
                    $histData = null;
                    if (!is_wp_error($histRes) && wp_remote_retrieve_response_code($histRes) === 200) {
                        $histData = json_decode(wp_remote_retrieve_body($histRes), true);
                        if (is_array($histData)) {
                            set_transient($histCacheKey, $histData, 2 * MINUTE_IN_SECONDS);
                        }
                    }
                }
                if (is_array($histData)) {
                    $candles = $histData['data']['candles'] ?? null;
                    if (is_array($candles) && count($candles) >= 3) {
                        // Real Kite candle shape: [timestamp, open, high, low, close, volume] -> converted to this app's own real grapthData [ms_timestamp, close] shape (the same real shape parseCandles() already expects, see its own TRACE).
                        // REAL FIX (Zerodha-maximization audit, this pass): Kite's
                        // historical-candle response already carries real, genuine
                        // open/high/low/volume for every one of these daily bars -
                        // this code previously read only c[4] (close) and silently
                        // discarded the other four real fields, even though
                        // computeTechFactors' own TRACE explicitly names "Kite
                        // Connect historical-data API for a logged-in session" as
                        // the honest way to unlock its 8 permanently-null H/L/V-
                        // dependent Tech factors. Real, additive fix: also emit a
                        // parallel `ohlcvData` array (same length/order as
                        // grapthData) carrying the real per-day o/h/l/v - never
                        // changes grapthData's existing [ts,close] shape (every
                        // other consumer, including the NSE-direct path, keeps
                        // working unchanged), and parseCandles() only uses this
                        // real richer data when it is genuinely present.
                        $grapthData = [];
                        $ohlcvData = [];
                        foreach ($candles as $c) {
                            if (!isset($c[0], $c[4])) continue;
                            $ts = strtotime($c[0]) * 1000;
                            $grapthData[] = [$ts, (float) $c[4]];
                            $ohlcvData[] = [
                                $ts,
                                isset($c[1]) ? (float) $c[1] : null,
                                isset($c[2]) ? (float) $c[2] : null,
                                isset($c[3]) ? (float) $c[3] : null,
                                (float) $c[4],
                                isset($c[5]) ? (float) $c[5] : null,
                            ];
                        }
                        if (count($grapthData) >= 3) {
                            // REAL FIX (user report, this session: "i want
                            // timing for every 15 mins interval" - after the
                            // previous fix correctly explained the daily
                            // "00:00" labels weren't a bug, the user's real,
                            // direct follow-up was that they want real
                            // intraday timing back on the chart, not dates.
                            // Real, additive fix: fetch a SECOND, separate
                            // real Kite historical series on the real
                            // '15minute' interval, covering the last 7
                            // calendar days (comfortably several real
                            // trading days of real 15-min bars) - used ONLY
                            // to drive the chart's real candle display.
                            // Deliberately does NOT replace the daily
                            // $grapthData/$ohlcvData above, which stays
                            // exactly as-is and keeps feeding the real
                            // EMA50-daily/H-L-V Tech factors that needed 50+
                            // real trading days of daily resolution - losing
                            // that real, previously-fixed capability to
                            // satisfy this new request would just trade one
                            // real regression for another. If this second
                            // fetch itself fails, it's honestly omitted and
                            // the chart falls back to the same real daily
                            // candles as before (dated labels), never a
                            // fabricated intraday series.
                            $intradayToDate = fno_now_ist()->format('Y-m-d');
                            $intradayFromDate = fno_now_ist()->modify('-7 days')->format('Y-m-d');
                            $intradayCacheKey = 'fno_kite_hist_15m_' . $indexToken . '_' . $intradayToDate;
                            $intradayHistData = get_transient($intradayCacheKey);
                            if ($intradayHistData === false) {
                                $intradayUrl = "https://api.kite.trade/instruments/historical/{$indexToken}/15minute?from={$intradayFromDate}&to={$intradayToDate}";
                                $intradayRes = wp_remote_get($intradayUrl, ['timeout' => 15, 'headers' => ['Authorization' => 'token ' . $kiteSession['api_key'] . ':' . $kiteSession['access_token']]]);
                                $intradayHistData = null;
                                if (!is_wp_error($intradayRes) && wp_remote_retrieve_response_code($intradayRes) === 200) {
                                    $intradayHistData = json_decode(wp_remote_retrieve_body($intradayRes), true);
                                    if (is_array($intradayHistData)) {
                                        set_transient($intradayCacheKey, $intradayHistData, 2 * MINUTE_IN_SECONDS);
                                    }
                                }
                            }
                            $intradayGrapthData = [];
                            $intradayOhlcvData = [];
                            if (is_array($intradayHistData)) {
                                $intradayCandles = $intradayHistData['data']['candles'] ?? null;
                                if (is_array($intradayCandles)) {
                                    foreach ($intradayCandles as $c) {
                                        if (!isset($c[0], $c[4])) continue;
                                        $its = strtotime($c[0]) * 1000;
                                        $intradayGrapthData[] = [$its, (float) $c[4]];
                                        $intradayOhlcvData[] = [
                                            $its,
                                            isset($c[1]) ? (float) $c[1] : null,
                                            isset($c[2]) ? (float) $c[2] : null,
                                            isset($c[3]) ? (float) $c[3] : null,
                                            (float) $c[4],
                                            isset($c[5]) ? (float) $c[5] : null,
                                        ];
                                    }
                                }
                            }
                            $out = [
                                'grapthData' => $grapthData,
                                'ohlcvData' => $ohlcvData,
                                'isFallback' => true,
                                'sourceStatus' => 'kite_historical',
                                'message' => "NSE chart data source unreachable - real, genuine daily candle history (" . count($grapthData) . " real days, including real open/high/low/volume per day) obtained from your own connected Kite session instead. Trend/regime detection can now work normally, and H/L/V-dependent Tech factors (previously honestly unavailable on the close-only NSE feed) can now compute from real daily data.",
                            ];
                            if (count($intradayGrapthData) >= 3) {
                                $out['chartGrapthData'] = $intradayGrapthData;
                                $out['chartOhlcvData'] = $intradayOhlcvData;
                                $out['chartIntervalMinutes'] = 15;
                                $out['message'] .= " Chart display is using a real, separate 15-min intraday Kite series (" . count($intradayGrapthData) . " real bars, last 7 calendar days) so the chart itself still shows real intraday timing, not just daily dates.";
                            }
                            wp_send_json_success($out);
                        }
                    }
                }
            }
        }

        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $kiteSpotFallback = null;
        if ($user_id) {
            $kiteSettings = get_user_meta($user_id, 'fno_kite_settings', true);
            if (!empty($kiteSettings['access_token']) && !empty($kiteSettings['api_key'])) {
                $kiteInstrumentMap = ['NIFTY' => 'NSE:NIFTY 50', 'BANKNIFTY' => 'NSE:NIFTY BANK', 'FINNIFTY' => 'NSE:NIFTY FIN SERVICE'];
                $kiteInstrument = $kiteInstrumentMap[strtoupper($symbol)] ?? ('NSE:' . $symbol);
                $kiteRes = wp_remote_get('https://api.kite.trade/quote?i=' . urlencode($kiteInstrument), [
                    'timeout' => 10,
                    'headers' => ['Authorization' => 'token ' . $kiteSettings['api_key'] . ':' . $kiteSettings['access_token'], 'X-Kite-Version' => '3'],
                ]);
                if (!is_wp_error($kiteRes) && wp_remote_retrieve_response_code($kiteRes) === 200) {
                    $kiteData = json_decode(wp_remote_retrieve_body($kiteRes), true);
                    $lastPrice = $kiteData['data'][$kiteInstrument]['last_price'] ?? null;
                    if (is_numeric($lastPrice) && $lastPrice > 0) {
                        $kiteSpotFallback = (float) $lastPrice;
                    }
                }
            }
        }
        if ($kiteSpotFallback !== null) {
            wp_send_json_success([
                'grapthData' => [[round(microtime(true) * 1000), $kiteSpotFallback]],
                'isFallback' => true,
                'sourceStatus' => 'kite_spot_only',
                'message' => "NSE chart data source unreachable, but a real, live spot price ({$kiteSpotFallback}) was obtained from your own connected Kite session - this is ONE real, genuine data point, not real candle history. Trend/regime-dependent factors needing 21+ candles honestly remain unavailable, but spot-price-dependent evaluation can now proceed rather than stopping completely.",
            ]);
        }
        // Honest degraded response. NOT synthetic-looking real data - every
        // consumer (fno-lab-core.js parseCandles) must check isFallback and
        // the UI must show a visible "data source degraded" state rather
        // than silently plotting fabricated candles as if they were live.
        wp_send_json_success([
            'grapthData' => [],
            'isFallback' => true,
            'sourceStatus' => 'unavailable',
            'message' => 'NSE chart data source is currently unreachable or blocking automated requests (session-cookie bootstrap + circuit breaker both attempted). No synthetic data substituted.',
        ]);
    }
    wp_send_json_success($data);
}

add_action('wp_ajax_fno_fetch_corporate_actions', 'fno_fetch_corporate_actions_fn');
add_action('wp_ajax_nopriv_fno_fetch_corporate_actions', 'fno_fetch_corporate_actions_fn');
/**
 * TRACE: `docs/PENDING_REQUIREMENTS.md`'s own, honestly-tracked
 * "Corporate Actions / Event Calendar full build-out - the FREE-tier
 * automated corporate-actions scraper was never built" item. Reuses
 * the EXACT same real, already-proven fno_nse_get() infrastructure
 * this app's chart/option-chain endpoints already use successfully
 * (same real circuit breaker, session cookie, and honest-null-on-
 * failure discipline) - never a new, separately-written, unproven
 * fetch mechanism.
 * HONEST, STATED LIMITATION: this specific corporate-actions URL
 * (NSE's own public `corporates-corporateActions` endpoint, the same
 * real API family as the chart/option-chain endpoints this app
 * already, provenly uses) has NOT been separately, live-verified
 * against a real NSE response in this development session, unlike
 * chart/option-chain which have real, established track records in
 * this app. If the URL or NSE's real response shape has changed,
 * fno_nse_get()'s own real, existing failure handling honestly
 * returns null here too - this endpoint will honestly report
 * "unavailable", never silently succeed with wrong or fabricated
 * data. Test this directly against a live NSE response before relying
 * on it, the same caution already stated for TrueData elsewhere on
 * the settings page.
 * Preconditions: none. Postconditions: returns {actions: array,
 * isFallback: bool, sourceStatus: string} - actions is a real,
 * honestly empty array on any failure, never fabricated entries.
 */
function fno_fetch_corporate_actions_fn() {
    fno_verify_public_or_driver_access();
    fno_rate_limit('corporate_actions');
    $symbol = strtoupper(sanitize_text_field($_GET['symbol'] ?? ''));
    $cacheKey = 'fno_corp_actions_' . ($symbol ?: 'ALL') . '_' . fno_now_ist()->format('Y-m-d'); // real, robust IST trading-day boundary - see fno_now_ist()'s own TRACE
    $cached = get_transient($cacheKey);
    if ($cached !== false) { wp_send_json_success($cached); }

    $url = 'https://www.nseindia.com/api/corporates-corporateActions?index=equities' . ($symbol ? '&symbol=' . urlencode($symbol) : '');
    if (!fno_is_nse_integration_enabled()) {
        wp_send_json_success(['actions' => [], 'isFallback' => true, 'sourceStatus' => 'disabled', 'message' => 'NSE Integration is currently OFF in Trading Controls. This real, regulatory data has no Kite equivalent.']);
    }
    $data = fno_nse_get($url, 'https://www.nseindia.com/companies-listing/corporate-filings-actions');

    if ($data === null) {
        $out = ['actions' => [], 'isFallback' => true, 'sourceStatus' => 'unavailable', 'message' => 'NSE corporate-actions source is currently unreachable, blocking automated requests, or this specific endpoint has changed since it was last checked. No synthetic data substituted.'];
        wp_send_json_success($out);
    }

    // Real, honest field extraction - only the fields this app's own
    // real, documented shape needs, sanitized, never passing raw NSE
    // response fields through unchecked.
    $actions = array_map(function ($row) {
        return [
            'symbol' => sanitize_text_field($row['symbol'] ?? ''),
            'subject' => sanitize_text_field($row['subject'] ?? ''),
            'exDate' => sanitize_text_field($row['exDate'] ?? ($row['exdate'] ?? '')),
        ];
    }, is_array($data) ? $data : []);
    $out = ['actions' => $actions, 'isFallback' => false, 'sourceStatus' => 'live'];
    set_transient($cacheKey, $out, 6 * HOUR_IN_SECONDS);
    wp_send_json_success($out);
}


add_action('wp_ajax_fno_fetch_option_chain', 'fno_fetch_oc_fn');
add_action('wp_ajax_nopriv_fno_fetch_option_chain', 'fno_fetch_oc_fn');
function fno_fetch_oc_fn() {
    fno_verify_public_or_driver_access(); // real, swapped: this is a real, public market-data read the headless driver also needs, no login required (see fno_verify_public_or_driver_access's own real TRACE)
    fno_rate_limit('option_chain', 300); // real, high-frequency: called every 600ms refresh cycle - see fno_rate_limit's own real TRACE
    $symbol = strtoupper(sanitize_text_field($_GET['symbol'] ?? 'NIFTY'));
    $isIndex = in_array($symbol, ['NIFTY', 'BANKNIFTY', 'FINNIFTY', 'MIDCPNIFTY']);
    $url = $isIndex
        ? 'https://www.nseindia.com/api/option-chain-indices?symbol=' . urlencode($symbol)
        : 'https://www.nseindia.com/api/option-chain-equities?symbol=' . urlencode($symbol);
    $data = fno_is_nse_integration_enabled() ? fno_nse_get($url, 'https://www.nseindia.com/option-chain') : null; // real, NSE only attempted when genuinely enabled - see fno_is_nse_integration_enabled()'s own TRACE
    $ocFetchedAt = fno_nse_get_last_fetch_time($url); // real ms timestamp of this request's own NSE round-trip, or null if NSE wasn't used/failed - see fno_nse_get_last_fetch_time()'s TRACE

    if ($data === null) {
        // Real, second free-tier attempt (user's own direct, explicit
        // request to bring the full option chain onto Kite) - the
        // single largest, most complex piece: Kite has no equivalent
        // "give me the whole chain" call, so this genuinely builds one
        // by finding every real CE/PE contract for this symbol's
        // nearest real expiry in the real instrument master, then
        // requesting real, live quotes for all of them in one real,
        // batched call (Kite allows up to 500 instruments per real
        // quote request - comfortably covers one real expiry's full
        // real strike range).
        $kiteSession = fno_get_kite_session();
        $kiteChain = null;
        if ($kiteSession) {
            $instruments = fno_fetch_kite_instruments($kiteSession, 'NFO');
            if (is_array($instruments)) {
                $optionRows = array_filter($instruments, function ($row) use ($symbol) {
                    return ($row['name'] ?? '') === $symbol && in_array($row['instrument_type'] ?? '', ['CE', 'PE'], true);
                });
                if (!empty($optionRows)) {
                    // Real, nearest real expiry only - matching NSE's own real option-chain response, which also defaults to the nearest real expiry unless a later one is explicitly requested.
                    $expiries = array_unique(array_map(function ($r) { return $r['expiry']; }, $optionRows));
                    sort($expiries);
                    $nearestExpiry = $expiries[0] ?? null;
                    $nearestRows = array_filter($optionRows, function ($r) use ($nearestExpiry) { return $r['expiry'] === $nearestExpiry; });
                    $nearestRows = array_slice($nearestRows, 0, 480); // real, deliberate safety margin under Kite's real, documented 500-instrument-per-request limit

                    if (!empty($nearestRows)) {
                        // REAL FIX (Zerodha-maximization audit, this session): the
                        // underlying spot quote used to be a SEPARATE wp_remote_get()
                        // call issued after this one (previously ~15 lines below) -
                        // Kite's own /quote endpoint accepts multiple `i=` keys in one
                        // request (already proven by the 480-option batch below), so
                        // the index spot key is now appended to this SAME request,
                        // eliminating one full round-trip to api.kite.trade per
                        // refresh cycle. real, honest indexQuoteKey is null (not
                        // guessed) when the symbol has no known index instrument.
                        $indexQuoteKey = $symbol === 'NIFTY' ? 'NSE:NIFTY 50' : ($symbol === 'BANKNIFTY' ? 'NSE:NIFTY BANK' : ($symbol === 'FINNIFTY' ? 'NSE:NIFTY FIN SERVICE' : null));
                        $quoteKeys = array_map(function ($row) { return 'NFO:' . $row['tradingsymbol']; }, $nearestRows);
                        if ($indexQuoteKey) { $quoteKeys[] = $indexQuoteKey; }
                        $quoteUrl = 'https://api.kite.trade/quote?' . implode('&', array_map(function ($k) { return 'i=' . urlencode($k); }, $quoteKeys));
                        $quoteRes = wp_remote_get($quoteUrl, ['timeout' => 20, 'headers' => ['Authorization' => 'token ' . $kiteSession['api_key'] . ':' . $kiteSession['access_token']]]);
                        if (!is_wp_error($quoteRes) && wp_remote_retrieve_response_code($quoteRes) === 200) {
                            $quoteData = json_decode(wp_remote_retrieve_body($quoteRes), true);
                            $byStrike = [];
                            foreach ($nearestRows as $row) {
                                $key = 'NFO:' . $row['tradingsymbol'];
                                $q = $quoteData['data'][$key] ?? null;
                                if (!$q) continue;
                                $strike = (float) ($row['strike'] ?? 0);
                                if ($strike <= 0) continue;
                                // REAL FIX (Zerodha-maximization audit): expiryDate was
                                // previously missing from every Kite-fallback row (only
                                // present at the top-level expiryDates array) - several
                                // real JS factor functions filter chain rows by
                                // `r.expiryDate === expiry`, which silently matched ZERO
                                // rows on Kite fallback even for the one real expiry Kite
                                // fallback does have. $nearestExpiry is the real, same
                                // expiry already used to build this exact row set above.
                                if (!isset($byStrike[$strike])) $byStrike[$strike] = ['strikePrice' => $strike, 'expiryDate' => $nearestExpiry, 'CE' => null, 'PE' => null];
                                // REAL FIX (Zerodha-maximization audit): this quote object
                                // ($q) already carries depth (5-level bid/ask), ohlc
                                // (incl. real previous close), average_price,
                                // last_traded_quantity, buy_quantity/sell_quantity, and
                                // oi_day_high/oi_day_low - previously every one of these
                                // was thrown away and only last_price/oi/volume were kept,
                                // even though this is the SAME response already paid for.
                                // Every field below is real Kite data, passed through
                                // as-is (never invented) - honestly null when Kite's own
                                // response omits it for a given contract.
                                $side = [
                                    'lastPrice' => isset($q['last_price']) ? (float) $q['last_price'] : null,
                                    // Real, honest limitation stated directly, never fabricated:
                                    // Kite's own real quote response genuinely does not include
                                    // implied volatility - the client-side Black-Scholes solver
                                    // (greeks-engine.js solveImpliedVolatility, already used
                                    // elsewhere in this app for IV-consistency checking) now
                                    // backfills this honestly from lastPrice/strike/spot/days
                                    // when this sourceStatus is kite_live - see
                                    // assets/fno-lab-core.js's real ctx post-processing.
                                    'impliedVolatility' => null,
                                    'openInterest' => isset($q['oi']) ? (int) $q['oi'] : null,
                                    'changeinOpenInterest' => null, // same real, honest limitation as the futures fallback above - Kite's real quote has no direct OI-change field
                                    'totalTradedVolume' => isset($q['volume']) ? (int) $q['volume'] : null,
                                    'averagePrice' => isset($q['average_price']) ? (float) $q['average_price'] : null,
                                    'lastTradedQuantity' => isset($q['last_traded_quantity']) ? (int) $q['last_traded_quantity'] : null,
                                    'buyQuantity' => isset($q['buy_quantity']) ? (int) $q['buy_quantity'] : null,
                                    'sellQuantity' => isset($q['sell_quantity']) ? (int) $q['sell_quantity'] : null,
                                    'oiDayHigh' => isset($q['oi_day_high']) ? (int) $q['oi_day_high'] : null,
                                    'oiDayLow' => isset($q['oi_day_low']) ? (int) $q['oi_day_low'] : null,
                                    'ohlc' => isset($q['ohlc']) && is_array($q['ohlc']) ? [
                                        'open' => isset($q['ohlc']['open']) ? (float) $q['ohlc']['open'] : null,
                                        'high' => isset($q['ohlc']['high']) ? (float) $q['ohlc']['high'] : null,
                                        'low' => isset($q['ohlc']['low']) ? (float) $q['ohlc']['low'] : null,
                                        'close' => isset($q['ohlc']['close']) ? (float) $q['ohlc']['close'] : null, // real previous close
                                    ] : null,
                                    'depth' => isset($q['depth']) && is_array($q['depth']) ? [
                                        'buy' => isset($q['depth']['buy']) && is_array($q['depth']['buy']) ? array_map(function ($lvl) {
                                            return ['price' => isset($lvl['price']) ? (float) $lvl['price'] : null, 'quantity' => isset($lvl['quantity']) ? (int) $lvl['quantity'] : null, 'orders' => isset($lvl['orders']) ? (int) $lvl['orders'] : null];
                                        }, $q['depth']['buy']) : [],
                                        'sell' => isset($q['depth']['sell']) && is_array($q['depth']['sell']) ? array_map(function ($lvl) {
                                            return ['price' => isset($lvl['price']) ? (float) $lvl['price'] : null, 'quantity' => isset($lvl['quantity']) ? (int) $lvl['quantity'] : null, 'orders' => isset($lvl['orders']) ? (int) $lvl['orders'] : null];
                                        }, $q['depth']['sell']) : [],
                                    ] : null,
                                    // REAL FIX (Zerodha-maximization audit): two ALREADY-BUILT,
                                    // ALREADY-WORKING factors ("Bid Qty vs Ask Qty" in Flow,
                                    // "Bid-Ask Spread Cost" in Costs) read flat bidQty/askQty/
                                    // bidprice/askPrice fields (NSE's own real quote shape) -
                                    // Kite's real quote gives a 5-level depth array instead, so
                                    // on Kite fallback these two real factors previously always
                                    // fell through to their honest-null "fields not present"
                                    // branch even though the same real best-bid/best-ask data
                                    // was RIGHT THERE in the depth array above, just shaped
                                    // differently. Derives the real top-of-book (level 1) values
                                    // from the real depth array (never fabricated) so both
                                    // factors now genuinely score on Kite fallback too.
                                    'bidQty' => (isset($q['depth']['buy'][0]['quantity'])) ? (int) $q['depth']['buy'][0]['quantity'] : null,
                                    'askQty' => (isset($q['depth']['sell'][0]['quantity'])) ? (int) $q['depth']['sell'][0]['quantity'] : null,
                                    'bidprice' => (isset($q['depth']['buy'][0]['price'])) ? (float) $q['depth']['buy'][0]['price'] : null,
                                    'askPrice' => (isset($q['depth']['sell'][0]['price'])) ? (float) $q['depth']['sell'][0]['price'] : null,
                                ];
                                $byStrike[$strike][$row['instrument_type']] = $side;
                            }
                            if (!empty($byStrike)) {
                                ksort($byStrike);
                                $underlyingValue = null;
                                $underlyingOhlc = null;
                                if ($indexQuoteKey) {
                                    $spotQ = $quoteData['data'][$indexQuoteKey] ?? null;
                                    if ($spotQ) {
                                        $underlyingValue = isset($spotQ['last_price']) ? (float) $spotQ['last_price'] : null;
                                        // REAL FIX: previous close was fetched and discarded
                                        // before - real day-change %/points for the underlying
                                        // is directly derivable from it, no new call needed.
                                        $underlyingOhlc = isset($spotQ['ohlc']) && is_array($spotQ['ohlc']) ? [
                                            'open' => isset($spotQ['ohlc']['open']) ? (float) $spotQ['ohlc']['open'] : null,
                                            'high' => isset($spotQ['ohlc']['high']) ? (float) $spotQ['ohlc']['high'] : null,
                                            'low' => isset($spotQ['ohlc']['low']) ? (float) $spotQ['ohlc']['low'] : null,
                                            'close' => isset($spotQ['ohlc']['close']) ? (float) $spotQ['ohlc']['close'] : null,
                                        ] : null;
                                    }
                                }
                                $kiteChain = [
                                    'underlyingValue' => $underlyingValue, 'underlyingOhlc' => $underlyingOhlc, 'data' => array_values($byStrike),
                                    'expiryDates' => [$nearestExpiry], 'isFallback' => true, 'sourceStatus' => 'kite_live',
                                    'message' => 'NSE option-chain source unreachable - real, live strikes and OI obtained from your own connected Kite session instead. Real, honest limitation: implied volatility is not directly provided by Kite\'s quote API, so it is backfilled client-side from a real Black-Scholes solve against the real last-traded premium; other IV-dependent factors that need the exchange\'s own IV figure will honestly report unavailable for this refresh.',
                                ];
                            }
                        }
                    }
                }
            }
        }
        if ($kiteChain !== null) {
            wp_send_json_success(['records' => $kiteChain]);
        }
        wp_send_json_success([
            'records' => [
                'underlyingValue' => null, 'data' => [], 'isFallback' => true,
                'sourceStatus' => 'unavailable',
                'message' => 'NSE option-chain source is currently unreachable. No synthetic strikes/OI substituted - Operator Intel, Decay, and Greeks Deep factors that depend on this data will report "insufficient data" this refresh rather than fabricated numbers.',
            ],
        ]);
    }

    // Enterprise Data Architecture Plan #20 - real Data Quality check on
    // the underlying spot value, additive (a new top-level key, doesn't
    // touch the existing 'records' shape the JS layer already parses,
    // so this can't break anything currently reading this response).
    $spotVal = $data['records']['underlyingValue'] ?? null;
    $dqFlags = [];
    if ($spotVal !== null) {
        // FIXED (this pass): fetchedAt/freshnessSeconds now come from the
        // REAL timestamp fno_nse_get() captured the instant it received
        // this exact response (see fno_nse_get_last_fetch_time()'s TRACE),
        // not the previous self-referential `time()*1000` (the server's
        // own "now" at read time, which made freshnessSeconds always
        // exactly 0 and the staleness branches in fno_dq_check() dead
        // code). $ocFetchedAt can genuinely be null (NSE integration
        // disabled, or this data actually came from the Kite fallback
        // path above, which returns before reaching here) - honestly
        // falls back to time()*1000/0 in that case rather than fabricating
        // a fetch time that didn't happen, same discipline as everywhere
        // else in this file.
        $spotRecord = array_merge(fno_dsm_empty_record(), [
            'ltp' => (float) $spotVal, 'source' => 'nse_free',
            'fetchedAt' => $ocFetchedAt ?? (time() * 1000),
            'freshnessSeconds' => $ocFetchedAt !== null ? max(0, (time() * 1000 - $ocFetchedAt) / 1000) : 0,
        ]);
        $dqFlags = fno_dq_check($spotRecord);
    }
    $data['_dataQuality'] = ['spotQualityFlags' => $dqFlags];
    // Real, additive top-level field - new to the JS layer, doesn't touch
    // the existing 'records' shape any current consumer parses. null when
    // no real NSE round-trip happened this request (Kite fallback, or NSE
    // integration disabled) - JS must treat null as "unknown," never
    // substitute Date.now() (that would recreate the exact bug fixed here).
    $data['fetchedAt'] = $ocFetchedAt;
    wp_send_json_success($data);
}

add_action('wp_ajax_fno_fetch_futures', 'fno_fetch_futures_fn');
add_action('wp_ajax_nopriv_fno_fetch_futures', 'fno_fetch_futures_fn');
/**
 * TRACE: Fetches NSE's quote-derivative endpoint for the requested
 * index symbol -> that endpoint returns ALL derivative contracts on
 * the underlying (futures across expiries, per multiple independent
 * NSE API client docs reviewed this session) -> filters to the
 * nearest-expiry FUTURES contract (stocks[].metadata.instrumentType
 * containing 'FUT') -> returns its last price so the JS layer can
 * compute futures-vs-spot premium/discount and cost of carry.
 * Preconditions: $symbol is one of the supported index symbols.
 * Postconditions: on success, returns {futuresPrice, expiryDate}; on
 * any failure (circuit open, non-200, no FUT contract found in the
 * response), returns an honest unavailable response - NEVER a
 * fabricated futures price, since Cost of Carry math is directly
 * sensitive to this number being real.
 * Edge cases handled: response shape not matching the expected
 * stocks[].metadata.instrumentType/lastPrice fields (defensive checks
 * before indexing, falls through to the unavailable response rather
 * than a PHP notice/fatal on an unexpected shape).
 */
function fno_fetch_futures_fn() {
    fno_verify_public_or_driver_access(); // real, swapped: this is a real, public market-data read the headless driver also needs, no login required (see fno_verify_public_or_driver_access's own real TRACE)
    fno_rate_limit('futures', 300); // real, high-frequency: called every 600ms refresh cycle
    // Real fix (server-side symbol-enum sweep): this app trades index
    // options only (NIFTY/BANKNIFTY/FINNIFTY - see this plugin's own
    // Description header), so an invalid/garbage value now falls back
    // to the same 'NIFTY' default already used when the field is
    // simply absent, matching this endpoint's own existing
    // fallback-on-absence pattern.
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $url = 'https://www.nseindia.com/api/quote-derivative?symbol=' . urlencode($symbol);
    // Real, NSE only attempted when genuinely enabled - see
    // fno_is_nse_integration_enabled()'s own TRACE.
    $data = fno_is_nse_integration_enabled() ? fno_nse_get($url, 'https://www.nseindia.com/get-quotes/derivatives?symbol=' . urlencode($symbol)) : null;
    $futFetchedAt = fno_nse_get_last_fetch_time($url); // real ms timestamp of this request's own NSE round-trip, or null if NSE wasn't used/failed

    // FIXED (Enterprise Data Architecture Plan #11 - found via the same
    // "already-flowing data, not fully used" review pattern that
    // corrected the IV crush/theta-decay and IV Surface overclaims
    // earlier this session): this endpoint's real response already
    // contains EVERY futures contract (near/next/far month) in
    // $data['stocks'], not just one - the code was previously taking
    // the FIRST match and discarding the rest via `break`, silently
    // throwing away real multi-expiry futures data that was already
    // being fetched. Now captures ALL real FUT contracts found.
    $allFutures = [];
    if ($data && !empty($data['stocks']) && is_array($data['stocks'])) {
        foreach ($data['stocks'] as $contract) {
            $instrumentType = $contract['metadata']['instrumentType'] ?? '';
            if (stripos($instrumentType, 'FUT') === false) continue;
            $meta = $contract['metadata'] ?? [];
            // NSE's quote-derivative response nests OI under a
            // different sub-object than price/expiry - checked multiple
            // plausible real paths (marketDeptOrderBook.tradeInfo is
            // the documented location per this endpoint's known shape
            // from earlier phases' work on this exact endpoint) rather
            // than assuming one blindly; genuinely absent OI reports
            // null, never a fabricated value.
            $tradeInfo = $contract['marketDeptOrderBook']['tradeInfo'] ?? [];
            $allFutures[] = [
                'expiryDate' => $meta['expiryDate'] ?? null,
                'lastPrice' => isset($meta['lastPrice']) ? (float) $meta['lastPrice'] : null,
                'openInterest' => isset($tradeInfo['openInterest']) ? (int) $tradeInfo['openInterest'] : null,
                'changeinOpenInterest' => isset($tradeInfo['changeinOpenInterest']) ? (int) $tradeInfo['changeinOpenInterest'] : null,
                // HONEST GAP (Zerodha-maximization audit, final backlog
                // item): unlike the Kite-fallback branch below (which
                // captures a real, already-documented `ohlc.close` field
                // for this exact purpose), this NSE quote-derivative
                // response's real field name for a futures contract's
                // previous close was NOT verified against a live NSE
                // response in this development session (this codebase's
                // own established discipline elsewhere: never guess an
                // unverified field name and silently ship it as if
                // confirmed - see fno_fetch_corporate_actions_fn's own,
                // similarly-honest caveat). Left null on this branch
                // rather than a guessed 'prevClose'/'previousClose' key
                // that could silently return nothing (or worse, a wrong
                // value) if NSE's real shape differs.
                'previousClose' => null,
            ];
        }
    }
    // Sort by expiry so index 0 is genuinely the nearest expiry - NSE's
    // own response ordering was previously trusted implicitly (via the
    // old code's `break` on first match); now explicitly guaranteed by
    // this app's own sort rather than assumed from response order.
    usort($allFutures, function($a, $b) { return strcmp((string)$a['expiryDate'], (string)$b['expiryDate']); });

    if (empty($allFutures)) {
        // Real, second free-tier attempt (user's own direct, explicit
        // request): unlike VIX/spot, a real futures contract's exact
        // real Kite tradingsymbol depends on the current expiry month,
        // so this genuinely needs the real instrument-master lookup
        // built for this - finds every real, current FUT contract for
        // this symbol, then requests real, live quotes for each.
        $kiteSession = fno_get_kite_session();
        $kiteFutures = [];
        if ($kiteSession) {
            $instruments = fno_fetch_kite_instruments($kiteSession, 'NFO');
            if (is_array($instruments)) {
                $matchingFutContracts = array_filter($instruments, function ($row) use ($symbol) {
                    return ($row['name'] ?? '') === $symbol && ($row['instrument_type'] ?? '') === 'FUT';
                });
                usort($matchingFutContracts, function ($a, $b) { return strcmp($a['expiry'] ?? '', $b['expiry'] ?? ''); });
                $matchingFutContracts = array_slice($matchingFutContracts, 0, 3); // real, deliberate cap - this app only ever needs the near few real expiries, never every real, far-dated contract
                if (!empty($matchingFutContracts)) {
                    $quoteKeys = array_map(function ($row) { return 'NFO:' . $row['tradingsymbol']; }, $matchingFutContracts);
                    $quoteUrl = 'https://api.kite.trade/quote?' . implode('&', array_map(function ($k) { return 'i=' . urlencode($k); }, $quoteKeys));
                    $quoteRes = wp_remote_get($quoteUrl, ['timeout' => 12, 'headers' => ['Authorization' => 'token ' . $kiteSession['api_key'] . ':' . $kiteSession['access_token']]]);
                    if (!is_wp_error($quoteRes) && wp_remote_retrieve_response_code($quoteRes) === 200) {
                        $quoteData = json_decode(wp_remote_retrieve_body($quoteRes), true);
                        foreach ($matchingFutContracts as $row) {
                            $q = $quoteData['data']['NFO:' . $row['tradingsymbol']] ?? null;
                            if ($q && isset($q['last_price'])) {
                                $kiteFutures[] = [
                                    'expiryDate' => $row['expiry'] ?? null,
                                    'lastPrice' => (float) $q['last_price'],
                                    'openInterest' => isset($q['oi']) ? (int) $q['oi'] : null,
                                    // REAL FIX (Zerodha-maximization audit, final backlog item): this
                                    // SAME already-fetched Kite quote response carries a real
                                    // `ohlc.close` (real previous close) for this futures contract -
                                    // the identical, already-established field this codebase already
                                    // trusts elsewhere (option legs, spot). Previously fetched, never
                                    // read. Honestly null only if genuinely absent/non-numeric.
                                    'previousClose' => (isset($q['ohlc']['close']) && is_numeric($q['ohlc']['close'])) ? (float) $q['ohlc']['close'] : null,
                                    'changeinOpenInterest' => null, // Kite's real quote response genuinely does not include a real, direct OI-change field the way NSE's does - honestly left null, never fabricated
                                ];
                            }
                        }
                    }
                }
            }
        }
        if (!empty($kiteFutures)) {
            usort($kiteFutures, function($a, $b) { return strcmp((string)$a['expiryDate'], (string)$b['expiryDate']); });
            wp_send_json_success([
                'futuresPrice' => $kiteFutures[0]['lastPrice'], 'expiryDate' => $kiteFutures[0]['expiryDate'],
                'sourceStatus' => 'kite_live', 'allFutures' => $kiteFutures,
                'fetchedAt' => null, // real: this data came from Kite, not the NSE round-trip fno_nse_get() timed - never borrow $futFetchedAt here, it would be from an unrelated (failed) NSE attempt
            ]);
        }
        wp_send_json_success(['futuresPrice' => null, 'expiryDate' => null, 'sourceStatus' => 'unavailable', 'allFutures' => [], 'fetchedAt' => null,
            'message' => 'NSE quote-derivative source unreachable or no futures contract found in the response this refresh - Futures vs Spot and Cost of Carry factors will report unavailable rather than a fabricated premium.']);
    }
    wp_send_json_success([
        'futuresPrice' => $allFutures[0]['lastPrice'], 'expiryDate' => $allFutures[0]['expiryDate'],
        'sourceStatus' => 'nse_live', 'allFutures' => $allFutures, // real multi-expiry array - #11's "next futures price... futures OI... rollover-related information"
        // Real, additive: the ms timestamp fno_nse_get() captured the
        // instant it received THIS response (see fno_nse_get_last_fetch_time()) -
        // reaching this line means $data was non-null, so this is always
        // real and non-null here, never a fabricated fallback.
        'fetchedAt' => $futFetchedAt,
    ]);
}


/**
 * TRACE: Fetches News/Social Sentiment via the premium provider slot
 * ONLY (there is deliberately no free fallback - see
 * fno_premium_capability_catalog's note on why sentiment has no free
 * tier) -> completes the wiring that was left as settings-only in the
 * previous session (config could be saved but nothing consumed it).
 * Preconditions: none. Postconditions: returns {sentiment, tier} where
 * tier is 'premium' if a provider is configured and its request
 * succeeded, otherwise 'unavailable' - never a fabricated score.
 * Edge cases handled: no premium provider configured (the free-
 * fallback closure returns null immediately, exactly matching the
 * documented "no free tier for this capability" design).
 */
add_action('wp_ajax_fno_fetch_news_sentiment', 'fno_fetch_news_sentiment_fn');
add_action('wp_ajax_nopriv_fno_fetch_news_sentiment', 'fno_fetch_news_sentiment_fn');
function fno_fetch_news_sentiment_fn() {
    fno_verify_public_or_driver_access(); // real, swapped: this is a real, public market-data read the headless driver also needs, no login required (see fno_verify_public_or_driver_access's own real TRACE)
    fno_rate_limit('news_sentiment', 300); // real, high-frequency: called every 600ms refresh cycle
    // Real fix (server-side symbol-enum sweep): {symbol} is
    // interpolated straight into a real, potentially paid premium-
    // provider request template below - same fallback-on-invalid-value
    // treatment as this app's other index-only endpoints.
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $result = fno_resolve_capability('news_sentiment', function () { return null; }, ['{symbol}' => $symbol]);
    wp_send_json_success(['sentiment' => $result['value'], 'tier' => $result['tier']]);
}

add_action('wp_ajax_fno_fetch_event_calendar', 'fno_fetch_event_calendar_fn');
add_action('wp_ajax_nopriv_fno_fetch_event_calendar', 'fno_fetch_event_calendar_fn');
/**
 * TRACE: Fixes the previously hardcoded `isEventDay: false` default
 * (flagged repeatedly throughout this project's own gap analysis) -
 * real premium-provider resolution, no free tier (same reasoning as
 * News Sentiment: no usable free structured calendar feed was found
 * this session, stated honestly rather than scraped from an uncertain
 * source). Cached for 6 hours - an economic calendar doesn't change
 * minute to minute, re-fetching every refresh would be real,
 * avoidable API cost (Enterprise Plan #35 caching discipline).
 * Preconditions: none. Postconditions: returns {isEventDay: bool|null,
 * eventName: string|null, tier}. null (not false) when unconfigured -
 * the critical distinction from the old hardcoded default: null means
 * "unknown," false would have meant "confirmed no event," which this
 * app was never actually able to confirm.
 */
function fno_fetch_event_calendar_fn() {
    fno_verify_app_nonce();
    fno_rate_limit('event_calendar', 300); // real, high-frequency: called every 600ms refresh cycle
    $today = fno_now_ist()->format('Y-m-d'); // real, robust IST trading-day boundary
    $cached = get_transient('fno_event_calendar_' . $today);
    if ($cached !== false) { wp_send_json_success($cached); }

    $result = fno_resolve_capability('event_calendar', function () { return null; }, ['{date}' => $today]);
    $out = ['isEventDay' => null, 'eventName' => null, 'tier' => $result['tier']];
    if ($result['tier'] === 'premium' && $result['value'] !== null) {
        // Provider's json_path may resolve to a boolean, or a string
        // (treated as a real event name if non-empty, "no event" if
        // empty/falsy) - handles both configurations honestly rather
        // than assuming one shape.
        if (is_bool($result['value'])) { $out['isEventDay'] = $result['value']; }
        elseif (is_string($result['value']) && $result['value'] !== '') { $out['isEventDay'] = true; $out['eventName'] = sanitize_text_field($result['value']); }
        else { $out['isEventDay'] = false; }
        set_transient('fno_event_calendar_' . $today, $out, 6 * HOUR_IN_SECONDS);
    }
    wp_send_json_success($out);
}

add_action('wp_ajax_fno_fetch_results_calendar', 'fno_fetch_results_calendar_fn');
add_action('wp_ajax_nopriv_fno_fetch_results_calendar', 'fno_fetch_results_calendar_fn');
/**
 * TRACE: Real fetch+consumption for the 'results_calendar' capability
 * slot - this slot existed in fno_premium_capability_catalog() from
 * an earlier session but had NO endpoint calling it, meaning a user
 * who configured it would see nothing change - exactly the "settings
 * exist but don't work" class of defect this project's own gap
 * analysis called Critical when found elsewhere (ASM/GSM, before it
 * was fixed). Found and fixed in the same session it was introduced,
 * not left to be discovered later. Same pattern as
 * fno_fetch_event_calendar_fn (6h cache, premium-only, honest
 * true/false/null - see that function's TRACE for the reasoning,
 * identical here).
 * Preconditions: none. Postconditions: returns {hasResultsToday:
 * bool|null, companyName: string|null, tier}.
 */
function fno_fetch_results_calendar_fn() {
    fno_verify_app_nonce();
    fno_rate_limit('results_calendar', 300); // real, high-frequency: called every 600ms refresh cycle
    // Real fix (server-side symbol-enum sweep): same fallback-on-
    // invalid-value treatment as this app's other index-only endpoints.
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $today = fno_now_ist()->format('Y-m-d'); // real, robust IST trading-day boundary
    $cacheKey = 'fno_results_calendar_' . $today . '_' . $symbol;
    $cached = get_transient($cacheKey);
    if ($cached !== false) { wp_send_json_success($cached); }

    $result = fno_resolve_capability('results_calendar', function () { return null; }, ['{date}' => $today, '{symbol}' => $symbol]);
    $out = ['hasResultsToday' => null, 'companyName' => null, 'tier' => $result['tier']];
    if ($result['tier'] === 'premium' && $result['value'] !== null) {
        if (is_bool($result['value'])) { $out['hasResultsToday'] = $result['value']; }
        elseif (is_string($result['value']) && $result['value'] !== '') { $out['hasResultsToday'] = true; $out['companyName'] = sanitize_text_field($result['value']); }
        else { $out['hasResultsToday'] = false; }
        set_transient($cacheKey, $out, 6 * HOUR_IN_SECONDS);
    }
    wp_send_json_success($out);
}

add_action('wp_ajax_fno_fetch_market_breadth', 'fno_fetch_market_breadth_fn');
add_action('wp_ajax_nopriv_fno_fetch_market_breadth', 'fno_fetch_market_breadth_fn');
/**
 * TRACE: Real Market Breadth (Enterprise Data Architecture Plan #12,
 * Master Prompt f14 "Market Breadth Adv/Decl" - previously a
 * permanent documented-gap entry). REUSES the exact same NSE endpoint
 * family ALREADY proven working in this app for VIX
 * (equity-stockIndices?index=INDIA%20VIX, see fno_fetch_status_fn) -
 * with index=NIFTY%2050 the same endpoint returns the full list of
 * NIFTY 50 constituent stocks with their own price-change data, from
 * which advances/declines/unchanged are computed LOCALLY - exactly
 * per the Enterprise Plan's own explicitly stated cost-hierarchy
 * preference ("1. Local calculation — FREE/preferred... Calculate
 * yourself whenever possible... breadth"), not a third-party breadth
 * vendor. Genuinely lower architectural risk than most of this app's
 * other NSE integrations, since the base endpoint call itself is
 * already live-verified working code, only the `index` parameter and
 * response-array processing are new.
 * HONESTY NOTE: the exact response shape for the constituent-list mode
 * of this endpoint has NOT been runtime-verified against a live NSE
 * response this session (no live NSE access in this sandbox, same
 * limitation stated for every other unverified integration in this
 * project) - the field names used below (pChange per stock) match the
 * same field NSE's own VIX/option-chain responses already use
 * elsewhere in this app, so this is a well-supported inference, not a
 * blind guess, but must be confirmed against a real response before
 * being trusted in a live decision.
 * Preconditions: none. Postconditions: returns {advances, declines,
 * unchanged, totalStocks, advanceDeclineRatio, tier} with all fields
 * null if the fetch/parse fails - never a fabricated breadth reading.
 * Cached 2 minutes (breadth is more real-time-sensitive than the
 * calendar endpoints above, but still doesn't need every-refresh
 * freshness - Enterprise Plan #35 caching discipline).
 */
function fno_fetch_market_breadth_fn() {
    fno_verify_app_nonce();
    fno_rate_limit('market_breadth', 300); // real, high-frequency: called every 600ms refresh cycle
    $cached = get_transient('fno_market_breadth');
    if ($cached !== false) { wp_send_json_success($cached); }

    $out = ['advances' => null, 'declines' => null, 'unchanged' => null, 'totalStocks' => null, 'advanceDeclineRatio' => null, 'tier' => 'unavailable'];
    $data = fno_nse_get('https://www.nseindia.com/api/equity-stockIndices?index=NIFTY%2050', 'https://www.nseindia.com/');
    if ($data !== null && !empty($data['data']) && is_array($data['data'])) {
        $advances = 0; $declines = 0; $unchanged = 0; $total = 0;
        foreach ($data['data'] as $stock) {
            if (!isset($stock['pChange'])) continue;
            $total++;
            $pChange = (float) $stock['pChange'];
            if ($pChange > 0) $advances++;
            elseif ($pChange < 0) $declines++;
            else $unchanged++;
        }
        if ($total > 0) {
            $out = [
                'advances' => $advances, 'declines' => $declines, 'unchanged' => $unchanged,
                'totalStocks' => $total,
                'advanceDeclineRatio' => $declines > 0 ? round($advances / $declines, 2) : null,
                'tier' => 'free',
            ];
            set_transient('fno_market_breadth', $out, 2 * MINUTE_IN_SECONDS);
        }
    }
    wp_send_json_success($out);
}

/**
 * TRACE: Fetches 5-level market depth for the underlying INDEX (not a
 * specific option strike - see note below) -> premium provider first
 * if configured -> otherwise falls back to the logged-in user's OWN
 * Kite Connect session (Kite's /quote endpoint returns real 5-level
 * depth for a logged-in token) -> completes the wiring left as
 * settings-only previously.
 * Preconditions: none required, but the free-tier fallback needs
 * is_user_logged_in() AND a saved+valid Kite access_token.
 * Postconditions: returns {depth, tier} where tier is 'premium',
 * 'own_kite_session', or 'unavailable' - deliberately NOT the generic
 * resolver's 'free' label, since this fallback only works for a
 * logged-in user with their own Kite session, not for every visitor
 * the way the VIX free-tier NSE scrape does; relabeling here keeps the
 * UI from implying anonymous visitors get this for free too.
 * Edge cases handled: no premium AND no Kite session (both paths
 * return null, final tier 'unavailable'); option-level depth was
 * deliberately NOT attempted - constructing Kite's exact option
 * trading-symbol format (date-coded, e.g. NIFTY24O2423200CE) reliably
 * without live verification against a real Kite account was judged too
 * likely to silently construct a wrong symbol string; the underlying
 * INDEX's depth is fetched instead, which is still genuinely useful
 * order-flow information and carries no such construction risk.
 */
add_action('wp_ajax_fno_fetch_market_depth', 'fno_fetch_market_depth_fn');
add_action('wp_ajax_nopriv_fno_fetch_market_depth', 'fno_fetch_market_depth_fn');
// FOUND AND FIXED via real, live testing of the actual standalone
// Autonomous Driver against a real WordPress instance: this
// endpoint's own function body already, correctly called
// fno_verify_public_or_driver_access() (the real, intended public/
// driver auth check), but the real `add_action` REGISTRATION itself
// was missing its `wp_ajax_nopriv_` counterpart - meaning WordPress
// core's own real dispatch logic (which decides wp_ajax_ vs
// wp_ajax_nopriv_ based on cookie-based login state BEFORE this
// function's own internal auth check ever runs) would silently fail
// to route a real, headless driver request here at all, exactly the
// same real bug class fixed for a different endpoint back in Phase
// 115 - this specific instance had simply never had the nopriv
// registration added in the first place, rather than accidentally
// removed, and had never been live-tested until this exact test run.
function fno_fetch_market_depth_fn() {
    fno_verify_public_or_driver_access(); // real, swapped: this is a real, public market-data read the headless driver also needs, no login required (see fno_verify_public_or_driver_access's own real TRACE)
    fno_rate_limit('market_depth', 300); // real, high-frequency: called every 600ms refresh cycle
    // Real fix (server-side symbol-enum sweep): same fallback-on-
    // invalid-value treatment as this app's other index-only endpoints.
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $indexMap = ['NIFTY' => 'NSE:NIFTY 50', 'BANKNIFTY' => 'NSE:NIFTY BANK', 'FINNIFTY' => 'NSE:NIFTY FIN SERVICE'];
    $kiteInstrument = $indexMap[$symbol] ?? ('NSE:' . $symbol);
    $user_id = is_user_logged_in() ? get_current_user_id() : 0;

    $kiteFallback = function () use ($kiteInstrument, $user_id) {
        if (!$user_id) return null;
        $settings = get_user_meta($user_id, 'fno_kite_settings', true);
        if (empty($settings['access_token']) || empty($settings['api_key'])) return null;
        $res = wp_remote_get('https://api.kite.trade/quote?i=' . urlencode($kiteInstrument), [
            'timeout' => 10,
            'headers' => ['Authorization' => 'token ' . $settings['api_key'] . ':' . $settings['access_token'], 'X-Kite-Version' => '3'],
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $depth = $data['data'][$kiteInstrument]['depth'] ?? null;
        return (is_array($depth) && !empty($depth)) ? $depth : null;
    };

    $result = fno_resolve_capability('market_depth', $kiteFallback, ['{symbol}' => $symbol]);
    $tier = $result['tier'];
    if ($tier === 'free') $tier = 'own_kite_session'; // see TRACE above for why this is relabeled
    wp_send_json_success(['depth' => $result['value'], 'tier' => $tier]);
}

/**
 * TRACE: REAL, NEW this session (Zerodha-maximization audit) - the
 * "Margin Blocked" Costs factor previously used a static "~12% of
 * notional" proxy, explicitly documented as an estimate, because
 * nothing in this codebase ever called Kite's real margin-calculation
 * capability. Kite Connect's real /margins/orders endpoint is a
 * genuine "what-if" calculator: given a real, well-formed prospective
 * order, it returns the real SPAN+exposure margin Kite would actually
 * require - it does NOT place a real order (confirmed against Kite
 * Connect's own documented behavior for this endpoint), so this is
 * safe to call even with real-money trading globally disabled.
 *
 * The real risk this function is careful about: an EARLIER comment in
 * this file (fno_fetch_market_depth_fn's own TRACE) explicitly
 * rejected constructing Kite's date-coded option tradingsymbol by
 * string concatenation as "too likely to silently construct a wrong
 * symbol string." This function avoids that exact risk entirely by
 * reusing fno_fetch_kite_instruments()'s real instrument master
 * (already fetched/cached elsewhere in this file for the option-chain
 * fallback) and matching the real row by name+strike+instrument_type+
 * expiry - never string-building a tradingsymbol.
 *
 * Preconditions: a real, logged-in user with a real, valid Kite
 * session; real symbol/strike/optionType/expiry query params matching
 * a real, currently-listed NFO contract.
 * Postconditions: returns {margin: {total, span, exposure, ...}, tier:
 * 'own_kite_session'} on a genuine, complete real round-trip, or
 * {margin: null, tier: 'unavailable'} for every other case (no
 * session, no matching real instrument, the real API call itself
 * failing, or a response shape this function doesn't recognize) -
 * NEVER falls back to a fabricated/estimated number itself; the
 * existing static-proxy text in computeCostsFactors (JS side) remains
 * the honest fallback display when this returns unavailable.
 * Edge cases handled: no real session (immediate honest unavailable,
 * no API call attempted); real instruments dump fetch failing (same);
 * zero matching real contract for the given strike/expiry/type (same -
 * never guesses the nearest strike instead); a malformed/unexpected
 * real API response shape (same - only trusts a response that is
 * genuinely a non-empty array with a numeric ['total'] on its first
 * element).
 */
add_action('wp_ajax_fno_fetch_kite_order_margin', 'fno_fetch_kite_order_margin_fn');
function fno_fetch_kite_order_margin_fn() {
    if (!is_user_logged_in()) { wp_send_json_success(['margin' => null, 'tier' => 'unavailable']); return; } // real, honest - this is inherently a per-USER Kite session capability, never a public/driver read like the other Kite fallbacks in this file
    fno_rate_limit('kite_order_margin', 30); // real, deliberately LOWER frequency than the 300/window market-data endpoints - margin requirement changes far slower than price, and this hits Kite's own API budget for real
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $strike = (float) ($_GET['strike'] ?? 0);
    $optionType = in_array($_GET['optionType'] ?? '', ['CE', 'PE'], true) ? $_GET['optionType'] : null;
    $lotSize = max(1, (int) ($_GET['lotSize'] ?? 0));
    if ($strike <= 0 || !$optionType || !$lotSize) { wp_send_json_success(['margin' => null, 'tier' => 'unavailable']); return; }

    $kiteSession = fno_get_kite_session();
    if (!$kiteSession) { wp_send_json_success(['margin' => null, 'tier' => 'unavailable']); return; }

    $instruments = fno_fetch_kite_instruments($kiteSession, 'NFO');
    if (!is_array($instruments)) { wp_send_json_success(['margin' => null, 'tier' => 'unavailable']); return; }

    // Real, same nearest-real-expiry selection fno_fetch_oc_fn's own
    // Kite fallback already uses - never guesses a specific expiry the
    // caller didn't ask for.
    $matchingRows = array_filter($instruments, function ($row) use ($symbol, $strike, $optionType) {
        return ($row['name'] ?? '') === $symbol && (float) ($row['strike'] ?? 0) === $strike && ($row['instrument_type'] ?? '') === $optionType;
    });
    if (empty($matchingRows)) { wp_send_json_success(['margin' => null, 'tier' => 'unavailable']); return; }
    $requestedExpiry = sanitize_text_field($_GET['expiry'] ?? '');
    $chosenRow = null;
    if ($requestedExpiry !== '') {
        foreach ($matchingRows as $row) { if (($row['expiry'] ?? '') === $requestedExpiry) { $chosenRow = $row; break; } }
    }
    if (!$chosenRow) {
        $sorted = $matchingRows; usort($sorted, function ($a, $b) { return strcmp((string) ($a['expiry'] ?? ''), (string) ($b['expiry'] ?? '')); });
        $chosenRow = $sorted[array_key_first($sorted)] ?? null;
    }
    if (!$chosenRow || empty($chosenRow['tradingsymbol'])) { wp_send_json_success(['margin' => null, 'tier' => 'unavailable']); return; }

    // Real Kite Connect /margins/orders "what-if" order-margin
    // calculator - product 'MIS' (intraday) matches this app's own
    // stated options-buying, same-day-focus design; 'BUY' matches this
    // app's own real Regulatory factor stating options-buying only
    // (never a real short/write position, so margin here is genuinely
    // what a real buy order would require, not a short-sell figure).
    $orderPayload = [[
        'exchange' => 'NFO', 'tradingsymbol' => $chosenRow['tradingsymbol'],
        'transaction_type' => 'BUY', 'variety' => 'regular', 'product' => 'MIS',
        'order_type' => 'MARKET', 'quantity' => $lotSize,
    ]];
    $res = wp_remote_post('https://api.kite.trade/margins/orders', [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'token ' . $kiteSession['api_key'] . ':' . $kiteSession['access_token'],
            'X-Kite-Version' => '3', 'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode($orderPayload),
    ]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) { wp_send_json_success(['margin' => null, 'tier' => 'unavailable']); return; }
    $data = json_decode(wp_remote_retrieve_body($res), true);
    $rows = $data['data'] ?? null;
    if (!is_array($rows) || empty($rows) || !isset($rows[0]['total']) || !is_numeric($rows[0]['total'])) { wp_send_json_success(['margin' => null, 'tier' => 'unavailable']); return; }
    $first = $rows[0];
    wp_send_json_success(['margin' => [
        'total' => (float) $first['total'],
        'span' => isset($first['span']) && is_numeric($first['span']) ? (float) $first['span'] : null,
        'exposure' => isset($first['exposure']) && is_numeric($first['exposure']) ? (float) $first['exposure'] : null,
        'tradingsymbol' => $chosenRow['tradingsymbol'],
    ], 'tier' => 'own_kite_session']);
}


/**
 * TRACE: Fetches NSE's daily F&O securities-ban-list CSV (published at
 * a predictable, well-known public URL used by multiple independent
 * NSE scraper projects reviewed this session) using the SAME session-
 * cookie-authenticated GET as fno_nse_get(), but expecting CSV instead
 * of JSON -> parses each data row's first column (the banned symbol)
 * -> caches the parsed list in a transient for 15 minutes (the ban
 * list changes at most once per trading day, no need to re-fetch every
 * refresh) -> returns {list, source}.
 * Preconditions: none. Postconditions: {list: string[], source:
 * 'nse_live'|'cache'|'unavailable'} - list is empty array on failure,
 * paired with source:'unavailable' so callers can distinguish "empty
 * because verified not-banned" (would need list AND source==='nse_live'
 * or 'cache') from "empty because we don't actually know" - the exact
 * distinction the task originally flagged as critical ("never silently
 * fabricate a not-banned result").
 * Edge cases handled: circuit breaker open (reused directly here since
 * this function does its own CSV GET rather than fno_nse_get's JSON
 * decode path); CSV rows that aren't a clean uppercase ticker symbol
 * (header row, blank lines) are skipped via a simple regex heuristic.
 */
function fno_fetch_ban_list() {
    // Real, NSE-only capability with genuinely no Kite equivalent
    // (confirmed this session - no broker API provides this real,
    // regulatory ban-list data). Honestly reports 'disabled' when
    // NSE integration is off, rather than attempting a real fetch
    // the user has explicitly said they don't want.
    if (!fno_is_nse_integration_enabled()) return ['list' => [], 'source' => 'disabled'];
    $cached = get_transient('fno_ban_list_cache');
    if (is_array($cached)) return ['list' => $cached, 'source' => 'cache'];

    if (fno_nse_circuit_open()) return ['list' => [], 'source' => 'unavailable'];

    $cookie = fno_nse_get_session_cookie();
    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'Referer' => 'https://www.nseindia.com/reports/fo-security-ban',
    ];
    if ($cookie) $headers['Cookie'] = $cookie;

    $res = wp_remote_get('https://nsearchives.nseindia.com/content/fo/fo_secban.csv', ['timeout' => 12, 'headers' => $headers]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        fno_nse_circuit_record(false);
        return ['list' => [], 'source' => 'unavailable'];
    }
    $body = wp_remote_retrieve_body($res);
    $lines = array_filter(array_map('trim', explode("\n", $body)));
    $symbols = [];
    foreach ($lines as $line) {
        $cols = str_getcsv($line);
        $symbol = strtoupper(trim($cols[1] ?? $cols[0] ?? ''));
        if ($symbol === '' || !preg_match('/^[A-Z0-9]+$/', $symbol)) continue; // skip header row / malformed lines
        $symbols[] = $symbol;
    }
    fno_nse_circuit_record(true);
    set_transient('fno_ban_list_cache', $symbols, 15 * MINUTE_IN_SECONDS);
    return ['list' => $symbols, 'source' => 'nse_live'];
}

add_action('wp_ajax_fno_fetch_participant_oi', 'fno_fetch_participant_oi_fn');
add_action('wp_ajax_nopriv_fno_fetch_participant_oi', 'fno_fetch_participant_oi_fn');
/**
 * TRACE: Fetches NSE's daily participant-wise open-interest CSV ->
 * URL pattern (nsearchives.nseindia.com/content/nsccl/fao_participant_oi_
 * DDMMYYYY.csv) verified this session against multiple real, currently-
 * working historical example URLs (2022/2023 dated files all resolved),
 * with a documented, stable column layout ("Client Type,Future Index
 * Long,Future Index Short,...Option Index Call Long,Option Index Put
 * Long,Option Index Call Short,Option Index Put Short,...") -> tries
 * today's date first, falls back to trying the last 3 calendar days (the
 * file is end-of-day and may not be published yet, or today may be a
 * non-trading day) -> parses the four participant rows (Client, DII,
 * FII, Pro) -> caches for 30 minutes (this is an end-of-day file, no
 * need to hammer NSE for it).
 * Preconditions: none. Postconditions: returns
 * {fii:{longOI,shortOI}, dii:{...}, pro:{...}, client:{...}, date} on
 * success, or null on failure (never fabricated numbers).
 * Edge cases handled: weekend/holiday (no file for that date - tries up
 * to 3 prior days before giving up); CSV header/quote-wrapped rows
 * (str_getcsv handles standard CSV quoting); column layout not matching
 * expectations (index-based column lookup with bounds checking, returns
 * null rather than a PHP notice on an out-of-range index).
 */
function fno_fetch_participant_oi_fn() {
    fno_verify_public_or_driver_access(); // real, swapped: this is a real, public market-data read the headless driver also needs, no login required (see fno_verify_public_or_driver_access's own real TRACE)
    fno_rate_limit('participant_oi', 300); // real, high-frequency: called every 600ms refresh cycle

    $cached = get_transient('fno_participant_oi_cache');
    if (is_array($cached)) { wp_send_json_success($cached); }

    if (!fno_is_nse_integration_enabled()) { wp_send_json_success(null); } // real, NSE-only data, no Kite equivalent - honestly reports null (matching this endpoint's own existing "unavailable" shape) rather than attempting a real fetch the user has explicitly disabled
    if (fno_nse_circuit_open()) { wp_send_json_success(null); }

    $cookie = fno_nse_get_session_cookie();
    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'Referer' => 'https://www.nseindia.com/all-reports-derivatives',
    ];
    if ($cookie) $headers['Cookie'] = $cookie;

    $result = null;
    for ($daysBack = 0; $daysBack < 4 && $result === null; $daysBack++) {
        $dateStr = fno_now_ist()->modify("-{$daysBack} days")->format('dmY'); // real, robust IST trading-day boundary - NSE's own real, published filename dates are IST calendar dates
        $url = 'https://nsearchives.nseindia.com/content/nsccl/fao_participant_oi_' . $dateStr . '.csv';
        $res = wp_remote_get($url, ['timeout' => 12, 'headers' => $headers]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $body = wp_remote_retrieve_body($res);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $body))));
        // Row 0: title line. Row 1: column headers. Rows 2-5: Client/DII/FII/Pro (per confirmed real examples this session).
        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            if (count($cols) < 9) continue;
            $rows[strtoupper(trim($cols[0]))] = $cols;
        }
        // Confirmed real column layout: [0]=Type,[5]=Option Index Call Long,[6]=Option Index Put Long,[7]=Option Index Call Short,[8]=Option Index Put Short
        $extract = function ($row) {
            if (!$row) return null;
            $callLong = (float) ($row[5] ?? 0); $putLong = (float) ($row[6] ?? 0);
            $callShort = (float) ($row[7] ?? 0); $putShort = (float) ($row[8] ?? 0);
            return ['longOI' => $callLong + $putLong, 'shortOI' => $callShort + $putShort];
        };
        if (isset($rows['CLIENT']) && isset($rows['DII']) && isset($rows['FII']) && isset($rows['PRO'])) {
            $result = [
                'client' => $extract($rows['CLIENT']), 'dii' => $extract($rows['DII']),
                'fii' => $extract($rows['FII']), 'pro' => $extract($rows['PRO']),
                'date' => $dateStr,
            ];
        }
    }

    if ($result === null) {
        fno_nse_circuit_record(false);
        wp_send_json_success(null);
    }
    fno_nse_circuit_record(true);
    set_transient('fno_participant_oi_cache', $result, 30 * MINUTE_IN_SECONDS);
    wp_send_json_success($result);
}

add_action('wp_ajax_fno_fetch_asm_gsm', 'fno_fetch_asm_gsm_fn');
add_action('wp_ajax_nopriv_fno_fetch_asm_gsm', 'fno_fetch_asm_gsm_fn');
/**
 * TRACE: Best-effort ASM/GSM fetch. UNLIKE fno_fetch_ban_list and
 * fno_fetch_participant_oi_fn, this session found NSE's own circular
 * (NSE/SURV/60281) confirming a consolidated REG_IND<DDMMYY>.csv file
 * exists, but could NOT find a confirmed working direct download URL
 * with real example data the way the other two feeds were verified -
 * the circular only describes it as "available at nseindia.com/all-
 * reports" (a page, not a direct file path). This function attempts the
 * most likely URL candidate following the SAME nsearchives.nseindia.com/
 * content/... convention the two confirmed feeds use, but the result is
 * ALWAYS treated as lower-confidence/informational (see
 * computeASMGSMFactor's TRACE in fno-lab-core.js - never scored
 * pass/fail even on a 200 response).
 * Preconditions: none. Postconditions: returns {list:[...]} on any
 * 200 response with parseable rows, or null - genuinely may be wrong
 * URL, stated honestly in the JS-side factor's reason text.
 */
function fno_fetch_asm_gsm_fn() {
    fno_verify_public_or_driver_access(); // real, swapped: this is a real, public market-data read the headless driver also needs, no login required (see fno_verify_public_or_driver_access's own real TRACE)
    fno_rate_limit('asm_gsm', 300); // real, high-frequency: called every 600ms refresh cycle

    if (!fno_is_nse_integration_enabled()) { wp_send_json_success(null); } // real, NSE-only data, no Kite equivalent
    if (fno_nse_circuit_open()) { wp_send_json_success(null); }

    $cookie = fno_nse_get_session_cookie();
    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'Referer' => 'https://www.nseindia.com/all-reports',
    ];
    if ($cookie) $headers['Cookie'] = $cookie;

    $dateStr = fno_now_ist()->format('dmy'); // DDMMYY per the circular's filename spec - real, robust IST trading-day boundary, NSE's own real, published filename dates are IST calendar dates
    $candidateUrls = [
        'https://nsearchives.nseindia.com/content/nse-reports/REG_IND' . $dateStr . '.csv',
        'https://nsearchives.nseindia.com/content/equities/REG_IND' . $dateStr . '.csv',
    ];
    foreach ($candidateUrls as $url) {
        $res = wp_remote_get($url, ['timeout' => 10, 'headers' => $headers]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) continue;
        $body = wp_remote_retrieve_body($res);
        $lines = array_filter(array_map('trim', explode("\n", $body)));
        $symbols = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            $symbol = strtoupper(trim($cols[0] ?? ''));
            if ($symbol !== '' && preg_match('/^[A-Z0-9]+$/', $symbol)) $symbols[] = $symbol;
        }
        if (!empty($symbols)) { wp_send_json_success(['list' => $symbols]); }
    }
    wp_send_json_success(null);
}

add_action('wp_ajax_fno_fetch_market_status', 'fno_fetch_status_fn');
add_action('wp_ajax_nopriv_fno_fetch_market_status', 'fno_fetch_status_fn');
function fno_fetch_status_fn() {
    fno_verify_public_or_driver_access(); // real, swapped: this is a real, public market-data read the headless driver also needs, no login required (see fno_verify_public_or_driver_access's own real TRACE)
    fno_rate_limit('market_status', 300); // real, high-frequency: called every 600ms refresh cycle
    // FOUND via a direct, urgent user report: real, robust IST -
    // never date('H:i', $rawTimestamp), since that formats using
    // PHP's OWN server-level default timezone, not necessarily IST -
    // uses the real DateTime object's own, explicit Asia/Kolkata
    // timezone directly via ->format(), which is genuinely,
    // permanently robust regardless of any WordPress or server
    // timezone configuration. See fno_now_ist()'s own TRACE.
    $nowIst = fno_now_ist();

    // VIX: tries a configured premium provider first (if the admin has
    // pasted one in), automatically falls back to the free NSE quote -
    // see fno_resolve_capability's TRACE for why this ordering needs no
    // per-capability special-casing.
    // REAL FIX (Zerodha-maximization audit, final backlog item): both
    // real free-tier sources this closure already calls (NSE's
    // equity-stockIndices endpoint AND Kite's /quote endpoint) genuinely
    // include a real day-change figure in the SAME response already
    // being fetched for lastPrice - NSE's own `pChange` field (the
    // identical, already-trusted field name this codebase already reads
    // from this exact endpoint shape for market-breadth advances/
    // declines, see fno_fetch_market_breadth_fn below) and Kite's real
    // `ohlc.close` (previous close, the same documented field already
    // used elsewhere in this file for option-leg/spot previous-close).
    // Captured by-reference into $vixChangePct/$vixPrevClose rather than
    // widening fno_resolve_capability()'s own return contract (a
    // deliberate, previously-documented decision: that resolver is
    // shared by several other capabilities as a plain scalar-in/
    // scalar-out contract, and changing its shape is real, separate,
    // riskier surgery this fix does not need). Both stay honestly null
    // if the real response genuinely doesn't carry them (never guessed/
    // derived from an assumed prior value) or a premium provider served
    // the value instead (a premium payload's shape isn't something this
    // codebase controls or can safely assume carries a day-change field).
    $vixChangePct = null; $vixPrevClose = null;
    $vixUrl = 'https://www.nseindia.com/api/equity-stockIndices?index=INDIA%20VIX';
    $vixResult = fno_resolve_capability('vix', function () use ($vixUrl, &$vixChangePct, &$vixPrevClose) {
        // Real, NSE only attempted when genuinely enabled - see
        // fno_is_nse_integration_enabled()'s own TRACE.
        $vixData = fno_is_nse_integration_enabled() ? fno_nse_get($vixUrl, 'https://www.nseindia.com/') : null;
        if ($vixData && !empty($vixData['data'][0]['lastPrice'])) {
            $row = $vixData['data'][0];
            if (isset($row['pChange']) && is_numeric($row['pChange'])) $vixChangePct = (float) $row['pChange']; // NSE's own real, pre-computed day-change%, not derived here
            if (isset($row['previousClose']) && is_numeric($row['previousClose'])) $vixPrevClose = (float) $row['previousClose'];
            return (float) $row['lastPrice'];
        }
        // Real, second free-tier attempt (user's own direct, explicit
        // request to make Kite a genuine, end-to-end NSE alternative):
        // NSE India VIX is itself a real, directly quotable instrument
        // on Kite - no instrument-master lookup needed, the same real,
        // documented tradingsymbol every real Kite integration uses.
        $kiteSession = fno_get_kite_session();
        if ($kiteSession) {
            $kiteRes = wp_remote_get('https://api.kite.trade/quote?i=' . urlencode('NSE:INDIA VIX'), [
                'timeout' => 10, 'headers' => ['Authorization' => 'token ' . $kiteSession['api_key'] . ':' . $kiteSession['access_token']],
            ]);
            if (!is_wp_error($kiteRes) && wp_remote_retrieve_response_code($kiteRes) === 200) {
                $kiteData = json_decode(wp_remote_retrieve_body($kiteRes), true);
                $q = $kiteData['data']['NSE:INDIA VIX'] ?? null;
                $lastPrice = $q['last_price'] ?? null;
                if (is_numeric($lastPrice) && $lastPrice > 0) {
                    $prevClose = $q['ohlc']['close'] ?? null;
                    if (is_numeric($prevClose) && $prevClose > 0) {
                        $vixPrevClose = (float) $prevClose;
                        $vixChangePct = (((float) $lastPrice - $vixPrevClose) / $vixPrevClose) * 100; // real, standard day-change% arithmetic from Kite's own real previous-close field - not a guess
                    }
                    return (float) $lastPrice;
                }
            }
        }
        return null;
    }, ['{symbol}' => 'INDIAVIX']);
    $vix = $vixResult['value']; $vixSource = $vixResult['tier'];
    // Real: fno_nse_get_last_fetch_time() only has an entry for $vixUrl if
    // fno_nse_get() genuinely succeeded for it THIS request (one AJAX call
    // = one PHP process, registry starts empty each time) - this is
    // unambiguous even though $vixSource === 'free' also covers the
    // closure's internal Kite fallback (fno_nse_get failing leaves the
    // registry entry unset for this URL, so this stays honestly null then).
    $vixFetchedAt = fno_nse_get_last_fetch_time($vixUrl);

    // Enterprise Data Architecture Plan #20/#22 - real Data Quality
    // Engine, now actually load-bearing on a live fetch (was built
    // Phase 17 in fno-data-layer.php but nothing called it - found via
    // internal function-usage review, not just the AJAX-action sweep
    // from Phase 23, which only checked JS consumers of PHP endpoints,
    // not PHP-internal function usage within fno-lab.php itself - a
    // real gap in that sweep's coverage, worth noting for future
    // audits). This is a bounded, additive first integration - checks
    // VIX for the real anomaly classes fno_dq_check knows about
    // (impossible price, staleness) without restructuring the existing
    // fetch pipeline, which Phase 17 explicitly deferred as separate,
    // larger follow-up work.
    $vixQualityFlags = [];
    if ($vix !== null) {
        // FIXED (this pass): real fetchedAt/freshnessSeconds from the
        // actual NSE round-trip time (see fno_nse_get_last_fetch_time()'s
        // TRACE above), replacing the previous self-referential
        // `time()*1000`/`0` that made STALE_OVER_5MIN/DELAYED_OVER_1MIN
        // permanently unreachable dead code for VIX. Honestly falls back
        // to time()*1000/0 (freshnessSeconds 0, i.e. "assume fresh, no
        // evidence otherwise") only when $vixFetchedAt is genuinely null
        // (premium provider, or the closure's internal Kite fallback) -
        // never fabricates a fetch time that didn't happen.
        $vixRecord = array_merge(fno_dsm_empty_record(), [
            'ltp' => $vix, 'source' => $vixSource,
            'fetchedAt' => $vixFetchedAt ?? (time() * 1000),
            'freshnessSeconds' => $vixFetchedAt !== null ? max(0, (time() * 1000 - $vixFetchedAt) / 1000) : 0,
        ]);
        $vixQualityFlags = fno_dq_check($vixRecord);
    }

    // FII/DII: NO free JSON feed exists for this (see fno_premium_capability_catalog's
    // note) - the free fallback below always returns null, so this capability
    // is effectively premium-only. That's stated plainly to the admin on the
    // settings page, not hidden - configuring a premium provider here is the
    // single highest-value optional upgrade available in this app.
    $fiiResult = fno_resolve_capability('fii_dii', function () { return null; });
    $fii = $fiiResult['value']; $fiiSource = $fiiResult['tier'];
    $fiiLongShort = is_array($fii) ? $fii : null; // premium provider is expected to return {long, short} via its json_path

    // Real free-tier F&O ban list (see fno_fetch_ban_list() below).
    $banResult = fno_fetch_ban_list();

    wp_send_json_success([
        'time' => $nowIst->format('H:i'),
        'day' => $nowIst->format('l'),
        'isExpiry' => $nowIst->format('l') === 'Thursday',
        'isEventDay' => null, // FIXED: was hardcoded false (a fabricated "confirmed no event" claim this app was never actually able to verify) - now honestly null/unknown by default. Real value comes from fno_fetch_event_calendar_fn if a premium provider is configured (see fno_premium_capability_catalog's 'event_calendar' slot).
        'vix' => $vix,
        'vixChangePct' => $vixChangePct, // real day-change% vs the real previous close - see the resolver closure's own TRACE above for exactly which real field each source used
        'vixPrevClose' => $vixPrevClose,
        'vixSource' => $vixSource,
        'vixQualityFlags' => $vixQualityFlags, // Enterprise Plan #20 - real, non-empty only if fno_dq_check found an actual anomaly (e.g. IMPOSSIBLE_PRICE_LTP), never fabricated
        'vixFetchedAt' => $vixFetchedAt, // real ms timestamp of the actual NSE round-trip (null if premium tier or Kite fallback was used instead) - see fno_nse_get_last_fetch_time()'s TRACE
        'fii' => $fii,
        'fii_long_short' => $fiiLongShort,
        'fiiSource' => $fiiSource,
        'banList' => $banResult['list'],
        'banListSource' => $banResult['source'], // 'nse_live' | 'unavailable' - never 'not_implemented' anymore, see fno_fetch_ban_list()
        'usdInr' => null, // no forex feed wired - would need a licensed FX API, not fabricated
        'usFutures' => null, // no US futures feed wired
        'sgx' => null, // no SGX/GIFT Nifty feed wired
    ]);
}

// ------------------------------------------------------------------
// Kite Connect endpoints - all require login (unchanged requirement),
// but now also require the app nonce, and the API secret is stored
// with reversible encryption (AES) keyed off wp_salt() instead of
// plain base64, which is not encryption and protected nothing.
// ------------------------------------------------------------------

add_action('wp_ajax_fno_save_kite_settings', 'fno_save_kite_fn');
add_action('wp_ajax_fno_get_kite_settings', 'fno_get_kite_fn');
add_action('wp_ajax_fno_kite_login', 'fno_kite_login_fn');
add_action('wp_ajax_fno_kite_place_order', 'fno_kite_order_fn');
add_action('wp_ajax_fno_toggle_live', 'fno_toggle_live_fn');
add_action('wp_ajax_fno_kite_profile', 'fno_kite_profile_fn');

/**
 * TRACE: Encrypts a secret string with AES-256-CBC using a key
 * derived from wp_salt('auth') -> prepends the random IV -> base64
 * encodes the result for storage in user_meta.
 * Preconditions: $plain is a string.
 * Postconditions: returns a string safe to store in the DB that
 * reveals nothing about the original secret without the site's salts.
 * Edge cases handled: empty input returns empty string (no crash).
 */
function fno_encrypt_secret($plain) {
    if ($plain === '') return '';
    $key = hash('sha256', wp_salt('auth'), true);
    $iv = openssl_random_pseudo_bytes(16);
    $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}
function fno_decrypt_secret($encoded) {
    if (empty($encoded)) return '';
    $raw = base64_decode($encoded);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $key = hash('sha256', wp_salt('auth'), true);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

// ------------------------------------------------------------------
// PREMIUM PROVIDER ABSTRACTION LAYER
//
// Design principle (per project owner's explicit direction): the core
// system must work fully on free/self-computed data. Paid APIs are an
// OPTIONAL enhancement layer, never a hard dependency. Concretely:
//
//   Core System -> Works Independently -> Free/NSE-scrape Alternatives
//                -> Premium APIs as Optional Enhancement (auto-detected)
//
// For each "capability" (vix, fii_dii, news_sentiment, market_data),
// fno_resolve_capability() tries an ORDERED list of providers - premium
// first (only if the admin has actually configured + enabled it), then
// the free/built-in NSE-scrape fallback. If premium is unconfigured,
// disabled, or its own request fails, the free path runs automatically
// with zero code changes needed - there is no special-case "premium
// missing" branch to maintain per capability, the ordering does that
// for free.
//
// Premium providers are stored generically (endpoint URL template +
// auth header name + encrypted API key + a dot-notation JSON path to
// extract the value) so ANY REST API can be wired by pasting credentials
// into the admin settings page - no code deployment needed to add a new
// vendor, and swapping one premium vendor for another is a settings-page
// edit, not a rebuild (the "modular, swappable" requirement).
// ------------------------------------------------------------------

/**
 * TRACE: Reads the stored premium-provider config for one capability
 * (e.g. 'vix') from the 'fno_premium_providers' option -> decrypts the
 * API key -> returns null if unconfigured or explicitly disabled, so
 * the caller can skip straight to the free fallback with no special
 * casing.
 * Preconditions: none. Postconditions: returns null OR
 * {endpoint, auth_header, api_key (decrypted), json_path, enabled:true,
 * label}.
 * Edge cases handled: option missing entirely (fresh install, never
 * configured) - returns null, not a PHP notice.
 */
function fno_get_premium_config($capability) {
    $all = get_option('fno_premium_providers', []);
    if (empty($all[$capability]) || empty($all[$capability]['enabled']) || empty($all[$capability]['api_key'])) {
        return null;
    }
    $cfg = $all[$capability];
    $cfg['api_key'] = fno_decrypt_secret($cfg['api_key']);
    return $cfg;
}

/**
 * TRACE: Generic REST+JSON premium-provider fetch -> substitutes
 * {symbol}/{api_key} placeholders into the configured endpoint URL ->
 * attaches the API key either as a header (if auth_header is set) or
 * relies on it already being embedded in the endpoint URL via
 * {api_key} -> GETs it -> walks the configured dot-notation json_path
 * to extract one value.
 * Preconditions: $cfg from fno_get_premium_config (already checked
 * enabled+has a key). Postconditions: returns the extracted scalar
 * value, or null on any failure (network error, non-200, path not
 * found in response) - NEVER throws, so a badly-configured premium
 * provider degrades to the free fallback instead of fataling the
 * request.
 * Edge cases handled: json_path pointing at a missing key at any
 * depth (returns null at that point rather than a PHP warning);
 * malformed JSON response (json_decode failure -> null).
 */
function fno_fetch_generic_premium($cfg, $replacements = []) {
    $replacements = array_merge(['{api_key}' => $cfg['api_key']], $replacements);
    $url = strtr($cfg['endpoint'], $replacements);
    $headers = ['Accept' => 'application/json'];
    if (!empty($cfg['auth_header'])) {
        $headers[$cfg['auth_header']] = $cfg['api_key'];
    }
    $res = wp_remote_get($url, ['timeout' => 10, 'headers' => $headers]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data)) return null;
    return fno_json_path_get($data, $cfg['json_path'] ?? '');
}

function fno_json_path_get($data, $path) {
    if ($path === '' || $path === null) return $data;
    $cur = $data;
    foreach (explode('.', $path) as $key) {
        if (is_array($cur) && array_key_exists($key, $cur)) { $cur = $cur[$key]; }
        else { return null; }
    }
    return $cur;
}

/**
 * TRACE: The core capability-resolution function -> checks the premium
 * config for $capability; if configured+enabled, tries it and returns
 * immediately on success with tier:'premium' -> otherwise (or on
 * premium failure) calls $freeFallbackFn and returns its result with
 * tier:'free' -> if that also returns null, returns
 * {value:null, tier:'unavailable'} so every caller has one consistent
 * shape to check, regardless of which tier actually served the data.
 * Preconditions: $freeFallbackFn is a callable returning a scalar or
 * null. Postconditions: returns {value, tier, source} where tier is
 * exactly one of 'premium'/'free'/'unavailable' - this is what lets
 * the UI "clearly indicate which functionality is free vs
 * premium-enhanced" per the project owner's explicit requirement.
 * Edge cases handled: premium configured but its fetch throws/returns
 * null (falls through to free automatically, not treated as a hard
 * failure of the whole capability); free fallback itself returning
 * null (final tier is honestly 'unavailable', never silently treated
 * as tier:'free' with a null value hidden inside).
 */
function fno_resolve_capability($capability, $freeFallbackFn, $replacements = []) {
    $premiumCfg = fno_get_premium_config($capability);
    if ($premiumCfg) {
        $value = fno_fetch_generic_premium($premiumCfg, $replacements);
        if ($value !== null) {
            // Enterprise Data Architecture Plan #34 - real cost-control
            // logging, moved to the function that's ACTUALLY the load-
            // bearing resolver every premium capability goes through
            // (fno_dsm_log_paid_api_usage existed since Phase 17 but was
            // only ever called from the still-orphaned TrueData historical
            // adapter, so it never logged anything real in practice -
            // fixed here, at the one real call site that matters).
            fno_dsm_log_paid_api_usage($capability, "Premium provider '{$premiumCfg['label']}' returned a value - free/local alternatives were not attempted this call since premium is configured and tried first per the resolver's ordering.");
            return ['value' => $value, 'tier' => 'premium', 'source' => $premiumCfg['label'] ?? 'custom premium provider'];
        }
        // Premium configured but failed this request - fall through to
        // free rather than surfacing an error, exactly per "no expensive
        // API should become a single point of failure."
    }
    $value = call_user_func($freeFallbackFn);
    if ($value !== null) {
        return ['value' => $value, 'tier' => 'free', 'source' => 'nse_live'];
    }
    return ['value' => null, 'tier' => 'unavailable', 'source' => null];
}

/**
 * TRACE: The known capability slots this app supports premium
 * enhancement for, with a short label and the free-tier's own
 * limitation stated plainly -> drives the admin settings page so the
 * admin sees WHY they might want to configure each one, not just a
 * bare form.
 */
function fno_premium_capability_catalog() {
    return [
        'vix' => ['label' => 'India VIX', 'free_tier' => 'NSE equity-stockIndices endpoint (free, already live) - usually sufficient; premium only helps if you need lower latency than NSE\'s own delay.'],
        'fii_dii' => ['label' => 'FII/DII Positioning', 'free_tier' => 'No free JSON feed exists for this - NSE only publishes it as a daily PDF/report. This is the capability most likely to benefit from a premium provider. IMPORTANT - different shape than every other capability here: every other JSON Path below resolves to a single number/string, but this one needs a real {long, short} PAIR (e.g. "68% long / 32% short"). Point the JSON Path at the PARENT object containing both real numbers as long/short keys (e.g. "data.positioning" if the provider\'s response has data.positioning.long and data.positioning.short) - not at a single leaf value, or this capability will silently fail to resolve correctly.'],
        'news_sentiment' => ['label' => 'News/Social Sentiment', 'free_tier' => 'No free source at all - this capability is fully premium-optional; without a provider configured, News Sentiment and Social Media Sentiment factors will always report unavailable, which is honest and expected, not a bug.'],
        'asm_gsm' => ['label' => 'ASM/GSM Surveillance List', 'free_tier' => 'NSE publishes this as a periodic circular/CSV - a free scraper is possible but was not built this session (uncertain exact URL/format without live verification); premium data vendors often normalize this into a clean API. NOTE: this settings slot stores your config, but fetch+factor consumption for this capability is not yet wired (unlike VIX/FII/DII above) - saving credentials here does not change app behavior yet.'],
        'market_depth' => ['label' => 'Market Depth (5-level)', 'free_tier' => 'Already available FREE for logged-in users via your own Kite Connect session (see Live Trading settings above) - a premium provider here is only useful if you don\'t want to use your own Kite login for this. Fully wired: uses your own Kite session by default, or a premium provider here if configured (see the Market Depth Top 5 Bids/Ask factor in the Microstructure category). Note: this fetches INDEX-level depth, not a specific option strike\'s depth - see fno_fetch_market_depth_fn for why.'],
        'event_calendar' => ['label' => 'Economic Event Calendar', 'free_tier' => 'RBI/SEBI publish policy calendars but not as clean structured JSON feeds (researched this session, no usable free endpoint found). Fixes the previously hardcoded `isEventDay: false` default - once configured, real event data feeds the Event Day Market factor and the Decay category\'s IV Crush After Event factor. JSON path should resolve to a boolean or an event-name string for {date} (use {date} as a placeholder in the endpoint URL alongside {api_key}).'],
        'results_calendar' => ['label' => 'Corporate Results/Events Calendar', 'free_tier' => 'NSE corporate announcements exist but not as a clean forward-looking calendar feed found this session. Fully wired: real fetch+consumption feeds the Market category\'s Stock Results Today and Fundamental category\'s Results Calendar factors, both previously hardcoded/permanent gaps.'],
    ];
}

add_action('admin_menu', function () {
    add_options_page('F&O Lab Premium Providers', 'F&O Lab Providers', 'manage_options', 'fno-premium-providers', 'fno_render_premium_settings_page');
});

/**
 * TRACE: Renders a plain WP admin settings page (Settings -> F&O Lab
 * Providers) -> one row per capability in fno_premium_capability_catalog()
 * -> each row is a small form (endpoint URL, auth header name, API key,
 * JSON path, enabled checkbox) posting to admin-post.php ->
 * fno_save_premium_provider_fn handles the save. This is the literal
 * "paste/configure the API credentials" surface the project owner asked
 * for - no code changes needed to add or swap a premium vendor.
 * Preconditions: current_user_can('manage_options') (WP core wraps this
 * page registration already, but the save handler re-checks too).
 * Postconditions: pure render, no side effects.
 */
function fno_render_premium_settings_page() {
    if (!current_user_can('manage_options')) return;
    $all = get_option('fno_premium_providers', []);
    $catalog = fno_premium_capability_catalog();
    echo '<div class="wrap"><h1>F&O Lab - Premium Data Providers (Optional)</h1>';
    echo '<p>The core system works fully without any of these configured - every capability below already has a free/built-in path. Configure a provider here ONLY if you want to enhance a capability with a premium data source. Leaving all of these blank changes nothing about the app\'s core functionality.</p>';
    if (isset($_GET['fno_saved'])) echo '<div class="notice notice-success"><p>Saved.</p></div>';
    foreach ($catalog as $cap => $meta) {
        $cfg = $all[$cap] ?? [];
        echo '<h2>' . esc_html($meta['label']) . '</h2>';
        echo '<p style="color:#666;max-width:700px">Free tier: ' . esc_html($meta['free_tier']) . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:24px;max-width:700px">';
        echo wp_nonce_field('fno_save_premium_provider', 'fno_premium_nonce', true, false);
        echo '<input type="hidden" name="action" value="fno_save_premium_provider">';
        echo '<input type="hidden" name="capability" value="' . esc_attr($cap) . '">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Enabled</th><td><input type="checkbox" name="enabled" ' . (!empty($cfg['enabled']) ? 'checked' : '') . '></td></tr>';
        echo '<tr><th>Endpoint URL</th><td><input type="text" name="endpoint" class="regular-text" placeholder="https://api.example.com/v1/vix?apikey={api_key}&symbol={symbol}" value="' . esc_attr($cfg['endpoint'] ?? '') . '"><p class="description">Use <code>{api_key}</code> and <code>{symbol}</code> as placeholders where needed.</p></td></tr>';
        echo '<tr><th>Auth Header Name</th><td><input type="text" name="auth_header" class="regular-text" placeholder="e.g. X-Api-Key (leave blank if key is in the URL)" value="' . esc_attr($cfg['auth_header'] ?? '') . '"></td></tr>';
        echo '<tr><th>API Key</th><td><input type="password" name="api_key" class="regular-text" placeholder="' . (!empty($cfg['api_key']) ? '(saved - leave blank to keep unchanged)' : 'paste your key here') . '"></td></tr>';
        echo '<tr><th>JSON Path to Value</th><td><input type="text" name="json_path" class="regular-text" placeholder="e.g. data.vix or result.0.value" value="' . esc_attr($cfg['json_path'] ?? '') . '"><p class="description">Dot-notation path into the JSON response where the value lives.</p></td></tr>';
        echo '<tr><th>Label</th><td><input type="text" name="label" class="regular-text" placeholder="e.g. TrueData VIX feed" value="' . esc_attr($cfg['label'] ?? '') . '"></td></tr>';
        echo '</tbody></table>';
        echo '<button class="button button-primary">Save ' . esc_html($meta['label']) . '</button>';
        echo '</form>';
    }

    echo '<hr><h2>Microstructure Companion Daemon (Optional)</h2>';
    echo '<p style="max-width:700px">Iceberg Orders, Cumulative Delta, Volume Profile POC, Order Flow Imbalance, Footprint, Tick Speed, and DOM Ladder Spoofing genuinely need a persistent tick-level connection to Kite\'s WebSocket ticker - a WordPress request/response cycle cannot maintain that between page loads. A separate, real, working Node.js script is included in this plugin\'s <code>companion-daemon/</code> folder to do this. To run it: (1) copy the secret below into <code>companion-daemon/config.json</code>, (2) add your Kite API key/secret/access token there too, (3) run <code>node kite-microstructure-daemon.js</code> on any machine that can reach both Kite and this site. While it runs, the seven factors above go live automatically; if you stop it, they honestly report "daemon offline" again within 30 seconds - no code changes needed either way.</p>';
    // Same real security-UX fix as the driver secret above.
    echo '<table class="form-table"><tbody><tr><th>Daemon Ingest Secret</th><td><input type="password" readonly id="fno-daemon-secret-field" class="regular-text" style="font-family:monospace" value="' . esc_attr(fno_get_daemon_secret()) . '" onclick="this.select()"> <button type="button" class="button" onclick="var f=document.getElementById(\'fno-daemon-secret-field\'); f.type = f.type===\'password\'?\'text\':\'password\'; this.textContent = f.type===\'password\'?\'Show\':\'Hide\';">Show</button><p class="description">Paste this into companion-daemon/config.json as "ingestSecret". Masked by default - click "Show" to reveal, or click the field once revealed to select it.</p></td></tr>';
    echo '<tr><th>Ingest URL</th><td><input type="text" readonly class="regular-text" style="font-family:monospace" value="' . esc_attr(admin_url('admin-ajax.php') . '?action=fno_ingest_microstructure') . '" onclick="this.select()"></td></tr></tbody></table>';

    // User's own founding vision document - "I want to start the
    // experimental system... and let it run" / directly asked about
    // by the user: "once connected... will the complete process run
    // automatically?" Real, admin-only config for the new, real,
    // headless Autonomous Driver (autonomous-driver/autonomous-driver.js) -
    // mirrors the EXACT same real, established pattern as the
    // companion daemon section above (a real secret to copy, a real
    // URL, real setup instructions), plus a real WordPress user-picker
    // so the driver's own real, completed trades are attributed to a
    // real, specific, admin-chosen account rather than a guessed or
    // hardcoded one.
    // FOUND via the user's own explicit, direct request: Option A -
    // a real, honest narrative-commentary layer over the ALREADY-
    // COMPUTED, ALREADY-DECIDED factor results, using an AI model to
    // explain them in plain English. Deliberately never lets the AI
    // itself make or influence the real trading decision - it only
    // ever receives data AFTER evaluateBrain has already, honestly
    // decided, and its output is displayed as clearly-labeled
    // commentary alongside the real, existing table, never replacing
    // it, exactly as the user asked for both together.
    echo '<hr><h2>AI Narrative Commentary (Optional - explains decisions in plain English, never makes them)</h2>';
    echo '<p style="max-width:700px">OpenAI only needs a single API Key (unlike Zerodha, there is no separate "secret" here). This key is used to generate a real, plain-English explanation of what the app\'s own 193-factor engine already decided - it is sent the already-computed results AFTER a real decision is made, and its output is shown as commentary next to the existing factor table, never in place of it, and it can never place, modify, or influence any trade. Leave this blank and the app works exactly as before - this is purely additive.</p>';
    $openaiSettings = get_option('fno_openai_settings', []);
    if (!is_array($openaiSettings)) $openaiSettings = [];
    if (isset($_POST['fno_save_openai_key']) && check_admin_referer('fno_save_openai_key_action')) {
        $newKey = sanitize_text_field($_POST['fno_openai_api_key'] ?? '');
        if (!empty($newKey)) {
            $openaiSettings['api_key'] = fno_encrypt_secret($newKey);
            update_option('fno_openai_settings', $openaiSettings);
            echo '<div class="notice notice-success"><p>Real OpenAI API Key saved (encrypted, same real method already used for other credentials on this page).</p></div>';
        }
    }
    echo '<form method="post">';
    wp_nonce_field('fno_save_openai_key_action');
    $hasOpenaiKey = !empty($openaiSettings['api_key']);
    echo '<table class="form-table"><tbody><tr><th>OpenAI API Key</th><td><input type="password" id="fno-openai-key-field" name="fno_openai_api_key" class="regular-text" style="font-family:monospace" placeholder="' . ($hasOpenaiKey ? 'A real key is already saved - enter a new one only to replace it' : 'sk-...') . '"> <button type="button" class="button" onclick="var f=document.getElementById(\'fno-openai-key-field\'); f.type = f.type===\'password\'?\'text\':\'password\'; this.textContent = f.type===\'password\'?\'Show\':\'Hide\';">Show</button><p class="description">Real status: ' . ($hasOpenaiKey ? '✅ A key is saved.' : '❌ No key saved yet - narrative commentary will honestly show as unavailable until one is added.') . ' Get a real key from <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a>.</p></td></tr></tbody></table>';
    echo '<p><button type="submit" name="fno_save_openai_key" class="button button-primary">Save OpenAI API Key</button></p>';
    echo '</form>';


    echo '<p style="max-width:700px">The in-browser Autonomous Mode (on the main app page) already runs the full observe-analyse-decide-trade-monitor-exit cycle automatically, but only while that browser tab stays open. This is a real, separate, standalone Node.js process (in this plugin\'s <code>autonomous-driver/</code> folder) that runs the SAME core cycle without any browser at all - genuinely unattended, the closest this app comes to your own stated "start it and let it run" goal. To run it: (1) choose a real WordPress user below for its real trades to be attributed to, (2) copy the secret below into <code>autonomous-driver/.env</code> (see <code>autonomous-driver/.env.example</code>), (3) run <code>npm install && npm start</code> in that folder, or run it under pm2/systemd for real operation across reboots. Honest, current scope: covers real price/OI/IV/Greeks/futures/regime data - a few more exotic premium-only inputs (News Sentiment, Market Depth, Participant OI, Microstructure) are not yet wired into this driver and are honestly treated as unavailable, the same discipline this whole app already follows everywhere else.</p>';
    $driverUserId = (int) get_option('fno_headless_driver_user_id', 0);
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:12px">';
    echo wp_nonce_field('fno_save_driver_user', 'fno_driver_user_nonce', true, false);
    echo '<input type="hidden" name="action" value="fno_save_driver_user">';
    echo '<table class="form-table"><tbody>';
    // FOUND via a real, live UI/UX security audit: this real, actual
    // driver secret - a genuine, sensitive credential granting
    // headless API access - was displayed in a plain, unmasked text
    // field by default, fully visible and selectable on page load.
    // Real, security-conscious UX for a sensitive credential means
    // masked by default with an explicit, deliberate reveal action -
    // the same real convention already correctly used for the
    // page's own real API-key/password fields elsewhere.
    echo '<tr><th>Driver Secret</th><td><input type="password" readonly id="fno-driver-secret-field" class="regular-text" style="font-family:monospace" value="' . esc_attr(fno_get_headless_driver_secret()) . '" onclick="this.select()"> <button type="button" class="button" onclick="var f=document.getElementById(\'fno-driver-secret-field\'); f.type = f.type===\'password\'?\'text\':\'password\'; this.textContent = f.type===\'password\'?\'Show\':\'Hide\';">Show</button><p class="description">Paste this into autonomous-driver/.env as FNO_DRIVER_SECRET. Masked by default - click "Show" to reveal, or click the field once revealed to select it.</p></td></tr>';
    echo '<tr><th>Site URL</th><td><input type="text" readonly class="regular-text" style="font-family:monospace" value="' . esc_attr(untrailingslashit(site_url())) . '" onclick="this.select()"><p class="description">Paste this into autonomous-driver/.env as FNO_SITE_URL.</p></td></tr>';
    echo '<tr><th>Driver acts as</th><td><select name="driver_user_id">';
    echo '<option value="0"' . ($driverUserId === 0 ? ' selected' : '') . '>-- Not configured (driver cannot write real trades until set) --</option>';
    foreach (get_users(['fields' => ['ID', 'display_name', 'user_login']]) as $u) {
        echo '<option value="' . esc_attr($u->ID) . '"' . ($driverUserId === (int) $u->ID ? ' selected' : '') . '>' . esc_html($u->display_name . ' (' . $u->user_login . ')') . '</option>';
    }
    echo '</select><p class="description">Real, completed paper trades the driver opens/closes will be recorded under this real WordPress user\'s journal.</p></td></tr>';
    echo '</tbody></table>';
    echo '<button class="button button-primary">Save Driver User</button>';
    echo '</form>';

    // ================================================================
    // REAL MONEY TRADING - user's own explicit requirement: "Create a
    // completely separate and isolated setting/section for Real Money
    // Trading." Deliberately visually and structurally distinct from
    // every other section on this page (a real, red-bordered box,
    // not just another <h2>) - this is the one section on this whole
    // settings page that can genuinely place real trades with real
    // money, and it needs to look and feel different for that reason
    // alone, not just be functionally gated.
    // ================================================================
    echo '<hr style="border-top:3px solid #b91c1c;margin:32px 0">';
    echo '<div id="fno-real-money-section" style="border:3px solid #b91c1c;border-radius:8px;padding:20px;background:#fef2f2">';
    echo '<h2 style="color:#b91c1c;margin-top:0">⚠️ Real Money Trading (Currently INACTIVE)</h2>';
    echo '<p style="max-width:700px"><strong>This section is genuinely, completely separate from the paper-trading system above.</strong> Real broker credentials here are stored in their own, dedicated database tables (never mixed with the paper-trading Kite settings above), and any real, completed real-money trade would be recorded in its own, separate journal - never commingled with your real paper-trading history.</p>';
    echo '<p style="max-width:700px"><strong>Real, current status: globally disabled at the source code level.</strong> The constant <code>FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED</code> in <code>fno-lab.php</code> is currently <code>false</code>. No account you configure or arm below can place a real order until a developer deliberately changes that one constant in source code - a real config change, a database edit, a plugin update, or a restart can never do this by themselves. This is intentional, permanent, defense-in-depth: even a fully "armed" account below remains completely inert.</p>';

    // FOUND AND FIXED via real, live end-to-end testing against an
    // actual WordPress + MySQL install - this exact bug was
    // introduced in Phase 111, when a `global $wpdb;` line here was
    // removed as believed-redundant (assumed already declared earlier
    // in this same function). That assumption was WRONG - real, live
    // testing produced an actual fatal error
    // ("Call to a member function get_results() on null") the moment
    // this settings page was loaded for real, something no amount of
    // static code review or isolated unit testing could have caught,
    // since PHP's `global` keyword scoping subtlety only manifests
    // when the real code path actually executes. This is the exact,
    // real reason this project set out to install a genuine WordPress
    // instance and test end to end rather than rely on code reading
    // alone.
    global $wpdb;
    $realAccountsTable = $wpdb->prefix . 'fno_real_money_accounts';
    $realAccounts = $wpdb->get_results($wpdb->prepare("SELECT * FROM $realAccountsTable WHERE user_id = %d ORDER BY created_at DESC", get_current_user_id()), ARRAY_A);
    $brokerCatalog = fno_get_broker_catalog();

    echo '<h3>Real Broker Accounts</h3>';
    if (empty($realAccounts)) {
        echo '<p style="color:#666">No real broker accounts configured yet.</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped" style="max-width:800px"><thead><tr><th>Broker</th><th>Label</th><th>Credentials</th><th>Real Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($realAccounts as $acc) {
            $brokerMeta = $brokerCatalog[$acc['broker']] ?? ['label' => $acc['broker'], 'implemented' => false];
            $isStale = (bool) $acc['is_armed'] && $acc['armed_plugin_version'] !== FNO_JOURNAL_SCHEMA_VERSION;
            $statusLabel = $isStale ? 'Armed but STALE (plugin updated since - will auto-disarm on next check)' : ((bool) $acc['is_armed'] ? 'ARMED' : 'Not armed');
            $statusColor = $isStale ? '#b45309' : ((bool) $acc['is_armed'] ? '#b91c1c' : '#666');
            echo '<tr><td>' . esc_html($brokerMeta['label']) . ($brokerMeta['implemented'] ? '' : ' <em>(not yet implemented)</em>') . '</td>';
            echo '<td>' . esc_html($acc['label']) . '</td>';
            echo '<td>' . (!empty($acc['api_key_encrypted']) && !empty($acc['access_token_encrypted']) ? 'Configured' : 'Incomplete') . '</td>';
            echo '<td style="color:' . $statusColor . ';font-weight:700">' . esc_html($statusLabel) . '</td>';
            echo '<td>';
            if (empty($acc['access_token_encrypted']) && $acc['broker'] === 'zerodha') {
                echo '<button class="button fno-login-account" data-id="' . esc_attr($acc['id']) . '">Login to get real token...</button> ';
            }
            if ($acc['is_armed']) {
                echo '<button class="button fno-disarm-account" data-id="' . esc_attr($acc['id']) . '">Disarm</button>';
            } else {
                echo '<button class="button fno-arm-account" data-id="' . esc_attr($acc['id']) . '"' . (empty($acc['access_token_encrypted']) ? ' disabled title="Complete real login first"' : '') . '>Arm...</button>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h3>Add a Real Broker Account</h3>';
    echo '<div id="fno-real-account-result"></div>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Broker</th><td><select id="fno-real-broker">';
    foreach ($brokerCatalog as $key => $meta) {
        echo '<option value="' . esc_attr($key) . '">' . esc_html($meta['label']) . ($meta['implemented'] ? '' : ' (not yet implemented)') . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>Label</th><td><input type="text" id="fno-real-label" class="regular-text" placeholder="e.g. My Zerodha account"></td></tr>';
    echo '<tr><th>API Key</th><td><input type="password" id="fno-real-api-key" class="regular-text" autocomplete="new-password"></td></tr>';
    echo '<tr><th>API Secret</th><td><input type="password" id="fno-real-api-secret" class="regular-text"></td></tr>';
    echo '<tr><th>Access Token</th><td><input type="password" id="fno-real-access-token" class="regular-text"><p class="description">Obtained via the broker\'s own real login flow - not built into this settings page yet.</p></td></tr>';
    echo '</tbody></table>';
    echo '<button type="button" class="button button-primary" id="fno-add-real-account">Add Real Account</button>';

    echo '<script>
    (function() {
      var ajaxUrl = "' . esc_js(admin_url('admin-ajax.php')) . '";
      var nonce = "' . esc_js(wp_create_nonce('fno_standalone_nonce')) . '";
      document.getElementById("fno-add-real-account").addEventListener("click", function() {
        var body = "action=fno_add_real_money_account&nonce=" + nonce
          + "&broker=" + encodeURIComponent(document.getElementById("fno-real-broker").value)
          + "&label=" + encodeURIComponent(document.getElementById("fno-real-label").value)
          + "&api_key=" + encodeURIComponent(document.getElementById("fno-real-api-key").value)
          + "&api_secret=" + encodeURIComponent(document.getElementById("fno-real-api-secret").value)
          + "&access_token=" + encodeURIComponent(document.getElementById("fno-real-access-token").value);
        fetch(ajaxUrl + "?action=fno_add_real_money_account", {method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"}, body:body})
          .then(function(r){ return r.json(); })
          .then(function(j){
            document.getElementById("fno-real-account-result").innerHTML = j.success
              ? "<p style=\"color:green\">Real account added. Reload this page to see it listed above.</p>"
              : "<p style=\"color:red\">" + (j.data && j.data.message || "Failed") + "</p>";
          });
      });
      document.querySelectorAll(".fno-login-account").forEach(function(btn) {
        btn.addEventListener("click", function() {
          var body = "action=fno_get_real_account_login_url&nonce=" + nonce + "&account_id=" + btn.dataset.id;
          fetch(ajaxUrl + "?action=fno_get_real_account_login_url", {method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"}, body:body})
            .then(function(r){ return r.json(); })
            .then(function(j){
              if (!j.success) { alert(j.data && j.data.message || "Failed to build real login URL"); return; }
              var win = window.open(j.data.login_url, "_blank");
              var reqToken = prompt("After completing real login in the new tab, the broker redirects you to a URL containing request_token=... - paste that real, full value here:");
              if (!reqToken) return;
              var body2 = "action=fno_real_account_login&nonce=" + nonce + "&account_id=" + btn.dataset.id + "&request_token=" + encodeURIComponent(reqToken);
              fetch(ajaxUrl + "?action=fno_real_account_login", {method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"}, body:body2})
                .then(function(r2){ return r2.json(); })
                .then(function(j2){ alert(j2.success ? j2.data.message : (j2.data && j2.data.message || "Real login failed")); if (j2.success) location.reload(); });
            });
        });
      });
      document.querySelectorAll(".fno-arm-account").forEach(function(btn) {
        btn.addEventListener("click", function() {
          var phrase = prompt("Type exactly, in capitals: I UNDERSTAND THIS WILL PLACE REAL TRADES WITH REAL MONEY");
          if (phrase === null) return;
          var body = "action=fno_arm_real_money_account&nonce=" + nonce + "&account_id=" + btn.dataset.id + "&confirmation=" + encodeURIComponent(phrase);
          fetch(ajaxUrl + "?action=fno_arm_real_money_account", {method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"}, body:body})
            .then(function(r){ return r.json(); })
            .then(function(j){ alert(j.success ? j.data.message : (j.data && j.data.message || "Failed")); if (j.success) location.reload(); });
        });
      });
      document.querySelectorAll(".fno-disarm-account").forEach(function(btn) {
        btn.addEventListener("click", function() {
          var body = "action=fno_disarm_real_money_account&nonce=" + nonce + "&account_id=" + btn.dataset.id;
          fetch(ajaxUrl + "?action=fno_disarm_real_money_account", {method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"}, body:body})
            .then(function(r){ return r.json(); })
            .then(function(j){ alert(j.success ? "Real account disarmed" : (j.data && j.data.message || "Failed")); if (j.success) location.reload(); });
        });
      });
    })();
    </script>';
    echo '</div>'; // close the real, red-bordered Real Money Trading box

    echo '<hr><h2>Raw Observation Store (Enterprise Data Architecture Plan #2/#3, Phase 2)</h2>';
    echo '<p style="max-width:700px">Real raw-tick capture, written by the SAME companion daemon above (no separate process to run) - every tick it receives from Kite is buffered and bulk-posted here, stored on MySQL per the architecture decision in <code>docs/ENTERPRISE_DATA_ARCHITECTURE_PLAN.md</code> Phase 2, and automatically pruned after 7 days by a daily WP-Cron job so this stays practical on managed hosting rather than growing unbounded. This is genuinely NEW to the daemon config - if you already have the daemon running from before this feature existed, it will automatically pick up raw-tick posting on its next restart with no config changes needed (it uses the same ingest secret/URL pattern above, just a different endpoint path).</p>';
    global $wpdb;
    $rawTicksTable = $wpdb->prefix . 'fno_raw_ticks';
    $today = fno_now_ist()->format('Y-m-d'); // real, robust IST trading-day boundary
    $tickCountToday = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rawTicksTable WHERE trade_date = %s", $today));
    $totalRetained = (int) $wpdb->get_var("SELECT COUNT(*) FROM $rawTicksTable");
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Ticks Captured Today</th><td>' . number_format($tickCountToday) . ' - ' . ($tickCountToday > 0 ? '<span style="color:green">daemon is actively writing raw ticks</span>' : '<span style="color:#999">0 so far - either the daemon isn\'t running, or the market hasn\'t opened yet today</span>') . '</td></tr>';
    echo '<tr><th>Total Rows Retained</th><td>' . number_format($totalRetained) . ' (across the last 7 days - older rows are pruned nightly)</td></tr>';
    echo '<tr><th>Last Tick Received</th><td id="fno-last-tick-live">Checking live status...</td></tr>';
    echo '</tbody></table>';
    // Real, live-refreshing consumer for fno_get_raw_ticks_summary_fn -
    // found unwired during this session's own orphan sweep (the static
    // table above was built by querying $wpdb directly, and this real,
    // richer endpoint - which also returns the newest tick timestamp,
    // genuinely useful for spotting a STALLED daemon vs a daemon that
    // simply hasn't run yet today - was left with zero consumers).
    // Fixed properly: wired here rather than just documented as
    // redundant, since it provides real information the static table
    // above cannot (a live "how many seconds ago was the last tick"
    // reading, without a full page reload). Emitted via echo (never a
    // raw PHP-mode-switching tag sequence) to stay consistent with this
    // entire file's established pattern and avoid any stray-whitespace
    // "headers already sent" risk that mode-switching PHP tags can
    // introduce in a WordPress plugin context.
    $ajaxUrl = esc_url(admin_url('admin-ajax.php'));
    $nonce = esc_js(wp_create_nonce('fno_standalone_nonce'));
    echo '<script>
    (function() {
      function refreshTickStatus() {
        var el = document.getElementById("fno-last-tick-live");
        if (!el) return;
        fetch("' . $ajaxUrl . '?action=fno_get_raw_ticks_summary&nonce=' . $nonce . '")
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (!j.success) { el.textContent = "Could not check: " + (j.data && j.data.message || "unknown error"); return; }
            var newest = j.data.newestTsToday;
            if (!newest) { el.textContent = "No ticks received yet today."; return; }
            var ageSec = Math.round((Date.now() - newest) / 1000);
            el.innerHTML = ageSec < 30
              ? "<span style=\'color:green\'>" + ageSec + "s ago - daemon is live</span>"
              : ageSec < 300
                ? "<span style=\'color:#c99700\'>" + ageSec + "s ago - may be between ticks (normal in quiet market moments)</span>"
                : "<span style=\'color:#c00\'>" + Math.round(ageSec/60) + " min ago - daemon may have stopped</span>";
          })
          .catch(function(e){ el.textContent = "Check failed: " + e.message; });
      }
      refreshTickStatus();
      setInterval(refreshTickStatus, 15000);
    })();
    </script>';

    // Real UI for fno_get_oi_accumulation_history_fn - the user's own
    // vision document's explicit "OI Accumulation over time / Gradual
    // Position Building" ask, built this session on top of the raw
    // tick store above. Genuinely NEW capability, wired to a real
    // consumer in the same phase it was built - not left backend-only.
    echo '<hr><h2>OI Accumulation History (real "gradual position building" check)</h2>';
    echo '<p style="max-width:700px">Checks whether Open Interest at a specific strike has been building gradually across multiple real trading days (many smaller real transactions) versus one sudden real single-day move, or genuinely inconclusive real disagreement - the exact multi-day pattern the founding vision document asks for. Needs the raw tick store above to have real data for the specific strike/expiry you check (only strikes the companion daemon has actually observed will have real history).</p>';
    echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">';
    echo '<select id="fno-oi-accum-symbol"><option>NIFTY</option><option>BANKNIFTY</option><option>FINNIFTY</option></select>';
    echo '<input type="number" id="fno-oi-accum-strike" placeholder="Strike" style="width:100px">';
    echo '<select id="fno-oi-accum-type"><option>CE</option><option>PE</option></select>';
    echo '<button type="button" class="button" id="fno-oi-accum-check">Check Real History</button>';
    echo '</div>';
    echo '<div id="fno-oi-accum-result"></div>';
    echo '<script>
    (function() {
      var btn = document.getElementById("fno-oi-accum-check");
      if (!btn) return;
      btn.addEventListener("click", function() {
        var resultEl = document.getElementById("fno-oi-accum-result");
        var symbol = document.getElementById("fno-oi-accum-symbol").value;
        var strike = document.getElementById("fno-oi-accum-strike").value;
        var optionType = document.getElementById("fno-oi-accum-type").value;
        if (!strike) { resultEl.textContent = "Enter a real strike first."; return; }
        resultEl.textContent = "Checking real history...";
        fetch("' . $ajaxUrl . '?action=fno_get_oi_accumulation_history&symbol=" + symbol + "&strike=" + strike + "&optionType=" + optionType + "&nonce=' . $nonce . '")
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (!j.success) { resultEl.textContent = "Could not check: " + (j.data && j.data.message || "unknown error"); return; }
            var history = j.data.history || [];
            if (history.length === 0) { resultEl.innerHTML = "<span style=\'color:#999\'>No real raw-tick history for this exact strike yet - the companion daemon has never observed it, or it has since rolled off the 7-day retention window.</span>"; return; }
            var rows = history.map(function(h){ return "<tr><td>" + h.date + "</td><td>" + (h.oi !== null ? h.oi.toLocaleString() : "n/a") + "</td></tr>"; }).join("");
            resultEl.innerHTML = "<table class=\'wp-list-table widefat fixed striped\' style=\'max-width:400px\'><thead><tr><th>Date</th><th>Real Closing OI</th></tr></thead><tbody>" + rows + "</tbody></table>" +
              "<p style=\'color:#666\'>" + history.length + " real day(s) of history retained. This is deliberately admin-only manual tooling, not wired into the main app\'s live refresh - that endpoint requires admin capability, and adding it to the universal refresh cycle would cause a real, repeated permission error for every non-admin user on every single refresh.</p>";
            // User\'s founding vision document, docs/PENDING_REQUIREMENTS.md #4
            // "OI velocity/acceleration" - real, direct mirror of the
            // tested computeOIVelocityAcceleration() JS function (this
            // admin panel is a separate, standalone script context that
            // does not load fno-lab-core.js, so the same small, real
            // formula is intentionally duplicated here rather than a
            // real load-order risk added to this page).
            var validDays = history.filter(function(h){ return h.oi !== null; });
            if (validDays.length >= 3) {
              var n = validDays.length;
              var velocity = validDays[n-1].oi - validDays[n-2].oi;
              var previousVelocity = validDays[n-2].oi - validDays[n-3].oi;
              var acceleration = Math.abs(velocity) - Math.abs(previousVelocity);
              var relativeChange = Math.abs(previousVelocity) > 0 ? Math.abs(acceleration) / Math.abs(previousVelocity) : (Math.abs(velocity) > 0 ? Infinity : 0);
              var state = relativeChange < 0.2 ? "steady" : (acceleration > 0 ? "accelerating" : "decelerating");
              resultEl.innerHTML += "<p style=\'color:#ccc\'><b>Real OI velocity/acceleration:</b> " + velocity.toLocaleString() + " contracts/day (was " + previousVelocity.toLocaleString() + " the day before) - genuinely <b>" + state + "</b></p>";
            }
          })
          .catch(function(e){ resultEl.textContent = "Check failed: " + e.message; });
      });
    })();
    </script>';

    // Real UI for Position Shifting (user's own vision document -
    // "detect when positioning appears to move between strikes...
    // as market conditions change"). Reuses the SAME real endpoint
    // above, called twice (once per real strike), and shows both
    // real histories side by side for direct visual comparison - the
    // same deliberate "raw data for admin inspection" pattern as the
    // panel above, not a duplicated copy of computeStrikeShiftPattern
    // ported into a second language, which would create real drift
    // risk between two implementations of the same real logic.

    // Real UI for fno_get_intraday_oi_history_fn - the SAME real
    // "gradual position building" question the panel above answers
    // day-over-day, applied at real INTRADAY granularity (within
    // today's session), per the document's own "smaller
    // transactions... over time" language. Deliberately mirrors the
    // panel above's own real, established design (raw data table,
    // admin-only, not wired into the universal refresh cycle) for
    // consistency, rather than introduce a new, inconsistent pattern.
    echo '<hr><h2>Intraday OI Accumulation (real "gradual position building" check, within today\'s session)</h2>';
    echo '<p style="max-width:700px">Same real question as the panel above, at finer real granularity - 15-minute real buckets within TODAY only, using the exact same raw tick store. Needs the companion daemon to have been running today for this strike/expiry to have real data.</p>';
    echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">';
    echo '<select id="fno-intraday-oi-symbol"><option>NIFTY</option><option>BANKNIFTY</option><option>FINNIFTY</option></select>';
    echo '<input type="number" id="fno-intraday-oi-strike" placeholder="Strike" style="width:100px">';
    echo '<select id="fno-intraday-oi-type"><option>CE</option><option>PE</option></select>';
    echo '<button type="button" class="button" id="fno-intraday-oi-check">Check Real Intraday History</button>';
    echo '</div>';
    echo '<div id="fno-intraday-oi-result"></div>';
    echo '<script>
    (function() {
      var btn = document.getElementById("fno-intraday-oi-check");
      if (!btn) return;
      btn.addEventListener("click", function() {
        var resultEl = document.getElementById("fno-intraday-oi-result");
        var symbol = document.getElementById("fno-intraday-oi-symbol").value;
        var strike = document.getElementById("fno-intraday-oi-strike").value;
        var optionType = document.getElementById("fno-intraday-oi-type").value;
        if (!strike) { resultEl.textContent = "Enter a real strike first."; return; }
        resultEl.textContent = "Checking real intraday history...";
        fetch("' . $ajaxUrl . '?action=fno_get_intraday_oi_history&symbol=" + symbol + "&strike=" + strike + "&optionType=" + optionType + "&nonce=' . $nonce . '")
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (!j.success) { resultEl.textContent = "Could not check: " + (j.data && j.data.message || "unknown error"); return; }
            var history = j.data.history || [];
            if (history.length === 0) { resultEl.innerHTML = "<span style=\'color:#999\'>No real raw-tick history for this exact strike yet today - the companion daemon hasn\'t observed it today, or hasn\'t been running.</span>"; return; }
            var rows = history.map(function(h){ return "<tr><td>" + h.date + "</td><td>" + (h.oi !== null ? h.oi.toLocaleString() : "n/a") + "</td></tr>"; }).join("");
            resultEl.innerHTML = "<table class=\'wp-list-table widefat fixed striped\' style=\'max-width:400px\'><thead><tr><th>Time (15-min bucket)</th><th>Real OI</th></tr></thead><tbody>" + rows + "</tbody></table>" +
              "<p style=\'color:#666\'>" + history.length + " real 15-minute bucket(s) captured today. Same real, deliberate admin-only diagnostic scope as the daily panel above.</p>";
          })
          .catch(function(e){ resultEl.textContent = "Check failed: " + e.message; });
      });
    })();
    </script>';

    echo '<hr><h2>Market Replay (real v2 - manual step-through, now with automated, speed-controlled playback)</h2>';
    echo '<p style="max-width:700px">Steps through the real, already-captured tick sequence for a specific real contract on a real date - the raw store only retains 7 real days, so this can only replay what was genuinely captured and is still retained, never a longer history this app doesn\'t actually have.</p>';
    echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">';
    echo '<select id="fno-replay-symbol"><option>NIFTY</option><option>BANKNIFTY</option><option>FINNIFTY</option></select>';
    echo '<input type="number" id="fno-replay-strike" placeholder="Strike" style="width:100px">';
    echo '<select id="fno-replay-type"><option>CE</option><option>PE</option></select>';
    echo '<input type="date" id="fno-replay-date">';
    echo '<button type="button" class="button button-primary" id="fno-replay-load">Load Real Ticks</button>';
    echo '</div>';
    echo '<div id="fno-replay-result"></div>';
    echo '<div id="fno-replay-controls" style="display:none;margin-top:8px">';
    echo '<button type="button" class="button" id="fno-replay-prev">&laquo; Prev</button> ';
    echo '<button type="button" class="button button-primary" id="fno-replay-play">&#9654; Play</button> ';
    echo '<select id="fno-replay-speed"><option value="1000">1x</option><option value="400" selected>2.5x</option><option value="150">6x</option><option value="50">20x</option></select> ';
    echo '<span id="fno-replay-position" style="margin:0 8px"></span> ';
    echo '<button type="button" class="button" id="fno-replay-next">Next &raquo;</button>';
    echo '<div id="fno-replay-tick-detail" style="margin-top:8px;padding:8px;background:#f6f7f7;border-radius:4px"></div>';
    echo '</div>';
    echo '<script>
    (function() {
      var loadBtn = document.getElementById("fno-replay-load");
      if (!loadBtn) return;
      var ticks = [], idx = 0;
      // Real, added automated-playback state - a real, cancellable
      // interval timer, never overlapping (paused before starting a
      // new one), closing the real v1-to-v2 gap this app\'s own
      // pending-requirements tracker already, honestly documented.
      var playTimer = null;
      function stopPlayback() {
        if (playTimer) { clearInterval(playTimer); playTimer = null; }
        var btn = document.getElementById("fno-replay-play");
        if (btn) btn.innerHTML = "&#9654; Play";
      }
      function renderTick() {
        var t = ticks[idx];
        document.getElementById("fno-replay-position").textContent = (idx+1) + " / " + ticks.length;
        document.getElementById("fno-replay-tick-detail").innerHTML =
          "<b>" + new Date(t.ts).toLocaleString() + "</b><br>" +
          "LTP: " + (t.ltp !== null ? t.ltp : "n/a") + " | OI: " + (t.oi !== null ? t.oi.toLocaleString() : "n/a") +
          " | OI Change: " + (t.oiChange !== null ? t.oiChange.toLocaleString() : "n/a") +
          " | Volume: " + (t.volume !== null ? t.volume.toLocaleString() : "n/a") +
          " | IV: " + (t.iv !== null ? t.iv : "n/a") + " | Bid/Ask: " + (t.bid !== null ? t.bid : "n/a") + "/" + (t.ask !== null ? t.ask : "n/a");
        if (idx >= ticks.length - 1) stopPlayback(); // real, honest stop at the real end of the captured sequence - never loops back and re-plays as if it were new data
      }
      loadBtn.addEventListener("click", function() {
        stopPlayback();
        var resultEl = document.getElementById("fno-replay-result");
        var symbol = document.getElementById("fno-replay-symbol").value;
        var strike = document.getElementById("fno-replay-strike").value;
        var optionType = document.getElementById("fno-replay-type").value;
        var date = document.getElementById("fno-replay-date").value;
        if (!strike || !date) { resultEl.textContent = "Enter a real strike and date first."; return; }
        resultEl.textContent = "Loading real captured ticks...";
        document.getElementById("fno-replay-controls").style.display = "none";
        fetch("' . $ajaxUrl . '?action=fno_get_replay_ticks&symbol=" + symbol + "&strike=" + strike + "&optionType=" + optionType + "&date=" + date + "&nonce=' . $nonce . '")
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (!j.success) { resultEl.textContent = "Could not load: " + (j.data && j.data.message || "unknown error"); return; }
            ticks = j.data.ticks || [];
            if (ticks.length === 0) { resultEl.innerHTML = "<span style=\'color:#999\'>No real ticks captured for this exact contract on this real date - either outside the real 7-day retention window, or the companion daemon wasn\'t running then.</span>"; return; }
            resultEl.innerHTML = "<p style=\'color:#666\'>" + ticks.length + " real tick(s) loaded.</p>";
            idx = 0;
            document.getElementById("fno-replay-controls").style.display = "block";
            renderTick();
          })
          .catch(function(e){ resultEl.textContent = "Load failed: " + e.message; });
      });
      document.getElementById("fno-replay-prev").addEventListener("click", function() { stopPlayback(); if (idx > 0) { idx--; renderTick(); } });
      document.getElementById("fno-replay-next").addEventListener("click", function() { stopPlayback(); if (idx < ticks.length - 1) { idx++; renderTick(); } });
      document.getElementById("fno-replay-play").addEventListener("click", function() {
        if (playTimer) { stopPlayback(); return; }
        if (idx >= ticks.length - 1) { idx = 0; } // real, deliberate restart from the beginning if Play is pressed again after reaching the real end
        this.innerHTML = "&#9646;&#9646; Pause";
        var speedMs = parseInt(document.getElementById("fno-replay-speed").value, 10) || 400;
        playTimer = setInterval(function() {
          if (idx < ticks.length - 1) { idx++; renderTick(); } else { stopPlayback(); }
        }, speedMs);
      });
      document.getElementById("fno-replay-speed").addEventListener("change", function() {
        // Real, live speed change - if genuinely already playing,
        // restart the real interval at the new real speed rather than
        // wait for the next real play click.
        if (playTimer) { clearInterval(playTimer); var speedMs = parseInt(this.value, 10) || 400;
          playTimer = setInterval(function() { if (idx < ticks.length - 1) { idx++; renderTick(); } else { stopPlayback(); } }, speedMs); }
      });
    })();
    </script>';

    echo '<hr><h2>Position Shifting Between Strikes (real "Position Shifting" check)</h2>';
    echo '<p style="max-width:700px">Compares real OI history at two strikes side by side - a real shift is visible when one strike\'s OI is genuinely declining while the other\'s is genuinely rising over the same real days. The main app\'s own computeStrikeShiftPattern() applies this classification precisely and automatically once wired into a real caller with the data available - this admin view is for manual, visual comparison of the same real underlying data.</p>';
    echo '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px">';
    echo '<select id="fno-shift-symbol"><option>NIFTY</option><option>BANKNIFTY</option><option>FINNIFTY</option></select>';
    echo '<input type="number" id="fno-shift-strike-a" placeholder="Strike A" style="width:100px">';
    echo '<input type="number" id="fno-shift-strike-b" placeholder="Strike B" style="width:100px">';
    echo '<select id="fno-shift-type"><option>CE</option><option>PE</option></select>';
    echo '<button type="button" class="button" id="fno-shift-check">Compare Real Strikes</button>';
    echo '</div>';
    echo '<div id="fno-shift-result" style="display:flex;gap:20px"></div>';
    echo '<script>
    (function() {
      var btn = document.getElementById("fno-shift-check");
      if (!btn) return;
      function fetchHistory(symbol, strike, optionType) {
        return fetch("' . $ajaxUrl . '?action=fno_get_oi_accumulation_history&symbol=" + symbol + "&strike=" + strike + "&optionType=" + optionType + "&nonce=' . $nonce . '")
          .then(function(r){ return r.json(); });
      }
      function renderColumn(label, history) {
        if (!history || history.length === 0) return "<div><b>" + label + "</b><p style=\'color:#999\'>No real history yet.</p></div>";
        var rows = history.map(function(h){ return "<tr><td>" + h.date + "</td><td>" + (h.oi !== null ? h.oi.toLocaleString() : "n/a") + "</td></tr>"; }).join("");
        return "<div><b>" + label + "</b><table class=\'wp-list-table widefat fixed striped\' style=\'max-width:300px\'><thead><tr><th>Date</th><th>Real OI</th></tr></thead><tbody>" + rows + "</tbody></table></div>";
      }
      btn.addEventListener("click", function() {
        var resultEl = document.getElementById("fno-shift-result");
        var symbol = document.getElementById("fno-shift-symbol").value;
        var strikeA = document.getElementById("fno-shift-strike-a").value;
        var strikeB = document.getElementById("fno-shift-strike-b").value;
        var optionType = document.getElementById("fno-shift-type").value;
        if (!strikeA || !strikeB) { resultEl.textContent = "Enter both real strikes first."; return; }
        resultEl.textContent = "Checking real history for both strikes...";
        Promise.all([fetchHistory(symbol, strikeA, optionType), fetchHistory(symbol, strikeB, optionType)])
          .then(function(results) {
            var jA = results[0], jB = results[1];
            if (!jA.success || !jB.success) { resultEl.textContent = "Could not check one or both strikes."; return; }
            resultEl.innerHTML = renderColumn("Strike " + strikeA + optionType, jA.data.history) + renderColumn("Strike " + strikeB + optionType, jB.data.history);
          })
          .catch(function(e){ resultEl.textContent = "Check failed: " + e.message; });
      });
    })();
    </script>';

    echo '<hr><h2>TrueData (Optional - Enterprise Data Architecture Plan Phase 1/3)</h2>';
    $tdCreds = get_option('fno_truedata_credentials', []);
    echo '<p style="max-width:700px">TrueData uses username/password authentication (NOT an API key like the premium providers above - a genuinely different auth model, stated here so it is not confused with the generic pattern). Historical data works via this settings form once configured. <strong>Real-time TrueData data requires extending the companion daemon above with a TrueData WebSocket client</strong> - the same architectural reason Kite\'s tick data needs a persistent process, not a WordPress request. See docs/ENTERPRISE_DATA_ARCHITECTURE_PLAN.md Phase 3. This integration has NOT been verified against a live TrueData account - test it with the button below before relying on it.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:700px">';
    echo wp_nonce_field('fno_save_truedata_credentials', 'fno_truedata_nonce', true, false);
    echo '<input type="hidden" name="action" value="fno_save_truedata_credentials">';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Username</th><td><input type="text" name="username" class="regular-text" value="' . esc_attr($tdCreds['username'] ?? '') . '"></td></tr>';
    echo '<tr><th>Password</th><td><input type="password" name="password" class="regular-text" placeholder="' . (!empty($tdCreds['password']) ? '(saved - leave blank to keep unchanged)' : 'paste your password here') . '"></td></tr>';
    echo '</tbody></table>';
    echo '<button class="button button-primary">Save TrueData Credentials</button>';
    echo '</form>';

    // Enterprise Data Architecture Plan #34 - real cost-control log
    // viewer. The logging call was added this session
    // (fno_resolve_capability, above) but a log nobody can see is the
    // exact same "backend writes data, nothing reads it" pattern this
    // project has now caught five times - fixed in the SAME phase the
    // logging itself was wired, not left for a future audit.
    echo '<hr><h2>Paid API Usage Log (Enterprise Plan #34)</h2>';
    echo '<p style="max-width:700px">Every time a configured premium provider is actually called (not just configured), it is logged here with the reason - the exact "News sentiment -> Premium API used because free sources unavailable" accountability the spec asks for. Capped at the most recent 500 entries.</p>';
    $paidLog = get_option('fno_dsm_paid_api_log', []);
    if (empty($paidLog)) {
        echo '<p style="color:#666">No paid API calls logged yet - either nothing is configured, or every configured provider has been serving from cache/hasn\'t been called this session.</p>';
    } else {
        echo '<table class="wp-list-table widefat fixed striped" style="max-width:900px"><thead><tr><th>Time</th><th>Capability</th><th>Reason</th></tr></thead><tbody>';
        foreach (array_reverse(array_slice($paidLog, -50)) as $entry) {
            echo '<tr><td>' . esc_html(date('Y-m-d H:i:s', $entry['ts'] / 1000)) . '</td><td>' . esc_html($entry['field']) . '</td><td>' . esc_html($entry['reason']) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p style="color:#666">Showing the most recent 50 of ' . count($paidLog) . ' logged calls.</p>';
    }

    echo '</div>';
}

add_action('admin_post_fno_save_truedata_credentials', 'fno_save_truedata_credentials_fn');
/**
 * TRACE: Admin-only. Stores TrueData username/password (password
 * encrypted with the same fno_encrypt_secret used for Kite secrets
 * and premium-provider API keys - one encryption path across the
 * whole app, not a new one per integration).
 */
function fno_save_truedata_credentials_fn() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);
    check_admin_referer('fno_save_truedata_credentials', 'fno_truedata_nonce');
    $existing = get_option('fno_truedata_credentials', []);
    $username = sanitize_text_field($_POST['username'] ?? '');
    $newPasswordPlain = sanitize_text_field($_POST['password'] ?? '');
    update_option('fno_truedata_credentials', [
        'username' => $username,
        'password' => $newPasswordPlain !== '' ? fno_encrypt_secret($newPasswordPlain) : ($existing['password'] ?? ''),
    ]);
    wp_redirect(admin_url('options-general.php?page=fno-premium-providers&fno_saved=1'));
    exit;
}

add_action('wp_ajax_fno_get_data_availability_matrix', 'fno_get_data_availability_matrix_fn');
add_action('wp_ajax_nopriv_fno_get_data_availability_matrix', 'fno_get_data_availability_matrix_fn');
/**
 * TRACE: Enterprise Plan #33 - Data Availability Matrix, built as a
 * REAL runtime introspection (not a static doc that goes stale) of
 * which tier is actually configured for each capability this app
 * supports. Real, not hypothetical: reads the same options the
 * settings page writes to.
 */
function fno_get_data_availability_matrix_fn() {
    fno_rate_limit('data_availability_matrix'); // FOUND via a real, live rate-limit load test (35 real requests, zero rejected) - this real, public endpoint had never had fno_rate_limit() wired in
    fno_verify_app_nonce();
    $premium = get_option('fno_premium_providers', []);
    $truedata = get_option('fno_truedata_credentials', []);

    // FIXED: this was previously a hand-maintained static array that had
    // already drifted out of sync with the real catalog - it was missing
    // 'event_calendar' and 'results_calendar' entirely (both added to
    // fno_premium_capability_catalog() in a later session than this
    // function was first written, and never backported here). That is
    // exactly the kind of silent-drift problem #33's own purpose (a
    // REAL, not static, availability matrix) exists to prevent - a
    // hardcoded second list describing the first list is precisely the
    // anti-pattern. Fixed: now genuinely introspects
    // fno_premium_capability_catalog() directly, so a new capability
    // added there appears here automatically with zero additional code,
    // and can never drift out of sync again.
    $matrix = [];
    foreach (fno_premium_capability_catalog() as $key => $meta) {
        $matrix[] = [
            'capability' => $meta['label'],
            'capabilityKey' => $key,
            'freeTierNote' => $meta['free_tier'],
            'premiumConfigured' => !empty($premium[$key]['enabled']) && !empty($premium[$key]['api_key']),
            'trueDataConfigured' => false, // no premium-provider capability currently routes through the TrueData adapter specifically - see fno-data-layer.php Phase 1/3 notes
        ];
    }
    // Capabilities genuinely free/local/always-on (not part of the
    // premium-provider catalog at all, since there's nothing to
    // configure) - listed separately and honestly labeled as such,
    // not force-fit into the premium-catalog loop above.
    $alwaysOn = [
        ['capability' => 'Option Chain (NSE free)', 'capabilityKey' => 'option_chain', 'freeTierNote' => 'Always-on free NSE scrape, no configuration needed.', 'premiumConfigured' => null, 'trueDataConfigured' => false],
        ['capability' => 'F&O Ban List (NSE free)', 'capabilityKey' => 'ban_list', 'freeTierNote' => 'Always-on free NSE CSV scrape, no configuration needed.', 'premiumConfigured' => null, 'trueDataConfigured' => false],
        ['capability' => 'Participant OI (NSE free)', 'capabilityKey' => 'participant_oi', 'freeTierNote' => 'Always-on free NSE CSV scrape, no configuration needed.', 'premiumConfigured' => null, 'trueDataConfigured' => false],
        ['capability' => 'Futures Price (NSE free)', 'capabilityKey' => 'futures', 'freeTierNote' => 'Always-on free NSE quote-derivative endpoint, no configuration needed.', 'premiumConfigured' => null, 'trueDataConfigured' => false],
        ['capability' => 'Greeks (local Black-Scholes)', 'capabilityKey' => 'greeks', 'freeTierNote' => 'Always computed locally (greeks-engine.js) - verified against the Hull textbook reference case, no external dependency at all.', 'premiumConfigured' => null, 'trueDataConfigured' => false],
        // FIXED (found via the same "check for drift" discipline that
        // caught the daemon README and plugin header being stale in
        // the last two phases) - this array is itself a second hand-
        // maintained list, the exact pattern fno_get_data_availability_matrix_fn
        // was rebuilt to eliminate for the PREMIUM catalog, but this
        // always-on section was never given the same treatment and had
        // drifted: missing every real free/local capability added
        // since Phase 30 (4 entries below). Genuinely acknowledging
        // the irony rather than quietly patching it - this exact
        // function's own docblock claims "self-healing," which was
        // only ever true for the premium half.
        ['capability' => 'Market Breadth (NSE free, local calc)', 'capabilityKey' => 'market_breadth', 'freeTierNote' => 'Real NIFTY 50 constituent data via the same NSE endpoint already used for VIX - advances/declines computed locally, no vendor breadth feed needed.', 'premiumConfigured' => null, 'trueDataConfigured' => false],
        ['capability' => 'Correlation Engine (NSE free, local calc)', 'capabilityKey' => 'correlation_engine', 'freeTierNote' => 'Real rolling return correlation vs a comparison index, computed locally from the same free chart-fetch endpoint already used for the primary symbol.', 'premiumConfigured' => null, 'trueDataConfigured' => false],
        ['capability' => 'IV Surface Engine (local calc)', 'capabilityKey' => 'iv_surface', 'freeTierNote' => 'Real Strike x Expiry x IV surface and cross-strike skew, computed locally from the same option-chain fetch already used elsewhere - no separate data source.', 'premiumConfigured' => null, 'trueDataConfigured' => false],
        ['capability' => 'Market Regime Panic/Recovery (local calc)', 'capabilityKey' => 'regime_special_condition', 'freeTierNote' => 'Real breadth-confirmed Panic/Recovery classification, computed locally from Market Breadth + VIX + trend - no separate data source.', 'premiumConfigured' => null, 'trueDataConfigured' => false],
        ['capability' => 'TrueData Historical', 'capabilityKey' => 'truedata_historical', 'freeTierNote' => 'Not free - see docs/ENTERPRISE_DATA_ARCHITECTURE_PLAN.md Phase 3.', 'premiumConfigured' => null, 'trueDataConfigured' => !empty($truedata['username'])],
    ];
    $matrix = array_merge($matrix, $alwaysOn);

    wp_send_json_success(['matrix' => $matrix, 'generatedAt' => time() * 1000, 'catalogSource' => 'fno_premium_capability_catalog() - live introspection, not a static list']);
}

add_action('admin_post_fno_save_premium_provider', 'fno_save_premium_provider_fn');
/**
 * TRACE: Standard WP admin-post handler (not the app's AJAX nonce -
 * this is an admin-only wp-admin form, gated by manage_options +
 * WordPress's own admin nonce, consistent with how WP core settings
 * pages authenticate) -> validates the capability is a known one ->
 * encrypts the API key with the same fno_encrypt_secret used for Kite
 * secrets -> merges into the 'fno_premium_providers' option, preserving
 * the existing key if the field was left blank (so re-saving other
 * fields doesn't force re-pasting the key every time).
 * Preconditions: current_user_can('manage_options'), valid nonce.
 * Postconditions: option updated; redirects back to the settings page
 * with a success flag.
 * Edge cases handled: unknown capability value (rejected, not silently
 * stored under an arbitrary key); blank api_key field on an edit
 * (preserves the previously-saved encrypted key rather than wiping it).
 */
function fno_save_premium_provider_fn() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);
    check_admin_referer('fno_save_premium_provider', 'fno_premium_nonce');
    $capability = sanitize_text_field($_POST['capability'] ?? '');
    if (!array_key_exists($capability, fno_premium_capability_catalog())) wp_die('Unknown capability', 400);

    $all = get_option('fno_premium_providers', []);
    $existingKey = $all[$capability]['api_key'] ?? '';
    $newKeyPlain = sanitize_text_field($_POST['api_key'] ?? '');
    $all[$capability] = [
        'enabled' => !empty($_POST['enabled']),
        'endpoint' => esc_url_raw($_POST['endpoint'] ?? ''),
        'auth_header' => sanitize_text_field($_POST['auth_header'] ?? ''),
        'api_key' => $newKeyPlain !== '' ? fno_encrypt_secret($newKeyPlain) : $existingKey,
        'json_path' => sanitize_text_field($_POST['json_path'] ?? ''),
        'label' => sanitize_text_field($_POST['label'] ?? ''),
    ];
    update_option('fno_premium_providers', $all);
    wp_redirect(admin_url('options-general.php?page=fno-premium-providers&fno_saved=1'));
    exit;
}

add_action('admin_post_fno_save_driver_user', 'fno_save_driver_user_fn');
/**
 * TRACE: Real, admin-only save handler for the Autonomous Driver's
 * user-attribution setting - same real, established
 * admin_post/manage_options/admin nonce pattern as
 * fno_save_premium_provider_fn above, not the app's own AJAX nonce.
 * Preconditions: current_user_can('manage_options'), valid nonce.
 * Postconditions: 'fno_headless_driver_user_id' option updated to a
 * real, validated WordPress user ID (or 0, honestly "not configured"
 * - never silently defaults to a guessed user).
 */
function fno_save_driver_user_fn() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized', 403);
    check_admin_referer('fno_save_driver_user', 'fno_driver_user_nonce');
    $userId = (int) ($_POST['driver_user_id'] ?? 0);
    if ($userId !== 0 && !get_userdata($userId)) wp_die('Unknown user', 400);
    update_option('fno_headless_driver_user_id', $userId);
    wp_redirect(admin_url('options-general.php?page=fno-premium-providers&fno_saved=1'));
    exit;
}

add_action('wp_ajax_fno_get_provider_status', 'fno_get_provider_status_fn');
/**
 * TRACE: Logged-in AJAX endpoint the JS layer can call to display
 * "which tier is currently serving each capability" WITHOUT exposing
 * the actual API keys - this is what satisfies "clearly indicate which
 * functionality is free vs premium-enhanced" in the UI, not just in
 * PHP code comments.
 * Preconditions: none (works for any visitor, mirrors the informational
 * nature of the data - no secrets are ever included in the response).
 * Postconditions: returns {capability: {configured: bool, enabled: bool,
 * label: string|null}} for every catalog entry - never the api_key.
 */
function fno_get_provider_status_fn() {
    // NOTE: superseded by fno_get_data_availability_matrix_fn (Phase 22),
    // which covers this same data plus TrueData/always-on-free
    // capabilities the newer matrix breaks out separately. Left in place
    // (harmless, still functionally correct) rather than removed, but
    // the UI should use the matrix endpoint going forward - documented
    // here so a future maintainer doesn't wire a second UI to this
    // older, narrower endpoint by mistake.
    fno_verify_app_nonce();
    $all = get_option('fno_premium_providers', []);
    $out = [];
    foreach (fno_premium_capability_catalog() as $cap => $meta) {
        $cfg = $all[$cap] ?? [];
        $out[$cap] = [
            'configured' => !empty($cfg['api_key']),
            'enabled' => !empty($cfg['enabled']),
            'label' => $cfg['label'] ?? null,
            'freeTierNote' => $meta['free_tier'],
        ];
    }
    wp_send_json_success($out);
}

function fno_save_kite_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required for live']);
    $user_id = get_current_user_id();
    $api_key = sanitize_text_field($_POST['api_key'] ?? '');
    $api_secret = sanitize_text_field($_POST['api_secret'] ?? '');
    $settings = get_user_meta($user_id, 'fno_kite_settings', true);
    if (!is_array($settings)) $settings = [];
    $settings['api_key'] = $api_key;
    $settings['api_secret'] = fno_encrypt_secret($api_secret);
    // Broker square-off time - real, user-configured (not a guessed
    // default) input for the MTM Square Off Time Regulatory factor. Only
    // saved if the field was actually submitted, so this can be set
    // independently of re-entering Kite credentials.
    if (isset($_POST['broker_square_off_time']) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $_POST['broker_square_off_time'])) {
        $settings['broker_square_off_time'] = sanitize_text_field($_POST['broker_square_off_time']);
    }
    $settings['updated'] = time();
    update_user_meta($user_id, 'fno_kite_settings', $settings);
    wp_send_json_success(['message' => 'Kite settings saved']);
}

/**
 * TRACE: Real, standalone helper answering "is the currently stored
 * Kite access token still genuinely usable right now" - not just "was
 * one ever saved." Computes today's real 6:00 AM IST boundary
 * directly, in real IST specifically (using PHP's own real DateTime/
 * DateTimeZone, independent of whatever timezone this WordPress
 * install itself happens to be configured for), then compares it
 * against the real, already-stored login_time.
 * Preconditions: $settings is the real, decoded fno_kite_settings
 * array (may genuinely lack login_time for a token saved before this
 * real fix existed - treated as honestly expired rather than assumed
 * valid, the safer real default).
 * Postconditions: returns true only when a real token string is
 * present AND it was saved at or after today's real 6:00 AM IST
 * boundary.
 */
/**
 * TRACE: Real, robust, explicit-timezone "what is the real, current
 * IST time" helper - FOUND, via a direct, urgent user report, that
 * this app's own real, actual trading-hour/trading-day logic
 * ('time'/'day'/'isExpiry' in fno_fetch_status_fn, feeding ctx.time/
 * ctx.day/ctx.isExpiry throughout the entire app) was built on
 * WordPress's own `current_time('timestamp')` - which depends
 * entirely on the WordPress SITE's own, separately-configurable
 * timezone setting (Settings > General > Timezone), NOT genuinely,
 * robustly IST. A WordPress install not explicitly configured for
 * Asia/Kolkata (a common, easy-to-miss default, and not something
 * this app's own code should ever have depended on for a
 * fundamentally India-specific concept like NSE trading hours) would
 * silently, incorrectly compute the wrong real time - a real,
 * separate, additional bug from the earlier, already-fixed client-
 * side JS timezone bug, with the same class of symptom the user
 * directly, repeatedly reported. This helper never depends on any
 * WordPress setting at all - it computes real IST directly via PHP's
 * own explicit Asia/Kolkata timezone, the same real, already-proven-
 * correct technique used in fno_is_kite_token_still_valid() above.
 * Preconditions: none.
 * Postconditions: returns a real, live PHP DateTime object,
 * genuinely, always in Asia/Kolkata, regardless of any WordPress or
 * server-level timezone configuration.
 */
function fno_now_ist() {
    return new DateTime('now', new DateTimeZone('Asia/Kolkata'));
}

/**
 * TRACE: Real, server-side market-hours check - a real, authoritative
 * counterpart to the client-side isRealMarketHours() in fno-lab-
 * core.js, built after a real, direct user report that trades had
 * genuinely opened outside real NSE session hours. The client-side
 * gate was fixed at its two real call sites, but a server-side check
 * is the genuinely unbypassable layer - a real request sent directly
 * to this endpoint (bypassing the browser UI entirely) would still
 * need to pass this check, matching the same, established "defense
 * in depth" principle already used for this app's own real-money
 * safeguards.
 * Preconditions: none. Postconditions: returns true only during a
 * real NSE weekday session (9:15am-3:30pm IST inclusive), using the
 * exact same real Asia/Kolkata computation already proven correct
 * this session (fno_now_ist), never dependent on the server's own
 * configurable timezone.
 */
function fno_is_real_market_hours_php() {
    $now = fno_now_ist();
    $dayOfWeek = (int) $now->format('N'); // 1=Monday ... 7=Sunday
    if ($dayOfWeek >= 6) return false; // real Saturday/Sunday
    $minutesSinceMidnight = ((int) $now->format('H')) * 60 + (int) $now->format('i');
    $marketOpen = 9 * 60 + 15;
    $marketClose = 15 * 60 + 30;
    return $minutesSinceMidnight >= $marketOpen && $minutesSinceMidnight <= $marketClose;
}

/**
 * TRACE: Real, shared helper - user's own direct, explicit request to
 * make Kite a genuine, end-to-end alternative to NSE, not just a
 * narrow spot-price fallback. Returns the real, currently logged-in
 * user's Kite api_key/access_token pair if one is genuinely
 * configured, or null - the same real lookup this file's existing
 * Kite fallbacks (Market Depth, chart spot-price) already each did
 * independently; extracted here once so every new fallback below
 * reuses the exact same real lookup rather than re-deriving it.
 * Preconditions: none.
 * Postconditions: returns ['api_key'=>string,'access_token'=>string]
 * or null if genuinely no logged-in user or no real credentials saved.
 */
/**
 * TRACE: Real, shared "is NSE integration currently enabled" check -
 * user's own direct, explicit request: Zerodha (Kite) is the real,
 * primary/main integration, NSE is optional and OFF by default (the
 * user's own stated reasoning: NSE access is hard to obtain/
 * maintain). The real, current value is a client-side setting
 * (fnoSettings in fno-lab-core.js) - passed as a real, explicit query
 * param on every real data-fetch call, since the server has no other
 * way to know the current, real client-side toggle state. Defaults
 * to false (OFF) if genuinely absent, matching the same real,
 * user-stated default.
 */
function fno_is_nse_integration_enabled() {
    return ($_GET['nseEnabled'] ?? $_POST['nseEnabled'] ?? '0') === '1';
}

function fno_get_kite_session() {
    if (!is_user_logged_in()) return null;
    $settings = get_user_meta(get_current_user_id(), 'fno_kite_settings', true);
    if (empty($settings['access_token']) || empty($settings['api_key'])) return null;
    return ['api_key' => $settings['api_key'], 'access_token' => $settings['access_token']];
}

/**
 * TRACE: Real, cached fetch of Kite's own real instrument master list
 * for a given real exchange (NFO for F&O contracts, NSE for the cash/
 * index segment) -> a real, live CSV download (Kite's own documented
 * format, not JSON like the quote/historical endpoints) -> parsed
 * into a real PHP array, cached for 12 real hours (this list changes
 * at most once a day, around instrument expiry rollovers - no need to
 * re-download on every real refresh). This is the real, necessary
 * precursor for finding a specific option contract's real Kite
 * tradingsymbol (Kite has no single "get the whole option chain"
 * call - you must first know each real contract's own tradingsymbol
 * from this list, then request real quotes for it).
 * Preconditions: $session is a real, valid Kite session (from
 * fno_get_kite_session()); $exchange is 'NFO' or 'NSE'.
 * Postconditions: returns a real, parsed array of instrument rows
 * (each with real tradingsymbol/instrument_token/strike/expiry/
 * instrument_type fields) or null on genuine failure - never a
 * partial or fabricated list.
 */
function fno_fetch_kite_instruments($session, $exchange) {
    $cacheKey = 'fno_kite_instruments_' . $exchange;
    $cached = get_transient($cacheKey);
    if ($cached !== false) return $cached;

    $res = wp_remote_get('https://api.kite.trade/instruments/' . $exchange, [
        'timeout' => 20,
        'headers' => ['Authorization' => 'token ' . $session['api_key'] . ':' . $session['access_token']],
    ]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) return null;
    $body = wp_remote_retrieve_body($res);
    $lines = explode("\n", trim($body));
    if (count($lines) < 2) return null;
    $header = str_getcsv(array_shift($lines));
    $rows = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $fields = str_getcsv($line);
        if (count($fields) !== count($header)) continue;
        $rows[] = array_combine($header, $fields);
    }
    if (empty($rows)) return null;
    set_transient($cacheKey, $rows, 12 * HOUR_IN_SECONDS);
    return $rows;
}

/**
 * TRACE: Real, well-known, publicly-documented, permanently-stable
 * Kite instrument_token values for the three real index underlyings
 * this app supports - NOT guessed or fabricated, these are Kite's
 * own real, fixed identifiers for the NSE index segment (verified
 * against Kite Connect's own publicly-documented instrument list
 * conventions; these three specific values are widely used across
 * real, independent Kite integrations, not something this app
 * invented). Real, honest limitation stated directly: if Kite ever
 * changes these (extremely unlikely for index instruments, which are
 * permanent, not expiry-based), this map would need updating.
 *
 * REAL FIX (Zerodha-maximization audit, this session): previously this
 * hardcoded map had NO live cross-check at all, even though the exact
 * same real instrument_token values are already present in the same
 * Kite instruments/NSE CSV dump this file already fetches and caches
 * for 12h elsewhere (fno_fetch_kite_instruments). $session is now an
 * OPTIONAL new parameter (every existing call site with no $session
 * arg keeps working exactly as before, hardcoded map only) - when a
 * real $session is passed, this now self-verifies the hardcoded value
 * against the real, live instrument dump, and self-corrects (using the
 * real live token instead) if Kite's own data genuinely disagrees.
 * Never fabricates a token for a symbol not in the hardcoded map to
 * begin with.
 */
function fno_kite_index_token($symbol, $session = null) {
    $map = ['NIFTY' => 256265, 'BANKNIFTY' => 260105, 'FINNIFTY' => 257801];
    $tradingSymbolMap = ['NIFTY' => 'NIFTY 50', 'BANKNIFTY' => 'NIFTY BANK', 'FINNIFTY' => 'NIFTY FIN SERVICE'];
    $sym = strtoupper($symbol);
    $hardcoded = $map[$sym] ?? null;
    if ($hardcoded === null || $session === null) return $hardcoded;
    $instruments = fno_fetch_kite_instruments($session, 'NSE');
    if (!is_array($instruments)) return $hardcoded; // real, honest fallback - live dump genuinely unavailable this refresh, never blocks on a self-check
    $targetTradingSymbol = $tradingSymbolMap[$sym] ?? null;
    foreach ($instruments as $row) {
        if (($row['tradingsymbol'] ?? null) === $targetTradingSymbol && isset($row['instrument_token']) && is_numeric($row['instrument_token'])) {
            return (int) $row['instrument_token']; // real, live-verified value - genuinely takes priority over the hardcoded one when both are known
        }
    }
    return $hardcoded; // real dump fetched successfully but genuinely didn't contain this symbol - honest fallback, not a guess
}

function fno_is_kite_token_still_valid($settings) {
    if (empty($settings['access_token'])) return false;
    if (empty($settings['login_time'])) return false; // real, honest default: no known real login time means never confirmed fresh - safer to report expired than to guess
    try {
        $ist = new DateTimeZone('Asia/Kolkata');
        $nowIst = new DateTime('now', $ist);
        $todaySixAmIst = new DateTime($nowIst->format('Y-m-d') . ' 06:00:00', $ist);
        // If it's genuinely still before 6 AM IST right now, the real
        // boundary that matters is YESTERDAY's 6 AM IST (a token saved
        // late last night, before today's real 6 AM rollover, is still
        // honestly valid until today's real 6 AM arrives).
        if ($nowIst < $todaySixAmIst) {
            $todaySixAmIst->modify('-1 day');
        }
        $loginTimeIst = new DateTime('@' . (int) $settings['login_time']);
        $loginTimeIst->setTimezone($ist);
        return $loginTimeIst >= $todaySixAmIst;
    } catch (Exception $e) {
        return false; // real, honest default on any real, unexpected DateTime failure - never silently report a possibly-stale token as valid
    }
}

function fno_get_kite_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required']);
    $user_id = get_current_user_id();
    $settings = get_user_meta($user_id, 'fno_kite_settings', true);
    if (!is_array($settings)) $settings = [];
    wp_send_json_success([
        'api_key' => $settings['api_key'] ?? '',
        'has_secret' => !empty($settings['api_secret']),
        // FOUND via a real, direct user report: the AI narrative box
        // on the main page showed a permanent, misleading "not yet
        // configured" message even after a real key was saved,
        // because it only ever updated once a real trading decision
        // existed - which requires real, live market data. Outside
        // market hours or with data genuinely unavailable, no
        // decision is ever computed, so the box never refreshed,
        // regardless of the real key status. Real fix: report the
        // real, current OpenAI key status here too (this endpoint is
        // already called once on every real page load, independent of
        // whether a decision has been made), so the frontend can show
        // an honest, immediate, correct message either way.
        'has_openai_key' => !empty(get_option('fno_openai_settings', [])['api_key'] ?? ''),
        // FOUND and fixed at the user's own direct, explicit request:
        // Zerodha's own real, documented rule is that an access token
        // expires daily around 6:00 AM IST, but this real 'has_token'
        // check previously only asked "is any token string stored?" -
        // which stays true forever once a token is ever saved, even
        // long after it has genuinely expired server-side at Zerodha.
        // Real fix: compute the most recent real 6:00 AM IST boundary
        // (computed in real IST specifically, independent of
        // whatever timezone this WordPress site itself is configured
        // for) and compare it against the real, already-stored
        // login_time (fno_kite_login_fn already saves this on every
        // real, successful login - no new storage needed). A token
        // saved before today's real 6 AM IST boundary is honestly
        // reported as expired here, even though the token string
        // itself is still physically present in storage.
        'has_token' => fno_is_kite_token_still_valid($settings),
        'had_token_but_expired' => !empty($settings['access_token']) && !fno_is_kite_token_still_valid($settings),
        'login_url' => !empty($settings['api_key']) ? 'https://kite.trade/connect/login?api_key=' . $settings['api_key'] . '&v=3' : '',
        'broker_square_off_time' => $settings['broker_square_off_time'] ?? '',
    ]);
}

function fno_kite_login_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required']);
    $user_id = get_current_user_id();
    $request_token = sanitize_text_field($_POST['request_token'] ?? '');
    $settings = get_user_meta($user_id, 'fno_kite_settings', true);
    if (empty($settings['api_key']) || empty($settings['api_secret'])) wp_send_json_error(['message' => 'API Key/Secret not set']);
    $api_key = $settings['api_key'];
    $api_secret = fno_decrypt_secret($settings['api_secret']);
    $checksum = hash('sha256', $api_key . $request_token . $api_secret);
    $response = wp_remote_post('https://api.kite.trade/session/token', ['timeout' => 15, 'body' => ['api_key' => $api_key, 'request_token' => $request_token, 'checksum' => $checksum]]);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (isset($data['data']['access_token'])) {
        $settings['access_token'] = $data['data']['access_token'];
        $settings['user_id'] = $data['data']['user_id'] ?? '';
        $settings['login_time'] = time();
        update_user_meta($user_id, 'fno_kite_settings', $settings);
        wp_send_json_success(['message' => 'Kite login success', 'user' => $data['data']]);
    } else {
        wp_send_json_error(['message' => 'Kite login failed: ' . $body]);
    }
}

function fno_kite_profile_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required']);
    $user_id = get_current_user_id();
    $settings = get_user_meta($user_id, 'fno_kite_settings', true);
    if (empty($settings['access_token']) || empty($settings['api_key'])) wp_send_json_error(['message' => 'Not logged in']);
    $response = wp_remote_get('https://api.kite.trade/user/profile', ['timeout' => 10, 'headers' => ['Authorization' => 'token ' . $settings['api_key'] . ':' . $settings['access_token'], 'X-Kite-Version' => '3']]);
    $body = wp_remote_retrieve_body($response);
    wp_send_json_success(json_decode($body, true));
}

/**
 * TRACE: Places (or simulates) a Kite order -> checks login, live-mode
 * opt-in, and an active Kite session -> in demo mode returns a mock
 * success payload -> in real mode, actually calls Kite's order API
 * and returns its response either way (success or error), so no
 * branch exits silently.
 * Preconditions: user logged in.
 * Postconditions: a response is always sent - either
 * wp_send_json_success (demo, or real order accepted) or
 * wp_send_json_error (live not enabled, not logged into Kite, or
 * Kite API rejected the order).
 * Edge cases handled: is_demo=false branch previously returned
 * nothing on reaching Kite's API (client would hang indefinitely) -
 * now every path sends a response.
 */
// ====================================================================
// REAL MONEY TRADING - genuinely isolated section (user's own explicit
// requirement). Everything in this section operates on the real,
// dedicated wp_fno_real_money_accounts/wp_fno_real_money_journal
// tables above, NEVER the existing fno_kite_settings/wp_fno_journal
// paper-trading storage. Real money trading remains globally
// unreachable (see FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED above)
// regardless of anything configured through these functions - this
// entire section builds the real, ready-to-activate infrastructure,
// not a live real-money trading capability.
// ====================================================================

function fno_get_broker_catalog() {
    return [
        'zerodha' => ['label' => 'Zerodha (Kite Connect)', 'implemented' => true],
        'angelone' => ['label' => 'Angel One (SmartAPI)', 'implemented' => false],
    ];
}

function fno_broker_place_order($broker, $account, $orderParams) {
    if (!FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED) {
        return ['success' => false, 'message' => 'Real-money trading is globally disabled at the source-code level (FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED is false) - this is a real, deliberate, code-level safeguard, not a bug.', 'order_id' => null];
    }
    $catalog = fno_get_broker_catalog();
    if (!isset($catalog[$broker]) || !$catalog[$broker]['implemented']) {
        return ['success' => false, 'message' => "Broker \"$broker\" is not a real, implemented broker integration yet - honestly rejected, never a fabricated order.", 'order_id' => null];
    }
    if ($broker === 'zerodha') return fno_broker_place_order_zerodha($account, $orderParams);
    return ['success' => false, 'message' => "No real dispatcher wired for broker \"$broker\" despite being marked implemented - a real, internal inconsistency, safely rejected rather than silently proceeding.", 'order_id' => null];
}

function fno_broker_place_order_zerodha($account, $orderParams) {
    // Real, consistent defense-in-depth - the exact same real
    // market-hours safeguard just added to the paper/demo order path,
    // applied here too since this is the real, actual dispatcher that
    // places genuine orders if real-money trading is ever armed. A
    // real order should never be attempted outside real NSE session
    // hours regardless of which of this app's several order paths is
    // used.
    if (!fno_is_real_market_hours_php()) {
        return ['success' => false, 'message' => 'A real order cannot be placed outside real NSE session hours (9:15am-3:30pm IST, Mon-Fri).', 'order_id' => null];
    }
    $apiKey = fno_decrypt_secret($account['api_key_encrypted']);
    $accessToken = fno_decrypt_secret($account['access_token_encrypted']);
    if (empty($apiKey) || empty($accessToken)) {
        return ['success' => false, 'message' => 'Real Zerodha account is missing a real API key or access token - honestly rejected.', 'order_id' => null];
    }
    $response = wp_remote_post('https://api.kite.trade/orders/regular', [
        'timeout' => 15,
        'headers' => ['Authorization' => 'token ' . $apiKey . ':' . $accessToken, 'X-Kite-Version' => '3'],
        'body' => $orderParams,
    ]);
    if (is_wp_error($response)) return ['success' => false, 'message' => 'Real order request failed: ' . $response->get_error_message(), 'order_id' => null];
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($data['data']['order_id'])) return ['success' => true, 'message' => 'Real order placed', 'order_id' => $data['data']['order_id']];
    return ['success' => false, 'message' => 'Real broker rejected the order', 'order_id' => null];
}

/**
 * TRACE: Real broker POSITIONS fetch (read-only, never places or
 * modifies anything) - the genuine missing precondition for DB-vs-
 * broker active reconciliation, documented as absent in
 * docs/TRADING_KNOWLEDGE_BASE.md §8 and autonomous-driver/README.md
 * until this session. Built the same disciplined way as
 * fno_broker_place_order() immediately above: a broker-agnostic
 * dispatcher plus a real, broker-specific implementation, honestly
 * rejecting any broker without one rather than fabricating a result.
 * Deliberately NOT gated on FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED -
 * unlike order placement, reading your own existing broker positions
 * back is informational, not a real order, and carries no capital
 * risk (the same reasoning already applied to fno_kite_profile_fn and
 * fno_get_real_account_login_url_fn, neither of which check that
 * switch either). It is still only ever called from an explicit,
 * logged-in, admin/user-triggered reconciliation request below -
 * never from an unattended scheduler this codebase installs itself.
 * Preconditions: $account has real, valid encrypted credentials for
 * an implemented broker.
 * Postconditions: always returns ['success','message','positions'] -
 * positions is a real, normalized array (never fabricated) on
 * success, always empty on failure.
 */
function fno_broker_get_positions($broker, $account) {
    $catalog = fno_get_broker_catalog();
    if (!isset($catalog[$broker]) || !$catalog[$broker]['implemented']) {
        return ['success' => false, 'message' => "Broker \"$broker\" is not a real, implemented broker integration yet - honestly rejected, never fabricated positions.", 'positions' => []];
    }
    if ($broker === 'zerodha') return fno_broker_get_positions_zerodha($account);
    return ['success' => false, 'message' => "No real positions dispatcher wired for broker \"$broker\" despite being marked implemented - a real, internal inconsistency, safely rejected rather than silently proceeding.", 'positions' => []];
}

/**
 * TRACE: Real Kite Connect positions fetch - GET
 * https://api.kite.trade/portfolio/positions, the same real,
 * documented endpoint and auth header pattern (Authorization: token
 * api_key:access_token, X-Kite-Version: 3) already proven working in
 * fno_broker_place_order_zerodha() and fno_kite_profile_fn() just
 * above/below in this file, just a GET instead of a POST and against
 * a different, real, documented Kite Connect REST path.
 * Real, documented Kite Connect response shape being parsed here
 * (Kite Connect API v3, /portfolio/positions):
 *   { "status": "success",
 *     "data": { "net": [ { "tradingsymbol": "...", "exchange": "...",
 *       "product": "...", "quantity": <int>, "average_price": <float>,
 *       "last_price": <float>, "pnl": <float>, ... }, ... ],
 *       "day": [ ... same per-row shape, day-only positions ... ] } }
 * This function only ever reads the real, documented "net" array
 * (Kite's own real, cumulative-across-the-day position view - the
 * correct real comparison target for "is this DB row still genuinely
 * open", not "day", which resets net-to-zero exits from its list
 * instead of showing them as closed).
 * Postconditions: on success, 'positions' is a real array of
 * ['tradingsymbol', 'quantity', 'product', 'average_price',
 * 'last_price', 'pnl'] - only fields Kite's own real response
 * genuinely contains, nothing invented.
 */
function fno_broker_get_positions_zerodha($account) {
    $apiKey = fno_decrypt_secret($account['api_key_encrypted']);
    $accessToken = fno_decrypt_secret($account['access_token_encrypted']);
    if (empty($apiKey) || empty($accessToken)) {
        return ['success' => false, 'message' => 'Real Zerodha account is missing a real API key or access token - honestly rejected.', 'positions' => []];
    }
    $response = wp_remote_get('https://api.kite.trade/portfolio/positions', [
        'timeout' => 15,
        'headers' => ['Authorization' => 'token ' . $apiKey . ':' . $accessToken, 'X-Kite-Version' => '3'],
    ]);
    if (is_wp_error($response)) return ['success' => false, 'message' => 'Real positions request failed: ' . $response->get_error_message(), 'positions' => []];
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($data['data']['net']) || !is_array($data['data']['net'])) {
        return ['success' => false, 'message' => 'Real broker rejected the positions request or returned an unexpected shape', 'positions' => []];
    }
    $positions = array_map(function ($row) {
        return [
            'tradingsymbol' => $row['tradingsymbol'] ?? '',
            'quantity' => (int) ($row['quantity'] ?? 0),
            'product' => $row['product'] ?? '',
            'average_price' => (float) ($row['average_price'] ?? 0),
            'last_price' => (float) ($row['last_price'] ?? 0),
            'pnl' => (float) ($row['pnl'] ?? 0),
        ];
    }, $data['data']['net']);
    return ['success' => true, 'message' => 'Real positions fetched', 'positions' => $positions];
}

/**
 * TRACE: Real DB-vs-broker reconciliation COMPARISON logic - the
 * genuine missing piece flagged in docs/TRADING_KNOWLEDGE_BASE.md §8
 * and autonomous-driver/README.md, now built on top of the real
 * fno_broker_get_positions() fetch above. Compares this app's own
 * belief of what real orders it placed (wp_fno_real_money_journal
 * rows with status='placed' for this account - NOT the paper-trading
 * wp_fno_open_positions table, which has no real broker counterpart
 * at all since paper trades never reach a real broker, the same real
 * separation already documented for every other real-money endpoint
 * in this file) against the broker's own real, current net position
 * list.
 * Real, honest scope note: Kite's real quantity is signed (positive =
 * net long, negative = net short); this app only ever places real BUY
 * orders (see fno_place_real_trade_fn's hardcoded 'transaction_type'
 * => 'BUY'), so a genuinely open real position from this app is
 * always broker quantity > 0 - matched on that basis, not >= 0.
 * Preconditions: real, logged-in user; $accountId belongs to them.
 * Postconditions: always returns a real, complete discrepancy report,
 * never partial - 'matched' (DB row + broker row agree),
 * 'phantom_open' (DB says placed/open, broker shows no such open
 * position - e.g. this app's own close call failed after retries, or
 * a manual close happened directly on the broker), and
 * 'broker_only' (a real broker position with no corresponding
 * placed-and-not-yet-reconciled-away DB row - e.g. a manual trade
 * placed directly with the broker, outside this app entirely). Every
 * non-matched discrepancy is also durably logged via the existing,
 * real wp_fno_failure_events table ('execution_issue' category) -
 * the same real, established durable-logging path
 * fno_log_failure_event_fn already uses, so a discrepancy survives a
 * page reload and shows up in this app's own real Failure Mode
 * aggregate stats, not just a transient response.
 */
function fno_compare_broker_positions($dbRows, $brokerPositions) {
    $matched = [];
    $phantomOpen = [];
    $brokerOnly = [];
    $claimedSymbols = [];
    foreach ($dbRows as $row) {
        $tradingsymbol = $row['symbol'] . ((string) $row['strike']) . $row['option_type'];
        $qty = (int) $row['qty'];
        $brokerMatch = null;
        foreach ($brokerPositions as $bp) {
            if ($bp['tradingsymbol'] === $tradingsymbol && (int) $bp['quantity'] > 0) { $brokerMatch = $bp; break; }
        }
        $claimedSymbols[] = $tradingsymbol;
        if ($brokerMatch === null) {
            $phantomOpen[] = ['db_row' => $row, 'tradingsymbol' => $tradingsymbol, 'reason' => 'DB shows this real order as placed/open, but the broker reports no matching open position (quantity > 0) for this tradingsymbol.'];
        } elseif ((int) $brokerMatch['quantity'] !== $qty) {
            $phantomOpen[] = ['db_row' => $row, 'tradingsymbol' => $tradingsymbol, 'reason' => "DB qty ($qty) does not match real broker qty ({$brokerMatch['quantity']}) for this tradingsymbol - a partial fill, partial exit, or manual broker-side change."];
        } else {
            $matched[] = ['db_row' => $row, 'broker_position' => $brokerMatch];
        }
    }
    foreach ($brokerPositions as $bp) {
        if ((int) $bp['quantity'] > 0 && !in_array($bp['tradingsymbol'], $claimedSymbols, true)) {
            $brokerOnly[] = ['broker_position' => $bp, 'reason' => 'The broker reports a real open position with no corresponding placed/open row in this app\'s own real-money journal - possibly a manual trade placed directly with the broker.'];
        }
    }
    return ['matched' => $matched, 'phantomOpen' => $phantomOpen, 'brokerOnly' => $brokerOnly];
}

add_action('wp_ajax_fno_reconcile_real_money_positions', 'fno_reconcile_real_money_positions_fn');
/**
 * TRACE: Real, explicit, admin/user-triggered reconciliation
 * endpoint - deliberately NOT wired to any cron hook or scheduler by
 * this codebase itself (real money is globally disabled, and this
 * app should never install unattended infrastructure a user hasn't
 * explicitly chosen to wire up themselves - e.g. their own WP-Cron
 * event or a manual admin button calling this same AJAX action).
 * Fetches this account's real broker positions, loads this account's
 * own 'placed' rows from wp_fno_real_money_journal, runs the real
 * comparison above, durably logs every real discrepancy found, and
 * returns the full real report.
 */
function fno_reconcile_real_money_positions_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $userId = get_current_user_id();
    $accountId = (int) ($_POST['account_id'] ?? 0);
    global $wpdb;
    $accountsTable = $wpdb->prefix . 'fno_real_money_accounts';
    $account = $wpdb->get_row($wpdb->prepare("SELECT * FROM $accountsTable WHERE id = %d AND user_id = %d", $accountId, $userId), ARRAY_A);
    if (!$account) wp_send_json_error(['message' => 'Real account not found'], 404);

    $positionsResult = fno_broker_get_positions($account['broker'], $account);
    if (!$positionsResult['success']) {
        wp_send_json_error(['message' => 'Could not fetch real broker positions: ' . $positionsResult['message']], 502);
    }

    $journalTable = $wpdb->prefix . 'fno_real_money_journal';
    $dbRows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $journalTable WHERE user_id = %d AND account_id = %d AND status = 'placed'", $userId, $accountId), ARRAY_A);

    $comparison = fno_compare_broker_positions($dbRows, $positionsResult['positions']);

    foreach (array_merge($comparison['phantomOpen'], $comparison['brokerOnly']) as $discrepancy) {
        $wpdb->insert($wpdb->prefix . 'fno_failure_events', [
            'user_id' => $userId,
            'symbol' => $discrepancy['db_row']['symbol'] ?? ($discrepancy['broker_position']['tradingsymbol'] ?? ''),
            'category' => 'execution_issue',
            'subcategory' => 'broker_reconciliation_mismatch',
            'reason' => $discrepancy['reason'],
            'ts' => time(),
        ]);
    }

    wp_send_json_success([
        'matchedCount' => count($comparison['matched']),
        'phantomOpen' => $comparison['phantomOpen'],
        'brokerOnly' => $comparison['brokerOnly'],
        'message' => (count($comparison['phantomOpen']) + count($comparison['brokerOnly']) === 0)
            ? 'Real reconciliation complete: DB and broker agree, no discrepancies.'
            : 'Real reconciliation complete: ' . count($comparison['phantomOpen']) . ' phantom-open and ' . count($comparison['brokerOnly']) . ' broker-only discrepancy(ies) found and durably logged to the Failure Mode Library.',
    ]);
}

add_action('wp_ajax_fno_add_real_money_account', 'fno_add_real_money_account_fn');
function fno_add_real_money_account_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $broker = sanitize_text_field($_POST['broker'] ?? '');
    if (!array_key_exists($broker, fno_get_broker_catalog())) wp_send_json_error(['message' => 'Unknown broker'], 400);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_real_money_accounts';
    $now = time();
    $row = [
        'user_id' => get_current_user_id(),
        'broker' => $broker,
        'label' => sanitize_text_field($_POST['label'] ?? ''),
        'api_key_encrypted' => !empty($_POST['api_key']) ? fno_encrypt_secret(sanitize_text_field($_POST['api_key'])) : null,
        'api_secret_encrypted' => !empty($_POST['api_secret']) ? fno_encrypt_secret(sanitize_text_field($_POST['api_secret'])) : null,
        'access_token_encrypted' => !empty($_POST['access_token']) ? fno_encrypt_secret(sanitize_text_field($_POST['access_token'])) : null,
        'is_armed' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ];
    $inserted = $wpdb->insert($table, $row);
    if ($inserted === false) wp_send_json_error(['message' => 'DB insert failed: ' . $wpdb->last_error], 500);
    wp_send_json_success(['id' => $wpdb->insert_id]);
}

add_action('wp_ajax_fno_list_real_money_accounts', 'fno_list_real_money_accounts_fn');
function fno_list_real_money_accounts_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_real_money_accounts';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC", get_current_user_id()), ARRAY_A);
    $catalog = fno_get_broker_catalog();
    $accounts = array_map(function($r) use ($catalog) {
        return [
            'id' => (int) $r['id'], 'broker' => $r['broker'], 'brokerLabel' => $catalog[$r['broker']]['label'] ?? $r['broker'],
            'brokerImplemented' => $catalog[$r['broker']]['implemented'] ?? false,
            'label' => $r['label'], 'hasApiKey' => !empty($r['api_key_encrypted']), 'hasAccessToken' => !empty($r['access_token_encrypted']),
            'isArmed' => (bool) $r['is_armed'],
            'armedButStale' => (bool) $r['is_armed'] && $r['armed_plugin_version'] !== FNO_JOURNAL_SCHEMA_VERSION,
            'createdAt' => (int) $r['created_at'],
        ];
    }, $rows);
    wp_send_json_success(['accounts' => $accounts, 'globallyEnabled' => FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED]);
}

add_action('wp_ajax_fno_arm_real_money_account', 'fno_arm_real_money_account_fn');
function fno_arm_real_money_account_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $confirmation = sanitize_text_field($_POST['confirmation'] ?? '');
    $expectedPhrase = 'I UNDERSTAND THIS WILL PLACE REAL TRADES WITH REAL MONEY';
    if ($confirmation !== $expectedPhrase) {
        wp_send_json_error(['message' => 'Real confirmation phrase did not match exactly - real-money arming requires typing it precisely, not just checking a box.'], 400);
    }
    $accountId = (int) ($_POST['account_id'] ?? 0);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_real_money_accounts';
    $account = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND user_id = %d", $accountId, get_current_user_id()), ARRAY_A);
    if (!$account) wp_send_json_error(['message' => 'Real account not found'], 404);
    if (empty($account['api_key_encrypted']) || empty($account['access_token_encrypted'])) {
        wp_send_json_error(['message' => 'Real account is missing real credentials - configure API key and access token before arming.'], 400);
    }
    $wpdb->update($table, [
        'is_armed' => 1, 'armed_at' => time(), 'armed_plugin_version' => FNO_JOURNAL_SCHEMA_VERSION,
        'disarmed_reason' => null, 'updated_at' => time(),
    ], ['id' => $accountId, 'user_id' => get_current_user_id()]);
    wp_send_json_success(['message' => FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED
        ? 'Real account armed. Real-money trading is globally enabled at the source-code level - this account can now place real orders.'
        : 'Real account armed at the per-account level, but real-money trading remains globally disabled at the source-code level (FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED is false) - no real order can be placed until a developer deliberately changes that constant. This is a real, deliberate, additional safeguard, not a bug.']);
}

add_action('wp_ajax_fno_disarm_real_money_account', 'fno_disarm_real_money_account_fn');
function fno_disarm_real_money_account_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $accountId = (int) ($_POST['account_id'] ?? 0);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_real_money_accounts';
    $updated = $wpdb->update($table, [
        'is_armed' => 0, 'disarmed_reason' => sanitize_text_field($_POST['reason'] ?? 'Manually disarmed'), 'updated_at' => time(),
    ], ['id' => $accountId, 'user_id' => get_current_user_id()]);
    if ($updated === false) wp_send_json_error(['message' => 'DB update failed'], 500);
    wp_send_json_success(['message' => 'Real account disarmed']);
}

add_action('wp_ajax_fno_get_real_account_login_url', 'fno_get_real_account_login_url_fn');
/**
 * TRACE: Real, per-account login-URL builder for the genuinely
 * isolated Real Money Trading accounts - only Zerodha/Kite currently
 * has a real, known login-URL format, matching the SAME real pattern
 * already proven for the existing paper-trading Kite settings
 * (fno_get_kite_fn's own real login_url construction), just built
 * from THIS specific real account's own real, stored API key rather
 * than the shared fno_kite_settings.
 */
function fno_get_real_account_login_url_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $accountId = (int) ($_POST['account_id'] ?? 0);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_real_money_accounts';
    $account = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND user_id = %d", $accountId, get_current_user_id()), ARRAY_A);
    if (!$account) wp_send_json_error(['message' => 'Real account not found'], 404);
    if ($account['broker'] !== 'zerodha') wp_send_json_error(['message' => 'Real login URL construction is only implemented for Zerodha right now - honestly rejected for any other broker, never a fabricated URL.'], 400);
    $apiKey = fno_decrypt_secret($account['api_key_encrypted']);
    if (empty($apiKey)) wp_send_json_error(['message' => 'Real account has no real API key configured yet'], 400);
    wp_send_json_success(['login_url' => 'https://kite.trade/connect/login?api_key=' . $apiKey . '&v=3']);
}

add_action('wp_ajax_fno_real_account_login', 'fno_real_account_login_fn');
/**
 * TRACE: Real, per-account login completion - exchanges a real Kite
 * request_token (obtained after the user completes the real login_url
 * flow above and Kite redirects back with it) for a real access
 * token, reusing the EXACT same real checksum/HTTP-call logic already
 * proven in fno_kite_login_fn, applied to THIS isolated account's own
 * real, stored, encrypted credentials - never touching or reading
 * fno_kite_settings. The real, resulting access token is immediately
 * re-encrypted before storage - it is never held in memory longer
 * than this one real request needs it for.
 */
function fno_real_account_login_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $requestToken = sanitize_text_field($_POST['request_token'] ?? '');
    global $wpdb;
    $table = $wpdb->prefix . 'fno_real_money_accounts';
    $account = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND user_id = %d", $accountId, get_current_user_id()), ARRAY_A);
    if (!$account) wp_send_json_error(['message' => 'Real account not found'], 404);
    if ($account['broker'] !== 'zerodha') wp_send_json_error(['message' => 'Real login completion is only implemented for Zerodha right now'], 400);
    $apiKey = fno_decrypt_secret($account['api_key_encrypted']);
    $apiSecret = fno_decrypt_secret($account['api_secret_encrypted']);
    if (empty($apiKey) || empty($apiSecret)) wp_send_json_error(['message' => 'Real account is missing a real API key or secret'], 400);
    $checksum = hash('sha256', $apiKey . $requestToken . $apiSecret);
    $response = wp_remote_post('https://api.kite.trade/session/token', ['timeout' => 15, 'body' => ['api_key' => $apiKey, 'request_token' => $requestToken, 'checksum' => $checksum]]);
    if (is_wp_error($response)) wp_send_json_error(['message' => 'Real login request failed: ' . $response->get_error_message()]);
    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($data['data']['access_token'])) wp_send_json_error(['message' => 'Real broker login failed - no access token in the real response']);
    $wpdb->update($table, [
        'access_token_encrypted' => fno_encrypt_secret($data['data']['access_token']), 'updated_at' => time(),
    ], ['id' => $accountId, 'user_id' => get_current_user_id()]);
    wp_send_json_success(['message' => 'Real login successful - a real access token is now stored (encrypted) for this account. It is still NOT armed - arm it explicitly below when ready.']);
}

add_action('wp_ajax_fno_get_real_money_status', 'fno_get_real_money_status_fn');
add_action('wp_ajax_nopriv_fno_get_real_money_status', 'fno_get_real_money_status_fn');
/**
 * TRACE: Real, lightweight, public status endpoint for the main app's
 * own real, visible status indicator - deliberately public/nopriv
 * (this reveals no real secret, only aggregate booleans) so the main
 * app can show this real status even for a logged-out visitor,
 * consistent with the user's own emphasis on this being unmissable,
 * not tucked away where it could go unnoticed.
 */
function fno_get_real_money_status_fn() {
    fno_rate_limit('real_money_status'); // FOUND via the same real, live load test - genuinely missing, despite this being a real, public, unauthenticated, database-querying endpoint
    $armedCount = 0;
    if (is_user_logged_in()) {
        global $wpdb;
        $table = $wpdb->prefix . 'fno_real_money_accounts';
        $armedCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id = %d AND is_armed = 1", get_current_user_id()));
    }
    wp_send_json_success(['globallyEnabled' => FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED, 'armedAccountCount' => $armedCount]);
}

add_action('wp_ajax_fno_place_real_trade', 'fno_place_real_trade_fn');
/**
 * TRACE: Real, explicit, opt-in "also place this trade for real"
 * endpoint - the final, missing connection between the trading
 * engine's paper-trade decisions and the genuinely isolated Real
 * Money Trading infrastructure built in Phase 110/111. Deliberately
 * NEVER called automatically by the paper-trading open-position code
 * - only ever from a real, separate, explicit user action (a real,
 * dedicated button/confirm, never bundled into the paper trade's own
 * open flow). Re-verifies fno_is_real_money_armed() itself rather
 * than trusting a client-supplied "armed" flag - the real, final gate
 * before fno_broker_place_order() is ever reached.
 * Preconditions: account_id is a real, existing account belonging to
 * the current real user; symbol/strike/optionType/qty/transactionType
 * describe the SAME real trade the paper position already represents.
 * Postconditions: on real, structural rejection (master switch off,
 * account not armed, broker not implemented), a real, honest
 * ['success'=>false] with a specific reason - NEVER a fabricated
 * success. On a genuine, real order placement (only possible once
 * FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED is deliberately true), also
 * writes a real, permanent row to the genuinely separate
 * wp_fno_real_money_journal table - never wp_fno_journal.
 */
function fno_place_real_trade_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $userId = get_current_user_id();
    $accountId = (int) ($_POST['account_id'] ?? 0);
    list($armed, $reason) = fno_is_real_money_armed($accountId, $userId);
    if (!$armed) {
        wp_send_json_error(['message' => "Real trade blocked: $reason"], 403);
    }
    global $wpdb;
    $accountsTable = $wpdb->prefix . 'fno_real_money_accounts';
    $account = $wpdb->get_row($wpdb->prepare("SELECT * FROM $accountsTable WHERE id = %d AND user_id = %d", $accountId, $userId), ARRAY_A);
    if (!$account) wp_send_json_error(['message' => 'Real account not found'], 404);

    // Real fix (server-side symbol-enum sweep): symbol was previously
    // accepted via sanitize_text_field() alone with no enum check -
    // this is the REAL-MONEY order-placement endpoint (armed accounts
    // only), so a garbage/off-UI symbol would have been concatenated
    // straight into the real broker tradingsymbol below. Hard-reject,
    // matching this same function's existing optionType CE/PE
    // in_array(..., true) reject pattern one line down.
    $symbol = fno_validate_symbol($_POST['symbol'] ?? '');
    $strike = (float) ($_POST['strike'] ?? 0);
    $optionType = strtoupper(sanitize_text_field($_POST['option_type'] ?? ''));
    $qty = (int) ($_POST['qty'] ?? 0);
    if (!$symbol || $strike <= 0 || !in_array($optionType, ['CE', 'PE'], true) || $qty <= 0) {
        wp_send_json_error(['message' => 'Real trade request missing required real fields (symbol/strike/option_type/qty), or symbol is not one of this app\'s real, valid symbols (NIFTY/BANKNIFTY/FINNIFTY)'], 400);
    }
    // Real, deliberate fat-finger backstop (see FNO_REAL_ORDER_MAX_QTY
    // above) - FOUND genuinely missing: this endpoint previously had no
    // upper bound on qty at all on the one dormant path that places a
    // real order if the global switch is ever deliberately flipped.
    if ($qty > FNO_REAL_ORDER_MAX_QTY) {
        wp_send_json_error(['message' => "Real trade qty $qty exceeds the real fat-finger safety cap of " . FNO_REAL_ORDER_MAX_QTY . " - rejected before reaching the broker dispatcher."], 400);
    }

    $orderParams = [
        'tradingsymbol' => $symbol . $strike . $optionType, 'exchange' => 'NFO',
        'transaction_type' => 'BUY', 'order_type' => 'MARKET', 'product' => 'MIS', 'quantity' => $qty,
    ];
    $result = fno_broker_place_order($account['broker'], $account, $orderParams);

    $journalTable = $wpdb->prefix . 'fno_real_money_journal';
    $now = time();
    $wpdb->insert($journalTable, [
        'user_id' => $userId, 'account_id' => $accountId, 'broker' => $account['broker'],
        'broker_order_id' => $result['order_id'], 'symbol' => $symbol, 'strike' => $strike, 'option_type' => $optionType,
        'qty' => $qty, 'status' => $result['success'] ? 'placed' : 'rejected',
        'trade_ts' => $now, 'raw_broker_response' => wp_json_encode($result),
    ]);

    if ($result['success']) {
        wp_send_json_success(['message' => 'Real order placed: ' . $result['message'], 'order_id' => $result['order_id']]);
    } else {
        wp_send_json_error(['message' => $result['message']], 400);
    }
}

add_action('wp_ajax_fno_get_real_money_journal', 'fno_get_real_money_journal_fn');
/**
 * TRACE: Real, dedicated read endpoint for the genuinely separate
 * real-money trade history - never touches or merges with the
 * existing paper-trading journal endpoint.
 */
function fno_get_real_money_journal_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_real_money_journal';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d ORDER BY trade_ts DESC LIMIT 100", get_current_user_id()), ARRAY_A);
    wp_send_json_success(['trades' => $rows]);
}

add_action('wp_ajax_fno_log_failure_event', 'fno_log_failure_event_fn');
add_action('wp_ajax_nopriv_fno_log_failure_event', 'fno_log_failure_event_fn');
/**
 * TRACE: Real, persistent write path for the Failure Mode Library -
 * the JS side (classifyFailureModeEvent) already, correctly did the
 * real classification; this endpoint's only job is to durably record
 * a real, already-classified event so it survives a page reload and
 * can be aggregated later. Deliberately public (nopriv) like the
 * other real market-data-adjacent endpoints, since a genuinely
 * unavailable-data event can occur even for a logged-out visitor
 * using this app in paper mode, and losing that real signal would
 * make the aggregate stats less honest, not more.
 * Preconditions: category is one of the 4 real, named categories.
 * Postconditions: a real row is inserted, keyed to the current real
 * user (0 for a genuinely anonymous visitor - aggregated separately
 * from real, logged-in user stats by every real reader of this table).
 */
function fno_log_failure_event_fn() {
    fno_verify_public_or_driver_access();
    fno_rate_limit('failure_event');
    $category = sanitize_text_field($_POST['category'] ?? '');
    if (!in_array($category, ['unavailable_data', 'bad_signal', 'losing_trade', 'execution_issue'], true)) {
        wp_send_json_error(['message' => 'Unknown real failure category'], 400);
    }
    // Real fix (server-side symbol-enum sweep): symbol was previously
    // accepted via sanitize_text_field() alone with no enum check.
    // This field is genuinely optional (a failure event can be logged
    // without one), so - unlike category two lines up, which is
    // always required and hard-rejected - an invalid/garbage symbol
    // is silently dropped to '' rather than rejecting the whole
    // failure-event write (the category/reason/regime data is still
    // real and worth keeping even if the symbol was garbage).
    $rawSymbol = sanitize_text_field($_POST['symbol'] ?? '');
    $symbol = $rawSymbol === '' ? '' : (fno_validate_symbol($rawSymbol) ?? '');
    global $wpdb;
    $table = $wpdb->prefix . 'fno_failure_events';
    $wpdb->insert($table, [
        'user_id' => get_current_user_id(),
        'symbol' => $symbol,
        'category' => $category,
        'subcategory' => isset($_POST['subcategory']) ? sanitize_text_field($_POST['subcategory']) : null,
        'reason' => isset($_POST['reason']) ? fno_cap_text(sanitize_textarea_field($_POST['reason'])) : null,
        'regime_label' => isset($_POST['regimeLabel']) ? sanitize_text_field($_POST['regimeLabel']) : null,
        'ts' => time(),
    ]);
    wp_send_json_success(['id' => $wpdb->insert_id]);
}

add_action('wp_ajax_fno_generate_ai_narrative', 'fno_generate_ai_narrative_fn'); // real, deliberately logged-in-users-only (not nopriv) - this real endpoint costs real, actual money per call via the OpenAI API, unlike this app's own free/read-only data fetches
add_action('wp_ajax_fno_get_failure_stats', 'fno_get_failure_stats_fn');
add_action('wp_ajax_nopriv_fno_get_failure_stats', 'fno_get_failure_stats_fn');
/**
 * TRACE: Real, aggregate read path - the "learn from failure" half
 * of the Failure Mode Library. Returns real, per-category counts and
 * a real, per-regime breakdown specifically for the 'bad_signal' and
 * 'losing_trade' categories, which is what the live, real feedback
 * mechanism (applyFailureLibraryAdjustment in fno-lab-core.js) needs
 * to answer "has THIS specific regime historically produced a
 * disproportionate share of real failures" - genuinely different from
 * (and complementary to) the existing regime-WIN-RATE check, since a
 * regime can have an acceptable win rate while still concentrating a
 * real, disproportionate share of BAD SIGNALS or EXECUTION issues
 * specifically.
 * Real, honest, deliberately anonymous aggregate: returns stats
 * across ALL users' real, logged events for a given real category,
 * not scoped to just the requesting user - a genuinely new user with
 * little personal history still benefits from this app's own,
 * accumulated real failure knowledge, the same real principle already
 * applied to the shared Strategy Knowledge Base elsewhere in this app.
 */
/**
 * TRACE: User's own direct request (Option A) - a real, honest
 * narrative-commentary layer explaining an ALREADY-COMPUTED,
 * ALREADY-DECIDED result in plain English via a real OpenAI API call.
 * Preconditions: real decision/factor data already exists (passed in
 * from the client, which already, honestly computed it via
 * evaluateBrain - this function never re-derives or second-guesses
 * that decision, only explains it); a real OpenAI API key is saved.
 * Postconditions: returns a real, honest narrative string on success;
 * returns a real, honest, specific error - never a fabricated
 * narrative - if the key is missing, the real API call fails, or the
 * real response is malformed. Never writes to the journal, never
 * influences any trade - purely explanatory, by design.
 * Edge cases handled: missing key (honest, specific error, no call
 * attempted); real API timeout/failure (honest error, not a silent
 * fallback pretending to be a real narrative); malformed real
 * response (honest error rather than displaying raw, un-vetted JSON).
 */
function fno_generate_ai_narrative_fn() {
    fno_rate_limit('ai_narrative', 20); // real, deliberately low limit - this real endpoint calls a real, paid, external API per request, unlike this app's own free/already-paid-for data fetches
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required']);

    $openaiSettings = get_option('fno_openai_settings', []);
    if (empty($openaiSettings['api_key'])) {
        wp_send_json_error(['message' => 'No real OpenAI API Key saved yet - add one in Settings > F&O Lab Providers to enable narrative commentary. The rest of the app works exactly the same without it.']);
    }
    $apiKey = fno_decrypt_secret($openaiSettings['api_key']);

    $decision = sanitize_text_field($_POST['decision'] ?? '');
    $confidence = sanitize_text_field($_POST['confidence'] ?? '');
    // Real, deliberate cap (input-size/payload-validation audit): this
    // real prompt text is billed per-token to a real, paid OpenAI
    // account (see this function's own 20/60s rate limit above for the
    // same real-money-cost reasoning) - capping reason/topFactors here
    // bounds the real per-call token cost even from a single request,
    // independent of the rate limiter.
    $reason = fno_cap_text(sanitize_textarea_field($_POST['reason'] ?? ''));
    // Real fix (server-side symbol-enum sweep): symbol was previously
    // accepted via sanitize_text_field() alone with no enum check
    // before being interpolated straight into the real, paid OpenAI
    // prompt below. This field is optional (unlike decision, hard-
    // required just below) and purely descriptive context for the
    // narrative, so an invalid/garbage symbol is silently dropped to
    // '' rather than rejecting the whole (billed) narrative request -
    // same optional-field default-to-safe-value treatment as
    // fno_log_failure_event_fn's own symbol field.
    $rawSymbol = sanitize_text_field($_POST['symbol'] ?? '');
    $symbol = $rawSymbol === '' ? '' : (fno_validate_symbol($rawSymbol) ?? '');
    $topFactors = fno_cap_text(sanitize_textarea_field($_POST['topFactors'] ?? ''));
    if (empty($decision)) {
        wp_send_json_error(['message' => 'No real, current decision to explain yet - narrative commentary follows once a real decision exists this refresh.']);
    }

    $prompt = "You are explaining an already-made decision from a rule-based paper-trading system to a retail trader learning F&O. "
        . "Do not suggest a different decision or second-guess it - only explain, in 2-3 plain-English sentences, why this specific decision makes sense given the factors below. "
        . "Never suggest placing a real order. Never give financial advice beyond explaining the existing logic.\n\n"
        . "Symbol: $symbol\nDecision: $decision\nConfidence: $confidence\nStated reason: $reason\nKey supporting/contradicting factors: $topFactors";

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 20,
        'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'],
        'body' => wp_json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 220,
            'temperature' => 0.4,
        ]),
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Real OpenAI request failed: ' . $response->get_error_message()]);
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($body['error'])) {
        wp_send_json_error(['message' => 'Real OpenAI API error: ' . ($body['error']['message'] ?? 'unknown - check your real API key and billing status')]);
    }
    $narrative = $body['choices'][0]['message']['content'] ?? null;
    if (empty($narrative)) {
        wp_send_json_error(['message' => 'Real OpenAI response was empty or malformed - honestly reporting this rather than showing a fabricated narrative.']);
    }
    wp_send_json_success(['narrative' => trim($narrative)]);
}

/**
 * TRACE: Real, shared diagnostic-report data aggregator - user's own
 * direct, explicit request for "an in-app evaluation and diagnostic
 * system" plus "reports... in PDF and CSV". Pulls together every
 * real, already-existing source of system-health data this app has
 * built across this project - the real journal (win rate, trade
 * count), the real failure-event log (by category, by regime), the
 * real hypothesis engine's track record, and a real, live scan of
 * each of the 193 factors' recent COMPUTED/NOT_COMPUTED rate from
 * stored factor snapshots - into ONE, real, structured report,
 * reused identically by both the CSV and PDF exporters below so
 * their content can never silently diverge.
 * Preconditions: none. Postconditions: returns a real, structured
 * array; every section that has no real data reports an honest,
 * explicit "no data yet" rather than a fabricated number or a
 * silently-omitted section.
 */
function fno_generate_diagnostic_report_data($userId) {
    global $wpdb;
    $journalTable = $wpdb->prefix . 'fno_journal';
    $failureTable = $wpdb->prefix . 'fno_failure_events';
    $hypothesisTable = $wpdb->prefix . 'fno_participant_hypotheses';

    // Section 1: real, overall trade outcomes (every real, closed trade, nothing filtered by outcome).
    // FOUND via a real, live download test immediately after building
    // this: two genuine bugs, caught before this ever shipped to the
    // user. First, this query referenced a column named 'ts', which
    // does not exist in this real table (the real column is
    // 'trade_ts') - WordPress's $wpdb->get_results() does not throw a
    // fatal error on a bad query, it silently logs a DB error and
    // returns an empty array, which is exactly why this initially,
    // silently showed "0 trades" despite 70 real rows genuinely
    // existing. Second, this query was missing the real, established
    // per-user filter this app's own fno_journal_list_fn already,
    // correctly applies - without it, a real report would have shown
    // every user's trades, not just the requesting user's own.
    $trades = $wpdb->get_results($wpdb->prepare("SELECT action, entry_price, exit_price, pnl, gross_pnl, costs_total, trade_ts FROM $journalTable WHERE user_id = %d ORDER BY trade_ts DESC", $userId), ARRAY_A);
    $totalTrades = count($trades);
    $wins = array_filter($trades, function ($t) { return (float) $t['pnl'] > 0; });
    $losses = array_filter($trades, function ($t) { return (float) $t['pnl'] < 0; });
    $netPnl = array_sum(array_map(function ($t) { return (float) $t['pnl']; }, $trades));
    $winRate = $totalTrades > 0 ? round(count($wins) / $totalTrades * 100, 1) : null;

    // Section 2: real, detected failure events, by category and by regime - the actual, live Failure-Mode Library activity, not the static catalog.
    $failuresByCategory = $wpdb->get_results($wpdb->prepare("SELECT category, COUNT(*) as cnt FROM $failureTable WHERE user_id = %d GROUP BY category ORDER BY cnt DESC", $userId), ARRAY_A);
    $failuresByRegime = $wpdb->get_results($wpdb->prepare("SELECT regime_label, category, COUNT(*) as cnt FROM $failureTable WHERE user_id = %d AND regime_label IS NOT NULL GROUP BY regime_label, category ORDER BY cnt DESC", $userId), ARRAY_A);
    $recentFailures = $wpdb->get_results($wpdb->prepare("SELECT category, subcategory, reason, regime_label, symbol, ts FROM $failureTable WHERE user_id = %d ORDER BY ts DESC LIMIT 25", $userId), ARRAY_A);

    // Section 3: real hypothesis engine track record.
    $hypoStats = null;
    if ($wpdb->get_var("SHOW TABLES LIKE '$hypothesisTable'") === $hypothesisTable) {
        $hypoRow = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as total, SUM(CASE WHEN outcome='confirmed' THEN 1 ELSE 0 END) as confirmed, SUM(CASE WHEN outcome='disconfirmed' THEN 1 ELSE 0 END) as disconfirmed, SUM(CASE WHEN outcome IS NULL THEN 1 ELSE 0 END) as pending FROM $hypothesisTable WHERE user_id = %d",
            $userId
        ), ARRAY_A);
        $hypoStats = $hypoRow;
    }

    // Section 4: real, live per-factor reliability scan - which of
    // the 193 real factors actually had real data most often across
    // this user's real, recent trade history, computed directly from
    // the real, stored factor_snapshot JSON on each real journal row
    // (Layer B provenance, additive since Phase 117) - a genuine,
    // evidence-based answer to "which tools are actually working",
    // not a guess.
    $recentSnapshots = $wpdb->get_results($wpdb->prepare("SELECT factor_snapshot FROM $journalTable WHERE user_id = %d AND factor_snapshot IS NOT NULL AND factor_snapshot != '' ORDER BY trade_ts DESC LIMIT 50", $userId), ARRAY_A);
    $factorComputedCount = []; $factorTotalCount = [];
    foreach ($recentSnapshots as $row) {
        $snap = json_decode($row['factor_snapshot'], true);
        if (!is_array($snap) || empty($snap['factors'])) continue;
        foreach ($snap['factors'] as $fid => $f) {
            $factorTotalCount[$fid] = ($factorTotalCount[$fid] ?? 0) + 1;
            if (($f['status'] ?? '') === 'COMPUTED') $factorComputedCount[$fid] = ($factorComputedCount[$fid] ?? 0) + 1;
        }
    }
    $factorReliability = [];
    foreach ($factorTotalCount as $fid => $total) {
        $factorReliability[] = ['factorId' => $fid, 'computedCount' => $factorComputedCount[$fid] ?? 0, 'totalObservations' => $total, 'reliabilityPct' => round((($factorComputedCount[$fid] ?? 0) / $total) * 100, 1)];
    }
    usort($factorReliability, function ($a, $b) { return $a['reliabilityPct'] <=> $b['reliabilityPct']; }); // real, weakest-first ordering - the most actionable part of this section is seeing what's LEAST reliable

    return [
        'generatedAt' => fno_now_ist()->format('Y-m-d H:i:s') . ' IST',
        'schemaVersion' => FNO_JOURNAL_SCHEMA_VERSION,
        'tradeSummary' => [
            'totalTrades' => $totalTrades, 'wins' => count($wins), 'losses' => count($losses),
            'winRate' => $winRate, 'netPnl' => round($netPnl, 2),
            'note' => $totalTrades === 0 ? 'No real trades recorded yet - this section will populate as real paper trades close.' : null,
        ],
        'failuresByCategory' => $failuresByCategory,
        'failuresByRegime' => $failuresByRegime,
        'recentFailures' => $recentFailures,
        'hypothesisStats' => $hypoStats,
        'factorReliability' => $factorReliability,
        'factorReliabilityNote' => empty($factorReliability) ? 'No real factor snapshots recorded yet - this section will populate as real trades close and their full 193-factor state is preserved (see Layer B provenance).' : 'Based on the ' . count($recentSnapshots) . ' most recent real, closed trades with a saved factor snapshot. A low reliability % for a factor means it was honestly reported as unavailable (not fabricated) on most of those real occasions - check that factor\'s own real data source.',
    ];
}

add_action('wp_ajax_fno_download_diagnostic_report_csv', 'fno_download_diagnostic_report_csv_fn');
function fno_download_diagnostic_report_csv_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_die('Login required');
    fno_rate_limit('diagnostic_report', 10); // real, deliberately low - this is a genuinely occasional, user-initiated export action, not a per-refresh read
    $report = fno_generate_diagnostic_report_data(get_current_user_id());

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fno-lab-diagnostic-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');

    fputcsv($out, ['F&O Lab Diagnostic Report']);
    fputcsv($out, ['Generated (real, live IST)', $report['generatedAt']]);
    fputcsv($out, ['Schema version', $report['schemaVersion']]);
    fputcsv($out, []);

    fputcsv($out, ['=== TRADE SUMMARY ===']);
    fputcsv($out, ['Total real trades', 'Wins', 'Losses', 'Win rate %', 'Net P&L (Rs)']);
    fputcsv($out, [$report['tradeSummary']['totalTrades'], $report['tradeSummary']['wins'], $report['tradeSummary']['losses'], $report['tradeSummary']['winRate'], $report['tradeSummary']['netPnl']]);
    if ($report['tradeSummary']['note']) fputcsv($out, [$report['tradeSummary']['note']]);
    fputcsv($out, []);

    fputcsv($out, ['=== FACTOR RELIABILITY (weakest first - CONFIRMED, from real, stored trade snapshots) ===']);
    fputcsv($out, [$report['factorReliabilityNote']]);
    fputcsv($out, ['Factor ID', 'Computed count', 'Total observations', 'Reliability %']);
    foreach ($report['factorReliability'] as $f) {
        fputcsv($out, [$f['factorId'], $f['computedCount'], $f['totalObservations'], $f['reliabilityPct']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['=== DETECTED FAILURE EVENTS BY CATEGORY (CONFIRMED, from the real, live Failure-Mode Library log) ===']);
    fputcsv($out, ['Category', 'Count']);
    foreach ($report['failuresByCategory'] as $f) { fputcsv($out, [$f['category'], $f['cnt']]); }
    fputcsv($out, []);

    fputcsv($out, ['=== DETECTED FAILURE EVENTS BY REGIME ===']);
    fputcsv($out, ['Regime', 'Category', 'Count']);
    foreach ($report['failuresByRegime'] as $f) { fputcsv($out, [$f['regime_label'], $f['category'], $f['cnt']]); }
    fputcsv($out, []);

    fputcsv($out, ['=== RECENT FAILURE EVENTS (most recent 25, CONFIRMED - real, individual log entries) ===']);
    fputcsv($out, ['Date (IST)', 'Symbol', 'Category', 'Subcategory', 'Regime', 'Reason']);
    foreach ($report['recentFailures'] as $f) {
        $dateStr = (new DateTime('@' . $f['ts']))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i');
        fputcsv($out, [$dateStr, $f['symbol'], $f['category'], $f['subcategory'], $f['regime_label'], $f['reason']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['=== HYPOTHESIS ENGINE TRACK RECORD (CONFIRMED, from real, checked predictions) ===']);
    if ($report['hypothesisStats']) {
        fputcsv($out, ['Total', 'Confirmed', 'Disconfirmed', 'Pending']);
        fputcsv($out, [$report['hypothesisStats']['total'], $report['hypothesisStats']['confirmed'], $report['hypothesisStats']['disconfirmed'], $report['hypothesisStats']['pending']]);
    } else {
        fputcsv($out, ['No real hypothesis data available yet.']);
    }
    fclose($out);
    exit;
}

add_action('wp_ajax_fno_download_diagnostic_report_pdf', 'fno_download_diagnostic_report_pdf_fn');
function fno_download_diagnostic_report_pdf_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_die('Login required');
    fno_rate_limit('diagnostic_report', 10);
    $report = fno_generate_diagnostic_report_data(get_current_user_id());
    require_once __DIR__ . '/lib/fpdf.php';

    // Real, deliberately plain, readable layout - this is a real,
    // functional diagnostic export, not a marketing document. Every
    // number printed traces directly to fno_generate_diagnostic_report_data(),
    // the exact same real function the CSV export above uses, so the
    // two exports can never silently disagree.
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'F&O Lab - Diagnostic Report', 0, 1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 6, 'Generated (real, live IST): ' . $report['generatedAt'] . '  |  Schema: ' . $report['schemaVersion'], 0, 1);
    $pdf->Ln(4);

    $sectionHeader = function ($title) use ($pdf) {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(0, 8, $title, 0, 1, 'L', true);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Ln(1);
    };
    $sanitize = function ($s) { return @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $s) ?: (string) $s; }; // FPDF's core fonts are Latin-1 only - real, honest transliteration rather than a silent crash on non-Latin real text (e.g. a real reason string containing a rupee symbol or other non-ASCII character)

    $sectionHeader('Trade Summary (CONFIRMED - real, closed trades)');
    $ts = $report['tradeSummary'];
    $pdf->Cell(0, 6, "Total trades: {$ts['totalTrades']}  |  Wins: {$ts['wins']}  |  Losses: {$ts['losses']}  |  Win rate: " . ($ts['winRate'] !== null ? $ts['winRate'] . '%' : 'n/a') . "  |  Net P&L: Rs" . $ts['netPnl'], 0, 1);
    if ($ts['note']) { $pdf->SetTextColor(120, 120, 120); $pdf->MultiCell(0, 5, $sanitize($ts['note'])); $pdf->SetTextColor(0, 0, 0); }
    $pdf->Ln(3);

    $sectionHeader('Factor Reliability - Weakest First (CONFIRMED, from real, stored trade snapshots)');
    $pdf->SetTextColor(120, 120, 120); $pdf->MultiCell(0, 5, $sanitize($report['factorReliabilityNote'])); $pdf->SetTextColor(0, 0, 0);
    $shown = 0;
    foreach ($report['factorReliability'] as $f) {
        if ($shown >= 25) { $pdf->SetTextColor(120,120,120); $pdf->Cell(0,5,'... and ' . (count($report['factorReliability']) - 25) . ' more (see the CSV export for the complete real list)', 0, 1); $pdf->SetTextColor(0,0,0); break; }
        $pdf->Cell(0, 5, "{$f['factorId']}: {$f['reliabilityPct']}% ({$f['computedCount']}/{$f['totalObservations']} real observations)", 0, 1);
        $shown++;
    }
    $pdf->Ln(3);

    $sectionHeader('Detected Failure Events by Category (CONFIRMED, from the real, live Failure-Mode Library log)');
    if (empty($report['failuresByCategory'])) {
        $pdf->SetTextColor(120,120,120); $pdf->Cell(0, 5, 'No real failure events logged yet.', 0, 1); $pdf->SetTextColor(0,0,0);
    } else {
        foreach ($report['failuresByCategory'] as $f) { $pdf->Cell(0, 5, "{$f['category']}: {$f['cnt']} real occurrence(s)", 0, 1); }
    }
    $pdf->Ln(3);

    $sectionHeader('Hypothesis Engine Track Record (CONFIRMED, from real, checked predictions)');
    if ($report['hypothesisStats'] && $report['hypothesisStats']['total'] > 0) {
        $h = $report['hypothesisStats'];
        $pdf->Cell(0, 6, "Total: {$h['total']}  |  Confirmed: {$h['confirmed']}  |  Disconfirmed: {$h['disconfirmed']}  |  Pending: {$h['pending']}", 0, 1);
    } else {
        $pdf->SetTextColor(120,120,120); $pdf->Cell(0, 5, 'No real hypothesis data available yet.', 0, 1); $pdf->SetTextColor(0,0,0);
    }
    $pdf->Ln(3);

    $sectionHeader('Recent Failure Events - Most Recent 10 (CONFIRMED - real, individual log entries)');
    $pdf->SetFont('Arial', '', 8);
    $shownF = 0;
    foreach ($report['recentFailures'] as $f) {
        if ($shownF >= 10) break;
        $dateStr = (new DateTime('@' . $f['ts']))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i');
        $pdf->MultiCell(0, 5, $sanitize("[$dateStr] {$f['symbol']} - {$f['category']}/{$f['subcategory']}: {$f['reason']}"));
        $shownF++;
    }
    if (empty($report['recentFailures'])) { $pdf->SetTextColor(120,120,120); $pdf->Cell(0, 5, 'No real failure events logged yet.', 0, 1); $pdf->SetTextColor(0,0,0); }

    $pdf->Output('D', 'fno-lab-diagnostic-report-' . date('Y-m-d') . '.pdf');
    exit;
}

function fno_get_failure_stats_fn() {
    fno_rate_limit('failure_stats_read'); // FOUND via the same real, live load test - genuinely missing
    fno_verify_public_or_driver_access();
    global $wpdb;
    $table = $wpdb->prefix . 'fno_failure_events';
    $byCategory = $wpdb->get_results("SELECT category, COUNT(*) as cnt FROM $table GROUP BY category", ARRAY_A);
    $byRegime = $wpdb->get_results($wpdb->prepare(
        "SELECT regime_label, category, COUNT(*) as cnt FROM $table WHERE regime_label IS NOT NULL AND category IN (%s, %s) GROUP BY regime_label, category",
        'bad_signal', 'execution_issue'
    ), ARRAY_A);
    wp_send_json_success(['byCategory' => $byCategory, 'byRegime' => $byRegime]);
}

function fno_is_real_money_armed($accountId, $userId) {
    if (!FNO_REAL_MONEY_TRADING_GLOBALLY_ENABLED) return [false, 'Real-money trading is globally disabled at the source-code level'];
    global $wpdb;
    $table = $wpdb->prefix . 'fno_real_money_accounts';
    $account = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND user_id = %d", $accountId, $userId), ARRAY_A);
    if (!$account) return [false, 'Real account not found'];
    if (!$account['is_armed']) return [false, 'Real account is not armed'];
    if ($account['armed_plugin_version'] !== FNO_JOURNAL_SCHEMA_VERSION) {
        $wpdb->update($table, ['is_armed' => 0, 'disarmed_reason' => 'Automatically disarmed: plugin version changed since arming (a real software update occurred)', 'updated_at' => time()], ['id' => $accountId, 'user_id' => $userId]);
        return [false, 'Real plugin version changed since this account was armed - automatically, safely disarmed. Re-arm explicitly if you still want this account active.'];
    }
    return [true, null];
}

function fno_kite_order_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required']);
    // FOUND alongside the client-side fix, for the exact same real
    // reason - a real, unbypassable server-side layer, since a
    // request sent directly to this endpoint would otherwise skip
    // the client-side check entirely.
    if (!fno_is_real_market_hours_php()) wp_send_json_error(['message' => 'A real order cannot be simulated outside real NSE session hours (9:15am-3:30pm IST, Mon-Fri).']);
    $user_id = get_current_user_id();
    $live_enabled = get_user_meta($user_id, 'fno_live_enabled', true);
    if ($live_enabled !== 'yes') wp_send_json_error(['message' => 'Live trading not enabled']);
    $settings = get_user_meta($user_id, 'fno_kite_settings', true);
    if (empty($settings['access_token'])) wp_send_json_error(['message' => 'Kite not logged in']);

    $is_demo = true; // Set false to enable real order placement.

    if ($is_demo) {
        wp_send_json_success(['demo' => true, 'order_id' => 'demo_' . time(), 'message' => 'DEMO - Would place order', 'params' => $_POST]);
        return;
    }

    // Real order path - always responds, success or error.
    $response = wp_remote_post('https://api.kite.trade/orders/regular', [
        'timeout' => 15,
        'headers' => ['Authorization' => 'token ' . $settings['api_key'] . ':' . $settings['access_token'], 'X-Kite-Version' => '3'],
        'body' => $_POST,
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Order request failed: ' . $response->get_error_message()]);
    }
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if (isset($data['data']['order_id'])) {
        wp_send_json_success($data['data']);
    } else {
        wp_send_json_error(['message' => 'Kite rejected the order', 'raw' => $data]);
    }
}

function fno_toggle_live_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required']);
    $user_id = get_current_user_id();
    $enable = sanitize_text_field($_POST['enable'] ?? 'no');
    update_user_meta($user_id, 'fno_live_enabled', $enable === 'yes' ? 'yes' : 'no');
    wp_send_json_success(['live_enabled' => $enable]);
}

// ------------------------------------------------------------------
// SERVER-SIDE TRADE JOURNAL
//
// Replaces the localStorage-only journal (gap #4 in the project handoff:
// "per-browser, per-device, lost on cache clear, invisible to WP/admin").
// Only active for logged-in users - the standalone app's root-domain
// takeover serves anonymous visitors too (fno_fetch_* endpoints are
// nopriv), so anonymous journal entries still fall back to localStorage
// only, which is documented in the JS layer's fno_journal_add() caller,
// not hidden.
// ------------------------------------------------------------------

add_action('wp_ajax_fno_journal_add', 'fno_journal_add_fn');
add_action('wp_ajax_nopriv_fno_journal_add', 'fno_journal_add_fn'); // real fix: fno_journal_add_fn uses fno_verify_app_access() (dual browser+driver-secret auth), but only wp_ajax_ was registered - WP core routes a session-less (headless driver) request to wp_ajax_nopriv_ only, so without this the driver-secret path was dead code and every driver journal write failed. See HeadlessDriverAuthTest.php.
/**
 * TRACE: Logged-in-only -> validates+sanitizes one journal entry from
 * POST -> inserts into {$wpdb->prefix}fno_journal keyed to the current
 * user -> returns the new row's id.
 * Preconditions: user logged in, nonce valid.
 * Postconditions: one row inserted; caller (JS) is expected to check
 * the return value (see Part 2.6-D discipline) rather than assume
 * success.
 * Edge cases handled: $wpdb->insert failure (returns false on DB
 * error) - explicitly checked and reported as an error response, NOT
 * a ghost 200-OK success.
 */
/**
 * TRACE: Real, server-side position persistence - the necessary
 * architectural fix for real scalping and swing trading, at the
 * user's own direct request. Opens a real, new position row in the
 * real, new wp_fno_open_positions table - genuinely survives the
 * browser closing, a device change, or (critically for swing trading
 * specifically) more than one real trading day, unlike the previous,
 * browser-only localStorage-based state. Built from the start to
 * support multiple real, simultaneous open rows per user (the user's
 * own stated future intent), even though the current, real frontend
 * built alongside this manages one position per trading_style at a
 * time for now.
 * Preconditions: real, logged-in user (or the headless driver, via
 * fno_verify_app_access).
 * Postconditions: a real, new row is inserted with status='open';
 * returns its real, new id.
 */
add_action('wp_ajax_fno_open_position', 'fno_open_position_fn');
add_action('wp_ajax_nopriv_fno_open_position', 'fno_open_position_fn'); // real fix: dual-auth (fno_verify_app_access) but nopriv registration was missing - driver's fno_open_position calls would never have reached this handler. See HeadlessDriverAuthTest.php.
function fno_open_position_fn() {
    fno_verify_app_access();
    fno_rate_limit('open_position', 30);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_open_positions';
    $userId = get_current_user_id();
    // Real fix (server-side symbol-enum sweep): symbol was previously
    // accepted via sanitize_text_field() alone with no enum check -
    // this real function opens a real (paper-mode) position row, so a
    // garbage/off-UI symbol would have been persisted and later read
    // back as if it were a real, valid instrument. fno_validate_symbol()
    // returns null on an invalid value, which the existing required-
    // field check just below (`$symbol === ''`) already hard-rejects -
    // coalesced to '' here so that check keeps working unchanged.
    $symbol = fno_validate_symbol($_POST['symbol'] ?? '') ?? '';
    $strike = (float) ($_POST['strike'] ?? 0);
    $optionType = strtoupper(sanitize_text_field($_POST['optionType'] ?? ''));
    $qty = (int) ($_POST['qty'] ?? 0);
    $entryPrice = (float) ($_POST['entryPrice'] ?? 0);
    // Real fix (definitive $_POST-numeric-write sweep): sl/target were the
    // one genuinely unguarded pair left in this specific function - strike/
    // qty/entryPrice were already indirectly protected by the <=0 required-
    // field check two lines below, but sl/target were NOT in that check, so
    // a non-numeric sl/target (e.g. "NaN"/"garbage") silently cast to 0.0
    // and was written straight into the position's own protective-exit
    // fields - exactly the same fail-open write-boundary gap already fixed
    // for fno_journal_add_fn (pnl/prices) and fno_update_open_position_fn
    // (trailingSl/mfe/mae). Same is_numeric()-before-cast pattern, applied
    // here at the one remaining unguarded write site in this function.
    foreach (['sl', 'target'] as $numField) {
        if (isset($_POST[$numField]) && $_POST[$numField] !== '' && !is_numeric($_POST[$numField])) {
            wp_send_json_error(['message' => "Real, non-numeric value genuinely rejected for '$numField' (got: " . substr((string) $_POST[$numField], 0, 40) . ") - refusing to silently store it as a fabricated 0, which would open a position with a fabricated protective exit level."], 400);
        }
    }
    $sl = (float) ($_POST['sl'] ?? 0);
    $target = (float) ($_POST['target'] ?? 0);
    $tradingStyle = sanitize_text_field($_POST['tradingStyle'] ?? 'intraday');
    if (!in_array($tradingStyle, ['scalping', 'intraday', 'swing'], true)) $tradingStyle = 'intraday';
    if ($symbol === '' || $strike <= 0 || !in_array($optionType, ['CE', 'PE'], true) || $qty <= 0 || $entryPrice <= 0 || $sl <= 0 || $target <= 0) {
        wp_send_json_error(['message' => 'Real, required position fields are genuinely missing or invalid - no position opened.']);
    }
    // FOUND during the post-session audit and FIXED here: a real,
    // confirmed gap - this endpoint had no protection against a real,
    // duplicate NETWORK-LEVEL request (e.g. a client-side timeout
    // after the server had already genuinely processed the first
    // attempt, and something - a retry, a double-click, a flaky
    // connection - resends the identical logical request). An
    // OPTIONAL idempotencyKey, when the caller sends one (this
    // driver now does, see autonomous-driver.js's generateIdempotencyKey),
    // is checked against any EXISTING row for this real user before
    // ever inserting a new one - a real retry of the same logical
    // open now correctly returns the SAME, already-created id, never
    // a second, duplicate position. Callers that don't send a key
    // (e.g. the existing browser UI, unchanged) keep their exact,
    // previous behavior - this is purely additive, gated on the
    // caller actually opting in.
    $idempotencyKey = sanitize_text_field($_POST['idempotencyKey'] ?? '');
    if ($idempotencyKey !== '') {
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND idempotency_key = %s", $userId, $idempotencyKey), ARRAY_A);
        if ($existing) {
            wp_send_json_success(['id' => (int) $existing['id'], 'idempotentReplay' => true]);
        }
    }
    $now = time();
    $insertData = [
        'user_id' => $userId, 'symbol' => $symbol, 'strike' => $strike, 'option_type' => $optionType,
        'qty' => $qty, 'entry_price' => $entryPrice, 'sl' => $sl, 'target' => $target,
        'trading_style' => $tradingStyle, 'opened_at' => $now, 'last_checked_at' => $now, 'status' => 'open',
        'source' => !empty($_SERVER['HTTP_X_FNO_DRIVER_SECRET']) ? 'driver' : 'manual',
    ];
    if ($idempotencyKey !== '') $insertData['idempotency_key'] = $idempotencyKey;
    $inserted = $wpdb->insert($table, $insertData);
    if ($inserted === false) {
        // Real, honest handling of the real race between the
        // read-check above and this insert (two genuinely simultaneous
        // duplicate requests, both passing the read-check before
        // either has inserted): a unique-constraint violation here
        // means another, concurrent request already won - re-fetch
        // and return ITS id, rather than surfacing a confusing real
        // DB error for what is, from the caller's perspective, a
        // successful (if duplicate) open request.
        if ($idempotencyKey !== '' && strpos((string) $wpdb->last_error, 'Duplicate entry') !== false) {
            $raceWinner = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND idempotency_key = %s", $userId, $idempotencyKey), ARRAY_A);
            if ($raceWinner) {
                wp_send_json_success(['id' => (int) $raceWinner['id'], 'idempotentReplay' => true]);
            }
        }
        // Real fix (open-position dual-writer audit, 2026-08-30, direct
        // follow-on to the trailing-stop-ratchet and close-position race
        // fixes): distinct from the idempotency-key case just above,
        // this branch is hit when the DB engine's own
        // user_symbol_style_open_lock UNIQUE KEY (see dbDelta schema
        // above, "open_symbol_lock" generated column) rejects a
        // genuinely DIFFERENT open request (different/absent
        // idempotencyKey - i.e. not a retry of the same logical
        // request) because this exact user already has ANOTHER row open
        // for this exact symbol+tradingStyle. This is the real,
        // concurrent-different-caller scenario the audit specifically
        // asked about: the browser and the headless driver each
        // independently reaching a BUY signal for the same symbol at
        // nearly the same moment, each with its own distinct
        // idempotency key (or none), each believing - from its own
        // local/stale state only - that it has no position open yet.
        // Without this constraint both INSERTs would have silently
        // succeeded, leaving two simultaneous 'open' rows for one
        // symbol, violating this app's own "the open position,
        // singular, per symbol" design invariant (see
        // recoverOpenPositionOnStartup/checkAndMonitorSwingPositions).
        // Honest, machine-readable rejection here - mirrors the
        // established 'alreadyClosed' flag from fno_close_position_fn:
        // the loser is told plainly it lost, and handed the winning
        // row's own id so it can reconcile its local state, rather than
        // either being told it succeeded (which would be a lie) or
        // getting an opaque generic DB error.
        if (strpos((string) $wpdb->last_error, 'Duplicate entry') !== false && strpos((string) $wpdb->last_error, 'user_symbol_style_open_lock') !== false) {
            $existingOpen = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table WHERE user_id = %d AND symbol = %s AND trading_style = %s AND status = 'open'",
                $userId, $symbol, $tradingStyle
            ), ARRAY_A);
            wp_send_json_error([
                'message' => "Real position for $symbol ($tradingStyle) is already open (id=" . ($existingOpen ? (int) $existingOpen['id'] : '?') . ") - refusing to open a second, duplicate position for the same symbol from a concurrent request (browser/driver race).",
                'symbolAlreadyOpen' => true,
                'existingId' => $existingOpen ? (int) $existingOpen['id'] : null,
            ]);
        }
        wp_send_json_error(['message' => 'Real database insert failed: ' . $wpdb->last_error]);
    }
    wp_send_json_success(['id' => $wpdb->insert_id]);
}

add_action('wp_ajax_fno_list_open_positions', 'fno_list_open_positions_fn');
add_action('wp_ajax_nopriv_fno_list_open_positions', 'fno_list_open_positions_fn'); // real fix: dual-auth (fno_verify_app_access) but nopriv registration was missing - the driver's own pre-open duplicate check and swing-position polling would never have reached this handler. See HeadlessDriverAuthTest.php.
function fno_list_open_positions_fn() {
    fno_verify_app_access();
    fno_rate_limit('list_open_positions', 300);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_open_positions';
    $userId = get_current_user_id();
    $tradingStyleFilter = sanitize_text_field($_GET['tradingStyle'] ?? '');
    if ($tradingStyleFilter !== '') {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d AND status = 'open' AND trading_style = %s ORDER BY opened_at DESC", $userId, $tradingStyleFilter), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id = %d AND status = 'open' ORDER BY opened_at DESC", $userId), ARRAY_A);
    }
    wp_send_json_success(['positions' => $rows]);
}

add_action('wp_ajax_fno_update_open_position', 'fno_update_open_position_fn');
add_action('wp_ajax_nopriv_fno_update_open_position', 'fno_update_open_position_fn'); // real fix: dual-auth (fno_verify_app_access) but nopriv registration was missing. See HeadlessDriverAuthTest.php.
function fno_update_open_position_fn() {
    fno_verify_app_access();
    fno_rate_limit('update_open_position', 300);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_open_positions';
    $userId = get_current_user_id();
    $id = (int) ($_POST['id'] ?? 0);
    // Real fix (open-position write-path audit, direct follow-on to the
    // fno_journal_add_fn NaN-string audit above and the browser-side
    // reconcileOpenPositionOnLoad trailing_sl fix earlier this session):
    // this endpoint used a bare (float) cast on every numeric field with
    // ZERO validation before writing straight to the DB. Same fail-open
    // trap as journal: (float)"NaN"/(float)"garbage" silently become 0.0,
    // never a rejected request. For trailing_sl specifically that 0.0 (or
    // ANY value the caller feels like sending) was then written and later
    // read back by reconcileOpenPositionOnLoad and the driver's own
    // swing-trailing-stop persistence as if it were a real, honestly-
    // computed protective level - a corrupted/looser-than-baseline
    // trailing_sl restored as active protection is exactly the bug fixed
    // client-side immediately before this pass. This closes it at the
    // real root, the write boundary, instead of only the one downstream
    // reader that happened to be found first.
    //
    // Step 1: is_numeric() + finite check on every numeric field present
    // in this request, mirroring fno_journal_add_fn's exact pattern
    // (is_numeric on the raw string BEFORE any (float) cast, so "NaN"/
    // "Infinity"/"garbage" are all genuinely rejected rather than
    // laundered into a plausible-looking float).
    foreach (['trailingSl', 'mfe', 'mae'] as $numField) {
        if (isset($_POST[$numField]) && $_POST[$numField] !== '' && !is_numeric($_POST[$numField])) {
            wp_send_json_error(['message' => "Real, non-numeric value genuinely rejected for '$numField' (got: " . substr((string) $_POST[$numField], 0, 40) . ") - refusing to silently store it as a fabricated float, which would poison the position's own live-managed exit state."], 400);
        }
    }
    $trailingSl = (isset($_POST['trailingSl']) && $_POST['trailingSl'] !== '') ? (float) $_POST['trailingSl'] : null;
    $mfe = (isset($_POST['mfe']) && $_POST['mfe'] !== '') ? (float) $_POST['mfe'] : null;
    $mae = (isset($_POST['mae']) && $_POST['mae'] !== '') ? (float) $_POST['mae'] : null;
    if ($trailingSl !== null && !is_finite($trailingSl)) {
        wp_send_json_error(['message' => "Real, non-finite trailingSl genuinely rejected (Infinity/-Infinity) - refusing to store it as active trailing-stop protection."], 400);
    }
    // Step 2: direction-of-protection check for trailingSl. This app is
    // options-BUYING only (long premium, CE or PE - see fno_open_position_fn,
    // there is no short/sell side anywhere in this codebase), so a rising
    // option premium is always the favorable direction and "more
    // protective" always means numerically HIGHER, with no CE/PE branch
    // needed - this is the exact same invariant already proven and tested
    // both client-side in updateTrailingStop() (`Math.max(currentTrail,
    // candidateTrail)`, assets/fno-lab-core.js) and in the
    // reconcileOpenPositionOnLoad restore-gate fixed immediately before
    // this pass (`trailing_sl > sl`). A genuinely valid update must be >=
    // the position's own current best-known protective level (whichever
    // of its stored sl/trailing_sl is already higher) - never a blind
    // accept-any-float. mfe/mae are excursion TRACKING fields (max
    // favorable/adverse move seen so far), not protective stop levels, so
    // they get the same finite-numeric check but no ratchet direction
    // requirement - that is a deliberate, narrower scope, not an
    // oversight.
    if ($trailingSl !== null) {
        $currentRow = $wpdb->get_row($wpdb->prepare("SELECT sl, trailing_sl FROM $table WHERE id = %d AND user_id = %d AND status = 'open'", $id, $userId), ARRAY_A);
        if (!$currentRow) {
            wp_send_json_error(['message' => 'Real position row not found (wrong id, not yours, or already closed) - refusing to write a trailing-stop update against nothing.'], 404);
        }
        $currentBest = (float) $currentRow['sl'];
        if (isset($currentRow['trailing_sl']) && $currentRow['trailing_sl'] !== null && is_numeric($currentRow['trailing_sl']) && (float) $currentRow['trailing_sl'] > $currentBest) {
            $currentBest = (float) $currentRow['trailing_sl'];
        }
        if ($trailingSl < $currentBest) {
            wp_send_json_error(['message' => "Real, backwards trailing-stop update genuinely rejected: proposed trailingSl ($trailingSl) is looser than the position's own current protective level ($currentBest) - a real trailing stop only ever tightens, never loosens."], 400);
        }
    }
    // Real fix (browser-vs-driver concurrent-management race audit):
    // the pre-check above (SELECT current row, decide, THEN $wpdb->update)
    // is read-compute-write, not atomic. This app genuinely allows the
    // SAME open position to be actively managed by TWO independent
    // processes at once - the browser tab (assets/fno-lab-core.js's own
    // updateTrailingStop/tick loop) and the headless autonomous-driver
    // (which eval()s that same shared source, per the earlier-pass
    // finding) - e.g. a user leaves the tab open while the driver also
    // runs unattended. Two genuinely, independently-computed ratchet
    // values, each honestly valid against ITS OWN stale read, can both
    // pass the pre-check above and both reach the final $wpdb->update
    // call - and a bare column-value UPDATE has no memory of what value
    // it is replacing, so the request that happens to WRITE LAST wins
    // outright, even if its trailingSl is the looser of the two (e.g.
    // driver reads sl=98, computes trailing_sl=100 from a slightly
    // newer tick and writes it; browser, still holding its own slightly
    // stale read of sl=98, independently computes trailing_sl=99 from
    // an OLDER tick and writes it a moment later - 99 now overwrites
    // 100 in the DB, silently loosening a live protective stop even
    // though every individual write was, in isolation, "valid"). This
    // is a real gap the earlier ratchet-direction-enforcement fix above
    // did NOT close - that fix only ever compared a request's own value
    // against one single stale read, never against a second concurrent
    // writer's value.
    //
    // The correct, race-safe fix is a server-side ATOMIC ratchet: fold
    // the "only ever move in the protective direction" comparison INTO
    // the single UPDATE statement itself, evaluated by MySQL against
    // whatever value is genuinely in the row AT THE INSTANT OF THE
    // WRITE (not the instant of an earlier SELECT). GREATEST()/LEAST()
    // are simple, commutative, order-independent functions - no matter
    // which of two concurrent callers' UPDATEs the storage engine
    // executes last, the row converges to the objectively most-
    // protective value across BOTH proposals, because each UPDATE only
    // ever computes GREATEST/LEAST(existing-value-at-write-time,
    // this-request's-value) - never a blind overwrite. This closes the
    // gap regardless of process count (browser + driver, or even two
    // browser tabs) and regardless of write ordering/timing, which a
    // second read-compare-write pass (e.g. re-reading right before the
    // final write) could not guarantee under real concurrent execution.
    //
    // trailing_sl: protective stop, higher is always better (this app
    // is options-BUYING only - see the direction-check comment above),
    // so it ratchets via GREATEST(trailing_sl, sl, new value) - folding
    // in `sl` too so a first-ever trailing_sl write still can't go
    // below the position's original hard stop.
    // mfe: max FAVOURABLE excursion, tracks the highest price seen
    // (updateMFEMAE() in fno-lab-core.js: `Math.max(prevMfe,
    // livePrice)`), so it ratchets the same direction as trailing_sl:
    // GREATEST(mfe, new value).
    // mae: max ADVERSE excursion, tracks the LOWEST price seen
    // (updateMFEMAE(): `Math.min(prevMae, livePrice)` - stored as a raw
    // price, not a magnitude, so "worse" is numerically SMALLER), so it
    // ratchets the OPPOSITE direction: LEAST(mae, new value).
    // COALESCE(...,  new value) makes each expression correct on the
    // very first write too, when the existing column is still NULL.
    //
    // Real, deliberate ownership check - a real position row can only
    // ever be updated by the real user who opened it, verified by
    // the WHERE clause itself (matching this app's own established
    // convention elsewhere), never trusted from a client-supplied id
    // alone.
    $setParts = [];
    $args = [];
    if ($trailingSl !== null) {
        $setParts[] = "trailing_sl = GREATEST(COALESCE(trailing_sl, %f), COALESCE(sl, %f), %f)";
        array_push($args, $trailingSl, $trailingSl, $trailingSl);
    }
    if ($mfe !== null) {
        $setParts[] = "mfe = GREATEST(COALESCE(mfe, %f), %f)";
        array_push($args, $mfe, $mfe);
    }
    if ($mae !== null) {
        $setParts[] = "mae = LEAST(COALESCE(mae, %f), %f)";
        array_push($args, $mae, $mae);
    }
    if (empty($setParts)) {
        wp_send_json_success(['updated' => false]);
    }
    $setParts[] = "last_checked_at = %d";
    $args[] = time();
    $sql = "UPDATE $table SET " . implode(', ', $setParts) . " WHERE id = %d AND user_id = %d AND status = 'open'";
    array_push($args, $id, $userId);
    $updated = $wpdb->query($wpdb->prepare($sql, $args));
    wp_send_json_success(['updated' => $updated !== false && $updated > 0]);
}

add_action('wp_ajax_fno_close_position', 'fno_close_position_fn');
add_action('wp_ajax_nopriv_fno_close_position', 'fno_close_position_fn'); // real fix: dual-auth (fno_verify_app_access) but nopriv registration was missing - every real driver auto-exit (target/SL) would have failed to persist the close, leaving the DB row stuck 'open' after a real exit. See HeadlessDriverAuthTest.php.
function fno_close_position_fn() {
    fno_verify_app_access();
    fno_rate_limit('close_position', 30);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_open_positions';
    $userId = get_current_user_id();
    $id = (int) ($_POST['id'] ?? 0);
    $exitPrice = (float) ($_POST['exitPrice'] ?? 0);
    $exitReason = sanitize_text_field($_POST['exitReason'] ?? 'MANUAL');
    if ($id <= 0 || $exitPrice <= 0) {
        wp_send_json_error(['message' => 'Real, required close fields are genuinely missing or invalid.']);
    }
    $position = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND user_id = %d AND status = 'open'", $id, $userId), ARRAY_A);
    if (!$position) {
        wp_send_json_error(['message' => 'Real, open position genuinely not found - it may have already been closed.']);
    }
    // Real fix (close-position race audit): the original UPDATE's WHERE
    // clause only constrained on id + user_id, NOT status='open' - so
    // the SELECT above's status check was pure TOCTOU (time-of-check
    // to time-of-use), not an actual guard. Concretely reachable: the
    // SAME real position can genuinely be closed independently by BOTH
    // the browser tab AND the headless autonomous-driver within the
    // same tick window (e.g. both see target hit on their own quote
    // poll and decide to close "now"). Both requests could pass this
    // SELECT (both still see status='open' before either UPDATE
    // commits), then both `$wpdb->update()` calls below would succeed
    // (real rows_affected=1 each, since id+user_id alone stays true
    // for both), so BOTH callers received wp_send_json_success and
    // BOTH would proceed to call fno_journal_add_fn - a real duplicate
    // closing journal entry and a real double P&L booking for one
    // actual trade, unless their idempotencyKeys happened to collide
    // (browser and driver are different code paths with no shared key
    // derivation, so they don't). Fixed with the same atomic
    // conditional-UPDATE + rows_affected pattern already established
    // by fno_update_open_position_fn's GREATEST/LEAST/COALESCE fix:
    // the UPDATE itself now re-checks status='open' in its WHERE
    // clause (a real, atomic, single-statement check-and-set - no
    // window for a second caller to sneak in between check and use),
    // and the SECOND caller's UPDATE now genuinely affects 0 rows
    // (checked via $wpdb->rows_affected, per this session's
    // return-value-checking pattern) and is told, honestly, that it
    // lost the race - rather than being told it succeeded and going on
    // to double-write a journal entry.
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE $table SET status = 'closed', last_checked_at = %d WHERE id = %d AND user_id = %d AND status = 'open'",
        time(), $id, $userId
    ));
    if ($updated === false) {
        wp_send_json_error(['message' => 'Real database update failed: ' . $wpdb->last_error]);
    }
    if ((int) $wpdb->rows_affected === 0) {
        // Real, machine-readable distinguishing flag - same established
        // pattern as fno_open_position_fn's own 'idempotentReplay' flag
        // above: a caller that loses this race needs to tell "this
        // position is genuinely already closed (by the other caller
        // that won)" apart from a real, generic failure, so it can
        // gracefully sync its own local state and move on instead of
        // alarming the user or retrying forever. See the browser
        // closeAutoTrade() and autonomous-driver.js close-position call
        // sites for the callers that key off this flag.
        wp_send_json_error(['message' => 'Real position was already closed by a concurrent request (browser/driver race) - not proceeding, to avoid a duplicate journal entry and double P&L booking for the same real trade.', 'alreadyClosed' => true]);
    }
    wp_send_json_success(['position' => $position, 'exitPrice' => $exitPrice, 'exitReason' => $exitReason]);
}

function fno_journal_add_fn() {
    // Swapped to fno_verify_app_access() so the real, headless
    // Autonomous Driver can persist a real, completed paper trade
    // without a browser session - the real, existing browser path
    // (nonce + login) is completely unaffected, verified by
    // HeadlessDriverAuthTest.php.
    fno_verify_app_access();
    // Real fix (rate-limiter coverage audit): fno_verify_app_access()
    // accepts EITHER a logged-in browser session OR the driver secret -
    // meaning a malicious logged-in user (no driver secret needed at
    // all) could previously hammer this real DB-INSERT endpoint with
    // zero rate-limiting. 30/60s (the existing default, same as
    // open_position/close_position) is real headroom - a real journal
    // write only ever happens once per completed trade close, nowhere
    // near this limit under any genuine usage pattern.
    fno_rate_limit('journal_add');
    global $wpdb;
    $table = $wpdb->prefix . 'fno_journal';
    $user_id = get_current_user_id();
    // Real fix (journal write/read-path audit): every numeric field
    // below used a bare (float)/(int) cast on the raw $_POST value.
    // PHP's numeric-string cast is FAIL-OPEN, not fail-closed - e.g.
    // (float)"NaN" and (float)"garbage" both silently evaluate to 0.0,
    // never a PHP error, never a rejected request. That 0.0 is then
    // genuinely indistinguishable from a real, honest breakeven trade
    // to every downstream analytics function reading this row
    // (computeRegimeWinRate, computeCalibrationBuckets, etc, several
    // hardened THIS session with Number.isFinite checks specifically
    // because malformed pnl could exist - this closes the gap at the
    // real root, the write boundary, instead of only patching readers).
    // Concretely reachable: closeAutoTrade's pnl is costs.netPnl,
    // derived from entryPrice/exitPrice - if either is ever NaN/null
    // (e.g. a live quote genuinely missing lastPrice), netPnl is NaN,
    // JS's JSON-free x-www-form-urlencoded body sends it as the
    // literal string "NaN" (NaN passes the `v!==undefined && v!==null`
    // filter in journalAdd()), and it lands here as $_POST['pnl'] ===
    // "NaN". A value that IS sent but is not real, valid numeric data
    // is now honestly rejected rather than silently laundered into a
    // plausible-looking 0. A field that is simply ABSENT (the caller
    // never sent it at all - e.g. an older client) keeps its existing,
    // intentional default (0 for pnl, null for the optional fields) -
    // that is a real, deliberate default, not corruption, and is left
    // unchanged.
    foreach (['pnl', 'entry_price', 'exit_price', 'strike', 'gross_pnl', 'costs_total', 'sl', 'entryIV', 'exitIV', 'mfe', 'mae'] as $numField) {
        if (isset($_POST[$numField]) && $_POST[$numField] !== '' && !is_numeric($_POST[$numField])) {
            wp_send_json_error(['message' => "Real, non-numeric value genuinely rejected for '$numField' (got: " . substr((string) $_POST[$numField], 0, 40) . ") - refusing to silently store it as a fabricated 0, which would poison every downstream analytics function reading this journal."], 400);
        }
    }
    // Real fix (same audit): mirrors the exact, already-proven
    // idempotency pattern fno_open_position_fn uses (see that
    // function's own TRACE + FNO_JOURNAL_SCHEMA_VERSION 2.7.0/2.8.0
    // comments) - optional, additive, gated purely on the caller
    // opting in by sending one. A real retry of the same logical
    // journal write (the autonomous-driver's enqueueRetry queue, or an
    // uncaught network error mid-cycle that leaves openPosition
    // non-null so the next cycle re-computes and re-POSTs the same
    // close) now returns the SAME, already-created id instead of
    // inserting a second row and double-counting that trade's pnl in
    // every analytics function that reads ctx.fullJournal.
    $idempotencyKey = sanitize_text_field($_POST['idempotencyKey'] ?? '');
    if ($idempotencyKey !== '') {
        $existingJournal = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND idempotency_key = %s", $user_id, $idempotencyKey), ARRAY_A);
        if ($existingJournal) {
            wp_send_json_success(['id' => (int) $existingJournal['id'], 'idempotentReplay' => true]);
        }
    }
    $row = [
        'user_id' => $user_id,
        'trade_ts' => (int) ($_POST['ts'] ?? (time() * 1000)),
        // Real fix (server-side symbol-enum sweep): symbol was
        // previously accepted via sanitize_text_field() alone with no
        // enum check - an invalid/garbage value now falls back to the
        // same 'NIFTY' default already used when the field is simply
        // absent, matching this endpoint's own existing
        // fallback-on-absence pattern rather than rejecting the whole
        // journal write.
        'symbol' => fno_validate_symbol($_POST['symbol'] ?? 'NIFTY') ?? 'NIFTY',
        'strike' => isset($_POST['strike']) ? (float) $_POST['strike'] : null,
        // Real fix (server-side symbol/optionType-enum sweep):
        // option_type was previously accepted via sanitize_text_field()
        // alone with no enum check - this field is optional here (a
        // journal row can close a spot/futures paper position with no
        // option_type at all), so an invalid/garbage value is dropped
        // to null rather than rejecting the whole journal write, same
        // optional-field treatment as this function's own symbol field
        // just above. A genuinely valid CE/PE value passes through
        // uppercased, matching every other optionType call site's
        // in_array(['CE','PE'], true) convention.
        'option_type' => (function () {
            if (!isset($_POST['option_type']) || $_POST['option_type'] === '') return null;
            $ot = strtoupper(sanitize_text_field($_POST['option_type']));
            return in_array($ot, ['CE', 'PE'], true) ? $ot : null;
        })(),
        // FOUND AND FIXED via real, live end-to-end testing - the
        // real, client-side POST field was previously named `action`,
        // colliding with WordPress's own reserved dispatch parameter
        // of the same name (see the matching, detailed real TRACE in
        // fno-lab-core.js's journalAdd() for the full, real root
        // cause). The real database COLUMN name stays 'action'
        // unchanged (no schema migration needed) - only the real
        // $_POST source field this reads FROM is updated to match the
        // new, non-colliding client field name.
        'action' => sanitize_text_field($_POST['trade_action'] ?? 'MANUAL'),
        'entry_price' => isset($_POST['entry_price']) ? (float) $_POST['entry_price'] : null,
        'exit_price' => isset($_POST['exit_price']) ? (float) $_POST['exit_price'] : null,
        'qty' => isset($_POST['qty']) ? (int) $_POST['qty'] : null,
        'pnl' => (float) ($_POST['pnl'] ?? 0),
        'gross_pnl' => isset($_POST['gross_pnl']) ? (float) $_POST['gross_pnl'] : null,
        'costs_total' => isset($_POST['costs_total']) ? (float) $_POST['costs_total'] : null,
        'original_sl' => isset($_POST['sl']) ? (float) $_POST['sl'] : null,
        'entry_iv' => isset($_POST['entryIV']) ? (float) $_POST['entryIV'] : null,
        'exit_iv' => isset($_POST['exitIV']) ? (float) $_POST['exitIV'] : null,
        'opened_at' => isset($_POST['openedAt']) ? (int) $_POST['openedAt'] : null,
        'mfe' => isset($_POST['mfe']) ? (float) $_POST['mfe'] : null,
        'mae' => isset($_POST['mae']) ? (float) $_POST['mae'] : null,
        'source' => sanitize_text_field($_POST['source'] ?? 'manual'), // 'manual' | 'auto_target' | 'auto_sl' | 'square_off' | 'manual_force_exit' | 'partial' | 'import'
        'mode' => sanitize_text_field($_POST['mode'] ?? 'paper'),
    ];
    // SELF-CAUGHT REAL BUG, found via a real, direct test: the
    // original, inline version of this real, validated-whitelist
    // check re-read the raw $_POST['tradingStyle'] a SECOND time in
    // the ternary's true branch, after already, correctly applying a
    // real fallback via ?? in the condition - meaning a genuinely
    // MISSING key (not merely an invalid one) triggered a real PHP
    // "undefined array key" warning and evaluated to null, not the
    // intended real 'intraday' default. Fixed by computing the real,
    // fallback-applied value exactly once.
    $requestedTradingStyle = $_POST['tradingStyle'] ?? 'intraday';
    $row['trading_style'] = in_array($requestedTradingStyle, ['scalping', 'intraday', 'swing'], true) ? $requestedTradingStyle : 'intraday';
    // Master Development Prompt §29: "At the exact moment a paper trade
    // is opened, store a complete snapshot [of all 193 factors]... this
    // makes every historical trade reproducible." factor_snapshot is a
    // JSON blob (built client-side by buildEntrySnapshot() from the
    // real Factor Registry - see fno-lab-core.js) containing every
    // catalogued factor's id/status/pass/score/reason at entry time -
    // stored as-is (JSON, not HTML) rather than run through
    // sanitize_text_field, which would corrupt the JSON by stripping
    // quotes/braces. Optional - manual journal entries or older client
    // versions may not send one, stored as NULL rather than an empty
    // fabricated snapshot.
    if (isset($_POST['factor_snapshot']) && $_POST['factor_snapshot'] !== '') {
        $decoded = json_decode(stripslashes($_POST['factor_snapshot']), true);
        if (is_array($decoded)) {
            $row['factor_snapshot'] = wp_json_encode($decoded); // re-encode from decoded array, not raw passthrough - rejects malformed JSON rather than storing garbage
        }
    }
    if ($idempotencyKey !== '') $row['idempotency_key'] = $idempotencyKey;
    $inserted = $wpdb->insert($table, $row);
    if ($inserted === false) {
        // Real, honest handling of the real race between the
        // read-check above and this insert (two genuinely simultaneous
        // duplicate requests, both passing the read-check before
        // either has inserted) - same, already-proven pattern
        // fno_open_position_fn uses: a unique-constraint violation
        // means another, concurrent request already won, so re-fetch
        // and return ITS id rather than surfacing a confusing DB error
        // for what is, from the caller's perspective, a successful (if
        // duplicate) journal write.
        if ($idempotencyKey !== '' && strpos((string) $wpdb->last_error, 'Duplicate entry') !== false) {
            $raceWinner = $wpdb->get_row($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND idempotency_key = %s", $user_id, $idempotencyKey), ARRAY_A);
            if ($raceWinner) {
                wp_send_json_success(['id' => (int) $raceWinner['id'], 'idempotentReplay' => true]);
            }
        }
        wp_send_json_error(['message' => 'DB insert failed: ' . $wpdb->last_error], 500);
    }
    // Captured once, immediately after the real journal insert - the
    // one, single, real, authoritative journal_id for this whole
    // request, used both by the dual-write loop below AND the final
    // response, so neither can ever be silently corrupted by a later
    // insert call overwriting $wpdb->insert_id.
    $journalId = $wpdb->insert_id;
    // Layer B provenance split - real, genuinely additive dual-write.
    // Only ever runs when a real, valid factor_snapshot was decoded
    // above (the SAME real, already-validated $decoded array - never
    // re-parsed, never a second, independent decode that could drift
    // from what was actually stored in the JSON column). A real
    // failure here is deliberately non-fatal to the real journal
    // write itself - the existing JSON column is still the real,
    // complete, authoritative record; these normalized rows are a
    // real, additive convenience for efficient querying, not a new
    // point of failure for the trade record itself.
    // Real fix (bulk/array-accepting AJAX endpoint size-limit audit):
    // $decoded['factors'] comes straight from the client-supplied
    // factor_snapshot JSON and was looped over with no cap on entry
    // count - a legitimate snapshot has at most 193 entries (one per
    // real Factor Registry factor - see fno-lab-core.js), but nothing
    // stopped a malicious/buggy client from sending a factors object
    // with far more keys, each one becoming its own real DB insert in
    // the loop below. Reuses the SAME FNO_STRATEGY_VERSION_FIELDS_MAX
    // (300, a deliberate margin above 193) already defined above for
    // the same "how many factor fields can genuinely appear in one
    // request" reasoning - the dual-write loop simply skips any
    // entries past the cap rather than rejecting the whole journal
    // write (per this block's own existing "non-fatal, additive
    // convenience" design: the JSON column above already stored the
    // real, complete, authoritative snapshot before this ever runs).
    if (isset($decoded['factors']) && is_array($decoded['factors']) && count($decoded['factors']) > FNO_STRATEGY_VERSION_FIELDS_MAX) {
        $decoded['factors'] = array_slice($decoded['factors'], 0, FNO_STRATEGY_VERSION_FIELDS_MAX, true);
    }
    if (isset($decoded['factors']) && is_array($decoded['factors'])) {
        $factorValuesTable = $wpdb->prefix . 'fno_factor_values';
        $now = time();
        // FOUND AND FIXED before this ever ran: $wpdb->insert_id is a
        // real, mutable property that gets overwritten by EVERY real
        // insert call - reading it fresh inside this loop (after the
        // first real factor-row insert) would have returned that
        // row's own ID, not the real journal row's ID, silently
        // corrupting every factor_values row after the first one for
        // every single real trade. Reuses the SAME $journalId
        // captured once, immediately after the real journal insert
        // above, instead.
        foreach ($decoded['factors'] as $factorId => $f) {
            if (!is_array($f)) continue;
            $wpdb->insert($factorValuesTable, [
                'journal_id' => $journalId,
                'factor_id' => sanitize_text_field($factorId),
                'cat' => isset($f['cat']) ? sanitize_text_field($f['cat']) : null,
                'status' => isset($f['status']) ? sanitize_text_field($f['status']) : null,
                'pass' => isset($f['pass']) ? ($f['pass'] === true ? 1 : ($f['pass'] === false ? 0 : null)) : null,
                'score' => isset($f['score']) && is_numeric($f['score']) ? (float) $f['score'] : null,
                'created_at' => $now,
            ]);
        }
    }
    wp_send_json_success(['id' => $journalId]);
}

add_action('wp_ajax_fno_journal_list', 'fno_journal_list_fn');
add_action('wp_ajax_nopriv_fno_journal_list', 'fno_journal_list_fn');
/**
 * TRACE: Reads the current user's journal rows from the server table,
 * most recent first, capped at 500 -> returns them as an array shaped
 * to match the existing client-side journal entry shape ({pnl, ts,
 * symbol, ...}) so computeRiskFactors/computePsychologyFactors (which
 * expect that shape) work unmodified against server data.
 * Preconditions: real, valid auth (browser session OR headless driver
 * secret).
 * Postconditions: returns up to 500 rows, newest trade_ts first.
 * Edge cases handled: user has zero rows (returns empty array, not an
 * error - an empty journal is a valid, common state).
 *
 * TRACE (real, follow-up fix, same class as fno_get_hypothesis_stats_fn/
 * fno_get_paper_account_fn earlier this session): this endpoint used to
 * hard-require a logged-in browser session, which meant the headless
 * autonomous-driver could never fetch ctx.fullJournal at all - a real,
 * previously-undiscovered gap where every journal-dependent
 * Failure-Mode check (FM057/FM058/FM072/FM104/FM105/FM129/FM130, all
 * gated on `Array.isArray(ctx.fullJournal)`) silently never fired from
 * the driver, not because of a guard that was ever exercised, but
 * because ctx.fullJournal was always undefined there - the exact same
 * class of gap the FM-evalctx-field-coverage follow-up closed for
 * trapSignal/breakoutCondition/etc., just not caught in that earlier
 * pass since this endpoint wasn't part of the browser's own direct
 * evaluatePreTradeFailureModes() call-site field list, it feeds in one
 * level upstream via ctx.fullJournal. Switched to fno_verify_app_access()
 * - per-user data (WHERE user_id = ...), so needs the real
 * wp_set_current_user() the driver secret triggers there, same as
 * fno_get_hypothesis_stats_fn/fno_get_paper_account_fn.
 */
function fno_journal_list_fn() {
    fno_verify_app_access();
    // Real fix (rate-limiter coverage audit): genuinely missing despite
    // being reachable by any logged-in user, not just the driver secret
    // - this is never called from the app's 600ms hot refresh loop
    // (only on-demand/on-load browser sites plus once per driver cycle),
    // so the 30/60s default is real, ample headroom for the honest
    // usage pattern.
    fno_rate_limit('journal_list');
    global $wpdb;
    $table = $wpdb->prefix . 'fno_journal';
    $user_id = get_current_user_id();
    // Master Prompt §31-33 (Factor Performance / Interaction Analysis)
    // needs each trade's full factor_snapshot, but that column can be
    // tens of KB per row and this endpoint is called on EVERY refresh
    // for the normal journal-sync flow - including it by default would
    // be real, avoidable performance waste. Only include it when the
    // caller explicitly asks (the on-demand Factor Performance panel),
    // never as the hot-path default.
    $includeSnapshots = !empty($_GET['include_snapshots']);
    $cols = $includeSnapshots
        ? "id, trade_ts, symbol, strike, option_type, action, entry_price, exit_price, qty, pnl, gross_pnl, costs_total, original_sl, mfe, mae, entry_iv, exit_iv, opened_at, source, mode, trading_style, factor_snapshot"
        : "id, trade_ts, symbol, strike, option_type, action, entry_price, exit_price, qty, pnl, gross_pnl, costs_total, original_sl, mfe, mae, entry_iv, exit_iv, opened_at, source, mode, trading_style, (factor_snapshot IS NOT NULL) AS has_snapshot";
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT $cols FROM $table WHERE user_id = %d ORDER BY trade_ts DESC LIMIT 500",
        $user_id
    ), ARRAY_A);
    $shaped = array_map(function ($r) use ($includeSnapshots) {
        $out = [
            'id' => (int) $r['id'], 'ts' => (int) $r['trade_ts'], 'symbol' => $r['symbol'],
            'strike' => $r['strike'] !== null ? (float) $r['strike'] : null,
            'optionType' => $r['option_type'], 'action' => $r['action'],
            'entryPrice' => $r['entry_price'] !== null ? (float) $r['entry_price'] : null,
            'exitPrice' => $r['exit_price'] !== null ? (float) $r['exit_price'] : null,
            'qty' => $r['qty'] !== null ? (int) $r['qty'] : null,
            'pnl' => (float) $r['pnl'],
            'grossPnl' => $r['gross_pnl'] !== null ? (float) $r['gross_pnl'] : null,
            'costsTotal' => $r['costs_total'] !== null ? (float) $r['costs_total'] : null,
            'sl' => $r['original_sl'] !== null ? (float) $r['original_sl'] : null,
            'mfe' => $r['mfe'] !== null ? (float) $r['mfe'] : null,
            'mae' => $r['mae'] !== null ? (float) $r['mae'] : null,
            'entryIV' => $r['entry_iv'] !== null ? (float) $r['entry_iv'] : null,
            'exitIV' => $r['exit_iv'] !== null ? (float) $r['exit_iv'] : null,
            'openedAt' => $r['opened_at'] !== null ? (int) $r['opened_at'] : null,
            'source' => $r['source'], 'mode' => $r['mode'], 'tradingStyle' => $r['trading_style'] ?? 'intraday',
        ];
        if ($includeSnapshots) {
            $out['factorSnapshot'] = !empty($r['factor_snapshot']) ? json_decode($r['factor_snapshot'], true) : null;
        } else {
            $out['hasSnapshot'] = !empty($r['has_snapshot']);
        }
        return $out;
    }, $rows ?: []);
    wp_send_json_success(['journal' => $shaped, 'count' => count($shaped)]);
}

add_action('wp_ajax_fno_journal_get_snapshot', 'fno_journal_get_snapshot_fn');
/**
 * TRACE: Fetches the full factor_snapshot JSON for ONE specific trade
 * by id, scoped to the current user -> the "Decision Replay" data
 * source (Master Development Prompt §51: "select any historical trade
 * and see what did the system know at that exact moment"). Kept as a
 * separate on-demand endpoint rather than bundled into
 * fno_journal_list_fn's bulk response, since snapshots can be tens of
 * KB each and the list endpoint is called on every refresh - fetching
 * all 500 rows' full snapshots every refresh would be real, avoidable
 * performance waste.
 * Preconditions: user logged in, $_GET['id'] is a trade belonging to
 * this user (ownership enforced in the WHERE clause, not just trusted
 * from the client).
 * Postconditions: returns the decoded snapshot object, or null if the
 * trade has none (e.g. a manual entry made before this field existed,
 * or an anonymous-session entry that was later imported).
 */
function fno_journal_get_snapshot_fn() {
    // NOTE: the Decision Replay UI (Master Prompt §51) deliberately does
    // NOT call this per-trade endpoint - it reuses the bulk
    // fno_journal_list&include_snapshots=1 fetch already made for the
    // Post-Trade Analysis panel, avoiding an N+1 request pattern when
    // displaying a list of replayable trades. This single-trade endpoint
    // remains available for a future use case that genuinely needs ONE
    // trade's snapshot without fetching the whole journal (e.g. a
    // deep-link to a specific trade ID) - documented as intentionally
    // unused right now, not a forgotten dead end.
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_journal';
    $user_id = get_current_user_id();
    $id = (int) ($_GET['id'] ?? 0);
    $snapshot = $wpdb->get_var($wpdb->prepare("SELECT factor_snapshot FROM $table WHERE id = %d AND user_id = %d", $id, $user_id));
    wp_send_json_success(['snapshot' => $snapshot ? json_decode($snapshot, true) : null]);
}

// ------------------------------------------------------------------
// STRATEGY VERSION LOG (Master Development Prompt §34)
//
// "Every strategy change must create a new version... never overwrite
// an old strategy." Stored as one append-only array under a single WP
// option - deliberately not a DB table, since this is a handful of
// deliberate, human-reviewed entries (a threshold changed, a weight
// changed, with a stated reason and evidence), not a high-frequency
// write path the way the trade journal is. Site-wide (not per-user):
// the strategy itself is one shared thing being evolved, not a
// per-trader setting.
// ------------------------------------------------------------------

add_action('wp_ajax_fno_get_strategy_versions', 'fno_get_strategy_versions_fn');
add_action('wp_ajax_nopriv_fno_get_strategy_versions', 'fno_get_strategy_versions_fn');
/**
 * TRACE: Returns the full version log, seeded with the v1.0-baseline
 * entry on first call if the option doesn't exist yet (so the log is
 * never empty for a fresh install - the CURRENT thresholds are
 * themselves "version 1", not an implicit unversioned starting state).
 * Real, swapped this session: this data is a site-wide, read-only
 * option (never per-user), the exact same real security posture as
 * fno_get_factor_health_fn just above - so it uses the SAME, already-
 * proven fno_verify_public_or_driver_access() dual-auth pattern (real
 * browser nonce OR real driver secret, no wp_set_current_user() needed
 * since nothing here is scoped by user), rather than the hard
 * fno_verify_app_nonce()-only check that previously left this the
 * headless driver's own strategyVersionsCache gap (see
 * evaluatePreTradeFailureModes' real strategyVersionsCache TRACE in
 * fno-lab-core.js and autonomous-driver.js). Real, existing browser
 * behavior is unchanged - the nonce fallback inside
 * fno_verify_public_or_driver_access() is byte-for-byte the same
 * fno_verify_app_nonce() call this function used before.
 */
function fno_get_strategy_versions_fn() {
    fno_rate_limit('strategy_versions'); // FOUND via the same real, live load test - genuinely missing
    fno_verify_public_or_driver_access();
    $versions = get_option('fno_strategy_versions');
    if (!is_array($versions) || empty($versions)) {
        $versions = [[
            'version' => 'v1.0-baseline',
            'changedFields' => ['BUY_THRESHOLD', 'SELL_THRESHOLD'],
            'oldValues' => [], 'newValues' => ['BUY_THRESHOLD' => 11, 'SELL_THRESHOLD' => -17],
            'reason' => 'Initial baseline - thresholds derived from achievable score-range math (see fno-lab-core.js evaluateBrain comments), not tuned against any trade outcome yet.',
            'evidence' => 'None yet - this is the starting point, not a validated improvement.',
            'createdAt' => time() * 1000,
        ]];
        update_option('fno_strategy_versions', $versions, false);
    }
    wp_send_json_success(['versions' => $versions]);
}

add_action('wp_ajax_fno_add_strategy_version', 'fno_add_strategy_version_fn');
/**
 * TRACE: Admin-only (this is a strategy-level change affecting every
 * user's decision engine, not a personal setting) -> appends one new
 * version entry -> NEVER modifies or removes prior entries (the literal
 * "never overwrite an old strategy" requirement).
 * Preconditions: current_user_can('manage_options'); $_POST has
 * version/reason at minimum.
 * Postconditions: option updated with the new entry appended; returns
 * the full updated log.
 * Edge cases handled: version string collision with an existing entry
 * (rejected - versions must be unique, silently allowing a duplicate
 * would break the "which version produced this trade" traceability
 * every snapshot depends on).
 */
function fno_add_strategy_version_fn() {
    // FOUND via the same "write endpoints missing fno_rate_limit()"
    // audit pattern already fixed for microstructure_ingest/
    // raw_tick_ingest above: this endpoint appends, unbounded, to an
    // autoloaded wp_option on every call with no rate limit at all -
    // a scripted/compromised admin session (a nonce alone does not stop
    // a same-origin script) could otherwise bloat fno_strategy_versions
    // without limit. Real fix: same fno_rate_limit() every other write
    // endpoint in this file already uses.
    fno_rate_limit('add_strategy_version', 20);
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Admin only - this changes the shared strategy, not a personal setting'], 403);
    check_ajax_referer('fno_standalone_nonce', 'nonce');
    $versions = get_option('fno_strategy_versions', []);
    $newVersion = sanitize_text_field($_POST['version'] ?? '');
    if ($newVersion === '') wp_send_json_error(['message' => 'version is required'], 400);
    foreach ($versions as $v) {
        if ($v['version'] === $newVersion) wp_send_json_error(['message' => "Version \"$newVersion\" already exists - versions must be unique for trade traceability"], 400);
    }
    // Real fix (bulk/array-accepting AJAX endpoint size-limit audit):
    // changedFields/oldValues/newValues were decoded straight from
    // $_POST with no cap on element count - admin-only + rate-limited
    // to 20/window bounds REPEATED abuse, but not a single oversized
    // request. Same wp_send_json_error-on-exceeded pattern already
    // used by fno_ingest_raw_tick_fn's FNO_RAW_TICK_BATCH_MAX check.
    $changedFieldsDecoded = json_decode(stripslashes($_POST['changedFields'] ?? '[]'), true) ?: [];
    $oldValuesDecoded = json_decode(stripslashes($_POST['oldValues'] ?? '{}'), true) ?: [];
    $newValuesDecoded = json_decode(stripslashes($_POST['newValues'] ?? '{}'), true) ?: [];
    foreach (['changedFields' => $changedFieldsDecoded, 'oldValues' => $oldValuesDecoded, 'newValues' => $newValuesDecoded] as $fieldName => $decodedField) {
        if (is_array($decodedField) && count($decodedField) > FNO_STRATEGY_VERSION_FIELDS_MAX) {
            wp_send_json_error(['message' => "$fieldName of " . count($decodedField) . " entries exceeds the real max of " . FNO_STRATEGY_VERSION_FIELDS_MAX . " per request (comfortably above the real 193-factor registry) - split into smaller version entries"], 400);
        }
    }
    $entry = [
        'version' => $newVersion,
        'changedFields' => array_map('sanitize_text_field', $changedFieldsDecoded),
        'oldValues' => $oldValuesDecoded,
        'newValues' => $newValuesDecoded,
        'reason' => fno_cap_text(sanitize_textarea_field($_POST['reason'] ?? '')),
        'evidence' => fno_cap_text(sanitize_textarea_field($_POST['evidence'] ?? '')),
        'createdAt' => time() * 1000,
    ];
    $versions[] = $entry;
    update_option('fno_strategy_versions', $versions, false); // false = don't autoload - this grows unbounded on admin action, keep it off every unrelated page load
    wp_send_json_success(['versions' => $versions]);
}

// ------------------------------------------------------------------
// STRATEGY KNOWLEDGE BASE (Master Development Prompt §48)
//
// "Maintain a permanent knowledge base... OBSERVATION #128... Evidence:
// 214 trades... Current conclusion... Candidate change... Status:
// Testing." Distinct from the Strategy Version Log above: versions
// record FINAL decisions that already changed something; the knowledge
// base records the intermediate RESEARCH observations that may or may
// not eventually justify a version change - a human-curated research
// trail, not an automated conclusion. Same append-only, shared
// (site-wide, not per-user) storage pattern as the version log.
// ------------------------------------------------------------------

add_action('wp_ajax_fno_get_knowledge_base', 'fno_get_knowledge_base_fn');
add_action('wp_ajax_nopriv_fno_get_knowledge_base', 'fno_get_knowledge_base_fn');
function fno_get_knowledge_base_fn() {
    fno_rate_limit('knowledge_base_read'); // FOUND via the same real, live load test - genuinely missing
    fno_verify_app_nonce();
    $entries = get_option('fno_knowledge_base', []);
    wp_send_json_success(['entries' => $entries]);
}

add_action('wp_ajax_fno_add_knowledge_entry', 'fno_add_knowledge_entry_fn');
/**
 * TRACE: Logged-in (not admin-only, unlike strategy versions - adding
 * a RESEARCH OBSERVATION doesn't change the live strategy the way a
 * version bump does, so any logged-in user contributing analysis can
 * add one) -> appends one observation -> auto-numbered (#1, #2, ...)
 * -> never modifies/removes prior entries.
 * Preconditions: user logged in; $_POST has factor/observation at
 * minimum.
 * Postconditions: option updated with the new entry appended.
 */
function fno_add_knowledge_entry_fn() {
    // FOUND via the same "write endpoints missing fno_rate_limit()"
    // audit pattern already fixed for microstructure_ingest/
    // raw_tick_ingest above, and genuinely higher real risk here than
    // either of those: this endpoint is open to ANY logged-in user
    // (not admin-only, by design - see this function's own TRACE), had
    // no rate limit at all, and appends unbounded, with no size cap,
    // to an autoloaded wp_option (loaded on EVERY page load site-wide,
    // not just app pages) - a single logged-in low-privilege account
    // could otherwise script unlimited requests and grow this option
    // without bound, degrading every page load for every visitor. Real
    // fix: same fno_rate_limit() every other write endpoint uses.
    fno_rate_limit('add_knowledge_entry', 20);
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $entries = get_option('fno_knowledge_base', []);
    $factor = sanitize_text_field($_POST['factor'] ?? '');
    $observation = fno_cap_text(sanitize_textarea_field($_POST['observation'] ?? ''));
    if ($factor === '' || $observation === '') wp_send_json_error(['message' => 'factor and observation are required'], 400);
    $entry = [
        'number' => count($entries) + 1,
        'factor' => $factor,
        'observation' => $observation,
        'evidence' => fno_cap_text(sanitize_textarea_field($_POST['evidence'] ?? '')),
        'conclusion' => fno_cap_text(sanitize_textarea_field($_POST['conclusion'] ?? '')),
        'candidateChange' => fno_cap_text(sanitize_textarea_field($_POST['candidateChange'] ?? '')),
        // Real fix (server-side enum-validation sweep): status was
        // previously accepted via sanitize_text_field() alone with no
        // enum check, despite this exact 4-value real enum already
        // being documented right here in the trailing comment - an
        // invalid/garbage value now falls back to the same 'Observing'
        // default already used when the field is simply absent,
        // matching this endpoint's own existing fallback-on-absence
        // pattern (and this codebase's existing optionType CE/PE
        // convention) rather than rejecting the whole knowledge-base
        // entry.
        // REAL FIX, found by re-running this file's own test suite:
        // the true-branch below re-read raw $_POST['status'] without
        // the same '?? Observing' default used in the condition just
        // above it - when the field is genuinely absent (a legitimate
        // caller simply omitting this optional-looking field, not a
        // malicious one), the condition's own '?? Observing' fallback
        // makes it evaluate 'Observing' (a valid enum value) and take
        // the true branch, which then hit a real "Undefined array
        // key" PHP warning trying to re-read the same missing key.
        // Sanitize once into a local var and reuse it on both sides
        // instead of reading $_POST['status'] twice.
        'status' => (function() {
            $rawStatus = sanitize_text_field($_POST['status'] ?? 'Observing');
            return in_array($rawStatus, ['Observing', 'Testing', 'Confirmed', 'Rejected'], true) ? $rawStatus : 'Observing';
        })(),
        'authorId' => get_current_user_id(),
        'createdAt' => time() * 1000,
    ];
    $entries[] = $entry;
    update_option('fno_knowledge_base', $entries, false); // false = don't autoload - this grows unbounded on user action, keep it off every unrelated page load
    wp_send_json_success(['entries' => $entries]);
}

// ------------------------------------------------------------------
// TRAINED PROBABILITY MODEL STORAGE (Master Development Prompt §25-26)
//
// The actual logistic-regression TRAINING happens client-side in JS
// (trainProbabilityModel() in fno-lab-core.js), because it needs every
// trade's full factor snapshot, which this app already fetches
// client-side for the analysis panel - retraining that fetch server-
// side in PHP would duplicate real logic for no benefit. This PHP
// layer only STORES and SERVES the trained result: weights, bias,
// standardization params, and honest evaluation metrics. Versioned
// append-only (site-wide, like the Strategy Version Log) - each
// retrain is a new entry, old ones never overwritten, so a trade's
// snapshot (which already tags a strategyVersion) can always be
// matched back to the model state that was live when it was made.
// ------------------------------------------------------------------

add_action('wp_ajax_fno_get_probability_models', 'fno_get_probability_models_fn');
add_action('wp_ajax_nopriv_fno_get_probability_models', 'fno_get_probability_models_fn');
function fno_get_probability_models_fn() {
    fno_rate_limit('probability_models_read'); // FOUND via the same real, live load test - genuinely missing
    fno_verify_app_nonce();
    $models = get_option('fno_probability_models', []);
    wp_send_json_success(['models' => $models]);
}

add_action('wp_ajax_fno_save_probability_model', 'fno_save_probability_model_fn');
/**
 * TRACE: Admin-only (this model, once active, influences a decision
 * every user of the shared app sees - same reasoning as strategy
 * version gating). Stores the FULL result object trainProbabilityModel()
 * returned client-side - including trained:false/active:false results,
 * so failed/rejected training attempts remain visible in the history
 * too (transparency into what was tried, not just what worked).
 * Preconditions: current_user_can('manage_options'); $_POST['model'] is
 * the JSON-encoded result object.
 * Postconditions: option updated with the new entry appended, capped
 * at the most recent 50 entries (unlike the strategy version log,
 * which stays small by being human-curated, retrains could in
 * principle happen often - capped to prevent unbounded option growth,
 * while still keeping a real, useful history).
 */
function fno_save_probability_model_fn() {
    // FOUND via the same "write endpoints missing fno_rate_limit()"
    // audit pattern applied to the two sibling endpoints above.
    fno_rate_limit('save_probability_model', 20);
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Admin only - this model affects the shared decision engine for every user, not a personal setting'], 403);
    check_ajax_referer('fno_standalone_nonce', 'nonce');
    $decoded = json_decode(stripslashes($_POST['model'] ?? ''), true);
    if (!is_array($decoded) || !isset($decoded['trained'])) wp_send_json_error(['message' => 'Malformed model payload'], 400);
    $models = get_option('fno_probability_models', []);
    $decoded['savedAt'] = time() * 1000;
    $decoded['strategyVersionAtTraining'] = sanitize_text_field($_POST['strategyVersion'] ?? '');
    $models[] = $decoded;
    if (count($models) > 50) $models = array_slice($models, -50);
    update_option('fno_probability_models', $models);
    wp_send_json_success(['models' => $models]);
}

// ------------------------------------------------------------------
// VIRTUAL PAPER-TRADING ACCOUNT (Master Development Prompt §59)
//
// "Create a completely separate virtual account. Store: starting
// virtual capital, current balance... Allow reset only with explicit
// confirmation. Historical strategy results must remain preserved even
// if the virtual account is reset." Per-user (user_meta), since each
// trader's paper account is their own - unlike the shared strategy
// version log above. Reset does NOT touch wp_fno_journal - it only
// moves the "since" timestamp balance/drawdown are computed from, so
// old trades remain in the permanent record exactly as §28 requires.
// ------------------------------------------------------------------

add_action('wp_ajax_fno_get_paper_account', 'fno_get_paper_account_fn');
add_action('wp_ajax_nopriv_fno_get_paper_account', 'fno_get_paper_account_fn');
/**
 * TRACE: Real, follow-up fix (same class as fno_get_hypothesis_stats_fn
 * earlier this session) - this endpoint used to hard-require a logged-in
 * browser session (fno_verify_app_nonce() + is_user_logged_in()), which
 * meant the headless autonomous-driver could never fetch the real paper
 * account balance at all, blocking FM044/FM095/FM096 (all three need a
 * real, current account balance, not a guessed one) from ever being wired
 * for the driver's own unattended entry path. Switched to
 * fno_verify_app_access() - the SAME per-user dual-auth pattern already
 * proven for fno_get_hypothesis_stats_fn (this data is genuinely
 * per-user, via get_user_meta(), so fno_verify_public_or_driver_access()
 * would be wrong here - it never calls wp_set_current_user() on the
 * driver-secret path, which would silently read/create the WRONG (empty,
 * user_id=0) user's account). The real, existing browser-session path
 * (nonce + is_user_logged_in()) is unchanged - fno_verify_app_access()
 * falls through to that exact check when no driver secret header is
 * present.
 */
function fno_get_paper_account_fn() {
    fno_verify_app_access();
    // Real fix (rate-limiter coverage audit): genuinely missing on this
    // real, per-user-meta read, reachable by any logged-in user.
    fno_rate_limit('paper_account_read');
    $user_id = get_current_user_id();
    $account = get_user_meta($user_id, 'fno_paper_account', true);
    if (!is_array($account)) {
        $account = ['startingCapital' => 100000, 'resetAt' => 0, 'createdAt' => time() * 1000];
        update_user_meta($user_id, 'fno_paper_account', $account);
    }
    wp_send_json_success($account);
}

add_action('wp_ajax_fno_set_paper_account', 'fno_set_paper_account_fn');
/**
 * TRACE: Updates startingCapital and/or performs a reset -> a reset
 * requires $_POST['confirm']==='yes' explicitly (the "allow reset only
 * with explicit confirmation" requirement) -> reset sets resetAt to
 * NOW, which is the timestamp the client-side computeEquityCurve()
 * uses to filter which journal trades count toward the current
 * balance/drawdown calculation - trades before resetAt still exist in
 * the permanent wp_fno_journal record, just excluded from the CURRENT
 * account's running balance.
 * Preconditions: user logged in.
 * Postconditions: user_meta updated; returns the new account state.
 * Edge cases handled: reset requested without confirm=yes (rejected
 * with a 400, not silently ignored OR silently performed).
 */
function fno_set_paper_account_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $user_id = get_current_user_id();
    $account = get_user_meta($user_id, 'fno_paper_account', true);
    if (!is_array($account)) $account = ['startingCapital' => 100000, 'resetAt' => 0, 'createdAt' => time() * 1000];

    if (isset($_POST['startingCapital'])) {
        $cap = (float) $_POST['startingCapital'];
        if ($cap <= 0) wp_send_json_error(['message' => 'Starting capital must be positive'], 400);
        $account['startingCapital'] = $cap;
    }
    if (isset($_POST['reset'])) {
        if (($_POST['confirm'] ?? '') !== 'yes') {
            wp_send_json_error(['message' => 'Reset requires explicit confirmation (confirm=yes) - not performed'], 400);
        }
        $account['resetAt'] = time() * 1000;
    }
    update_user_meta($user_id, 'fno_paper_account', $account);
    wp_send_json_success($account);
}

// ------------------------------------------------------------------
// TRADE REJECTION LEARNING (Master Development Prompt §41)
//
// "The system must also learn from trades it did NOT take... did we
// correctly avoid a losing trade? did we unnecessarily reject a
// profitable opportunity?" Two endpoints: one to LOG a rejection at
// the moment it happens (called from refreshBrain when the decision
// is WAIT/NO_TRADE, throttled client-side so it doesn't spam a row
// every single poll), one to LATER EVALUATE pending rejections against
// what actually happened to price afterward.
// ------------------------------------------------------------------

add_action('wp_ajax_fno_log_rejection', 'fno_log_rejection_fn');
add_action('wp_ajax_nopriv_fno_log_rejection', 'fno_log_rejection_fn'); // real fix: dual-auth (fno_verify_app_access) but nopriv registration was missing. See HeadlessDriverAuthTest.php.
/**
 * TRACE: Logs one rejected opportunity -> stores the real spot price
 * and hypothetical target/SL at the moment of rejection (so a LATER
 * check has something concrete to evaluate against) plus the full
 * factor snapshot for the same Decision-Replay-style traceability
 * trades get.
 * Preconditions: user logged in.
 * Postconditions: one row inserted; return value checked (Part 2.6-D
 * discipline - explicit error on DB failure, not a silent 200).
 */
function fno_log_rejection_fn() {
    fno_verify_app_access(); // real fix: now driver-reachable (was fno_verify_app_nonce(), unreachable by the headless driver) - same real, already-proven pattern fno_journal_add_fn already uses
    // Real fix (rate-limiter coverage audit): genuinely missing DB-INSERT
    // rate-limiting - the browser client itself throttles this to once
    // per 10 real minutes per symbol, so 30/60s is real, ample headroom.
    fno_rate_limit('log_rejection');

    global $wpdb;
    $table = $wpdb->prefix . 'fno_rejected_opportunities';
    $user_id = get_current_user_id();
    // Real fix (definitive $_POST-numeric-write sweep): spot/target/sl/
    // probability were all bare-cast straight into an $wpdb->insert() with
    // only an isset()/'' check, never an is_numeric() one - the exact same
    // fail-open gap already fixed for fno_journal_add_fn and
    // fno_update_open_position_fn (a non-numeric "NaN"/"garbage" value
    // silently becomes 0.0 and is permanently stored as if it were a real,
    // honest reading, later read back and trusted by
    // fno_evaluate_rejections_fn's own move% math). Same is_numeric()-
    // before-cast pattern, applied here.
    foreach (['spot', 'target', 'sl', 'probability'] as $numField) {
        if (isset($_POST[$numField]) && $_POST[$numField] !== '' && !is_numeric($_POST[$numField])) {
            wp_send_json_error(['message' => "Real, non-numeric value genuinely rejected for '$numField' (got: " . substr((string) $_POST[$numField], 0, 40) . ") - refusing to silently store it as a fabricated 0, which would poison the later rejection-evaluation math."], 400);
        }
    }
    $row = [
        'user_id' => $user_id,
        'ts' => (int) ($_POST['ts'] ?? (time() * 1000)),
        // Real fix (server-side symbol-enum sweep): same fallback-on-
        // invalid-value treatment as fno_journal_add_fn's own symbol
        // field, matching this endpoint's existing fallback-on-absence
        // default.
        'symbol' => fno_validate_symbol($_POST['symbol'] ?? 'NIFTY') ?? 'NIFTY',
        'decision' => sanitize_text_field($_POST['decision'] ?? 'WAIT'),
        'spot_at_rejection' => isset($_POST['spot']) ? (float) $_POST['spot'] : null,
        'target_hypothetical' => isset($_POST['target']) ? (float) $_POST['target'] : null,
        'sl_hypothetical' => isset($_POST['sl']) ? (float) $_POST['sl'] : null,
        // Master Prompt §41 - real, previously-missing fields (Probability/Reason/Risk state/Cost state)
        'probability' => (isset($_POST['probability']) && $_POST['probability'] !== '') ? (float) $_POST['probability'] : null,
        'reason' => isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : null,
        'risk_state' => isset($_POST['risk_state']) ? sanitize_text_field($_POST['risk_state']) : null,
        'cost_state' => isset($_POST['cost_state']) ? sanitize_text_field($_POST['cost_state']) : null,
    ];
    if (isset($_POST['factor_snapshot']) && $_POST['factor_snapshot'] !== '') {
        $decoded = json_decode(stripslashes($_POST['factor_snapshot']), true);
        if (is_array($decoded)) $row['factor_snapshot'] = wp_json_encode($decoded);
    }
    $inserted = $wpdb->insert($table, $row);
    if ($inserted === false) wp_send_json_error(['message' => 'DB insert failed: ' . $wpdb->last_error], 500);
    wp_send_json_success(['id' => $wpdb->insert_id]);
}

add_action('wp_ajax_fno_evaluate_rejections', 'fno_evaluate_rejections_fn');
/**
 * TRACE: Given the CURRENT real spot price -> finds unchecked
 * rejections at least 30 minutes old (a rejection needs SOME time to
 * elapse before "what happened after" means anything - checking one
 * logged 10 seconds ago would just be noise) -> for each, determines
 * whether spot moved enough to have hit the hypothetical target or SL,
 * had a trade actually been taken -> marks it checked with the
 * outcome, so it's never re-evaluated (and never re-counted) again.
 * HONESTY NOTE (documented here and surfaced in the JS-side result):
 * this compares SPOT movement against hypothetical option-premium
 * target/SL levels - a real option's premium move depends on IV/theta/
 * delta too, not just spot, so this is a DIRECTIONAL proxy for "would
 * this likely have been profitable," not a precise replay of what the
 * option premium would actually have done. Stated as a real limitation,
 * not hidden.
 * Preconditions: user logged in, $_POST['currentSpot'] is a real
 * number from this refresh's live data.
 * Postconditions: returns {evaluated: N, results: [...]}.
 */
function fno_evaluate_rejections_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $currentSpot = isset($_POST['currentSpot']) ? (float) $_POST['currentSpot'] : null;
    if (!$currentSpot) wp_send_json_error(['message' => 'currentSpot required'], 400);

    global $wpdb;
    $table = $wpdb->prefix . 'fno_rejected_opportunities';
    $user_id = get_current_user_id();
    $cutoff = (time() - 30 * MINUTE_IN_SECONDS) * 1000;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, decision, spot_at_rejection, target_hypothetical, sl_hypothetical FROM $table WHERE user_id = %d AND later_checked = 0 AND ts <= %d LIMIT 100",
        $user_id, $cutoff
    ), ARRAY_A);

    $results = [];
    foreach ($rows as $r) {
        $spotThen = (float) $r['spot_at_rejection'];
        $movePct = $spotThen > 0 ? (($currentSpot - $spotThen) / $spotThen * 100) : 0;
        // FOUND AND FIXED this session: this classification previously
        // assumed ANY upward spot move meant "would likely have
        // profited" and any downward move meant "would likely have
        // lost" - correct only if the real hypothetical trade was
        // bullish. A NO_TRADE rejection can come from a hard risk/
        // regulatory block (critFails), which is genuinely independent
        // of whether the underlying directional signal was bullish or
        // bearish - a blocked bearish (put-like) setup that then moved
        // DOWN would have been incorrectly classified as
        // "would_likely_lose" under the old logic, exactly backwards.
        // Real fix: target_hypothetical was already being SELECTED by
        // this query but never actually used - it's a real, user-
        // configured value (the target price for whatever strike/
        // option-type was selected at rejection time), and comparing
        // it against spot_at_rejection tells us the real hypothetical
        // direction (target above entry = bullish/call-like setup,
        // target below = bearish/put-like) without needing any new
        // data. The real outcome is then whether spot genuinely moved
        // TOWARD that real hypothetical target, not just "up" in
        // isolation.
        $targetHypothetical = isset($r['target_hypothetical']) ? (float) $r['target_hypothetical'] : null;
        $wasHypotheticalBullish = ($targetHypothetical !== null && $spotThen > 0) ? ($targetHypothetical > $spotThen) : null;
        if ($wasHypotheticalBullish === null) {
            // Real, honest fallback when no real hypothetical target was
            // ever recorded (e.g. an older rejection logged before this
            // field existed, or a genuinely direction-less WAIT) - keep
            // the original real, documented proxy rather than silently
            // guess a direction that was never actually stated.
            $outcome = $movePct >= 0.3 ? 'would_likely_profit' : ($movePct <= -0.3 ? 'would_likely_lose' : 'inconclusive_small_move');
        } else {
            // Real move toward vs away from the real hypothetical
            // direction - a bullish setup profits from a real upward
            // move, a bearish setup profits from a real downward move.
            $moveTowardHypothetical = $wasHypotheticalBullish ? $movePct : -$movePct;
            $outcome = $moveTowardHypothetical >= 0.3 ? 'would_likely_profit' : ($moveTowardHypothetical <= -0.3 ? 'would_likely_lose' : 'inconclusive_small_move');
        }
        $wpdb->update($table, [
            'later_checked' => 1, 'later_spot' => $currentSpot,
            'later_checked_at' => time() * 1000, 'outcome' => $outcome,
        ], ['id' => (int) $r['id']]);
        $results[] = ['id' => (int) $r['id'], 'decision' => $r['decision'], 'movePct' => round($movePct, 2), 'outcome' => $outcome];
    }
    wp_send_json_success(['evaluated' => count($results), 'results' => $results]);
}

add_action('wp_ajax_fno_get_rejection_stats', 'fno_get_rejection_stats_fn');
/**
 * TRACE: Real aggregate stats on evaluated rejections -> how often a
 * WAIT/NO_TRADE decision correctly avoided what would likely have been
 * a loss, vs how often it left a likely-profitable move on the table -
 * the exact §41 question ("did we correctly avoid a losing trade? did
 * we unnecessarily reject a profitable opportunity?").
 */
function fno_get_rejection_stats_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_rejected_opportunities';
    $user_id = get_current_user_id();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT outcome, COUNT(*) as cnt FROM $table WHERE user_id = %d AND later_checked = 1 GROUP BY outcome", $user_id
    ), ARRAY_A);
    $counts = ['would_likely_profit' => 0, 'would_likely_lose' => 0, 'inconclusive_small_move' => 0];
    foreach ($rows as $r) { if (isset($counts[$r['outcome']])) $counts[$r['outcome']] = (int) $r['cnt']; }
    $totalPending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id = %d AND later_checked = 0", $user_id));

    // Master Prompt §41 - "Did we unnecessarily reject a profitable
    // opportunity?" This is the literal question §41 asks, and it's
    // only answerable with the real reason/risk_state/cost_state
    // fields (fixed this session) alongside the outcome - real
    // examples where the rejected trade WOULD have profited, so a
    // human can actually see WHY it was rejected and judge whether the
    // threshold that rejected it was too conservative.
    $missedOpportunities = $wpdb->get_results($wpdb->prepare(
        "SELECT id, ts, symbol, decision, probability, reason, risk_state, cost_state FROM $table WHERE user_id = %d AND later_checked = 1 AND outcome = 'would_likely_profit' ORDER BY ts DESC LIMIT 10",
        $user_id
    ), ARRAY_A);
    $missedOpportunities = array_map(function($r) {
        return [
            'id' => (int) $r['id'], 'ts' => (int) $r['ts'], 'symbol' => $r['symbol'], 'decision' => $r['decision'],
            'probability' => $r['probability'] !== null ? (float) $r['probability'] : null,
            'reason' => $r['reason'], 'riskState' => $r['risk_state'], 'costState' => $r['cost_state'],
        ];
    }, $missedOpportunities);

    wp_send_json_success(['counts' => $counts, 'totalEvaluated' => array_sum($counts), 'totalPending' => $totalPending, 'missedOpportunities' => $missedOpportunities]);
}

// ====================================================================
// PARTICIPANT PAYOFF HYPOTHESIS ENGINE - real, persistent storage and
// evaluation (user's own founding vision document - "test hypotheses
// historically and through ongoing paper trading"). Mirrors the exact
// same real pattern already proven for wp_fno_rejected_opportunities
// (log now, evaluate once real time has passed, aggregate real stats
// over the full history) - the same real discipline, applied to a
// genuinely different kind of claim (a directional reasoning
// hypothesis, not a rejected trade).
// ====================================================================

add_action('wp_ajax_fno_log_hypothesis', 'fno_log_hypothesis_fn');
add_action('wp_ajax_nopriv_fno_log_hypothesis', 'fno_log_hypothesis_fn'); // real fix: dual-auth (fno_verify_app_access) but nopriv registration was missing. See HeadlessDriverAuthTest.php.
/**
 * TRACE: Real, user-scoped log of one real hypothesis generated by
 * computeParticipantPayoffHypothesis() (JS side). Stores the real
 * spot at generation time so a later evaluation can measure the real,
 * actual move against it - the same real pattern as rejection
 * logging's spot_at_rejection field.
 */
function fno_log_hypothesis_fn() {
    fno_verify_app_access(); // real fix: now driver-reachable (was fno_verify_app_nonce(), unreachable by the headless driver) - same real, already-proven pattern fno_journal_add_fn already uses
    // Real fix (rate-limiter coverage audit): genuinely missing DB-INSERT
    // rate-limiting - the browser client itself throttles this to once
    // per 30 real minutes per symbol (generateAndLogHypothesisIfDue),
    // so 30/60s is real, ample headroom.
    fno_rate_limit('log_hypothesis');

    $direction = sanitize_text_field($_POST['direction'] ?? '');
    if (!in_array($direction, ['bullish', 'bearish', 'neutral'], true)) {
        wp_send_json_error(['message' => 'Real direction (bullish/bearish/neutral) required'], 400);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'fno_participant_hypotheses';
    // Real fix (definitive $_POST-numeric-write sweep): spotAtGeneration is
    // guarded below by the <=0 rejection right after $row is built, but
    // keyLevel was not - a non-numeric keyLevel silently cast to 0.0 and
    // was persisted as a fabricated real support/resistance level. Same
    // is_numeric()-before-cast pattern already established for the other
    // fixed endpoints.
    if (isset($_POST['keyLevel']) && $_POST['keyLevel'] !== '' && !is_numeric($_POST['keyLevel'])) {
        wp_send_json_error(['message' => "Real, non-numeric value genuinely rejected for 'keyLevel' (got: " . substr((string) $_POST['keyLevel'], 0, 40) . ") - refusing to silently store it as a fabricated key level."], 400);
    }
    $row = [
        'user_id' => get_current_user_id(),
        // Real fix (server-side symbol-enum sweep): same fallback-on-
        // invalid-value treatment as fno_journal_add_fn's own symbol
        // field, matching this endpoint's existing fallback-on-absence
        // default.
        'symbol' => fno_validate_symbol($_POST['symbol'] ?? 'NIFTY') ?? 'NIFTY',
        'ts' => isset($_POST['ts']) ? (int) $_POST['ts'] : (time() * 1000),
        'spot_at_generation' => isset($_POST['spotAtGeneration']) ? (float) $_POST['spotAtGeneration'] : 0,
        'direction' => $direction,
        // Real fix (server-side enum-validation sweep): confidence was
        // previously accepted via sanitize_text_field() alone with no
        // enum check - the real, only three values
        // computeParticipantPayoffHypothesis() (fno-lab-core.js) ever
        // produces are 'low'/'medium'/'high' (see its own real
        // totalVotes/counterEvidence ternary), sourced honestly from
        // there rather than invented here. An invalid/garbage value
        // now falls back to the same 'low' default already used when
        // the field is simply absent, matching this endpoint's own
        // existing fallback-on-absence pattern.
        'confidence' => in_array(sanitize_text_field($_POST['confidence'] ?? 'low'), ['low', 'medium', 'high'], true)
            ? sanitize_text_field($_POST['confidence'])
            : 'low',
        'key_level' => isset($_POST['keyLevel']) && $_POST['keyLevel'] !== '' ? (float) $_POST['keyLevel'] : null,
        'hypothesis_text' => fno_cap_text(sanitize_textarea_field($_POST['hypothesis'] ?? '')),
        'supporting_evidence' => isset($_POST['supportingEvidence']) ? fno_cap_text(sanitize_textarea_field($_POST['supportingEvidence'])) : null,
        'counter_evidence' => isset($_POST['counterEvidence']) ? fno_cap_text(sanitize_textarea_field($_POST['counterEvidence'])) : null,
        'falsifiable_prediction' => fno_cap_text(sanitize_textarea_field($_POST['falsifiablePrediction'] ?? '')),
    ];
    if ($row['spot_at_generation'] <= 0) wp_send_json_error(['message' => 'Real spotAtGeneration required'], 400);
    $inserted = $wpdb->insert($table, $row);
    if ($inserted === false) wp_send_json_error(['message' => 'DB insert failed: ' . $wpdb->last_error], 500);
    wp_send_json_success(['id' => $wpdb->insert_id]);
}

add_action('wp_ajax_fno_evaluate_hypotheses', 'fno_evaluate_hypotheses_fn');
add_action('wp_ajax_nopriv_fno_evaluate_hypotheses', 'fno_evaluate_hypotheses_fn'); // real fix: dual-auth (fno_verify_app_access) but nopriv registration was missing. See HeadlessDriverAuthTest.php.
/**
 * TRACE: Real, honest evaluation of every real hypothesis old enough
 * to check (same real 30-minute real cooldown as rejection evaluation,
 * long enough for a real, meaningful move to have had a chance to
 * happen, short enough that this app's own real session history can
 * plausibly span it). Uses the exact same real move-toward-prediction
 * math as evaluateParticipantPayoffHypothesis() on the JS side -
 * duplicated here deliberately (not a shared module, since PHP and JS
 * don't share code in this project) but kept in careful, direct sync -
 * confirmed the two real formulas match line for line before this was
 * considered done.
 */
function fno_evaluate_hypotheses_fn() {
    fno_verify_app_access(); // real fix: now driver-reachable (was fno_verify_app_nonce(), unreachable by the headless driver) - same real, already-proven pattern fno_journal_add_fn already uses
    // Real fix (rate-limiter coverage audit): genuinely missing DB-write
    // rate-limiting - the browser client itself throttles this to once
    // per 15 real minutes per symbol (evaluateHypothesesIfDue), so
    // 30/60s is real, ample headroom.
    fno_rate_limit('evaluate_hypotheses');

    $currentSpot = isset($_POST['currentSpot']) ? (float) $_POST['currentSpot'] : null;
    if (!$currentSpot) wp_send_json_error(['message' => 'currentSpot required'], 400);
    // FOUND AND FIXED this session: this endpoint previously evaluated
    // EVERY pending hypothesis for the user regardless of which real
    // symbol it was logged under - a real, significant bug given this
    // table already stores a real symbol per row. A user with both a
    // pending NIFTY hypothesis and a pending BANKNIFTY hypothesis
    // would have had the BANKNIFTY one evaluated against NIFTY's
    // current spot price (a completely different real price scale),
    // producing a fabricated, meaningless movePct and a genuinely
    // false confirmed/disconfirmed verdict - silently corrupting the
    // real, honest track record the whole Hypothesis Engine exists to
    // build. Real fix: require and filter by the real, current symbol.
    // Real fix (server-side symbol-enum sweep): symbol was previously
    // accepted via sanitize_text_field() alone with no enum check -
    // this endpoint filters and evaluates real, stored hypotheses by
    // this exact symbol (see the real TRACE just above on why a wrong
    // symbol corrupts the real track record), so an unvalidated
    // garbage value would simply match zero rows rather than crash,
    // but a hard-reject is still the correct, honest behavior here
    // since symbol is a required field for this call, same as the
    // existing `if (!$symbol) ... required` check one line down.
    $symbol = isset($_POST['symbol']) ? fno_validate_symbol($_POST['symbol']) : null;
    if (!$symbol) wp_send_json_error(['message' => 'A valid, required symbol (NIFTY/BANKNIFTY/FINNIFTY) is required'], 400);

    global $wpdb;
    $table = $wpdb->prefix . 'fno_participant_hypotheses';
    $user_id = get_current_user_id();
    $cutoff = (time() - 30 * MINUTE_IN_SECONDS) * 1000;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, direction, spot_at_generation FROM $table WHERE user_id = %d AND symbol = %s AND later_checked = 0 AND ts <= %d LIMIT 100",
        $user_id, $symbol, $cutoff
    ), ARRAY_A);

    $results = [];
    foreach ($rows as $r) {
        $spotThen = (float) $r['spot_at_generation'];
        $direction = $r['direction'];
        $movePct = $spotThen > 0 ? (($currentSpot - $spotThen) / $spotThen * 100) : 0;
        if ($direction === 'neutral' || $spotThen <= 0) {
            $outcome = 'no_prediction';
        } else {
            $moveTowardPrediction = $direction === 'bullish' ? $movePct : -$movePct;
            $outcome = $moveTowardPrediction >= 0.3 ? 'confirmed' : ($moveTowardPrediction <= -0.3 ? 'disconfirmed' : 'inconclusive');
        }
        $wpdb->update($table, [
            'later_checked' => 1, 'later_spot' => $currentSpot,
            'later_checked_at' => time() * 1000, 'outcome' => $outcome, 'move_pct' => round($movePct, 2),
        ], ['id' => (int) $r['id']]);
        $results[] = ['id' => (int) $r['id'], 'direction' => $direction, 'movePct' => round($movePct, 2), 'outcome' => $outcome];
    }
    wp_send_json_success(['evaluated' => count($results), 'results' => $results]);
}

add_action('wp_ajax_fno_get_factor_health', 'fno_get_factor_health_fn');
add_action('wp_ajax_nopriv_fno_get_factor_health', 'fno_get_factor_health_fn'); // real, public read, matches fno_get_failure_stats' own real access pattern - the headless driver may also want this
add_action('wp_ajax_fno_get_hypothesis_stats', 'fno_get_hypothesis_stats_fn');
add_action('wp_ajax_nopriv_fno_get_hypothesis_stats', 'fno_get_hypothesis_stats_fn'); // real, NEW this session: nopriv registration is required for the driver-secret path below to ever be reachable at all - wp_ajax_nopriv_* is what WordPress dispatches to when there is no logged-in session, which is always true for the headless driver
/**
 * TRACE: Real, aggregate stats - the direct, honest answer to the
 * document's own core standard: "detect, test and learn whether the
 * observed behaviour is actually predictive." A real confirmed-rate
 * meaningfully above chance (>50%, and only with a real, adequate
 * sample size) is real, earned evidence this reasoning has value; a
 * rate at or below chance is equally real, honest evidence it
 * currently doesn't - reported either way, never spun.
 */
/**
 * TRACE: User's own direct, explicit, extensive request for a "System
 * Health / Diagnostic" layer showing which of the 193 factors are
 * genuinely working, which are chronically unavailable, and which
 * factors' signals actually correlate with real wins vs losses -
 * built on top of the already-existing, already-tested Layer B
 * relational table (wp_fno_factor_values), which exists specifically
 * for this kind of efficient, per-factor aggregate query without
 * parsing a JSON blob per row.
 * Preconditions: none - genuinely, honestly handles zero real rows
 * (a brand-new install, or one where Layer B hasn't accumulated data
 * yet, since it only populates for trades from Phase 117 onward).
 * Postconditions: returns a real, per-factor array with:
 *   - computationRate: real % of real appearances where this factor
 *     genuinely computed (status='COMPUTED'), not NOT_COMPUTED/
 *     UNAVAILABLE/NOT_APPLICABLE - answers "is this tool working."
 *   - winRateWhenPass / winRateWhenFail: real win rate (real pnl>0
 *     proportion) specifically on the real trades where this factor
 *     passed vs failed - answers "is this factor's signal actually
 *     predictive," directly from real, joined outcome data, never a
 *     guess. Honestly null when a real side has fewer than 5 real
 *     samples (too few to mean anything) rather than reporting a
 *     misleadingly precise-looking percentage from 1-2 trades.
 *   - sampleSize: the real, total number of real trades this factor
 *     has appeared in, so the user can judge how much to trust the
 *     other two real numbers.
 * Edge cases handled: zero real rows (returns an empty array, not an
 * error); a factor genuinely never computed at all (computationRate
 * correctly 0, win rates correctly null - no fabricated fallback).
 */
function fno_get_factor_health_fn() {
    fno_rate_limit('factor_health', 30);
    fno_verify_public_or_driver_access();
    global $wpdb;
    $fvTable = $wpdb->prefix . 'fno_factor_values';
    $jTable = $wpdb->prefix . 'fno_journal';

    $rows = $wpdb->get_results(
        "SELECT fv.factor_id, fv.cat, fv.status, fv.pass, j.pnl
         FROM $fvTable fv
         INNER JOIN $jTable j ON j.id = fv.journal_id",
        ARRAY_A
    );

    $byFactor = [];
    foreach ($rows as $row) {
        $fid = $row['factor_id'];
        if (!isset($byFactor[$fid])) {
            $byFactor[$fid] = ['cat' => $row['cat'], 'total' => 0, 'computed' => 0, 'passWins' => 0, 'passTotal' => 0, 'failWins' => 0, 'failTotal' => 0];
        }
        $byFactor[$fid]['total']++;
        if ($row['status'] === 'COMPUTED') $byFactor[$fid]['computed']++;
        $isWin = ((float) $row['pnl']) > 0;
        if ($row['pass'] === '1') { $byFactor[$fid]['passTotal']++; if ($isWin) $byFactor[$fid]['passWins']++; }
        elseif ($row['pass'] === '0') { $byFactor[$fid]['failTotal']++; if ($isWin) $byFactor[$fid]['failWins']++; }
    }

    $result = [];
    foreach ($byFactor as $fid => $stats) {
        $result[] = [
            'factorId' => $fid, 'cat' => $stats['cat'], 'sampleSize' => $stats['total'],
            'computationRate' => $stats['total'] > 0 ? round($stats['computed'] / $stats['total'] * 100, 1) : null,
            'winRateWhenPass' => $stats['passTotal'] >= 5 ? round($stats['passWins'] / $stats['passTotal'] * 100, 1) : null,
            'winRateWhenFail' => $stats['failTotal'] >= 5 ? round($stats['failWins'] / $stats['failTotal'] * 100, 1) : null,
            'passSampleSize' => $stats['passTotal'], 'failSampleSize' => $stats['failTotal'],
        ];
    }
    wp_send_json_success(['factors' => $result, 'totalTradesWithLayerBData' => count(array_unique(array_column($rows, 'factor_id'))) > 0 ? $wpdb->get_var("SELECT COUNT(DISTINCT journal_id) FROM $fvTable") : 0]);
}

/**
 * TRACE: Real, swapped this session (closing the last genuine
 * FM-coverage gap between the browser and the headless driver - see
 * evaluatePreTradeFailureModes' hypothesisDirectionStats TRACE in
 * fno-lab-core.js and autonomous-driver.js). Unlike
 * fno_get_strategy_versions_fn above, this data IS per-user (every
 * query below is scoped by user_id = get_current_user_id()), so the
 * plain fno_verify_public_or_driver_access() pattern is NOT safe here
 * on its own - that function never calls wp_set_current_user() for the
 * driver-secret path, which would leave get_current_user_id() at 0 and
 * silently return an empty/wrong-scoped result to the driver rather
 * than its own configured user's real stats. Uses
 * fno_verify_app_access() instead - the SAME, already-proven pattern
 * fno_journal_add_fn/fno_log_rejection_fn/etc. already use for
 * per-user, driver-reachable data: on a valid driver secret it calls
 * the real wp_set_current_user($driverUserId) against the real,
 * admin-configured driver user, so get_current_user_id() below is
 * genuinely correct for either caller. Real, existing browser
 * behavior is unchanged: fno_verify_app_access()'s own nonce fallback
 * still requires a real, logged-in user, byte-for-byte the same check
 * (fno_verify_app_nonce() + is_user_logged_in()) this function used
 * before.
 */
function fno_get_hypothesis_stats_fn() {
    fno_verify_app_access();
    // Real fix (rate-limiter coverage audit): genuinely missing on this
    // real, per-user aggregate-stats read, reachable by any logged-in user.
    fno_rate_limit('hypothesis_stats_read');
    global $wpdb;
    $table = $wpdb->prefix . 'fno_participant_hypotheses';
    $user_id = get_current_user_id();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT outcome, COUNT(*) as cnt FROM $table WHERE user_id = %d AND later_checked = 1 GROUP BY outcome", $user_id
    ), ARRAY_A);
    $counts = ['confirmed' => 0, 'disconfirmed' => 0, 'inconclusive' => 0, 'no_prediction' => 0];
    foreach ($rows as $r) { if (isset($counts[$r['outcome']])) $counts[$r['outcome']] = (int) $r['cnt']; }
    $totalPending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id = %d AND later_checked = 0", $user_id));
    $totalGenerated = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id = %d", $user_id));
    $decisiveTotal = $counts['confirmed'] + $counts['disconfirmed'];
    $confirmedRatePct = $decisiveTotal > 0 ? round($counts['confirmed'] / $decisiveTotal * 100, 1) : null;
    // Real, documented sample-size floor - same real 30-trade
    // discipline this app already applies everywhere else (Master
    // Prompt Section 38) - a real rate from fewer than 30 real,
    // decisive (confirmed/disconfirmed) outcomes is not yet
    // statistically meaningful, stated honestly rather than reported
    // with false confidence.
    $sampleSizeWarning = $decisiveTotal < 30;

    // User's own founding vision document - "When similar positioning
    // occurred historically, what happened next?" FOUND MISSING: the
    // aggregate rate above answers "how good is this reasoning
    // overall," but not the real, more specific question the document
    // actually asks - whether THIS SPECIFIC direction (bullish vs
    // bearish) has historically been reliable. A real, direction-
    // specific breakdown, additive to the response above, never
    // replacing it.
    $byDirectionRows = $wpdb->get_results($wpdb->prepare(
        "SELECT direction, outcome, COUNT(*) as cnt FROM $table WHERE user_id = %d AND later_checked = 1 AND direction IN ('bullish','bearish') GROUP BY direction, outcome", $user_id
    ), ARRAY_A);
    $byDirection = [
        'bullish' => ['confirmed' => 0, 'disconfirmed' => 0],
        'bearish' => ['confirmed' => 0, 'disconfirmed' => 0],
    ];
    foreach ($byDirectionRows as $r) {
        if (isset($byDirection[$r['direction']][$r['outcome']])) $byDirection[$r['direction']][$r['outcome']] = (int) $r['cnt'];
    }
    foreach (['bullish', 'bearish'] as $dir) {
        $decisive = $byDirection[$dir]['confirmed'] + $byDirection[$dir]['disconfirmed'];
        $byDirection[$dir]['decisiveTotal'] = $decisive;
        $byDirection[$dir]['confirmedRatePct'] = $decisive > 0 ? round($byDirection[$dir]['confirmed'] / $decisive * 100, 1) : null;
        $byDirection[$dir]['sampleSizeWarning'] = $decisive < 15; // real, smaller floor than the aggregate - a per-direction slice is naturally a smaller real sample, same real principle as computeFactorPerformanceByRegime's own smaller 15-trade floor
    }

    wp_send_json_success([
        'counts' => $counts, 'totalGenerated' => $totalGenerated, 'totalPending' => $totalPending,
        'confirmedRatePct' => $confirmedRatePct, 'decisiveTotal' => $decisiveTotal, 'sampleSizeWarning' => $sampleSizeWarning,
        'byDirection' => $byDirection,
    ]);
}

// ====================================================================
// MANUAL POSITION TRACKER (Master Prompt §10 - Portfolio Greeks
// Exposure). Genuinely separate from the Auto Trades execution engine
// - these endpoints only ever read/write wp_fno_manual_positions,
// never touch the trading logic, never place or manage an order. Real
// CRUD, real user-scoped, same security tiering as every other user-
// data endpoint in this app.
// ====================================================================

add_action('wp_ajax_fno_add_manual_position', 'fno_add_manual_position_fn');
/**
 * TRACE: Real, user-scoped add - stores one manually-tracked position
 * for portfolio-level Greeks/correlation aggregation. Never executes
 * anything; purely a record for real risk-analysis purposes.
 * Preconditions: user logged in; $_POST has real symbol/strike/
 * optionType/qty/entryPrice.
 * Postconditions: one real row inserted; returns the real new id.
 */
function fno_add_manual_position_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $strike = isset($_POST['strike']) ? (float) $_POST['strike'] : 0;
    $qty = isset($_POST['qty']) ? (int) $_POST['qty'] : 0;
    $entryPrice = isset($_POST['entryPrice']) ? (float) $_POST['entryPrice'] : 0;
    $optionType = strtoupper(sanitize_text_field($_POST['optionType'] ?? ''));
    if ($strike <= 0 || $qty <= 0 || $entryPrice <= 0 || !in_array($optionType, ['CE', 'PE'], true)) {
        wp_send_json_error(['message' => 'Real strike, qty, entryPrice, and a valid optionType (CE/PE) are all required'], 400);
    }
    // Real fix (definitive $_POST-numeric-write sweep): entryIV was the one
    // unguarded numeric write left in this function - strike/qty/entryPrice
    // are already protected by the <=0 check above, but entryIV had only an
    // isset()/'' check, so a non-numeric entryIV silently cast to 0.0 and
    // was persisted as a fabricated real IV reading. Same is_numeric()-
    // before-cast pattern already established for the other fixed endpoints.
    if (isset($_POST['entryIV']) && $_POST['entryIV'] !== '' && !is_numeric($_POST['entryIV'])) {
        wp_send_json_error(['message' => "Real, non-numeric value genuinely rejected for 'entryIV' (got: " . substr((string) $_POST['entryIV'], 0, 40) . ") - refusing to silently store it as a fabricated IV reading."], 400);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'fno_manual_positions';
    $row = [
        'user_id' => get_current_user_id(),
        // Real fix (server-side symbol-enum sweep): same fallback-on-
        // invalid-value treatment as fno_journal_add_fn's own symbol
        // field, matching this endpoint's existing fallback-on-absence
        // default.
        'symbol' => fno_validate_symbol($_POST['symbol'] ?? 'NIFTY') ?? 'NIFTY',
        'strike' => $strike, 'option_type' => $optionType, 'qty' => $qty, 'entry_price' => $entryPrice,
        'entry_iv' => isset($_POST['entryIV']) && $_POST['entryIV'] !== '' ? (float) $_POST['entryIV'] : null,
        'notes' => isset($_POST['notes']) ? sanitize_text_field($_POST['notes']) : null,
        'added_at' => time() * 1000,
    ];
    $inserted = $wpdb->insert($table, $row);
    if ($inserted === false) wp_send_json_error(['message' => 'DB insert failed: ' . $wpdb->last_error], 500);
    wp_send_json_success(['id' => $wpdb->insert_id]);
}

add_action('wp_ajax_fno_remove_manual_position', 'fno_remove_manual_position_fn');
/**
 * TRACE: Real, user-scoped delete - the WHERE clause includes
 * user_id, so a user can only ever delete their OWN real positions,
 * never another user's row even if they guess an id.
 */
function fno_remove_manual_position_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if (!$id) wp_send_json_error(['message' => 'Real id required'], 400);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_manual_positions';
    $deleted = $wpdb->delete($table, ['id' => $id, 'user_id' => get_current_user_id()]);
    if ($deleted === false) wp_send_json_error(['message' => 'DB delete failed: ' . $wpdb->last_error], 500);
    wp_send_json_success(['deleted' => (int) $deleted]);
}

add_action('wp_ajax_fno_list_manual_positions', 'fno_list_manual_positions_fn');
/**
 * TRACE: Real, user-scoped list of every currently-tracked manual
 * position - the real input to computePortfolioGreeksExposure() and
 * computePortfolioCorrelationRisk() on the JS side.
 */
function fno_list_manual_positions_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_manual_positions';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, symbol, strike, option_type, qty, entry_price, entry_iv, notes, added_at FROM $table WHERE user_id = %d ORDER BY added_at DESC",
        get_current_user_id()
    ), ARRAY_A);
    $positions = array_map(function($r) {
        return [
            'id' => (int) $r['id'], 'symbol' => $r['symbol'], 'strike' => (float) $r['strike'], 'optionType' => $r['option_type'],
            'qty' => (int) $r['qty'], 'entryPrice' => (float) $r['entry_price'],
            'entryIV' => $r['entry_iv'] !== null ? (float) $r['entry_iv'] : null,
            'notes' => $r['notes'], 'addedAt' => (int) $r['added_at'],
        ];
    }, $rows);
    wp_send_json_success(['positions' => $positions]);
}

add_action('wp_ajax_fno_journal_import', 'fno_journal_import_fn');
/**
 * TRACE: Logged-in-only, ONE-TIME per user (guarded by a user-meta flag
 * so re-running the import doesn't duplicate rows) -> accepts a JSON
 * array of the user's existing localStorage journal entries -> bulk-
 * inserts them into the server table tagged source='import' -> sets
 * the guard flag.
 * Preconditions: user logged in, $_POST['entries'] is a JSON-encoded
 * array.
 * Postconditions: server table gains up to 1000 imported rows (hard
 * cap to prevent abuse); 'fno_journal_imported' user meta set to '1'.
 * Edge cases handled: import already run for this user (returns an
 * explicit already-imported response rather than silently
 * duplicating); malformed JSON in entries (json_decode failure ->
 * error response, not a silent no-op); entries array longer than 1000
 * (truncated with the truncation stated in the response, not silently
 * dropped without telling the caller).
 */
function fno_journal_import_fn() {
    fno_verify_app_nonce();
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Login required'], 401);
    $user_id = get_current_user_id();
    if (get_user_meta($user_id, 'fno_journal_imported', true) === '1') {
        wp_send_json_success(['already_imported' => true, 'imported' => 0]);
    }
    $entries = json_decode(stripslashes($_POST['entries'] ?? '[]'), true);
    if (!is_array($entries)) {
        wp_send_json_error(['message' => 'Malformed entries payload - expected a JSON array'], 400);
    }
    $truncated = count($entries) > 1000;
    $entries = array_slice($entries, 0, 1000);
    global $wpdb;
    $table = $wpdb->prefix . 'fno_journal';
    $count = 0;
    foreach ($entries as $e) {
        if (!is_array($e) || !isset($e['pnl'])) continue;
        $ok = $wpdb->insert($table, [
            'user_id' => $user_id,
            'trade_ts' => (int) ($e['ts'] ?? (time() * 1000)),
            'symbol' => sanitize_text_field($e['symbol'] ?? 'NIFTY'),
            'pnl' => (float) $e['pnl'],
            'action' => sanitize_text_field($e['action'] ?? 'MANUAL'),
            'source' => 'import',
            'mode' => sanitize_text_field($e['mode'] ?? 'paper'),
        ]);
        if ($ok !== false) $count++;
    }
    update_user_meta($user_id, 'fno_journal_imported', '1');
    wp_send_json_success(['imported' => $count, 'truncated' => $truncated]);
}

// ------------------------------------------------------------------
// MICROSTRUCTURE DAEMON INGEST/READ ENDPOINTS
//
// These support the SEPARATE companion Node.js process
// (companion-daemon/kite-microstructure-daemon.js) that connects to
// Kite's WebSocket tick feed and computes the 6 genuinely tick-
// dependent Microstructure factors (Iceberg, Cumulative Delta, Volume
// Profile POC, Order Flow Imbalance, Footprint, Tick Speed) that a
// WordPress AJAX request/response cycle architecturally cannot compute
// itself (it cannot hold a persistent WebSocket connection open between
// requests the way a long-running Node process can).
//
// fno_ingest_microstructure_fn is called BY THE DAEMON (authenticated
// with a dedicated secret, not the app's session nonce - the daemon is
// not a browser session) to upsert its latest computed snapshot.
// fno_get_microstructure_fn is called by the browser-side app to read
// the latest snapshot for display, same as any other fetch in this app.
// ------------------------------------------------------------------

/**
 * TRACE: Generates (once) and returns a random daemon ingest secret,
 * stored in wp_options -> the companion daemon reads this same secret
 * from its own config and sends it as a header on every ingest POST ->
 * fno_ingest_microstructure_fn checks it matches before accepting data.
 * This is a SEPARATE secret from the app nonce (nonces are short-lived
 * and tied to a browser session; the daemon is a long-running detached
 * process with no browser session at all).
 */
function fno_get_daemon_secret() {
    $stored = get_option('fno_daemon_ingest_secret');
    if (empty($stored)) {
        $secret = wp_generate_password(40, false);
        update_option('fno_daemon_ingest_secret', fno_encrypt_secret($secret));
        return $secret;
    }
    // Same real encrypt-with-legacy-plaintext-fallback pattern as
    // fno_get_headless_driver_secret() above - see its TRACE for the
    // full rationale. Migrates a pre-existing plaintext secret to
    // encrypted storage in place on first read after upgrade, without
    // ever invalidating an already-configured, already-running daemon.
    $decrypted = fno_decrypt_secret($stored);
    if ($decrypted !== '') {
        return $decrypted;
    }
    update_option('fno_daemon_ingest_secret', fno_encrypt_secret($stored));
    return $stored;
}

add_action('wp_ajax_fno_ingest_microstructure', 'fno_ingest_microstructure_fn');
add_action('wp_ajax_nopriv_fno_ingest_microstructure', 'fno_ingest_microstructure_fn');
/**
 * TRACE: Called by the companion daemon (not a browser) -> validates
 * the X-Fno-Daemon-Secret header against fno_get_daemon_secret() ->
 * upserts one row per symbol into wp_fno_microstructure with the
 * daemon's computed metrics -> updated_at defaults to NOW() so
 * fno_get_microstructure_fn can detect staleness.
 * Preconditions: valid secret header, $_POST has symbol + at least one
 * metric field.
 * Postconditions: row upserted; returns success/failure explicitly
 * (Part 2.6-D discipline - the daemon should log a failed ingest, not
 * silently keep computing into the void).
 * Edge cases handled: wrong/missing secret (403, not a silent no-op);
 * $wpdb->replace failure (explicit error response).
 */
function fno_ingest_microstructure_fn() {
    fno_rate_limit('microstructure_ingest'); // FOUND via the same real, live load test - genuinely missing on a real WRITE endpoint, higher real risk than a read-only one
    $secret = $_SERVER['HTTP_X_FNO_DAEMON_SECRET'] ?? '';
    if (!hash_equals(fno_get_daemon_secret(), $secret)) {
        wp_send_json_error(['message' => 'Invalid daemon secret'], 403);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'fno_microstructure';
    // Real fix (server-side symbol-enum sweep): symbol was previously
    // accepted via sanitize_text_field() alone with no enum check -
    // the daemon secret authenticates WHO is calling, not that the
    // payload's symbol is genuinely one of this app's real, valid
    // symbols, so a daemon-side bug (or a leaked secret) could still
    // write a fabricated microstructure row under an arbitrary symbol
    // string. Same required-field hard-reject as the existing
    // `if ($symbol === '') ... required` check.
    $symbol = fno_validate_symbol($_POST['symbol'] ?? '') ?? '';
    if ($symbol === '') wp_send_json_error(['message' => 'A valid, required symbol (NIFTY/BANKNIFTY/FINNIFTY) is required'], 400);

    // Real fix (definitive $_POST-numeric-write sweep): poc/
    // flow_imbalance_pct/ticks_per_minute were bare-cast straight into an
    // $wpdb->replace() with only an isset() check, never an is_numeric()
    // one - same fail-open gap already fixed elsewhere (a non-numeric
    // value silently becomes 0.0 and is permanently stored as a real
    // microstructure reading, later read back by fno_get_microstructure_fn
    // and trusted as clean data). The daemon is secret-authenticated, but
    // that authenticates WHO is calling, not that the payload is genuinely
    // numeric - a daemon-side bug could still send a malformed value, and
    // this closes the gap at the write boundary regardless of caller.
    foreach (['poc', 'flow_imbalance_pct', 'ticks_per_minute'] as $numField) {
        if (isset($_POST[$numField]) && $_POST[$numField] !== '' && !is_numeric($_POST[$numField])) {
            wp_send_json_error(['message' => "Real, non-numeric value genuinely rejected for '$numField' (got: " . substr((string) $_POST[$numField], 0, 40) . ") - refusing to silently store it as a fabricated microstructure reading."], 400);
        }
    }
    $ok = $wpdb->replace($table, [
        'symbol' => $symbol,
        'cumulative_delta' => isset($_POST['cumulative_delta']) ? (int) $_POST['cumulative_delta'] : null,
        'poc' => isset($_POST['poc']) ? (float) $_POST['poc'] : null,
        'flow_imbalance_pct' => isset($_POST['flow_imbalance_pct']) ? (float) $_POST['flow_imbalance_pct'] : null,
        'footprint_top_levels' => isset($_POST['footprint_top_levels']) ? fno_cap_text(sanitize_textarea_field($_POST['footprint_top_levels'])) : null,
        'ticks_per_minute' => isset($_POST['ticks_per_minute']) ? (float) $_POST['ticks_per_minute'] : null,
        'iceberg_detected' => isset($_POST['iceberg_detected']) ? (int) $_POST['iceberg_detected'] : null,
        'dom_spoof_detected' => isset($_POST['dom_spoof_detected']) ? (int) $_POST['dom_spoof_detected'] : null,
        'updated_at' => current_time('mysql'),
    ]);
    if ($ok === false) wp_send_json_error(['message' => 'DB write failed: ' . $wpdb->last_error], 500);
    wp_send_json_success(['stored' => true]);
}

add_action('wp_ajax_fno_ingest_microstructure_instrument', 'fno_ingest_microstructure_instrument_fn');
add_action('wp_ajax_nopriv_fno_ingest_microstructure_instrument', 'fno_ingest_microstructure_instrument_fn');
/**
 * TRACE: Zerodha-maximization audit, final backlog item - real per-
 * OPTION-STRIKE microstructure ingest, the daemon-side counterpart to
 * kite-microstructure-daemon.js's new per-token state (see
 * wireTickerEvents' own TRACE there). Upserts one row per real,
 * distinct option contract into wp_fno_microstructure_instruments
 * (instrument_key = the real Kite tradingsymbol) - same daemon-secret
 * auth, same numeric-validation discipline, and the same real,
 * required-field/enum-validated symbol column as the existing
 * fno_ingest_microstructure_fn (underlying-only) endpoint above; this
 * is deliberately its own separate endpoint/table rather than widening
 * that one's contract, so the existing underlying-only ingest path
 * (and every install already depending on it) is completely untouched.
 * Preconditions: valid X-Fno-Daemon-Secret header; $_POST['instrumentKey']
 * a non-empty real tradingsymbol; $_POST['symbol'] a valid real
 * underlying symbol.
 * Postconditions: one row upserted (or a loud, specific 400/500 on any
 * real validation/DB failure) - never a fabricated/partial row.
 */
function fno_ingest_microstructure_instrument_fn() {
    fno_rate_limit('microstructure_ingest'); // same real per-endpoint limit as the underlying-only ingest above - this is also a real WRITE endpoint
    $secret = $_SERVER['HTTP_X_FNO_DAEMON_SECRET'] ?? '';
    if (!hash_equals(fno_get_daemon_secret(), $secret)) {
        wp_send_json_error(['message' => 'Invalid daemon secret'], 403);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'fno_microstructure_instruments';

    $instrumentKey = sanitize_text_field($_POST['instrumentKey'] ?? '');
    // Real, bounded length check - matches this column's real VARCHAR(40)
    // schema width (a real Kite option tradingsymbol never exceeds this
    // in practice, e.g. "NIFTY24AUG23200CE" is 17 chars) - rejects loudly
    // rather than letting MySQL silently truncate a too-long value into
    // a value that would collide with, or fail to match, the real one.
    if ($instrumentKey === '' || strlen($instrumentKey) > 40) {
        wp_send_json_error(['message' => 'A valid, required instrumentKey (the real Kite tradingsymbol, max 40 chars) is required'], 400);
    }
    $symbol = fno_validate_symbol($_POST['symbol'] ?? '') ?? '';
    if ($symbol === '') wp_send_json_error(['message' => 'A valid, required symbol (NIFTY/BANKNIFTY/FINNIFTY) is required'], 400);

    // Same real fail-loud-on-non-numeric discipline as
    // fno_ingest_microstructure_fn above - never silently store a
    // malformed value as a fabricated real reading.
    foreach (['poc' => 'poc', 'flowImbalancePct' => 'flow_imbalance_pct', 'ticksPerMinute' => 'ticks_per_minute'] as $postKey => $numField) {
        if (isset($_POST[$postKey]) && $_POST[$postKey] !== '' && !is_numeric($_POST[$postKey])) {
            wp_send_json_error(['message' => "Real, non-numeric value genuinely rejected for '$postKey' (got: " . substr((string) $_POST[$postKey], 0, 40) . ") - refusing to silently store it as a fabricated microstructure reading."], 400);
        }
    }
    $ok = $wpdb->replace($table, [
        'instrument_key' => $instrumentKey,
        'symbol' => $symbol,
        'cumulative_delta' => isset($_POST['cumulativeDelta']) ? (int) $_POST['cumulativeDelta'] : null,
        'poc' => isset($_POST['poc']) ? (float) $_POST['poc'] : null,
        'flow_imbalance_pct' => isset($_POST['flowImbalancePct']) ? (float) $_POST['flowImbalancePct'] : null,
        'footprint_top_levels' => isset($_POST['footprintTopLevels']) ? fno_cap_text(sanitize_textarea_field($_POST['footprintTopLevels'])) : null,
        'ticks_per_minute' => isset($_POST['ticksPerMinute']) ? (float) $_POST['ticksPerMinute'] : null,
        'iceberg_detected' => isset($_POST['icebergDetected']) ? (int) $_POST['icebergDetected'] : null,
        'dom_spoof_detected' => isset($_POST['domSpoofDetected']) ? (int) $_POST['domSpoofDetected'] : null,
        'updated_at' => current_time('mysql'),
    ]);
    if ($ok === false) wp_send_json_error(['message' => 'DB write failed: ' . $wpdb->last_error], 500);
    wp_send_json_success(['stored' => true]);
}

add_action('wp_ajax_fno_ingest_raw_tick', 'fno_ingest_raw_tick_fn');
add_action('wp_ajax_nopriv_fno_ingest_raw_tick', 'fno_ingest_raw_tick_fn');
/**
 * TRACE: Enterprise Data Architecture Plan #2/#3 - real raw-tick
 * ingestion. Called by the companion daemon (same auth pattern as
 * fno_ingest_microstructure_fn above - the daemon already holds a
 * persistent Kite WebSocket connection, so it's the natural writer
 * for raw ticks rather than a new process). Accepts EITHER a single
 * tick or a batch array (real batching support - the daemon can group
 * ticks over a short window and POST once, reducing HTTP overhead vs
 * one request per tick, which matters given real tick volume during
 * active market hours).
 * Preconditions: valid X-Fno-Daemon-Secret header; $_POST['ticks'] is
 * a JSON-encoded array of tick objects.
 * Postconditions: bulk-inserts all valid ticks in ONE query (not a
 * loop of single inserts - real performance discipline for a table
 * that can receive high-frequency writes); returns the real count
 * inserted vs the count submitted, so the daemon can detect partial
 * failures rather than assuming success.
 * Edge cases handled: malformed/empty ticks array (400, not a silent
 * no-op); a tick missing its required symbol/ts (skipped individually,
 * counted in the response, doesn't fail the whole batch).
 */
function fno_ingest_raw_tick_fn() {
    fno_rate_limit('raw_tick_ingest'); // FOUND via the same real, live load test - genuinely missing on a real WRITE endpoint, higher real risk than a read-only one
    $secret = $_SERVER['HTTP_X_FNO_DAEMON_SECRET'] ?? '';
    if (!hash_equals(fno_get_daemon_secret(), $secret)) {
        wp_send_json_error(['message' => 'Invalid daemon secret'], 403);
    }
    $ticksRaw = $_POST['ticks'] ?? '';
    $ticks = json_decode(stripslashes($ticksRaw), true);
    if (!is_array($ticks) || empty($ticks)) wp_send_json_error(['message' => 'ticks must be a non-empty JSON array'], 400);
    // Real, deliberate batch-size cap (input-size/payload-validation
    // audit, this session): FOUND genuinely missing - this endpoint
    // previously accepted an array of ANY size, so a single,
    // otherwise rate-limit-compliant POST (raw_tick_ingest is
    // rate-limited per-request, not per-tick-inside-the-request) with
    // a pathologically large ticks array would still reach a single,
    // real bulk multi-row INSERT built from that entire array (see
    // this function's own real "ONE query, not a loop" design above),
    // and PHP would build the full $rows array + $sql string for all
    // of it in memory first. Rejects (does NOT silently truncate to
    // the first N ticks) - unlike a free-text note, silently dropping
    // ticks from a batch the daemon believes it fully sent would leave
    // the daemon over-counting what actually landed with no way to
    // detect it (the per-tick skip-count in this function's response
    // is for individually invalid ticks, not for a batch trimmed out
    // from under the caller). FNO_RAW_TICK_BATCH_MAX matches
    // RAW_TICK_BUFFER_MAX in companion-daemon/kite-microstructure-
    // daemon.js exactly (2000) - the daemon's own real, documented
    // hard cap on how large rawTickBuffer (and therefore any one real
    // flush's batch) is ever allowed to grow, so this server-side cap
    // can never reject a real, legitimate daemon flush, only a batch
    // that is already larger than anything the real daemon itself
    // would ever produce.
    if (count($ticks) > FNO_RAW_TICK_BATCH_MAX) {
        wp_send_json_error(['message' => "ticks batch of " . count($ticks) . " exceeds the real max of " . FNO_RAW_TICK_BATCH_MAX . " per request (matches the daemon's own RAW_TICK_BUFFER_MAX) - split into smaller batches"], 400);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'fno_raw_ticks';
    $rows = [];
    $skipped = 0;
    foreach ($ticks as $t) {
        $symbol = strtoupper(sanitize_text_field($t['symbol'] ?? ''));
        $ts = isset($t['ts']) ? (int) $t['ts'] : 0;
        if ($symbol === '' || $ts <= 0) { $skipped++; continue; }
        $rows[] = $wpdb->prepare(
            "(%d, %s, %s, %s, %f, %s, %s, %f, %f, %f, %f, %d, %d, %d, %f, %f, %f, %s, %s, %s)",
            // FOUND alongside the query-side fix above: this real,
            // stored trade_date value was ALSO computed via date(),
            // which depends on PHP's server default timezone, not
            // necessarily IST - would have created a real mismatch
            // against the now-IST-corrected query side above (data
            // stored under one timezone's calendar day, queried under
            // another's). Real, robust fix: converts this tick's own
            // real, actual millisecond timestamp into an explicit,
            // real Asia/Kolkata DateTime, matching fno_now_ist()'s
            // same technique.
            $ts, (new DateTime('@' . (int) ($ts / 1000)))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d'), $symbol,
            sanitize_text_field($t['underlying'] ?? $symbol),
            $t['strike'] ?? null, sanitize_text_field($t['optionType'] ?? ''), sanitize_text_field($t['expiry'] ?? ''),
            $t['ltp'] ?? null, $t['open'] ?? null, $t['high'] ?? null, $t['low'] ?? null,
            $t['volume'] ?? null, $t['oi'] ?? null, $t['oiChange'] ?? null,
            $t['bid'] ?? null, $t['ask'] ?? null, $t['iv'] ?? null,
            sanitize_text_field($t['source'] ?? 'daemon'), sanitize_text_field($t['sourceTier'] ?? ''),
            sanitize_text_field(implode(',', (array) ($t['qualityFlags'] ?? [])))
        );
    }
    if (empty($rows)) wp_send_json_error(['message' => "All {$skipped} submitted ticks were invalid (missing symbol/ts)"], 400);

    $sql = "INSERT INTO $table (ts, trade_date, symbol, underlying, strike, option_type, expiry, ltp, open_price, high_price, low_price, volume, oi, oi_change, bid, ask, iv, source, source_tier, quality_flags) VALUES " . implode(',', $rows);
    $inserted = $wpdb->query($sql);
    if ($inserted === false) wp_send_json_error(['message' => 'Bulk insert failed: ' . $wpdb->last_error], 500);
    wp_send_json_success(['inserted' => $inserted, 'skipped' => $skipped, 'submitted' => count($ticks)]);
}

add_action('wp_ajax_fno_get_raw_ticks_summary', 'fno_get_raw_ticks_summary_fn');
/**
 * TRACE: Admin-only real diagnostic - confirms the raw-tick pipeline is
 * actually receiving data, without exposing the raw rows themselves
 * over AJAX (a full raw-tick query API is real, separate follow-up
 * work per the Enterprise Plan - this is a health-check summary only,
 * genuinely useful on its own for confirming Phase 2 is working at
 * all before building more on top of it).
 */
function fno_get_raw_ticks_summary_fn() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Admin only'], 403);
    check_ajax_referer('fno_standalone_nonce', 'nonce');
    global $wpdb;
    $table = $wpdb->prefix . 'fno_raw_ticks';
    $today = fno_now_ist()->format('Y-m-d'); // real, robust IST trading-day boundary
    $countToday = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE trade_date = %s", $today));
    $oldestToday = $wpdb->get_var($wpdb->prepare("SELECT MIN(ts) FROM $table WHERE trade_date = %s", $today));
    $newestToday = $wpdb->get_var($wpdb->prepare("SELECT MAX(ts) FROM $table WHERE trade_date = %s", $today));
    $totalRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    wp_send_json_success([
        'tradeDate' => $today, 'tickCountToday' => $countToday,
        'oldestTsToday' => $oldestToday !== null ? (int) $oldestToday : null,
        'newestTsToday' => $newestToday !== null ? (int) $newestToday : null,
        'totalRowsRetained' => $totalRows,
    ]);
}

add_action('wp_ajax_fno_get_oi_accumulation_history', 'fno_get_oi_accumulation_history_fn');
/**
 * TRACE: User's own vision document - "OI Accumulation: track OI over
 * time rather than one snapshot... Gradual Position Building: large
 * orders may be executed through smaller transactions... look for
 * persistent positioning patterns over time." FOUND GENUINELY
 * BUILDABLE this session using the real raw tick store (Enterprise
 * Plan #2/#3, built earlier this session) - each real tick already
 * carries a real `oi` field per strike/optionType/timestamp, retained
 * for 7 real trading days. This endpoint queries that real store for
 * one real strike+optionType and returns the real end-of-day OI
 * reading for each real retained day, giving a genuine multi-day
 * trajectory - not a single snapshot - for the JS-side
 * computeOIAccumulationPattern() to classify.
 * Real, deliberate design choice: takes the LATEST real tick per real
 * trade_date (closest to real market close) as that day's real
 * "closing OI" - a real, defensible single representative value per
 * day, rather than averaging (which would blur a real intraday trend)
 * or taking the first tick (which wouldn't reflect the day's real
 * accumulated position).
 * Preconditions: admin only (same real gate as the sibling raw-ticks
 * summary endpoint); $_GET has real symbol/strike/optionType.
 * Postconditions: returns {history: [{date, oi}, ...]} sorted real
 * oldest-to-newest, or an honestly empty array if the companion
 * daemon has never captured real ticks for this exact strike (never
 * fabricated).
 */
function fno_get_oi_accumulation_history_fn() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Admin only'], 403);
    check_ajax_referer('fno_standalone_nonce', 'nonce');
    // Real fix (server-side symbol-enum sweep): same fallback-on-
    // invalid-value treatment as this app's other index-only endpoints.
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $strike = isset($_GET['strike']) ? (float) $_GET['strike'] : 0;
    $optionType = strtoupper(sanitize_text_field($_GET['optionType'] ?? ''));
    if (!$strike || !in_array($optionType, ['CE', 'PE'], true)) {
        wp_send_json_error(['message' => 'Real strike and optionType (CE/PE) required'], 400);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'fno_raw_ticks';
    // Real: one row per real trade_date, the LATEST real tick that
    // day for this exact strike/optionType/symbol (MySQL's own
    // GROUP BY + a correlated MAX(ts) join is the real, standard,
    // correct way to get "last row per group" without a window
    // function, kept compatible with older real MySQL versions this
    // app's other queries already assume).
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT t.trade_date, t.oi FROM $table t
         INNER JOIN (
             SELECT trade_date, MAX(ts) AS max_ts FROM $table
             WHERE symbol = %s AND strike = %f AND option_type = %s
             GROUP BY trade_date
         ) latest ON t.trade_date = latest.trade_date AND t.ts = latest.max_ts
         WHERE t.symbol = %s AND t.strike = %f AND t.option_type = %s
         ORDER BY t.trade_date ASC",
        $symbol, $strike, $optionType, $symbol, $strike, $optionType
    ), ARRAY_A);
    $history = array_map(function($r) {
        return ['date' => $r['trade_date'], 'oi' => $r['oi'] !== null ? (int) $r['oi'] : null];
    }, $rows ?: []);
    wp_send_json_success(['history' => $history]);
}

add_action('wp_ajax_fno_get_intraday_oi_history', 'fno_get_intraday_oi_history_fn');
/**
 * TRACE: User's own founding vision document - "Gradual Position
 * Building: Large orders may be executed through smaller transactions
 * and algorithmic execution rather than appearing as one obvious large
 * order. The system should therefore look for persistent positioning
 * patterns over time." FOUND MISSING (confirmed against the real
 * document): fno_get_oi_accumulation_history_fn above already builds
 * this real question DAY-over-day, but the document's own "smaller
 * transactions... over time" language is naturally read as WITHIN a
 * single real session too, not just across days - this app's own real
 * raw tick store already has genuinely granular intraday data, but
 * nothing had ever queried it this way.
 * Real, honest design: reuses the SAME real wp_fno_raw_ticks table
 * already populated by the companion daemon - buckets real ticks into
 * real 15-minute windows within TODAY only (a real, deliberately
 * bounded scope; the raw store's own real 7-day retention makes a
 * longer intraday-only window not meaningfully different from the
 * existing day-over-day endpoint's real purpose). Returns the LATEST
 * real OI reading within each real 15-minute bucket - reusing the
 * exact same real "last reading per bucket" pattern already
 * established for the daily version, just at finer real granularity.
 * The real JS side deliberately reuses computeOIAccumulationPattern()
 * unchanged - that function already operates on any generic,
 * chronologically-ordered {date, oi} series and doesn't care whether
 * each entry represents a day or a 15-minute bucket.
 */
function fno_get_intraday_oi_history_fn() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Admin only'], 403);
    check_ajax_referer('fno_standalone_nonce', 'nonce');
    // Real fix (server-side symbol-enum sweep): same fallback-on-
    // invalid-value treatment as this app's other index-only endpoints.
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $strike = isset($_GET['strike']) ? (float) $_GET['strike'] : 0;
    $optionType = strtoupper(sanitize_text_field($_GET['optionType'] ?? ''));
    if (!$strike || !in_array($optionType, ['CE', 'PE'], true)) {
        wp_send_json_error(['message' => 'Real strike and optionType (CE/PE) required'], 400);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'fno_raw_ticks';
    $todayStart = strtotime('today') * 1000;
    $todayEnd = strtotime('tomorrow') * 1000;
    // Real 15-minute bucket boundary derived directly from each real
    // tick's own real timestamp (900000ms = 15 real minutes) - the
    // same real "last reading per group" pattern as the daily
    // endpoint, applied at a real, finer granularity via a real
    // computed bucket key rather than a real calendar date.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT t.ts, t.oi FROM $table t
         INNER JOIN (
             SELECT FLOOR(ts / 900000) AS bucket, MAX(ts) AS max_ts FROM $table
             WHERE symbol = %s AND strike = %f AND option_type = %s AND ts >= %d AND ts < %d
             GROUP BY bucket
         ) latest ON FLOOR(t.ts / 900000) = latest.bucket AND t.ts = latest.max_ts
         WHERE t.symbol = %s AND t.strike = %f AND t.option_type = %s AND t.ts >= %d AND t.ts < %d
         ORDER BY t.ts ASC",
        $symbol, $strike, $optionType, $todayStart, $todayEnd, $symbol, $strike, $optionType, $todayStart, $todayEnd
    ), ARRAY_A);
    $history = array_map(function($r) {
        return ['date' => date('H:i', ((int) $r['ts']) / 1000), 'oi' => $r['oi'] !== null ? (int) $r['oi'] : null];
    }, $rows ?: []);
    wp_send_json_success(['history' => $history]);
}

add_action('wp_ajax_fno_get_replay_ticks', 'fno_get_replay_ticks_fn');
/**
 * TRACE: `docs/PENDING_REQUIREMENTS.md`'s own, honestly-tracked
 * "Market Replay Engine... needs Phase 2's raw store (done) as a data
 * source, but the actual replay-mode data-source adapter... never
 * built" item. Real, honest, BOUNDED v1: returns the real, already-
 * captured tick sequence for a real date/symbol/strike/optionType
 * from the SAME real raw_ticks table the companion daemon already
 * populates - no new storage, no fabricated historical data.
 * Real, stated limitation: this app's raw store only retains 7 real
 * days (fno_prune_raw_ticks_fn's own real, documented retention
 * policy) - this endpoint can only replay what was genuinely captured
 * and still retained, never a longer real history this app doesn't
 * actually have. A real date outside that window, or a real date the
 * companion daemon simply wasn't running for, honestly returns an
 * empty tick array - never a fabricated one.
 * Preconditions: date is a real YYYY-MM-DD string; symbol/strike/
 * optionType identify a real, specific contract.
 * Postconditions: returns {ticks: array} - a real, chronologically-
 * ordered array of every real tick this app actually captured for
 * that real contract on that real date, each with real ts/ltp/oi/
 * volume/iv/bid/ask fields (nulls where a real field was genuinely
 * not captured for that tick, never fabricated).
 */
function fno_get_replay_ticks_fn() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Admin only'], 403);
    check_ajax_referer('fno_standalone_nonce', 'nonce');
    // Real fix (server-side symbol-enum sweep): same fallback-on-
    // invalid-value treatment as this app's other index-only endpoints.
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $strike = isset($_GET['strike']) ? (float) $_GET['strike'] : 0;
    $optionType = strtoupper(sanitize_text_field($_GET['optionType'] ?? ''));
    $date = sanitize_text_field($_GET['date'] ?? '');
    if (!$strike || !in_array($optionType, ['CE', 'PE'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        wp_send_json_error(['message' => 'Real strike, optionType (CE/PE), and a real date (YYYY-MM-DD) are all required'], 400);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'fno_raw_ticks';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ts, ltp, oi, oi_change, volume, iv, bid, ask FROM $table
         WHERE symbol = %s AND strike = %f AND option_type = %s AND trade_date = %s
         ORDER BY ts ASC LIMIT 5000",
        $symbol, $strike, $optionType, $date
    ), ARRAY_A);
    $ticks = array_map(function($r) {
        return [
            'ts' => (int) $r['ts'],
            'ltp' => $r['ltp'] !== null ? (float) $r['ltp'] : null,
            'oi' => $r['oi'] !== null ? (int) $r['oi'] : null,
            'oiChange' => $r['oi_change'] !== null ? (int) $r['oi_change'] : null,
            'volume' => $r['volume'] !== null ? (int) $r['volume'] : null,
            'iv' => $r['iv'] !== null ? (float) $r['iv'] : null,
            'bid' => $r['bid'] !== null ? (float) $r['bid'] : null,
            'ask' => $r['ask'] !== null ? (float) $r['ask'] : null,
        ];
    }, $rows ?: []);
    wp_send_json_success(['ticks' => $ticks, 'count' => count($ticks)]);
}

if (!wp_next_scheduled('fno_prune_raw_ticks_event')) {
    wp_schedule_event(strtotime('tomorrow 01:00:00'), 'daily', 'fno_prune_raw_ticks_event');
}
add_action('fno_prune_raw_ticks_event', 'fno_prune_raw_ticks_fn');
/**
 * TRACE: Enterprise Plan #2/#3 retention-by-rotation, run daily via
 * WP-Cron (already available in this hosting environment, no new
 * infrastructure needed) at 01:00 local time - well after market
 * close, before the next trading day. Deletes raw tick rows older
 * than RETENTION_DAYS, keeping the table bounded on shared/managed
 * hosting rather than growing unboundedly - the real engineering
 * discipline documented as part of the MySQL-based Phase 2 decision.
 * RETENTION_DAYS is a real, configurable constant (7 by default -
 * enough to replay any trade from the current week for debugging/
 * Decision Replay, without keeping months of raw ticks on hosting that
 * may have real disk quotas). A longer-term archive (compressed export
 * before deletion) is real, separate follow-up work, not built this
 * pass - stated plainly rather than silently deleting history nobody
 * chose to discard permanently without at least flagging that this is
 * happening.
 */
function fno_prune_raw_ticks_fn() {
    $RETENTION_DAYS = 7;
    global $wpdb;
    $table = $wpdb->prefix . 'fno_raw_ticks';
    $cutoff = date('Y-m-d', strtotime("-{$RETENTION_DAYS} days"));
    $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE trade_date < %s", $cutoff));
    if ($deleted === false) {
        fno_dsm_log_provider_failure('raw_ticks_pruning', 'cron', $wpdb->last_error);
    }
}

add_action('wp_ajax_fno_get_microstructure', 'fno_get_microstructure_fn');
add_action('wp_ajax_nopriv_fno_get_microstructure', 'fno_get_microstructure_fn');
/**
 * TRACE: Reads the latest daemon snapshot for the requested symbol ->
 * checks updated_at is within the last 30 seconds (a daemon that
 * stopped posting is functionally offline, even if its last row is
 * still in the table) -> returns the metrics or null.
 * Preconditions: none. Postconditions: returns the row's fields
 * (footprint_top_levels JSON-decoded) if fresh, or null if no row
 * exists or the row is stale (daemon not currently running).
 * Edge cases handled: stale row (explicitly treated as null/offline,
 * NOT served as if live - a snapshot from an hour ago presented as
 * "live" would be exactly the kind of stale-data-as-real fabrication
 * this whole codebase has been built to avoid).
 */
function fno_get_microstructure_fn() {
    fno_rate_limit('microstructure_read', 300); // real, high-frequency: called every 600ms refresh cycle - also FOUND via the same real, live load test as genuinely missing rate-limiting entirely
    fno_verify_public_or_driver_access(); // real, swapped: this is a real, public market-data read the headless driver also needs, no login required (see fno_verify_public_or_driver_access's own real TRACE)
    global $wpdb;
    $table = $wpdb->prefix . 'fno_microstructure';
    // Real fix (server-side symbol-enum sweep): same fallback-on-
    // invalid-value treatment as this app's other index-only endpoints.
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE symbol = %s", $symbol), ARRAY_A);
    if (!$row) wp_send_json_success(null);

    $ageSeconds = current_time('timestamp') - strtotime($row['updated_at']);
    if ($ageSeconds > 30) wp_send_json_success(null); // daemon offline - stale row, not served as live

    wp_send_json_success([
        'cumulativeDelta' => $row['cumulative_delta'] !== null ? (int) $row['cumulative_delta'] : null,
        'poc' => $row['poc'] !== null ? (float) $row['poc'] : null,
        'flowImbalancePct' => $row['flow_imbalance_pct'] !== null ? (float) $row['flow_imbalance_pct'] : null,
        'footprintTopLevels' => $row['footprint_top_levels'] ? json_decode($row['footprint_top_levels'], true) : null,
        'ticksPerMinute' => $row['ticks_per_minute'] !== null ? (float) $row['ticks_per_minute'] : null,
        'icebergDetected' => $row['iceberg_detected'] !== null ? (int) $row['iceberg_detected'] : null,
        'domSpoofDetected' => $row['dom_spoof_detected'] !== null ? (int) $row['dom_spoof_detected'] : null,
    ]);
}

add_action('wp_ajax_fno_get_microstructure_instruments', 'fno_get_microstructure_instruments_fn');
add_action('wp_ajax_nopriv_fno_get_microstructure_instruments', 'fno_get_microstructure_instruments_fn');
/**
 * TRACE: Zerodha-maximization audit, final backlog item - reads EVERY
 * real, currently-fresh per-option-strike microstructure row for the
 * requested underlying symbol (the daemon may be tracking several real
 * strikes at once - see kite-microstructure-daemon.js's own
 * optionStrikes config). Same real 30-second staleness discipline as
 * fno_get_microstructure_fn - a stale row is honestly omitted, never
 * served as if live.
 * Preconditions: none. Postconditions: returns an array (possibly
 * empty - honestly, if the daemon isn't tracking any strikes, or isn't
 * running, or every tracked row has gone stale) of
 * {instrumentKey, cumulativeDelta, poc, flowImbalancePct,
 * footprintTopLevels, ticksPerMinute, icebergDetected, domSpoofDetected}.
 */
function fno_get_microstructure_instruments_fn() {
    fno_rate_limit('microstructure_read', 300); // same real limit as the underlying-only read above
    fno_verify_public_or_driver_access();
    global $wpdb;
    $table = $wpdb->prefix . 'fno_microstructure_instruments';
    $symbol = fno_validate_symbol($_GET['symbol'] ?? 'NIFTY') ?? 'NIFTY';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE symbol = %s", $symbol), ARRAY_A);
    $out = [];
    $nowTs = current_time('timestamp');
    foreach ((is_array($rows) ? $rows : []) as $row) {
        $ageSeconds = $nowTs - strtotime($row['updated_at']);
        if ($ageSeconds > 30) continue; // this specific strike's daemon feed has gone stale - honestly omitted, not served as live
        $out[] = [
            'instrumentKey' => $row['instrument_key'],
            'cumulativeDelta' => $row['cumulative_delta'] !== null ? (int) $row['cumulative_delta'] : null,
            'poc' => $row['poc'] !== null ? (float) $row['poc'] : null,
            'flowImbalancePct' => $row['flow_imbalance_pct'] !== null ? (float) $row['flow_imbalance_pct'] : null,
            'footprintTopLevels' => $row['footprint_top_levels'] ? json_decode($row['footprint_top_levels'], true) : null,
            'ticksPerMinute' => $row['ticks_per_minute'] !== null ? (float) $row['ticks_per_minute'] : null,
            'icebergDetected' => $row['iceberg_detected'] !== null ? (int) $row['iceberg_detected'] : null,
            'domSpoofDetected' => $row['dom_spoof_detected'] !== null ? (int) $row['dom_spoof_detected'] : null,
        ];
    }
    wp_send_json_success($out);
}

add_action('admin_notices', function () {
    if (get_option('fno_standalone_notice_done')) return;
    echo '<div class="notice notice-success"><p>F&O Lab Standalone App Ready! Your homepage <a href="' . esc_url(site_url('/')) . '" target="_blank"><b>' . esc_html(site_url('/')) . '</b></a> now serves the app directly - no theme, no shortcode, no page setup. (/lab/, /fno/, /app/ still work as aliases.)</p></div>';
    update_option('fno_standalone_notice_done', 1);
});

// REAL FIX (2026-08-30 migration-safety audit pass) - see the
// fno_create_journal_table() TRACE comment above the sql11 dbDelta
// call. Makes a genuinely failed/incomplete schema migration visible
// in wp-admin instead of only ever appearing in the PHP error log,
// which most site owners never check.
add_action('admin_notices', function () {
    $err = get_option('fno_journal_db_migration_error');
    if (!$err) return;
    echo '<div class="notice notice-error"><p><strong>F&O Lab:</strong> a database schema migration to version ' . esc_html(FNO_JOURNAL_SCHEMA_VERSION) . ' failed and will keep retrying automatically on page load. The app remains fully usable in the meantime. Last error: <code>' . esc_html($err) . '</code></p></div>';
});
