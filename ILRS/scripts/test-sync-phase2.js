#!/usr/bin/env node
/**
 * Phase 2: reminders/tasks + follow-up reminders sync between two DBs.
 */
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const Database = require('better-sqlite3');
const { randomUUID } = require('crypto');

const { runSyncMigrationV20, runSyncMigrationV21, runSyncMigrationV22 } = require('../sync-migration');
const { configureSyncFolder, runSyncCycle } = require('../sync-engine');
const { createInquiry, seedInquiryStages } = require('../inquiry-actions');
const { completeOccurrence } = require('../reminder-actions');
const { maybeSyncEntity } = require('../sync-hook');

function makeWorkDb(label) {
  const dbPath = path.join(os.tmpdir(), `ilrs-sync-p2-${label}-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    INSERT INTO settings (key, value) VALUES ('schema_version', '19');
    INSERT INTO settings (key, value) VALUES ('user_name', 'Operator ${label}');

    CREATE TABLE clients (
      id TEXT PRIMARY KEY, name TEXT, company TEXT, mobile TEXT, email TEXT, notes TEXT, created_at TEXT
    );
    CREATE TABLE inquiry_stages (
      key TEXT PRIMARY KEY, display_name TEXT, category TEXT, sort_order INTEGER, is_closed INTEGER,
      color TEXT, fields_json TEXT, automation_json TEXT
    );
    CREATE TABLE inquiries (
      id TEXT PRIMARY KEY, inquiry_number TEXT UNIQUE, client_id TEXT, client_name TEXT, company TEXT,
      mobile TEXT, email TEXT, requirement TEXT, service_category TEXT, source TEXT, stage_key TEXT,
      priority TEXT, assigned_to TEXT, next_action TEXT, next_follow_up TEXT, next_follow_up_time TEXT,
      expected_value REAL, quotation_amount REAL, payment_status TEXT, outcome_status TEXT, closed_reason TEXT,
      health TEXT, stage_changed_at TEXT, last_activity_at TEXT, notes TEXT, internal_notes TEXT, tags TEXT,
      created_at TEXT, updated_at TEXT, lifecycle_status TEXT, work_start_date TEXT, expected_completion_date TEXT,
      work_phase TEXT, last_activity_summary TEXT, operational_state TEXT,
      payment_tracking_enabled INTEGER, payment_total REAL, next_payment_due_date TEXT, next_payment_amount REAL,
      payment_track_status TEXT
    );
    CREATE TABLE inquiry_activities (
      id TEXT PRIMARY KEY, inquiry_id TEXT, activity_type TEXT, title TEXT, body TEXT,
      old_stage_key TEXT, new_stage_key TEXT, metadata TEXT, created_at TEXT
    );
    CREATE TABLE work_payments (
      id TEXT PRIMARY KEY, entity_type TEXT, entity_id TEXT, amount REAL, received_date TEXT, note TEXT, created_at TEXT
    );
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY, title TEXT, task_type TEXT, category TEXT, why_it_matters TEXT, repeat_type TEXT,
      repeat_value TEXT, time_mode TEXT, reminder_time TEXT, reminder_times TEXT, start_date TEXT, end_date TEXT,
      priority TEXT, urgency_quadrant TEXT, alert_style TEXT, snooze_duration INTEGER, assigned_to TEXT,
      is_private INTEGER, notes TEXT, tags TEXT, depends_on TEXT, status TEXT, snooze_count INTEGER,
      completion_count INTEGER, missed_count INTEGER, streak INTEGER, best_streak INTEGER, last_completed TEXT,
      last_fired TEXT, next_fire TEXT, created_at TEXT, updated_at TEXT, workflow_status TEXT, source_type TEXT,
      source_id TEXT, lifecycle_status TEXT, stage_key TEXT, stage_changed_at TEXT, stage_reminder_disabled INTEGER,
      work_start_date TEXT, expected_completion_date TEXT, alarm_rings INTEGER,
      payment_tracking_enabled INTEGER, payment_total REAL, next_payment_due_date TEXT, next_payment_amount REAL,
      payment_track_status TEXT
    );
    CREATE TABLE reminder_logs (
      id TEXT PRIMARY KEY, reminder_id TEXT, action TEXT, timestamp TEXT, note TEXT
    );
  `);
  runSyncMigrationV20(db);
  runSyncMigrationV21(db);
  runSyncMigrationV22(db);
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '22')").run();
  seedInquiryStages(db);
  return { db, dbPath };
}

function enableSync(db, folderRoot) {
  configureSyncFolder(db, folderRoot);
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('sync_enabled', '1')").run();
}

const syncRoot = path.join(os.tmpdir(), `ilrs-p2-shared-${Date.now()}`);
const dbA = makeWorkDb('A');
const dbB = makeWorkDb('B');

try {
  enableSync(dbA.db, syncRoot);
  enableSync(dbB.db, syncRoot);

  const created = createInquiry(dbA.db, {
    clientName: 'Raj Kumar',
    mobile: '9999900002',
    requirement: 'University Transcript',
    nextFollowUp: '2026-10-01',
    nextFollowUpTime: '10:00',
  });
  assert.ok(created.success);

  const taskId = randomUUID();
  dbA.db.prepare(`
    INSERT INTO reminders (
      id, title, task_type, category, repeat_type, reminder_time, start_date, priority, alert_style,
      status, workflow_status, lifecycle_status, next_fire, created_at, updated_at
    ) VALUES (?, 'Collect documents', 'task', 'work', 'once', '11:00', '2026-10-02', 'normal', 'sound-popup',
      'active', 'pending', 'active', '2026-10-02T11:00:00', datetime('now'), datetime('now'))
  `).run(taskId);
  maybeSyncEntity(dbA.db, 'reminder', taskId);

  runSyncCycle(dbA.db, '1.2.2-test', { force: true });
  runSyncCycle(dbB.db, '1.2.2-test', { force: true });

  const followUp = dbB.db.prepare(`
    SELECT * FROM reminders WHERE source_type = 'inquiry' AND status != 'deleted'
  `).get();
  assert.ok(followUp, 'follow-up reminder on B');

  const taskB = dbB.db.prepare('SELECT * FROM reminders WHERE id = ?').get(taskId);
  assert.ok(taskB, 'standalone task on B');
  assert.strictEqual(taskB.title, 'Collect documents');

  completeOccurrence(dbA.db, taskId);
  runSyncCycle(dbA.db, '1.2.2-test', { force: true });
  runSyncCycle(dbB.db, '1.2.2-test', { force: true });

  const taskDone = dbB.db.prepare('SELECT * FROM reminders WHERE id = ?').get(taskId);
  assert.strictEqual(taskDone.status, 'completed');
  assert.ok(
    dbB.db.prepare('SELECT * FROM reminder_logs WHERE reminder_id = ? AND action = ?').get(taskId, 'completed'),
    'completion log on B',
  );

  console.log('✅ test-sync-phase2 passed');
} finally {
  dbA.db.close();
  dbB.db.close();
  try { fs.unlinkSync(dbA.dbPath); } catch (_) { /* ignore */ }
  try { fs.unlinkSync(dbB.dbPath); } catch (_) { /* ignore */ }
  try { fs.rmSync(syncRoot, { recursive: true, force: true }); } catch (_) { /* ignore */ }
}
