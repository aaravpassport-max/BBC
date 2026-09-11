'use strict';
/**
 * Real, extracted config-building/validation logic for autonomous-driver.js.
 *
 * TRACE: same audit pass, same bug class, as companion-daemon's
 * loadConfig(configPath, exitFn) fix (see
 * companion-daemon/kite-microstructure-daemon.js) - pulled out of
 * autonomous-driver.js's own top-level module body for the exact same
 * two real reasons:
 *
 * 1. UNTESTABLE (FOUND this pass): requiring autonomous-driver.js
 *    directly runs its full top-level side effects unconditionally
 *    (dotenv, fs.readFileSync of the core/greeks asset sources, an
 *    eval() of the extracted core source, and - previously - an
 *    immediate validateConfig() call that could process.exit(1) the
 *    test runner itself). The project's own existing tests already
 *    work around this by spawning autonomous-driver.js as a real child
 *    process (see test/run-integration-test.js) rather than requiring
 *    it - which proves the config logic itself was never actually
 *    unit-tested directly, only exercised indirectly and expensively.
 *    Extracting the config logic into this small, side-effect-free
 *    module lets tests require() it directly, with no dotenv, no file
 *    reads, no eval, no network, and no process.exit unless they ask
 *    for it.
 *
 * 2. NO TYPE VALIDATION ON NUMERIC FIELDS (FOUND this pass): the
 *    former inline validateConfig() only checked siteUrl/driverSecret
 *    presence and optionType's CE/PE enum - it never checked that
 *    strikeOffset/lotSize/pollIntervalMs/strikeStep actually parsed to
 *    real numbers. A garbage env value (e.g. FNO_LOT_SIZE=abc) makes
 *    parseInt(...) return NaN silently; that NaN would then flow
 *    straight into real trade-quantity/strike-selection/poll-interval
 *    arithmetic (NaN comparisons are always false, NaN arithmetic
 *    poisons every downstream value - the exact same permanent-
 *    poisoning failure mode already documented and guarded against in
 *    companion-daemon's own computeTickRule) instead of failing fast
 *    at startup with a clear, actionable message. Fixed here the same
 *    way companion-daemon validates its OPTIONAL_NUMBER_FIELDS.
 *
 * v16.26.0: FNO_LOT_SIZE is the number of exchange lots (default 2).
 * Values > 10 are treated as legacy raw qty (pre-v16.26 installs using
 * FNO_LOT_SIZE=50 or 75). lotSize in CONFIG is always order qty.
 */

const EXCHANGE_LOT_SIZES = { NIFTY: 75, BANKNIFTY: 15, FINNIFTY: 40 };

function normalizeUnderlyingSymbol(sym) {
  const s = String(sym || 'NIFTY').trim().toUpperCase();
  if (s.includes('BANK')) return 'BANKNIFTY';
  if (s.includes('FIN')) return 'FINNIFTY';
  return 'NIFTY';
}

function getExchangeLotSize(symbol) {
  return EXCHANGE_LOT_SIZES[normalizeUnderlyingSymbol(symbol)] || 75;
}

/**
 * Resolves FNO_LOT_SIZE to order qty. <=10 means exchange lots; >10 legacy raw qty.
 */
function resolveOrderQty(symbol, env) {
  const raw = parseInt(env.FNO_LOT_SIZE || '2', 10);
  if (raw > 10) return raw;
  const lots = Math.max(1, raw);
  return lots * getExchangeLotSize(symbol);
}

function resolveExchangeLots(symbol, env) {
  const raw = parseInt(env.FNO_LOT_SIZE || '2', 10);
  if (raw > 10) return Math.max(1, Math.round(raw / getExchangeLotSize(symbol)));
  return Math.max(1, raw);
}

const NUMBER_FIELDS = ['strikeOffset', 'lotSize', 'exchangeLots', 'pollIntervalMs', 'strikeStep'];

/**
 * Builds the real CONFIG object from an env-like source (defaults to
 * process.env). Takes env as a parameter - rather than reading
 * process.env directly - purely so tests can pass a synthetic env
 * object without mutating/relying on real process.env state.
 */
function buildConfig(env) {
  env = env || process.env;
  const symbol = env.FNO_SYMBOL || 'NIFTY';
  const exchangeLots = resolveExchangeLots(symbol, env);
  return {
    siteUrl: env.FNO_SITE_URL,
    driverSecret: env.FNO_DRIVER_SECRET,
    symbol,
    strikeOffset: parseInt(env.FNO_STRIKE_OFFSET || '0', 10),
    optionType: (env.FNO_OPTION_TYPE || 'CE').toUpperCase(),
    // Fallback when brain has not yet produced a direction this cycle;
    // live entries use BUY_READY→CE / SELL_READY→PE (browser parity).
    tradingType: (env.FNO_TRADING_TYPE || 'intraday').toLowerCase(),
    exchangeLots,
    lotSize: resolveOrderQty(symbol, env),
    pollIntervalMs: parseInt(env.FNO_POLL_INTERVAL_MS || '60000', 10),
    strikeStep: parseInt(env.FNO_STRIKE_STEP || '50', 10),
    nseEnabled: env.FNO_NSE_ENABLED === '1',
    trailingEnabled: env.FNO_TRAILING_ENABLED === '1',
  };
}

/**
 * Validates a built CONFIG object. FAIL-SAFE: doExit defaults to
 * process.exit but is injectable so tests can assert on the exact
 * exit code/message without a real process exit (same injectable-exit
 * pattern already used by companion-daemon's loadConfig/
 * wireTickerEvents). A test doExit must not return (it throws) so this
 * function does not fall through past the exit and return a
 * still-invalid config to the caller.
 *
 * Secret-leakage discipline (enforced by test-secret-never-logged.js's
 * static source scan): never logs CONFIG.driverSecret itself, only
 * whether it is present.
 */
function validateConfig(config, exitFn) {
  const doExit = exitFn || process.exit;

  const missing = [];
  if (!config.siteUrl) missing.push('FNO_SITE_URL');
  if (!config.driverSecret) missing.push('FNO_DRIVER_SECRET');
  if (missing.length) {
    console.error(`FATAL: missing required real config: ${missing.join(', ')}. Copy .env.example to .env and fill these in - refusing to run with a guessed or fabricated value for a real, security-relevant setting.`);
    return doExit(1);
  }

  if (!['CE', 'PE'].includes(config.optionType)) {
    console.error(`FATAL: FNO_OPTION_TYPE must be a real CE or PE, got "${config.optionType}"`);
    return doExit(1);
  }

  if (!['scalping', 'intraday', 'swing'].includes(config.tradingType)) {
    console.error(`FATAL: FNO_TRADING_TYPE must be scalping, intraday, or swing, got "${config.tradingType}"`);
    return doExit(1);
  }

  const badNumbers = NUMBER_FIELDS.filter(f => !Number.isFinite(config[f]));
  if (badNumbers.length) {
    console.error(`FATAL: config field(s) must be real numbers: ${badNumbers.join(', ')} (got ${badNumbers.map(f => JSON.stringify(config[f])).join(', ')}). Check the corresponding FNO_* env var(s) for a non-numeric value.`);
    return doExit(1);
  }

  return config;
}

module.exports = { buildConfig, validateConfig, NUMBER_FIELDS, getExchangeLotSize, resolveOrderQty, resolveExchangeLots };
