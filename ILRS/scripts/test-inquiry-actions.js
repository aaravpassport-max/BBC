#!/usr/bin/env node
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const {
  createInquiry,
  changeInquiryStage,
  findPossibleDuplicates,
  computeInquiryHealth,
} = require('../inquiry-actions');

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
  const dbPath = path.join(os.tmpdir(), `ilrs-inq-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    INSERT INTO settings (key, value) VALUES ('inquiry_counter', '1000');
    CREATE TABLE clients (
      id TEXT PRIMARY KEY, name TEXT, company TEXT, mobile TEXT, email TEXT, notes TEXT, created_at TEXT
    );
    CREATE TABLE inquiries (
      id TEXT PRIMARY KEY, inquiry_number TEXT UNIQUE, client_id TEXT, client_name TEXT,
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
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY, title TEXT, task_type TEXT, category TEXT, why_it_matters TEXT,
      repeat_type TEXT, reminder_time TEXT, start_date TEXT, priority TEXT, alert_style TEXT,
      assigned_to TEXT, source_type TEXT, source_id TEXT, next_fire TEXT, status TEXT,
      workflow_status TEXT, created_at TEXT, updated_at TEXT
    );
  `);
  return { db, dbPath };
}

test('createInquiry assigns number and client', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const result = createInquiry(db, {
    clientName: 'Raj Kumar',
    requirement: 'Birth Certificate',
    nextAction: 'Call client',
    nextFollowUp: '2026-09-14',
    mobile: '9876543210',
  }, now);
  assert.strictEqual(result.success, true);
  assert.ok(result.inquiry.inquiry_number.startsWith('INQ-'));
  assert.strictEqual(result.inquiry.client_name, 'Raj Kumar');
  assert.strictEqual(result.inquiry.stage_key, 'follow_up');
  const client = db.prepare('SELECT * FROM clients WHERE mobile = ?').get('9876543210');
  assert.ok(client);
  db.close();
  fs.unlinkSync(dbPath);
});

test('changeInquiryStage updates stage and outcome', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const { inquiry } = createInquiry(db, { clientName: 'A', requirement: 'Visa' }, now);
  const result = changeInquiryStage(db, inquiry.id, 'quotation_sent', {}, now);
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.inquiry.stage_key, 'quotation_sent');
  assert.strictEqual(result.inquiry.outcome_status, 'active');
  db.close();
  fs.unlinkSync(dbPath);
});

test('findPossibleDuplicates matches mobile', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  createInquiry(db, { clientName: 'A', requirement: 'X', mobile: '111' }, now);
  const matches = findPossibleDuplicates(db, { mobile: '111', name: 'A', requirement: 'Y' });
  assert.strictEqual(matches.length, 1);
  db.close();
  fs.unlinkSync(dbPath);
});

test('computeInquiryHealth flags overdue follow-up', () => {
  const health = computeInquiryHealth({
    outcome_status: 'active',
    next_follow_up: '2026-09-10',
    next_follow_up_time: '09:00',
    last_activity_at: '2026-09-12 10:00:00',
  }, new Date(2026, 8, 13, 12, 0, 0));
  assert.strictEqual(health, 'at_risk');
});
