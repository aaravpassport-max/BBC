#!/usr/bin/env node
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const {
  loadStagesFromDb,
  saveStageToDb,
  deleteInquiryStage,
  countInquiryStageUsage,
} = require('../inquiry-stage-store');
const { seedInquiryStages } = require('../inquiry-actions');

function test(name, fn) {
  try { fn(); console.log(`✅ ${name}`); }
  catch (err) { console.error(`❌ ${name}: ${err.message}`); process.exitCode = 1; }
}

function makeDb() {
  const dbPath = path.join(os.tmpdir(), `ilrs-inq-stage-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    INSERT INTO settings (key, value) VALUES ('inquiry_counter', '1000');
    CREATE TABLE clients (id TEXT PRIMARY KEY, name TEXT, company TEXT, mobile TEXT, email TEXT, notes TEXT, created_at TEXT);
    CREATE TABLE inquiries (
      id TEXT PRIMARY KEY, inquiry_number TEXT, client_id TEXT, client_name TEXT,
      company TEXT, mobile TEXT, email TEXT, requirement TEXT, service_category TEXT, source TEXT,
      stage_key TEXT, priority TEXT, assigned_to TEXT, next_action TEXT, next_follow_up TEXT,
      next_follow_up_time TEXT, expected_value REAL, quotation_amount REAL, payment_status TEXT,
      outcome_status TEXT, closed_reason TEXT, health TEXT, stage_changed_at TEXT, last_activity_at TEXT,
      notes TEXT, internal_notes TEXT, tags TEXT, created_at TEXT, updated_at TEXT
    );
    CREATE TABLE inquiry_activities (
      id TEXT PRIMARY KEY, inquiry_id TEXT, activity_type TEXT, title TEXT, body TEXT,
      old_stage_key TEXT, new_stage_key TEXT, metadata TEXT, created_at TEXT
    );
    CREATE TABLE inquiry_stages (
      key TEXT PRIMARY KEY, display_name TEXT, category TEXT, sort_order INTEGER,
      is_closed INTEGER, color TEXT, fields_json TEXT DEFAULT '[]', automation_json TEXT DEFAULT '{}'
    );
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY, title TEXT, task_type TEXT, status TEXT, source_type TEXT, source_id TEXT
    );
  `);
  seedInquiryStages(db);
  return { db, dbPath };
}

test('saveStageToDb creates custom inquiry stage', () => {
  const { db, dbPath } = makeDb();
  saveStageToDb(db, {
    key: 'site_visit',
    display: 'Site Visit Scheduled',
    category: 'qualification',
    sort: 25,
    closed: false,
    automation: { followUpDays: 2, reminderEnabled: true },
  });
  const stages = loadStagesFromDb(db);
  const stage = stages.find((s) => s.key === 'site_visit');
  assert.ok(stage);
  assert.strictEqual(stage.display, 'Site Visit Scheduled');
  assert.strictEqual(stage.automation.followUpDays, 2);
  db.close(); fs.unlinkSync(dbPath);
});

test('saveStageToDb updates existing inquiry stage display name', () => {
  const { db, dbPath } = makeDb();
  const stages = loadStagesFromDb(db);
  const followUp = stages.find((s) => s.key === 'follow_up');
  saveStageToDb(db, { ...followUp, display: 'Follow Up Renamed' });
  const updated = loadStagesFromDb(db).find((s) => s.key === 'follow_up');
  assert.strictEqual(updated.display, 'Follow Up Renamed');
  db.close(); fs.unlinkSync(dbPath);
});

test('deleteInquiryStage reassigns inquiries', () => {
  const { db, dbPath } = makeDb();
  saveStageToDb(db, {
    key: 'temp_inq',
    display: 'Temp',
    category: 'qualification',
    sort: 99,
    closed: false,
    automation: {},
  });
  db.prepare(`INSERT INTO inquiries (id, inquiry_number, client_name, requirement, stage_key, outcome_status)
    VALUES ('i1', 'INQ-1', 'A', 'Test', 'temp_inq', 'active')`).run();
  assert.strictEqual(countInquiryStageUsage(db, 'temp_inq'), 1);
  const result = deleteInquiryStage(db, 'temp_inq', 'follow_up');
  assert.strictEqual(result.success, true);
  const row = db.prepare('SELECT stage_key FROM inquiries WHERE id = ?').get('i1');
  assert.strictEqual(row.stage_key, 'follow_up');
  db.close(); fs.unlinkSync(dbPath);
});

console.log('Inquiry stage admin tests finished.');
