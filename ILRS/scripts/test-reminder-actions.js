#!/usr/bin/env node
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const {
  completeOccurrence,
  snoozeReminder,
  postponeReminder,
  deleteReminder,
  bulkDeleteReminders,
  acknowledgeReminder,
  dismissAllRingingReminders,
  setReminderLifecycle,
  parseNotificationAction,
} = require('../reminder-actions');
const { parseLocalDateTime, ALARM_MAX_RINGS, shouldFireNow } = require('../alarm');

function test(name, fn) {
  try {
    fn();
    console.log(`✅ ${name}`);
  } catch (err) {
    console.error(`❌ ${name}: ${err.message}`);
    process.exitCode = 1;
  }
}

function makeDb() {
  const dbPath = path.join(os.tmpdir(), `ilrs-test-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY,
      title TEXT,
      repeat_type TEXT DEFAULT 'once',
      reminder_time TEXT,
      start_date TEXT,
      status TEXT DEFAULT 'active',
      next_fire TEXT,
      alarm_rings INTEGER DEFAULT 0, missed_count INTEGER DEFAULT 0,
      snooze_count INTEGER DEFAULT 0,
      snooze_duration INTEGER DEFAULT 10,
      last_completed TEXT,
      workflow_status TEXT DEFAULT 'pending',
      lifecycle_status TEXT DEFAULT '',
      task_type TEXT DEFAULT 'reminder',
      category TEXT DEFAULT 'general',
      source_type TEXT DEFAULT '',
      updated_at TEXT
    );
    CREATE TABLE reminder_logs (
      id TEXT PRIMARY KEY,
      reminder_id TEXT,
      action TEXT,
      timestamp TEXT
    );
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
  `);
  return { db, dbPath };
}

test('completeOccurrence marks once reminder completed', () => {
  const { db, dbPath } = makeDb();
  db.prepare(`INSERT INTO reminders (id, title, repeat_type, reminder_time, status, next_fire) VALUES (?, ?, 'once', '09:00', 'active', '2026-09-13T09:00:00')`)
    .run('r1', 'One-time');

  const result = completeOccurrence(db, 'r1', new Date(2026, 8, 13, 9, 0, 0));
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.recurring, false);

  const row = db.prepare('SELECT status FROM reminders WHERE id = ?').get('r1');
  assert.strictEqual(row.status, 'completed');
  db.close();
  fs.unlinkSync(dbPath);
});

test('completeOccurrence advances daily reminder without completing series', () => {
  const { db, dbPath } = makeDb();
  db.prepare(`INSERT INTO reminders (id, title, repeat_type, reminder_time, status, next_fire) VALUES (?, ?, 'daily', '08:00', 'active', '2026-09-13T08:00:00')`)
    .run('r2', 'Daily med');

  const now = new Date(2026, 8, 13, 8, 0, 0);
  const result = completeOccurrence(db, 'r2', now);
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.recurring, true);

  const row = db.prepare('SELECT status, next_fire FROM reminders WHERE id = ?').get('r2');
  assert.strictEqual(row.status, 'active');
  const next = parseLocalDateTime(row.next_fire);
  assert.strictEqual(next.getDate(), 14);
  assert.strictEqual(next.getHours(), 8);
  db.close();
  fs.unlinkSync(dbPath);
});

test('snoozeReminder allows unlimited snoozes', () => {
  const { db, dbPath } = makeDb();
  db.prepare('INSERT INTO settings (key, value) VALUES (?, ?)').run('snooze_limit', '2');
  db.prepare(`INSERT INTO reminders (id, title, snooze_count, status, next_fire) VALUES (?, ?, 5, 'active', '2026-09-13T09:00:00')`)
    .run('r3', 'Unlimited');

  const result = snoozeReminder(db, 'r3', 10, new Date(2026, 8, 13, 9, 0, 0));
  assert.strictEqual(result.success, true);
  const row = db.prepare('SELECT snooze_count FROM reminders WHERE id = ?').get('r3');
  assert.strictEqual(row.snooze_count, 6);
  db.close();
  fs.unlinkSync(dbPath);
});

test('postponeReminder reactivates completed reminder', () => {
  const { db, dbPath } = makeDb();
  db.prepare(`INSERT INTO reminders (id, title, task_type, status, workflow_status, reminder_time, start_date, next_fire)
    VALUES (?, ?, 'reminder', 'completed', 'done', '09:00', '2026-09-10', '2026-09-10T09:00:00')`)
    .run('r4b', 'Done reminder');

  const result = postponeReminder(db, 'r4b', '2026-09-20', '14:00', new Date(2026, 8, 13, 10, 0, 0));
  assert.strictEqual(result.success, true);
  const row = db.prepare('SELECT status, workflow_status, last_completed FROM reminders WHERE id = ?').get('r4b');
  assert.strictEqual(row.status, 'active');
  assert.strictEqual(row.workflow_status, 'pending');
  assert.strictEqual(row.last_completed, null);
  db.close();
  fs.unlinkSync(dbPath);
});

test('postponeReminder reactivates completed task', () => {
  const { db, dbPath } = makeDb();
  db.prepare(`INSERT INTO reminders (id, title, task_type, status, workflow_status, reminder_time, start_date, next_fire)
    VALUES (?, ?, 'task', 'completed', 'done', '09:00', '2026-09-10', '2026-09-10T09:00:00')`)
    .run('r4', 'Done task');

  const result = postponeReminder(db, 'r4', '2026-09-20', '14:00', new Date(2026, 8, 13, 10, 0, 0));
  assert.strictEqual(result.success, true);
  const row = db.prepare('SELECT status, workflow_status, next_fire FROM reminders WHERE id = ?').get('r4');
  assert.strictEqual(row.status, 'active');
  assert.strictEqual(row.workflow_status, 'pending');
  assert.ok(row.next_fire);
  db.close();
  fs.unlinkSync(dbPath);
});

