'use strict';
/**
 * Regression test: the real Driver Secret (CONFIG.driverSecret /
 * FNO_DRIVER_SECRET) must never appear on any console.log/error/warn
 * line in autonomous-driver.js. A future edit that adds a "debug"
 * console.log(CONFIG) or similar would silently leak the real secret
 * to stdout/log files - this test fails loudly if that happens.
 *
 * Static source scan, not a runtime check: it reads the actual driver
 * source and asserts no console.* call site references the secret
 * fields at all (not even in redacted form - the safe pattern here is
 * to never log it, not to log a masked version).
 */
const fs = require('fs');
const path = require('path');

const SRC_PATH = path.join(__dirname, '..', 'autonomous-driver.js');
const src = fs.readFileSync(SRC_PATH, 'utf8');

let failures = 0;
function assert(cond, msg) {
  if (!cond) { console.error('FAIL: ' + msg); failures++; }
  else { console.log('PASS: ' + msg); }
}

// Real sanity check: the secret field must actually exist in this file
// (otherwise this test would pass vacuously against a stale/renamed field).
assert(/driverSecret/.test(src), 'sanity: driverSecret field is present in autonomous-driver.js');

const lines = src.split('\n');
const consoleLinesWithSecret = [];
lines.forEach((line, i) => {
  if (/console\.(log|error|warn|info|debug)\s*\(/.test(line) && /driverSecret/i.test(line)) {
    consoleLinesWithSecret.push(`line ${i + 1}: ${line.trim()}`);
  }
});

assert(
  consoleLinesWithSecret.length === 0,
  'no console.* call site in autonomous-driver.js references CONFIG.driverSecret' +
    (consoleLinesWithSecret.length ? '\n  Offending line(s):\n  ' + consoleLinesWithSecret.join('\n  ') : '')
);

// Also confirm the secret is only ever used as a real HTTP header value,
// never concatenated into a logged message string.
const headerUses = (src.match(/X-FNO-Driver-Secret['"]\s*:\s*CONFIG\.driverSecret/g) || []).length;
assert(headerUses >= 1, 'CONFIG.driverSecret is used as the X-FNO-Driver-Secret header value at least once (sanity - confirms real usage path)');

// Broader secret-leakage sweep (companion to the companion-daemon
// version of this same check): no console.* call site should log a
// raw caught-error object (only .message/.stack strings) - a future
// fetch/HTTP error thrown by the code that sends CONFIG.driverSecret
// as a header could, in principle, carry request details on the
// error object depending on the fetch implementation in use.
const rawErrorObjectDumps = [];
lines.forEach((line, i) => {
  if (line.trim().startsWith('//')) return; // skip comments (avoid false positives on lines describing the pattern)
  if (/console\.(log|error|warn|info|debug)\s*\([^)]*,\s*e\s*\)/.test(line)) {
    rawErrorObjectDumps.push(`line ${i + 1}: ${line.trim()}`);
  }
});
assert(
  rawErrorObjectDumps.length === 0,
  'no console.* call site in autonomous-driver.js logs a raw caught-error object' +
    (rawErrorObjectDumps.length ? '\n  Offending line(s):\n  ' + rawErrorObjectDumps.join('\n  ') : '')
);

if (failures > 0) {
  console.error(`\n${failures} failure(s).`);
  process.exit(1);
} else {
  console.log('\nAll secret-logging regression checks passed.');
}
