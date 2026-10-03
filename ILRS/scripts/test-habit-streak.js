#!/usr/bin/env node
const assert = require('assert');
const path = require('path');
const fs = require('fs');
const os = require('os');
const Database = require('better-sqlite3');
const {
  isHabitScheduledOnDate,
  calculateHabitStreak,
  calculateCompletionRate,
  recalculateHabitStats,
} = require('../habit-streak');

function test(name, fn) {
  try {
    fn();
    console.log(`✅ ${name}`);
  } catch (err) {
    console.error(`❌ ${name}: ${err.message}`);
    process.exitCode = 1;
  }
}

const dailyHabit = { id: 'h1', frequency: 'daily', best_streak: 0 };
const weekdayHabit = { id: 'h2', frequency: 'weekdays', best_streak: 0, created_at: '2026-09-01 08:00:00' };

test('isHabitScheduledOnDate respects weekdays', () => {
  const sat = new Date(2026, 8, 12); // Saturday
  const mon = new Date(2026, 8, 14); // Monday
  assert.strictEqual(isHabitScheduledOnDate(weekdayHabit, sat), false);
  assert.strictEqual(isHabitScheduledOnDate(weekdayHabit, mon), true);
});

test('calculateHabitStreak counts consecutive scheduled days', () => {
  const dates = ['2026-09-11', '2026-09-12', '2026-09-13'];
  const now = new Date(2026, 8, 13, 8, 0, 0);
  assert.strictEqual(calculateHabitStreak(dailyHabit, dates, now), 3);
});

test('calculateHabitStreak breaks on missed day', () => {
  const dates = ['2026-09-10', '2026-09-12', '2026-09-13'];
  const now = new Date(2026, 8, 13, 8, 0, 0);
  assert.strictEqual(calculateHabitStreak(dailyHabit, dates, now), 2);
});

test('calculateCompletionRate uses scheduled days only', () => {
  const dates = ['2026-09-08', '2026-09-09', '2026-09-10', '2026-09-11', '2026-09-14'];
  const now = new Date(2026, 8, 14, 8, 0, 0); // Monday
  const rate = calculateCompletionRate(weekdayHabit, dates, now, 5);
  assert.strictEqual(rate, 100);
});

test('recalculateHabitStats updates database', () => {
  const dbPath = path.join(os.tmpdir(), `ilrs-hstreak-${Date.now()}.db`);
  const db = new Database(dbPath);
  db.exec(`
    CREATE TABLE habits (id TEXT PRIMARY KEY, frequency TEXT, streak INTEGER, best_streak INTEGER, completion_rate REAL, last_completed TEXT, created_at TEXT);
    CREATE TABLE habit_logs (id TEXT PRIMARY KEY, habit_id TEXT, log_date TEXT, completed INTEGER);
  `);
  db.prepare("INSERT INTO habits (id, frequency, streak, best_streak, completion_rate) VALUES ('h1', 'daily', 0, 0, 0)").run();
  db.prepare("INSERT INTO habit_logs (id, habit_id, log_date, completed) VALUES ('a', 'h1', '2026-09-12', 1)").run();
  db.prepare("INSERT INTO habit_logs (id, habit_id, log_date, completed) VALUES ('b', 'h1', '2026-09-13', 1)").run();

  const habit = db.prepare('SELECT * FROM habits WHERE id = ?').get('h1');
  const stats = recalculateHabitStats(db, habit, new Date(2026, 8, 13, 9, 0, 0));
  assert.strictEqual(stats.streak, 2);

  const row = db.prepare('SELECT streak, completion_rate FROM habits WHERE id = ?').get('h1');
  assert.strictEqual(row.streak, 2);
  assert.ok(row.completion_rate > 0);

  db.close();
  fs.unlinkSync(dbPath);
});

console.log('\nHabit streak tests finished.');
