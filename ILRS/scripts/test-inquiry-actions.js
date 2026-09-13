#!/usr/bin/env node
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const {
  createInquiry,
  updateInquiry,
  changeInquiryStage,
  findPossibleDuplicates,
  computeInquiryHealth,
  deleteInquiry,
  bulkDeleteInquiries,
  rescheduleInquiry,
  seedInquiryStages,
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
    CREATE TABLE inquiry_stages (
      key TEXT PRIMARY KEY, display_name TEXT, category TEXT, sort_order INTEGER,
      is_closed INTEGER, color TEXT, fields_json TEXT DEFAULT '[]', automation_json TEXT DEFAULT '{}'
    );
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY, title TEXT, task_type TEXT, category TEXT, why_it_matters TEXT,
      repeat_type TEXT, reminder_time TEXT, start_date TEXT, priority TEXT, alert_style TEXT,
      assigned_to TEXT, source_type TEXT, source_id TEXT, next_fire TEXT, status TEXT,
      workflow_status TEXT, created_at TEXT, updated_at TEXT
    );
  `);
  seedInquiryStages(db);
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

test('updateInquiry updates requirement and notes', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const { inquiry } = createInquiry(db, { clientName: 'A', requirement: 'Visa' }, now);
  const result = updateInquiry(db, inquiry.id, { requirement: 'Passport', notes: 'Urgent' }, now);
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.inquiry.requirement, 'Passport');
  assert.strictEqual(result.inquiry.notes, 'Urgent');
  db.close();
  fs.unlinkSync(dbPath);
});

test('deleteInquiry marks inquiry deleted', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const { inquiry } = createInquiry(db, { clientName: 'A', requirement: 'Visa' }, now);
  const result = deleteInquiry(db, inquiry.id);
  assert.strictEqual(result.success, true);
  const row = db.prepare('SELECT outcome_status FROM inquiries WHERE id = ?').get(inquiry.id);
  assert.strictEqual(row.outcome_status, 'deleted');
  db.close();
  fs.unlinkSync(dbPath);
});

test('bulkDeleteInquiries deletes multiple inquiries', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const a = createInquiry(db, { clientName: 'A', requirement: 'X' }, now).inquiry;
  const b = createInquiry(db, { clientName: 'B', requirement: 'Y' }, now).inquiry;
  const result = bulkDeleteInquiries(db, [a.id, b.id]);
  assert.strictEqual(result.deleted, 2);
  db.close();
  fs.unlinkSync(dbPath);
});

test('rescheduleInquiry reopens closed inquiry with new follow-up', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const { inquiry } = createInquiry(db, { clientName: 'Closed Co', requirement: 'GST' }, now);
  changeInquiryStage(db, inquiry.id, 'closed_no_response', { closedReason: 'No budget' }, now);
  const closed = db.prepare('SELECT outcome_status FROM inquiries WHERE id = ?').get(inquiry.id);
  assert.strictEqual(closed.outcome_status, 'closed_lost');

  const result = rescheduleInquiry(db, inquiry.id, {
    date: '2026-09-20',
    time: '15:00',
    nextAction: 'Call again',
  }, now);
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.inquiry.outcome_status, 'active');
  assert.strictEqual(result.inquiry.next_follow_up, '2026-09-20');
  assert.strictEqual(result.inquiry.next_follow_up_time, '15:00');

  const reminder = db.prepare(
    "SELECT * FROM reminders WHERE source_type = 'inquiry' AND source_id = ? AND status = 'active'",
  ).get(inquiry.id);
  assert.ok(reminder);
  db.close();
  fs.unlinkSync(dbPath);
});

test('updateInquiry reactivates closed inquiry when follow-up is set', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const { inquiry } = createInquiry(db, { clientName: 'B', requirement: 'PAN' }, now);
  changeInquiryStage(db, inquiry.id, 'delivered', {}, now);
  const result = updateInquiry(db, inquiry.id, { nextFollowUp: '2026-09-25', nextFollowUpTime: '10:30' }, now);
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.inquiry.outcome_status, 'active');
  assert.strictEqual(result.inquiry.next_follow_up, '2026-09-25');
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
