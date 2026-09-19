#!/usr/bin/env node
/**
 * Phase 1: clients, inquiries, activities, payments sync between two local DBs via shared folder.
 */
const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const Database = require('better-sqlite3');

const { runSyncMigrationV20, runSyncMigrationV21 } = require('../sync-migration');
const { configureSyncFolder, runSyncCycle } = require('../sync-engine');
const { createInquiry, logInquiryActivity, seedInquiryStages } = require('../inquiry-actions');
const { recordPayment } = require('../payment-manager');

function makeWorkDb(label) {
  const dbPath = path.join(os.tmpdir(), `ilrs-sync-p1-${label}-${Date.now()}.db`);
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
      id TEXT PRIMARY KEY, title TEXT, task_type TEXT, category TEXT, status TEXT, source_type TEXT, source_id TEXT,
      next_fire TEXT, reminder_time TEXT, start_date TEXT, updated_at TEXT, workflow_status TEXT,
      payment_tracking_enabled INTEGER, payment_total REAL, next_payment_due_date TEXT, next_payment_amount REAL,
      payment_track_status TEXT
    );
    CREATE TABLE reminder_logs (id TEXT PRIMARY KEY, reminder_id TEXT, action TEXT, timestamp TEXT, note TEXT);
  `);
  runSyncMigrationV20(db);
  runSyncMigrationV21(db);
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '21')").run();
  seedInquiryStages(db);
  return { db, dbPath };
}

function enableSync(db, folderRoot) {
  configureSyncFolder(db, folderRoot);
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('sync_enabled', '1')").run();
}

const syncRoot = path.join(os.tmpdir(), `ilrs-p1-shared-${Date.now()}`);
const dbA = makeWorkDb('A');
const dbB = makeWorkDb('B');

try {
  enableSync(dbA.db, syncRoot);
  enableSync(dbB.db, syncRoot);

  const created = createInquiry(dbA.db, {
    clientName: 'Raj Kumar',
    mobile: '9999900001',
    requirement: 'University Transcript',
    skipFollowUpReminder: true,
  });
  assert.ok(created.success);
  const inquiryId = created.inquiry.id;

  logInquiryActivity(dbA.db, inquiryId, {
    type: 'conversation',
    title: 'Client contacted',
    body: 'Client contacted.',
  });

  dbA.db.prepare(`
    UPDATE inquiries SET payment_tracking_enabled = 1, payment_total = 5000 WHERE id = ?
  `).run(inquiryId);

  recordPayment(dbA.db, {
    entityType: 'inquiry',
    entityId: inquiryId,
    amount: 1000,
    receivedDate: '2026-09-19',
    note: 'Advance',
  });

  const pub = runSyncCycle(dbA.db, '1.2.1-test', { force: true });
  assert.ok(pub.success);
  assert.ok(pub.published > 0, 'expected outbound events');

  const pull = runSyncCycle(dbB.db, '1.2.1-test', { force: true });
  assert.ok(pull.success);
  assert.ok(pull.inbound.applied > 0, 'expected inbound apply');

  const clientB = dbB.db.prepare('SELECT * FROM clients WHERE mobile = ?').get('9999900001');
  assert.ok(clientB, 'client on B');
  const inquiryB = dbB.db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  assert.ok(inquiryB, 'inquiry on B');
  assert.strictEqual(inquiryB.requirement, 'University Transcript');

  const activities = dbB.db.prepare('SELECT * FROM inquiry_activities WHERE inquiry_id = ?').all(inquiryId);
  assert.ok(activities.length >= 2, 'activities include note and conversation');

  const payments = dbB.db.prepare('SELECT * FROM work_payments WHERE entity_id = ?').all(inquiryId);
  assert.ok(payments.length >= 1, 'payment synced');

  console.log('✅ test-sync-phase1 passed');
} finally {
  dbA.db.close();
  dbB.db.close();
  try { fs.unlinkSync(dbA.dbPath); } catch (_) { /* ignore */ }
  try { fs.unlinkSync(dbB.dbPath); } catch (_) { /* ignore */ }
  try { fs.rmSync(syncRoot, { recursive: true, force: true }); } catch (_) { /* ignore */ }
}
