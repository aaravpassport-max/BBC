#!/usr/bin/env node
const assert = require('assert');
const Database = require('better-sqlite3');
const {
  normalizeLifecycle,
  requiresNextReminderDate,
  blocksNextReminder,
  reminderRowPatchForLifecycle,
  inquiryRowPatchForLifecycle,
  isReminderSchedulableByLifecycle,
  shouldMaintainInquiryFollowUp,
  inferLifecycleFromInquiry,
  backfillReminderLifecycle,
  LIFECYCLE_CLOSED,
  LIFECYCLE_COMPLETED,
} = require('../work-lifecycle');
const { shouldFireNow } = require('../alarm');

function test(name, fn) {
  try {
    fn();
    console.log(`  ✓ ${name}`);
  } catch (err) {
    console.error(`  ✗ ${name}`);
    throw err;
  }
}

console.log('work-lifecycle');

test('normalizeLifecycle defaults to active', () => {
  assert.strictEqual(normalizeLifecycle(''), 'active');
  assert.strictEqual(normalizeLifecycle('pending'), 'pending');
});

test('requiresNextReminderDate is status-dependent', () => {
  assert.strictEqual(requiresNextReminderDate('active'), true);
  assert.strictEqual(requiresNextReminderDate('pending'), false);
  assert.strictEqual(requiresNextReminderDate('completed'), false);
  assert.strictEqual(requiresNextReminderDate('completed', { scheduleNext: true }), true);
  assert.strictEqual(blocksNextReminder('closed'), true);
});

test('completed reminder without next_fire is not schedulable', () => {
  const row = { lifecycle_status: 'completed', status: 'completed', next_fire: '' };
  assert.strictEqual(isReminderSchedulableByLifecycle(row), false);
  assert.strictEqual(shouldFireNow(row, new Date('2026-09-17T10:00:00')), false);
});

test('inquiry closed does not maintain follow-up', () => {
  const inq = { outcome_status: 'closed_lost', lifecycle_status: 'closed', next_follow_up: '2026-09-20' };
  assert.strictEqual(shouldMaintainInquiryFollowUp(inq), false);
  assert.strictEqual(inferLifecycleFromInquiry(inq), LIFECYCLE_CLOSED);
});

test('migration backfill sets lifecycle on reminders', () => {
  const db = new Database(':memory:');
  db.exec(`
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY,
      status TEXT,
      workflow_status TEXT,
      lifecycle_status TEXT DEFAULT ''
    );
  `);
  db.prepare(`INSERT INTO reminders (id, status, workflow_status) VALUES ('a', 'completed', 'done')`).run();
  const n = backfillReminderLifecycle(db);
  assert.strictEqual(n, 1);
  const row = db.prepare('SELECT lifecycle_status FROM reminders WHERE id = ?').get('a');
  assert.strictEqual(row.lifecycle_status, LIFECYCLE_COMPLETED);
  db.close();
});

test('reminderRowPatchForLifecycle clears schedule when closed', () => {
  const patch = reminderRowPatchForLifecycle(LIFECYCLE_CLOSED);
  assert.strictEqual(patch.clear_schedule, true);
  assert.strictEqual(patch.next_fire, '');
});

test('inquiryRowPatchForLifecycle clears follow-up when completed without schedule', () => {
  const patch = inquiryRowPatchForLifecycle(LIFECYCLE_COMPLETED, { scheduleNext: false });
  assert.strictEqual(patch.clear_follow_up, true);
});

console.log('All work-lifecycle tests passed.');
