/**
 * Shared Life-module actions for renderer (IPC) and main-process notifications.
 */
const { randomUUID } = require('crypto');
const { localDateStr, localTimeStr } = require('./alarm');

function markMedicineDoseTaken(db, medId, doseTime, now = new Date()) {
  const med = db.prepare('SELECT * FROM medicines WHERE id = ?').get(medId);
  if (!med) return { success: false, error: 'Medicine not found' };

  const time = doseTime || localTimeStr(now);
  const today = localDateStr(now);
  const logId = randomUUID();

  db.prepare(`
    INSERT OR REPLACE INTO medicine_logs
    (id, medicine_id, dose_time, scheduled_time, status, taken_at, log_date)
    VALUES (?, ?, ?, ?, 'taken', ?, ?)
  `).run(logId, medId, time, time, now.toISOString(), today);

  return { success: true, medId, doseTime: time };
}

function markBillPaidAction(db, billId, now = new Date()) {
  const bill = db.prepare('SELECT * FROM bills WHERE id = ?').get(billId);
  if (!bill) return { success: false, error: 'Bill not found' };

  const logId = randomUUID();
  const paidDate = localDateStr(now);
  const amount = Number(bill.amount) || 0;

  db.prepare(`
    INSERT INTO bill_history (id, bill_id, paid_date, amount)
    VALUES (?, ?, ?, ?)
  `).run(logId, billId, paidDate, amount);
  db.prepare(`UPDATE bills SET payment_status = 'paid' WHERE id = ?`).run(billId);

  return { success: true, billId };
}

function logHabitAction(db, habitId, now = new Date()) {
  const habit = db.prepare('SELECT * FROM habits WHERE id = ?').get(habitId);
  if (!habit) return { success: false, error: 'Habit not found' };

  const today = localDateStr(now);
  const logId = randomUUID();

  db.prepare(`
    INSERT OR REPLACE INTO habit_logs (id, habit_id, log_date, completed)
    VALUES (?, ?, ?, 1)
  `).run(logId, habitId, today);

  const newStreak = (Number(habit.streak) || 0) + 1;
  const bestStreak = Math.max(newStreak, Number(habit.best_streak) || 0);
  db.prepare(`
    UPDATE habits
    SET streak = ?, best_streak = ?, last_completed = ?,
        completion_rate = MIN(100, completion_rate + 3)
    WHERE id = ?
  `).run(newStreak, bestStreak, today, habitId);

  return { success: true, habitId, streak: newStreak };
}

module.exports = {
  markMedicineDoseTaken,
  markBillPaidAction,
  logHabitAction,
};
