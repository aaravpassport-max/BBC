/**
 * Inquiry lifecycle actions — create, stage change, activity log, follow-up reminders.
 */
const { randomUUID } = require('crypto');
const { localDateStr, localTimeStr, toLocalISO, computeNextFire } = require('./alarm');
const {
  DEFAULT_STAGES,
  getStage,
  isClosedStage,
  getStageAutomation,
} = require('./inquiry-pipeline');
const { loadStagesFromDb, saveAllStagesToDb } = require('./inquiry-stage-store');
const {
  normalizeLifecycle,
  inferLifecycleFromInquiry,
  inquiryRowPatchForLifecycle,
  reminderRowPatchForLifecycle,
  clearInquiryFollowUpSchedule,
  shouldMaintainInquiryFollowUp,
  LIFECYCLE_ACTIVE,
  LIFECYCLE_CLOSED,
  LIFECYCLE_COMPLETED,
} = require('./work-lifecycle');

function nextInquiryNumber(db) {
  const row = db.prepare("SELECT value FROM settings WHERE key = 'inquiry_counter'").get();
  const n = (parseInt(row?.value, 10) || 1000) + 1;
  db.prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('inquiry_counter', ?)").run(String(n));
  return `INQ-${n}`;
}

function findOrCreateClient(db, { name, company, mobile, email }) {
  const trimmedMobile = (mobile || '').trim();
  const trimmedEmail = (email || '').trim().toLowerCase();
  const trimmedName = (name || '').trim();

  if (trimmedMobile) {
    const byMobile = db.prepare('SELECT * FROM clients WHERE mobile = ? LIMIT 1').get(trimmedMobile);
    if (byMobile) return byMobile;
  }
  if (trimmedEmail) {
    const byEmail = db.prepare('SELECT * FROM clients WHERE lower(email) = ? LIMIT 1').get(trimmedEmail);
    if (byEmail) return byEmail;
  }

  const id = randomUUID();
  db.prepare(`
    INSERT INTO clients (id, name, company, mobile, email) VALUES (?, ?, ?, ?, ?)
  `).run(id, trimmedName, company || '', trimmedMobile, trimmedEmail);
  return { id, name: trimmedName, company: company || '', mobile: trimmedMobile, email: trimmedEmail };
}

function findPossibleDuplicates(db, { name, mobile, email, requirement }) {
  const results = [];
  const trimmedMobile = (mobile || '').trim();
  const trimmedEmail = (email || '').trim().toLowerCase();
  const trimmedName = (name || '').trim().toLowerCase();

  if (trimmedMobile) {
    db.prepare(`
      SELECT i.* FROM inquiries i
      WHERE i.mobile = ? AND i.outcome_status = 'active' LIMIT 5
    `).all(trimmedMobile).forEach((r) => results.push(r));
  }
  if (trimmedEmail) {
    db.prepare(`
      SELECT i.* FROM inquiries i
      WHERE lower(i.email) = ? AND i.outcome_status = 'active' LIMIT 5
    `).all(trimmedEmail).forEach((r) => {
      if (!results.find((x) => x.id === r.id)) results.push(r);
    });
  }
  if (trimmedName.length >= 3) {
    db.prepare(`
      SELECT i.* FROM inquiries i
      WHERE lower(i.client_name) LIKE ? AND i.outcome_status = 'active' LIMIT 5
    `).all(`%${trimmedName}%`).forEach((r) => {
      if (!results.find((x) => x.id === r.id)) results.push(r);
    });
  }
  return results.slice(0, 5);
}

