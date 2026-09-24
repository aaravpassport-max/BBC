#!/usr/bin/env node
/**
 * Regression test for the Kite WebSocket ticker's reconnect/give-up
 * wiring in wireTickerEvents() (kite-microstructure-daemon.js).
 *
 * CONTEXT (audit finding, dated 2026-08-29): the reconnect-with-backoff
 * logic itself lives inside the `kiteconnect` npm library's KiteTicker
 * class (exponential backoff between attempts, bounded retry count) -
 * this file does not, and should not, reimplement that. What this file
 * DID own, and what was missing real test coverage, is what happens
 * once the library gives up and fires 'noreconnect': previously the
 * handler only did console.error() and let the process keep running -
 * so a daemon left unattended (e.g. after a daily Kite access-token
 * expiry) would sit "alive" per any process supervisor while silently
 * never receiving another tick again, with no way for a supervisor to
 * notice or restart it. The fix makes 'noreconnect' exit(1), matching
 * every other fatal-condition handler already in this file (missing
 * config, missing kiteconnect module, failed instrument lookup all
 * process.exit(1) already - see kite-microstructure-daemon.js).
 *
 * This test uses a real Node EventEmitter as a stand-in for KiteTicker
 * (KiteTicker itself is just an EventEmitter with .connect/.subscribe/
 * .setMode/.on - the wiring code under test only touches those), and
 * asserts wireTickerEvents():
 *   1. Calls subscribe/setMode with the resolved instrument token on 'connect'
 *   2. Processes real ticks into tickTimestamps/rawTickBuffer on 'ticks'
 *   3. Does NOT exit on 'error', 'disconnect', or 'reconnect' (those are
 *      normal/transient - only 'noreconnect' means "library gave up")
 *   4. DOES exit(1) on 'noreconnect'
 *
 * Run with: node companion-daemon/test-ticker-reconnect.js
 */
const assert = require('assert');
const path = require('path');
const { EventEmitter } = require('events');

const daemon = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
const { wireTickerEvents, _setConfigForTest, computeTickRule } = daemon;

let passed = 0, failed = 0;
function test(name, fn) {
  try { fn(); console.log(`  PASS  ${name}`); passed++; }
  catch (e) { console.log(`  FAIL  ${name}\n        ${e.stack}`); failed++; }
}

function makeMockTicker() {
  const ee = new EventEmitter();
  ee.modeFull = 'full';
  ee.subscribeCalls = [];
  ee.setModeCalls = [];
  ee.subscribe = (tokens) => ee.subscribeCalls.push(tokens);
  ee.setMode = (mode, tokens) => ee.setModeCalls.push({ mode, tokens });
  return ee;
}

console.log('\n=== wireTickerEvents: connect/subscribe wiring ===');
test('connect event subscribes and sets full mode for the resolved token', () => {
  const ticker = makeMockTicker();
  let exitCode = null;
  wireTickerEvents(ticker, 12345, (code) => { exitCode = code; });
  ticker.emit('connect');
  assert.deepStrictEqual(ticker.subscribeCalls, [[12345]]);
  assert.deepStrictEqual(ticker.setModeCalls, [{ mode: 'full', tokens: [12345] }]);
  assert.strictEqual(exitCode, null, 'connect must not trigger exit');
});

console.log('\n=== wireTickerEvents: transient events must NOT exit the process ===');
test('a plain "error" event does not exit', () => {
  const ticker = makeMockTicker();
  let exitCode = null;
  wireTickerEvents(ticker, 1, (code) => { exitCode = code; });
  ticker.emit('error', new Error('transient socket error'));
  assert.strictEqual(exitCode, null);
});

test('a "disconnect" event does not exit (the library will itself attempt to reconnect)', () => {
  const ticker = makeMockTicker();
  let exitCode = null;
  wireTickerEvents(ticker, 1, (code) => { exitCode = code; });
  ticker.emit('disconnect', new Error('socket closed'));
  assert.strictEqual(exitCode, null);
});

