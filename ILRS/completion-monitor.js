/**
 * Expected completion date monitoring + follow-up scheduling.
 */
const { randomUUID } = require('crypto');
const { localDateStr, toLocalISO, computeNextFire } = require('./alarm');
const {
  effectiveCompletionDate,
  isWorkItemActive,
  isCompletionDueToday,
  isCompletionOverdue,
} = require('./work-scheduling');
const { completeOccurrence, postponeReminder } = require('./reminder-actions');
const { logActivity } = require('./inquiry-actions');

function getSetting(db, key, defaultValue = '') {
  try {
    const row = db.prepare('SELECT value FROM settings WHERE key = ?').get(key);
    return row ? row.value : defaultValue;
  } catch {
    return defaultValue;
  }
}

function setSetting(db, key, value) {
  db.prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)').run(key, String(value));
}

function loadCompletionAlerted(db, today) {
  const raw = getSetting(db, 'completion_alerts_sent_date', '');
  if (raw !== today) return new Set();
  try {
    return new Set(JSON.parse(getSetting(db, 'completion_alerts_sent_keys', '[]')));
  } catch {
    return new Set();
  }
}

function saveCompletionAlerted(db, today, keys) {
  setSetting(db, 'completion_alerts_sent_date', today);
  setSetting(db, 'completion_alerts_sent_keys', JSON.stringify([...keys]));
}

function checkCompletionDueAlerts(db, dispatchDue, now = new Date()) {
  if (!db || getSetting(db, 'completion_alerts_enabled', '1') !== '1') {
    return { alerted: 0 };
  }

  const today = localDateStr(now);
  const alerted = loadCompletionAlerted(db, today);
  let count = 0;

  const reminders = db.prepare(`
    SELECT * FROM reminders
    WHERE status = 'active' AND expected_completion_date != ''
    AND (workflow_status IS NULL OR workflow_status != 'done')
  `).all();

  for (const item of reminders) {
    if (!isCompletionDueToday(item, now) && !isCompletionOverdue(item, now)) continue;
    const key = `reminder:${item.id}:${today}`;
    if (alerted.has(key)) continue;
    dispatchDue({
      ...item,
      _type: 'completion_check',
      _completionDue: true,
      _overdue: isCompletionOverdue(item, now),
      title: item.title,
      why_it_matters: isCompletionOverdue(item, now)
        ? `Expected completion was ${effectiveCompletionDate(item)} — is this done?`
        : `Expected completion is today (${effectiveCompletionDate(item)}) — is this done?`,
      priority: isCompletionOverdue(item, now) ? 'critical' : 'important',
      alert_style: 'sound-popup',
    }, 'completion_check');
    alerted.add(key);
    count += 1;
  }

  const inquiries = db.prepare(`
    SELECT * FROM inquiries WHERE outcome_status = 'active' AND expected_completion_date != ''
  `).all();

  for (const inq of inquiries) {
    if (!isCompletionDueToday(inq, now) && !isCompletionOverdue(inq, now)) continue;
    const key = `inquiry:${inq.id}:${today}`;
    if (alerted.has(key)) continue;
    dispatchDue({
      ...inq,
      _type: 'completion_check',
      _completionDue: true,
      _overdue: isCompletionOverdue(inq, now),
      title: `${inq.client_name} — ${inq.requirement}`,
      why_it_matters: isCompletionOverdue(inq, now)
        ? `Expected completion was ${effectiveCompletionDate(inq)}`
        : `Expected completion is today`,
      priority: isCompletionOverdue(inq, now) ? 'critical' : 'important',
      inquiry_id: inq.id,
    }, 'completion_check');
    alerted.add(key);
    count += 1;
  }

  saveCompletionAlerted(db, today, alerted);
  return { alerted: count };
}

function scheduleReminderFollowUp(db, itemId, { date, time, note } = {}, now = new Date()) {
  const item = db.prepare('SELECT * FROM reminders WHERE id = ?').get(itemId);
  if (!item) return { success: false, error: 'Item not found' };
  const dateStr = date || localDateStr(now);
  const timeStr = time || item.reminder_time || '09:00';
  postponeReminder(db, itemId, dateStr, timeStr, now);
  if (note) {
    db.prepare('UPDATE reminders SET notes = ?, updated_at = ? WHERE id = ?')
      .run(note, toLocalISO(now), itemId);
  }
  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp, note)
    VALUES (?, ?, 'follow_up_scheduled', datetime('now'), ?)
  `).run(randomUUID(), itemId, note || `Follow-up ${dateStr} ${timeStr}`);
  return { success: true };
}

function rescheduleCompletionDate(db, { type, id, date, followUpDate, followUpTime, note }, now = new Date()) {
  if (!id || !date) return { success: false, error: 'Missing id or date' };
  if (type === 'inquiry') {
    db.prepare(`
      UPDATE inquiries SET expected_completion_date = ?, updated_at = datetime('now')
      WHERE id = ?
    `).run(date, id);
    if (followUpDate) {
      db.prepare(`
        UPDATE inquiries SET next_follow_up = ?, next_follow_up_time = ?, updated_at = datetime('now')
        WHERE id = ?
      `).run(followUpDate, followUpTime || '11:00', id);
    }
    logActivity(db, id, 'follow_up', 'Completion rescheduled', note || date, {});
    return { success: true };
  }
  db.prepare(`
    UPDATE reminders SET expected_completion_date = ?, updated_at = ?
    WHERE id = ?
  `).run(date, toLocalISO(now), id);
  if (followUpDate) {
    postponeReminder(db, id, followUpDate, followUpTime || '09:00', now);
  }
  db.prepare(`
    INSERT INTO reminder_logs (id, reminder_id, action, timestamp, note)
    VALUES (?, ?, 'completion_rescheduled', datetime('now'), ?)
  `).run(randomUUID(), id, note || date);
  return { success: true };
}

function handleCompletionAction(db, { type, id, action, date, time, note }, now = new Date()) {
  if (!id || !action) return { success: false, error: 'Missing parameters' };
  if (action === 'complete') {
    if (type === 'inquiry') {
      const inq = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(id);
      if (!inq) return { success: false, error: 'Not found' };
      const { changeInquiryStage } = require('./inquiry-actions');
      return changeInquiryStage(db, id, 'delivered', { note: note || 'Marked complete' }, now);
    }
    return completeOccurrence(db, id, now);
  }
  if (action === 'follow_up') {
    const followDate = date || localDateStr(now);
    if (type === 'inquiry') {
      db.prepare(`
        UPDATE inquiries SET next_follow_up = ?, next_follow_up_time = ?, next_action = ?,
          updated_at = datetime('now')
        WHERE id = ?
      `).run(followDate, time || '11:00', note || 'Follow up — not completed', id);
      logActivity(db, id, 'follow_up', 'Not completed — follow-up scheduled', `${followDate} ${time || ''}`, {});
      return { success: true };
    }
    return scheduleReminderFollowUp(db, id, { date: followDate, time, note }, now);
  }
  if (action === 'reschedule') {
    return rescheduleCompletionDate(db, { type, id, date, followUpDate: date, followUpTime: time, note }, now);
  }
  return { success: false, error: 'Unknown action' };
}

module.exports = {
  checkCompletionDueAlerts,
  scheduleReminderFollowUp,
  rescheduleCompletionDate,
  handleCompletionAction,
};
