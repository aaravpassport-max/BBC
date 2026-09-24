#!/usr/bin/env node
/**
 * Regression test for loadConfig() startup validation
 * (kite-microstructure-daemon.js).
 *
 * CONTEXT (audit finding, dated 2026-08-30): before this fix, config.json
 * loading was inlined under `if (require.main === module)` as a bare
 * `JSON.parse(fs.readFileSync(...))` with no try/catch - a hand-edited
 * config.json with a syntax error (trailing comma, missing quote, etc.)
 * crashed the process with a raw uncaught SyntaxError stack trace instead
 * of a clear "your config.json has a syntax error" message. Required-field
 * validation already existed (kiteApiKey/kiteAccessToken/wpSiteUrl/
 * ingestSecret/symbol via a `!config[f]` check) but had no test coverage
 * (the whole block was unreachable via require(), so nothing exercised
 * it), and there was no type validation at all - a string "5000" for
 * postIntervalMs, or a number for kiteApiKey, would sail through and only
 * surface confusingly later (e.g. inside setInterval or a KiteTicker
 * constructor call). The fix extracts loadConfig(configPath, exitFn) so
 * this is directly testable, adds a try/catch around JSON.parse with a
 * specific message, and adds type checks for both required (must be
 * string) and optional numeric (tickSize/postIntervalMs, must be finite
 * number) fields - all before any KiteTicker/network activity in main().
 *
 * This test also asserts the startup success-path log line is masked -
 * never printing more than a few leading characters of kiteApiKey - so a
 * "config loaded" confirmation log can never leak the full secret to
 * stdout/log files.
 *
 * Run with: node companion-daemon/test-config-validation.js
 */
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');

const daemon = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
const { loadConfig, maskSecret } = daemon;

let passed = 0, failed = 0;
function test(name, fn) {
  try { fn(); console.log(`  PASS  ${name}`); passed++; }
  catch (e) { console.log(`  FAIL  ${name}\n        ${e.stack}`); failed++; }
}

const TMP_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'fno-daemon-config-test-'));
function writeTmpConfig(name, content) {
  const p = path.join(TMP_DIR, name);
  fs.writeFileSync(p, content, 'utf8');
  return p;
}

const VALID_CONFIG = {
  kiteApiKey: 'realkiteapikey1234',
  kiteAccessToken: 'realkiteaccesstoken5678',
  wpSiteUrl: 'https://example.test',
  ingestSecret: 'realingestsecretABCDEFG',
  symbol: 'NIFTY',
  tickSize: 0.05,
  postIntervalMs: 5000,
};

function captureConsole(fn) {
  const outLines = [];
  const errLines = [];
  const origLog = console.log, origErr = console.error;
  console.log = (...a) => outLines.push(a.join(' '));
  console.error = (...a) => errLines.push(a.join(' '));
  try { fn(); } finally { console.log = origLog; console.error = origErr; }
  return { outLines, errLines, all: outLines.concat(errLines).join('\n') };
}

console.log('\n=== loadConfig: missing config file ===');
test('missing config.json exits(1) with a clear message, before any network activity', () => {
  let exitCode = null;
  const { all } = captureConsole(() => {
    const result = loadConfig(path.join(TMP_DIR, 'does-not-exist.json'), (code) => { exitCode = code; return undefined; });
    assert.strictEqual(result, undefined, 'must not return a usable config object on failure');
  });
  assert.strictEqual(exitCode, 1);
  assert.ok(/config\.json not found/i.test(all), 'expected a clear "not found" message, got: ' + all);
});

console.log('\n=== loadConfig: malformed JSON ===');
test('malformed JSON (trailing comma) exits(1) with a clear "syntax error" message, not a raw crash', () => {
  const p = writeTmpConfig('malformed.json', '{ "kiteApiKey": "abc", }'); // trailing comma - invalid JSON
  let exitCode = null;
  let threw = false;
  const { all } = captureConsole(() => {
    try {
      loadConfig(p, (code) => { exitCode = code; return undefined; });
    } catch (e) {
      threw = true; // an uncaught SyntaxError escaping loadConfig would land here - must NOT happen
    }
  });
  assert.strictEqual(threw, false, 'JSON.parse syntax error must be caught inside loadConfig, not escape as an uncaught exception');
  assert.strictEqual(exitCode, 1);
  assert.ok(/syntax error/i.test(all), 'expected a clear "syntax error" message, got: ' + all);
});

test('malformed JSON error message never echoes the raw file content (which may contain real secrets mid-edit)', () => {
  const p = writeTmpConfig('malformed2.json', '{ "kiteApiKey": "REALSECRETVALUE1234"');
  const { all } = captureConsole(() => {
    loadConfig(p, () => undefined);
  });
  assert.ok(!all.includes('REALSECRETVALUE1234'), 'malformed-JSON error output must not include the raw file content: ' + all);
});

console.log('\n=== loadConfig: missing required fields ===');
test('missing required field(s) exit(1) and name exactly which fields are missing', () => {
  const p = writeTmpConfig('missing-fields.json', JSON.stringify({ symbol: 'NIFTY' }));
  let exitCode = null;
  const { all } = captureConsole(() => {
    loadConfig(p, (code) => { exitCode = code; return undefined; });
  });
  assert.strictEqual(exitCode, 1);
  assert.ok(all.includes('kiteApiKey'), all);
  assert.ok(all.includes('kiteAccessToken'), all);
  assert.ok(all.includes('wpSiteUrl'), all);
  assert.ok(all.includes('ingestSecret'), all);
});

