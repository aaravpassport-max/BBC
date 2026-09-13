/**
 * Shared Life-module actions for renderer (IPC) and main-process notifications.
 */
const { randomUUID } = require('crypto');
const { localDateStr, localTimeStr } = require('./alarm');
const { recalculateHabitStats } = require('./habit-streak');

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
  const existing = db.prepare(`
    SELECT id FROM habit_logs WHERE habit_id = ? AND log_date = ? AND completed = 1
  `).get(habitId, today);
  if (existing) {
    const stats = recalculateHabitStats(db, habit, now);
    return { success: true, habitId, streak: stats.streak, alreadyLogged: true };
  }

  const existingRow = db.prepare(`
    SELECT id FROM habit_logs WHERE habit_id = ? AND log_date = ?
  `).get(habitId, today);
  const logId = existingRow?.id || randomUUID();
  db.prepare(`
    INSERT OR REPLACE INTO habit_logs (id, habit_id, log_date, completed)
    VALUES (?, ?, ?, 1)
  `).run(logId, habitId, today);

  const stats = recalculateHabitStats(db, habit, now);
  return { success: true, habitId, streak: stats.streak, completionRate: stats.completionRate };
}

module.exports = {
  markMedicineDoseTaken,
  markBillPaidAction,
  logHabitAction,
};
