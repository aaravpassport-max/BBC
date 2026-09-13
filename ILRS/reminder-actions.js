/**
 * Shared reminder actions for renderer (via IPC) and main-process notification buttons.
 */
const { randomUUID } = require('crypto');
const { toLocalISO, advanceRecurring } = require('./alarm');

function getSetting(db, key, defaultValue = '') {
  if (!db) return defaultValue;
  try {
    const row = db.prepare('SELECT value FROM settings WHERE key = ?').get(key);
    return row ? row.value : defaultValue;
  } catch {
    return defaultValue;
  }
}

function getReminder(db, id) {
  return db.prepare('SELECT * FROM reminders WHERE id = ?').get(id);
}

/**
 * Mark one occurrence done. Recurring series stays active with next_fire advanced.
 */
function updateWorkflowStatus(db, id, workflowStatus, now = new Date()) {
  const reminder = getReminder(db, id);
  if (!reminder) return { success: false, error: 'Reminder not found' };

  db.prepare(`
    UPDATE reminders SET workflow_status = ?, updated_at = ? WHERE id = ?
  `).run(workflowStatus, toLocalISO(now), id);
  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
    VALUES (?, ?, ?, datetime('now'))
  `).run(randomUUID(), id, workflowStatus);

  return { success: true, workflowStatus };
}

function completeOccurrence(db, id, now = new Date()) {
  const reminder = getReminder(db, id);
  if (!reminder) return { success: false, error: 'Reminder not found' };
  if (reminder.status === 'deleted') return { success: false, error: 'Reminder deleted' };

  const firedAt = toLocalISO(now);
  const repeat = reminder.repeat_type || 'once';
  const isTask = reminder.task_type === 'task';

  if (isTask) {
    db.prepare(`
      UPDATE reminders
      SET status = 'completed', workflow_status = 'done', alarm_rings = 0,
          last_completed = ?, updated_at = ?
      WHERE id = ?
    `).run(firedAt, firedAt, id);
    db.prepare(`
      INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
      VALUES (?, ?, 'completed', datetime('now'))
    `).run(randomUUID(), id);
    return { success: true, recurring: false, task: true };
  }

  if (repeat !== 'once') {
    const nextFire = advanceRecurring(reminder, now);
    db.prepare(`
      UPDATE reminders
      SET next_fire = ?, alarm_rings = 0, snooze_count = 0, workflow_status = 'pending',
          last_completed = ?, updated_at = ?
      WHERE id = ?
    `).run(nextFire, firedAt, firedAt, id);
    db.prepare(`
      INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
      VALUES (?, ?, 'completed_occurrence', datetime('now'))
    `).run(randomUUID(), id);
    return { success: true, recurring: true, nextFire };
  }

  db.prepare(`
    UPDATE reminders
    SET status = 'completed', workflow_status = 'done', alarm_rings = 0,
        last_completed = ?, updated_at = ?
    WHERE id = ?
  `).run(firedAt, firedAt, id);
  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
    VALUES (?, ?, 'completed', datetime('now'))
  `).run(randomUUID(), id);
  return { success: true, recurring: false };
}

/**
 * Snooze a reminder by N minutes. Enforces snooze_limit from settings.
 */
function snoozeReminder(db, id, minutes, now = new Date()) {
  const reminder = getReminder(db, id);
  if (!reminder) return { success: false, error: 'Reminder not found' };

  const limit = parseInt(getSetting(db, 'snooze_limit', '3'), 10) || 3;
  const count = parseInt(reminder.snooze_count, 10) || 0;
  if (count >= limit) {
    return { success: false, error: 'snooze_limit', limit };
  }

  const duration = minutes
    || parseInt(reminder.snooze_duration, 10)
    || parseInt(getSetting(db, 'snooze_duration', '10'), 10)
    || 10;

  const newFire = toLocalISO(new Date(now.getTime() + duration * 60000));
  db.prepare(`
    UPDATE reminders
    SET next_fire = ?, alarm_rings = 0, snooze_count = snooze_count + 1, updated_at = ?
    WHERE id = ?
  `).run(newFire, toLocalISO(now), id);
  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
    VALUES (?, ?, 'snoozed', datetime('now'))
  `).run(randomUUID(), id);

  return { success: true, minutes: duration, nextFire: newFire };
}

/**
 * Postpone to a specific date/time (resets snooze count).
 */
function postponeReminder(db, id, dateStr, timeStr, now = new Date()) {
  const reminder = getReminder(db, id);
  if (!reminder) return { success: false, error: 'Reminder not found' };

  const { computeNextFire } = require('./alarm');
  const nextFire = computeNextFire(
    dateStr,
    timeStr || reminder.reminder_time || '09:00',
    reminder.repeat_type || 'once',
    now,
  );

  db.prepare(`
    UPDATE reminders
    SET start_date = ?, reminder_time = ?, next_fire = ?, alarm_rings = 0, snooze_count = 0,
        workflow_status = 'postponed', updated_at = ?
    WHERE id = ?
  `).run(dateStr, timeStr || reminder.reminder_time, nextFire, toLocalISO(now), id);
  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
    VALUES (?, ?, 'postponed', datetime('now'))
  `).run(randomUUID(), id);

  return { success: true, nextFire };
}

function parseNotificationAction(response) {
  if (response == null) return null;
  const value = String(response).toLowerCase().trim();
  if (!value || value === 'dismissed' || value === 'timedout') return null;
  if (value === 'done' || value.includes('done') || value.includes('complete')) return 'done';
  if (value.includes('snooze') || value.includes('later')) return 'snooze';
  if (value.includes('tomorrow')) return 'tomorrow';
  return null;
}

module.exports = {
  completeOccurrence,
  snoozeReminder,
  postponeReminder,
  updateWorkflowStatus,
  parseNotificationAction,
  getSetting,
};
