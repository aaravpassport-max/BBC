#!/usr/bin/env node
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const {
  markMedicineDoseTaken,
  markBillPaidAction,
  logHabitAction,
} = require('../module-actions');

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
  const dbPath = path.join(os.tmpdir(), `ilrs-mod-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE medicines (id TEXT PRIMARY KEY, name TEXT, status TEXT DEFAULT 'active');
    CREATE TABLE medicine_logs (
      id TEXT PRIMARY KEY, medicine_id TEXT, dose_time TEXT, scheduled_time TEXT,
      status TEXT, taken_at TEXT, log_date TEXT
    );
    CREATE TABLE bills (id TEXT PRIMARY KEY, name TEXT, amount REAL, status TEXT DEFAULT 'active', payment_status TEXT);
    CREATE TABLE bill_history (id TEXT PRIMARY KEY, bill_id TEXT, paid_date TEXT, amount REAL);
    CREATE TABLE habits (id TEXT PRIMARY KEY, name TEXT, streak INTEGER DEFAULT 0, best_streak INTEGER DEFAULT 0, completion_rate INTEGER DEFAULT 0, last_completed TEXT, status TEXT DEFAULT 'active');
    CREATE TABLE habit_logs (id TEXT PRIMARY KEY, habit_id TEXT, log_date TEXT, completed INTEGER);
  `);
  return { db, dbPath };
}

test('markMedicineDoseTaken logs dose', () => {
  const { db, dbPath } = makeDb();
  db.prepare("INSERT INTO medicines (id, name) VALUES ('m1', 'Aspirin')").run();
  const result = markMedicineDoseTaken(db, 'm1', '08:00', new Date(2026, 8, 13, 8, 0, 0));
  assert.strictEqual(result.success, true);
  const log = db.prepare("SELECT status FROM medicine_logs WHERE medicine_id='m1'").get();
  assert.strictEqual(log.status, 'taken');
  db.close();
  fs.unlinkSync(dbPath);
});

test('markBillPaidAction records payment', () => {
  const { db, dbPath } = makeDb();
  db.prepare("INSERT INTO bills (id, name, amount, payment_status) VALUES ('b1', 'Electric', 500, 'pending')").run();
  const result = markBillPaidAction(db, 'b1', new Date(2026, 8, 13, 10, 0, 0));
  assert.strictEqual(result.success, true);
  const bill = db.prepare("SELECT payment_status FROM bills WHERE id='b1'").get();
  assert.strictEqual(bill.payment_status, 'paid');
  db.close();
  fs.unlinkSync(dbPath);
});

test('logHabitAction increments streak', () => {
  const { db, dbPath } = makeDb();
  db.prepare("INSERT INTO habits (id, name, streak) VALUES ('h1', 'Walk', 2)").run();
  const result = logHabitAction(db, 'h1', new Date(2026, 8, 13, 7, 0, 0));
  assert.strictEqual(result.success, true);
  assert.strictEqual(result.streak, 3);
  db.close();
  fs.unlinkSync(dbPath);
});

console.log('\nModule action tests finished.');
