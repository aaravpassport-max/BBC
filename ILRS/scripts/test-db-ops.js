#!/usr/bin/env node
/**
 * Smoke-test critical ILRS database writes (reminders, medicines, bills, habits).
 */
const path = require('path');
const fs = require('fs');
const Database = require('better-sqlite3');

const dbPath = path.join(__dirname, '..', 'test-ilrs.db');
if (fs.existsSync(dbPath)) fs.unlinkSync(dbPath);

const db = new Database(dbPath);
db.exec(fs.readFileSync(path.join(__dirname, '..', 'main.js'), 'utf8').match(/db\.exec\(`([\s\S]*?)`\)/)?.[1] || '');

// Minimal schema from main.js
db.exec(`
  CREATE TABLE IF NOT EXISTS reminders (
    id TEXT PRIMARY KEY, title TEXT NOT NULL, task_type TEXT DEFAULT 'reminder',
    category TEXT DEFAULT 'general', why_it_matters TEXT DEFAULT '', repeat_type TEXT DEFAULT 'once',
    reminder_time TEXT DEFAULT '', start_date TEXT DEFAULT '', end_date TEXT DEFAULT '',
    priority TEXT DEFAULT 'normal', urgency_quadrant TEXT DEFAULT 'important-not-urgent',
    alert_style TEXT DEFAULT 'sound-popup', snooze_duration INTEGER DEFAULT 10,
    assigned_to TEXT DEFAULT 'me', is_private INTEGER DEFAULT 0, notes TEXT DEFAULT '',
    tags TEXT DEFAULT '[]', status TEXT DEFAULT 'active', next_fire TEXT DEFAULT '',
    created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now'))
  );
  CREATE TABLE IF NOT EXISTS medicines (
    id TEXT PRIMARY KEY, name TEXT NOT NULL, condition TEXT DEFAULT '', doses_per_day INTEGER DEFAULT 1,
    dose_times TEXT DEFAULT '[]', food_timing TEXT DEFAULT 'anytime', start_date TEXT DEFAULT '',
    end_date TEXT DEFAULT '', notes TEXT DEFAULT '', status TEXT DEFAULT 'active'
  );
  CREATE TABLE IF NOT EXISTS bills (
    id TEXT PRIMARY KEY, name TEXT NOT NULL, bill_type TEXT DEFAULT 'other', amount REAL DEFAULT 0,
    due_day INTEGER DEFAULT 1, warning_days INTEGER DEFAULT 3, account_info TEXT DEFAULT '',
    status TEXT DEFAULT 'active', payment_status TEXT DEFAULT 'pending'
  );
  CREATE TABLE IF NOT EXISTS habits (
    id TEXT PRIMARY KEY, name TEXT NOT NULL, frequency TEXT DEFAULT 'daily',
    target_time TEXT DEFAULT '07:00', streak INTEGER DEFAULT 0, best_streak INTEGER DEFAULT 0,
    completion_rate REAL DEFAULT 0, status TEXT DEFAULT 'active'
  );
`);

function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

const today = new Date().toISOString().split('T')[0];
let passed = 0;
let failed = 0;

function test(name, fn) {
  try {
    fn();
    console.log(`✅ ${name}`);
    passed += 1;
  } catch (err) {
    console.error(`❌ ${name}: ${err.message}`);
    failed += 1;
  }
}

test('saveReminder INSERT', () => {
  const id = uuid();
  const params = [
    id, 'Test Reminder', 'reminder', 'general', 'Why', 'once', '09:00', today, '',
    'normal', 'important-not-urgent', 'sound-popup', 10, 'me', 0, 'notes', '[]', `${today}T09:00:00`,
  ];
  db.prepare(
    `INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,reminder_time,start_date,end_date,priority,urgency_quadrant,alert_style,snooze_duration,assigned_to,is_private,notes,tags,next_fire,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',datetime('now'),datetime('now'))`
  ).run(...params);
  const row = db.prepare("SELECT * FROM reminders WHERE id=? AND status != 'deleted'").get(id);
  if (!row || row.title !== 'Test Reminder') throw new Error('row not found');
});

test('quickAdd INSERT', () => {
  const id = uuid();
  db.prepare(
    `INSERT INTO reminders (id,title,task_type,category,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))`
  ).run(id, 'Quick task', 'reminder', 'general', 'once', '10:00', today, 'normal', 'sound-popup', 'active', `${today}T10:00:00`);
});

test('saveMedicine + linked reminder', () => {
  const mid = uuid();
  db.prepare(
    `INSERT INTO medicines (id,name,condition,doses_per_day,dose_times,food_timing,start_date,end_date,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?)`
  ).run(mid, 'Aspirin', 'pain', 1, '["08:00"]', 'after', today, '', '', 'active');
  const rid = uuid();
  db.prepare(
    `INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))`
  ).run(rid, 'Take Aspirin', 'reminder', 'medicine', 'pain', 'daily', '08:00', today, 'important', 'sound-popup', 'active', `${today}T08:00:00`);
});

test('saveBill + linked reminder', () => {
  const bid = uuid();
  db.prepare(
    `INSERT INTO bills (id,name,bill_type,amount,due_day,warning_days,account_info,status) VALUES (?,?,?,?,?,?,?,?)`
  ).run(bid, 'Electricity', 'electricity', 500, 5, 3, '', 'active');
  const rid = uuid();
  db.prepare(
    `INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))`
  ).run(rid, 'Pay Electricity', 'reminder', 'bills', 'Avoid late fees', 'monthly', '09:00', today, 'important', 'sound-popup', 'active', `${today}T09:00:00`);
});

test('saveHabit + linked reminder', () => {
  const hid = uuid();
  db.prepare(
    `INSERT INTO habits (id,name,frequency,target_time,streak,best_streak,completion_rate,status) VALUES (?,?,?,?,0,0,0,'active')`
  ).run(hid, 'Walk', 'daily', '07:00');
  const rid = uuid();
  db.prepare(
    `INSERT INTO reminders (id,title,task_type,category,why_it_matters,repeat_type,reminder_time,start_date,priority,alert_style,status,next_fire,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))`
  ).run(rid, 'Walk', 'habit', 'personal', 'Build habit', 'daily', '07:00', today, 'normal', 'sound-popup', 'active', `${today}T07:00:00`);
});

const activeCount = db.prepare("SELECT COUNT(*) as c FROM reminders WHERE status != 'deleted'").get().c;
console.log(`\nActive reminders in test DB: ${activeCount}`);
console.log(`Results: ${passed} passed, ${failed} failed`);
fs.unlinkSync(dbPath);
process.exit(failed > 0 ? 1 : 0);
