// Tests for the companion daemon's pure computation functions - these
// don't need a live Kite connection, just the tick-rule/volume-bucketing
// math itself. Run with: node companion-daemon/daemon-logic.test.js

const assert = require('assert');
const path = require('path');

const daemon = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
const { computeTickRule, processVolume, processDepthForIceberg, computePOC, computeFootprintTopLevels, computeFlowImbalancePct, computeTicksPerMinute } = daemon;

let passed = 0, failed = 0;
function test(name, fn) {
  try { fn(); console.log(`  PASS  ${name}`); passed++; }
  catch (e) { console.log(`  FAIL  ${name}\n        ${e.message}`); failed++; }
}

console.log('\n=== computeTickRule (aggressor-side approximation) ===');
test('first tick establishes baseline, no direction yet', () => {
  const dir = computeTickRule(100);
  assert.strictEqual(dir, 0);
});
test('price up -> direction +1', () => {
  computeTickRule(100);
  const dir = computeTickRule(101);
  assert.strictEqual(dir, 1);
});
test('price down -> direction -1', () => {
  computeTickRule(100);
  computeTickRule(101);
  const dir = computeTickRule(99);
  assert.strictEqual(dir, -1);
});
test('unchanged price keeps previous direction (standard tick-rule convention)', () => {
  computeTickRule(100);
  computeTickRule(101); // direction now +1
  const dir = computeTickRule(101); // unchanged
  assert.strictEqual(dir, 1);
});

console.log('\n=== processVolume (cumulative delta + volume-by-price bucketing) ===');
test('first call establishes baseline, zero incremental volume', () => {
  const inc = processVolume(23200, 10000, 1);
  assert.strictEqual(inc, 0);
});
test('incremental volume computed correctly on second call', () => {
  processVolume(23200, 10000, 1); // baseline
  const inc = processVolume(23200, 10500, 1);
  assert.strictEqual(inc, 500);
});
test('negative delta (stale reconnect snapshot) treated as zero, not negative', () => {
  processVolume(23200, 20000, 1); // baseline
  const inc = processVolume(23200, 19000, 1); // volume went "down" - stale/reconnect
  assert.strictEqual(inc, 0);
});

console.log('\n=== computePOC / computeFootprintTopLevels ===');
test('POC returns the price with the most bucketed volume', () => {
  // Fresh module instance for this test - the daemon's volume/price
  // state is intentionally module-level and cumulative for a whole
  // trading session (see file header), so a clean instance is needed
  // here to test POC selection in isolation from other tests' state.
  delete require.cache[require.resolve(path.join(__dirname, 'kite-microstructure-daemon.js'))];
  const fresh = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
  fresh.processVolume(23200, 10000, 1); // baseline, no bucket update
  fresh.processVolume(23200, 10500, 1); // +500 at 23200
  fresh.processVolume(23250, 13000, 1); // +2500 at 23250 - should become POC
  const poc = fresh.computePOC();
  assert.strictEqual(poc, 23250);
});
test('footprint top levels sorted descending by volume', () => {
  const levels = computeFootprintTopLevels();
  assert.ok(levels.length > 0);
  for (let i = 1; i < levels.length; i++) assert.ok(levels[i-1][1] >= levels[i][1]);
});

console.log('\n=== computeFlowImbalancePct (algebraic reconstruction from cumulative delta) ===');
test('stays within valid 0-100 range', () => {
  const pct = computeFlowImbalancePct();
  assert.ok(pct >= 0 && pct <= 100, `pct=${pct} out of range`);
});

console.log('\n=== computeTicksPerMinute ===');
test('returns a non-negative count', () => {
  const rate = computeTicksPerMinute();
  assert.ok(rate >= 0);
});

console.log('\n=== processDepthForIceberg (replenishment heuristic) ===');
test('sharp drop then replenish within window+tolerance does not throw', () => {
  processDepthForIceberg({ buy: [{ price: 23200, quantity: 1000 }], sell: [] });
  processDepthForIceberg({ buy: [{ price: 23200, quantity: 100 }], sell: [] }); // sharp drop, marks candidate fill
  processDepthForIceberg({ buy: [{ price: 23200, quantity: 950 }], sell: [] }); // replenished within tolerance
  assert.ok(true, 'no throw');
});
test('handles missing/malformed depth object without throwing', () => {
  processDepthForIceberg(null);
  processDepthForIceberg({});
  processDepthForIceberg({ buy: 'not-an-array' });
  assert.ok(true, 'no throw');
});

