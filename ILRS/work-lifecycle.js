/**
 * Work lifecycle — stage (workflow position) vs status (active/pending/completed/closed).
 */
const LIFECYCLE_ACTIVE = 'active';
const LIFECYCLE_PENDING = 'pending';
const LIFECYCLE_COMPLETED = 'completed';
const LIFECYCLE_CLOSED = 'closed';

const LIFECYCLE_STATUSES = [
  { id: LIFECYCLE_ACTIVE, label: 'Active / In Progress' },
  { id: LIFECYCLE_PENDING, label: 'Pending / On Hold' },
  { id: LIFECYCLE_COMPLETED, label: 'Completed / Done' },
  { id: LIFECYCLE_CLOSED, label: 'Closed' },
];

function normalizeLifecycle(value) {
  const v = String(value || '').trim().toLowerCase();
  if ([LIFECYCLE_ACTIVE, LIFECYCLE_PENDING, LIFECYCLE_COMPLETED, LIFECYCLE_CLOSED].includes(v)) return v;
  return LIFECYCLE_ACTIVE;
}

function inferLifecycleFromReminder(reminder) {
  if (!reminder) return LIFECYCLE_ACTIVE;
  if (reminder.lifecycle_status) return normalizeLifecycle(reminder.lifecycle_status);
  if (reminder.status === 'deleted') return LIFECYCLE_CLOSED;
  if (reminder.status === 'completed' || (reminder.workflow_status || '') === 'done') {
    return LIFECYCLE_COMPLETED;
  }
  if ((reminder.workflow_status || '') === 'postponed') return LIFECYCLE_PENDING;
  if ((reminder.workflow_status || '') === 'in_progress') return LIFECYCLE_ACTIVE;
  return LIFECYCLE_ACTIVE;
}

function inferLifecycleFromInquiry(inquiry) {
  if (!inquiry) return LIFECYCLE_ACTIVE;
  const stored = String(inquiry.lifecycle_status ?? '').trim();
  if (stored) return normalizeLifecycle(stored);
  if (inquiry.outcome_status === 'deleted') return LIFECYCLE_CLOSED;
  if (inquiry.outcome_status === 'closed_lost') return LIFECYCLE_CLOSED;
  if (inquiry.outcome_status === 'closed_won') return LIFECYCLE_COMPLETED;
  if (inquiry.outcome_status && inquiry.outcome_status !== 'active') return LIFECYCLE_CLOSED;
  return LIFECYCLE_ACTIVE;
}

function lifecycleLabel(id) {
  return LIFECYCLE_STATUSES.find((s) => s.id === id)?.label || id;
}

function isLifecycleSchedulable(lifecycle) {
  return lifecycle === LIFECYCLE_ACTIVE || lifecycle === LIFECYCLE_PENDING;
}

function requiresNextReminderDate(lifecycle, { scheduleNext = false } = {}) {
  if (lifecycle === LIFECYCLE_ACTIVE) return true;
  if (lifecycle === LIFECYCLE_PENDING) return false;
  if (lifecycle === LIFECYCLE_COMPLETED) return Boolean(scheduleNext);
  return false;
}

function blocksNextReminder(lifecycle) {
  return lifecycle === LIFECYCLE_CLOSED;
}

function isReminderSchedulableByLifecycle(reminder) {
  const lc = inferLifecycleFromReminder(reminder);
  if (lc === LIFECYCLE_CLOSED) return false;
  if (lc === LIFECYCLE_COMPLETED) {
    return Boolean(String(reminder.next_fire || '').trim());
  }
  return true;
}

function isInquiryActiveForWorkQueue(inquiry) {
  const lc = inferLifecycleFromInquiry(inquiry);
  return lc === LIFECYCLE_ACTIVE || lc === LIFECYCLE_PENDING;
}

function isReminderOpenForWork(reminder) {
  const lc = inferLifecycleFromReminder(reminder);
  return lc === LIFECYCLE_ACTIVE || lc === LIFECYCLE_PENDING;
}

