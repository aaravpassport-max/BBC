/**
 * Work scheduling helpers + completion monitor tests
 */
const assert = require('assert');
const path = require('path');
const Database = require('better-sqlite3');
const {
  effectiveWorkStart,
  effectiveCompletionDate,
  isCompletionOverdue,
  isCompletionDueToday,
  matchesCompletionFilter,
  getDateFilterRange,
} = require('../work-scheduling');
const { handleCompletionAction, checkCompletionDueAlerts } = require('../completion-monitor');

function makeDb() {
  const db = new Database(':memory:');
  db.exec(`
    CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
    CREATE TABLE reminders (
      id TEXT PRIMARY KEY, title TEXT, task_type TEXT DEFAULT 'reminder',
      status TEXT DEFAULT 'active', workflow_status TEXT DEFAULT 'pending', lifecycle_status TEXT DEFAULT '',
      start_date TEXT, end_date TEXT, work_start_date TEXT, expected_completion_date TEXT,
      next_fire TEXT, reminder_time TEXT, notes TEXT, updated_at TEXT, alarm_rings INTEGER DEFAULT 0,
      repeat_type TEXT DEFAULT 'once', last_completed TEXT
    );
    CREATE TABLE reminder_logs (id TEXT PRIMARY KEY, reminder_id TEXT, action TEXT, timestamp TEXT, note TEXT);
    CREATE TABLE inquiries (
      id TEXT PRIMARY KEY, client_name TEXT, requirement TEXT, outcome_status TEXT DEFAULT 'active', lifecycle_status TEXT DEFAULT '',
      stage_key TEXT, work_start_date TEXT, expected_completion_date TEXT,
      next_follow_up TEXT, next_follow_up_time TEXT, next_action TEXT,
      updated_at TEXT, last_activity_at TEXT
    );
    CREATE TABLE inquiry_activities (
      id TEXT PRIMARY KEY, inquiry_id TEXT, activity_type TEXT, title TEXT, body TEXT,
      old_stage_key TEXT, new_stage_key TEXT, metadata TEXT, created_at TEXT
    );
    CREATE TABLE inquiry_stages (key TEXT PRIMARY KEY, display_name TEXT, category TEXT, sort_order INTEGER, is_closed INTEGER);
    INSERT INTO inquiry_stages (key, display_name, category, sort_order, is_closed) VALUES ('delivered', 'Delivered', 'closed', 99, 1);
  `);
  return db;
}

const now = new Date('2026-09-14T10:00:00');

assert.strictEqual(effectiveWorkStart({ work_start_date: '2026-09-01' }), '2026-09-01');
assert.strictEqual(effectiveWorkStart({ start_date: '2026-08-01' }), '2026-08-01');
assert.strictEqual(effectiveCompletionDate({ expected_completion_date: '2026-09-20' }), '2026-09-20');

const overdueItem = { status: 'active', expected_completion_date: '2026-09-10' };
assert.ok(isCompletionOverdue(overdueItem, now));
assert.ok(!isCompletionOverdue({ status: 'completed', expected_completion_date: '2026-09-10' }, now));

const dueToday = { status: 'active', expected_completion_date: '2026-09-14' };
assert.ok(isCompletionDueToday(dueToday, now));

const nextMonth = getDateFilterRange('next_month', now);
assert.strictEqual(nextMonth.start, '2026-10-01');
assert.strictEqual(nextMonth.end, '2026-10-31');

assert.ok(matchesCompletionFilter(dueToday, 'today', now));
assert.ok(matchesCompletionFilter({ status: 'active', expected_completion_date: '2026-10-15' }, 'next_month', now));
assert.ok(matchesCompletionFilter(overdueItem, 'overdue', now));

const db = makeDb();
db.prepare(`INSERT INTO reminders (id, title, status, expected_completion_date, reminder_time, next_fire)
  VALUES ('r1', 'Task A', 'active', '2026-09-14', '09:00', '2026-09-14T09:00:00')`).run();

let alerted = 0;
checkCompletionDueAlerts(db, () => { alerted += 1; }, now);
assert.strictEqual(alerted, 1, 'should alert once on completion due today');

checkCompletionDueAlerts(db, () => { alerted += 1; }, now);
assert.strictEqual(alerted, 1, 'should not double-alert same day');

const completeResult = handleCompletionAction(db, { type: 'reminder', id: 'r1', action: 'complete' }, now);
assert.ok(completeResult.success);

db.prepare(`INSERT INTO inquiries (id, client_name, requirement, outcome_status, expected_completion_date)
  VALUES ('i1', 'Test Client', 'Visa', 'active', '2026-09-12')`).run();
const followResult = handleCompletionAction(db, {
  type: 'inquiry', id: 'i1', action: 'follow_up', date: '2026-09-16', time: '11:00', note: 'Call again',
}, now);
assert.ok(followResult.success);
const inq = db.prepare('SELECT next_follow_up, next_action FROM inquiries WHERE id = ?').get('i1');
assert.strictEqual(inq.next_follow_up, '2026-09-16');

console.log('✅ test-work-scheduling passed');