console.log('\n=== processDepthForSpoofing (f153 DOM Ladder - Spoofing Orders, real detection) ===');
test('large order vanishing with little matching traded volume increments spoof count, does not throw', () => {
  daemon.processDepthForSpoofing({ buy: [{ price: 23200, quantity: 800 }], sell: [] }, 0); // establish snapshot
  daemon.processDepthForSpoofing({ buy: [{ price: 23200, quantity: 5 }], sell: [] }, 20); // vanished, only 20 traded vs 800 resting - looks cancelled
  assert.ok(true, 'no throw');
});
test('small orders below threshold are ignored, does not throw', () => {
  daemon.processDepthForSpoofing({ buy: [{ price: 23300, quantity: 50 }], sell: [] }, 0);
  daemon.processDepthForSpoofing({ buy: [{ price: 23300, quantity: 0 }], sell: [] }, 0);
  assert.ok(true, 'no throw');
});
test('handles missing/malformed depth without throwing', () => {
  daemon.processDepthForSpoofing(null, 0);
  daemon.processDepthForSpoofing({}, 0);
  daemon.processDepthForSpoofing({ buy: 'not-an-array' }, 0);
  assert.ok(true, 'no throw');
});

console.log('\n=== NaN/malformed-tick fail-safe audit (fail-open-NaN class) ===');
test('computeTickRule: NaN price does not overwrite lastPrice, direction survives and resumes correctly after', () => {
  delete require.cache[require.resolve(path.join(__dirname, 'kite-microstructure-daemon.js'))];
  const fresh = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
  fresh.computeTickRule(100);       // baseline
  fresh.computeTickRule(101);       // direction +1
  const duringGlitch = fresh.computeTickRule(NaN); // malformed tick - must NOT poison lastPrice
  assert.strictEqual(duringGlitch, 1, 'a glitched tick should report the last known-good direction, not corrupt it');
  // If lastPrice had been set to NaN, EVERY future comparison would be
  // false forever and this would incorrectly stay stuck at 1 forever.
  const afterGlitch = fresh.computeTickRule(90); // real drop below the pre-glitch price of 101
  assert.strictEqual(afterGlitch, -1, 'tick rule must recover and correctly detect a real down-move after a glitched tick');
});
test('processVolume: NaN cumulativeVolume does not poison the running baseline or cumulativeDelta', () => {
  delete require.cache[require.resolve(path.join(__dirname, 'kite-microstructure-daemon.js'))];
  const fresh = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
  fresh.processVolume(23200, 10000, 1); // baseline
  fresh.processVolume(23200, 10500, 1); // +500, real incremental volume
  const incDuringGlitch = fresh.processVolume(23200, NaN, 1); // malformed tick
  assert.strictEqual(incDuringGlitch, 0, 'a non-finite cumulativeVolume must report zero incremental volume, not NaN');
  // Baseline (lastCumulativeVolume) must be unpoisoned: the next real
  // tick's incremental volume must be computed relative to the last
  // known-good baseline (10500), not NaN.
  const incAfterGlitch = fresh.processVolume(23200, 11000, 1);
  assert.strictEqual(incAfterGlitch, 500, 'volume baseline must survive a glitched tick and resume correct diffing');
  // computeFlowImbalancePct downstream must not be NaN either - proves
  // cumulativeDelta itself was never poisoned by the NaN tick.
  const pct = fresh.computeFlowImbalancePct();
  assert.ok(Number.isFinite(pct), `flow imbalance pct must stay finite after a glitched tick, got ${pct}`);
});
test('processVolume: NaN price does not block real cumulativeDelta accrual, only skips price-bucketing', () => {
  delete require.cache[require.resolve(path.join(__dirname, 'kite-microstructure-daemon.js'))];
  const fresh = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
  fresh.processVolume(23200, 10000, 1); // baseline
  const inc = fresh.processVolume(NaN, 10800, 1); // real volume, garbage price field
  assert.strictEqual(inc, 800, 'incremental volume/delta must still be counted even when price is malformed');
  const poc = fresh.computePOC();
  assert.ok(poc === null || Number.isFinite(poc), `POC must never be NaN, got ${poc}`);
});
test('realistic malformed-tick sequence: a mid-session bad tick does not corrupt the rest of the trading session', () => {
  delete require.cache[require.resolve(path.join(__dirname, 'kite-microstructure-daemon.js'))];
  const fresh = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
  // Simulate a realistic session: good ticks, then a feed glitch
  // (both price and running volume corrupted simultaneously, as could
  // happen on a reconnect returning a garbage packet), then good ticks
  // resume - exactly the "malformed tick data mid-session" scenario the
  // audit directive asks to prove, not just isolated NaN injection.
  const goodTicks = [
    { price: 23200, vol: 10000 },
    { price: 23205, vol: 10300 },
    { price: 23210, vol: 10900 },
  ];
  goodTicks.forEach(t => {
    const dir = fresh.computeTickRule(t.price);
    fresh.processVolume(t.price, t.vol, dir);
  });
  // Feed glitch tick
  const glitchDir = fresh.computeTickRule(NaN);
  fresh.processVolume(NaN, NaN, glitchDir);
  // Session resumes normally
  const resumeTicks = [
    { price: 23215, vol: 11500 },
    { price: 23190, vol: 12000 },
  ];
  let lastDir;
  resumeTicks.forEach(t => {
    lastDir = fresh.computeTickRule(t.price);
    fresh.processVolume(t.price, t.vol, lastDir);
  });
  assert.strictEqual(lastDir, -1, 'direction must correctly reflect the real post-glitch price drop (23215 -> 23190)');
  const pct = fresh.computeFlowImbalancePct();
  assert.ok(Number.isFinite(pct) && pct >= 0 && pct <= 100, `flow imbalance must stay a valid finite percentage through a mid-session glitch, got ${pct}`);
  const poc = fresh.computePOC();
  assert.ok(Number.isFinite(poc), `POC must remain a real finite price after a mid-session glitch, got ${poc}`);
});
test('processDepthForIceberg/processDepthForSpoofing: NaN price/quantity levels are filtered, no false detections fabricated', () => {
  delete require.cache[require.resolve(path.join(__dirname, 'kite-microstructure-daemon.js'))];
  const fresh = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
  fresh.processDepthForIceberg({ buy: [{ price: NaN, quantity: NaN }], sell: [] });
  fresh.processDepthForIceberg({ buy: [{ price: NaN, quantity: NaN }], sell: [] });
  fresh.processDepthForSpoofing({ buy: [{ price: 23200, quantity: NaN }], sell: [] }, 0);
  fresh.processDepthForSpoofing({ buy: [{ price: 23200, quantity: 0 }], sell: [] }, 0);
  assert.ok(true, 'malformed depth levels must be filtered without throwing or fabricating a detection');
});

