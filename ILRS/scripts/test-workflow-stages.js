#!/usr/bin/env node
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const {
  seedWorkflowStages,
  loadWorkflowStages,
  saveWorkflowStage,
  deleteWorkflowStage,
} = require('../workflow-stage-store');
const { changeReminderStage, setInitialReminderStage } = require('../workflow-stage-actions');

function test(name, fn) {
  try { fn(); console.log(`✅ ${name}`); }
  catch (err) { console.error(`❌ ${name}: ${err.message}`); process.exitCode = 1; }
}

function makeDb() {
  const dbPath = path.join(os.tmpdir(), `ilrs-wf-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE workflow_stages (
      key TEXT NOT NULL, entity_type TEXT NOT NULL, display_name TEXT,
      category TEXT, sort_order INTEGER, is_closed INTEGER, color TEXT,
      fields_json TEXT DEFAULT '[]', automation_json TEXT DEFAULT '{}',
      PRIMARY KEY (key, entity_type)
    );
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY, title TEXT, task_type TEXT DEFAULT 'reminder',
      repeat_type TEXT DEFAULT 'once', reminder_time TEXT, start_date TEXT,
      status TEXT DEFAULT 'active', workflow_status TEXT DEFAULT 'pending',
      stage_key TEXT DEFAULT '', stage_reminder_disabled INTEGER DEFAULT 0,
      next_fire TEXT, stage_changed_at TEXT, updated_at TEXT
    );
  `);
  seedWorkflowStages(db);
  return { db, dbPath };
}

test('loadWorkflowStages returns task stages', () => {
  const { db, dbPath } = makeDb();
  const stages = loadWorkflowStages(db, 'task');
  assert.ok(stages.length >= 5);
  db.close(); fs.unlinkSync(dbPath);
});

test('saveWorkflowStage creates and updates custom task stage', () => {
  const { db, dbPath } = makeDb();
  saveWorkflowStage(db, {
    key: 'custom_review',
    entityType: 'task',
    display: 'Custom Review',
    category: 'active',
    sort: 99,
    closed: false,
    automation: { followUpDays: 3, reminderEnabled: true },
  });
  let stages = loadWorkflowStages(db, 'task');
  const created = stages.find((s) => s.key === 'custom_review');
  assert.strictEqual(created.display, 'Custom Review');
  assert.strictEqual(created.automation.followUpDays, 3);

  saveWorkflowStage(db, {
    ...created,
    display: 'Custom Review Updated',
    automation: { followUpDays: 5, reminderEnabled: true },
  });
  stages = loadWorkflowStages(db, 'task');
  assert.strictEqual(stages.find((s) => s.key === 'custom_review').display, 'Custom Review Updated');
  db.close(); fs.unlinkSync(dbPath);
});

test('changeReminderStage uses newly created custom stage', () => {
  const { db, dbPath } = makeDb();
  saveWorkflowStage(db, {
    key: 'awaiting_approval',
    entityType: 'task',
    display: 'Awaiting Approval',
    category: 'hold',
    sort: 50,
    closed: false,
    automation: { followUpDays: 1, reminderEnabled: true },
  });
  db.prepare(`INSERT INTO reminders (id, title, task_type, status, reminder_time, start_date, next_fire)
    VALUES ('t2', 'Approval task', 'task', 'active', '09:00', '2026-09-13', '2026-09-13T09:00:00')`).run();
  setInitialReminderStage(db, 't2', 'task', 'new');
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const result = changeReminderStage(db, 't2', 'awaiting_approval', {}, now);
  assert.strictEqual(result.success, true);
  const row = db.prepare('SELECT stage_key FROM reminders WHERE id = ?').get('t2');
  assert.strictEqual(row.stage_key, 'awaiting_approval');
  db.close(); fs.unlinkSync(dbPath);
});

test('deleteWorkflowStage reassigns items to another stage', () => {
  const { db, dbPath } = makeDb();
  saveWorkflowStage(db, {
    key: 'temp_stage',
    entityType: 'reminder',
    display: 'Temp',
    category: 'open',
    sort: 99,
    closed: false,
    automation: {},
  });
  db.prepare(`INSERT INTO reminders (id, title, task_type, stage_key, status, next_fire)
    VALUES ('r1', 'Test', 'reminder', 'temp_stage', 'active', '2026-09-13T09:00:00')`).run();
  const result = deleteWorkflowStage(db, 'reminder', 'temp_stage', 'scheduled');
  assert.strictEqual(result.success, true);
  const row = db.prepare('SELECT stage_key FROM reminders WHERE id = ?').get('r1');
  assert.strictEqual(row.stage_key, 'scheduled');
  const stages = loadWorkflowStages(db, 'reminder');
  assert.ok(!stages.find((s) => s.key === 'temp_stage'));
  db.close(); fs.unlinkSync(dbPath);
});

test('changeReminderStage to follow_up schedules next_fire', () => {
  const { db, dbPath } = makeDb();
  db.prepare(`INSERT INTO reminders (id, title, task_type, status, reminder_time, start_date, next_fire)
    VALUES ('t1', 'Test task', 'task', 'active', '09:00', '2026-09-13', '2026-09-13T09:00:00')`).run();
  setInitialReminderStage(db, 't1', 'task', 'new');
  const now = new Date(2026, 8, 13, 10, 0, 0);
  const result = changeReminderStage(db, 't1', 'follow_up', {}, now);
  assert.strictEqual(result.success, true);
  const row = db.prepare('SELECT stage_key, next_fire FROM reminders WHERE id = ?').get('t1');
  assert.strictEqual(row.stage_key, 'follow_up');
  assert.ok(row.next_fire);
  db.close(); fs.unlinkSync(dbPath);
});
