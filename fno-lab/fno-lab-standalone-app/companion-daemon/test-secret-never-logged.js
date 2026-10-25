'use strict';
/**
 * Regression test: the real Daemon Ingest Secret (config.ingestSecret)
 * and the real Kite credentials (kiteApiKey/kiteAccessToken) must
 * never appear on any console.log/error/warn line in
 * kite-microstructure-daemon.js. A future "debug" console.log(config)
 * would silently leak real secrets to stdout/log files.
 *
 * Static source scan of the actual daemon source.
 */
const fs = require('fs');
const path = require('path');

const SRC_PATH = path.join(__dirname, 'kite-microstructure-daemon.js');
const src = fs.readFileSync(SRC_PATH, 'utf8');

let failures = 0;
function assert(cond, msg) {
  if (!cond) { console.error('FAIL: ' + msg); failures++; }
  else { console.log('PASS: ' + msg); }
}

assert(/ingestSecret/.test(src), 'sanity: ingestSecret field is present in kite-microstructure-daemon.js');

const secretFields = ['ingestSecret', 'kiteApiKey', 'kiteAccessToken'];
const lines = src.split('\n');
const offending = [];
lines.forEach((line, i) => {
  if (/console\.(log|error|warn|info|debug)\s*\(/.test(line)) {
    secretFields.forEach((field) => {
      if (new RegExp(field, 'i').test(line)) {
        offending.push(`line ${i + 1} (${field}): ${line.trim()}`);
      }
    });
  }
});

assert(
  offending.length === 0,
  'no console.* call site in kite-microstructure-daemon.js references a real secret/credential field' +
    (offending.length ? '\n  Offending line(s):\n  ' + offending.join('\n  ') : '')
);

const headerUses = (src.match(/X-Fno-Daemon-Secret['"]\s*:\s*config\.ingestSecret/g) || []).length;
assert(headerUses >= 1, 'config.ingestSecret is used as the X-Fno-Daemon-Secret header value at least once (sanity - confirms real usage path)');

// Broader secret-leakage sweep finding (fixed this session): a
// console.error(label, e) call that logs the RAW error/catch-variable
// object (not just e.message) is a real risk here, since main()
// constructs KiteTicker directly with config.kiteApiKey/
// config.kiteAccessToken - if a thrown error object from that or any
// third-party call ever carried those as enumerable properties, a
// wholesale object dump would print them. Assert no call site logs a
// bare catch variable as a second/trailing console.* argument.
const rawErrorObjectDumps = [];
lines.forEach((line, i) => {
  if (line.trim().startsWith('//')) return; // skip comments (avoid false positives on lines describing the pattern)
  if (/console\.(log|error|warn|info|debug)\s*\([^)]*,\s*e\s*\)/.test(line)) {
    rawErrorObjectDumps.push(`line ${i + 1}: ${line.trim()}`);
  }
});
assert(
  rawErrorObjectDumps.length === 0,
  'no console.* call site logs a raw caught-error object (only .message/.stack strings are logged, never the object itself)' +
    (rawErrorObjectDumps.length ? '\n  Offending line(s):\n  ' + rawErrorObjectDumps.join('\n  ') : '')
);

if (failures > 0) {
  console.error(`\n${failures} failure(s).`);
  process.exit(1);
} else {
  console.log('\nAll secret-logging regression checks passed.');
}