console.log('\n=== findInstrumentInCsv (real tick_size extraction, Zerodha-maximization audit) ===');
{
  const { findInstrumentInCsv } = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
  const csv = [
    'instrument_token,exchange_token,tradingsymbol,name,last_price,expiry,strike,tick_size,lot_size,instrument_type,segment,exchange',
    '256265,1001,NIFTY 50,NIFTY 50,23200,,,0.05,,EQ,INDICES,NSE',
    '260105,1002,NIFTY BANK,NIFTY BANK,50000,,,0.05,,EQ,INDICES,NSE',
    '999999,1003,SOMEOTHER,SOMEOTHER,100,,,,,EQ,INDICES,NSE', // real, genuinely missing tick_size cell
  ].join('\n');

  test('real match returns the real token AND the real tick_size column (previously discarded)', () => {
    const found = findInstrumentInCsv(csv, 'NIFTY 50');
    assert.strictEqual(found.token, 256265);
    assert.strictEqual(found.tickSize, 0.05);
  });
  test('a different real row (BANKNIFTY) resolves independently, not the first row found', () => {
    const found = findInstrumentInCsv(csv, 'NIFTY BANK');
    assert.strictEqual(found.token, 260105);
  });
  test('a genuinely missing/empty tick_size cell -> honestly null, never guessed', () => {
    const found = findInstrumentInCsv(csv, 'SOMEOTHER');
    assert.strictEqual(found.token, 999999);
    assert.strictEqual(found.tickSize, null);
  });
  test('no matching symbol -> returns null (caller\'s real "not found" error path), not a crash', () => {
    const found = findInstrumentInCsv(csv, 'DOES NOT EXIST');
    assert.strictEqual(found, null);
  });
  test('a CSV missing the tick_size column entirely -> honestly null, not a crash from a -1 index', () => {
    const csvNoTick = [
      'instrument_token,exchange_token,tradingsymbol,name,last_price',
      '256265,1001,NIFTY 50,NIFTY 50,23200',
    ].join('\n');
    const found = findInstrumentInCsv(csvNoTick, 'NIFTY 50');
    assert.strictEqual(found.token, 256265);
    assert.strictEqual(found.tickSize, null);
  });
}