test('deleteReminder soft-deletes item', () => {
  const { db, dbPath } = makeDb();
  db.prepare(`INSERT INTO reminders (id, title, status) VALUES (?, ?, 'active')`).run('r5', 'Remove me');
  const result = deleteReminder(db, 'r5');
  assert.strictEqual(result.success, true);
  const row = db.prepare('SELECT status FROM reminders WHERE id = ?').get('r5');
  assert.strictEqual(row.status, 'deleted');
  db.close();
  fs.unlinkSync(dbPath);
});

test('bulkDeleteReminders deletes multiple items', () => {
  const { db, dbPath } = makeDb();
  db.prepare(`INSERT INTO reminders (id, title, status) VALUES ('a', 'A', 'active'), ('b', 'B', 'active')`).run();
  const result = bulkDeleteReminders(db, ['a', 'b']);
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.deleted, 2);
  db.close();
  fs.unlinkSync(dbPath);
});

test('completeOccurrence completes tasks without recurring advance', () => {
  const { db, dbPath } = makeDb();
  db.prepare(`INSERT INTO reminders (id, title, task_type, repeat_type, status, workflow_status) VALUES (?, ?, 'task', 'once', 'active', 'in_progress')`)
    .run('t1', 'Finish report');

  const result = completeOccurrence(db, 't1', new Date(2026, 8, 13, 10, 0, 0));
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.task, true);

  const row = db.prepare('SELECT status, workflow_status FROM reminders WHERE id = ?').get('t1');
  assert.strictEqual(row.status, 'completed');
  assert.strictEqual(row.workflow_status, 'done');
  db.close();
  fs.unlinkSync(dbPath);
});

test('setReminderLifecycle closed clears next_fire', () => {
  const { db, dbPath } = makeDb();
  db.prepare(`INSERT INTO reminders (id, title, status, workflow_status, next_fire) VALUES (?, ?, 'active', 'pending', '2026-09-20T09:00:00')`)
    .run('lc1', 'Follow up client');
  const result = setReminderLifecycle(db, 'lc1', 'closed');
  assert.strictEqual(result.success, true);
  const row = db.prepare('SELECT lifecycle_status, status, next_fire FROM reminders WHERE id = ?').get('lc1');
  assert.strictEqual(row.lifecycle_status, 'closed');
  assert.strictEqual(row.next_fire, '');
  db.close();
  fs.unlinkSync(dbPath);
});

test('parseNotificationAction maps toast button labels', () => {
  assert.strictEqual(parseNotificationAction('Done'), 'done');
  assert.strictEqual(parseNotificationAction('Snooze'), 'snooze');
  assert.strictEqual(parseNotificationAction('Snooze 10m'), 'snooze');
  assert.strictEqual(parseNotificationAction('dismissed'), 'dismiss');
  assert.strictEqual(parseNotificationAction('Dismiss'), 'dismiss');
});

test('acknowledgeReminder suppresses repeat alerts', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 15, 22, 0, 0);
  db.prepare(`
    INSERT INTO reminders (id, title, task_type, category, repeat_type, reminder_time, start_date,
      status, workflow_status, next_fire, alarm_rings, missed_count, updated_at)
    VALUES ('r-ack', 'Overdue task', 'reminder', 'work', 'once', '09:00', '2026-09-15',
      'active', 'pending', '2026-09-15T09:00:00', 3, 0, datetime('now'))
  `).run();

  const result = acknowledgeReminder(db, 'r-ack', now);
  assert.strictEqual(result.success, true);

  const row = db.prepare('SELECT alarm_rings, missed_count FROM reminders WHERE id = ?').get('r-ack');
  assert.strictEqual(row.alarm_rings, ALARM_MAX_RINGS);
  assert.strictEqual(row.missed_count, 1);
  assert.strictEqual(shouldFireNow({
    ...row,
    id: 'r-ack',
    reminder_time: '09:00',
    repeat_type: 'once',
    start_date: '2026-09-15',
    next_fire: '2026-09-15T09:00:00',
    last_fired: '2026-09-15T21:58:00',
  }, now), false);

  db.close();
  fs.unlinkSync(dbPath);
});

test('dismissAllRingingReminders acknowledges overdue active reminders', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 15, 22, 0, 0);
  db.prepare(`
    INSERT INTO reminders (id, title, task_type, category, repeat_type, reminder_time, start_date,
      status, workflow_status, next_fire, alarm_rings, missed_count, updated_at)
    VALUES ('r1', 'A', 'reminder', 'work', 'once', '09:00', '2026-09-15',
      'active', 'pending', '2026-09-15T09:00:00', 2, 0, datetime('now')),
      ('r2', 'B', 'reminder', 'work', 'once', '10:00', '2026-09-16',
      'active', 'pending', '2026-09-16T10:00:00', 0, 0, datetime('now'))
  `).run();

  const result = dismissAllRingingReminders(db, now);
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.count, 1);

  const r1 = db.prepare('SELECT alarm_rings FROM reminders WHERE id = ?').get('r1');
  const r2 = db.prepare('SELECT alarm_rings FROM reminders WHERE id = ?').get('r2');
  assert.strictEqual(r1.alarm_rings, ALARM_MAX_RINGS);
  assert.strictEqual(r2.alarm_rings, 0);
  db.close();
  fs.unlinkSync(dbPath);
});

console.log('\nReminder action tests finished.');