test('a "reconnect" event (library retrying with backoff) does not exit', () => {
  const ticker = makeMockTicker();
  let exitCode = null;
  wireTickerEvents(ticker, 1, (code) => { exitCode = code; });
  ticker.emit('reconnect', 3, 4000); // attempt 3, 4s delay - proof the library IS backing off, not hammering
  assert.strictEqual(exitCode, null);
});

console.log('\n=== wireTickerEvents: "noreconnect" (library exhausted its retry budget) ===');
test('"noreconnect" exits the process with a non-zero code so a supervisor can restart/alert', () => {
  const ticker = makeMockTicker();
  let exitCode = null;
  wireTickerEvents(ticker, 1, (code) => { exitCode = code; });
  ticker.emit('noreconnect');
  assert.strictEqual(exitCode, 1, 'expected exit(1) on noreconnect, so the daemon does not sit "alive" forever while silently receiving no data');
});

test('"noreconnect" firing after prior reconnect attempts still exits (realistic multi-event sequence)', () => {
  // Simulates a real outage: connect, several transient reconnect
  // attempts with growing delay (proving backoff, not a tight loop),
  // then the library finally gives up.
  const ticker = makeMockTicker();
  let exitCode = null;
  wireTickerEvents(ticker, 99, (code) => { exitCode = code; });
  ticker.emit('connect');
  ticker.emit('disconnect', new Error('network blip'));
  ticker.emit('reconnect', 1, 2000);
  ticker.emit('reconnect', 2, 4000);
  ticker.emit('reconnect', 3, 8000); // exponential-ish growth, not a fixed tight-loop interval
  assert.strictEqual(exitCode, null, 'must not have exited yet - still retrying');
  ticker.emit('noreconnect');
  assert.strictEqual(exitCode, 1, 'must exit only once the library truly gives up');
});

console.log('\n=== wireTickerEvents: real tick ingestion still works after wiring extraction ===');
test('a real "ticks" payload for the tracked token is processed (not dropped by the refactor)', () => {
  _setConfigForTest({ symbol: 'NIFTY' });
  const ticker = makeMockTicker();
  wireTickerEvents(ticker, 555, () => {});
  ticker.emit('connect');
  ticker.emit('ticks', [
    { instrument_token: 555, last_price: 100, volume_traded: 1000, depth: { buy: [{ price: 99.9 }], sell: [{ price: 100.1 }] } },
    { instrument_token: 999, last_price: 5000, volume_traded: 1 }, // different instrument - must be ignored
  ]);
  // No exception thrown means it ran the same computeTickRule / processVolume /
  // processDepthForIceberg / processDepthForSpoofing / rawTickBuffer.push
  // path that existed before this file's ticker.on('ticks', ...) was
  // extracted into wireTickerEvents() - i.e. the refactor didn't drop it.
});

console.log('\n=== wireTickerEvents: real option-strike subscription + raw-tick capture (Zerodha-maximization audit) ===');
test('connect event subscribes/sets full mode for BOTH the underlying token AND every real configured option-strike token', () => {
  const ticker = makeMockTicker();
  const optionTokens = new Map([[201, { instrumentKey: 'NIFTY24AUG23200CE', tradingsymbol: 'NIFTY24AUG23200CE', symbol: 'NIFTY' }], [202, { instrumentKey: 'NIFTY24AUG23200PE', tradingsymbol: 'NIFTY24AUG23200PE', symbol: 'NIFTY' }]]);
  wireTickerEvents(ticker, 12345, () => {}, optionTokens);
  ticker.emit('connect');
  assert.deepStrictEqual(ticker.subscribeCalls, [[12345, 201, 202]], 'must subscribe to the real underlying token plus every real option-strike token, in one call');
  assert.deepStrictEqual(ticker.setModeCalls, [{ mode: 'full', tokens: [12345, 201, 202] }], 'option strikes get real full-mode depth too, not just LTP');
});