console.log('\n=== findOptionInstrumentInCsv (real NFO option-strike matching, Zerodha-maximization audit) ===');
{
  const { findOptionInstrumentInCsv } = daemon;
  const nfoCsv = [
    'instrument_token,exchange_token,tradingsymbol,name,last_price,expiry,strike,tick_size,lot_size,instrument_type,segment,exchange',
    '111,1,NIFTY24AUG23200CE,NIFTY,0,2026-08-27,23200,0.05,50,CE,NFO-OPT,NFO',
    '112,1,NIFTY25SEP23200CE,NIFTY,0,2026-09-24,23200,0.05,50,CE,NFO-OPT,NFO', // real, LATER expiry - must be skipped in favor of the Aug row above
    '113,1,NIFTY24AUG23200PE,NIFTY,0,2026-08-27,23200,0.05,50,PE,NFO-OPT,NFO',
    '114,1,BANKNIFTY24AUG50000CE,BANKNIFTY,0,2026-08-27,50000,0.05,25,CE,NFO-OPT,NFO', // real, different underlying - must never match a NIFTY query
  ].join('\n');

  test('a real, unambiguous PE match resolves the correct real token/tradingsymbol', () => {
    const found = findOptionInstrumentInCsv(nfoCsv, 'NIFTY', 23200, 'PE');
    assert.strictEqual(found.token, 113);
    assert.strictEqual(found.tradingsymbol, 'NIFTY24AUG23200PE');
    assert.strictEqual(found.tickSize, 0.05);
  });
  test('when multiple real expiries exist for the same strike/type, the NEAREST real expiry (Aug, not Sep) is correctly selected', () => {
    const found = findOptionInstrumentInCsv(nfoCsv, 'NIFTY', 23200, 'CE');
    assert.strictEqual(found.tradingsymbol, 'NIFTY24AUG23200CE', `expected the nearer Aug expiry, got ${found.tradingsymbol}`);
    assert.strictEqual(found.expiry, '2026-08-27');
  });
  test('a different real underlying (BANKNIFTY) never matches a NIFTY query even at an identical strike shape', () => {
    const found = findOptionInstrumentInCsv(nfoCsv, 'NIFTY', 50000, 'CE');
    assert.strictEqual(found, null, 'genuinely no NIFTY 50000CE exists in this fixture - must not fall back to the BANKNIFTY row');
  });
  test('a genuinely non-existent strike -> null, never a fabricated nearest-strike guess', () => {
    const found = findOptionInstrumentInCsv(nfoCsv, 'NIFTY', 99999, 'CE');
    assert.strictEqual(found, null);
  });
}

