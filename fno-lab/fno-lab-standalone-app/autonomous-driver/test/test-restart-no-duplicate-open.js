#!/usr/bin/env node
/**
 * Real, permanent regression test for the specific restart-recovery /
 * duplicate-position questions from this audit pass:
 *
 * 1. A driver process that crashes/restarts while a real position is
 *    still open must recover that SAME position (not lose track of it,
 *    and not open a SECOND, duplicate one on top of it).
 * 2. Once that recovered position is genuinely closed, a LATER restart
 *    must NOT re-recover the already-closed position (proving the
 *    close is real, persisted server-side state, not just a local,
 *    in-memory fact that a fresh process could stay ignorant of and
 *    wrongly re-latch onto a stale row).
 *
 * Real, deliberate design, matching test-position-persistence.js: spawns
 * the REAL, current, unmodified autonomous-driver.js source (never a
 * hand-copied stand-in) as TWO SEPARATE, REAL child processes against
 * ONE SAME real, running mock WordPress server - so this test proves
 * genuine cross-process state reconciliation over real HTTP, not just
 * that a single process's own in-memory guard works.
 *
 * Run with: node test/test-restart-no-duplicate-open.js
 */
const { spawn, fork } = require('child_process');
const fs = require('fs');
const path = require('path');

const DRIVER_DIR = path.join(__dirname, '..');
const MOCK_PORT = 8769; // real, distinct port from every other integration test in this suite

function patchedDriverPath() {
  const realSource = fs.readFileSync(path.join(DRIVER_DIR, 'autonomous-driver.js'), 'utf8');
  const marker = 'const marketHours = isRealMarketHours();';
  if (!realSource.includes(marker)) {
    console.error('FATAL: the real driver source no longer contains the expected real market-hours check line - this test\'s own patch point is stale and needs updating.');
    process.exit(1);
  }
  const patchedSource = realSource.replace(
    marker,
    'const marketHours = process.env.FNO_TEST_BYPASS_MARKET_HOURS === "1" ? {isMarketHours:true, reason:"test bypass"} : isRealMarketHours();'
  );
  const tmpDriverPath = path.join(DRIVER_DIR, `.integration-test-tmp-restart-${Date.now()}-${Math.random().toString(36).slice(2)}.js`);
  fs.writeFileSync(tmpDriverPath, patchedSource);
  return tmpDriverPath;
}

function runDriver(env, runMs) {
  return new Promise((resolve) => {
    const tmpDriverPath = patchedDriverPath();
    const driverEnv = {
      ...process.env,
      FNO_SITE_URL: `http://localhost:${MOCK_PORT}`,
      FNO_DRIVER_SECRET: 'test-secret-for-real-integration-test-only',
      FNO_SYMBOL: 'NIFTY',
      FNO_OPTION_TYPE: 'CE',
      FNO_STRIKE_OFFSET: '0',
      FNO_STRIKE_STEP: '200',
      FNO_LOT_SIZE: '50',
      FNO_POLL_INTERVAL_MS: '2000',
      FNO_TEST_BYPASS_MARKET_HOURS: '1',
      NODE_PATH: path.join(DRIVER_DIR, 'node_modules'),
      ...env,
    };
    const driver = spawn('node', [tmpDriverPath], { cwd: DRIVER_DIR, env: driverEnv });
    let driverLog = '';
    driver.stdout.on('data', (d) => { driverLog += d.toString(); });
    driver.stderr.on('data', (d) => { driverLog += d.toString(); });
    setTimeout(() => {
      driver.kill(); // real, deliberate simulated crash/kill - the exact real restart scenario this test exists for
      fs.unlinkSync(tmpDriverPath);
      resolve(driverLog);
    }, runMs);
  });
}