function shouldMaintainInquiryFollowUp(inquiry) {
  if (!inquiry || inquiry.outcome_status === 'deleted') return false;
  const lc = inferLifecycleFromInquiry(inquiry);
  if (blocksNextReminder(lc)) return false;
  if (!String(inquiry.next_follow_up || '').trim()) return false;
  if (lc === LIFECYCLE_COMPLETED) return true;
  return isLifecycleSchedulable(lc);
}

function reminderRowPatchForLifecycle(lifecycle) {
  const lc = normalizeLifecycle(lifecycle);
  if (lc === LIFECYCLE_CLOSED || lc === LIFECYCLE_COMPLETED) {
    return {
      lifecycle_status: lc,
      status: 'completed',
      workflow_status: 'done',
      next_fire: '',
      alarm_rings: 0,
      clear_schedule: true,
    };
  }
  if (lc === LIFECYCLE_PENDING) {
    return {
      lifecycle_status: lc,
      status: 'active',
      workflow_status: 'postponed',
    };
  }
  return {
    lifecycle_status: lc,
    status: 'active',
    workflow_status: 'in_progress',
  };
}

function inquiryRowPatchForLifecycle(lifecycle, { scheduleNext = false } = {}) {
  const lc = normalizeLifecycle(lifecycle);
  if (lc === LIFECYCLE_CLOSED) {
    return {
      lifecycle_status: lc,
      outcome_status: 'closed_lost',
      next_follow_up: '',
      next_follow_up_time: '',
      clear_follow_up: true,
    };
  }
  if (lc === LIFECYCLE_COMPLETED && !scheduleNext) {
    return {
      lifecycle_status: lc,
      outcome_status: 'active',
      next_follow_up: '',
      next_follow_up_time: '',
      clear_follow_up: true,
    };
  }
  if (lc === LIFECYCLE_PENDING) {
    return { lifecycle_status: lc, outcome_status: 'active' };
  }
  return { lifecycle_status: lc, outcome_status: 'active' };
}

function backfillReminderLifecycle(db) {
  const rows = db.prepare("SELECT id, status, workflow_status, lifecycle_status FROM reminders WHERE status != 'deleted'").all();
  const upd = db.prepare('UPDATE reminders SET lifecycle_status = ? WHERE id = ?');
  let n = 0;
  for (const row of rows) {
    if (row.lifecycle_status) continue;
    upd.run(inferLifecycleFromReminder(row), row.id);
    n += 1;
  }
  return n;
}

function backfillInquiryLifecycle(db) {
  const rows = db.prepare('SELECT id, outcome_status, lifecycle_status FROM inquiries WHERE outcome_status != ?').all('deleted');
  const upd = db.prepare('UPDATE inquiries SET lifecycle_status = ? WHERE id = ?');
  let n = 0;
  for (const row of rows) {
    if (row.lifecycle_status) continue;
    upd.run(inferLifecycleFromInquiry(row), row.id);
    n += 1;
  }
  return n;
}

function clearInquiryFollowUpSchedule(db, inquiryId) {
  db.prepare(`
    UPDATE reminders SET status = 'deleted', updated_at = datetime('now')
    WHERE source_type = 'inquiry' AND source_id = ? AND status != 'deleted'
  `).run(inquiryId);
  db.prepare(`
    UPDATE inquiries SET next_follow_up = '', next_follow_up_time = '', updated_at = datetime('now')
    WHERE id = ?
  `).run(inquiryId);
}

module.exports = {
  LIFECYCLE_ACTIVE,
  LIFECYCLE_PENDING,
  LIFECYCLE_COMPLETED,
  LIFECYCLE_CLOSED,
  LIFECYCLE_STATUSES,
  normalizeLifecycle,
  inferLifecycleFromReminder,
  inferLifecycleFromInquiry,
  lifecycleLabel,
  isLifecycleSchedulable,
  requiresNextReminderDate,
  blocksNextReminder,
  reminderRowPatchForLifecycle,
  inquiryRowPatchForLifecycle,
  backfillReminderLifecycle,
  backfillInquiryLifecycle,
  clearInquiryFollowUpSchedule,
  isReminderSchedulableByLifecycle,
  isInquiryActiveForWorkQueue,
  isReminderOpenForWork,
  shouldMaintainInquiryFollowUp,
};
