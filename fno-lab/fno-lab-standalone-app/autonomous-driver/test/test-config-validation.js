'use strict';
/**
 * Regression test for autonomous-driver/config.js (buildConfig/
 * validateConfig), extracted this pass to close the same gap already
 * fixed in companion-daemon's loadConfig: (1) config-loading logic was
 * previously untestable except by spawning the whole driver as a
 * child process, and (2) numeric env fields (FNO_STRIKE_OFFSET,
 * FNO_LOT_SIZE, FNO_POLL_INTERVAL_MS, FNO_STRIKE_STEP) were never
 * type-checked, so a garbage value silently became NaN instead of
 * failing fast at startup.
 *
 * Requiring config.js has NO side effects (no dotenv, no fs reads of
 * core/greeks assets, no eval, no network) - unlike requiring
 * autonomous-driver.js itself, which is why this test can exercise
 * the real functions directly instead of spawning a subprocess.
 */
const { buildConfig, validateConfig } = require('../config');

let failures = 0;
function check(cond, msg) {
  if (!cond) { console.error('FAIL: ' + msg); failures++; }
  else { console.log('PASS: ' + msg); }
}

function baseEnv(overrides) {
  return Object.assign({
    FNO_SITE_URL: 'https://example.test',
    FNO_DRIVER_SECRET: 'real-secret-value',
  }, overrides);
}

// Injectable exit: records the call instead of really exiting, and
// throws so validateConfig cannot fall through past the "exit" (same
// pattern as companion-daemon's own test-config-validation.js).
function makeCapturedExit() {
  const calls = [];
  const exitFn = (code) => { calls.push(code); throw new Error('EXIT_CALLED'); };
  return { calls, exitFn };
}

// 1. A fully valid config passes through untouched.
{
  const cfg = buildConfig(baseEnv());
  const { calls, exitFn } = makeCapturedExit();
  const result = validateConfig(cfg, exitFn);
  check(calls.length === 0, 'valid config does not call exitFn');
  check(result === cfg, 'valid config is returned unchanged by validateConfig');
  check(result.symbol === 'NIFTY', 'default symbol applied when FNO_SYMBOL unset');
  check(result.exchangeLots === 2, 'default exchangeLots is 2 when FNO_LOT_SIZE unset');
  check(result.lotSize === 150, 'default lotSize is 150 qty (2 NIFTY lots) when FNO_LOT_SIZE unset');
}

// 1b. Legacy raw qty (pre-v16.26) still works when FNO_LOT_SIZE > 10.
{
  const cfg = buildConfig(baseEnv({ FNO_LOT_SIZE: '50' }));
  check(cfg.lotSize === 50, 'legacy FNO_LOT_SIZE=50 treated as raw qty');
  check(cfg.exchangeLots === 1, 'legacy raw qty 50 maps to ~1 NIFTY lot for display');
}

// 1c. Explicit 2 exchange lots for BANKNIFTY.
{
  const cfg = buildConfig(baseEnv({ FNO_SYMBOL: 'BANKNIFTY', FNO_LOT_SIZE: '2' }));
  check(cfg.lotSize === 30, '2 BANKNIFTY lots = 30 qty');
  check(cfg.exchangeLots === 2, 'exchangeLots=2 for FNO_LOT_SIZE=2');
}

// 2. Missing FNO_SITE_URL / FNO_DRIVER_SECRET -> exit(1) with both named.
{
  const cfg = buildConfig({});
  const { calls, exitFn } = makeCapturedExit();
  let threw = false;
  const origError = console.error;
  let lastMsg = '';
  console.error = (m) => { lastMsg = m; };
  try { validateConfig(cfg, exitFn); } catch (e) { threw = e.message === 'EXIT_CALLED'; }
  console.error = origError;
  check(threw, 'missing required fields triggers exitFn (not a silent pass-through)');
  check(calls[0] === 1, 'missing required fields exits with code 1');
  check(/FNO_SITE_URL/.test(lastMsg) && /FNO_DRIVER_SECRET/.test(lastMsg), 'error message names both missing fields: ' + lastMsg);
}

// 3. Invalid FNO_OPTION_TYPE -> exit(1) naming the bad value.
{
  const cfg = buildConfig(baseEnv({ FNO_OPTION_TYPE: 'XX' }));
  const { calls, exitFn } = makeCapturedExit();
  let threw = false;
  const origError = console.error;
  let lastMsg = '';
  console.error = (m) => { lastMsg = m; };
  try { validateConfig(cfg, exitFn); } catch (e) { threw = e.message === 'EXIT_CALLED'; }
  console.error = origError;
  check(threw, 'invalid FNO_OPTION_TYPE triggers exitFn');
  check(calls[0] === 1, 'invalid FNO_OPTION_TYPE exits with code 1');
  check(/XX/.test(lastMsg), 'error message names the bad option type value: ' + lastMsg);
}

// 4. THE GAP THIS PASS FOUND AND FIXED: a garbage numeric env value
// must be rejected at startup, not silently become NaN and flow into
// real trade-quantity/strike-selection/poll-interval arithmetic.
const numericFieldEnvVars = {
  strikeOffset: 'FNO_STRIKE_OFFSET',
  lotSize: 'FNO_LOT_SIZE',
  exchangeLots: 'FNO_LOT_SIZE',
  pollIntervalMs: 'FNO_POLL_INTERVAL_MS',
  strikeStep: 'FNO_STRIKE_STEP',
};
for (const [field, envVar] of Object.entries(numericFieldEnvVars)) {
  const cfg = buildConfig(baseEnv({ [envVar]: 'not-a-number' }));
  check(Number.isNaN(cfg[field]), `sanity: buildConfig(${envVar}=not-a-number) genuinely produces NaN for ${field} (pre-validation)`);
  const { calls, exitFn } = makeCapturedExit();
  let threw = false;
  const origError = console.error;
  let lastMsg = '';
  console.error = (m) => { lastMsg = m; };
  try { validateConfig(cfg, exitFn); } catch (e) { threw = e.message === 'EXIT_CALLED'; }
  console.error = origError;
  check(threw, `non-numeric ${envVar} is rejected by validateConfig (exitFn called), not silently passed through as NaN`);
  check(calls[0] === 1, `non-numeric ${envVar} exits with code 1`);
  check(new RegExp(field).test(lastMsg), `error message names the offending field "${field}" for bad ${envVar}: ` + lastMsg);
}

// 5. Secret-leakage discipline: the real driver secret value must
// never appear in any validateConfig error message, even on the
// missing-fields path where it is one of the named fields.
{
  const cfg = buildConfig(baseEnv({ FNO_DRIVER_SECRET: 'super-secret-do-not-log-12345' }));
  cfg.optionType = 'BOGUS'; // force a validation failure on a later check while the real secret value is still present in cfg
  const { exitFn } = makeCapturedExit();
  const origError = console.error;
  let lastMsg = '';
  console.error = (m) => { lastMsg = m; };
  try { validateConfig(cfg, exitFn); } catch (e) { /* expected */ }
  console.error = origError;
  check(!lastMsg.includes('super-secret-do-not-log-12345'), 'validateConfig error output never contains the real driver secret value');
}

if (failures > 0) {
  console.error(`\n${failures} failure(s).`);
  process.exit(1);
} else {
  console.log('\nAll config-validation regression checks passed.');
}
