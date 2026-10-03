/**
 * Payment tracking tests
 */
const assert = require('assert');
const Database = require('better-sqlite3');
const {
  computePaymentSummary,
  recordPayment,
  updatePaymentSettings,
  deletePayment,
  formatRupee,
} = require('../payment-manager');
const { checkPaymentDueAlerts } = require('../payment-monitor');

function makeDb() {
  const db = new Database(':memory:');
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    CREATE TABLE work_payments (
      id TEXT PRIMARY KEY, entity_type TEXT, entity_id TEXT,
      amount REAL, received_date TEXT, note TEXT, created_at TEXT
    );
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY, title TEXT, status TEXT DEFAULT 'active',
      payment_tracking_enabled INTEGER DEFAULT 0, payment_total REAL DEFAULT 0,
      next_payment_due_date TEXT, next_payment_amount REAL DEFAULT 0,
      payment_track_status TEXT, updated_at TEXT
    );
    CREATE TABLE reminder_logs (id TEXT PRIMARY KEY, reminder_id TEXT, action TEXT, timestamp TEXT, note TEXT);
    CREATE TABLE inquiries (
      id TEXT PRIMARY KEY, client_name TEXT, requirement TEXT, outcome_status TEXT DEFAULT 'active',
      payment_tracking_enabled INTEGER DEFAULT 0, payment_total REAL DEFAULT 0,
      quotation_amount REAL DEFAULT 0, next_payment_due_date TEXT, next_payment_amount REAL DEFAULT 0,
      payment_track_status TEXT, updated_at TEXT, last_activity_at TEXT
    );
    CREATE TABLE inquiry_activities (
      id TEXT PRIMARY KEY, inquiry_id TEXT, activity_type TEXT, title TEXT, body TEXT,
      old_stage_key TEXT, new_stage_key TEXT, metadata TEXT, created_at TEXT
    );
  `);
  return db;
}

const now = new Date('2026-09-14T10:00:00');

assert.strictEqual(formatRupee(10000), '₹10,000');

const db = makeDb();
db.prepare(`INSERT INTO reminders (id, title) VALUES ('r1', 'Project X')`).run();

updatePaymentSettings(db, {
  entityType: 'reminder',
  entityId: 'r1',
  enabled: true,
  total: 50000,
  nextPaymentDueDate: '2026-09-25',
  nextPaymentAmount: 10000,
}, now);

recordPayment(db, { entityType: 'reminder', entityId: 'r1', amount: 10000, receivedDate: '2026-09-05', note: 'Advance' }, now);
recordPayment(db, { entityType: 'reminder', entityId: 'r1', amount: 20000, receivedDate: '2026-09-12' }, now);
recordPayment(db, { entityType: 'reminder', entityId: 'r1', amount: 5000, receivedDate: '2026-09-18' }, now);

const payments = db.prepare('SELECT * FROM work_payments WHERE entity_id = ?').all('r1');
assert.strictEqual(payments.length, 3);

const entity = db.prepare('SELECT * FROM reminders WHERE id = ?').get('r1');
const summary = computePaymentSummary(entity, payments, now);
assert.strictEqual(summary.received, 35000);
assert.strictEqual(summary.balance, 15000);
assert.strictEqual(summary.status, 'partial');
assert.strictEqual(summary.statusLabel, 'Partially Paid');

let alerted = 0;
db.prepare(`UPDATE reminders SET next_payment_due_date = '2026-09-14' WHERE id = 'r1'`).run();
const updated = db.prepare('SELECT * FROM reminders WHERE id = ?').get('r1');
checkPaymentDueAlerts(db, () => { alerted += 1; }, now);
assert.ok(alerted >= 1, 'should alert on payment due today');

const pay = payments[0];
const del = deletePayment(db, pay.id, now);
assert.ok(del.success);
const after = computePaymentSummary(updated, db.prepare('SELECT * FROM work_payments WHERE entity_id = ?').all('r1'), now);
assert.strictEqual(after.received, 25000);

console.log('✅ test-payments passed');