function logActivity(db, inquiryId, activityType, title, body = '', meta = {}) {
  const id = randomUUID();
  db.prepare(`
    INSERT INTO inquiry_activities (id, inquiry_id, activity_type, title, body, old_stage_key, new_stage_key, metadata)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    id,
    inquiryId,
    activityType,
    title,
    body,
    meta.oldStage || '',
    meta.newStage || '',
    JSON.stringify(meta.extra || {}),
  );
  const summary = String(body || '').trim()
    ? `${title}: ${String(body).trim()}`.slice(0, 240)
    : String(title || '').slice(0, 240);
  db.prepare(`
    UPDATE inquiries SET last_activity_at = datetime('now'), updated_at = datetime('now'),
      last_activity_summary = ?
    WHERE id = ?
  `).run(summary, inquiryId);
  return id;
}

function computeInquiryHealth(inquiry, now = new Date()) {
  if (inquiry.outcome_status !== 'active') return 'closed';
  const followUp = inquiry.next_follow_up;
  const followTime = inquiry.next_follow_up_time || '09:00';
  if (followUp) {
    const due = new Date(`${followUp}T${followTime}:00`);
    if (due.getTime() < now.getTime() - 86400000) return 'at_risk';
    if (due.getTime() < now.getTime()) return 'needs_attention';
  }
  if (inquiry.last_activity_at) {
    const last = new Date(String(inquiry.last_activity_at).replace(' ', 'T'));
    const daysSince = (now - last) / 86400000;
    if (daysSince > 7) return 'stale';
    if (daysSince > 3) return 'needs_attention';
  }
  return 'healthy';
}

function syncInquiryFollowUpReminder(db, inquiryId, now = new Date()) {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry || inquiry.outcome_status === 'deleted') return;

  db.prepare(`
    UPDATE reminders SET status = 'deleted', updated_at = datetime('now')
    WHERE source_type = 'inquiry' AND source_id = ? AND status != 'deleted'
  `).run(inquiryId);

  if (!shouldMaintainInquiryFollowUp(inquiry)) return;

  createFollowUpReminder(db, inquiry, {
    title: inquiry.next_action ? `${inquiry.next_action}: ${inquiry.client_name}` : undefined,
    date: inquiry.next_follow_up,
    time: inquiry.next_follow_up_time || '09:00',
    now,
  });
}

function createFollowUpReminder(db, inquiry, { title, date, time, now = new Date() }) {
  const reminderId = randomUUID();
  const fireTime = time || '09:00';
  const startDate = date || localDateStr(now);
  const nextFire = computeNextFire(startDate, fireTime, 'once', now);
  const reminderTitle = title || `Follow up: ${inquiry.client_name}`;
  db.prepare(`
    INSERT INTO reminders (
      id, title, task_type, category, why_it_matters, repeat_type, reminder_time,
      start_date, priority, alert_style, assigned_to, source_type, source_id,
      next_fire, status, workflow_status, created_at, updated_at
    ) VALUES (?, ?, 'reminder', 'work', ?, 'once', ?, ?, 'important', 'sound-popup', ?, 'inquiry', ?, ?, 'active', 'pending', datetime('now'), datetime('now'))
  `).run(
    reminderId,
    reminderTitle,
    inquiry.requirement || '',
    fireTime,
    startDate,
    inquiry.assigned_to || 'me',
    inquiry.id,
    nextFire,
  );
  return reminderId;
}

/** When a follow-up reminder’s work status changes, keep the parent inquiry in sync for queue tabs. */
function mirrorInquiryLifecycleFromReminder(db, reminderId, lifecycle) {
  const row = db.prepare('SELECT source_type, source_id FROM reminders WHERE id = ?').get(reminderId);
  if (!row || row.source_type !== 'inquiry' || !row.source_id) return;
  applyInquiryLifecycleFields(db, row.source_id, lifecycle, { scheduleNext: false });
}

function syncLinkedReminderLifecycles(db, inquiryId, lifecycle) {
  const patch = reminderRowPatchForLifecycle(normalizeLifecycle(lifecycle));
  const rows = db.prepare(`
    SELECT id FROM reminders WHERE source_type = 'inquiry' AND source_id = ? AND status != 'deleted'
  `).all(inquiryId);
  const upd = db.prepare(`
    UPDATE reminders SET
      lifecycle_status = ?,
      status = ?,
      workflow_status = ?,
      next_fire = CASE WHEN ? THEN '' ELSE next_fire END,
      alarm_rings = 0,
      updated_at = datetime('now')
    WHERE id = ?
  `);
  for (const row of rows) {
    upd.run(
      patch.lifecycle_status,
      patch.status,
      patch.workflow_status,
      patch.clear_schedule ? 1 : 0,
      row.id,
    );
  }
}

function applyInquiryLifecycleFields(db, inquiryId, lifecycle, { scheduleNext = false, nextFollowUp, nextFollowUpTime } = {}) {
  const lc = normalizeLifecycle(lifecycle);
  const patch = inquiryRowPatchForLifecycle(lc, { scheduleNext });

  if (patch.clear_follow_up) {
    clearInquiryFollowUpSchedule(db, inquiryId);
    db.prepare(`
      UPDATE inquiries SET lifecycle_status = ?, outcome_status = ?, updated_at = datetime('now')
      WHERE id = ?
    `).run(patch.lifecycle_status, patch.outcome_status, inquiryId);
    syncLinkedReminderLifecycles(db, inquiryId, lc);
    return;
  }

  const followUp = nextFollowUp != null ? nextFollowUp : undefined;
  const followTime = nextFollowUpTime != null ? nextFollowUpTime : undefined;
  db.prepare(`
    UPDATE inquiries SET
      lifecycle_status = ?,
      outcome_status = ?,
      next_follow_up = COALESCE(?, next_follow_up),
      next_follow_up_time = COALESCE(?, next_follow_up_time),
      updated_at = datetime('now')
    WHERE id = ?
  `).run(
    patch.lifecycle_status,
    patch.outcome_status,
    followUp !== undefined ? followUp : null,
    followTime !== undefined ? followTime : null,
    inquiryId,
  );
  syncLinkedReminderLifecycles(db, inquiryId, lc);
}

function inquiryPayloadTouchesNonLifecycleFields(data) {
  const lifecycleKeys = new Set(['lifecycleStatus', 'scheduleNext', 'nextFollowUp', 'nextFollowUpTime']);
  return Object.keys(data || {}).some((k) => !lifecycleKeys.has(k) && data[k] !== undefined);
}

function createInquiry(db, data, now = new Date()) {
  const client = findOrCreateClient(db, {
    name: data.clientName,
    company: data.company,
    mobile: data.mobile,
    email: data.email,
  });

  const id = randomUUID();
  const inquiryNumber = nextInquiryNumber(db);
  const stageKey = data.stageKey || 'follow_up';
  const today = localDateStr(now);
  const lifecycle = normalizeLifecycle(data.lifecycleStatus || LIFECYCLE_ACTIVE);
  const lifecyclePatch = inquiryRowPatchForLifecycle(lifecycle, { scheduleNext: data.scheduleNext });
  let nextFollowUp = data.nextFollowUp || '';
  let nextFollowUpTime = data.nextFollowUpTime || '';
  if (lifecyclePatch.clear_follow_up) {
    nextFollowUp = '';
    nextFollowUpTime = '';
  }

  db.prepare(`
    INSERT INTO inquiries (
      id, inquiry_number, client_id, client_name, company, mobile, email,
      requirement, service_category, source, stage_key, priority, assigned_to,
      next_action, next_follow_up, next_follow_up_time, expected_value, quotation_amount,
      work_start_date, expected_completion_date,
      outcome_status, lifecycle_status, health, stage_changed_at, last_activity_at, notes, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'healthy', datetime('now'), datetime('now'), ?, datetime('now'), datetime('now'))
  `).run(
    id,
    inquiryNumber,
    client.id,
    client.name,
    data.company || client.company || '',
    data.mobile || client.mobile || '',
    data.email || client.email || '',
    data.requirement,
    data.serviceCategory || '',
    data.source || '',
    stageKey,
    data.priority || 'normal',
    data.assignedTo || 'me',
    data.nextAction || data.requirement || 'Follow up',
    nextFollowUp,
    nextFollowUpTime,
    parseFloat(data.expectedValue) || 0,
    parseFloat(data.quotationAmount) || 0,
    data.workStartDate || today,
    data.expectedCompletionDate || '',
    lifecyclePatch.outcome_status,
    lifecyclePatch.lifecycle_status,
    data.notes || '',
  );

  logActivity(db, id, 'inquiry_created', 'Inquiry created', `${client.name} — ${data.requirement}`, { newStage: stageKey });

  const inquiryRow = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(id);
  if (!data.skipFollowUpReminder && shouldMaintainInquiryFollowUp(inquiryRow)) {
    syncInquiryFollowUpReminder(db, id, now);
    if (nextFollowUp) {
      logActivity(db, id, 'follow_up', 'Follow-up scheduled', `${nextFollowUp} ${nextFollowUpTime || '09:00'}`, {});
    }
  }

  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(id);
  inquiry.health = computeInquiryHealth(inquiry, now);
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(inquiry.health, id);

  return { success: true, inquiry, client };
}

const NON_CONVERTIBLE_CATEGORIES = new Set(['medicine', 'bills', 'habit']);

function convertReminderToInquiry(db, reminderId, data, now = new Date()) {
  const reminder = db.prepare("SELECT * FROM reminders WHERE id = ? AND status != 'deleted'").get(reminderId);
  if (!reminder) return { success: false, error: 'Reminder or task not found' };
  if (reminder.source_type) return { success: false, error: 'Already linked to an inquiry' };
  if (NON_CONVERTIBLE_CATEGORIES.has(reminder.category)) {
    return { success: false, error: 'Life module items cannot be converted to inquiries' };
  }
  if (!data?.clientName?.trim()) return { success: false, error: 'Client name is required' };
  if (!data?.requirement?.trim()) return { success: false, error: 'Requirement is required' };

  const followDate = data.nextFollowUp
    || String(reminder.next_fire || reminder.start_date || '').slice(0, 10);
  const followTime = data.nextFollowUpTime || reminder.reminder_time || '11:00';
  const createData = {
    ...data,
    nextFollowUp: followDate,
    nextFollowUpTime: followTime,
    skipFollowUpReminder: true,
  };

  const result = createInquiry(db, createData, now);
  if (!result.success) return result;

  const inquiryId = result.inquiry.id;
  const reminderTitle = data.nextAction && data.clientName
    ? `${data.nextAction}: ${data.clientName}`
    : (data.requirement || reminder.title);
  const nextFire = followDate
    ? computeNextFire(followDate, followTime, 'once', now)
    : reminder.next_fire;

  db.prepare(`
    UPDATE reminders SET
      source_type = 'inquiry',
      source_id = ?,
      category = 'work',
      title = ?,
      why_it_matters = COALESCE(?, why_it_matters),
      start_date = COALESCE(?, start_date),
      reminder_time = COALESCE(?, reminder_time),
      next_fire = COALESCE(?, next_fire),
      work_start_date = COALESCE(?, work_start_date),
      expected_completion_date = COALESCE(?, expected_completion_date),
      assigned_to = COALESCE(?, assigned_to),
      updated_at = datetime('now')
    WHERE id = ?
  `).run(
    inquiryId,
    reminderTitle,
    data.requirement || null,
    followDate || null,
    followTime || null,
    nextFire || null,
    data.workStartDate || null,
    data.expectedCompletionDate || null,
    data.assignedTo || null,
    reminderId,
  );

  logActivity(
    db,
    inquiryId,
    'note',
    'Converted from reminder/task',
    reminder.title,
    { extra: { reminderId, taskType: reminder.task_type } },
  );

  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  inquiry.health = computeInquiryHealth(inquiry, now);
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(inquiry.health, inquiryId);

  return { success: true, inquiry, linkedReminderId: reminderId };
}

function changeInquiryStage(db, inquiryId, newStageKey, options = {}, now = new Date()) {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };

  const stages = loadStagesFromDb(db);
  const stage = getStage(newStageKey, stages);
  if (!stage) return { success: false, error: 'Invalid stage' };
  const automation = getStageAutomation(newStageKey, stages);

  const oldKey = inquiry.stage_key;
  const outcome = isClosedStage(newStageKey, stages) ? 'closed_lost' : (newStageKey === 'delivered' ? 'closed_won' : 'active');
  const lifecycleFromStage = isClosedStage(newStageKey, stages)
    ? LIFECYCLE_CLOSED
    : (newStageKey === 'delivered' ? LIFECYCLE_COMPLETED : LIFECYCLE_ACTIVE);

  // Cancel prior stage follow-up reminders before applying new stage rules
  db.prepare(`
    UPDATE reminders SET status = 'deleted', updated_at = datetime('now')
    WHERE source_type = 'inquiry' AND source_id = ? AND status != 'deleted'
  `).run(inquiryId);

  db.prepare(`
    UPDATE inquiries SET stage_key = ?, outcome_status = ?, lifecycle_status = ?, closed_reason = ?,
      stage_changed_at = datetime('now'), updated_at = datetime('now'),
      quotation_amount = COALESCE(?, quotation_amount),
      payment_status = COALESCE(?, payment_status)
    WHERE id = ?
  `).run(
    newStageKey,
    outcome,
    lifecycleFromStage,
    options.closedReason || (isClosedStage(newStageKey, stages) ? stage.display : ''),
    options.quotationAmount ?? null,
    options.paymentStatus ?? null,
    inquiryId,
  );

  if (lifecycleFromStage === LIFECYCLE_CLOSED || lifecycleFromStage === LIFECYCLE_COMPLETED) {
    clearInquiryFollowUpSchedule(db, inquiryId);
  }

  logActivity(db, inquiryId, 'stage_change', `Stage → ${stage.display}`, options.note || '', {
    oldStage: oldKey,
    newStage: newStageKey,
  });

  const followDays = options.followUpDays ?? automation.followUpDays;
  const autoNextAction = options.nextAction || automation.nextAction;
  if (followDays && !isClosedStage(newStageKey, stages)) {
    const d = new Date(now);
    d.setDate(d.getDate() + followDays);
    const dateStr = localDateStr(d);
    const timeStr = options.followUpTime || inquiry.next_follow_up_time || '11:00';
    createFollowUpReminder(db, inquiry, {
      title: `Follow up: ${inquiry.client_name}`,
      date: dateStr,
      time: timeStr,
      now,
    });
    db.prepare(`UPDATE inquiries SET next_follow_up = ?, next_follow_up_time = ?, next_action = ? WHERE id = ?`).run(
      dateStr, timeStr, autoNextAction || 'Follow up with client', inquiryId,
    );
    logActivity(db, inquiryId, 'follow_up', 'Auto follow-up scheduled', `${dateStr} ${timeStr}`, {});
  }

  if (options.nextFollowUp) {
    createFollowUpReminder(db, inquiry, {
      date: options.nextFollowUp,
      time: options.nextFollowUpTime || '11:00',
      now,
    });
    db.prepare(`UPDATE inquiries SET next_follow_up = ?, next_follow_up_time = ? WHERE id = ?`).run(
      options.nextFollowUp, options.nextFollowUpTime || '11:00', inquiryId,
    );
  }

  const updated = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  const health = computeInquiryHealth(updated, now);
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(health, inquiryId);
  updated.health = health;

  return { success: true, inquiry: updated, stage };
}

function logInquiryActivity(db, inquiryId, { type, title, body }) {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };
  logActivity(db, inquiryId, type || 'note', title || 'Note', body || '');
  const health = computeInquiryHealth(inquiry, new Date());
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(health, inquiryId);
  return { success: true };
}

function pickUpdateField(data, key, current) {
  return data[key] !== undefined ? data[key] : current;
}

function updateInquiry(db, inquiryId, data, now = new Date()) {
  let inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };

  if (data.lifecycleStatus != null) {
    applyInquiryLifecycleFields(db, inquiryId, data.lifecycleStatus, {
      scheduleNext: data.scheduleNext,
      nextFollowUp: data.nextFollowUp,
      nextFollowUpTime: data.nextFollowUpTime,
    });
    inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
    if (!inquiryPayloadTouchesNonLifecycleFields(data)) {
      syncInquiryFollowUpReminder(db, inquiryId, now);
      const updated = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
      const health = computeInquiryHealth(updated, now);
      db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(health, inquiryId);
      updated.health = health;
      return { success: true, inquiry: updated };
    }
  }

  if (data.clientName || data.mobile || data.email) {
    const client = findOrCreateClient(db, {
      name: data.clientName || inquiry.client_name,
      company: data.company || inquiry.company,
      mobile: data.mobile || inquiry.mobile,
      email: data.email || inquiry.email,
    });
    db.prepare(`
      UPDATE clients SET name = ?, company = ?, mobile = ?, email = ? WHERE id = ?
    `).run(client.name, data.company || client.company, data.mobile || client.mobile, data.email || client.email, client.id);
    data.clientId = client.id;
  }

  const nextFollowUp = pickUpdateField(data, 'nextFollowUp', inquiry.next_follow_up);
  const nextFollowUpTime = pickUpdateField(data, 'nextFollowUpTime', inquiry.next_follow_up_time);

  db.prepare(`
    UPDATE inquiries SET
      client_id = COALESCE(?, client_id),
      client_name = COALESCE(?, client_name),
      company = COALESCE(?, company),
      mobile = COALESCE(?, mobile),
      email = COALESCE(?, email),
      requirement = COALESCE(?, requirement),
      service_category = COALESCE(?, service_category),
      source = COALESCE(?, source),
      stage_key = COALESCE(?, stage_key),
      next_action = COALESCE(?, next_action),
      next_follow_up = ?,
      next_follow_up_time = ?,
      expected_value = COALESCE(?, expected_value),
      quotation_amount = COALESCE(?, quotation_amount),
      work_start_date = COALESCE(?, work_start_date),
      expected_completion_date = COALESCE(?, expected_completion_date),
      notes = COALESCE(?, notes),
      operational_state = COALESCE(?, operational_state),
      updated_at = datetime('now')
    WHERE id = ?
  `).run(
    data.clientId ?? null,
    data.clientName ?? null,
    data.company ?? null,
    data.mobile ?? null,
    data.email ?? null,
    data.requirement ?? null,
    data.serviceCategory ?? null,
    data.source ?? null,
    data.stageKey ?? null,
    data.nextAction ?? null,
    nextFollowUp ?? '',
    nextFollowUpTime ?? '',
    data.expectedValue != null ? parseFloat(data.expectedValue) : null,
    data.quotationAmount != null ? parseFloat(data.quotationAmount) : null,
    data.workStartDate ?? null,
    data.expectedCompletionDate ?? null,
    data.notes ?? null,
    data.operationalState ?? null,
    inquiryId,
  );

  logActivity(db, inquiryId, 'note', 'Inquiry updated', data.requirement || inquiry.requirement, {});

  const wasClosed = inquiry.outcome_status !== 'active' && inquiry.outcome_status !== 'deleted';
  if (wasClosed && data.nextFollowUp) {
    return rescheduleInquiry(db, inquiryId, {
      date: data.nextFollowUp,
      time: data.nextFollowUpTime,
      stageKey: data.stageKey,
      nextAction: data.nextAction,
    }, now);
  }

  const followUpChanged = data.nextFollowUp !== undefined && data.nextFollowUp !== inquiry.next_follow_up;
  const followUpTimeChanged = data.nextFollowUpTime !== undefined && data.nextFollowUpTime !== inquiry.next_follow_up_time;
  const nextActionChanged = data.nextAction != null && data.nextAction !== inquiry.next_action;
  if (followUpChanged || followUpTimeChanged || nextActionChanged || data.lifecycleStatus != null) {
    syncInquiryFollowUpReminder(db, inquiryId, now);
  }

  const updated = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  const health = computeInquiryHealth(updated, now);
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(health, inquiryId);
  updated.health = health;
  return { success: true, inquiry: updated };
}

function deleteInquiry(db, inquiryId) {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };
  if (inquiry.outcome_status === 'deleted') return { success: false, error: 'Already deleted' };
  db.prepare(`
    UPDATE inquiries SET outcome_status = 'deleted', updated_at = datetime('now') WHERE id = ?
  `).run(inquiryId);
  logActivity(db, inquiryId, 'note', 'Inquiry deleted', '', {});
  return { success: true };
}

function bulkDeleteInquiries(db, ids) {
  if (!Array.isArray(ids) || ids.length === 0) {
    return { success: false, error: 'No items selected' };
  }
  let deleted = 0;
  for (const id of ids) {
    const result = deleteInquiry(db, id);
    if (result.success) deleted += 1;
  }
  return { success: true, deleted };
}

function reopenInquiry(db, inquiryId, stageKey = 'follow_up') {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };
  db.prepare(`
    UPDATE inquiries SET outcome_status = 'active', lifecycle_status = ?, stage_key = ?, closed_reason = '',
      stage_changed_at = datetime('now'), updated_at = datetime('now'), health = 'healthy'
    WHERE id = ?
  `).run(LIFECYCLE_ACTIVE, stageKey, inquiryId);
  logActivity(db, inquiryId, 'stage_change', 'Inquiry reopened', '', { newStage: stageKey });
  const updated = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  return { success: true, inquiry: updated };
}

function rescheduleInquiry(db, inquiryId, options = {}, now = new Date()) {
  const inquiry = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);
  if (!inquiry) return { success: false, error: 'Inquiry not found' };
  if (inquiry.outcome_status === 'deleted') return { success: false, error: 'Inquiry deleted' };

  const date = options.date || options.nextFollowUp;
  if (!date) return { success: false, error: 'Follow-up date required' };

  const time = options.time || options.nextFollowUpTime || inquiry.next_follow_up_time || '11:00';
  const stageKey = options.stageKey || (inquiry.outcome_status === 'active' ? inquiry.stage_key : 'follow_up');
  const nextAction = options.nextAction || inquiry.next_action || 'Follow up with client';
  const wasClosed = inquiry.outcome_status !== 'active';

  db.prepare(`
    UPDATE inquiries SET
      outcome_status = 'active',
      lifecycle_status = ?,
      stage_key = ?,
      closed_reason = '',
      next_follow_up = ?,
      next_follow_up_time = ?,
      next_action = ?,
      stage_changed_at = datetime('now'),
      updated_at = datetime('now')
    WHERE id = ?
  `).run(LIFECYCLE_ACTIVE, stageKey, date, time, nextAction, inquiryId);

  syncInquiryFollowUpReminder(db, inquiryId, now);
  const refreshed = db.prepare('SELECT * FROM inquiries WHERE id = ?').get(inquiryId);

  logActivity(
    db,
    inquiryId,
    wasClosed ? 'stage_change' : 'follow_up',
    wasClosed ? 'Inquiry rescheduled & reopened' : 'Follow-up rescheduled',
    `${date} ${time}`,
    { newStage: stageKey },
  );

  const health = computeInquiryHealth(refreshed, now);
  db.prepare('UPDATE inquiries SET health = ? WHERE id = ?').run(health, inquiryId);
  refreshed.health = health;
  return { success: true, inquiry: refreshed };
}

function seedInquiryStages(db) {
  const { getStageFields, getStageAutomation } = require('./inquiry-pipeline');
  const stages = DEFAULT_STAGES.map((s) => ({
    ...s,
    fields: getStageFields(s.key),
    automation: getStageAutomation(s.key),
  }));
  saveAllStagesToDb(db, stages);
}

module.exports = {
  createInquiry,
  convertReminderToInquiry,
  updateInquiry,
  mirrorInquiryLifecycleFromReminder,
  changeInquiryStage,
  logInquiryActivity,
  findPossibleDuplicates,
  findOrCreateClient,
  computeInquiryHealth,
  createFollowUpReminder,
  syncInquiryFollowUpReminder,
  reopenInquiry,
  rescheduleInquiry,
  deleteInquiry,
  bulkDeleteInquiries,
  seedInquiryStages,
  logActivity,
};
