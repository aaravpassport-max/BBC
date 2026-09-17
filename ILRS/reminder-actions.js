/**
 * Shared reminder actions for renderer (via IPC) and main-process notification buttons.
 */
const { randomUUID } = require('crypto');
const { toLocalISO, advanceRecurring, ALARM_MAX_RINGS, isDue } = require('./alarm');
const {
  LIFECYCLE_COMPLETED,
  LIFECYCLE_ACTIVE,
  normalizeLifecycle,
  reminderRowPatchForLifecycle,
} = require('./work-lifecycle');

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
      SET status = 'completed', workflow_status = 'done', lifecycle_status = ?, alarm_rings = 0,
          next_fire = '', last_completed = ?, updated_at = ?
      WHERE id = ?
    `).run(LIFECYCLE_COMPLETED, firedAt, firedAt, id);
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
          lifecycle_status = ?, last_completed = ?, updated_at = ?
      WHERE id = ?
    `).run(nextFire, LIFECYCLE_ACTIVE, firedAt, firedAt, id);
    db.prepare(`
      INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
      VALUES (?, ?, 'completed_occurrence', datetime('now'))
    `).run(randomUUID(), id);
    return { success: true, recurring: true, nextFire };
  }

  db.prepare(`
    UPDATE reminders
    SET status = 'completed', workflow_status = 'done', lifecycle_status = ?, alarm_rings = 0,
        next_fire = '', last_completed = ?, updated_at = ?
    WHERE id = ?
  `).run(LIFECYCLE_COMPLETED, firedAt, firedAt, id);
  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
    VALUES (?, ?, 'completed', datetime('now'))
  `).run(randomUUID(), id);
  return { success: true, recurring: false };
}

/**
 * Snooze a reminder by N minutes (unlimited).
 */
function snoozeReminder(db, id, minutes, now = new Date()) {
  const reminder = getReminder(db, id);
  if (!reminder) return { success: false, error: 'Reminder not found' };
  if (reminder.status === 'deleted') return { success: false, error: 'Reminder deleted' };

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
  if (reminder.status === 'deleted') return { success: false, error: 'Reminder deleted' };

  const { computeNextFire } = require('./alarm');
  const resolvedTime = timeStr || reminder.reminder_time || '09:00';
  const nextFire = computeNextFire(
    dateStr,
    resolvedTime,
    reminder.repeat_type || 'once',
    now,
    reminder.repeat_value,
  );

  const wasCompleted = reminder.status === 'completed' || reminder.workflow_status === 'done';
  const workflowStatus = wasCompleted ? 'pending' : 'postponed';

  db.prepare(`
    UPDATE reminders
    SET start_date = ?, reminder_time = ?, next_fire = ?, alarm_rings = 0, snooze_count = 0,
        status = 'active', workflow_status = ?, last_completed = NULL, updated_at = ?
    WHERE id = ?
  `).run(dateStr, resolvedTime, nextFire, workflowStatus, toLocalISO(now), id);
  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
    VALUES (?, ?, 'postponed', datetime('now'))
  `).run(randomUUID(), id);

  return { success: true, nextFire };
}

function deleteReminder(db, id, now = new Date()) {
  const reminder = getReminder(db, id);
  if (!reminder) return { success: false, error: 'Reminder not found' };
  db.prepare(`
    UPDATE reminders SET status = 'deleted', updated_at = ? WHERE id = ?
  `).run(toLocalISO(now), id);
  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
    VALUES (?, ?, 'deleted', datetime('now'))
  `).run(randomUUID(), id);
  return { success: true };
}

function bulkDeleteReminders(db, ids, now = new Date()) {
  if (!Array.isArray(ids) || ids.length === 0) {
    return { success: false, error: 'No items selected' };
  }
  let deleted = 0;
  for (const id of ids) {
    const result = deleteReminder(db, id, now);
    if (result.success) deleted += 1;
  }
  return { success: true, deleted };
}

function parseNotificationAction(response) {
  if (response == null) return null;
  const value = String(response).toLowerCase().trim();
  if (!value || value === 'timedout') return null;
  if (value === 'dismissed' || value.includes('dismiss')) return 'dismiss';
  if (value === 'done' || value.includes('done') || value.includes('complete')) return 'done';
  if (value.includes('snooze') || value.includes('later')) return 'snooze';
  if (value.includes('tomorrow')) return 'tomorrow';
  return null;
}

/**
 * Stop repeat alerts for the current occurrence without completing the reminder.
 * Keeps the item overdue in lists but suppresses further beeps until snooze/done.
 */
function acknowledgeReminder(db, id, now = new Date()) {
  const reminder = getReminder(db, id);
  if (!reminder) return { success: false, error: 'Reminder not found' };
  if (reminder.status === 'deleted') return { success: false, error: 'Reminder deleted' };

  db.prepare(`
    UPDATE reminders
    SET alarm_rings = ?, missed_count = COALESCE(missed_count, 0) + 1, updated_at = ?
    WHERE id = ?
  `).run(ALARM_MAX_RINGS, toLocalISO(now), id);
  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
    VALUES (?, ?, 'acknowledged', datetime('now'))
  `).run(randomUUID(), id);

  return { success: true };
}

function setReminderLifecycle(db, id, lifecycle, now = new Date()) {
  const reminder = getReminder(db, id);
  if (!reminder) return { success: false, error: 'Reminder not found' };
  if (reminder.status === 'deleted') return { success: false, error: 'Reminder deleted' };

  const lc = normalizeLifecycle(lifecycle);
  const patch = reminderRowPatchForLifecycle(lc);
  const nextFire = patch.clear_schedule ? '' : (reminder.next_fire || '');
  const status = patch.status || reminder.status;
  const workflow = patch.workflow_status || reminder.workflow_status;

  db.prepare(`
    UPDATE reminders SET
      lifecycle_status = ?,
      status = ?,
      workflow_status = ?,
      next_fire = ?,
      alarm_rings = 0,
      updated_at = ?
    WHERE id = ?
  `).run(patch.lifecycle_status, status, workflow, nextFire, toLocalISO(now), id);

  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp)
    VALUES (?, ?, ?, datetime('now'))
  `).run(randomUUID(), id, `lifecycle_${lc}`);

  try {
    const { mirrorInquiryLifecycleFromReminder } = require('./inquiry-actions');
    mirrorInquiryLifecycleFromReminder(db, id, lc);
  } catch (err) {
    console.error('mirrorInquiryLifecycleFromReminder:', err.message);
  }

  return { success: true, lifecycle: lc, nextFire };
}

function dismissAllRingingReminders(db, now = new Date()) {
  const rows = db.prepare(`
    SELECT id, next_fire, alarm_rings FROM reminders
    WHERE status = 'active'
    AND (source_type IS NULL OR source_type = '')
    AND next_fire != ''
  `).all();

  let count = 0;
  for (const row of rows) {
    const ringing = Number(row.alarm_rings || 0) > 0;
    const overdue = row.next_fire && isDue(row.next_fire, now);
    if (!ringing && !overdue) continue;
    const result = acknowledgeReminder(db, row.id, now);
    if (result.success) count += 1;
  }
  return { success: true, count };
}

module.exports = {
  completeOccurrence,
  snoozeReminder,
  postponeReminder,
  deleteReminder,
  bulkDeleteReminders,
  updateWorkflowStatus,
  acknowledgeReminder,
  dismissAllRingingReminders,
  setReminderLifecycle,
  parseNotificationAction,
  getSetting,
};