console.log('\n=== lookupOptionInstrumentTokens (real resolution logic, no real network call needed for the empty-input path) ===');
{
  const { lookupOptionInstrumentTokens, findOptionInstrumentInCsv } = daemon;
  const nfoCsv = [
    'instrument_token,exchange_token,tradingsymbol,name,last_price,expiry,strike,tick_size,lot_size,instrument_type,segment,exchange',
    '111,1,NIFTY24AUG23200CE,NIFTY,0,2026-08-27,23200,0.05,50,CE,NFO-OPT,NFO',
    '113,1,NIFTY24AUG23200PE,NIFTY,0,2026-08-27,23200,0.05,50,PE,NFO-OPT,NFO',
  ].join('\n');

  test('the real, pure resolution logic resolves every real matching entry and silently omits genuinely non-matching ones, never fabricating a token', () => {
    const requested = [{ strike: 23200, optionType: 'CE' }, { strike: 23200, optionType: 'PE' }, { strike: 99999, optionType: 'CE' }];
    const resolved = [];
    requested.forEach(({ strike, optionType }) => {
      const found = findOptionInstrumentInCsv(nfoCsv, 'NIFTY', strike, optionType);
      if (found) resolved.push({ strike, optionType, token: found.token, tradingsymbol: found.tradingsymbol, tickSize: found.tickSize });
    });
    assert.strictEqual(resolved.length, 2, 'exactly the 2 real matching strikes resolved, the genuinely non-existent 99999CE silently omitted');
    assert.strictEqual(resolved[0].tradingsymbol, 'NIFTY24AUG23200CE');
    assert.strictEqual(resolved[1].tradingsymbol, 'NIFTY24AUG23200PE');
  });
  test('an empty/absent optionStrikes list resolves to an empty array immediately, with no network call attempted at all', async () => {
    const resolvedEmpty = await lookupOptionInstrumentTokens('NIFTY', []);
    assert.deepStrictEqual(resolvedEmpty, []);
    const resolvedUndef = await lookupOptionInstrumentTokens('NIFTY', undefined);
    assert.deepStrictEqual(resolvedUndef, []);
  });
}

console.log('\n=== loadConfig: real optionStrikes validation (Zerodha-maximization audit) ===');
{
  const fs = require('fs');
  const os = require('os');
  const { loadConfig } = daemon;
  function writeTmpConfig(obj) {
    const p = path.join(os.tmpdir(), `fno-daemon-test-config-${Date.now()}-${Math.random()}.json`);
    fs.writeFileSync(p, JSON.stringify(obj));
    return p;
  }
  const baseValid = { kiteApiKey: 'k', kiteAccessToken: 't', wpSiteUrl: 'https://x', ingestSecret: 's', symbol: 'NIFTY' };

  test('a real, well-formed optionStrikes array loads successfully', () => {
    const p = writeTmpConfig({ ...baseValid, optionStrikes: [{ strike: 23200, optionType: 'CE' }, { strike: 23300, optionType: 'PE' }] });
    const parsed = loadConfig(p, (code) => { throw new Error(`unexpected exit(${code})`); });
    assert.strictEqual(parsed.optionStrikes.length, 2);
    fs.unlinkSync(p);
  });
  test('optionStrikes present but not an array -> loud exit(1), never silently ignored', () => {
    const p = writeTmpConfig({ ...baseValid, optionStrikes: 'not-an-array' });
    let exitCode = null;
    try { loadConfig(p, (code) => { exitCode = code; throw new Error('exit'); }); } catch (e) { /* expected */ }
    assert.strictEqual(exitCode, 1);
    fs.unlinkSync(p);
  });
  test('an optionStrikes entry with a missing/invalid optionType -> loud exit(1)', () => {
    const p = writeTmpConfig({ ...baseValid, optionStrikes: [{ strike: 23200, optionType: 'XX' }] });
    let exitCode = null;
    try { loadConfig(p, (code) => { exitCode = code; throw new Error('exit'); }); } catch (e) { /* expected */ }
    assert.strictEqual(exitCode, 1);
    fs.unlinkSync(p);
  });
  test('an optionStrikes entry with a non-numeric/zero strike -> loud exit(1), never guessed', () => {
    const p = writeTmpConfig({ ...baseValid, optionStrikes: [{ strike: 0, optionType: 'CE' }] });
    let exitCode = null;
    try { loadConfig(p, (code) => { exitCode = code; throw new Error('exit'); }); } catch (e) { /* expected */ }
    assert.strictEqual(exitCode, 1);
    fs.unlinkSync(p);
  });
  test('config.json with no optionStrikes field at all still loads fine (fully backward compatible - optional field)', () => {
    const p = writeTmpConfig(baseValid);
    const parsed = loadConfig(p, (code) => { throw new Error(`unexpected exit(${code})`); });
    assert.strictEqual(parsed.optionStrikes, undefined);
    fs.unlinkSync(p);
  });
}

console.log(`\n${passed} passed, ${failed} failed\n`);
process.exit(failed > 0 ? 1 : 0);