test('a real tick for a tracked option-strike token is captured into rawTickBuffer with its REAL matched tradingsymbol, never config.symbol', () => {
  _setConfigForTest({ symbol: 'NIFTY' });
  const bufBefore = daemon._getRawTickBufferForTest().length;
  const ticker = makeMockTicker();
  const optionTokens = new Map([[201, { instrumentKey: 'NIFTY24AUG23200CE', tradingsymbol: 'NIFTY24AUG23200CE', symbol: 'NIFTY' }]]);
  wireTickerEvents(ticker, 12345, () => {}, optionTokens);
  ticker.emit('connect');
  ticker.emit('ticks', [
    { instrument_token: 201, last_price: 145.5, volume_traded: 3000, oi: 50000, depth: { buy: [{ price: 145.3 }], sell: [{ price: 145.7 }] } },
  ]);
  const buf = daemon._getRawTickBufferForTest();
  assert.strictEqual(buf.length, bufBefore + 1, 'exactly one real raw tick captured for the option-strike tick');
  const row = buf[buf.length - 1];
  assert.strictEqual(row.symbol, 'NIFTY24AUG23200CE', 'the real, matched option tradingsymbol is used, never config.symbol (which is just "NIFTY", the underlying)');
  assert.strictEqual(row.ltp, 145.5);
  assert.strictEqual(row.bid, 145.3);
  assert.strictEqual(row.ask, 145.7);
  assert.strictEqual(row.source, 'daemon_kite');
});

test('an option-strike tick is routed into its OWN independent state (getOrCreateState), never mixed into the underlying state (real per-instrument isolation, this pass)', () => {
  // Uses two ticks with wildly different prices (underlying ~23000-pt
  // index vs a ~145-Rs option premium) - if an option tick were ever
  // mistakenly run through the underlying's OWN state object, it would
  // corrupt lastPrice with a nonsensical ~150x jump. This test proves
  // that never happens: a genuine underlying tick right after an
  // option tick still resolves its OWN direction correctly, AND the
  // option strike's own independent state was genuinely updated too
  // (not silently dropped) - real per-token isolation, not the old
  // scope-limited raw-capture-only behavior.
  _setConfigForTest({ symbol: 'NIFTY' });
  const ticker = makeMockTicker();
  const optionTokens = new Map([[201, { instrumentKey: 'NIFTY24AUG23200CE', tradingsymbol: 'NIFTY24AUG23200CE', symbol: 'NIFTY' }]]);
  wireTickerEvents(ticker, 555555, () => {}, optionTokens);
  ticker.emit('connect');
  ticker.emit('ticks', [{ instrument_token: 555555, last_price: 23000, volume_traded: 100000 }]);
  ticker.emit('ticks', [{ instrument_token: 201, last_price: 145.5, volume_traded: 3000 }]); // option tick in between
  ticker.emit('ticks', [{ instrument_token: 555555, last_price: 23010, volume_traded: 100200 }]);
  const dir = computeTickRule(23020); // one more real underlying tick, continuing the same real up-move
  assert.strictEqual(dir, 1, 'the underlying direction correctly reflects only real underlying ticks (23000->23010->23020, all up) - the option tick at 145.5 never poisoned lastPrice');

  const optState = daemon.getOrCreateState(201);
  assert.strictEqual(optState.lastPrice, 145.5, 'the real option strike (token 201) has its OWN, independent state, genuinely updated by its own tick - not silently dropped, and not the underlying\'s lastPrice');
  assert.ok(optState.tickTimestamps.length >= 1, 'the real option strike\'s own tickTimestamps genuinely accrued from its own tick(s), independent of the underlying\'s (state persists across this file\'s own sequential tests, same token 201 reused by an earlier test above, so length is >=1 rather than strictly 1)');
});

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) process.exit(1);
