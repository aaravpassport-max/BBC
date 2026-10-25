#!/usr/bin/env node
/**
 * Phase 3: Life modules sync (medicine, bills, habits, family).
 */
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const Database = require('better-sqlite3');
const { randomUUID } = require('crypto');

const {
  runSyncMigrationV20,
  runSyncMigrationV21,
  runSyncMigrationV22,
  runSyncMigrationV23,
} = require('../sync-migration');
const { configureSyncFolder, runSyncCycle } = require('../sync-engine');
const { markMedicineDoseTaken, markBillPaidAction, logHabitAction } = require('../module-actions');
const { maybeSyncEntity } = require('../sync-hook');

function makeLifeDb(label) {
  const dbPath = path.join(os.tmpdir(), `ilrs-sync-p3-${label}-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    INSERT INTO settings (key, value) VALUES ('schema_version', '19');
    INSERT INTO settings (key, value) VALUES ('user_name', 'Life ${label}');

    CREATE TABLE medicines (
      id TEXT PRIMARY KEY, name TEXT, condition TEXT, doses_per_day INTEGER, dose_times TEXT,
      food_timing TEXT, start_date TEXT, end_date TEXT, alert_style TEXT, escalate INTEGER,
      family_notify TEXT, track_doses INTEGER, notes TEXT, status TEXT, created_at TEXT
    );
    CREATE TABLE medicine_logs (
      id TEXT PRIMARY KEY, medicine_id TEXT, dose_time TEXT, scheduled_time TEXT, status TEXT,
      taken_at TEXT, note TEXT, log_date TEXT
    );
    CREATE TABLE bills (
      id TEXT PRIMARY KEY, name TEXT, bill_type TEXT, amount REAL, due_day INTEGER,
      warning_days INTEGER, auto_monthly INTEGER, payment_status TEXT, account_info TEXT,
      notes TEXT, status TEXT, created_at TEXT
    );
    CREATE TABLE bill_history (
      id TEXT PRIMARY KEY, bill_id TEXT, paid_date TEXT, amount REAL, note TEXT
    );
    CREATE TABLE habits (
      id TEXT PRIMARY KEY, reminder_id TEXT, name TEXT, frequency TEXT, target_time TEXT,
      streak INTEGER, best_streak INTEGER, completion_rate REAL, last_completed TEXT, status TEXT, created_at TEXT
    );
    CREATE TABLE habit_logs (
      id TEXT PRIMARY KEY, habit_id TEXT, log_date TEXT, completed INTEGER, note TEXT
    );
    CREATE TABLE family_members (
      id TEXT PRIMARY KEY, name TEXT, role TEXT, email TEXT, phone TEXT,
      is_emergency_contact INTEGER, created_at TEXT
    );
    CREATE TABLE reminders (id TEXT PRIMARY KEY, title TEXT, status TEXT);
  `);
  runSyncMigrationV20(db);
  runSyncMigrationV21(db);
  runSyncMigrationV22(db);
  runSyncMigrationV23(db);
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '23')").run();
  return { db, dbPath };
}

function enableSync(db, folderRoot) {
  configureSyncFolder(db, folderRoot);
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('sync_enabled', '1')").run();
}

const syncRoot = path.join(os.tmpdir(), `ilrs-p3-shared-${Date.now()}`);
const dbA = makeLifeDb('A');
const dbB = makeLifeDb('B');

try {
  enableSync(dbA.db, syncRoot);
  enableSync(dbB.db, syncRoot);

  const medId = randomUUID();
  dbA.db.prepare(`
    INSERT INTO medicines (id, name, status, doses_per_day, dose_times, created_at)
    VALUES (?, 'Metformin', 'active', 1, '["08:00"]', datetime('now'))
  `).run(medId);
  maybeSyncEntity(dbA.db, 'medicine', medId);
  markMedicineDoseTaken(dbA.db, medId, '08:00');

  const billId = randomUUID();
  dbA.db.prepare(`
    INSERT INTO bills (id, name, amount, payment_status, status, created_at)
    VALUES (?, 'Electricity', 1200, 'pending', 'active', datetime('now'))
  `).run(billId);
  maybeSyncEntity(dbA.db, 'bill', billId);
  markBillPaidAction(dbA.db, billId);

  const habitId = randomUUID();
  dbA.db.prepare(`
    INSERT INTO habits (id, name, frequency, target_time, streak, best_streak, completion_rate, status, created_at)
    VALUES (?, 'Morning walk', 'daily', '07:00', 0, 0, 0, 'active', datetime('now'))
  `).run(habitId);
  maybeSyncEntity(dbA.db, 'habit', habitId);
  logHabitAction(dbA.db, habitId);

  const famId = randomUUID();
  dbA.db.prepare(`
    INSERT INTO family_members (id, name, role, phone, email, is_emergency_contact, created_at)
    VALUES (?, 'Priya Sharma', 'spouse', '9999900011', 'priya@example.com', 1, datetime('now'))
  `).run(famId);
  maybeSyncEntity(dbA.db, 'family_member', famId);

  runSyncCycle(dbA.db, '1.2.3-test', { force: true });
  runSyncCycle(dbB.db, '1.2.3-test', { force: true });

  assert.ok(dbB.db.prepare('SELECT * FROM medicines WHERE id = ?').get(medId), 'medicine on B');
  assert.ok(dbB.db.prepare('SELECT * FROM medicine_logs WHERE medicine_id = ?').get(medId), 'medicine log on B');
  assert.strictEqual(dbB.db.prepare('SELECT payment_status FROM bills WHERE id = ?').get(billId).payment_status, 'paid');
  assert.ok(dbB.db.prepare('SELECT * FROM bill_history WHERE bill_id = ?').get(billId), 'bill history on B');
  assert.ok(dbB.db.prepare('SELECT * FROM habit_logs WHERE habit_id = ? AND completed = 1').get(habitId), 'habit log on B');
  assert.ok(dbB.db.prepare('SELECT * FROM habits WHERE id = ?').get(habitId).streak >= 1, 'habit streak on B');
  assert.ok(dbB.db.prepare('SELECT * FROM family_members WHERE id = ?').get(famId), 'family on B');

  console.log('✅ test-sync-phase3 passed');
} finally {
  dbA.db.close();
  dbB.db.close();
  try { fs.unlinkSync(dbA.dbPath); } catch (_) { /* ignore */ }
  try { fs.unlinkSync(dbB.dbPath); } catch (_) { /* ignore */ }
  try { fs.rmSync(syncRoot, { recursive: true, force: true }); } catch (_) { /* ignore */ }
}
