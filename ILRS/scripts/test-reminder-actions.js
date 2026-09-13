#!/usr/bin/env node
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const {
  completeOccurrence,
  snoozeReminder,
  parseNotificationAction,
} = require('../reminder-actions');
const { parseLocalDateTime } = require('../alarm');

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
      alarm_rings INTEGER DEFAULT 0,
      snooze_count INTEGER DEFAULT 0,
      snooze_duration INTEGER DEFAULT 10,
      last_completed TEXT,
      workflow_status TEXT DEFAULT 'pending',
      task_type TEXT DEFAULT 'reminder',
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

test('snoozeReminder respects snooze limit', () => {
  const { db, dbPath } = makeDb();
  db.prepare('INSERT INTO settings (key, value) VALUES (?, ?)').run('snooze_limit', '2');
  db.prepare(`INSERT INTO reminders (id, title, snooze_count, status, next_fire) VALUES (?, ?, 2, 'active', '2026-09-13T09:00:00')`)
    .run('r3', 'Limited');

  const result = snoozeReminder(db, 'r3', 10);
  assert.strictEqual(result.success, false);
  assert.strictEqual(result.error, 'snooze_limit');
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

test('parseNotificationAction maps toast button labels', () => {
  assert.strictEqual(parseNotificationAction('Done'), 'done');
  assert.strictEqual(parseNotificationAction('Snooze'), 'snooze');
  assert.strictEqual(parseNotificationAction('Snooze 10m'), 'snooze');
  assert.strictEqual(parseNotificationAction('dismissed'), null);
});

console.log('\nReminder action tests finished.');
