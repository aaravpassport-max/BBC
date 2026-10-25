/**
 * Habit streak and completion-rate calculations from habit_logs.
 */
const { localDateStr } = require('./alarm');

function getWeeklyAnchorDay(habit) {
  if (habit.created_at) {
    const raw = String(habit.created_at).trim().replace(' ', 'T');
    const d = new Date(raw);
    if (!Number.isNaN(d.getTime())) return d.getDay();
  }
  return 1;
}

function isHabitScheduledOnDate(habit, date) {
  const freq = habit.frequency || 'daily';
  const day = date.getDay();
  if (freq === 'daily') return true;
  if (freq === 'weekdays') return day >= 1 && day <= 5;
  if (freq === 'weekends') return day === 0 || day === 6;
  if (freq === 'weekly') return day === getWeeklyAnchorDay(habit);
  return true;
}

function dateAtNoon(date) {
  const d = new Date(date);
  d.setHours(12, 0, 0, 0);
  return d;
}

function calculateHabitStreak(habit, completedDateStrings, referenceDate = new Date()) {
  const completed = new Set(completedDateStrings);
  let streak = 0;
  const cursor = dateAtNoon(referenceDate);

  for (let guard = 0; guard < 400; guard++) {
    if (!isHabitScheduledOnDate(habit, cursor)) {
      cursor.setDate(cursor.getDate() - 1);
      continue;
    }
    const key = localDateStr(cursor);
    if (!completed.has(key)) break;
    streak++;
    cursor.setDate(cursor.getDate() - 1);
  }

  return streak;
}

function calculateCompletionRate(habit, completedDateStrings, referenceDate = new Date(), scheduledWindow = 30) {
  const completed = new Set(completedDateStrings);
  let scheduled = 0;
  let done = 0;
  const cursor = dateAtNoon(referenceDate);

  for (let guard = 0; guard < scheduledWindow * 3 && scheduled < scheduledWindow; guard++) {
    if (isHabitScheduledOnDate(habit, cursor)) {
      scheduled++;
      if (completed.has(localDateStr(cursor))) done++;
    }
    cursor.setDate(cursor.getDate() - 1);
  }

  if (scheduled === 0) return 0;
  return Math.round((done / scheduled) * 100);
}

function getHabitCompletedDates(db, habitId) {
  return db.prepare(`
    SELECT log_date FROM habit_logs
    WHERE habit_id = ? AND completed = 1
    ORDER BY log_date DESC
  `).all(habitId).map((row) => row.log_date);
}

function recalculateHabitStats(db, habit, now = new Date()) {
  const dates = getHabitCompletedDates(db, habit.id);
  const streak = calculateHabitStreak(habit, dates, now);
  const bestStreak = Math.max(streak, Number(habit.best_streak) || 0);
  const completionRate = calculateCompletionRate(habit, dates, now);
  const lastCompleted = dates[0] || habit.last_completed || '';

  db.prepare(`
    UPDATE habits
    SET streak = ?, best_streak = ?, completion_rate = ?, last_completed = ?
    WHERE id = ?
  `).run(streak, Math.max(bestStreak, streak), completionRate, lastCompleted, habit.id);

  try {
    const { maybeSyncEntity } = require('./sync-hook');
    maybeSyncEntity(db, 'habit', habit.id);
  } catch (_) { /* sync optional in tests */ }

  return { streak, bestStreak: Math.max(bestStreak, streak), completionRate, lastCompleted };
}

function recalculateAllHabitStreaks(db, now = new Date()) {
  const habits = db.prepare("SELECT * FROM habits WHERE status = 'active'").all();
  for (const habit of habits) {
    recalculateHabitStats(db, habit, now);
  }
}

module.exports = {
  getWeeklyAnchorDay,
  isHabitScheduledOnDate,
  calculateHabitStreak,
  calculateCompletionRate,
  getHabitCompletedDates,
  recalculateHabitStats,
  recalculateAllHabitStreaks,
};
