#!/usr/bin/env node
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const { createInquiry, changeInquiryStage } = require('../inquiry-actions');
const { loadStagesFromDb, saveStageToDb } = require('../inquiry-stage-store');
const { listTemplates, seedDefaultTemplates, createTemplate } = require('../inquiry-templates');
const { getWorkAnalytics } = require('../inquiry-analytics');
const { refreshAllInquiryHealth, checkInquiryAlerts } = require('../inquiry-health-monitor');
const { getStageFields, getStageAutomation } = require('../inquiry-pipeline');

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
  const dbPath = path.join(os.tmpdir(), `ilrs-wf-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    INSERT INTO settings (key, value) VALUES ('inquiry_counter', '1000');
    INSERT INTO settings (key, value) VALUES ('inquiry_alerts_enabled', '1');
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
      last_activity_at TEXT, notes TEXT, internal_notes TEXT, tags TEXT, created_at TEXT, updated_at TEXT
    );
    CREATE TABLE inquiry_activities (
      id TEXT PRIMARY KEY, inquiry_id TEXT, activity_type TEXT, title TEXT, body TEXT,
      old_stage_key TEXT, new_stage_key TEXT, metadata TEXT, created_at TEXT
    );
    CREATE TABLE inquiry_templates (
      id TEXT PRIMARY KEY, name TEXT, requirement TEXT, service_category TEXT, stage_key TEXT,
      next_action TEXT, source TEXT, expected_value REAL, notes TEXT, sort_order INTEGER, created_at TEXT
    );
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY, title TEXT, task_type TEXT, category TEXT, why_it_matters TEXT,
      repeat_type TEXT, reminder_time TEXT, start_date TEXT, priority TEXT, alert_style TEXT,
      assigned_to TEXT, source_type TEXT, source_id TEXT, next_fire TEXT, status TEXT,
      workflow_status TEXT, created_at TEXT, updated_at TEXT
    );
  `);
  const { seedInquiryStages } = require('../inquiry-actions');
  seedInquiryStages(db);
  return { db, dbPath };
}

test('loadStagesFromDb returns stages with fields', () => {
  const { db, dbPath } = makeDb();
  const stages = loadStagesFromDb(db);
  assert.ok(stages.length >= 15);
  const q = stages.find((s) => s.key === 'quotation_sent');
  assert.ok(q?.fields?.length > 0 || q?.automation?.followUpDays);
  db.close();
  fs.unlinkSync(dbPath);
});

test('saveStageToDb updates display name', () => {
  const { db, dbPath } = makeDb();
  const stages = loadStagesFromDb(db);
  const s = { ...stages[0], display: 'Custom Follow Up' };
  saveStageToDb(db, s);
  const row = db.prepare('SELECT display_name FROM inquiry_stages WHERE key = ?').get(s.key);
  assert.strictEqual(row.display_name, 'Custom Follow Up');
  db.close();
  fs.unlinkSync(dbPath);
});

test('stage change applies automation follow-up', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const { inquiry } = createInquiry(db, { clientName: 'A', requirement: 'Visa' }, now);
  const result = changeInquiryStage(db, inquiry.id, 'quotation_sent', { quotationAmount: 5000 }, now);
  assert.strictEqual(result.success, true);
  const updated = db.prepare('SELECT next_follow_up, quotation_amount FROM inquiries WHERE id = ?').get(inquiry.id);
  assert.ok(updated.next_follow_up);
  assert.strictEqual(updated.quotation_amount, 5000);
  db.close();
  fs.unlinkSync(dbPath);
});

test('templates seed and create', () => {
  const { db, dbPath } = makeDb();
  seedDefaultTemplates(db);
  assert.ok(listTemplates(db).length >= 3);
  createTemplate(db, { name: 'Custom', requirement: 'Test service' });
  assert.ok(listTemplates(db).find((t) => t.name === 'Custom'));
  db.close();
  fs.unlinkSync(dbPath);
});

test('getWorkAnalytics returns funnel data', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  createInquiry(db, { clientName: 'A', requirement: 'X' }, now);
  const a = getWorkAnalytics(db, now);
  assert.strictEqual(a.totals.active, 1);
  assert.ok(a.byStage.length >= 1);
  db.close();
  fs.unlinkSync(dbPath);
});

test('checkInquiryAlerts fires for overdue follow-up', () => {
  const { db, dbPath } = makeDb();
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const { inquiry } = createInquiry(db, {
    clientName: 'Late Client', requirement: 'Docs', nextFollowUp: '2026-09-10',
  }, now);
  db.prepare("UPDATE inquiries SET health = 'at_risk' WHERE id = ?").run(inquiry.id);
  const alerts = [];
  checkInquiryAlerts(db, (data) => alerts.push(data), now);
  assert.ok(alerts.length >= 1);
  db.close();
  fs.unlinkSync(dbPath);
});

test('getStageFields and automation defined', () => {
  assert.ok(getStageFields('quotation_sent').length > 0);
  assert.ok(getStageAutomation('quotation_sent').followUpDays > 0);
});
