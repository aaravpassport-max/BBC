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
  convertReminderToInquiry,
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
      work_start_date TEXT, expected_completion_date TEXT,
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
      workflow_status TEXT, work_start_date TEXT, expected_completion_date TEXT,
      created_at TEXT, updated_at TEXT
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

test('updateInquiry reschedules linked follow-up reminder', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const { inquiry } = createInquiry(db, {
    clientName: 'Schedule Co',
    requirement: 'License',
    nextFollowUp: '2026-09-14',
    nextFollowUpTime: '11:00',
  }, now);
  const before = db.prepare(
    "SELECT start_date, reminder_time FROM reminders WHERE source_type = 'inquiry' AND source_id = ? AND status = 'active'",
  ).get(inquiry.id);
  assert.strictEqual(before.start_date, '2026-09-14');
  assert.strictEqual(before.reminder_time, '11:00');

  const result = updateInquiry(db, inquiry.id, {
    nextFollowUp: '2026-09-20',
    nextFollowUpTime: '15:30',
  }, now);
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.inquiry.next_follow_up, '2026-09-20');

  const activeReminders = db.prepare(
    "SELECT * FROM reminders WHERE source_type = 'inquiry' AND source_id = ? AND status = 'active'",
  ).all(inquiry.id);
  assert.strictEqual(activeReminders.length, 1);
  assert.strictEqual(activeReminders[0].start_date, '2026-09-20');
  assert.strictEqual(activeReminders[0].reminder_time, '15:30');
  db.close();
  fs.unlinkSync(dbPath);
});

test('convertReminderToInquiry links original reminder without duplicate follow-up', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const reminderId = 'rem-1';
  db.prepare(`
    INSERT INTO reminders (
      id, title, task_type, category, why_it_matters, repeat_type, reminder_time,
      start_date, priority, alert_style, assigned_to, source_type, source_id,
      next_fire, status, workflow_status, created_at, updated_at
    ) VALUES (?, 'Call Raj about passport', 'task', 'work', 'Discuss documents', 'once', '10:00',
      '2026-09-15', 'normal', 'sound-popup', 'me', '', '', '2026-09-15T10:00:00', 'active', 'pending',
      datetime('now'), datetime('now'))
  `).run(reminderId);

  const result = convertReminderToInquiry(db, reminderId, {
    clientName: 'Raj Kumar',
    requirement: 'Passport',
    nextAction: 'Call client',
    nextFollowUp: '2026-09-15',
    nextFollowUpTime: '10:00',
  }, now);
  assert.strictEqual(result.success, true);
  assert.ok(result.inquiry.inquiry_number.startsWith('INQ-'));
  assert.strictEqual(result.linkedReminderId, reminderId);

  const linked = db.prepare('SELECT * FROM reminders WHERE id = ?').get(reminderId);
  assert.strictEqual(linked.source_type, 'inquiry');
  assert.strictEqual(linked.source_id, result.inquiry.id);
  assert.strictEqual(linked.task_type, 'task');

  const followUps = db.prepare(
    "SELECT * FROM reminders WHERE source_type = 'inquiry' AND source_id = ? AND status = 'active'",
  ).all(result.inquiry.id);
  assert.strictEqual(followUps.length, 1);
  assert.strictEqual(followUps[0].id, reminderId);

  const activity = db.prepare(
    "SELECT title FROM inquiry_activities WHERE inquiry_id = ? AND title = 'Converted from reminder/task'",
  ).get(result.inquiry.id);
  assert.ok(activity);
  db.close();
  fs.unlinkSync(dbPath);
});

test('convertReminderToInquiry rejects life module reminders', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  db.prepare(`
    INSERT INTO reminders (
      id, title, task_type, category, repeat_type, reminder_time, start_date,
      status, workflow_status, created_at, updated_at
    ) VALUES ('med-1', 'Take aspirin', 'reminder', 'medicine', 'once', '09:00', '2026-09-13',
      'active', 'pending', datetime('now'), datetime('now'))
  `).run();
  const result = convertReminderToInquiry(db, 'med-1', {
    clientName: 'A',
    requirement: 'Medicine',
  }, now);
  assert.strictEqual(result.success, false);
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