console.log('\n=== loadConfig: wrong field types ===');
test('a required field present but non-string (number) exits(1) with a clear type message', () => {
  const cfg = Object.assign({}, VALID_CONFIG, { kiteApiKey: 12345 });
  const p = writeTmpConfig('wrong-type-required.json', JSON.stringify(cfg));
  let exitCode = null;
  const { all } = captureConsole(() => {
    loadConfig(p, (code) => { exitCode = code; return undefined; });
  });
  assert.strictEqual(exitCode, 1);
  assert.ok(/kiteApiKey/.test(all) && /string/i.test(all), all);
});

test('optional numeric field present but wrong type (string) exits(1) with a clear type message', () => {
  const cfg = Object.assign({}, VALID_CONFIG, { postIntervalMs: '5000' }); // string, not number
  const p = writeTmpConfig('wrong-type-optional.json', JSON.stringify(cfg));
  let exitCode = null;
  const { all } = captureConsole(() => {
    loadConfig(p, (code) => { exitCode = code; return undefined; });
  });
  assert.strictEqual(exitCode, 1);
  assert.ok(/postIntervalMs/.test(all) && /number/i.test(all), all);
});

test('optional numeric field present but non-finite (NaN via Infinity-producing JSON is impossible, so test a bad literal) rejects', () => {
  const p = writeTmpConfig('wrong-type-tickSize.json', JSON.stringify(Object.assign({}, VALID_CONFIG, { tickSize: 'abc' })));
  let exitCode = null;
  captureConsole(() => { loadConfig(p, (code) => { exitCode = code; return undefined; }); });
  assert.strictEqual(exitCode, 1);
});

console.log('\n=== loadConfig: valid config proceeds normally ===');
test('a fully valid config.json parses, passes validation, and returns the config object (no exit)', () => {
  const p = writeTmpConfig('valid.json', JSON.stringify(VALID_CONFIG));
  let exitCalled = false;
  const result = loadConfig(p, () => { exitCalled = true; return undefined; });
  assert.strictEqual(exitCalled, false, 'exitFn must not be called for a valid config');
  assert.strictEqual(result.kiteApiKey, VALID_CONFIG.kiteApiKey);
  assert.strictEqual(result.symbol, 'NIFTY');
  assert.strictEqual(result.tickSize, 0.05);
});

test('a valid config with only required fields (optional numeric fields omitted) proceeds normally', () => {
  const cfg = { kiteApiKey: 'a', kiteAccessToken: 'b', wpSiteUrl: 'c', ingestSecret: 'd', symbol: 'NIFTY' };
  const p = writeTmpConfig('valid-minimal.json', JSON.stringify(cfg));
  let exitCalled = false;
  const result = loadConfig(p, () => { exitCalled = true; return undefined; });
  assert.strictEqual(exitCalled, false);
  assert.strictEqual(result.symbol, 'NIFTY');
});

console.log('\n=== loadConfig: no full secret ever logged on startup ===');
test('the success-path log line never contains the full kiteApiKey/kiteAccessToken/ingestSecret value', () => {
  const cfg = Object.assign({}, VALID_CONFIG, {
    kiteApiKey: 'SUPERSECRETKITEAPIKEYVALUE',
    kiteAccessToken: 'SUPERSECRETACCESSTOKENVALUE',
    ingestSecret: 'SUPERSECRETINGESTVALUE',
  });
  const p = writeTmpConfig('valid-secrets.json', JSON.stringify(cfg));
  const { all } = captureConsole(() => { loadConfig(p, () => undefined); });
  assert.ok(!all.includes('SUPERSECRETKITEAPIKEYVALUE'), 'full kiteApiKey must not appear in startup log: ' + all);
  assert.ok(!all.includes('SUPERSECRETACCESSTOKENVALUE'), 'full kiteAccessToken must not appear in startup log: ' + all);
  assert.ok(!all.includes('SUPERSECRETINGESTVALUE'), 'full ingestSecret must not appear in startup log: ' + all);
});

test('maskSecret() truncates to a short prefix and never returns the full value for a realistic secret', () => {
  const masked = maskSecret('abcdefghij1234567890');
  assert.ok(masked.length < 'abcdefghij1234567890'.length);
  assert.ok(masked.startsWith('abcd'));
  assert.ok(!masked.includes('1234567890'));
});

test('maskSecret() handles empty/falsy input without throwing', () => {
  assert.strictEqual(maskSecret(''), '(empty)');
  assert.strictEqual(maskSecret(undefined), '(empty)');
});

console.log('\n=== loadConfig: config.json that is not a JSON object ===');
test('a JSON array (valid JSON, wrong shape) exits(1) with a clear message instead of crashing later on config.foo access', () => {
  const p = writeTmpConfig('array.json', JSON.stringify(['not', 'an', 'object']));
  let exitCode = null;
  const { all } = captureConsole(() => { loadConfig(p, (code) => { exitCode = code; return undefined; }); });
  assert.strictEqual(exitCode, 1);
  assert.ok(/object/i.test(all), all);
});

fs.rmSync(TMP_DIR, { recursive: true, force: true });

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
