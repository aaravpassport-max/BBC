#!/usr/bin/env node
const assert = require('assert');
const {
  toLocalISO,
  isDue,
  computeNextFire,
  planAfterFire,
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

test('parseLocalDateTime uses local clock', () => {
  const d = parseLocalDateTime('2026-09-03T15:30:00');
  assert.strictEqual(d.getHours(), 15);
  assert.strictEqual(d.getMinutes(), 30);
});

test('isDue compares local fire time', () => {
  const now = new Date(2026, 8, 3, 15, 31, 0);
  assert.strictEqual(isDue('2026-09-03T15:30:00', now), true);
  assert.strictEqual(isDue('2026-09-03T15:32:00', now), false);
});

test('computeNextFire schedules past once reminder soon', () => {
  const now = new Date(2026, 8, 3, 16, 0, 0);
  const next = computeNextFire('2026-09-03', '09:00', 'once', now);
  const parsed = parseLocalDateTime(next);
  assert.ok(parsed.getTime() > now.getTime());
  assert.ok(parsed.getTime() - now.getTime() <= 60000);
});

test('planAfterFire repeats once reminders before completing', () => {
  const now = new Date(2026, 8, 3, 9, 0, 0);
  const reminder = { id: '1', repeat_type: 'once', next_fire: '2026-09-03T09:00:00', alarm_rings: 0 };
  const plan = planAfterFire(reminder, now);
  assert.strictEqual(plan.status, 'active');
  assert.ok(plan.alarmRings === 1);
  assert.ok(isDue(plan.nextFire, new Date(now.getTime() + 3 * 60 * 1000)));
});

test('planAfterFire advances daily reminders', () => {
  const now = new Date(2026, 8, 3, 8, 0, 0);
  const reminder = { repeat_type: 'daily', reminder_time: '08:00', alarm_rings: 0 };
  const plan = planAfterFire(reminder, now);
  assert.strictEqual(plan.alarmRings, 0);
  assert.ok(plan.nextFire.includes('T08:00:00'));
});

console.log('\nAlarm tests finished.');
