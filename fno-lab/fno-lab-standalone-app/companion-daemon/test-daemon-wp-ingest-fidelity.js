#!/usr/bin/env node
/**
 * Fidelity test for the companion daemon's REAL WordPress-ingest
 * network path (postSnapshot / flushRawTicks in
 * kite-microstructure-daemon.js).
 *
 * CONTEXT (why this test exists): the autonomous-driver's mock
 * WordPress server was found this session to be structurally blind to
 * real wp_ajax_/wp_ajax_nopriv_ routing semantics, giving false
 * confidence from 53+ "passing" tests about a real missing-nopriv bug.
 * This companion-daemon codebase was audited for the same risk class.
 * Findings, with direct evidence:
 *
 *   1. companion-daemon uses admin-ajax.php actions (NOT REST routes):
 *      kite-microstructure-daemon.js posts to
 *      '/wp-admin/admin-ajax.php?action=fno_ingest_microstructure' and
 *      '...?action=fno_ingest_raw_tick' (see postSnapshot/flushRawTicks).
 *
 *   2. Unlike the driver, companion-daemon's existing test suite
 *      (daemon-logic.test.js, 16/16) has NO mock WordPress server at
 *      all - it only tests pure computation functions. postSnapshot and
 *      flushRawTicks were not even exported from the module before this
 *      test was added, so the network/auth/routing code had ZERO test
 *      coverage (not "covered by a blind mock" - literally untested).
 *      That is a real, distinct gap from the driver's bug, not the same
 *      false-confidence pattern - fixed here by actually exercising the
 *      real network code against a real HTTP server.
 *
 *   3. fno-lab.php DOES register both wp_ajax_fno_ingest_microstructure
 *      AND wp_ajax_nopriv_fno_ingest_microstructure (and same for
 *      fno_ingest_raw_tick) - verified below by parsing the real,
 *      current fno-lab.php source (not hand-duplicated), so this test
 *      fails the moment that registration pair ever regresses. This
 *      was also proven by deliberately breaking a real registration and
 *      confirming this exact test then fails (see PENDING_REQUIREMENTS.md
 *      for the transcript of that proof) - the break was reverted before
 *      this file was committed.
 *
 *   4. Auth is a custom shared-secret header (X-Fno-Daemon-Secret),
 *      NOT a WP session/nonce/cookie - correct for a headless daemon
 *      with no browser session. This test's mock server enforces that
 *      header exactly the way fno_ingest_microstructure_fn /
 *      fno_ingest_raw_tick_fn do (hash_equals-style exact match,
 *      missing/wrong header -> 403), and proves the real daemon code
 *      sends it correctly.
 *
 * Run with: node companion-daemon/test-daemon-wp-ingest-fidelity.js
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const assert = require('assert');

const FNO_LAB_PHP = path.join(__dirname, '..', 'fno-lab.php');
const PORT = 8991; // distinct from any other real test server in this repo

let passed = 0, failed = 0;
function check(cond, label) {
  if (cond) { passed++; console.log(`  PASS  ${label}`); }
  else { failed++; console.log(`  FAIL  ${label}`); }
}

// --- Step 1: derive the REAL registration facts from fno-lab.php itself,
// never a hand-maintained duplicate list (this is the exact discipline
// that was missing from the driver's mock before the fix). ---
function realRegistrations(action) {
  const src = fs.readFileSync(FNO_LAB_PHP, 'utf8');
  const privRe = new RegExp(`add_action\\(\\s*'wp_ajax_${action}'\\s*,`);
  const noprivRe = new RegExp(`add_action\\(\\s*'wp_ajax_nopriv_${action}'\\s*,`);
  return { priv: privRe.test(src), nopriv: noprivRe.test(src) };
}

async function main() {
  console.log('=== Companion Daemon WP-Ingest Fidelity Test ===\n');

  // --- Step 1 checks: real registration facts, parsed live from fno-lab.php ---
  const micro = realRegistrations('fno_ingest_microstructure');
  check(micro.priv, 'fno-lab.php registers wp_ajax_fno_ingest_microstructure');
  check(micro.nopriv, 'fno-lab.php registers wp_ajax_nopriv_fno_ingest_microstructure (required - daemon is a headless, session-less process)');

  const rawtick = realRegistrations('fno_ingest_raw_tick');
  check(rawtick.priv, 'fno-lab.php registers wp_ajax_fno_ingest_raw_tick');
  check(rawtick.nopriv, 'fno-lab.php registers wp_ajax_nopriv_fno_ingest_raw_tick (required - daemon is a headless, session-less process)');

  // --- Step 2: spin up a real mock WP server that enforces the SAME
  // shape of auth the real handlers use (exact secret match -> 403 on
  // mismatch/missing), and drive the REAL daemon postSnapshot/
  // flushRawTicks functions against it - not a hand-written fake POST. ---
  const SECRET = 'test-secret-92f1';
  const received = [];
  const server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      const u = new URL(req.url, `http://localhost:${PORT}`);
      const action = u.searchParams.get('action');
      const secretHeader = req.headers['x-fno-daemon-secret'];
      received.push({ path: u.pathname, action, method: req.method, secretHeader, body, contentType: req.headers['content-type'] });
      if (secretHeader !== SECRET) {
        res.writeHead(403, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, data: { message: 'Invalid daemon secret' } }));
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ success: true, data: { stored: true } }));
    });
  });
  await new Promise((resolve) => server.listen(PORT, resolve));

  const daemon = require(path.join(__dirname, 'kite-microstructure-daemon.js'));
  daemon._setConfigForTest({ wpSiteUrl: `http://localhost:${PORT}`, ingestSecret: SECRET, symbol: 'NIFTY' });

  // Capture console output from the real success/failure logging paths.
  const origLog = console.log, origErr = console.error;
  let logLines = [];
  console.log = (...a) => { logLines.push(a.join(' ')); };
  console.error = (...a) => { logLines.push(a.join(' ')); };

  daemon.postSnapshot();
  daemon._addRawTickForTest({ symbol: 'NIFTY', ltp: 23250, volume: 100, oi: 500, bid: 23249, ask: 23251, ts: Date.now() });
  daemon.flushRawTicks();

  await new Promise((r) => setTimeout(r, 400)); // let the real async HTTP round-trips complete
  console.log = origLog; console.error = origErr;

  check(received.length === 2, `real daemon code made exactly 2 real HTTP requests (got ${received.length})`);
  const snap = received.find((r) => r.action === 'fno_ingest_microstructure');
  const tick = received.find((r) => r.action === 'fno_ingest_raw_tick');
  check(!!snap, 'postSnapshot() hit path with action=fno_ingest_microstructure, matching the real registered action name');
  check(!!tick, 'flushRawTicks() hit path with action=fno_ingest_raw_tick, matching the real registered action name');
  check(snap && snap.path === '/wp-admin/admin-ajax.php', 'postSnapshot() posted to the real /wp-admin/admin-ajax.php path');
  check(tick && tick.path === '/wp-admin/admin-ajax.php', 'flushRawTicks() posted to the real /wp-admin/admin-ajax.php path');
  check(snap && snap.secretHeader === SECRET, 'postSnapshot() sent the correct X-Fno-Daemon-Secret header value');
  check(tick && tick.secretHeader === SECRET, 'flushRawTicks() sent the correct X-Fno-Daemon-Secret header value');
  check(snap && snap.method === 'POST', 'postSnapshot() used POST (matches admin-ajax.php requirement)');
  check(logLines.some((l) => l.includes('Snapshot posted OK')), 'real postSnapshot() success path logged "Snapshot posted OK" after a genuine 200 from the mock server');
  check(logLines.some((l) => l.includes('Raw tick batch posted OK')), 'real flushRawTicks() success path logged "Raw tick batch posted OK" after a genuine 200 from the mock server');

  // --- Step 3: prove wrong-secret is genuinely rejected (403), not
  // silently accepted - i.e. the mock enforces auth for real, and the
  // daemon's error-logging path genuinely fires on a real failure. ---
  received.length = 0;
  logLines = [];
  console.log = (...a) => { logLines.push(a.join(' ')); };
  console.error = (...a) => { logLines.push(a.join(' ')); };
  daemon._setConfigForTest({ ingestSecret: 'wrong-secret' });
  daemon.postSnapshot();
  await new Promise((r) => setTimeout(r, 400));
  console.log = origLog; console.error = origErr;
  check(logLines.some((l) => l.includes('Ingest failed (HTTP 403)')), 'daemon correctly logs an ingest failure when the secret is wrong (mock genuinely enforced 403, not a rubber-stamp)');

  // --- Step 4 (per-strike microstructure refactor, this pass): real
  // fidelity coverage for postInstrumentSnapshot/postAllInstrumentSnapshots
  // - the new per-OPTION-STRIKE posting path added alongside the existing
  // underlying-only postSnapshot above. Same discipline: drive the REAL
  // daemon code against the same real mock HTTP server, never a
  // hand-written fake POST, and verify computeMetricsForState genuinely
  // reads a non-ambient state object rather than the ambient default. ---
  daemon._setConfigForTest({ ingestSecret: SECRET });
  received.length = 0;
  logLines = [];
  console.log = (...a) => { logLines.push(a.join(' ')); };
  console.error = (...a) => { logLines.push(a.join(' ')); };

  // Real, independent per-token state: feed token 777 a real, distinct
  // value directly, then read it back only via computeMetricsForState -
  // proving that function genuinely reads the PASSED-IN state object,
  // not whatever the ambient default currently holds.
  const s777 = daemon.getOrCreateState(777);
  s777.cumulativeDelta = 4200;
  const metrics777 = daemon.computeMetricsForState(s777);
  check(metrics777.cumulativeDelta === 4200, 'computeMetricsForState(s) reads the REAL passed-in state object, not the ambient default (cumulativeDelta genuinely isolated)');

  daemon.postInstrumentSnapshot(777, 'NIFTY24AUG23200CE', 'NIFTY');
  await new Promise((r) => setTimeout(r, 400));
  console.log = origLog; console.error = origErr;

  const instr = received.find((r) => r.action === 'fno_ingest_microstructure_instrument');
  check(!!instr, 'postInstrumentSnapshot() hit path with action=fno_ingest_microstructure_instrument, matching the real registered action name');
  const instrReg = realRegistrations('fno_ingest_microstructure_instrument');
  check(instrReg.priv && instrReg.nopriv, 'fno-lab.php registers BOTH wp_ajax_fno_ingest_microstructure_instrument AND wp_ajax_nopriv_fno_ingest_microstructure_instrument (required - daemon is headless/session-less, same discipline as every other daemon endpoint)');
  check(instr && instr.path === '/wp-admin/admin-ajax.php', 'postInstrumentSnapshot() posted to the real /wp-admin/admin-ajax.php path');
  check(instr && instr.secretHeader === SECRET, 'postInstrumentSnapshot() sent the correct X-Fno-Daemon-Secret header value');
  check(instr && instr.body.includes('instrumentKey=NIFTY24AUG23200CE'), 'the real POST body carries the real instrumentKey field');
  check(instr && instr.body.includes('cumulativeDelta=4200'), 'the real POST body carries the real, genuinely-isolated cumulativeDelta=4200 from token 777\'s own state, not the underlying\'s');
  check(logLines.some((l) => l.includes('Instrument snapshot posted OK')), 'real postInstrumentSnapshot() success path logged "Instrument snapshot posted OK" after a genuine 200 from the mock server');

  // postAllInstrumentSnapshots: real fan-out over a real Map<token, {instrumentKey, symbol}>.
  received.length = 0;
  const optMap = new Map([
    [777, { instrumentKey: 'NIFTY24AUG23200CE', symbol: 'NIFTY' }],
    [778, { instrumentKey: 'NIFTY24AUG23200PE', symbol: 'NIFTY' }],
  ]);
  daemon.postAllInstrumentSnapshots(optMap);
  await new Promise((r) => setTimeout(r, 400));
  const instrCalls = received.filter((r) => r.action === 'fno_ingest_microstructure_instrument');
  check(instrCalls.length === 2, `postAllInstrumentSnapshots() made exactly one real HTTP request per tracked strike (got ${instrCalls.length})`);
  check(instrCalls.some((c) => c.body.includes('instrumentKey=NIFTY24AUG23200CE')) && instrCalls.some((c) => c.body.includes('instrumentKey=NIFTY24AUG23200PE')), 'both real, distinct strikes were posted independently, not collapsed into one');

  server.close();

  console.log(`\n${passed} passed, ${failed} failed\n`);
  process.exit(failed > 0 ? 1 : 0);
}

main().catch((e) => { console.error(e); process.exit(1); });
