#!/usr/bin/env node
/**
 * End-to-end enquiry workflow: create → stage → follow-up reminder → complete linked task → activity timeline.
 */
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const { createInquiry, changeInquiryStage, updateInquiry } = require('../inquiry-actions');
const { completeOccurrence } = require('../reminder-actions');
const { seedInquiryStages } = require('../inquiry-actions');

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
  const dbPath = path.join(os.tmpdir(), `ilrs-e2e-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    INSERT INTO settings (key, value) VALUES ('inquiry_counter', '2000');
    CREATE TABLE clients (id TEXT PRIMARY KEY, name TEXT, company TEXT, mobile TEXT, email TEXT, notes TEXT, created_at TEXT);
    CREATE TABLE inquiry_stages (
      key TEXT PRIMARY KEY, display_name TEXT, category TEXT, sort_order INTEGER,
      is_closed INTEGER, color TEXT, fields_json TEXT DEFAULT '[]', automation_json TEXT DEFAULT '{}'
    );
    CREATE TABLE inquiries (
      id TEXT PRIMARY KEY, inquiry_number TEXT, client_id TEXT, client_name TEXT, company TEXT,
      mobile TEXT, email TEXT, requirement TEXT, service_category TEXT, source TEXT,
      stage_key TEXT, priority TEXT, assigned_to TEXT, next_action TEXT, next_follow_up TEXT,
      next_follow_up_time TEXT, expected_value REAL, quotation_amount REAL, payment_status TEXT,
      outcome_status TEXT, closed_reason TEXT, health TEXT, stage_changed_at TEXT,
      last_activity_at TEXT, last_activity_summary TEXT, operational_state TEXT,
      lifecycle_status TEXT, notes TEXT, internal_notes TEXT, tags TEXT,
      work_start_date TEXT, expected_completion_date TEXT,
      created_at TEXT, updated_at TEXT
    );
    CREATE TABLE inquiry_activities (
      id TEXT PRIMARY KEY, inquiry_id TEXT, activity_type TEXT, title TEXT, body TEXT,
      old_stage_key TEXT, new_stage_key TEXT, metadata TEXT, created_at TEXT DEFAULT (datetime('now'))
    );
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY, title TEXT, task_type TEXT, category TEXT, why_it_matters TEXT,
      repeat_type TEXT, reminder_time TEXT, start_date TEXT, priority TEXT, alert_style TEXT,
      assigned_to TEXT, source_type TEXT, source_id TEXT, next_fire TEXT, status TEXT,
      workflow_status TEXT, lifecycle_status TEXT, alarm_rings INTEGER DEFAULT 0,
      snooze_count INTEGER DEFAULT 0, last_completed TEXT, created_at TEXT, updated_at TEXT
    );
    CREATE TABLE reminder_logs (id TEXT PRIMARY KEY, reminder_id TEXT, action TEXT, timestamp TEXT);
  `);
  seedInquiryStages(db);
  return { db, dbPath };
}

test('enquiry workflow produces chronological activity trail', () => {
  const { db, dbPath } = makeDb();
  const now = new Date('2026-09-18T10:00:00');
  const created = createInquiry(db, {
    clientName: 'E2E Client',
    requirement: 'Passport renewal',
    nextFollowUp: '2026-09-20',
    nextFollowUpTime: '11:00',
    nextAction: 'Call client',
  }, now);
  assert.ok(created.success);
  const id = created.inquiry.id;

  changeInquiryStage(db, id, 'quotation_sent', { note: 'Sent quote PDF' }, now);

  const linked = db.prepare(`
    SELECT * FROM reminders WHERE source_type = 'inquiry' AND source_id = ? AND status != 'deleted'
  `).all(id);
  assert.ok(linked.length >= 1, 'follow-up reminder exists');

  const taskId = linked[0].id;
  db.prepare(`
    UPDATE reminders SET task_type = 'task', title = 'Prepare documents', workflow_status = 'in_progress'
    WHERE id = ?
  `).run(taskId);

  const done = completeOccurrence(db, taskId, now);
  assert.ok(done.success);

  updateInquiry(db, id, { lifecycleStatus: 'pending', scheduleNext: false }, now);

  const activities = db.prepare(`
    SELECT activity_type, title FROM inquiry_activities WHERE inquiry_id = ? ORDER BY created_at ASC
  `).all(id);
  const types = activities.map((a) => a.activity_type);
  assert.ok(types.includes('inquiry_created'));
  assert.ok(types.includes('stage_change'));
  assert.ok(types.includes('reminder_created') || types.includes('follow_up'));
  assert.ok(types.includes('task_completed') || activities.some((a) => /completed/i.test(a.title)));
  assert.ok(types.includes('status_change'));

  const inq = db.prepare('SELECT last_activity_summary FROM inquiries WHERE id = ?').get(id);
  assert.ok(String(inq.last_activity_summary || '').length > 0);

  db.close();
  fs.unlinkSync(dbPath);
});

console.log('Enquiry workflow E2E tests finished.');
