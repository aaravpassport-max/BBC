/**
 * Stage changes and stage-based reminder scheduling for tasks/reminders.
 */
const { randomUUID } = require('crypto');
const { localDateStr, localTimeStr, toLocalISO, computeNextFire } = require('./alarm');
const { getWorkflowStage, loadWorkflowStages } = require('./workflow-stage-store');
const { defaultStageKey } = require('./workflow-stage-pipeline');
function cancelInquiryStageReminders(db, inquiryId) {
  db.prepare(`
    UPDATE reminders SET status = 'deleted', updated_at = datetime('now')
    WHERE source_type = 'inquiry' AND source_id = ? AND status != 'deleted'
  `).run(inquiryId);
}

function computeStageFireTime(automation, now = new Date(), fallbackTime = '09:00') {
  if (!automation?.reminderEnabled && !automation?.followUpDays && !automation?.followUpHours && !automation?.followUpMinutes) {
    return null;
  }
  const mins = (parseInt(automation.followUpMinutes, 10) || 0)
    + (parseInt(automation.followUpHours, 10) || 0) * 60
    + (parseInt(automation.followUpDays, 10) || 0) * 24 * 60;
  if (mins <= 0) return null;
  const fire = new Date(now.getTime() + mins * 60000);
  return {
    dateStr: localDateStr(fire),
    timeStr: localTimeStr(fire) || fallbackTime,
  };
}

function applyReminderStageSchedule(db, item, stage, options = {}, now = new Date()) {
  if (!item || Number(item.stage_reminder_disabled) === 1) return null;
  const automation = { ...(stage?.automation || {}), ...options.automation };
  if (options.disableStageReminder) return null;

  let dateStr = options.manualDate || options.nextFollowUp;
  let timeStr = options.manualTime || options.nextFollowUpTime || item.reminder_time || '09:00';

  if (!dateStr) {
    const computed = computeStageFireTime(automation, now, timeStr);
    if (!computed) return null;
    dateStr = computed.dateStr;
    timeStr = computed.timeStr;
  }

  const nextFire = computeNextFire(
    dateStr,
    timeStr,
    item.repeat_type || 'once',
    now,
    item.repeat_value,
  );

  const updates = {
    next_fire: nextFire,
    start_date: dateStr,
    reminder_time: timeStr,
    workflow_status: stage?.closed ? 'done' : (item.task_type === 'task' ? 'pending' : 'pending'),
    status: stage?.closed ? 'completed' : 'active',
  };

  if (automation.reminderTitle) {
    updates.title = automation.reminderTitle.replace('{title}', item.title || '');
  }

  db.prepare(`
    UPDATE reminders SET next_fire = ?, start_date = ?, reminder_time = ?,
      workflow_status = ?, status = ?, stage_changed_at = datetime('now'), updated_at = ?
    WHERE id = ?
  `).run(
    updates.next_fire,
    updates.start_date,
    updates.reminder_time,
    updates.workflow_status,
    updates.status,
    toLocalISO(now),
    item.id,
  );

  return { nextFire, dateStr, timeStr };
}

function changeReminderStage(db, itemId, stageKey, options = {}, now = new Date()) {
  const item = db.prepare('SELECT * FROM reminders WHERE id = ? AND status != ?').get(itemId, 'deleted');
  if (!item) return { success: false, error: 'Item not found' };

  const entityType = item.task_type === 'task' ? 'task' : 'reminder';
  const stage = getWorkflowStage(db, entityType, stageKey);
  if (!stage) return { success: false, error: 'Invalid stage' };

  if (options.disableStageReminder) {
    db.prepare('UPDATE reminders SET stage_reminder_disabled = 1 WHERE id = ?').run(itemId);
  } else if (options.enableStageReminder) {
    db.prepare('UPDATE reminders SET stage_reminder_disabled = 0 WHERE id = ?').run(itemId);
  }

  db.prepare(`
    UPDATE reminders SET stage_key = ?, stage_changed_at = datetime('now'), updated_at = ?
    WHERE id = ?
  `).run(stageKey, toLocalISO(now), itemId);

  const refreshed = db.prepare('SELECT * FROM reminders WHERE id = ?').get(itemId);

  if (stage.closed) {
    db.prepare(`
      UPDATE reminders SET status = 'completed', workflow_status = 'done', alarm_rings = 0, updated_at = ?
      WHERE id = ?
    `).run(toLocalISO(now), itemId);
  } else if (refreshed.status === 'completed') {
    db.prepare(`
      UPDATE reminders SET status = 'active', workflow_status = 'pending', last_completed = NULL, updated_at = ?
      WHERE id = ?
    `).run(toLocalISO(now), itemId);
  }

  const schedule = applyReminderStageSchedule(
    db,
    db.prepare('SELECT * FROM reminders WHERE id = ?').get(itemId),
    stage,
    options,
    now,
  );

  const updated = db.prepare('SELECT * FROM reminders WHERE id = ?').get(itemId);
  const { maybeSyncEntity } = require('./sync-hook');
  maybeSyncEntity(db, 'reminder', itemId);
  return { success: true, item: updated, stage, schedule };
}

function setInitialReminderStage(db, itemId, entityType, stageKey) {
  const key = stageKey || defaultStageKey(entityType);
  db.prepare(`
    UPDATE reminders SET stage_key = ?, stage_changed_at = datetime('now') WHERE id = ?
  `).run(key, itemId);
  const { maybeSyncEntity } = require('./sync-hook');
  maybeSyncEntity(db, 'reminder', itemId);
}

module.exports = {
  cancelInquiryStageReminders,
  applyReminderStageSchedule,
  changeReminderStage,
  setInitialReminderStage,
  computeStageFireTime,
  loadWorkflowStages,
};
