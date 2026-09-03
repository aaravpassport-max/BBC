#!/usr/bin/env node
/**
 * Regression test: reminders must fire at local wall-clock time, not UTC-offset late.
 */
const assert = require('assert');
const {
  toLocalISO,
  isDue,
  computeNextFire,
  normalizeNextFire,
  localDateStr,
  parseLocalDateTime,
} = require('../alarm');

function test(name, fn) {
  try {
    fn();
    console.log(`✅ ${name}`);
  } catch (err) {
    console.error(`❌ ${name}: ${err.message}`);
    process.exitCode = 1;
  }
}

test('localDateStr uses system local calendar date', () => {
  const d = new Date(2026, 8, 4, 1, 0, 0);
  assert.strictEqual(localDateStr(d), '2026-09-04');
  // UTC date string can lag behind local date near midnight in positive-offset zones
  const utcDate = d.toISOString().split('T')[0];
  assert.ok(utcDate <= localDateStr(d));
});

test('UTC ISO string compare delays alarm for UTC+5:30 style offset', () => {
  const nextFireLocal = '2026-09-03T10:00:00';
  // 10:00 AM in India (UTC+5:30) = 04:30 UTC
  const utcIsoAtTenAmIst = '2026-09-03T04:30:00.000Z';
  const oldBugWouldFire = nextFireLocal <= utcIsoAtTenAmIst;
  assert.strictEqual(oldBugWouldFire, false, 'UTC compare blocks fire at correct local time');
  // When wall-clock parser runs in user timezone, 10:00 local is due at that wall time
  const tenAmLocal = new Date(2026, 8, 3, 10, 0, 0);
  assert.strictEqual(isDue(nextFireLocal, tenAmLocal), true);
});

test('normalizeNextFire converts UTC Z suffix to local wall time', () => {
  const d = new Date(2026, 8, 3, 10, 0, 0);
  const utcStored = d.toISOString();
  const normalized = normalizeNextFire(utcStored);
  assert.ok(normalized.includes('T10:00:00') || normalized.includes('T10:00:'), normalized);
});

test('computeNextFire at 10:00 local fires at 10:00 not 15:30', () => {
  const nineAm = new Date(2026, 8, 3, 9, 0, 0);
  const next = computeNextFire('2026-09-03', '10:00', 'once', nineAm);
  const parsed = parseLocalDateTime(next);
  assert.strictEqual(parsed.getHours(), 10);
  assert.strictEqual(parsed.getMinutes(), 0);
  assert.strictEqual(isDue(next, new Date(2026, 8, 3, 10, 0, 0)), true);
  assert.strictEqual(isDue(next, new Date(2026, 8, 3, 9, 59, 0)), false);
});

test('reminder due exactly at scheduled minute', () => {
  const fire = '2026-09-03T14:30:00';
  assert.strictEqual(isDue(fire, new Date(2026, 8, 3, 14, 30, 0)), true);
  assert.strictEqual(isDue(fire, new Date(2026, 8, 3, 14, 29, 59)), false);
});

console.log('\nTimezone regression tests finished.');