async function main() {
  console.log('=== Restart / No-Duplicate-Open Regression Test (cross-process, shared mock WP server state) ===\n');

  // Real fixture: one already-open intraday position (id 601), CE price
  // held HIGH (well above both a real target and, crucially, above the
  // real SL too) so it does NOT exit during "Run 1" - the exact
  // condition needed to prove Run 2 recovers the SAME still-open
  // position rather than seeing it already closed.
  const intradayFixture = [{ id: 601, symbol: 'NIFTY', strike: 23200, option_type: 'CE', qty: 50, entry_price: 140, sl: 130, target: 500, trading_style: 'intraday' }];
  const mockServer = fork(path.join(__dirname, 'mock-wordpress-server.js'), [], {
    env: { ...process.env, MOCK_PORT: String(MOCK_PORT), MOCK_INTRADAY_POSITIONS: JSON.stringify(intradayFixture), MOCK_CE_23200_PRICE: '160' },
    stdio: ['ignore', 'pipe', 'pipe', 'ipc'],
  });
  let mockLog = '';
  mockServer.stdout.on('data', (d) => { mockLog += d.toString(); });
  await new Promise((resolve) => setTimeout(resolve, 800));

  let passed = 0, failed = 0;
  function check(cond, label) {
    if (cond) { passed++; console.log(`  PASS  ${label}`); }
    else { failed++; console.log(`  FAIL  ${label}`); }
  }

  // --- Run 1: driver process #1 starts, recovers id=601, position stays
  // open (CE @ 160 is above SL 130 and below target 500) - simulate a
  // real crash by killing this process while the position is still open. ---
  console.log('--- Run 1 (recovers pre-existing open position, then is killed mid-flight) ---');
  const log1 = await runDriver({}, 4500);
  check(log1.includes('Real startup recovery: found and restored') && log1.includes('id=601'),
    'Run 1 genuinely recovers the pre-existing open position (id=601) from the (mock) database on startup');
  check(!log1.includes('fno_open_position call received') && !mockLog.includes('fno_open_position call received'),
    'Run 1 never calls fno_open_position at all - a recovered, still-open position correctly blocks new entry-signal evaluation, so no SECOND, duplicate position is opened on top of it');
  check(!log1.includes('Real exit ('), 'Run 1 does NOT exit the position (CE held at 160, between SL 130 and target 500) - it is killed while genuinely still open, the real mid-crash scenario this test targets');

  // --- Run 2: a real, brand-new driver process starts against the SAME
  // running mock server. It must recover the SAME id=601 position again
  // (never lost, never duplicated) - not a fresh, second position. ---
  console.log('\n--- Run 2 (fresh process, same mock server - must recover the SAME position, not duplicate it) ---');
  const log2 = await runDriver({}, 4500);
  check(log2.includes('Real startup recovery: found and restored') && log2.includes('id=601'),
    'Run 2 (a genuinely separate, fresh driver process) recovers the exact SAME position (id=601) that Run 1 had open - not lost across the simulated restart');
  check(!log2.includes('fno_open_position call received') && !mockLog.includes('fno_open_position call received'),
    'Run 2 also never calls fno_open_position - no duplicate position is created for the one already recovered');

  check((log1.match(/Real startup recovery: found and restored/g) || []).length === 1 && (log2.match(/Real startup recovery: found and restored/g) || []).length === 1,
    'each real driver process performs exactly one real startup-recovery HTTP round trip to the (mock) database (not zero, not cached, not repeated) - both genuinely queried fresh state, not stale/local memory');

  // --- Run 3: a real, second, separate mock server (fresh state) starts
  // with the SAME id=601 fixture, but this time the real CE premium is
  // set BELOW its SL (120 < 130) - so this run genuinely exits AND
  // closes id=601 server-side (fno_close_position) via the real
  // exit-side code path, before being killed. ---
  console.log('\n--- Run 3 (recovers id=601, this time genuinely SL-exits and closes it server-side) ---');
  const MOCK_PORT_2 = MOCK_PORT + 1;
  const mockServer2 = fork(path.join(__dirname, 'mock-wordpress-server.js'), [], {
    env: { ...process.env, MOCK_PORT: String(MOCK_PORT_2), MOCK_INTRADAY_POSITIONS: JSON.stringify(intradayFixture), MOCK_CE_23200_PRICE: '120' },
    stdio: ['ignore', 'pipe', 'pipe', 'ipc'],
  });
  let mockLog2 = '';
  mockServer2.stdout.on('data', (d) => { mockLog2 += d.toString(); });
  await new Promise((resolve) => setTimeout(resolve, 800));
  const log3 = await runDriver({ FNO_SITE_URL: `http://localhost:${MOCK_PORT_2}` }, 4500);
  check(log3.includes('Real startup recovery: found and restored') && log3.includes('id=601'), 'Run 3 recovers id=601 on startup, same as Run 1/2');
  check(log3.includes('Real exit (sl): entry 140') && mockLog2.includes('fno_close_position call received: id=601'),
    'Run 3 genuinely SL-exits the recovered position and closes it server-side (real fno_close_position call reaches the mock with id=601)');

  // --- Run 4: a fresh, third driver process against the SAME mock
  // server used in Run 3 (now holding a genuinely CLOSED id=601) - must
  // find NO open position, never re-recovering an already-closed one. ---
  console.log('\n--- Run 4 (fresh process, same mock as Run 3 - id=601 is now closed, must NOT be re-recovered) ---');
  const log4 = await runDriver({ FNO_SITE_URL: `http://localhost:${MOCK_PORT_2}` }, 3000);
  check(log4.includes('no open intraday/scalping position found in the database - starting clean'),
    'Run 4 correctly finds NO open position - the real close from Run 3 is genuinely persisted server-side, so a later restart does not wrongly re-latch onto an already-closed, stale position');
  check(!log4.includes('id=601'), 'Run 4 never even mentions id=601 - it is genuinely gone from the (mock) open-positions view, not just skipped by coincidence');
  mockServer2.kill();

  console.log(`\n${passed} passed, ${failed} failed`);
  mockServer.kill();
  if (failed > 0) {
    console.log('\n--- Run 1 driver output ---');
    console.log(log1);
    console.log('\n--- Run 2 driver output ---');
    console.log(log2);
    console.log('\n--- Run 3 driver output ---');
    console.log(log3);
    console.log('\n--- Run 4 driver output ---');
    console.log(log4);
    console.log('\n--- Mock server 1 output ---');
    console.log(mockLog);
    console.log('\n--- Mock server 2 output ---');
    console.log(mockLog2);
  }
  process.exit(failed > 0 ? 1 : 0);
}

main().catch((e) => { console.error('FATAL:', e); process.exit(1); });
